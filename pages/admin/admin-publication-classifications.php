<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/publication-classification.php';
require_once __DIR__ . '/../../includes/archival-record-ui.php';
require_once __DIR__ . '/../../includes/admin-shell.php';
requireRole('admin');
$user=getCurrentUser();
if(!$user||$user['role']!=='admin') archiveDeny();
$errors=[]; $catalog=publicationCatalog($conn);
$catalogVersion=hash('sha256',json_encode($catalog,JSON_THROW_ON_ERROR));
$flash=$_SESSION['publication_classification_flash']??'';
unset($_SESSION['publication_classification_flash']);
if($_SERVER['REQUEST_METHOD']==='POST') {
    if(!isCsrfTokenValid($_POST['csrf_token']??null)) $errors[]='Your form expired. Refresh and try again.';
    if(!hash_equals($catalogVersion,publicationText($_POST,'catalog_version'))) $errors[]='Settings changed while this page was open. Refresh before saving.';
    if(!$errors) {
        try {
            $conn->begin_transaction(); publicationLockCatalog($conn);
            $catalog=publicationCatalog($conn);
            if(!hash_equals($catalogVersion,hash('sha256',json_encode($catalog,JSON_THROW_ON_ERROR)))) throw new RuntimeException('Settings changed. Refresh before saving.');
            $before=$catalog; $action=publicationText($_POST,'action');
            if(in_array($action,['save_type','save_subtype','save_scope'],true)) {
                $kind=substr($action,5); $id=(int)publicationText($_POST,'item_id');
                $spec=['type'=>['publication_types','publication_type_id','type_code','types',160],
                    'subtype'=>['publication_subtypes','publication_subtype_id','subtype_code','subtypes',160],
                    'scope'=>['publication_scopes','publication_scope_id','scope_code','scopes',120]][$kind];
                [$table,$idKey,$codeKey,$collection,$max]=$spec;
                $label=publicationText($_POST,'label'); $code=publicationText($_POST,'code');
                if($label===''||mb_strlen($label)>$max) throw new RuntimeException("Enter a label with at most $max characters.");
                $active=publicationText($_POST,'is_active')==='1'?1:0;
                if($id&&!isset($catalog[$collection][$id])) throw new RuntimeException('Classification not found.');
                if(!$id&&!preg_match('/^[a-z][a-z0-9_]{1,'.($kind==='scope'?39:79).'}$/D',$code)) throw new RuntimeException('Use a stable code of lowercase letters, digits and underscores.');
                if($kind==='scope'&&$active&&($code==='regional'||stripos($label,'regional')!==false)&&(!$id||$catalog['scopes'][$id]['label']!==$label||!(int)$catalog['scopes'][$id]['is_active'])&&publicationText($_POST,'confirm_scope')!=='1') throw new RuntimeException('Confirm the Research Office reporting requirement before enabling Regional scope.');
                if($id) {
                    archiveQuery($conn,"UPDATE $table SET label=?,is_active=? WHERE $idKey=?",[$label,$active,$id]);
                } elseif($kind==='subtype') {
                    $typeId=(int)publicationText($_POST,'parent_id');
                    if(empty($catalog['types'][$typeId]['is_active'])) throw new RuntimeException('Choose an active parent type.');
                    archiveQuery($conn,'INSERT INTO publication_subtypes(publication_type_id,subtype_code,label,is_active) VALUES(?,?,?,?)',[$typeId,$code,$label,$active]);
                    $id=$conn->insert_id;
                } elseif($kind==='type') {
                    archiveQuery($conn,'INSERT INTO publication_types(type_code,label,is_proposed,is_active) VALUES(?,?,1,?)',[$code,$label,$active]);
                    $id=$conn->insert_id;
                } else {
                    if(($code==='regional'||stripos($label,'regional')!==false)&&publicationText($_POST,'confirm_scope')!=='1') throw new RuntimeException('Confirm the Research Office reporting requirement before enabling Regional scope.');
                    archiveQuery($conn,'INSERT INTO publication_scopes(scope_code,label,is_active) VALUES(?,?,?)',[$code,$label,$active]);
                    $id=$conn->insert_id;
                }
                if($kind==='type') {
                    $template=publicationText($_POST,'template');
                    if(!isset(publicationTemplates()[$template])) throw new RuntimeException('Choose a supported metadata template.');
                    $type=$catalog['types'][$id]??[];
                    if($type&&isset(publicationTemplates()[$type['type_code']])&&$template!==$type['type_code']) throw new RuntimeException('The built-in type template is stable; adjust subtype fields instead.');
                    if($type&&publicationTemplate($type,$catalog)!==$template&&archiveQuery($conn,'SELECT 1 FROM publications WHERE publication_type_id=? LIMIT 1',[$id])->get_result()->fetch_row()) throw new RuntimeException('A used type keeps its metadata template. Adjust subtype field rules instead.');
                    publicationSaveSetting($conn,'publication_type_'.$id,['template'=>$template],(int)$user['user_id']);
                }
            } elseif($action==='save_rule') {
                $id=(int)publicationText($_POST,'item_id');
                if(!isset($catalog['subtypes'][$id])) throw new RuntimeException('Subtype not found.');
                $integers=[];
                foreach(['abstract_word_limit'=>10000,'keyword_count'=>10,'manuscript_page_limit'=>10000,'max_attachment_kb'=>102400] as $key=>$max) {
                    $raw=publicationText($_POST,$key);
                    if($raw!==''&&(!ctype_digit($raw)||(int)$raw>$max)) throw new RuntimeException('Enter valid numeric rule limits.');
                    $integers[$key]=(int)$raw;
                }
                $fields=$_POST['fields']??[]; $required=$_POST['required_fields']??[];
                if(!is_array($fields)||!is_array($required)||array_filter(array_merge($fields,$required),static fn($v)=>!is_string($v))||array_diff($fields,array_keys(publicationFieldCatalog()))||array_diff($required,$fields)) throw new RuntimeException('Required metadata fields must also be applicable.');
                $fields=array_values(array_unique($fields)); $required=array_values(array_unique($required));
                $word=$integers['abstract_word_limit']?:null; $pages=$integers['manuscript_page_limit']?:null;
                $paper=publicationText($_POST,'manuscript_required')==='1'?1:0;
                $references=publicationText($_POST,'references_required')==='1'?1:0;
                $evidence=publicationText($_POST,'evidence_required')==='1'?1:0;
                archiveQuery($conn,"INSERT INTO publication_form_rules(publication_subtype_id,abstract_word_limit,keyword_count,manuscript_required,references_required,evidence_required,manuscript_page_limit,allowed_attachment_extensions) VALUES(?,?,?,?,?,?,?,'pdf') ON DUPLICATE KEY UPDATE abstract_word_limit=VALUES(abstract_word_limit),keyword_count=VALUES(keyword_count),manuscript_required=VALUES(manuscript_required),references_required=VALUES(references_required),evidence_required=VALUES(evidence_required),manuscript_page_limit=VALUES(manuscript_page_limit),allowed_attachment_extensions=VALUES(allowed_attachment_extensions)",[$id,$word,$integers['keyword_count'],$paper,$references,$evidence,$pages]);
                publicationSaveSetting($conn,'publication_rule_'.$id,['fields'=>$fields,'required'=>$required,'abstract_required'=>publicationText($_POST,'abstract_required')==='1','abstract_visible'=>publicationText($_POST,'abstract_visible')==='1','keywords_visible'=>publicationText($_POST,'keywords_visible')==='1','max_attachment_kb'=>$integers['max_attachment_kb']],(int)$user['user_id']);
            } else throw new RuntimeException('Unsupported settings action.');
            if(!logActivity('Publication classification settings: '.$action,'publications')) throw new RuntimeException('Settings audit could not be recorded.');
            $logId=$conn->insert_id;
            archiveQuery($conn,'UPDATE activity_log SET details=? WHERE log_id=?',[json_encode(['event'=>'classification_settings','action'=>$action,'before'=>$before,'after'=>publicationCatalog($conn)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$logId]);
            $conn->commit();
            $_SESSION['publication_classification_flash']='Publication settings saved. Existing records keep their saved validation rules.';
            header('Location: '.SITE_URL.'pages/admin/admin-publication-classifications.php'); exit;
        } catch(Throwable $e) {
            $conn->rollback();
            $errors[]=$e instanceof mysqli_sql_exception?'That label or code already exists, or the settings could not be saved. No settings were committed.':$e->getMessage();
        }
    }
}
$catalog=publicationCatalog($conn);
$catalogVersion=hash('sha256',json_encode($catalog,JSON_THROW_ON_ERROR));
$formHidden=static function(string $action,int $id) use($catalogVersion): void {
    echo csrfField().'<input type="hidden" name="action" value="'.archiveEscape($action).'"><input type="hidden" name="item_id" value="'.$id.'"><input type="hidden" name="catalog_version" value="'.$catalogVersion.'">';
};
$c=archiveConfig('publication');
archiveShellOpen($user,$c,'Publication classification settings');
?>
<link rel="stylesheet" href="<?= archiveEscape(SITE_URL.'css/publication-archive.css') ?>">
<div class="archive-heading"><div><h1>Publication classification settings</h1><p>Manage proposed archive categories and form requirements. Changes apply to new records and explicit classification changes.</p></div><a class="archive-button secondary" href="<?= archiveEscape(SITE_URL.'pages/admin/admin-publications.php') ?>">Publication Records</a></div>
<?php if($flash): ?><div class="archive-success" role="status"><?= archiveEscape($flash) ?></div><?php endif; ?><?php foreach($errors as $error): ?><div class="archive-error" role="alert"><?= archiveEscape($error) ?></div><?php endforeach; ?>
<p class="publication-notice">No classification is deleted here. Deactivated values remain attached to saved records. Detailed categories are proposed RMS options; implementation does not establish institutional approval.</p>
<?php foreach(['type'=>['types','publication_type_id','type_code','Publication types'],'subtype'=>['subtypes','publication_subtype_id','subtype_code','Publication subtypes'],'scope'=>['scopes','publication_scope_id','scope_code','Publication scopes']] as $kind=>[$collection,$idKey,$codeKey,$heading]): ?>
<section class="archive-card"><h2><?= $heading ?></h2><?php foreach($catalog[$collection] as $row): $id=(int)$row[$idKey]; ?><details class="publication-setting"><summary><?= archiveEscape(($kind==='subtype'?($catalog['types'][(int)$row['publication_type_id']]['label'].' · '):'').$row['label'].(!(int)$row['is_active']?' (inactive)':'')) ?></summary><form method="post" class="archive-grid"><?php $formHidden('save_'.$kind,$id); ?>
<div class="archive-field"><label>Display label<input name="label" value="<?= archiveEscape($row['label']) ?>" maxlength="<?= $kind==='scope'?120:160 ?>" required></label><small>Stable code: <?= archiveEscape($row[$codeKey]) ?></small></div>
<?php if($kind==='type'): ?><div class="archive-field"><label>Metadata template<select name="template"><?php foreach(publicationTemplates() as $key=>$label): ?><option value="<?= $key ?>" <?= publicationTemplate($row,$catalog)===$key?'selected':'' ?>><?= archiveEscape($label) ?></option><?php endforeach; ?></select></label></div><?php endif; ?>
<div class="archive-field"><label><input type="checkbox" name="is_active" value="1" <?= (int)$row['is_active']?'checked':'' ?>> Active / selectable</label></div><?php if($kind==='scope'): ?><label><input type="checkbox" name="confirm_scope" value="1"> Research Office reporting requirements confirm Regional scope, if enabling it.</label><?php endif; ?><div class="archive-field full"><button class="archive-button secondary">Save classification</button></div></form>
<?php if($kind==='subtype'): $rule=publicationRules($catalog,(int)$row['publication_type_id'],$id); ?><form method="post" class="publication-rule-form"><?php $formHidden('save_rule',$id); ?><h3>Form validation</h3><div class="archive-grid">
<?php foreach(['abstract_word_limit'=>['Abstract word limit (0 means none)',10000],'keyword_count'=>['Exact keyword count (0 means optional)',10],'max_attachment_kb'=>['Maximum attachment size in KB (0 uses system limit)',102400],'manuscript_page_limit'=>['Prescribed manuscript page limit (0 means none)',10000]] as $key=>[$label,$max]): ?><div class="archive-field"><label><?= archiveEscape($label) ?><input type="number" name="<?= $key ?>" min="0" max="<?= $max ?>" value="<?= (int)$rule[$key] ?>"></label></div><?php endforeach; ?>
</div><p>PDF is the validated archive format. Size is also capped by system settings. A prescribed page limit checks an encoder-declared count; RMS does not certify the document’s actual page count.</p>
<div class="publication-choices"><?php foreach(['abstract_visible'=>'Show abstract','abstract_required'=>'Abstract required','keywords_visible'=>'Show keywords','manuscript_required'=>'Published paper required','references_required'=>'Separate references required','evidence_required'=>'Publication evidence required'] as $key=>$label): ?><label><input type="checkbox" name="<?= $key ?>" value="1" <?= !empty($rule[$key])?'checked':'' ?>> <?= $label ?></label><?php endforeach; ?></div>
<div class="archive-table-wrap"><table class="archive-table"><thead><tr><th>Metadata field</th><th>Applicable</th><th>Required</th></tr></thead><tbody><?php foreach(publicationFieldCatalog() as $key=>[$label]): ?><tr><th scope="row"><?= archiveEscape($label) ?></th><td><input aria-label="<?= archiveEscape($label.' applicable') ?>" type="checkbox" name="fields[]" value="<?= $key ?>" <?= in_array($key,$rule['fields'],true)?'checked':'' ?>></td><td><input aria-label="<?= archiveEscape($label.' required') ?>" type="checkbox" name="required_fields[]" value="<?= $key ?>" <?= in_array($key,$rule['required'],true)?'checked':'' ?>></td></tr><?php endforeach; ?></tbody></table></div><button class="archive-button">Save form rules</button></form><?php endif; ?></details><?php endforeach; ?>
<details class="publication-setting"><summary>Add <?= $kind ?></summary><form method="post" class="archive-grid"><?php $formHidden('save_'.$kind,0); ?><div class="archive-field"><label>Stable code<input name="code" maxlength="<?= $kind==='scope'?40:80 ?>" pattern="[a-z][a-z0-9_]+" required></label></div><div class="archive-field"><label>Display label<input name="label" maxlength="<?= $kind==='scope'?120:160 ?>" required></label></div>
<?php if($kind==='subtype'): ?><div class="archive-field"><label>Parent type<select name="parent_id"><?php foreach($catalog['types'] as $t): if(!(int)$t['is_active'])continue; ?><option value="<?= (int)$t['publication_type_id'] ?>"><?= archiveEscape($t['label']) ?></option><?php endforeach; ?></select></label></div><?php endif; ?>
<?php if($kind==='type'): ?><div class="archive-field"><label>Metadata template<select name="template"><?php foreach(publicationTemplates() as $key=>$label): ?><option value="<?= $key ?>"><?= archiveEscape($label) ?></option><?php endforeach; ?></select></label></div><?php endif; ?>
<div class="archive-field"><label><input type="checkbox" name="is_active" value="1" checked> Active / selectable</label><?php if($kind==='scope'): ?><label><input type="checkbox" name="confirm_scope" value="1"> Research Office reporting requirements confirm a Regional option, if adding one.</label><?php endif; ?></div><div class="archive-field full"><button class="archive-button">Add <?= $kind ?></button></div></form></details></section><?php endforeach; ?>
<?php archiveShellClose($user); ?>
