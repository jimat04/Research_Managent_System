# Verification: activity roster affiliation

- Date/time: 2026-11-07 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Invited account participants show their account affiliation and program/department in the activity roster; submitted check-in details take precedence.
- Environment URL: Not recorded; the user is viewing the target activity in the integrated browser.
- Roles and disposable records/files: Existing activity roster; no records changed by the assistant.
- Session state: Finished.

## Graphify and source context
- Question and selected graph vocabulary: How does the activity roster obtain participant affiliation and program/department? Selected existing labels: `activity-detail.php`, `users`, `Activity Participants`, `Research Activities and Participants`.
- Query/lookup and result: Read-only `graphify query "activity-detail.php" --budget 1500`; returned activity detail/form nodes but no roster loader or useful query-to-user relationship. Earlier broader query was truncated. Current source was used to confirm the data path.
- Relevant source locations and verified relationships: `pages/shared/activity-detail.php:216` calls `rmsActivityScheduleLoadRosterPage()` and renders its `attendee_affiliation` and `attendee_program_department` fields at lines 1657–1676. `includes/activity-schedule.php:1289` inserts invited account participants with `user_id` and name, while the roster query at `includes/activity-schedule.php:486` previously read only optional check-in columns. `database/migrations/074_activity_checkin_attendee_details.sql` adds those columns; `public/activity-check-in.php:187` writes self-reported details. Eligible account profiles expose `role`, `program`, `department`, and `office` via `rmsActivityScheduleEligibleUsers()` at `includes/activity-schedule.php:336`.
- Missing, inferred, stale, or truncated context: Graphify did not provide the roster helper relationship; broader query was truncated. Code paths above are verified against current source. The user's existing profile data was not inspected.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Static / PHP syntax | `rtk php -l includes/activity-schedule.php` | No syntax errors | `No syntax errors detected in includes/activity-schedule.php` | Passed |
| A2 | Static / diff whitespace | `git diff --check` | No whitespace errors | No errors; Git emitted line-ending warnings for unrelated modified files | Passed |
| B1 | Browser / authorized activity manager / same activity roster | Refresh the activity detail roster | Invited accounts with profile values show affiliation and program/department; check-in values override profile values; guests without linked accounts remain blank | User confirmed: “fixed” | Passed |

## Failures, decisions, fixes, and retests
- Initial cause: invited participants were persisted with linked account IDs, but roster rows selected only participant check-in fields, which are empty until check-in.
- Fix: roster query now joins users and falls back to role plus profile program/department/office. Existing check-in values keep precedence. The same effective values drive roster search and affiliation filtering.
- Browser retest: user confirmed the issue was fixed.

## Assistant-run checks
- Exact non-mutating commands: `rtk php -l includes/activity-schedule.php`; `git diff --check`.
- Database effects: none.
- Limitations: no application interactions or live database reads were performed by the assistant.

## Resume / handoff
- Last user-confirmed state: Screenshot shows all six invited rows with em dashes for affiliation and program/department.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: The user confirmed the roster fix; specific account values and check-in precedence were not separately reported.
- User-performed data changes and cleanup status: None reported.
