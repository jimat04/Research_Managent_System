# Verification: approved-proposal-filters

- Date/time: 2026-11-07 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Approved Proposal and Approved Final Manuscript combine title/researcher search and course filtering with a Submission year filter based on submission dates. Review Proposals, Chapter Review, and Review Final Manuscript use the same responsive search/course panel, with no year control. These review queues can also sort oldest first, newest first, or by submitter name. Search and selections filter only each server-rendered authorized queue.
- Environment URL: `http://localhost:8080/rms/` (from the user-provided page link; runtime availability not confirmed)
- Roles and disposable records/files: Authorized administrator or research staff; no record changes planned.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How the proposal, chapter, and final-manuscript queues load submission versions, search filters, and sort order; selected graph token `research-submission-review.php`.
- Query/lookup and result: `graphify query "research-submission-review.php" --budget 1200` returned the page node at `pages/shared/research-submission-review.php:L1` and conceptual workflow/data nodes. The graph did not expose current include/helper or hero-section edges.
- Relevant source locations and verified relationships: Current source confirms `pages/shared/research-submission-review.php` loads the authorized review queue with prepared statements and calls `rms_approval_projects()` from `includes/research-approval-list.php` for approved tabs. The helper applies course/year filtering to approved title/final-manuscript projects; `includes/research-approval-view.php` renders those filters. The proposal, chapter, and final-manuscript review tabs apply prepared title/researcher search and fixed allowlisted SQL ordering to their existing course/type/status queue query.
- Missing, inferred, stale, or truncated context: Graph relationships for this page's current helper/view includes are missing. Current source was checked directly and takes precedence. No graph rebuild/update was performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant: PHP syntax | Run `php -l` on the three changed PHP files | No syntax errors | All three reported no syntax errors | Passed |
| S2 | Assistant: focused diff whitespace | Run `git diff --check` on the four changed files | No whitespace errors | No whitespace errors reported; Git emitted line-ending and protected user-config ignore-file warnings | Passed |
| S3 | Assistant: PHP syntax after visual refinement | Run `php -l includes/research-approval-view.php` | No syntax errors | No syntax errors detected | Passed |
| S4 | Assistant: focused diff whitespace after visual refinement | Run `git diff --check` on the changed source, stylesheet, and report | No whitespace errors | No whitespace errors reported; Git emitted line-ending warnings | Passed |
| S5 | Assistant: PHP/CSS checks after cache and width fix | Run `php -l` on changed page/view, `git diff --check`, and compare CSS brace counts | Syntax/structure checks pass | Both PHP files passed; no diff whitespace errors; CSS braces balanced 66/66 | Passed |
| S6 | Assistant: PHP syntax after enabling final-manuscript year filtering | Run `php -l` on approval helper, view, and page | No syntax errors | All three reported no syntax errors | Passed |
| S7 | Assistant: focused diff whitespace after enabling final-manuscript year filtering | Run `git diff --check` on changed files | No whitespace errors | No whitespace errors reported; Git emitted line-ending warnings | Passed |
| S8 | Assistant: PHP syntax after matching the review filter design | Run `php -l pages/shared/research-submission-review.php` | No syntax errors | No syntax errors detected | Passed |
| S9 | Assistant: source/style structure | Run focused `git diff --check` and compare CSS brace counts | No whitespace errors and balanced CSS braces | No whitespace errors; CSS braces balanced 66/66 | Passed |
| S10 | Assistant: PHP syntax after removing Review Final Manuscript hero | Run `php -l pages/shared/research-submission-review.php` | No syntax errors | No syntax errors detected | Passed |
| S11 | Assistant: focused diff whitespace after hero removal | Run `git diff --check` on changed page and report | No whitespace errors | No whitespace errors reported | Passed |
| S12 | Assistant: PHP syntax after applying shared filters to all review queues | Run `php -l pages/shared/research-submission-review.php` | No syntax errors | No syntax errors detected | Passed |
| S13 | Assistant: focused diff whitespace after applying shared filters | Run `git diff --check` on changed page and report | No whitespace errors | No whitespace errors reported | Passed |
| S14 | Assistant: PHP syntax after adding review queue sorting | Run `php -l pages/shared/research-submission-review.php` | No syntax errors | No syntax errors detected | Passed |
| S15 | Assistant: focused diff whitespace after adding review queue sorting | Run `git diff --check` on changed source, stylesheet, and report | No whitespace errors | No whitespace errors reported; Git emitted a line-ending warning | Passed |
| S16 | Assistant: stylesheet structure | Compare opening and closing CSS brace counts | Balanced braces | CSS braces balanced 67/67 | Passed |
| B1 | Authorized administrator or research staff / Approved Proposal | Inspect the supplied page screenshot | The filters are visually grouped and scan cleanly | User screenshot showed Course, Submission year, and Apply filters stacked in separate rows with unused horizontal space | Failed |
| B1R | Authorized administrator or research staff / Approved Proposal | Inspect the screenshot after the first filter-panel refinement | The filter panel uses the available width and aligns its fields | User screenshot still showed default stacked controls and substantial unused space; diagnosis was that the stylesheet was served under an unchanged URL and likely cached | Failed |
| B1R2 | Authorized administrator or research staff / Approved Proposal | Reload after stylesheet cache bust and grid adjustment | Search, Course, Submission year, Apply filters, and Clear filters (when active) appear in a compact responsive panel that fills the available width | User confirmed all pending browser checks passed | Passed |
| B2 | Authorized administrator or research staff / Approved Proposal | After B1R2, select a year and apply filters | Only approved proposals submitted in that year remain; selected year stays active through pagination | User confirmed all pending browser checks passed | Passed |
| B3 | Authorized administrator or research staff / Approved Final Manuscript | Open the approved final manuscript tab | The shared filter panel also shows Submission year, populated from eligible approved final manuscript submissions | User confirmed all pending browser checks passed | Passed |
| B4 | Authorized administrator or research staff / Approved Final Manuscript | After B3, select a year and apply filters | Only approved final manuscripts submitted in that year remain; selected year stays active through pagination | User confirmed all pending browser checks passed | Passed |
| B5 | Authorized administrator or research staff / Review Final Manuscript | Open the review tab | Search and Course appear in the same responsive panel; no year control appears | User confirmed all pending browser checks passed | Passed |
| B6 | Authorized administrator or research staff / Review Final Manuscript | Search for a title or researcher | Only matching final manuscripts remain in the authorized review queue; the search value remains visible | User confirmed all pending browser checks passed | Passed |
| B7 | Authorized administrator or research staff / Review Final Manuscript | Reload the review tab | The redundant hero is gone; the filter-panel heading remains above the queue | User confirmed all outstanding browser checks passed | Passed |
| B8 | Authorized administrator or research staff / Review Proposals | Open the review tab | Search and Course appear in the matching panel; no year selector or separate hero appears | User confirmed all outstanding browser checks passed | Passed |
| B9 | Authorized administrator or research staff / Chapter Review | Open the review tab | Search and Course appear in the matching panel; no year selector or separate hero appears | User confirmed all outstanding browser checks passed | Passed |
| B10 | Authorized administrator or research staff / Review Proposals | Search for a proposal title or researcher | Only matching proposals remain in the authorized review queue; the search value remains visible | User confirmed all outstanding browser checks passed | Passed |
| B11 | Authorized administrator or research staff / Chapter Review | Search for a title or researcher | Only matching chapters remain in the authorized review queue; the search value remains visible | User confirmed all outstanding browser checks passed | Passed |
| B12 | Authorized administrator or research staff / Review Proposals | Open the review tab | Sort by offers oldest first, newest first, and submitter name A–Z; no year control appears | User confirmed all outstanding browser checks passed | Passed |
| B13 | Authorized administrator or research staff / Chapter Review | Open the review tab | Same three sort choices appear; no year control appears | User confirmed all outstanding browser checks passed | Passed |
| B14 | Authorized administrator or research staff / Review Final Manuscript | Open the review tab | Same three sort choices appear; no year control appears | User confirmed all outstanding browser checks passed | Passed |
| B15 | Authorized administrator or research staff / Any of the three review tabs | Choose each sort option and apply filters | Queue order changes to oldest submission, newest submission, or submitter name A–Z, while search/course remain selected | User confirmed all outstanding browser checks passed | Passed |

