# Verification: remove redundant faculty reports view

- Date/time: 2026-10-28, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Faculty sidebar no longer offers the redundant Accomplishment Summary or a Notifications link duplicated by the header control; Research Analytics and achievements remain available, and an obsolete `view=reports` URL falls back to the default overview.
- Environment URL: `http://localhost/rms/` (configured fallback; availability not confirmed)
- Roles and disposable records/files: Faculty; read-only review, no record changes planned.
- Session state: finished

## Graphify and source context
- Question and selected graph vocabulary: How does the faculty shell link to dashboard views? Selected graph terms: `faculty-dashboard.php`, `faculty-shell.php`, `renderFacultyShell()`.
- Query/lookup and result: `graphify query "faculty-dashboard.php faculty-shell.php renderFacultyShell" --budget 1100` found 65 nodes and was truncated (32 shown), including the faculty shell and render helpers; no useful edge connected the reports view to the achievements page. After the user requested removal of the duplicate sidebar Notifications link, `graphify query "faculty-shell.php notifications.php" --budget 900` found 69 nodes and was truncated (28 shown), including the faculty shell and shared notifications page; direct source confirmed the header popover is rendered separately.
- Relevant source locations and verified relationships: `includes/faculty-shell.php` defines the sidebar item; `pages/faculty/faculty-dashboard.php` accepts `reports` and renders a link list to the achievements, publications, and engagement destinations. `pages/faculty/faculty-profile.php` retains the actual Research achievements tab and its accomplishment metrics. Current source confirms the behavior.
- Limitations: Graph traversal was truncated and did not show the reports view relationship; source inspection confirmed the duplicate links.

## Checks
| Check | Expected | Observation | Status |
|---|---|---|---|
| Assistant: `php -l pages/faculty/faculty-dashboard.php` | No syntax errors | `No syntax errors detected` | Passed |
| Assistant: `php -l includes/faculty-shell.php` | No syntax errors | `No syntax errors detected` | Passed |
| Assistant: focused search for removed routes and sidebar links | Reports-only references and duplicate sidebar notifications link are absent | No matches; header `renderNotificationPopover()` and Research Analytics remain in source | Passed |
| Faculty: navigation | No Accomplishment Summary or Notifications sidebar item; Research Analytics remains; header notification control remains | User confirmed navigation is correct | Passed |
| Faculty: achievements destination | Research achievements tab remains available and shows accomplishment metrics | User confirmed achievements remain | Passed |
| Faculty: obsolete `view=reports` URL | Falls back to overview | User confirmed the overview opens | Passed |

## Results and follow-up
- Database effects: none.
- Browser observations: user confirmed both redundant sidebar links are gone, Research Analytics remains, the header notification control remains, the achievements tab is still available, and the obsolete reports URL opens the overview.
- Source review: `reports` is no longer an allowed dashboard view and its rendering branch is removed. Invalid/legacy `view=reports` falls back to `overview`; the Research Analytics link remains under Research & Resources. The sidebar Notifications link is removed while the header `renderNotificationPopover()` remains. The Research achievements profile tab and its metrics remain unchanged.
- Next action: none.
