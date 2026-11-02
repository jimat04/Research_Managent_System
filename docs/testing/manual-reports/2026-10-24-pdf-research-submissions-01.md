# Verification: PDF-only research submissions

- Date/time: 2026-10-24 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: New canonical student/faculty proposal/title, chapter, and final-manuscript uploads accept only files with a `.pdf` extension and detected PDF MIME/content; renamed DOCX files are rejected before upload storage or submission version/status changes. Existing upload safeguards and old document downloads remain available.
- Environment URL: Not verified; no browser session was used.
- Roles and disposable records/files: Student and faculty workflows; no records or files created or modified.
- Session state: In progress; browser checks pending user operation.

## Graphify and source context
- Question and selected graph vocabulary: How the canonical student/faculty title, chapter, and final-manuscript submission handlers connect to shared file validation and upload endpoints. Selected vocabulary from the existing graph: `chapter`, `faculty`, `file`, `handler`, `manuscript`, `pdf`, `proposal`, `research`, `student`, `submission`, `title`, `upload`, `workflow`.
- Query/lookup and result: Ran `graphify query "chapter faculty file handler manuscript pdf proposal research student submission title upload workflow" --budget 1800` against the existing graph (1,588 nodes). BFS found 192 nodes; output showed 45 and was truncated. Relevant nodes included `handleRmsUpload()`, `file-uploader.php`, `upload.php`, `researcher/submit-research.php`, and the student/faculty shells.
- Relevant source locations and verified relationships: Current `includes/file-uploader.php` defines `handleRmsUpload()` (around line 209); its workflow access/eligibility checks precede upload validation. The PDF extension, MIME, and `%PDF-` signature checks are in the helper before `move_uploaded_file()` (around lines 394–454). `pages/researcher/submit-research.php`, `edit-research.php`, `submit-chapter.php`, and `submit-final-research.php` pass their uploads through that helper before submitted status/version capture; their rendered uploaders advertise `.pdf`. `public/api/upload.php` maps proposal, chapter, and manuscript destinations to the corresponding workflow types and restricts those destinations to PDF. The chapter proposal/template DOCX download links are unchanged.
- Missing, inferred, stale, or truncated context: Graphify results were truncated and reflect indexed source rather than proving runtime behavior. Current source was checked directly. The separate student milestone uploader continues to allow its existing DOC/DOCX formats and was not changed because it is outside the canonical research submission workflows.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| STATIC-1 | Assistant / six changed PHP files | Ran `php -l` on `includes/file-uploader.php`, `pages/researcher/submit-research.php`, `pages/researcher/edit-research.php`, `pages/researcher/submit-chapter.php`, `pages/researcher/submit-final-research.php`, and `public/api/upload.php` | All changed PHP parses | Each file reported “No syntax errors detected.” | Passed |
| STATIC-2 | Assistant / focused tracked diff | Ran `git diff --check -- includes/file-uploader.php pages/researcher/submit-research.php pages/researcher/edit-research.php pages/researcher/submit-chapter.php public/api/upload.php` | No whitespace errors in the focused tracked changes | Exit 0; Git printed only existing LF-to-CRLF normalization warnings. The already-untracked final-manuscript page is not included in this tracked diff check. | Passed |
| STATIC-3 | Assistant / preexisting untracked final-manuscript page | Ran `git diff --no-index --check -- /dev/null pages/researcher/submit-final-research.php` | No whitespace diagnostics | No diagnostics; exit 1 is expected because the untracked file differs from `/dev/null`. | Passed |
| STATIC-4 | Assistant / shared upload helper, local-only check | Passed the existing DOCX proposal template to `handleRmsUpload()` under a fake `.pdf` filename, with `workflowType=title` and no project/database connection | Reject the file for invalid PDF content before storage or database writes | The helper returned the PDF-content validation error; no file or database changes were made. | Passed |
| BROWSER-1 | Student or faculty / canonical proposal-title page | User selects a DOCX file renamed with a `.pdf` extension in the proposal uploader; stop before any successful submission | Clear PDF-content rejection; no successful upload or changed submission status/version | User confirmed RMS rejected the file and did not report a successful upload. Exact message wording was not provided. | Passed |
| BROWSER-2 | Student or faculty / editable chapter page | User selects a DOCX renamed `.pdf` in the chapter uploader | Clear rejection; no submitted chapter version/status change | User said they will check this later; no eligible chapter browser test was performed in this session. | Pending |
| BROWSER-3 | Student or faculty / eligible final-manuscript page | User selects a DOCX renamed `.pdf` in the final-manuscript uploader | Clear rejection; no uploaded final version or status change | Awaiting user observation. | Pending |
| BROWSER-4 | Student or faculty / applicable submission form | User inspects the file chooser hint and error message for each workflow | The chooser advertises PDF only and invalid input receives a clear PDF-specific message | Awaiting user observation. | Pending |
| BROWSER-5 | Authorized disposable workflow, with user approval | User uploads a genuine PDF and follows the normal save/submit path | PDF accepted under the existing role, ownership, eligibility, CSRF, size, and workflow rules | Not attempted; requires user-operated browser verification and a user-approved disposable record because a successful upload/submission can persist data. | Blocked |

## Failures, decisions, fixes, and retests
- No failures observed in assistant-run checks. The user-operated proposal upload check rejected the renamed DOCX; no successful upload, database operation, or data modification was reported.
- The worktree already contained extensive unrelated changes, including modifications to shared RMS files and an untracked `pages/researcher/submit-final-research.php`; those existing changes were preserved.

## Assistant-run checks
- PHP syntax checks: `php -l` for each of the six files listed in STATIC-1 — all passed.
- Focused whitespace check: `git diff --check` for the five tracked changed PHP files listed in STATIC-2 — passed; Git emitted line-ending normalization warnings only.
- Untracked-file whitespace check: `git diff --no-index --check -- /dev/null pages/researcher/submit-final-research.php` — no whitespace diagnostics; exit 1 is the expected no-index “files differ” status.
- Local-only helper check: the DOCX proposal template renamed as `.pdf` was rejected by the shared helper before storage or database access.
- Source review confirmed the research workflow gate checks extension, `finfo` MIME `application/pdf`, and `%PDF-` signature before file storage. Workflow handlers call the helper before version/status updates; their transaction error paths roll back on invalid uploads.
- No automated application test convention was found/added. No database effects.

## Resume / handoff
- Last user-confirmed state: The proposal/title uploader rejected a DOCX renamed to `.pdf`; no successful upload was reported. Exact error wording was not provided. The user deferred the chapter browser check.
- Next pending action: If a suitable existing editable chapter is available, select a DOCX renamed to `.pdf` in its chapter uploader and confirm rejection before successful submission. Repeat on the final-manuscript page only if an eligible project is available.
- Blocked/skipped checks and remaining verification gaps: Chapter and final-manuscript browser rejection, PDF acceptance, and user-visible persistence/status behavior are not runtime-confirmed. Positive submission checks require a user-approved disposable workflow. Direct-route role/ownership safeguards were source-reviewed but not browser-exercised.
- User-performed data changes and cleanup status: None.
