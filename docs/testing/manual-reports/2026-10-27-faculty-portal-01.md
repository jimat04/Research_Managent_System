# Verification: faculty portal

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Faculty navigation and page organization, with a useful faculty-specific dashboard, clear next steps, advising status, outputs, and working section filters.
- Environment URL: Local RMS; exact URL not recorded.
- Roles and disposable records/files: Faculty role; no records created or modified by assistant.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How does the faculty dashboard connect to its shell and data? Selected graph terms: `faculty-dashboard.php`, `faculty-shell.php`.
- Query/lookup and result: `graphify query "faculty-dashboard.php" --budget 1500`; BFS returned 61 nodes, of which 17 were cut off. A multi-term query was also tried and was truncated. Both results included stale E2E nodes.
- Relevant source locations and verified relationships: Graph identifies `renderFacultyShell()` in `includes/faculty-shell.php:28` and `pages/faculty/faculty-dashboard.php:1`. Current source confirms the dashboard loads the faculty shell and prepares project, publication, profile, engagement, and adviser queries using the current faculty user ID.
- Missing, inferred, stale, or truncated context: Graph results were broad, truncated, and include stale E2E nodes; current source and schema were used to confirm the relationships. No graph rebuild was run. University reference pages reviewed: Arkansas Faculty Information System, Missouri myVITA, Georgetown Faculty Activity Reporting, and UNC Charlotte Faculty Activity Reporting.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant / PHP syntax | Run `php -l` on faculty shell, dashboard, profile, and engagement pages. | All modified PHP files parse. | All four commands reported no syntax errors. | Passed |
| A2 | Assistant / diff whitespace | Run `git diff --check` on the five modified faculty files. | No whitespace errors. | No errors; Git printed a line-ending normalization warning for the dashboard file. | Passed |
| A3 | Faculty / Dashboard (prior version) | Open Dashboard from the faculty sidebar. | Dashboard shows account-linked counts and recent records, or clear empty states, without sample metrics. | Superseded by the follow-up dashboard enhancement before the user exercised it. | Skipped |
| A4 | Faculty / Engagement | Open the Seminars & Training sidebar item. | The selected item is active, the form defaults to seminar/training, and history is filtered to that category. | Not started; perform after dashboard observation if continuing the prior navigation check. | Pending |
| A5 | Assistant / PHP syntax | Run `php -l` on the four faculty PHP files. | All modified PHP files parse. | All four commands reported no syntax errors. | Passed |
| A6 | Assistant / CSS and diff | Check faculty stylesheet brace balance and run focused `git diff --check`. | Balanced CSS braces and no whitespace errors. | CSS braces balanced 332/332; focused diff check reported no errors (Git emitted a line-ending warning). | Passed |
| A7 | Faculty / Dashboard (enhanced) | Open Dashboard from the faculty sidebar and review its data panels. | It shows linked project counts and statuses, returned items needing action, profile status, advised projects, recent publications, and engagement; empty states appear where data is absent. No fabricated values or unauthorized global event list appears. | Awaiting user observation. | Pending |

## Failures, decisions, fixes, and retests
- A previous PHP syntax check caught a mismatched quote in the new project count query at line 78. Corrected the quote; rerunning `php -l` on all four faculty PHP files passed. No application failure was observed.

## Assistant-run checks
- Faculty system references reviewed: [University of Arkansas FIS](https://its.uark.edu/administrative-services/faculty-information-systems/), [University of Missouri myVITA](https://provost.missouri.edu/faculty-affairs/myvita/), [Georgetown Faculty Activity Reporting](https://far.georgetown.edu/), and [UNC Charlotte Faculty Activity Reporting](https://provost.charlotte.edu/faculty-activity-reporting/). They describe faculty activity histories, research/teaching/service records, annual review material, and report access. RMS dashboard scope remains limited to the data currently available in RMS.
- Source review confirmed the previous fixed dashboard figures/sample rows are gone; new lists use current-user-scoped project, publication, advising, profile, and engagement queries.
- No database queries or application interactions were executed by the assistant.

## Resume / handoff
- Last user-confirmed state: None yet.
- Next pending action: User opens the enhanced faculty Dashboard and reports whether the listed account-linked panels and empty states appear.
- Blocked/skipped checks and remaining verification gaps: Browser behavior and role boundaries still need user observation; no live application session was inspected.
- User-performed data changes and cleanup status: None.
