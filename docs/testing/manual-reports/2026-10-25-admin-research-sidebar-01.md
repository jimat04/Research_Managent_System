# Verification: Admin research sidebar

- Date/time: 2026-10-25, Asia/Taipei
- Assistant: GitHub Copilot
- Target and expected behavior: Correctly nested Research Capacity Building navigation in the Admin sidebar, with active ancestor expansion and existing child routes preserved.
- Environment URL: Local RMS environment; URL not confirmed
- Roles and disposable records/files: Admin; no records or files used
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How does the Admin shell render its navigation? Selected exact graph vocabulary: `renderAdminShell`.
- Query/lookup and result: `graphify query "renderAdminShell" --budget 1500` returned the Admin shell, its icon helper, close helper, and extracted call/containment edges.
- Relevant source locations and verified relationships: `includes/admin-shell.php` defines the navigation groups and recursive active-descendant checks; `pages/admin/admin-research-module.php` retains the existing `research_capacity_building` routes for each form and status listing.
- Missing, inferred, stale, or truncated context: Graph nodes for Admin dashboard screenshots and older E2E references are historical context only. Browser interaction and mobile appearance have not been confirmed.
- Follow-up question and selected graph vocabulary: Why does the shared Incoming/Ongoing activity listing warn or show an empty schedule? Selected `activitiesRun` and `ongoing` (matched to the graph node “Ongoing Research View”).
- Follow-up query/lookup and result: `graphify query "activitiesRun ongoing" --budget 1500` returned the current `pages/shared/activities.php` page, `activitiesRun()` at `pages/shared/activities.php:L100`, and nearby helper/page relationships. The broader `activities.php` lookup had been truncated at its 1500-token budget and included historical/stale E2E references.
- Follow-up source confirmation: The `incoming` and `ongoing` preset views are validated at `pages/shared/activities.php:L224-L235`, while the activity-category-only `$typeLabels` lookup was used by the schedule panel. The preset views did not fill `$upcoming`; the corrected query path is at `pages/shared/activities.php:L281-L288`, and safe view-specific panel copy is derived at `pages/shared/activities.php:L444-L461` and rendered at `pages/shared/activities.php:L922-L926`.
- Follow-up limitations: Graph edges are hints; current page source confirmed the relevant data flow. No database or browser interaction was performed for this fix.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | PHP syntax check / assistant | Run `php -l includes/admin-shell.php` | No PHP syntax errors | `No syntax errors detected` | Passed |
| S2 | Focused source assertion / assistant | Check the Research Capacity Building navigation definition | Exactly two direct child groups, two Add links, three View links; no Seminars, Trainings, or Workshops in this sidebar group | Source assertion passed | Passed |
| S3 | Route source review / assistant | Inspect the existing Admin module map | The five child links retain their form/listing routes and permission key | Existing routes and permission key confirmed in `pages/admin/admin-research-module.php` | Passed |
| S4 | Diff whitespace check / assistant | Run `git diff --check` on the changed shell and stylesheet | No whitespace errors | Passed; Git emitted only its existing LF-to-CRLF working-copy warning | Passed |
| B1 | Browser / Admin / current Admin page / desktop width | Click Research Capacity Building once | It expands without navigating, showing exactly Add Activity and View Activity as direct children | User confirmed it expanded in place and showed only Add Activity and View Activity | Passed |
| B2 | Browser / Admin / same page / desktop width | Click Add Activity once | It expands in place to exactly Add Incoming Activity and Add Conducted Activity; View Activity remains its sibling | User confirmed exactly those two children appeared and the page did not change | Passed |
| B3 | Browser / Admin / same page / desktop width | Click View Activity once | It expands in place to exactly Incoming, Ongoing, and Conducted | User confirmed exactly those three children appeared and the page did not change | Passed |
| B4 | Browser / Admin / activity form | Click Add Incoming Activity | Existing incoming activity form opens; both ancestor groups are expanded and the child is active | User confirmed form opened with both ancestors expanded and the active child highlighted | Passed |
| B5 | Browser / Admin / activity form | Click Add Conducted Activity | Existing conducted activity form opens; both ancestor groups are expanded and the child is active | User confirmed the conducted form opened with both ancestors expanded and the active child highlighted | Passed |
| B6 | Browser / Admin / activity list | Click Incoming | Existing incoming list opens; both ancestor groups are expanded and the child is active, with no PHP warning; its schedule panel lists incoming records or shows “No incoming activities” | User retested after the fix and confirmed the expected listing and active state with no warning | Passed |
| B7 | Browser / Admin / activity list | Click Ongoing | Existing ongoing list opens; both ancestor groups are expanded and the child is active, with no PHP warning; its schedule panel lists ongoing records or shows “No ongoing activities” | User retested after the fix and confirmed the expected listing and active state with no warning | Passed |
| B8 | Browser / Admin / activity list | Click Conducted | Existing conducted list opens; both ancestor groups are expanded and the child is active | User confirmed the existing Conducted view opened with View Activity expanded and Conducted active | Passed |
| B9 | Browser / Admin / current Admin page / mobile width | Resize to 768px or narrower | Sidebar moves off-canvas and the mobile navigation button appears | User confirmed the mobile menu button appeared while the sidebar was hidden | Passed |
| B10 | Browser / Admin / Conducted activity view | Refresh the page | The page remains on Conducted and Research Capacity Building and View Activity auto-expand with Conducted active | User confirmed the same page returned and both active ancestors were restored | Passed |
| B11 | Browser / Admin / Conducted activity view | Expand Research Development | Current page remains unchanged and Research Capacity Building remains open | User confirmed Research Development expanded while the Conducted page and Research Capacity Building stayed open | Passed |
| B12 | Browser / Admin / Conducted activity view / mobile width | Open administrator navigation | Sidebar opens and retains the active nested path to Conducted | User confirmed the sidebar opened with the active nested path visible | Passed |
| B13 | Browser / Admin / Conducted activity view / mobile width | Click Add Activity | Add Activity expands to its two form links without navigating or closing View Activity | User confirmed both form links appeared and the page and View Activity group stayed unchanged | Passed |
| B14 | Browser / Admin / Conducted activity view / mobile width | Click Add Activity again | Its two links collapse while the page and View Activity remain unchanged | User confirmed its links collapsed and the page plus View Activity stayed unchanged | Passed |
| B15 | Browser / Admin / Conducted activity view / mobile width | Click View Activity | Its three status links collapse without navigating | User confirmed all three status links collapsed and the page stayed unchanged | Passed |
| B16 | Browser / Admin / Conducted activity view / mobile width | Click Research Capacity Building | The whole section collapses without navigating away from Conducted | User confirmed the section collapsed and the Conducted page remained open | Passed |

