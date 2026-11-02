<?php
/** Type-aware metadata for the existing publication archive. No request side effects. */
require_once __DIR__ . '/archival-records.php';
require_once __DIR__ . '/faculty-research-records.php';

function publicationJson($value): array
{
    if (!is_string($value) || $value === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}
function publicationText(array $input, string $key): string
{
    return is_string($input[$key] ?? null) ? trim($input[$key]) : '';
}
function publicationWordCount(string $text): int
{
    preg_match_all("/[\\p{L}\\p{N}]+(?:['’\\-][\\p{L}\\p{N}]+)*/u", trim($text), $matches);
    return count($matches[0]);
}
function publicationHttpUrl(string $value): bool
{
    return (bool) filter_var($value, FILTER_VALIDATE_URL)
        && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http','https'], true);
}
function publicationDoi(string $value): string
{
    return preg_replace('~^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)~i', '', trim($value));
}
function publicationClassificationTypes(mysqli $db, bool $activeOnly = true): array
{
    return archiveQuery($db, 'SELECT * FROM publication_types' . ($activeOnly ? ' WHERE is_active=1' : '') . ' ORDER BY sort_order,label')->get_result()->fetch_all(MYSQLI_ASSOC);
}
function publicationClassificationSubtypes(mysqli $db, int $typeId, bool $activeOnly = true): array
{
    return archiveQuery($db, 'SELECT s.* FROM publication_subtypes s JOIN publication_types t USING(publication_type_id) WHERE s.publication_type_id=?'
        . ($activeOnly ? ' AND s.is_active=1 AND t.is_active=1' : '') . ' ORDER BY s.sort_order,s.label', [$typeId])->get_result()->fetch_all(MYSQLI_ASSOC);
}
function publicationClassificationScopes(mysqli $db, bool $activeOnly = true): array
{
    return archiveQuery($db, 'SELECT * FROM publication_scopes' . ($activeOnly ? ' WHERE is_active=1' : '') . ' ORDER BY sort_order,label')->get_result()->fetch_all(MYSQLI_ASSOC);
}
function publicationCatalog(mysqli $db): array
{
    $catalog = ['types'=>[], 'subtypes'=>[], 'scopes'=>[], 'rules'=>[], 'settings'=>[]];
    foreach (publicationClassificationTypes($db, false) as $row) $catalog['types'][(int)$row['publication_type_id']] = $row;
    foreach (archiveQuery($db, 'SELECT * FROM publication_subtypes ORDER BY sort_order,label')->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $catalog['subtypes'][(int)$row['publication_subtype_id']] = $row;
    foreach (publicationClassificationScopes($db, false) as $row) $catalog['scopes'][(int)$row['publication_scope_id']] = $row;
    foreach (archiveQuery($db, 'SELECT * FROM publication_form_rules ORDER BY publication_subtype_id')->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $catalog['rules'][(int)$row['publication_subtype_id']] = $row;
    foreach (archiveQuery($db, "SELECT setting_key,setting_value FROM application_settings WHERE setting_key LIKE 'publication!_%' ESCAPE '!' ORDER BY setting_key")->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $catalog['settings'][$row['setting_key']] = publicationJson($row['setting_value']);
    return $catalog;
}
function publicationLockCatalog(mysqli $db): void
{
    foreach (['publication_types','publication_subtypes','publication_scopes','publication_form_rules'] as $table) archiveQuery($db, "SELECT * FROM $table FOR UPDATE")->get_result()->free();
    archiveQuery($db, "SELECT setting_key FROM application_settings WHERE setting_key LIKE 'publication!_%' ESCAPE '!' FOR UPDATE")->get_result()->free();
}
function publicationSaveSetting(mysqli $db, string $key, array $value, int $userId): void
{
    archiveQuery($db,'INSERT INTO application_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)',[$key,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$userId]);
}
function publicationUploadLimit(array $rules): int
{
    $configured=(int)($rules['max_attachment_kb']??0);
    return max(1,(int)floor(rmsEffectiveUploadLimit($configured>0?$configured*1024:PHP_INT_MAX)/1024));
}
function publicationTemplates(): array
{
    return ['journal_article'=>'Journal Article', 'conference_proceedings_paper'=>'Conference Proceedings Paper',
        'book'=>'Book', 'book_chapter_scholarly'=>'Book Chapter', 'research_monograph'=>'Research Monograph', 'generic'=>'Other scholarly publication'];
}
function publicationTemplate(array $type, array $catalog): string
{
    $code = $type['type_code'] ?? '';
    if (isset(publicationTemplates()[$code])) return $code;
    return $catalog['settings']['publication_type_' . ($type['publication_type_id'] ?? 0)]['template']
        ?? (['journal'=>'journal_article','conference'=>'conference_proceedings_paper','book_chapter'=>'book_chapter_scholarly'][$code] ?? 'generic');
}
function publicationCompatibilityType(string $template): string
{
    return match ($template) {
        'journal_article'=>'journal', 'conference_proceedings_paper'=>'conference', 'book_chapter_scholarly'=>'book_chapter', default=>'other',
    };
}
function publicationFieldCatalog(): array
{
    // Field identifiers are allowlisted; settings cannot introduce SQL or HTML.
    return [
        'source_title'=>['Journal / publication source title','text',255],
        'publisher'=>['Publisher / publishing institution','text',255],
        'publication_year'=>['Publication year','year',4],
        'conference_name'=>['Conference name','text',255],
        'conference_organizer'=>['Conference organizer / host','text',255],
        'conference_date'=>['Conference date','date',10],
        'conference_venue'=>['Conference venue','text',255],
        'proceedings_title'=>['Proceedings title','text',255],
        'book_title'=>['Book title','text',255],
        'book_editors'=>['Book editors','text',500],
        'book_compilers'=>['Book compilers','text',500],
        'subject_discipline'=>['Subject / discipline','text',255],
        'journal_website'=>['Journal website','url',500],
        'review_type'=>['Review type / methodology','text',120],
        'technical_focus'=>['Technical / professional focus','text',500],
        'chapter_number'=>['Chapter number','text',40],
        'monograph_series'=>['Monograph series','text',255],
        'report_number'=>['Series / report number','text',120],
        'edition'=>['Edition','text',100],
        'volume'=>['Volume','text',60], 'issue'=>['Issue','text',60], 'pages'=>['Page range','text',80],
        'isbn'=>['ISBN','text',80], 'issn'=>['ISSN','text',80], 'isbn_issn'=>['ISBN / ISSN','text',160],
        'publication_identifier'=>['Publication identifier','text',255],
    ];
}
function publicationMetadataFields(string $template, string $subtypeCode = ''): array
{
    $keys = match ($template) {
        'journal_article'=>match ($subtypeCode) {
            'review_article'=>['source_title','publisher','publication_year','volume','issue','pages','issn','journal_website','review_type'],
            'technical_professional_article'=>['source_title','publisher','publication_year','volume','issue','pages','issn','technical_focus'],
            default=>['source_title','publisher','publication_year','volume','issue','pages','issn','journal_website'],
        },
        'conference_proceedings_paper'=>['conference_name','conference_organizer','conference_date','conference_venue','proceedings_title','publisher','publication_year','pages','isbn_issn'],
        'book'=>match ($subtypeCode) {
            'edited_book'=>['publisher','publication_year','edition','isbn','subject_discipline','book_editors'],
            'compiled_book'=>['publisher','publication_year','edition','isbn','book_compilers','subject_discipline'],
            'textbook'=>['publisher','publication_year','edition','isbn','subject_discipline'],
            default=>['publisher','publication_year','edition','isbn','subject_discipline'],
        },
        'book_chapter_scholarly'=>['book_title','book_editors','publisher','publication_year','pages','chapter_number','isbn'],
        'research_monograph'=>match ($subtypeCode) {
            'technical_research_monograph'=>['publisher','publication_year','publication_identifier','monograph_series','report_number','technical_focus'],
            default=>['publisher','publication_year','publication_identifier','monograph_series'],
        },
        default=>['source_title','publisher','publication_year','publication_identifier'],
    };
    return array_intersect_key(publicationFieldCatalog(), array_flip($keys));
}
function publicationSubtypeLabels(string $template, string $subtypeCode): array
{
    return match ($template) {
        'journal_article'=>[
            'title_label'=>match ($subtypeCode) {'review_article'=>'Review article title','technical_professional_article'=>'Article title',default=>'Research article title'},
            'contributor_label'=>'Authors','contributor_role'=>'Author','document_label'=>$subtypeCode==='review_article'?'Published Review Article / Document':'Published journal article',
            'field_labels'=>['source_title'=>'Journal title','pages'=>'Page range / article number','issn'=>'ISSN / eISSN'],
        ],
        'conference_proceedings_paper'=>[
            'title_label'=>'Conference paper title','contributor_label'=>'Authors','contributor_role'=>'Author','document_label'=>'Published conference paper',
            'field_labels'=>['pages'=>'Page range / article number'],
        ],
        'book'=>[
            'title_label'=>match ($subtypeCode) {'textbook'=>'Textbook title',default=>'Book title'},
            'contributor_label'=>match ($subtypeCode) {'edited_book'=>'Editors','compiled_book'=>'Compilers',default=>'Authors'},
            'contributor_role'=>match ($subtypeCode) {'edited_book'=>'Editor','compiled_book'=>'Compiler',default=>'Author'},
            'document_label'=>'Published book',
        ],
        'book_chapter_scholarly'=>[
            'title_label'=>'Chapter title','contributor_label'=>'Chapter authors','contributor_role'=>'Chapter author','document_label'=>'Published book chapter',
            'field_labels'=>['book_title'=>'Parent book title','pages'=>'Chapter page range','chapter_number'=>'Chapter number'],
        ],
        'research_monograph'=>[
            'title_label'=>'Monograph title','contributor_label'=>'Authors','contributor_role'=>'Author','document_label'=>'Published monograph',
        ],
        default=>['title_label'=>'Publication title','contributor_label'=>'Authors / contributors','contributor_role'=>'Contributor','document_label'=>'Published document'],
    };
}
function publicationRules(array $catalog, int $typeId, int $subtypeId): array
{
    $template = publicationTemplate($catalog['types'][$typeId] ?? [], $catalog);
    $base = $catalog['rules'][$subtypeId] ?? [];
    $extra = $catalog['settings']['publication_rule_' . $subtypeId] ?? [];
    $subtypeCode = (string)($catalog['subtypes'][$subtypeId]['subtype_code'] ?? '');
    $presentation = publicationSubtypeLabels($template, $subtypeCode);
    $fields = $extra['fields'] ?? array_keys(publicationMetadataFields($template, $subtypeCode));
    $fields = array_values(array_intersect(is_array($fields) ? $fields : [], array_keys(publicationFieldCatalog())));
    if (!in_array('publication_year',$fields,true)) $fields[]='publication_year';
    $required = $extra['required'] ?? ['publication_year'];
    if (!in_array('publication_year',$required,true)) $required[]='publication_year';
    if ($template === 'journal_article') $required[] = 'source_title';
    $required = array_values(array_intersect(is_array($required) ? $required : [], $fields));
    $defaultTextVisible = in_array($template,['journal_article','conference_proceedings_paper'],true)
        || ($template==='research_monograph'&&$subtypeCode==='scholarly_monograph');
    $abstractVisible = (bool)($extra['abstract_visible'] ?? $defaultTextVisible);
    $keywordsVisible = (bool)($extra['keywords_visible'] ?? $defaultTextVisible);
    return [
        'fields'=>$fields,
        'required'=>$required,
        'title_label'=>$presentation['title_label'],
        'contributor_label'=>$presentation['contributor_label'],
        'contributor_role'=>$presentation['contributor_role'],
        'document_label'=>$presentation['document_label'],
        'field_labels'=>$presentation['field_labels'] ?? [],
        'abstract_visible'=>$abstractVisible,
        'keywords_visible'=>$keywordsVisible,
        'abstract_required'=>$extra['abstract_required'] ?? (($catalog['subtypes'][$subtypeId]['subtype_code'] ?? '') === 'short_conference_paper'),
        'abstract_word_limit'=>(int)($base['abstract_word_limit'] ?? 0),
        'keyword_count'=>(int)($base['keyword_count'] ?? 0),
        'manuscript_required'=>(bool)($base['manuscript_required'] ?? false),
        'references_required'=>(bool)($base['references_required'] ?? false),
        'evidence_required'=>(bool)($base['evidence_required'] ?? true),
        'manuscript_page_limit'=>(int)($base['manuscript_page_limit'] ?? 0),
        'max_attachment_kb'=>(int)($extra['max_attachment_kb'] ?? 0),
        // handleRmsUpload validates PDF signatures for this archive destination.
        'allowed_attachment_extensions'=>'pdf',
    ];
}
function publicationEffectiveRules(array $catalog, int $typeId, int $subtypeId, ?array $record): array
{
    $metadata = publicationJson($record['publication_metadata_json'] ?? null);
    if ($record && (int)($record['publication_type_id'] ?? 0) === $typeId && (int)($record['publication_subtype_id'] ?? 0) === $subtypeId) {
        if (is_array($metadata['_rules'] ?? null)) return array_replace(publicationRules($catalog,$typeId,$subtypeId),$metadata['_rules']);
        // Records created before snapshots remain editable under their existing completeness.
        $rules = publicationRules($catalog, $typeId, $subtypeId);
        $rules['required'] = [];
        $rules['abstract_required'] = $rules['manuscript_required'] = $rules['references_required'] = $rules['evidence_required'] = false;
        $rules['abstract_word_limit'] = $rules['keyword_count'] = $rules['manuscript_page_limit'] = 0;
        return $rules;
    }
    return publicationRules($catalog, $typeId, $subtypeId);
}
function publicationAuthors(array $record): array
{
    $authors = [];
    foreach (publicationJson($record['authors_json'] ?? null) as $row) {
        $name = is_string($row) ? $row : (is_array($row) ? ($row['name'] ?? '') : '');
        if (is_string($name) && trim($name) !== '') $authors[] = trim($name);
    }
    return $authors ?: (!empty($record['researcher_name']) ? [(string)$record['researcher_name']] : []);
}
function publicationAuthorLabel(array $record): string { return implode('; ', publicationAuthors($record)); }
function publicationTypeLabel(array $row): string
{
    if (!empty($row['classification_type_label'])) return (string)$row['classification_type_label'];
    $legacyType=(string)($row['publication_type']??'');
    if ($legacyType==='other') return (string)(($row['publication_type_name']??'')?:'Other');
    $known=archiveConfig('publication')['types'][$legacyType]??null;
    return $known??($legacyType!==''?ucwords(str_replace('_',' ',$legacyType)):'Not recorded');
}
function publicationYear(array $row): string
{
    $meta = publicationJson($row['publication_metadata_json'] ?? null);
    $year = is_scalar($meta['publication_year'] ?? null) ? (string)$meta['publication_year'] : '';
    return $year ?: substr((string)($row['publication_date'] ?? ''), 0, 4);
}
function publicationSource(array $row): string
{
    $meta = publicationJson($row['publication_metadata_json'] ?? null);
    $typeCode=(string)($row['classification_type_code']??'');
    if($typeCode!=='') {
        $template=publicationTemplate(['type_code'=>$typeCode],[]);
        $subtypeCode=(string)($row['classification_subtype_code']??'');
        $allowed=publicationMetadataFields($template,$subtypeCode);
        $keys=match($template) {
            'journal_article'=>['source_title','publisher'],
            'conference_proceedings_paper'=>['proceedings_title','conference_name','publisher'],
            'book','book_chapter_scholarly'=>['book_title','publisher'],
            'research_monograph'=>['publisher','publication_identifier'],
            default=>['source_title','proceedings_title','book_title','publisher'],
        };
        foreach($keys as $key) if(isset($allowed[$key])&&!empty($meta[$key])&&is_string($meta[$key]))return $meta[$key];
        if(in_array($typeCode,['journal','conference','book_chapter','other'],true))return (string)($row['journal_publisher']??'');
        return '';
    }
    foreach (['proceedings_title','source_title','book_title','publisher'] as $key) if (!empty($meta[$key]) && is_string($meta[$key])) return $meta[$key];
    return (string)($row['journal_publisher'] ?? '');
}
function publicationYearSql(): string
{
    return "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(r.publication_metadata_json) THEN r.publication_metadata_json ELSE '{}' END,'$.publication_year')),''),YEAR(r.publication_date))";
}
function publicationJoinsSql(): string
{
    return " LEFT JOIN publication_types pt ON pt.publication_type_id=r.publication_type_id
        LEFT JOIN publication_subtypes ps ON ps.publication_subtype_id=r.publication_subtype_id
        LEFT JOIN publication_scopes psc ON psc.publication_scope_id=r.publication_scope_id
        LEFT JOIN research_areas pra ON pra.area_id=r.research_area_id
        LEFT JOIN programs pg ON pg.program_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(
            CASE WHEN JSON_VALID(r.publication_metadata_json) THEN r.publication_metadata_json ELSE '{}' END,'$.program_id')) AS UNSIGNED)";
}
function publicationIndexOptions(): array
{
    return ['Scopus','Web of Science','ASEAN Citation Index','Other Verified Index','Not Indexed','Unknown / Not Verified'];
}
function publicationValidate(array $input, array $files, array $catalog, ?array $record = null): array
{
    $errors = []; $data = []; $meta = publicationJson($record['publication_metadata_json'] ?? null);
    $candidateTypeId=filter_var(publicationText($input,'publication_type_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    $candidateSubtypeId=filter_var(publicationText($input,'publication_subtype_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
    $rules=publicationEffectiveRules($catalog,$candidateTypeId,$candidateSubtypeId,$record);
    foreach (['research_title'=>500,'abstract_text'=>20000,'doi_identifier'=>255,'publication_url'=>500,'evidence_url'=>500,'remarks'=>10000] as $key=>$max) {
        if ($key==='abstract_text' && empty($rules['abstract_visible'])) {
            $data[$key]=(string)($record['abstract_text']??'');
            continue;
        }
        $data[$key] = array_key_exists($key,$input)?publicationText($input,$key):(string)($record[$key]??'');
        if (isset($input[$key]) && !is_string($input[$key])) $errors[$key] = 'Enter text in this field.';
        if (mb_strlen($data[$key]) > $max) $errors[$key] = "Use at most $max characters.";
    }
    $data['research_title'] = trim(preg_replace('/\s+/u', ' ', $data['research_title']) ?? '');
    if ($data['research_title'] === '') $errors['research_title'] = 'Enter the research title.';
    foreach (['publication_type_id','publication_subtype_id','publication_scope_id','research_area_id'] as $key) {
        $raw = publicationText($input, $key);
        if (isset($input[$key]) && !is_string($input[$key])) $errors[$key] = 'Choose a valid option.';
        $data[$key] = $raw === '' ? null : (filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: null);
        if ($raw !== '' && !$data[$key]) $errors[$key] = 'Choose a valid option.';
    }
    $typeId = (int)$data['publication_type_id']; $subtypeId = (int)$data['publication_subtype_id']; $scopeId = (int)$data['publication_scope_id'];
    $type = $catalog['types'][$typeId] ?? null; $subtype = $catalog['subtypes'][$subtypeId] ?? null;
    $legacy = $record && empty($record['publication_type_id']) && !$typeId;
    $same = $record && (int)($record['publication_type_id'] ?? 0) === $typeId && (int)($record['publication_subtype_id'] ?? 0) === $subtypeId;
    if (!$legacy && (!$type || (!(int)$type['is_active'] && !$same) || (!(int)$type['is_proposed'] && !$same))) $errors['publication_type_id'] = 'Choose a supported active publication type.';
    $hasSubtypes=(bool)array_filter($catalog['subtypes'],static fn($s)=>(int)$s['publication_type_id']===$typeId&&(int)$s['is_active']===1);
    if (!$legacy && (($subtypeId && (!$subtype || (int)$subtype['publication_type_id'] !== $typeId || (!(int)$subtype['is_active'] && !$same) || (!(int)($type['is_proposed']??0) && !$same))) || (!$subtypeId&&$hasSubtypes))) $errors['publication_subtype_id'] = 'Choose an active subtype belonging to this type.';
    if ($scopeId) {
        $scope = $catalog['scopes'][$scopeId] ?? null;
        if (!$scope || (!(int)$scope['is_active'] && $scopeId !== (int)($record['publication_scope_id'] ?? 0))) $errors['publication_scope_id'] = 'Choose an active publication scope.';
    } elseif (!$legacy) $errors['publication_scope_id'] = 'Choose a publication scope.';
    if ($legacy && $subtypeId) $errors['publication_subtype_id'] = 'Choose a type before choosing a subtype.';
    $rules = publicationEffectiveRules($catalog, $typeId, $subtypeId, $record);
    $authors = $input['author_names'] ?? [];
    if (!is_array($authors) || count($authors) > 50) { $errors['author_names'] = 'Enter between 1 and 50 authors.'; $authors = []; }
    $cleanAuthors = [];
    foreach ($authors as $name) {
        if (!is_string($name) || mb_strlen(trim($name)) > 255) { $errors['author_names'] = 'Each author name must be text of at most 255 characters.'; continue; }
        if (trim($name) !== '') $cleanAuthors[] = trim($name);
    }
    if (!$cleanAuthors) $errors['author_names'] = 'Enter at least one author in publication order.';
    // Identical names can legitimately belong to different people; do not reject them.
    $authorLabel = implode('; ', $cleanAuthors);
    $authorRows=array_map(static fn(string $name)=>['name'=>$name,'role'=>$rules['contributor_role']??'Author'],$cleanAuthors);
    $data['authors_json'] = json_encode($authorRows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // This legacy summary column is only 255 characters; the complete ordered list lives in authors_json.
    $data['researcher_name'] = mb_substr($authorLabel, 0, 255);
    if ($record && $cleanAuthors === publicationAuthors($record)) {
        if ($same) $data['authors_json'] = $record['authors_json'];
        $data['researcher_name'] = $record['researcher_name'];
    }
    $keywordsVisible=!empty($rules['keywords_visible']);
    $keywords=$keywordsVisible?($input['publication_keywords']??[]):publicationJson($record['keywords_json']??null);
    if ($keywordsVisible && (!is_array($keywords) || count($keywords)>20)) { $errors['publication_keywords'] = 'Enter at most 20 keywords.'; $keywords = []; }
    $cleanKeywords=[];
    if($keywordsVisible) {
        foreach ($keywords as $keyword) {
            if (!is_string($keyword) || mb_strlen(trim($keyword))>100) { $errors['publication_keywords']='Each keyword must have at most 100 characters.'; continue; }
            if (trim($keyword)!=='') $cleanKeywords[]=trim($keyword);
        }
        if (count(array_unique(array_map('mb_strtolower',$cleanKeywords)))!==count($cleanKeywords)) $errors['publication_keywords']='Remove duplicate keywords.';
        if ((int)$rules['keyword_count']>0 && count($cleanKeywords)!==(int)$rules['keyword_count']) $errors['publication_keywords']='Enter exactly '.$rules['keyword_count'].' keywords for this form configuration.';
    } else {
        $cleanKeywords=array_filter($keywords,'is_string');
    }
    $data['keywords_json']=!empty($rules['keywords_visible'])?json_encode($cleanKeywords, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR):(string)($record['keywords_json']??'[]');
    if ($record && $cleanKeywords===publicationJson($record['keywords_json'] ?? null)) $data['keywords_json']=$record['keywords_json'];
    if (!empty($rules['abstract_visible'])&&!empty($rules['abstract_required'])&&$data['abstract_text']==='') $errors['abstract_text']='Enter the abstract.';
    if (!empty($rules['abstract_visible'])&&(int)$rules['abstract_word_limit']>0&&publicationWordCount($data['abstract_text'])>(int)$rules['abstract_word_limit']) $errors['abstract_text']='Use at most '.$rules['abstract_word_limit'].' words.';
    $postedMeta=$input['publication_metadata'] ?? [];
    if (!is_array($postedMeta)) { $errors['metadata']='Invalid publication metadata.'; $postedMeta=[]; }
    foreach (publicationFieldCatalog() as $key=>[$label,$kind,$max]) {
        if (!in_array($key,$rules['fields'],true)) continue;
        $hasPostedValue=array_key_exists($key,$postedMeta);
        $rawValue=$hasPostedValue?$postedMeta[$key]:($meta[$key]??'');
        $value=is_string($rawValue)?trim($rawValue):'';
        if ($hasPostedValue && !is_string($rawValue)) $errors[$key]='Enter a valid '.$label.'.';
        if (mb_strlen($value)>$max) $errors[$key]="Use at most $max characters.";
        if ($value!=='' && $kind==='year' && !preg_match('/^[1-9][0-9]{3}$/D',$value)) $errors[$key]='Enter a four-digit publication year.';
        if ($value!=='' && $kind==='date') {
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if (!$date || $date->format('Y-m-d')!==$value) $errors[$key]='Enter a valid date.';
        }
        if ($value!=='' && $kind==='url' && !publicationHttpUrl($value)) $errors[$key]='Enter an HTTP or HTTPS URL.';
        if (in_array($key,$rules['required'],true) && $value==='') $errors[$key]=$label.' is required.';
        if ($hasPostedValue) $meta[$key]=$value;
    }
    foreach (['publication_url','evidence_url'] as $key) if ($data[$key]!=='' && !publicationHttpUrl($data[$key])) $errors[$key]='Enter an HTTP or HTTPS URL.';
    $date=array_key_exists('publication_date',$input)?publicationText($input,'publication_date'):(string)($record['publication_date']??''); $data['publication_date']=$date ?: null;
    if ($date!=='') { $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date); if (!$parsed || $parsed->format('Y-m-d')!==$date) $errors['publication_date']='Enter a valid publication date.'; }
    $indexing=$input['indexing'] ?? [];
    if (!is_array($indexing) || array_filter($indexing,static fn($v)=>!is_string($v)) || array_diff($indexing,publicationIndexOptions())) { $errors['indexing']='Choose listed indexing options.'; $indexing=[]; }
    $indexing=array_values(array_unique($indexing));
    if (count($indexing)>1 && (in_array('Not Indexed',$indexing,true)||in_array('Unknown / Not Verified',$indexing,true))) $errors['indexing']='Not Indexed and Unknown / Not Verified must be selected alone.';
    $indexEvidence=publicationText($input,'indexing_evidence');
    if (mb_strlen($indexEvidence)>500) $errors['indexing_evidence']='Use at most 500 characters.';
    $verified=array_intersect($indexing,['Scopus','Web of Science','ASEAN Citation Index','Other Verified Index']);
    if ($verified && $indexEvidence==='' && $indexing!==($meta['indexing'] ?? [])) $errors['indexing_evidence']='Provide a source or identifier supporting the selected indexing database.';
    if ($indexing || isset($meta['indexing']) || isset($input['clear_legacy_indexing'])) $meta['indexing']=$indexing;
    if ($indexEvidence!=='' || isset($meta['indexing_evidence'])) $meta['indexing_evidence']=$indexEvidence;
    $data['indexing_information']=implode('; ',$indexing) ?: (isset(publicationJson($record['publication_metadata_json'] ?? null)['indexing']) ? null : ($record['indexing_information'] ?? null));
    if (!$indexing && isset($input['clear_legacy_indexing'])) $data['indexing_information']=null;
    $sdgs=$input['sdgs'] ?? [];
    if (!is_array($sdgs) || count($sdgs)>17) { $errors['sdgs']='Choose valid SDG options.'; $sdgs=[]; }
    $sdgIds=[];
    foreach ($sdgs as $sdg) { if (!is_scalar($sdg)||!ctype_digit((string)$sdg)||!isset(facultyRecordSdgs()[(int)$sdg])) $errors['sdgs']='Choose valid SDG options.'; else $sdgIds[]=(int)$sdg; }
    $sdgIds=array_values(array_unique($sdgIds));
    if ($sdgIds || isset($meta['sdgs']) || isset($input['clear_legacy_sdgs'])) $meta['sdgs']=$sdgIds;
    $data['sdg_alignment']=$sdgIds ? implode(', ',array_map(static fn($n)=>'SDG '.$n,$sdgIds)) : (isset(publicationJson($record['publication_metadata_json'] ?? null)['sdgs']) ? null : ($record['sdg_alignment'] ?? null));
    if (!$sdgIds && isset($input['clear_legacy_sdgs'])) $data['sdg_alignment']=null;
    $program=publicationText($input,'program_id');
    foreach (['program_id','publication_date','declared_page_count','indexing_evidence'] as $key) if (isset($input[$key])&&!is_string($input[$key])) $errors[$key]='Enter a valid value.';
    if ($program!=='' && (!ctype_digit($program)||(int)$program<1)) $errors['program_id']='Choose a program.';
    $meta['program_id']=$program!==''?(int)$program:null;
    $pageCount=publicationText($input,'declared_page_count');
    if ($pageCount!=='' && (!ctype_digit($pageCount)||(int)$pageCount<1||(int)$pageCount>10000)) $errors['declared_page_count']='Enter a valid page count.';
    if ((int)$rules['manuscript_page_limit']>0 && ($pageCount===''||(int)$pageCount>(int)$rules['manuscript_page_limit'])) $errors['declared_page_count']='Declare a manuscript length of at most '.$rules['manuscript_page_limit'].' pages.';
    if ($pageCount!=='' || isset($meta['declared_page_count'])) $meta['declared_page_count']=$pageCount;
    $hasUpload=[];
    foreach (['supporting_file','evidence_file','references_file'] as $slot) {
        $file=$files[$slot] ?? null;
        $hasUpload[$slot]=is_array($file)&&is_int($file['error'] ?? null)&&$file['error']!==UPLOAD_ERR_NO_FILE;
        if (isset($files[$slot]) && (!is_array($file)||!is_int($file['error'] ?? null)||is_array($file['name'] ?? null))) $errors[$slot]='Invalid attachment.';
        if ($hasUpload[$slot] && ($file['error']!==UPLOAD_ERR_OK||!is_string($file['name']??null)||mb_strlen($file['name'])>255||!is_string($file['tmp_name']??null)||!is_int($file['size']??null))) $errors[$slot]='The attachment was not received correctly or its name is too long.';
    }
    if ($rules['manuscript_required'] && empty($record['file_path']) && !$hasUpload['supporting_file']) $errors['supporting_file']='Attach the published paper.';
    if ($rules['references_required'] && empty($record['references_file_path']) && !$hasUpload['references_file']) $errors['references_file']='This form configuration requires a separate references document.';
    if ($rules['evidence_required'] && $data['doi_identifier']==='' && $data['publication_url']==='' && $data['evidence_url']==='' && empty($record['file_path']) && empty($record['evidence_file_path']) && !$hasUpload['supporting_file'] && !$hasUpload['evidence_file']) $errors['evidence_url']='Provide a publication URL, DOI, published paper, or supporting publication evidence.';
    if (!$legacy) {
        $meta['_rules']=$rules;
        // Unclassified historical categories stay intact. Explicit changes to an already
        // classified record also update the compatibility category used by staff scopes.
        $preserveCategory=$record && $same;
        $data['publication_type']=$preserveCategory ? $record['publication_type'] : publicationCompatibilityType(publicationTemplate($type ?? [],$catalog));
        $data['publication_type_name']=$preserveCategory ? $record['publication_type_name'] : ($data['publication_type']==='other' ? mb_substr($type['label'] ?? 'Other',0,120) : null);
    } else {
        $data['publication_type']=$record['publication_type'];
        $data['publication_type_name']=publicationText($input,'publication_type_name') ?: $record['publication_type_name'];
        $data['journal_publisher']=publicationText($input,'legacy_source') ?: $record['journal_publisher'];
    }
    $data['publication_metadata_json']=json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    foreach (['volume'=>'volume','issue'=>'issue','pages'=>'page_numbers'] as $key=>$column) if (array_key_exists($key,$meta)) $data[$column]=$meta[$key] ?: null;
    if (!$legacy) $data['journal_publisher']=publicationSource(['publication_metadata_json'=>$data['publication_metadata_json'],'journal_publisher'=>$record['journal_publisher'] ?? '']) ?: null;
    foreach ($data as $key=>$value) if ($value==='') $data[$key]=null;
    return ['data'=>$data,'metadata'=>$meta,'rules'=>$rules,'errors'=>$errors,'authors'=>$cleanAuthors,'keywords'=>$cleanKeywords,'uploads'=>$hasUpload];
}
function publicationDuplicates(mysqli $db, array $data, array $user, int $excludeId): array
{
    $title=mb_strtolower(trim(preg_replace('/\s+/u',' ',(string)$data['research_title']) ?? ''));
    $doi=mb_strtolower(publicationDoi((string)($data['doi_identifier'] ?? '')));
    $sql="SELECT * FROM publications WHERE publication_id<>? AND (LOWER(REGEXP_REPLACE(TRIM(research_title),'[[:space:]]+',' '))=?"
        . ($doi!=='' ? " OR LOWER(REGEXP_REPLACE(TRIM(doi_identifier),'^(https?://(dx[.])?doi[.]org/|doi:[[:space:]]*)',''))=?" : '') . ')';
    $params=[$excludeId,$title]; if ($doi!=='') $params[]=$doi;
    $matches=[];
    foreach (archiveQuery($db,$sql,$params)->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        if (!archiveCan($db,'publication',$row,$user,'view')) continue;
        $matches[]=$row;
    }
    return $matches;
}
