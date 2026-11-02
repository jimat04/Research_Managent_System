# Verification: Admin dashboard academic-year card

- Date/time: 2026-11-01 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Show the academic-year card on the admin dashboard, center the “Academic Year” label and year value, and hide its semester line and calendar icon; preserve the research staff view. The Research Outputs metric should count active publications plus registered outputs permitted for the role, matching the outputs register.
- Environment URL: `http://localhost/rms/` (user-provided route; browser confirmation is pending).
- Roles and disposable records/files: Admin and research staff views; no records changed.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Which dashboard supplies the admin Research Outputs tile and which page defines its destination totals? Matched graph nodes: `admin-dashboard.php`, `staff-dashboard.php`, and `implementation-outputs.php` (selected terms: `research_outputs`, `admin-research-module.php`, `implementation-outputs.php`).
- Query/lookup and result: `graphify` query CLI was unavailable. Read-only direct lookup in `graphify-out/graph.json` found nodes for `admin-research-module.php` and `implementation-outputs.php`; adjacent extracted links only showed helpers contained by the outputs page, with no edge connecting the dashboard tile to the destination count.
- Relevant source locations and verified relationships: `pages/admin/admin-dashboard.php:1` includes `pages/staff/staff-dashboard.php`. `pages/admin/admin-research-module.php` maps `research_outputs` to `pages/shared/implementation-outputs.php?view=outputs`. The destination counts all active publication records (`deactivated_at IS NULL`) plus permitted registered copyright records. The dashboard previously used `$stats['published']`, which counts only archived or published records. It now uses `$stats['publication_total']`, and the restricted-scope registration count now uses the destination's active-record and registration-status rules.
- Missing, inferred, stale, or truncated context: Graphify did not describe the dashboard-to-destination count relationship. The relationship and count rules were confirmed against current PHP source. No live database value was queried.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Static / PHP | Run `php -l pages/staff/staff-dashboard.php` and `php -l pages/admin/admin-dashboard.php` | No PHP syntax errors | Both commands reported no syntax errors | Passed |
| S2 | Static / source comparison | Compare dashboard count sources and permission filters with the outputs register | Active publications and registered outputs use equivalent source and status filters | Dashboard now uses `publication_total`; restricted registration branch excludes deactivated records and accepts external or legacy registered status | Passed |
| B1 | Browser / admin / Admin dashboard | Open the admin dashboard and inspect its header | Academic year and label are centered; semester and calendar icon are absent | Awaiting user observation | Pending |
| B2 | Browser / research staff / Staff dashboard | Inspect the same header | Academic year and semester remain | Not yet checked | Pending |
| B3 | Browser / admin / Admin dashboard | Read the Research Outputs metric | Value represents active publications plus registered output records | Awaiting user observation | Pending |
| B4 | Browser / admin / Research Outputs | Open the linked outputs register and compare its summary | Register summary supports the dashboard total | Not yet checked | Pending |

## Failures, decisions, fixes, and retests
- The initial implementation hid the entire card. After the user's clarification, restored the card and removed only the semester display.
- Source comparison found the tile counted only archived or published publications while the linked register counted every active publication. Switched the tile to the active-publication total and aligned the restricted registered-output filter with the register.

## Assistant-run checks
- `php -l pages/staff/staff-dashboard.php` and `php -l pages/admin/admin-dashboard.php` — passed.
- Inline CSS brace count in `pages/staff/staff-dashboard.php` — balanced (65 opening and 65 closing braces).
- No database query or data change was performed for this diagnosis.
- The existing worktree has extensive unrelated changes, including pre-existing changes to the shared staff dashboard. Those were preserved; the status and diff are therefore not a clean snapshot of only this request.
- No application data or database changes were made.

## Resume / handoff
- Last user-confirmed state: None.
- Next pending action: User opens the Admin dashboard and reports the Research Outputs metric value for check B3.
- Blocked/skipped checks and remaining verification gaps: Browser checks B1–B4 remain pending; live count validation and responsive presentation have not been checked.
- User-performed data changes and cleanup status: None.
