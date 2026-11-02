# Verification: EARIST document field mapping

Phase 4 handoff reaffirmation (2026-10-30): the user confirmed the mapping functions they tested passed. Preserve the individually recorded observations below; this does not establish new per-role, JavaScript-disabled or Phase 2 supplemental results. Fresh Stage 4A assistant checks are recorded separately in `2026-10-30-earist-generation-01.md`.

- Date: 2026-10-30, Asia/Taipei
- Scope: approved Phase 3 read-only mapping, temporary document inputs and faculty review. No generation, downloads, history, migration, or database mutation.
- Assistant: Codex; implementation follows the local RMS page-builder definition (no specialist agent invocation).
- Session state: implementation and assistant checks complete; user reported P01, P02 and M01–M18 passed. Phase 3 browser checklist complete as reported. The user performed every application interaction.
- Environment: source in C:/xampp/htdocs/rms; non-secret SITE_URL setting confirms http://localhost/rms/. Browser availability/login state awaits user confirmation. No live database checks performed.

## Graphify and source context

- Question: which authenticated faculty profile/record helpers can supply independent document mappings?
- Selected actual vocabulary: faculty, template, getCurrentUser, author, document.
- Existing graph only: `graphify query 'faculty template' --budget 1500` returned four nodes without truncation. Broad `getCurrentUser faculty author document` traversal was truncated; narrowed `getCurrentUser` returned all 31 nodes and 53 edges over the requested budget. Direct adjacent-edge lookup confirmed its isLoggedIn relationship.
- Current source confirms includes/auth.php:getCurrentUser at line 75; graph line 17 is stale. facultyRecordRequireUser, facultyRecordProfile and facultyRecordLoadOwned in includes/faculty-research-records.php are the reusable strict faculty guard, live profile and owner-scoped loader.
- Graph coverage has no facultyRecord, rmsSetting or Annex nodes. New mapping dependencies must be reviewed directly. No graph installation/rebuild/update.

## Checks

| ID | Method | Expected | Observed | Status |
|---|---|---|---|---|
| S01 | Template inventory, CRC and XML analysis | Eight supplied blank DOCX packages parse; two C variants remain one type | Before implementation: all eight CRC checks and all contained XML/relationship parses passed. Full SHA-256 baseline retained for final comparison | Passed |
| S02 | Mapping semantic checks | Correct record data, classifications, role separation, input validation and reserved-field rejection | Final `C:/xampp/php/php.exe scripts/check-earist-document-mapping.php`: 129 passed, 0 failed. Earlier 124/126-check runs also passed before additional source/applicability checks. Pure arrays/policy only, no database/session/HTTP request | Passed |
| S03 | PHP syntax | All new/changed PHP parses | `php -l` passed for config/earist-documents.php, includes/earist-document-mapping.php, pages/faculty/faculty-document-mapping.php, pages/faculty/faculty-research-record-detail.php and scripts/check-earist-document-mapping.php. Final changed helper/check script were linted again | Passed |
| S04 | Structural bindings | Unique guarded destinations and unchanged package hashes | `python -X utf8 scripts/inspect-earist-templates.py`: 8 packages, 70 bindings, 0 failures. CRC, XML/rels, package and header/footer hashes, guards, A prechecks, QSF SDG 8/9 and F seven-row capacity checked. Repeated after refining B's repeat-row binding | Passed |
| S05 | Protected baseline | 049/050/051, schema, lifecycle helper and all eight DOCX files unchanged | All 13 SHA-256 values match the captured pre-implementation baseline | Passed |
| S06 | Phase 2 pure validation regression | Existing database-free checks pass | `C:/xampp/php/php.exe scripts/check-faculty-research-records.php`: 44 passed, 0 failed; no connection/application interaction | Passed |
| S07 | Final diff and access/data review | Only scoped changes; no writes, hidden generation, or weakened access | Reviewed the applied detail-link patch and scoped new files. Existing module/docs are untracked in this worktree, so ordinary git diff alone provides no coverage; targeted whitespace/source/link review supplements it. Static source confirms live-session/context checks, policy before retrieval, owner loader, deleted exclusion, read-only snapshot, allowlisted inputs, CSRF, no-store and escaped output. Runtime access remains Pending | Passed (static only) |

## Manual browser checks

Use authorized existing accounts and session-created disposable records. Do not send credentials, tokens or private record contents. Give one action, role/page, expected outcome and specific question, then wait. No automated application requests, fabricated sessions or database changes.

