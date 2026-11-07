# Verification: activity-budget-status

- Date/time: 2026-11-07 02:50, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: On the shared activity form, hide Budget status when Funding arrangement is Free of charge or Participant-funded; show it for other arrangements. For Participant-funded, also hide Institutional budget and the invited-participant contribution prompt. Keep Budget status selected while hidden, and require at least one mandatory participant charge with an amount.
- Environment URL: Not confirmed; navigate through the running RMS app.
- Roles and disposable records/files: Authorized activity manager (admin or staff with activity management permission); no record save is needed.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How does the activity form show Budget status and require participant charges according to Funding arrangement? Selected terms: `activity`, `budget`, `funding`, `form`.
- Query/lookup and result: `graphify query activity budget funding form --budget 1500` returned activity form/detail nodes, but not the budget visibility or participant charge validation functions.
- Relevant source locations and verified relationships: `pages/shared/activity-form.php:1197-1198` renders Funding arrangement, Budget status, and Institutional budget in the budget grid; `:1225` renders the invited-participant contribution prompt. `updateBudgetNoticePreview()` at `:1467` handles funding-dependent visibility and runs initially and on funding changes. For Participant-funded, Budget status, Institutional budget, and the contribution prompt are hidden; the prompt checkbox is disabled and cleared. Budget status remains enabled so its value is retained while hidden. The function marks the first Participant charge select as required and attaches the validation message to it. The handler passes `$_POST` to `rmsActivityBudgetValidatePost()`; server validation at `includes/activity-budget.php:259` requires at least one mandatory participant charge with an amount. `summarizeControls()` at `:1316` skips controls inside hidden sections.
- Missing, inferred, stale, or truncated context: Graphify does not map the budget visibility function. Its relationship to the Funding arrangement change listener was confirmed in current source. Query was not truncated.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| ACTIVITY-FORM-1 | Integrated browser / authorized activity manager / Activities > Add activity | Open the create activity form | Activity form appears; do not save | User reports all report checks passed | Passed |
| ACT-BUDGET-STATUS-1 | Integrated browser / authorized activity manager / activity form | Select Free of charge in Funding arrangement | Budget status becomes hidden; no save is needed | User reports all report checks passed | Passed |
| ACT-BUDGET-STATUS-2 | Integrated browser / authorized activity manager / same form | Select Institutional budget in Funding arrangement | Budget status becomes visible again with its prior selection | User reports all report checks passed | Passed |
| ACT-PARTICIPANT-FUNDING-1 | Integrated browser / authorized activity manager / same form | Select Participant-funded in Funding arrangement | Budget status, Institutional budget, and invited-participant contribution prompt hide; requirement note appears and Participant charge is required | User reports all report checks passed | Passed |
| ACT-PARTICIPANT-FUNDING-2 | Integrated browser / authorized activity manager / same form | After completing any other required fields, attempt submit with no mandatory participant charge; stop at validation and do not confirm/save | Browser blocks submission and reports the requirement on Participant charge | User reports all report checks passed | Passed |

## Failures, decisions, fixes, and retests
- Added server-rendered hidden state and a funding-change visibility update for Budget status. The activity review summary now omits controls inside hidden sections.
- Participant-funded validation previously blocked submission through Funding arrangement. Moved the client-side required state and validation message onto the first Participant charge selector; server-side aggregate validation remains enforced.
- Extended Participant-funded visibility rules to hide Institutional budget and the invited-participant contribution prompt. The prompt is cleared and disabled while hidden.

## Assistant-run checks
- `php -l pages/shared/activity-form.php`: passed, no syntax errors.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/shared/activity-form.php`: passed with no whitespace errors.
- User reports all browser checks passed. No application data changes were reported.

## Resume / handoff
- Last user-confirmed state: User reports all report checks passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported.
- User-performed data changes and cleanup status: No record changes reported.
