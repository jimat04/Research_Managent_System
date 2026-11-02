# Verification: Chapter review attribution

- Date/time: 2026-10-24 (Asia/Taipei)
- Assistant: GitHub Copilot
- Target and expected behavior: Approval and return confirmations identify the reviewer and the exact submission version; the review history remains associated with that version.
- Environment URL: http://localhost/rms/
- Roles and disposable records/files: Authorized administrator or research staff; user confirmed a disposable chapter submission is available.
- Session state: In progress; browser verification pending.

## Graphify and source context
- Question and selected graph vocabulary: How chapter approval and return decisions retain and display reviewer attribution. Selected terms: Reviewer Decision History, Review History, Submission Versions and Reviews, Returned for Revision.
- Query/lookup and result: Ran `graphify query "How are chapter review approvals and returns persisted and displayed, including reviewer attribution?"` and a focused follow-up using submission-review/version terms. The graph returned review-history and submission-version documentation nodes but did not expose useful implementation relationships; Graphify results are treated as navigation hints only.
- Relevant source locations and verified relationships: `includes/research-workflow.php:272` authorizes review decisions and `:332-340` inserts `reviewer_id` with the decision into review history for the specific `version_id`. `database/schema/rms_db.sql:592-603` and migration `database/migrations/017_research_submission_versions.sql:22-33` define that per-version reviewer record. `pages/shared/research-submission-review.php:109-136` adds reviewer name, role, and version to the immediate confirmation; `:231-235` joins review history to the reviewer; `:560` displays reviewer and decision in the corresponding version history.
- Missing, inferred, stale, or truncated context: The existing graph did not capture useful code-level links for this path. Current source was used to verify the storage and display relationships. No browser behavior has yet been confirmed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Static source review | Confirm the versioned review record stores the reviewer and the UI labels the confirmation/history | Reviewer identity remains tied to the reviewed version | Verified in current helper, schema/migration, and review page source | Passed |
| A2 | Browser / authorized reviewer / Research Review | Locate the disposable submitted Chapter 2 record; do not decide yet | The submitted version and its review controls are visible | User confirmed the submission is disposable; record has not yet been located in the browser | Pending |

## Failures, decisions, fixes, and retests
- Baseline observation supplied by the user: the previous confirmation read “Chapter 2 returned for revision.” and did not identify the reviewer.

## Assistant-run checks
- `php -l pages/shared/research-submission-review.php` — passed.
- `git --no-pager diff --check -- pages/shared/research-submission-review.php` — passed.
- An unscoped `git --no-pager diff --check` reported trailing whitespace in the pre-existing `public/login.php` change. That file is outside this task and was not modified.
- No browser interaction, database operation, or migration was run by the assistant.

## Resume / handoff
- Last user-confirmed state: The user previously saw the generic Chapter 2 return confirmation before this change.
- Next pending action: User locates the disposable submitted Chapter 2 record; after confirmation, guide the review form and decision as separate browser actions.
- Blocked/skipped checks and remaining verification gaps: Updated confirmation behavior is not yet user-confirmed in the integrated browser.
- User-performed data changes and cleanup status: No new changes performed during this implementation; no cleanup needed.
