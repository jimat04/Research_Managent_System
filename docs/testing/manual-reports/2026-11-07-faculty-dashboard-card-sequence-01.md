# Verification: Faculty dashboard card sequence

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Faculty dashboard metric cards follow the faculty sidebar section order for available metrics.
- Environment URL: http://localhost/rms/ (configured fallback; not confirmed running)
- Roles and disposable records/files: Faculty role; no records changed.
- Session state: finished for desktop; mobile viewport not confirmed

## Graphify and source context
- Question and selected graph vocabulary: How does the faculty dashboard reach its shell and overview? Selected graph terms: `faculty-dashboard.php`, `faculty-shell.php`.
- Query/lookup and result: `graphify query "faculty dashboard" --budget 1500` (repeated for the icon adjustment); 18 nodes found. Graph confirms the faculty dashboard page and its faculty shell relationship.
- Relevant source locations and verified relationships: `pages/faculty/faculty-dashboard.php` loads `includes/faculty-dashboard/overview.php` and renders via `includes/faculty-shell.php`; current `includes/faculty-shell.php:117-149` orders sidebar sections as Research Portfolio, Document Center, IP Applications, Publications, Activities, Profile & Settings, Advising & Engagement. The available dashboard metrics now follow the corresponding sections: Active Research Records, Publication Records, Upcoming Activities, Advising Projects, Verified Engagements.
- Missing, inferred, stale, or truncated context: Graph query did not include the overview template or dashboard service; confirmed their relationship by current source inspection. No useful graph node was found for the card template itself.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | `php -l includes/faculty-dashboard/overview.php` | PHP syntax is valid | `
tk php -l returned ok` | Passed |
| B1 | User-provided screenshot / faculty / Dashboard | Inspect metric card order | Cards appear as Active Research Records, Publication Records, Upcoming Activities, Advising Projects, Verified Engagements | Screenshot shows the requested order; first and third cards lack icons and are shorter | Passed |
| B2 | User-provided screenshot / faculty / Dashboard | Inspect card heights after adding icons | All cards have matching heights within each row | First and third cards are about 250 px high; Publication Records is about 220 px; lower row cards match at about 222 px | Failed |
| B3 | User browser / faculty / Dashboard / desktop | Refresh the dashboard and inspect all metric card heights | Cards have matching heights within each row | User confirmed: all passed | Passed |
| B4 | User browser / faculty / Dashboard / narrow viewport | Inspect card sizing and overflow at mobile width | Cards remain readable, consistently sized, and do not overflow | Not observed | Skipped: desktop retest confirmed; no mobile observation provided |

## Failures, decisions, fixes, and retests
- B2 failed on the user-provided screenshot. Investigated and fixed: matched the supplemental card content wrappers to the other metrics and made stat cards fill their grid rows. B3 passed on the desktop retest.

## Assistant-run checks
- `rtk php -l includes/faculty-dashboard/overview.php` returned `ok` after the height adjustment.
- No database changes or application records were made.

## Resume / handoff
- Last user-confirmed state: Desktop dashboard card heights passed.
- Next pending action: None for the requested desktop layout.
- Blocked/skipped checks and remaining verification gaps: Mobile viewport behavior remains unverified and is recorded as skipped.
- User-performed data changes and cleanup status: None.

