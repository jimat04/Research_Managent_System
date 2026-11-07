# Verification: invited activity access

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Students and faculty can browse only activities for which they have an active invitation record (`is_invited = 1`) and cannot open other activity details directly. Admin and staff permissions remain unchanged.
- Environment URL: Not recorded; the configured application URL was not inspected.
- Roles and disposable records/files: Student and faculty roles; no record identifiers or credentials recorded.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How the shared activity directory/detail pages connect to authentication and invitation data. `activities.php`, `activity-detail.php`, `hasModulePermission`, `activity_schedule_participants`.
- Query/lookup and result: Read-only `graphify query "activities.php activity-detail.php hasModulePermission activity_schedule_participants" --budget 2200`; 90 nodes found, output truncated at 61. A follow-up query with `rmsActivityScheduleUserInvited` found 50 nodes and truncated at 43. Current source was used to confirm the relevant relationships.
- Relevant source locations and verified relationships: `pages/shared/activities.php` links both student/faculty role directories to shared activity listing and previously required admin/staff module permission; `includes/auth.php:504` grants module access from the staff permission matrix; `includes/activity-schedule.php` stores per-date invitation rows; `pages/shared/activity-detail.php` previously restricted detail permissions to staff but did not require invitation ownership for student/faculty. A follow-up Graphify lookup for `activity-document.php`, `rmsActivityDocumentUserIsInvited`, `save_invitation_response`, and `activity_schedule_participants` returned 63 nodes, truncated at 43; direct source review confirmed the attachment endpoint already checks invite ownership and the response helper validates the invited user.
- Missing, inferred, stale, or truncated context: Graph output was truncated; graph hints were confirmed against the current source. Graph locations for newly added helpers do not exist in the map.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l` on the three changed PHP files | All files parse without syntax errors | `includes/activity-schedule.php`, `pages/shared/activities.php`, and `pages/shared/activity-detail.php` each reported no syntax errors | Passed |
| B1 | User browser / student / shared activities | Open Research Activities from the student navigation | Directory loads instead of showing 403 | User reported “all passed” | Passed |
| B2 | User browser / student / shared activities | Inspect the directory with known invited and uninvited disposable activities | Invited activity appears; uninvited activity is absent | User reported “all passed” | Passed |
| B3 | User browser / student / activity detail | Open a known invited activity from the directory | Invited detail opens | User reported “all passed” | Passed |
| B4 | User browser / student / direct activity detail | Open the normal detail link for a known uninvited activity | Existing 403 page appears | User reported “all passed” | Passed |
| B5 | User browser / faculty / shared activities | Open Research Activities from the faculty navigation | Directory loads instead of showing 403; invited activity appears | User reported “all passed” | Passed |
| B6 | User browser / faculty / direct activity detail | Open the normal detail link for a known uninvited activity | Existing 403 page appears | User reported “all passed” | Passed |
| B7 | User browser / admin and authorized staff | Open the activity directory under existing permissions | Existing admin/staff access behavior is preserved | User reported “all passed” | Passed |

## Failures, decisions, fixes, and retests
- Initial failure reported by user: student and faculty Research Activities routes show 403.
- Decision: expose only invited activities to students and faculty, based on `activity_schedule_participants.user_id` plus `is_invited = 1`, regardless of invitation response.
- Fix: implemented invitation-scoped directory queries and direct-detail authorization. User confirmed all pending browser checks passed.

## Assistant-run checks
- Graphify read-only lookup completed as described above; truncated graph output was supplemented with targeted source inspection.
- `rtk proxy php -l includes/activity-schedule.php`; `rtk proxy php -l pages/shared/activities.php`; `rtk proxy php -l pages/shared/activity-detail.php` — all passed with no syntax errors.
- `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check -- includes/activity-schedule.php pages/shared/activities.php pages/shared/activity-detail.php docs/testing/manual-reports/2026-11-07-activity-access-01.md` — no whitespace errors reported; Git emitted existing LF-to-CRLF conversion warnings for the two page files.

## Resume / handoff
- Last user-confirmed state: User reported “all passed” for the pending browser checks.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported. Individual record identifiers and per-role details were not provided.
- User-performed data changes and cleanup status: None reported.
