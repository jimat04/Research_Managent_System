# Verification: publication archive filters

- Date/time: 2026-11-02 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The admin publication archive filter hides legacy choices and links publication subtypes to the selected type. Copyright Applications, Patents / Utility Models, and Innovation & Technology Transfer lists expose working filters and retain their category scope when filters are cleared.
- Environment URL: http://localhost/rms/pages/admin/admin-publications.php; http://localhost/rms/pages/shared/copyrights.php
- Roles and disposable records/files: Admin; no record changes required.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Trace the publications archive and filter form. Selected graph terms: `publication`, `archive`.
- Query/lookup and result: Earlier `graphify query publication archive --budget 1500` covered the previous publication filter change. For this copyright scope change, `graphify query copyrights copyright archive --budget 1500` returned the shared `pages/shared/copyrights.php` wrapper and helpers, but not the archival route/list implementation.
- Relevant source locations and verified relationships: `pages/admin/admin-research-module.php:39-41` routes the three menu modules to `pages/shared/copyrights.php`; `includes/archival-copyright-route.php:15-24` selects the scoped archive and hands list rendering to `includes/archival-record-page.php`; that page configures the allowed output types and `includes/archival-record-list.php` renders and applies filters. The clear-filter query is assembled in `includes/archival-record-page.php`; copyright type, application status, filing year, and external status predicates are prepared in `includes/archival-record-list.php`. For publication filters, the form loads `js/archival-records.js`, which links subtypes by `data-type-id`. `includes/publication-classification.php:30-37` defines active-only type/subtype lookups; `database/schema/rms_db.sql:1164-1173` marks the four compatibility classifications as `is_proposed=0`.
- Missing, inferred, stale, or truncated context: Graph coverage is stale/incomplete for the shared archival-list implementation; source inspection confirmed the current call path. No relevant graph edges were available for that form.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run PHP syntax check on changed include and inspect filter option markup | PHP syntax is valid; legacy options are absent from the displayed choice lists | `php -l includes/archival-record-list.php` passed; source shows only active proposed types and active subtypes, with only Archived as an explicit archival status and no unresolved-owner option. `git diff --check` reported no whitespace errors (only existing line-ending warnings). | Passed |
| S2 | Assistant static check | Re-run PHP syntax and whitespace checks after correcting subtype labels | PHP syntax remains valid and each generated subtype option uses its own subtype label | `php -l includes/archival-record-list.php` passed; markup now renders `type — subtype`. `git diff --check` found no whitespace errors and emitted only existing line-ending warnings. | Passed |
| S3 | Assistant static check | Check dependent-filter JavaScript syntax and inspect its selectors | JavaScript parses and filters subtype options by selected type | `node --check js/archival-records.js` passed; handler matches each option's `data-type-id` against Publication Type and clears an incompatible subtype. `git diff --check` found no whitespace errors (existing line-ending warnings only). | Passed |
| S4 | Assistant static check | Run PHP syntax checks on the shared archive page and list renderer | Both PHP files parse after adding copyright filters and scope retention | `php -l includes/archival-record-page.php` and `php -l includes/archival-record-list.php` both passed. Source review confirms type options come from the route's allowed types and category scope is retained in clear links. | Passed |
| B1 | User browser / admin / publication archive | Inspect Publication Subtype choices | Each option identifies its subtype clearly | User screenshot shows repeated parent labels such as “Journal Article” and “Book” for multiple subtype entries, making the actual choices indistinguishable. | Failed |
| B1R | User browser / admin / publication archive | Reopen or refresh the page and inspect Publication Subtype choices | Each option shows its publication type and its specific subtype, with no legacy types | Pending | Pending |
| B2 | User browser / admin / publication archive | Select a Publication Type and inspect Publication Subtype choices | Only subtypes belonging to the selected type remain available | Pending | Pending |
| B3 | User browser / admin / publication archive | Select All Types after B2 | All active subtype choices become available again | Pending | Pending |
| C1 | User browser / admin / Copyright Applications | Open the list and inspect the filter form | Search, output type, filing year, application status, and external status filters appear; output types are copyright application types | Pending | Pending |
| C2 | User browser / admin / Patents / Utility Models | Open the scoped list and inspect output types; use Clear after a filter is applied | The list stays scoped to Patent and Utility Model records | Pending | Pending |
| C3 | User browser / admin / Innovation & Technology Transfer | Open the scoped list and inspect output types; use Clear after a filter is applied | The list stays scoped to Innovation and Technology Transfer records | Pending | Pending |

## Failures, decisions, fixes, and retests
- B1 failed: the subtype option markup displayed the parent type label for every subtype. The existing request to fix the archive choices authorizes correcting this label. Changed the display to include both the parent type and subtype label; B1R is pending user retest.

## Assistant-run checks
- `php -l includes/archival-record-list.php` — passed: no syntax errors.
- `git -C C:/xampp/htdocs/rms -c safe.directory=C:/xampp/htdocs/rms diff --check` — passed with no whitespace errors; Git emitted existing LF-to-CRLF working-copy warnings.
- After the B1 fix, repeated both commands above; PHP syntax passed and whitespace check had no errors.
- `node --check js/archival-records.js` — passed: no syntax errors.
- `php -l includes/archival-record-page.php` — passed: no syntax errors.
- `php -l includes/archival-record-list.php` — passed: no syntax errors.

## Resume / handoff
- Last user-confirmed state: None.
- Next pending action: Ask the user to open the Copyright Applications list and confirm the new filters appear for C1.
- Blocked/skipped checks and remaining verification gaps: Browser retest is pending.
- User-performed data changes and cleanup status: None.
