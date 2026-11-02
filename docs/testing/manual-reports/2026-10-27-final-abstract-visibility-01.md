# Verification: approved final manuscript abstract visibility

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Student/admin project details and archived project detail show an abstract only when the newest final-manuscript version is approved; reviewer and final-submission surfaces retain their abstract access.
- Environment URL: http://localhost/rms/ (configured local fallback; application availability not confirmed)
- Roles and disposable records/files: Student and administrator views; no records changed.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: research detail, abstract, final manuscript, version, snapshot.
- Query/lookup and result: `graphify query "research detail abstract final manuscript version snapshot" --budget 1500`; truncated and returned stale/unrelated nodes, with no reliable current-page coverage.
- Relevant source locations and verified relationships: `includes/research-workflow.php` now checks the newest `final_manuscript` version and decodes its `snapshot_json` only when status is `approved`. `pages/student/research-detail.php`, `pages/shared/research-detail.php`, and `pages/shared/view-research.php` render that approved snapshot only. `pages/researcher/submit-final-research.php` still uses the project abstract to prefill the submission form and stores the submitted abstract in the version snapshot; `pages/shared/research-submission-review.php` reads the snapshot for authorized review.
- Missing, inferred, stale, or truncated context: Graph lacks dependable current detail-page coverage; query truncated. Relationships confirmed against current source.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Integrated browser / student / project details | Open a project with no approved final manuscript | Abstract is absent | User confirmed the student view is all good | Passed |
| 2 | Integrated browser / student / project details | Open a project with an approved final manuscript | The approved final manuscript abstract is shown | User clarified they saw the approved abstract | Passed |
| 3 | Integrated browser / administrator / shared project details and archived detail | Inspect projects with and without an approved final manuscript | Abstract is absent before approval and matches the approved manuscript snapshot after approval | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- Initial interpretation of “not anymore” as a failure was incorrect. User clarified they saw the approved abstract; check 2 is passed and no fix was needed.

## Assistant-run checks
- `php -l includes/research-workflow.php`: passed.
- `php -l pages/student/research-detail.php`: passed.
- `php -l pages/shared/research-detail.php`: passed.
- `php -l pages/shared/view-research.php`: passed.
- `git diff --check` on changed files: passed.
- No database changes or browser interactions were performed.

## Resume / handoff
- Last user-confirmed state: student detail hides the abstract before final approval and shows it after approval.
- Next pending action: user checks admin shared project detail and archive views for projects with and without an approved final manuscript; no record changes.
- Blocked/skipped checks and remaining verification gaps: browser confirmation pending; Graphify coverage is stale/truncated.
- User-performed data changes and cleanup status: none.
