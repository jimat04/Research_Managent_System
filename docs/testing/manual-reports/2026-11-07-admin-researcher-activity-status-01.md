# Verification: admin researcher activity status filter

- Date/time: 2026-11-07 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The administrator researcher directory filters students by Academic Year associated with their linked research projects. The Activity status filter on an administrator's researcher profile uses Upcoming, Ongoing, Conducted, and Cancelled; status groups include their supported stored aliases and combine with date filters. Also establish whether the researcher profile fetches faculty research profile data, publications, copyright/intellectual outputs, and researcher identity data for faculty records.
- Environment URL: http://localhost:8080/rms/pages/admin/admin-researchers.php
- Roles and disposable records/files: Administrator; no record changes required.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How do the admin researcher directory filters apply to student Academic Year, and how are researcher profile records fetched? Terms matched in graph labels: `admin-researchers.php`, `Student Researcher`, `users`, `Faculty Researcher`, `Publication Records`, `Copyright Applications`, `Research Activities and Participants`.
- Query/lookup and result: `rtk graphify query 'admin-researchers Faculty Researcher Publication Records Copyright Applications Research Activities and Participants' --budget 1700` returned the admin researcher page and related activity/publication/copyright pages (truncated at 46/82 nodes). `rtk graphify query 'admin-researchers.php Student Researcher users' --budget 1300` returned the researcher page, user data node, and student workflow nodes (truncated at 35/45 nodes). `rtk graphify query 'admin-researchers.php academic_years research_projects' --budget 1400` returned the directory and schema nodes, truncated at 39/52 nodes.
- Relevant source locations and verified relationships: `pages/admin/admin-researchers.php:87` loads active faculty/student identity fields by user ID; `:93` loads the faculty research profile by user ID; `:147` loads non-deactivated publications through `publication_contributors`; `:153` loads non-deactivated outputs through `copyright_contributors` for intellectual-output types and `applicant_id` for other copyright types. Directory cards expose publication/output counts and whether a faculty research profile row exists (`:164-180`, `:470-475`). Academic years live in `academic_years` and link through `research_projects.ay_id`; students are associated via project owner, student ID, or project membership. The page now offers years attached to non-deleted projects and filters on a matching project relationship. The page shows a specific empty/unavailable state when the matching optional table is absent or no rows are returned. Schema/migrations define the related profile/contributor tables.
- Missing, inferred, stale, or truncated context: Graphify outputs were truncated and page offsets are stale; current page, `database/schema/rms_db.sql`, and migrations `024_faculty_research_engagement.sql` and `043_publication_ip_contributors.sql` were checked directly. Source review confirms query paths only; live database rows and browser rendering remain unverified. Publications/intellectual outputs require explicit contributor links for their contributor-backed types; legacy free-text names alone are not treated as account links. User clarified that Academic Year means the year linked to student research projects; student accounts themselves have no academic-year field.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant / PHP syntax | Run `php -l pages/admin/admin-researchers.php` | No syntax errors | No syntax errors detected after Academic Year implementation | Passed |
| A2 | Assistant / focused diff | Review the target page diff | Activity status mappings and Academic Year filtering are changed; unrelated activity-type label edit is preserved | Focused diff reviewed; `git diff --check -- pages/admin/admin-researchers.php` succeeded | Passed |
| A3 | Assistant / source review | Trace profile, publication, output, and identity fetch paths and their empty/unavailable states | Prepared queries are correctly scoped to the researcher and the UI distinguishes missing tables from empty records | Source paths and conditions reviewed; runtime/database rows were not accessed | Passed |
| A4 | Assistant / source review | Check Academic Year storage and student-project relationship | Academic Year filter uses project academic-year links and returns linked students only | `academic_years.ay_id` links to `research_projects.ay_id`; page query uses the project owner, student ID, or project member relation | Passed |
| U1 | Administrator / researcher profile | Open profile and select Upcoming, then apply | Activities with `upcoming`, `incoming`, or `scheduled` status appear; selection remains active | User manually confirmed all pending browser checks passed | Passed |
| U2 | Administrator / researcher profile | Select Ongoing and apply | Only ongoing activities appear | User manually confirmed all pending browser checks passed | Passed |
| U3 | Administrator / researcher profile | Select Conducted and apply | Activities with `conducted` or `completed` status appear | User manually confirmed all pending browser checks passed | Passed |
| U4 | Administrator / researcher profile | Select Cancelled and apply | Only cancelled activities appear | User manually confirmed all pending browser checks passed | Passed |
| U5 | Administrator / researcher profile | Combine a date range with a status, then clear filters | Both filters apply together; Clear resets them; All statuses remains available | User manually confirmed all pending browser checks passed | Passed |
| U6 | Administrator / researcher profile | Load with an invalid `activity_status` value; verify access boundary | Invalid value is ignored; non-admin access remains denied | User manually confirmed all pending browser checks passed | Passed |
| U7 | Administrator / faculty researcher profile | From the directory, open a faculty card marked Research profile: Saved | Researcher information and Faculty research profile sections display the profile's stored values or clear Not recorded/Not linked placeholders | User manually confirmed all pending browser checks passed | Passed |
| U8 | Administrator / faculty researcher profile | Open a faculty card with a nonzero Publications count | Publications section shows the associated rows; when the count is at most 100, displayed row count matches the directory count | User manually confirmed all pending browser checks passed | Passed |
| U9 | Administrator / faculty researcher profile | Open a faculty card with a nonzero Outputs count | Copyright and intellectual outputs section shows the associated rows; when the count is at most 100, displayed row count matches the directory count | User manually confirmed all pending browser checks passed | Passed |
| U10 | Administrator / researcher directory | Choose an Academic Year | The selected year remains visible in the control | User reported the check passed | Passed |
| U11 | Administrator / researcher directory | Submit the Academic Year filter | Only students linked to a non-deleted project in that year appear, and the Students role remains selected | User manually confirmed all pending browser checks passed | Passed |
| U12 | Administrator / researcher directory | Click Clear after U11 | Academic Year resets to All academic years and the directory filter is removed | User manually confirmed all pending browser checks passed | Passed |

