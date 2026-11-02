# Verification: adviser request messenger UI

- Date/time: 2026-11-02 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The private adviser-request thread reads like a two-person conversation, keeps long histories in a bounded scroll area, opens at the latest message, and provides a compact composer without changing who may view or send messages.
- Environment URL: `http://localhost/rms/pages/shared/adviser-request-messages.php?id=1` (route supplied in conversation)
- Roles and disposable records/files: Existing authorized student or faculty participant for request 1; no record changes needed for layout inspection.
- Session state: UI implementation complete; static checks and user browser confirmation pending

## Graphify and source context
- Question and selected graph vocabulary: Trace the adviser-request message page and its persistence path. Candidate terms: `adviser-request-messages.php`, `adviser_request_messages`, `adviser_requests`, and role shells.
- Query/lookup and result: `graphify query adviser-request-messages.php --budget 1000` returned 139 nodes and was truncated at 30. Its displayed start nodes were unrelated/shared `messages.php`, E2E request-guard helpers, and schema nodes; no current adviser-request page node was confirmed.
- Relevant source locations and verified relationships: `pages/shared/adviser-request-messages.php` authenticates the user, permits only the request's student or faculty participant, validates CSRF and the 5,000-character message limit, inserts via prepared statement, loads messages in chronological order, and renders the matching faculty/student shell. The existing page had a growing ordered message list and a composer below it, with no bounded conversation scroller.
- Missing, inferred, stale, or truncated context: Graph coverage for this route is missing or stale; current source was used. No schema, message-saving, access, or status behavior was changed. No live typing/read receipts are claimed because the existing schema has no such state.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| STATIC-1 | Assistant / PHP syntax | Run `php -l pages/shared/adviser-request-messages.php` | No PHP syntax errors | `No syntax errors detected in pages/shared/adviser-request-messages.php` | Passed |
| STATIC-2 | Assistant / diff check | Run scoped `git diff --check` | No whitespace errors | Command completed without errors | Passed |
| CHAT-1 | Student or faculty participant / adviser request 1 | Reload the private message page and inspect a long conversation | Distinct incoming/outgoing bubbles, date dividers, a scrollable history, and the newest messages visible at the bottom | Pending user observation | Pending |
| CHAT-2 | Student or faculty participant / adviser request 1 | Type a draft and use Ctrl+Enter without sending | Character count updates; Ctrl+Enter submits only a non-empty draft | Pending user observation | Pending |

## Assistant-run checks
- `php -l pages/shared/adviser-request-messages.php` passed.
- Scoped `git diff --check` passed for the page and report.
- Source review confirms the existing participant authorization, prepared message insert, CSRF check, declined-request lock, message query, and role-shell rendering remain in place.

## Resume / handoff
- Last user-confirmed state: The existing message body was not in its own scrollable area. Static checks pass after the messenger UI update.
- Next pending action: User reloads the page as an authorized student or faculty participant and confirms the conversation layout and composer behavior.
- Blocked/skipped checks and remaining verification gaps: Graph node coverage for this route was absent from the truncated lookup; browser confirmation pending.
- User-performed data changes and cleanup status: None.
