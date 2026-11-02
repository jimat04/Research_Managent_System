# Verification: multi-day activities

- Date/time: 2026-10-25 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: one activity record with manually selected scheduled dates, per-date start/end time, audience, participants, attendance, defaults/overrides, and authorized schedule editing and notifications; legacy one-day records remain viewable.
- Environment URL: Browser testing for the schedule workflow is pending; the user had earlier confirmed the separate recipient-filter UI in the ongoing workflow report.
- Roles and disposable records/files: The user had one authorized test recipient selected in an unsaved activity form, then paused before submission to request multi-day scheduling. No activity record or invitation was created.
- Session state: Migration 034 was applied by the user (reported, not independently verified). Per-date role/course/department filters have been restored. User clarified that an audience must combine multiple roles with individually selected recipients; implementing persistent Custom audience support will require an additive migration 035. Browser confirmation is pending.

## Graphify and source context

- Question and selected graph vocabulary: How do the existing activity form/detail pages connect to notifications, authorization, and migration tracking? Selected graph terms present in the map: `activity-detail.php`, `activity-form.php`, `createNotification()`, and `schema_migrations`.
- Query/lookup and result: Read-only queries against `graphify-out/graph.json`. CLI BFS traversals were broad/truncated; the migration-ledger query was ambiguous. Direct JSON node/adjacency lookup confirmed the existing form/detail nodes, `createNotification()` at `includes/auth.php:L141`, and the ledger node at `database/migrations/016_create_migration_tracking.sql:L9`. The graph has no nodes/edges for the newly added schedule helper or migration 034.
- Relevant source locations and verified relationships: Current code confirms the form calls schedule validation/save (`pages/shared/activity-form.php`), the detail route exposes `Edit Schedule` and checks submitted schedule/participant ownership (`pages/shared/activity-detail.php:L147-L164`, `L589`), notifications use the existing helper, and `scripts/migrate.php:L412-L436` verifies migration 034.
- Missing, inferred, stale, or truncated context: Graphify predates this feature and does not cover its new helper/migration. CLI traversals were truncated/ambiguous; the direct lookup was limited to relevant existing nodes. Current source, not inferred graph edges, was used for implementation checks.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | `php -l` on changed PHP | Lint `includes/activity-schedule.php`, the three shared activity pages, and `scripts/migrate.php` | No PHP syntax errors | All five files reported “No syntax errors detected.” | Passed |
| A2 | Database-free PHP validation assertions | Exercise non-consecutive manual date selection, per-date specific/role audiences, one-day input, single-to-multi refresh, and invalid dates, recipients, times, window, and checkbox state | Valid data accepted; invalid data rejected; only selected dates are returned | All assertions passed after the correction recorded below. No DB connection or SQL was used. | Passed |
| A3 | Static acceptance assertions | Check one-day compatibility, multi-day validation, per-date recipient persistence, schedule/participant owner predicates, all five activity types, notification state, migration verifier, and migration/schema table parity | Every source assertion passes | All assertions passed. | Passed |
| A4 | `git diff --check` on feature paths | Check whitespace | No whitespace errors | Exit code 0; Git emitted only working-copy LF-to-CRLF conversion warnings for existing Windows checkout paths. | Passed |
| A5 | Detail-page integration assertions | Check per-date schedule display and ensure legacy activity-wide participant/attendance actions are hidden when schedule tables are available, while retaining pre-migration fallback | Per-date actions remain; legacy actions appear only without migration tables | All four focused assertions passed after review. | Passed |
| A6 | Focused recipient picker checks | Verify per-date role/course/department filters preserve recipient POST values and selected users | Both one-day and multi-day pickers retain their recipient POST paths; each picker filters independently and hiding never disables/unchecks users | PHP lint, JavaScript syntax, diff check, and parent source review passed | Passed |
| A7 | Custom-audience implementation checks | Verify a per-date audience can combine multiple selected roles and specific accounts, deduplicate recipients, and reload persisted selections for editing | Role selections and specific users remain available on edit; combined recipient IDs are unique | Pending implementation | Pending |
| U1 | User-operated browser, administrator activity form/details | Create and edit a disposable one-day and multi-day activity; inspect persistence, attendance, notices, and unauthorized access | Confirm all UI, persistence, and authorization behavior in the application | Not attempted; browser interaction was prohibited for this implementation task. | Pending |
| U2 | Authorized database migration verification | Apply and inspect migration 034 on an approved disposable database | Verify schema/backfill and then confirm application behavior | User reports migration 034 is applied; assistant did not connect to or inspect a database. | Pending independent verification |
| U3 | User-operated browser, admin activity form | Choose Specific users on one or more date cards, filter by role and course/department, and confirm choices stay selected | Each date picker filters independently and retains selections | Filters restored and statically checked; browser confirmation pending | Pending |
| U4 | User-operated browser, admin activity form/detail | Select multiple roles plus individual recipients for one date, save after required migration 035, and reopen schedule | Combined audience and exact selected recipients persist; notifications go once per recipient/date | Pending implementation, migration 035, and browser confirmation | Pending |

