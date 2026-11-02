# Verification: publication archive

- Date/time: 2026-11-01 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Configurable publication classifications and subtype-aware archiving in the existing Publication Records module.
- Environment URL: `http://localhost/rms/` (fallback from `.env.example`; runtime URL not yet confirmed)
- Roles and disposable records/files: Admin and faculty; no new records/files created by assistant.
- Session state: In progress; no browser page or role state has been reported by the user.

## Graphify and source context
- Question and selected graph vocabulary: Trace publication archive upload, role authorization, and CSRF components: `publications`, `handleRmsUpload`, `requireRole`, `isCsrfTokenValid`, `archiveEscape`, `RmsFileUploader`, `publication-detail.php`, `file-uploader.php`.
- Query/lookup and result: `graphify query "publications handleRmsUpload requireRole archiveEscape" --budget 1500` was truncated at 42/55 nodes. Narrowed to `graphify query "handleRmsUpload()" --budget 1500`; found the uploader helper, its permission/logging calls, and an older publication form caller.
- Relevant source locations and verified relationships: Current `includes/archival-record-page.php` loads auth, role guards, and delegates to the existing archival handlers; `includes/archival-record-form.php` calls `handleRmsUpload`, validates CSRF, archive ownership, and writes activity history; `includes/archival-records.php` enforces owner/contributor/admin/staff access; `pages/shared/download-research-document.php` routes publication documents through `archiveCan` and a publications upload-path allowlist. Confirmed against current source after treating graph edges as hints.
- Missing, inferred, stale, or truncated context: Graphify has old publication-form and removed E2E references; new classification helper/pages are absent from the graph. Initial broad lookup was truncated. Focused `download-research-document.php` queries returned 61 nodes and truncated 18; direct edge lookup for the handler returned no edges. Current permission, detail template, and upload source were checked directly. No graph rebuild performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant, `php -l` on changed PHP | Lint archival form/detail/list/page, classification helper/admin settings, download handler, and migration runner | All files parse | All eight `php -l` commands reported no syntax errors | Passed |
| S2 | Assistant, `node --check js/publication-classification.js` | Check dynamic form script syntax | No syntax error | Command produced no diagnostic output | Passed |
| S3 | Assistant, `php scripts/migrate.php apply 060_publication_classification_metadata.sql` | Apply additive migration to local `rms_db` after verified backup | New nullable classification/metadata schema and catalogs are applied | Runner reported `APPLIED 060_publication_classification_metadata.sql`; subsequent status reported `applied / executed` | Passed |
| S4 | Assistant, `php scripts/migrate.php status` | Confirm migration ledger | Migration is recorded as applied | `060_publication_classification_metadata.sql` is `applied` | Passed |
| S5 | Assistant, `php scripts/check-archival-records.php` | Run existing read-only archive schema/distinction check | Archive distinction assertions pass | Initial run failed because the diagnostic expected `archiveCopyrightType('patent')` to be false, while current source supports patents. Updated that stale assertion; rerun passed with 27 archive SQL statements prepared, schema checks passed, and 7 distinction checks | Passed after fix |
| S6 | Assistant, `php scripts/check-publication-archive.php backups/archive-20261101-135802-05e836` | Exercise in-memory form validation and read-only schema/preservation comparisons | Rules reject invalid data; existing rows/files remain intact | 47 validation assertions passed; 11 nonlocking SELECTs prepared; 63 original non-ledger tables and all 30 backed-up attachments matched. Six new authentication audit rows and account `last_login`/`updated_at` changes are separate post-backup activity. Restore was not rehearsed. | Passed with noted live activity |
| S7 | Assistant, `php scripts/migrate.php status` | Confirm new schema and ledger | Both publication migrations appear applied | 060 and 061 are `applied / executed`; pre-existing `needs-verifier`, `checksum-mismatch`, and `ready-to-sync` statuses were not changed or run. | Passed |
| S8 | Assistant, PHP lint and `node --check` | Check changed PHP/JavaScript | No syntax errors | Affected publication/archive PHP files and the publication script passed syntax checks; CSS braces balanced 32/32. | Passed |
| B1 | User-operated browser | Open RMS login page | Login page is visible | User confirmed the login page loads at `http://localhost/rms/`. | Passed |
| B2 | User-operated browser, admin | Open Publication Records and classification settings | Catalog entries and initial short-paper rule appear | User confirmed all five proposed types, the three seeded scopes, and the 250-word/four-keyword/paper-required/no-page-limit/references-optional Short Conference Paper rule. | Passed |
| B4 | User-operated browser, faculty | Check abstract cap/counter and ordered author controls | Input that would exceed 250 words is blocked; author order changes as requested | User confirmed the cap blocks a 251st word and author rows reorder with updated numbering. | Passed |
| B5 | User-operated browser, faculty; user confirmed a disposable published-work example and PDF are available | Save a valid publication, reopen it, and review duplicate warning flow if a visible match exists | Metadata/authors persist; record is archived without a scholarly approval transition | User confirmed save succeeded, entered details persist, and no approval step appeared. | Passed |
| B6 | User-operated browser, authorized record | Preview and download the attached manuscript PDF | The selected PDF is served through the record's document links | User confirmed the manuscript preview and download both work. Separate evidence/reference attachments and previous versions were not exercised. | Passed (manuscript) |
| B7 | User-operated browser, faculty | Open the disposable record's detail and document route from a second faculty account | Unauthorized record/document access is denied | User confirmed the second faculty account was denied access. A separate screenshot showed the existing copyright supporting-document view; it was consistent with the shared detail page. | Passed |
| B8 | User-operated browser, admin and faculty | Check type/subtype/scope/year/program/indexing filters, report groups and CSV output | Directory, report and export values align | Not observed yet | Pending |
| B9 | User-operated browser, copyright detail view from screenshot | Reload and inspect the supporting-document section | Filename and file type are grouped; Preview and Download are distinct buttons and responsive at narrow width | The first screenshot showed new markup but not its styles, suggesting the browser retained the old archive CSS. Cache-busting was added; visual retest pending. | Pending |
| B10 | User-operated browser, publication detail view | Inspect the Authors in publication order section | Authors have readable sequence numbers and names at desktop and narrow widths | PHP lint passed, CSS braces balanced 93/93, and diff check is clean. Browser review pending. | Pending |
| B3 | User-operated browser, faculty | Open Add Publication and select Conference Proceedings Paper → Short Conference Paper | Conference form fields appear; Local / Institutional is selectable; 250-word and four-keyword rule is shown | User confirmed the expected form appears with the selected classification. | Passed |

