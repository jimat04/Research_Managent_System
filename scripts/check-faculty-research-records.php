<?php
/** CLI-only, non-mutating validation checks. Does not connect to any database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/faculty-research-records.php';

$connection = mysqli_init(); // Type-compatible handle; never connected or executed.
$types = facultyRecordWorkTypes();
$passed = 0;
$failed = [];
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) $passed++;
    else $failed[] = $label;
};
$make = static function (array $overrides = []): array {
    $post = array_fill_keys(array_keys(facultyRecordTextFields()), '');
    $post['title'] = '  Independent research record  ';
    $post['work_type'] = 'journal';
    $post['authors'] = [['record_author_id' => '', 'faculty_user_id' => '', 'author_type' => 'external',
        'full_name' => 'Example Author', 'position' => 'Researcher', 'affiliation' => 'Example Institute', 'author_role' => 'lead']];
    return facultyRecordReadInput(array_replace($post, $overrides));
};
$validate = static fn(array $overrides = [], ?array $existing = null): array => facultyRecordValidate($connection, $make($overrides), $types, $existing);

$valid = $validate();
$check(!$valid['errors'], 'Minimal record with blank optional sections');
$check($valid['values']['title'] === 'Independent research record', 'Title is trimmed');
$check($valid['sdgs'] === [], 'SDGs are optional');
$check(count(facultyRecordSdgs()) === 17 && array_keys(facultyRecordSdgs()) === range(1, 17), 'All 17 distinct SDGs available');
$check(isset($validate(['title' => ' '])['errors']['title']), 'Blank title rejected');
$check(!isset($validate(['title' => str_repeat('研', 2000)])['errors']['title']), '2000 Unicode characters accepted');
$check(isset($validate(['title' => str_repeat('研', 2001)])['errors']['title']), 'Overlong Unicode title rejected');
$check(isset($validate(['title' => ['malformed']])['errors']['title']), 'Array input rejected');
$check(isset($validate(['title' => "Invalid\0title"])['errors']['title']), 'NUL text rejected');
$check(isset($validate(['work_type' => 'unlisted'])['errors']['work_type']), 'Unknown work type rejected');
$check(isset($validate(['work_type' => 'other'])['errors']['work_type_other']), 'Other work type needs description');
$check(!$validate(['work_type' => 'other', 'work_type_other' => 'Dataset'])['errors'], 'Other description accepted');
$check($validate(['work_type_other' => 'Unrelated'])['values']['work_type_other'] === '', 'Irrelevant Other description cleared');
$check(isset($validate(['date_started' => '2026-13'])['errors']['date_started']), 'Invalid research month rejected');
$check(isset($validate(['date_started' => '2026-05', 'date_completed' => '2026-04'])['errors']['date_completed']), 'Reverse research dates rejected');
$check(!$validate(['date_started' => '2026-05', 'date_completed' => '2026-05'])['errors'], 'Equal research months accepted');
$check(isset($validate(['conference_start_date' => '2026-02-29'])['errors']['conference_start_date']), 'Invalid calendar date rejected');
$check(!$validate(['conference_start_date' => '2028-02-29'])['errors'], 'Valid leap day accepted');
$check(isset($validate(['conference_start_date' => '2026-10-30', 'conference_end_date' => '2026-10-29'])['errors']['conference_end_date']), 'Reverse conference dates rejected');
$check(isset($validate(['conference_classification' => 'invented'])['errors']['conference_classification']), 'Unsupported conference classification rejected');
$check(isset($validate(['presentation_modality' => 'invented'])['errors']['presentation_modality']), 'Unsupported modality rejected');
$check(!$validate(['sdgs' => ['1', '8', '17']])['errors'], 'Multiple SDGs accepted');
$check(isset($validate(['sdgs' => ['8', '8']])['errors']['sdgs']), 'Duplicate SDGs rejected');
$check(isset($validate(['sdgs' => ['18']])['errors']['sdgs']), 'Out-of-range SDG rejected');
$check(isset($validate(['sdgs' => [['invalid']]])['errors']['sdgs']), 'Malformed SDG rejected');
$check(isset($validate(['journal_title' => str_repeat('x', 501)])['errors']['journal_title']), 'Optional target text limit enforced');
$check(isset($validate(['authors' => []])['errors']['authors']), 'At least one author required');
$author = $make()['authors'][0];
$second = array_replace($author, ['full_name' => 'Second Author', 'author_role' => 'coauthor']);
$check(!$validate(['authors' => [$author, $second]])['errors'], 'External co-author accepted');
$check(isset($validate(['authors' => [array_replace($author, ['author_role' => 'coauthor'])]])['errors']['authors']), 'One lead is required');
$check(isset($validate(['authors' => [$author, array_replace($second, ['author_role' => 'lead'])]])['errors']['authors']), 'Multiple leads rejected');
$check(isset($validate(['authors' => [$author, array_replace($second, ['full_name' => ' EXAMPLE   AUTHOR ', 'affiliation' => 'EXAMPLE institute'])]])['errors']['authors']), 'Normalized duplicate authors rejected');
$check(isset($validate(['authors' => [array_replace($author, ['position' => ''])]])['errors']['authors']), 'Author position required');
$check(isset($validate(['authors' => [array_replace($author, ['record_author_id' => '999'])]])['errors']['authors']), 'Foreign author-row reference rejected');
$check(isset($validate(['authors' => [array_replace($author, ['author_type' => 'internal'])]])['errors']['authors']), 'New internal author requires registered selection');
$check($validate(['authors' => [array_replace($author, ['faculty_user_id' => '999'])]])['authors'][0]['faculty_user_id'] === null, 'External author cannot retain account link');
$ordered = $validate(['authors' => [$second, $author]]);
$check(isset($ordered['errors']['authors']) && array_column($ordered['authors'], 'display_order') === [1, 2] && $ordered['authors'][1]['author_role'] === 'lead', 'Non-lead first author rejected without changing submitted order');
$orderedLead = $validate(['authors' => [array_replace($author, ['author_role' => 'lead']), $second]]);
$check(!$orderedLead['errors'], 'Lead author in position one accepted');

// Historical snapshots are arrays only; no user/session/database is fabricated.
$historical = array_replace($author, ['record_author_id' => '7', 'author_type' => 'internal']);
$existing = ['authors' => [$historical]];
$check(!$validate(['authors' => [$historical]], $existing)['errors'], 'Historical internal snapshot survives missing account link');
$linked = array_replace($historical, ['faculty_user_id' => '12']);
$check(!$validate(['authors' => [$linked]], ['authors' => [$linked]])['errors'], 'Existing internal snapshot can be preserved without profile refresh');
$legacyTypes = $types + ['legacy' => 'Saved legacy classification'];
$legacy = facultyRecordValidate($connection, $make(['work_type' => 'legacy']), $legacyTypes, null);
$check(!$legacy['errors'], 'Saved classification can remain available after configuration changes');
$browse = facultyRecordBrowseOptions(['status' => 'invalid', 'sort' => 'title; DROP TABLE users', 'page' => '-3', 'q' => str_repeat('q', 180)]);
$check($browse['status'] === 'active' && $browse['sort'] === 'newest' && $browse['page'] === 1 && mb_strlen($browse['q']) === 150, 'Browse allowlists, page validation and search length');
$check(facultyRecordBrowseOptions(['status' => ['bad'], 'sort' => ['bad'], 'q' => ['bad']])['q'] === '', 'Malformed browse parameters handled');
$check(facultyRecordBrowsePageSize() === 10, 'Research records paginate 10 per page');
$check(facultyRecordEscape('<script>"&') === '&lt;script&gt;&quot;&amp;', 'Output escaping');

foreach ($failed as $label) fwrite(STDERR, 'FAILED: ' . $label . PHP_EOL);
echo $passed . ' passed; ' . count($failed) . ' failed. No database connection or application interaction.' . PHP_EOL;
exit($failed ? 1 : 0);