## Failures, decisions, fixes, and retests

- The first database-free validation pass exposed a single-to-multi-day refresh edge case: the selected start-date row contained only its selection marker, so its single-day details were not carried forward. The helper now merges those values, and the form preserves them on refresh. The rerun passed.
- That initial piped PHP check also used `STDERR`, which is unavailable in this stdin invocation; its failure reporter raised its own fatal error. The harness was corrected to use `php://stderr`, and the validation assertions passed.
- An early source assertion expected a more specific legacy-query string than the implementation used. It was replaced with semantic checks for the legacy date/time render path; the final static assertions passed.
- Legacy activities have no recorded date-level audience or RMS invitation-delivery history. Migration 034 therefore maps each to a specific audience based on its known linked participants and marks those historical rows as not yet notified. This avoids assuming a broad “All users” audience or sending a cancellation to someone whose prior notification cannot be established. On a first schedule save, those still-selected participants receive the feature’s invitation.
- The user reports migration 034 has been applied. The assistant has not connected to or inspected a database. Recipient role/course/department filters are restored within each per-date Specific users picker. No schedule-form interaction, record save, or notification has been performed in this turn.
- The user requested a combined Custom audience so an administrator can select multiple roles and also select specific accounts for a date. Because migration 034 is already applied, support will be added through additive migration 035; no migration has been applied by the assistant.

## Assistant-run checks

- Focused PHP lint: all five changed PHP files passed `php -l`.
- Inline PHP assertions: exercised schedule validation without a database; passed after the single-to-multi refresh fix.
- Inline Python source assertions: confirmed legacy one-day handling, window/date/time validation, per-date recipient resolution, schedule-owner checks, activity-type options, migration-runner recognition, and matching schedule-table DDL; passed.
- `git diff --check`: passed with only Git line-ending conversion warnings.
- Final integration review hid legacy activity-wide participant and attendance controls when the date-schedule tables are available; per-date participant/attendance controls remain on each scheduled-date card. The legacy controls remain available when the migration is not installed.
- No SQL was run in this task. The user reports applying migration 034. The restored recipient filters have not yet been browser-tested. An authorized test recipient was selected in the still-unsaved activity form earlier, but it was not submitted and no invitation was sent.

## Resume / handoff

- Last user-confirmed state: The user reports migration 034 has been applied, requested recipient filters be restored, and confirmed they need a Custom audience combining multiple roles with specific users. The previously selected test recipient was never submitted; no activity record or notification was created.
- Next pending action: Apply migration 035 in an authorized environment before testing saved Custom audiences. Then verify role/course/department filters, combined recipient uniqueness and persistence, create/edit/display, date attendance, notifications, legacy compatibility, and authorization in the browser.
- Blocked/skipped checks and remaining verification gaps: Browser behavior and database backfill/execution remain pending. Static source checks do not establish live MySQL behavior, notification delivery, or visual accessibility.
- User-performed data changes and cleanup status: None.
