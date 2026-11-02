# Verification: admin-student-researcher-information

- Date/time: 2026-10-27 (Asia/Taipei)
- Scope: Student details in `pages/admin/admin-researchers.php?role=student`.
- Expected behavior: Student directory cards and profiles show student ID and year level, omit Department, hide faculty-only rank/specialization details, and show student metrics for projects, submitted chapters, and approved chapters.

## Graphify lookup

- Question: Which query and render blocks supply the admin researcher directory and student details?
- Selected graph terms: `admin-researchers.php`, `adminResearchersRun()`, `student_id`, `year_level`.
- Command: `graphify query "admin researchers student_id year_level researcher" --budget 1500`.
- Result: Graph identified `admin-researchers.php` (source `pages/admin/admin-researchers.php`, L1), `adminResearchersRun()` (L24), and schema node `users`. Output was truncated by the 1500-token budget; narrowed source confirmation below resolved the relevant relationships.
- Source confirmation: The profile query selects `student_id` and `year_level` at L114. The directory query now selects those user fields and its student-filter search includes both. Student card/profile markup renders these fields; faculty cards retain specialization/rank.
- Follow-up lookup after the profile render change: `graphify query "admin-researchers.php adminResearchersRun" --budget 1000` again found the page and query helper, but output was truncated (29 of 65 nodes).
- Follow-up data lookup: `graphify query "users project_members research_projects chapters status" --budget 1500` found the users/project/chapter schema and workflow nodes; output was truncated (41 of 45 nodes). Current source confirms `project_members.user_id`, chapter `submitted_at`/`status` fields and project/chapter soft-delete filters.
- Limitations: Graph results were truncated and did not establish every query/render relationship. Direct source and schema are authoritative; `users.student_id` is varchar and `users.year_level` is an enum in `database/schema/rms_db.sql`.

## Assistant checks

| Check | Status | Result |
|---|---|---|
| PHP syntax: `php -l pages/admin/admin-researchers.php` (after metrics change) | Passed | No syntax errors. |
| Browser: open the student researcher directory and inspect student details | Pending | Awaiting user observation. |

## User browser check

- Role: Admin.
- Page: `admin-researchers.php?role=student`.
- Action: Open or refresh the Student researcher directory.
- Expected: Student cards and detail profiles display Student ID and Year level, omit Department, do not show faculty Rank or Specialization fields, and student cards show project, submitted chapter, and approved chapter counts.
- Confirm: Do the cards show those student details as expected, or do you see a different result?
- Observation: Pending.

## Database effects

- No database changes or application data mutations were made.






## Accuracy investigation for researcher 233

- User-provided page text was compared with the configured local target. Target identity: `rms_db` on a local host; E2E mode was off. All checks below were read-only.
- Student record: role, name, email, student ID, program, active status, and year level match the `users` row.
- Linked projects: the page's project query returns one non-deleted project, ID 71, “Development of 3R Evaluation,” status `completed`, updated 2026-10-25. The student is recorded in `project_members`; the project creator is a different user. The displayed association is valid.
- Completion status: project 71 has five approved chapter records and an approved final-manuscript version 2 (version 1 was returned). `completed` is consistent with the workflow rule that final-manuscript approval completes research.
- Metrics for the student directory: the page's current count queries return 1 project, 5 submitted chapters, and 5 approved chapters.
- Empty sections: the page's exact activity join returns 0; no activity participant name match was found. Publications and copyright applications are both 0 by direct user association. Additional checks by project 71, and by the student's denormalized name fields, also found no publication or copyright records. The empty messages are accurate for current data and supported links.
- Graphify: `graphify query "admin-researchers.php research_projects activity_participants publications copyright_applications" --budget 1500` located the admin page, project, activities, publications and copyright graph nodes; 42 of 124 nodes were shown. Source and current database checks confirmed the relevant relationships; graph traversal was truncated.
- Assistant result: Passed. Read-only queries matched the user-provided detail-page values and all empty states.
- Limitation: the first CLI bootstrap emitted a session-file permission warning in the XAMPP temp directory, but the local database connection and SELECT results succeeded. No application records or database values were changed.
