# Verification: chapter attachment removal

- Date/time: 2026-10-24 (Asia/Taipei)
- Assistant: GitHub Copilot
- Target and expected behavior: Fix SQL assembly in `deleteRmsUpload()`, distinguish submitted-version attachments from removable drafts, and show a post-commit confirmation naming the chapter and version submitted for review.
- Environment URL: http://localhost/rms/pages/researcher/submit-chapter.php?project_id=2&chapter=2
- Roles and disposable records/files: User confirmed the exact project and currently displayed Chapter 2 form attachment are disposable; student role.
- Session state: In progress; version-specific submission confirmation implemented, browser retest pending.

## Graphify and source context
- Question and selected graph vocabulary: `deleteRmsUpload` upload ownership/cleanup; selected graph terms: `delete`, `upload`, `ownership`, `cleanup`, `research`, `project`, `version`, `submission`.
- Query/lookup and result: Queried the existing `graphify-out/graph.json` with `graphify query "delete upload ownership cleanup research project version submission" --budget 1500`; it identified `deleteRmsUpload()` and related upload/version nodes, but the result was truncated at 40 of 53 nodes. A preceding focused query identified the extracted `deleteRmsUpload() --calls--> getRmsUpload()` edge.
- Relevant source locations and verified relationships: Current `includes/file-uploader.php` defines `deleteRmsUpload()` and checks session identity, project creator/member ownership, submitted-version attachment, project/chapter editability, project soft deletion where supported, upload soft deletion where supported, and upload-root containment before file cleanup. `pages/researcher/submit-chapter.php` calls it only after checking the requested upload matches the current chapter attachment and the chapter is editable.
- Missing, inferred, stale, or truncated context: The Graphify location for `deleteRmsUpload()` is L470 while the current source has moved it to about L533; the graph did not establish the current page caller, which was verified directly in source. The broader traversal was truncated. Current source and canonical schema are authoritative.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l includes/file-uploader.php` | No PHP syntax error | `No syntax errors detected in includes/file-uploader.php` | Passed |
| S2 | Assistant static check | Run `git diff --check -- includes/file-uploader.php` | No whitespace errors in the scoped source file | Exit code 0; Git emitted only its line-ending normalization warning | Passed |
| M1 | Student / chapter resubmission | On the confirmed disposable Chapter 2 form, click “Remove draft attachment” once | If editable, the request completes without the SQL syntax error and the draft attachment is removed; submitted-version files remain protected | User reports: “This attachment cannot be removed because it is not editable or is part of a submitted version.” No SQL syntax error was reported; the attachment was not removed | Failed |
| M2 | Assistant static check | Run `php -l` for the changed Chapter 2 page and upload helper | Both files have valid PHP syntax | Both focused PHP syntax checks passed | Passed |
| M3 | Assistant static check | Run scoped `git diff --check` for the changed page and upload helper | No whitespace errors in the scoped files | Passed; Git reported line-ending normalization warnings only | Passed |
| M4 | Student / Chapter 2 resubmission | Refresh the disposable Chapter 2 form after the attachment UI fix and submit a replacement PDF | Preserved attachment is labeled correctly, replacement PDF is accepted, and submission completes | User confirmed the message/control state appeared and PDF submission succeeded. No confirmation message appeared. The browser submission predates the new confirmation patch; exact submitted version was not observed | Passed for attachment UI and PDF submission; confirmation behavior not yet verified |
| M5 | Student / project detail after Chapter 2 submission | Submit the chapter through the normal form after the patch | A success notice states “Version N of Chapter 2 has been submitted for review.” | Awaiting user-operated retest | Pending |
| S3 | Assistant static check | Run `php -l pages/researcher/submit-chapter.php` after adding the confirmation | No PHP syntax errors | `No syntax errors detected in pages/researcher/submit-chapter.php` | Passed |
| S4 | Assistant static check | Run scoped `git diff --check -- pages/researcher/submit-chapter.php` | No whitespace errors in the changed page | Exit code 0; Git emitted a line-ending normalization warning only | Passed |

## Failures, decisions, fixes, and retests
- Reported failure: MariaDB rejected the ownership query because the PHP concatenation operators and `$deletedFilter` were inside a double-quoted SQL string and were sent literally to MariaDB.
- Focused fix: Move the PHP concatenation boundary outside the SQL string. The prepared placeholders and checks were not changed.
- Retest outcome: User clicked “Remove draft attachment” on the disposable Chapter 2 form. The page rejected removal with its editability/submitted-version safeguard message. This is a distinct behavior from the fixed SQL syntax error; no attachment was removed.
- User authorized investigation/fix now.
- Root cause: The returned Chapter 2 page showed its current upload with a Remove draft attachment button even when that upload was linked to a submitted-version snapshot. The backend correctly blocked deleting that immutable attachment, but the UI did not distinguish it from a removable draft upload.
- Focused UI fix: The current-upload query now identifies snapshot-linked files. For such a file, the page explains that it remains preserved and instructs the user to upload a replacement PDF; the remove button is shown only for an upload that is not linked to a submitted version. The backend deletion safeguard remains unchanged.
- User confirmed the preserved attachment message/control state appeared and the replacement PDF submission succeeded, but there was no submitted-for-review confirmation.
- Focused confirmation fix: After the chapter submission transaction commits, `pages/researcher/submit-chapter.php` sets the existing `module_success` session flash with the exact captured version and chapter; `pages/shared/research-detail.php` already renders and consumes that flash. Browser retest is pending.
- Static relationship check: `rms_capture_submission_version()` is followed by a query for that exact version's `version_number`; the confirmation uses that value only on the successful submission branch after commit. The destination page consumes and renders the existing session flash.
- No database query, database edit, or browser interaction was performed by the assistant.

## Assistant-run checks
- `php -l includes/file-uploader.php` — passed.
- `php -l pages/researcher/submit-chapter.php` — passed after the preserved-attachment UI change.
- `php -l pages/researcher/submit-chapter.php` — rerun after adding the version-specific success flash; result pending.
- `git diff --check -- includes/file-uploader.php` — passed (line-ending warning only).
- `git diff --check -- pages/researcher/submit-chapter.php includes/file-uploader.php` — passed (line-ending normalization warnings only).
- `php -l pages/researcher/submit-chapter.php` after adding the submission confirmation — passed.
- `git diff --check -- pages/researcher/submit-chapter.php` after adding the submission confirmation — passed (line-ending normalization warning only).
- No database was accessed or altered. No browser interaction was performed.

## Resume / handoff
- Last user-confirmed state: After refresh, the user saw the preserved attachment guidance/control state and successfully submitted a replacement PDF. No confirmation message appeared.
- Next pending action: On a future authorized disposable chapter draft or returned revision, user submits through the normal form and confirms the project detail page states the exact submitted version and chapter.
- Blocked/skipped checks and remaining verification gaps: The user’s successful submission happened before this confirmation patch. Submitted chapters are locked, so an immediate repeat is not appropriate; the new success message awaits the next normal editable submission. No submitted-version protection was weakened.
- User-performed data changes and cleanup status: User submitted a replacement PDF on the disposable project; the previously preserved attachment was not removed. Any later cleanup remains user-performed through supported application actions.
