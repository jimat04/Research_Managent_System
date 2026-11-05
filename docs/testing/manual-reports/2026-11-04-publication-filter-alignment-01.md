# Verification: publication filter year range alignment

- Date/time: 2026-11-04, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: On the publication list filter, custom From Year and To Year labels sit in a compact label column beside their inputs, without a large horizontal gap.
- Environment URL: `http://localhost:8080/rms/`
- Roles and disposable records/files: Not needed; visual-only filter check.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Which publication-list source owns the archive filter layout? Selected `archive`, `filter`, and `publication` from current graph labels.
- Query/lookup and result: `graphify query "publication archive filter" --budget 1500`; traversal found publication archive nodes and the older `pages/shared/publications.php` node.
- Relevant source locations and verified relationships: Current `includes/archival-record-list.php` renders the publication filter and custom year-range controls; it loads `css/archival-records.css` through the archival page shell. The graph result is stale for this current path, so the layout relationship was confirmed in current source and CSS.
- Missing, inferred, stale, or truncated context: Graphify did not identify the current archival list component; source inspection was used. Browser layout confirmation is pending.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Assistant static / PHP syntax | Run `php -l includes/archival-record-list.php` | No syntax errors | No syntax errors detected | Passed |
| 2 | Assistant static / changed files | Run `git diff --check` | No whitespace errors | No output | Passed |
| 3 | User browser / publication list / desktop and narrow viewport | Open the publication filter with a custom year range visible, then resize to a narrow viewport | Each year label sits close beside its input; fields remain usable without horizontal overflow | Awaiting user observation | Pending |
| 4 | User browser / publication list / desktop and narrow viewport | Refresh the publication filter and resize to a narrow viewport | Five dropdown fields form a centered group and wrap without horizontal overflow | Awaiting user observation | Pending |
| 5 | User browser / publication list | Refresh the filter action row | Search / Filter and Clear filters sit together on the left with a small gap | Awaiting user observation | Pending |

## Assistant-run checks
- `rtk proxy php -l includes/archival-record-list.php`: passed.
- `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check -- includes/archival-record-list.php css/archival-records.css`: passed.
- Graphify query: `rtk proxy graphify query "publication archive filter" --budget 1500`.

## Resume / handoff
- Fix: align each year label beside its input in a compact 110–140px label column, reduce the row/column gap, and associate labels with input IDs.
- Follow-up: center the five publication dropdowns as a compact responsive group while leaving the search field full width.
- Follow-up: left-align Search / Filter and Clear filters in a shared action row with a 12px gap.
- Next pending action: User opens the publication list, selects Custom Range (or opens a URL with a year bound), checks desktop and narrow widths, and reports whether the layout aligns and avoids overflow.
- Browser retest is pending user action. No records were changed.
