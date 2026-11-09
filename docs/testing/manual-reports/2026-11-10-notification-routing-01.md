# Verification: notification routing

- Date/time: 2026-11-10, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: A chapter notification opened from the bell popover should open the same valid destination as the corresponding item on `notifications.php`.
- Environment URL: Live site shown in the user-provided screenshot; full URL omitted.
- Roles and disposable records/files: User is viewing the live site as System Administrator; the affected item is a chapter notification.
- Session state: Finished.

## Graphify and source context
- Question and selected graph vocabulary: How the bell popover renders and opens notification records. Selected terms: `notification-popover.js`, `renderNotificationPopover`, `notification-status.php`.
- Query/lookup and result: Read-only `graphify query "notification-popover.js renderNotificationPopover notification-status.php" --budget 1500` returned 23 nodes and 26 edges over budget; the graph has no useful node for the popover JavaScript or status endpoint. Earlier workflow lookups were truncated. Current source and the user's comparison were used to confirm the redirect path.
- Relevant source locations and verified relationships: `includes/notification-popover.php` renders the bell's item forms and a menu form containing a hidden `name="action"` input. `js/notification-popover.js` rebuilds item forms from `pages/shared/notification-status.php`; before the fix it read `actionForm.action`. The form's named control can shadow that property, yielding the element string. On the full `pages/shared/notifications.php` page the user reports that opening the same notification works.
- Missing, inferred, stale, or truncated context: Graphify does not represent the popover JS/endpoint relationships. The exact browser behavior was reported by the user; no direct app interaction was performed by the assistant.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | User-reported live observation / admin / bell popover | Open a chapter notification from the bell | The notification opens its valid record destination | The user reports the popover goes to `/pages/admin/[object HTMLInputElement]`. | Failed (user-reported) |
| 2 | User-reported live observation / admin / `notifications.php` | Open the corresponding notification from the full page | The same valid record destination opens | User says notification links inside `notifications.php` work. | Passed (user-reported) |
| 3 | Source review | Compare server-rendered full-page links with bell popover forms | Both paths submit/open the same valid destination | Full page uses its own valid item form. Popover refresh code reads the menu form's `.action` property despite its hidden `name="action"` control. | Passed |
| 4 | Node.js / changed JavaScript | Run `node --check js/notification-popover.js` | No JavaScript syntax errors | Passed. | Passed |
| 5 | User-reported deployment state | Replace `js/notification-popover.js` from the prepared ZIP | The updated popover JavaScript is in place | User confirms the file was uploaded. | Passed (user-reported deployment only) |
| 6 | User-operated browser / admin | Click the same chapter notification in the bell popover once | Chapter Review opens instead of the malformed admin path | User confirms: “Chapter Review opens.” | Passed (user-reported) |

## Failures, decisions, fixes, and retests
- Preserved the reported failure at `/pages/shared/[object HTMLInputElement]` and then `/pages/admin/[object HTMLInputElement]`.
- User clarified that the failure is limited to the bell popover; opening the same notification from `notifications.php` works. This isolates the issue to popover JavaScript rather than the notification row's saved destination.
- Root cause: `renderPopoverRows()` read `actionForm.action`, but the menu form contains `<input name="action">`; the named control can shadow the DOM form property and stringify as `[object HTMLInputElement]`.
- Changed the popover to read the literal action attribute via `getAttribute('action')`. The earlier server fallback was removed because it did not address the popover-only failure. User reports the JavaScript package was uploaded and confirms the same bell notification now opens Chapter Review.

## Assistant-run checks
- `node --check js/notification-popover.js` — passed.
- `git diff --check -- js/notification-popover.js` — passed; Git emitted only a line-ending normalization notice.
- No browser interaction or database query/write was performed by the assistant.

## Resume / handoff
- Last user-confirmed state: Full notification page works; after deploying the JavaScript fix, the bell popover opens Chapter Review.
- Next pending action: None for the reported admin flow.
- Blocked/skipped checks and remaining verification gaps: Other role shells were not browser-tested; the shared JavaScript component was checked in the admin shell only.
- User-performed data changes and cleanup status: No database changes reported. Generated deployment ZIPs for this fix were removed after the successful browser check.
