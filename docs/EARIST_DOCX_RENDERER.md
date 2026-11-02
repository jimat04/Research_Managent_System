# EARIST DOCX renderer — Stage 4B

Stage 4B adds an internal, database-free PHP ZipArchive + DOM/XPath rendering engine. It supports Annex A/B/C/E/F and QSF-RDS-019 from the unchanged Phase 3 mapping builder, and Annex D from a separate pure preparation builder. It does not expose a route, authorize a user, load real records, save answers/history, provide downloads, sign or issue certificates, or convert PDF. Stage 4C supplies the separate authenticated integration. Live generation is temporarily enabled only for the user's approved B2 testing window; manual verification remains in progress.

## Files and contract

| File | Responsibility |
|---|---|
| `includes/earist-docx-xml.php` | Strict XML parsing, namespace-qualified unique bindings, guarded text edits, template-format inheritance, repeat fragments and checkbox handling |
| `includes/earist-annex-d.php` | Separate pure source/input validation and exact decimal-string classification; existing faculty access policy remains unchanged |
| `includes/earist-docx-renderer.php` | Rebuild validated mappings, produce review fingerprints, enforce acknowledgment, populate approved packages, return DOCX bytes and metadata |
| `scripts/check-earist-docx-rendering.php` | Authorized synthetic file-only automation and optional retained samples; never bootstraps application configuration or a database |
| `docs/testing/manual-reports/2026-10-30-earist-rendering-01.md` | Actual checks, interruptions/failures, user observations and Pending Word/print checks |

`earistRenderReview($mapping)` rebuilds and validates the mapping from its allowlisted source and normalized inputs, checks usable fields against the rebuilt result, and returns `mapping`, `missing`, `warnings`, `template_sha256`, `binding_version`, and `payload_sha256`. The fingerprint covers the rebuilt mapping, source version, inputs, renderer/binding/template versions, missing information and displayed warnings. This is deterministic content binding, not a security token, session, signature or authorization proof.

`earistRenderDocx($mapping, $options)` requires `acknowledged_payload_sha256` matching the current review. Invalid or contradictory inputs always block rendering. Missing valid information can remain blank after this acknowledgment. Stage 4C must display the review and record a genuine user acknowledgment through its authenticated, CSRF-protected handler; it must not automatically manufacture an acknowledgment because the helper exposes a hash.

The return value contains `bytes`, suggested `filename`, `sha256`, `size`, `document_type`, renderer/binding/template versions, `review_payload_sha256`, `missing`, `warnings`, `preparation_mode`, `verified_evidence`, and `supplements`. It does not contain a persistent output path or generated-document ID. The renderer currently returns no QSF supplement; selected SDGs are included in the primary QSF. The consumer must store every returned file securely and immutably and record its identity; never accept client filesystem paths or pass untrusted request arrays to these internal helpers.

## Package and template preservation

- Read original templates with `ZipArchive::RDONLY | CHECKCONS`; check exact SHA-256 before use and again afterward. No source package is ever opened for writing.
- Resolve all bindings before changes can shift positional XPath. Validate package table counts, unique targets, label guards and expected empty destinations. Changed/ambiguous bindings fail closed.
- Modify only `word/document.xml`. Every other package entry is copied byte-for-byte, including logos/media, headers/footers, fonts/styles, relationships, form identifiers and numbering. Original ZIP compression metadata may differ; unchanged entry contents do not.
- In mixed regions, change only identified text/glyphs, leaving runs, styles, paragraph properties and printed instructions intact. Clone known blank row/paragraph prototypes for expanded information; discard cloned Word editing identities. Variable rows with exact heights use minimum height so content can wrap instead of clipping.
- The generated ZIP is reopened and every entry compared with its expected bytes. XML rejects DOCTYPE/entity declarations, network/entity resolution and unsupported XML characters. Transient exclusive OS temporary files are removed in `finally`; retained samples are not automatically cleaned up.
- No mandatory Draft watermark is added to faculty forms. Actual signatures, evaluator ratings and clearance decisions are never fabricated.

## Form behavior

