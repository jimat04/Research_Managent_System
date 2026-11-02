# Verification: remove a missing My Documents upload record

- Date: 2026-11-02 (Asia/Taipei)
- Target: Student My Documents, especially an adviser-request DOCX whose file is missing on disk.
- Expected: Existing adviser-request files are recognized at their whitelisted storage path. Removing an owned missing upload uses soft deletion when the migration is applied, preserving linked adviser-request/history records. Draft deletion remains user-visible as before; submitted-version protection, ownership, CSRF, prepared SQL, and audit logging remain in force.
- Data and browser scope: No database/schema migration was run; no application record or uploaded file was changed. Browser checks await the migration decision and user operation in the integrated browser.
- Session state: Static source verification complete; browser checks pending.

## Graphify and source trace

- Selected graph vocabulary: `mydoc_file_exists_check`, `deleteRmsUpload`, `my-documents.php`.
- Lookup: `graphify query "mydoc_file_exists_check" --budget 1719` returned 53 nodes and 105 edges. The post-edit focused lookup `graphify query "mydoc_file_exists_check deleteRmsUpload" --budget 1500 --context_filter call` returned 53 nodes but was truncated at 46; no graph rebuild/update was performed.
- Confirmed source: `pages/student/my-documents.php` resolves files through `mydoc_storage_location()` and checks paths with `mydoc_file_exists_check()`. Its POST branch checks CSRF, ownership, student project access, soft-deleted state and `research_submission_version_uploads`; missing records use a prepared soft-delete UPDATE when `uploads.deleted_at` exists. `pages/researcher/request-adviser.php` stores request DOCX files in `uploads/adviser_requests` as type `other`. The canonical schema defines `adviser_requests.upload_id` with `ON DELETE RESTRICT`, so a linked row cannot be hard-deleted. `includes/auth.php` provides the CSRF validator and `logActivity()`.
- Graph limitation: the map predates these edits and the focused lookup was truncated; current source was used to confirm the path and action behavior.

## Checks

| ID | Method | Expected | Result | Status |
|---|---|---|---|---|
| S1 | `php -l pages/student/my-documents.php` | No syntax errors | No syntax errors detected | Passed |
| S2 | `php -l scripts/migrate.php` | No syntax errors | No syntax errors detected | Passed |
| S3 | `git diff --check` for tracked changes plus trailing-whitespace scan of new files; focused source review | Auth, ownership, CSRF, parameterized SQL, missing-file validation, normal delete path and audit behavior preserved | Passed; Git emitted only the existing LF-to-CRLF normalization warning for the modified page. Verified the action still locks/checks ownership, project access, soft-delete state and submitted-version links before issuing prepared SQL; normal deletes still call `deleteRmsUpload()` and audit logging remains after success. No records/files/database were touched. | Passed |
| B1 | Student browser: My Documents, adviser DOCX | The row uses its actual adviser-request storage path when checking whether the file exists | Pending user observation | Pending |
| B2 | Student browser: own missing adviser DOCX after migration is applied | Removal hides only its upload row while preserving request/history, and success notice appears | Blocked until migration decision/application and user operation | Blocked |
| B3 | Student browser: submitted/reviewed upload and ordinary draft delete | Submitted upload remains protected; ordinary draft deletion still behaves as before | Pending user-performed check | Pending |

## Database effects and remaining requirements

- No application database was contacted or modified.
- Migration `064_upload_soft_deletes.sql` adds nullable `uploads.deleted_at` and the runner verifier checks that schema shape. The migration must be applied through the normal migration runner before a missing upload linked to adviser-request history can be safely hidden without violating its restrictive FK.
- Browser confirmation and an applied-schema integration check remain outstanding.

## Failures, decisions, fixes, and retests

- Initial issue: the user reported that “Remove missing record” does not remove `request-for-adviser.docx`.
- Fix: recognize the `adviser_requests` storage folder and soft-delete the upload metadata while retaining adviser-request history.
- Decision: the user will run migration `064` manually. No migration was executed by the assistant.

## Assistant-run checks

- `php -l pages/student/my-documents.php` — passed.
- `php -l scripts/migrate.php` — passed.
- `git diff --check -- pages/student/my-documents.php scripts/migrate.php database/schema/rms_db.sql` — passed; Git printed an LF-to-CRLF normalization warning for the page.
- Focused source review confirmed ownership, project access, CSRF, submitted-version protection, parameterized SQL, and audit logging remain in the action path.

## Resume / handoff

- Last user-confirmed state: the page displays the DOCX as missing; the user chose to run the migration themselves.
- Next pending action: verify the configured target is local `rms_db` and review the current migration ledger, then run `php scripts/migrate.php apply 064_upload_soft_deletes.sql`.
- Blocked checks: the delete action and browser behavior remain unverified until the migration is applied.
- User-performed data changes and cleanup: none reported yet.