## Failures, decisions, fixes, and retests
- B6/B7: Initial Incoming and Ongoing listings showed the active sidebar state but emitted `Warning: Undefined array key "ongoing"` at `pages/shared/activities.php:901` (Ongoing also displayed `No upcoming`). The user authorized a fix; both retests passed without warnings.
- Follow-up fix: Added a status-specific schedule query for Incoming and Ongoing, plus view-specific labels and empty-state copy. This preserves the original observations above.

## Assistant-run checks
- `php -l includes/admin-shell.php` — passed.
- `git diff --check -- includes/admin-shell.php css/admin-shell.css` — passed; Git reported a line-ending conversion warning for the stylesheet.
- Focused source assertion — passed for the required nested labels and absence of the three removed category entries in this sidebar group.
- Read-only Graphify lookup and source confirmation — completed; no database commands or application data changes.
- Follow-up `php -l pages/shared/activities.php` — passed (`No syntax errors detected`).
- Follow-up focused read-only PowerShell source assertions — passed: Incoming/Ongoing schedule query branch; each status maps to its expected fixed SQL condition; explicit Incoming/Ongoing labels and category-label fallback exist; template renders derived copy and no longer directly indexes `$typeLabels[$view]`.
- Follow-up `git diff --check -- pages/shared/activities.php` — passed; Git emitted its working-copy LF-to-CRLF warning.
- No database commands or browser interactions were performed for the follow-up.

## Resume / handoff
- Last user-confirmed state: At 768px or narrower, Research Capacity Building collapsed without navigating away from the Conducted page.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None for the requested sidebar hierarchy, child routes, active state, refresh behavior, or mobile expand/collapse behavior.
- User-performed data changes and cleanup status: None.
