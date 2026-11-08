# Investigation: Faculty publication and registered IP data

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Explain how the faculty profile Publications & outputs and Registered IP tabs retrieve records, and why an account may see empty results.
- Environment URL: `http://localhost:8080/rms/pages/faculty/faculty-profile.php`
- Roles and disposable records/files: Faculty account; no records changed.
- Session state: Finished source investigation

## Graphify and source context
- Question and selected graph vocabulary: Trace profile tabs to contributor relationships and registered output filters; selected existing graph terms `copyright` and `output`, and checked for `publication_contributors`, `copyright_contributors`, and `faculty-profile` coverage.
- Query/lookup and result: Inspected the existing `graphify-out/graph.json` read-only. The installed CLI does not provide the documented `query` command. Graph coverage includes shared copyright/schema nodes but no faculty profile or contributor-query nodes/edges for this flow.
- Relevant source locations and verified relationships:
  - `pages/faculty/faculty-profile.php` loads publications at L206-L215 using `publication_contributors` for the signed-in user and active publication rows.
  - The same page loads registered IP at L238-L252 using `copyright_contributors`, non-deactivated rows, supported output types, and a registered-status/reference check.
  - New faculty publications are explicitly linked by `includes/publication-archive-form.php` at L135 and `includes/archival-record-form.php` at L130. The profile shows records only when the user is actually linked as an author.
  - New faculty copyright submissions sync the applicant as a contributor in `pages/shared/copyright-form.php` at L394. Staff archive edits use explicit author/contributor links; ownership alone does not create an authorship link.
  - `database/migrations/043_publication_ip_contributors.sql` creates explicit associations, and `database/migrations/055_archive_contributor_storage.sql` explicitly avoids an owner-to-author backfill.
  - `pages/student/my-ip-contributions.php` at L17-L18 has an `applicant_id` fallback for legacy non-copyright IP records; the faculty profile query does not.
- Missing, inferred, stale, or truncated context: The profile has no Graphify coverage. The screenshot confirms an empty-state UI only; account-specific rows/link/status were not inspected. The repository `.env` names `rms_db`; no database query or mutation was run.

## Findings
- The tabs use ordinary page navigation (`?tab=publications` / `?tab=achievements`) and server-side MySQLi queries; they do not fetch asynchronously.
- Publications require an explicit contributor-table link. Older records intentionally are not linked automatically because the project treats ownership and authorship separately.
- Registered IP also requires an explicit contributor link and a registered state. The profile does not use `applicant_id` as a fallback, unlike the student IP-contributions view; this can hide older primary-applicant records that lack contributor rows.
- It is not established whether these conditions explain this particular account's empty result, since the underlying account-specific record rows were not inspected.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant source/Graphify review | Trace queries, association writers, migration behavior, and IP filters | Identify actual query prerequisites and likely data gaps | Findings above, verified against current source | Passed |
| B1 | User browser / faculty profile | Review provided screenshot | Observe rendered empty state only | User screenshot shows the registered IP empty-state message; it does not show underlying data/link state | Passed |

## Failures, decisions, fixes, and retests
- No code or data changes were made; user asked for investigation only.

## Assistant-run checks
- Read-only source inspection only. No database query or application record changes.

## Resume / handoff
- Last user-confirmed state: User requested investigation; previous screenshot showed empty registered IP output state.
- Next pending action: None for source investigation. Account-specific confirmation would require checking the expected record through normal app flow or a separately scoped read-only query against `rms_db`.
- Blocked/skipped checks and remaining verification gaps: Which specific records the faculty account expects to see remains unknown; no account-specific DB rows were read.
- User-performed data changes and cleanup status: None.
