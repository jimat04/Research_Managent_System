# Verification: center activity statistics

- Date: October 26, 2026 (Asia/Taipei)
- Target: All stat-grid views in `pages/shared/activities.php` (Overview, Incoming, Ongoing, Conducted, and activity-type views).
- Expected behavior: Each statistic group is centered as a group with centered values/labels; a final odd card remains centered on narrow screens.

## Graphify lookup

- Question: Which page owns the ongoing and conducted activity statistics and their layout?
- Selected graph terms: `activities.php`, `stat-card`, `stat-grid`, `attendance`.
- Query: `graphify query "activities.php stat-card stat-grid attendance" --budget 1000`.
- Result: The graph returned 55 nodes and was truncated at 30. It identified `pages/shared/activities.php`; most other returned nodes were unrelated or historical tests/reports. The lookup was repeated after the requested scope expanded and returned the same coverage/limitation.
- Source confirmation: `pages/shared/activities.php` builds the ongoing two-stat and conducted three-stat arrays in `$viewConfig`, renders them through `.stat-grid`, and defines its page-scoped CSS inline. No separate stylesheet owns these stats.
- Limitation: The traversal was truncated; current page source confirmed the view-to-grid relationship and responsive rules.

## Assistant checks

| ID | Check | Expected | Result | Status |
|---|---|---|---|---|
| A1 | `php -l pages/shared/activities.php` | No PHP syntax errors | `No syntax errors detected`. | Passed |
| A2 | Focused source assertions for shared view class, centered grid/card, and mobile odd-card placement | All activity views use centered styles; a final odd card centers on narrow screens | The modifier is now on the shared page wrapper; all assertions passed for all views. | Passed |
| A3 | CSS brace balance and `git diff --check` | CSS blocks balance; no whitespace errors | Inline CSS braces balance at 110/110; `git diff --check` reports no errors. | Passed |
| U1 | Permitted user: open `pages/shared/activities.php?view=ongoing` at desktop width | Two stat cards appear centered as one group, with centered values and labels | Awaiting user observation. | Pending |
| U2 | Same role: open `pages/shared/activities.php?view=conducted` at desktop and narrow width | Three stat cards appear centered; at narrow width the third remains centered below the first two | Awaiting user observation. | Pending |
| U3 | Same role: open `pages/shared/activities.php` (Overview) | Overview activity-type stat group uses the same centered grid | Awaiting user observation. | Pending |

## User observations

- None received yet. The assistant made no application interactions.
