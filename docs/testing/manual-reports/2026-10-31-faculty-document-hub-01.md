# Verification: faculty document hub

- Date/time: 2026-10-31 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Faculty reaches a separate Document Center sidebar group, selects one of their research records in DOCX Generator, reviews mappings and prepares documents through existing pages, and sees only that record's generated history.
- Environment URL: Configured RMS local application; not opened by the assistant.
- Roles and disposable records/files: Faculty account and user-selected research records; no data changed by assistant.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Trace how faculty research records lead to EARIST document preparation and generated history. Selected matched terms: `faculty-shell`, `Generated document review`, and `faculty-generated-documents`.
- Query/lookup and result: `graphify query faculty-generated-documents earistDocumentFacultyHistoryList facultyRecordLoadOwned earistDocumentFacultyReview --budget 1500` returned role-shell/documentation nodes but no current mapping/hub/history-function nodes. After the navigation change, `graphify query faculty-shell faculty-record faculty-document --budget 1200` returned the faculty shell node and its basic relationships; current navigation details were confirmed from source.
- Relevant source locations and verified relationships: Current `faculty-research-record-detail.php` links to `faculty-document-mapping.php`; mapping links to `faculty-document-generate.php`; `faculty-generated-documents.php` calls the existing faculty history reader. Current source in `includes/faculty-research-records.php` provides the faculty-only actor and owner-scoped record loader. `includes/earist-document-service.php` permits history reads for soft-deleted records while preparation rejects them.
- Missing, inferred, stale, or truncated context: Graphify does not represent the current document page/service relationships and appears stale for these paths. The route and ownership edges above were confirmed directly from current source. No application behavior was inferred from graph edges.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A0 | Integrated browser / Faculty / sidebar | Inspect the faculty navigation | Research Records contains only My Research Records; Document Center contains DOCX Generator and Generated Documents | User screenshot showed Generated Documents still under Research Records before this fix; retest pending | Pending |
| A1 | Integrated browser / Faculty / DOCX Generator | Open the new entry page, choose a record, and open its EARIST Documents hub | Only owned records are offered; hub shows the chosen title, enabled faculty forms, and only its generated versions | Awaiting user observation | Pending |
| A2 | Integrated browser / Faculty / document hub | Open a faculty form and follow Review Document Fields | Existing mapping page opens; its back link returns to the same record's hub | Awaiting user observation | Pending |
| A3 | Integrated browser / Faculty / different account | Open the hub route with another faculty member's record ID | Existing not-found behavior; no record title or history is exposed | Awaiting user observation | Pending |
| A4 | Integrated browser / Faculty / deleted and archived records | Open each record's hub | Deleted record shows history but disables preparation; archived record retains the existing preparation path | Awaiting user observation | Pending |
| A5 | Integrated browser / Faculty / generation and history | Complete the existing review/generate flow, then open the faculty-wide Generated Documents page | Successful generation returns to the originating hub with the new version; global history remains available and spans records | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- User observed that Generated Documents was still nested under Research Records in the faculty sidebar (screenshot supplied in chat). The user asked for a separate Document Center and authorized implementation. The sidebar now places DOCX Generator and Generated Documents in that group; browser retest is pending.

## Assistant-run checks
- `php -l pages/faculty/faculty-document-hub.php` — passed.
- `php -l pages/faculty/faculty-research-record-detail.php` — passed.
- `php -l pages/faculty/faculty-document-mapping.php` — passed.
- `php -l pages/faculty/faculty-document-generate.php` — passed.
- `php -l pages/faculty/faculty-generated-documents.php` — passed.
- `php -l includes/earist-document-generation.php` — passed.
- `php -l pages/faculty/faculty-document-workspace.php` — passed.
- `php -l includes/faculty-shell.php` — passed after the navigation change.
- `php -l pages/faculty/faculty-document-hub.php`, `php -l pages/faculty/faculty-document-mapping.php`, `php -l pages/faculty/faculty-document-generate.php`, and `php -l pages/faculty/faculty-generated-documents.php` — passed after their navigation updates.
- `git diff --check` on the changed tracked PHP/CSS files — passed.
- Counted braces in `css/faculty-research-records.css` — balanced (155 opening / 155 closing).
- Confirmed the existing all-record history route still calls the optional-record-filter service without a record ID; the hub passes the owner-loaded record ID.
- No database changes. Browser actions and generated records have not been performed.

## Resume / handoff
- Last user-confirmed state: Faculty sidebar showed Generated Documents nested under Research Records before the navigation change.
- Next pending action: User opens the faculty sidebar and checks the new Document Center group (A0).
- Blocked/skipped checks and remaining verification gaps: Browser rendering, pagination, ownership boundary, deleted/archived behavior, and end-to-end generation return remain pending.
- User-performed data changes and cleanup status: None.