## Failures, decisions, fixes, and retests
- B9 first showed the updated document markup in an unstyled vertical stack. The user requested that this UI be fixed. Source showed the archive stylesheet URL had no cache version; `archiveShellOpen()` now appends the stylesheet modification time so updated CSS is fetched after edits.
- During B4, the user asked for the abstract input to stop accepting text that would exceed 250 words. The previous form only displayed the live count and rejected an over-limit submission. Added a client-side `beforeinput` guard while preserving the server-side limit; browser retest is pending.
- S6 first stopped because its diagnostic attempted a locking SELECT inside a read-only transaction. The user selected “Investigate and fix now.” The diagnostic now prepares nonlocking reads only; rerun passed. Backup comparison confirmed six new authentication-only audit rows and changes limited to account `last_login` and `updated_at`. These live changes are recorded separately; nothing was restored or rewritten.
- S5 initially failed. The user selected “Investigate and fix now.” Source inspection showed the check contradicted `archiveCopyrightType()`, which intentionally includes `patent`; updated the diagnostic expectation to match current supported types and reran successfully. Initial failure retained here.

## Assistant-run checks
- `php scripts/backup-archival-records.php` created `backups/archive-20261101-135802-05e836` for local `rms_db`. Backup verification reported `integrity: passed`, 64 tables, 30 supporting files, dump SHA-256 `605632fd756b6cac34f7d29b4d12aeec0698d168977f5f18a0debc770997fbc5`; restore was not rehearsed.
- `php scripts/migrate.php status` was checked before applying migration 060; that migration was pending. Other pre-existing ledger entries included `010_demo_accounts_resync.sql` and `029_research_basics_lesson_progress.sql` as `needs-verifier` and older checksum mismatches; none were run or modified.
- `php scripts/check-archival-records.php` passed after correcting the stale patent classification assertion. It reported 3 publication rows and the existing historical status counts; no publication data was changed by this read-only check.
- Manual SQL source review confirms migration 060 adds nullable fields and lookup tables, and contains no UPDATE/DELETE of existing publication rows.
- `php scripts/check-publication-archive.php backups/archive-20261101-135802-05e836` passed 47 pure validation assertions covering short-paper boundaries, author order, URL validation, indexing evidence, saved inactive classifications, legacy edit preservation, and malformed values. It prepared 11 nonlocking literal queries. Its comparison verified all 63 original data tables except the expected-changing migration ledger, all 3 existing publication rows, and 30 of 30 supporting files. Six authentication events were appended after backup; only account `last_login` and `updated_at` fields changed.
- A second checkpoint was created before migration 061: `backups/archive-20261101-140948-887bf1`, 68 tables, 30 files, dump SHA-256 `029f7996f4ba1959eb3422d24fce19834e9c65a8111dd2b8c6951a2887a6147b`. Migrations 060 and 061 are recorded applied. No restore was rehearsed.
- A focused Graphify query for `download-research-document.php` returned 61 nodes with 18 truncated; direct edge lookup found no edges. Current source confirms `archiveCan()` protects archive access, publication paths use the existing uploads allowlist, and previous file versions resolve from matching audit fields. The new classification helpers and forms are absent from the stale graph.

