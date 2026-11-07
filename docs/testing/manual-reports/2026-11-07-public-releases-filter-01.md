# Verification: public release school year filter

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Admin public release queue filters projects and project-linked faculty publications by selected school year while retaining source, status, and search filters.
- Environment URL: http://localhost:8080/rms/pages/admin/admin-public-releases.php
- Roles and disposable records/files: Admin; no record changes planned.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: Which school-year data and relationships support the admin public-release queue? Selected `academic_years` from the graph labels; no matching public-release page/query term was present.
- Query/lookup and result: `graphify query "academic year public releases" --budget 1500`; `academic_years` and the public archive page were found, but no admin public-release queue nodes or relevant year relationships.
- Relevant source locations and verified relationships: `pages/admin/admin-public-releases.php` contains three queue queries; `research_projects.ay_id` references `academic_years.ay_id` in `database/schema/rms_db.sql`; `publications.research_project_id` references `research_projects.project_id`. `faculty_research_records` has no school-year relation.
- Missing, inferred, stale, or truncated context: Graphify coverage for the release queue is absent. Current schema and page source confirm the associations used. Standalone faculty research records are excluded when a specific school year is selected because they have no school-year field.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Assistant source check | Review selected-year query conditions and query parameter bindings | Project rows and linked faculty publications are filtered by valid year; standalone faculty records are omitted for a selected year | Source reviewed at lines 285-369; parameter counts and ordering match each query | Passed |
| 2 | Assistant static check | Run `php -l pages/admin/admin-public-releases.php` | No PHP syntax errors | `No syntax errors detected in pages/admin/admin-public-releases.php` after the final edit | Passed |
| 3 | Admin browser check | Select a school year and submit the filter form | Selected year remains selected; queue contains only matching project-linked records; other filters continue to apply | Pending user observation | Pending |
| 4 | Admin browser check | Select “All school years” and submit | Full queue returns, including standalone faculty research records | Pending user observation | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l pages/admin/admin-public-releases.php` — passed after the final edit; no syntax errors.
- Source review confirmed school-year options are loaded from `academic_years`, invalid year IDs fall back to all years, all three queue queries use prepared bindings, and pagination/POST redirects preserve the selected year.
- Git diff review also showed pre-existing unrelated edits in the same page and many other worktree files; they were preserved.

## Resume / handoff
- Last user-confirmed state: None; no browser interaction performed.
- Next pending action: Ask the user to check the page in the integrated browser.
- Blocked/skipped checks and remaining verification gaps: Browser behavior is pending user operation.
- User-performed data changes and cleanup status: None.
