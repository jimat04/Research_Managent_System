# Verification: publication dissemination staff navigation

- Date/time: 2026-11-07 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Staff receive Admin-style publication actions, keep the Admin-matched Capacity Building and Research Development navigation, and see the Faculty Research & Engagement group only for staff-authorized destinations.
- Environment URL: Local RMS; exact browser URL not inspected.
- Roles and disposable records/files: Staff role with existing publication permissions; no records changed.
- Session state: In progress; awaiting user-operated browser check.

## Graphify and source context
- Question and selected graph vocabulary: How staff navigation, permission checks, and role-safe faculty engagement links connect. Terms: `renderStaffShell`, `rmsPermissionModules`, `hasModulePermission`, `requireModulePermission`, `publications`, `publication`.
- Query/lookup and result: `graphify` query command was unavailable. Read-only inspection of `graphify-out/graph.json` found `renderStaffShell()` in `includes/staff-shell.php:L28`, `rmsPermissionModules()` in `includes/auth.php:L221`, `hasModulePermission()` in `includes/auth.php:L504`, `requireModulePermission()` in `includes/auth.php:L595`, and shared publication pages at `pages/shared/publications.php:L1` and `pages/shared/publication-form.php:L1`. Extracted edges connect the permission matrix to permission lookup; inferred staff-shell links were checked against source.
- Relevant source locations and verified relationships: Current source confirms staff navigation and its permission-gated submenus in `includes/staff-shell.php`; publication alias permission checks are in `includes/archival-record-page.php`; the shared list applies staff category scopes in `includes/archival-record-list.php`; create permissions and type filtering are in `includes/publication-archive-form.php`. `admin-faculty-engagement.php` allows staff with `research_development/research_review` view permission. Faculty profiles, Annex D, collaboration, and reports endpoints currently require Admin.
- Missing, inferred, stale, or truncated context: The graph was built at commit `9d996919f74f8c4e73d6fe77804146a9c2c7acb6`; it lacks current coverage for `archival-record-page.php` and the faculty engagement route. Current source takes precedence. No useful graph traversal command was installed/available.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l` on the four changed PHP files. | All files parse. | No syntax errors reported for `includes/staff-shell.php`, `includes/archival-record-page.php`, `includes/archival-record-list.php`, and `includes/publication-archive-form.php`. | Passed |
| S2 | Assistant static check | Run `git diff --check` on changed files. | No whitespace errors. | No whitespace errors reported. | Passed |
| S3 | Assistant static check | Run `php -l` and `git diff --check` after the additional sidebar change. | Staff shell parses and has no whitespace errors. | `includes/staff-shell.php` reported no syntax errors; scoped diff check reported no whitespace errors. | Passed |
| B1 | User browser / staff with publication view access | Open Publication & Dissemination > All Records. | Staff submenu has the three admin-style actions; All Records opens and lists only records within assigned grants. | Awaiting user observation. | Pending |
| B2 | User browser / staff with publication manage access | Open Add New and inspect available publication types. | Only journal and conference types for which the account has manage permission can be created. | Not started; depends on B1. | Pending |
| B3 | User browser / staff with publication view access | Open Reports and return to All Records. | Reports are limited to authorized categories; the correct submenu item remains active when moving between views. | Not started; depends on B1. | Pending |
| B4 | User browser / staff without publication grants | Attempt the direct All Records route. | Access is denied. | Not started; requires a staff account without grants. | Pending |
| B5 | User browser / staff with the matching module grants | Inspect Capacity Building, Research Development, and Faculty Research & Engagement in the sidebar. | Capacity Building and Research Development retain their Admin submenu structure; Research Engagement Records appears under Faculty Research & Engagement only when the staff account has the required review view grant. | Awaiting user observation. | Pending |

## Failures, decisions, fixes, and retests
- None observed so far.
- User decision: Keep only the staff-authorized Research Engagement Records link under Faculty Research & Engagement. Leave Faculty Research Profiles, Annex D Preparation & History, Collaboration & Mentorship, and Faculty Research Analytics admin-only.

## Assistant-run checks
- `rtk proxy php -l` on the four changed PHP files: passed.
- `rtk proxy php -l includes/staff-shell.php` after the additional sidebar change: passed.
- `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check -- ...`: passed.
- No database changes or application records were made.

## Resume / handoff
- Last user-confirmed state: No browser observation yet.
- Next pending action: User opens the Staff permission editor in the integrated browser and reports whether it shows the refreshed keys and separate download controls (B6).
- Blocked/skipped checks and remaining verification gaps: Add, report navigation, and no-grant boundary checks are pending. Admin regression check is also pending.
- User-performed data changes and cleanup status: None.

## Permission catalog refresh (2026-11-07)
- Scope: Replace Staff publication category grants with `publication_records`, `publication_add`, and `publication_reports`; add `research_engagement_records`; preserve Chapter Review and the independent `research_outputs` grant; migrate existing permission rows idempotently.
- Graphify question and vocabulary: Trace permission catalog, matrix reads/saves, route authorization, archive scopes, and uploads. Queried `rmsPermissionModules`, `getStaffPermissionMatrix`, `saveStaffPermissionCollection`, `hasModulePermission`, and `handleRmsUpload` in `graphify-out/graph.json`.
- Graph result: The graph connects `getStaffPermissionMatrix()` to `seedDefaultStaffPermissions()` and `rmsPermissionModules()`, permission checks to the matrix, and `handleRmsUpload()` to `hasModulePermission()`. The graph predates current archive route files; confirmed all changed relationships against current source. Graph build commit remains `9d996919f74f8c4e73d6fe77804146a9c2c7acb6`; no graph rebuild was run.

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| P1 | Assistant static check | Run `php -l` on all changed PHP files. | No syntax errors. | Passed for `includes/auth.php`, `includes/staff-shell.php`, `includes/archival-record-page.php`, `includes/archival-record-list.php`, `includes/archival-records.php`, `includes/publication-archive-form.php`, `includes/file-uploader.php`, `includes/activity-schedule.php`, `pages/shared/activity-form.php`, `pages/shared/activity-detail.php`, `pages/admin/admin-faculty-engagement.php`, `pages/shared/download-research-document.php`, `pages/staff/staff-dashboard.php`, `pages/admin/admin-research-module.php`, and `pages/admin/admin-users.php`. | Passed |
| P2 | Assistant static check | Check scoped diff whitespace and scan active permission calls for retired publication keys. | No whitespace errors or active permission checks for retired categories. | Scoped `git diff --check` reported no whitespace errors. Remaining `research_review` checks belong to Chapter Review; retired publication strings remain only in Admin compatibility routing, labels, or aggregate SQL aliases. | Passed |
| P3 | Assistant source review | Inspect the new migration, permission mapping, and execution status. | Migration is idempotent, preserves explicit destination edits, leaves new publication downloads disabled, and is not applied without local-target ledger inspection and affected-row backup. | `database/migrations/073_refresh_staff_permissions.sql` inserts missing destinations, copies engagement level/download, removes retired category rows, and was not executed. No database or permission rows were read or changed. | Passed |
| B6 | User browser / Admin > User Management > Staff Permissions | Open the permission editor for a Staff account and inspect the Publication & Dissemination and Research Development rows. | Show All Records, Add New, Reports, Research Engagement Records, and Chapter Review with separate access/download controls; admin-only Faculty Research & Engagement links are absent. | Awaiting user observation. | Pending |

- Database effect: None. Migration application remains pending; before applying, confirm the connection targets local `rms_db`, inspect the migration ledger, and back up affected `staff_permissions` rows.
- Browser save behavior, migrated grants, type limits, download denial, and route boundaries remain pending user-operated verification; no application interaction was automated.
