# Verification: Publication and dissemination section content

- Date: 2026-11-01 (Asia/Taipei).
- Assistant: Codex, using the local rms-page-builder definition; no specialist invocation.
- Target: Separate Journal Publications, Conference Papers & Presentations, and Research Repository content within the existing publication list routes for administrators and research staff.
- Assumption: The request concerns the three existing Publication & Dissemination sidebar destinations, with existing shared handlers retained.
- Environment: Local RMS workspace; browser URL and session not confirmed.
- Roles: Admin and authorized research staff. No disposable records created or required for initial read-only checks.
- Session state: Implementation and static review complete; awaiting user-operated browser verification.
- Files changed: includes/archival-record-page.php, includes/archival-record-list.php.
- Database effects: None. No migrations, SQL execution, application requests, or record mutations performed.

## Graphify and source context

- Question: How do publication module links reach list queries, reports, record details, and permission checks?
- Actual vocabulary selected: publications, publication.
- Lookup: `rtk proxy graphify query "publications publication" --budget 1500`.
- Result: 15 nodes, including pages/shared/publications.php:L1 and historical helper nodes at L16/L21/L30. Those helper nodes are stale: the current entry page delegates to includes/archival-record-page.php. No graph installation or rebuild performed.
- Source confirms the module launcher appends module_page; admin and staff destinations use the same archive controller/list. archiveStaffScopes() in includes/archival-records.php defines repository membership as archived OR published. The list now applies that predicate before totals, summaries, rows, and export queries.
- Journal/conference scopes override conflicting record_type filters and omit the cross-type selector. Section parameters persist through list filters, pagination, reports, add/edit/detail links, and return links. The existing form and detail handlers consume navigationQuery/navigationSuffix.
- Staff section lists use requireModulePermission() from includes/auth.php:717; per-record access and manage/download checks remain in their existing handlers. Faculty ownership/contributor and student linkage predicates remain unchanged.
- Reviewed all existing launcher mappings, including the standard 15 subpages. Launcher and shell files were not edited; the affected destinations are journal_publications, conference_presentations, and research_repository. Research Outputs continues to use implementation-outputs.php.
- Coverage limitation: The graph predates the archival controller split; current source is the authority. No live rendering, permission response, or data result has been observed.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S01 | Assistant / changed PHP | Run php -l | Valid PHP syntax | Both changed files passed; list lint repeated after the final markup edit | Passed |
| S02 | Assistant / session diff | Compare to pre-edit snapshots | Only intended section changes | Reviewed both no-index diffs; no unrelated source changes in these edits | Passed |
| S03 | Assistant / whitespace | Run no-index diff --check | No whitespace diagnostics | No diagnostics; no-index returns 1 for differing files | Passed |
| B01 | Admin / Journal Publications | Open Publication & Dissemination > Journal Publications | Journal heading; journal-only records or empty state; no cross-type selector | Awaiting user | Pending |
| B02 | Admin / Conference Papers & Presentations | Open section | Conference heading and conference-only records | Awaiting user | Pending |
| B03 | Admin / Research Repository | Open section | Repository heading; published or archived records only | Awaiting user; existing authorized records needed to distinguish membership | Pending |
| B04 | Admin / section filters and reports | Filter, clear, paginate, open reports/export, return | Section retained; counts, summaries and CSV match section | Awaiting user, one action at a time | Pending |
| B05 | Admin / section details and forms | Open a record or add form, then return | Existing handler works and returns to originating section | Awaiting user; no record mutation required | Pending |
| B06 | Research staff / assigned section | Open permitted section, then details and return | Correct scoped content and sidebar context | Awaiting user with authorized account | Pending |
| B07 | Research staff / unassigned section | Open direct section route normally | Access denied without permission | Awaiting user with suitable restricted account | Pending |
| B08 | Admin / conflicting section filter URL | Open journal module_page with record_type=conference | Journal scope still enforced | Awaiting user | Pending |
| B09 | Faculty/student and copyright lists | Open existing authorized list | Existing role data boundaries and list behavior retained | Awaiting user | Pending |

