# EARIST document field mapping — Phase 3

Stage 4B provides a separate [internal DOCX renderer](EARIST_DOCX_RENDERER.md) consuming these mappings, plus a separate pure Annex D preparation builder. The original Phase 3 mapping contract remains unchanged. The Stage 4C Faculty Saved Answers checkpoint extends the existing Faculty Mapping Review page and saves validated answers separately in Migration 052 drafts, with source/version fingerprints and optimistic revisions. Stage 4C also supplies generation, immutable history, authenticated downloads and a separate Admin-only Annex D page; the live-generation gate remains false pending explicit activation. Stage 4B resolves the pinned Annex D certification paragraph; QSF selected SDGs render in the main form. Annex D remains unavailable on the faculty page.

The review page prepares mappings from the authenticated faculty profile, one selected saved faculty research record, and document-specific answers. The current Stage 4C checkpoint can save those validated answers to the existing `earist_document_drafts` table; it does not change research records or Migration 052. It does not create DOCX/PDF files, offer downloads, save generated history, submit research, approve anything or initiate a faculty workflow.

## Entry point and files

From an owned Active or Archived research record's detail page, choose **Review Document Fields**. The route is `pages/faculty/faculty-document-mapping.php?id=RECORD_ID&document=annex_a`. The six choices are `annex_a`, `annex_b`, `annex_c`, `annex_e`, `annex_f`, `qsf_rds_019`. Saved answers are isolated by source record, faculty owner and document type. Review is required before saving; concurrent revisions are rejected, and changing source/template/input versions requires a fresh review. The mapping review itself does not create a DOCX. A separate generation review/action uses the saved draft when the server gate is enabled. PDF remains unavailable.

| File | Responsibility |
|---|---|
| `config/earist-documents.php` | Versioned document registry, reusable field definitions, input allowlists/types, protected official and fixed fields |
| `config/earist-template-bindings.json` | Data-only manifest shared by PHP and Python: eight approved package hashes, seven types, provisional C variant, 70 structural bindings and known uncertainties |
| `includes/earist-document-mapping.php` | Live authenticated entry point, owner/lifecycle checks, consistent read, pure mapping/validation and reference-hash lookup |
| `pages/faculty/faculty-document-mapping.php` | Faculty shell, document selection, saved-answer editor, review/error/warning states |
| `includes/earist-document-service.php` | Own-record draft loading/saving, versioned answer envelope, optimistic revision and stale-source checks |
| `scripts/check-earist-document-drafts.php` | Pure synthetic envelope, author-ID retention, reconciliation and review-hash checks |
| `pages/faculty/faculty-research-record-detail.php` | One new review link; existing lifecycle handlers unchanged |
| `scripts/check-earist-document-mapping.php` | Database-free semantic and policy checks; never bootstraps application configuration/authentication |
| `scripts/inspect-earist-templates.py` | Read-only ZIP CRC, XML, structural guards and package/header/footer hashes; standard library only |

The JSON manifest replaces the initially proposed PHP binding file so both inspection and application code consume one data source without an extra dependency. It is structural preparation, not a DOCX writer. Existing faculty shell, CSS/JS, profile helper, record helper and escaping are reused without modification.

## Service contract and trust boundaries

`earistMappingForUser(mysqli $conn, array $context, int $recordId, string $type, array $inputs = []): array` is the authenticated entry point. Pass only server-derived context from the existing faculty guard, never a submitted owner/role object. The service compares that context with `getCurrentUser()`, enforces the document policy, then starts its own REPEATABLE READ, READ ONLY consistent-snapshot transaction. Do not call it inside another transaction.

Within that transaction it calls `facultyRecordProfile()` and `facultyRecordLoadOwned()`. The latter's existing owner-scoped parent/child queries are unchanged. It rejects soft-deleted sources and permits owned Active/Archived sources. The transaction commits only a read; it never writes an audit event, account, record, setting or file. On failure it rolls back and propagates a generic domain error or a server-side failure.

`earistMappingBuild()` and the validation/policy functions are internal pure functions for already retrieved data. They do not authenticate HTTP requests and must not be exposed as endpoints or used to authorize callers. The builder additionally verifies profile/record owner consistency and lifecycle eligibility.

The Phase 3 builder result has:

