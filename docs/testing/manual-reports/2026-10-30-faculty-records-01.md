# Verification: faculty research records

- Date: 2026-10-30, Asia/Taipei
- Assistant: Codex
- Scope: approved independent faculty record management; no document generation or student workflow changes.
- Environment: local development `rms_db`; configured SITE_URL `http://localhost/rms/` read from configuration. Actual browser availability/login state still to be confirmed by user.
- Session state: implementation and assistant checks complete; user completed the requested browser checklist and three follow-up retests. Supplemental checks remain pending where noted.
- User performs every application interaction. No scripted sessions, API mutations, seeded data, or browser automation.

## Graphify and source context

- Question: which faculty shell, profile, publication, and authentication components can be reused without entering the student submission workflow?
- Selected actual vocabulary: faculty, profile, publication, author, settings.
- Lookup: `graphify query 'faculty profile publication author settings' --budget 1100` (existing graph, read-only).
- Result: 23 nodes / 18 edges; CLI emitted the complete answer over its requested budget. No relationships were dropped.
- Source-confirmed: includes/faculty-shell.php renders navigation; pages/shared/profile.php maintains account data; pages/shared/publications.php owns a different publication lifecycle. includes/auth.php:getCurrentUser omits document-profile fields; requireRole includes an administrator bypass.
- Limitations: graph locations for shell helpers are stale; new faculty-record nodes are absent. Current source is authoritative. Graph was not rebuilt.
- Final focused lookup after implementation: `graphify query 'faculty' --budget 1500`, using the existing `faculty` vocabulary. Seven nodes and five edges returned without truncation; shell/dashboard dependencies confirmed against source. New module remains outside graph coverage and was reviewed directly.

## Assistant checks

| ID | Check | Expected | Observed | Status |
|---|---|---|---|---|
| S01 | Local target / migration ledger | Local development rms_db; 049 applied; 050 not yet applied | PHP preflight confirmed local target, applied 049 with matching checksum, absent 050 and new tables | Passed |
| S02 | Backup | Protected, ignored local dump; nonzero size and SHA-256; successful dump exit | backups/faculty-records-050-2026-10-30-22ef74b1.sql.backup; 645957 bytes; SHA-256 1330bdaffb888297d6f981088d2829e318c2e2a75f7959c0ad9ff46483e49125; exit 0, completion footer present, backups/.htaccess denies access | Passed |
| S03 | Migration 050 | Three tables; registered verifier passes; original table checksums unchanged | Runner returned APPLIED 050_faculty_research_records.sql; full registered schema verifier passed; ledger applied/executed; all three new tables empty; all 53 pre-existing data-table checksums unchanged (ledger excluded); 049 SHA-256 unchanged | Passed |
| S04 | PHP / JavaScript syntax | Changed PHP and new JS parse | php -l passed for all 8 PHP files in scope; node --check passed for js/faculty-research-records.js | Passed |
| S05 | Focused validation checks | Required/optional fields, bounds, dates, authors, snapshots, SDGs and browse options | Initial scripts/check-faculty-research-records.php run: 42 passed, 0 failed; expanded lead/order and page-size retest pending | Passed; see S10 |
| S06 | Initial final-diff whitespace check | No whitespace errors | git diff --check reported one extra EOF blank line in database/schema/rms_db.sql | Failed |
| S06-R1 | Approved formatting correction / retest | Remove only trailing blank line and rerun check | User explicitly chose Fix the formatting issue now; original bytes retained except trailing blank line. git diff --check now passes | Passed |
| S07 | Live, read-only query checks | Queries compile and execute; nonexistent owner/record does not load data | 24 status/sort/search combinations executed in a READ ONLY transaction using owner 0; all returned empty results, count 0, page 1. Nonexistent owner/ID loader returned null; 3-table availability confirmed. Transaction rolled back; no sessions or mutations. This does not prove runtime cross-account access control | Passed |
| S08 | Final source/schema/documentation review | No unrelated changes or unsafe cross-module data access | Reviewed strict live faculty guard on all 3 routes; owner-scoped parent/child loaders and list; CSRF in both POST handlers; version/owner locks in writes; delete confirmation; fixed-field prepared queries; escaped output; transactions/audit; child reconciliation scoped to locked parent. No writes to users/student projects, no workflow calls, no generation routes. Existing worktree edits preserved | Passed |
| S09 | Canonical definitions / artifacts / final ledger | Matching schema, valid local doc links, clean new files, matching applied checksum | Exact 050 SQL found in canonical schema; 3 CREATE TABLE definitions; all 11 new files have no trailing whitespace; documentation links exist; CSS braces balanced. Final applied/executed ledger checksum matches 050 file | Passed |
| S10 | Approved feedback changes: syntax and focused validation | Changed PHP/JS parses; first-author invariant and 10-record page size checks pass | php -l passed for the helper, form and list; node --check passed for the JavaScript; scripts/check-faculty-research-records.php: 44 passed, 0 failed. No database connection or browser interaction | Passed |

