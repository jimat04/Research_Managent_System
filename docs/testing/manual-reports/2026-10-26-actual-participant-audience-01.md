# Verification: actual participant audience picker visibility

- Date/time: 2026-10-26, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: In the actual participants audience selector, hide the Individual users section for All users, Students, Faculty, and Staff. Show it for Specific users and Custom audience.
- Environment URL: Pending user browser check.
- Roles and disposable records/files: Pending user browser check; no records or files changed by assistant.
- Session state: In progress; browser observation pending.

## Graphify and source context
- Question and selected graph vocabulary: How does the activity form connect to the existing recipient picker? Selected terms: `activity-form.php`, `updateRecipientPicker`, `specificRecipientPicker`.
- Query/lookup and result: `graphify query "activity-form.php updateRecipientPicker specificRecipientPicker" --budget 1500`; returned activity-form and picker nodes but was truncated (46 of 55 nodes).
- Relevant source locations and verified relationships: Current `pages/shared/activity-form.php` defines the six audience scopes at L143-L150, renders the picker for multi-day and single-day schedule cards at L703 and L781, and updates its state in `updateDateAudience()` at L1187-L1207. The function shows the picker only for `specific` and `custom`; now the markup and handler also set the panel's `hidden` state accordingly.
- Missing, inferred, stale, or truncated context: Graph predates the current audience-specific helper and did not include it; graph output was truncated. Confirmed the relevant relationships against current source. Browser behavior remains pending.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Assistant static check | Run `php -l pages/shared/activity-form.php` | PHP parses without errors | `No syntax errors detected` | Passed |
| 2 | Assistant source check | Inspect both schedule form variants and the audience change handler | Panel initially hidden for all/role scopes, shown only for specific/custom | Both variants emit `hidden` unless scope is `specific` or `custom`; handler synchronizes `userPicker.hidden` with the same condition | Passed |
| 3 | User browser check / activity form | Change Actual participants to All users | Individual users heading, guidance, filters, selection actions, and account list disappear | Pending user observation | Pending |
| 4 | User browser check / activity form | Change audience to Specific users, then Custom audience | Individual users section appears; Custom also keeps the role selection controls | Pending user observation | Pending |

## Limits
- No browser interaction or database operation was performed by the assistant.
