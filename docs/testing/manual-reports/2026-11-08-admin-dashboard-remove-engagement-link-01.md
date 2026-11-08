# Verification: admin-dashboard-remove-engagement-link

- Date/time: 2026-11-08 (Asia/Taipei)
- Assistant: Copilot
- Target and expected behavior: The admin dashboard does not show the Research Engagement Records link; authorized research staff retain the link on their dashboard.
- Environment URL: http://localhost:8080/rms/
- Roles and disposable records/files: Admin and research staff accounts; no record changes planned.
- Session state: complete

## Graphify and source context
- Question and selected graph vocabulary: How is the admin dashboard's shared module-link list rendered? Selected graph terms: `staff-dashboard`, `admin-research-module`, `dashboard`.
- Query/lookup and result: Initially ran `graphify query "staff-dashboard admin-research-module dashboard" --budget 1500` (truncated at 40/74 nodes), then repeated after the change with `graphify query "staff-dashboard" --budget 1500` (truncated at 42/49 nodes). Both included the `staff-dashboard.php` node.
- Relevant source locations and verified relationships: `pages/admin/admin-dashboard.php:3` requires `pages/staff/staff-dashboard.php`. The shared dashboard conditionally adds the engagement link for non-admin users at `pages/staff/staff-dashboard.php:517-526`, applies page permissions at lines 560-562, and renders dashboard links at lines 2060-2061. Current source confirms the admin and research-staff roles use this shared dashboard.
- Missing, inferred, stale, or truncated context: Graphify traversals were truncated, so complete relationships were not established. The relevant route and render path were confirmed in current source; user browser checks confirmed the admin link is absent and the staff link remains visible.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static / PHP | Run `php -l pages/staff/staff-dashboard.php` | No PHP syntax errors | `php -l` reported no syntax errors | Passed |
| S2 | Assistant static / diff | Run `git diff --check -- pages/staff/staff-dashboard.php` | No whitespace errors | `git diff --check` reported no whitespace errors | Passed |
| B1 | User browser / admin | Refresh the admin dashboard | Research Engagement Records is absent from Research Development | User confirmed “Absent as expected” | Passed |
| B2 | User browser / research staff with view permission | Open the staff dashboard | Research Engagement Records remains visible | User confirmed “Visible as expected” | Passed |

## Failures, decisions, fixes, and retests
- Change: Build the engagement link into the shared Research Development card only for research staff, preserving staff-permission filtering and the page itself.

## Assistant-run checks
- `php -l pages/staff/staff-dashboard.php`: passed, no syntax errors.
- `git diff --check -- pages/staff/staff-dashboard.php`: passed, no whitespace errors.

## Resume / handoff
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None.
- User-performed data changes and cleanup status: none.
