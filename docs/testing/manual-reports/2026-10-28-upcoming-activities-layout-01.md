# Verification: Upcoming activities layout

- Date: 2026-10-28 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: On `pages/shared/activities.php`, the upcoming activity cards display in a compact five-column desktop row like the supplied reference, then reflow to two columns and one column on smaller screens. Activity links and date/time remain readable.
- Environment URL: Not inspected; use the existing local RMS session.
- Roles and disposable records/files: Any authorized signed-in role with access to the shared activities page. No record changes planned.
- Session state: Pending visual retest.

## Graphify and source context

- Question and selected graph vocabulary: How does the shared activities page render upcoming activity cards? Terms: `activities`, `upcoming`.
- Query/lookup and result: `graphify query "activities upcoming" --budget 1200`; returned `activities.php` and helper/permission nodes. No layout/style node was returned, so CSS relationships were confirmed from current source.
- Relevant source locations and verified relationships: `pages/shared/activities.php` loads the upcoming events into `$upcoming` and renders them in `.upcoming-list` at lines 1057-1070; page-local CSS styles `.upcoming-list`, `.upcoming-item`, and responsive breakpoints around lines 744-1008. The activity detail link remains in each card.
- Coverage limitations: Graphify did not return page layout/style relationships. Current source was inspected directly.

## Checklist

| ID | Check | Status | Result |
|---|---|---|---|
| S1 | `php -l pages/shared/activities.php` | Passed | No syntax errors. |
| B1 | Desktop shared activities page shows up to five compact cards in one row with clear spacing between dates and titles | Failed, fix applied; retest pending | After the 8px-to-14px gap increase, the user's screenshot still showed the date and title touching. Changed each card to place its date badge above the activity text. |
| B2 | Narrow viewport reflows cards without horizontal overflow | Pending | User browser observation requested. |

## Observations

- Assistant-run checks: `php -l pages/shared/activities.php` and `git diff --check -- pages/shared/activities.php` passed after the stacked card layout change. The edit changes page-local CSS only; no database or activity records were changed.
- User observations: The user's screenshot after the first spacing adjustment showed the date and title still touching. The date badge now sits above the title and venue.
- Limitations: The updated layout needs a browser retest; narrow viewport behavior remains unobserved.
