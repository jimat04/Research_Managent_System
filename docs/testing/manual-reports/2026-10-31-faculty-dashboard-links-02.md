# Verification: faculty dashboard content links

- Date: 2026-10-31 (Asia/Taipei)
- Assistant: Codex
- Target: faculty dashboard content links reach supported pages and their intended sections; follow-up separates analytics into its own page.
- Environment URL: http://localhost/rms/pages/faculty/faculty-dashboard.php
- Role: faculty; no records created or changed.
- Session state: source corrections and static checks complete; browser confirmation pending.

## Graphify and source context

- Question: which content links still have unsupported parameters or missing destination anchors?
- Selected actual vocabulary: faculty, dashboard, publication.
- Read-only lookup: `rtk proxy graphify query "faculty dashboard publication" --budget 1500` returned 27 nodes and 22 edges. Graphify exceeded the requested budget to return the complete result; no omitted edges.
- Graph locations include `pages/faculty/faculty-dashboard.php:L1`, `includes/faculty-shell.php:L1`, and `pages/shared/publications.php:L1`. Shell function locations are stale; current dashboard services/components lack graph coverage.
- Current source confirms dashboard -> overview -> component -> service-generated queue/profile URLs. Faculty profile accepts the research tab, but its four shortcut targets lacked IDs. Analytics lacked its linked ID. Project detail lacked chapter IDs and the linked title/final-review IDs. Engagement history ignores the edit parameter and provides no evidence-edit action.
- Source fallback: inspected current profile/engagement/research-detail pages and service URLs directly. Research-detail faculty access remains assignment-gated; engagement history remains scoped to the signed-in faculty. Shared publication listing and creation already accept faculty.
- Existing report `2026-10-31-faculty-dashboard-links-01.md` was read for context; historical passes were not reused as current test results.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / PHP | Lint five changed PHP files | No syntax errors | All five php -l commands reported no syntax errors | Passed |
| S2 | Assistant / source | Compare dashboard/service URLs with destination files, parameters, and IDs | All affected links have supported destinations | Read-only Python check: seven route files exist; all affected fragment targets match source; unsupported edit link absent | Passed |
| S3 | Assistant / diff | Review task edits and whitespace | Changes limited to navigation targets and matching labels | Narrow task hunks reviewed; scoped git diff --check exited 0 | Passed |
| B1 | User / faculty / dashboard | Click Research Analytics | Dedicated faculty-analytics.php page opens with Research Analytics active in sidebar | No user observation; updated by follow-up request | Pending |
| B2 | User / faculty / dashboard | Follow profile edit shortcuts individually | Research tab opens at the intended field when profile setup is available | No user observation | Pending |
| B3 | User / faculty / dashboard queue | Follow chapter/title/manuscript links for assigned projects | Correct project section opens | No user observation; matching assignments required | Pending |
| B4 | User / faculty / returned engagement | Follow View Feedback | Own engagement history opens at the returned record and shows feedback | No user observation; own returned record required | Pending |
| B5 | User / faculty / research detail | Open an authorized disposable project outside faculty assignments | Existing access-denied behavior | No user observation; suitable disposable project required | Pending |

## Failures, decisions, fixes, and retests

- User requested redirect-link fixes; source findings above are within that authorization. No new browser failures reported.
- Added missing analytics, profile-field, chapter-row, and engagement-row IDs. Title history and manuscript links now use existing research-trail and final-manuscript-heading IDs.
- Replaced unsupported engagement edit navigation with View Feedback to the existing owner-scoped history record. Queue labels describe viewing; formal reviews remain admin/authorized-staff actions.
- Reused SITE_URL, existing pages, query selectors and authentication/ownership checks. No database, handler, permission, schema, dependency or CSS changes.
- Preserved unrelated worktree changes; tracked research-detail/profile/engagement edits predate this task too. Only the narrow hunks described here belong to this session.

## Assistant-run checks

- `rtk proxy powershell -NoProfile -Command` invoking `C:\xampp\php\php.exe -l` separately for `includes/faculty-dashboard/overview.php`, `pages/faculty/faculty-profile.php`, `pages/shared/research-detail.php`, `includes/services/FacultyReviewQueueService.php`, and `pages/faculty/faculty-engagement.php`: all five passed.
- `rtk proxy python -c` read-only source check: extracted seven unique route files from the dashboard and queue/profile services; all exist. Asserted profile field IDs, analytics target ID, chapter row ID pattern, existing research-trail/final-manuscript-heading IDs, engagement-row ID pattern, and queue use of history anchor without unsupported edit parameter: passed.
- `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/faculty/faculty-profile.php pages/shared/research-detail.php pages/faculty/faculty-engagement.php includes/faculty-dashboard/overview.php includes/services/FacultyReviewQueueService.php docs/testing/manual-reports/2026-10-31-faculty-dashboard-links-02.md`: exited 0 with no whitespace errors. Untracked files reviewed via task patch and direct source checks.
- Source review does not establish runtime or browser success. No application requests, login/session scripts, database connections, or record changes were performed.

