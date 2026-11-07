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
- Relevant source locations and verified relationships: `includes/earist-document-ui.php:51` defines the shared preview dialog; `pages/faculty/faculty-generated-documents.php:49,52,118` supplies primary/QSF preview controls and renders the dialog; `pages/admin/admin-earist-annex-d.php:319,333` supplies Annex D preview and renders the same dialog. `public/assets/js/earist-document-preview.js:77-105` renders DOCX sections with `breakPages: true`, adjusts the Annex E floating drawing wrapper after rendering, and tags landscape sections based on renderer-provided dimensions. The bundled renderer also defaults `breakPages` to true and groups on explicit page breaks/section properties. The approved Annex E template contains no explicit page-break elements and one section property, so it is a single page; lack of a page gap cannot explain an overlap inside its header. `css/earist-documents.css:98-99` applies A4 portrait/landscape dimensions. The header XML uses a floating 1-inch image anchored in the first centered letterhead paragraph, matching the overlap in the supplied screenshot.
- Missing, inferred, stale, or truncated context: Graphify coverage for the current EARIST preview and DOCX renderer is absent. Source/header XML was inspected read-only; current browser behavior after the correction remains pending. No page content was opened by the assistant.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / JavaScript syntax | Run `rtk proxy node --check public/assets/js/earist-document-preview.js` | Preview script parses | Command completed with no syntax errors | Passed |
| S2 | Assistant / CSS structure | Count opening and closing braces in `css/earist-documents.css` | Counts match | 138 opening / 138 closing braces | Passed |
| S3 | Assistant / source review | Trace shared preview call sites and render behavior | Faculty primary/QSF and Admin Annex D use the shared renderer; orientation is detected from source dimensions | Confirmed at the locations listed above | Passed |
| B1 | User browser / Faculty / Generated Documents | Reload the page, then preview Annex E Version 3 and scroll through the document | A4 pages are visibly separated where present; Annex E seal stays clear of letterhead text | User confirmed a fresh-page retest still showed overlap and suggested missing page separation. Source confirms explicit page breaks are enabled; the blank Annex E template has one section and no explicit page-break markers. A broader positioned drawing-wrapper correction was added; retest pending. | Failed three times / Pending retest |
| B2 | User browser / Faculty / Generated Documents | Preview a landscape or multi-page document | Landscape pages remain landscape; each page uses A4 dimensions and scrolls correctly | Awaiting user observation and suitable saved document | Pending |
| B3 | User browser / Faculty / Generated Documents | Open Preview QSF companion | Companion preview uses the shared A4 sizing and remains readable | Awaiting user observation and a saved QSF companion | Pending |
| B4 | User browser / Admin / Annex D history | Open Preview DOCX | Annex D preview uses the shared A4 sizing | Awaiting user observation | Pending |
| B5 | User browser / Faculty and Admin / narrow viewport | Resize the preview to a narrow viewport and scroll the pages | A4 page proportions remain intact and horizontal/vertical scrolling reaches the page content | Awaiting user observation | Pending |
| B6 | User browser / Faculty / Generated Documents | Download and use the existing edit control after preview | Existing actions still target the saved DOCX; preview creates no version or file changes | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- User screenshot exposed the Annex E floating header seal overlapping the centered letterhead. The supplied template's `word/header1.xml` confirms the image is anchored in the same paragraph as the first centered line. The initial scoped CSS transform and image-node correction were ineffective; a fresh-page retest ruled out stale caching. Broadened the wrapper lookup to find positioned inline-block drawing divs by their left/top styles, and set `breakPages: true` explicitly. Source inspection confirms page breaks were already enabled by default; this Annex E template has no explicit page break and one section property, so it produces one page. Page separation is not the cause of the within-header overlap. Browser retest of the broadened drawing-wrapper adjustment is pending.

## Assistant-run checks
- `rtk proxy node --check public/assets/js/earist-document-preview.js` — passed.
- CSS brace count — passed (138 opening / 138 closing braces) after removing the ineffective CSS selector.
- Source review confirmed that the shared preview renderer is used by Faculty generated documents and Admin Annex D; no generation, download, edit, or database code was changed.

## Resume / handoff
- Last user-confirmed state: Not applicable; no browser interaction has occurred in this session.
- Next pending action: As Faculty, reopen Annex E Version 3 in the freshly loaded Generated Documents page, scroll through it, and report whether its pages are visibly separated and the seal is clear of the letterhead.
- Blocked/skipped checks and remaining verification gaps: Landscape, QSF, Admin, and narrow-viewport browser checks remain pending until suitable documents/pages are available.
- User-performed data changes and cleanup status: None reported.
