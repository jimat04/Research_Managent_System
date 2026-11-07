# Verification: copyright faculty profile prefill

- Date/time: Started 2026-11-05; updated 2026-11-06 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: On the archival copyright/IP Add Record form, a faculty member's profile name is prefilled as an actual author/creator, and their RMS account is linked automatically as an author. Their account is omitted from the additional-author checkboxes.
- Environment URL: `http://localhost:8080/rms/pages/shared/copyright-form.php`
- Roles and disposable records/files: Faculty role; no record saved yet.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How does the archive form initialize its author text and linked RMS author options for a faculty owner? Selected graph terms: `archive`, `contributor`, `faculty`.
- Query/lookup and result: `graphify query archive contributor faculty --budget 1500`; 7 nodes returned around shared/research-archive helpers. It did not surface the archival form workflow.
- Relevant source locations and verified relationships: `includes/archival-record-page.php` loads the authenticated user and derives `$userId`; `includes/archival-record-form.php` initialized all contributors without excluding the faculty owner and left actual authors blank. On save it validates selected faculty IDs and calls `archiveSyncAuthors()`; the record owner is set to `$userId` for faculty. `includes/archival-records.php` `archiveSyncAuthors()` persists linked IDs.
- Missing, inferred, stale, or truncated context: Graph coverage for the archival form is missing/stale; current source was used directly. The graph query was not truncated.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | User-operated browser / Faculty / archival copyright form | Open Add Record and inspect Actual authors / creators and Linked RMS authors / creators | Actual authors contains the signed-in profile name; own account is absent from choices and noted as automatically linked; other faculty remain selectable | User confirmed “all passed” | Passed |
| 2 | User-operated browser / Faculty / archival copyright form | Enter a uniquely named disposable record and complete required fields without saving | Form is ready for submission with the prefilled faculty author retained | User confirmed the form is ready (“passed”) | Passed |
| 3 | User-operated browser / Faculty / archival copyright form | Click Save record | Record saves through the normal form and the detail page opens | User replied “all passed” to the save/result check | Passed |
| 4 | User-operated browser / Faculty / archival copyright detail page | Inspect Actual authors / creators and Linked RMS authors / creators | Saved detail shows the faculty account as a linked author | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- The initial investigation targeted the newer copyright application form, but the user's screenshot identified the archival Add Record form. The temporary change to `pages/shared/copyright-form.php` was reverted.
- Fix: new faculty archive records now prefill Actual authors / creators from the signed-in profile, link the faculty account automatically, and omit that account from additional-author choices. The server adds the faculty account to the linked author set on create. Existing records and non-faculty workflows are unchanged.

## Assistant-run checks
- `rtk proxy php -l includes/archival-record-form.php`: passed; no syntax errors.
- `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check -- includes/archival-record-form.php docs/testing/manual-reports/2026-11-05-copyright-faculty-prefill-01.md`: passed with no whitespace errors.
- Database effects: none.

## Resume / handoff
- Last user-confirmed state: Faculty form prefill and available author choices matched expectations (“all passed”).
- Next pending action: User inspects the opened detail page to confirm the linked faculty author appears.
- Blocked/skipped checks and remaining verification gaps: Detail-page authorship observation remains pending.
- User-performed data changes and cleanup status: User saved one disposable record through the application; cleanup has not been reported.