## Failures, decisions, fixes, and retests

- No browser failures observed. Source inspection established the requested separation issue before editing: shared generic headings, an overridable journal/conference filter, and no repository membership predicate in the list.
- No user-reported failures or fix decisions yet.

## Assistant-run checks

- `rtk proxy php -l includes/archival-record-page.php`: no syntax errors.
- `rtk proxy php -l includes/archival-record-list.php`: no syntax errors (including after final edit).
- `git -c core.autocrlf=false diff --no-index --unified=3` for the controller and `--unified=2` for the list against their pre-edit temporary snapshots, through rtk proxy: reviewed expected differences.
- `git -c core.autocrlf=false diff --no-index --check` for both files through rtk proxy: no whitespace diagnostics.
- Checks are static only; no claims about successful live queries, rendering, downloads, or navigation.

## Resume / handoff

- Last user-confirmed browser state: none.
- Next pending action: B01. As an administrator, open Publication & Dissemination > Journal Publications. Confirm the heading and whether only journal records (or the empty state) appear.
- User must perform every application interaction per the repository manual testing guide. Guide one action and wait for its observation.
- Remaining gaps: Browser results B01-B09, including applicable restricted-account and direct-route checks.
- User-performed changes and cleanup: None observed; no cleanup needed.

## Follow-up: remove the Repository section from the admin side

- Request: Hide Research Repository from admin publications while preserving the research staff route.
- Graphify query: `rtk proxy graphify query "admin-publications research_repository" --budget 1000`, then `rtk proxy graphify query "research_repository admin-publications.php" --budget 1600`.
- Graph result: Both broad expansions were truncated and mostly returned unrelated admin nodes. Direct inspection of matching graph nodes found `includes/admin-shell.php` and `pages/admin/admin-research-module.php`, but no nodes for `admin-publications.php` or `includes/archival-record-page.php`. Graph coverage is missing/stale for the actual page route; current source was checked directly.
- Source changes: Removed the admin sidebar item and its active-state inference; admin requests to the repository module route return to the admin dashboard; old repository selectors no longer create a repository section in the admin archive controller. The module mapping and staff destination remain so the research staff repository continues to work.
- Assistant checks: `php -l` passed for `includes/admin-shell.php`, `pages/admin/admin-research-module.php`, and `includes/archival-record-page.php`.
- Browser checks: Admin should confirm the Publication & Dissemination sidebar no longer lists Research Repository, and that Journal Publications and Conference Papers & Presentations still open. Staff repository access remains pending source-reviewed only.
- Next user action: As admin, open the Publication & Dissemination sidebar group and confirm Research Repository is absent while the other publication links remain visible.

## Follow-up: use the publication entry for Book Chapters

- Request interpretation: Replace the admin-side All Publications entry with a Book Chapters list, while leaving the other admin publication pages and staff repository alone.
- Graphify terms and query: `publications`, `book_chapters`; `rtk proxy graphify query "publications book_chapters" --budget 1500`.
- Graph result: 19 nodes, but the current Book Chapters/archive route was not represented. Results pointed to stale architecture/documentation nodes; current source is authoritative for this change.
- Source changes: The admin sidebar entry now opens `admin-publications.php?publication_type=book_chapter` and is labeled Book Chapters. The archive controller pins the list to `book_chapter`, carries that type through form/detail/report navigation, and gives it a dedicated title and description. The shared Research Staff repository mapping remains unchanged.
- Assistant checks: PHP lint passed for `includes/admin-shell.php`, `includes/archival-record-page.php`, and `includes/archival-record-list.php`.
- Browser check: Pending. As admin, open Publication & Dissemination > Book Chapters and confirm the Book Chapters heading and book-chapter-only list (or empty state). Confirm the link remains active after opening a record or report as convenient.
