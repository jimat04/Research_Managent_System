# Verification: delete an editable My Documents draft

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Students can remove their own editable, unsubmitted draft upload or a missing on-disk upload record that is not linked to a submitted version. Submitted versions and review history remain protected.
- Environment URL: `http://localhost/rms/pages/student/my-documents.php` (not opened by the assistant)
- Roles and disposable records/files: Student; actual deletion must use a disposable draft file created by the user for this verification session.
- Session state: In progress; source checks complete, browser verification pending.

## Graphify and source context
- Question and selected graph vocabulary: `my-documents.php`, `deleteRmsUpload`, `research_submission_version_uploads`, `CSRF`.
- Query/lookup and result: `graphify query "my-documents.php deleteRmsUpload research_submission_version_uploads CSRF" --budget 1100`; 129 nodes found, output truncated at 33. Follow-up: `graphify query "my-documents.php uploads deleted_at research_documents" --budget 900`; 99 nodes found, output truncated at 26. Current source for `deleteRmsUpload()` is in `includes/file-uploader.php:470-621`.
- Relevant source locations and verified relationships: The My Documents page scopes records to the logged-in student's owned/member projects (`pages/student/my-documents.php:99-153`). Deletion additionally requires the student to be the file uploader. `deleteRmsUpload()` checks project access/state and refuses uploads linked to `research_submission_version_uploads`; its existing behavior removes the stored file and soft-deletes the upload when supported. The `research_documents.upload_id` foreign key uses `ON DELETE SET NULL`, preserving the document record. Review rows are never deleted.
- Missing, inferred, stale, or truncated context: Graph output was truncated and contained unrelated historical/E2E nodes. Findings were confirmed against current helper and schema source. No browser or database action has been performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant / PHP syntax | Run `php -l pages/student/my-documents.php` | No PHP syntax errors | No syntax errors detected | Passed |
| A2 | Student / My Documents | Locate own editable draft upload not linked to a submitted version | Delete draft action appears only for the eligible upload | Awaiting user-operated browser check | Pending |
| A3 | Student / My Documents | Delete a disposable draft upload created for this session and confirm the prompt | File is removed from the list and success notice appears; no review history is affected | Awaiting user-operated browser check; do not delete an existing non-disposable record | Pending |
| A4 | Student / My Documents | Inspect a submitted/reviewed file | No Delete draft action appears; submitted-version protection is indicated | Awaiting user-operated browser check | Pending |
| A5 | Student / My Documents | Inspect an owned upload marked File missing on disk and not linked to a submitted version | Remove missing record action appears; confirming removes only the stale upload record and preserves project/review history | Awaiting user-operated browser check | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l pages/student/my-documents.php` — passed.
- `git diff --check -- pages/student/my-documents.php` — passed (Git emitted a line-ending normalization warning only).
- Database effects: none.

## Resume / handoff
- Last user-confirmed state: No browser state confirmed.
- Next pending action: Refresh the shown missing-file rows; confirm an owned, unlinked missing record shows Remove missing record. Use only a disposable record created for this verification session for any actual deletion.
- Blocked/skipped checks and remaining verification gaps: Actual draft/missing-record removal, confirmation behavior, review-history protection, and persistence await user-operated browser verification.
- User-performed data changes and cleanup status: None reported.
