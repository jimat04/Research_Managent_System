# EARIST document generation — Phase 4

## Current delivery: Stage 4C integration, activation pending

Stage 4B adds the explicitly approved internal DOCX renderer for the six faculty forms and separately prepared Admin Annex D. See [renderer architecture and integration contract](EARIST_DOCX_RENDERER.md) and the [Stage 4B report](testing/manual-reports/2026-10-30-earist-rendering-01.md). Synthetic file-only checks and sample outputs do not establish Word layout or printing. Stage 4C now implements saved answers, document history, authenticated downloads and separate Admin Annex D preparation; live activation and grouped manual verification remain pending. PDF stays disabled. No Stage 4B database changes or application routes are added.

Stage 4A storage remains unchanged. Migration 052 initially created its two empty tables but failed post-DDL text verification. After separately approved verifier-only correction and ledger-only reconciliation, the full verifier passed and the ledger is **applied / verified_existing**. Stage 4B does not rerun or reconcile migrations.

Stage 4C design was approved on 2026-10-30. Its implementation now connects protected storage, Faculty saved-answer review/generation, separate own-Admin Annex D preparation/generation/history and authenticated immutable-file downloads using the unchanged Migration 052 schema. Existing Apache/storage/role checks and Faculty saved-answer observations are retained. New Admin and end-to-end verification follows the [single manual checklist](testing/EARIST_STAGE4C_MANUAL_CHECKLIST.md). Live generation remains disabled until explicitly approved for testing. Follow the [approved integration design](EARIST_STAGE4C_INTEGRATION_DESIGN.md), [operations procedure](EARIST_DOCUMENT_OPERATIONS.md) and [current verification report](testing/manual-reports/2026-10-30-earist-integration-01.md); do not reapply Migration 052.

Stage 4A results: CLI functional package probe passed; the user confirmed Ready under Apache (`apache2handler`, ZipArchive Available, Libzip 1.7.1). After the correction: pure storage 126/0, mapping 129/0, faculty records 44/0, eight-template/70-binding inspection 0 failures; six PHP files parse; all 14 protected baseline hashes match. The full installed-schema verifier passes. The correction compares Boolean structure only for the two affected CHECKs, preserving conditions, AND/OR precedence and string literals. Original failure and all retests remain in the session report.

Fresh protected backup: `backups/earist-052-before-2026-10-30-8b6f4f8a.sql` (661,667 bytes; exit 0; completion footer and SHA-256 checked). All 57 old table schema hashes, all 56 existing data-table counts/checksums and every old ledger row are unchanged. Only two empty tables and one failed 052 ledger row were added. Full backup hash and diagnostics are in the session report and protected before/after JSON metadata. No restore rehearsal or automatic rollback occurred.

Reconciliation used one guarded, row-locked UPDATE of the existing same-checksum failed 052 ledger row after full verification, matching runner reconciliation audit metadata while making all DDL paths unreachable. A second attempt was an exact no-op. All 57 prior table definitions, 56 data-table counts/checksums and previous ledger entries remain unchanged. Both new tables remain empty with nine expected FKs and unchanged definitions. No SQL rerun, schema alteration, restoration or PDF activation occurred. Protected evidence: `backups/earist-052-reconcile-2026-10-30-11eca91e.json`.

Approved process: select research → select form → review automatic mappings → save additional answers → generate DOCX → download → print → physically sign/review. Faculty forms have no mandatory Draft watermark. Missing valid information may remain blank after warning acknowledgment. Invalid or contradictory supplied values block generation.

## Reviewable files

| File | Stage 4A responsibility |
|---|---|
| `database/migrations/052_earist_document_storage.sql` | Original approved SQL unchanged; two empty tables fully verified; ledger reconciled to applied |
| `database/schema/rms_db.sql` | Existing contents preserved; append byte-identical 052 block for fresh installs |
| `includes/earist-document-schema.php` | Read-only metadata verifier and definitions; no application bootstrap |
| `scripts/migrate.php` | Register 052 verifier; no automatic execution or synchronization |
| `includes/earist-document-prerequisites.php` | Read-only approved-template ZipArchive/DOM probe, shared by CLI and Apache |
| `scripts/check-earist-prerequisites.php` | CLI probe; optional `--database` reads local rms_db metadata without bootstrapping sessions/application |
| `scripts/check-earist-document-storage.php` | Database-free DDL synchronization and verifier rejection checks |
| `pages/admin/admin-settings.php` | Existing protected runtime diagnostics display the functional probe; no new public test endpoint |
| `docs/testing/manual-reports/2026-10-30-earist-generation-01.md` | Separate assistant results, user observations, failures and pending approval |
| `docs/testing/manual-reports/2026-10-30-earist-mapping-01.md` | Preserve and reaffirm historical user-reported Phase 3 results |
| `docs/EARIST_DOCUMENT_GENERATION.md` | Stage scope, complete file inventory, schema/Annex D contracts and execution gate |

No changes to migrations 049–051, existing faculty-record handlers, student workflow, mapping service or any of the eight official DOCX templates.

## Migration 052 design

`earist_document_drafts` stores one reusable preparation draft per research record + source faculty owner + document type. It holds versioned document-specific JSON answers, source version/fingerprint, template hash, optimistic draft revision, creator/updater and timestamps. Common research information remains canonical in Faculty Research Records.

