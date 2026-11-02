# Verification: faculty research access

- Date/time: 2026-10-29 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Faculty cannot submit or browse general research projects; assigned advisers retain read-only access to advisee projects. Student workflow remains available.
- Environment URL: Not confirmed
- Roles and disposable records/files: Faculty, student, and adviser/non-adviser accounts; no records created by the assistant.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Trace shared project/document routes used by faculty research submissions and adviser visibility. Selected `research-detail.php`, `download-research-document.php`, `my-research`, and `faculty-advising` terms.
- Query/lookup and result: `graphify query "research-detail.php" --budget 1500` and `graphify query "research-detail.php download-research-document.php" --budget 1500`; both returned broad BFS results truncated at 41/70 nodes. Existing map available; no graph writes or rebuilds.
- Relevant source locations and verified relationships: `pages/researcher/{submit-research,edit-research,submit-chapter,submit-final-research,my-research}.php` are the shared student/faculty entrypoints; `pages/shared/research-detail.php` checks project access; `pages/shared/download-research-document.php` authorizes project documents; `pages/faculty/{faculty-dashboard,faculty-profile,faculty-advising}.php` expose project lists; `pages/shared/{research-archive,view-research}.php` expose archive routes. Current source confirms these relationships; faculty detail access now checks `project_advisers` only.
- Missing, inferred, stale, or truncated context: Graph output was truncated and contained unrelated E2E nodes; conclusions rely on the listed current source. No browser observation yet.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / changed PHP pages | Run `php -l` on each changed PHP page | Every changed PHP file parses | `php -l` passed for all 13 affected PHP files | Passed |
| S2 | Assistant / affected routes and permissions | Review role guards, adviser checks, document preview gates, and faculty links | Faculty excluded from submit/list routes; adviser-only view remains; student/admin/staff paths remain | All five researcher routes use `requireRole('student')`; faculty shell no longer links to workflow routes; shared detail and archive detail require `project_advisers` for faculty; project document preview allows faculty only through adviser assignment; general authenticated archive returns 403 for faculty. Student ownership/membership logic and admin/staff review branches remain in source. | Passed |
| S3 | Assistant / scoped diff | Run `git diff --check` for changed files | No whitespace errors in scoped edits | `git diff --check` on all scoped PHP/CSS files produced no whitespace errors. Git emitted existing line-ending conversion warnings for several modified files. | Passed |
| B1 | User / faculty / faculty dashboard | Inspect navigation and dashboard project panel | No My Research/Research Proposals links; project list shows assigned advisees and links to Advising | Pending | Pending |
| B2 | User / faculty / canonical researcher routes | Open a submission route and My Research directly | Faculty receives access denied; no workflow form or project list appears | Pending | Pending |
| B3 | User / faculty / project detail and document | Open an assigned advisee project, then a project where the faculty account is only owner/member or is unassigned | Adviser can view assigned project; owner/member/non-assigned access is denied; project document preview follows adviser access | Pending | Pending |
| B4 | User / student / researcher workflow | Open My Research and title-submission page | Student workflow remains available | Pending | Pending |

## Failures, decisions, fixes, and retests
- None recorded.

## Assistant-run checks
- `php -l` across `includes/faculty-shell.php`, the faculty dashboard/profile/advising pages, five researcher pages, and shared research detail/document/archive pages: all passed.
- Scoped `git diff --check`: no whitespace errors; Git reported line-ending conversion warnings only.
- Source authorization review confirmed the intended faculty adviser boundary; no database or application records were changed by the assistant.

## Resume / handoff
- Last user-confirmed state: No browser check performed.
- Next pending action: User opens the faculty dashboard in the integrated browser and confirms the workflow links are absent while assigned advisee tracking remains.
- Blocked/skipped checks and remaining verification gaps: Browser observations are pending; an assigned and a non-assigned adviser project are needed for B3.
- User-performed data changes and cleanup status: None.
