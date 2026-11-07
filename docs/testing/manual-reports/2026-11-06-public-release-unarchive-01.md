# Verification: public release unarchive

- Date/time: 2026-11-06 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: An admin sees an Unarchive action beside Save release decision for an archived source whose release status is Needs review. Using it restores the source record without making a public release decision.
- Environment URL: Not established
- Roles and disposable records/files: Admin; no record changed during assistant checks
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: Trace the public release queue and archived source restore behavior; selected `archive` and `project` from graph node labels.
- Query/lookup and result: `graphify query 'release archive project' --budget 1500` returned 11 nodes, including archive helpers and `research_projects`, but no page-specific public release node. For the visual retest, `graphify query 'release archive admin' --budget 1000` returned 20 nodes but exceeded the requested budget and its output was truncated; it did not establish a relationship to this page's action styling.
- Relevant source locations and verified relationships: `pages/admin/admin-public-releases.php` checks admin role and CSRF, lists the three source types, and derives Needs review from a missing release row. `pages/admin/admin-research-management.php` restores archived projects to ongoing only with 1–4 approved chapters. `includes/faculty-research-records.php` restores archived faculty records to active. `database/schema/rms_db.sql` confirms publication archival states `legacy`/`archived` and faculty record states `active`/`archived`.
- Missing, inferred, stale, or truncated context: Graphify has no relevant public-release page node; source review was used to confirm current behavior. Query output was not truncated.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `rtk proxy php -l pages/admin/admin-public-releases.php` | PHP syntax is valid | `No syntax errors detected` | Passed |
| S2 | Assistant static check | Run `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/admin/admin-public-releases.php` | No whitespace errors | No diff-check errors; Git emitted a line-ending notice | Passed |
| S3 | Assistant static check | Count braces in `pages/admin/admin-public-releases.php` | Embedded PHP/CSS braces are balanced | `PHP/CSS braces: 117/117` | Passed |
| B1 | User browser / admin / Public Research Releases | Open the page and inspect a Needs review card whose source status is Archived | Unarchive appears beside Save release decision | User confirmed the button is beside Save release decision | Passed |
| B2 | User browser / admin / Public Research Releases | Submit Unarchive for an archived Needs review record | The source is restored and the page confirms no public release decision was made | Not attempted; no disposable target was identified | Blocked |
| B3 | User browser / admin / Public Research Releases | Inspect the two actions on desktop | Buttons have visible spacing and Unarchive has the admin orange treatment | User confirmed both look right | Passed |
| B4 | User browser / admin / Public Research Releases | Narrow the viewport and inspect the two actions | Buttons stack vertically and remain readable | User confirmed they stack and remain readable | Passed |

## Failures, decisions, fixes, and retests
- User reported that the two buttons were too close and Unarchive appeared plain white, and asked for the styling fix. Codex added a 10px gap, an orange Unarchive style with hover/focus states, and a stacked narrow-screen layout. PHP lint, diff check, and embedded CSS brace count passed after the change.

## Assistant-run checks
- The unarchive handler uses CSRF, an admin-only page guard, prepared statements, source-state checks, an activity audit, and a transaction. It preserves the missing release row, so restoring a source does not publish or withdraw it.
- Project restoration repeats the existing 1–4 approved chapter rule and excludes deleted chapters. Other source restores require the current archived state.
- No database or application records were changed by assistant checks.

## Resume / handoff
- Last user-confirmed state: On Public Research Releases, the Unarchive button appears beside Save release decision for an Archived record marked Needs review; user then reported that the buttons were too close and Unarchive was plain white.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: The unarchive action's result and persistence were not browser-tested because no disposable target was identified.
- User-performed data changes and cleanup status: None.
