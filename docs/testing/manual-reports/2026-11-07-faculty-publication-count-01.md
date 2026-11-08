# Verification: faculty-publication-count

- Date/time: 2026-11-07 22:38 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Faculty dashboard publication count and recent list include active publications the faculty member owns or is explicitly linked to as a contributor.
- Environment URL: `http://localhost:8080/rms/`
- Roles and disposable records/files: Existing faculty dashboard and publication supplied by the user; no data changes requested or performed.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: Why does the faculty publication dashboard omit a publication owned by the logged-in faculty member? Selected `faculty`, `dashboard`, and `publication` from matching labels in the graph.
- Query/lookup and result: `graphify query "faculty dashboard publication" --budget 1500`; 27 nodes and 22 edges returned, slightly over budget. Follow-up UI lookup: `graphify query "faculty publication author" --budget 1300`; 27 nodes and 22 edges returned, also slightly over budget.
- Relevant source locations and verified relationships: `includes/archival-record-list.php:9` allows faculty publication visibility by owner ID or contributor link. `includes/services/FacultyDashboardService.php:220` uses the same rule for the stat, and `:237` applies it to recent publications. `includes/publication-archive-form.php:51` matches the logged-in faculty name against ordered authors; `:100` keeps their account in the submitted contributor IDs, and `:220` renders their checked account control disabled with a hidden submitted value when their name is listed.
- Missing, inferred, stale, or truncated context: Graph coverage is incomplete for the dashboard data service and publication archive form; results connect general faculty routes but omit these helpers. Findings are confirmed against current PHP source. Both graph responses contained all listed nodes despite budget notes.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Static / PHP syntax | Run `php -l includes/services/FacultyDashboardService.php` | No syntax errors | `No syntax errors detected` | Passed |
| 2 | Faculty / new publication form | User opens the new publication form | The logged-in faculty account is already checked when preselected as a linked author | User screenshot shows the matching faculty account checked | Passed |
| 3 | Faculty / dashboard | User opens the Faculty Dashboard | Publication Records includes the active publication owned by that account; recent publications can include it | User confirmed: “all fixed” | Passed |
| 4 | Faculty / publication form | User reloads the form | When the logged-in faculty member's name is in the author list, their RMS account remains checked and cannot be toggled off | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- Initial cause: the dashboard count and recent-publication queries required a contributor link and ignored the publication owner field. The faculty archive list already permits owner OR contributor access.
- Fix: both dashboard queries now include rows where `publications.researcher_id` matches the faculty user or a `publication_contributors` link matches. No database rows were changed.
- Follow-up fix: the publication form now disables the logged-in faculty member's own author checkbox while their name appears in the ordered author list. It also submits the linked ID through a hidden field and restores it server-side, so omission from a crafted or malformed POST cannot remove the association.

## Assistant-run checks
- `php -l includes/services/FacultyDashboardService.php` — passed.
- Reviewed the focused diff for `includes/services/FacultyDashboardService.php`; count and recent-list prepared statement parameter types match their placeholders.
- `php -l includes/publication-archive-form.php` — passed.
- `git diff --check -- includes/publication-archive-form.php` — passed.

## Resume / handoff
- Last user-confirmed state: User confirmed the dashboard issue is fixed and supplied a form screenshot with their own linked author checked.
- Next pending action: User reloads the faculty publication form and confirms their own checked author link cannot be toggled off.
- Blocked/skipped checks and remaining verification gaps: The latest form interaction is pending. No database inspection or application interaction was performed by the assistant.
- User-performed data changes and cleanup status: None reported.
