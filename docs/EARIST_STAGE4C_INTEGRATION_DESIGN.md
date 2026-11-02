# Stage 4C integration design — approved

Date: 2026-10-30, Asia/Taipei. The user explicitly approved this architecture, own-Admin Annex D access and the protected-scratch option, and instructed Step 1 implementation with a checkpoint report before the next major component. Faculty and Admin Annex D preparation/drafts, generation, immutable history and authenticated downloads are implemented. The live-generation gate remains false pending grouped manual verification and explicit activation approval. See the [session report](testing/manual-reports/2026-10-30-earist-integration-01.md). No schema changes are authorized.

## Source findings and implementation status

- `includes/earist-docx-renderer.php` exposes renderer `4b.2`, `earistRenderReview()` and `earistRenderDocx()`. Reuse its validated mappings, template/binding guards, exact decimal handling and QSF supplements. Its payload hash binds content, not authentication or genuine user acknowledgment.
- `pages/faculty/faculty-document-mapping.php` now reviews and saves source-bound answers and links to separate generation/history. It renders document controls, contribution inputs keyed by saved author IDs, mapped values and official blank regions.
- `earistMappingForUser()` authenticates current faculty, checks ownership and owns a consistent read-only transaction. Do not call it inside an integration write transaction. Reuse the pure mapping builders and existing source loaders under an integration-owned transaction without changing the original service contract.
- `facultyRecordSave()` retains existing author IDs during edits/reordering and locks the parent record. Source edits/archive/delete/restore increment its version. Integration must use the same parent lock to serialize its final checks against those operations.
- `requireRole()` admits administrators generally and uses session roles. The EARIST service must additionally check the live account and exact role. Faculty's existing `facultyRecordRequireUser()` already applies an exact faculty check. Annex D must use a separate current-Admin reader, never a relaxed faculty reader or staff permission grant.
- Admin navigation now has the Annex D Preparation & History link in Faculty Research & Engagement; Faculty navigation has Generated Documents in Research & Profile.
- Fresh read-only preflight: target local `rms_db`, MariaDB 10.4.32, migrations 049–052 applied, both installed document tables fully match the existing schema verifier. No migration execution or schema/data change.
- Fresh CLI probe: PHP 8.2.12, ZipArchive/DOM/libxml/mbstring available, Libzip 1.7.1, approved template opens and parses unchanged. Apache processes exist; configuration loads PHP from `C:/xampp/php`, enables rewrite/access-control modules and allows `.htaccess` overrides for htdocs. The user subsequently confirmed current Apache capabilities, HTTP denial and both protected write lifecycles; those completed observations are retained in the session report.
- Current CLI input limit is 1,000 variables; configured PHP memory is 512 MB, Apache configuration file execution limit 120 seconds. Preserve the existing end-of-form sentinel and reject truncated submissions. Never silently truncate author/AI inputs or change server settings automatically.

## Ownership and lifecycle policy

| Actor | Draft/preparation | History/download |
|---|---|---|
| Faculty | Six faculty form types, own Active/Archived source records only | Own faculty-form versions, including soft-deleted sources |
| Admin | Annex D only; select eligible faculty-owned Active/Archived records | Own Admin-owned Annex D versions, including soft-deleted sources |
| Other Admin | Separate Annex D drafts for the same source permitted | No access to another Admin's drafts/files by default |
| Staff, student, coauthor without ownership | Denied | Denied |

For faculty forms, `owner_id` is the faculty source owner. For Annex D it is the preparing Admin, independently of the faculty `research_record_id` owner. Preserve source faculty ownership in the source snapshot and check both identities in the backend. Current active account/role is required for every action. No general Admin override into faculty document history.

Soft-deleted sources allow only authorized historical list/download actions; preparation saves and new generation require restoration. History must not call the active-source mapping entry point. Archive permits normal preparation and generation. Neither coauthorship nor generic staff download permissions confer access.

## Schema use, saved inputs and stale edits

- Keep Migration 052, its verifier, the canonical schema and migrations 049–051 unchanged. No additional migration is proposed.
- `earist_document_drafts` already provides a unique `(research_record_id, owner_id, document_type)` draft, optimistic `draft_revision`, source version/fingerprint, input/mapping/binding versions and template hash.
- Save a versioned `inputs_json` envelope containing normalized document-specific answers and minimal author-reference metadata needed to detect stale author associations. Do not copy the faculty profile into reusable answers. Keep transport/retry metadata separate from the allowlisted mapping inputs.
- Reuse Phase 3 validation and the Annex D pure builder. Missing valid values can be saved; invalid or contradictory values cannot replace the last valid draft. Show rejected browser entries for correction without claiming they were saved.
- Save uses a checked draft revision and current source fingerprint under row locks. A conflicting update returns a clear conflict and preserves entered values. An exact repeated save with the same request identity/content is a successful no-op; a reused identity with different content is rejected. Retain the last successful save's request/content hashes in the versioned JSON envelope for replay detection without a new column.
- Source fingerprint covers the current allowlisted source, author IDs/order/details and relevant profile context, rather than relying only on record version. Compare input/mapping/binding/template versions too.
- After source changes, retain answers and require a new field review before save/generation. Contributions remain keyed to existing author IDs. Removed-author contributions and invalidated author selections are shown for reconciliation and retained in the draft envelope until explicitly reconciled; never attach them to a replacement author by name or position. New authors start with blank contributions. No silent answer loss or automatic reassignment.
- Even forms with no document-specific inputs need an empty saved draft before generation because each generated version references a draft.

