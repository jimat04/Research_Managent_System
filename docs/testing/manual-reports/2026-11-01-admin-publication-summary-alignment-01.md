# Verification: Admin publication summary alignment

- Date/time: 2026-11-01 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Admin grouped report tables share column positions; labels stay left and count headings and values align right. Non-admin archive summaries keep their current layout.
- Environment URL: `http://localhost/rms/` (configured fallback; runtime not independently confirmed)
- Roles and disposable records/files: Admin role; no record changes required.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How admin archive report tables render and inherit shell table styles. Initial terms: `admin-reports`, `publication`; follow-up terms: `admin-shell`, `archive-table`.
- Query/lookup and result: `graphify query 'admin-reports publication' --budget 1500` found report/publication nodes but no useful edge for the grouped summary layout. Follow-up `graphify query 'admin-shell archive-table' --budget 1500` found `renderAdminShell()` in `includes/admin-shell.php:L42`; the result also contained unrelated nodes and exceeded the requested budget.
- Relevant source locations and verified relationships: `pages/admin/admin-publication-reports.php` enables the admin publication report view; `includes/archival-record-list.php` renders grouped report tables near L173. `includes/archival-record-ui.php:L10-L14` renders the admin shell then links `css/archival-records.css`. `includes/admin-shell.php:L858` has a left-alignment rule for all admin table headers. Current source confirmed this CSS cascade.
- Missing, inferred, stale, or truncated context: Graphify did not describe the grouped table markup or CSS cascade. Source was checked directly. Follow-up query was truncated/over budget; no graph edge was inferred.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant static check | Run `php -l includes/archival-record-list.php` | PHP syntax passes | No syntax errors detected | Passed |
| A2 | Assistant static check | Run scoped `git diff --check` | No whitespace errors | No output/errors | Passed |
| U1 | User browser / admin / report summary | Inspect grouped summary tables | All tables share consistent column positions | User screenshot showed headers shifting between Year, Faculty owner, Academic program, Record type, and External registration / IP status tables | Failed |
| A3 | Assistant static check | Run `php -l` and scoped `git diff --check` after the shared layout change | PHP syntax and whitespace checks pass | Both passed | Passed |
| U2 | User browser / admin / same report summary | Refresh and inspect count values | Counts align right within their columns | User reports the value `2` still does not align right | Failed |
| A4 | Assistant static check | Run scoped `git diff --check` after increasing selector specificity | No whitespace errors | No output/errors | Passed |
| U3 | User browser / admin / same report summary | Refresh and inspect the `2` count values | Each count is right-aligned to the same edge as its column heading | Awaiting user retest | Pending |

## Failures, decisions, fixes, and retests
- U1 failed because the category column width varied between tables. Admin grouped summaries were changed to use a shared 50% / 25% / 25% layout.
- U2 failed because the user reported `2` still did not align right. The admin shell explicitly left-aligns table headers. The summary selector was strengthened to override that shell rule for count columns.

## Assistant-run checks
- `php -l includes/archival-record-list.php` — passed after the shared layout change.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- css/archival-records.css includes/archival-record-list.php docs/testing/manual-reports/2026-11-01-admin-publication-summary-alignment-01.md` — passed after the selector adjustment. The repository has extensive pre-existing changes.

## Resume / handoff
- Last user-confirmed state: User reports the count `2` remains left-aligned.
- Next pending action: Ask the user to refresh and inspect the count values.
- Blocked/skipped checks and remaining verification gaps: Numeric value alignment awaits user retest.
- User-performed data changes and cleanup status: None.
