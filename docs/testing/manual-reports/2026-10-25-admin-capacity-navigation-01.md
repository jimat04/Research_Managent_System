# Verification: Admin capacity-building navigation

- Date/time: 2026-10-25, Asia/Taipei
- Assistant: GitHub Copilot
- Target and expected behavior: The Research Capacity Building title opens the general activities page; its separate disclosure toggles the Add Activity and Activity subgroups.
- Environment URL: `http://localhost/rms/`
- Roles and disposable records/files: Admin; no records or files used
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How does the Admin Research Capacity Building sidebar reach the shared activities page? Selected graph vocabulary: `activities.php`.
- Query/lookup and result: `graphify query "activities.php" --budget 3000` returned the activities page node and related activity helpers/routes (52 nodes, 74 edges; complete result).
- Relevant source locations and verified relationships: `includes/admin-shell.php` defines the Admin navigation and shared page active-state matcher; `pages/admin/admin-research-module.php` retains filtered activity destinations; `pages/shared/activities.php` validates the `type` filter and applies role/module permissions.
- Missing, inferred, stale, or truncated context: The graph includes historical test references. Current source was inspected directly; graph relationships were treated as hints. No live browser action has been performed for this change.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | PHP syntax check / assistant | Run `php -l includes/admin-shell.php` | No syntax errors | `No syntax errors detected` | Passed |
| S2 | Focused source check / assistant | Inspect Research Capacity Building links and route handling | Parent link uses the base `activities.php` URL; the existing type-specific filter remains available for filtered routes | Parent URL/source assertions passed; `admin-research-module.php` retains its seminar-filter route and `activities.php` retains validated type handling | Passed |
| S3 | Diff whitespace check / assistant | Run `git diff --check` for changed files | No whitespace errors | Passed; Git emitted only its existing LF-to-CRLF working-copy warning | Passed |
| B1 | Browser / Admin / sidebar / desktop | Click the Research Capacity Building title | The general activities page opens without `?type=seminar`; the active section is visible | Pending user confirmation | Pending |
| B2 | Browser / Admin / sidebar / desktop | Click the separate Research Capacity Building chevron | Add Activity and Activity disclosure groups expand/collapse without navigating | Pending user confirmation | Pending |

## Failures, decisions, fixes, and retests
- No failures observed yet.

## Assistant-run checks
- `php -l includes/admin-shell.php` — passed.
- Focused PowerShell source assertions confirmed the base activities URL, Activity submenu label, absence of a default `?type=seminar` sidebar route, and retained filtered-route/type handling.
- `git diff --check -- includes/admin-shell.php css/admin-shell.css` — passed.

## Resume / handoff
- Last user-confirmed state: The requested parent-title navigation behavior was selected.
- Next pending action: Run static checks, then ask the user to confirm the sidebar behavior in the integrated browser.
- Blocked/skipped checks and remaining verification gaps: Browser confirmation is pending.
- User-performed data changes and cleanup status: None.
