# Verification: course-specific research catalog

- Date/time: 2026-10-29 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: BSCS students see Computer Science categories and mapped areas; BSIT students see Information Technology categories and mapped areas; faculty keep the existing catalog; saved historical classifications remain available on edit.
- Environment URL: http://localhost/rms/ (configured fallback; runtime availability not confirmed)
- Database: local development `rms_db`; migration 044 applied through the runner after a focused pre-migration backup.
- Roles and disposable records/files: student and faculty; no records created.
- Session state: in progress; implementation checks pending.

## Graphify and source context

- Question: how do the researcher proposal forms load and validate the category and area catalog?
- Selected graph vocabulary: `researcher/submit-research.php`, `researcher/edit-research.php`, `research_categories`, `research-workflow.php`.
- Query: `graphify query "researcher/submit-research.php researcher/edit-research.php research_categories" --budget 1500` (repeated for the picker failure).
- Result: 70 nodes found, 41 shown; output truncated. Graph identifies the submit/edit pages and category table; it includes stale references to removed E2E tests and does not identify the current picker behavior.
- Current-source confirmation: both researcher forms call shared validators in `includes/research-workflow.php`; category-area filtering uses `js/research-area-picker.js`; current program and catalog paths were confirmed directly in source.
- Relevant source locations: `includes/research-workflow.php:100-267` resolves student programs, loads scoped categories, and validates course/category/area combinations; `pages/researcher/submit-research.php:14-17,298-372,565-569,641,660` applies create rules and styles the picker; `pages/researcher/edit-research.php:23-25,257-327,526-531` applies them while preserving saved classifications; `js/research-area-picker.js:3-62` filters areas by selected category; `scripts/migrate.php:454-525` verifies migration 044.
- Missing/stale context: existing graph omits current area catalog helper relationships and includes deleted `tests/e2e-browser` nodes. No graph rebuild performed.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| UI00 | User / browser / RMS login | Open the RMS login page | Login form appears | User confirmed the login page appears | Passed |
| UI00b | User / browser / RMS login | Sign in with the authorized BSCS student account | Student dashboard appears | User confirmed the student dashboard appeared | Passed |
| UI01 | User / BSCS student / researcher submission | Open Submit Research from the student navigation | Research title submission form appears | User confirmed the research title submission form appeared | Passed |
| UI02 | User / BSCS student / Submit Research | Open the Research Category selector and inspect its options | Only Current Computer Science Topics, Foundations of Computer Science, and Human-Computer Interaction appear | User confirmed these are the only category options | Passed |
| UI03 | User / BSCS student / Submit Research | Select Current Computer Science Topics | Only that category's related areas appear; unrelated areas are hidden | User reports related CS areas appear, but unrelated category areas remain visible and unclickable | Failed |
| UI03R1 | User / BSCS student / Submit Research | Refresh the form after the CSS fix | Updated submission form loads | Pending | Pending |
| UI03R2 | User / BSCS student / Submit Research | Select Current Computer Science Topics again | Only related CS areas are visible; unrelated areas are hidden | Pending | Pending |
| DB01 | Assistant / migration target | Read current database name and migration ledger | Local development `rms_db`; do not run unrelated pending or needs-verifier migrations | `rms_db`; 044 was pending; unrelated 010 remained `needs-verifier` and 043 remained pending | Passed |
| DB02 | Assistant / migration | Back up affected catalog tables, apply migration 044 through runner, verify catalog and ledger | 7 course categories, 38 distinct areas, 40 category-area mappings, 7 program links; old rows and project associations unchanged | Backup: 51,123 bytes; pre-migration counts: categories 5, areas 53, category-area links 165, scope 20, project-area links 0, ledger 34. Runner applied 044; verifier passed. Post counts: categories 12, areas 91, links 205, scope 58, project-area links 0. Second runner call skipped 044. | Passed |
| ST01 | Assistant / PHP and JS static checks | Lint changed PHP, check picker JS and migration consistency | Syntax clean; migration verifier and canonical schema match migration intent | `php -l` passed on all four changed PHP files; `node --check` passed; `git diff --check` passed (CRLF normalization warnings only). Migration runner's registered verifier passed on apply. | Passed |
| SEC01 | Assistant / source and read-only helper checks | Review submit/edit ownership, CSRF, lock, course/category/area validation | Forged cross-program selections rejected; historical saved selections retain only saved values | Read-only PHP matrix passed for the 7 categories and their expected area counts; cross-program category checks rejected; faculty catalog returned the 5 legacy categories; saved legacy area retention passed and adding an unrecorded area was rejected. Existing ownership, CSRF and editable-status gates remain in both pages. | Passed |
| UI01 | User / student / submit research | Inspect BSCS category and area options | Only CS categories and corresponding areas appear | Pending | Pending |
| UI02 | User / student / submit research | Inspect BSIT category and area options | Only IT categories and corresponding areas appear | Pending | Pending |
| UI03 | User / faculty / submit research | Inspect category and area options | Existing faculty catalog remains available; new course-only categories are absent | Pending | Pending |
| UI04 | User / researcher / old editable project | Open and save without changing the saved historical category/areas | Existing historical classification is displayed and preserved | Pending; local `research_project_areas` currently has no saved rows, so a suitable user-owned historical record is needed to observe area retention in the browser | Pending |

