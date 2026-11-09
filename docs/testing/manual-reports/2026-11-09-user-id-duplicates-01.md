# Verification: user ID duplicate registration

- Date/time: 2026-11-09 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Public and administrator user-account create/edit paths reject duplicate nonblank student/employee IDs; blank optional IDs remain absent; database uniqueness prevents concurrent duplicates.
- Environment URL: not applicable to assistant-run checks
- Roles and disposable records/files: none used
- Session state: finished

## Graphify and source context
- Question and selected graph vocabulary: Which registration handlers write `users.student_id`? Selected terms: `login`, `users`, `admin` (matched to `login.php`, `users`, and `admin-users.php`).
- Query/lookup and result: Read-only lookup of matching nodes and adjacent links in `graphify-out/graph.json`. `public_login` contains `public_login_updateregistrationfields`; `scripts_migrate` contains/calls `scripts_migrate_verify_migration`. These extracted links support the page and migration-runner locations, not the ID-write relationship itself.
- Relevant source locations and verified relationships: Current source confirms `public/login.php` handles registration and inserts `users.student_id`; `pages/admin/admin-users.php` creates/updates the same field; `scripts/migrate.php` dispatches migration verification. The graph hints were checked against these files.
- Missing, inferred, stale, or truncated context: The graph has no confirmed edge for the registration handler's ID validation or `users.student_id` index. Its snapshot points to old line locations for `verify_migration()` and may not include uncommitted changes. Source is authoritative. Graphify CLI was not used because querying can write cache files, which already contain unrelated worktree changes. A focused PowerShell traversal attempt failed due command quoting and was replaced by direct JSON node/edge inspection.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static review | Trace public/admin account writes and schema migration | Duplicate IDs rejected in every affected write path; DB uniqueness covers races | Source confirms checks in public registration and admin create/edit; unique migration/schema reviewed | Passed |
| S2 | Assistant command | `rtk proxy php -l public/login.php`; same for `pages/admin/admin-users.php` and `scripts/migrate.php` | No syntax errors | All three reported “No syntax errors detected” | Passed |
| S3 | Assistant migration runner / `rms_db` | Apply migration `077_unique_user_student_ids.sql` | Unique index applied and migration recorded | Runner reported `APPLIED`; post-check reports `applied/executed`, unique index present, no old index, zero duplicate nonblank IDs, zero blank IDs | Passed |
| B1 | User browser / public registration | Submit a new account using an existing ID and a distinct email | Duplicate ID is rejected; no account is created | User confirmed the form displayed “Student/Employee ID already registered.” Source review confirms the handler exits before the insert. | Passed |
| B2 | User browser / public registration | Submit with an unused ID, then submit a second account with that ID | First succeeds; second is rejected | Not exercised; positive-path registration would create an account and send verification email, outside the focused duplicate-ID check | Skipped |

## Failures, decisions, fixes, and retests
- Initial source finding: public registration checked email but did not check `student_id`; the schema had a non-unique index.

## Assistant-run checks
- Assistant ran the three `php -l` commands above; all passed.
- Assistant ran `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check` for tracked changes; no whitespace errors were reported. A trailing-whitespace scan of the new migration and report returned no matches.
- Before migration, read-only preflight confirmed the target was `rms_db`, migration 077 was not recorded, there were zero duplicate nonblank IDs, and three blank IDs would be normalized.
- Backed up `user_id` and `student_id` for all 14 users to `backups/user-id-077-20261109/users_student_ids.csv` before applying the migration. Verified nonzero size (228 bytes), SHA-256 `4aa0550e88b9a5abc81967c9b6ca5104e121872f24a1f80cad0015c049857513`, and a restricted ACL for Jim, the Codex sandbox account, Administrators, and SYSTEM. The backup remains in the Git-ignored `backups/` directory; no restore rehearsal was performed.
- Ran `rtk proxy php -d sys_temp_dir=C:\xampp\htdocs\rms\backups\user-id-077-20261109 scripts/migrate.php apply 077_unique_user_student_ids.sql`; runner reported `APPLIED 077_unique_user_student_ids.sql`.
- Post-migration read-only verification confirmed the migration ledger is `applied/executed`, `uq_users_student_id` is unique, the old nonunique index is absent, duplicate nonblank ID values are zero, and blank IDs are zero.

## Resume / handoff
- Last user-confirmed state: public registration displayed “Student/Employee ID already registered” for a duplicate-ID attempt.
- Next pending action: none for the focused duplicate-ID check.
- Blocked/skipped checks and remaining verification gaps: Valid-ID registration was skipped because it would create an account and send verification email. Backup restore was not rehearsed.
- User-performed data changes and cleanup status: Migration 077 changed three blank `student_id` values to `NULL` and added the unique index; backup retained.
