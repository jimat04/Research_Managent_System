# Verification: manuscript-year

- Date/time: 2026-11-06 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The user requested removal of the Submission year filter from both Final Manuscript tabs. The Course filter and approved-list search/pagination remain available.
- Environment URL: `http://localhost/rms/` (configured fallback; not confirmed running)
- Roles and disposable records/files: Authorized administrator or research staff; no record changes planned.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How the Final Manuscript review and approved tabs load; `research-submission-review.php`, `research-approval-list.php`.
- Query/lookup and result: `graphify query "research-submission-review.php" --budget 1200` returned the page node at `pages/shared/research-submission-review.php:L1` plus conceptual workflow nodes. It did not map the current page-specific include/helper path; current source confirmed the direct relationship.
- Relevant source locations and verified relationships: `pages/shared/research-submission-review.php` selects the tab, queries versions, and includes `includes/research-approval-list.php` for approved projects; `includes/research-approval-list.php` provides prepared, paginated project queries; `includes/research-approval-view.php` renders search and pagination. Year-specific UI, query parameters, and SQL have now been removed from these paths.
- Missing, inferred, stale, or truncated context: The graph returned broad conceptual neighbors rather than direct page/helper edges; source confirmed current relationships. No graph rebuild/update performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant: PHP syntax | Run `php -l` on the three changed PHP files | No syntax errors | All three reported no syntax errors | Passed |
| S2 | Assistant: diff whitespace | `git diff --check` | No whitespace errors | No whitespace errors reported; Git emitted unrelated existing protected-file permission warnings while scanning the worktree | Passed |
| B1 | Authorized admin or research staff / Review Final Manuscript | Open the Final Manuscript in-review tab | Course filtering remains; no Submission year control appears | Awaiting user observation | Pending |
| B2 | Authorized admin or research staff / Approved Final Manuscript | User opened the approved tab before removal | The approved list renders with its Submission year selector | User reported `ArgumentCountError` in `includes/research-approval-list.php:23`: eight bind variables were supplied with six type characters | Failed |
| B2R | Authorized admin or research staff / Approved Final Manuscript | Reload the approved tab after removal | The approved list renders without a fatal error; no Submission year control appears | User replied “passed” after being asked to reload the approved tab | Passed |

## Failures, decisions, fixes, and retests
- User-reported failure: approved Final Manuscript view threw `ArgumentCountError` because the year filter added two values while the approval-list count binding retained six type characters. The user then requested removal of the filter; all filter-specific query and binding changes were removed, restoring the original approval-list query path.

## Assistant-run checks
- `php -l pages/shared/research-submission-review.php` — passed.
- `php -l includes/research-approval-list.php` — passed.
- `php -l includes/research-approval-view.php` — passed.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check` — no whitespace errors reported; unrelated worktree protected-file permission warnings appeared.
- After removal: `php -l` passed for `pages/shared/research-submission-review.php`, `includes/research-approval-list.php`, and `includes/research-approval-view.php`; focused `git diff --check` reported no whitespace errors. A targeted search found no remaining Submission year UI, state, or SQL in these files. No database query or application interaction was run by the assistant.

## Resume / handoff
- Last user-confirmed state: User reloaded the approved Final Manuscript tab and reported “passed.”
- Next pending action: Confirm the in-review Final Manuscript tab still shows Course filtering and no Submission year control.
- Blocked/skipped checks and remaining verification gaps: In-review browser confirmation remains pending. Year-selection checks no longer apply. No database changes were made.
- User-performed data changes and cleanup status: None.