The schema has no companion-file table or composite FK tying all draft/source/owner fields together. Backend consistency checks are required. `snapshot_json` can safely store the companion manifest with path/hash/size and the frozen source/input/mapping/acknowledgment evidence; no schema change is needed for this scope.

## Review, generation, retries and immutable history

1. Load authorized source and saved draft consistently. Display current mapped values, missing fields, warnings and template status. Save validated answers separately from the source record.
2. Review the saved revision. Issue a random server-created generation request token bound to the actor, source, document, draft revision and `earistRenderReview()` payload hash. Require an explicit checkbox for the displayed missing information/warnings. The hash alone is not consent. Any input/source/template/binding/renderer change requires fresh review.
3. For Annex D, display the exact decimal score bands already implemented: [90,100], [80,90), [70,80), [60,70), [0,60). Keep scores as strings. Require applicable institutional scoring-policy confirmation and a separate fresh confirmation against institutional evaluation records for an unmarked preparation copy. Derive verifier ID/time/hash on the server; never save a reusable attestation that verifies later edits. Incomplete/unverified copies remain unissued with official result placeholders blank. Invalid values still block both modes.
4. Render from the reviewed authorized snapshot into protected scratch storage outside a long write transaction. Reuse renderer `4b.2`; do not rebuild its form logic. Validate all output ZIP entries and XML/relationship parts without entity/network access, reject duplicate/unsafe entries and invalid package relationships. Treat the primary and any QSF companion as one publication set.
5. Before publication, start a short transaction, lock/recheck current actor/account, source parent, relevant profile context and draft in a consistent order, and rebuild/compare the exact reviewed payload. Reject stale source/draft or authorization changes. Check successful request-token replay before requiring a still-active source so a retry can return its already-generated authorized version even after later source deletion.
6. Serialize version allocation on the source parent row. Allocate the next version for `(research_record_id, document_type)` across all owners, matching the installed unique index. Annex D numbering can therefore have gaps in an individual Admin's visible history; do not weaken the index or expose other Admin documents.
7. Persist every output to an exclusively created server-named version directory, verify complete writes and disk hashes/sizes, then insert the immutable history row and commit. Store exact source/input/mapping snapshots, renderer/template versions, warning acknowledgment, preparation mode and applicable Annex D evidence. Leave all PDF metadata null.
8. The unique `(generated_by, request_token_hash)` index handles successful duplicate requests. Reuse of a token with another payload is an error. Concurrent requests recheck the token after acquiring the lock. A deliberate new generation uses a new token and creates a new immutable version even if inputs are unchanged.
9. Files and SQL cannot share an atomic transaction. Publish files before committing success metadata; failed attempts must not appear as successful history. Retain unreferenced/uncertain files privately for documented reconciliation after crashes or ambiguous commits. Do not delete historical files, overwrite outputs or automatically retry by inventing a new request token.

Downloading only serves the stored historical bytes; it never renders or updates a historical snapshot. Missing/corrupt files return an unavailable/error state and are recorded for repair from a verified backup, not silently regenerated.

## Private files and operations

- Default server-owned root: `storage/earist/`, with separate `scratch/` and `generated/` subdirectories. Add an unconditional `Require all denied` file, a root rewrite denial, and ignore generated content in Git while retaining protection files. No public upload directory or direct file URL.
- Use server-generated opaque names, exclusive file creation, canonical root containment and symlink/reparse escape checks. Download accepts only a generated-document ID plus an allowlisted primary/companion selector, never a filesystem path. Check current role/ownership and draft/source consistency before resolving a stored file.
- Open/pin the selected file, confirm regular-file containment, size and SHA-256 before sending DOCX attachment headers; stream that same verified handle with private/no-store and nosniff headers. Build a safe filename from server metadata.
- The current renderer uses `sys_get_temp_dir()`. Proposed sole renderer change: a backward-compatible, internal scratch-directory option passed through package/supplement creation, preserving existing default synthetic checks and document bytes. Validate it as a server-controlled directory. No changes to mapping, form population, approved bindings or blank templates.
- Before real-record use, require user confirmation that HTTP access to the new directory is denied and that Apache can create protected scratch/output files through the normal feature. Windows ACLs must restrict access to the service/operator; POSIX mode arguments alone do not prove Windows privacy.
- Document coordinated recovery: pause generation, take a consistent SQL backup plus the immutable generated root and companion manifests, verify hashes/references, then resume. Restore/rehearse only against separately authorized isolated storage/database. Existing Admin backup creates SQL only and does not back up DOCX bytes. Do not change unrelated backup UI or claim SQL backup alone protects documents. Retain documents by default; no scheduled cleanup, retention deletion, export, backup execution or restore is authorized by this design review.

