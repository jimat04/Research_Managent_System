# Verification: archival records

- Date: 2026-10-31, Asia/Taipei
- Assistant: Codex; RMS workflow/page-builder guidance applied locally (custom specialist invocation unavailable).
- Target: existing Copyright Applications and Publication Records; direct archival saves, faculty ownership, admin corrections, supporting documents, audit history and reporting.
- Environment: local `rms_db`; application URL to confirm in user browser.
- Session state: implementation/static review in progress; browser checks pending.
- Roles: admin, record-owning faculty, a second faculty account, explicitly permissioned staff. No credentials collected.
- Disposable records/files: none created; user to create uniquely named records through supported forms.

## Graphify and source context

- Question: which existing pages, shared helpers and downstream reports enforce publication/copyright review, ownership and document access?
- Actual graph vocabulary: `copyright`, `publication`, `copyrights`.
- `rtk proxy graphify query "copyright publication" --budget 1500`: 24 nodes, 21 edges; complete graph response approximately 1569 tokens, no edges dropped.
- Relevant hints: `pages/shared/publications.php:L1`, `publicationsRun():L21`; `pages/shared/copyrights.php:L1`, `copyrightsRun():L21`, adjacent list closures.
- Confirmed against current forms/detail pages, `includes/output-contributors.php`, document download route, module launcher, reports and FacultyDashboardService.
- Existing map is stale: removed E2E suite nodes and old form/list functions remain; new archive helpers are absent. Follow-up lookup after edits reconfirmed old entry nodes; its console output was truncated. Targeted current-source tracing is authoritative. No graph regeneration or installation performed.

## Assistant-run checks

| ID | Method | Expected | Actual evidence | Status |
|---|---|---|---|---|
| A01 | Backup source/dump comparison | Complete recoverable local backup | 59 tables, per-table row counts, dump completion marker, SHA-256, 30 supporting-file hashes | Passed |
| A02 | Isolated restore | Restored tables match source inventory | All 59 table row counts/data hashes match; CHECK TABLE OK; supporting files match | Passed |
| A03 | Migration runner | Additive metadata only | 054 and 055 reported APPLIED through scripts/migrate.php | Passed |
| A04 | Read-only post-migration comparison | Original values/history unchanged | Initial comparison passed after migration. Later repeat found post-backup changes; see A08. | Partial |
| A05 | PHP syntax | Changed PHP parses | 25 affected PHP files: php -l passed | Passed |
| A06 | JavaScript syntax | New progressive enhancement parses | node --check js/archival-records.js exit 0 | Passed |
| A09 | Archive SQL source check | Archive queries and schema compile; legacy/archive distinctions hold | 27 literal archive SQL statements prepared; archive schema queries passed; 7 status/type distinction checks passed | Passed |
| A10 | Graphify follow-up | Check dashboard/profile/contributor access after scope edits | `student-shell.php archival-record-ui student copyright shell`: 94 nodes, 26 shown/68 truncated. Graph omits the archive shell helper. Confirmed current route/shell behavior against source. | Partial |
| A11 | Graphify follow-up | Check Student list/export route after role-scope edit | `archival-record-list student report export permission`: 102 nodes, 24 shown/78 truncated, mostly unrelated. Graph has no useful archive-list edges; confirmed the new guard and controls against current source. | Partial |
| A12 | Graphify follow-up | Check shared-router export denial after observed CSV download | `archival-record-page student report export`: 127 nodes, 17 shown/110 truncated, mostly unrelated. Graph has no useful archive-route edge; source gate and PHP syntax were checked. | Partial |
| A13 | Graphify follow-up | Trace Faculty sidebar to scoped IP archive pages | `faculty-shell copyrights ip_scope patents innovation`: 35 nodes, 26 shown/9 truncated. Confirmed the sidebar URLs and legacy `ip_scope` route against source. | Partial |
| A14 | Graphify/source follow-up | Trace scoped IP list/form/document dependencies and permissions | Query `copyrights.php copyright-form.php download-research-document.php`: 78 nodes, 51 shown/27 truncated; map includes legacy E2E nodes. Current source confirms explicit `ip_scope` and record-type routing through `archival-copyright-route.php`, scoped type lists and staff permissions through `archival-records.php` / `archival-record-list.php`, type-specific fields in archive form/detail, and archive permission checks in the document route. | Partial |
| A15 | PHP syntax and whitespace | Changed PHP parses and focused diff has no whitespace errors | Seven changed PHP files passed `php -l`; targeted `git diff --check` returned no whitespace errors. | Passed |
| A08 | Read-only repeat comparison against isolated restore | Identify changes since backup without modifying either database | Differences: 6 appended `activity_log` rows; 2 updated user rows (`last_login`, `updated_at`, `academic_rank`); 1 updated faculty research record (`version`, `updated_at`); 2 updated author links (`position`, `updated_at`). No rows were added or removed in these tables; prior audit entries remain. The checker did not change data; the source of the application-data edits is unverified. | Partial |
| A07 | Final diff/security review | No unintended edits or permission expansion | Read-only ledger confirmed 043 was pending; its backfill/verifier requirement were removed before execution, and no rows changed. Reviewed owner/contributor role gates, staff permission scopes, CSRF/optimistic locking, archive-only routes, protected file access, Student shell and report/export guards. Linked students are view/download only. Focused `git diff --check` found no whitespace errors. | Passed |

