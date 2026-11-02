# Verification: academic year

- Date: 2026-10-25 (Asia/Taipei)
- Assistant: Codex
- Target: shared student/faculty title submission and edit forms show Academic Year only, one option per year, with a current-year default that advances annually. Follow-up scope includes public and signed-in research archive year filtering and card labels.
- Environment URL: configured fallback http://localhost/rms/; browser availability not confirmed.
- Roles and disposable records: student/faculty; none created.
- Session state: implementation and static checks complete; user confirmed student and public archive views. Detailed filter/persistence checks were not separately observed.

## Graphify and source context
- Question: where do year/semester form options and saved project IDs originate?
- Selected actual vocabulary: `academic_years`, `research`.
- Lookup: `graphify query "academic_years research" --budget 1500` returned 11 nodes and submit-page helper relationships, without truncation.
- Graph locations: `pages/researcher/submit-research.php:L1`, `crc_validate_candidates` at L53; `academic_years` has no source location/adjacent relationship in the result.
- Current source confirms both shared pages read active academic-year semester rows, validate IDs, and save `research_projects.ay_id` inside existing project transactions. Canonical schema defines a required semester enum and preserved year IDs. Student compatibility routes delegate to researcher pages.
- Coverage limits: graph lines predate existing worktree changes; new academic-year helpers are absent. Current source is authoritative. No graph updates/installations performed.
- After edits, a direct read of graph JSON reconfirmed both canonical page nodes at L1, seven adjacent edit-page edges, and no coverage for `includes/academic-years.php`. Current source confirms the new helper is required by both pages, validates labels and resolves IDs within the existing save transactions.
- Archive follow-up vocabulary: `archive`, `academic_years`. `graphify query "archive academic_years" --budget 1500` returned seven nodes, primarily the signed-in archive and its helpers (`archiveBind` L44, `archiveColumns` L22, `archiveEscape` L39). Direct JSON lookup after edits confirmed `public/research-archive.php:L1` and `pages/shared/research-archive.php:L1` and adjacent helper edges. Current student-shell source routes Research Archive to the signed-in page. Graph does not cover the new year helper; current archive source confirms both callers. Focused output was not truncated; an earlier combined source/vocabulary read was truncated and the affected ranges were reread separately.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / PHP | Lint changed PHP | No syntax errors | `php -l` passed for helper and both pages | Passed |
| S2 | Assistant / pure helper | Check December/January boundaries, leap day, future dates, valid and invalid labels | Year range advances January 1; invalid labels rejected | Five date cases, two valid labels and six invalid payloads passed in database-free PHP CLI checks | Passed |
| S3 | Assistant / source | Review validation, CSRF, ownership, locks, transaction and year grouping | Only available years accepted; existing IDs retained; no GET writes | Reviewed helper and both callers: prepared queries, keyed year grouping, saved-ID preference, CSRF branches and project-lock guard before resolution | Passed |
| S4 | Assistant / diff | Review affected changes and whitespace | Focused changes; no whitespace errors | Targeted diff/source review and `git diff --check` completed; existing unrelated work preserved | Passed |
| B1 | Student / submit-research.php | Open new submission form | Academic Year label; current range selected; no semester text or duplicate years | Awaiting user | Pending |
| B2 | Student or faculty / normal save/edit flow | Save a disposable draft and reopen | Selected year persists, including an older saved year | Not exercised | Pending |
| B3 | Faculty / submit-research.php | Open shared submission form | Same year-only options/default | Not exercised | Pending |
| B4 | Owner/non-owner / edit-research.php | Check locked project and authorized direct-route boundary using disposable records | Existing edit/ownership restrictions hold | Source review only; browser not exercised | Pending |
| A1 | Assistant / both archive sources and PHP | Review and lint archive changes | One year option across semesters; year-wide prepared filter; dynamic current year; no semester labels; public-status/deleted/access filters preserved | All three affected PHP files linted successfully; source assertions and diff whitespace check passed | Passed |
| A2 | Student / pages/shared/research-archive.php | Open archive from sidebar | Academic Year dropdown and card labels; current range available; All academic years default retained | User confirmed: "it's all good, in student and in public" following the archive display check | Passed |
| A3 | Student or public visitor / both archive filters | Select a year, search, refresh and reset; exercise legacy year link | Matching completed/archived records from every semester; selection persists; reset restores all; empty state for no matches | Not exercised | Pending |
| A4 | Public visitor / public/research-archive.php | Open archive | Year-only options and cards; no semester text | User confirmed: "it's all good, in student and in public" | Passed |

