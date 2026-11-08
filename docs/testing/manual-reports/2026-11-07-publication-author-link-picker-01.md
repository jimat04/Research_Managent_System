# Verification: publication author link picker

- Date/time: 2026-11-07 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: In the shared publication form, a dropdown adds an unlinked RMS account, while a compact list shows only currently linked accounts with explicit Remove actions. The add button is disabled until an unlinked account is selected. Linking adds the account name only once; removing a link returns that account to the picker. A faculty owner account that must remain linked stays non-removable.
- Environment URL: Not recorded; user supplied a screenshot of the open publication form.
- Roles and disposable records/files: User screenshot showed an existing publication author form. No application records were changed by the assistant.
- Session state: Finished.

## Graphify and source context
- Question and selected graph vocabulary: How does the publication author account picker filter and link contributor accounts? Selected terms: `publication`, `archive`, `form`, `contributor`, `author`.
- Query/lookup and result: Read-only `rtk graphify query "publication archive author contributor" --budget 1500`; it returned the publication archive node, but exceeded budget (28 nodes, about 1859 tokens) and did not include the included form or picker script.
- Relevant source locations and verified relationships: `includes/publication-archive-form.php` renders eligible accounts, saved `contributor_ids[]`, and the account selector. `js/publication-classification.js` transforms saved links into a linked-account list, filters available options by program, and handles add/remove actions. `includes/publication-archive-form.php` validates contributor IDs with `rmsValidateOutputContributorIds()` before `archiveSyncAuthors()` saves them. The POST field shape and backend validation/persistence are unchanged.
- Missing, inferred, stale, or truncated context: Repeated Graphify query `rtk graphify query "publication author contributor account" --budget 900` truncated at 22 of 23 nodes and did not expose the form include or script relation. Current source inspection confirms the interactions. The screenshot's older checkbox rendering suggests a stale cached script; this is an inference, and browser behavior after cache busting is pending.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Static / PHP syntax | `rtk php -l includes/publication-archive-form.php` | No syntax errors | `ok includes/publication-archive-form.php` | Passed |
| A2 | Static / JavaScript syntax | `rtk node --check js/publication-classification.js` | No syntax errors | Exit code 0; no output | Passed |
| A3 | Static / diff whitespace | `rtk git diff --check -- includes/publication-archive-form.php js/publication-classification.js` | No whitespace errors | Exit code 0; no output | Passed |
| B1 | User screenshot / publication form | Before the fix, inspect the selected account and linked-author list | An account already checked in the linked list was also selected in the add picker | Screenshot showed Jose Reyes selected and already checked in the list | Passed |
| B2 | Browser / authorized publication editor / same form | Refresh the form and inspect the picker/list before the cache fix | No account is selected; the full checkbox catalog is replaced by a list of currently linked accounts with Remove actions; the faculty owner link has no Remove action; add button remains disabled | User screenshot showed Jose Reyes selected in the picker and the full checkbox list still displayed | No syntax Passed |
| B3 | Browser / authorized publication editor / same form | Choose an unlinked account and click “Add and link author” | Account appears in the linked list, its name is added only if absent, picker resets, and account no longer appears as an available option | User confirmed all checks passed | Passed |
| B4 | Browser / authorized publication editor / same form | Remove an added linked account | Account disappears from linked list and returns to the available dropdown options | User confirmed all checks passed | Passed |
| B5 | Browser / authorized publication editor / same form | Refresh after the script cache version and initial selection reset fix | Linked accounts display as a compact list, the picker starts blank, and any active unlinked faculty accounts are available | User confirmed all checks passed | Passed |

## Failures, decisions, fixes, and retests
- Initial finding from the user screenshot: Jose Reyes was already linked and appeared selected in the account picker. A later screenshot still showed the full checkbox catalog, which indicates the revised script was not applied in that page view. The user asked for a fix.
- Fix: Add a file modification version to the script URL to refresh cached copies, explicitly reset the account picker to its placeholder on initialization, and clarify that only active faculty accounts can be linked. The linked-only list and Remove actions remain; program/link changes refresh eligibility, and successful linking resets the picker. If the name already exists in the ordered author list, it is not inserted a second time. Faculty owner links remain protected while the owner name is present.
- Retest: After cache versioning and the initial selection reset, the user confirmed all checks passed.

## Assistant-run checks
- Exact non-mutating commands: `rtk php -l includes/publication-archive-form.php`; `rtk node --check js/publication-classification.js`; `rtk git diff --check -- includes/publication-archive-form.php js/publication-classification.js`.
- Database effects: None.
- Limitations: No application interaction or live database read was performed by the assistant.

## Resume / handoff
- Last user-confirmed state: Screenshot showed Jose Reyes selected in the picker while already checked in the linked-author list.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: The user confirmed all requested browser checks passed. The assistant did not inspect the live database or independently count eligible active faculty accounts.
- User-performed data changes and cleanup status: None reported.
