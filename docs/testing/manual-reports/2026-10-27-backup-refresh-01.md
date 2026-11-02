# Verification: backup refresh

- Date/time: 2026-10-27 08:43, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Replace the dated contents of `backups/` with a current full dump of local `rms_db` and a project ZIP containing current project files and handoff data.
- Environment URL: Not applicable.
- Roles and disposable records/files: No application records/files created; existing user and research data were exported for the requested group handoff.
- Session state: Finished.

## Graphify and source context
- Question and selected graph vocabulary: How the project backup path relates to database configuration and physical uploads. Selected existing terms: `backup-database.sh`, `admin-backup.php`, `config.php`, `uploads`.
- Query/lookup and result: `graphify query "backup-database.sh admin-backup.php config.php uploads" --budget 1500` returned 82 nodes and was truncated to 45. The relevant nodes were `backup-database.sh`, `admin-backup.php`, `config.php`, and `uploads`.
- Relevant source locations and verified relationships: `includes/config.php` resolves DB settings and connects to the configured database; `pages/admin/admin-backup.php` invokes `mysqldump`; physical attachments are stored under `uploads/`. Current sources and filesystem contents were checked directly. The archive includes the current migrations, vendor dependencies, physical uploads, and database dump.
- Missing, inferred, stale, or truncated context: Graphify did not fully cover this packaging flow. The query was truncated; no graph changes were made. Current source and direct checks were used.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant, read-only PHP CLI | Confirm configured and connected database | Both resolve to local `rms_db` | `configured=rms_db`, `connected=rms_db`; 51 tables and a migration ledger were present. A session-directory warning appeared during the first metadata check, but the read-only query completed; subsequent CLI work used the temp session directory. | Passed |
| S2 | Assistant, `mysqldump` | Create a full database dump | Successful exit, nonempty output, table definitions, completion marker, checksum | Exit 0; 444,781 bytes; 51 `CREATE TABLE` statements; completion marker present; SHA-256 `1462d51d7a2f2835b159d181c322b425693ede8b42134d4f16bcf71685e28539`. Temporary credential file was ACL-restricted and removed. | Passed |
| S3 | Assistant, filesystem | Replace prior backup artifacts | Only current dump, current ZIP, and protected `.htaccess` remain | `backups/` contains `rms_backup_2026-10-27.sql`, `rms_project_handoff_2026-10-27.zip`, and `.htaccess`; prior dated dump/ZIP and migration CSV snapshots were removed after replacement artifacts passed initial checks. | Passed |
| S4 | Assistant, ZIP inventory and decompression | Check archive contents and integrity | Current project, uploaded files, dependencies, current dump present; credentials/test outputs absent; all entries open | 1,114 files decompressed; 72 uploads and 167 vendor files; `.env.example`, migration 042, and embedded dump present; `.env` and `test-results/` absent. Embedded dump SHA-256 matches standalone dump. ZIP SHA-256 `80d4c47d76be252aa7f49b63b326c17e2248ded4ce51c50540a21b94aadebc36`. | Passed |
| S5 | Assistant, Git | Confirm generated artifacts remain ignored | Dump and ZIP are ignored | `git check-ignore --no-index` returned both artifact paths. Git emitted a warning that the user-level ignore file was inaccessible; the repository ignore rule still identified both artifacts. | Passed |
| S6 | Assistant, isolated restore | Rehearse restoration | Restore succeeds in an isolated target | No verified isolated database was provided; no restore was attempted. | Skipped |
| B1 | Browser | Application interaction | Not applicable to filesystem/database snapshot | No application interaction was needed. | Skipped |

## Failures, decisions, fixes, and retests
- The first read-only metadata command had a PHP quoting parse error; it was corrected before querying the database.
- The initial final ZIP verification command could not resolve `System.IO.Compression.ZipFile` until the companion assembly was loaded. The corrected check decompressed every archive entry successfully.
- No application data was modified. The old files were removed only after the new dump and ZIP were created and their initial integrity checks passed.

## Assistant-run checks
- The project ZIP excludes `.env`, `.git`, IDE/tool caches, `graphify-out/`, `test-results/`, logs, and unrelated old backup files; it includes `.env.example`, current source/docs, `vendor/`, all 72 files in `uploads/`, and the new database dump.
- The backup folder keeps `.htaccess` with `Require all denied`; root Apache rules also deny direct access to the backup path and SQL dump extensions.
- The dump and ZIP are ignored by Git. No credentials were printed or included in the archive.
- A successful dump and full ZIP decompression do not prove restorability. No isolated restore was performed.

## Resume / handoff
- Last user-confirmed state: User requested a refreshed `backups/` folder with the project and data for groupmates.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: Restore rehearsal is skipped because no verified isolated database was available.
- User-performed data changes and cleanup status: None. Existing dated files in `backups/` were replaced after successful artifact checks.
