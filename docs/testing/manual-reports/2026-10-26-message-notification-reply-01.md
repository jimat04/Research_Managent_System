# Verification: message history, notification, and reply

- Date/time: 2026-10-26, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The admin inbox list hides the compose header/body and uses the full width; opening a message shows its chronological conversation history and reply panel with the other participant. Opening an unread message marks its message and linked notification read; replies stay bound to the authorized thread.
- Environment URL: `http://localhost/rms/`
- Roles and disposable records/files: Admin recipient and a message the admin is ready to mark read; no reply will be sent during the initial browser check.
- Session state: In progress; browser observation pending.

## Graphify and source context
- Question and selected graph vocabulary: Trace the shared messages storage and rendering behavior. Selected term: `messages`.
- Query/lookup and result: `graphify query "messages" --budget 1400` found the existing message table and related schema nodes but did not include the page or the new history migration. Direct source and schema inspection confirmed the implementation.
- Relevant source locations and verified relationships: `pages/shared/messages.php:158-180` validates thread membership and derives its root; `pages/shared/messages.php:248-274` inserts and links a message in a transaction; `pages/shared/messages.php:349-364` loads detail by stable history ID and marks only the selected message read; `pages/shared/messages.php:442-461` loads chronological rows scoped to the current participant; `pages/shared/messages.php:704-724` renders each history entry. Migration `database/migrations/039_message_conversation_history.sql` adds the stable ID/thread key and backfills existing messages as individual roots. `includes/admin-shell.php:68-76` counts unread notifications for the badge.
- Missing, inferred, stale, or truncated context: Graphify has no useful page-to-thread edge for this behavior; its result omitted the page and migration. Current source was inspected directly and is authoritative here. No graph changes were made.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant, PHP CLI | Run `php -l pages/shared/messages.php` | No syntax errors | `No syntax errors detected`. | Passed |
| S2 | Assistant, Git | Run `git diff --check -- pages/shared/messages.php` | No whitespace errors | Completed without diff-check errors; Git emitted its existing LF-to-CRLF normalization warning. | Passed |
| S3 | Assistant, source review | Review message and reply authorization | Detail reads and replies remain limited to parties in the referenced message; the submitted recipient must match the server-derived counterpart | Prepared detail/reply statements retain the sender-or-recipient predicate and recipient comparison. No SQL data was changed. | Passed |
| S4 | Assistant, source review | Review admin list/detail rendering | Admin list omits the composer and its dead link; detail keeps the reply panel; other roles keep their compose form | Admin list gets a single-column class and conditionally omits the compose panel/anchor; the existing detail reply panel remains. | Passed |
| S5 | Assistant, migration runner and read-only database probe | Apply and inspect `039_message_conversation_history.sql` on the configured local target | Configured and connected database are both `rms_db`; all rows have unique history IDs and a thread root | Runner reported `APPLIED`; probe confirmed both database names are `rms_db`, 5 message rows, 5 unique history IDs, 0 missing thread roots, and 5 legacy self-roots. The probe also emitted PHP CLI session-file permission warnings; its read-only queries completed and returned the listed results. | Passed |
| S6 | Assistant, PHP CLI and Git | `php -l` on changed PHP; `git diff --check` on touched source/schema/migration | PHP parses and diff contains no whitespace errors | Both PHP files linted cleanly; diff check completed with only existing LF-to-CRLF warnings. | Passed |
| B1 | User, Admin, `http://localhost/rms/pages/shared/messages.php` | Open the Shared Messages page | Inbox loads full width without the compose header/body or a dead “New message” link | Awaiting user observation. | Pending |
| B2 | User, Admin, Shared Messages → Inbox | Open an unread message row using the current inbox link | Detail opens; the message and matching notification become read; `#new-recipient` shows that sender and role | Not attempted yet. The click intentionally marks that message read. | Pending |
| B3 | User, Admin, message detail | Send a reply to the displayed recipient | Reply appears in that user’s inbox and notification count is accurate | Not attempted; no disposable reply thread was supplied, and the initial check will not send correspondence. | Skipped |
| B4 | User, Admin, Shared Messages → Inbox | Open a message row and inspect the history section | Detail shows a message history section containing the opened message; multi-entry ordering is confirmed separately with a thread that has replies | User confirmed: “History appears as expected.” A one-entry history was observed; chronological ordering across multiple replies remains unverified. | Passed |

## Failures, decisions, fixes, and retests
- None observed in static checks.

## Assistant-run checks
- `php -l pages/shared/messages.php` passed.
- `git diff --check -- pages/shared/messages.php` passed with only Git's line-ending normalization warning.
- Notification read updates are scoped to the current recipient and message-specific link. Existing generic-link notification rows use an exact title/message match and update one row.
- Migration 039 was applied to local `rms_db`: it added `history_id` and `thread_id`, retained legacy `message_id`, and assigned each of the five existing messages its own root because historical reply relationships were not stored. No message body or participant data was changed.
- The user confirmed the history section appears in the Admin detail view. Multi-entry chronological ordering, reply persistence, and actual reply delivery remain unverified.

## Resume / handoff
- Last user-confirmed state: The user requested unread-message notification/reply behavior, then asked to hide the admin list composer until a message is opened, then requested message history.
- Last browser observation: Admin opened a message; the “Message history” section appeared as expected.
- Blocked/skipped checks and remaining verification gaps: Multi-entry chronological order and reply persistence remain unverified; sending a reply needs a user-approved test thread.
- User-performed data changes and cleanup status: None yet.
