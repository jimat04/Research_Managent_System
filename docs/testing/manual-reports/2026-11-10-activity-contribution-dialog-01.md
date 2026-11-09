# Verification: centered contribution prompt in ongoing activity

- Date/time: 2026-11-10 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The existing “Would you be willing to contribute?” dialog on an ongoing activity is centered horizontally and vertically in the viewport on desktop and mobile.
- Environment URL: `http://localhost:8080/rms/` (from the user screenshot; current availability not confirmed)
- Roles and records: An invited activity participant with a pending contribution response; the user's screenshot shows activity detail `id=69`.
- Session state: In progress; assistant checks complete, browser checks awaiting user

## Graphify and source context
- Question and selected graph vocabulary: Trace the activity detail contribution dialog. Selected terms: `activity-detail.php`, `activity_schedule_participants`, `contribution_response`.
- Query/lookup and result: The Graphify CLI was unavailable, so `graphify-out/graph.json` was inspected read-only. Only `activity-detail.php` matched; its adjacent extracted links point to local helper functions. There are no graph nodes for the contribution dialog or schedule participant response relationship.
- Relevant source locations and verified relationships: Current source checks the pending contribution prompt against the signed-in user and participant, renders the contribution dialog with the activity details, and opens it with `showModal()` (`pages/shared/activity-detail.php`, around lines 1158-1180, 1588-1607, 2049-2062). The original `.contribution-dialog` CSS set width and max height but did not set viewport position. The dialog's contribution response still uses its existing participant/user-scoped handler.
- Missing, inferred, stale, or truncated context: No Graphify coverage for the dialog or contribution response helper relationship; these details were verified against current source. The screenshot shows a hosted localhost page; exact served-file identity is not confirmed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant PHP syntax check | Run `php -l` on changed PHP | No syntax errors | `pages/shared/activity-detail.php` and `pages/shared/notifications.php` parse without syntax errors | Passed |
| S2 | Assistant source review | Inspect contribution dialog styles, open/close controls, and ownership checks | Existing behavior remains; modal gets explicit viewport centering | `.contribution-dialog` uses fixed 50% viewport coordinates, translate centering, viewport width, and bounded scrolling. Existing `showModal()`, close buttons, backdrop click, and user/participant checks remain. Earlier notice-viewer additions were removed after the screenshot clarification. | Passed |
| S3 | Assistant diff check | Run `git diff --check` on task files | No whitespace errors | No diff errors; Git emitted line-ending normalization warnings only | Passed |
| B1 | User browser / invited participant / ongoing activity detail | Reopen the contribution dialog at desktop width | Dialog appears centered and the full prompt remains visible | User reported “passed”; no screenshot or additional detail supplied | Passed |
| B2 | User browser / same activity detail | Reopen the dialog at a narrow viewport | Dialog remains centered and fits the screen | Pending | Pending |

## Failures, decisions, fixes, and retests
- The user clarified that the target is the contribution response dialog shown in their screenshot. The earlier inbox and activity-detail budget notice viewer additions were removed; they were based on a mistaken interpretation.

## Assistant-run checks
- `php -l pages/shared/activity-detail.php`: passed.
- `php -l pages/shared/notifications.php`: passed.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/shared/activity-detail.php pages/shared/notifications.php includes/activity-budget.php`: passed; Git emitted line-ending normalization warnings only.
- Source review confirmed the contribution prompt remains scoped to the signed-in invited participant and existing submission/close actions are unchanged.

## Resume / handoff
- Last user-confirmed state: User reports the desktop dialog check passed.
- Next pending action: User checks the contribution dialog at a narrow viewport.
- Blocked/skipped checks and remaining verification gaps: Browser observations and hosted-file identity remain unconfirmed.
