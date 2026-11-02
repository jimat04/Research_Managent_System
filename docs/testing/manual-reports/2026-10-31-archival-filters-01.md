# Verification: archival filters

- Date/time: 2026-10-31 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Admin and faculty copyright, publication, patent/utility model, and innovation/technology transfer lists show only the filters needed to find records; filter queries continue to narrow the same records.
- Environment URL: Not applicable
- Roles and disposable records/files: Admin and faculty accounts; no records changed.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How the copyright and publication list routes reach their filter forms. Selected terms: `copyright`, `publication`.
- Query/lookup and result: `graphify query copyright publication patent archive outputs --budget 1500` returned copyright route/form nodes. `graphify query publication --budget 1500` returned publication wrapper/form nodes.
- Relevant source locations and verified relationships: Current `pages/shared/publications.php` sets the publication list mode and includes `includes/archival-record-page.php`; copyright scoped routes are selected in `includes/archival-copyright-route.php` and both list forms render in `includes/archival-record-list.php`. The ordinary copyright application form is in `pages/shared/copyrights.php`.
- Missing, inferred, stale, or truncated context: Graphify is stale for these paths: it attributes list functions to `pages/shared/publications.php`, whose current source is a short route wrapper, and does not expose the current `archival-record-list.php` include relationship. Confirmed route relationships directly from current source. No application behavior was inferred from graph edges.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Integrated browser / Admin / Copyrights | Open the general Copyrights list and inspect its filter row | Search, Year, Record type, Archival status; no External copyright status filter | Awaiting user observation | Pending |
| A2 | Integrated browser / Admin / Publications | Open the Publications list and inspect its filter row | Search, Year, Record type, Archival status; no extra indexing, availability, owner, or program fields | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l includes/archival-record-list.php` — passed.
- `php -l pages/shared/copyrights.php` — passed.
- `git diff --check -- includes/archival-record-list.php pages/shared/copyrights.php` — passed.
- Inspected the general copyright filter definitions: no external-status control is rendered. The scoped IP registers still have their distinct registration/IP status filter.
- Browser filtering and responsive layout have not yet been observed.

## Resume / handoff
- Last user-confirmed state: No browser observation yet.
- Next pending action: User checks A1 in the integrated browser and reports whether External copyright status is absent from the general Copyrights filters.
- Blocked/skipped checks and remaining verification gaps: Faculty views, copyright application status/type/year/search, and the scoped patent/utility model and innovation/technology transfer lists remain pending.
- User-performed data changes and cleanup status: None.
