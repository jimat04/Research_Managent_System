# Verification: activity program department field

- Date/time: 2026-11-08 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Admin activity roster participant form accepts a free-form Program/Department; choosing a linked RMS account fills its saved affiliation, and switching to a guest clears that account value.
- Environment URL: Not confirmed
- Roles and disposable records/files: Admin; no record changes made
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How does the admin activity detail reach the participant form? Selected existing labels: `activity-detail.php`, `activity-form.php`, `admin-research-module.php`.
- Query/lookup and result: `graphify query "activity-detail.php activity-schedule.php admin-research-module.php" --budget 1500` returned 65 nodes and was truncated. Narrowed to `graphify query "activity-detail.php" --budget 1200 --context_filter call`; it returned the activity detail node and related shared activity page nodes, but no dynamic field-builder edge.
- Relevant source locations and verified relationships: `pages/admin/admin-research-module.php:L19-L25` routes activity management to shared activities/form pages; `pages/shared/activity-detail.php:L96-L104` checks the optional schedule/check-in schema; `pages/shared/activity-detail.php:L621-L630` validates and limits the submitted Program/Department to 150 characters when supported; `pages/shared/activity-detail.php:L1579-L1622` renders the participant form; `pages/shared/activity-detail.php:L1945-L1967` prefills/clears the field as the linked account changes. Confirmed against current source. The former option builder pulled both `program` and `department` values from all active users.
- Missing, inferred, stale, or truncated context: Graph results were truncated on the broad query and did not identify the dynamic option builder or admin route chain. Current source was used for those details; graph coverage for this template behavior is incomplete.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant static check | Run PHP syntax check on changed activity detail page | No syntax errors | `php -l pages/shared/activity-detail.php`: no syntax errors | Passed |
| U1 | Admin / activity detail / participant roster form | Open the Program/Department field | It is an editable text input with no profile-derived dropdown choices | Awaiting user browser observation | Pending |
| U2 | Admin / activity detail / participant roster form | Select a linked account, then switch to guest | Account selection fills its saved affiliation; guest selection clears it and allows free entry | Awaiting user browser observation | Pending |

## Failures, decisions, fixes, and retests
- Fixed the field source issue by replacing values harvested from every active user's profile with a free-entry input. Kept linked-account autofill and clear it when switching to an unlinked guest.

## Assistant-run checks
- `php -l pages/shared/activity-detail.php` — passed. No database access or application record changes were made.

## Resume / handoff
- Last user-confirmed state: None for the updated interface.
- Next pending action: User opens the admin activity participant roster form and confirms the field is editable text rather than a dropdown.
- Blocked/skipped checks and remaining verification gaps: Browser checks are pending user interaction in the integrated browser.
- User-performed data changes and cleanup status: None.
