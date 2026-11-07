# Verification: faculty-engagement

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Admin and authorized research staff filter faculty engagement records by status, keyword, type, department, and activity date, with paginated matching results; staff evidence access and review actions follow their permission level.
- Environment URL: Not confirmed.
- Roles and disposable records/files: No application record or file selected yet.
- Session state: Finished for the filtering enhancement. User-confirmed filter checks passed; remaining checks below were not confirmed in this session.

## Graphify and source context
- Question and selected graph vocabulary: How do the record listing, users, and staff authorization relate? `users`, `uploads`, `auth.php`.
- Query/lookup and result: `graphify query "users uploads auth.php" --budget 1300` returned a truncated result (39 of 59 nodes). It exposed permission helpers but not the engagement listing query; current source was used to confirm the filter fields and query dependencies.
- Relevant source locations and verified relationships: `pages/admin/admin-faculty-engagement.php` contains the record query and review handlers; `includes/auth.php` defines staff permission helpers; `includes/staff-shell.php` builds permission-based navigation. This filter change only queries engagement records joined to users.

## Assistant checks
- **Passed:** `php -l pages/admin/admin-faculty-engagement.php`
- **Passed:** `php -l includes/staff-shell.php`
- **Passed:** `php -l pages/shared/download-research-document.php`
- **Passed:** `git diff --check` reported no whitespace errors. Git also emitted permission warnings while examining unrelated `storage/earist` paths.
- **Passed:** `php -l pages/admin/admin-faculty-engagement.php` after filter and pagination changes.
- **Passed:** Focused `git diff --check` for the updated engagement page and this report; no whitespace errors (Git warned that the user-level ignore file was inaccessible).

## Browser checks
- **Passed (user-confirmed):** Administrator confirmed the redesigned record details and evidence preview action are present.
- **Passed (user-confirmed):** Administrator confirmed the status, search, type, department, date range, Filter, and Clear filters controls are visible.
- **Passed (user-confirmed):** Administrator entered a visible faculty name or record title in the keyword field.
- **Passed (user-confirmed):** Applying the keyword returned matching records and retained the search value.
- **Passed (user-confirmed):** User manually checked the engagement filters and reported all passed. The user did not provide per-case observations.
- **Passed (user-confirmed):** Research staff with view access could inspect records and preview evidence with review controls hidden.
- **Pending:** Research staff with manage access can verify/return pending records and link eligible presentation activities.
- **Pending:** Staff without review access are denied the page and direct evidence routes.
- **Pending:** Responsive layout, keyboard focus, and Word document fallback.