| Form | Population | Preserved official content |
|---|---|---|
| Annex A | Full title, work type, all ordered author name/position/affiliation blocks; all 17 SDGs and Others; applicable conference/journal details, dates and amounts/currencies; confirmed selections; repeatable AI-detail rows | Clearance/review table, declarations, instructions and printed officials. Four documentary prechecks are cleared in copies unless explicitly confirmed Yes. Extra coauthors receive cloned blank signature spaces. |
| Annex B | Full title, research months, one row per author with name/affiliation, saved role, confirmed CRediT categories, full description and exact percentage; expand blank author-signature rows | Certification language, CRediT taxonomy/references and blank handwriting/date areas |
| Annex C | Provisional Mabansag copy: full title, work type and all ordered author information | All evaluator identity/signature/date, ratings, rubric, remarks, total and recommendation matrix |
| Annex E | Full title and all ordered author information | ITSO outcome boxes, issuance/date/place, printed roles and signatures |
| Annex F | Full title and all ordered author information | Review signatories, roles, signatures, dates and fixed declarations; authors do not become reviewers |
| QSF-RDS-019 | Confirmed applicant/presenter; full title and ordered authors with positions and affiliations; conference, funding and milestone dates; all selected SDGs and supplied attachments | Original form, signatures, recommending officials, printed commitments and form/page identifiers |
| Annex D | Saved title and ordered names; only complete payload-bound verified preparation populates final score, explicit matching classification, certification statement/date/place | Printed bands/explanations, officials, institutional content and physical signature spaces |

QSF SDG Alignment lists every selected SDG by number and full goal name and includes supplied Others text directly in the official form copy. The renderer removes the template's fixed SDG 8/9 checkbox choices from generated output; the approved source template is unchanged.

Long titles are never truncated; author order never changes to put the owner/applicant/presenter first. Supplied month precision is retained for research dates. Empty answers remain empty rather than using system timestamps or guessed defaults. QSF's protected post-event commitments retain their original printed prechecks; only user-answer regions are populated from confirmed input.

Renderer `4b.13` retains the user's Word-review correction from `4b.2`: author-information blocks in Annex A/C/E/F and QSF show `Full Name, Position, Affiliation`, with each author in a separate paragraph and natural wrapping when needed. Name-only destinations remain limited to Annex D; Annex B keeps its separate name/affiliation column. In generated Annex B copies only, it removes 37 of the 39 empty paragraphs before “Contributor Roles Taxonomy,” leaves two for visual separation, and starts that taxonomy section on a new page. In Annex E output it removes all fifteen guarded empty body paragraphs; in Annex F it removes all twenty-two guarded trailing blank-page filler paragraphs. QSF conference date ranges use readable English month names with an en dash and are bold; other generated text in Section C is regular weight. QSF author paragraphs are left aligned so wrapped details stay with their numbered author; title and conference fields retain their template justification. QSF SDG Alignment lists every selected goal number and full name, plus supplied Others text; fixed options 8/9 are removed from generated copies. Applicant name, appointment status, position/designation and any supplied College/Department value are uppercased. Applicant information still requires an explicit profile or manual confirmation; missing profile/manual fields remain blank, and the renderer does not assume the faculty owner is the applicant. These adjustments change generated copies only. The approved source templates remain unchanged.

## Annex D and exact verification

`earistAnnexDMappingBuild($record, $inputs)` is an internal pure builder, not a live Admin API. Active/Archived records are eligible; deleted records must be restored first. Required for complete preparation:

- `final_score`: decimal **string**, 0–100, no floating-point conversion or fixed decimal scale. The original validated string is preserved.
- Explicit `classification`: `highly_recommended` [90,100], `recommended` [80,90), `moderately_recommended` [70,80), `conditionally_recommended` [60,70), or `not_recommended` [0,60). Contradictions block generation, including on marked incomplete copies.
- `certification_statement`, valid `certification_date` (YYYY-MM-DD), and `certification_place`.
- Boolean `scoring_policy_confirmed`: acknowledgment that applying the printed whole-number bands to decimal scores requires confirmation of EARIST's applicable institutional scoring policy.
- Optional `record_reference` is retained internally and never printed.

The approved package has a plain blank paragraph immediately after “Accordingly, the scholarly work is:” (body paragraph 20). The renderer validates that exact blank destination, its preceding wording, score paragraph and issuance placeholder before filling it. It does not replace printed certification language or invent an alternative destination. The old Phase 3 manifest's uncertainty remains historical; its bytes were not modified.

To populate official values, the caller must additionally supply `options['annex_d_verification']` containing server-derived positive integer `verified_by`, valid `verified_at` (`Y-m-d H:i:s`), and matching `verified_payload_sha256`. Evidence cannot attest to later source/input edits. The current helper validates the structure and payload binding; **only a future live Admin service can establish who is authorized and whether institutional records were actually reviewed**. Never accept the verifier identity, timestamp or verification evidence from client fields. Synthetic evidence in samples tests rendering only.