| Key | Contract |
|---|---|
| `document` | Stable type key, label, mapping-definition version |
| `source` | Record ID/version/owner, allowlisted profile context and full selected research values; creation token hashes and unrelated account data excluded |
| `fields` | Canonical keys with `label`, `value`, `source`, `type`, `required`, `origin`, `applicable`, `classification`, `errors` |
| `inputs` | Normalized temporary answers for redisplay; invalid text is retained without truncation where safely representable |
| `errors` | Keyed validation errors; unsupported/official inputs are rejected and discarded |
| `missing` | Applicable Missing Required, Missing Optional and Document-Specific fields |
| `warnings` | Unconfirmed roles, limited destinations, long content, template and unsaved-answer warnings |
| `review_valid` | Input/source validation only; not approval, issuance or completion |
| `temporary_inputs_saved` | Always false for the mapping builder; the separate Stage 4C page/service persists a draft only after a reviewed, CSRF-protected save |
| `prefill_information_complete` | Whether all applicable non-official mapping information is resolved; not a Saved or Completed state and not permission to issue a certificate |
| `official_fields_pending` | Reserved official fields have no authorized source in this phase |

A future consumer must not use mapping values when `review_valid` is false. The `inputs` and complete `source` sections are provenance/redisplay data, not blindly usable output. Invalid field values are removed from the usable mapping. No renderer is authorized by this contract.

## Sources and common mapping matrix

`getCurrentUser()` supplies identity/role but omits document-profile fields. `facultyRecordProfile()` retrieves existing `users.user_id`, `first_name`, `middle_name`, `last_name`, `academic_rank`, `designation`, `appointment_status`, `department`, `email`, `contact`, and the institutional affiliation through `rmsSetting('institutional_affiliation')`. `application_settings` stores `setting_key`/`setting_value`; settings are not duplicated.

Profile display names join only saved nonempty name components. Rank/designation remain distinct in context and can both appear in QSF's combined position/designation slot. The current faculty profile is only an applicant suggestion until explicitly confirmed. Manual confirmed applicant information may differ from the owner or lead. Applicant details never refresh research-author snapshots.

| Canonical information / existing columns | A | B | C | D (definition only) | E | F | QSF |
|---|---|---|---|---|---|---|---|
| Title: parent `title` | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Saved author names/order: child `full_name`, `display_order` | Yes | Yes | Yes | Names | Yes | Yes | Yes |
| Saved positions/affiliations: child `position`, `affiliation` | Both | Affiliation | Both | Not specified | Both | Both | Both |
| Authorship roles: child `author_role` | Context/signature identity | Role column | Context | Context | Context | Context | Context; presenter separate |
| `work_type`, `work_type_label`, `work_type_other` | Yes | No slot | Yes | No slot | No slot | No slot | No slot |
| `date_started`, `date_completed` | No slot | YYYY-MM | No slot | No slot | No slot | No slot | No slot |
| Child `sdg_number`, parent `sdg_other` | All 17 and Others | No slot | No SDG answer slot | No slot | No slot | No slot | All selected goal numbers/names and supplied Others in SDG Alignment |
| `conference_title` | Yes | No slot | No slot | No slot | No slot | No slot | Yes |
| `conference_host`, `conference_organizer` | Separately labelled in combined question | No slot | No slot | No slot | No slot | No slot | Organizer only |
| `conference_start_date`, `conference_end_date` | Inclusive dates | No slot | No slot | No slot | No slot | No slot | Dates; future mm/dd/yyyy formatting |
| `conference_classification` | Annex A categories | No slot | No slot | No slot | No slot | No slot | Never inferred; separate confirmation |
| `presentation_modality`, `conference_venue` | Modality/in-person venue | No slot | No slot | No slot | No slot | No slot | Both |
| `journal_title`, `journal_publisher` | Yes | No slot | No slot | No slot | No slot | No slot | No slot |
| Confirmed applicant name/appointment/position/department/contact/email | No applicant slots | No applicant slots | No applicant slots | No applicant slots | No applicant slots | No applicant slots | Profile or manual confirmation |

The selected record is identified only by `research_record_id`, never title. Author snapshots are read from that record, not refreshed from `users`. External affiliations never default to EARIST. `faculty_user_id` remains optional linkage, not visibility or mutation permission. The owner can be a co-author or not the presenter. System timestamps never become research dates. Full source information survives document-specific applicability filtering.

## Document-specific answers and protected fields

