<?php
/** CLI-only, read-only schema/preservation checks and in-memory validation regressions.
 * Does not dispatch an application request, authenticate a session, or upload/save records.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../includes/publication-classification.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$env=static fn($key,$default)=>(string)($_ENV[$key]??$_SERVER[$key]??$default);
if (!in_array($env('DB_HOST','localhost'),['localhost','127.0.0.1','::1'],true)||$env('DB_NAME','rms_db')!=='rms_db') exit("Local rms_db required.\n");
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
set_error_handler(static function($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
$checks=0;
$check=static function(bool $ok,string $description) use(&$checks): void { if(!$ok) throw new RuntimeException($description); $checks++; };
// Decode only this dump's complete single-row INSERT literals, never execute backup SQL.
function publicationCheckDumpRows(string $dump,string $table): array {
    preg_match_all('/^INSERT INTO `'.preg_quote($table,'/').'` \((.*?)\) VALUES \((.*?)\);$/m',$dump,$inserts,PREG_SET_ORDER);
    $rows=[];
    foreach($inserts as $insert) {
        preg_match_all('/`([a-zA-Z0-9_]+)`/',$insert[1],$columns);
        $values=[]; $text=$insert[2]; $length=strlen($text); $i=0;
        while($i<$length) {
            while($i<$length&&ctype_space($text[$i]))$i++;
            if(($text[$i]??'')==="'") {
                $i++; $value=''; $closed=false;
                while($i<$length) {
                    $char=$text[$i++];
                    if($char==='\\') { if($i>=$length)throw new RuntimeException('Incomplete dump escape.'); $next=$text[$i++]; $value.=match($next){'0'=>"\0",'n'=>"\n",'r'=>"\r",'Z'=>chr(26),'b'=>chr(8),'t'=>"\t",default=>$next}; }
                    elseif($char==="'") { $closed=true; break; } else $value.=$char;
                }
                if(!$closed)throw new RuntimeException('Incomplete dump literal.');
            } else { $start=$i; while($i<$length&&$text[$i]!==',')$i++; $value=trim(substr($text,$start,$i-$start)); if($value==='NULL')$value=null; }
            $values[]=$value;
            while($i<$length&&ctype_space($text[$i]))$i++;
            if($i<$length&&$text[$i++]!==',')throw new RuntimeException('Unexpected dump separator.');
        }
        if(count($columns[1])!==count($values))throw new RuntimeException('Dump column count mismatch.');
        $rows[]=array_combine($columns[1],$values);
    }
    return $rows;
}
try {
    $db=new mysqli($env('DB_HOST','localhost'),$env('DB_USER','root'),$env('DB_PASS',''),'rms_db'); $db->set_charset('utf8mb4');
    $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY|MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $catalog=publicationCatalog($db);
    $check(count($catalog['types'])>=9&&count($catalog['subtypes'])>=15&&count($catalog['scopes'])>=3,'Classification catalogs missing.');
    $check($catalog===publicationCatalog($db),'Catalog ordering must be stable.');
    // Isolate form-boundary checks from mutable administrator rule values, entirely in memory.
    $catalog['settings']=[];
    $catalog['rules'][6]=['abstract_word_limit'=>250,'keyword_count'=>4,'manuscript_required'=>1,'references_required'=>0,'evidence_required'=>1,'manuscript_page_limit'=>null];
    $input=['research_title'=>'  Mixed CASE   Research  ','publication_type_id'=>'6','publication_subtype_id'=>'6','publication_scope_id'=>'1','author_names'=>['First Author','External Author'],
        'abstract_text'=>str_repeat('word ',250),'publication_keywords'=>['Alpha','Beta','Gamma','Delta'],
        'publication_metadata'=>['conference_name'=>'Example conference','proceedings_title'=>'Published proceedings','publication_year'=>'2026'],'publication_url'=>'https://example.org/paper'];
    // Only file metadata validation is exercised; no real upload or publication is created.
    $files=['supporting_file'=>['name'=>'paper.pdf','error'=>UPLOAD_ERR_OK,'tmp_name'=>'not-an-upload','size'=>100]];
    $valid=publicationValidate($input,$files,$catalog);
    $check(!$valid['errors'],'Valid short-paper metadata rejected: '.implode('; ',$valid['errors']));
    $check($valid['data']['research_title']==='Mixed CASE Research','Title whitespace/capitalization changed incorrectly.');
    $check(publicationAuthors($valid['data'])===['First Author','External Author'],'Author order lost.');
    $check($valid['data']['publication_type']==='conference','Compatibility category incorrect.');
    $check($valid['rules']['manuscript_page_limit']===0&&!$valid['rules']['references_required'],'Unverified page/reference limit imposed.');
    $bad=$input; $bad['abstract_text'].=' extra';
    $check(isset(publicationValidate($bad,$files,$catalog)['errors']['abstract_text']),'251 words accepted.');
    foreach([['Alpha','Beta','Gamma'],['Alpha','Beta','Gamma',' alpha ']] as $keywords) {
        $bad=$input; $bad['publication_keywords']=$keywords;
        $check(isset(publicationValidate($bad,$files,$catalog)['errors']['publication_keywords']),'Invalid keyword count/duplicates accepted.');
    }
    $bad=$input; $bad['publication_subtype_id']='1';
    $check(isset(publicationValidate($bad,$files,$catalog)['errors']['publication_subtype_id']),'Mismatched subtype accepted.');
    $check(isset(publicationValidate($input,[],$catalog)['errors']['supporting_file']),'Required published paper not enforced.');
    $bad=$input; $bad['publication_url']='javascript:alert(1)';
    $check(isset(publicationValidate($bad,$files,$catalog)['errors']['publication_url']),'Unsafe URL accepted.');
    $bad=$input; $bad['indexing']=['Scopus'];
    $check(isset(publicationValidate($bad,$files,$catalog)['errors']['indexing_evidence']),'Unsupported indexing assertion accepted.');
    $bad['indexing_evidence']='Publisher index listing';
    $check(!publicationValidate($bad,$files,$catalog)['errors'],'Index evidence rejected.');
    $bad=$input; $bad['publication_scope_id']='3';
    $check(publicationValidate($bad,$files,$catalog)['data']['indexing_information']===null,'Scope inferred indexing.');
    foreach(['publication_type_id','publication_scope_id','author_names','publication_keywords','indexing','sdgs','program_id','publication_date','declared_page_count','publication_metadata'] as $field) {
        $bad=$input; $bad[$field]=[['malformed']]; $result=publicationValidate($bad,$files,$catalog);
        $check((bool)$result['errors'],'Malformed input accepted: '.$field);
    }
    $badFiles=$files; $badFiles['supporting_file']['name']=[];
    $check(isset(publicationValidate($input,$badFiles,$catalog)['errors']['supporting_file']),'Malformed file metadata accepted.');
    $saved=$valid['data']; $saved['file_path']='uploads/publications/in-memory.pdf';
    $changed=$catalog; $changed['rules'][6]['abstract_word_limit']=10; $changed['rules'][6]['keyword_count']=2;
    $check(!publicationValidate($input,[],$changed,$saved)['errors'],'Changed settings invalidated the saved snapshot.');
    $changed['types'][6]['is_active']=0; $changed['subtypes'][6]['is_active']=0;
    $check(!publicationValidate($input,[],$changed,$saved)['errors'],'Saved inactive classification cannot be retained.');
    $check(isset(publicationValidate($input,$files,$changed)['errors']['publication_type_id']),'Inactive type accepted for a new record.');
    foreach([[5,1],[7,8],[8,12],[9,14]] as [$type,$subtype]) {
        $other=$input; $other['publication_type_id']=(string)$type; $other['publication_subtype_id']=(string)$subtype;
        $other['publication_keywords']=[]; $other['abstract_text']=''; $other['publication_metadata']=[];
        foreach(publicationRules($catalog,$type,$subtype)['required'] as $field) $other['publication_metadata'][$field]=$field==='publication_year'?'2026':'Example source';
        $check(!publicationValidate($other,[],$catalog)['errors'],'Non-conference metadata rules failed for type '.$type);
    }
    $many=$input; $many['author_names']=array_fill(0,10,str_repeat('A',40));
    $manyResult=publicationValidate($many,$files,$catalog);
    $check(!$manyResult['errors']&&count(publicationAuthors($manyResult['data']))===10&&mb_strlen($manyResult['data']['researcher_name'])===255,'Long author list not preserved outside legacy summary.');
    $legacy=['research_title'=>'Legacy title','researcher_name'=>'Original Author; Another Author','publication_type'=>'conference','publication_type_name'=>null,'publication_type_id'=>null,'publication_subtype_id'=>null,'publication_scope_id'=>null,'authors_json'=>null,'keywords_json'=>null,'journal_publisher'=>'Original source','indexing_information'=>'Historical free text','sdg_alignment'=>'Original SDG text'];
    $legacyInput=['research_title'=>'Legacy title','author_names'=>[$legacy['researcher_name']]];
    $legacyResult=publicationValidate($legacyInput,[],$catalog,$legacy);
    $check(!$legacyResult['errors'],'Legacy metadata cannot be retained.');
    $second=publicationValidate($legacyInput,[],$catalog,array_merge($legacy,$legacyResult['data']));
    foreach(['researcher_name','authors_json','keywords_json','publication_type','journal_publisher','indexing_information','sdg_alignment'] as $key) $check($second['data'][$key]===$legacy[$key],'Second edit loses legacy '.$key);
    $typeIdFor=static function(string $code) use($catalog): int {
        foreach($catalog['types'] as $id=>$type) if($type['type_code']===$code)return (int)$id;
        throw new RuntimeException('Missing publication type '.$code);
    };
    $subtypeIdFor=static function(string $code) use($catalog): int {
        foreach($catalog['subtypes'] as $id=>$subtype) if($subtype['subtype_code']===$code)return (int)$id;
        throw new RuntimeException('Missing publication subtype '.$code);
    };
    $scopeId=(int)array_key_first($catalog['scopes']);
    $journalType=$typeIdFor('journal_article'); $reviewSubtype=$subtypeIdFor('review_article');
    $reviewCatalog=$catalog;
    $reviewCatalog['settings']=[];
    $reviewCatalog['rules'][$reviewSubtype]=['abstract_word_limit'=>null,'keyword_count'=>0,'manuscript_required'=>0,'references_required'=>0,'evidence_required'=>1,'manuscript_page_limit'=>null];
    $reviewInput=['research_title'=>'A review of archival systems','publication_type_id'=>(string)$journalType,'publication_subtype_id'=>(string)$reviewSubtype,
        'publication_scope_id'=>(string)$scopeId,'author_names'=>['Review Author','External Contributor'],'abstract_text'=>'','publication_keywords'=>[],
        'publication_metadata'=>['source_title'=>'Journal of Records','publication_year'=>'2026'],'publication_url'=>'https://example.org/review'];
    $review=publicationValidate($reviewInput,[],$reviewCatalog);
    $check(!$review['errors'],'Review Article rejected without optional DOI, volume, issue, abstract, keywords, or full-text: '.implode('; ',$review['errors']));
    $check($review['rules']['abstract_visible']&&$review['rules']['keywords_visible']&&!$review['rules']['abstract_required'],'Review Article abstract/keywords visibility or optionality is incorrect.');
    $check(in_array('source_title',$review['rules']['required'],true)&&in_array('publication_year',$review['rules']['required'],true),'Review Article journal title/year requirements missing.');
    $check(!in_array('conference_name',$review['rules']['fields'],true)&&!in_array('book_title',$review['rules']['fields'],true),'Review Article exposes unrelated metadata fields.');
    $authorRows=publicationJson($review['data']['authors_json']);
    $check(($authorRows[0]['role']??'')==='Author'&&publicationAuthors($review['data'])===['Review Author','External Contributor'],'Contributor roles or names/order were not retained.');
    $legacyType=$typeIdFor('journal');
    $legacyAssignment=$reviewInput; $legacyAssignment['publication_type_id']=(string)$legacyType; $legacyAssignment['publication_subtype_id']='';
    $check(isset(publicationValidate($legacyAssignment,[],$catalog)['errors']['publication_type_id']),'Deprecated Legacy type can be assigned to a new record.');
    $savedReview=$review['data']; $savedReview['abstract_text']='Saved review abstract'; $savedReview['keywords_json']=json_encode(['records','archive']);
    $savedMeta=publicationJson($savedReview['publication_metadata_json']); $savedMeta['review_type']='Systematic review';
    $savedReview['publication_metadata_json']=json_encode($savedMeta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $bookType=$typeIdFor('book'); $authoredBook=$subtypeIdFor('authored_book');
    $switchInput=['research_title'=>'A review of archival systems','publication_type_id'=>(string)$bookType,'publication_subtype_id'=>(string)$authoredBook,
        'publication_scope_id'=>(string)$scopeId,'author_names'=>['Review Author','External Contributor'],'publication_keywords'=>[],
        'publication_metadata'=>['source_title'=>'Tampered hidden journal title','publisher'=>'Example Press','publication_year'=>'2026'],'publication_url'=>'https://example.org/review'];
    $switched=publicationValidate($switchInput,[],$catalog,$savedReview);
    $switchedMeta=publicationJson($switched['data']['publication_metadata_json']);
    $check(!$switched['errors'],'Reclassification to an active Book subtype failed: '.implode('; ',$switched['errors']));
    $check(($switchedMeta['source_title']??'')==='Journal of Records'&&($switchedMeta['review_type']??'')==='Systematic review','Hidden historical metadata was overwritten during reclassification.');
    $check($switched['data']['abstract_text']==='Saved review abstract'&&$switched['data']['keywords_json']===$savedReview['keywords_json'],'Hidden abstract or keywords were not preserved.');
    $check($switched['data']['publication_type']==='other','Explicit Legacy reclassification did not update its compatibility classification.');
    $hiddenRuleCatalog=$catalog;
    $hiddenRuleCatalog['rules'][$authoredBook]['keyword_count']=4;
    $hiddenRuleCatalog['settings']['publication_rule_'.$authoredBook]=['abstract_visible'=>false,'keywords_visible'=>false];
    $hiddenRuleResult=publicationValidate($switchInput,[],$hiddenRuleCatalog,$savedReview);
    $check(!$hiddenRuleResult['errors']&&$hiddenRuleResult['data']['keywords_json']===$savedReview['keywords_json'],'Validation ran against hidden keyword requirements.');
    $activeSubtypeCount=0;
    foreach($catalog['subtypes'] as $subtypeId=>$subtype) {
        $parent=$catalog['types'][(int)$subtype['publication_type_id']]??[];
        if(empty($parent['is_active'])||empty($parent['is_proposed'])||empty($subtype['is_active']))continue;
        $activeSubtypeCount++;
        $rules=publicationRules($catalog,(int)$subtype['publication_type_id'],(int)$subtypeId);
        $check(in_array('publication_year',$rules['fields'],true)&&in_array('publication_year',$rules['required'],true),'Year must be visible and required for '.$subtype['subtype_code'].'.');
        $testInput=['research_title'=>'Subtype regression '.$subtype['subtype_code'],'publication_type_id'=>(string)$subtype['publication_type_id'],'publication_subtype_id'=>(string)$subtypeId,
            'publication_scope_id'=>(string)$scopeId,'author_names'=>['Test Contributor'],'abstract_text'=>'A short optional abstract.','publication_keywords'=>[],
            'publication_url'=>'https://example.org/publication','publication_metadata'=>[]];
        foreach($rules['required'] as $fieldKey) {
            $fieldKind=publicationFieldCatalog()[$fieldKey][1]??'text';
            $testInput['publication_metadata'][$fieldKey]=match($fieldKind){'year'=>'2026','date'=>'2026-02-01','url'=>'https://example.org/source',default=>'Example value'};
        }
        if((int)$rules['keyword_count']>0)$testInput['publication_keywords']=array_map(static fn($n)=>'Keyword '.$n,range(1,(int)$rules['keyword_count']));
        $testFiles=[];
        foreach(['supporting_file'=>'published.pdf','references_file'=>'references.pdf'] as $slot=>$filename) if(!empty($rules[$slot==='supporting_file'?'manuscript_required':'references_required'])) $testFiles[$slot]=['name'=>$filename,'error'=>UPLOAD_ERR_OK,'tmp_name'=>'in-memory-only','size'=>100];
        $subtypeResult=publicationValidate($testInput,$testFiles,$catalog);
        $check(!$subtypeResult['errors'],'Configured validation failed for '.$subtype['subtype_code'].': '.implode('; ',$subtypeResult['errors']));
    }
    $check($activeSubtypeCount===15,'Expected all 15 proposed active subtypes; found '.$activeSubtypeCount.'.');
    $check(publicationYear(['created_at'=>'2026-11-01'])==='','Encoding year incorrectly used as publication year.');
    $check(publicationWordCount("research-based children's 研究 results")===4,'Unicode word counting differs.');
    $db->query('SELECT r.publication_id,pt.label,ps.label,psc.label,pra.area_name,pg.program_code,'.publicationYearSql().' AS publication_year FROM publications r'.publicationJoinsSql().' LIMIT 0');
    $check(true,'Directory projection compiles.');
    $check($db->query("SELECT LOWER(REGEXP_REPLACE(TRIM(' A   B '),'[[:space:]]+',' '))")->fetch_row()[0]==='a b','Duplicate normalization is unsupported.');
    $compiled=0;
    foreach(['includes/publication-classification.php','includes/publication-archive-form.php','includes/publication-archive-detail.php','pages/admin/admin-publication-classifications.php','pages/shared/download-research-document.php'] as $source) {
        $tokens=token_get_all(file_get_contents(dirname(__DIR__).'/'.$source));
        foreach($tokens as $token) {
            if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING)continue;
            $raw=substr($token[1],1,-1);
            $sql=$token[1][0]==="'"?str_replace(["\\'","\\\\"],["'","\\"],$raw):stripcslashes($raw);
            if(!preg_match('/^SELECT\b/i',$sql)||preg_match('/\bFOR UPDATE\b/i',$sql)||!preg_match('/\b(publication_types|publication_subtypes|publication_form_rules|publication_scopes|publications|research_areas|programs|application_settings)\b/i',$sql))continue;
            // Fragments and dynamically interpolated statements are covered by current-source review.
            if(str_ends_with($sql,'WHERE ')||str_ends_with($sql,'FROM ')||str_ends_with($sql,'SELECT * FROM publications WHERE publication_id<>? AND (LOWER(REGEXP_REPLACE(TRIM(research_title),\'[[:space:]]+\',\' \'))=?'))continue;
            if(str_contains($sql,'INSERT INTO publications (`'))continue;
            $checkingSqlSource=$source.':'.$token[2];
            $statement=$db->prepare($sql); $statement->close(); $compiled++;
        }
    }
    $summary=['validation_assertions'=>$checks,'literal_nonlocking_selects_prepared'=>$compiled,'live_publications'=>(int)$db->query('SELECT COUNT(*) FROM publications')->fetch_row()[0]];
    if(isset($argv[1])) {
        $backup=realpath($argv[1]); $root=realpath(dirname(__DIR__).'/backups');
        $check($backup&&$root&&dirname($backup)===$root,'Backup must be a checkpoint directly inside backups.');
        $inventory=json_decode(file_get_contents($backup.'/inventory.json'),true,512,JSON_THROW_ON_ERROR);
        $verification=json_decode(file_get_contents($backup.'/verification.json'),true,512,JSON_THROW_ON_ERROR);
        $dump=file_get_contents($backup.'/rms_db.sql');
        $check(hash_equals($verification['sha256'],hash('sha256',$dump)),'Backup dump checksum changed.');
        $preserved=0; $liveChanges=[];
        foreach($inventory['tables'] as $table=>$expected) {
            if($table==='schema_migrations')continue; // The two authorized migrations change the ledger.
            $check((bool)preg_match('/^[a-zA-Z0-9_]+$/D',$table),'Unexpected backup table identifier.');
            $check((bool)preg_match('/CREATE TABLE `'.preg_quote($table,'/').'` \((.*?)\) ENGINE=/s',$dump,$match),'Original table definition unavailable.');
            preg_match_all('/^  `([a-zA-Z0-9_]+)` /m',$match[1],$columns);
            $check((bool)$columns[1],'Original columns unavailable.');
            $where='';
            if($table==='activity_log') {
                preg_match_all('/^INSERT INTO `activity_log` .*? VALUES \(([0-9]+),/m',$dump,$ids);
                $maxId=$ids[1]?max(array_map('intval',$ids[1])):0;
                $where=' WHERE log_id<='.$maxId;
                $liveChanges['new_audit_entries']=(int)$db->query('SELECT COUNT(*) FROM activity_log WHERE log_id>'.$maxId)->fetch_row()[0];
            }
            $rows=$db->query('SELECT `'.implode('`,`',$columns[1]).'` FROM `'.$table.'`'.$where)->fetch_all(MYSQLI_ASSOC);
            if($table==='users') {
                $oldRows=publicationCheckDumpRows($dump,$table);
                $originalHashes=array_map(static fn($row)=>hash('sha256',serialize($row)),$oldRows); sort($originalHashes);
                $check(hash('sha256',implode('',$originalHashes))===$expected['data_sha256'],'Backup account parser integrity failed.');
                $oldUsers=array_column($oldRows,null,'user_id'); $changedColumns=[];
                foreach($rows as $offset=>$row) {
                    $check(isset($oldUsers[$row['user_id']]),'Account inventory changed.');
                    foreach($row as $key=>$value) if($value!==$oldUsers[$row['user_id']][$key]) {
                        $changedColumns[$key]=true;
                        $check(in_array($key,['last_login','updated_at'],true),'Account field changed since backup: '.$key);
                        $rows[$offset][$key]=$oldUsers[$row['user_id']][$key];
                    }
                }
                $liveChanges['account_columns_changed']=array_keys($changedColumns);
            }
            $hashes=[];
            foreach($rows as $row)$hashes[]=hash('sha256',serialize($row));
            sort($hashes);
            $check(count($hashes)===$expected['count']&&hash('sha256',implode('',$hashes))===$expected['data_sha256'],'Existing rows changed in '.$table);
            $preserved++;
        }
        foreach($inventory['files'] as $relative=>$hash) {
            $live=realpath(dirname(__DIR__).'/'.$relative); $uploads=realpath(dirname(__DIR__).'/uploads');
            $check($live&&$uploads&&str_starts_with($live,$uploads.DIRECTORY_SEPARATOR),'Attachment is missing or outside uploads.');
            $check(hash_equals($hash,hash_file('sha256',$live))&&hash_equals($hash,hash_file('sha256',$backup.'/'.$relative)),'Attachment checksum changed.');
        }
        $summary['original_tables_preserved']=$preserved; $summary['live_activity_differences']=$liveChanges;
        $summary['attachments_preserved']=count($inventory['files']); $summary['restore']='not_rehearsed';
    }
    $db->rollback();
    echo json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
} catch(Throwable $e) {
    if(isset($db))$db->rollback();
    fwrite(STDERR,'Publication check failed: '.($e instanceof mysqli_sql_exception?'SQL error '.$e->getCode().' at '.($checkingSqlSource??basename($e->getFile()).':'.$e->getLine()).' after '.$checks.' assertions.':$e->getMessage()).PHP_EOL); exit(1);
}
