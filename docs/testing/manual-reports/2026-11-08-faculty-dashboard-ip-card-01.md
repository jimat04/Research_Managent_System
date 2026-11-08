# Verification: Faculty dashboard registered IP card

- Date/time: 2026-11-08, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Faculty Dashboard displays a secondary stat card for registered IP outputs, counted using the same faculty contributor and registration rules as Faculty Profile; the card opens the Registered IP section. Card order follows the faculty sidebar's related modules: Active Research Records, Registered IP Outputs, Publication Records, Upcoming Activities, Advising Projects, Verified Engagements.
- Environment URL: `http://localhost:8080/rms/pages/faculty/faculty-dashboard.php`
- Roles and disposable records/files: Faculty account; no record changes planned.
- Session state: active; browser confirmation pending.

## Graphify and source context
- Question and selected graph vocabulary: How does the faculty dashboard page render data and role navigation? Selected terms: `faculty-dashboard.php`, `FacultyDashboardService`, `overview.php`.
- Query/lookup and result: `rtk graphify query "FacultyDashboardService faculty-dashboard.php overview.php" --budget 1500` returned 33 nodes and 29 edges (complete answer exceeded budget); narrowed `rtk graphify query "faculty-dashboard.php" --budget 1500` returned 25 nodes and 21 edges. Graph results include the faculty dashboard page and shell but no dashboard service or overview template relationships.
- Relevant source locations and verified relationships: `includes/faculty-shell.php` orders sidebar sections as Research Portfolio, IP Applications, Publications, Activities, and Advising & Engagement after Dashboard. `pages/faculty/faculty-dashboard.php` requires the dashboard service, fetches overview data, sets URLs, and includes `includes/faculty-dashboard/overview.php`. Current source confirms the stat list is rendered by that template and the service supplies `$data['stats']`. Registered output count rules match the prepared query in `pages/faculty/faculty-profile.php`.
- Missing, inferred, stale, or truncated context: Graphify coverage is incomplete and unrelated edges exceed the requested budget. No unverified graph edges were used; current source was confirmed directly.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | PHP syntax checks for changed PHP files | Run `php -l` on dashboard service, overview template, and dashboard page | No syntax errors | All three commands returned `No syntax errors detected` | Passed |
| S2 | `rtk git diff --check` for changed files | Check patch whitespace | No errors | Command returned no output | Passed |
| U1 | Browser / faculty / Dashboard | Inspect the dashboard card order and open Registered IP Outputs | Order matches the related sidebar modules; the IP card uses secondary styling, displays the faculty registered IP count, and opens Faculty Profile's Registered IP section | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- No failures reported.

## Assistant-run checks
- `rtk php -l includes/services/FacultyDashboardService.php` — passed.
- `rtk php -l includes/faculty-dashboard/overview.php` — passed.
- `rtk php -l pages/faculty/faculty-dashboard.php` — passed.
- `rtk git diff --check -- includes/services/FacultyDashboardService.php includes/faculty-dashboard/overview.php pages/faculty/faculty-dashboard.php css/faculty-shell.css` — passed.
- No database or application records were changed.

## Resume / handoff
- Last user confirmation: user confirmed the target is a new IP box on the faculty dashboard.
- Next pending action: User checks the ordered cards and Registered IP link in the browser.
- Blocked/skipped checks and remaining gaps: Browser rendering and link behavior are pending.
- User-performed data changes and cleanup status: none.
