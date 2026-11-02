# Verification: document review comments and recommendations

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The student's My Documents table keeps review notes collapsed behind one per-file control; expanding it shows comments, recommendation, decision, reviewer, version, and review date.
- Environment URL: `http://localhost/rms/pages/student/my-documents.php` (not opened by the assistant)
- Roles and disposable records/files: Student; use existing project documents and reviews. No records are changed by the assistant.
- Session state: In progress; a layout issue was reported and the CSS fix is awaiting browser retest.

## Graphify and source context
- Question and selected graph vocabulary: `uploads`, `research_submission_version_uploads`, `research_submission_reviews`, `my-documents.php`.
- Query/lookup and result: Initial query: `graphify query "research_submission_version_uploads research_submission_reviews uploads my-documents.php" --budget 1700`; 95 nodes found, output truncated at 48. Layout follow-ups: `graphify query "my-documents.php review recommendation table-mobile-stack" --budget 1200` (80 nodes, truncated at 33), and `graphify query "my-documents.php Preview action table" --budget 1100` (81 nodes, truncated at 31). The defense filter lookup used `graphify query "my-documents.php defense type filter uploads" --budget 1100`; 66 nodes found, output truncated at 33. Status clarification lookup used `graphify query "my-documents.php chapter_status project_status Review feedback" --budget 1000`; 99 nodes found, output truncated at 27. Current source confirms `includes/research-workflow.php:484` links submitted upload IDs to submission versions.
- Relevant source locations and verified relationships: The canonical schema defines version-to-upload links and review comments/recommendations in `database/schema/rms_db.sql:658-690`. The documents page scopes upload queries to projects owned by or joined by the logged-in student (`pages/student/my-documents.php:99-153`), then reads the latest review for each version linked to each returned upload. Comments display decision, version, reviewer, and review date; recommendation appears in its own column.
- Missing, inferred, stale, or truncated context: Graph output was truncated and contained unrelated historical/E2E references. Findings were checked against current schema, upload-link helper, and page source. Legacy/unlinked uploads have no associated review data and display that no linked review record exists.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant / PHP syntax | Run `php -l pages/student/my-documents.php` | No PHP syntax errors | No syntax errors detected | Passed |
| A2 | Student / My Documents | Expand the Review feedback control for a proposal or chapter file with review history | Comments and recommendation are hidden by default and both appear on expansion with review context; long feedback wraps without overlapping neighboring columns | Earlier screenshot showed the always-visible feedback columns clipped/overlapping. Follow-up CSS first widened and justified the columns, then feedback was combined under a single expandable Review feedback control. Final browser retest pending. | Pending |
| A3 | Student / My Documents | Open a legacy or not-yet-reviewed document | Cells clearly show that no review is linked, without inventing review content | Awaiting user-operated browser check | Pending |
| A4 | Student / My Documents / narrow viewport | View a document row on a mobile-sized viewport | Comments and recommendation remain readable under their table labels | Awaiting user-operated browser check | Pending |
| A5 | Student / My Documents | Inspect the final Action column | Preview button has visible right padding from the card edge | User screenshot showed the Preview button nearly touching the right card edge; action column widened and right padding increased. Retest pending. | Pending |
| A6 | Student / My Documents type filter | Open the Document type filter and inspect All types | Defense is absent as a filter option; Defense files remain included when All types is selected | Assistant source check confirms the Defense option and explicit defense filter value were removed; user browser check pending | Pending |
| A7 | Student / My Documents | Inspect a chapter document whose current status differs from its linked review decision | Column reads Current status and labels the badge Chapter status; expanded feedback identifies the specific review version and decision | Awaiting user-operated browser check | Pending |

## Failures, decisions, fixes, and retests
- Initial browser observation: User screenshot showed the recommendation column clipped at the card edge and feedback columns too narrow. User requested justified feedback text and more width.
- Fix 1: Added a keyboard-focusable horizontal scroll region, widened the feedback columns, and justified comment/recommendation text.
- Follow-up: User requested the feedback remain hidden until expanded. Combined both fields in one native disclosure control per document row and reduced the table width. Original clipping/overlap remains recorded until final browser retest passes.
- Follow-up observation: User screenshot showed the Preview button too close to the card's right edge. Widened the Action column and added right padding; retest pending.

## Assistant-run checks
- `php -l pages/student/my-documents.php` — passed.
- `git diff --check -- pages/student/my-documents.php` — passed (line-ending and global Git ignore access warnings only).
- Database effects: none.

## Resume / handoff
- Last user-confirmed state: No browser state confirmed.
- Next pending action: Refresh My Documents; verify the chapter status caption distinguishes current Chapter status from the version-specific review decision.
- Blocked/skipped checks and remaining verification gaps: Review-to-upload content, unlinked-record fallback, mobile layout, and the type-filter presentation require user-operated browser verification.
- User-performed data changes and cleanup status: None reported.
