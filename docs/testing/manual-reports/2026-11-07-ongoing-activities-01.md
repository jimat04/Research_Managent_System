# Verification: activity ongoing and past views

- Date/time: 2026-11-07 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Verify the ongoing list/count and provide an admin shortcut to ended activities not yet marked conducted.
- Environment URL: `http://localhost:8080/rms/pages/shared/activities.php?view=conducted`
- Roles and disposable records/files: Existing authorized browser session; no records changed.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How ongoing and past activity views fetch schedule data. Selected graph vocabulary: `activities`.
- Query/lookup and result: `graphify query activities --budget 1500` returned `pages/shared/activities.php`, its local helpers, and an inferred permission-helper relationship. `research_activities` was ambiguous across three graph nodes, so no follow-up traversal was made.
- Relevant source locations and verified relationships: `pages/shared/activities.php:287-295` resolves ongoing and past views. The past-view predicate at `:339-350` requires at least one scheduled date and excludes nonterminal activities with any current/future scheduled session; when schedule tables are unavailable it uses a past activity date. Count and list apply this predicate at `:482-488` and `:544-545`. The Conducted page header links to the past view at `:1332-1335`. `database/schema/rms_db.sql:887-968` defines activity status and scheduled dates separately. Current source confirms these findings.
- Missing, inferred, stale, or truncated context: Graphify did not expose the schedule-query relationship. Source inspection is authoritative. Live database contents remain uninspected.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| OA-1 | Source review | Trace ongoing view, SQL filters, count, and displayed status | Understand exactly which rows and count the page derives | Source locations recorded above | Passed |
| OA-2 | User-provided screenshot / ongoing activities page | Compare schedule panel with count and directory | A matching ongoing record should appear in the active schedule panel | Screenshot shows count `1`, schedule panel empty, and one directory row titled “Add Activity” with “Ongoing” status | Failed |
| OA-3 | User-operated browser / existing authorized role / provided URL | Reload after the schedule-panel query fix and compare the count, panel content, and directory rows | The active record appears in the panel and count/list agree | User confirmed “all passed”; specific count/title not supplied | Passed |
| OA-4 | User-operated browser / admin / Conducted Activity Records | Open the conducted records page | A small “Past Activities” button replaces “Add Activity” in the header | User confirmed “all passed”; no further visual details supplied | Passed |
| OA-5 | User-operated browser / admin / past activities view | Click “Past Activities” | A separate list opens for ended, not-yet-conducted activities | User confirmed “all passed”; no record count/title supplied | Passed |

## Failures, decisions, fixes, and retests
- Initial source review identified a count/list mismatch; the ongoing count was updated to share the active-session condition with the directory when schedule tables are available.
- The supplied screenshot showed one ongoing directory record but an empty schedule panel. Investigation found the panel query used stored status instead of the active-session condition. The panel query now shares the same active-session condition and uses the active schedule date/time. The original screenshot failure is preserved above; the user confirmed the browser retest passed.
- Added a small admin-only “Past Activities” header link on Conducted Activity Records in place of “Add Activity”. Its separate `view=past` list shows ended, noncancelled activities not already marked conducted, based on scheduled end times (or the activity date when schedule tables are unavailable). The Past view links back to Conducted Records.

## Assistant-run checks
- `php -l pages/shared/activities.php`: passed, no syntax errors.
- `git diff --check -- pages/shared/activities.php`: passed with no whitespace errors. (The new report is untracked, so Git's diff check did not cover it.)
- Repeated `graphify query activities --budget 1500` after the source changes; it returned the same page/helper/permission neighborhood and still did not expose the schedule-query relationship. Current source inspection remains the basis for the data-flow conclusion.
- No database or application records were accessed or changed.

## Resume / handoff
- Last user-confirmed state: The user confirmed all checks passed, including the Past Activities button and view.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: Live database contents were not inspected directly; the user confirmed the browser checks passed.
- User-performed data changes and cleanup status: None.