Complete verified copies display **Prepared for physical signing** and return `prepared_for_printing`. Incomplete, unconfirmed or unverified copies display **UNISSUED — INCOMPLETE PREPARATION**, leave official score/classification/certification/date/place placeholders blank, return `incomplete`, and carry no verification evidence. Both retain physical signature spaces. Neither mode establishes actual signing or issuance. Faculty forms reject Annex D verification evidence.

## Checks and samples

```powershell
php scripts/check-earist-docx-rendering.php
php scripts/check-earist-docx-rendering.php --samples
```

The script builds synthetic one-author, five-author and long-title examples for all seven forms, plus unanswered Annex A and incomplete/unverified Annex D copies. It checks exact score boundaries, contradictory input, stale acknowledgment/evidence, changed/ambiguous binding guards, UTF-8/XML escaping, rows, checkboxes, dates, complete author/title content, protected subtrees, ZIP/XML integrity and original hashes. It never uses real faculty records or creates/modifies a database.

`--samples` creates a new exclusive run directory under ignored, HTTP-denied `backups/earist-stage4b-samples-<date>-<random>/`, with a synthetic-only README and file hash/size manifest. Open locally from the filesystem in Microsoft Word. No HTTP download route is added, no previous samples/backups are overwritten, and no automatic cleanup runs. Samples may be manually retained or removed by their owner; they are not generated institutional history.

Word layout, wrapping, printed pagination/page wording, font availability, signature-space usability and actual printing require user observations. XML/package checks cannot establish those visual results. Very long titles and repeated rows may increase page count despite preserving original printed pagination. The provisional Annex C selection and QSF SDG Alignment layout require manual review.

## Stage 4C prerequisites

Approved Stage 4C Step 1 adds a backward-compatible internal `options['scratch_directory']` string. The live integration must pass the exact configured protected scratch root from `earistDocumentStorageDirectories()['scratch']`; never pass request-controlled options. Each package uses its own random subdirectory and random file. libzip can replace the package inode when closing, so cleanup verifies the exclusive directory allocation and removes only that call's known file and empty directory. The renderer no longer creates a QSF SDG companion. Omitting it retains Stage 4B's OS-temporary behavior for existing synthetic callers. Renderer `4b.13` applies guarded Annex B, E and F spacing changes, QSF author details, Section C text weight/date emphasis, selected-SDG output and uppercase applicant identity display to generated output only; it does not alter source template packages.

`includes/earist-document-files.php` provides additional publication validation and file-set manifests; its file primitives do not authorize users or write history. Stage 4C connects role-specific Faculty and Admin Annex D preparation, saved drafts, immutable generation snapshots/history and authenticated downloads. Generation is temporarily enabled for the user's approved B2 controlled test window and must be disabled at its end or after a critical failure. Apache capabilities, protected scratch/generated writes, HTTP denial and Windows ACL correction have user-confirmed results. Faculty Annex A and Annex B versions have been generated and opened in Word; the new Annex B spacing output awaits user review. See the [grouped checklist](testing/EARIST_STAGE4C_MANUAL_CHECKLIST.md), [operations](EARIST_DOCUMENT_OPERATIONS.md), and [Stage 4C report](testing/manual-reports/2026-10-30-earist-integration-01.md).

Implement the separate live Admin-only source reader without relaxing faculty ownership or enabling Annex D on the faculty mapping page. Recheck the current session/account and role for every prepare/generate/list/download action, including direct routes. Staff permissions must not grant Annex D access. Tie institutional verification and warning acknowledgments to the exact current reviewed payload; invalidate them after any edit or source/template/version change.

Connect optimistic saved drafts, immutable snapshots/history, idempotent request handling, version allocation, secure file paths and authenticated downloads to existing Migration 052 storage. Persist missing/warning acknowledgments and verified-by/time/hash consistently with its constraints. Retain history through source edits/archive/deletion; reject new generation from deleted sources. No Stage 4B SQL or migration execution is needed.

Before handling real records, establish protected output/scratch storage, safe filename handling, failure compensation and backup/retention procedures. Generated primary files must be backed up with their immutable metadata; do not automatically delete prior versions. Browser ownership/role tests, saved/reopened consistency and Word/printing checks remain necessary. PDF conversion requires separate approval and verified converted outputs.
