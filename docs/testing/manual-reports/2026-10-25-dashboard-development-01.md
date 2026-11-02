# Research Development dashboard counts

- Date: 2026-10-25 (Asia/Taipei)
- Assistant: Codex, following rms-debug locally (named specialist invocation unavailable).
- Target: http://localhost/rms/pages/admin/admin-dashboard.php
- Role: administrator; shared staff dashboard also affected.
- Session state: awaiting user browser observation.

## Graphify and source context

- Question: what supplies the Research Development dashboard and its linked queues?
- Vocabulary: dashboard. Ran `graphify query dashboard --budget 1500`.
- Result: 14 nodes, including admin-dashboard.php L1, staff-dashboard.php L1, and monitoringDashboardPageDetail L57. Extracted containment edges confirmed against source. Graph does not establish the queue SQL relationship; direct source inspection confirmed it through admin-research-module.php and research-submission-review.php.
- Existing graph line numbers predate these edits. No graph updates performed.

## Findings and authorized fix

- Original dashboard counted legacy project statuses, including returned projects as requiring review and approved projects as awaiting approval.
- Replaced development counts with latest submission versions: submitted titles, submitted chapters, and approved title projects. Reused rms_approval_latest_version_condition().
- Excludes deleted projects, owners, submitters and chapters. Counts distinct projects across permitted development destinations for the section total, avoiding overlap between approved titles and pending chapters.
- Kept existing role/page visibility checks and all routes. No database connections, database edits, migrations, or application interactions were executed by the assistant.
- Preserved pre-existing worktree edits. Only the shared dashboard and this report were edited for this task.

## Checks

| ID | Method | Expected | Observed | Status |
|---|---|---|---|---|
| S1 | PHP syntax | Valid changed PHP | `C:\xampp\php\php.exe -l pages/staff/staff-dashboard.php`: no syntax errors | Passed |
| S2 | Diff whitespace | No whitespace errors | `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/staff/staff-dashboard.php`: exit 0 | Passed |
| S3 | Source review | Correct latest-version filters and permissions | Reviewed SQL, approval predicate, all 15 unchanged module destinations and existing page permission filtering | Passed |
| B1 | Admin dashboard refresh | Current counts and approved-proposals wording | No user observation yet | Pending |
| B2 | Compare three development destinations | Counts correspond to default unfiltered queues | Not run | Pending |
| B3 | Restricted staff | Total includes only permitted destinations | Source reviewed; browser observation pending | Pending |

## Limitations and next step

- Runtime SQL and live counts have not been verified. User operates every application interaction under the manual guide.
- Dashboard counts all eligible records; pending queues currently cap display at 100. Pending queue source also lacks the dashboard's soft-delete exclusions. These existing differences remain outside this bounded fix.
- No navigation or permission rules changed; all 15 destinations reviewed statically, not browser-tested.
- Next: administrator refreshes the dashboard and reports the three Research Development counts and whether Approval says approved proposals.
- No disposable data created; no cleanup required.

## Follow-up label change

- User requested renaming Research Approval to Approved Proposals on the admin dashboard. Updated the shared dashboard card label; staff sees the same label. Route and counts remain unchanged.
- Reused the graph/source relationship above; dependencies and tested behavior are unchanged apart from display text.
- Re-ran PHP syntax and scoped diff whitespace checks: both passed. Browser label confirmation remains Pending.

## Follow-up: Implementation & Outputs counts

- User authorized fixing this section's data. Source comparison confirmed raw status counts omitted title-approved ongoing projects and did not enforce final-manuscript approval for completed research. Outputs omitted registered copyrights.
- Reused rms_ongoing_research_predicate() and rms_completed_research_predicate() with the directory's distinct chapter aggregate and soft-delete exclusions. Completion requires five approved chapters and latest final-manuscript approval.
- Outputs now combine published publications and registered copyright records. Staff copyright scopes match implementation-outputs.php: all copyright records when permitted, otherwise permitted patent/utility-model and innovation/technology-transfer types. Existing page visibility filtering remains intact.
- Section total retains the sum of ongoing projects, completed projects and output records from permitted pages; it is not a distinct-project total because output records are independent records.
- Graph: repeated `graphify query dashboard --budget 1500`, then inspected relevant existing lifecycle and implementation node labels directly. Current source confirms admin module routes, shared implementation redirects, directory lifecycle helpers, and output register branches. Dashboard graph locations are stale after edits; graph does not fully capture SQL relationships. No graph writes.
- Source review: shared dashboard calculation and rendering reviewed against includes/research-workflow.php lifecycle predicates, admin-research-management.php chapter aggregate, and implementation-outputs.php output filters. All 15 module routes remain unchanged; no permission rules changed.
- Passed: `C:\xampp\php\php.exe -l pages/staff/staff-dashboard.php` and scoped `git diff --check` after edits.
- Pending: runtime SQL, dashboard live totals, comparison with the three linked unfiltered pages, and restricted-staff browser confirmation. No database queries or mutations executed by the assistant; no records created.
- Next user step: as admin, refresh the dashboard. Expected: ongoing/completed reflect workflow approvals, and Research Outputs includes published and registered records. Ask for the three displayed counts.