## Exact proposed integration files

| File | Change |
|---|---|
| `includes/earist-document-service.php` | New live role/ownership checks, source snapshots, saved draft operations, stale checks, idempotency and version/history transactions |
| `includes/earist-document-generation.php` | Shared internal row-locked publication, replay, versioning and role-scoped immutable history |
| `includes/earist-annex-d-service.php` | New separate Admin-only faculty source reader and verification preparation service |
| `includes/earist-document-files.php` | New private storage, ZIP/XML validation, immutable publication, manifest and verified file access helpers |
| `includes/earist-document-ui.php` | Shared escaped preparation/history presentation; reuse current field controls |
| `config/earist-document-storage.php` | Server-controlled storage configuration and input-envelope version |
| `pages/faculty/faculty-document-mapping.php` | Extend current page with saved answers, fresh review acknowledgment and generation actions |
| `pages/faculty/faculty-generated-documents.php` | New faculty history with source/type/version/date/by/source-version/completeness and DOCX/companion links |
| `pages/admin/admin-earist-annex-d.php` | New separate Admin source selection, preparation, verification, generation and own-history views |
| `pages/shared/download-earist-document.php` | New authenticated primary/companion DOCX download route |
| `pages/faculty/faculty-research-record-detail.php` | Only document entry text/history links, including history access for deleted sources |
| `includes/faculty-shell.php`, `includes/admin-shell.php` | Only relevant Generated Documents / Annex D navigation links |
| `css/earist-documents.css` | Small scoped styles using existing role shells, colors and accessible form patterns |
| `includes/earist-docx-renderer.php` | Only backward-compatible private scratch hook described above |
| `.htaccess`, `.gitignore`, `storage/earist/.htaccess` | Deny HTTP access and keep private runtime files out of Git |
| `scripts/check-earist-document-integration.php` | Database-free synthetic policy/input/snapshot/retry/file-integrity checks; no fabricated sessions or application requests |
| `docs/EARIST_DOCUMENT_GENERATION.md`, `docs/EARIST_DOCUMENT_MAPPING.md`, `docs/EARIST_DOCX_RENDERER.md` | Update delivered contracts and cross-links after implementation |
| `docs/EARIST_DOCUMENT_OPERATIONS.md` | New storage, failure/reconciliation and coordinated backup/retention procedure |
| `docs/testing/manual-reports/2026-10-30-earist-integration-01.md` | This session's actual static checks, decisions and pending user checks |

Existing auth, mapping, faculty-record persistence, storage-schema verifier, migrations 049–052, templates, student/publication/copyright functionality remain unchanged. No dependencies, PDF conversion, signing or approval workflow.

## Incremental sequence and acceptance checks

1. Approve this design, including own-Admin Annex D ownership and the narrow scratch hook. Record the decision before implementation.
2. Build private file and integration-service foundations; verify syntax and synthetic access/containment/package checks. Report the checkpoint.
3. Add saved drafts and extend faculty mapping review/generation. Verify validation, stale author/revision handling and retry logic statically/synthetically. Report the checkpoint.
4. Add immutable history and authenticated primary/QSF-companion downloads; add only relevant faculty links. Check source deletion/history separation and file integrity. Report the checkpoint.
5. Add the separate Admin Annex D source/preparation/history interface with fresh server-bound verification and exact score validation. Add its Admin link; review affected destinations among the 15 module subpages. Report the checkpoint.
6. Run changed-PHP syntax, existing storage/mapping/faculty/renderer regressions, new pure integration checks, template/baseline hashes and final scoped diff review. Finish storage/recovery documentation.
7. Use the user-requested [single grouped manual checklist](testing/EARIST_STAGE4C_MANUAL_CHECKLIST.md); reuse completed observations rather than repeat them. Remaining coverage includes: current Apache probe; private-directory denial; faculty save/reload, stale tabs, invalid inputs, warning acknowledgment and generation; same-request retry versus intentional regeneration; immutable history and QSF companions; Admin complete/unissued Annex D and decimal mismatch rejection; active/archive/delete/restore behavior; owner/other faculty/coauthor/Admin/staff/student and logged-out direct-route boundaries. Use disposable records through supported UI only. Then user downloads, opens Word, inspects layout and physically prints. Record only confirmed observations.

No mutating database automation is authorized against normal `rms_db`. Pure tests cannot establish database persistence or concurrent SQL execution. Those remain user-operated checks; any later request for automated transactional tests needs a verified isolated disposable target and agreed scope. Physical printing and unreviewed Stage 4B variants remain pending independently of implementation.
