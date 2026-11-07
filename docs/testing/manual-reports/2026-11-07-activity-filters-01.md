# Verification: activity filters

- Date/time: 2026-11-07 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Activity type choices remain selectable; Status is available on all directory views; explicit status filters override preset status views; type and status filters combine in the activity list and count; selecting another type does not cause an incorrect 403.
- Environment URL: Not confirmed; use the local RMS Activities page.
- Roles and disposable records/files: Admin and research staff; no record changes required.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How does the activity directory connect its prepared query helper and staff permissions? `activities.php`, `activitiesRun()`, `hasModulePermission()`.
- Query/lookup and result: `rtk graphify query "activities.php capacityPage requestedActivityType" --budget 1500`; Graphify returned the activity page, query helper, and staff permission helper, but no direct node for the capacity context variable.
- Relevant source locations and verified relationships: Current `pages/shared/activities.php` checks capacity context compatibility near the request setup, builds shared `$whereSql`, executes count and list queries, and calls `hasModulePermission()` to limit staff filter options; `includes/auth.php` defines module permissions. Current source confirms these relationships.
- Missing, inferred, stale, or truncated context: Graphify source offsets are stale for the edited page, and its query does not model the capacity-context guard directly; source was checked. The earlier lookup output was truncated (43 of 60 nodes).

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant / PHP syntax | Run `rtk proxy php -l pages/shared/activities.php` | No syntax errors | `No syntax errors detected in pages/shared/activities.php` | Passed |
| A2 | Assistant / diff hygiene | Run `git diff --check -- pages/shared/activities.php` | No whitespace errors | Exit succeeded; Git reported only its existing LF-to-CRLF normalization notice | Passed |
| U1 | Admin / Activities directory, Upcoming view | Open the view and inspect the Type and Status controls | Both controls are visible; Type offers all types; Status offers All statuses and the four statuses | User confirmed all pending checks passed; no per-check details supplied | Passed |
| U2 | User-reported / activity directory | Apply a different Type and Status combination | The directory loads and results match both filters | User reports the request went to 403; screenshot showed Type `Trainings`, Status `All statuses`, and the 403 page | Failed |
| U2R | Same role / activity directory context that produced U2 | Return to the originating activity directory and repeat the different-type selection with All statuses | The request stays on the activity directory and results follow the selected type/status; admin module context follows the selected type | User confirmed all pending checks passed; no per-check details supplied | Passed |
| U3 | Research staff / capacity module and cross-type activity view | Inspect available types in a typed capacity context and in a cross-type view | Options match view permissions and capacity context; selecting an available type does not produce a 403 | User confirmed all pending checks passed; no per-check details supplied | Passed |

## Failures, decisions, fixes, and retests
- Initial failure: the user reported that applying a different type/status combination led to 403 and supplied a screenshot showing `Trainings`, `All statuses`, then the 403 page.
- Decision: user explicitly requested investigation and a fix.
- Fix: when an administrator changes to a type incompatible with a submitted capacity-module context, the page discards that old context and derives the selected type's context where applicable. Non-admin compatibility and permission checks remain enforced. The user confirmed the retest and all other pending browser checks passed.

## Assistant-run checks
- `rtk graphify query "activities.php capacityPage requestedActivityType" --budget 1500` — completed; no direct capacity-context node was found, so the current guard and form were inspected in source.
- `rtk proxy php -l pages/shared/activities.php` — passed.
- `git diff --check -- pages/shared/activities.php` — passed, with a line-ending normalization notice.

## Resume / handoff
- Last user-confirmed state: User reported that all pending browser checks passed. The screenshot from the original failure did not identify the role or originating module.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported. Individual browser observations were not provided beyond the user's confirmation that all checks passed.
- User-performed data changes and cleanup status: None.
