# Verification: Generated Document A4 Preview

- Date/time: 2026-11-05, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Shared generated-document DOCX previews use A4 page dimensions while retaining portrait/landscape orientation; Annex E's floating header seal does not cover letterhead text; saved files and download/edit behavior stay unchanged.
- Environment URL: Local RMS; no application page opened by the assistant.
- Roles and disposable records/files: Faculty generated-document history (primary DOCX and QSF companion) and Admin Annex D history. No record or file was changed by the assistant.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: Trace the shared generated-document preview rendering and its source page orientation. Selected graph term: `document-preview.php`.
- Query/lookup and result: `rtk proxy graphify query "document-preview" --budget 1500` returned 9 nodes, including the older generic `includes/document-preview.php` helper and staff document verification nodes. It did not cover the EARIST DOCX preview renderer.
- Relevant source locations and verified relationships: `includes/earist-document-ui.php:51` defines the shared preview dialog; `pages/faculty/faculty-generated-documents.php:49,52,118` supplies primary/QSF preview controls and renders the dialog; `pages/admin/admin-earist-annex-d.php:319,333` supplies Annex D preview and renders the same dialog. `public/assets/js/earist-document-preview.js:77-99` renders DOCX sections, adjusts the Annex E header image after rendering, and tags landscape sections based on renderer-provided dimensions. `css/earist-documents.css:98-99` applies A4 portrait/landscape dimensions. The approved Annex E header XML uses a floating 1-inch image anchored in the first centered letterhead paragraph, matching the overlap in the supplied screenshot.
- Missing, inferred, stale, or truncated context: Graphify coverage for the current EARIST preview and DOCX renderer is absent. Source/header XML was inspected read-only; current browser behavior after the correction remains pending. No page content was opened by the assistant.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / JavaScript syntax | Run `rtk proxy node --check public/assets/js/earist-document-preview.js` | Preview script parses | Command completed with no syntax errors | Passed |
| S2 | Assistant / CSS structure | Count opening and closing braces in `css/earist-documents.css` | Counts match | 138 opening / 138 closing braces | Passed |
| S3 | Assistant / source review | Trace shared preview call sites and render behavior | Faculty primary/QSF and Admin Annex D use the shared renderer; orientation is detected from source dimensions | Confirmed at the locations listed above | Passed |
| B1 | User browser / Faculty / Generated Documents | Open Preview DOCX for a saved portrait document | Rendered page appears A4 portrait; Annex E seal stays clear of letterhead text | Initial preview-only CSS correction did not move the seal; user retested and supplied a screenshot confirming it still overlaps. Updated the correction to set the rendered image's inline translate after DOCX rendering. Retest pending. | Failed twice / Pending retest |
| B2 | User browser / Faculty / Generated Documents | Preview a landscape or multi-page document | Landscape pages remain landscape; each page uses A4 dimensions and scrolls correctly | Awaiting user observation and suitable saved document | Pending |
| B3 | User browser / Faculty / Generated Documents | Open Preview QSF companion | Companion preview uses the shared A4 sizing and remains readable | Awaiting user observation and a saved QSF companion | Pending |
| B4 | User browser / Admin / Annex D history | Open Preview DOCX | Annex D preview uses the shared A4 sizing | Awaiting user observation | Pending |
| B5 | User browser / Faculty and Admin / narrow viewport | Resize the preview to a narrow viewport and scroll the pages | A4 page proportions remain intact and horizontal/vertical scrolling reaches the page content | Awaiting user observation | Pending |
| B6 | User browser / Faculty / Generated Documents | Download and use the existing edit control after preview | Existing actions still target the saved DOCX; preview creates no version or file changes | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- User screenshot exposed the Annex E floating header seal overlapping the centered letterhead. The supplied template's `word/header1.xml` confirms the image is anchored in the same paragraph as the first centered line. The initial scoped CSS transform was ineffective; the user retest confirmed the overlap persisted. Updated the preview to set a 1.25-inch inline translate on Annex E header images immediately after DOCX rendering, which bypasses the missed stylesheet selector. This changes only the preview DOM; it does not modify the generated file, template, download, or history. Browser retest is pending.

## Assistant-run checks
- `rtk proxy node --check public/assets/js/earist-document-preview.js` — passed.
- CSS brace count — passed (138 opening / 138 closing braces) after removing the ineffective CSS selector.
- Source review confirmed that the shared preview renderer is used by Faculty generated documents and Admin Annex D; no generation, download, edit, or database code was changed.

## Resume / handoff
- Last user-confirmed state: Not applicable; no browser interaction has occurred in this session.
- Next pending action: As Faculty, reopen Annex E Version 3 in Generated Documents and report whether the seal is now clear of all letterhead text and the page remains readable.
- Blocked/skipped checks and remaining verification gaps: Landscape, QSF, Admin, and narrow-viewport browser checks remain pending until suitable documents/pages are available.
- User-performed data changes and cleanup status: None reported.
