# Verification: shared messages messenger layout

- Date/time: 2026-11-02 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: `pages/shared/messages.php` presents inbox/sent and conversation detail views in a messenger style for admin, research staff, faculty, and student roles. The selected conversation shows incoming/outgoing bubbles and a compact reply composer; role access, validation, notification handling, and private thread boundaries remain intact.
- Environment URL: `http://localhost/rms/pages/shared/messages.php`
- Roles and records: Existing authorized accounts and their existing conversations; no messages need to be sent for layout inspection.
- Session state: UI changes and focused static checks complete; user browser confirmation pending.

## Graphify and source context

- Question and selected graph vocabulary: Trace the shared message page, thread history, and persistence relationships. Selected terms: `message`, `messages` (the only matching terms in `graphify-out/.vocab.txt`).
- Query/lookup and result: `graphify query messages --budget 1500` returned 33 nodes and 34 edges (~2465 tokens). The result includes `messages` in the schema and its reference to `users` at `database/schema/rms_db.sql:L804`; it does not include the current shared PHP page or the active message handlers.
- Relevant source locations and verified relationships: `pages/shared/messages.php` authenticates through `requireLogin()`, routes through the four role shells, inserts/replies using prepared statements with CSRF validation, loads thread history only for a sender or recipient, and emits notification links. The visual update uses the existing message records and handlers; replies now return to the opened thread.
- Missing, inferred, stale, or truncated context: Graph coverage for the runtime page and handler relationships is absent/stale. The query returned all matching nodes despite the requested budget. Current PHP source was inspected directly and takes precedence. No schema or message access rules were changed.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| STATIC-1 | Assistant / PHP syntax | Run `php -l pages/shared/messages.php` | No PHP syntax errors | `No syntax errors detected in pages/shared/messages.php` | Passed |
| STATIC-2 | Assistant / whitespace | Run scoped `git diff --check -- pages/shared/messages.php` | No whitespace errors | Command completed without whitespace errors; Git printed a line-ending normalization warning | Passed |
| UI-1 | Student / Messages | Open the inbox, then an existing conversation; inspect the desktop layout | Role-purple accents, readable conversation list, distinct incoming/outgoing bubbles, and a visible reply composer with no clipped content | Pending user observation | Pending |
| UI-2 | Faculty / Messages | Open an existing conversation and inspect the narrow viewport | Role-blue accents; chat and composer remain readable without horizontal overflow | Pending user observation | Pending |
| UI-3 | Research staff / Messages | Open the inbox and a conversation | Role-teal accents; inbox, thread, and reply behavior remain available | Pending user observation | Pending |
| UI-4 | Admin / Messages | Open the inbox and a conversation; inspect compose recipient filtering | Role-orange accents; user filters and recipient selection remain usable | Pending user observation | Pending |

## Assistant-run checks

- `php -l pages/shared/messages.php` passed.
- Scoped `git diff --check -- pages/shared/messages.php` completed without whitespace errors.
- Source review confirms the existing role-based recipient rules, CSRF validation, prepared inserts, thread authorization, escaping, and message notification flow remain in place.
- No application navigation, login, account switching, message send, or database action was performed by the assistant.

## Resume / handoff

- Last user-confirmed state: None for this shared Messages UI update.
- Next pending action: User opens Messages in the integrated browser as a student and confirms whether the inbox rail, chat bubbles, and reply composer are readable and unclipped.
- Blocked/skipped checks and remaining verification gaps: All role and viewport browser observations are pending. No scripted browser actions or test records were used.
- User-performed data changes and cleanup status: None requested or performed.