| Type | Temporary answers | Always blank/reserved |
|---|---|---|
| A | Submission target; conference deadline/proceedings/indexing/registration amount and currency; journal deadline/peer review/indexing/scope and explanation/publication amount and currency; AI declaration and repeatable tool/purpose/prompts/extent rows; four independently confirmed documentary answers | Project Head checks, reference number, official indexing verification, clearance/remarks, evaluation date and signatures |
| B | Per-author categories from the printed CRediT list, descriptions, percentages, keyed by `record_author_id` | Signatures and signed dates; fixed certification/taxonomy untouched |
| C | No evaluator inputs | Seven ratings, remarks, total, recommendation, evaluator identity/position/date/signature |
| D | No active inputs or Admin reader | Verified final score, classification, certification/issuance and signatures; printed officials remain fixed |
| E | No clearance inputs | ITSO result, decisions, issuance and signatures |
| F | No signatory inputs | Reviewer signatory names/roles/signatures/dates; authors never fill these rows |
| QSF | Confirmed applicant source/details; explicit presenter author ID; QSF nature of conference; funding source/estimate/currency; recognition/endorsement answer; abstract/acceptance status and dates; attachment confirmations | Applicant signature/date and recommending approval signatures/dates |

Temporary monetary amounts use nonnegative decimal strings (up to ten integer digits and two decimal places), paired with a three-letter uppercase currency code; no currency is assumed. Currency syntax is checked, not independently verified against an exchange/ISO service. No fee or funding declaration constitutes approval.

Dates must be real calendar dates; research months retain their precision. Contradictory declarations fail validation (AI No plus AI details, indexing without proceedings, N/A plus indexing services, milestone date without confirmation). Complete sets of per-author percentages must total 100%, with each value between 0 and 100. Partial contribution drafts are allowed and missing per-author components remain Document-Specific. No contributions are invented. AI details support two initial rows and explicit addition up to 100 rows; exceeding this input limit is an error, not truncation. All saved research authors are retained without a template-based count limit.

The end-of-form completion marker detects POST/input-limit truncation before accepting a review or save. The page uses CSRF, strict field allowlists, output escaping, private/no-store caching and a same-origin referrer policy. Draft reads and saves remain limited to the live Faculty owner, source record and selected document type. Generic unavailable responses cover missing/foreign/deleted records. Domain errors show no foreign record content; unexpected errors log only exception class/code.

## Classification and requirement policy

Available means validated usable data; Missing Required means a missing common identity field; Missing Optional means an optional saved field is absent; Document-Specific means an answer/confirmation remains unresolved; Authorized-Only means no legitimate official source is enabled; Fixed Template Content means untouched official content. The origin is retained even when an explicit answer becomes Available. An invalid supplied answer remains unresolved with errors and cannot be used as a field value.

The common identity requirements are title/authors and, where printed, scholarly-work type (A/C). Optional source dates and targets remain optional in the mapping contract because the supplied templates do not establish complete institutional finalization rules. Document-specific conditional validation applies only to explicit declarations. Annex A submission-target confirmation controls applicability of conference/journal mappings without removing saved source information.

Missing official fields never prevent preparation of a legitimate blank/partially prefilled draft in a future renderer. Phase 3 does not decide institutional submission completeness, compute scores or certify readiness. The fixed Annex C recommendation thresholds are preserved instructions, not an implemented evaluation workflow.

## Template structure, discrepancies and preservation

The manifest lists exact filenames/hashes. A has six body plus two nested tables; B three; each C ten; D two; E one; F two; QSF seven. Variable destinations principally occupy cells, with mixed checkbox/label paragraphs, nested AI/signature tables and repeatable author rows. All templates have headers/footers; several have textboxes. Generic `goog_rdk_*` controls are not trusted semantic identifiers. There are no consistent named merge fields to support global replacement.

Bindings are one-based namespace-qualified paths into the approved `word/document.xml`, guarded by package hashes, node counts and nearby exact labels. A future renderer must preserve `w:tcPr`, `w:pPr`, `w:rPr`, fixed text and all other package parts. A mixed-cell binding is a documented region, not permission to erase its labels. It must later be refined into precise run/checkbox operations. Fixed headers/footers, logos/media, embedded fonts, borders, instructions, rubrics, signature areas and form identifiers are protected. No global string replacement, DOCX regeneration through a generic word processor, or automatic template edits are implemented.

Specific unresolved issues:

