# Verification: registration-contact

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Registration accepts an optional contact number only when it is exactly 11 digits and begins with 09.
- Environment URL: local RMS registration form
- Roles and disposable records/files: public visitor; no account created
- Session state: in progress; static checks complete

## Graphify and source context
- Question and selected graph vocabulary: `login`, `registration`, `contact`, `sanitize`.
- Query/lookup and result: Read-only lookup of `graphify-out/graph.json` (1,588 nodes); `public/login.php` and registration switch nodes are indexed, but no specific contact-field node is present.
- Relevant source locations and verified relationships: `public/login.php` reads and sanitizes `contact`, validates registration before inserting into `users`, and renders the Contact Number input. The users schema stores contact as `varchar(30)`.
- Missing, inferred, stale, or truncated context: The graph does not model the contact validation flow; current PHP source confirmed it.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| P1 | PHP CLI / source | `php -l public/login.php` | No syntax errors | No syntax errors detected | Passed |
| P2 | Integrated browser / public visitor / registration | Submit invalid-length and non-09 contact values on an otherwise valid disposable registration | Form blocks invalid values; no account is created | Not exercised; no disposable registration target was established | Blocked |

## Failures, decisions, fixes, and retests
- Added HTML pattern, numeric keyboard hint, and 11-character bounds. Added server validation requiring the exact `09` plus nine digits format when a number is supplied. The field remains optional.

## Assistant-run checks
- `php -l public/login.php` — passed.
- `git diff --check -- public/login.php` — passed.
- No account or database was changed by the assistant.

## Resume / handoff
- Last user-confirmed state: none.
- Next pending action: if a verified disposable local registration target is available, user can test invalid phone values through the normal registration form.
- Blocked/skipped checks and remaining verification gaps: application behavior not exercised to avoid creating an account in an unverified database target.
- User-performed data changes and cleanup status: none.
