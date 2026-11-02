# Verification: Chapter Review labels

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The admin and staff visible name for the chapter submission review module is Chapter Review, while the existing route and permission key remain `research_review`.
- Environment URL: http://localhost/rms/ (configured local fallback; application availability not confirmed)
- Roles and disposable records/files: Administrator and research staff; no record changes.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: research_review, Chapter Review, permission labels, admin navigation, staff dashboard.
- Query/lookup and result: `graphify query "research_review Chapter Review permission labels admin staff dashboard" --budget 1500`; truncated at 38/120 nodes, with much unrelated/stale coverage.
- Relevant source locations and verified relationships: `includes/admin-shell.php` routes the Chapter Review sidebar entry through `module=research_review`; `pages/admin/admin-research-module.php` keeps `research_review` as the key and redirects to `module_page=research_review`; `includes/auth.php` labels that permission Chapter Review; the shared review page uses Chapter Review as its queue title/tab.
- Missing, inferred, stale, or truncated context: Graph results truncated and did not provide reliable label/navigation edges; current source was inspected directly.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Integrated browser / administrator / admin sidebar | Click Chapter Review | Chapter review queue opens and page heading says Chapter Review | User confirmed it opens correctly | Passed |
| 2 | Integrated browser / administrator / user permissions | Open a staff member's permission list without saving | Research Development permission is labeled Chapter Review | User confirmed the label reads Chapter Review | Passed |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l` passed for all six changed PHP files: `includes/auth.php`, `includes/admin-shell.php`, `pages/admin/admin-research-module.php`, `pages/staff/staff-dashboard.php`, `pages/shared/research-submission-review.php`, and `pages/shared/download-research-document.php`.
- Focused source search found no remaining visible `Research Review` or `Review Chapters` labels in `includes/` and `pages/`; `research_review` remains in routes and permission checks.
- `git diff --check` on changed PHP files: passed.
- No database changes or browser interactions were performed.

## Resume / handoff
- Last user-confirmed state: admin sidebar opens the chapter review queue, and staff permissions display Chapter Review.
- Next pending action: none.
- Blocked/skipped checks and remaining verification gaps: Graphify coverage was truncated; the relevant labels and route were confirmed against current source and browser observations.
- User-performed data changes and cleanup status: none.
