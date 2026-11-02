# Verification: student single current project

- Date/time: 2026-11-01 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: A student has at most one current project membership, whether lead or co-researcher. Completed and archived projects are hidden from the current-team list while their slots remain occupied. Faculty advise and do not join project teams.
- Environment URL: `http://localhost/rms/` (configured `SITE_URL`)
- Roles and disposable records/files: Student login and project check were user-operated. User reports an authorized admin account and a suitable disposable student/project are available in a separate Chrome window; no credentials or record details shared.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Trace student project membership writers and project history. Selected graph term: `project_members` (exact code label).
- Query/lookup and result: Initial `graphify query "database/e2e/rms_e2e_schema.sql::project_members" --budget 1500` returned 155 nodes and was truncated at 44. The slot helper query returned 113 nodes (41 shown); admin removal returned 134 (42 shown); completion tracing returned 115 (41 shown); current-team query returned 102 (42 shown); and author-snapshot view lookup returned 85 (40 shown). Graph coverage is stale/incomplete for these handlers.
- Relevant source locations and verified relationships: Membership writers are in `pages/researcher/submit-research.php`, `pages/researcher/edit-research.php`, and `pages/admin/admin-faculty-collaboration.php`. Admin removal checks student lead/owner status at `pages/admin/admin-faculty-collaboration.php:124`. Project completion is written in `includes/research-workflow.php` and `pages/staff/staff-milestones.php`. The admin team list filters completed/archived projects; historical authorship snapshots are preserved and displayed by project detail views.
- Missing, inferred, stale, or truncated context: Graph coverage is stale/incomplete for current membership handlers; implementation decisions are verified against current source. The local target and migration state were inspected, but private project details were not reported.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static | Lint changed PHP files and review migration/schema alignment | PHP syntax is valid; migrations and canonical schema are aligned | `php -l` passed for all seven changed PHP files; migration runner verified 058 and recorded 059 as applied | Passed |
| B1 | User browser / student | Open the RMS login page | Login form appears | User confirmed the login form is visible | Passed |
| B1a | User browser / student | Enter authorized credentials in the browser | Fields are filled and ready to submit | User confirmed the fields are filled and ready; no credentials shared | Passed |
| B1b | User browser / student | Submit the login form | Student dashboard appears | User confirmed the student dashboard appeared | Passed |
| B2 | User browser / student | Attempt a second project membership through submission/team-add flow | Clear existing-assignment message; no duplicate membership | User observed `Database error: Duplicate entry '4' for key 'PRIMARY'`. Source catch performs transaction rollback; persisted outcome has not been independently checked. | Failed |
| B2a | User browser / student | Open the canonical research-title proposal page | Proposal form appears | User confirmed the form opened | Passed |
| B2b | User browser / student | Inspect My Research | Current student project list appears | User reported one project listed | Passed |
| B2c | User browser / student | Enter three distinct disposable title options | All three fields contain the temporary titles | User confirmed all three were entered | Passed |
| B2d | User browser / student | Complete the three required title classifications | Valid category and matching research area selected for all title options | User confirmed all three are selected | Passed |
| B2r | User browser / student | Retry Save Draft after the error-message fix | Clear existing-assignment message; no duplicate project is created | User confirmed the planned “already assigned” message appeared | Passed |
| B3 | User browser / admin | Assign the same student to a second project | Clear existing-assignment message; no duplicate membership | Awaiting an authorized admin account and user observation | Pending |
| B3a | User browser / admin | Open Collaboration & Mentorship | Collaboration page and assignment controls appear | User confirmed the controls are visible | Passed |
| B3b | User browser / admin | Select a disposable destination project not assigned to the test student | Destination project is selected | User confirmed the project is selected | Passed |
| B3c | User browser / admin | Select the same test student who already has a project | Test student is selected | User confirmed the student is selected | Passed |
| B3d | User browser / admin | Try assigning the student to the different project | Clear existing-assignment message; no second membership | User confirmed the “already assigned” message appeared | Passed |
| B4a | User browser / admin | Inspect the test student’s current team role | Identify Lead versus Member before choosing a safe removal check | User confirmed the test student is Lead | Passed |
| B4b | User browser / admin | Try removing the disposable test student’s Lead assignment | Removal is refused with the lead/owner message | User confirmed the expected refusal message appeared | Passed |
| B4c | User browser / admin | Inspect the available Member student assignment | Identify whether the candidate is on an active or completed project | User reports the Member student's project is completed and their name still appears in the current-team list | Passed |
| B4d | User browser / admin | Refresh the collaboration page after the list filter change | Completed project/member no longer appears in “Current research teams” | User confirmed the completed project is gone from the list | Passed |
| B4 | User browser / admin or student team owner | Remove the student's current membership, then assign them to another project | Removal succeeds and reassignment succeeds | Blocked: the available non-lead student is on a completed project, and user chose to keep its slot occupied | Blocked |
| B5 | User browser / admin | Refresh Collaboration & Mentorship and inspect team assignment options | Team-member choices show students only | User confirmed only students are listed; adviser assignment remains a separate workflow in source | Passed |

