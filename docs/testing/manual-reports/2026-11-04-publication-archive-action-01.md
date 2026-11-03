# Verification: publication archive action

- Date/time: 2026-11-04 04:24 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Admin can mark an existing legacy publication as archived; the separate availability action still deactivates/restores records.
- Environment URL: `http://localhost:8080/rms/pages/admin/admin-publications.php`
- Roles and disposable records/files: Admin role; no disposable legacy publication is available for a state-changing browser check.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How do publication archival state, the admin detail handler, and the archive audit helper relate? Selected existing graph label: `publications`.
- Query/lookup and result: `graphify query 'database/schema/rms_db.sql::publications' --budget 1500`; 55 nodes matched and output was truncated to 42. The result did not establish handler relationships.
- Relevant source locations and verified relationships: `database/schema/rms_db.sql` defines `publications.archival_status` as `legacy|archived`; `includes/archival-record-detail.php` handles admin POST actions and renders the detail controls; `includes/archival-records.php` supplies `archiveAudit()`; `includes/publication-archive-table.php` links to availability.
- Missing, inferred, stale, or truncated context: Graph coverage for the page handler and audit helper is missing/truncated; implementation was confirmed against current source.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| PHP-1 | CLI syntax check | Run `php -l` on changed PHP files | No PHP syntax errors | Both changed PHP files lint clean | Passed |
| SRC-1 | Source review | Check authorization, CSRF, row locking, state precondition, prepared update, and audit path | Archive is admin-only, limited to legacy publication records, and audited transactionally | Handler requires admin and CSRF, locks the row, only updates `legacy` to `archived`, then records the before/after audit in the same transaction | Passed |
| UI-1 | Admin browser; publication detail | Open a legacy publication and inspect available actions without submitting | “Archive record” appears separately from “Deactivate record” | User screenshot shows both actions in separate cards | Passed |
| UI-2 | Admin browser; publication detail | Reload after the layout change and inspect the Archive record card | Archive button aligns to the right and bottom of its card | Awaiting user observation | Pending |
| DATA-1 | Admin browser; publication detail | Submit archive action and confirm resulting status/history | Legacy becomes Archived; history records the change; public visibility remains unchanged until separately released | Not run; existing legacy records are not disposable, and no session-created legacy record is available | Blocked |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- Read-only inspection of the canonical schema, relevant migration, admin handler, list link, CSRF flow, authorization check, and audit helper.
- `php -l includes/archival-record-detail.php` and `php -l includes/publication-archive-table.php`: no syntax errors.
- `git diff --check -- includes/archival-record-detail.php includes/publication-archive-table.php`: passed; Git emitted only its existing line-ending normalization warning for the table partial.
- `php -l includes/archival-record-detail.php` after the layout update: no syntax errors.
- CSS brace count for `css/archival-records.css`: 149 opening and 149 closing braces. `git diff --check` for the layout changes passed.
- No database writes, migrations, or application record changes were made.

## Resume / handoff
- Last user-confirmed state: Not applicable.
- Next pending action: User can reload a legacy publication detail page and confirm the Archive record button is at the card’s lower right.
- Blocked/skipped checks and remaining verification gaps: State-changing browser check awaits an isolated disposable legacy record or user authorization to archive a chosen existing record.
- User-performed data changes and cleanup status: None.
