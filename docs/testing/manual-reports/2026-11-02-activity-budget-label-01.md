# Verification: activity budget label

- Date/time: 2026-11-02 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The organizational allocation field in the activity form displays the label “Institutional budget”; its submitted field name and value handling stay unchanged.
- Environment URL: http://localhost/rms/
- Roles and disposable records/files: Authorized admin or research staff; no records changed.
- Session state: In progress, awaiting user browser observation.

## Graphify and source context
- Question and selected graph vocabulary: Which shared activity form renders the organizational allocation label? Selected existing graph labels `activity-form.php`, `activityFormEscape()`, and `activity-detail.php`.
- Query/lookup and result: `graphify query "activity-form.php organizational allocation" --budget 1500` returned a truncated BFS result centered on the shared activity form. The form and detail page appeared; unrelated test and research-manual nodes were also included.
- Relevant source locations and verified relationships: `pages/shared/activity-form.php:1077` renders the allocation input and label. `includes/activity-budget.php` consumes the submitted `organizational_allocation` field; the change is limited to the visible label text.
- Missing, inferred, stale, or truncated context: The graph result was truncated and did not establish the label-to-input relationship; the current source was checked directly.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l pages/shared/activity-form.php` | No PHP syntax errors | `No syntax errors detected in pages/shared/activity-form.php` | Passed |
| B1 | Browser / admin or research staff / activity form | Open an activity’s edit form and inspect Budget and Participation Details | Allocation field label reads “Institutional budget” | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- Changed only the displayed label from “Allocated organizational budget (₱)” to “Institutional budget.” The input ID, name, value, and help text remain as they were.
- No application records or database state were changed by the assistant.

## Assistant-run checks
- `php -l pages/shared/activity-form.php` — passed.
- `git diff --check -- pages/shared/activity-form.php` — passed with no whitespace errors.

## Resume / handoff
- Last user-confirmed state: None for this form.
- Next pending action: User opens an activity edit form and reports whether the allocation field label reads “Institutional budget.”
- Blocked/skipped checks and remaining verification gaps: Browser observation pending.
- User-performed data changes and cleanup status: None.