## Failures, decisions, fixes, and retests
- User-provided screenshots showed the controls stacked with substantial unused space on the right, including after the panel markup was refined. The page linked `research-approval.css` without a version, so a stale cached stylesheet was a likely cause. Added a `filemtime()` cache version to that stylesheet URL; the grid columns now expand to occupy available desktop width and collapse at narrower breakpoints. The user later confirmed the filter checks passed. The hero removal was then requested and is awaiting browser confirmation.

## Assistant-run checks
- `php -l pages/shared/research-submission-review.php` — passed.
- `php -l includes/research-approval-list.php` — passed.
- `php -l includes/research-approval-view.php` — passed.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/shared/research-submission-review.php includes/research-approval-list.php includes/research-approval-view.php css/research-approval.css` — no whitespace errors reported. Git emitted line-ending and protected user-config ignore-file warnings.
- After the visual refinement: `php -l includes/research-approval-view.php` — passed; focused diff check — no whitespace errors reported.
- After the cache/width fix: `php -l pages/shared/research-submission-review.php` and `php -l includes/research-approval-view.php` — passed; focused diff check — no whitespace errors; CSS braces balanced 66/66.
- After adding year filtering to Approved Final Manuscript: PHP syntax checks for the helper, view, and page passed; focused diff check reported no whitespace errors.
- After matching the filter design on Review Final Manuscript: page PHP syntax passed; focused diff check reported no whitespace errors; CSS braces balanced 66/66.
- After removing the Review Final Manuscript hero section: `php -l pages/shared/research-submission-review.php` passed; focused diff check reported no whitespace errors.
- After matching the panel on Review Proposals and Chapter Review: page PHP syntax passed; focused diff check reported no whitespace errors.
- After adding oldest/newest/submitter sorting: PHP syntax passed; focused diff check reported no whitespace errors; CSS braces balanced 67/67.
- No application interaction or database query was performed by the assistant.

## Resume / handoff
- Last user-confirmed state: User confirmed all outstanding browser checks passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported for this session.
- User-performed data changes and cleanup status: None.
