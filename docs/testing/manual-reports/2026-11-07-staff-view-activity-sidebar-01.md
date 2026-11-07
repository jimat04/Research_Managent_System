# Staff Research Activities parent link

Date: 2026-11-07 (Asia/Taipei)

## Question

When research staff click the Research Activities group label in the sidebar, does it open the activity overview while the View group contains only Upcoming, Ongoing, and Completed?

## Graphify review

- Selected existing vocabulary: `renderStaffShell()`, `staff-shell.php`, `admin-shell.php`, `Research Activities`.
- Ran `graphify query 'staff-shell.php renderStaffShell moduleLinks sidebar' --budget 1500`.
- It returned 63 nodes and truncated at 41. Useful hints were `renderStaffShell()` at `includes/staff-shell.php:L28`, `staff-shell.php` in the Role Navigation Shells community, `rmsPermissionModules()` at `includes/auth.php:L221`, and `admin-shell.php` at `includes/admin-shell.php:L1`.
- Current-source confirmation: `renderStaffShellNavigationNode()` in `includes/staff-shell.php` rendered every group label as a disclosure-only `<summary>`. The Research Activities staff node had children but no destination. The admin Research Activities parent already routes through `pages/shared/activities.php` (`includes/admin-shell.php`, around lines 58–63). The staff View Activity route is `pages/admin/admin-research-module.php?module=view_activity` (`pages/admin/admin-research-module.php`, around line 23), which is page-grant checked and routes to the activity overview.
- No complete graph path was returned. The lookup was broad and truncated; current source is authoritative.
- Repeated a focused lookup for `renderStaffShellNavigationNode`; the existing graph has no matching node. The source locations above were reviewed directly instead.
- After removing the duplicate child and relabeling the parent grant, repeated `graphify query 'renderStaffShell rmsPermissionModules' --budget 1400`. It returned 35 nodes and 58 edges; all nodes fit, though output included unrelated auth helpers. Confirmed `rmsPermissionModules()` at `includes/auth.php:L221`, `renderStaffShell()` at `includes/staff-shell.php:L28`, and permission-matrix callers. The map has no dedicated sidebar-node/render-helper node, so final navigation details were confirmed from current source.

## Changes and assistant checks

| Check | Status | Result |
|---|---|---|
| Research Activities label destination | Implemented | Staff group label receives the overview route only when its page grant is present; otherwise it remains a disclosure-only group. |
| Duplicate View Activity child | Implemented | Removed from the View submenu; its permission row is now labeled Research Activities to govern the parent destination. |
| Submenu disclosure | Implemented | Existing `<details>/<summary>` disclosure and status links remain. The chevron still toggles the submenu. |
| Keyboard focus | Implemented | The new group link retains a visible focus outline. |
| PHP syntax (`php -l`) | Passed | No syntax errors in `includes/staff-shell.php`, `includes/auth.php`, `pages/admin/admin-research-module.php`, or `pages/staff/staff-dashboard.php`. |
| Whitespace check | Passed | `git diff --check` reports no whitespace errors; Git emitted only existing line-ending conversion warnings. |
| Browser navigation as research staff | Passed (user-reported) | User confirmed all checks passed: Research Activities opens the activity overview, and the View group contains only Upcoming, Ongoing, and Completed. |

## User observations

User reported: “all passed.” This confirms the requested sidebar behavior in the integrated browser.
