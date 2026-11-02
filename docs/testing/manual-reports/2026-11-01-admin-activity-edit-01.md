# Verification: admin activity edit sections

- Date/time: 2026-11-01 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The activity detail page has one edit action. Opening Edit activity shows saved Session details and Budget and Participation Details immediately, without requiring Update date choices. For multiple selected dates, each session shows clearly separated venue and speaker override helper text.
- Environment URL: Not confirmed; user can open from the current RMS session.
- Roles and disposable records/files: Admin; view an existing activity, no record submitted or changed.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How does activity form submission reach budget participant-charge validation? Selected `activity`, `budget`, `participant`, and `charge` from the graph vocabulary.
- Query/lookup and result: `graphify query "activity budget participant charge" --budget 1200` returned `activity-form.php` and `activity-detail.php` as relevant nodes. The result contained 18 nodes and 15 edges and exceeded the nominal budget; it was complete.
- Relevant source locations and verified relationships: `pages/shared/activity-form.php:427` calls `rmsActivityBudgetValidatePost()` in `includes/activity-budget.php:153`. The activity form hides participant-charge inputs for organizational funding at lines 1091 and 1094–1095. The validator previously required participant amounts whenever a submitted row had a non-none charge type, without checking funding source. The validator now accepts participant charges only for participant-funded or combined budgets; the review summary also omits hidden participant charges when the funding source does not allow them.
- Missing, inferred, stale, or truncated context: The graph has no edge from the activity form submission to its budget validator; this call and hidden-field relationship were confirmed directly against current source. The graph lookup returned a complete result beyond the nominal budget. No graph changes made.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | `php -l pages/shared/activity-detail.php` | PHP syntax passes | No syntax errors detected | Passed |
| S2 | Assistant static check | `php -l pages/shared/activity-form.php` | PHP syntax passes | No syntax errors detected | Passed |
| S3 | Assistant static check | `git diff --check -- pages/shared/activity-form.php` | No whitespace errors | No output; passed | Passed |
| B1 | User browser / admin / activity detail and edit page | Open an existing multi-day activity, click Edit activity, and inspect its selected dates | Session details and Budget and Participation Details are visible immediately; each selected date has separated Venue override and Speaker override helper text without submitting Update date choices | Awaiting user observation | Pending |
| B2 | User-provided browser screenshot / role not confirmed / activity form | Inspect screenshot with Schedule over multiple days unchecked | Single-day form does not display venue or speaker override fields | Screenshot shows both override fields despite the toggle being unchecked | Failed |
| S4 | Assistant source check | Confirm single-day controls no longer render override fields and multi-day cards still render them | Only multi-day cards render visible override fields; existing single-day values remain preserved in hidden inputs | Multi-day inputs render at lines 898–899; single-day branch retains values in hidden inputs at lines 976–977 | Passed |
| B3 | User browser / admin / activity form | Reopen the single-day form, then toggle Schedule over multiple days and inspect the resulting per-date session cards | Override fields are absent while unchecked and appear for each selected date once multi-day mode is enabled and date choices are updated | Awaiting user observation | Pending |
| S5 | Assistant source check | Run `php -l includes/activity-budget.php` and `php -l pages/shared/activity-form.php`; check diff whitespace | PHP syntax passes and no whitespace errors | Both files reported no syntax errors; diff check produced no errors | Passed |
| B4 | User browser / admin / activity #76 edit form | Review the funding arrangement and, only if this is a disposable record, retry saving after the code fix | Organizational or free funding no longer triggers a participant amount requirement for hidden participant charges | Code fix is in place; application save has not been attempted. Record disposability is unconfirmed. | Pending |

## Failures, decisions, fixes, and retests
- Source review identified the cause: the form intentionally hid the session and budget sections on every initial GET, including edits, and showed the update-date prompt. The detail page also offered a separate Edit schedule link.
- The edit page now renders its preloaded session and budget sections immediately. The update-date prompt remains for new activities. The redundant Edit schedule link was removed.
- Multi-day override helper text now renders below each input label, with `aria-describedby` associations so it is announced with the corresponding input.
- User screenshot confirmed a related visibility issue: single-day forms also displayed the override fields while Schedule over multiple days was unchecked. The single-day branch now keeps existing override values in hidden inputs, while visible override controls remain in multi-day session cards. The schedule guidance now explains the distinction between single-day and multi-day behavior.
- User-reported validation on activity #76: “Enter an estimated or actual participant amount for a participant charge.” Source review found the validator applied this rule even when organizational or free funding hides participant charge fields. Participant charge type and amount validation now runs only for participant-funded or combined budgets; other funding arrangements normalize these fields to no participant charge. This also prevents stale hidden participant charges from blocking a save after choosing organizational or free funding.
- The budget review dialog now omits participant charge and amount rows when the selected funding source hides those controls, so the review matches the validated payload.
- No database or record changes were made.

## Assistant-run checks
- `php -l pages/shared/activity-detail.php` — passed.
- `php -l pages/shared/activity-form.php` — passed.

## Resume / handoff
- Next pending actions: User performs B1 and B3 in the integrated browser. B4 requires confirmation that activity #76 is disposable before attempting a save; no record was changed by the assistant.
- Browser verification is pending; no application interaction was performed by the assistant.
- User-performed data changes and cleanup status: None.
