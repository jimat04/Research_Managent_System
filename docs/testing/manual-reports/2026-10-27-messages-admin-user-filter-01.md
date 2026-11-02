# Verification: admin message user filter

- Date/time: 2026-10-27, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Admin can filter inbox messages by sender name and sender role, with the signed-in user's role omitted and Faculty labeled “Faculty”; admins can search recipient names and filter recipient choices by role and course when starting a new conversation; faculty can be assigned to BSIT, BSCS, or both; all message-page hero copy accurately describes role-specific actions and current page state; filtered message links retain active filters when returning to the inbox. Existing message participant authorization remains in force.
- Environment URL: `http://localhost/rms/`
- Roles and disposable records/files: Admin account and an existing inbox sender; no message will be sent.
- Session state: Complete; migration applied to local `rms_db`; user reports the feature is working.

## Graphify and source context
- Question and selected graph vocabulary: Trace the relationship between faculty, programs, and recipient course filtering. Selected graph terms: `faculty`, `programs`, `departments`.
- Query/lookup and result: `graphify query "faculty program department" --budget 1400` returned 8 nodes and 8 edges, mainly program/department concepts and faculty shell relationships. It did not expose a faculty-to-program assignment edge.
- Relevant source locations and verified relationships: `database/schema/rms_db.sql:274-283` defines programs; `database/schema/rms_db.sql:599-630` gives users a single `program` field; `pages/admin/admin-users.php:831-832` labels that field Course only for students; the admin create/edit forms now assign one or both faculty programs; `pages/shared/messages.php` joins active faculty program assignments into recipient choices. Migration `042_faculty_program_assignments.sql` creates the mapping table and backfills recognized legacy BSIT/BSCS values.
- Missing, inferred, stale, or truncated context: Graphify output exceeded the requested budget because all 33 matching nodes and edges fit; the graph did not provide useful page-level relationships. Current source was inspected directly and is authoritative.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant, PHP CLI | `php -l pages/shared/messages.php` | No syntax errors | Latest run after admin compose changes reported no syntax errors. | Passed |
| S2 | Assistant, Git | `git diff --check -- pages/shared/messages.php` | No whitespace errors | Latest run completed without whitespace errors; Git reported its LF-to-CRLF normalization warning. | Passed |
| S3 | Assistant, source review | Review filter SQL, allowed role options, and detail access | Filters are prepared; the signed-in role is excluded; message detail remains participant-scoped | Role options and role validation use the allowlist with the current role removed. Name and role inputs are bound as parameters. Detail query still requires sender or recipient to match the logged-in user. | Passed |
| S4 | Assistant, source review | Review new-conversation and reply POST paths | New messages require an active non-self recipient; replies require a valid participant-scoped thread | Recipient is rechecked against active, non-deleted users. A missing thread ID selects new-message handling; a supplied invalid thread ID is rejected. Reply recipient continues to be derived from the verified thread. | Passed |
| S5 | Assistant, source review | Review recipient filter controls and fallback | Search, role, and course controls narrow recipient choices; the form remains usable without JavaScript | Filtering is scoped to the active-user select; names, roles, and student program/course values are escaped in option data; without JavaScript all active recipients remain selectable. Courses are derived from active student recipients' `users.program` values. | Passed |
| S6 | Assistant, PHP CLI | `php -l pages/admin/admin-users.php` | No syntax errors | No syntax errors detected. | Passed |
| S7 | Assistant, PHP CLI | `php -l pages/shared/messages.php` | No syntax errors | No syntax errors detected. | Passed |
| S8 | Assistant, PHP CLI | `php -l scripts/migrate.php` | No syntax errors | No syntax errors detected. | Passed |
| S9 | Assistant, Git | `git diff --check` on affected PHP/schema/migration files | No whitespace errors | Completed without whitespace errors; Git reported line-ending normalization warnings for pre-existing modified files. | Passed |
| S10 | Assistant, source review | Review faculty assignments and recipient course filtering | Assignments support one or both BSIT/BSCS programs; assigned faculty match the corresponding course filters | Admin create/edit validates active program IDs and saves assignments transactionally; recipient filtering reads mapped active program codes. | Passed |
| S11 | Assistant, PHP CLI/read-only DB probe | Confirm connected target, migration ledger, and source data before migration | Connected DB is local `rms_db`; 042 is pending; affected data is understood | Connected database was `rms_db`; 042 was pending; assignment table was absent; 2 active faculty, 0 with a legacy program value, 2 active BSIT/BSCS catalog rows. CLI probe emitted session-file permission warnings, but all read-only queries returned successfully. | Passed |
| S12 | Assistant, RMS migration runner and read-only postflight | Apply 042 and verify target/schema/ledger | Migration is applied and verified on `rms_db` | Runner reported `APPLIED`; postflight confirmed `rms_db`, ledger status `applied`, table present, and 0 assignment rows (no existing faculty course value needed backfill). | Passed |
| S13 | Assistant, backup verification | Back up affected faculty program values and BSIT/BSCS catalog rows before migration | Ignored backup file exists, is nonempty, and has a recorded checksum | Created `backups/faculty_programs_before_042_2026-10-27.csv` (267 bytes, SHA-256 `84cc912c713439a1881e6fc67ecbcdd9f5a14ef4aed60a73c86cb29e7969b8ff`); Git confirmed the file is ignored. | Passed |
| B1 | User, Admin, Shared Messages | Search for a sender name known to have messaged the admin | Inbox shows matching senders | User confirmed the search showed matching messages. | Passed |
| B2 | User, Admin, filtered inbox | Open one of the matching messages | The selected message detail opens | User's overall confirmation: “it's all working”; individual actions/results were not itemized. | Passed |
| B3 | User, Admin, message detail | Return to the inbox | The sender filter remains applied | User's overall confirmation: “it's all working”; individual actions/results were not itemized. | Passed |
| B4 | User, Admin, Shared Messages | Inspect role options, select a non-admin role, and submit | Admin is absent; Faculty is labeled “Faculty”; inbox shows only messages from senders of the selected role. Name and role filters can be combined. | User's overall confirmation: “it's all working”; individual actions/results were not itemized. | Passed |
| B5 | User, Admin, Shared Messages | Click the “Compose a message” hero action | An admin composer opens | User reported the compose action cannot be clicked. Source review found it links to `#compose`, while the admin compose panel is omitted; only thread replies render a compose form. | Failed |
| B6 | User, Admin, Shared Messages | Click the “Compose a message” hero action after the fix | The admin new-message form opens and lists active users other than the signed-in admin | User's overall confirmation: “it's all working”; individual actions/results were not itemized. | Passed |
| B7 | User, Admin, new-message form | Send a message to an agreed disposable active recipient | Message appears in Sent and the recipient receives it in their inbox/notification feed | No specific send, persistence, or notification result was reported. Aggregate confirmation was not detailed enough to verify this data-changing action separately. | Skipped |
| B8 | User, Admin, Shared Messages | Open the messages page and review the hero content | Hero describes starting conversations and filtering incoming messages; available-recipient count is shown | User's overall confirmation: “it's all working”; individual actions/results were not itemized. | Passed |
| B9 | User, Admin, new-message form | Select Faculty in the recipient role filter | Recipient choices narrow to faculty users and the count updates | User's overall confirmation: “it's all working”; individual actions/results were not itemized. | Passed |
| B12 | User, Admin, new-message form | Search for a known recipient name | Recipient choices narrow to matching users; the count updates | User's overall confirmation: “it's all working”; individual actions/results were not itemized. | Passed |
| B10 | User, Admin, new-message form | Select a course | Recipient options narrow to active students and faculty assigned to the selected course; faculty assigned to both appear for either course; the result count updates | User's overall confirmation: “it's all working”; individual actions/results were not itemized. No faculty program mappings were present in the pre-migration data snapshot. | Passed |
| B11 | User, Admin, User Management | Create or edit a disposable faculty account and assign one or both programs | Faculty program assignments persist and display as BSIT, BSCS, or both | User's overall confirmation: “it's all working”; individual actions/results and any record cleanup were not itemized. | Passed |

