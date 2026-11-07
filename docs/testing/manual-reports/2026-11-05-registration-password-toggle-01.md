# Registration Password Visibility Toggle

Date: 2026-11-05 (Asia/Taipei)

## Scope

Add independent show/hide controls to the registration password and confirmation fields on `public/login.php`.

## Assistant checks

- Graphify lookup: `rtk graphify query registration --budget 1500` (BFS, depth 2).
- Terms matched: `registration`, `updateRegistrationFields()`, `togglePassword()`.
- Confirmed graph relationships: `login.php` contains `updateRegistrationFields()` and `togglePassword()`; the current page defines both helpers. Graph was built from commit `9d996919`, so it may not include later changes. No relevant missing or truncated graph coverage was reported by the query.
- Current source reviewed: `public/login.php`, registration fields around lines 930–948 and `togglePassword()` around line 1000. The form posts to the existing registration handler in the same file; this change only alters presentation and input type.
- Static PHP syntax check: Passed (`rtk proxy php -l public/login.php`).
- Follow-up animation: Added a short scale/rotation fade when switching eye states, enforced hiding of the inactive SVG, and disabled that motion for `prefers-reduced-motion`.
- Browser interaction: Passed. User confirmed all checks passed, including the independent password visibility controls and their interaction behavior.

## User-operated check

Passed. User confirmed all checks passed on `public/login.php`.

## Status

- Assistant static check: Passed
- User browser observation: Passed (user confirmation, 2026-11-05).
