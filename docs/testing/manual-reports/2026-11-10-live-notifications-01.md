# Verification: live notifications

- Date/time: 2026-11-10 04:09, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Bell, popover, and student/faculty navigation notification counts update automatically within 30 seconds.
- Environment URL: `http://localhost:8080/rms/`
- Roles and disposable records/files: Roles and disposable notification records are not yet confirmed.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How notification rendering relates to role shells, authentication, and notification records. Selected terms: `notifications`, `notifications.php`, `renderStudentShell`, `renderFacultyShell`, `isLoggedIn`.
- Query/lookup and result: Read-only lookup in `graphify-out/graph.json`. Relevant nodes included `notifications.php`, `renderStudentShell()`, `renderFacultyShell()`, and `isLoggedIn()`; current graph links include `auth.php` containing `isLoggedIn()` and schema defining `notifications`.
- Relevant source locations and verified relationships: Current shells call `renderNotificationPopover()` and include `js/notification-popover.js`; the shared renderer loads counts and latest rows; the new endpoint checks `isLoggedIn()` and filters notification queries by the session user ID. Student/faculty group counts reuse `getUnreadNotificationCountsByArea()`.
- Missing, inferred, stale, or truncated context: The graph does not represent the new status endpoint or the notification popover component relationships; those were confirmed against current source. No application browser observation has been made yet.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Integrated browser / student / local RMS | Open the local application | Login page or current authenticated student page appears | Awaiting user observation | Pending |
| 2 | Integrated browser / student | Receive a notification while a student page remains open | Bell/popover and applicable group badges update without a refresh within 30 seconds | Requires a suitable disposable notification flow; not yet confirmed | Pending |
| 3 | Integrated browser / faculty, staff, admin | Repeat live badge and popover check for each role shell | Each shell updates without refresh; faculty group badge follows its area count | Requires suitable authorized accounts and disposable notifications; not yet confirmed | Pending |
| 4 | Source review / authenticated endpoint | Confirm user scoping, read-only behavior, and safe rendering | Endpoint uses only session identity, no-store JSON, prepared user-scoped queries, and client text nodes | Confirmed in current source; browser/data-boundary exercise not run | Passed |
| 5 | PHP CLI / changed PHP | Run `php -l` on endpoint, renderer, and student/faculty shells | No PHP syntax errors | All four commands passed | Passed |
| 6 | Node.js / changed JavaScript | Run `node --check js/notification-popover.js` | No JavaScript syntax errors | Passed | Passed |
| 7 | Git / changed files | Run `git diff --check` on implementation files | No whitespace errors | Passed; Git reported line-ending normalization notices for three files | Passed |

## Failures, decisions, fixes, and retests
- No functional failures observed yet. Browser checks are pending.

## Assistant-run checks
- `php -l pages/shared/notification-status.php` — passed.
- `php -l includes/notification-popover.php` — passed.
- `php -l includes/student-shell.php` — passed.
- `php -l includes/faculty-shell.php` — passed.
- `node --check js/notification-popover.js` — passed.
- `git diff --check` on implementation files — passed; line-ending normalization notices remain informational.
- No database writes or browser interactions were performed by the assistant.

## Resume / handoff
- Last user-confirmed state: None yet.
- Next pending action: User opens the local RMS application in the integrated browser and reports whether the login page or an authenticated student page appears.
- Blocked/skipped checks and remaining verification gaps: Live polling behavior and cross-role browser checks await user-operated verification and suitable disposable notification data.
- User-performed data changes and cleanup status: None reported.