## Manual browser checks

The user has reported results for M01-M20 and the three follow-up retests; observations are recorded below. Use only authorized accounts and user-created disposable records. Cleanup is user-operated through supported actions; no automatic deletion or reset. When guiding further testing, give one browser action, its role/page/expected result and a specific confirmation question, then wait before recording it.

Prerequisites: existing authorized Faculty A and Faculty B accounts (B can be an internal co-author), an authorized student account for regression, and optional staff/admin accounts for role-boundary checks. Missing accounts or records make dependent checks Blocked; do not create accounts or bypass permissions. Never send credentials, cookies, CSRF tokens, or private record contents in chat.

Use a unique disposable title prefix such as `FRR-QA-20261030-` so records created during this session can be identified. These are user-entered test records, not seeded/live research claims. Default configured list route: `http://localhost/rms/pages/faculty/faculty-research-records.php`; confirm the actual site URL/login state with the user before starting.

| ID | Role / page | Planned action | Expected outcome | Observation | Status |
|---|---|---|---|---|---|
| M01 | Faculty A / My Research Records | Open the faculty navigation entry | Active list opens; Add Research Record is available; useful empty state if no records exist | User confirmed seeing the Active list and Add Research Record button | Passed |
| M02 | Faculty A / Add | Open Add Research Record | Five sections; profile-suggested author; no preselected role | User reported pass | Passed |
| M03 | Faculty A / Add | Enter and save the required title, type and author; leave optional sections blank | Required details save while optional sections can remain blank | User reported pass | Passed |
| M04 | Faculty A / Add | Save a minimal record | Detail page opens with a new internal ID | User reported pass | Passed |
| M05 | Faculty A / detail | Reload the saved detail | Saved title and author remain | User reported pass | Passed |
| M06 | Faculty A / Add | Create records 2 through 4, including a duplicate title | No three-record limit; repeated titles receive separate IDs | User reported pass | Passed |
| M07 | Faculty A / Edit | Change one record and save | Only the selected record changes | User reported pass | Passed |
| M08 | Faculty A / Edit | Search for Faculty B, select the result and add the author | Registered faculty can be added from search | User confirmed author addition works. Enter initially did not launch search; user reported follow-up check 2 passed after the fix | Passed after retest; see R3 |
| M09 | Faculty A / Edit | Set B as Lead and A as Co-Author | Owner need not be the lead | User reported pass | Passed |
| M10 | Faculty A / Edit | Add an external co-author and enter details | External author needs no RMS account | User reported pass | Passed |
| M11 | Faculty A / Edit | Reorder authors | Position 1 is Lead Author and remaining authors are Co-Authors | User confirmed M11 passed after the fix: moving author 2 up makes that author position 1 and Lead Author. This retest did not request saving; persistence of the changed roles has not been separately retested | Passed (UI); see R1 |
| M12 | Faculty A / Edit | Select SDGs and enter optional Others text | Multiple SDGs save and persist | User reported pass | Passed |
| M13 | Faculty A / Edit | Enter conference title, host, organizer, venue, classification, dates and modality | Separate conference fields persist | User reported pass | Passed |
| M14 | Faculty A / Edit | Enter target journal and publisher | Journal information persists alongside conference information | User reported pass | Passed |
| M15 | Faculty A / Edit then detail | Save and reopen the expanded record | Authors, order, SDGs, conference and journal persist under the same ID | User reported pass | Passed |
| M16 | Faculty A / another record detail | Open another record | It retains independent information | User reported pass | Passed |
| M17 | Faculty A / list | Search by saved author name | Matching records appear once each | User reported pass | Passed |
| M18 | Faculty A / list | Search identical titles | Both independent records appear | User reported pass | Passed |
| M19 | Faculty A / list | Apply each sort option | Each selected order works | User reported pass | Passed |
| M20 | Faculty A / Active list | Archive a record | It leaves Active and appears under Archived | User reported pass | Passed |
| M21 | Faculty A / Archived | Restore the record | Same ID and content return to Active | User reported pass | Passed |
| M22 | Faculty A / Delete confirmation | Open Delete then cancel | Record stays in its prior state | User reported the original flow worked, but Delete did not change to Cancel. User reported follow-up check 1 passed after the fix | Passed after retest; see R2 |
| M23 | Faculty A / Delete confirmation | Confirm recoverable deletion | Record moves to Deleted / Recoverable with information retained | User reports delete works; retest after confirmation-label fix pending | Passed; see R2 |
| M24 | Faculty A / Deleted | Restore the record | Original ID and details return to Active | User reported pass | Passed |
| M25 | Faculty A / Archived then Deleted | Recover an archived record | It returns to Archived and can then be restored to Active | User reported pass | Passed |
| M26 | Faculty A / two edit tabs | Save one tab, then attempt to save older content from the other | Stale save is rejected; submitted input remains visible; latest saved version is not overwritten | Not performed | Pending |
| M27 | Faculty B / direct detail route | Open A record URL while signed in as B, including if B is a co-author | Generic unavailable response without record details | User reports generic Record unavailable text and a return link; no contents were shown | Passed |
| M28 | Faculty B / direct edit route | Open A edit URL | Record cannot be edited by B | Not separately reported | Pending |
| M29 | Faculty B / list and status filters | Search for A's disposable record title across Active/Archived/Deleted | A's records never appear; co-authorship grants no visibility or mutation rights | Not performed | Pending |
| M30 | Student, staff, admin / direct module routes | Open the list/form/detail routes under each available non-faculty account | Access denied; no faculty document generator or Admin reader introduced | Not performed | Pending |
| M31 | Faculty A / Add or Edit | Try missing required data, reversed dates, no/multiple leads, duplicate authors and overlong fields separately | Helpful validation; failed save does not alter stored record; entered values retained where server validation runs | Not performed | Pending |
| M32 | Faculty A / save | Attempt repeated save clicks and ordinary back/retry behavior | One creation per Add form token; creating a fresh Add form still permits an identical title | Not performed | Pending |
| M33 | Faculty A / list | Check the records page count | 10 records per page, with filters/search/sort retained | User reported pagination worked but requested 10 per page; user reported follow-up check 3 passed after the change | Passed after retest; see R4 |
| M34 | Faculty A / mobile and keyboard | Check narrow viewport and keyboard use | Layout and controls remain usable | User reported pass; registered-author Enter search is tracked under M08/R3 | Passed with M08 exception |
| M35 | Faculty A / form with JavaScript disabled, if practical | Use server-rendered author search/add/order controls and save | Input retained across author-editor reloads; only Save persists; deletion still requires explicit checkbox confirmation | Not performed | Pending |
| M36 | Student / normal Student Research pages | Open My Research and available existing draft/submitted/chapter views | Existing records and title/chapter workflow remain intact; no faculty-record actions or entries appear | Not performed | Pending |
| M37 | Faculty A / saved author snapshot | If desired, change only the owner's profile through the existing Account Profile UI, then view the disposable research record | Saved author snapshot does not silently change; account edit is optional and user-authorized, never performed by assistant | Not performed; optional account change | Pending |

