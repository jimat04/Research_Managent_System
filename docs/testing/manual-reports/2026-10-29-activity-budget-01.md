# Verification: activity budget

- Date/time: 2026-10-29, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The Funding arrangement choice is labeled “Institutional budget.” The selectable choices exclude “Organizational and participant funding”; existing activities with that stored value retain it as a hidden selected legacy option until changed. Expense item Cost audience choices exclude “Specific user” and its picker, and Participant charge choices exclude “Optional contribution”; existing saved values are retained as hidden selected legacy options. Selecting “Free of charge” hides Expense details and budget notice controls. The contribution prompt is shown only for a Pending, non-free budget; saving it is rejected for mandatory charges. Invitee response forms are available only for Pending, non-free budgets without mandatory charges. Budget notice heading, recipient explanation, and custom message field are removed; the automatic preview and explicit send confirmation remain.
- Environment URL: Not recorded; browser action is pending.
- Roles and disposable records/files: Existing authorized activity manager and activity; no record changes requested.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Which form owns the budget notice controls and recipient copy? Selected graph terms: `activity-form.php`, `activity-budget.php`.
- Query/lookup and result: `graphify query "activity-form.php activity-budget.php" --budget 900` found 59 nodes but was truncated at 26. It did not expose focused budget-notice relationships.
- Relevant source locations and verified relationships: `pages/shared/activity-form.php:L1032-L1035` contains the automatic notice preview and explicit send confirmation; the redundant heading, invitee explanation, and custom message field are removed. The section remains hidden for free funding through `#budget-notice-wrap`.
- Missing, inferred, stale, or truncated context: Graph traversal was truncated and did not expose the notice helper relationship; current source confirmed the visible controls and funding-based hiding. Browser confirmation is pending.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l pages/shared/activity-form.php` | No syntax errors | Command reported “No syntax errors detected.” | Passed |
| S2 | Assistant static check | Run `git diff --check` for the workspace | No whitespace errors | Command exited without diff-check findings. | Passed |
| S3 | Assistant static check | Run `php -l includes/activity-budget.php` | No syntax errors | Command reported “No syntax errors detected.” | Passed |
| S4 | Assistant static check | Run `git diff --check` after free-funding visibility and send guard changes | No whitespace errors | Exit code 0. | Passed |
| S5 | Assistant static check | Run `php -l pages/shared/activity-form.php` after removing the combined arrangement choice | No syntax errors | Command reported “No syntax errors detected.” | Passed |
| S6 | Assistant static check | Run `git diff --check` after dropdown change | No whitespace errors | Exit code 0. | Passed |
| S7 | Assistant static check | Run `php -l` on the activity form, activity detail, and budget helper after label updates | No syntax errors | All three commands reported “No syntax errors detected.” | Passed |
| S8 | Assistant static check | Run `git diff --check` after label updates | No whitespace errors | Exit code 0. | Passed |
| S9 | Assistant static check | Run `php -l pages/shared/activity-form.php` after removing the expense user picker | No syntax errors | Command reported “No syntax errors detected.” | Passed |
| S10 | Assistant static check | Run `git diff --check` after removing the expense user picker | No whitespace errors | Exit code 0. | Passed |
| S11 | Assistant static check | Run `php -l pages/shared/activity-form.php` after removing Optional contribution from choices | No syntax errors | Command reported “No syntax errors detected.” | Passed |
| S12 | Assistant static check | Run `git diff --check` after removing Optional contribution from choices | No whitespace errors | Exit code 0. | Passed |
| S13 | Assistant static check | Run `php -l` on the activity form, activity detail, and budget helper after pending-only contribution changes | No syntax errors | All three commands reported “No syntax errors detected.” | Passed |
| S14 | Assistant static check | Run `git diff --check` after pending-only contribution changes | No whitespace errors | Exit code 0. | Passed |
| S15 | Assistant static check | Run `php -l pages/shared/activity-form.php` after removing the budget notice heading and audience explanation | No syntax errors | Command reported “No syntax errors detected.” | Passed |
| S16 | Assistant static check | Run `git diff --check` after removing the budget notice heading and audience explanation | No whitespace errors | Exit code 0. | Passed |
| S17 | Assistant static check | Run `php -l pages/shared/activity-form.php` after removing Additional notice details | No syntax errors | Command reported “No syntax errors detected.” | Passed |
| S18 | Assistant static check | Run `git diff --check` after removing Additional notice details | No whitespace errors | Exit code 0. | Passed |
| B0 | User browser / authorized activity manager | Open an existing activity’s edit form and its budget section through the Activities page. | The Funding arrangement dropdown is visible. | User confirmed “yes.” | Passed |
| B1 | User browser / authorized activity manager / activity budget form | Open the Funding arrangement dropdown. | “Institutional budget” appears instead of “Organizational budget”; the combined option is not selectable. | Awaiting user observation. | Pending |
| B2 | User browser / authorized activity manager / activity budget form | Select “Free of charge.” | Expense details, the contribution prompt, and the Optional budget notice all disappear. | Not started. | Pending |
| B3 | User browser / authorized activity manager / activity budget form | Select a non-free arrangement. | Applicable sections reappear; notice confirmation stays unchecked. | Not started. | Pending |
| B4 | User browser / authorized activity manager / activity budget form | Open Cost audience on an expense item. | Choices omit “Specific user”; existing specific-user rows retain their audience without a user picker. | Awaiting user observation. | Pending |
| B5 | User browser / authorized activity manager / activity budget form | Open Participant charge on an expense item. | Choices omit “Optional contribution”; existing optional-charge rows preserve their stored value until changed. | Awaiting user observation. | Pending |
| B6 | User browser / authorized activity manager / activity budget form | Change Budget status to Confirmed. | The contribution prompt disappears and its checkbox is cleared. | Awaiting user observation. | Pending |
| B7 | User browser / authorized activity manager / activity budget form | Inspect the budget notice controls. | The “Optional budget notice” heading and invitee-recipient explanation are absent; additional details, preview, and explicit confirmation remain. | Awaiting user observation. | Pending |
| B8 | User browser / authorized activity manager / activity budget form | Inspect the budget notice controls. | “Additional notice details” is absent; the automatic preview and explicit confirmation remain. | Awaiting user observation. | Pending |

## Failures, decisions, fixes, and retests
- None recorded.

## Assistant-run checks
- `php -l pages/shared/activity-form.php` — passed.
- `git -C C:/xampp/htdocs/rms -c safe.directory=C:/xampp/htdocs/rms diff --check` — passed with no findings.

## Resume / handoff
- Last user-confirmed state: Authorized activity manager reported that the Funding arrangement dropdown was visible in the activity edit form.
- Next pending action: User inspects the budget notice controls and confirms Additional notice details is gone while the automatic preview and send confirmation remain.
- Blocked/skipped checks and remaining verification gaps: Dropdown options, free-funding visibility, and restore behavior are pending browser confirmation. Notice delivery was not exercised; it would send to selected invitees and still needs explicit user confirmation in the app.
- User-performed data changes and cleanup status: None.