`earist_generated_documents` stores immutable input/source/mapping snapshots, source/draft/document versions, generated-by/time, missing fields and warnings, acknowledgments, generation-request token hash, renderer/template versions, DOCX path/hash/size and optional future PDF metadata. Existing snapshots are not updated when research changes. PDF metadata is reserved for a later append-once verified conversion, not an activated feature.

All IDs use existing unsigned types. Both tables use InnoDB and utf8mb4_unicode_ci. Foreign keys use RESTRICT, preserving history across source lifecycle actions. Unique indexes protect per-record/type document versions, request retries, and output-file identities. Explicit checks protect JSON, positive versions, hashes, mandatory DOCX metadata, warning acknowledgment, Annex D verification evidence and complete-or-absent PDF metadata.

No triggers, account changes, sample records, new grants, evaluation/approval workflow, signing or issuance state. No new index or alteration is required on existing tables. Role, ownership, draft/source consistency, exact score validation, immutable snapshots and append-once PDF handling remain backend responsibilities in later stages; a schema verifier alone does not enforce runtime permissions or filesystem immutability.

The registered verifier checks every expected column's type/nullability/default/extra and text collation, engine, indexes including order/uniqueness/prefixes, same-schema foreign-key targets and update/delete rules, and exact normalized CHECK expressions. It rejects absent, partial or mismatched storage. Positive verification against installed 052 tables and constraint behavior cannot be claimed until an approved execution.

## Annex D contract for Stages 4B/4C

Only live authorized Admin accounts may prepare, generate, list or download Annex D. Use a separate scoped reader; do not relax the existing faculty mapping/ownership policy. Admin may select an eligible Active or Archived faculty record. Deleted records must be restored for new generation; existing Admin-only history remains accessible.

Populate title and numbered, ordered author names with saved faculty rank/position and institute/affiliation. Admin enters the independently verified final score, explicit classification, certification statement, date/place and optional internal institutional-record reference. Signatures stay blank; printed officials and institutional content remain intact.

Preserve the score as a decimal **string inside JSON**, without floating-point conversion or fixed-scale storage. Accept 0–100; compare using exact decimal-string logic. Require the explicitly selected classification to match: Highly Recommended [90,100], Recommended [80,90), Moderately Recommended [70,80), Conditionally Recommended [60,70), Not Recommended [0,60). Never derive a score from Annex C or unrelated project reviews.

Applying printed whole-number bands to decimal scores requires confirmation of EARIST's institutional scoring policy. Display this requirement and require applicable Admin acknowledgment. Complete unmarked preparation requires valid mandatory fields plus confirmation against actual institutional evaluation records. Bind the confirmation to the exact reviewed payload; record current Admin/time/hash in the generated snapshot. No reusable draft permanently attests later edits.

Incomplete/unconfirmed Annex D copies are marked **UNISSUED — INCOMPLETE PREPARATION**. Complete, verified copies have no mandatory Draft watermark and are described as **Prepared for physical signing**. Neither mode asserts physical signature or official issuance. The schema requires verification evidence for Annex D `prepared_for_printing` rows and forbids attributing that evidence to faculty forms or incomplete copies.

## Safe execution gate

1. Complete the user-operated Apache probe and review the prepared SQL and verifier.
2. Obtain explicit approval to apply **052 only**. Recheck local target, ledger, migration checksum and source prerequisites immediately before execution.
3. Create a fresh backup under ignored, HTTP-denied storage. Check dump completion, nonzero size and SHA-256, and retain protected metadata. Do not print dump contents or credentials. A dump check is not a tested restore.
4. Execute the explicitly named migration through the existing runner; do not `sync`, bulk apply, or rerun 010.
5. Run the verifier and inspect the new ledger entry, empty new tables and unchanged existing data. Only then record application as passed.

Until approval, use the read-only preflight, not `migrate.php status`: the runner calls `ensure_tracking_table()` before its status branch. `CREATE TABLE IF NOT EXISTS` is not authorization to execute or a substitute for checking incompatible existing tables.

Rollback means disable generation access and retain tables/files; no automatic DROP or history cleanup. Operational backups/retention for generated artifacts must be documented when storage is implemented.

## Verification and next stages

Run `php -l` on changed PHP, `scripts/check-earist-prerequisites.php --database`, `scripts/check-earist-document-storage.php`, the existing 129 mapping checks, 44 faculty-record checks and eight-template/70-binding inspector. These do not establish installed SQL constraints, browser isolation, generated document layout or printing correctness.

User operates Admin Settings for Apache verification. Expected: **Ready**, **ZipArchive: Available**, Libzip 1.7.1, read-only template open/XML parse with unchanged hash. Do not send phpinfo dumps or credentials. Retain observations in the Stage 4A report.

Stage 4B implements the guarded renderer, repeatable authors/contributions/AI rows, confirmed checkboxes, full long-title content, separate QSF SDG continuation DOCX and payload-bound Annex D preparation. Stage 4C now implements Faculty saved answers/generation/history, separate own-Admin Annex D preparation and history, and authenticated stored-file downloads with role-specific scopes. Generation remains disabled pending explicit activation. See the [grouped manual checklist](testing/EARIST_STAGE4C_MANUAL_CHECKLIST.md), [operations](EARIST_DOCUMENT_OPERATIONS.md) and [session report](testing/manual-reports/2026-10-30-earist-integration-01.md) for actual passed/blocked/pending results. User-generated Word files and paper printing remain unverified. PDF remains disabled.