M33 can remain Pending/Blocked until sufficient records exist. M35 and M37 may be Skipped at the user's choice with reasons recorded. No unperformed check is Passed.

## Findings and retests

- S06: final whitespace check found a trailing blank line only. User approved the formatting correction; S06-R1 passed. Original failure retained above.
- Graph lookup for this follow-up: selected existing `faculty` and `author` vocabulary; `graphify query 'faculty author' --budget 1500` returned 19 nodes/related edges. The CLI warned that the complete answer exceeded the requested budget and dropped no relationships. Confirmed faculty-shell relationships against source; the new module remains outside graph coverage.
- User-reported M01–M20 batch: M01–M07, M09–M10, M12–M21 and M24–M25 passed. M08, M11, M22–M23, M27 and M33 carry the specific observations in the table. Items M28–M30 and M35–M37 remain pending unless separately reported. No browser actions were performed by the assistant.
- R1 — original partial finding: reordering worked, but position 1 was not automatically made Lead Author. The fix makes position 1 Lead and every remaining author Co-Author after reordering; choosing Lead moves that author to the first position. Existing saved lead identity is preserved when opening legacy order in the edit form. Server validation requires one lead in first position. User subsequently reported "m11 passed", confirming the requested immediate UI behavior. Saving/reopening the changed roles was not part of this retest and remains unconfirmed.
- R2 — delete control: opening the delete confirmation now changes the top control to Cancel; clicking it closes the details. Confirmed deletion still uses its separate “Move to Deleted” button and existing confirmation checkbox/dialog. User reported follow-up check 1 passed.
- R3 — faculty search keyboard: user reports Enter in “Add a registered faculty author” did not start a search, while selecting a result worked. Enter now submits the Search faculty button rather than the first author-order control. User reported follow-up check 2 passed.
- R4 — page size: the user requested 10 per page. The server page size and visible range indicator now use 10 records. User reported follow-up check 3 passed.

