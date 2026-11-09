# Verification: faculty mobile menu

- Date/time: 2026-11-09 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: At mobile width, the faculty navigation button opens the sidebar and sets `aria-expanded="true"`.
- Environment URL: Current integrated browser faculty page; URL not captured.
- Roles and disposable records/files: Authorized faculty account; no records or files needed.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: Why does the faculty mobile menu close immediately? `faculty-shell.php`, `faculty-dashboard.php` (actual graph labels).
- Query/lookup and result: `graphify query "faculty-shell.php faculty-dashboard.php" --budget 1500`; the map relates the faculty page and shared shell but does not include the drawer handler in `js/faculty-dashboard.js`.
- Relevant source locations and verified relationships: `includes/faculty-shell.php` loads `js/faculty-dashboard.js`; the shell markup contains the menu button. Before the fix, the shell also attached an inline click handler. `js/faculty-dashboard.js` attaches the remaining drawer click handler in `initSidebarDrawer()`.
- Missing, inferred, stale, or truncated context: Graphify coverage omits the JavaScript drawer handler; confirmed against current source. Query output exceeded the requested budget but included all returned nodes and edges.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| FM-1 | Integrated browser / faculty / existing faculty page at mobile width | Tap the faculty navigation button once | Sidebar drawer and scrim appear; button reports expanded | User confirmed all checks passed | Passed |

## Failures, decisions, fixes, and retests
- Initial source finding: two click handlers toggled the same drawer, causing the second handler to close it after the first opened it.
- Fix: removed the duplicate inline shell handler; the existing `initSidebarDrawer()` handler remains the single owner.
- User confirmed the browser retest passed.

## Assistant-run checks
- `rtk proxy php -l includes/faculty-shell.php` — passed: no syntax errors.
- `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms -C C:\xampp\htdocs\rms diff --check -- includes/faculty-shell.php` — passed: no whitespace errors in the changed file.
- No application interaction or database action was performed by the assistant.

## Resume / handoff
- Last user-confirmed state: Mobile menu browser check passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: No persistence/data checks apply to this UI-only control.
- User-performed data changes and cleanup status: None.
