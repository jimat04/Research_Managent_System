# Verification: login home transition

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Clicking Back to homepage on the login page briefly fades and lifts the login panel, then opens the public landing page.
- Environment URL: Local RMS application; exact URL not captured.
- Roles and disposable records/files: Public login page; no account or records required.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How the login page's Back to homepage link reaches the public landing page; selected terms: `back-home`, `landing`.
- Query/lookup and result: `graphify query "back-home landing" --budget 1500`; returned five nodes, including `landing-v2.js` and a public homepage image node. No edge described the login link transition.
- Relevant source locations and verified relationships: `public/login.php` renders `.back-home` as `index.php`; the new listener adds `is-leaving-home` to `.auth-split` before navigating to the link URL.
- Missing, inferred, stale, or truncated context: Graph result did not cover the link-specific behavior; source inspection confirms it. Existing graph may not represent this exact relationship.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant static check / PHP syntax | Run `php -l public/login.php` | No PHP syntax errors | Command reported no syntax errors | Passed |
| U1 | User browser / public login page | Click Back to homepage | Login panel fades and lifts, then the public landing page appears | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l public/login.php` — passed. Browser behavior remains unverified.

## Resume / handoff
- Last user-confirmed state: None yet.
- Next pending action: On the local public login page, click Back to homepage and report whether the animation appears before the landing page loads.
- Blocked/skipped checks and remaining verification gaps: Browser result pending. Reduced-motion behavior is supported in source but not browser-checked.
- User-performed data changes and cleanup status: None.
