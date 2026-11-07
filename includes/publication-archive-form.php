<?php
// Publication-specific fields inside the existing archive route and persistence model.
require_once __DIR__ . '/publication-classification.php';
require_once __DIR__ . '/archival-record-ui.php';
if ($record && (!archiveCan($conn,'publication',$record,$user,'manage') || !empty($record['deactivated_at']))) archiveDeny();
$publicationCatalogForActor = static function (array $catalog) use ($role, $userId, $record): array {
    if ($role !== 'research_staff' || $record) return $catalog;
    if (!hasModulePermission($userId, 'publication_dissemination', 'manage', 'publication_add')) archiveDeny();
    $allowedTypeIds = [];
    foreach ($catalog['types'] as $typeId => $type) {
        if (in_array((string) ($type['type_code'] ?? ''), ['journal', 'conference'], true)) $allowedTypeIds[] = (int) $typeId;
    }
    if (!$allowedTypeIds) archiveDeny();
    $allowedTypeLookup = array_fill_keys($allowedTypeIds, true);
    $catalog['types'] = array_intersect_key($catalog['types'], $allowedTypeLookup);
    $catalog['subtypes'] = array_filter($catalog['subtypes'], static fn(array $subtype): bool => isset($allowedTypeLookup[(int) $subtype['publication_type_id']]));
    return $catalog;
};
$catalog=$publicationCatalogForActor(publicationCatalog($conn));
$catalogVersion=hash('sha256',json_encode($catalog,JSON_THROW_ON_ERROR));
$contributorsBefore=$record?archiveContributors($conn,'publication',$id):[];
$version=$record?hash('sha256',json_encode([$record,$contributorsBefore],JSON_THROW_ON_ERROR)):'';
$selectedAuthors=array_map('intval',array_column($contributorsBefore,'user_id'));
$allContributors=rmsOutputContributorOptions($conn);
$facultyAuthorName='';
if(!$record&&$role==='faculty') {
    foreach($allContributors as $contributor) {
        if((int)$contributor['user_id']!==$userId) continue;
        $facultyAuthorName=trim($contributor['first_name'].' '.$contributor['last_name']);
        $selectedAuthors[]=$userId;
        break;
    }
}
$facultyProgramOptions=archiveQuery($conn,"SELECT p.program_id, p.program_code, p.program_name FROM programs p WHERE p.status = 1 AND EXISTS (SELECT 1 FROM faculty_program_assignments fpa WHERE fpa.program_id = p.program_id) ORDER BY FIELD(p.program_code, 'BSIT', 'BSCS'), p.program_name")->get_result()->fetch_all(MYSQLI_ASSOC);
foreach(array_diff($selectedAuthors,array_map('intval',array_column($allContributors,'user_id'))) as $uid) {
    $old=archiveQuery($conn,'SELECT user_id,first_name,last_name,role FROM users WHERE user_id=?',[$uid])->get_result()->fetch_assoc();
    if($old) { $old['last_name'].=' (historical account)'; $allContributors[]=$old; }
}
$facultyOwners=archiveQuery($conn,"SELECT user_id,first_name,last_name,program FROM users WHERE role='faculty' AND deleted_at IS NULL AND status='active' ORDER BY last_name,first_name")->get_result()->fetch_all(MYSQLI_ASSOC);
$areaOptions=archiveQuery($conn,'SELECT area_id,area_name,is_active FROM research_areas WHERE is_active=1 OR area_id=? ORDER BY area_name',[$record['research_area_id']??0])->get_result()->fetch_all(MYSQLI_ASSOC);
$metadata=publicationJson($record['publication_metadata_json']??null);
$savedProgramId=is_scalar($metadata['program_id']??null)?(filter_var((string)$metadata['program_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0):0;
$programOptions=archiveQuery($conn,'SELECT program_id,program_code,program_name,status FROM programs WHERE status=1 OR program_id=? ORDER BY program_name',[$savedProgramId])->get_result()->fetch_all(MYSQLI_ASSOC);
$projects=archiveQuery($conn,'SELECT project_id,title FROM research_projects WHERE deleted_at IS NULL'.($role==='faculty'?' AND (created_by=? OR student_id=? OR adviser_id=?)':'').' ORDER BY title',$role==='faculty'?[$userId,$userId,$userId]:[])->get_result()->fetch_all(MYSQLI_ASSOC);
$input=$record??[];
// Prepared SELECTs return numeric columns as PHP integers; form value helpers expect strings.
foreach(['publication_type_id','publication_subtype_id','publication_scope_id'] as $classificationKey) {
    $input[$classificationKey]=isset($record[$classificationKey])?(string)$record[$classificationKey]:'';
}
$input['author_names']=publicationAuthors($record??[])?:($facultyAuthorName!==''?[$facultyAuthorName]:['']);
$input['publication_keywords']=publicationJson($record['keywords_json']??null);
$input['publication_metadata']=$metadata;
foreach(['volume'=>'volume','issue'=>'issue','pages'=>'page_numbers'] as $key=>$column) if(!isset($input['publication_metadata'][$key])&&!empty($record[$column])) $input['publication_metadata'][$key]=$record[$column];
$input['indexing']=is_array($metadata['indexing']??null)?array_values(array_filter($metadata['indexing'],'is_string')):[];
$input['indexing_evidence']=is_string($metadata['indexing_evidence']??null)?$metadata['indexing_evidence']:'';
$input['sdgs']=is_array($metadata['sdgs']??null)?array_values(array_filter($metadata['sdgs'],'is_scalar')):[];
$input['program_id']=$savedProgramId?(string)$savedProgramId:'';
$input['declared_page_count']=is_scalar($metadata['declared_page_count']??null)?(string)$metadata['declared_page_count']:'';
$input['legacy_source']=(string)($record['journal_publisher']??'');
$ownerId=$record['researcher_id']??($role==='faculty'?$userId:null);
$projectId=(string)($record['research_project_id']??'');
$fieldErrors=[]; $duplicates=[]; $duplicateToken='';
$rules=publicationEffectiveRules($catalog,(int)($record['publication_type_id']??0),(int)($record['publication_subtype_id']??0),$record);
if($_SERVER['REQUEST_METHOD']==='POST') {
    $input=$_POST;
    $input['publication_metadata']=array_merge($metadata,is_array($_POST['publication_metadata']??null)?$_POST['publication_metadata']:[]);
    if(!isCsrfTokenValid($_POST['csrf_token']??null)) $errors[]='Your form expired. Refresh and try again.';
    if(publicationText($input,'action')!=='save_record'||publicationText($input,'record_id')!==(string)$id) $errors[]='Invalid archive request.';
    if(!hash_equals($catalogVersion,publicationText($input,'catalog_version'))) $errors[]='Publication settings changed while this form was open. Review the refreshed rules before saving.';
    $validated=publicationValidate($input,$_FILES,$catalog,$record);
    $data=$validated['data']; $rules=$validated['rules']; $fieldErrors=$validated['errors'];
    if($role==='admin'||(!$record&&$role==='research_staff')) {
        $ownerId=filter_var(publicationText($input,'owner_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:null;
        $changedOwner=!$record||(string)($record['researcher_id']??'')!==(string)($ownerId??'');
        if($changedOwner&&(!$ownerId||!in_array($ownerId,array_map('intval',array_column($facultyOwners,'user_id')),true))) $fieldErrors['owner_id']='Choose an active faculty record owner.';
        if($record&&$changedOwner&&publicationText($input,'confirm_owner_change')!=='1') $fieldErrors['owner_id']='Confirm the ownership correction.';
    } elseif(isset($input['owner_id'])&&publicationText($input,'owner_id')!==(string)$ownerId) archiveDeny();
    $selectedAuthors=[]; $rawContributors=$input['contributor_ids']??[];
    if(!is_array($rawContributors)||count($rawContributors)>500) $fieldErrors['contributor_ids']='Choose valid linked authors.';
    else foreach($rawContributors as $raw) {
        $uid=is_scalar($raw)?filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;
        if(!$uid) $fieldErrors['contributor_ids']='Choose valid linked authors.'; else $selectedAuthors[]=$uid;
    }
    $selectedAuthors=array_values(array_unique($selectedAuthors)); sort($selectedAuthors);
    $newAuthors=array_values(array_diff($selectedAuthors,array_map('intval',array_column($contributorsBefore,'user_id'))));
    if(rmsValidateOutputContributorIds($conn,$newAuthors,0)===null) $fieldErrors['contributor_ids']='New contributor links must refer to active faculty or student accounts.';
    $projectId=publicationText($input,'research_project_id');
    if($projectId!==(string)($record['research_project_id']??'')&&$projectId!==''&&(!ctype_digit($projectId)||!in_array((int)$projectId,array_map('intval',array_column($projects,'project_id')),true))) $fieldErrors['research_project_id']='Choose an authorized research project.';
    if($data['research_area_id']&&!in_array($data['research_area_id'],array_map('intval',array_column($areaOptions,'area_id')),true)) $fieldErrors['research_area_id']='Choose an active research area or retain the saved area.';
    if($validated['metadata']['program_id']&&!in_array($validated['metadata']['program_id'],array_map('intval',array_column($programOptions,'program_id')),true)) $fieldErrors['program_id']='Choose an active program or retain the saved program.';
    $candidate=array_merge($record??[],$data,['researcher_id'=>$ownerId,'archival_status'=>$record['archival_status']??'archived']);
    if ($role === 'research_staff' && !$record) {
        if (!hasModulePermission($userId, 'publication_dissemination', 'manage', 'publication_add')
            || !in_array((string) ($candidate['publication_type'] ?? ''), ['journal', 'conference'], true)) archiveDeny();
    } elseif(!archiveCan($conn,'publication',$candidate,$user,'manage')) archiveDeny();
    if(!$errors&&!$fieldErrors) {
        $duplicates=publicationDuplicates($conn,$data,$user,$id);
        $duplicateToken=hash('sha256',json_encode([$data,array_column($duplicates,'publication_id')],JSON_THROW_ON_ERROR));
        if($duplicates&&(publicationText($input,'confirm_duplicate')!=='1'||!hash_equals($duplicateToken,publicationText($input,'duplicate_token')))) $errors[]='Review the possible duplicate records below and confirm before saving.';
    }
    if(!$errors&&!$fieldErrors) {
        $newUploads=[];
        try {
            $conn->begin_transaction();
            publicationLockCatalog($conn);
            if(!hash_equals($catalogVersion,hash('sha256',json_encode($publicationCatalogForActor(publicationCatalog($conn)),JSON_THROW_ON_ERROR)))) throw new RuntimeException('Publication settings changed. Review this form and save again.');
            $locked=$id?archiveLoad($conn,'publication',$id,true):null;
            if($id&&(!$locked||!archiveCan($conn,'publication',$locked,$user,'manage')||!empty($locked['deactivated_at']))) throw new RuntimeException('This record is no longer editable.');
            if($locked&&!hash_equals(hash('sha256',json_encode([$locked,archiveContributors($conn,'publication',$id)],JSON_THROW_ON_ERROR)),publicationText($input,'record_version'))) throw new RuntimeException('The record changed while you were editing. Reopen it before saving.');
            if(!$locked||(string)$ownerId!==(string)($locked['researcher_id']??'')) {
                if(!archiveQuery($conn,"SELECT user_id FROM users WHERE user_id=? AND role='faculty' AND status='active' AND deleted_at IS NULL FOR UPDATE",[$ownerId])->get_result()->fetch_row()) throw new RuntimeException('The selected faculty owner is unavailable.');
            }
            if(rmsValidateOutputContributorIds($conn,$newAuthors,0)===null) throw new RuntimeException('A linked account is no longer available.');
            $data['researcher_id']=$ownerId;
            $data['researcher_category']=$locked&&(string)$ownerId===(string)($locked['researcher_id']??'')?$locked['researcher_category']:'faculty';
            $data['research_project_id']=$projectId!==''?(int)$projectId:null;
            $data['last_modified_by']=$userId;
            if(!$locked) { $data['encoded_by']=$userId; $data['archival_status']='archived'; $data['status']='published'; }
            $maxKb=publicationUploadLimit($rules);
            foreach(['supporting_file'=>['file_path','file_name'],'evidence_file'=>['evidence_file_path','evidence_file_name'],'references_file'=>['references_file_path','references_file_name']] as $slot=>[$pathKey,$nameKey]) {
                if(!$validated['uploads'][$slot]) continue;
                $upload=handleRmsUpload(['inputName'=>$slot,'folderTarget'=>'publications','type'=>'other','accept'=>['.pdf'],'maxSize'=>$maxKb,'archiveAuthorized'=>true,'archiveAction'=>$locked?'edit':'create'],$_FILES,$conn);
                if(empty($upload['success'])) throw new RuntimeException((string)($upload['error']??'The PDF could not be uploaded.'));
                $newUploads[]=$upload; $data[$pathKey]=$upload['file_path']; $data[$nameKey]=$upload['original_name'];
            }
            $columns=array_keys($data); $params=array_values($data);
            if($locked) {
                $set=implode(', ',array_map(static fn($column)=>"`$column` = ?",$columns)); $params[]=$id;
                archiveQuery($conn,"UPDATE publications SET $set,updated_at=CURRENT_TIMESTAMP WHERE publication_id=?",$params);
                $savedId=$id;
            } else {
                archiveQuery($conn,'INSERT INTO publications (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',$params);
                $savedId=$conn->insert_id;
            }
            archiveSyncAuthors($conn,'publication',$savedId,$selectedAuthors,$validated['rules']['contributor_role']??'Author');
            $before=$locked??[]; $before['authors_linked']=$contributorsBefore;
            $after=archiveLoad($conn,'publication',$savedId); $after['authors_linked']=archiveContributors($conn,'publication',$savedId);
            archiveAudit($conn,'publication',$savedId,$locked?($role==='admin'?'admin_correction':'edited'):'created',$before,$after);
            $conn->commit();
            $_SESSION['publications_flash']=['type'=>'success','message'=>$locked?'Publication changes saved.':'Publication saved to the archive.'];
            $canBrowseArchive = $role !== 'research_staff'
                || hasModulePermission($userId, 'publication_dissemination', 'view', 'publication_records');
            $afterSaveUrl = !$locked && !$canBrowseArchive
                ? $formUrl . '?module_page=publication_add&saved=1'
                : $detailUrl . '?id=' . $savedId . $navigationSuffix;
            header('Location: '.$afterSaveUrl); exit;
        } catch(Throwable $e) {
            $conn->rollback();
            foreach($newUploads as $upload) {
                $path=realpath(dirname(__DIR__).'/'.$upload['file_path']); $root=realpath(UPLOADS_DIR.'/publications');
                if($path&&$root&&str_starts_with($path,$root.DIRECTORY_SEPARATOR)&&is_file($path)) unlink($path);
            }
            $errors[]=$e instanceof mysqli_sql_exception?'The publication could not be saved. No record changes were committed.':$e->getMessage();
        }
    }
}
$typeId=(int)publicationText($input,'publication_type_id'); $subtypeId=(int)publicationText($input,'publication_subtype_id');
$savedTypeId=(int)($record['publication_type_id']??0);
$errorFor=static function(string $key) use($fieldErrors): void {
    if(isset($fieldErrors[$key])) echo '<small class="publication-field-error" id="error-'.archiveEscape($key).'" tabindex="-1">'.archiveEscape($fieldErrors[$key]).'</small>';
};
$field=static function(string $key,string $label,string $type='text',int $max=500,bool $required=false) use($input,$fieldErrors,$errorFor): void {
    $value=publicationText($input,$key);
    echo '<div class="archive-field"><label'.($key==='research_title'?' id="publication-title-label" data-default-label="Publication title"':'').' for="pub-'.archiveEscape($key).'">'.archiveEscape($label).($required?' *':'').'</label>';
    $attrs=' id="pub-'.archiveEscape($key).'" name="'.archiveEscape($key).'" maxlength="'.$max.'"'.($required?' required':'').(isset($fieldErrors[$key])?' aria-invalid="true" aria-describedby="error-'.archiveEscape($key).'"':'');
    if($type==='textarea') echo '<textarea'.$attrs.' rows="5">'.archiveEscape($value).'</textarea>';
    else echo '<input'.$attrs.' type="'.archiveEscape($type).'" value="'.archiveEscape($value).'">';
    $errorFor($key); echo '</div>';
};
$clientRules=[];
$clientTypeRules=[];
foreach($catalog['types'] as $key=>$type) {
    $clientTypeRules[$key]=publicationEffectiveRules($catalog,$key,0,$record);
    $clientTypeRules[$key]['max_attachment_kb']=publicationUploadLimit($clientTypeRules[$key]);
}
$legacyClientRules=publicationEffectiveRules($catalog,0,0,$record);
$legacyClientRules['max_attachment_kb']=publicationUploadLimit($legacyClientRules);
foreach($catalog['subtypes'] as $key=>$subtype) {
    $t=(int)$subtype['publication_type_id'];
    $clientRules[$key]=publicationEffectiveRules($catalog,$t,$key,$record);
    $clientRules[$key]['max_attachment_kb']=publicationUploadLimit($clientRules[$key]);
}
$selectedRules=$clientRules[$subtypeId]??$clientTypeRules[$typeId]??$legacyClientRules;
$titleLabel=$selectedRules['title_label']??'Publication title';
$contributorLabel=$selectedRules['contributor_label']??'Authors / contributors';
$documentLabel=$selectedRules['document_label']??'Published document';
archiveShellOpen($user,$c,$record?'Edit Publication Record':'Add Publication Record');
?>
<link rel="stylesheet" href="<?= archiveEscape(SITE_URL.'css/publication-archive.css') ?>">
<div class="archive-heading"><div><h1><?= $record?'Edit publication':'Add publication' ?></h1><p class="archive-muted">Archive an already-published scholarly work. Required fields are marked *.</p></div><?php $canBrowseArchive = $role !== 'research_staff' || hasModulePermission($userId, 'publication_dissemination', 'view', 'publication_records'); $backUrl = $record ? $detailUrl.'?id='.$id.$navigationSuffix : ($canBrowseArchive ? $listUrl.$navigationQuery : SITE_URL.'pages/staff/staff-dashboard.php'); ?><a class="archive-button secondary" href="<?= archiveEscape($backUrl) ?>">Back to <?= $record?'record':($canBrowseArchive?'publications':'dashboard') ?></a></div>
<?php if ($flash && ($flash['type'] ?? '') === 'success'): ?><div class="archive-success" role="status"><?= archiveEscape($flash['message'] ?? '') ?></div><?php endif; ?>
<?php if($errors||$fieldErrors): ?><div class="archive-error" role="alert"><strong>Review the following before saving.</strong><ul><?php foreach($errors as $error): ?><li><?= archiveEscape($error) ?></li><?php endforeach; ?><?php foreach($fieldErrors as $key=>$error): ?><li><a href="#error-<?= archiveEscape((string)$key) ?>"><?= archiveEscape($error) ?></a></li><?php endforeach; ?></ul><p>Select an error to jump to its field. Text entries are retained. Select any new attachments again before saving.</p></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="publication-form" id="publication-form">
<?= csrfField() ?><input type="hidden" name="action" value="save_record"><input type="hidden" name="record_id" value="<?= $id ?>"><input type="hidden" name="record_version" value="<?= archiveEscape($version) ?>"><input type="hidden" name="catalog_version" value="<?= archiveEscape($catalogVersion) ?>">
<?php if($duplicates): ?><section class="archive-card publication-notice"><h2>Possible duplicate publications</h2><p>Similar titles can describe different works. Review the year, source, authors and DOI before continuing.</p><ul><?php foreach($duplicates as $duplicate): ?><li><a href="<?= archiveEscape($detailUrl.'?id='.(int)$duplicate['publication_id']) ?>" target="_blank" rel="noopener"><?= archiveEscape($duplicate['research_title']) ?></a> — <?= archiveEscape(publicationYear($duplicate).' · '.publicationSource($duplicate).' · '.publicationAuthorLabel($duplicate)) ?></li><?php endforeach; ?></ul><input type="hidden" name="duplicate_token" value="<?= archiveEscape($duplicateToken) ?>"><label><input type="checkbox" name="confirm_duplicate" value="1"> I reviewed these records and want to save this publication.</label></section><?php endif; ?>
<section class="archive-card"><h2>Publication classification</h2><p class="archive-muted">Detailed types and subtypes are a proposed RMS framework awaiting Research Office confirmation. Scope and indexing are independent.</p><div class="archive-grid">
<div class="archive-field"><label for="publication-type-id">Publication type <?= !$record?'*':'' ?></label><select id="publication-type-id" name="publication_type_id" <?= !$record?'required':'' ?>><option value=""><?= $record&&!$record['publication_type_id']?'Keep original classification: '.archiveEscape(publicationTypeLabel($record)):'Select a type' ?></option><?php foreach($catalog['types'] as $key=>$type): if(!(int)$type['is_proposed']&&$key!==$savedTypeId)continue; if(!(int)$type['is_active']&&$key!==$savedTypeId)continue; ?><option value="<?= $key ?>" <?= $typeId===$key?'selected':'' ?>><?= archiveEscape($type['label'].(!(int)$type['is_proposed']?' (legacy, saved)':(!(int)$type['is_active']?' (inactive, saved)':''))) ?></option><?php endforeach; ?></select><?php $errorFor('publication_type_id'); ?></div>
<div class="archive-field"><label for="publication-subtype-id">Publication subtype</label><select id="publication-subtype-id" name="publication_subtype_id"><option value="">Select a subtype</option><?php foreach($catalog['types'] as $t): if(!(int)$t['is_proposed']&&(int)$t['publication_type_id']!==$savedTypeId)continue; ?><optgroup label="<?= archiveEscape($t['label']) ?>"><?php foreach($catalog['subtypes'] as $key=>$subtype): if((int)$subtype['publication_type_id']!==(int)$t['publication_type_id']||(!(int)$subtype['is_active']&&$key!==(int)($record['publication_subtype_id']??0)))continue; ?><option value="<?= $key ?>" data-type-id="<?= (int)$subtype['publication_type_id'] ?>" <?= $subtypeId===$key?'selected':'' ?>><?= archiveEscape($subtype['label']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select><?php $errorFor('publication_subtype_id'); ?></div>
<div class="archive-field"><label for="publication-scope-id">Publication scope</label><select id="publication-scope-id" name="publication_scope_id"><option value="">Not recorded</option><?php foreach($catalog['scopes'] as $key=>$scope): if(!(int)$scope['is_active']&&$key!==(int)($record['publication_scope_id']??0))continue; ?><option value="<?= $key ?>" <?= (int)publicationText($input,'publication_scope_id')===$key?'selected':'' ?>><?= archiveEscape($scope['label']) ?></option><?php endforeach; ?></select><?php $errorFor('publication_scope_id'); ?><small>Choose according to the publication venue or reporting policy. EARIST authorship alone does not establish Local scope.</small></div>
</div><p id="publication-rule-note" class="publication-notice" role="status"></p><noscript><p>Choose a subtype within its type. All metadata fields are shown; server validation applies the chosen subtype rules.</p></noscript></section>
<section class="archive-card"><h2>Research information</h2><?php $field('research_title',$titleLabel,'text',500,true); ?>
<fieldset class="publication-author-field"><legend>Authors in publication order *</legend><div id="publication-authors"><?php $authorInputs=is_array($input['author_names']??null)?array_slice($input['author_names'],0,50):['']; if(!$authorInputs)$authorInputs=['']; foreach($authorInputs as $i=>$name): ?><div class="publication-author-row"><label>Author <span data-author-number><?= $i+1 ?></span><input name="author_names[]" value="<?= archiveEscape(is_string($name)?$name:'') ?>" maxlength="255"></label><button type="button" class="archive-button secondary" data-author-up aria-label="Move author up">↑</button><button type="button" class="archive-button secondary" data-author-down aria-label="Move author down">↓</button><button type="button" class="archive-button secondary" data-author-remove>Remove</button></div><?php endforeach; ?></div><button type="button" id="publication-add-author" class="archive-button secondary">Add author</button><?php $errorFor('author_names'); ?><small>External authors need no RMS account. The record owner and encoder are recorded separately.</small></fieldset>
<details class="publication-author-link-panel"><summary>Link RMS author accounts</summary><div class="publication-author-link-body"><div class="archive-field publication-author-link-row"><div class="publication-author-link-field"><label for="publication-account-course">Filter by course</label><select id="publication-account-course"><option value="">All faculty programs</option><?php foreach($facultyProgramOptions as $program): ?><option value="<?= (int)$program['program_id'] ?>"><?= archiveEscape($program['program_code'].' — '.$program['program_name']) ?></option><?php endforeach; ?></select></div><div class="publication-author-link-field"><label for="publication-account-author">Add a faculty / researcher name to RMS</label><select id="publication-account-author"><option value="">Choose an account</option><?php foreach($allContributors as $author): $authorLabel = trim($author['first_name'].' '.$author['last_name']); $programLabel = trim((string)($author['program_codes'] ?? '')); $displayLabel = $programLabel !== '' ? $authorLabel.' ('.$programLabel.')' : $authorLabel.' ('.$author['role'].')'; ?><option value="<?= (int)$author['user_id'] ?>" data-name="<?= archiveEscape($authorLabel) ?>" data-program-ids="<?= archiveEscape((string)($author['program_ids'] ?? '')) ?>"><?= archiveEscape($displayLabel) ?></option><?php endforeach; ?></select></div><button type="button" id="publication-link-author" class="archive-button">Add and link author</button></div><div class="archive-authors"><?php foreach($allContributors as $author): $authorLabel = trim($author['first_name'].' '.$author['last_name']); $programLabel = trim((string)($author['program_codes'] ?? '')); ?><label data-program-ids="<?= archiveEscape((string)($author['program_ids'] ?? '')) ?>"><?php if($programLabel !== ''): ?><span class="publication-author-program"><?= archiveEscape($programLabel) ?></span><?php endif; ?><input type="checkbox" name="contributor_ids[]" value="<?= (int)$author['user_id'] ?>" data-program-ids="<?= archiveEscape((string)($author['program_ids'] ?? '')) ?>" <?= in_array((int)$author['user_id'],$selectedAuthors,true)?'checked':'' ?>><?= archiveEscape($authorLabel) ?></label><?php endforeach; ?></div><p class="publication-author-note">Linked authors may view this record. Editing remains restricted to its owner and authorized administrators/staff. Removing a name does not silently remove a saved account link.</p><?php $errorFor('contributor_ids'); ?></div></details>
<?php $field('abstract_text','Abstract','textarea',20000); ?><p id="abstract-word-count" role="status"></p>
<fieldset><legend>Keywords</legend><div class="archive-grid" id="publication-keywords"><?php $keywords=is_array($input['publication_keywords']??null)?$input['publication_keywords']:[]; for($i=0;$i<max(10,count($keywords));$i++): ?><div class="archive-field keyword-item"><label for="publication-keyword-<?= $i ?>">Keyword <?= $i+1 ?></label><input id="publication-keyword-<?= $i ?>" name="publication_keywords[]" maxlength="100" value="<?= archiveEscape(is_string($keywords[$i]??null)?$keywords[$i]:'') ?>"></div><?php endfor; ?></div><?php $errorFor('publication_keywords'); ?></fieldset></section>
<section class="archive-card"><h2>Publication details</h2><p>For proceedings papers, record the published collection. Attendance, presentations and acceptance notices alone do not establish publication.</p><?php $errorFor('metadata'); ?><p id="publication-retained-note" hidden>Previously entered metadata remains visible and is retained when classifications change.</p><div class="archive-grid">
<?php $postedMeta=is_array($input['publication_metadata']??null)?$input['publication_metadata']:[]; foreach(publicationFieldCatalog() as $key=>[$label,$kind,$max]): ?><div class="archive-field" data-metadata-field="<?= $key ?>"><label for="metadata-<?= $key ?>"><span data-field-label><?= archiveEscape($label) ?></span><span data-required-mark></span></label><input id="metadata-<?= $key ?>" name="publication_metadata[<?= $key ?>]" data-original-value="<?= archiveEscape(publicationText($metadata,$key)) ?>" type="<?= $kind==='date'?'date':($kind==='url'?'url':'text') ?>" <?= $kind==='year'?'inputmode="numeric"':'' ?> maxlength="<?= $max ?>" value="<?= archiveEscape(publicationText($postedMeta,$key)) ?>" <?= isset($fieldErrors[$key])?'aria-invalid="true"':'' ?>><?php $errorFor($key); ?></div><?php endforeach; ?>
<?php $field('publication_date','Publication date (if available)','date',10); $field('doi_identifier','DOI / identifier (optional)','text',255); $field('publication_url','Publication URL','url',500); ?>
<?php if($record&&!$record['publication_type_id']): $field('legacy_source','Original publication source','text',255); if($record['publication_type']==='other')$field('publication_type_name','Original custom type','text',120); endif; ?>
</div><fieldset><legend>Indexing information (optional)</legend><div class="publication-choices"><?php foreach(publicationIndexOptions() as $index): ?><label><input type="checkbox" name="indexing[]" value="<?= archiveEscape($index) ?>" <?= in_array($index,is_array($input['indexing']??null)?$input['indexing']:[],true)?'checked':'' ?>> <?= archiveEscape($index) ?></label><?php endforeach; ?></div><?php $errorFor('indexing'); $field('indexing_evidence','Indexing source / supporting identifier','text',500); ?><?php if(!empty($record['indexing_information'])&&!isset($metadata['indexing'])): ?><p>Original indexing information: <?= archiveEscape($record['indexing_information']) ?></p><label><input type="checkbox" name="clear_legacy_indexing" value="1"> Explicitly clear the original indexing text if no new option is selected.</label><?php endif; ?></fieldset></section>
<section class="archive-card"><h2>Published paper and publication evidence</h2><p>PDF is supported by the current validated archive uploader. Manuscript sections may include Introduction, Methods, Results, Discussion and References; RMS does not verify their presence.</p><link rel="stylesheet" href="<?= archiveEscape(SITE_URL.'css/file-uploader.css') ?>">
<?php $field('evidence_url','Official proceedings / supporting publication URL','url',500); ?>
<?php foreach(['supporting_file'=>['Published paper / manuscript','file_name'],'evidence_file'=>['Supporting publication evidence','evidence_file_name'],'references_file'=>['Separate references document','references_file_name']] as $slot=>[$label,$nameKey]): ?><div class="publication-upload" data-upload-slot="<?= $slot ?>"><?php if(!empty($record[$nameKey])): ?><p>Current <?= archiveEscape(strtolower($label)) ?>: <?= archiveEscape($record[$nameKey]) ?></p><?php endif; ?><?= renderFileUploader(['inputName'=>$slot,'accept'=>'.pdf','maxSize'=>publicationUploadLimit([]),'folderTarget'=>'publications','label'=>$label,'required'=>false]) ?><?php $errorFor($slot); ?></div><?php endforeach; ?>
<p>References already included in the full paper do not need a separate upload unless the selected form requires one. Replacing a file retains its previous version in record history.</p>
<div id="publication-page-count"><?php $field('declared_page_count','Declared manuscript page count','number',5); ?><small>This is the encoder’s declaration; RMS does not automatically count or certify document pages.</small></div></section>
<section class="archive-card"><h2>Research classification</h2><div class="archive-grid">
<div class="archive-field"><label for="research-area-id">Research area</label><select id="research-area-id" name="research_area_id"><option value="">Not recorded</option><?php foreach($areaOptions as $area): ?><option value="<?= (int)$area['area_id'] ?>" <?= (int)publicationText($input,'research_area_id')===(int)$area['area_id']?'selected':'' ?>><?= archiveEscape($area['area_name']) ?></option><?php endforeach; ?></select><?php $errorFor('research_area_id'); ?></div>
<div class="archive-field"><label for="pub-program-id">Institute program</label><select id="pub-program-id" name="program_id"><option value="">Not recorded</option><?php foreach($programOptions as $program): ?><option value="<?= (int)$program['program_id'] ?>" <?= (int)publicationText($input,'program_id')===(int)$program['program_id']?'selected':'' ?>><?= archiveEscape($program['program_code'].' — '.$program['program_name']) ?></option><?php endforeach; ?></select><?php $errorFor('program_id'); ?></div>
<div class="archive-field full"><label for="pub-project">Originating research project</label><select id="pub-project" name="research_project_id"><option value="">Not linked</option><?php if($record&&$record['research_project_id']&&!in_array((int)$record['research_project_id'],array_map('intval',array_column($projects,'project_id')),true)): ?><option value="<?= (int)$record['research_project_id'] ?>" <?= $projectId===(string)$record['research_project_id']?'selected':'' ?>>Existing project #<?= (int)$record['research_project_id'] ?></option><?php endif; ?><?php foreach($projects as $project): ?><option value="<?= (int)$project['project_id'] ?>" <?= $projectId===(string)$project['project_id']?'selected':'' ?>><?= archiveEscape($project['title']) ?></option><?php endforeach; ?></select><?php $errorFor('research_project_id'); ?></div>
</div><fieldset><legend>SDG alignment</legend><div class="publication-choices"><?php foreach(facultyRecordSdgs() as $number=>$label): ?><label><input type="checkbox" name="sdgs[]" value="<?= $number ?>" <?= in_array((string)$number,array_map('strval',array_filter(is_array($input['sdgs']??null)?$input['sdgs']:[],'is_scalar')),true)?'checked':'' ?>> <?= $number ?>. <?= archiveEscape($label) ?></label><?php endforeach; ?></div><?php $errorFor('sdgs'); ?><?php if(!empty($record['sdg_alignment'])&&!isset($metadata['sdgs'])): ?><p>Original SDG alignment: <?= archiveEscape($record['sdg_alignment']) ?></p><label><input type="checkbox" name="clear_legacy_sdgs" value="1"> Explicitly clear the original SDG text if no new goal is selected.</label><?php endif; ?></fieldset></section>
<section class="archive-card"><h2>Record information</h2><p><?= $record?'Publication record #'.$id.' · '.archiveEscape(archiveStatus($record)):'Record ID and encoding date are assigned when saved.' ?></p>
<?php if($role==='admin'||(!$record&&$role==='research_staff')): ?><div class="archive-field"><label for="owner-id">Faculty record owner *</label><select id="owner-id" name="owner_id"><option value="">Unresolved legacy owner / select faculty</option><?php if($record&&$record['researcher_id']&&!in_array((int)$record['researcher_id'],array_map('intval',array_column($facultyOwners,'user_id')),true)): ?><option value="<?= (int)$record['researcher_id'] ?>" <?= (int)$ownerId===(int)$record['researcher_id']?'selected':'' ?>><?= archiveEscape(archiveUserName($conn,$record['researcher_id'])) ?> (historical owner)</option><?php endif; ?><?php foreach($facultyOwners as $owner): ?><option value="<?= (int)$owner['user_id'] ?>" <?= (int)$ownerId===(int)$owner['user_id']?'selected':'' ?>><?= archiveEscape($owner['first_name'].' '.$owner['last_name']) ?></option><?php endforeach; ?></select><?php $errorFor('owner_id'); ?></div><?php if($record): ?><label><input type="checkbox" name="confirm_owner_change" value="1" <?= publicationText($input,'confirm_owner_change')==='1'?'checked':'' ?>> I am correcting record ownership.</label><?php endif; ?><?php else: ?><p>Owner: <?= archiveEscape(archiveUserName($conn,$ownerId)) ?></p><?php endif; ?>
<p><?= $record?'Last editor on save':'Encoder' ?>: <?= archiveEscape(archiveUserName($conn,$userId)) ?>. Timestamps are generated by RMS.</p><?php $field('remarks','Remarks','textarea',10000); ?></section>
<div class="archive-actions"><a href="<?= archiveEscape($listUrl.$navigationQuery) ?>">Cancel</a><button class="archive-button" type="submit"><?= $record?'Save changes':'Save publication' ?></button></div>
</form>
<script type="application/json" id="publication-rules"><?= json_encode(['rules'=>$clientRules,'typeRules'=>$clientTypeRules,'legacyRules'=>$legacyClientRules,'systemMaxKb'=>publicationUploadLimit([]),'savedType'=>(int)($record['publication_type_id']??0),'savedSubtype'=>(int)($record['publication_subtype_id']??0),'legacy'=>(bool)($record&&!$record['publication_type_id']),'hasRecord'=>(bool)$record,'hasFiles'=>['supporting_file'=>!empty($record['file_path']),'evidence_file'=>!empty($record['evidence_file_path']),'references_file'=>!empty($record['references_file_path'])]],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<script src="<?= archiveEscape(SITE_URL.'js/file-uploader.js') ?>"></script><script src="<?= archiveEscape(SITE_URL.'js/publication-classification.js') ?>"></script>
<?php archiveShellClose($user); ?>
