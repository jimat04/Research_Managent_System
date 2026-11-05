# Verification: public-login-button-icon

- Date/time: 2026-11-04 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The Sign In button at `public/login.php` displays its label without the key icon.
- Environment URL: http://localhost:8080/rms/public/login.php
- Roles and disposable records/files: Public login page; no records or files.
- Session state: Pending user browser observation.

## Graphify and source context
- Question and selected graph vocabulary: Which page renders the login form? Selected vocabulary: `login`, `authentication`, `submit`.
- Query/lookup and result: `graphify query "login authentication submit" --budget 1500`; it found the `public/login.php` node and login helpers, plus unrelated milestone and historical report nodes. The response exceeded the requested budget because all 28 nodes and 30 edges fit; no focused button relationship was available.
- Relevant source locations and verified relationships: `public/login.php:809-811` renders the login submit button. Its content is now `Sign In` with the key emoji removed. The earlier edit to `public/landing-pages/inner-green-3d.html` was restored; the Sign In dock icon remains.
- Missing, inferred, stale, or truncated context: Graph coverage does not identify the button markup; source inspection confirms it directly.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| UI-01 | User browser / public / login page | Reload the login page and inspect its Sign In button | “Sign In” appears without the key icon | Awaiting user observation | Pending |

## Assistant-run checks
- `php -l public/login.php`: passed.
- Source review: confirmed the target login button contains only the “Sign In” label; landing-page dock markup was restored.