## Browser checks (user-operated only)

| ID | Role / scope | Expected | User observation | Status |
|---|---|---|---|---|
| B01 | Admin / Publication Records list | Existing records visible; Add record and View/Edit/Documents/History; no approval controls | User: “Expected archive list and controls appear” | Passed |
| B02a | Admin / add publication form | Search owner, required faculty owner, separate authors, supporting PDF and Save record are shown | User: “All expected fields appear” | Passed |
| B02b | Admin / owner selector | Search narrows options to matching faculty account | User: “Matching faculty options appear” | Passed |
| B02c | Admin / Copyright Applications list | Existing archive records and Add record control; no approval controls | User: “Expected list and Add record appear” | Passed |
| B02d | Admin / add copyright form | Search/select faculty owner; independent actual creators; external status; supporting PDF; Save record | User: “All expected fields appear” | Passed |
| B03a | Admin / publication edit form | Current owner, authors/creators, metadata and file reference load; no save | User: “Existing values appear; I did not save” | Passed |
| B03b | Admin / copyright edit form | Current owner, authors/creators, external status, metadata and file reference load; no save | User: “Existing values appear; I did not save” | Passed |
| B03c | Admin / edit persistence and audit | Save a disposable edit and verify history/modified-by fields | Not run: no disposable record was created or authorized for mutation | Blocked |
| B04 | Admin / ownership correction | Explicit, unchecked confirmation control; authored accounts independent | User: “Checkbox is visible and unchecked” | Passed |
| B05a | Faculty / own archive list | Own and shared records; Add record; no approval controls | User: “Expected list appears” | Passed |
| B05b | Student / linked author access | Only explicitly linked archive records; no Add record | User: “Linked-only list appears, or list is empty; no Add record” | Passed |
| B05c | Student / unlinked direct route | A record absent from the student's list is denied without details/documents | User: “Access is denied/not found” | Passed |
| B05d | Student / add-form direct route | Student cannot open archive form or create records | User: “Access is denied” | Passed |
| B05e | Student / Copyright Applications list | Student shell; only explicitly linked archive records; no Add record | User: “my student account access the staff” — URL opens with Staff shell; source confirms `archiveShellOpen()` routes Student to `renderStaffShell()`. Record scope/Add control not confirmed. | Failed |
| B05f | Student / Copyright Applications controls | No Reports or CSV export controls; direct Student report/export is denied | User asks whether Student should have Reports/Download CSV; current source showed both controls for Student. | Failed |
| B05g | Student / Copyright Applications shell retest | Student navigation shell; linked-only/empty list; no Add record, Reports or CSV | User: “Student shell and linked-only/empty list; no controls” | Passed |
| B05h | Student / direct report URL | `report=1` request denied without report data | User: “Access denied; no report data” | Passed |
| B05i | Student / direct CSV URL | `export=csv` request denied; no CSV is returned | User: “CSV downloads” — failed despite list-level guard; added a shared-router guard for retest. | Failed |
| B05j | Student / direct CSV URL retest | `export=csv` request denied before list queries; no CSV returned | User: “Access denied; no CSV returned” | Passed |
| B05k | Student / copyright form direct route | Student cannot open copyright archive form or create records | User: “Access is denied” | Passed |
| B05l | Student / unlinked copyright direct route | Copyright record absent from Student list is denied without details/documents | User: “Access is denied/not found” | Passed |
| B05p | Faculty / create/edit persistence | Save disposable archive entries and verify persistence, ownership and audit without approval flow | Not run: no disposable record was created or authorized for mutation | Blocked |
| B06a | Faculty / unlinked publication direct route | A record owned by another Faculty account is denied without details/documents | User: “Access is denied/not found” | Passed |
| B06 | Faculty / unauthorized IDs and documents | Other owners' forms/files denied; linked authors get view access only | None yet | Pending |
| B05m | Faculty / add publication form | Own account is owner; independent authors/creators; PDF; Save; no approval controls | User: “Expected fields; no approval controls” | Passed |
| B05n | Faculty / add copyright form | Own account is owner; independent authors/creators; external status; PDF; Save; no approval controls | User: “Expected fields; no approval controls” | Passed |
| B06b | Faculty / unlinked copyright direct route | A copyright record owned by another Faculty account is denied without details/documents | User: “Access is denied/not found” | Passed |
| B07a | Admin / existing PDF preview | Existing archive PDF opens in protected built-in viewer | User: “PDF previews successfully” | Passed |
| B07 | Upload/replacement/download | Valid PDFs persist; old reference remains; invalid content rejected; history preview selects correct version | Preview passed; upload/replacement needs a disposable record, none created or authorized for mutation | Blocked |
| B09a | Admin / publication report | Unique totals and groups by year, owner, program, type and indexing | User: “Expected report summaries appear” | Passed |
| B09b | Admin / copyright report | Unique totals and groups by year, owner, program, type and external outcome; legacy registered distinction retained | User: “Expected report summaries appear” | Passed |
| B09c | Admin / CSV export | CSV downloads with archive metadata and current filters applied | User: “CSV downloads with expected columns” | Passed |
| B09d | Admin / year filter | Matching year narrows records and unique totals consistently | User: “Matching records and totals appear” | Passed |
| B09e | Admin / record-type filter | Selecting a type narrows list and unique totals consistently | User: “Matching records and totals appear” | Passed |
| B08 | Invalid input/concurrency/CSRF/retired actions | Invalid dates/type/owner rejected; stale edits rejected; approval actions cannot execute | None yet | Pending |
| B09 | Reports/search/filters | Scoped records; unique totals; year/owner/program/type/indexing/external outcomes; CSV/print | Publication report groupings, year/type filters, Copyright report groupings and Admin CSV columns passed; owner/program/external filters and print remain untested. | Partial |
| B10 | Admin / deactivate/restore | Audit retained; inactive records excluded from active lists and restored explicitly | Not run: would mutate an existing record; no disposable record was created or authorized for mutation | Blocked |
| B11 | Explicitly permissioned staff | Existing page/action/download grants enforced | None yet | Pending |
| B12 | Admin module launcher | Affected journal, conference, repository, copyrights and research-output destinations open | None yet | Pending |
| B12a | Faculty navigation | Copyright Applications, Patents / Utility Models, Innovation & Technology Transfer entries visible under My Research | None yet | Pending |
| B12b | Faculty / Patents scope | Sidebar entry opens the archive-only Patents / Utility Models list with only patent and utility-model records and an Add record action | None yet | Pending |
| B12c | Faculty / Innovation scope | Sidebar entry opens the archive-only Innovation & Technology Transfer list with only innovation and technology-transfer records and an Add record action | None yet | Pending |
| B13 | Unrelated regression | Student/faculty proposals, reviews, DOCX/Annexes, capacity building, authentication unchanged | No browser observations | Pending |
| B14 | Responsive/accessibility | Existing shells/branding, readable mobile layout, keyboard labels/focus | None yet | Pending |

