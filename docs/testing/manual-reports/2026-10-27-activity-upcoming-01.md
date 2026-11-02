# Verification: activity upcoming terminology

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Activity status terminology uses “Upcoming” in faculty, staff, and admin views; existing records and access grants remain intact.
- Environment URL: Local RMS; browser URL not confirmed.
- Roles and disposable records/files: Admin role for pending browser check; no record changes requested.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How do activity status values connect to faculty/staff activity pages, admin navigation, permission keys, and the database? Selected terms: `activity-form.php`, `activity-detail.php`, `activities.php`, `research_activities`, `activity-schedule.php`, `admin-research-module.php`.
- Query/lookup and result: `graphify query "activity-form.php activity-detail.php activities.php research_activities activity-schedule.php admin-research-module.php" --budget 1500`; 109 nodes were returned, output truncated at 43/109 and included stale E2E nodes.
- Relevant source locations and verified relationships: `pages/shared/activities.php` reads and filters `research_activities.status`; `pages/shared/activity-form.php` creates/edits activity status; `pages/shared/activity-detail.php` gates actions from stored status; `includes/activity-schedule.php` and `includes/activity-budget.php` filter eligible statuses; `pages/admin/admin-research-module.php`, `includes/admin-shell.php`, and `includes/auth.php` connect admin menu entries to stable permission/module keys. Current source was checked directly.
- Missing, inferred, stale, or truncated context: Graph result was truncated and some E2E references were stale; direct source checks confirmed these relationships. Legacy admin route keys remain accepted as aliases, while active permission keys use `upcoming`.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant static check | PHP syntax checks for all changed PHP files | No syntax errors | `php -l` passed for 10 changed PHP files | Passed |
| A2 | Assistant static check | Apply migration to local `rms_db` | Existing `incoming` activity changes to `upcoming`; other activity records remain | Migration 040 applied. Pre-migration snapshot had `incoming=1`, `completed=1`; post-migration enum/default are upcoming and counts are `upcoming=1`, `completed=1`. | Passed |
| A3 | Assistant static check | Rename stored activity permission keys | Old staff subpage keys are replaced without reducing grants | No matching old/new keys existed in the local DB; migration 041 was recorded as verified-existing. Legacy URLs normalize to the new keys. | Passed |
| A4 | Admin browser | Open Capacity Building → Upcoming from admin sidebar | Upcoming view opens with an Upcoming heading/status; prior incoming activity is listed there if visible under the current filters | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- No application failures observed. PHP config emitted a session-start warning during CLI-only DB reads; the read-only queries completed successfully.

## Assistant-run checks
- `php scripts/migrate.php status`: migration 040 is applied; pre-existing unrelated migration states remain as before.
- Migration 041 is recorded `verified_existing`; the pre-migration backup query found zero rows for its old/new permission subpage keys.
- `php -l` on `scripts/migrate.php`, `includes/activity-schedule.php`, `includes/activity-budget.php`, `includes/auth.php`, `includes/admin-shell.php`, `pages/admin/admin-research-module.php`, `pages/shared/activity-form.php`, `pages/shared/activity-detail.php`, `pages/shared/activities.php`, and `pages/staff/staff-dashboard.php`: all passed.
- `git diff --check`: completed without whitespace errors in modified files; Git displayed line-ending notices for the already modified working tree.
- Backup saved at `backups/activity_status_before_040_2026-10-27.csv` (ignored local backup; activity IDs and prior statuses only).

## Resume / handoff
- Last user-confirmed state: No browser observation yet.
- Next pending action: As an admin, open the Capacity Building section in the sidebar and select Upcoming. Confirm whether the Upcoming page and label appear and whether any activity list/error is shown.
- Blocked/skipped checks and remaining verification gaps: Browser rendering and role-based navigation need user confirmation; no browser interaction was performed by the assistant.
- User-performed data changes and cleanup status: None.
