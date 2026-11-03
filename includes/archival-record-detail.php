<?php
require_once __DIR__ . '/archival-record-ui.php';
if ($archiveKind === 'publication') require_once __DIR__ . '/publication-classification.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Retired approval/rejection actions have no executable branch here.
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? null)) { http_response_code(400); exit('Your request expired.'); }
    $action = (string) ($_POST['action'] ?? '');
    $supportedActions = $archiveKind === 'publication' ? ['deactivate', 'restore', 'archive'] : ['deactivate', 'restore'];
    if (!in_array($action, $supportedActions, true)) { http_response_code(400); exit('This action is not supported for archival records.'); }
    if ($role !== 'admin' || (int)($_POST['record_id']??0)!==$id) archiveDeny();
    try {
        $conn->begin_transaction(); $before=archiveLoad($conn,$archiveKind,$id,true);
        if (!$before || !archiveCan($conn,$archiveKind,$before,$user,$action)) throw new RuntimeException('Record unavailable.');
        if ($action === 'archive') {
            if (($before['archival_status'] ?? '') !== 'legacy') throw new RuntimeException('Only legacy publication records can be archived. Refresh the page.');
            $archiveStmt = archiveQuery($conn, "UPDATE {$c['table']} SET archival_status = ?, last_modified_by = ?, updated_at = CURRENT_TIMESTAMP WHERE {$c['id']} = ? AND archival_status = ?", ['archived', $userId, $id, 'legacy']);
            $archived = $archiveStmt->affected_rows === 1;
            $archiveStmt->close();
            if (!$archived) throw new RuntimeException('The publication is no longer a legacy record. Refresh the page.');
        } else {
            if (($action==='deactivate') === !empty($before['deactivated_at'])) throw new RuntimeException('Record availability already changed. Refresh the page.');
            archiveQuery($conn,"UPDATE {$c['table']} SET deactivated_at = ".($action==='deactivate'?'CURRENT_TIMESTAMP':'NULL').", last_modified_by = ?, updated_at = CURRENT_TIMESTAMP WHERE {$c['id']} = ?",[$userId,$id]);
        }
        $after = archiveLoad($conn, $archiveKind, $id);
        if (!$after) throw new RuntimeException('Record is no longer available.');
        archiveAudit($conn, $archiveKind, $id, $action, $before, $after);
        $conn->commit();
        $message = match ($action) {
            'archive' => 'Publication archived for repository and release review. It has not been published publicly.',
            'deactivate' => 'Record deactivated; history and documents retained.',
            default => 'Record restored.',
        };
        $_SESSION[$c['module'].'_flash']=['type'=>'success','message'=>$message];
        header('Location: '.$detailUrl.'?id='.$id.$navigationSuffix); exit;
    } catch(Throwable $e) { $conn->rollback(); $errors[]=$e instanceof mysqli_sql_exception?'The change could not be saved.':$e->getMessage(); }
}
$historyPage=max(1,(int)($_GET['history_page']??1));
$historyPattern='(publication|copyright application) #'.$id.'([^0-9]|$)';
$history=archiveQuery($conn,"SELECT a.*, CONCAT_WS(' ',u.first_name,u.last_name) AS actor FROM activity_log a LEFT JOIN users u ON u.user_id=a.user_id WHERE a.module = ? AND ((JSON_VALID(a.details) AND JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.record_kind')) = ? AND JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.record_id')) = ?) OR (a.details IS NULL AND a.action REGEXP ?)) ORDER BY a.log_id DESC LIMIT 26 OFFSET ".(($historyPage-1)*25),[$c['module'],$archiveKind,$id,$historyPattern])->get_result()->fetch_all(MYSQLI_ASSOC);
$moreHistory=count($history)>25; $history=array_slice($history,0,25);
$contributors=archiveQuery($conn,"SELECT u.first_name,u.last_name,ac.contribution FROM {$archiveKind}_contributors ac JOIN users u ON u.user_id=ac.user_id WHERE ac.{$c['id']} = ? ORDER BY ac.is_primary DESC,u.last_name",[$id])->get_result()->fetch_all(MYSQLI_ASSOC);
archiveShellOpen($user,$c,$c['label'].' — Details');
$metadata=$archiveKind==='publication' ? [] : [$c['authors']=>'Actual authors / creators',$c['type']=>'Record type'];
if($archiveKind!=='publication') {
    $metadata += ['co_authors'=>'Additional creators','filing_date'=>'Application / filing date','application_number'=>'Application / reference number','copyright_ref_no'=>'Registration number','registered_at'=>'Registration date','date_completed'=>'Work completed'];
    if ($archiveScope === 'patents_utility_models') $metadata['inventors'] = 'Inventors / researchers';
    if ($archiveScope === 'innovation_technology_transfer') $metadata += ['potential_partners'=>'Potential partners','agreement_reference'=>'Agreement reference','utilization_status'=>'Utilization status','transfer_activities'=>'Technology-transfer activities'];
}
$metadata+=['remarks'=>'Remarks','created_at'=>'Originally recorded','updated_at'=>'Last updated'];
?>
<div class="archive-heading"><a href="<?= archiveEscape($listUrl . $navigationQuery) ?>">← All records</a><div class="archive-actions"><?php if(archiveCan($conn,$archiveKind,$record,$user,'manage')&&empty($record['deactivated_at'])): ?><a class="archive-button" href="<?= archiveEscape($formUrl.'?id='.$id.$navigationSuffix) ?>">Edit record</a><?php endif; ?><a class="archive-button secondary" href="#history">History</a></div></div>
<?php if($flash): ?><div class="archive-success" role="status"><?= archiveEscape($flash['message']??'') ?></div><?php endif; ?><?php foreach($errors as $error): ?><div class="archive-error" role="alert"><?= archiveEscape($error) ?></div><?php endforeach; ?>
<article class="archive-card"><span class="archive-badge"><?= archiveEscape(archiveStatus($record)) ?></span><h1><?= archiveEscape($record[$c['title']]) ?></h1>
<?php if($archiveKind==='copyright'): ?><p><strong><?= $archiveScope === '' ? 'External copyright status' : 'External registration / IP status' ?>:</strong> <?= archiveEscape(archiveExternalLabel($record)) ?></p><?php endif; ?>
<dl class="archive-grid archive-detail"><div><dt>Faculty Record Owner</dt><dd><?= archiveEscape(archiveUserName($conn,$record[$c['owner']])) ?></dd></div><div><dt>Encoded by</dt><dd><?= archiveEscape(archiveUserName($conn,$record['encoded_by'])) ?></dd></div><div><dt>Last modified by</dt><dd><?= archiveEscape(archiveUserName($conn,$record['last_modified_by'])) ?></dd></div>
<?php foreach($metadata as $key=>$label): ?><div><dt><?= archiveEscape($label) ?></dt><dd><?= archiveEscape($key===$c['type']?($c['types'][$record[$key]]??$record[$key]):($record[$key]?:'Not recorded')) ?></dd></div><?php endforeach; ?>
<?php if($archiveKind==='publication'&&!empty($record['research_area_id'])): $area=archiveQuery($conn,'SELECT area_name FROM research_areas WHERE area_id = ?',[(int)$record['research_area_id']])->get_result()->fetch_assoc(); ?><div><dt>Research Area</dt><dd><?= archiveEscape($area['area_name']??'Historical classification') ?></dd></div><?php endif; ?>
<div class="archive-field full"><dt>Linked RMS authors / creators</dt><dd><?php if(!$contributors): ?>None linked<?php else: foreach($contributors as $author): ?><?= archiveEscape($author['first_name'].' '.$author['last_name'].' — '.$author['contribution']) ?><br><?php endforeach; endif; ?></dd></div>
<?php if($record['research_project_id']): $project=archiveQuery($conn,'SELECT title FROM research_projects WHERE project_id = ?',[$record['research_project_id']])->get_result()->fetch_assoc(); ?><div><dt>Originating project</dt><dd><?= archiveEscape($project['title']??'Historical link') ?></dd></div><?php endif; ?>
<?php if($archiveKind==='copyright'&&$record['publication_id']): $linked=archiveQuery($conn,'SELECT research_title FROM publications WHERE publication_id = ?',[$record['publication_id']])->get_result()->fetch_assoc(); ?><div><dt>Related publication</dt><dd><?= archiveEscape($linked['research_title']??'Historical link') ?></dd></div><?php endif; ?>
</dl></article>
<?php if($archiveKind==='publication') require __DIR__ . '/publication-archive-detail.php'; ?>
<?php
$documentSlots=$archiveKind==='publication' ? ['manuscript'=>['file_path','file_name','Published paper'],'evidence'=>['evidence_file_path','evidence_file_name','Publication evidence'],'references'=>['references_file_path','references_file_name','References document']] : ['manuscript'=>['file_path','file_name','Supporting document']];
$attachedDocumentSlots=array_filter($documentSlots,static fn($slot)=>!empty($record[$slot[0]]));
?>
<section id="documents" class="archive-card archive-documents-section" aria-labelledby="archive-documents-title">
<div class="archive-documents-heading"><div><h2 id="archive-documents-title">Supporting documents</h2><p>Files available with this record</p></div><span class="archive-documents-count"><?= count($attachedDocumentSlots) ?> <?= count($attachedDocumentSlots)===1?'file':'files' ?></span></div>
<?php if(!$attachedDocumentSlots): ?><div class="archive-document-empty" role="status">No supporting documents are attached to this record.</div><?php else: ?>
<div class="archive-document-list">
<?php foreach($attachedDocumentSlots as $slot=>[$pathKey,$nameKey,$label]): $displayName=(string)($record[$nameKey]??basename((string)$record[$pathKey])); $extension=strtoupper(pathinfo($displayName,PATHINFO_EXTENSION)?:'FILE'); $slotUrl=$documentDownloadUrl.'?type='.$archiveKind.'&id='.$id.($archiveKind==='publication'?'&document='.$slot:''); ?>
<article class="archive-document">
<span class="archive-document-file-type" aria-hidden="true"><?= archiveEscape($extension) ?></span>
<div class="archive-document-info"><p class="archive-document-kind"><?= archiveEscape($label) ?></p><h3><?= archiveEscape($displayName) ?></h3><span class="archive-document-format"><?= archiveEscape($extension) ?> file</span></div>
<div class="archive-document-actions"><a class="archive-document-action" href="<?= archiveEscape($slotUrl.'&mode=viewer') ?>" aria-label="Preview <?= archiveEscape($displayName) ?>">Preview</a><?php if(archiveCan($conn,$archiveKind,$record,$user,'download')): ?><a class="archive-document-action primary" href="<?= archiveEscape($slotUrl) ?>" aria-label="Download <?= archiveEscape($displayName) ?>">Download</a><?php endif; ?></div>
</article>
<?php endforeach; ?></div><?php endif; ?>
<p class="archive-document-history-note">Previous document versions remain available in record history when they have been replaced.</p>
</section>
<section id="history" class="archive-card<?= $archiveKind === 'publication' && $role === 'admin' ? ' archive-admin-publication-history' : '' ?>"><h2>Record history</h2>
<?php if($record['archival_status']==='legacy'): ?><p>Historical workflow status: <strong><?= archiveEscape(ucwords(str_replace('_',' ',$record['status']))) ?></strong>. Original dates, ownership, approval events and notifications are preserved. Unknown historical encoder/editor identities have not been inferred.</p><?php endif; ?>
<?php if(!$history): ?><p>No audit entries were recorded for this record.</p><?php endif; ?>
<?php foreach($history as $event): $details=json_decode((string)($event['details']??''),true); ?><details><summary><?= archiveEscape($event['created_at'].' — '.($event['actor']?:'Historical account').' — '.$event['action']) ?></summary><?php if(is_array($details)): ?><dl class="archive-history"><?php foreach($details['changes']??[] as $field=>$change): ?><dt><strong><?= archiveEscape($field) ?></strong></dt><dd>Before: <?= archiveEscape(is_array($change['before'])?json_encode($change['before'],JSON_UNESCAPED_UNICODE):($change['before']??'Not recorded')) ?><br>After: <?= archiveEscape(is_array($change['after'])?json_encode($change['after'],JSON_UNESCAPED_UNICODE):($change['after']??'Not recorded')) ?></dd><?php endforeach; ?></dl><?php foreach($documentSlots as $slot=>[$pathKey,$nameKey,$label]): if(empty($details['changes'][$pathKey]['before']))continue; ?><p><a href="<?= archiveEscape($documentDownloadUrl.'?type='.$archiveKind.'&id='.$id.'&mode=viewer&history_log='.(int)$event['log_id'].($archiveKind==='publication'?'&document='.$slot:'')) ?>">Preview previous <?= archiveEscape(strtolower($label)) ?></a></p><?php endforeach; ?><?php endif; ?></details><?php endforeach; ?>
<div class="archive-links"><?php if($historyPage>1): ?><a href="<?= archiveEscape($detailUrl.'?id='.$id.'&history_page='.($historyPage-1).$navigationSuffix.'#history') ?>">Newer history</a><?php endif; ?><?php if($moreHistory): ?><a href="<?= archiveEscape($detailUrl.'?id='.$id.'&history_page='.($historyPage+1).$navigationSuffix.'#history') ?>">Older history</a><?php endif; ?></div>
<?php
// Legacy rejection reasons were stored in notifications, not in record columns.
$notificationLinks = [$legacyDetailUrl . '?id=' . $id];
if ($archiveKind === 'publication') {
    $notificationLinks[] = SITE_URL . 'pages/faculty/faculty-publication-detail.php?id=' . $id;
    $notificationLinks[] = SITE_URL . 'pages/admin/admin-publication-detail.php?id=' . $id;
}
$oldNotifications=archiveQuery($conn,'SELECT title,message,created_at FROM notifications WHERE link IN (' . implode(',', array_fill(0, count($notificationLinks), '?')) . ') ORDER BY created_at DESC',$notificationLinks)->get_result();
if($oldNotifications->num_rows): ?><details><summary>Historical record notifications</summary><?php while($note=$oldNotifications->fetch_assoc()): ?><p><strong><?= archiveEscape($note['created_at'].' — '.$note['title']) ?></strong><br><?= archiveEscape($note['message']) ?></p><?php endwhile; ?></details><?php endif; ?>
</section>
<?php if ($role === 'admin' && $archiveKind === 'publication' && ($record['archival_status'] ?? '') === 'legacy'): ?>
<form id="record-archive" method="post" class="archive-card" onsubmit="return confirm('Archive this publication record? It may become eligible for repository and public-release review, but this does not publish it publicly.');">
<?= csrfField() ?><input type="hidden" name="record_id" value="<?= $id ?>"><input type="hidden" name="action" value="archive">
<h2>Archive record</h2><p>Mark this legacy record as archived. It may then appear in the research repository and public-release review queue. This does not publish it publicly.</p>
<button type="submit" class="archive-button">Archive record</button>
</form>
<?php endif; ?>
<?php if($role==='admin'): ?><form id="record-availability" method="post" class="archive-card<?= $archiveKind === 'publication' ? ' archive-admin-publication-availability' : '' ?>"><?= csrfField() ?><input type="hidden" name="record_id" value="<?= $id ?>"><input type="hidden" name="action" value="<?= $record['deactivated_at']?'restore':'deactivate' ?>"><h2>Record availability</h2><p>Deactivation removes the record from active lists and keeps its documents and history. Administrators can restore it here.</p><button type="submit" class="archive-button secondary<?= $archiveKind === 'publication' ? ' archive-publication-availability-action' . ($record['deactivated_at'] ? ' is-restore' : '') : '' ?>"><?= $record['deactivated_at']?'Restore record':'Deactivate record' ?></button></form><?php endif; ?>
<?php archiveShellClose($user); ?>
