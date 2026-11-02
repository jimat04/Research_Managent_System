# Verification: activity calendar and time fields

- Date/time: 2026-10-28, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Replace browser-default date and time popups for activity scheduling with branded pickers, while preserving the existing submitted values and server-side schedule handling.
- Environment URL: Not checked
- Roles and disposable records/files: Admin or authorized research staff; no record changes planned for the first visual check.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How the activity form renders schedule dates and times; `activity`, `schedule`, `time`.
- Query/lookup and result: Selected `activity`, `schedule`, `time`, and `input`; ran `graphify query "activity schedule time input" --budget 1500`. It included the activity form node but was truncated and returned unrelated nodes too.
- Relevant source locations and verified relationships: `pages/shared/activity-form.php` renders `window-start` and `window-end` date inputs plus session `start_time` and `end_time` inputs. `includes/activity-schedule.php` validates submitted times as 24-hour `HH:MM` (or `HH:MM:SS`) and the form saves those existing values. Both custom pickers update their original inputs and keep the form field names.
- Missing, inferred, stale, or truncated context: Graph results were truncated; direct current-source inspection confirmed the relevant relationship. Browser retest pending.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Admin or authorized research staff / Activities > Add activity | Open the browser date picker | The original Chrome popup appeared plain, as shown in the user's screenshot | User screenshot | Failed |
| A2 | Admin or authorized research staff / Activities > Add activity | Refresh the form page and use the date calendar | The custom calendar appears and works | User reported: “the custom calendar is working” | Passed |
| A3 | Admin or authorized research staff / Activities > Add activity | Open a session time picker | A styled time panel shows hour, minute, and AM/PM controls | User reported the time picker was not working | Failed |
| A4 | Admin or authorized research staff / Activities > Add activity | Reload the form after the fix | The activity form reloads normally with the updated picker code | Awaiting user observation | Pending |
| A5 | Admin or authorized research staff / Activities > Add activity | Open a visible session time field | A styled panel opens with hour, minute, and AM/PM controls | Awaiting user retest | Pending |

## Failures, decisions, fixes, and retests
- A1 did not meet the intended visual design. The user asked for a designed calendar and time picker; browser-native popups cannot be restyled with page CSS. A2 passed. A3 exposed a selector mismatch: the time panel created unique hour/minute IDs but queried generic IDs. The selectors now use the generated IDs; A4 is pending.

## Assistant-run checks
- `php -l pages/shared/activity-form.php` — passed: no syntax errors.
- `node --check` on the extracted activity calendar script — passed: no syntax errors.
- `node --check` on the extracted activity time picker script — passed: no syntax errors.
- `git -C C:/xampp/htdocs/rms -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/shared/activity-form.php` — passed: no whitespace errors reported.
- No application interaction was performed by the assistant.

## Resume / handoff
- Last user-confirmed state: The custom calendar works; the time picker failed before the selector fix.
- Next pending action: User reloads the activity form; then retry a visible session time field.
- Blocked/skipped checks and remaining verification gaps: Browser appearance, responsive layout, keyboard navigation, time selection, and persistence remain unverified.
- User-performed data changes and cleanup status: None.
