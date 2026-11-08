# Staff permissions and dashboard alignment

Date: 2026-11-07 (Asia/Taipei)

## Scope

Align staff research navigation, dashboard cards, and the permission editor; remove the separate Seminars / Workshops and Trainings grants; and correct status-page authorization for activity list, form, and detail routes.

## Graphify review

- Queried the existing Graphify map for staff activity permissions, routes, dashboard, sidebar, and permission editor.
- The map returned 83 nodes and was truncated. Its useful hints included `staff-dashboard.php`, `activity-form.php`, `auth.php`, `getStaffPermissionMatrix()`, and `saveStaffPermissionCollection()`.
- The map did not provide complete activity list/detail route coverage. Current source was checked directly in `pages/shared/activities.php`, `pages/shared/activity-form.php`, `pages/shared/activity-detail.php`, `includes/auth.php`, `includes/staff-shell.php`, `pages/admin/admin-research-module.php`, and `pages/admin/admin-users.php`.
- Repeated the query after route/dashboard edits. It returned 90 nodes and 95 edges, but included unrelated documentation and defense-scheduling nodes; direct source review remained the authoritative route check.

## Assistant checks

| Check | Status | Result |
|---|---|---|
| Source review of permission catalog and staff permission matrix | Passed | Activity permissions now use Add Activity, Add Upcoming Activity, Add Completed Activity, View Activity, Upcoming, Ongoing, and Completed. Deprecated type-specific rows are removed. |
| Source review of route authorization | Passed | List/detail routes use page grants; form creation uses Add grants; form edits and activity actions require Manage on an allowed page. The four activity types use the same grants. |
| PHP syntax (`php -l`), first pass | Failed | Found a missing `endif` in the staff dashboard and an unparenthesized nested ternary in the activity list return URL. Both were introduced during this implementation and have been corrected; rerun the lint before marking syntax passed. |
| PHP syntax (`php -l`), retest | Passed | No syntax errors in `includes/auth.php`, `includes/staff-shell.php`, `pages/admin/admin-research-module.php`, `pages/admin/admin-users.php`, `pages/staff/staff-dashboard.php`, `pages/shared/activities.php`, `pages/shared/activity-form.php`, or `pages/shared/activity-detail.php`. Dashboard and activity routes were linted again after the final PHP edits. |
| Staff browser flow (granted page, denied page, allowed actions) | Pending user observation | The user must perform the application interaction in the integrated browser. |
| Desktop/mobile dashboard, sidebar, and permission editor | Pending user observation | The user must inspect the app at both widths. |

## User observations

Pending.

## Migration verifier follow-up (2026-11-07 Asia/Taipei)

- Added postcondition verifiers for migrations 073 and 075 in `scripts/migrate.php`: 073 checks retired publication keys are absent and users with research-review grants have corresponding engagement-record grants; 075 checks retired activity-type permission keys are absent. The checks are limited to postconditions changed by each migration.
- `php -l scripts/migrate.php` passed. Read-only `php scripts/migrate.php status` reports 073 and 075 as pending. The pre-migration target is local `rms_db`; neither migration was applied, and no database was changed. The verifier postconditions have not been exercised against a migrated state.
- Status recheck (2026-11-07 Asia/Taipei): ran `php scripts/migrate.php status` again against the confirmed local `rms_db`. Both `073_refresh_staff_permissions.sql` and `075_staff_activity_status_permissions.sql` still report `pending`. Graphify query terms `migration`, `verify_migration`, `print_status`, and `schema_migrations` returned truncated migration-runner hints; the map is stale (`built_at_commit` `9d996919`, current HEAD `d128a3b2`). Confirmed current runner source at `scripts/migrate.php` lines 393, 803, 1372, and 1485–1491; status reads the tracking table and the existing ledger is present. No migration was applied.
- Staff browser permission checks remain pending as listed above.

## Limitations

Migration `075_staff_activity_status_permissions.sql` removes old type-specific rows. It is now applied and verified on local `rms_db`; those legacy permission rows were removed there. Staff browser checks remain pending.

## Authorized migration execution (2026-11-07 Asia/Taipei)

- User authorized running migrations 073 and 075 against local `rms_db`.
- Before execution, `php scripts/backup-archival-records.php` created an integrity-verified backup at `backups/archive-20261107-134318-70f13c` (78 tables and 31 supporting files; restore not rehearsed).
- `php scripts/migrate.php apply 073_refresh_staff_permissions.sql` returned `APPLIED`; immediate status reported `applied / executed`.
- `php scripts/migrate.php apply 075_staff_activity_status_permissions.sql` returned `APPLIED`; final read-only status reported both 073 and 075 as `applied / executed`.
- The runner's postcondition verifiers passed for both migrations. No production database was targeted. Staff browser checks remain pending.
