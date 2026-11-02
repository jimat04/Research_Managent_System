# Verification: EARIST generation — Stage 4A

- Date: 2026-10-30, Asia/Taipei.
- Assistant: Codex; local RMS database specialist definition followed; no delegated specialist invocation.
- Target: local `rms_db`; Stage 4A preparation followed by explicitly approved execution of Migration 052 only.
- Environment URL: http://localhost/rms/
- Session state: Stage 4A verification and explicitly approved verifier-only reconciliation complete. Both new tables remain empty; 052 ledger status is now `applied`, method `verified_existing`. Original failure is preserved below. No migration SQL rerun, DDL, rollback, restore, PDF activation or Stage 4B implementation during reconciliation.
- Browser operations and Word inspection belong to the user. No credentials or private records requested.

## Graphify and source context

- Question: which migration helpers and authenticated diagnostics can safely support Phase 4A?
- Actual vocabulary: `migration`, `getcurrentuser`; ZIP/Annex labels absent.
- `graphify query 'migration' --budget 1500` returned 14 nodes, without truncation, principally historical 003/010 documentation rather than current storage verification.
- Current source confirms `scripts/migrate.php` invokes `ensure_tracking_table()` before its status branch. Do not use that command as a read-only preflight. Use a separate no-bootstrap, read-only connection.
- Current source confirms Admin Settings already provides protected read-only runtime diagnostics. Extend that existing page with a read-only approved-template probe; no public test endpoint, session fabrication or direct application requests.
- Graph coverage of Phase 3 and new Phase 4 helpers is missing. No graph maintenance performed. Inferred edges are not verification evidence.
- Focused direct lookup of `scripts_migrate_verify_migration` confirmed EXTRACTED callers `execute_one`, `print_status`, and `sync_existing`. Graph location L286 is stale; current source confirms the registered 052 branch in `verify_migration`. Newly added schema/probe helpers have no graph coverage.
- Authorized execution context: `graphify query 'backup migration' --budget 1500` returned 17 nodes without truncation, including `scripts/backup-database.sh:L1`. Source confirmed its hardcoded connection settings; backup instead used configured credentials in a child-process environment, never printed or placed in command arguments. No graph rebuild/update. The current runner's `execute_one` catches verification failure and records a failed ledger row; its existing same-checksum reconciliation path does not rerun SQL once the full verifier succeeds.
- Reconciliation reused the unchanged graph/source findings above. Direct source review confirmed current `earistDocumentSchemaCompare` performed text comparison and the runner's reconciliation metadata conventions. The new comparison helper has no graph node; current source and live information_schema remain authoritative. No graph write/rebuild or new architecture assumptions.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A01 | Assistant / read-only database query | Inspect target, relevant ledger and proposed table names | Local rms_db; 049–051 applied; 052 absent; two new tables absent | `check-earist-prerequisites.php --database` confirms rms_db, MariaDB 10.4.32, 049/050/051 applied, highest numbered ledger entry 051, both new tables absent | Passed |
| A02 | Assistant / CLI prerequisites | Instantiate ZipArchive and read approved template/XML without writing | ZIP, XML capabilities and unchanged template | PHP 8.2.12; ZipArchive available; Libzip 1.7.1; read-only package open and DOM XML parse passed; template hash unchanged | Passed |
| A03 | User / Apache Admin Settings | Observe protected ZIP/DOM package probe | Ready, ZipArchive available, approved template read/XML parsed unchanged | User pasted: Ready; runtime apache2handler; ZipArchive Available; Libzip 1.7.1; approved DOCX opened read-only; DOM XML parsed; template unchanged. Assistant did not operate the browser | Passed (user-reported) |
| A04 | Assistant / migration source | Compare canonical SQL and inspect registered verifier | Two additive tables; no mutation of existing data or workflows | Canonical schema ends with byte-identical 052 SQL. Static checks cover two allowlisted CREATE statements, expected columns, indexes, foreign keys and checks. Runner has explicit verifier registration. No SQL executed | Passed (source only) |
| A05 | Assistant / syntax and pure regressions | Run focused PHP/semantic/template checks | Checks pass with original templates unchanged | Six changed PHP files parse. Mapping: 129/0; faculty records: 44/0; inspector: 8 packages/70 bindings/0 failures. Storage checks: first run 103/1; authorized correction retest 104/0 | Passed; initial failure preserved below |
| A06 | Assistant / protected baseline | Compare 049–051, eight DOCX files and existing workflow/record helpers | All 14 hashes unchanged | SHA-256 comparison against pre-edit in-session baseline: 14 matches, 0 changes | Passed |
| A07 | User / administrator | Explicitly approve Migration 052 after review | Approval before backup/execution stage | User explicitly approved 052 only on local rms_db, after a verified backup, with mandatory stop on verification failure | Passed |
| A08 | Assistant / authorized runner and read-only metadata | Apply 052 only, then verify installed schema/ledger | Full verifier passes; 052 ledger status applied | Original attempt: runner exit 1; SQL completed but verifier failed. Two empty tables created; 052 initially failed in ledger. Only two CHECK text comparisons mismatched. Stopped as instructed; later authorized correction/retest recorded in A12–A14 | Failed initially; retained |
| A09 | Assistant / protected backup | Fresh single-transaction dump and verification before execution | Exit 0, nonzero size, completed footer, SHA-256, protected location, stable source | Dump exit 0; 661,667 bytes; completed footer; 0 stderr bytes; stable pre/post-backup snapshot of all 57 tables. Backup details below. No restore rehearsal | Passed (dump verification only) |
| A10 | Assistant / read-only post-comparison | Compare preexisting data, definitions and old ledger rows | Only two new tables and one 052 ledger row added | 57 → 59 tables; all 57 previous schema hashes unchanged; all 56 existing data-table row counts/checksums unchanged; every previous ledger row unchanged; only 052 failed row added | Passed |
| A11 | Assistant / post-attempt regressions | Run pure storage/mapping/faculty checks, template inspector and PHP lint | Existing functionality and protected files remain intact | Storage 104/0, mapping 129/0, faculty 44/0; 8 packages/70 bindings/0 failures; 6 PHP files parse; 14 protected hashes unchanged | Passed; does not override A08 failure |
| A12 | Assistant / approved verifier-only correction | Compare actual Boolean CHECK structure and rerun full verifier/tests | Redundant parentheses accepted; changed semantics rejected | Live schema valid=true/errors=[]; storage now 126/0 including actual MariaDB clause fixtures and negative grouping/operator/literal/condition tests. All existing metadata checks retained | Passed |
| A13 | Assistant / approved conditional ledger update | Lock and reconcile only matching failed 052 row after verification | One row applied; no DDL/SQL rerun; idempotent | Exactly one guarded UPDATE; status applied, method verified_existing, approved checksum preserved. Second attempt found applied and performed no update; complete row unchanged across no-op attempt | Passed |
| A14 | Assistant / full read-only pre/post comparison | Verify previous data/schema/ledger, both empty new tables and regressions | No unintended modifications | 57 old schema hashes, 56 old data counts/checksums and all prior ledger entries unchanged; 59 tables total; both new definitions unchanged since original DDL; 9 FKs; new tables empty. Mapping 129/0, faculty 44/0, packages 8/bindings 70/failures 0; six PHP files parse; 14 protected hashes match | Passed |

