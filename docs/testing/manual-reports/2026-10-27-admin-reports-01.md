# Verification: Admin reports role filters and monthly graphs

- Date/time: 2026-10-27, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Replace the monthly new-project chart with monthly Activities, Publications, Copyrights, and Faculty graphs; filter report totals and module summaries by Student or Faculty; retain faculty-only statistics.
- Environment URL: http://localhost/rms/pages/admin/admin-reports.php (configured fallback; not confirmed live)
- Roles and disposable records/files: Administrator; no records changed
- Session state: Finished (report-choice spacing follow-up)

## Graphify and source context
- Question and selected graph vocabulary: Trace admin reports, their report-page selection, and the datasets used by the role filters and monthly graphs. Selected terms: `admin-reports.php`, `renderAdminShell()`, `research_projects`, `research_activities`, `activity_participants`, `publications`, `copyright_applications`.
- Query/lookup and result: `graphify` command was unavailable. Read the existing `graphify-out/graph.json` directly. The page node points to `pages/admin/admin-reports.php:L1`; there are no adjacent edges for that page. Schema nodes point to `database/schema/rms_db.sql` (graph locations L400, L606, L623, L635, and L660), but the graph's schema locations are stale relative to current source.
- Relevant source locations and verified relationships: Current `pages/admin/admin-reports.php` uses `research_projects.created_by`, `activity_participants.user_id`, `publications.researcher_id`/`researcher_category`, and `copyright_applications.applicant_id`/`applicant_category`; confirmed against current `database/schema/rms_db.sql`. Report sections and print selection are rendered and controlled within `pages/admin/admin-reports.php`.
- Missing, inferred, stale, or truncated context: Graphify has no report-page dependency edges and its schema line locations are stale. Current source and schema were used as the authority. No database query was run.
- Follow-up graph lookup: Question: hide Faculty reporting under Student role. Terms: `admin-reports.php`, `renderAdminShell()`, `faculty`. The existing page node still has no adjacent dependency edges. Current source gates the Faculty selector at L606 and the Faculty report card at L729 on `$reportRole !== 'student'`.
- Program-assignment follow-up lookup: Question: group faculty by their admin-assigned programs. Terms: `admin-reports.php`, `faculty_program_assignments`, `programs`, `admin-users.php`. The graph includes the `admin-reports.php`, `admin-users.php`, and `programs` nodes but no assignment-table node or useful report-to-assignment edge; an initial broad PowerShell matcher had an escaping error, then exact node IDs were queried directly. Current source confirms `admin-users.php` saves assignments to `faculty_program_assignments` and reads program codes through `programs`; current schema and migration 042 define the join table. The report now joins these tables when both are available, with the legacy `users.program` value as fallback.
- Visual redesign follow-up lookup: Question: trace the report shell and report-page styling before reorganizing the controls. Terms: `admin-reports.php`, `renderAdminShell()`, `admin-print`, `report selector`, `admin-reports.css`. `graphify query "admin-reports.php" --budget 1500` returned 57 nodes but truncated after unrelated admin-user and E2E nodes. Narrow follow-up `graphify explain "admin-reports.php"` reports degree 0. Current source confirms `renderAdminShell()` outputs the `.admin-page-content` shell, the report page links its stylesheet, and report selector/print controls are local to this page. Relationship coverage is missing from the graph.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / PHP syntax | `php -l pages/admin/admin-reports.php` | No syntax errors | No syntax errors detected | Passed |
| S2 | Assistant / diff whitespace | `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/admin/admin-reports.php` | No whitespace errors | No diff-check diagnostics; Git emitted a warning that its global ignore config was unreadable in the sandbox | Passed |
| S3 | Assistant / CSS whitespace | PowerShell `Select-String` for trailing spaces or tabs in `css/admin-reports.css` | No trailing whitespace | No trailing whitespace found | Passed |
| B1 | Administrator / Reports & Analytics | Open the admin reports page | Role filter and Research status report render | User confirmed both are visible | Passed |
| B2 | Administrator / Reports & Analytics | Select Activities, Publications, Copyrights, and Faculty reports | Each selected report shows its monthly graph and existing statistics | User confirmed all four reports appear as expected | Passed |
| B3 | Administrator / Reports & Analytics | Select Students in the role filter and apply | Student role is selected, chosen reports remain selected, and Faculty statistics remain faculty-only | User confirmed all three outcomes | Passed |
| B4 | Administrator / Reports & Analytics | Select Faculty in the role filter and apply | Faculty role is selected, project/module data uses the faculty scope, and Faculty statistics remain faculty-only | User confirmed the expected result | Passed |
| B5 | Administrator / Reports & Analytics | Click Print selected reports and inspect print preview | The selected report pages and graphs appear in print preview | User confirmed expected reports and graphs appear | Passed |
| B6 | Administrator / Reports & Analytics | View selected reports at a narrow viewport | Graphs remain readable and can scroll horizontally without clipping the page | User confirmed the expected layout | Passed |
| S4 | Assistant / PHP syntax after Student filter visibility change | `php -l pages/admin/admin-reports.php` | No syntax errors | No syntax errors detected | Passed |
| S5 | Assistant / diff whitespace after Student filter visibility change | `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/admin/admin-reports.php` | No whitespace errors | No diff-check diagnostics | Passed |
| B7 | Administrator / Reports & Analytics | Select Students and apply the filter | Faculty selector option and report card are absent; other selected reports remain available | User confirmed Faculty is hidden and other reports remain available | Passed |
| S6 | Assistant / PHP syntax and whitespace after assigned-program grouping change | `php -l` and `git diff --check` on `pages/admin/admin-reports.php` | No PHP syntax or whitespace errors | PHP syntax passed; diff check emitted no diagnostics | Passed |
| B8 | Administrator / Reports & Analytics | Set the role filter to Faculty and apply it | The Faculty report option reappears | User confirmed the Faculty option is visible | Passed |
| B9 | Administrator / Reports & Analytics | Select the Faculty report and inspect Faculty by program | Assigned program codes appear for assigned faculty instead of grouping them as “Program not recorded” | User confirmed assigned programs appear | Passed |
| S7 | Assistant / PHP syntax after report layout change | `php -l pages/admin/admin-reports.php` | No syntax errors | No syntax errors detected | Passed |
| S8 | Assistant / PHP and CSS whitespace after report layout change | `git diff --check` for PHP and a PowerShell trailing-whitespace scan for CSS | No whitespace errors | No PHP diff-check diagnostics; no trailing CSS whitespace | Passed |
| B10 | Administrator / Reports & Analytics | Refresh at desktop width | Intro, researcher filter, portfolio totals, and report builder appear as distinct grouped sections | User confirmed the page is clearly grouped | Passed |
| B11 | Administrator / Reports & Analytics | View at narrow width | Filter and report controls stack cleanly; no horizontal page overflow | User confirmed the layout fits without horizontal overflow | Passed |
| B12 | Administrator / Reports & Analytics | Print selected report | Report selection and filter controls remain hidden in print; selected report content remains | Awaiting user observation | Pending |
| B13 | Administrator / Reports & Analytics | Refresh at desktop width and inspect the Report builder choices | Choices fill the available row without the prior right-side gap | User confirmed all five choices fill the row | Passed |
| B15 | Administrator / Reports & Analytics | Resize to a narrow viewport | Report choices remain usable and the page has no horizontal overflow | User confirmed they fit and work | Passed |
| B14 | Administrator / Reports & Analytics | Open Faculty research and engagement and inspect Faculty-linked records by month; hover/focus data points | A six-month area chart with gradient fill appears; each point exposes its month and record count; verified engagements contribute when the engagement table is available | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- No failures observed so far.

