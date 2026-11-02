<?php
/** Read-only local schema/data inspection. No sessions, application requests or mutations. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
$env = static fn($k,$d) => (string)($_ENV[$k]??$_SERVER[$k]??$d);
if (!in_array($env('DB_HOST','localhost'),['localhost','127.0.0.1','::1'],true) || $env('DB_NAME','rms_db')!=='rms_db') exit('Local rms_db required.');
$db=new mysqli($env('DB_HOST','localhost'),$env('DB_USER','root'),$env('DB_PASS',''),'rms_db'); $db->set_charset('utf8mb4');
$db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
$summary=['database'=>$db->query('SELECT DATABASE()')->fetch_row()[0]];
require_once __DIR__ . '/../includes/archival-records.php';
// Compile literal archive-related statements from the affected source, without executing them.
$compiled=0;
$sources=['pages/admin/admin-reports.php','pages/staff/staff-dashboard.php','pages/student/my-publications.php','pages/student/my-ip-contributions.php','pages/student/student-dashboard.php','pages/faculty/faculty-profile.php','pages/admin/admin-researchers.php','includes/services/FacultyDashboardService.php','includes/archival-record-detail.php','includes/archival-record-form.php'];
foreach($sources as $source) {
    $tokens=token_get_all(file_get_contents(__DIR__.'/../'.$source));
    for($i=0;$i<count($tokens);$i++) {
        if(!is_array($tokens[$i]) || $tokens[$i][0]!==T_STRING || $tokens[$i][1]!=='prepare') continue;
        $j=$i+1;
        while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE) $j++;
        if(($tokens[$j]??null)!=='(') continue; $j++;
        while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE) $j++;
        $literal=$tokens[$j]??null;
        if(!is_array($literal)||$literal[0]!==T_CONSTANT_ENCAPSED_STRING) continue; $j++;
        while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE) $j++;
        if(($tokens[$j]??null)!==')') continue;
        $raw=substr($literal[1],1,-1);
        $sql=$literal[1][0]==="'"?str_replace(["\\'","\\\\"],["'","\\"],$raw):stripcslashes($raw);
        if(!preg_match('/\b(publications|copyright_applications|publication_contributors|copyright_contributors)\b/i',$sql)) continue;
        $stmt=$db->prepare($sql); $stmt->close(); $compiled++;
    }
}
$summary['literal_archive_sql_statements_prepared']=$compiled;
foreach(['publication','copyright'] as $kind) {
    $cfg=archiveConfig($kind);
    $projection=$kind==='publication'?'volume,issue,page_numbers,publication_url,indexing_information,sdg_alignment,publication_type_name':'external_status,copyright_ref_no,registered_at,application_number,filing_date';
    $db->query("SELECT archival_status,encoded_by,last_modified_by,deactivated_at,remarks,$projection FROM {$cfg['table']} LIMIT 0");
    $db->query("SELECT user_id,contribution,is_primary,created_at FROM {$kind}_contributors LIMIT 0");
    $stmt=$db->prepare("SELECT a.log_id FROM activity_log a WHERE a.module=? AND ((JSON_VALID(a.details) AND JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.record_kind'))=? AND JSON_UNQUOTE(JSON_EXTRACT(a.details,'$.record_id'))=?) OR (a.details IS NULL AND a.action REGEXP ?))"); $stmt->close();
}
$summary['archive_schema_queries']='passed';
$labels=[archiveStatus(['archival_status'=>'archived','status'=>'submitted'])==='Archived',archiveStatus(['archival_status'=>'legacy','status'=>'registered'])==='Legacy record',archiveStatus(['archival_status'=>'archived','deactivated_at'=>'2026-10-31'])==='Inactive',archiveExternalLabel(['archival_status'=>'archived','external_status'=>'filed'])==='Applied / Filed',archiveExternalLabel(['status'=>'registered'])!=='Registered (legacy record)',archiveCopyrightType('patent'),archiveCopyrightType('software')];
if(in_array(false,$labels,true)) throw new RuntimeException('Archive distinction check failed.');
$summary['archive_distinction_checks']=count($labels);
$summary['recent_migrations']=$db->query("SELECT filename,status FROM schema_migrations ORDER BY filename DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$stmt=$db->prepare('SELECT filename,status,verification_method FROM schema_migrations WHERE filename IN (?, ?, ?) ORDER BY filename');
$migrationNames=['043_publication_ip_contributors.sql','054_archival_publications_copyrights.sql','055_archive_contributor_storage.sql'];
$stmt->bind_param('sss',...$migrationNames); $stmt->execute();
$summary['archive_migrations']=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$summary['isolated_restores']=array_column($db->query("SHOW DATABASES LIKE 'rms_archive_restore_%'")->fetch_all(),0);
foreach(['publications'=>['publication_id','researcher_id'],'copyright_applications'=>['copyright_id','applicant_id']] as $table=>[$id,$owner]) {
    $summary[$table]=['rows'=>(int)$db->query("SELECT COUNT(*) FROM $table")->fetch_row()[0], 'ownership_needs_review'=>(int)$db->query("SELECT COUNT(*) FROM $table r LEFT JOIN users u ON u.user_id=r.$owner WHERE u.user_id IS NULL OR u.role<>'faculty' OR u.deleted_at IS NOT NULL")->fetch_row()[0], 'legacy_status_counts'=>$db->query("SELECT status,COUNT(*) AS total FROM $table GROUP BY status")->fetch_all(MYSQLI_ASSOC)];
}
if (isset($argv[1])) {
    $restore=$argv[1];
    if(!preg_match('/^rms_archive_restore_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/D',$restore)) exit('Invalid restore name.');
    $unchanged=[];
    foreach($db->query("SHOW TABLES FROM `$restore`")->fetch_all() as [$table]) {
        if($table==='schema_migrations') continue;
        $columns=array_column($db->query("SHOW COLUMNS FROM `$restore`.`$table`")->fetch_all(MYSQLI_ASSOC),'Field');
        $projection='`'.implode('`,`',$columns).'`';
        $sets=[];
        foreach(['rms_db',$restore] as $name) {
            $hashes=[];
            foreach($db->query("SELECT $projection FROM `$name`.`$table`")->fetch_all(MYSQLI_ASSOC) as $row) $hashes[]=hash('sha256',serialize($row));
            sort($hashes); $sets[]=hash('sha256',implode('',$hashes));
        }
        $unchanged[$table]=$sets[0]===$sets[1];
    }
    $summary['original_columns_unchanged']=$unchanged;
    $summary['all_original_data_preserved']=!in_array(false,$unchanged,true);
    $differenceDetails=[];
    foreach(array_keys(array_filter($unchanged,static fn($same)=>!$same)) as $table) {
        $columns=array_column($db->query("SHOW COLUMNS FROM `$restore`.`$table`")->fetch_all(MYSQLI_ASSOC),'Field');
        $primaryRows=$db->query("SHOW KEYS FROM `$restore`.`$table` WHERE Key_name='PRIMARY'")->fetch_all(MYSQLI_ASSOC);
        usort($primaryRows,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        $primary=array_column($primaryRows,'Column_name');
        if(!$primary) { $differenceDetails[$table]=['detail'=>'no primary key']; continue; }
        $projection='`'.implode('`,`',$columns).'`'; $maps=[];
        foreach(['rms_db'=>$db->query("SELECT $projection FROM `rms_db`.`$table`")->fetch_all(MYSQLI_ASSOC),$restore=>$db->query("SELECT $projection FROM `$restore`.`$table`")->fetch_all(MYSQLI_ASSOC)] as $name=>$rows) {
            $map=[]; foreach($rows as $row) { $key=[]; foreach($primary as $column) $key[]=$row[$column]??null; $map[json_encode($key,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]=$row; } $maps[$name]=$map;
        }
        $added=array_diff_key($maps['rms_db'],$maps[$restore]); $removed=array_diff_key($maps[$restore],$maps['rms_db']); $changed=[]; $fields=[];
        foreach(array_intersect_key($maps['rms_db'],$maps[$restore]) as $key=>$row) if($row!==$maps[$restore][$key]) { $changed[$key]=true; foreach($columns as $column) if(($row[$column]??null)!==($maps[$restore][$key][$column]??null)) $fields[$column]=true; }
        $differenceDetails[$table]=['added'=>count($added),'removed'=>count($removed),'updated'=>count($changed),'changed_columns'=>array_keys($fields)];
    }
    $summary['table_differences_since_restore']=$differenceDetails;
    $oldMax=(int)$db->query("SELECT COALESCE(MAX(log_id),0) FROM `$restore`.activity_log")->fetch_row()[0];
    $summary['new_audit_rows_since_backup']=(int)$db->query('SELECT COUNT(*) FROM activity_log WHERE log_id>'.$oldMax)->fetch_row()[0];
    $summary['old_audit_entries_preserved']=(int)$db->query("SELECT COUNT(*) FROM `$restore`.activity_log o LEFT JOIN rms_db.activity_log n ON n.log_id=o.log_id WHERE n.log_id IS NULL OR NOT (n.user_id <=> o.user_id) OR NOT (n.action <=> o.action) OR NOT (n.module <=> o.module) OR NOT (n.created_at <=> o.created_at)")->fetch_row()[0]===0;
}
$db->rollback();
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