| ID | Role / page | Expected check | Observation | Status |
|---|---|---|---|---|
| P01 | Faculty A / My Research Records | Open http://localhost/rms/pages/faculty/faculty-research-records.php; report records list, login, denied or other result | User reported “My Research Records opens” | Passed |
| P02 | Faculty A / owned Active or Archived research record detail | Open one record through View; confirm Review Document Fields link appears | User reported P02 passed | Passed |
| M01 | Faculty A / own Active record detail | Review Document Fields link opens the selected record's mapping review | User reported M01 passed | Passed |
| M02 | Faculty A / review | Six faculty document choices; no Annex D choice; no generation/download or saved/completed claim | User reported M02 passed | Passed |
| M03 | Faculty A / review | Each document shows appropriate common values from that record; author snapshots/order preserved | User reported M03 passed | Passed |
| M04 | Faculty A / Annex A | Checklist answers initially unconfirmed despite template prechecks; temporary input remains after review | User reported M04 passed | Passed |
| M05 | Faculty A / Annex B | Contributions keyed to each saved author; invalid percentage reported without source mutation | User reported M05 passed | Passed |
| M06 | Faculty A / Annex C/E/F | Official fields remain blank/reserved; authors never become reviewer signatories | User reported M06 passed | Passed |
| M07 | Faculty A / QSF | Applicant and presenter require explicit choices; QSF conference classification not inferred | User reported M07 passed | Passed |
| M08 | Faculty A / QSF with SDG outside 8/9 | All source SDGs remain visible; only 8/9 map to printed destinations; warning lists unsupported selections | User reported M08 passed | Passed |
| M09 | Faculty A / Archived record | Owned Archived record remains a mapping source | User reported M09 passed | Passed |
| M10 | Faculty A / deleted record's previously noted review URL | Generic unavailable result until restored; no record contents | User reported M10 passed | Passed |
| M11 | Faculty B / A's review URL, including co-author B | Generic unavailable result without A's data | User reported M11 passed | Passed |
| M12 | Student/staff/admin / direct review URL | Access denied under each available authorized account | User reported M12 passed; individual non-faculty roles were not listed separately | Passed |
| M13 | Faculty / direct review URL with document=annex_d | Server denies Annex D; no selected record data shown | User reported M13 passed | Passed |
| M14 | Faculty / invalid temporary input | Helpful errors and retained entered values; no stored record changes | User reported M14 passed | Passed |
| M15 | Faculty / fresh GET after temporary review | Temporary answers absent; saved record data unchanged; unsaved notice remains | User reported M15 passed | Passed |
| M16 | Faculty / keyboard, narrow viewport, JS disabled | Selection, repeatable AI rows, inputs and review remain usable | User reported M16 passed; separate JavaScript-disabled detail was not supplied | Passed |
| M17 | Faculty / long title and multiple authors | Complete source values visible; layout warnings without truncation | User reported M17 passed | Passed |
| M18 | Faculty / two different records | Each review remains independent, including conference/journal/authors/SDGs | User reported M18 passed | Passed |

## Failures, decisions and retests

- Template analysis found Annex A's four documentary requirements checked despite older notes claiming they were cleared. User explicitly approved preserving the file and distinguishing its states from user-confirmed answers.
- User provisionally approved the Mabansag Annex C copy; both files remain primary references. Word visual verification is deferred to Phase 4.
- QSF prints SDG 8/9 only; preserve unsupported selections and warn. Annex F has seven blank signatory rows, not eight.
- CLI ZIP extension absent; DOM/libxml/mbstring available. No PHP configuration changes authorized. Apache extension state unverified.
- No implementation check failed. During source review, invalid source-value exclusion and conditional applicability were tightened before the final passing checks. No runtime behavior was inferred from these changes.

## Template hash baseline

| Reference | SHA-256 (unchanged) |
|---|---|
| Annex A | 4c0e2062c7ff1879f5e769be3111661a7e1ab64539fceda1b9b63ac12013633e |
| Annex B | ca45a507d7eec1069730789ad61dda72820573a6666b50aafaa0d42acd16bd8e |
| Annex C Liwanag | 0578ebcdea2e3c6ea02add8cbff16980e6a5fd9f5be1005bcc429557b6fe3888 |
| Annex C Mabansag | b2fecb925262dd95e359e32d165df79e5403d23fad248a3f4892b71502c62733 |
| Annex D | 8ed3a1aacdc7b2e8b3ebe4dbcb8046625ddcf1f37a01bf6166b31b82dc1f874b |
| Annex E | 51248e2603a9954c3c204f991301e7c09304b3e853ab1c44dc3b5ea5b90e6da3 |
| Annex F | e7715df21df0379386e43ff11093a5317d10363c96dd9ac4bb1eec8d395ac65d |
| QSF-RDS-019 | 379420f537b36324637c17855e20435b2f8305db26828b9da057b704cc70858e |

## Resume / limitations

- P01, P02 and M01–M18 were reported passed by the user. M12/M16 were reported as aggregate checklist results without per-role or JavaScript-disabled details. Assistant did not operate the browser or observe the screen independently; the results establish the user's observed outcomes only. Static review and pure checks remain separate. Phase 2's pending supplemental checks remain in its own report. No new database writes, document output or cleanup were performed by the assistant.
- Phase 2 release checks still pending: M26, M28-M32, M36, reordered-role save/reopen and final delete retest; M35/M37 supplemental.
- No database writes, schema/ledger checks, sample records, generated documents or automatic cleanup.