- **Annex A:** four documentary requirements currently contain checked glyphs. Older notes claimed they were reset; current package inspection is authoritative. Template states are recorded separately from all initially empty faculty answers. Before Phase 4 production, obtain institutional approval for an output policy: for example, output only explicitly confirmed checks and clear unconfirmed completion marks in generated copies while preserving the source, or obtain an approved truly blank replacement. Neither action is implemented or silently assumed.
- **QSF SDGs:** preserve every selected SDG and Others in `source.record`. In generated copies, replace the template's fixed 8/9 checkbox choices with all selected SDG numbers and full goal names from `facultyRecordSdgs()`, plus supplied Others text in SDG Alignment. Keep the approved source template unchanged; do not create an SDG companion DOCX.
- **Annex C:** Mabansag copy provisionally selected; Liwanag copy retained and hash-checked. The criteria, weights and recommendation text match. Signature guides, package parts/fonts/media and a column-break element differ. Cached pagination is not proof of rendered pages. User/institutional Word visual review is required before selection becomes final.
- **Annex F:** seven blank reviewer-signatory rows, not eight. Expansion beyond these rows needs an approved layout decision; research authors do not populate them.
- **QSF milestones:** status inputs govern date applicability; the supplied date rows do not provide Yes/No checkbox destinations. Do not invent checkboxes.
- **Annex D:** the certification-result area following “Accordingly” has no reliably resolved variable destination. A future separately authorized Admin implementation must verify it; current project-review scores/recommendations are not valid substitutes.
- **Pagination:** QSF footer parts include differing page-count wording. Preserve it and verify in Word before any correction.
- **Long content:** all titles (up to the existing 2,000-character source limit) and authors are retained. Long-title (>200 characters) and >2-author warnings request layout review, not truncation or a new database limit. Future repeated rows/paragraphs, signature expansion and continuation pages require visual verification.

## Phase 4 persistence and renderer prerequisites

Future reusable document drafts should be keyed by **Research Record ID + Document Type + Document Version**, with owner authorization. Store the versioned document-specific input schema and canonical answers, source record version, confirmed applicant/presenter roles, template hash, mapping-definition version and timestamps. Keep document version distinct from record version and mapping-definition version. Detect stale source changes and request reconciliation. Common research information remains canonical in the record; do not duplicate it into each live draft unnecessarily. Future generated versions need immutable input snapshots and must survive research edits/archive/delete/restore. No draft/history tables exist in Phase 3.

Before rendering: verify PHP ZIP support in both CLI and Apache separately; current CLI ZIP is absent, DOM/libxml/mbstring present, Apache unverified. No php.ini edits or temporary public phpinfo endpoint are part of this phase. Approve and package templates explicitly: global `*.docx` ignore rules currently omit the EARIST files from ordinary Git tracking. Validate allowlisted template paths and deployed hashes. Use ZIP + namespace-aware DOM targeted updates only after structural/visual approval. See [Microsoft WordprocessingML structure](https://learn.microsoft.com/en-us/office/open-xml/word/structure-of-a-wordprocessingml-document) and [PHP ZipArchive](https://www.php.net/manual/en/class.ziparchive.php).

Annex D remains Admin-only metadata with runtime mapping disabled even for Admin. Do not weaken the faculty owner-scoped loader. A future Admin reader, authorized verified results, aggregation policy and issuance controls require separate implementation/approval.

## Verification and release gates

Run the focused non-mutating checks:

```powershell
C:/xampp/php/php.exe scripts/check-earist-document-mapping.php
python -X utf8 scripts/inspect-earist-templates.py
C:/xampp/php/php.exe scripts/check-faculty-research-records.php
```

Use `php -l` on changed PHP. The semantic script uses in-memory illustrative arrays only: no database, application bootstrap, authenticated session or request. Policy tests do not prove runtime account isolation. The inspector only reads approved packages and writes no output DOCX. No new dependency or graph maintenance is needed.

Follow the [Phase 3 manual report](testing/manual-reports/2026-10-30-earist-mapping-01.md). The user operates every application interaction; Pending means unobserved. Before final generator release address Phase 2 M26 (stale saves), M28/M29 (edit/list isolation), M30 (non-faculty routes), M31/M32 (invalid input/retries), M36 (student regression), saved/reopened reordered roles and final deletion retest. M35/M37 remain supplemental. No historic pass is a fresh runtime verification.
