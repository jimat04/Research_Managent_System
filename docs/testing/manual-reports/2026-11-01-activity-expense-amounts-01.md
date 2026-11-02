# Verification: Activity expense amount fields

- Date/time: 2026-11-01 01:44 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Activity budget edit form shows participant charge and amount controls only for participant-funded or combined budgets; institutional-only funding hides them while preserving saved values. It continues to show estimate versus actual according to activity status. Expense rows omit Cost audience while schedule-level Invitee audience remains visible.
- Environment URL: Pending user-operated browser check
- Roles and disposable records/files: Admin or authorized research staff; use an existing activity for read-only inspection and a disposable activity only if saving is needed.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Which activity form, budget notice, and activity detail paths handle participant charges by funding source? Terms: activity, budget, expense, funding, participant, notice.
- Query/lookup and result: The `graphify` query executable was unavailable. Read-only lookup in `graphify-out/graph.json` found nodes for `pages/shared/activity-form.php` (L1) and `activityFormEscape()` (L14), with containment links only; no budget, expense, or audience behavior nodes or relationships were present.
- Relevant source locations and verified relationships: `pages/shared/activity-form.php` hides participant charge fields for institutional-only funding at L1090-L1094 and L1316-L1320; `updateBudgetNoticePreview()` switches visibility and omits participant fees from the preview at L1347-L1377. Schedule-level Invitee audience controls remain at L899-L900 and L977-L978. `includes/activity-budget.php` omits participant fees from saved notice text for institutional-only funding at L485-L487. `pages/shared/activity-detail.php` omits participant cost labels and values for institutional-only budgets at L1039 and L1149. Graphify has no nodes for these budget behaviors; relationships were confirmed in source.
- Missing, inferred, stale, or truncated context: The graph does not model budget amount handling. Relationships above were confirmed against current source.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant command | Run `php -l pages/shared/activity-form.php` | No PHP syntax errors | `No syntax errors detected in pages/shared/activity-form.php` | Passed |
| S2 | Assistant source check | Inspect saved rows, add-row template, hidden value preservation, and notice selector | One visible participant amount per expense; existing organizational amounts remain submitted invisibly | Confirmed in current source; `git diff --check` clean | Passed |
| S3 | Assistant source check | Search saved-row and template markup for Cost audience and Invitee audience controls | No visible Cost audience control; schedule Invitee audience remains; saved scopes are preserved and new rows default to all participants | No `Cost audience` label remains; Invitee audience controls remain at L899-L900 and L977-L978 | Passed |
| S4 | Assistant source check | Inspect funding-source visibility, notice generation, and activity detail | Institutional-only hides participant charge controls and participant cost display; saved values remain available if funding changes to Combined | Confirmed in form JS/PHP, notice helper, and detail markup; PHP syntax and diff checks passed | Passed |
| B1 | Admin or authorized research staff / activity budget form | Open an upcoming activity and confirm only the participant estimate appears; confirm Invitee audience remains in Schedule details and Cost audience is absent from Expense details | One participant estimate is visible; schedule audience selector remains; no cost-audience selector | Awaiting user observation | Pending |
| B2 | Admin or authorized research staff / activity budget form | Open a conducted/completed activity and confirm only participant actual appears; check the participant notice preview | One participant actual is visible; preview uses that amount | Awaiting user observation | Pending |
| B3 | Admin or authorized research staff / activity budget form | Add an expense row for each status case | New row has no Cost audience selector and shows the matching participant estimate or actual | Awaiting user observation | Pending |
| B4 | Admin or authorized research staff / activity budget form | Change Funding arrangement between Institutional, Participant-funded, and Combined without saving | Institutional hides participant controls and preview charges; participant/combined restore them and saved values | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- User selected a single participant amount as the recommendation because it directly corresponds to the Participant charge selector. Organization allocation remains available in the main budget fields; existing item-level organizational estimate/actual values are retained in hidden form inputs. No schema change.
- Removed the visible Cost audience selector per the user's request; existing per-expense audience scopes remain hidden and preserved, while new expense rows default to `all`.
- Hide participant charge controls and suppress participant fees in notice/detail views for institutional-only funding. Keep values in storage so switching to participant-funded or combined can restore them.

## Assistant-run checks
- `rtk proxy php -l includes/activity-budget.php` — passed.
- `rtk proxy php -l pages/shared/activity-detail.php` — passed.
- `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/shared/activity-form.php includes/activity-budget.php pages/shared/activity-detail.php` — passed; Git noted an LF-to-CRLF conversion for `activity-detail.php`.
- `rtk proxy php -l pages/shared/activity-form.php` — passed.
- `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/shared/activity-form.php` — passed.
- Source inspection confirmed existing item-level organizational amounts remain submitted through hidden inputs, while only the status-appropriate participant amount is visible.

## Resume / handoff
- Last user-confirmed state: No browser observations yet.
- Next pending action: User checks B1-B4 in the integrated browser and reports outcomes.
- Blocked/skipped checks and remaining verification gaps: Browser behavior and persistence remain pending; no application interactions were performed by the assistant.
- User-performed data changes and cleanup status: None.
