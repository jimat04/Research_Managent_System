# Verification: custom activity type

- Date/time: 2026-11-08 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: An authorized activity manager can choose Other, enter a custom type label, save it, and see the label when reopening or viewing the activity. A missing label is rejected server-side.
- Environment URL: http://localhost/rms/ (configured fallback; user confirmed local activity list is reachable)
- Roles and disposable records/files: Admin or staff with activity manage permission; use a uniquely named disposable activity if browser verification proceeds.
- Session state: finished

## Graphify and source context
- Question and selected graph vocabulary: How activity form type selection flows through save, edit, list/detail display, and permission checks; terms: `activity-form.php`, `activity-detail.php`, `activities.php`, `activity_type`.
- Query/lookup and result: `rtk graphify query "activity-form.php activity-detail.php activities.php" --budget 1800 --context-filter call`; then repeated after the compatibility fix with `rtk graphify query "activity-form.php activities.php activity-detail.php custom_activity_type" --budget 1200 --context-filter call`. Both found the relevant page nodes; output was truncated/over budget, so source was checked directly.
- Relevant source locations and verified relationships: `pages/shared/activity-form.php` loads/saves activity fields and gates add/edit by module permission; `includes/activity-schedule.php` persists activity type on insert/update; `pages/shared/activities.php` filters activities and resolves management permission; `pages/shared/activity-detail.php` loads and renders activity details and checks management permission. Current source confirms these paths; graph hints were corroborative only.
- Missing, inferred, stale, or truncated context: Graph output was over budget and did not expose the form-to-save/database edge in the returned excerpt. Current source inspection supplied that relationship. Graph map may predate this change.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l` on the five changed PHP files | All changed PHP files have valid syntax | All five checks returned `ok`; `rtk git diff --check` returned no issues, including after the compatibility fix | Passed |
| S2 | Assistant static review | Review the changed form, save helper, schema, and permission paths | Custom value is escaped on output, stored in its own field, and available only through existing manager permissions | Type allowlists in form/detail/list include `other`; server requires a nonempty label up to 100 characters; insert/update bind the separate label; detail uses the escaped label; admin/staff manager permission checks remain in place. Migration and schema agree. | Passed |
| S3 | Assistant source review | Trace single-day and multi-day confirmation summary builders | Each schedule value appears once; custom type is shown by its entered label | Graphify query `activity-form.php buildReview dialog schedule preview` located the form but truncated context; current source showed `.schedule-date-card` and single-day controls were both summarized for one-day schedules. The builder now uses one branch per schedule mode and shows the entered custom type label. `php -l` and `git diff --check` passed. | Passed |
| B1 | User browser / admin / activity list | Open the activity list | Activity list loads so the form can be opened | User reported fatal `Unknown column 'ra.custom_activity_type'` at `pages/shared/activities.php:577` before migration 076 was applied | Failed |
| B1R | User browser / admin / activity list | Reload the same activity list after compatibility fix | Activity list loads without an unknown-column fatal on the unmigrated database | User confirmed the list loads | Passed |
| M1 | Assistant / configured local database `rms_db` | Apply migration 076 through `scripts/migrate.php`, then inspect runner status | Enum and nullable label column are applied and verifier confirms them | `rtk php scripts/migrate.php apply 076_custom_activity_type.sql` returned `APPLIED`; status reports `applied / executed`. No activity data was changed. | Passed |
| B2 | User browser / admin or authorized research staff / activity form | Open Add Activity and choose Other | Custom activity type field appears and is required | User confirmed it appears and becomes required | Passed |
| B3 | User browser / authorized activity manager / activity form | Enter `Research exhibit` as the custom type | Field retains the entered custom label | User confirmed the value remains in the field | Passed |
| B4 | User browser / authorized activity manager / activity form | Enter the unique title `Activity type persistence check 20261108` | Title appears in the form | User confirmed the title appears correctly | Passed |
| B5 | User browser / authorized activity manager / activity form | Click Update date choices using the prefilled one-day schedule | The session details for the selected date appear; no activity is saved yet | User confirmed the session details appeared | Passed |
| B6 | User browser / authorized activity manager / activity form | Set the session audience to Specific users | The individual recipient picker appears | User confirmed the picker appeared | Passed |
| B7 | User browser / authorized activity manager / activity form | Select one appropriate test account controlled by the user | Only that test account is selected for the session | User confirmed exactly one test account is selected; save will send that account an in-app invitation | Passed |
| B8P | User browser / authorized activity manager / confirmation dialog | Review the save confirmation | Schedule time and audience appear once | Screenshot showed each value twice. User reported the redundancy; the confirmation summary was updated to use one schedule branch. Retest pending. | Failed |
| B8C | User browser / authorized activity manager / confirmation dialog | Click Cancel | Return to the form without saving the activity | User confirmed it returned to the form | Passed |
| B8R | User browser / authorized activity manager / confirmation dialog | Reload the updated form and review the save confirmation | Start/end time and audience each appear once; custom type is shown as `Research exhibit` | User reported all remaining checks passed; individual preview details not itemized | Passed |
| B8 | User browser / authorized activity manager / activity form | Save the uniquely named disposable activity, then reopen detail/edit | Save succeeds; custom label appears in detail and edit form | User reported all remaining checks passed; save/persistence details not itemized | Passed |
| B9 | User browser / admin or authorized research staff / activity form | Choose Other and submit without a custom label | Server rejects the save and preserves the error state | User reported all remaining checks passed; no error text supplied | Passed |

## Failures, decisions, fixes, and retests
- B1 failed because the activity list selected `ra.custom_activity_type` against a database schema that did not yet contain that column. User chose to fix compatibility now. Queries in the list, detail, and edit loader now select `NULL AS custom_activity_type` when the column is absent. The user retested and confirmed the list loads. After confirming the configured target was `rms_db`, the migration runner first showed 076 as pending; a structural verifier was added and the runner applied the additive enum/nullable-column migration. It changed no activity rows.
- B8P failed because the confirmation dialog repeated one-day start time, end time, and invitee audience. User reported the duplication. The review builder now summarizes either multi-day cards or single-day controls, never both, and displays the entered custom type label. User canceled the dialog and returned to the form without saving. Reloading is needed to load the updated script and will clear this unsaved form state.
- User chose to reload and re-enter the unsaved values to retest the corrected confirmation summary.
- User's final report: “all passed.” This aggregate confirmation covers the remaining browser checks; individual outcomes and error text were not provided.

## Assistant-run checks
- Passed: `php -l` on five changed PHP files; `rtk git diff --check` after the compatibility fix and review-summary update; migration 076 runner status is applied/executed.

## User observations
- Admin page load first produced a fatal unknown-column error in `pages/shared/activities.php:577`. After the compatibility fix, user confirmed the activity list loads. Migration 076 is applied. The user confirmed the Other field appears and is required, entered `Research exhibit`, confirmed the unique activity title appears, confirmed session details appeared, chose Specific users, and selected exactly one test account they control. The first confirmation preview showed duplicate times and audience; user reported this and the summary was fixed. User later reported all remaining checks passed, including retest and persistence, without itemized details.
