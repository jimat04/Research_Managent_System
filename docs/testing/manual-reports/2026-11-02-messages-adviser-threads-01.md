# Verification: adviser request threads in Messages

- Date/time: 2026-11-02 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Student and faculty users can find their adviser-request conversations in `pages/shared/messages.php`, open the existing thread in the messenger view, see project context and incoming/outgoing messages, and reply while the request is open or accepted. Declined requests remain readable but cannot receive new messages. Admins can open a request thread in a read-only view showing the student's course and year; the assigned faculty adviser also sees the student's course and year. Only the request's student and assigned faculty member can send messages or mark messages read.
- Environment URL: `http://localhost/rms/pages/shared/messages.php`
- Roles and records: Existing authorized student, faculty, and admin accounts; adviser request `1` from the user-provided URL. No new records or messages are needed.
- Session state: Student/faculty thread implementation remains awaiting browser observations; the admin read-only extension syntax check passed and admin browser observation is pending.

## Graphify and source context

- Question and selected graph vocabulary: Trace adviser-request message persistence and its shared Messages presentation. Candidate tokens from `graphify-out/.vocab.txt`: `adviser`, `advisers`, `message`, `messages`.
- Query/lookup and result: `graphify query adviser --budget 1000` returned six workflow/report nodes, without the current adviser-request messaging page or schema relationship. The current graph vocabulary also has `message`/`messages`; the prior Messages report records `graphify query messages --budget 1500`, which returned 33 schema-oriented nodes and 34 edges but omitted the PHP handlers.
- Relevant source locations and confirmed relationships: `database/migrations/057_student_adviser_requests.sql` defines `adviser_requests` and `adviser_request_messages`, including `read_at`; `pages/shared/adviser-request-messages.php` previously loaded conversations after checking requester/faculty participation and wrote to that table; `pages/shared/messages.php` now loads that same table for the two participant roles, adds request threads to their inbox, marks incoming request messages read when the thread is opened, and sends replies to the same table. Request links from `pages/researcher/request-adviser.php` and `pages/faculty/faculty-advising.php` now open the shared route.
- Missing, inferred, stale, or truncated context: Graphify does not currently trace this page or its handler; current migration and PHP source were inspected directly. No schema migration or message copying was performed. The adviser-request messages stay in their existing table and retain their own read state.
- Admin-view follow-up lookup: `rtk proxy graphify.exe query "msg_load_adviser_request" --budget 900` returned the `messages.php` node but truncated 70 of 95 nearby nodes and did not surface the loader or admin access relationship. Its results included stale browser E2E references. Current PHP source was checked directly; no graph relationship was inferred.
- Faculty-display follow-up lookup: `rtk proxy graphify.exe query "messages.php msg_load_adviser_request" --budget 1000` returned the current `messages.php` node but truncated 99 of 128 nearby nodes and included stale browser E2E references. Current source was checked directly; no graph relationship was inferred.
- Current admin-view source relationships: `pages/shared/messages.php:86` has an opt-in admin-view flag on the prepared request loader; `:184` keeps the send handler participant-only; `:426` allows the admin role through the request-detail route; `:660` enables admin loading only for an admin session; `:679` skips read/notification updates for admins; `:1198` shows course/year from the joined student profile; `:1231` renders the read-only notice.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| STATIC-1 | Assistant / PHP syntax | Run `php -l` on the four changed PHP files | No syntax errors | All four files reported no syntax errors | Passed |
| STATIC-2 | Assistant / whitespace | Run `git diff --check` on the four changed files and this report | No whitespace errors | Command completed without whitespace errors; Git printed a line-ending normalization warning | Passed |
| UI-1 | Student / Messages | Open inbox and an existing adviser-request thread | Request appears with an â€œAdviser requestâ€ marker, project title, latest message; opening shows the existing conversation | Pending user observation | Pending |
| UI-2 | Faculty / Messages | Open inbox and an existing adviser-request thread | Faculty sees only requests assigned to them and can read/reply to a non-declined request | Pending user observation | Pending |
| ACCESS-1 | User-operated / declined request | Open an existing declined request, if available | Prior conversation is visible and no reply composer is offered | Pending user observation | Pending |
| ACCESS-2 | User-operated / unrelated student or faculty | If an existing non-participant account is available, visit a request link through normal navigation | The route denies access; no other participant's conversation is shown | Pending user observation | Pending |
| STATIC-3 | Assistant / PHP syntax | Run `php -l pages/shared/messages.php` after the admin view change | No syntax errors | `No syntax errors detected in pages/shared/messages.php` | Passed |
| STATIC-4 | Assistant / PHP syntax and whitespace | Run `php -l` and `git diff --check` after extending the display to faculty | No syntax or whitespace errors | PHP lint passed; `git diff --check` returned no whitespace errors (Git noted LF-to-CRLF normalization) | Passed |
| ADMIN-1 | Admin / `pages/shared/messages.php?request_id=1` | Open the link and inspect the request header and conversation without submitting anything | Student course and year are shown; existing messages are visible; the page says read-only and has no reply composer | Pending user observation | Pending |
| FACULTY-0 | Faculty / request thread screenshot before this change | Inspect the student header | Course and year should be visible to the assigned faculty adviser | User screenshot showed the thread without course/year details | Failed |
| FACULTY-1 | Faculty / existing adviser-request thread | Refresh the thread and inspect the student header | Student course and year appear; faculty can still reply as before | Awaiting user browser observation | Pending |

## Assistant-run checks

- `php -l` passed for `pages/shared/messages.php`, `pages/shared/adviser-request-messages.php`, `pages/researcher/request-adviser.php`, and `pages/faculty/faculty-advising.php`.
- Scoped `git diff --check` completed without whitespace errors.
- After extending the course/year display to faculty, `php -l pages/shared/messages.php` passed and `git diff --check -- pages/shared/messages.php` returned no whitespace errors. Git reported its existing LF-to-CRLF normalization warning.
- Source review confirms the request conversation reads/writes remain isolated to `adviser_request_messages`, and the request lookup and send handler restrict access to the requester or assigned faculty member.
- Admin-view source review confirms only the admin detail route opts into read access; the send handler still uses the default participant-only loader, and admin page views do not update participant read receipts or notification state.
- User asked to show the same course/year information on the faculty side after sharing a thread screenshot; the header display now includes faculty along with admin.
- Student course and year are read from the existing `users.program` and `users.year_level` fields; no schema or database changes were made.
- No application interactions or database edits have been performed by the assistant.
- No message rows were copied, merged, or deleted. The shared page reads and writes the existing adviser-request message table.

## Resume / handoff

- Last user-confirmed state: None for adviser-request thread integration.
- Next pending action: A faculty adviser refreshes the existing request thread in the integrated browser and confirms the student course/year appear.
- Blocked/skipped checks and remaining verification gaps: Faculty retest, admin browser observation, and prior student/faculty thread observations remain pending. No reply or record update is needed for the faculty display check.
- User-performed data changes and cleanup status: None.