## Assistant-run checks
- `php -l pages/admin/admin-reports.php`: passed.
- Follow-up after Student visibility change: `php -l pages/admin/admin-reports.php` passed; `git diff --check` emitted no diagnostics.
- Follow-up after assigned-program grouping change: `php -l pages/admin/admin-reports.php` passed; `git diff --check` emitted no diagnostics.
- Follow-up after layout changes: `php -l pages/admin/admin-reports.php` passed; `git diff --check` emitted no PHP diagnostics; the CSS trailing-whitespace scan found none.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/admin/admin-reports.php`: no diff-check diagnostics; Git warned that `C:\Users\jimwe/.config/git/ignore` could not be read.
- PowerShell trailing-whitespace scan of `css/admin-reports.css`: no trailing whitespace found.
- Source review confirmed project drafts remain included in project totals/status counts; role-bound SQL values use prepared statements; activity role counts use `EXISTS` to count each activity once; monthly series use `created_at` and cover the current month plus five prior months.
- No automated application actions or database changes were performed.
- Graphify lookup for the report selector used `graphify query "report selector choice" --budget 1500`; it returned `admin-reports.php` (L1) but was truncated and primarily surfaced unrelated report-generation nodes. Current source review confirmed the selector at `pages/admin/admin-reports.php` (L719-L725), with layout owned by `css/admin-reports.css`. No selector-specific relationship was established; Graphify coverage is limited for this CSS behavior.
- Faculty trend follow-up Graphify lookup used `graphify query "adminReportsRenderMonthlyAreaChart facultyMonthlyCounts" --budget 1100`; no matching nodes were found. Direct source review confirmed the faculty trend data queries and render call in `pages/admin/admin-reports.php` (L90-L126, L253-L309, L899-L904); Graphify has no useful coverage for these symbols.
- `php -l pages/admin/admin-reports.php`: no syntax errors after the faculty trend update. `git diff --check` for the page and this report emitted no diagnostics.
- Follow-up for the right-side gap: review of `css/admin-reports.css` found the report selector constrained to grid column 1 while print occupied column 2. Changed the selector to span the full grid width; its responsive `auto-fit` columns can now distribute the choices across the row. Graphify terms `admin-reports.php`, `report selector`, and `admin-reports.css` matched the page node only; `graphify explain "admin-reports.php"` reports degree 0, so the CSS relationship was confirmed from current source.

## Resume / handoff
- Last user-confirmed state: At desktop width, all five report choices use the full row; at narrow width they fit and remain usable.
- Next pending action: None for this spacing fix.
- Blocked/skipped checks and remaining verification gaps: The new area-chart presentation, selector layout, prior visual hierarchy, narrow layout, and print layout await browser confirmation. Live database totals were not independently queried.
- User-performed data changes and cleanup status: None.