## Resume / handoff
- Last user-confirmed state: No browser page, login state, or application observation yet.
- Next pending action: Request the user to open the local login page, then guide one browser check at a time.
- Blocked/skipped checks and remaining verification gaps: B1-B7 passed; B8 and B9 remain pending. Separate evidence/reference file slots and historical file previews were not exercised. Other non-target type forms remain unconfirmed. Continue with one user-operated browser check at a time.
- User-performed data changes and cleanup status: None. Assistant applied additive schema migrations 060 and 061 to local `rms_db`; both verified backups are retained. No archive records were created or changed.

## Follow-up: Legacy classification and complete subtype visibility

- Request: Keep deprecated Legacy classifications out of new-record choices; preserve and display historical classifications; complete subtype-specific form rules; preserve hidden historical metadata while changing classification.
- Graphify terms and query: `publicationValidate`, `publicationRules`, `classification`, `subtype`, `metadata`, `archive`, `permissions`; `rtk graphify query "publicationValidate publicationRules classification subtype metadata archive permissions" --budget 1500` returned older `pages/shared/publications.php` and `pages/shared/research-archive.php` nodes. Current publication-classification helpers and role pages are missing from the graph; source was confirmed directly.
- Confirmed current relationships: Existing faculty/admin publication routes call the shared archive form; `includes/publication-archive-form.php` loads classification rules and uses existing archive authorization, audit, and secure upload helpers; `includes/archival-record-list.php` supplies directory filters, reports, and export; `pages/admin/admin-publication-classifications.php` saves allowed form settings.
- Assistant changes: Added subtype-specific metadata fields and labels, configurable abstract/keyword visibility, required-field filtering, contributor-role snapshots, and explicit Legacy rejection for new classifications. Hidden metadata is ignored by validation and remains stored; details and list source labels now use the current classification. Reclassification updates the compatibility category and contributor role while keeping record IDs and attachment relationships in the existing archive structure.
- Schema/database: No schema change or migration was needed; existing `publication_metadata_json`, `authors_json`, `application_settings`, and contributor relationship storage are reused. No database rows or attachments were modified. The read-only diagnostic reported the local `rms_db` target and 3 publication records.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S9 | Assistant, PHP lint | Lint the changed publication classification/form/detail/archive/list/admin files and diagnostic script | All PHP files parse | All seven `php -l` commands reported no syntax errors | Passed |
| S10 | Assistant, `node --check js/publication-classification.js` | Check dynamic visibility script syntax | No syntax errors | Command completed with no diagnostic output | Passed |
| S11 | Assistant, `php scripts/check-publication-archive.php` | Run read-only schema and in-memory publication regression checks | Review Article optionality, hidden metadata preservation, Legacy rejection, contributor roles, all 15 active subtypes, and schema projections pass | 89 validation assertions and 11 nonlocking SELECT preparations passed; 3 live publication rows observed. Script used a read-only transaction and rolled it back; no records changed. | Passed |
| B10 | User-operated browser, Faculty | Open Publication Records > Add Publication | Add form opens and the Publication Type list contains the five active types but no Legacy option | Awaiting user observation | Pending |
| B11 | User-operated browser, Faculty or authorized Admin | Select Journal Article, then Review Article | Review title, authors, year, journal details, abstract, keywords, and publication evidence appear; conference/book/chapter/monograph fields remain hidden; DOI remains optional | Not started; depends on B10 | Pending |
| B12 | User-operated browser, authorized user | Open an existing historical Legacy record for editing | Existing classification and record remain readable; supported active reclassification is available; Legacy is not offered as a new selection | Not started; requires selecting an existing suitable record | Pending |

