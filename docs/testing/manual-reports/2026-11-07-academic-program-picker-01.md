# Verification: academic program picker

- Date/time: 2026-11-07 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Profile academic program selection uses clear, independently operable checkboxes. For faculty, the primary academic unit is also the Department value, and the profile presents that affiliation without duplicate labels.
- Environment URL: `http://localhost:8080/rms/pages/shared/profile.php`
- Roles and disposable records/files: Admin, research staff, or faculty profile; no records changed during assistant checks.
- Session state: Complete.

## Graphify and source context
- Question and selected graph vocabulary: Trace the profile academic affiliation editor, program selection and Department synchronization; graph labels exposed only `profile.php` for this behavior.
- Query/lookup and result: `rtk proxy graphify query "profile.php" --budget 1000` found the profile page and a profile flash helper, but no affiliation renderer or save helper.
- Relevant source locations and verified relationships: `pages/shared/profile.php` renders the editor, derives the faculty Department display from the primary affiliation summary, and calls `rmsAcademicAffiliationSave()` during the existing profile transaction. `js/academic-affiliation.js` renders checkboxes with the existing `academic_affiliations[index][program_ids][]` request shape. `includes/academic-affiliation.php` validates/saves groups and programs, then synchronizes the faculty `users.department` value from the primary group in the same transaction.
- Missing, inferred, stale, or truncated context: The graph does not expose the affiliation renderer or save-helper path; source review confirmed them. The graph was not rebuilt or updated.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| UI-PROGRAM-1 | Browser / admin, research staff, or faculty / Profile | Inspect Academic Program | Programs appear as clearly labeled checkboxes, not a multi-select listbox | User screenshot showed separate BSCS and BSIT checkboxes | Passed |
| UI-PROGRAM-2 | Browser / admin, research staff, or faculty / Profile | Toggle program checkboxes | Each checkbox can be selected independently, and multiple selections remain selected | User confirmed all checks passed | Passed |
| UI-DEPARTMENT-1 | Browser / faculty / Profile | Reload and inspect Department / Academic Unit | Primary selected group appears as Department; primary group is not duplicated in the academic affiliation summary | User confirmed all checks passed | Passed |
| SAVE-DEPARTMENT-1 | Browser / faculty / Profile | Save profile after confirming the selected primary group | Existing profile transaction updates `users.department` to that primary group; program assignment remains selected | User confirmed all checks passed, including save and persistence | Passed |
| STATIC-1 | PHP syntax check | `php -l pages/shared/profile.php` | No PHP syntax errors | No syntax errors detected | Passed |
| STATIC-2 | JavaScript syntax check | `node --check js/academic-affiliation.js` | No JavaScript syntax errors | Command returned without syntax errors | Passed |
| STATIC-3 | Source review | Compare submitted control names and backend save validation | Existing multi-program request shape and validation remain in use | Checkbox names retain the `academic_affiliations[index][program_ids][]` shape; existing save helper validates catalog membership | Passed |
| STATIC-4 | PHP syntax checks | `php -l pages/shared/profile.php`; `php -l includes/academic-affiliation.php` | No PHP syntax errors | Both files reported no syntax errors | Passed |
| STATIC-5 | Source review | Trace faculty group save and Department mapping | The primary group sets the legacy `users.department` value inside the same save transaction | `rmsAcademicAffiliationSave()` performs the department update after validating/saving the affiliation; profile caller rolls back if the helper fails | Passed |

## Assistant-run checks
- Reviewed current profile markup, the affiliation rendering script, and the existing save helper. No database or profile data was changed by the assistant; the browser save check remains pending.
- The worktree already contains many unrelated modifications; this task changed only `pages/shared/profile.php` and `js/academic-affiliation.js`.

## Resume / handoff
- Last user-confirmed state: User confirmed all profile display and checkbox checks passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported; browser checks were confirmed passed by the user.
- User-performed data changes and cleanup status: None.
