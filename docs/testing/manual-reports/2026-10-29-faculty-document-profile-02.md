# Verification: Faculty document profile preparation

- Date/time: 2026-10-29 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Add optional faculty profile fields and a configurable default institutional affiliation without changing registration, authentication, student profiles, or research workflows.
- Environment URL: Local RMS; configured and connected database confirmed as `rms_db`.
- Roles and disposable records/files: No faculty profile row was edited. Browser checks require an existing authorized disposable faculty account; no account credentials or profile values were requested.
- Session state: Implementation and assistant-run checks complete; user-operated checks pending.

## Graphify and source context

- Question and selected graph vocabulary: How does the shared profile connect to user data, faculty shell, and settings? Selected existing labels `profile`, `users`, `settings`, `faculty`.
- Query/lookup and result: `graphify query "profile users settings faculty" --budget 1500` returned 10 nodes. It showed `profile.php` and `settings.php` containment relationships and faculty-shell relationships, but no useful profile-to-users or settings-store edge. Current source was checked directly.
- Relevant source locations and confirmed relationships: `pages/shared/profile.php` lines 46–77 (defensive column detection and authenticated `$user_id` profile read), 99–192 (CSRF-checked prepared update), 300–316 (profile value preparation), and 382–562 (faculty-only readouts and inputs); `includes/system-settings.php` lines 3–25, 116–176 (defaults, retrieval and validation); `pages/admin/admin-settings.php` lines 160–202 and 405–437 (CSRF-protected save through the existing system-settings helper and configuration form); `scripts/migrate.php` lines 537 onward (registered migration verification); `database/schema/rms_db.sql` users table; Migration 049.
- Missing, inferred, stale, or truncated context: The graph did not expose database/settings dependencies for this flow. These were verified against current code and the schema. The graph was not rebuilt or modified.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| DB-01 | Assistant, local MySQL | Confirm configured DB, connected DB, and ledger before migration | Both DB names are `rms_db`; Migration 048 applied and 049 pending | Confirmed exact target; 048 ledger row was applied; 049 was pending before execution. User counts recorded: student 6, faculty 2, research_staff 2, admin 1. | Passed |
| DB-02 | Assistant, local filesystem/database | Back up before schema change | Non-empty backup exists before 049 execution | `backups/rms_db_pre_049_20261029_155158.sql`, 607,653 bytes. Backup used the configured database credentials through a temporary protected client file; credentials were not printed. | Passed |
| DB-03 | Assistant, migration runner | Apply explicit Migration 049 | Only the requested migration applies and runner verifies it | `php scripts/migrate.php apply 049_faculty_document_profile_fields.sql` returned `APPLIED 049_faculty_document_profile_fields.sql`. Ledger now records `applied`. | Passed |
| DB-04 | Assistant, local MySQL | Verify columns and record preservation | Three nullable columns exist; existing user counts and rank data are preserved | `middle_name varchar(80) YES`, `designation varchar(150) YES`, `appointment_status varchar(80) YES`; account counts still 6/2/2/1; faculty rank distribution remains Instructor: 2; all new columns remain NULL after migration, and the additive migration did not update existing user records. | Passed |
| PHP-01 | Assistant, PHP CLI | Syntax-check changed PHP files | No syntax errors | `php -l` passed for `pages/shared/profile.php`, `includes/system-settings.php`, `pages/admin/admin-settings.php`, and `scripts/migrate.php`. | Passed |
| SET-01 | Assistant, PHP CLI | Read institutional-affiliation setting through existing helper | Default is available from the existing settings mechanism | `rmsSetting('institutional_affiliation')` returned `Eulogio “Amang” Rodriguez Institute of Science and Technology (EARIST)`. Persisting a change through the admin UI remains pending. | Passed (default read) |
| APP-01 | User-operated browser, faculty / Shared Profile | Save and reload optional faculty fields and College Professor using an authorized disposable faculty test account | Faculty-only fields display, values persist after reload, rank saves; account role/status remain unchanged | Not exercised by assistant; waiting for user observation. | Pending |
| APP-02 | User-operated browser, admin / System Settings | Save the displayed default affiliation and reload settings | The existing settings form persists and reads back the configured affiliation | Not exercised by assistant; follow after APP-01. | Pending |
| APP-03 | User-operated browser, registration/login | Normal registration and login smoke check | Existing registration and login behavior remains available | No registration/authentication code was changed in this implementation; runtime browser confirmation remains pending. | Pending |

## Failures, decisions, fixes, and retests

- No implementation or migration failure occurred. An initial read-only database audit one-liner had shell quoting errors; it was replaced with a temporary non-mutating PHP audit script, and the target/ledger checks completed before backup or migration.
- Existing unrelated ledger entries include `010_demo_accounts_resync.sql` (`needs-verifier`), checksum mismatches for 012 and 014, and pending/reconciliation statuses for older migrations. The requested 049 was applied alone by explicit filename; those unrelated entries were not changed.
- Existing local worktree changes predate this task. The implementation was scoped to its profile, settings, migration, schema, verifier, and report files; unrelated work was preserved.

## Assistant-run checks

- Read-only migration ledger and account-count inspection before execution.
- Credential-safe backup of the confirmed local `rms_db`.
- Explicit Migration 049 execution and post-migration type/nullability/ledger verification.
- Post-migration account-count and faculty-rank comparison against the pre-migration snapshot.
- PHP syntax checks listed above.
- No user profile row or application setting was edited by the assistant.

## Resume / handoff

- Last user-confirmed state: User approved the implementation and local `rms_db` migration. No browser observation yet.
- Next pending action: Ask the user to perform APP-01 in the integrated browser using an authorized disposable faculty test account, then record the observation and continue to APP-02 and APP-03.
- Blocked/skipped checks and remaining verification gaps: Faculty profile persistence/College Professor save, admin affiliation persistence, and runtime registration/login checks require normal user-operated browser interactions. If no disposable faculty test account is available, do not mutate a personal account; mark dependent profile checks blocked and continue independent checks.
- User-performed data changes and cleanup status: None reported.
