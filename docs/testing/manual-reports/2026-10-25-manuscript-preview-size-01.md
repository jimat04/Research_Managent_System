# Verification: manuscript preview size

- Date/time: 2026-10-25, Asia/Taipei
- Assistant: GitHub Copilot
- Target and expected behavior: Increase the final manuscript inline preview height on desktop and mobile while preserving full-width responsive layout.
- Environment URL: http://localhost/rms/pages/shared/view-research.php?id=71
- Roles and disposable records/files: Student role; existing page viewed without modifying records or files.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How does the manuscript preview relate to the shared research detail page? Selected terms: `view-research`, `manuscript`, `document preview`.
- Query/lookup and result: `graphify query "view-research manuscript document preview" --budget 1200`; it found the `view-research.php` page and document preview nodes, but truncated 8 of 38 nodes.
- Relevant source locations and verified relationships: `pages/shared/view-research.php` renders the inline document in `.detail-document-frame`; its height is now responsive and larger on desktop and mobile.
- Missing, inferred, stale, or truncated context: Graph output was truncated and does not validate browser dimensions. Current CSS was checked directly; user visual confirmation is pending.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Assistant static check | Run `php -l pages/shared/view-research.php` | PHP parses without errors | `No syntax errors detected` | Passed |
| 2 | Assistant static check | Inspect desktop and mobile frame rules | Preview uses larger responsive heights at both breakpoints | Desktop `clamp(520px, 72vh, 900px)`; mobile `clamp(420px, 72vh, 720px)` | Passed |
| 3 | Student browser check / research detail page | Refresh the page | Inline manuscript preview is visibly taller while remaining full width | Student confirmed the larger preview is visible and looks right | Passed |
