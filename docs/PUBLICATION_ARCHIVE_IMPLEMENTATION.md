# Publication archive implementation

## Inspection and design decision (2026-11-01, Asia/Taipei)

The application uses procedural PHP, MySQLi, vanilla HTML/CSS/JavaScript, and existing role shells. Admin/faculty publication routes already delegate to `includes/archival-record-page.php` and its shared archive handlers. `archiveCan()` grants edits to faculty owners, administrators, or explicitly authorized staff; contributor links grant visibility, not editing. CSRF, optimistic record versions, transactional audit details, and authenticated document delivery already exist.

`publications` is the single archive table. Legacy `publication_type` is an enum (`journal`, `conference`, `book_chapter`, `other`); `publication_type_name` supports Other. There was no stored publication scope or subtype. Legacy source/date/DOI/indexing/SDG fields, manuscript references, contributor associations, historical statuses, notifications, and activity log details must remain. Migrations 054/055 had already removed approval from active archive actions. No new approval transition is needed. Copyright forms share infrastructure and must retain their existing behavior.

The existing `application_settings` table is suitable for small form configuration overrides, but cannot provide relational type/subtype/scope foreign keys itself. The new catalog tables from migration 060 provide stable IDs and restrictive foreign keys; existing enum values are retained as compatibility categories. New classifications are not assigned to old rows. Metadata and ordered author names use the nullable fields added to the existing archive. Research areas reuse `research_areas` (migration 061 adds the nullable reference); programs reuse `programs`; SDG options reuse `facultyRecordSdgs()`.

Uploads already restrict publication archives to verified PDF content in `handleRmsUpload()`. This implementation will expose PDF only, even though migration 060 initially seeded a broader extension preference. It must not advertise DOCX as usable until the shared validator actually supports it. Publication evidence and references use the same protected storage and record access policy. Existing file references are retained in audit history when replaced.

Subtype rules use `publication_form_rules`; additional field selections, required fields, and size settings reuse namespaced `application_settings`. Each saved classified publication snapshots its effective rules in metadata so later configuration changes do not invalidate existing records. Changing a classification deliberately adopts the new rules. Page limits, when configured, are checked against an encoder-declared count, never presented as reliable document parsing.

Acceptance checks: five proposed primary types and all requested subtypes; independent scope/indexing; 250-word/four-keyword Short Conference Paper; ordered external/faculty authors; evidence; existing record preservation; no silent classification-switch clearing; owner-only faculty editing; protected current/historical files; directory/report/filter consistency; source review and read-only checks followed by user-operated browser verification.

## Database checkpoints

- Before 060: `backups/archive-20261101-135802-05e836`, 64 tables and 30 supporting files, verified dump/file inventory; SHA-256 `605632fd756b6cac34f7d29b4d12aeec0698d168977f5f18a0debc770997fbc5`.
- Before 061: `backups/archive-20261101-140948-887bf1`, 68 tables and 30 supporting files; SHA-256 `029f7996f4ba1959eb3422d24fce19834e9c65a8111dd2b8c6951a2887a6147b`.
- Migrations 060 and 061 were applied individually with `scripts/migrate.php` to local `rms_db`. Neither migration updates existing publication rows. Restore rehearsals have not been authorized or run for these checkpoints.
- Rollback: prefer reverting application changes while retaining additive nullable schema/catalogs and all new records. For a complete database rollback, first back up current data and reconcile any post-checkpoint records/attachments, then authorize an operator restore of the selected full checkpoint in a maintenance window. Do not drop columns/tables or automatically restore over newer data. Migration ledger must match the restored checkpoint.

## Institutional basis

The existing repository configuration (`config/faculty-research-records.php`) attributes broad books, journals, research articles, monographs, modules, and instructional materials to EARIST Faculty Manual 2018–2019 §2.4.1.1. This session has not independently authenticated that manual. The five primary types and all detailed subtypes are proposed RMS archive classifications, not a claim of official EARIST adoption. The Research Office must confirm category names, scope policy, evidence rules, and any prescribed limits. No regional scope is seeded. The 250-word and four-keyword rule comes from the supplied target form, not a universal institutional policy.

## Verification and remaining browser checks

See `docs/testing/manual-reports/2026-11-01-publication-archive-01.md`. Assistant checks passed: PHP lint on affected handlers/pages, JavaScript syntax, 47 in-memory form validation assertions, 11 nonlocking catalog/report SQL prepares, 28 existing archive SQL prepares, 7 archive distinction assertions, and migration status for 060/061. The preservation check compared all 63 original tables other than the migration ledger and all 30 backed-up files. Original publication rows and all other compared table data match. It found six new authentication log entries and account `last_login`/`updated_at` changes since the backup; the manual report records these separately. No restore rehearsal was run.

The existing `scripts/check-archival-records.php` originally contained a stale assertion that excluded patent records although current `archiveCopyrightType()` supports them. After the user's fix-now instruction, its assertion was aligned to the current source and the check passed. A first run of the new publication diagnostic also attempted a locking select inside a read-only transaction; that diagnostic now prepares nonlocking queries only and passed. These failures and their fixes are retained in the manual report.

The user still needs to perform browser checks for settings, form rules, submission persistence, document preview/download and historical versions, role ownership, duplicate warnings, reports and exports. The Research Office must confirm proposed categories and any local publication policy before the configuration is represented as institutionally approved. The supplied brief, rather than a general EARIST rule, is the basis for 250 words and four keywords.
