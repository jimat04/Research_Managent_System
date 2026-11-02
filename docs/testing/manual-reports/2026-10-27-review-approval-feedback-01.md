# Verification: approval with review feedback

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Approval feedback opens an RMS role-colored confirmation dialog; approval can continue with guidance, switch to the existing return action, or be canceled without changing form values.
- Environment URL: Not exercised
- Roles and disposable records/files: Admin or authorized research staff; use only an existing or session-created disposable pending submission if browser verification is performed.
- Session state: In progress; source checks complete, browser observations pending.

## Graphify and source context
- Question and selected graph vocabulary: `research-submission-review`, `approve`, `comments`, `recommendation`.
- Query/lookup and result: `graphify query "research-submission-review approve comments recommendation" --budget 1200`; 46 nodes found, output truncated at 30. The review page node points to `pages/shared/research-submission-review.php`.
- Relevant source locations and verified relationships: Current review forms and optional/required fields are in `pages/shared/research-submission-review.php:652-686`; status/decision validation and history persistence are handled by `includes/research-workflow.php:530-647`. The dialog uses existing form buttons and leaves backend decisions unchanged.
- Missing, inferred, stale, or truncated context: Graph query was truncated and included unrelated historical/E2E nodes. Current source was used as authority; no browser interaction or persistence result is established.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant / PHP syntax | Run `php -l pages/shared/research-submission-review.php` | No PHP syntax errors | No syntax errors detected | Passed |
| A2 | Admin or authorized staff / research development review queue | Approve a pending version with comments and recommendation empty | Approval submits directly without opening the dialog | Awaiting user-operated browser check | Pending |
| A3 | Admin or authorized staff / research development review queue | Enter feedback and select Approve; choose Approve with Guidance | Dialog uses the active admin orange or staff teal accent; submission is approved and feedback is saved | Awaiting user-operated browser check | Pending |
| A4 | Admin or authorized staff / research development review queue | Enter feedback, select Approve, then choose Return for Revision in the dialog | Existing return decision submits with feedback intact; proposal title selection clears to the return option | Awaiting user-operated browser check | Pending |
| A5 | Admin or authorized staff / research development review queue | Enter feedback, select Approve, then choose Cancel | Dialog closes and the form values remain unchanged; no decision is submitted | Awaiting user-operated browser check | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l pages/shared/research-submission-review.php` — passed.
- Database effects: none.

## Resume / handoff
- Last user-confirmed state: No browser state confirmed.
- Next pending action: In the integrated browser, test one of the pending scenarios with a disposable pending submission and report the observed result.
- Blocked/skipped checks and remaining verification gaps: Browser presentation, keyboard operation, responsive layout, and saved review outcomes remain unverified pending user interaction.
- User-performed data changes and cleanup status: None reported.
