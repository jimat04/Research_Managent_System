# Stage 4C B1 — activation readiness review

2026-10-30, Asia/Taipei. **Ready for controlled disposable testing; backup prerequisites passed and conditional B2 approval was exercised.** This is not acceptance for real-record use. `live_generation_enabled` is temporarily `true` for the approved controlled test window.

Latest follow-up: the user conditionally approved B2 and confirmed the quiet window. A corrected fresh coordinated backup passed SQL/file/checksum/ACL verification. The DOCX flag is enabled only for controlled disposable testing under that approval. Restore it to false at the end of testing. See the [B2 backup report](EARIST_STAGE4C_B2_BACKUP.md).

## Completed prerequisites

| Prerequisite | Evidence and limit |
|---|---|
| Migration 052 applied and verified | Existing C01 inspected the local `rms_db` ledger and exact installed storage metadata successfully. No schema change has been made in this review; migration execution was not repeated |
| Faculty and separate own-Admin Annex D modules | Implemented; current source reviewed. Exact role/active-account, Faculty ownership, separate preparing-Admin ownership, CSRF and reviewed-input checks are enforced server-side |
| Renderer and regressions | Reuse unchanged C157: renderer 4,569/0; policies 66/0; drafts 11/0; storage 126/0; mapping 129/0; faculty records 44/0; eight templates/70 bindings/0 failures. These do not prove live SQL publication |
| Apache ZIP/XML and private writes | Retain user-confirmed C04, scratch C36/C39–C42, generated-storage C135. No repeated browser tests |
| HTTP protection | Retain user-confirmed Incognito and signed-in Admin direct-file 403 results. Project-root denial for `storage/earist` remains present; no HTTP request was made by the assistant |
| Windows restrictions | No ACL changes. Current root descriptor permits SYSTEM ReadAndExecute, Administrators FullControl and Jim Modify; no broad/development principal listed. Child ACL inspection is denied to CLI, so full child-descriptor equality is not independently established. Retain the user's successful ten-object ACL verification and Apache write evidence; do not weaken permissions |
| Section A | A1–A4 and A6 Passed, user-recorded. A5 Not Applicable to the current single-Admin configuration at the user's instruction; two-Admin separation remains untested, not Passed |
| Preservation | Fresh SHA-256 comparison: all 19 protected baseline files unchanged, including migrations 049–052, schema, eight templates, renderer, original mapping/workflow helpers and disabled configuration |

## Backup status - B2 verification passed

The fresh coordinated checkpoint `storage/earist/recovery/b2-20261030-233036-c5444acba36141b7a762fd8ca25c71ea` passed the B2 checks. It contains a completed SQL dump with 59 tables including both Migration 052 tables and `schema_migrations`, plus the full protected generated storage and metadata. Independent verification matched all 31 checksum entries, table definitions and row counts, generated file sizes/hashes, manifest association and DOCX package validation. The dump contains two drafts and zero generated-history rows; one retained synthetic file set is marked unreferenced. The ACL remained restricted. No restore rehearsal was performed. Details and the earlier failed attempt are retained in the [B2 report](EARIST_STAGE4C_B2_BACKUP.md).

The user's conditional B2 approval and quiet-window confirmation were received. After backup verification, the DOCX gate was enabled for controlled disposable testing. It is global to eligible Faculty/Admin users; test only the approved disposable Faculty records and synthetic Admin evaluation information. Restore the setting to false after C-G or at the first critical failure. No PDF, migrations, templates or ACL changes.

## Remaining technical verification

