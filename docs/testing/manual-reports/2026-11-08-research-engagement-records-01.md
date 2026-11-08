# Verification: research-engagement-records

- Date/time: 2026-11-08 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Admin and authorized research staff see a live count of faculty engagement records on the shared dashboard; the engagement page continues to fetch and list records.
- Environment URL: http://localhost:8080/rms/
- Roles and disposable records/files: Admin and research staff accounts; no record changes planned.
- Session state: finished

## Graphify and source context
- Question and selected graph vocabulary: How does the shared admin/staff dashboard expose Research Engagement Records? Selected graph terms: `staff-dashboard`, `admin-research-module`, `dashboard`.
- Query/lookup and result: `graphify query "staff-dashboard admin-research-module dashboard" --budget 1500`; found `staff-dashboard.php` and `admin-research-module.php` dashboard/module nodes, but results were truncated at 40/74 nodes.
- Relevant source locations and verified relationships: `pages/admin/admin-dashboard.php:1-3` delegates to `pages/staff/staff-dashboard.php`; that shared page defines the engagement card at line 518 and filters it through the existing role permission matrix. The card had `count => null`; the dashboard's allowed table list omitted `faculty_engagement_records`. `pages/admin/admin-faculty-engagement.php:331-418` contains the record count and paginated record query used by admin and authorized staff; current source confirms the same route selects the role shell and checks staff view permission near lines 1-24.
- Missing, inferred, stale, or truncated context: The Graphify output was truncated and had no visible engagement-specific node in the returned portion. The dashboard/page relationship and query were confirmed against current PHP source. User reported that the browser checks passed; specific counts and record-list observations were not provided.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static / PHP | Run `php -l pages/staff/staff-dashboard.php` | No PHP syntax errors | `php -l` reported no syntax errors; `git diff --check` reported no whitespace errors | Passed |
| B1 | User browser / admin | Open the admin dashboard and read the Research Engagement Records card count | A numeric total is shown | User reported “passed”; specific count not provided | Passed |
| B2 | User browser / research staff with view permission | Open the staff dashboard and read the Research Engagement Records card count | A numeric total is shown | User reported “all passed”; details not provided | Passed |
| B3 | User browser / admin or authorized staff | Open the linked records page | Existing faculty engagement records appear, or an empty state appears when there are no records | User reported “all passed”; details not provided | Passed |

## Failures, decisions, fixes, and retests
- Initial source finding: the shared dashboard omitted `faculty_engagement_records` from its available-table allowlist and hard-coded the engagement card count to `null`, so both admin and staff dashboards could not fetch/display the count.
- Fix: add the engagement table to dashboard availability detection, fetch its total count, and use that value in the shared card. The admin dashboard delegates to this shared page.

## Assistant-run checks
- `php -l pages/staff/staff-dashboard.php`: passed, no syntax errors.
- `git diff --check -- pages/staff/staff-dashboard.php docs/testing/manual-reports/2026-11-08-research-engagement-records-01.md`: passed, no whitespace errors.

## Resume / handoff
- Last user-confirmed state: User reported all remaining browser checks passed; no counts or detailed observations provided.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: User confirmed success but did not provide counts or specific record-list observations.
- User-performed data changes and cleanup status: none.