## Failures, decisions, fixes, and retests
- B2 initially blocked the duplicate assignment at the database primary key, but exposed a raw database duplicate error instead of the planned student-facing message. User chose fix now. `rms_claim_student_project_slot()` now catches MySQLi duplicate-key exceptions and returns the expected conflict result. User retested and confirmed the planned “already assigned” message appeared; the initial failure remains recorded above.

## Assistant-run checks
- `rtk proxy php -l` passed for `includes/research-workflow.php`, `pages/researcher/submit-research.php`, `pages/researcher/edit-research.php`, `pages/admin/admin-faculty-collaboration.php`, `pages/shared/view-research.php`, `pages/shared/research-detail.php`, and `scripts/migrate.php` after the final changes.
- `rtk proxy php -l` passed for the admin collaboration page, public research view, student research detail, migration runner, and temporary backup utility before it was removed.
- `rtk proxy php scripts/migrate.php status` showed migration 058 pending before application and applied afterward. The runner reported `APPLIED 058_student_single_current_project.sql` and then `applied executed`.
- Before applying migration 058, the configured target was confirmed as `localhost/rms_db`; a git-ignored backup captured 9 `project_members` rows at `backups/student_project_members_pre_058_20261101_111514.json`.
- Migration 058 added the student assignment table and reconciled retained memberships by the agreed progress order; project/submission history tables were unchanged.
- Before migration 059, the configured target remained `localhost/rms_db`; a git-ignored backup captured 0 faculty membership rows. The runner recorded 059 as verified-existing without executing its SQL because there were no faculty team rows to convert or remove.
- Faculty team assignments are now restricted to students in the admin form and handler. Existing faculty team rows, if present on another database, are copied to `research_author_snapshots` before removal; historical views read those snapshots. Faculty project submission was already restricted to students in the current researcher handler.

## Resume / handoff
- Last user-confirmed state: The completed project no longer appears in “Current research teams”; the student assignment remains occupied by design.
- Next pending action: None for the completed browser checks. Membership removal/reassignment remains blocked pending a non-lead student attached to a non-completed disposable project.
- Blocked/skipped checks and remaining verification gaps: Admin duplicate assignment and faculty team-option checks passed. Removal/reassignment is blocked because the available non-lead student is on a completed project and the slot intentionally remains occupied. Migration 010 remains `needs-verifier` and was not run.
- User-performed data changes and cleanup status: None. Assistant applied migration 058 after backup; no test records were created.

## Course filter follow-up
- Graphify lookup: `graphify query "admin-faculty-collaboration course_filter project_members programs" --budget 1500` returned 58 nodes and was truncated at 43; it did not provide a focused node for the new query logic. The graph is stale/incomplete for this page, so source was checked directly.
- Assistant static check: `rtk proxy php -l pages/admin/admin-faculty-collaboration.php` passed. Reviewed the allowlisted `BSIT`/`BSCS` filter, prepared filter parameters, filter preservation after assignment/removal, the student picker, project picker, and current-team query.
- After B7 exposed the unfiltered project picker, its query was updated to filter by the project creator's active BSIT/BSCS program and display that program beside each project title. Graphify was queried again; it returned 52 nodes and was truncated at 40, so the graph remains stale/incomplete. `php -l` passed after the query change.
- B6 | User browser / admin | Refresh Collaboration & Mentorship and confirm the course/program filter appears | Filter menu appears above team controls | User confirmed the filter is visible | Passed
- B7 | User browser / admin | Select BSIT and apply the filter; inspect all assignment choices and current teams | Student, project, and team lists show BSIT only | User confirmed student choices and teams showed BSIT only, but project picker still showed projects from all courses | Failed; project list filter added
- B7r | User browser / admin | Refresh after the project picker fix and reapply BSIT | Student, project, and team lists show BSIT only | User confirmed all three show BSIT | Passed
- B8 | User browser / admin | Select BSCS and apply the filter | Student, project, and team lists show BSCS only | User confirmed all three show BSCS | Passed
- B9 | User browser / admin | Clear the filter | All eligible programs and their teams return | User confirmed both courses returned across the project dropdown, student menu, and Current research teams | Passed
- Session state: Complete for course-filter verification. The separate membership-removal/reassignment check remains blocked as described above.