## Resume / handoff

- Last user-confirmed state: no browser state confirmed.
- Next action: faculty on the dashboard, click Research Analytics and confirm whether the dedicated analytics page opens with Research Analytics active in the sidebar.
- Browser navigation and access-boundary checks remain pending; user performs application interactions per the manual guide.
- User-performed data changes: none reported. No cleanup needed.

## Follow-up: standalone analytics page

- User instruction: separate analytics from the dashboard.
- Hypothesis before edits: overview rendering, getOverviewData, shared dashboard JavaScript, and two navigation destinations currently couple analytics to the dashboard. Cheapest check: inspect these owners and their callers; this confirmed all four couplings.
- Reused the locally read rms-page-builder specialist and mandatory references. No specialist invocation was available; implementation performed locally.
- Graph vocabulary inspection found faculty and dashboard; analytics was not an actual label token. Repeated `rtk proxy graphify query "faculty dashboard" --budget 1500`: 18 nodes, with dashboard/shell file nodes at L1 and an inferred shell-close/logout edge. Existing graph has no service/component or new analytics-page coverage, and shell function positions remain stale. No graph writes or rebuilds.
- New `pages/faculty/faculty-analytics.php` requires the faculty role, obtains the authenticated user, calls public getAnalyticsData with that user ID, renders the existing faculty shell and analytics template, and loads its own script. Existing prepared, faculty-scoped queries are unchanged.
- Removed analytics data loading from getOverviewData and chart markup from the overview. Dashboard and sidebar links, including the alternate hero component fallback, use the standalone route. Legacy dashboard view=analytics requests redirect after authentication and before dashboard data fetching/output.
- Moved the existing analytics partial to includes/faculty-analytics.php and chart code to js/faculty-analytics.js. Removed dashboard chart/collapse initialization and storage preference handling. The standalone page shows its content immediately. Existing Chart.js URL/version reused; no dependencies installed. Data tables stay open until the corresponding chart initializes, and remain usable if JavaScript/CDN loading fails. Existing empty datasets render explicit messages.
- Database/schema/data/permissions changes: none. Existing faculty scoping and shell navigation conventions reused. Unrelated worktree changes and earlier link fixes preserved.

| ID | Method | Expected | Evidence | Status |
|---|---|---|---|---|
| A1 | Assistant / PHP syntax | All seven changed/new PHP files parse | php -l passed for dashboard, analytics page, shell, overview, hero-stats, service, analytics partial | Passed |
| A2 | Assistant / JavaScript syntax | Dashboard and analytics scripts parse | node --check passed for both files | Passed |
| A3 | Assistant / source and diff | Page separation, links, compatibility redirect, script ownership, clean patch | Read-only Python assertions passed for separation, role gate, user-ID scoping, three navigation targets, redirect before data load, dedicated chart script and data fallbacks. Scoped git diff --check passed; line-ending notice only | Passed |
| A4 | User / faculty / analytics | Page renders charts or usable data tables, correct active navigation, refresh remains on analytics | No user observation | Pending |
| A5 | User / faculty / legacy analytics URL | Old dashboard view=analytics URL opens standalone analytics | No user observation | Pending |
| A6 | User / faculty / dashboard | Dashboard contains overview sections and no embedded analytics | No user observation | Pending |
| A7 | User / unauthenticated / analytics direct route | Existing login requirement enforced | No user observation | Pending |

- Static commands: `rtk proxy C:\xampp\php\php.exe -l pages/faculty/faculty-analytics.php`; `rtk proxy powershell -NoProfile -Command` for the other six PHP lints and `node --check` on both scripts. All reported no syntax errors or exited 0; no application execution or database connection performed.
- Final review: dedicated page and script, moved partial, service return shape, dashboard redirect and overview, alternate hero URL, and sidebar route inspected. Source scan found no remaining old analytics component, getAnalyticsPreview/analyticsPreview caller, or generated view=analytics link in pages/faculty, includes, or js (rg exit 1 means no matches). Narrow diff whitespace check passed; new/untracked file source assertions and whitespace inspection passed. Analytics JS/PHP lint repeated after indentation cleanup and passed.
- Next browser step remains B1; browser appearance, refresh behavior, empty-state rendering and access-boundary results are pending user observations.