## Resume / handoff

- Last user-confirmed state: No browser observation has been provided for this follow-up.
- Next pending action: Perform B10 only. Use the Faculty account and open Publication Records > Add Publication; confirm whether Legacy appears in the Publication Type list.
- Remaining verification gaps: B10-B12 and the existing report's prior pending checks remain open; role boundaries, direct attachment routes, all subtype layouts, report/export behavior, and Research Proposal/Student Research/Copyright regressions have not been confirmed in this follow-up.
- User-performed data changes and cleanup status: None reported. No new DB changes or record creation occurred.

## Follow-up: publication classification confirmation dialog

- Request: Replace the browser-native “localhost says” confirmation with a clear RMS-styled notification for type changes, subtype changes, and hidden-field save confirmation.
- Graphify terms and query: `publication`, `classification`, `confirmation`, `dialog`, `form`; `rtk graphify query "publication classification confirmation dialog form" --budget 1200` returned older publication form nodes and an unrelated public-site modal. It did not include `js/publication-classification.js`; current form source was checked directly.
- Changes: Added a reusable accessible HTML dialog with a clear title/body, action-specific buttons, focus restoration, backdrop/Escape cancellation, and reduced-motion/mobile styling. Type changes say “Change type”; hidden-field saves say “Save publication” or “Save changes” as appropriate. A native confirmation remains only as a fallback where `dialog.showModal()` is unsupported.

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S14 | Assistant, `node --check js/publication-classification.js` | Check dialog script syntax | No syntax errors | Command completed with no diagnostic output | Passed |
| S15 | Assistant, CSS delimiter check | Count braces in `css/publication-archive.css` | Opening/closing brace counts match | 70 opening and 70 closing braces | Passed |
| B13 | User-operated browser, Faculty | On Add Publication, select a different type after an initial type is selected | RMS dialog appears instead of browser “localhost says”; title and action are clear; Cancel/Escape keep the current type and restore focus | Awaiting user observation | Pending |

## Resume / handoff

- Last user-confirmed state: No browser observation for the custom dialog has been provided.
- Next action: Continue with the pending Faculty Add Publication check; first confirm the active type choices, then make one type change to open the new dialog and report what appears.
- Remaining gaps: Browser appearance, responsive sizing, keyboard cancellation, and restored focus are unverified. No application data changes were made.
