# Verification: faculty engagement other type

- Date/time: 2026-11-01 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Selecting “Other Research Engagement” reveals a required field for its specific engagement type; a failed submission preserves the selection and entered text.
- Environment URL: Not confirmed
- Roles and disposable records/files: Faculty role; no record submitted
- Session state: In progress

## Graphify and source context
- UI follow-up question and selected graph vocabulary: Which faculty dashboard link matches the screenshot and where is its style owned? In-memory label scan selected `faculty`, `dashboard`, and `analytics`.
- UI follow-up query/lookup and result: Existing Graphify data contains a `faculty-dashboard.php` node (`pages/faculty/faculty-dashboard.php`, L1), but no adjacent view or stylesheet edges. The graph CLI remains unavailable.
- UI follow-up source confirmation: `includes/faculty-dashboard/overview.php:20–25` renders the “Research Analytics” link and arrow; `css/faculty-shell.css` owns the faculty dashboard button styles. The screenshot corresponds to this overview link.
- UI follow-up graph limitations: No dashboard-template-to-CSS edge was available, so the rendered template and owning stylesheet were confirmed directly.
- Question and selected graph vocabulary: Does the faculty dashboard graph entry show the review-queue/service/schema relationship implicated by the reported missing-column error? In-memory label scan selected `faculty`, `dashboard`, `engagement`, and `review`.
- Query/lookup and result: Existing `graphify-out/graph.json` is present; the `graphify` CLI is unavailable. Direct node/adjacent-edge lookup found `faculty-dashboard.php` (`pages/faculty/faculty-dashboard.php`, L1) and no adjacent edges for the dashboard-to-service/schema path.
- Relevant source locations and verified relationships: `pages/faculty/faculty-dashboard.php` calls `FacultyDashboardService::getOverviewData()`; `includes/services/FacultyDashboardService.php:44` calls `FacultyReviewQueueService::getPendingReviews()`. `includes/services/FacultyReviewQueueService.php:334–339` selects `custom_engagement_type` from `faculty_engagement_records`. Migration `database/migrations/056_add_custom_engagement_type.sql` adds that nullable column; the canonical schema had omitted it.
- Missing, inferred, stale, or truncated context: The graph has no adjacent dashboard/service/schema edges, so these relationships were confirmed against current source. No graph changes made.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l pages/faculty/faculty-engagement.php` | PHP syntax passes | “No syntax errors detected” | Passed |
| S2 | Assistant static check | Run `php -l scripts/migrate.php` | PHP syntax passes | “No syntax errors detected” | Passed |
| S3 | Assistant static check | Run `php -l includes/faculty-dashboard/overview.php` and count braces in `css/faculty-shell.css` | PHP syntax passes and CSS braces balance | PHP: “No syntax errors detected”; CSS braces: 505/505 | Passed |
| B1 | User browser / faculty / faculty engagement page | Open page and confirm the submission form appears | Engagement type selector and form are visible | Awaiting user observation | Pending |
| B2 | User browser / faculty / faculty engagement page | Select “Other Research Engagement” | “Specify other research engagement” text input appears and is required | Not run; depends on B1 | Pending |
| D1 | Assistant read-only preflight and migration runner / local database | Confirm configured target and column/ledger state, then apply migration `056_add_custom_engagement_type.sql` | Local `rms_db` receives nullable column and runner records migration | Preflight: `database_target=rms_db;column=missing;migration=untracked`; runner: `APPLIED 056_add_custom_engagement_type.sql`; postflight: `database_target=rms_db;column=present;migration=applied` | Passed |
| B3 | User browser / faculty / faculty dashboard | Reload dashboard after D1 | Dashboard renders without the unknown-column fatal error | User confirmed: “It renders without the unknown-column fatal error.” | Passed |
| U1 | User browser / faculty / faculty dashboard / desktop | Reload dashboard and inspect the Research Analytics action | Filled blue action has a clear label, arrow, comfortable hit area, and no layout shift | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- User-reported failure: faculty dashboard request throws `mysqli_sql_exception: Unknown column 'custom_engagement_type' in 'field list'` at `includes/services/FacultyReviewQueueService.php:334`.
- Source comparison confirmed the query and the faculty engagement form expect the column, migration 056 defines it, and the fresh-install schema omitted it. The canonical schema now includes the column, and the migration runner now verifies its expected nullable `varchar(255)` shape before recording or applying migration 056.
- Applied migration 056 after confirming the configured database was local `rms_db`, the column was missing, and the migration was untracked. The runner reported `APPLIED`; a read-only postflight confirmed the column is present and the migration is recorded as applied.
- Retest passed: user confirmed the faculty dashboard renders without the unknown-column fatal error.

## Assistant-run checks
- `php -l pages\faculty\faculty-engagement.php` — passed.
- `php scripts/migrate.php apply 056_add_custom_engagement_type.sql` — applied successfully against verified local `rms_db`.
- `php -l includes/faculty-dashboard/overview.php` — passed; CSS brace count for `css/faculty-shell.css` is balanced (505/505).
- Read-only preflight/postflight confirmed the column and ledger status. PHP emitted session-directory permission warnings because CLI config tried to open `C:\xampp\tmp`; the database checks completed and returned the expected states.

## Resume / handoff
- Last user-confirmed state: User reported the faculty dashboard fatal error; no post-fix browser observation yet.
- Last user-confirmed state: Faculty dashboard renders without the unknown-column fatal error.
- Next pending action: None for the dashboard error. The original engagement form visibility and custom-type validation checks remain pending if that workflow is resumed.
- Blocked/skipped checks and remaining verification gaps: Engagement form validation/persistence checks from the original session remain pending.
- User-performed data changes and cleanup status: None.
