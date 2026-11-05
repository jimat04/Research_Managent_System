# Verification: login demo panel removal

- Date/time: 2026-11-05 17:19 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Remove demo credential text from the login page; the regular sign-in form remains available.
- Environment URL: http://localhost/rms/public/login.php (configured fallback)
- Roles and disposable records/files: Public login page; no records or files used.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: Where is the public login page and how does the demo credential panel relate to it? Selected terms: `login`, `credentials` (matched `login.php`, `loginFailureKey()`, and `Demo Credentials`).
- Query/lookup and result: `graphify query "login credentials" --budget 1500` returned the login page and related login helpers; 24 nodes and 31 edges were returned, with the complete result exceeding the requested token budget.
- Relevant source locations and verified relationships: Graph lists `login.php` at `public/login.php:L1` and `Demo Credentials` in `README.md:L68`. Current source confirms the credential panel was inline in `public/login.php` immediately before the login form; its presentation rules were local to the same page.
- Missing, inferred, stale, or truncated context: The README credential concept is unrelated to the visual rendering change. Graph output exceeded its requested budget but stated all matching nodes and edges were included. Current source was checked after the edit.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant static check / PHP source | Run `php -l public/login.php` | PHP syntax is valid | `No syntax errors detected in public/login.php` | Passed |
| A2 | Assistant static check / `public/login.php` | Search for demo panel class, heading, and listed account emails | No demo credential panel or account text remains in the login source | Focused `rg` search returned no matches | Passed |
| U1 | User browser check / public login page | Open or refresh the login page | Login form remains visible and no demo credential heading, account emails, or passwords appear | User confirmed: form visible; demo text is gone | Passed |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- `rtk proxy php -l public/login.php` — passed.
- Focused search of `public/login.php` for the removed panel and demo account strings — no matches.
- Graphify lookup: `graphify query "login credentials" --budget 1500` — returned the full matching result, which exceeded the requested budget.

## Resume / handoff
- Last user-confirmed state: Login form is visible and demo text is gone.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None for this change.
- User-performed data changes and cleanup status: None.