| Area | Source safeguard already reviewed | Controlled live evidence still needed |
|---|---|---|
| SQL/history publication | Actor/source/draft row locks, stale payload/version checks, validated files, insert then successful commit | First real transaction, reload and stored download; database/file reference agreement |
| Concurrent versions | Source-parent serialization, current `FOR UPDATE` version read, unique source/type/version key | Independent overlapping requests; deadlock/timeout outcome. Same-session PHP requests may serialize, so two tabs alone do not prove overlap |
| Repeated requests | Server-issued session token, exact payload/mode binding and unique generator/token key | Actual repeated request returns the prior version. A double-click that sends only one request is not proof |
| Immutable versions | New random exclusive file set, INSERT history, frozen snapshots; no generation UPDATE of prior versions | Generate a later version and reopen the earlier stored bytes; source edits must not alter it |
| Downloads | Current role/owner and snapshot/manifest checks, safe server paths, size/hash verification; no regeneration | Successful authorized downloads and rejection of other users with actual generated IDs |
| QSF | Primary and companion validated before publication; same immutable manifest | Download both from the same history row and compare against their saved references |
| Failures | SQL rollback attempt; uncertain/partial file sets retained privately; no success unless commit confirms | Failure-path execution and ambiguous-commit reconciliation remain untested. Do not deliberately damage live files/database to test them |
| Source lifecycle | Coauthorship grants no history access; Admin owner stays distinct; deleted source blocks preparation/new generation but preserves history | Archive/delete/restore disposable source, including stale open forms and historical downloads |

No new implementation defect was established by B1. Pure/static checks do not replace the remaining transaction/concurrency tests. Multi-Admin isolation must be verified if that configuration is introduced. Full protected-file CLI tests and isolated restore/fault injection remain unavailable under the current environment.

## Exact activation and deactivation

1. Close the backup conditions, reserve a controlled local testing window, select only approved disposable Faculty sources and synthetic Admin evaluation details, and obtain explicit **Section B2** approval. The current gate is global to eligible Faculty/Admin generation, not a record allowlist: other users must not generate real documents during this window. Do not change roles/permissions to arrange testing.
2. In `C:\xampp\htdocs\rms\config\earist-document-storage.php`, change only `'live_generation_enabled' => false,` to `'live_generation_enabled' => true,`. Run `C:\xampp\php\php.exe -l C:\xampp\htdocs\rms\config\earist-document-storage.php`. If syntax fails, restore `false` before continuing. No `.env`, migration, ACL, template or PDF change is required.
3. User reloads the relevant preparation/generation page and confirms the enabled controls; perform the checklist below. No automatic activation is authorized by this document.
4. To stop new generation, immediately change that same value back to `false`, save, lint, then reload the generation page to confirm the disabled notice. Drafts, history and authorized stored downloads remain available. Existing source/config loading is per PHP request; the inspected PHP configuration does not enable OPcache. If the actual server does not observe the change, suspend testing and have the operator investigate runtime caching before continuing.
5. **The flag does not cancel in-flight requests:** it is checked at the start of the service. Wait for their outcomes and reconcile them before declaring a quiet backup point. Do not kill Apache/MySQL or restore data as an automatic rollback. Immediate isolation of all traffic, if ever needed, is a separate operator incident decision affecting other RMS functions.
6. On a failed test, disable new generation, record the non-sensitive error/time/document ID, and inspect authorized history before retrying. Use a read-only metadata/file reconciliation under an authorized identity to distinguish committed history from unreferenced partial sets. Preserve original files, database rows and logs. Missing/corrupt history must not be regenerated silently. Any restore, deletion or ownership transfer needs a separate reviewed decision and must not overwrite good history.

## Final manual checklist

Use the existing [single grouped checklist](EARIST_STAGE4C_MANUAL_CHECKLIST.md), without repeating A or previously passed security/storage checks:

1. **C — Faculty:** six forms, genuine warning acknowledgment, repeated/new requests, stale review, Annex B saved-author association.
2. **D — Admin:** fresh verification required, exact-decimal verified output, unissued output, stale evidence, repeated/new versions.
3. **E — History/downloads:** frozen metadata, repeat downloads without new versions, QSF companion association, older bytes unchanged, other-user and signed-out denial. Cross-check SQL/file manifests as above. Second-Admin cases remain Not Applicable to this single-Admin setup, not a proven cross-Admin pass.
4. **F — Source lifecycle:** archive, soft-delete and restore only the approved disposable source; preserve and download earlier versions.
5. **G — Word/printing:** open current downloads, verify content and blank official/signature areas, inspect all pages, record paper printing separately.

Report grouped Passed/Failed/Blocked results by ID. Keep genuine overlapping concurrency, fault injection and restore limits explicit if they cannot be exercised. B2 approval was exercised after the paired backup passed. The generation gate is active temporarily for controlled testing.