## Failures, decisions, fixes, and retests
- Initial source finding: the dropdown offered Pending, Approved, and Rejected, while the activity status domain uses upcoming/scheduled, ongoing, conducted/completed, and cancelled values.
- Decision: user approved implementation using the shared activity labels and alias groups. User clarified that the directory filter should be Academic Year, not student year level.
- Fix: the allowlist and dropdown now use Upcoming, Ongoing, Conducted, and Cancelled. The prepared query expands Upcoming to `upcoming`/`incoming`/`scheduled` and Conducted to `conducted`/`completed`; Ongoing and Cancelled use their corresponding values. Date filters and Clear behavior are unchanged.
- Academic Year filter: removed the interim year-level selector. The new Academic Year selector lists labels linked to non-deleted projects; selecting a year filters to students associated with those projects through owner, student ID, or project membership.

## Assistant-run checks
- `rtk graphify query 'admin-researchers Faculty Researcher Publication Records Copyright Applications Research Activities and Participants' --budget 1700` — related page/data nodes returned; output truncated at 46 of 82 nodes and page offsets are stale.
- `rtk graphify query 'admin-researchers.php Student Researcher users' --budget 1300` — directory/user/student nodes returned; output truncated at 35 of 45 nodes and page offsets are stale.
- `rtk graphify query 'admin-researchers.php academic_years research_projects' --budget 1400` — directory/schema nodes returned; output truncated at 39 of 52 nodes and page offsets are stale.
- `rtk proxy php -l pages/admin/admin-researchers.php` — passed after the Academic Year implementation.
- `git diff --check -- pages/admin/admin-researchers.php` — passed.

## Resume / handoff
- Last user-confirmed state: User reports all browser checks passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: User manually confirmed all pending browser checks passed; individual row counts, field values, and screenshots were not provided. Source review and manual browser confirmation establish the reported behavior; no direct database inspection was performed.
- User-performed data changes and cleanup status: None.
