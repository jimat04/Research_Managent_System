# Verification: publication and IP role access

- Date: 2026-10-28 (Asia/Taipei)
- Assistant: Codex
- Target behavior: Students see only credited publication records and limited IP contribution summaries; faculty create and track their own publication/IP submissions; staff access remains category permission based; admins retain institutional management.
- Environment URL: Local RMS, not inspected.
- Roles and disposable records/files: Student, faculty, research staff, and admin accounts; no record changes planned for browser checks.
- Session state: Finished with listed checks pending or blocked.

## Graphify and source context

- Question and selected graph vocabulary: How do role shells, publication/IP list/detail routes, and contributor tables connect? Terms selected from graph labels: `publication`, `copyright`, `student`, `faculty`, `researcher`, `permission`, `shell`.
- Query/lookup and result: `graphify query "publication copyright student faculty researcher permission shell" --budget 1500`, then a focused lookup for publication/IP pages and role shells. Both results were truncated. The map predates the contributor code in this change and includes old E2E test nodes; direct source review was used to confirm current behavior.
- Relevant source locations: `includes/student-shell.php` role navigation; `includes/faculty-shell.php` role navigation; `pages/shared/publications.php` and `pages/shared/copyrights.php` list scoping; `pages/shared/publication-detail.php` and `pages/shared/copyright-detail.php` detail access; `pages/shared/download-research-document.php` attachment authorization; `database/migrations/043_publication_ip_contributors.sql` contributor links and primary-account backfill.
- Confirmed relationships: Student summary pages query only rows linked to the logged-in account; student visits to shared publication/IP lists redirect to the summaries; detail/form routes deny student access; publication/IP file preview is blocked for students. Staff list/detail authorization continues through the existing page-level module permission helpers.
- Limitations: Graph results were truncated and stale for newly added contributor files. Source was checked directly. No graph rebuild was performed.

## Checklist

| ID | Check | Status | Result |
|---|---|---|---|
| S1 | PHP syntax for changed PHP files and migration runner | Passed | `php -l` reported no syntax errors for all changed PHP files. |
| S2 | Whitespace and patch check | Passed | `git diff --check` reported no whitespace errors. Git emitted existing line-ending/config warnings. |
| S3 | Inspect access guards, SQL scope, contributor backfill and staff permission gates | Passed | Source review confirms role and ownership checks; legacy free-text names are not matched to accounts. |
| S4 | Avoid fatal errors before migration 043 | Passed | Missing contributor tables now trigger primary researcher/applicant fallback on student summaries and skip contributor-table operations on faculty forms. |
| D1 | Check migration `043_publication_ip_contributors.sql` status | Passed (unapplied) | Read-only `php scripts/migrate.php status` reports `043_publication_ip_contributors.sql` as pending. No migration was applied. |
| B1 | Student navigation and My Publications summary | Passed | User confirmed the page shows only credited publications in a read-only list or empty state, with no submit/edit controls. |
| B1a | Student direct access to publication creation | Passed | User opened `pages/shared/publication-form.php` directly and received the expected 403 Access Denied page. |
| B1b | Student direct access to IP application creation | Passed | User opened `pages/shared/copyright-form.php` directly and received the expected 403 Access Denied page. |
| B2 | Student IP contribution summary and restricted details/attachments | Passed | Initial page load raised `mysqli_sql_exception` because `copyright_contributors` was absent. After fallback, the screenshot showed only the expected four summary columns and primary-applicant rows. User confirmed the scoped contrast fix made the page readable. |
| B3 | Faculty own submissions, pending edits, and review/register denial | Partially passed | Own-record lists and pending edit/review boundaries were observed. Record creation and persistence were not exercised. |
| B3a | Faculty navigation entries | Passed | User confirmed the sidebar shows **My Publications** and **My IP Applications** as the faculty entry points. |
| B3b | Faculty publication list and submission entry point | Passed | User confirmed the list shows this faculty account's own records and provides a publication submission action. |
| B3c | Faculty IP application list and submission entry point | Passed | User confirmed the list shows this faculty account's own applications and provides an application submission action. |
| B3d | Faculty pending IP application controls | Passed | User confirmed an edit action is available and review/registration controls are absent. |
| B3e | Faculty publication edit and review controls | Passed | User confirmed their own submitted publication remains editable while review controls are absent. |
| B4 | Research staff category navigation | Passed | User confirmed only categories granted to the staff account appear. A **View** action is expected for categories with page-level view permission; manage actions require manage permission. |
| B4a | Research staff record view and permitted actions | Passed | User confirmed the record opens and edit/review actions match the account's permission level. |
| B5 | Admin institution-wide publication/IP navigation | Passed | User confirmed admin access to the institution-wide publication and IP categories remains available. |
| B5a | Admin IP review controls on a pending application | Blocked | No pending application was available to inspect; no status changes were made. |
| B5b | Admin publication review controls | Passed | User confirmed a submitted publication opens with the appropriate admin review controls. No status change was made. |
| B6 | Identify an existing IP record for student direct-route check | Passed | User opened an existing application and retained its ID for the student-role route check. The ID is omitted from this report. |
| B7 | Student direct access to another IP record | Passed | User confirmed the route returns Access Denied without application details or attachment links. |
| B8 | Student direct access to IP attachment | Passed | User confirmed the route returns Access Denied and starts no preview or download. |
| B9 | Prepare faculty cross-owner direct-route check | Passed | User opened an existing publication for the check. Its ID is intentionally omitted from this report. |
| B10 | Faculty direct access to another faculty publication | Passed | User confirmed Access Denied with no other faculty publication details. |
| B11 | Prepare faculty cross-owner IP check | Passed | User opened an IP application for the check; its ID is intentionally omitted from this report. |
| B12 | Faculty direct access to another faculty IP application | Passed | User confirmed Access Denied with no other faculty application details. |

## Observations

- Assistant checks: All changed PHP files passed `php -l`; `git diff --check` found no whitespace errors. Read-only migration status confirmed migration 043 is pending; no migration was executed.
- User observations: Admin retains institution-wide publication and IP category access. A submitted publication opens with admin review controls. No pending IP application was available for review. Existing IP applications were opened to prepare student and faculty direct-route checks; identifiers are intentionally omitted. Student direct access to an IP detail returned Access Denied with no details or attachment links, and the copyright attachment route returned Access Denied without starting a preview or download. Faculty direct access to another faculty publication and IP application returned Access Denied. Research staff sidebar shows only granted categories; a record opens and its edit/review actions match the account's permission level. Faculty sidebar shows **My Publications** and **My IP Applications**. Both faculty lists are scoped to this account and expose their submit actions. A pending faculty IP application offers edit but no review or registration controls. A submitted faculty publication remains editable with no review controls. Student publication page shows only credited publications in a read-only list or empty state, with no submit/edit controls; direct access to both publication and IP application forms returned 403. Student IP page first failed with a fatal missing-table error (`copyright_contributors`); after the fallback fix, the screenshot confirmed the page loaded with title, type, contribution and status only. User confirmed the contrast fix in `css/student-shell.css` resolved readability.
- Limitations: Migration 043 is not applied, so structured contributor links beyond primary applicant/researcher IDs are not active. The runner status also reports unrelated existing ledger issues for migration 010 (`needs-verifier`), migrations 012 and 014 (`checksum-mismatch`), and migration 029 (`needs-verifier`). Admin IP review controls remain unverified because there was no pending application to inspect. Faculty record creation/persistence was not tested; no supported UI delete action is available to clean up a disposable test record.
