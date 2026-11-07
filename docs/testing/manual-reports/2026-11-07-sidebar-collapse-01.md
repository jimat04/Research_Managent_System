# Verification: sidebar collapse behavior

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The desktop sidebar collapse button should collapse and restore the active role sidebar consistently across pages.
- Environment URL: Not confirmed
- Roles and disposable records/files: Pages across roles; no records or files changed.
- Session state: Finished.

## Graphify and source context
- Question and selected graph vocabulary: How do the shared role shells render and close? Selected graph term: `shell`.
- Query/lookup and result: `graphify query "shell" --budget 1500` returned Admin and Staff shell nodes and their render/close functions. A follow-up lookup for `role-shell-controls is-collapsed` did not expose the JavaScript control node; the graph is incomplete for this behavior.
- Relevant source locations and verified relationships: All four role shells (`includes/{admin,staff,faculty,student}-shell.php`) render a `[data-role-shell]` root, a `[data-role-shell-sidebar]` element and a `[data-role-shell-collapse]` button, and load `js/role-shell-controls.js` from their close helper. The shared script at `js/role-shell-controls.js:1-46` restores role-specific localStorage state, toggles the root `is-collapsed` class, and updates the button label. Each role stylesheet defines matching `.is-collapsed` width, margin and label rules. Current-source review found consistent hooks across the four shells.
- Missing, inferred, stale, or truncated context: Graphify has no useful node for `role-shell-controls.js`; the `shell` query did not trace the client-side collapse behavior. Source search found no screen rule overriding collapsed width/margin across pages; the only extra main-margin override in `css/admin-reports.css` is inside `@media print`. Exact failing routes and browser console/network observations are not yet known, so the original page-specific trigger cannot be confirmed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Static source review / four role shells and stylesheets | Compare shared script inclusion, data hooks and collapsed selectors | Every role shell exposes the same functional control path | Hooks and CSS selectors are present in all four shells; shared handler is attached in source | Passed |
| B1 | Integrated browser / Research Capacity Building / role not specified | Click the collapse button at desktop width | Sidebar collapses and the button works | User reports the button is now working on this page; exact visual change not specified | Passed (user-reported) |
| B2 | Integrated browser / affected pages | Retest the collapse button after the shared-control change | Sidebar collapses and expands correctly across the affected pages | User reported: “all passed.” Exact routes and individual visual details were not supplied | Passed (user-reported) |

## Failures, decisions, fixes, and retests
- Hardened `js/role-shell-controls.js`: the collapse action now uses a document-level capture handler keyed to the shell/button data attributes, so it survives page-specific bubbling handlers and replacement of the original button node. The button also exposes its expanded state. User retest passed; exact routes were not provided.

## Assistant-run checks
- Read-only Graphify lookups and targeted source review completed. No application interaction, PHP/JavaScript test, database command, or data change was performed.
- `node --check js/role-shell-controls.js`: passed.
- `git diff --check -- js/role-shell-controls.js docs/testing/manual-reports/2026-11-07-sidebar-collapse-01.md`: passed.

## Resume / handoff
- Last user-confirmed state: User reports all collapse checks passed after the shared-control change.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: Exact page routes and individual visual observations were not supplied; browser result is recorded as user-reported.
- User-performed data changes and cleanup status: None.
