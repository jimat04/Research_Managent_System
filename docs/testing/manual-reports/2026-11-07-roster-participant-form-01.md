# Verification: roster participant form

- Date/time: 2026-11-07 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: On an activity date roster, the entire “Manage participants” section is collapsed initially. Opening it exposes the attendance roster and an add form with Affiliation, Program/Department, and Role dropdowns; choosing an RMS account prefills its profile affiliation and program/department; saved values appear in the roster.
- Environment URL: Not recorded; user will open the activity detail in the integrated browser.
- Roles and disposable records/files: Authorized activity manager and an existing activity; no records changed by the assistant.
- Session state: In progress; browser observation pending.

## Graphify and source context
- Question and selected graph vocabulary: How does the date roster add form save and display participant affiliation, program/department, and role? Selected graph terms: `activity-detail.php`, `Activity Participants`, `Research Activities and Participants`.
- Query/lookup and result: Read-only `rtk graphify query "activity schedule participant" --budget 1500`; found the `activity-detail.php` node and related activity nodes. Output exceeded the requested budget (29 nodes, about 2165 tokens) and did not expose the add-form storage relationship.
- Relevant source locations and verified relationships: `pages/shared/activity-detail.php` handles `add_schedule_participant`, validates participant role/affiliation, saves existing `attendee_affiliation` and `attendee_program_department` columns, renders the collapsed add form, and prefills fields from selected account attributes. `includes/activity-schedule.php:rmsActivityScheduleEligibleUsers()` provides role/program/department values; the roster loader already falls back to linked account profile values. Canonical schema and migration 074 already contain the two attendee detail columns; no schema change is needed.
- Missing, inferred, stale, or truncated context: Graph results were over budget and omitted detailed handler/storage edges; source inspection confirmed current relationships. Browser behavior and persistence remain unobserved.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Static / PHP syntax | `rtk php -l pages/shared/activity-detail.php` | No syntax errors | `ok pages/shared/activity-detail.php` | Passed |
| A2 | Static / diff whitespace | `rtk git diff --check -- pages/shared/activity-detail.php` | No whitespace errors | No output | Passed |
| B1 | Browser / authorized activity manager / activity date roster | Refresh the page and inspect the roster management section | “Manage participants” is collapsed by default, with its attendance count visible | Pending user observation | Pending |
| B2 | Browser / authorized activity manager / same activity date roster | Expand “Manage participants” | Attendance roster and Affiliation, Program/Department, and Role dropdowns are visible; selecting an RMS account fills account name and profile values | Pending user observation | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- Exact non-mutating commands: `rtk php -l pages/shared/activity-detail.php`; `rtk git diff --check -- pages/shared/activity-detail.php`.
- Database effects: None.
- Limitations: No application interaction or live database read was performed by the assistant.

## Resume / handoff
- Last user-confirmed state: None for this change.
- Next pending action: User refreshes the activity detail page and confirms B1; then expands “Manage participants” and confirms B2.
- Blocked/skipped checks and remaining verification gaps: Browser observations pending.
- User-performed data changes and cleanup status: None reported.
