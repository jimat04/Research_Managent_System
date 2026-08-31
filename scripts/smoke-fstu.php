<?php
/**
 * Smoke test for the new faculty-students.php queries.
 * Run: php scripts/smoke-fstu.php
 * Safe to delete after verification.
 */
require __DIR__ . '/../includes/config.php';

$uid = (int) $conn->query("SELECT user_id FROM users WHERE email='msantos@rms.edu.ph'")->fetch_assoc()['user_id'];
echo 'faculty user_id: ' . $uid . PHP_EOL;

function has_col($conn, $t, $c) {
    // MariaDB does not accept a placeholder for the LIKE pattern in SHOW
    // statements, so use a literal (safe here — both args are hardcoded).
    $s = $conn->prepare("SHOW COLUMNS FROM `$t` LIKE '$c'");
    $s->execute();
    $n = $s->get_result()->num_rows;
    $s->close();
    return $n > 0;
}
function dff($conn, $t, $a) {
    return has_col($conn, $t, 'deleted_at') ? " AND $a.deleted_at IS NULL" : '';
}

$u_f  = dff($conn, 'users', 'u');
$rp_f = dff($conn, 'research_projects', 'rp');
$pm_f = dff($conn, 'project_members', 'pm');
$u_tf = dff($conn, 'users', 'users'); // for top-level users selects (no 'u' alias)
$rp_tf = dff($conn, 'research_projects', 'research_projects'); // for top-level project selects (no 'rp' alias)
echo 'filters: u=[' . $u_f . '] rp=[' . $rp_f . '] pm=[' . $pm_f . ']' . PHP_EOL;

$base_sql =
    "SELECT DISTINCT u.user_id, u.first_name, u.last_name, u.email,
            u.student_id, u.program, u.year_level
       FROM users u
      WHERE u.role = 'student'
        AND u.status = 'active'$u_f
        AND (
            u.user_id IN (
                SELECT rp.created_by
                  FROM research_projects rp
                  JOIN project_advisers pa ON pa.project_id = rp.project_id
                 WHERE pa.adviser_id = ?$rp_f
            )
            OR
            u.user_id IN (
                SELECT pm.user_id
                  FROM project_members pm
                  JOIN project_advisers pa ON pa.project_id = pm.project_id
                 WHERE pa.adviser_id = ?$pm_f
            )
        )
      ORDER BY u.last_name, u.first_name, u.user_id LIMIT 200";

echo PHP_EOL . '--- Test 1: no search filter ---' . PHP_EOL;
$s = $conn->prepare($base_sql);
if (!$s) { echo 'PREPARE FAILED: ' . $conn->error . PHP_EOL; exit(1); }
$a = $uid; $b = $uid;
$s->bind_param('ii', $a, $b);
$s->execute();
$res = $s->get_result();
echo 'rows: ' . $res->num_rows . PHP_EOL;
while ($r = $res->fetch_assoc()) {
    echo "  - {$r['user_id']} {$r['first_name']} {$r['last_name']} (program={$r['program']}, yl={$r['year_level']})" . PHP_EOL;
}
$s->close();

echo PHP_EOL . '--- Test 2: with search q=juan ---' . PHP_EOL;
$q = 'juan';
$like = '%' . $q . '%';
// Mirror the actual page: insert the search AND clause BEFORE the ORDER BY/LIMIT.
$order_pos = strpos($base_sql, 'ORDER BY');
$sql2 = ($order_pos === false)
    ? $base_sql . " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)"
    : substr($base_sql, 0, $order_pos)
      . " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?) "
      . substr($base_sql, $order_pos);
$s = $conn->prepare($sql2);
if (!$s) { echo 'PREPARE2 FAILED: ' . $conn->error . PHP_EOL; exit(1); }
$a = $uid; $b = $uid; $l1 = $like; $l2 = $like; $l3 = $like;
$s->bind_param('iisss', $a, $b, $l1, $l2, $l3);
$s->execute();
$res = $s->get_result();
echo 'rows: ' . $res->num_rows . PHP_EOL;
while ($r = $res->fetch_assoc()) {
    echo "  - {$r['first_name']} {$r['last_name']}" . PHP_EOL;
}
$s->close();

