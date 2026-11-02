# Verification: combined review feedback

- Date/time: 2026-10-29 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Both the document viewer and research review queue show one Comments/Recommendations field. Returning a version requires at least 10 combined feedback characters; historical separate comments and recommendations remain visible together.
- Environment URL: `http://localhost/rms/` (availability not confirmed)
- Roles and disposable records/files: Administrator or authorized research staff; the user supplied research version 99 for viewer presentation only. Do not submit a decision on that record.
- Session state: In progress; assistant source checks complete, user browser checks pending.

## Graphify and source context
- Question and selected graph vocabulary: `research-submission-review`, `comments`, `recommendation`, `download-research-document`, `viewer`.
- Query/lookup and result: `graphify query "research-submission-review comments recommendation download-research-document viewer" --budget 1500`; found both target page nodes among 44 results, with 37 shown before truncation.
- Relevant source locations and verified relationships: `pages/shared/research-submission-review.php:136,668-669,716` receives and displays feedback; `pages/shared/download-research-document.php:349,554-555,569` calls `rms_review_submission()` and renders the viewer field and approval preview. `includes/research-workflow.php:798,878` validates and stores feedback; the helper allows a combined 10-character return value while preserving support for older submissions that send two values.
- Missing, inferred, stale, or truncated context: Graph output was truncated and included unrelated nodes. Confirmed current relationships against source; no graph rebuild or write was performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Assistant / PHP syntax | Run `php -l` on the workflow helper and both changed pages | No syntax errors | All three commands reported no syntax errors | Passed |
| 2 | Assistant / JavaScript syntax | Run `node --check js/research-document-review.js` | No syntax errors | Command exited successfully with no output | Passed |
| 3 | Assistant / diff whitespace | Run `git diff --check` on the four implementation files | No whitespace errors | Command exited successfully with no output | Passed |
| 4 | Integrated browser / administrator or authorized staff / viewer for research version 99 | Open the supplied viewer URL and inspect the review form without submitting it | One Comments/Recommendations field appears; no separate Recommendation field remains | Awaiting user observation | Pending |
| 5 | Integrated browser / administrator or authorized staff / research review queue | Open `research-submission-review.php?module_page=research_review` and inspect a pending version | One Comments/Recommendations field appears for the pending submission | Awaiting user observation | Pending |
| 6 | Integrated browser / administrator or authorized staff / pending disposable version | Enter fewer than 10 feedback characters and choose Return for Revision | Validation prevents the return and explains the minimum | Not attempted; requires a suitable disposable pending version | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l includes/research-workflow.php` — passed.
- `php -l pages/shared/research-submission-review.php` — passed.
- `php -l pages/shared/download-research-document.php` — passed.
- `node --check js/research-document-review.js` — passed.
- `git diff --check -- includes/research-workflow.php pages/shared/research-submission-review.php pages/shared/download-research-document.php js/research-document-review.js` — passed.
- No database or application interaction was performed.

## Resume / handoff
- Last user-confirmed state: No browser observation yet.
- Next pending action: Inspect the supplied viewer URL without submitting a decision, then report whether the single field label is shown and the separate recommendation field is gone.
- Blocked/skipped checks and remaining verification gaps: Queue form and combined return validation await browser observation; a suitable disposable pending version is needed for the latter. Responsive layout and confirmation dialog behavior also await user-operated checks.
- User-performed data changes and cleanup status: None reported.
