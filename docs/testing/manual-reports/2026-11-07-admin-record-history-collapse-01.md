# Verification: collapsible archive record history

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The `#history` card starts collapsed, shows the record history title and entry count, and opens to reveal audit events and the existing per-event disclosures.
- Environment URL: `pages/admin/admin-publication-detail.php?id=43#history` (from the supplied screenshot; use the local RMS host)
- Roles and disposable records/files: Admin; read-only page inspection, no record changes.
- Session state: Complete; user confirmed all browser checks passed.

## Graphify and source context
- Question and selected graph vocabulary: Find the shared archival record history rendering; selected `archiveQuery`, `archival-record-detail`, `history`.
- Query/lookup and result: `rtk graphify query "archiveQuery" --budget 1500` returned no matching nodes. A broader `archival record history` query returned unrelated migration/login nodes. Targeted current source inspection confirmed the live relationship.
- Relevant source locations and verified relationships: `pages/admin/admin-publication-detail.php:1-4` sets publication detail mode and includes `includes/archival-record-page.php`; `includes/archival-record-detail.php:85-99` renders the private `#history` card, event details, paging links, and historical notifications. The existing authorization condition around the card is unchanged. The card now wraps this content in a native closed `<details>` disclosure; `css/archival-records.css:459-514` styles the summary, count, indicator, event rows, and reduced-motion behavior.
- Missing, inferred, stale, or truncated context: Graphify has no matching current archive-detail node; its broad query returned unrelated nodes. Source is authoritative for this change.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant command | Run `php -l includes/archival-record-detail.php` | No PHP syntax errors | `No syntax errors detected` (`rtk php -l`) | Passed |
| S2 | Assistant source review | Inspect focused diff | The history anchor and visibility guard remain, and all audit content is inside the closed disclosure | Current diff and source lines 85-99 | Passed |
| B1 | Browser / admin / publication #43 detail | Open or reload the page at `#history` | The card shows “Record history” and its count; event rows are hidden until expanded | User confirmed all checks passed | Passed |
| B2 | Browser / admin / publication #43 detail | Expand Record history | Audit entries and their individual disclosure controls appear | User confirmed all checks passed | Passed |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `rtk php -l includes/archival-record-detail.php` — passed.
- No application data was changed. No browser interaction was run by the assistant.

## Resume / handoff
- Last user-confirmed state: User reported that all checks passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported.
- User-performed data changes and cleanup status: None.
