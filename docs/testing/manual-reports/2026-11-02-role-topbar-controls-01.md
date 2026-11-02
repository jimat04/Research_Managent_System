# Verification: role topbar controls

- Date/time: 2026-11-02 (Asia/Taipei)
- Assistant: GitHub Copilot
- Target and expected behavior: Admin, student, faculty, and research staff shells share accessible page-navigation search and desktop sidebar collapse; each role searches its own navigable sidebar pages and persists collapse independently.
- Environment URL: http://localhost/rms/
- Roles and disposable records/files: Student, faculty, and research staff; no records or files changed.
- Session state: finished

## Graphify and source context
- Question and selected graph vocabulary: How are `renderAdminShell`, `renderStudentShell`, `renderFacultyShell`, and `renderStaffShell` related to their role navigation?
- Query/lookup and result: `graphify query "renderAdminShell renderStudentShell renderFacultyShell renderStaffShell" --budget 1200`; BFS found the four renderers and their shell files, plus closing functions. The result exceeded the requested budget but included the full 26-node, 34-edge neighborhood.
- Relevant source locations and verified relationships: Graph nodes locate the renderers in `includes/admin-shell.php`, `includes/student-shell.php`, `includes/faculty-shell.php`, and `includes/staff-shell.php`. Current dashboard callers include `pages/student/student-dashboard.php:626`, `pages/faculty/faculty-dashboard.php:54`, and `pages/staff/staff-dashboard.php:700`. Each shell renders its own sidebar navigation; the shared control script uses only those rendered links. Staff permission checks remain in the staff renderer and are unchanged.
- Missing, inferred, stale, or truncated context: The existing graph predates the current working-tree edits and does not include `js/role-shell-controls.js`; no graph rebuild/update was performed. Graph role-shell edges are broad and do not describe the new control behavior, so current source takes precedence.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| STATIC-1 | Assistant / changed PHP shells | Run `php -l` on admin, student, faculty, and staff shell files | All four files parse | `php -l` reported no syntax errors for all four shells | Passed |
| STATIC-2 | Assistant / shared JavaScript | Run JavaScript syntax check and inspect role-specific selectors | Valid syntax; links and controls stay scoped to the current shell | `node --check js\\role-shell-controls.js` and targeted source review passed; search filters placeholders and forms and uses only the current shell's sidebar links | Passed |
| STATIC-3 | Assistant / tracked shell and CSS files | Run `git diff --check` | No whitespace errors | Command exited successfully with no output | Passed |
| BROWSER-1 | Student / authenticated shell | Open the student dashboard | Dashboard loads with sidebar, topbar, collapse button, and page-search field | User confirmed the dashboard loaded and both controls appeared | Passed |
| BROWSER-2 | Student / authenticated shell | Activate the sidebar collapse button once | Sidebar collapses and content area expands without navigating away | User confirmed the sidebar visibly collapsed | Passed |
| BROWSER-3 | Student / authenticated shell | Search student sidebar pages; check keyboard/no-match behavior, mobile layout, and onboarding state | Student-only suggestions; usable keyboard and empty states; mobile fits; onboarding remains unchanged | User reported all browser checks passed | Passed |
| BROWSER-4 | Faculty / authenticated shell | Open faculty shell and use collapse/search at desktop and narrow viewport | Faculty-only suggestions; collapse and responsive layout work | User reported all browser checks passed | Passed |
| BROWSER-5 | Research staff / authenticated shell | Open staff shell and use collapse/search at desktop and narrow viewport | Staff-only suggestions; collapse and responsive layout work | User reported all browser checks passed | Passed |

## Failures, decisions, fixes, and retests
- None observed yet.

## Assistant-run checks
- Graphify lookup completed as recorded above.
- `php -l` passed for `includes/admin-shell.php`, `includes/student-shell.php`, `includes/faculty-shell.php`, and `includes/staff-shell.php`.
- `node --check js\\role-shell-controls.js` passed.
- `git diff --check` passed for the tracked shell and CSS files. The new JavaScript file was checked with `node --check`.
- User reported that all requested browser checks passed across the role shells.

## Resume / handoff
- Last user-confirmed state: User reported all browser checks passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: No browser screenshots were shared; user confirmation is recorded as reported.
- User-performed data changes and cleanup status: None.
