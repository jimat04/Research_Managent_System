# Verification: faculty dashboard stats

- Date/time: 2026-11-02 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Faculty dashboard presents five top-level metric cards in one responsive grid: advising projects, publication records, verified engagements, active research records, and activities participated. The final two cards are centered beneath the first three.
- Environment URL: Not yet confirmed
- Roles and disposable records/files: Faculty role; no record changes required
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How does the faculty dashboard get owned research-record totals and participation totals? Selected terms: `faculty`, `research`, `records`, `participation`.
- Query/lookup and result: `graphify query "faculty research records participation" --budget 1500` returned general faculty/workflow nodes, but no dashboard service or record-count implementation. The result was over budget and included unrelated nodes.
- Relevant source locations and verified relationships: `pages/faculty/faculty-dashboard.php` role-gates the dashboard and supplies the research-record and engagement-history URLs. `includes/services/FacultyDashboardService.php` now counts active research records using `owner_id`, `status = 'active'`, and `deleted_at IS NULL`. The canonical schema and migration `050_faculty_research_records.sql` confirm these columns and the `active`/`archived` statuses. `FacultyParticipationService::getMySummary()` returns `activity_count` as distinct activity IDs for the current faculty account. The Active Research Records card links to `pages/faculty/faculty-research-records.php`; Activities Participated links to the existing faculty engagement history, as previously selected by the user.
- Missing, inferred, stale, or truncated context: Graphify did not cover these service/table relationships; all claims above were confirmed against current source and schema. There is no faculty activity-specific detail list, so the activity card retains the selected engagement-history destination.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Source review | Review metric rendering and responsive CSS | One five-card grid with two centered metric cards and meaningful unavailable states | `overview.php` renders Active Research Records and Activities Participated after the three existing metrics. The service scopes record counts to the faculty owner, excludes archived/deleted records, and reuses the distinct activity count. | Passed |
| 2 | PHP syntax | Run PHP lint on changed PHP files | No syntax errors | `php -l` passed for `FacultyDashboardService.php`, `faculty-dashboard.php`, and `overview.php`. | Passed |
| 3 | Diff whitespace | Run `git diff --check` on the changed tracked files | No whitespace errors | Exit code 0; Git emitted only an LF-to-CRLF notice for `faculty-dashboard.php`. | Passed |
| 4 | Browser / faculty / dashboard | Open the faculty dashboard at desktop width | Five cards appear, with Active Research Records and Activities Participated centered beneath the first three | Awaiting user observation | Pending |
| 5 | Browser / faculty / dashboard | Click Active Research Records | My Research Records opens | Awaiting user observation | Pending |
| 6 | Browser / faculty / dashboard | Click Activities Participated | Faculty engagement history opens | Awaiting user observation | Pending |
| 7 | Browser / faculty / dashboard | Resize to tablet and mobile widths | Cards reflow without horizontal overflow or clipped labels | Awaiting user observation | Pending |
| 8 | Browser / faculty / dashboard | Earlier attendee/presenter card checks | Superseded by the approved metric replacement | Not performed; obsolete labels/destinations removed | Skipped |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l includes/faculty-dashboard/overview.php` — passed after the clickable-card update.
- `git diff --check -- css/faculty-shell.css` — passed.
- No automated browser or application actions were run.

## Resume / handoff
- Last user-confirmed state: None.
- Next pending action: Ask the user to inspect the new centered metrics in the faculty dashboard at desktop width in the integrated browser.
- Blocked/skipped checks and remaining verification gaps: Browser appearance and responsive behavior await user operation and observation.
- User-performed data changes and cleanup status: None.
