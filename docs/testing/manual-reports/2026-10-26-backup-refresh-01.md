# Verification: backup refresh

- Date/time: 2026-10-26, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Replace the existing ignored backup artifacts with a current full dump of local `rms_db`, then package the current project source, documentation, dependencies, database dump, and uploaded data for group handoff.
- Environment URL: Not applicable.
- Roles and disposable records/files: No application records or files created.
- Session state: Finished.

## Graphify and source context
- Question and selected graph vocabulary: How database and uploaded project data are represented for backup and handoff. Selected terms: `backup-database.sh`, `admin-backup.php`, `config.php`, and `uploads`.
- Query/lookup and result: `graphify query "backup-database.sh" --budget 1200` returned six nodes but no live dump relationship. `graphify query "uploads backup-database.sh database config.php" --budget 1300` was truncated. `uploads` alone was ambiguous across three schema files; retrying `graphify query "database_schema_rms_db_uploads" --budget 900` remained broad/truncated.
- Relevant source locations and verified relationships: `includes/config.php:55-63` resolves DB settings; `includes/config.php:115-125` opens the selected database; `pages/admin/admin-backup.php:42-75` constructs its dump command. The canonical schema contains the upload tracking table, while physical files are under `uploads/`; filesystem enumeration confirmed 70 files. The one-time refresh separately verified `DB_NAME` and `SELECT DATABASE()` before dumping.
- Missing, inferred, stale, or truncated context: Graphify does not represent this one-time packaging operation and did not provide a useful edge between SQL data and physical uploads. Current source and direct filesystem checks were used; no graph changes were made.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant, read-only PHP CLI | Compare configured DB name with `SELECT DATABASE()` | Both resolve to local `rms_db` | Both returned `rms_db`; no database writes were performed. | Passed |
| S2 | Assistant, `mysqldump` | Create and validate full dump | Successful exit, nonempty file, table definitions, completion marker, checksum | Exit status 0; 431,236 bytes; 50 `CREATE TABLE` statements; completion marker present; SHA-256 `a450a1dd3b291fef63cea8db96d54b56f2f8d72a72df416e9f7906e18154d076`. | Passed |
| S3 | Assistant, filesystem | Replace old backup contents | Only the new dump and protected `.htaccess` remain | `backups/` contains `rms_backup_2026-10-26_03-23-09.sql` and `.htaccess`; prior backup files/directories were removed. | Passed |
| S4 | Assistant, Git | Check the dump remains ignored | Git reports the dump as ignored | `git check-ignore --no-index` returned `backups/rms_backup_2026-10-26_03-23-09.sql`. | Passed |
| S5 | Assistant, isolated restore | Rehearse restoration | Restore succeeds in an isolated target | No isolated database was provided or verified; no restore was attempted. | Skipped |
| B1 | Browser | Application interaction | Not applicable to this filesystem/database snapshot task | No application interaction was needed. | Skipped |
| S6 | Assistant, ZIP inventory | Create and inspect project handoff archive | Current source and docs, dependencies, SQL dump, and uploaded data are present; secrets and local tooling state are absent | 1,250 ZIP entries; 1,079 files; 70 upload files and 167 vendor files; SQL dump and `.env.example` present; `.env`, `.git`, local tool folders, test outputs, and logs absent. | Passed |
| S7 | Assistant, ZIP integrity | Read all archive entries and compare embedded dump | Every entry opens; embedded SQL matches standalone dump; ZIP remains ignored | All 1,079 files decompressed without error. Embedded DB dump SHA-256 matches standalone: `a450a1dd3b291fef63cea8db96d54b56f2f8d72a72df416e9f7906e18154d076`. `git check-ignore` confirms the ZIP is ignored. | Passed |

## Failures, decisions, fixes, and retests
- Initial removal preflight stopped because the current process could not inspect `backups/pre-migration-028`; no old files were removed at that point. After requesting elevated access, the path and nested contents were checked for reparse points, then the authorized replacement completed.
- The first ZIP build stopped before creating an archive because this host's .NET did not provide `Path.GetRelativePath`; path handling was adjusted and the archive was rebuilt. The first embedded-dump hash check used a newer .NET hashing API unavailable on this host; the compatibility retry passed using `SHA256.ComputeHash`.

## Assistant-run checks
- Confirmed configured and connected database names were both `rms_db` before the dump.
- `mysqldump` used a temporary credential file with restricted access; the credential file and helper were removed after the command.
- Final folder inventory, dump size, table-definition count, completion marker, SHA-256, and Git ignore status were checked after replacement.
- The retained `backups/.htaccess` contains `Require all denied`; root `.htaccess` also denies direct access to the `backups/` path and SQL dump extensions.
- Created `backups/rms_project_handoff_2026-10-26.zip` (71,944,547 bytes; SHA-256 `f4b2a8920ee4a65e5b280982e78f068f54282133a099991f6e1533768213e717`). It contains current source/docs, `vendor/`, all 70 files from `uploads/`, and the current SQL dump. It excludes `.env`, `.git`, local tool/cache folders, test outputs, and logs; `.env.example` is included.
- The ZIP's uncompressed file payload is 91,286,924 bytes. The ZIP and SQL dump are both ignored by Git.
- The dump has not been restore-tested. Its checksum and SQL completion marker do not establish restoreability.

## Resume / handoff
- Last user-confirmed state: The user requested a project ZIP containing all required project files and data for groupmates.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: Isolated restore rehearsal was skipped because no verified isolated target was available.
- User-performed data changes and cleanup status: None. Existing backup artifacts were removed after the validated dump was ready; the current database was not changed.
