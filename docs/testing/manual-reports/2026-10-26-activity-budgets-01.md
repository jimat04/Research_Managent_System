# Verification: activity budgets and participation

- Date/time: 2026-10-26, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Incoming and Conducted activity budget, expense, attendance, contribution-response, and explicitly confirmed budget notice flows.
- Environment URL: Not used; browser verification remains pending user-operated checks.
- Roles and disposable records/files: No application records created.
- Session state: Schema migrations applied to the confirmed local `rms_db`; browser checks remain pending.

## Graphify and source context
- Question and selected graph vocabulary: Trace the shared activity form, schedule save, participant records, and in-app notifications. Selected terms: `activity`, `schedule`, `participant`, `notification`.
- Query/lookup and result: Repeated `graphify query activity schedule participant notification --budget 1500` after implementation. It returned the same 13 page/shell nodes. A focused lookup for `rmsActivityScheduleSave activity_schedule_participants createNotification` found no matching graph nodes.
- Relevant source locations and verified relationships: Current source confirms `pages/shared/activity-form.php` calls `rmsActivityScheduleSave()`; `includes/activity-schedule.php:477` owns schedule saves; `includes/activity-budget.php:349` saves budgets and revision snapshots; `includes/activity-budget.php:524` creates confirmed budget notices; `includes/activity-budget.php:741` validates participant contribution responses; `pages/shared/activity-detail.php:148` routes participant responses. `includes/auth.php` defines existing in-app notifications. Source wins where graph coverage is missing.
- Missing, inferred, stale, or truncated context: The map has no matching schedule helper node; the feature relationships were confirmed directly from current source. No graph update or rebuild was performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l` on changed PHP files | No syntax errors | Final run: `includes/activity-budget.php`, `pages/shared/activity-form.php`, `pages/shared/activity-detail.php`, `includes/activity-schedule.php`, and `scripts/migrate.php` all reported no syntax errors. | Passed |
| S2 | Assistant DB migration | Confirm configured and connected database; create backup; apply migrations through runner | Apply feature schema only to local `rms_db` with recoverable pre-change data | Configured and connected database were both `rms_db`. Backup created at `backups/rms_db_before_activity_budgets_20261026_004840.sql` (413,600 bytes; SHA-256 checked). Existing schedules and all 5 legacy participant rows were present. The first 034 apply attempt stopped at duplicate `is_multi_day`; no DDL from that attempt succeeded. Added additive repairs 037 (missing `is_notified`) and 038 (activity type/status enums), applied both, reconciled 034 as verified-existing, then applied 036. | Passed |
| B1 | Admin / Add Conducted Activity | Enter a multi-day conducted activity with actual attendees and actual organization/participant costs | Saves without sending invitations; detail shows attendees and date-specific actual expenses | Not attempted; schema is available; user browser interaction required | Pending |
| B2 | Admin / Add Incoming Activity | Add optional contribution costs and enable willingness responses | Invitees can respond willing, unwilling, or need more information; responses do not record payments | Not attempted; user browser interaction required | Pending |
| B3 | Admin / Edit Incoming Activity | Add a mandatory fee and try contribution willingness | Mandatory fee is disclosed and no optional willingness prompt is available | Not attempted; user browser interaction required | Pending |
| B4 | Admin / Activity detail | Confirm a budget notice to selected recipients, then inspect history | Notice preview is explicit; per-recipient results are recorded; retry affects failed deliveries only | Not attempted; user browser interaction required | Pending |
| B5 | Admin / Activity detail | Submit an expense referencing another activity's schedule | Server rejects the cross-activity schedule reference | Not attempted; user browser interaction required | Pending |

## Failures, decisions, fixes, and retests
- Migration 034's initial attempt failed on a duplicate column because most of its schema already existed. The runner recorded the attempt as failed; inspection showed the missing 034 requirements were `activity_schedule_participants.is_notified` and activity type/status enum expansions. Additive migrations 037 and 038 completed those pieces. The runner then reconciled 034 using its complete schema verifier without rerunning its SQL, and migration 036 applied successfully. No activity records were created or altered by the feature migration.

## Assistant-run checks
- `graphify query activity schedule participant notification --budget 1500` — returned activity form/detail context; focused lookup for the schedule-save helper found no matching graph nodes.
- `php scripts/migrate.php status` confirms 034 (verified-existing), 035 (verified-existing), 036 (executed), 037 (executed), and 038 (executed) are applied. Unrelated migration ledger states were left untouched.
- Pre-change local database backup completed and was SHA-256 checked; restore was not rehearsed.
- `php scripts/migrate.php sync` stopped at an existing unrelated checksum mismatch for migration 012; that command synchronized no migrations. Migration 034 was reconciled directly after its verifier passed.
- `php -l` on each changed PHP file — all passed.
- `git diff --check` on the feature files — no whitespace errors; Git reported only its configured LF-to-CRLF normalization warnings for existing working-copy files.
- Database schema migration is applied to local `rms_db`. The backup has not been restore-tested.

## Resume / handoff
- Last user-confirmed state: The user requested implementation; no browser state or records were supplied.
- Next pending action: User-operated browser verification of the Incoming and Conducted activity flows.
- Blocked/skipped checks and remaining verification gaps: Conducted and Incoming browser flows, notice delivery, persistence, invalid schedule ownership, and response authorization remain unverified.
- User-performed data changes and cleanup status: None.