## Failures, decisions, fixes, and retests
- No observed application failures.
- User confirmed both student and public archive views after implementation. This confirms the requested display check; it does not separately establish filter results, persistence, legacy-link behavior or earlier submission/edit checks.
- User requested applying academic-year-only behavior to the research archive. Source confirms duplicate semester options, per-semester filtering, semester text on cards, and an upper year bound derived from manually active records. Follow-up will reuse the date helper and retain public catalog visibility restrictions.
- The sidebar also exposes a signed-in archive whose old Year field filtered `YEAR(updated_at)`. Updated both archive views. Shared archive-year helper returns the current date-derived range and distinct real archived/completed year labels up to that range, with soft-delete guards. Current range is an option even without matching records; normal empty state is retained and no sample records are created. All academic years remains the default.
- Public `ay_id` bookmarks are resolved to their year label and filter across semester IDs; unknown legacy IDs retain the original no-match filter. Signed-in legacy `year=YYYY` links map to the academic year beginning in YYYY. Both forms now submit `academic_year`.
- Signed-in cards use saved academic-year labels and a missing-year fallback. Existing authentication, staff permission check, record limits, publication and title filters, links and document protections are preserved. The dropdown shares existing input/focus styles and has a wider grid column; mobile stacking is retained.
- Assumption: current calendar year starts the academic-year range; automatic rollover is January 1 in the application's configured timezone. Existing saved years remain unchanged.
- Legacy semester column stays for compatibility. Newly needed year rows use its existing `1st` convention internally; researcher forms display year labels only. No schema migration or direct database command is required.

## Assistant-run checks
- Graph query completed as described above.
- `php -l includes/academic-years.php`: no syntax errors.
- `php -l pages/researcher/submit-research.php`: no syntax errors.
- `php -l pages/researcher/edit-research.php`: no syntax errors.
- PHP CLI here-string piped to `php`, requiring only `includes/academic-years.php`: checked 2026-10-25, 2026-12-31 23:59:59, 2027-01-01 00:00:00, 2028-02-29 and 2040-01-01, using Asia/Manila timezone. All five expected year ranges passed. Accepted current unsaved and historical available labels; rejected null, empty string, array, integer, unavailable year and semester-suffixed label. Exit code 0; no database loaded.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/researcher/submit-research.php pages/researcher/edit-research.php`: exit code 0; only line-ending conversion notices. Repository ownership exception was command-scoped; no global Git configuration changed.
- No browser interactions, database writes, migrations, sessions, or application requests performed by the assistant.
- Archive follow-up: `php -l includes/academic-years.php`, `php -l public/research-archive.php`, `php -l pages/shared/research-archive.php` all passed. The shared page was linted again after its select styling adjustment and passed.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- public/research-archive.php pages/shared/research-archive.php includes/academic-years.php`: exit code 0, line-ending notice only. Targeted source assertions via a Python here-string verified shared dynamic options, DISTINCT labels/deduplication, label-bound prepared filters, removed semester rendering, no update-year filter, soft-delete/status/access guards, absence of writes in the archive helper, and dropdown focus styles. Source checks do not establish runtime SQL results or rendered behavior.
- Existing calendar-boundary results above remain applicable: the date helper is unchanged. Archive helper itself is read-only; no database/schema changes were performed.

## Resume / handoff
- Last user-confirmed state: student and public archive views are working as expected, per the user's confirmation.
- Next action: none required for the confirmed archive display change. Optional continued verification starts with selecting a year in the archive filter; filter selection and search submission should remain separate user-operated steps.
- Remaining gaps: archive search/persistence/empty-state/legacy-link results were not separately observed, nor were earlier submission/edit browser checks, normal save persistence, faculty rendering and browser access boundaries.
- Save behavior: existing year rows/IDs are reused, including an inactive saved year. When the current year has no row, an authorized valid project save creates it inside the project transaction; failure rolls it back with the project. Form GETs do not create year rows. No schema change or scheduler is needed.
- Data changes/cleanup: none.
