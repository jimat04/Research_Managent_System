# Stage 4C B2 - coordinated backup attempt

2026-10-30, Asia/Taipei. **Coordinated backup passed independent integrity checks. Conditional B2 approval was exercised and DOCX generation is temporarily enabled for controlled disposable testing.**

The user conditionally approved B2 and confirmed a quiet window with no other users editing records/drafts, using disposable records only. The first attempt failed due to the ordering defect below and was preserved. After correcting the comparator, a new backup directory was created; all automated and independent checks passed. The prior checkpoint remains unchanged.

## Results

| Check | Result |
|---|---|
| Authorized operator access | Approved unsandboxed command ran as Windows Jim. Existing private generated-directory ACL grants only SYSTEM Modify, Administrators FullControl and Jim Modify. No ACL, owner, service or policy change |
| Backup implementation | Added CLI-only PHP exporter and PowerShell privacy preflight. No RMS bootstrap, session, application request, SQL DML/DDL or restore. Fresh unique private destination; no earlier backup overwritten/deleted |
| Private source/destination preflight | Wrapper checked source objects for approved principals and reparse points, preserved original descriptors, created a new directory inheriting existing private restrictions. HTTP-denied and Git-ignored EARIST recovery subtree |
| SQL creation | mysqldump completed with exit 0 and empty stdout/stderr logs. SQL is 1,252,107 bytes. Credentials were passed through an exclusively created private option file, not process arguments, and that file was removed after the process |
| Initial validation | First attempt failed at table ordering; the diagnosis and correction are retained below. The retry used a new directory |
| Read-only diagnosis | 59 current database tables and 59 dump definitions; no missing or extra tables. Both EARIST tables and schema_migrations are present. The sorted inventories match; their original ordering differs |
| Correction | SQL collation ordering and PHP sorting differed. Both inventory sides now use explicit PHP string ordering. PHP syntax passed, then the corrected backup completed |
| Generated storage copy / history reconciliation / checksums | Passed on retry. Four generated-storage files copied; one manifest set and both DOCX packages validated. Current history has 0 generated rows, so the existing synthetic set is retained as unreferenced evidence. Thirty-one independent checksum entries matched |
| Activation | Enabled after successful backup and user quiet-window confirmation. Only the `live_generation_enabled` flag and its explanatory comment changed. PHP syntax/config read passed; PDF remains disabled |

Retained incomplete checkpoint:
`storage/earist/recovery/b2-20261030-232336-36dadb8d8faf4a2eb8bf0d5d73ff60a3`

It contains the SQL, source ACL evidence, private dump logs and failure reason. It is protected recovery evidence, not a successful restore point. Do not expose a direct URL or remove the folder. Earlier backups remain untouched.


## Verified fresh checkpoint

`storage/earist/recovery/b2-20261030-233036-c5444acba36141b7a762fd8ca25c71ea`

- SQL dump: 1,252,107 bytes, SHA-256 `6cf72ecb432ac5e0fe934ef3e5751160b58ef51ff9509dd568d3d037390d7cc5`; 59/59 table definitions and per-table INSERT statement counts match the read-only database inventory. Both `earist_document_drafts`, `earist_generated_documents`, and `schema_migrations` are present; the dump includes Migration 052 as applied. Dump completion marker is present; mysqldump exited 0; diagnostics were empty.
- Database snapshot records two draft rows and zero generated-history rows. Generated storage contains four files: one `.htaccess`, one manifest and two QSF DOCX files. Their sizes and SHA-256 values match the source inventory; the primary/companion packages passed the existing DOCX package inspector. The one set has no history row and is labeled unreferenced; it was preserved.
- Independent read-only verification checked all 31 entries in `checksums.json`; SQL table definitions and every table's row count matched; generated files and the manifest matched; issues: zero. SQL and generated-file reads succeeded.
- The new backup tree and final ACL record inherit the approved Jim/Administrators/SYSTEM private ACL. The PowerShell preflight verified ten existing storage objects unchanged and every created backup object against the approved principal set. No permissions were changed.
- Relevant template, binding, renderer, mapping, storage and Migration 052 files were copied under `metadata/`. Existing backups and the earlier failed attempt remain untouched.
- Backup status is **integrity verified; restore not rehearsed**. A recovery must restore SQL and the exact matching file tree together into an explicitly approved isolated database and private storage root, then compare every history snapshot/manifest/path/hash and test role boundaries before pointing an application at it. Never restore over normal `rms_db` as a rehearsal.
- The generation config now has SHA-256 `39dc7700ed4d2f36b5c3c81f5b4750a84b0052d2a105c617d000a85c4c563399`; only the documented comment and feature flag differ from the prior false-gate file. Gate true for the controlled test window. `php -l` and direct config read passed. No migration, template, ACL or PDF change.

## Prepared retry and limits

The initial failed checkpoint and its reason remain preserved; the corrected successful checkpoint is the one paired for this test window. Do not reuse or overwrite either folder.

The script checks current local rms_db schema/ledger, uses read-only before/after table-content and schema inventories, compares SQL definition/row counts, copies the complete generated tree, validates manifest association and DOCX package integrity, compares committed history references, preserves metadata/configuration, and writes hashes and completion evidence. The wrapper verifies source ACLs unchanged and private destination access. Independent read-back of all recorded checksums passed with no issues. No restore rehearsal was possible.

The conditional approval has been exercised. The generation flag is global to eligible accounts; use only the agreed disposable/synthetic records. Run C-G as grouped in the existing checklist. On a critical failure or at the end of testing, return the flag to false, retain all versions/evidence and reconcile in-flight work without automatic destructive rollback.

Restoration procedure remains in the [B1 report](EARIST_STAGE4C_B1_READINESS.md) and [operations procedure](../EARIST_DOCUMENT_OPERATIONS.md): keep SQL, generated files and metadata as one checkpoint; restore only to a separately approved isolated target first; validate references, hashes and access boundaries. No isolated restore target exists and no restore rehearsal was performed. No recovery over normal rms_db is authorized.