## Follow-up checks from partial results

The user completed these three follow-ups from the items originally reported as partial. M11 was also subsequently confirmed passed.

| ID | Role / page | Action | Expected | Status |
|---|---|---|---|---|
| M15 retest | Faculty / Delete confirmation | Open Delete; confirm the control changes to Cancel; click Cancel | Confirmation closes and the record stays unchanged | Passed — user reported check 1 passed |
| M17 retest | Faculty / Edit, registered-author search | Type at least two characters and press Enter | Search results appear without activating author-order controls | Passed — user reported check 2 passed |
| M19 retest | Faculty / list with 11+ records | Check the page size and open the next page | 10 records per page; the next page shows the remaining records | Passed — user reported check 3 passed |
- One exploratory `rg` invocation used a Windows path glob that rg did not expand; rerun used `-g 'faculty-research-record*.php'` against pages/faculty and completed. No coverage relies on the failed search invocation.
- The user completed the requested browser checklist and reported the results recorded above. No application interaction was performed by the assistant. Supplemental checks not separately reported remain pending.

## Database effects and limitations

- Migration 050 created 3 empty tables and its ledger entry. No research records, accounts, author entries, SDGs, or sample data were inserted by the assistant.
- Migration 049 remained byte-identical (SHA-256 checked); all 53 existing data-table checksums matched immediately after migration. Only schema_migrations was excluded from that data comparison because 050 adds its authorized ledger entry.
- Backup metadata baseline: `backups/faculty-records-050-2026-10-30-22ef74b1.json`. Dump and metadata remain local, ignored, and under an HTTP-denied directory. Backup dump exit, size, completion footer and SHA-256 were checked; contents were not printed.
- No browser interactions performed by the assistant.
- A successful backup dump does not establish a tested restore; no restore rehearsal is authorized in this phase.
- The user reported that saving, editing, archiving, deletion/recovery, cross-account detail isolation, and Student Research worked in the first browser pass. Duplicate-click behavior and stale concurrent saves were not part of the user's checklist and remain unobserved.
- Single optional conference and single optional journal only. Multi-target migration path is documented, not implemented. Scholarly-work options are PHP-configurable without an Admin configuration UI.
- No DOCX/PDF generation, document history, Admin reader, Annex D permission change, account-registration change, or student workflow change.
- Core implementation/file inventory: [Faculty Research Records](../../FACULTY_RESEARCH_RECORDS.md). Future mappings: [FIELD-MAPPINGS](../../../templates/earist/blank/FIELD-MAPPINGS.md).
