# Verification: publication error navigation

- Date/time: 2026-11-01, Asia/Taipei
- Assistant: GitHub Copilot
- Target and expected behavior: On admin and faculty publication forms, clicking a field-specific error smoothly navigates to its inline error target with the sticky header accounted for. Reduced-motion preferences are respected. General request-level errors remain plain summary text.
- Environment URL: http://localhost/rms/pages/admin/admin-publication-form.php
- Roles and disposable records/files: Admin; no record changes intended.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: How does the publication page relate to form scrolling and error navigation? Matched terms `publication-form.php`, `publications.php`, `createPublication()`, and `errorElement()`.
- Query/lookup and result: `graphify query "publication-form.php publications.php" --budget 1200`; the graph returned the older shared publications page and E2E nodes, with 61 nodes and truncated output.
- Relevant source locations and verified relationships: Current `includes/publication-archive-form.php` renders the top error summary and per-field errors. Its `$errorFor` closure gives inline messages IDs of the form `error-{key}`; publication field validation in `includes/publication-classification.php` supplies keyed errors. Current `css/admin-shell.css` and `css/faculty-shell.css` confirm sticky shell headers; the scroll handler measures the rendered header at click time.
- Missing, inferred, stale, or truncated context: Graphify does not cover the current archive form error-summary path or shell topbar nodes in the returned results; output was truncated. Current source was used to verify these relationships. No current browser observation yet.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / PHP syntax | Run `php -l includes/publication-archive-form.php` | No syntax errors | `No syntax errors detected in includes/publication-archive-form.php` | Passed |
| S2 | Assistant / JavaScript syntax and editor diagnostics | Run `node --check js/publication-classification.js`; inspect diagnostics for touched JS and CSS | No syntax or editor errors | Node check completed successfully; no diagnostics found | Passed |
| B1 | User browser / admin / Add Publication | Leave the required publication title empty and submit the form | The form is not saved and the error summary includes a publication-title error link | Awaiting user observation | Pending |
| B2 | User browser / admin / Add Publication | Click the publication-title error link in the summary | The title error is focused and visible below the sticky topbar after a smooth jump | Pending; perform after B1 | Pending |
| B3 | User browser / faculty / Add Publication | Leave the required publication title empty and submit the form | The form is not saved and the error summary includes a publication-title error link | Pending; perform after admin checks | Pending |
| B4 | User browser / faculty / Add Publication | Click the publication-title error link in the summary | The title error is focused and visible below the sticky topbar after a smooth jump | Pending; perform after B3 | Pending |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- `php -l includes/publication-archive-form.php` — passed; no syntax errors.
- `node --check js/publication-classification.js` — passed; no syntax errors.
- Editor diagnostics for `js/publication-classification.js` and `css/publication-archive.css` — no errors found.

## Resume / handoff
- Last user-confirmed state: none for this check.
- Next pending action: Admin browser check B1.
- Blocked/skipped checks and remaining verification gaps: Admin navigation check B2 and faculty checks B3-B4 are pending; browser behavior has not been confirmed by the user.
- User-performed data changes and cleanup status: none.
