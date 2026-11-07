# Verification: activity source links

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Optional colloquium-to-student-research and conference-to-faculty-publication links; preserve them across edit and activity status changes.
- Environment URL: Not checked.
- Roles and disposable records/files: Admin activity manager; existing eligible archive records. No records changed by the assistant.
- Session state: Finished; migration applied and user reported all browser checks passed.

## Graphify and source context

- Question and selected graph vocabulary: Trace `activities.php` to source archive records; selected `activities.php`, `Publications`, and `Research Projects`.
- Query/lookup and result: `graphify query 'activities.php Publications Research Projects' --budget 1500` was truncated at 42 of 53 nodes; narrowed with `graphify query 'activities.php' --budget 1800`, which returned 16 nodes and no extracted source-record edge.
- Relevant source locations and verified relationships: `pages/shared/activity-form.php` loads archive-eligible choices for users with the relevant archive view permission; `includes/activity-schedule.php` validates archive eligibility and access before storing source IDs in the activity save transaction; `pages/shared/activity-detail.php` loads and displays the linked record only where archive access allows it. Faculty research visibility uses the assigned-adviser check from the existing research-detail access rule.
- Missing, inferred, stale, or truncated context: The broad query was truncated; graph does not encode the new relationship. Findings were confirmed against current source. No graph files were intentionally updated.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant CLI; changed PHP files | Run `php -l` on activity form, activity detail, activity schedule helper, and migration runner | No PHP syntax errors | All four commands reported no syntax errors. | Passed |
| S2 | Assistant CLI; changed tracked files | Run `git diff --check` on the focused PHP, schema, and migration-runner paths | No whitespace errors | No diff-check errors. Existing unrelated worktree changes were preserved. | Passed |
| S3 | Assistant CLI; verified local `rms_db` | Apply `071_activity_source_links.sql` with `scripts/migrate.php` | Both nullable source links, indexes, and foreign keys are installed and recorded | Target preflight confirmed configured and connected database `rms_db`; runner reported `APPLIED 071_activity_source_links.sql` after its registered schema verifier passed. Migration contains schema-only changes; no activity or archive rows were modified. | Passed |
| B1 | Admin; activity creation form | Open the colloquium form and inspect the optional Archived research picker and its options | Picker is optional and lists eligible completed/archived student research | User reported all browser checks passed. | Passed |
| B2 | Admin; activity creation form | Open the conference form and inspect the optional Archived publication picker and its options | Picker is optional and lists active published/archived faculty publications | User reported all browser checks passed. | Passed |
| B3 | Admin; activity form and detail page | Save an activity with and without a source, reopen it, and change its status | Link persists when selected; blank remains valid; detail page shows the linked record | User reported all browser checks passed. | Passed |
| B4 | Admin; activity form | Change type after choosing a source and attempt a mismatched source ID | Incompatible links clear; server rejects an ineligible or mismatched record | User reported all browser checks passed. | Passed |

## Failures, decisions, fixes, and retests

- No failures observed. The assistant applied the approved additive migration to verified local `rms_db`; no activity or archive rows were created or modified.

## Assistant-run checks

- `php -l pages/shared/activity-form.php` — passed.
- `php -l pages/shared/activity-detail.php` — passed.
- `php -l includes/activity-schedule.php` — passed.
- `php -l scripts/migrate.php` — passed.
- `git diff --check` on focused tracked paths — passed.
- `php scripts/migrate.php apply 071_activity_source_links.sql` — reported applied after the registered schema verifier passed; target preflight confirmed `rms_db`.
- Browser interactions were not performed.

## Resume / handoff

- Last user-confirmed state: User reported all browser checks passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported.
- User-performed data changes and cleanup status: User reported all browser checks passed; specific disposable record identifiers and cleanup status were not provided.
