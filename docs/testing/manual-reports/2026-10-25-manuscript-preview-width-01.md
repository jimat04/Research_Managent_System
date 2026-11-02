# Verification: manuscript preview width

- Date/time: 2026-10-25, Asia/Taipei
- Assistant: GitHub Copilot
- Target and expected behavior: The research detail page removes the manuscript description and displays the title and inline document preview at full section width.
- Environment URL: http://localhost/rms/pages/shared/view-research.php?id=71
- Roles and disposable records/files: Student role; existing view only, no records or files changed.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How does the manuscript preview relate to the shared research detail page? Selected terms: `manuscript`, `document preview`, `view-research`.
- Query/lookup and result: `graphify query "manuscript document preview view-research" --budget 1200`; it found `view-research.php` and document preview nodes, but truncated 8 of 38 nodes.
- Relevant source locations and verified relationships: Current source in `pages/shared/view-research.php` conditionally renders the final manuscript section; its iframe loads the inline document route. The section now has one full-width grid column.
- Missing, inferred, stale, or truncated context: Graph output was truncated and cannot verify rendered dimensions. Current source was checked directly; user browser confirmation remains pending.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Assistant static check | Run `php -l pages/shared/view-research.php` | PHP parses without errors | `No syntax errors detected` | Passed |
| 2 | Assistant static check | Check description and preview layout source | Description paragraph is absent; document section uses one full-width column | Target paragraph text is absent; `.detail-document-section` uses `minmax(0, 1fr)` | Passed |
| 3 | Student browser check / research detail page | Refresh the page | Final manuscript heading and inline preview appear, with preview spanning the section width | Student confirmed the paragraph is gone and the preview spans the section width | Passed |
