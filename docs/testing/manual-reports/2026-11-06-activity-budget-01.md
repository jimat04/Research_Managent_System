# Verification: activity-budget

- Date/time: 2026-11-06, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: In the shared activity form, hide the Institutional budget allocation field when Funding arrangement is Free of charge; require at least one mandatory participant charge with an amount when Funding arrangement is Participant-funded. In the activity detail page, open the contribution response dialog after an eligible invitee successfully accepts attendance, replacing the inline response form.
- Environment URL: `http://localhost:8080/rms/pages/shared/activity-form.php`
- Roles and disposable records/files: Authorized activity manager; no record changes required for the check.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How does the activity form update and validate participant charges by funding arrangement? Selected terms: `activity`, `budget`, `funding`, `form`.
- Query/lookup and result: `graphify query activity budget funding form --budget 1500` returned `activity-form.php` and nearby shared-form nodes. The map did not expose the budget-specific update function or validator.
- Relevant source locations and verified relationships: `pages/shared/activity-form.php:1077` renders the Institutional budget field; `pages/shared/activity-form.php:1347` contains `updateBudgetNoticePreview()`, which updates free-funding visibility and now checks participant-funded charges; the `funding-source` change listener invokes that function at `pages/shared/activity-form.php:1414`. `includes/activity-budget.php:259` enforces a mandatory participant charge server-side.
- Missing, inferred, stale, or truncated context: Graphify coverage for the budget-specific JavaScript was missing; conclusions were confirmed against current source. The query was not truncated.
- Follow-up question and selected graph vocabulary: Does accepting an activity invitation lead to a contribution response dialog? Selected terms: `activity`, `detail`, `invitation`, `response`, `contribution`.
- Follow-up query/lookup and result: `graphify query activity detail invitation response contribution --budget 1500` returned the activity detail/form nodes but did not map the invitation-to-contribution path. Current source was traced directly.
- Follow-up source locations and verified relationships: `pages/shared/activity-detail.php:192` handles RSVP acceptance; `:208` records a one-time session trigger for the redirect; `:818` limits auto-open to an accepted invitee when a contribution prompt is eligible; `:1139` renders the dialog; `:257` retains the contribution-response handler.
- Missing, inferred, stale, or truncated context: Graphify omitted the RSVP/contribution runtime relationship; current source is authoritative.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| BUDGET-UI-1 | Integrated browser / authorized activity manager / existing activity form | Choose “Free of charge” in Funding arrangement | Institutional budget allocation field becomes hidden; switching to another arrangement shows it again | User reported all activity budget browser checks passed | Passed |
| BUDGET-UI-2 | Integrated browser / authorized activity manager / existing activity form | Choose “Participant-funded” in Funding arrangement | Requirement note appears, explaining that a mandatory participant charge and amount are needed | User reported all activity budget browser checks passed | Passed |
| BUDGET-UI-3 | Integrated browser / authorized activity manager / existing activity form | Attempt to submit with no mandatory participant charge and amount; do not save | Browser blocks submission and explains the requirement | User reported all activity budget browser checks passed | Passed |
| RSVP-POPUP-1 | Integrated browser / invited attendee / disposable eligible activity detail page | Click “Yes, I will attend” | RSVP saves, then the contribution dialog opens | User reported all RSVP popup checks passed; individual outcomes not itemized | Passed |
| RSVP-POPUP-2 | Integrated browser / invited attendee / same disposable activity | Click “Not now” in the open dialog | Dialog closes; the already-saved RSVP remains accepted and no contribution answer is submitted | User reported all RSVP popup checks passed; individual outcomes not itemized | Passed |
| RSVP-POPUP-3 | Integrated browser / invited attendee / same disposable activity | Reopen the contribution dialog, choose “Not willing to contribute,” and save | Contribution answer is saved while the attendance RSVP remains accepted | User reported all RSVP popup checks passed; individual outcomes not itemized | Passed |

## Failures, decisions, fixes, and retests
- The Institutional budget allocation field had no visibility toggle while other free-funding details already had one. Added a server-rendered hidden state and a change-driven toggle.
- Participant-funded arrangements previously allowed saving without any mandatory participant charge. Added an explanatory form message and client-side validity check, plus matching server-side validation.
- Replaced the attendee-page inline contribution form with a dialog. Successful acceptance of an invitation triggers it once when eligible; “Not now” only dismisses it, and saved contribution responses do not alter attendance. A page button reopens it for later changes.

## Assistant-run checks
- `php -l pages/shared/activity-form.php`: passed, no syntax errors.
- `php -l includes/activity-budget.php`: passed, no syntax errors.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- includes/activity-budget.php pages/shared/activity-form.php`: passed with no whitespace errors. An earlier repository-wide diff check reported access-denied messages for unrelated protected `.htaccess` files.
- `php -l pages/shared/activity-detail.php`: passed, no syntax errors.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/shared/activity-detail.php`: passed with no whitespace errors.
- RSVP dialog follow-up: `php -l pages/shared/activity-detail.php` passed after implementation; targeted `git diff --check` for the page and this report passed. No database changes were made.

## Resume / handoff
- Last user-confirmed state: User reported that all activity budget and RSVP popup checks passed. Individual browser observations were not itemized.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: No individual browser outputs were provided. Assistant did not operate the application or change records.
- User-performed data changes and cleanup status: The disposable test activity's RSVP and contribution response may have been saved during the passed checks; cleanup status was not reported.