## Failures, decisions, fixes, and retests

- UI03 failed: as a BSCS student on Submit Research with Current Computer Science Topics selected, the user sees related CS areas and unrelated areas that are visible but unclickable. Expected unrelated areas to be hidden. User chose fix now.
- Source review found `.submission-page .card-body label { display: block !important; }` overrode the research-area picker's hidden/display state. The rule now excludes `.research-area-option`, allowing category-filtered `display:none` and the intended flex layout to apply. Retest pending.

## Assistant-run checks

- `php -l includes/research-workflow.php`, `php -l pages/researcher/submit-research.php`, `php -l pages/researcher/edit-research.php`, and `php -l scripts/migrate.php`: passed.
- `node --check js/research-area-picker.js`: passed.
- Read-only helper matrix against `rms_db`: seven mapped categories and 40 area links verified; 38 distinct scoped areas; exact course catalogs, cross-program rejection, faculty legacy catalog, and saved-legacy retention behavior passed.
- `php scripts/migrate.php apply 044_course_specific_research_categories.sql`: `APPLIED`; repeat invocation: `SKIPPED`.
- `git diff --check` passed for touched tracked files; Git emitted CRLF normalization warnings for existing Windows line endings.
- Graphify recheck for the picker issue found current submit/edit page and category nodes but no picker/CSS relationship; result was truncated and retained stale deleted E2E nodes. The current CSS override and JavaScript filtering behavior were confirmed directly in source.
- `php -l pages/researcher/submit-research.php` after the picker CSS fix: passed.
- A temporary backup helper emitted a PHP session warning because Windows PowerShell wrote a UTF-8 BOM before the PHP opening tag. The export completed and reported the verified `rms_db` target; the temporary helper was removed. No database state was changed by that helper.
- Backup: ignored `backups/research-course-catalog-20261029.sql.backup`; count/hash baseline: `backups/research-course-catalog-20261029-baseline.json`. The backup includes categories, areas, mappings, scope, project-area associations, and migration ledger; no user credentials or project abstracts were exported.

## Per-title category and area classifications

- User clarified that the explanatory text should identify the student's course and that each of the three proposed titles needs exactly one category and one related research area. Faculty retain their shared category and area picker.
- Graphify question: how does title-version review connect to the current project record? Selected actual graph terms: `rms_review_submission`, `research_submission_versions`, `researcher/submit-research.php`. Query: `graphify query "title_option_1 category_id research_project_areas rms_review_submission research-submission-review" --budget 1500`. It returned 97 nodes and truncated at 39; current-source confirmation found create/edit snapshot calls in `pages/researcher/submit-research.php` and `pages/researcher/edit-research.php`, approval handling in `includes/research-workflow.php`, and review display in `pages/shared/research-submission-review.php`. The graph included stale E2E nodes and no explicit per-title classification relationship; source was used as authority.
- Implementation: students now submit/edit three category-area pairs, with exactly one mapped area per title. The picker filters each dropdown independently. Server-side validation checks all three pairs against the account program. Faculty retain the existing shared catalog. Per-title pairs persist in `research_project_title_classifications`, are included in title-version snapshots, appear in review choices and project views, and the approved title's category is synchronized to the project. Existing project-area associations are preserved during student edits and approval; the legacy project-level fields remain for compatibility.
- Database: confirmed local target `rms_db`. Additive migration `045_title_option_research_classifications.sql` creates only the new table; it does not rewrite existing projects or links, so no existing data backup was needed for this empty-table addition. Applied with `php scripts/migrate.php apply 045_title_option_research_classifications.sql`; runner reported `APPLIED`. Subsequent status reports `applied / executed`. Fresh-install schema and registered migration verifier were updated.
- Assistant static checks: `php -l` passed for `includes/research-workflow.php`, both researcher pages, review page, detail page, `my-research.php`, and `scripts/migrate.php`; `node --check js/research-area-picker.js` passed; targeted `git diff --check` passed (Git emitted only line-ending normalization warnings).
- Browser checks for the new per-title controls and category-filtered dropdowns passed. Edit-form reload, faculty unchanged behavior, and approval/classification alignment remain pending. The user created one draft through the application for persistence verification.

