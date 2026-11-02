# Verification: Submission document preview loop

- Date: 2026-10-24 (Asia/Taipei)
- Target: Remove the nested document preview from admin/staff submission cards and provide separate full-document and download actions.
- Environment: Local RMS admin Research Proposals page; no credentials, document contents, or private record details are included here.
- Session status: Source diagnosis complete; both document actions confirmed by the user in the browser.

## Graphify and source context

- Question and selected graph vocabulary: How document preview and download work in the submission review card; selected from graph labels `download-research-document.php`, `document-preview.php`, and `rmsDocumentPreviewContentType`.
- Lookup: `graphify query "download-research-document document preview viewer" --budget 1100`.
- Result: Graph returned the shared download endpoint, MIME helper, and document-preview-related nodes (22 nodes, 17 edges; complete answer over the requested budget). Current source was checked directly.
- Verified cause: `pages/shared/research-submission-review.php` embedded a `mode=viewer` URL in an iframe for PDFs. `pages/shared/download-research-document.php` renders the viewer mode as an HTML wrapper with a second iframe for `mode=inline` and a toolbar whose “Dashboard” link targets the role dashboard. In the review page’s iframe, that link navigates the dashboard into the embedded frame, producing the reported nested-dashboard loop.
- Change: Removed the in-card iframe; filenames are now plain text with separate “View full document” and permission-aware “Download” actions. The view action opens the existing viewer wrapper in a separate tab, so its dashboard link no longer navigates inside the review card. Download links are rendered for admins and research staff whose current page view and download permissions match the download endpoint.

## Checks

| ID | Role / page | Action | Expected | Observed | Status |
|---|---|---|---|---|---|
| DOC-1 | Admin / Research Proposals | User refreshed the page and inspected the PDF submission card | No embedded preview/dashboard frame appears; filename and View full document and Download buttons are visible | User confirmed the embedded preview is gone and both buttons appear beside the filename. | Passed |
| DOC-2 | Admin / Research Proposals | User selected View full document | Opens the standalone viewer in a separate tab; PDF displays there; review card remains on the original page | User confirmed the viewer opened separately, displayed the PDF, and left Research Proposals open. | Passed |
| DOC-3 | Admin / Research Proposals | User selected Download for the same PDF | The original document downloads; the review page stays available | User confirmed the PDF downloaded and the review page remained open. | Passed |

## Assistant-run checks

- `php -l pages\shared\research-submission-review.php` — passed; no syntax errors.
- `git diff --check -- pages/shared/research-submission-review.php docs/testing/manual-reports/2026-10-24-document-preview-loop-01.md` — passed with no whitespace errors.
- Focused source search confirms the shared review page no longer contains an embedded-preview iframe or its CSS; separate viewer and download URLs are present, and the download action is permission-gated.
- No database changes, review decisions, or document modifications were made.

## Limitations

- The original PDF checks and the DOCX-specific follow-up were user-confirmed in the browser.

## DOCX preview follow-up

- Cause: The inline preview helper supports PDF and plain-text files, not DOCX. The local PHP CLI lacks the ZIP extension needed to unpack DOCX, and no local LibreOffice converter was available. No document was sent to an external preview service.
- Change: DOCX files now show a format-specific explanation, a direct “Download DOCX” action, and instructions to open the editable file in Word or LibreOffice. The review-card action is labeled “Open DOCX options” instead of implying the file can be previewed in RMS. PDF and text preview behavior is unchanged.
- Workaround for an in-browser RMS preview: upload a PDF copy; the original DOCX remains available for editing and download.
- Browser confirmation: user refreshed the DOCX viewer and confirmed the Word-document instructions and “Download DOCX” button appeared.
- Assistant checks: `php -l` passed for both changed PHP files; `git diff --check` passed for the changed files.
