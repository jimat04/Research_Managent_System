# Verification: student-faculty-profile-dropdown

- Date/time: 2026-11-07 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Student and faculty topbar profile menus provide Profile, Account settings, and Log out; neither role has a sidebar logout control.
- Environment URL: Not confirmed
- Roles and disposable records/files: Student and faculty accounts; no records changed
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Trace student/faculty shell logout placement and topbar profile menus. Selected terms: `student`, `faculty`, `shell`, `profile`, `logout`, `sidebar`.
- Query/lookup and result: `graphify query "student-shell.php faculty-shell.php logout sidebar" --budget 1200` found both role shells, the admin shell, and the shared logout handler/transition. It returned the relevant shell nodes and edges; it exceeded the requested token budget slightly.
- Relevant source locations and verified relationships: Current `includes/student-shell.php:288-301` and `includes/faculty-shell.php:336-349` render native `<details>` menus with shared profile/settings links and CSRF-protected logout POST forms. The sidebar session/logout blocks were removed from both shells. `includes/admin-shell.php` confirms the matching menu pattern.
- Missing, inferred, stale, or truncated context: The graph exposes shell-level relationships but not the new dropdown markup (added after graph generation). Some graph source line offsets differ from current files; current source was checked directly. No relevant relationship was truncated.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Integrated browser / student / dashboard | Inspect sidebar and open the profile menu | Sidebar has no Session/Logout entry; menu shows Profile, Account settings, and Log out | User confirmed “all passed” in response to the student check | Passed |
| S2 | Integrated browser / faculty / dashboard | Inspect sidebar and open the profile menu | Sidebar has no Session/Logout entry; menu shows Profile, Account settings, and Log out | Awaiting user observation | Pending |
| S3 | Source review | Inspect both role shell logout forms and sidebars | Topbar logout uses POST, CSRF field, and existing confirmation hook; sidebar logout is absent | Confirmed in current source | Passed |
| S4 | Integrated browser / student and faculty / narrow viewport | Open dropdown at narrow width | Menu stays visible and usable without horizontal overflow | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `rtk proxy php -l includes/student-shell.php` — passed: no syntax errors.
- `rtk proxy php -l includes/faculty-shell.php` — passed: no syntax errors.
- `rtk git diff -- includes/student-shell.php includes/faculty-shell.php css/student-shell.css css/faculty-shell.css` — reviewed; changes are limited to both shell menus and role stylesheets.
- `rtk rg -n 'nav-session|nav-logout|Logout|Log out|profile-menu|csrfField|data-logout-confirm' includes/student-shell.php includes/faculty-shell.php` — confirmed each shell retains its topbar menu/logout and the student Research Basics logout; no sidebar logout/session block remains.
- Browser interaction and visual layout have not been observed by the assistant.

## Resume / handoff
- Last user-confirmed state: Student sidebar/menu check passed.
- Next pending action: Ask the user to check the faculty sidebar and profile dropdown in the integrated browser.
- Blocked/skipped checks and remaining verification gaps: Browser confirmation for faculty and narrow viewports is pending. Logout submission itself was not triggered.
- User-performed data changes and cleanup status: None.