## Failures, decisions, fixes, and retests

- **Original failure, subsequently corrected with explicit approval:** authorized `C:/xampp/php/php.exe scripts/migrate.php apply 052_earist_document_storage.sql` completed its SQL then exited 1 because the registered verifier returned false. Runner initially recorded 052 as failed with the approved checksum. The initial attempt was not successful verification.
- Original defect: string normalization removed whitespace/backticks and changed case, but did not normalize redundant Boolean grouping parentheses. MariaDB serialized the Annex D evidence and PDF bundle CHECK clauses without unnecessary AND-group parentheses. Expected/actual expressions remain in the original protected post-execution metadata; the failure record is not erased from this report.
- Per the user's explicit failure policy, stopped further implementation immediately. Only non-mutating metadata/data comparisons and regression checks followed. No SQL rerun, schema alterations, destructive rollback, restoration, reconciliation or Stage 4B work.
- User subsequently explicitly approved a verifier-only correction and safe, idempotent ledger reconciliation, requiring no SQL rerun, no schema changes, and a Stage 4A stop. That approval superseded the initial stop only for this bounded repair.
- Exact correction: added `earistSchemaCheckTree()` and used it **only** for `chk_earist_generated_verification` and `chk_earist_generated_pdf`. It scans quoted strings/identifiers and balanced parentheses, parses OR with lower precedence than AND, retains every ordered atomic condition and exact string-literal bytes, and flattens only associative groups of the same operator. Function-call parentheses remain part of atoms. Malformed/empty expressions fail closed. All other CHECK comparisons, expected expressions, columns/types/defaults/collation, index count/order/uniqueness/prefix checks, and nine FK target/rule checks are unchanged.
- Added 22 pure regression assertions (104 → 126): actual MariaDB serialization, essential mixed-operator grouping, missing conditions, changed AND/OR/comparison operators, altered hash/literal values, quotes/function parentheses, malformed input and identifier boundaries. Final storage result 126 passed, 0 failed; actual installed schema verifies fully with no errors.
- To make DDL unreachable, reconciliation did **not** invoke runner apply/status/sync. After full pre-verification, a prepared conditional UPDATE under a row lock changed only the existing matching-checksum failed 052 record to applied, with the same audit metadata conventions as runner reconciliation: method verified_existing, applied_at/verified_at current server time, execution_ms=0, error_message=NULL. No row was inserted and the checksum/key/filename stayed unchanged. Before/after rows and original error are retained in a new protected manifest.
- A second reconciliation attempt locked and verified the same applied/checksum row, then rolled back its read-only/no-update branch. It did not refresh timestamps or mutate any field; exact row comparison confirmed idempotency. Full post-verification passed. No repair needed beyond the approved scope.

