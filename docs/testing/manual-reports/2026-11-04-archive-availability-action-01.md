# Verification: archive availability action

- Date/time: 2026-11-04 04:23 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Confirm what the admin publication availability button does and whether “Archive” accurately labels it.
- Environment URL: Not applicable; source review only.
- Roles and disposable records/files: None.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: Does the publication detail button archive a record or deactivate it? Checked graph labels for `archival-record-detail`, `availability`, and `deactivated_at`; no relevant labels matched.
- Query/lookup and result: No relevant graph node was available, so current source was inspected directly.
- Relevant source locations and verified relationships: `includes/archival-record-detail.php` lines 8–16 validates `deactivate`/`restore` and sets `deactivated_at`; line 78 renders the matching button text. `includes/archival-records.php` lines 79–88 denies deactivation/restoration to non-admin roles.
- Missing, inferred, stale, or truncated context: Graph coverage for this handler is missing; findings were confirmed against current source.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| SRC-1 | Static source review | Trace the admin form action to its POST handler | Confirm whether it changes archival status or availability | Hidden action is `deactivate` or `restore`; handler sets `deactivated_at` to current time or `NULL`, and preserves documents/history | Passed |
| UI-1 | Browser | No browser interaction performed | Not required to confirm source behavior | Skipped; visible behavior was not browser-tested | Skipped |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- Read-only inspection of the form, POST handler, authorization helper, and publication-list link. No application or database changes were made.

## Resume / handoff
- Last user-confirmed state: Not applicable.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: No browser observation; source confirms handler behavior.
- User-performed data changes and cleanup status: None.
