# Verification: Admin research activity type pie chart

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Add a second interactive donut beside the existing Research status donut in Admin Reports. It displays counts and percentages for each non-cancelled research activity type and follows the selected researcher role filter. Both charts respond to legend hover and keyboard focus.
- Environment URL: http://localhost/rms/pages/admin/admin-reports.php (configured fallback; live availability not confirmed)
- Roles and disposable records/files: Administrator; no record changes required.
- Session state: In progress; awaiting user browser observation.

## Graphify and source context
- Question and selected graph vocabulary: Trace research activities and role-filtered report data. Selected terms: `activity`, `research_activities`, `activities.php`, `activity-form.php`.
- Query/lookup and result: `graphify query "activity research_activities publications copyright" --budget 1500`; results were truncated (50 nodes), primarily showing shared activities pages and unrelated publication/copyright nodes. A narrower `graphify query "research_activities" --budget 1500` was ambiguous across schema/migration nodes. No graph edge established the Admin Reports query dependency.
- Relevant source locations and verified relationships: Current `pages/admin/admin-reports.php` already role-filters activity metrics through `research_activities` and `activity_participants` near L293-L365. The added type query uses the same participant-role `EXISTS` filter, excludes cancelled activities, groups by `activity_type`, and renders beside the project status donut near L655-L755. Activity types and enum values were confirmed in `database/schema/rms_db.sql` near L721. The page JS synchronizes both donuts independently; `css/admin-reports.css` controls their shared layout and responsive stacking.
- Missing, inferred, stale, or truncated context: Graph search was broad/truncated and its narrower retry ambiguous; current report source and canonical schema are the authority for this chart.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Assistant / PHP syntax and whitespace | `php -l pages/admin/admin-reports.php`; `git diff --check -- pages/admin/admin-reports.php css/admin-reports.css` | No PHP syntax or whitespace errors | PHP syntax passed; diff check emitted no diagnostics | Passed |
| 2 | Integrated browser / administrator / Reports & Analytics | Open Research overview and inspect both charts side by side; hover the activity legend entries | Research status and Research activities charts are visible; activity segments and legend show activity types/counts/percentages; hover updates center value/label without changing the status chart | Awaiting user observation | Pending |
| 3 | Integrated browser / administrator / Reports & Analytics | Focus an activity legend entry using the keyboard, then inspect at a narrow viewport | Focus shows matching slice and center value; charts stack cleanly on narrow screens | Awaiting user observation | Pending |

## Assistant-run checks
- `php -l pages/admin/admin-reports.php`: passed.
- `git diff --check -- pages/admin/admin-reports.php css/admin-reports.css`: passed with no diagnostics.
- No database actions or application interactions were performed.

## Resume / handoff
- Last user-confirmed state: no browser observation yet.
- Next pending action: user opens the Admin Reports page and checks the two side-by-side donuts plus activity-type hover behavior.
- User-performed data changes and cleanup status: none.
