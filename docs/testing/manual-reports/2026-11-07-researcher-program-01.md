# Verification: researcher program display

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Admin researcher profile displays faculty program assignment(s), falling back to the legacy users.program value; students continue to see users.program.
- Environment URL: http://localhost:8080/rms/pages/admin/admin-researchers.php
- Roles and disposable records/files: Faculty account shown in user-provided screenshot; no data changes.
- Session state: finished

## Graphify and source context
- Question and selected graph vocabulary: Why does the admin researcher profile show a faculty program as not recorded? Selected existing terms: `adminresearchersrun`, `program`, `faculty`.
- Query/lookup and result: `graphify query "adminresearchersrun program faculty" --budget 1500` (via `rtk proxy`), BFS depth 2, 14 nodes. It found the researcher page and program concept but no relationship to the faculty assignment tables.
- Relevant source locations and verified relationships: `pages/admin/admin-researchers.php` loads `users.program` for profiles and renders that value at line 319. `database/migrations/042_faculty_program_assignments.sql` defines `faculty_program_assignments` and seeds it from existing faculty `users.program`. `includes/academic-affiliation.php` writes normalized faculty program selections to `user_academic_program_assignments` and synchronizes canonical program IDs into `faculty_program_assignments`. `pages/admin/admin-users.php` displays faculty program codes from `faculty_program_assignments` joined to `programs`.
- Missing, inferred, stale, or truncated context: Graphify did not include the assignment helper/table path in its focused result; current source inspection confirms the relationship. No live database values were inspected.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static / changed PHP | Run `rtk php -l pages/admin/admin-researchers.php` | No PHP syntax errors | `No syntax errors detected in pages/admin/admin-researchers.php` | Passed |
| B1 | Admin / researcher profile | Refresh the screenshot's researcher profile | Program shows assigned program code(s), with existing legacy value as fallback | User confirmed: all checks passed. | Passed |

## Failures, decisions, fixes, and retests
- Initial issue: user-provided screenshot shows a faculty profile with Program `Not recorded`.
- Cause: the page reads only `users.program`, while current faculty program assignments are stored in normalized affiliation records and synchronized into `faculty_program_assignments`.
- Fix: `pages/admin/admin-researchers.php` now reads assigned faculty program codes from `faculty_program_assignments` joined to `programs`, when those tables exist. If there are no assigned codes, it keeps the legacy `users.program` fallback. Student display behavior is unchanged.

## Assistant-run checks
- `rtk php -l pages/admin/admin-researchers.php` — passed.

## Resume / handoff
- Last user-confirmed state: User provided the profile screenshot and URL; no browser state has been observed by the assistant.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported. The exact Program text was not provided; no database query was run.
- User-performed data changes and cleanup status: None.