## Backup and database evidence

- Backup: `backups/archive-20261031-074439-96f219/rms_db.sql` (1,572,329 bytes).
- SHA-256: `01a8a8c0585e4340f72e48bc2e7ddefcf609a2e4d7c9a591ed9f371c12ef9033`.
- Private verification/inventory manifests and supporting file copies are beside the dump; backups are Git-ignored and HTTP-denied.
- User explicitly authorized isolated restore and requested retention. Verified database: `rms_archive_restore_20261031_074647_8bab24`.
- First restore attempt: initial overbroad SQL-target check rejected prose; corrected to statement boundaries. Subsequent multi_query attempt exceeded packet size and stopped. Its database `rms_archive_restore_20261031_074636_835abb` is retained. MySQL client restore then passed.
- Active app database configuration was not changed. Only approved migrations 054/055 were applied to local rms_db; no resets, account changes or application records created.
- Live inspection: 3 publications, 2 copyright/IP records. One publication and two copyright/IP rows have non-faculty/missing/deleted ownership needing review; no owner was invented.
- Migration 043 was absent from the local ledger. Its owner-to-contributor backfill and corresponding verifier requirement were removed before any execution. Migrations 054/055 were applied; 055 created the contributor tables without inserting inferred authors. No contributor or publication/copyright rows were changed by that source adjustment.
- No approval/rejection history or prior notifications deleted; legacy status/timestamps retained. New archival status defaults to legacy for old rows; new handler inserts archived explicitly.

## Failures, decisions, fixes, and retests

- Backup rehearsal technical failures and successful retest recorded above. Browser failures B05e/B05f (Student saw Staff shell/Reports/CSV) and B05i (Student CSV downloaded) are preserved; fixes were followed by passing B05g/B05h/B05j checks. Student direct route/form boundaries and Faculty unlinked-record denials passed.
- RTK's filtered git command failed due to its missing Claude config directory; `rtk proxy git` worked. A nested PowerShell lint loop expanded its variable too early and checked no files; replaced with a Python subprocess loop, which actually linted 23 files successfully.
- `npm run build` reports that no build process is configured; PHP and JavaScript syntax checks were run directly.

## Resume / handoff

- Last user-confirmed state: Admin publication type filter narrowed to matching records with consistent totals. Faculty navigation has separate Copyright Applications, Patents / Utility Models and Innovation & Technology Transfer links. Source refactor now routes the two scoped IP links into archive-only Add record lists while preserving each existing record type and permission scope.
- Next pending action: user verifies the Faculty sidebar entries and opens each scoped IP list to confirm its archive title, scoped records and Add record control.
- No scripted application interactions, fabricated sessions, direct API writes or seeded records used.
- No user-performed record changes or cleanup yet. Both restore databases retained; deletion requires permission.
