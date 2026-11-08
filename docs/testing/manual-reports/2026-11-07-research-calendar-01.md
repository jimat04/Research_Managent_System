# Verification: research-calendar

- Date/time: 2026-11-07 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Authorized admin/research staff can open the Research Calendar tab, filter projects with approved titles, and switch between monthly timeline and agenda views showing latest workflow milestones.
- Environment URL: `http://localhost:8080/rms/`
- Roles and disposable records/files: Admin or research staff with `research_approval` view permission; use existing records for read-only viewing.
- Session state: Finished

## Graphify and source context

- Question and selected graph vocabulary: How does the existing review page relate to research projects, submission versions/reviews, and academic years? Terms: `research-submission-review.php`, `research_projects`, `research_submission_versions`, `research_submission_reviews`, `academic_years`.
- Query/lookup and result: Read-only inspection of existing `graphify-out/graph.json`; matching schema nodes were present for `research_projects` (schema L400), `research_submission_versions` (L562), and `research_submission_reviews` (L579). A second lookup found `hasModulePermission()` (`includes/auth.php` L504). The visual retest lookup reconfirmed submission/review nodes; there are no graph nodes for the new calendar helper or useful route-to-helper edges.
- Relevant source locations and verified relationships: `pages/shared/research-submission-review.php` checks admin/research_staff roles and `research_development` view permission before the page, and requires the `research_approval` module permission for staff routes. `database/schema/rms_db.sql` links projects to `academic_years` and stores submission versions and review timestamps. Current source is authoritative for the implementation.
- Missing, inferred, stale, or truncated context: Graph coverage is incomplete for the current approval-list helper and academic-year usage. No graph build or cache-writing query was run during verification.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| RC-01 | Assistant static check | Run `php -l` on each changed PHP file. | All changed PHP files pass syntax validation. | Passed on `pages/shared/research-submission-review.php`, `includes/research-calendar.php`, and `includes/research-calendar-view.php`, including after the visual refinement. | Passed |
| RC-02 | Integrated browser / admin or authorized research staff | Open the Research Calendar view directly. | The calendar shows its filters and projects with approved titles. | User confirmed the calendar loaded with projects. | Passed |
| RC-03 | Integrated browser / admin or authorized research staff | Switch to Agenda. | The selected layout and filters remain in the URL and the project milestones are visible. | User confirmed agenda milestones and selected filters looked correct. | Passed |
| RC-04 | Source review | Inspect the calendar query and route permission gate. | Filters use prepared statements, and route access remains within existing research approval authorization. | Confirmed in source: input filters are bound parameters; staff calendar link is shown only with approval-view permission; the existing page calls `requireModulePermission()` for the route. | Passed |
| RC-05 | Integrated browser / admin or authorized research staff | Select BSIT in the Course filter. | BSIT is shown as selected. | User confirmed BSIT was selected. | Passed |
| RC-06 | Integrated browser / admin or authorized research staff | Apply the selected course filter. | Results reflect the course, the filter remains selected, and the URL retains the filter. | User confirmed results and URL are correct. | Passed |
| RC-07 | Integrated browser / student | Open the calendar route directly while logged in as a student. | The route denies access. | Not exercised in the browser; route permission gate was confirmed in source review. | Skipped |
| RC-08 | Integrated browser / admin or authorized research staff | Refresh the calendar after the visual refinement. | Stage dates remain readable, status/progress indicators appear, and no text is clipped in the timeline. | User confirmed the updated timeline is readable and unclipped. | Passed |

## Failures, decisions, fixes, and retests

- Visual refinement: the initial timeline screenshot showed clipped date labels on short bars. The date captions were moved beside their milestone rows and progress/status context was added; the user confirmed the retest was readable and unclipped.

## Completion

- Browser-observed outcomes: The user confirmed projects load, Agenda and filters display correctly, the BSIT filter updates results and URL, and the refined timeline is readable and unclipped.
- Assistant-run checks: PHP syntax checks passed for all changed PHP files after the visual refinement. `git diff --check` passed for the tracked page and stylesheet changes (Git reported the existing LF-to-CRLF normalization warning for the stylesheet).
- Database effects: None; calendar reads existing project, academic-year, submission, and review records.
- Remaining gaps: Student-role direct-route denial was not browser-tested; source review confirms the existing permission gate remains in effect.
