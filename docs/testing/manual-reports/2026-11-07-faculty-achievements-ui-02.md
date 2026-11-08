# Verification: Faculty achievements UI

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The faculty profile tab is labeled “Registered IP”; its accomplishments/statistics summary is removed and the registered output list remains, including patents, utility models, innovations, and technology transfers.
- Environment URL: `http://localhost:8080/rms/pages/faculty/faculty-profile.php?tab=achievements`
- Roles and disposable records/files: Faculty role; no records changed.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: Find the faculty achievements control and registered output section; selected existing terms `copyright`, `registered`, and `output`.
- Query/lookup and result: `graphify-out/graph.json` was inspected read-only because the installed Graphify CLI does not provide the documented `query` command. Matching nodes include `copyright-detail.php` (`pages/shared/copyright-detail.php`, L1), `copyrights.php` (`pages/shared/copyrights.php`, L1), and `copyright_applications` (`database/schema/rms_db.sql`, L660). No node covers `pages/faculty/faculty-profile.php`.
- Relevant source locations and verified relationships: `pages/faculty/faculty-profile.php` checks for the copyright table at L98, loads the registered output count and rows at L238-L244, defines the profile tab labels near L283-L287, and renders the registered output section in the achievements branch near L534-L555. Current source confirms the registered-output filters include patents, utility models, innovations, and technology transfers while retaining contributor ownership and registered-status checks.
- Missing, inferred, stale, or truncated context: The graph has no faculty profile page coverage; current source was used to verify the change. No graph files were intentionally edited.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l pages/faculty/faculty-profile.php` | No PHP syntax errors | `No syntax errors detected` after the short-label edit | Passed |
| B1 | User browser / faculty / achievements tab | Previous check from the superseded interpretation | Old card label and in-page jump | Superseded by the user's clarification; not performed | Skipped |
| B2 | User browser / faculty / registered IP tab | Previous check from the prior label | Long descriptive tab label; accomplishments summary removed and registered outputs shown | User screenshot shows the tab with the long label and the updated content layout | Passed |
| B3 | User browser / faculty / registered IP tab | Reload/open the target URL | Tab label reads “Registered IP”; registered outputs heading/list remains | User confirmed all checks passed | Passed |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- `rtk php -l pages/faculty/faculty-profile.php` — passed after the short-label edit. No database or application records were changed.

## Resume / handoff
- Last user-confirmed state: User confirmed all checks passed after reloading the short-label version.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: The screenshot did not establish actual category records; no data records were changed.
- User-performed data changes and cleanup status: None.
