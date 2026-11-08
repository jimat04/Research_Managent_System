# Verification: research-calendar

- Date/time: 2026-11-08 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Research Calendar project rows and academic-year filter omit semester; selecting an academic year includes its projects across semesters.
- Environment URL: `http://localhost:8080/rms/`
- Roles and disposable records/files: Admin or research staff with research calendar view permission; existing records, read-only.
- Session state: Finished

## Graphify and source context

- Question and selected graph vocabulary: How does the research calendar filter projects by academic year? Terms: `academic_years`, `calendar`.
- Query/lookup and result: `graphify query "academic_years calendar" --budget 1500` returned the `academic_years` schema node and generic `pages/shared/calendar.php` nodes, but no direct nodes for the Research Calendar view/helper.
- Relevant source locations and verified relationships: `includes/research-calendar-view.php` renders project metadata in both Agenda and Timeline layouts and submits the academic-year filter. `includes/research-calendar.php` loads distinct academic-year labels, validates the selected label, and filters joined projects by `ay.label` using a prepared parameter, grouping the year's semesters.
- Missing, inferred, stale, or truncated context: Graphify does not cover the Research Calendar helper/view relationship. Findings were confirmed from current source.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| RC-01 | Assistant static check | Run `php -l` on `includes/research-calendar-view.php`. | No PHP syntax errors. | `php -l` reported no syntax errors. | Passed |
| RC-02 | Integrated browser / admin or authorized research staff | Open the Research Calendar in Timeline layout. | Project labels show academic year without semester. | User confirmed all browser checks passed. | Passed |
| RC-03 | Integrated browser / admin or authorized research staff | Switch to Agenda layout. | Project labels show academic year without semester. | User confirmed all browser checks passed. | Passed |
| RC-04 | Integrated browser / admin or authorized research staff | Inspect the Academic year filter options, then apply one year filter. | Options show each academic year once without semester; the selected year filters projects across its semesters. | User confirmed all browser checks passed. | Passed |

## Failures, decisions, fixes, and retests

- None observed.

## Assistant-run checks

- `rtk php -l includes/research-calendar-view.php`: passed, no syntax errors.
- `rtk git diff --check -- includes/research-calendar-view.php`: passed with no whitespace errors. The view is an existing untracked file in the worktree, so Git did not emit a tracked-file diff for it.
- After the filter update: `rtk php -l includes/research-calendar.php` and `rtk php -l includes/research-calendar-view.php` passed; `rtk git diff --check -- includes/research-calendar.php includes/research-calendar-view.php` returned no whitespace errors.

## Resume / handoff

- Last user-confirmed state: User confirmed all browser checks passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None; no database changes were made.
- User-performed data changes and cleanup status: None.

## Completion

- Browser-observed outcomes: User confirmed Timeline, Agenda, and academic-year filter checks all passed.
- Assistant-run checks: PHP syntax checks passed for both changed PHP files; `git diff --check` reported no whitespace errors.
- Database effects: None; the calendar reads existing records.
- Remaining gaps: None reported.
