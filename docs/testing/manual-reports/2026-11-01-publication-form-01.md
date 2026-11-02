# Verification: publication form types

- Date/time: 2026-11-01, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Admin and faculty publication forms omit legacy publication type choices while retaining proposed types and preserving a saved legacy classification on an existing record.
- Environment URL: not confirmed
- Roles and disposable records/files: Admin and faculty; no record changes planned.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: How do admin and faculty publication form routes share the publication type selector? `publication-form.php`, `publications.php`.
- Query/lookup and result: `graphify query 'publication-form.php' --budget 1500`; matched the shared publication form/list nodes and some nearby helpers, but the 1,500-token result was truncated.
- Relevant source locations and verified relationships: `pages/admin/admin-publication-form.php:1-4` and `pages/faculty/faculty-publication-form.php:1-4` configure the shared archive page; `includes/archival-record-page.php` dispatches publication form requests to `includes/publication-archive-form.php`; that shared file renders the type and subtype selectors. `database/migrations/060_publication_classification_metadata.sql` marks the four broad legacy types as `is_proposed=0` and newer classifications as `is_proposed=1`.
- Missing, inferred, stale, or truncated context: Graphify references an older `pages/shared/publication-form.php` implementation and does not cover the current `includes/publication-archive-form.php` path in the result; current source was used to confirm the relationship. Graph output was truncated.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / PHP syntax | Run `php -l includes/publication-archive-form.php` | No syntax errors | `No syntax errors detected in includes/publication-archive-form.php` | Passed |
| B1 | User browser / admin / Add Publication | Open the admin Add Publication form and inspect Publication type options | Legacy types are absent; proposed types remain | Awaiting user observation | Pending |
| B2 | User browser / faculty / Add Publication | Open the faculty Add Publication form and inspect Publication type options | Legacy types are absent; proposed types remain | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- `rtk proxy php -l includes/publication-archive-form.php` — passed; no syntax errors.

## Resume / handoff
- Last user-confirmed state: none.
- Next pending action: Admin user-operated check B1.
- Blocked/skipped checks and remaining verification gaps: Faculty browser check is pending; no application interaction has been performed by the assistant.
- User-performed data changes and cleanup status: none.
