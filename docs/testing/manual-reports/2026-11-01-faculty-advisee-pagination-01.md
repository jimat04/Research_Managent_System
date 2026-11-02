# Verification: Faculty advisee project pagination

- Date/time: 2026-11-01 Asia/Taipei
- Assistant: Codex (used `rms-workflow` locally because agent invocation was unavailable; also followed the RMS UI specialist and UI reference)
- Target and expected behavior: Show one advisee progress row per research project, identify each row by research title with researcher and program beneath it, and paginate projects in groups of ten.
- Environment URL: http://localhost/rms/pages/faculty/faculty-dashboard.php
- Roles and disposable records/files: Faculty role; existing advising projects only; no record changes.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: How does the faculty dashboard render project progress and its data? Terms: `faculty-dashboard.php`, `faculty-advisee-matrix-table`, `FacultyAdviseeProgressService`.
- Query/lookup and result: `graphify query "faculty-dashboard.php faculty-advisee-matrix-table FacultyAdviseeProgressService" --budget 1800`; 114 nodes found, truncated to 54. Results confirmed faculty dashboard and shell nodes but did not expose the matrix component/service chain.
- Relevant source locations and verified relationships: `pages/faculty/faculty-dashboard.php` passes the requested page into `FacultyDashboardService`; `includes/services/FacultyAdviseeProgressService.php` loads assigned projects and per-project chapter statuses; `includes/faculty-dashboard/components/advisee-matrix.php` renders the matrix; `js/faculty-dashboard.js` adds chapter status tooltips and opens project detail; `css/faculty-shell.css` owns matrix and responsive styling. The existing implementation grouped projects by student, then chose the first project’s status for each chapter cell.
- Missing, inferred, stale, or truncated context: Graphify results were broad/truncated and do not represent the component relationship. Current source was inspected directly.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check / changed PHP | Run `php -l` on changed PHP files | No syntax errors | Passed for `FacultyAdviseeProgressService.php`, `FacultyDashboardService.php`, `faculty-dashboard.php`, and `advisee-matrix.php`. | Passed |
| S2 | Assistant static check / changed files | Run whitespace check on changed files | No whitespace errors | `git diff --check` and trailing-whitespace scan passed for changed files. `node --check js/faculty-dashboard.js` passed. | Passed |
| D1 | Assistant read-only local service check | Confirm project row data and pagination metadata against local `rms_db` | One project per row; page size is ten; name, title, program, and five chapter statuses are present; oversized page requests clamp safely | Project 60 resolves to one project row with title, researcher name, program, and five chapter statuses. Out-of-range page clamps to the last page. No second page exists in the current target. | Passed |
| B1 | User browser / faculty dashboard | Refresh page 1 | Each row shows its research title with student and program; the displayed chapter statuses belong to that project | Awaiting user observation | Pending |
| B2 | User browser / faculty dashboard | Use the pagination control if more than one page is available | Next page shows a different set of up to ten projects; Previous returns to the first set | Blocked: local target has only one assigned project, so a second page is not available. No records were created to simulate it. | Blocked |
| B3 | User browser / faculty dashboard | Resize to a narrow viewport | Project title, student/program, chapter statuses, and pagination remain usable | Pending | Pending |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- Graphify CLI and existing graph were available; the query before and after the change remained broad/truncated. Current source was used to confirm component behavior.
- `php -l` passed for all four changed PHP files; `node --check` passed for the dashboard script; `git diff --check` and trailing-whitespace scan passed.
- Read-only service check against `rms_db` confirmed one current project row, researcher name, program, all five chapter slots, and out-of-range page clamping. There is no second page in the current data.

## Resume / handoff
- Last user-confirmed state: The user authorized the project-title layout and pagination.
- Next pending action: User refreshes the faculty dashboard and confirms the displayed title, researcher, program and chapter statuses.
- Blocked/skipped checks and remaining verification gaps: Browser confirmation and narrow viewport check pending. Page 2 cannot be exercised with the current one-project dataset.
- User-performed data changes and cleanup status: None.
