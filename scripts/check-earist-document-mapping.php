<?php
/** Pure semantic checks: no config bootstrap, database, session or HTTP requests. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/earist-document-mapping.php';
$passed = 0;
$failed = [];
$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) $passed++; else $failed[] = $message;
};
$denied = static function (callable $operation, int $code) use ($check): void {
    try { $operation(); $check(false, 'Expected policy rejection ' . $code); }
    catch (DomainException $exception) { $check($exception->getCode() === $code, 'Policy rejection code ' . $code); }
};
$profile = ['user_id' => 101, 'first_name' => 'Pure', 'middle_name' => null, 'last_name' => 'Owner', 'academic_rank' => 'Instructor I', 'designation' => 'Program Head', 'appointment_status' => 'Permanent', 'department' => 'Computing', 'email' => 'pure@example.test', 'contact' => '', 'affiliation' => 'Internal institution'];
$author = ['record_author_id' => 201, 'research_record_id' => 301, 'faculty_user_id' => null, 'author_type' => 'external', 'full_name' => 'Saved External Lead', 'position' => 'Saved Position', 'affiliation' => 'External Institution', 'author_role' => 'lead', 'display_order' => 1];
$coauthor = array_replace($author, ['record_author_id' => 202, 'faculty_user_id' => 101, 'author_type' => 'internal', 'full_name' => 'Saved Owner Snapshot', 'affiliation' => 'Old affiliation', 'author_role' => 'coauthor', 'display_order' => 2]);
$record = array_fill_keys(array_keys(facultyRecordTextFields()), '');
$record = array_replace($record, ['research_record_id' => 301, 'owner_id' => 101, 'version' => 4, 'status' => 'active', 'deleted_at' => null, 'title' => 'Pure mapping check — no database record', 'work_type' => 'conference', 'work_type_label' => 'Saved conference label', 'authors' => [$author, $coauthor], 'sdgs' => [4, 8, 9, 17], 'sdg_other' => 'Saved additional alignment', 'date_started' => '2026-02', 'date_completed' => '2026-10', 'conference_title' => 'Saved conference', 'conference_host' => 'Saved host', 'conference_organizer' => 'Saved organizer', 'conference_classification' => 'international', 'conference_start_date' => '2026-11-01', 'conference_end_date' => '2026-11-03', 'conference_venue' => 'Saved venue', 'presentation_modality' => 'virtual', 'journal_title' => 'Saved journal', 'journal_publisher' => 'Saved publisher', 'creation_token_hash' => 'must-never-be-returned']);
$map = static fn(string $type, array $inputs = [], array $changes = [], array $profileChanges = []): array => earistMappingBuild(array_replace($profile, $profileChanges), array_replace($record, $changes), $type, $inputs);
$field = static fn(array $result, string $key) => $result['fields'][$key]['value'];
foreach (['annex_a', 'annex_b', 'annex_c', 'annex_e', 'annex_f', 'qsf_rds_019'] as $type) {
    $result = $map($type);
    $check($result['review_valid'], $type . ': baseline validates');
    $check($field($result, 'research.title') === $record['title'], $type . ': title from selected source');
    $check($field($result, 'research.authors')[0]['full_name'] === $author['full_name'], $type . ': saved author snapshot');
    $check(!isset($result['source']['record']['creation_token_hash']), $type . ': creation token excluded');
    $check($result['temporary_inputs_saved'] === false, $type . ': answers never marked saved');
    foreach ($result['fields'] as $key => $definition) if (str_starts_with($key, 'reserved.')) $check($definition['value'] === null && $definition['classification'] === 'Authorized-Only', $type . ': official fields protected');
}
$check(count(array_filter(earistMappingDefinitions()['documents'], static fn(array $d): bool => $d['role'] === 'faculty' && $d['enabled'])) === 6, 'Exactly six faculty types');
foreach (['student', 'research_staff', 'admin', ''] as $role) $denied(static fn() => earistMappingAssertAccess(['user_id' => 101, 'role' => $role], 'annex_a'), 403);
$denied(static fn() => earistMappingAssertAccess(['user_id' => 101, 'role' => 'faculty'], 'annex_d'), 403);
$denied(static fn() => earistMappingAssertAccess(['user_id' => 101, 'role' => 'admin'], 'annex_d'), 403);
$denied(static fn() => $map('annex_d'), 403);
$denied(static fn() => $map('../../annex_d'), 400);
$denied(static fn() => $map('annex_a', [], ['owner_id' => 999]), 404);
$denied(static fn() => $map('annex_a', [], ['deleted_at' => '2026-10-30 12:00:00']), 404);
$denied(static fn() => earistMappingAssertRecord(null, 101), 404);
$check($map('annex_a', [], ['status' => 'archived'])['review_valid'], 'Archived source remains available');
foreach (['score', 'signature', 'itso_result', 'approval', 'owner_id', 'research.title', 'reserved.total'] as $key) {
    $result = $map('annex_a', [$key => 'unauthorized']);
    $check(!$result['review_valid'] && !isset($result['inputs'][$key]), 'Reject and discard unsupported field: ' . $key);
}
$a = $map('annex_a');
$check($a['inputs']['checklist_profiles'] === '' && $a['fields']['input.checklist_profiles']['classification'] === 'Document-Specific', 'Template prechecks are not checklist answers');
$check($field($a, 'conference.host_organizer') === ['Host' => 'Saved host', 'Organizer' => 'Saved organizer'], 'Host and organizer remain distinct');
$check($field($map('annex_a', ['submission_target' => 'journal']), 'conference.title') === null, 'Journal-only target excludes conference mappings');
$check($map('annex_a', ['submission_target' => 'journal'])['source']['record']['conference_title'] === 'Saved conference', 'Inactive section retains source');
$check($field($map('annex_b'), 'research.date_started') === '2026-02', 'Month precision preserved');
$check($field($map('annex_b', [], ['date_started' => null]), 'research.date_started') === '', 'Missing research date not replaced by system timestamp');
$changed = $map('annex_c', [], [], ['first_name' => 'Changed', 'middle_name' => 'Middle', 'affiliation' => 'Changed institution']);
$check($field($changed, 'research.authors')[1]['full_name'] === 'Saved Owner Snapshot', 'Profile changes do not replace author names');
$check($field($changed, 'research.authors')[0]['affiliation'] === 'External Institution', 'External affiliation not overwritten');
$q = $map('qsf_rds_019');
$check($field($q, 'applicant.name') === null && $field($q, 'presentation.presenter') === null, 'No automatic applicant/presenter');
$check($field($q, 'research.sdgs') === [8, 9] && $q['source']['record']['sdgs'] === [4, 8, 9, 17], 'Only printed SDGs mapped; full source retained');
$check((bool) array_filter($q['warnings'], static fn(string $s): bool => str_contains($s, 'Unrepresented selections retained') && str_contains($s, '4, 17')), 'Unsupported SDGs explicitly warned');
$check($q['inputs']['qsf_classification'] === '', 'QSF classification not inferred from Annex A');
$confirmed = $map('qsf_rds_019', ['applicant_source' => 'profile', 'presenter_author_id' => '202']);
$check($field($confirmed, 'applicant.name') === 'Pure Owner', 'Profile applicant explicitly confirmed without invented middle name');
$check($field($confirmed, 'applicant.position') === 'Instructor I / Program Head', 'Rank and designation preserved');
$check($field($confirmed, 'presentation.presenter')['record_author_id'] === 202, 'Owner coauthor can be selected as presenter explicitly');
$check($field($confirmed, 'research.authors')[0]['is_presenter'] === false && $field($confirmed, 'research.authors')[1]['is_presenter'] === true, 'Presenter marker does not reorder authors');
$check(!$confirmed['fields']['input.applicant_name']['applicable'], 'Confirmed profile does not require duplicate manual applicant answers');
$check($confirmed['source']['authorship']['lead_author']['full_name'] === 'Saved External Lead' && count($confirmed['source']['authorship']['coauthors']) === 1, 'Lead and coauthor views derive only from saved authorship');
$check(!$map('qsf_rds_019', ['presenter_author_id' => '999'])['review_valid'], 'Foreign presenter rejected');
$manual = $map('qsf_rds_019', ['applicant_source' => 'manual', 'applicant_name' => 'Confirmed Other Applicant', 'applicant_email' => 'other@example.test']);
$check($field($manual, 'applicant.name') === 'Confirmed Other Applicant', 'Explicit applicant can differ from owner and lead');
$check(!$map('qsf_rds_019', ['applicant_source' => 'profile', 'applicant_name' => 'Conflicting'])['review_valid'], 'Conflicting applicant sources rejected');
$badEmail = $map('qsf_rds_019', ['applicant_source' => 'manual', 'applicant_email' => 'invalid']);
$check(!$badEmail['review_valid'] && $field($badEmail, 'applicant.email') === null, 'Invalid applicant email is not mapped');
$check(!$map('annex_a', ['conference_deadline' => '2026-02-30'])['review_valid'], 'Invalid calendar date rejected');
$check(!$map('annex_a', [], ['date_started' => '2026-13'])['review_valid'], 'Invalid saved month detected');
$check($field($map('annex_b', [], ['date_started' => '2026-13']), 'research.date_started') === null, 'Invalid saved date excluded from usable mapping');
$check(!$map('annex_a', [], ['title' => "bad\x01title"])['review_valid'], 'XML-incompatible source text detected');
$check(!$map('annex_a', [], ['conference_end_date' => '2026-10-01'])['review_valid'], 'Reversed saved conference dates detected');
$check(!$map('qsf_rds_019', ['abstract_submitted' => 'yes'])['review_valid'], 'Declared milestone needs its date');
$check(!$map('qsf_rds_019', ['abstract_submitted' => 'no', 'abstract_date' => '2026-10-01'])['review_valid'], 'Date cannot imply an unconfirmed milestone');
$check(!$map('qsf_rds_019', ['abstract_submitted' => 'yes', 'abstract_date' => '2026-10-10', 'accepted' => 'yes', 'acceptance_date' => '2026-10-09'])['review_valid'], 'Reversed milestone dates rejected');
$check(!$map('annex_a', ['conference_indexing' => ['wos', 'na'], 'proceedings' => 'yes'])['review_valid'], 'Indexing N/A cannot coexist with a service');
$check(!$map('annex_a', ['conference_indexing' => ['wos']])['review_valid'], 'Indexing cannot declare unconfirmed proceedings');
$check(!$map('annex_a', ['ai_used' => 'yes'])['review_valid'], 'AI declaration requires details');
$ai = ['tool' => 'Tool', 'purpose' => 'Editing', 'prompts' => 'Check wording', 'extent' => 'Abstract'];
$check($map('annex_a', ['ai_used' => 'yes', 'ai_tools' => [$ai]])['review_valid'], 'Complete explicit AI details accepted');
$check(!$map('annex_a', ['ai_used' => 'no', 'ai_tools' => [$ai]])['review_valid'], 'Contradictory AI declaration rejected');
$check(!$map('annex_a', ['ai_used' => 'no'])['fields']['input.ai_tools']['applicable'], 'No AI declaration does not request AI-detail completion');
$check(!$map('annex_a', ['registration_fee' => '-1', 'registration_currency' => 'PHP'])['review_valid'], 'Negative fee rejected');
$check($map('annex_a', ['registration_fee' => '0', 'registration_currency' => 'PHP'])['review_valid'], 'Explicit zero fee retained');
$check(!$map('qsf_rds_019', ['funding_source' => 'earist'])['review_valid'], 'Assistance request needs estimate and currency');
$contribution = ['categories' => ['Conceptualization'], 'description' => 'Explicit contribution', 'percentage' => '50'];
$b = $map('annex_b', ['contributions' => [201 => $contribution, 202 => $contribution]]);
$check($b['review_valid'], 'Explicit complete contribution set totals 100');
$check(!$map('annex_b', ['contributions' => [999 => $contribution]])['review_valid'], 'Foreign contribution author rejected');
$check(!$map('annex_b', ['contributions' => [201 => array_replace($contribution, ['percentage' => '101'])]])['review_valid'], 'Percentage over 100 rejected');
$check(!$map('annex_b', ['contributions' => [201 => $contribution, 202 => array_replace($contribution, ['percentage' => '40'])]])['review_valid'], 'Complete percentages must total 100');
$check($map('annex_b')['fields']['contributions.201.percentage']['classification'] === 'Document-Specific', 'Missing per-author percentage identified');
$long = str_repeat('文', 2000);
$many = [$author, $coauthor, array_replace($coauthor, ['record_author_id' => 203, 'display_order' => 3])];
$expanded = $map('annex_e', [], ['title' => $long, 'authors' => $many]);
$check($field($expanded, 'research.title') === $long && count($field($expanded, 'research.authors')) === 3, 'Long Unicode title and all authors retained');
$check(count($expanded['warnings']) >= 3, 'Long-title and author expansion warnings');
$other = $map('annex_a', [], ['research_record_id' => 302, 'title' => 'Different source', 'conference_title' => 'Different conference', 'sdgs' => [1]]);
$check($field($other, 'research.title') === 'Different source' && $field($other, 'conference.title') === 'Different conference' && $field($other, 'research.sdgs') === [1], 'Independent source records never share mapping state');
$check($field($a, 'research.title') === $record['title'], 'Earlier result unchanged after another mapping');
foreach ([['proceedings' => ['yes']], ['conference_indexing' => [['wos']]], ['ai_tools' => 'bad'], ['conference_deadline' => "bad\0text"], ['scope_explanation' => "\xC3\x28"]] as $malformed) $check(!$map('annex_a', $malformed)['review_valid'], 'Malformed input rejected');
$overlong = str_repeat('x', 4001);
$invalid = $map('annex_a', ['scope_aligned' => 'yes', 'scope_explanation' => $overlong]);
$check(!$invalid['review_valid'] && $invalid['inputs']['scope_explanation'] === $overlong && $field($invalid, 'input.scope_explanation') === null, 'Overlong input retained for correction but excluded from mapping');
$check(facultyRecordEscape('<script>"&') === '&lt;script&gt;&quot;&amp;', 'Existing output escaping reused');
$check($map('annex_a', ['checklist_profiles' => 'yes'])['inputs']['checklist_profiles'] === 'yes' && $map('annex_a')['inputs']['checklist_profiles'] === '', 'Temporary answers do not persist into a new mapping call');
$manifest = json_decode(file_get_contents(__DIR__ . '/../config/earist-template-bindings.json'), true, 512, JSON_THROW_ON_ERROR);
$check(array_keys($manifest['documents']) === array_keys(earistMappingDefinitions()['documents']), 'All document definitions have template manifests');
foreach ($manifest['documents'] as $type => $template) $check(earistMappingTemplateInfo($type)['matches'], $type . ': approved package hash');
foreach ($failed as $message) fwrite(STDERR, 'FAILED: ' . $message . PHP_EOL);
echo $passed . ' passed; ' . count($failed) . ' failed. Pure mapping/policy checks only; no database, sessions or application requests.' . PHP_EOL;
exit($failed ? 1 : 0);
