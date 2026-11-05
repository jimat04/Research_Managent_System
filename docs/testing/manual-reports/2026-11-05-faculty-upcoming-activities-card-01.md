# Verification: faculty upcoming activities stat card

- Date: 2026-11-05 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The faculty dashboard stat card reads “Upcoming Activities,” shows the count of activities with `upcoming` or `scheduled` status, and links to the shared upcoming activities view. If `research_activities` is unavailable, the card shows an unavailable state.
- Environment URL: `http://localhost/rms/` (fallback only; runtime setting and availability not verified)
- Role: Faculty
- Session state: In progress

## Graphify and source context

- Question and selected vocabulary: How does the faculty dashboard obtain and render the upcoming activities metric? Selected terms: `FacultyDashboardService`, `research_activities`, `upcoming`.
- Query: `graphify query "FacultyDashboardService research_activities upcoming" --budget 1500`.
- Result: 23 nodes and 20 edges were returned, slightly over budget. The graph surfaced `pages/shared/activities.php` but did not include the faculty dashboard service or its card template.
- Current source confirmation: `includes/services/FacultyDashboardService.php` provides dashboard data; `includes/faculty-dashboard/overview.php` renders the card; `pages/faculty/faculty-dashboard.php` supplies its destination. The shared upcoming view counts statuses `upcoming` and `scheduled`.
- Missing/stale graph context: No graph relationship for the faculty dashboard count/rendering path was returned; current source was used.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence | Status |
|---|---|---|---|---|---|
| 1 | Assistant static check | Run `php -l` on all three changed PHP files | No syntax errors | All three reported no syntax errors | Passed |
| 2 | Assistant static check | Run `git diff --check` | No whitespace errors in the task changes | No task-file whitespace errors reported | Passed |
| 3 | User-operated browser / Faculty / Dashboard | Inspect the stat card, then open it | “Upcoming Activities” shows a count and opens the shared upcoming activities view with matching count | Awaiting user observation | Pending |

## Database and application changes

- No database changes. The dashboard reads the existing `research_activities` table and returns an unavailable state if that table is absent.
- No application interaction or data change has been performed by the assistant.

## Resume

- Pending: user observation for browser check 3.