## Failures, decisions, fixes, and retests
- B5: Admin’s hero compose action originally had no target because the admin compose panel was hidden and admin sends required a thread message ID. The user authorized new conversations; an admin composer and new-message handling were implemented. B6 was subsequently confirmed by the user as part of the overall “it's all working” report.
- The user confirmed overall that the message page and filters are working. Specific browser actions, message delivery, record identifiers, and cleanup were not itemized; B7 is therefore skipped because it requires a separately observed send and delivery result.

## Assistant-run checks
- `php -l pages/admin/admin-users.php`, `php -l pages/shared/messages.php`, and `php -l scripts/migrate.php` passed.
- Focused `git diff --check` passed with line-ending normalization warnings only.
- Migration `042_faculty_program_assignments.sql` was applied to local `rms_db` through the runner. It created the assignment table; 0 mapping rows were backfilled because neither active faculty account had a legacy `users.program` value. The assistant did not send messages or add faculty assignments; user-performed browser changes, if any, were not itemized.
- Backup created before migration: `backups/faculty_programs_before_042_2026-10-27.csv`, 267 bytes, SHA-256 `84cc912c713439a1881e6fc67ecbcdd9f5a14ef4aed60a73c86cb29e7969b8ff`; ignored by Git.

## Resume / handoff
- Last user-confirmed state: User said “it's all working” for the message feature overall; individual browser actions/results were not itemized.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: B7 remains skipped because no specific send/persistence/notification result was reported. No record identifiers or cleanup details were provided. The Clear control was not individually reported.
- User-performed data changes and cleanup status: Not itemized by the user; do not infer whether any disposable records or messages were created or cleaned up.