- Storage safety check first run: 103 passed, 1 failed. The test scanned all SQL for DELETE/UPDATE and misclassified legitimate `ON DELETE RESTRICT` and `ON UPDATE CURRENT_TIMESTAMP` clauses. No DDL/data-mutation issue was identified in the migration. User explicitly chose "Correct the test and rerun it". Fix checks statement starts against the two allowlisted CREATE TABLE forms. Retest: 104 passed, 0 failed; checker PHP syntax also passed.
- A server-side PREPARE attempt within a READ ONLY transaction returned MariaDB error 1792 before any statement executed. Do not retry DDL outside the read-only boundary or claim server-side SQL validation. Post-apply verification remains blocked on explicit migration approval. No migration ran.
- An earlier ad hoc `php -r` invocation failed because Windows argument quoting removed string quotation marks. The helper passed syntax validation; the actual capability probe was rerun successfully through the CLI script. This was a command-invocation failure, not an observed application failure.
- Earlier Phase 3 user-reported passes remain historical results, separate from fresh Stage 4A checks.

## Assistant-run checks

- Read-only target query used a connection without application/session bootstrap and a READ ONLY transaction. No DDL/DML or ledger changes.
- Migration 052 preparation, PHP checks, and schema comparison results will be recorded here.
- `C:/xampp/php/php.exe scripts/check-earist-document-mapping.php`: 129 passed, 0 failed, pure arrays/policy.
- `C:/xampp/php/php.exe scripts/check-faculty-research-records.php`: 44 passed, 0 failed, no database/application interaction.
- `python -X utf8 scripts/inspect-earist-templates.py`: 8 packages, 70 bindings, 0 failures, no output DOCX.
- `php -l`: prerequisites helper, schema helper, preflight CLI, storage checker, migration runner and Admin Settings passed.
- `C:/xampp/php/php.exe scripts/check-earist-document-storage.php`: final 104 passed, 0 failed; database-free source and synthetic metadata checks, not installed-schema proof.
- Final protected SHA-256 comparison: migrations 049/050/051, all eight blank DOCX files, `includes/faculty-research-records.php`, `includes/earist-document-mapping.php`, and `includes/research-workflow.php` unchanged (14/14).
- Final source review removed an unintended duplicate verifier include/match arm before delivery; the runner is rechecked after correction. Scoped `git diff --check` found no whitespace errors; Git emitted line-ending normalization notices only.
- Prepared Migration 052 SHA-256: `f53c4a8222b5e637b09a7c5fdeb0caf28c59a4b27dd4ea1390d6a741690e7054`. Recheck bytes/checksum before any approved execution.
- Reconciliation retests: `php -l` passed for both changed PHP files and the four other Stage 4A PHP files; pure storage 126/0, mapping 129/0, faculty 44/0, eight-template/70-binding inspector 0 failures. Actual `earistDocumentSchemaStatus` returned valid=true/errors=[] both before and after ledger change.

## Stage 4A changes and database effects

