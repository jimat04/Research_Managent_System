# Verification: faculty participation print

- Date/time: 2026-11-04 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Admin can print a faculty profile’s identifying details and activity participation history without the application shell or other profile sections.
- Environment URL: `http://localhost:8080/rms/pages/admin/admin-researchers.php`
- Roles and disposable records/files: Admin; existing faculty profile, read-only.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How the admin researcher page obtains and renders faculty participation; `adminResearchers`, `activity_participants`.
- Query/lookup and result: `rtk graphify query "participation" --budget 1500` found one unrelated report node. `rtk graphify query "adminResearchers activity_participants" --budget 1500` found `admin-researchers.php`, `adminResearchersEscape()` at L9, `adminResearchersTableExists()` at L14, and `adminResearchersRun()` at L24, plus activity-form concepts.
- Relevant source locations and verified relationships: `pages/admin/admin-researchers.php` requires the admin role; for an individual profile it queries activity rows through `activity_participants` joined to `research_activities`, then renders date, activity/type, participant role, status, and attendance. Current source is authoritative; the graph did not expose the query/render relationship.
- Missing, inferred, stale, or truncated context: Graph lookup coverage is incomplete for the profile query and print behavior. Confirmed against current source. No schema change or database action.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l pages/admin/admin-researchers.php` | No PHP syntax errors | `No syntax errors detected in pages/admin/admin-researchers.php` | Passed |
| S2 | Assistant static check | Run `git diff --check` on the worktree | No whitespace errors in the change | No diff-check errors; unrelated worktree changes remain untouched | Passed |
| B1 | Admin / admin researchers | Open a faculty researcher profile | Profile page shows a **Print participation** button | User confirmed the button is visible | Passed |
| B2 | Admin / faculty researcher profile | Click **Print participation** | Print preview contains faculty name and selected identity fields plus all currently filtered activity-history rows; app shell, projects, faculty profile extras, publications, copyright outputs, and filter controls are absent | Depends on B1 | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l pages/admin/admin-researchers.php` passed.
- `git diff --check` reported no whitespace errors. Git also reported unrelated worktree changes; they were preserved.

## Resume / handoff
- Last user-confirmed state: Not yet browser-verified.
- Next pending action: User opens a faculty profile from the admin researchers page and confirms the button is visible.
- Blocked/skipped checks and remaining verification gaps: Browser print preview and completeness are pending user confirmation.
- User-performed data changes and cleanup status: None; print is read-only.
