# Verification: Faculty advisee name display

- Date/time: 2026-11-01 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The advisee matrix displays the linked researcher’s name for project 60 instead of “Researcher not recorded.”
- Environment URL: http://localhost/rms/pages/faculty/faculty-dashboard.php
- Roles and disposable records/files: Faculty role; existing project 60; no record changes.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: How does the faculty dashboard resolve advisee names? Terms: `faculty-dashboard.php`, `student_id`, `FacultyAdviseeProgressService`.
- Query/lookup and result: `graphify query "faculty-dashboard.php student_id FacultyAdviseeProgressService" --budget 1500`; 76 nodes found and truncated to 42. The results confirm the dashboard and shell nodes but do not expose the relevant service relationship.
- Relevant source locations and verified relationships: `pages/faculty/faculty-dashboard.php` requests overview data through `FacultyDashboardService`. `includes/services/FacultyAdviseeProgressService.php` builds the matrix from projects assigned in `project_advisers`. Before the fix, it joined `users` only through `research_projects.student_id` and used “Researcher not recorded” when that linked user name was blank. It now falls back to `created_by` for both the user lookup and grouping key. `pages/shared/research-detail.php` displays the project lead via `research_projects.created_by`. Canonical `database/schema/rms_db.sql` defines both `created_by` and nullable `student_id`; migration `rms_db_migration.sql` backfills `student_id` from `created_by` when null. Migrations 058 and 059 introduce/maintain project members and author snapshots.
- Missing, inferred, stale, or truncated context: Graphify did not show the relevant service edge. The target database row has not yet been inspected; the graph’s relationships are only hints.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| D1 | Assistant read-only local database check | Confirm the configured database is `rms_db`; inspect project 60’s researcher link and associated user-name availability | Find a valid intended researcher relationship without changing data | `student_id` is null; `created_by` resolves to an active user who is also the project’s lead member. The creator has a name, and the updated service returns a researcher name for project 60. No name value was included in the report. | Passed |
| S1 | Assistant static check / changed PHP | Run `php -l` on changed PHP files | No syntax errors | `php -l includes/services/FacultyAdviseeProgressService.php` passed. | Passed |
| S2 | Assistant static check / changed files | Run whitespace check on changed files | No whitespace errors | `git diff --check -- includes/services/FacultyAdviseeProgressService.php` passed. | Passed |
| B1 | User browser / faculty dashboard | Refresh the faculty dashboard and open project 60 from Advisee Progress | The correct researcher name appears in the matrix and project detail | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- Diagnosis confirmed: project 60 has no `student_id`, while its `created_by` user is active and matches its lead project member. The matrix previously joined users only through `student_id`.
- Fixed the lookup and matrix grouping to use `COALESCE(student_id, created_by)`, matching the lead source shown by `research-detail.php` for this legacy/incomplete link.

## Assistant-run checks
- Graphify CLI and the existing graph were available; this focused vocabulary still produced a broad, truncated result. Current source and schema were reviewed directly.
- The active target was confirmed as `rms_db`. Read-only SQL and a direct service call confirmed the fixed service resolves a researcher name for project 60 without returning the name itself.
- `php -l` and `git diff --check` passed for the changed service.

## Resume / handoff
- Last user-confirmed state: The user reported that project 60 appears as “Researcher not recorded.”
- Next pending action: User refreshes the faculty dashboard and confirms the project 60 row shows a researcher name instead of “Researcher not recorded.”
- Blocked/skipped checks and remaining verification gaps: Browser confirmation is pending.
- User-performed data changes and cleanup status: None.
