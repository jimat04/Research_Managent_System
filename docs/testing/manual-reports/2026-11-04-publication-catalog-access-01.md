# Verification: shared publication catalog access

- Date/time: 2026-11-04 05:06 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Faculty and students can view metadata for active archived or published publications across RMS; record management, history, supporting documents, and private evidence remain limited to existing permissions.
- Environment URL: `http://localhost:8080/rms/`
- Roles and disposable records/files: Faculty and student browser observations use existing records read-only; no records will be changed.
- Session state: In progress; user browser observations are still pending

## Graphify and source context
- Question and selected graph vocabulary: How do faculty/student publication lists connect to the shared document route? Selected graph labels: `publicationsRun()`, `download-research-document.php`.
- Query/lookup and result: `graphify query 'publicationsRun() download-research-document.php' --budget 1500`; the graph returned 35 nodes and 30 edges, with a note that the full result exceeded the requested budget. Relevant permission edges were not established.
- Relevant source locations and verified relationships: `includes/archival-record-list.php` builds role-scoped list queries; `includes/archival-record-page.php` guards detail routes; `includes/archival-records.php` centralizes view/manage/download decisions; `pages/shared/download-research-document.php` checks document access; `includes/archival-record-detail.php` and publication partials render metadata, history, and document controls.
- Missing, inferred, stale, or truncated context: Graph output was over budget and did not establish the permission call path. Current source is authoritative.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| SRC-1 | Static source review | Review list, detail guard, permission helper, and document endpoint | Public catalog scope is limited to active archived/published publication metadata; manage/history/document access stays scoped | Public list/detail scope is active archived or published publications; faculty report/export remains owner-scoped; private RMS contributor links, history, evidence, and supporting documents remain hidden for catalog-only viewers. Full text link is shown only when the existing public-release eligibility conditions match; the document endpoint rechecks access. | Passed |
| PHP-1 | PHP CLI | Run `php -l` on changed PHP files | No syntax errors | `php -l` passed for all 10 changed PHP files. `git diff --check` reported no whitespace errors. | Passed |
| UI-F | Faculty browser; faculty publications page | Open a publication owned by someone else that is archived or published | Metadata opens; edit, history, and supporting-document controls remain unavailable | Awaiting user observation | Pending |
| UI-S | Student browser; student publication page | Open an archived or published publication not linked to the student | Metadata opens; edit, history, and supporting-document controls remain unavailable | Awaiting user observation | Pending |
| UI-PUB | Faculty browser; publication detail | Open an archived or published publication with its bibliographic details | The record reads like a scholarly publication page with title, authors, citation facts, abstract, and keywords; the full-text link appears only for an eligible public release | Awaiting user observation after the publication-detail redesign | Pending |
| UI-FULLTEXT | Faculty or student browser; publication detail | Open a faculty publication with a public full-text release | A “Read full text” action appears and points to the protected document viewer | Awaiting user observation | Pending |
| DOC-1 | Faculty or student browser; shared document route | Open the document route for a non-owned/non-contributor publication without a public release | Access is denied; publicly released papers remain available through the public archive | Not exercised; needs an eligible record with an unreleased file | Blocked |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- Read-only review of role-scoped list SQL, detail authorization, document download/preview authorization, and visible record controls.
- PHP syntax checks and focused diff whitespace check completed successfully.
- Publication detail now presents a journal-style title, byline, publication facts and identifier, abstract, keywords, expandable metadata, and permission-scoped repository provenance. Catalog-only viewers do not receive indexing evidence or internal record provenance.
- CSS brace count is balanced (195/195).
- No database writes, migrations, or application record changes occurred.

## Resume / handoff
- Last user-confirmed state: Not applicable.
- Next pending action: User inspects the publication detail layout and the “Read full text” action when an eligible public release record is available, without editing or archiving a record.
- Blocked/skipped checks and remaining verification gaps: Student observation and document route check remain pending/blocked as shown above.
- User-performed data changes and cleanup status: None.
