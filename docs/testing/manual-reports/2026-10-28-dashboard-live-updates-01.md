# Verification: dashboard live updates

- Date/time: 2026-10-28 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The shared admin and research staff dashboard refreshes its visible counts and live data every 30 seconds without a page reload.
- Environment URL: Local RMS; configured URL was not inspected.
- Roles and disposable records/files: Admin and research staff; no record changes planned.
- Session state: Finished; the first relative-route attempt was inconclusive and the root-based retest passed.

## Graphify and source context

- Question and selected graph vocabulary: How does the shared staff dashboard relate to its permission checks and role-specific route? Selected terms: `staff`, `dashboard`, `monitoring`, `permission`.
- Query/lookup and result: `graphify query "staff dashboard monitoring permission" --budget 1500` was truncated (41 of 57 nodes). Narrowed with `graphify query "monitoringDashboardTableExists" --budget 1500 --context_filter call`; it returned 12 nodes and showed `staff-dashboard.php` contains the dashboard helpers. The map does not cover the new refresh path.
- Relevant source locations and verified relationships: `pages/admin/admin-dashboard.php` delegates to `pages/staff/staff-dashboard.php`; the shared page requires login and allows only `research_staff` or `admin` near the top. The JSON response is built after the existing query results and module permission filtering; aggregate chart/events are limited to roles that already see them. The client uses text nodes and SVG elements for dynamic content and checks event URLs against the current origin.
- Missing, inferred, stale, or truncated context: Graph results were truncated and stale for the new endpoint and JS. Current source was reviewed directly; no graph rebuild was performed.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static checks | Run PHP and JavaScript syntax checks | Both files parse cleanly | `php -l pages/staff/staff-dashboard.php` reported no syntax errors; `node --check js/dashboard-live-updates.js` exited successfully without output. | Passed |
| S2 | Assistant source review | Inspect JSON response, role/permission gating, DOM updates, polling lifecycle | Read-only response is protected; dynamic content is safely rendered; polling pauses while hidden and retries after failure | Confirmed in the current shared page and client script. | Passed |
| S3 | Assistant whitespace check | Run targeted `git diff --check` for the shared dashboard PHP file | No whitespace errors | `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/staff/staff-dashboard.php` exited successfully without output. | Passed |
| B1 | Admin / dashboard | Open the admin dashboard and observe its initial live-update status | Dashboard loads and shows an update status | User screenshot shows `Updated 9:51 PM` near the dashboard header. | Passed |
| B2 | Admin / dashboard | After B1, wait at least 30 seconds without reloading | Update status advances and changed data is applied in place | User confirmed the `Updated` time advanced without reloading. No individual metric change was reported. | Passed |
| B3 | Research staff / dashboard | After the initial staff view, wait at least 30 seconds without reloading | The update status advances in place | User confirmed the `Updated` time advanced without reloading in the research staff session. Permission filtering is separately confirmed by source review in S2. | Passed |
| B4a | Authenticated session / RMS | Use the normal logout control | Return to the public login page | User confirmed the login page appeared after logout. | Passed |
| B4 | Anonymous / dashboard data route | First attempt: open the dashboard data route while logged out | Existing login protection redirects or denies access; no dashboard JSON is exposed | User saw the RMS 404 page. The instruction used a relative route; source review shows the dashboard exists at the RMS root-level path, so this attempt is inconclusive for authorization. | Failed |
| B4R | Anonymous / dashboard data route | Retest at the root-based route while logged out | Existing login protection redirects or denies access; no dashboard JSON is exposed | User reports the route reloads the login page; no dashboard JSON is shown. | Passed |

## Failures, decisions, fixes, and retests

- The user confirmed the update status advanced without reloading in both admin and research staff sessions; no specific metric change was reported. The first anonymous-route attempt returned the RMS 404 page because the instruction used a relative path. Source review confirmed the dashboard file exists and delegates to the guarded shared page. The root-based retest returned the user to the login page; no application code change was needed for the initial navigation error.

## Assistant-run checks

- No database queries or record changes were performed by the assistant. No browser actions were performed.

## Resume / handoff

- Last user-confirmed state: Logged out; the root-based dashboard data route returned to the login page.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: No pending checks. Metric-value changes were not observed; refresh timing was confirmed in both roles by the update status.
- User-performed data changes and cleanup status: None.
