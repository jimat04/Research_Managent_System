# Verification: faculty public records

- Date/time: 2026-11-04 04:09 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Check whether `rms_db` has faculty records eligible for the Admin Public Releases page.
- Environment URL: Not applicable; read-only local database check.
- Roles and disposable records/files: None; no records were created or changed.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: Do eligible faculty publications or faculty research records exist? Selected graph labels: `publications`, `rms_db.sql`.
- Query/lookup and result: `graphify query 'database/schema/rms_db.sql::publications' --budget 1200`; output was truncated (55 nodes, 33 shown). The current page source was checked directly.
- Relevant source locations and verified relationships: `pages/admin/admin-public-releases.php` lines 224–258 loads eligible faculty publications and archived faculty research records; `database/migrations/050_faculty_research_records.sql` defines faculty records; `database/migrations/066_public_research_releases.sql` defines release tracking. Query filters were matched to the page.
- Missing, inferred, stale, or truncated context: Graph output was truncated and did not establish direct page-to-query edges. Current PHP source and canonical schema were used as authority.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| DB-1 | Read-only PHP/MySQLi query; local database | Confirm configured and connected database are both `rms_db`; count eligible faculty sources using the page filters | Counts identify any records currently eligible for the page | Target confirmed as `rms_db`; eligible faculty publications: 0; eligible archived faculty research records: 0 | Passed |
| DB-2 | Read-only PHP/MySQLi query; local database | Count all faculty publication rows and faculty research records by status/deletion state | Distinguish no records from records excluded by queue eligibility | Two faculty publications (`submitted`, `under_review`); two active faculty research records. None match the page’s publication or archived-record eligibility filters. | Passed |
| UI-1 | Browser page | No browser interaction performed; request was limited to whether matching database records exist | Not required for the database count | Skipped; no UI behavior was requested or claimed | Skipped |

## Failures, decisions, fixes, and retests
- The first all-status count attempt treated a quoted category value as an identifier and failed before returning data. Retried with a prepared category parameter; DB-2 passed with the status breakdown above. No application or database state changed.

## Assistant-run checks
- Read-only count queries confirmed the local database target and used the same eligibility filters as the page. There are two faculty publication rows, both ineligible (`submitted` and `under_review`), and two active faculty research records, also ineligible because the page only includes archived ones. No titles, names, or credentials were read into the report.
- No database writes, migration runs, or application record changes occurred.

## Resume / handoff
- Last user-confirmed state: Not applicable.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: No browser observation was made; counts establish table counts and current eligibility at query time.
- User-performed data changes and cleanup status: None.
