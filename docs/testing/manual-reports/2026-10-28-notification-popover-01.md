# Notification popover verification

Date: 2026-10-28 (Asia/Taipei)

## Scope

Add a notification preview to the authenticated admin, research staff, faculty, and student shells. All and Unread each show up to five rows from the current user's own notifications. Reading/opening and mark-all actions post through the existing CSRF-protected notification handler. No database changes or test records were made.

## Graph lookup and source confirmation

- Selected vocabulary: `notifications`, `shell`, `open_record`.
- Queried the existing Graphify map for `notifications`; it matched three schema nodes and returned no usable focused traversal. Retried with `createNotification()` and the student shell symbol. The map returned authentication and shell nodes but truncated the neighborhoods.
- Graph hints are stale: `createNotification()` is listed at `includes/auth.php:L141`, while current source defines it at `L199`. Current source confirms the helper writes per-user notification rows. Shell source confirms all four role shells render the shared popover; current `pages/shared/notifications.php` confirms `open_record` selects and updates only a row matching both notification ID and current user ID.
- Coverage limitation: existing Graphify data predates current source changes and did not provide complete shell-to-page edges. Source inspection was used to confirm the affected relationships; the map was not rebuilt.

## Screenshot follow-up

- User-provided observation: the notification header, controls, and list appeared unstyled, with browser-default borders and bullets.
- Diagnosis: the popover styles are in `css/style.css`, but all four shells linked that shared stylesheet without a version query. Their role-specific stylesheets already use `filemtime()` cache keys, so a browser could retain an older shared stylesheet.
- Fix: added a `filemtime()` cache key to the shared stylesheet URL in all four role shells. Current source confirms the versioned URLs. No browser cache was cleared or browser action performed by the assistant.
- Follow-up graph lookup terms: `renderAdminShell`, `renderStudentShell`, `style.css`. `graphify query 'renderAdminShell()' --budget 550` and the corresponding `renderStudentShell()` query found the two shell definitions and close helpers. Graph close-helper locations remain stale after unrelated shell edits; current source wins. The map has no focused stylesheet dependency edge.

## Assistant-run checks

| Check | Status | Result |
|---|---|---|
| `php -l` on the shared component and four role shells | Passed | No syntax errors detected. |
| `php -l` on the four role shells after cache-key fix | Passed | No syntax errors detected. |
| `node --check js/notification-popover.js` | Passed | No syntax errors reported. |
| Shared CSS structural check | Passed | Opening and closing brace counts match (314/314). |
| `git diff --check` on changed tracked source | Passed | No whitespace errors; Git reported existing line-ending normalization notices. |
| Review current-user scoping and CSRF handling | Passed | Read query and POST handling are scoped to `user_id`; state changes use the existing CSRF token and user-scoped handler. |

## User-operated browser checks

| Check | Status | Observation |
|---|---|---|
| Reload the authenticated page to fetch the versioned shared stylesheet | Passed | User reports the updated interface is all working after the cache-key fix. |
| Open the bell and review All / Unread list in the current role shell | Passed | User confirms the notification popover is working; the individual role and item contents were not specified. |
| Open a linked notification and confirm its destination | Passed | User confirms this check individually. |
| Check narrow viewport layout and keyboard Escape/focus behavior | Passed | User confirms this check individually. |
| Repeat bell availability in all four roles | Passed | User confirms the role checks individually. |

## Completion

The user confirmed the updated notification interface and each listed browser check individually. The initial unstyled result and stylesheet cache fix are preserved above. All listed browser checks are passed based on the user's confirmation.
