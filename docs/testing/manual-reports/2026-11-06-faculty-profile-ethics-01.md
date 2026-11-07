# Verification: faculty profile ethics tab removal

- Date/time: 2026-11-06 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Remove the Ethics & compliance tab and panel from the faculty profile. Requests for the removed `ethics` tab resolve to the overview.
- Environment URL: Local RMS; browser URL to use is the current host's `/rms/pages/faculty/faculty-profile.php?tab=ethics`.
- Roles and disposable records/files: Faculty role; no records or files changed.
- Session state: Finished (completed 2026-11-07, Asia/Taipei)

## Graphify and source context
- Question and selected graph vocabulary: How is the faculty profile tab selected and rendered? Selected terms: `faculty`, `profile`, `ethics`.
- Query/lookup and result: `graphify query "faculty-profile.php ethics" --budget 1500 --context_filter call` and `graphify query "faculty-profile.php" --budget 1200`. The graph returned shared `profile.php`, faculty shell/dashboard and generic ethics manual nodes; it has no node for `pages/faculty/faculty-profile.php`. The result is not useful for this page-specific behavior.
- Relevant source locations and verified relationships: `pages/faculty/faculty-profile.php` reads `$_GET['tab']`, validates against `$allowedTabs` (original line 52), builds `$tabLinks` (original line 290), and renders the achievements/ethics branches (original lines 600-674). Current source confirms invalid tabs fall back to `overview`.
- Missing, inferred, stale, or truncated context: Graph coverage for this page is missing; source inspection was used. First broad graph query returned more nodes/edges than its requested budget.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l pages/faculty/faculty-profile.php`. | PHP syntax is valid. | `No syntax errors detected in pages/faculty/faculty-profile.php`. | Passed |
| S2 | Assistant source review | Search the faculty profile for ethics/compliance labels and inspect the focused diff. | No ethics tab or panel remains; achievements still renders as the last allowed tab. | No `ethics` or `compliance` matches remain in the page; focused diff removes the tab, allowlist entry, and panel. | Passed |
| S3 | Faculty / profile page | Open `/rms/pages/faculty/faculty-profile.php?tab=ethics` while signed in as faculty. | The profile shows the overview; no Ethics & compliance tab or panel appears. | User confirmed all checks passed. | Passed |

## Failures, decisions, fixes, and retests
- No application failures reported.

## Assistant-run checks
- `php -l pages/faculty/faculty-profile.php`: passed.
- `git diff --check -- pages/faculty/faculty-profile.php`: passed with no whitespace errors; Git emitted a line-ending conversion warning.
- A repository-wide `git diff --check` encountered access-denied messages for protected `storage/earist` files. It was narrowed to the changed PHP file; unrelated files were left untouched.

## Resume / handoff
- Last user-confirmed state: User confirmed all checks passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported.
- User-performed data changes and cleanup status: None.
