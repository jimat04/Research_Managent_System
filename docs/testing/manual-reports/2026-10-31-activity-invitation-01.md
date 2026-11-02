# Verification: activity invitation response

- Date/time: 2026-10-31 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: An invited participant sees a formal invitation with date, time, venue, and speaker in prose; the page header keeps only the activity title and status. Invitees see accepted names only for dates they are invited to; pending/declined names remain private. Participants can save Yes/No and change the answer until the session starts; attendance remains separate.
- Environment URL: http://localhost/rms/ (guide fallback; actual availability not confirmed)
- Roles and disposable records/files: Invited student/faculty/staff account and an upcoming disposable activity; no credentials recorded
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: Which date-scoped participant responses can be shown to an invited user? Selected graph labels: `activity-detail.php`, `Activity Participants`, `Research Activities and Participants`.
- Query/lookup and result: `graphify query "activity-detail.php Activity Participants Research Activities and Participants" --budget 900`; returned 84 nodes and was truncated. It included the activity detail page and historical data-model references.
- Relevant source locations and verified relationships: `pages/shared/activity-detail.php` reads each date's participant list from `rmsActivityScheduleLoad()` and filters current invitees and accepted responses before rendering names. `includes/activity-schedule.php` loads invitation response fields when the schema is available.
- Missing, inferred, stale, or truncated context: Graphify output was truncated and mostly historical; current relationships were confirmed against `pages/shared/activity-detail.php` and `includes/activity-schedule.php`.
- Follow-up question and selected vocabulary: After a saved RSVP, how is response editing rendered? Selected graph terms: `activity-detail.php`, `activityDetailEscape()`, `activityDetailStatusBadge()`.
- Follow-up query/result: `graphify query "activity-detail.php invitation response" --budget 900`; 72 nodes found, output truncated at 26. Current RSVP rendering and state were verified directly in `pages/shared/activity-detail.php` around lines 947-1008.
- Follow-up limitation: Graph output is broad and truncated, so it provides no reliable detail for this conditional UI. Current source is authoritative for the fix.
- Live-status follow-up: selected Graphify labels `activitiesStatusBadge()` and `activityDetailStatusBadge()`; query `graphify query "activitiesStatusBadge activityDetailStatusBadge" --budget 650` returned 10 nodes and 8 extracted edges without truncation. Source review confirmed both badges previously read the saved activity status, while per-session RSVP deadlines and manager actions use schedule timestamps.
- Attendee-name follow-up: `graphify query "activity-detail.php Activity Participants" --budget 700`; 67 nodes found, truncated at 20. Confirmed against current `pages/shared/activity-detail.php` around lines 1023-1040: the attendee rows include `user_id`, and the page has the authenticated user's role and ID available.
- Spacing follow-up: repeated `graphify query "activity-detail.php Activity Participants" --budget 500`; 67 nodes found, output truncated at 14. Source confirmed the self-marker is rendered in the confirmed-attendee list at line 1039.
- Audience-selection follow-up question and selected terms: Why did a student-only activity audience appear to include other roles? Selected graph vocabulary: `activity-form.php`, `recipientScopeInputs`, `recipientCheckboxes`, `Activity Participants`.
- Follow-up query/result: `graphify query "activity schedule audience recipients invitation participant" --budget 1200`; 25 nodes and 29 edges returned completely. `activity-form.php` contains the date audience inputs and recipient picker; current source confirms recipients are then built in `rmsActivityScheduleValidatePost()` and saved by `rmsActivityScheduleSave()` in `includes/activity-schedule.php`.
- Follow-up limitation: The graph connects to form inputs but does not fully represent the helper's recipient calculation; source was used as authority.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S3 | Assistant static check | `php -l pages/shared/activity-detail.php` | Updated invitation template parses | No syntax errors detected | Passed |
| S4 | Assistant diff check | `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/shared/activity-detail.php docs/testing/manual-reports/2026-10-31-activity-invitation-01.md` | No whitespace errors | No diff-check errors; Git printed a line-ending normalization warning for the working-copy page | Passed |
| S5 | Assistant static check | `php -l pages/shared/activity-detail.php` after invitee header update | Conditional detail header parses | No syntax errors detected | Passed |
| S6 | Assistant diff check | Scoped `git diff --check` after invitee header update | No whitespace errors | No diff-check errors; Git printed a line-ending normalization warning for the working-copy page | Passed |
| S7 | Assistant static check | `php -l pages/shared/activity-detail.php` after confirmed-attendee list update | Accepted-attendee rendering parses | No syntax errors detected | Passed |
| S8 | Assistant diff check | Scoped `git diff --check` after confirmed-attendee list update | No whitespace errors | No diff-check errors; Git printed a line-ending normalization warning for the working-copy page | Passed |
| S9 | Assistant static check | `php -l pages/shared/activity-detail.php` after saved-response control update | Updated RSVP template parses | No syntax errors detected | Passed |
| S10 | Assistant diff check | `git diff --check -- pages/shared/activity-detail.php` | No whitespace errors | No diff-check errors; Git printed line-ending normalization warning | Passed |
| S11 | Assistant static check | `php -l pages/shared/activity-detail.php` after current-faculty name marker | Updated attendee rendering parses | No syntax errors detected | Passed |
| S12 | Assistant diff check | `git diff --check -- pages/shared/activity-detail.php` | No whitespace errors | No diff-check errors; Git printed line-ending normalization warning | Passed |
| S13 | Assistant static check | `php -l pages/shared/activity-detail.php` after marker spacing update | Updated template parses | No syntax errors detected | Passed |
| S14 | Assistant static check | `php -l pages/shared/activity-form.php` after selected-role summary update | Updated form parses | No syntax errors detected | Passed |
| S15 | Assistant diff check | Scoped `git diff --check` for activity form and this report | No whitespace errors | No diff-check errors | Passed |
| S16 | Assistant static check | `php -l includes/activity-schedule.php` after live status helper and schedule timestamps | Helper parses | No syntax errors detected | Passed |
| S17 | Assistant static check | `php -l pages/shared/activity-detail.php` after live detail badges | Detail template parses | No syntax errors detected | Passed |
| S18 | Assistant static check | `php -l pages/shared/activities.php` after live list badges | Activity list parses | No syntax errors detected | Passed |
| S19 | Assistant static check | `node --check js/activity-status-clock.js` | Live clock script parses | No syntax errors reported | Passed |
| S20 | Assistant diff check | Scoped `git diff --check` for live status files | No whitespace errors | No diff-check errors; Git printed line-ending normalization warnings for existing PHP pages | Passed |
| S1 | Assistant static check | `php -l pages/shared/activity-detail.php`; `php -l includes/activity-schedule.php`; `php -l scripts/migrate.php` | All parse successfully | All three reported no syntax errors | Passed |
| S2 | Assistant static check | `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/shared/activity-detail.php includes/activity-schedule.php database/schema/rms_db.sql database/migrations/053_activity_invitation_responses.sql scripts/migrate.php` | No whitespace errors | No diff-check errors; Git printed line-ending normalization warnings for existing working-copy files | Passed |
| B1 | User browser / invited participant / activity notification | Open the invitation from the notification | A formal letter shows date, time, venue, speaker, activity description, and Yes/No response buttons | User screenshot showed the invitation and details but presented Date/Time/Venue/Speaker in a shaded labeled grid; response buttons were outside the screenshot | Failed |
| B1-R | User browser / invited participant / activity notification | Reopen the invitation after the letter layout fix | Date, time, venue, and speaker read naturally in the letter; no labeled details grid; response buttons remain available | Awaiting user observation; migration 053 is applied to local `rms_db` | Pending |
| B4 | User browser / invited participant / activity detail | View the page header above the invitation | Header contains only the activity title and current status | User screenshot showed the title plus duplicate type, date/time, venue, speaker, organizer, and description | Failed |
| B4-R | User browser / invited participant / activity detail | Reopen the invitation after the header update | Header contains only title and status; invitation retains the session details | Awaiting user observation | Pending |
| B5-R | User browser / invited participant / activity detail | Save Yes on an upcoming disposable activity, then review the participant area | The accepted participant's name appears under Confirmed attendees for that date; pending/declined names do not appear | Awaiting user observation; this action saves an RSVP that remains editable until the session starts | Pending |
| B6 | User browser / invited participant / activity detail | After saving Yes, inspect the response area | Saved response remains visible and the answer buttons are hidden behind Change response | User reported the success message appeared but both buttons remained visible | Failed |
| B6-R | User browser / invited participant / activity detail | Reopen/refresh the activity after this UI fix | Saved response remains visible; only Change response is shown until expanded, and Yes/No choices remain available inside it before session start | Awaiting user observation | Pending |
| B7 | User browser / faculty invitee / activity detail | View Confirmed attendees on an activity where the signed-in faculty member has accepted | The faculty member's own linked name ends with “(You)”; other attendees are unchanged | Awaiting user observation | Pending |
| B8 | Assistant read-only local database check / activity #63 | Compare the saved audience scope with invited account-role counts for each scheduled date | Investigate the reported activity | Local `rms_db` showed `audience_scope=specific`, 8 invited accounts per date: 6 students and 2 faculty; this was not the activity in the user's report | Skipped |
| B8-R | User browser / Admin / activity #76 schedule editor | Review the saved audience for October 30, 2026 | Audience is Students and only student accounts are invited | User screenshot shows activity #76 with Students selected; read-only `rms_db` aggregate confirms 6 invited students, 0 faculty, 0 staff | Passed |
| B9 | User browser / invited student / activity detail | View the October 30 session after its end time | Activity and session badges show Past; RSVP remains closed | User screenshot showed Upcoming and Scheduled even though “Responses closed when this session started” appeared | Failed |
| B9-R | User browser / invited student / activity detail | Reload the activity after the live clock update | The October 30 date badge shows Past and the RSVP remains closed | User confirmed the date badge says Past and the response remains closed | Passed |
| B9-R2 | User browser / invited student / activity detail | Check the activity-level badge at the top of the detail page | The activity badge also shows Past | User confirmed it says Past | Passed |
| B2 | User browser / invited participant / activity detail | Choose a response and reopen/refresh the activity | Saved answer is shown and remains editable before session start | Pending; requires B1-R | Pending |
| B3 | User browser / Administrator or authorized activity manager / activity detail | View the invited participant list | RSVP appears separately from attendance | Pending; requires a saved response | Pending |