- Created: Migration 052, prerequisites helper, schema verifier helper, two CLI check scripts, Phase 4 documentation and this session report.
- Modified: canonical schema by appending matching 052 definitions; runner by adding the verifier include/branch; Admin Settings by adding the read-only functional probe; Phase 3 report by preserving/reaffirming the user's historical pass report.
- Detailed file inventory: `docs/EARIST_DOCUMENT_GENERATION.md`.
- Intended database change after explicit approval: two empty additive tables only, `earist_document_drafts` and `earist_generated_documents`, plus the runner-managed 052 ledger entry. No existing-table alteration, sample records, grants or workflow changes.
- Actual authorized database effects: two empty tables created (`earist_document_drafts`, `earist_generated_documents`) and one failed 052 ledger row. Drafts: 16 columns, 5 indexes, 4 FKs, 3 CHECKs; generated documents: 33 columns, 9 indexes, 5 FKs, 7 CHECKs. All FKs use RESTRICT and expected same-schema targets (source faculty research record, source owner, creator/updater/generator/verifier users, and generated version → draft). Runtime ownership and role enforcement is not implemented or established by FK metadata alone.
- Subsequent reconciliation effects: only that 052 ledger row changed from failed to applied with audit metadata above; no further tables, columns, constraints or data records changed. Both original new tables remain empty and byte-normalized SHOW CREATE hashes are unchanged since their creation. Full metadata now matches the verifier; there are no remaining structural mismatches.
- Existing data preserved: all 56 preexisting data tables have unchanged exact row counts and EXTENDED checksums; all 57 preexisting table definitions have unchanged SHOW CREATE TABLE SHA-256 values; all previous ledger rows unchanged. Source snapshots in protected before/after metadata contain no dumped records. No PHP configuration changes, dependencies installed or PDF capability activated.
- New schema contracts support exact decimal score strings in JSON and Annex D verifier/time/payload fingerprint; no score calculation, issuance, signing or generation handler is implemented in Stage 4A.

## Verified backup and protected diagnostics

- Backup: `C:\xampp\htdocs\rms\backups\earist-052-before-2026-10-30-8b6f4f8a.sql`.
- Before metadata: `backups/earist-052-before-2026-10-30-8b6f4f8a.json`; after/diagnostic metadata: `backups/earist-052-after-2026-10-30-8b6f4f8a.json`.
- Size: **661,667 bytes**. Dump exit: **0**. Completion footer present; stderr **0 bytes**.
- Backup SHA-256: `6f382edc76fad276b5d2e3f2dbddf4786c55848f70cbc1651db1d037a88ea5a2`; rechecked before execution and afterward, unchanged.
- Protected storage: existing `backups/.htaccess` contains `Require all denied`; root rewrite also denies backups/SQL; `backups/` remains Git-ignored. No automated browser/HTTP request was made to test protection.
- Source stable during backup: all 57 table snapshots and full ledger rows matched before/after the single-transaction dump. Credentials stayed in memory/child environment; no password in command arguments, reports or backup metadata.
- This verifies dump completion/integrity, not recoverability through a tested restore. No restore rehearsal, rollback, backup removal or retention operation was performed.
- Post-attempt PHP syntax checks passed; the migration runner reported the verifier failure described above. Do not claim an error-free or successfully applied migration.
- The line above describes the original failed attempt. After the separately approved correction, final schema verification and ledger reconciliation succeeded; final ledger status is applied/verified_existing. This does not retroactively remove the initial error.
- Reconciliation manifest: `backups/earist-052-reconcile-2026-10-30-11eca91e.json`, retaining the failed row, applied row, full pre/post comparison results and idempotency outcome. Original dump and earlier diagnostic manifests were not modified.

## Files changed by the verifier-only reconciliation

1. `includes/earist-document-schema.php` — constrained Boolean comparison helper and its two CHECK call sites only.
2. `scripts/check-earist-document-storage.php` — 22 focused normalization/safety assertions.
3. `docs/testing/manual-reports/2026-10-30-earist-generation-01.md` — original failure, approval, exact fix, database evidence and final results.
4. `docs/EARIST_DOCUMENT_GENERATION.md` — current Stage 4A status only, consistent with this report.

Created protected artifact: `backups/earist-052-reconcile-2026-10-30-11eca91e.json`. No migration SQL, canonical schema, runner, application pages, templates, configuration, existing backup or unrelated code was modified in this repair.

## Resume / handoff

- Last user-confirmed state: Admin Settings prerequisites item reports Ready under apache2handler, ZipArchive Available, Libzip 1.7.1, read-only template/XML success and unchanged template.
- Next pending action: report successful Stage 4A reconciliation and stop. Stage 4B requires a new explicit approval. Do not rerun SQL, restore data, activate PDF or implement rendering at this checkpoint.
- Backup is verified as a completed dump, retained in protected storage; restore is untested. Preserve all evidence and the failed ledger row.
- No business records, generated documents, automatic cleanup, PHP configuration changes or dependencies installed. Across Stage 4A the two empty tables and the single 052 ledger record (initially failed, now applied) are the only observed database changes.
- Stages 4B–4D are not implemented. Word layout, printing, generated-file access/isolation and actual persistence checks remain pending their corresponding stages. Existing Admin navigation and fifteen module permissions were not changed in Stage 4A.