| UI05 | User / BSCS student / Submit Research | Refresh the form to load the per-title controls | Three category/area pairs appear, one for each title option | User confirmed the category and area per title are visible | Passed |
| UI06 | User / BSCS student / Submit Research | Select a category in each title pair and inspect its area choices | Each of the three area dropdowns shows only areas mapped to its own selected category | User confirmed filtering works through Title option 3 | Passed |
| UI07 | User / BSCS student / Submit Research | Save three distinct title options with three category/area pairs as a draft | Draft saves and all classifications appear after redirect | Initial duplicate-title save was rejected as expected. After correcting titles, user screenshot of My Research shows Draft plus all three title/category/area pairs listed | Passed |
| UI08 | User / BSCS student / My Research | Open Edit on the newly saved draft | All three title/category/area pairs are preselected from storage | Pending | Pending |

- UI08 observation: the user confirmed the saved category/area pairs appeared in Edit, but noticed an Abstract field. Source review found new student title submissions have no abstract input, while edit previously displayed and required one. Student edit now hides that field and preserves any already-saved abstract without requiring or overwriting it; faculty edit behavior is unchanged. `php -l pages/researcher/edit-research.php` and targeted `git diff --check` passed. Retest after refresh is pending.
- Abstract-field graph lookup: selected `researcher/edit-research.php`; command `graphify query "researcher/edit-research.php" --budget 1000` returned 61 nodes and truncated at 28, with stale E2E references. It identified the current edit and submit pages; direct source confirmed their differing abstract behavior.

| UI09 | User / BSCS student / Edit draft | Refresh after student abstract field change | Abstract field is absent; all three saved pairs remain selected | User confirmed both results after refresh | Passed |
| UI10 | User / authorized admin / Research Management | Permanently delete only the session-created test draft | Exact draft and related project data are removed through the supported UI | User reports: “Project deletion complete. Project documents and review history have been removed.” | Passed |
| UI11 | User / admin / RMS | Sign out of the BSCS account and sign in with the authorized admin account | Admin session is active | User reports they logged in as admin | Passed |
| UI12 | User / admin / Research Management | Search for the test project's option 1 title and verify its proponent | Exact session-created draft appears | User confirmed the matching title and student proponent are listed | Passed |
| UI13 | User / BSIT student / Submit, edit, and admin cleanup | Verify course-specific category/area selections from draft through deletion | BSIT choices work through the draft, reopen/edit, and cleanup flow | User reports the BSIT draft-to-deletion flow worked | Passed |

## Resume / handoff

- Last user-confirmed state: My Research displays the newly saved draft with three title options and their corresponding category/area values.
- Last user-confirmed state: the exact draft was deleted through Admin Research Management; the application confirmed its project documents and review history were removed.
- Next pending action: optional browser verification of faculty's unchanged catalog requires an authorized faculty account. An editable historical project with legacy area links and title-approval classification alignment were not browser tested. Static source and read-only catalog checks cover several of these, but browser outcomes remain unconfirmed.
- User confirmed an authorized admin account is available for the approved test-draft cleanup. Logout/login and deletion remain user-operated browser actions; no credentials requested.
- User agreed to create and remove a temporary draft. The project was created via the normal student flow and is identifiable from this test: Title option 1 `Filipino Language Campus FAQ Chatbot`; option 2 `Graph Search Algorithms for Campus Route Planning`; option 3 `Usability Evaluation of a Student Research Portal`; date Oct 29, 2026; lead shown as the current BSCS user. Titles do not have the requested TEMP prefix. Do not delete a project unless the exact session-created record is confirmed in the admin UI.
- Corrected the draft-entry step after source inspection: `pages/researcher/submit-research.php` has no abstract input or abstract-required check for title proposals. The proposed temporary abstract entry was withdrawn; it is not needed for this test.
- UI07 first caught a duplicate: options 1 and 2 both read `Filipino Language Campus FAQ Chatbot`. Server validation correctly rejected it. The user corrected option 2; the subsequent My Research screenshot confirms the saved draft and all three classifications.
- Cleanup route review: Graphify query `graphify query "rms_admin_delete_project my-research.php admin-research-management.php research_projects" --budget 1500` returned 140 nodes (43 shown; truncated) with stale E2E references. Current source confirms project deletion is admin-only and permanent (`pages/admin/admin-research-management.php`, `includes/admin-project-actions.php`). User confirmed the application completed removal of the test project, documents, and review history.
- Blocked/skipped checks and remaining gaps: faculty browser outcome not confirmed; approval alignment browser check not performed because the workflow requires a title submission for review; an editable historical project with saved legacy area links is not present in the local database. Per-title validation and category mapping were statically checked.
- User-performed data changes and cleanup status: one draft created through the student application flow, its three classifications were verified after reopen, then the exact test draft was deleted by an authorized admin through Research Management. Application confirmed removal of project documents and review history.
- Assistant-applied database change: migration 044 added 7 categories, 38 areas, 40 category-area mappings and 7 program mappings; existing five categories, 53 prior areas, 165 prior category-area mappings and zero project-area links were preserved by the additive migration.