## Failures, decisions, fixes, and retests
- Initial B1 screenshot exposed the four activity details in a shaded labeled grid. User requested the invitation read as a formal letter; the grid is replaced with prose for the retest.
- The later user screenshot showed the same schedule and description repeated in the top hero. User approved keeping only title/status for invitees; the header is now conditional for invitees while other viewers retain the summary.
- User screenshot showed the generic participant privacy notice below the invitation. User requested showing people who accepted; the new list exposes accepted names only to invitees for that date and retains the privacy notice for other viewers.
- User reported that after saving “Yes, I will attend,” the success text appeared but both response buttons remained visible. Saved Yes/No states now show a collapsed “Change response” disclosure containing those buttons; unanswered invitations still show the choices directly.
- User requested marking the logged-in faculty member in Confirmed attendees. The list now appends “(You)” only when the current role is faculty and the accepted attendee's linked `user_id` matches the authenticated user; other attendee names are unchanged.
- User requested spaces inside the self-marker parentheses; the output is now “( You )”.
- Investigation of activity #63 found its saved audience is `specific`, not `student`; each of its two dates has 8 invited accounts (6 students and 2 faculty). The server creates invitations from the submitted recipient IDs, and the detail page correctly renders rows marked invited. In the user picker, changing the role filter hides unchecked roles but preserves their checked recipients; the old summary reported an overall total without showing the role mix. The edit form now includes a live selected-recipient breakdown by role to expose this before saving.
- Correction after the user supplied the activity #76 screenshot: activity #63 was unrelated to the reported issue and is not treated as a failure. Activity #76 is saved with audience scope `student`; its one date has 6 invited students and no faculty or staff. “Students” means all active student accounts, matching the option description in `pages/shared/activity-form.php`.
- The user agreed the status display should follow the live schedule clock. Added a shared status helper and a 30-second browser refresh for activity detail/list badges: scheduled dates show Upcoming before start, Ongoing during the time window, and Past afterward. Saved Cancelled and Conducted states remain authoritative; the clock display does not mark an activity conducted or alter attendance.
- Two initial read-only aggregate-query invocations failed because of PowerShell/PHP command-line quoting (PHP parse/argument errors). The corrected query succeeded; no source change or database mutation resulted from those failed invocations.