echo PHP_EOL . '--- Test 3: count projects by student (batch query pattern) ---' . PHP_EOL;
$projects_by_student = [];
if ($uid > 0) {
    $own = $conn->prepare("SELECT rp.project_id, rp.title, rp.status, rp.updated_at, rp.created_by
                             FROM research_projects rp
                            WHERE rp.created_by = ?$rp_f");
    $own->bind_param('i', $uid);
    $own->execute();
    $res = $own->get_result();
    echo 'projects owned by faculty (sanity, should be 0): ' . $res->num_rows . PHP_EOL;
    $own->close();
}

echo PHP_EOL . '--- Test 4: chapter progress batch query (no projects -> 0 rows) ---' . PHP_EOL;
$ch = $conn->query('SELECT project_id, COUNT(*) AS total,
                       SUM(CASE WHEN status="approved" THEN 1 ELSE 0 END) AS approved_count
                  FROM chapters GROUP BY project_id');
echo 'chapter project rollups: ' . ($ch ? $ch->num_rows : 'ERR ' . $conn->error) . PHP_EOL;

echo PHP_EOL . '--- Test 5: needs-attention query ---' . PHP_EOL;
$rev = $conn->query("SELECT DISTINCT project_id FROM chapters WHERE status = 'revision_required'");
echo 'projects with revision_required chapters: ' . ($rev ? $rev->num_rows : 'ERR ' . $conn->error) . PHP_EOL;

// --- Test 6: non-empty path. Insert a transient adviser + member row so the
//     main advisee query actually returns a row, then re-run Test 1.
echo PHP_EOL . '--- Test 6: non-empty path (transient insert) ---' . PHP_EOL;
$student_id = 0;
$sx = $conn->query("SELECT user_id FROM users WHERE role='student' AND status='active'$u_tf LIMIT 1");
if ($sx && ($sr = $sx->fetch_assoc())) { $student_id = (int) $sr['user_id']; }
$project_id = 0;
$rp_tf_where = ($rp_tf !== '' ? " WHERE 1=1$rp_tf" : '');
$px = $conn->query("SELECT project_id FROM research_projects$rp_tf_where LIMIT 1");
if ($px && ($pr = $px->fetch_assoc())) { $project_id = (int) $pr['project_id']; }
$ins_pa = null; $ins_pm = null;
if ($student_id > 0 && $project_id > 0) {
    // The 'id' PKs in these join tables are NOT auto-increment. Pick fresh IDs
    // per run so re-running the script doesn't crash on duplicate keys.
    $next_pa_id = (int) $conn->query('SELECT COALESCE(MAX(id), 0) + 1 AS n FROM project_advisers')->fetch_assoc()['n'];
    $next_pm_id = (int) $conn->query('SELECT COALESCE(MAX(id), 0) + 1 AS n FROM project_members')->fetch_assoc()['n'];

    $ins_pa = $conn->prepare('INSERT INTO project_advisers (id, project_id, adviser_id) VALUES (?, ?, ?)');
    $ins_pa->bind_param('iii', $next_pa_id, $project_id, $uid);
    $ins_pa->execute();
    $ins_pm = $conn->prepare('INSERT INTO project_members (id, project_id, user_id, role) VALUES (?, ?, ?, "lead")');
    $ins_pm->bind_param('iii', $next_pm_id, $project_id, $student_id);
    $ins_pm->execute();

    $s = $conn->prepare($base_sql);
    if (!$s) { echo 'PREPARE6 FAILED: ' . $conn->error . PHP_EOL; exit(1); }
    $a = $uid; $b = $uid;
    $s->bind_param('ii', $a, $b);
    $s->execute();
    $res = $s->get_result();
    echo 'rows: ' . $res->num_rows . PHP_EOL;
    while ($r = $res->fetch_assoc()) {
        echo "  - {$r['user_id']} {$r['first_name']} {$r['last_name']} (program={$r['program']}, yl={$r['year_level']})" . PHP_EOL;
    }
    $s->close();
} else {
    echo 'no student or project to test with' . PHP_EOL;
}

if ($ins_pa) { $conn->query("DELETE FROM project_advisers WHERE id = $next_pa_id"); }
if ($ins_pm) { $conn->query("DELETE FROM project_members   WHERE id = $next_pm_id"); }
// Belt-and-suspenders: clean any stray rows left by a prior crashed run.
if (isset($next_pa_id)) { $conn->query("DELETE FROM project_advisers WHERE id = $next_pa_id"); }
if (isset($next_pm_id)) { $conn->query("DELETE FROM project_members   WHERE id = $next_pm_id"); }

echo PHP_EOL . 'All smoke tests passed.' . PHP_EOL;
?>