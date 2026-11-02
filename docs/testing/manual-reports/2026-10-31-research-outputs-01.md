# Verification: research outputs register and filters

- Date/time: 2026-10-31 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: `pages/shared/implementation-outputs.php?view=outputs&module_page=research_outputs`; output search, type, year, paging, and current-year summary should include eligible legacy records with missing publication/registration dates by using their record creation date.
- Environment URL: `http://localhost/rms/`
- Roles and disposable records/files: Admin or research staff with `research_outputs` permission; no record changes required.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: How does the implementation outputs page construct its register and filter URLs? Selected terms: `implementation`, `outputs`, `filter`.
- Query/lookup and result: `graphify query "implementation outputs filter" --budget 1500` and `graphify query "publication outputs filter" --budget 1500` found the outputs and publication routes. A focused lookup for `archival-record-list.php archiveStaffScopes()` was truncated and matched stale/unrelated graph nodes; source inspection was used for this helper path.
- Additional lookup: `graphify query "stat-card alignment" --budget 1000` matched stale image/document nodes and provided no useful CSS context; the current page CSS was inspected directly.
- Relevant source locations and verified relationships: The outputs view in `pages/shared/implementation-outputs.php` had limited publication rows to active archived or published records, then filtered `feed.record_type` for `type=publication`. The Publications hub delegates to `includes/archival-record-list.php`; its default list excludes inactive records but permits all publication statuses. Its detail/download authorization uses `archiveStaffScopes()` in `includes/archival-records.php`.
- Missing, inferred, stale, or truncated context: Graphify coverage for the archival helper path is stale/unhelpful. The user confirmed that all active publication records should be included because review is no longer needed. No runtime database values were queried.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l pages/shared/implementation-outputs.php` | PHP syntax is valid | `No syntax errors detected in pages/shared/implementation-outputs.php` | Passed |
| B1 | User browser / admin or research staff / Research Outputs | Open Research Outputs filtered to publication type with no year | Matching active publication records appear | User reports no records in the publication view with an empty year filter | Failed |
| S2 | Assistant static checks | Run `php -l` on the three changed PHP files and `git diff --check` on the implementation and archive files | All syntax checks and whitespace checks pass | All three PHP lint commands reported no syntax errors; `git diff --check` produced no findings | Passed |
| B2 | User browser / admin or research staff / Research Outputs | Reopen Research Outputs with `type=publication` and a blank year | All active publication records appear; the publication count matches the register | User screenshot shows 2 active publications, 1 registered copyright, and 3 current-year outputs; the filtered register rows are not in the crop | Pending |
| B3 | User browser / admin or research staff / Research Outputs | Refresh the Research Outputs page and inspect the three summary cards | Each card's number and caption are centered within the card | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- Source review found that registered legacy rows may qualify through `status` and `copyright_ref_no` while `registered_at` is null; the outputs branch exposed a null event date and the year filter then excluded the row. Publication rows with a missing `publication_date` had the same mismatch despite an existing `created_at` fallback for sorting.
- Updated current-year counts, output event dates, and output table date labeling to use the record creation date when the actual publication/registration date is missing. No database data or schema was changed.
- The user chose to include all active publication records because publication review is no longer needed. Updated the output count and register queries to exclude only deactivated rows, aligned the Research Outputs grant in the publications list/detail access paths with all active publication statuses, and updated labels to describe active publication records. The existing `research_outputs` permission still gates this access; no broader role grant or database change was added.
- User supplied a screenshot of the output summary cards and asked to center them. Added output-view-only flex alignment to center each card's number and caption horizontally and vertically; the row layout remains unchanged.

## Assistant-run checks
- `php -l pages/shared/implementation-outputs.php`, `php -l includes/archival-record-list.php`, and `php -l includes/archival-records.php` — all passed.
- `git diff --check -- pages/shared/implementation-outputs.php includes/archival-record-list.php includes/archival-records.php docs/testing/manual-reports/2026-10-31-research-outputs-01.md` — passed with no findings.
- Read-only Graphify queries and current schema/source review — completed; no application interaction or database mutation was performed.

## Resume / handoff
- Last user screenshot: summary values are 2 active publication records, 1 registered copyright, and 3 current-year outputs; the register rows are not visible in the crop.
- Next pending action: user refreshes the output view and confirms the summary-card alignment, and whether the publication register shows active rows with the year blank.
- Blocked/skipped checks and remaining verification gaps: Runtime database contents and browser retest remain unverified. Search, year filtering, pagination, and clear-filter interactions remain unverified.
- User-performed data changes and cleanup status: none.