## Assistant-run checks
- Read-only aggregate query against local `rms_db` for activity #76: audience scope `student`; 6 participant rows and 6 invited student accounts; 0 faculty and 0 staff. No records were changed.
- Read-only aggregate query against the verified local `rms_db` for activity #63 returned two dates, each with `audience_scope=specific`, 8 invited accounts (6 students and 2 faculty). The config bootstrap emitted nonfatal CLI session-directory permission warnings; the read-only query still succeeded. No records were changed.
- `php -l pages/shared/activity-form.php` after the selected-recipient role summary update: passed.
- Scoped `git diff --check -- pages/shared/activity-form.php docs/testing/manual-reports/2026-10-31-activity-invitation-01.md`: passed with no whitespace errors.
- `php -l includes/activity-schedule.php`, `php -l pages/shared/activity-detail.php`, `php -l pages/shared/activities.php`, `node --check js/activity-status-clock.js`, and scoped `git diff --check` for the live-status changes: all passed; Git printed only the existing LF-to-CRLF normalization warnings.
- `php -l pages/shared/activity-detail.php` after the formal-letter layout update — passed.
- Scoped `git diff --check` for the changed page and this report — passed with no whitespace errors; Git emitted an LF-to-CRLF normalization warning.
- `php -l pages/shared/activity-detail.php` — passed.
- `php -l includes/activity-schedule.php` — passed.
- `php -l scripts/migrate.php` — passed.
- Scoped `git diff --check` — passed with no whitespace errors; Git emitted LF-to-CRLF normalization warnings.
- `php scripts/migrate.php apply 053_activity_invitation_responses.sql` — applied through the project runner to `localhost` / `rms_db`; the runner's post-apply verifier passed.
- `php scripts/migrate.php status` — migrations 034, 037, and 053 are recorded applied; 053 is `executed`.
- Read-only aggregate of `activity_schedule_participants` — 111 rows total, 111 `pending`, 0 response timestamps.

## Resume / handoff
- Last user-confirmed state: The invited student confirmed both the October 30 date badge and the activity-level badge say Past; the RSVP remains closed.
- Next pending action: The earlier RSVP-control retest remains pending.
- Current status follow-up: Reload the activity detail page and confirm its parent and session badges now show Past for the October 30 session. No activity data or status was changed by the live clock display.
- Blocked/skipped checks and remaining verification gaps: Letter layout/header retest, accepted-name display, response editing, and manager visibility remain unverified.
- User-performed data changes and cleanup status: None. Codex applied migration 053 to local `rms_db`; the additive columns defaulted all 111 existing participant rows to pending and added no response timestamps.
