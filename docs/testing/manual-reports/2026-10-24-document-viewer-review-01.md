# Verification: document viewer review

- Date/time: 2026-10-24 (Asia/Taipei)
- Assistant: GitHub Copilot
- Target and expected behavior: An authorized reviewer can enter separate review comments and recommendation and approve or return the exact latest submitted version from its document viewer. Review results remain attached to that version and visible in history. On desktop, the document is on the left and review controls are on the right; narrow screens stack document first, then controls.
- Environment URL: http://localhost/rms/pages/shared/download-research-document.php?type=research&id=88&mode=viewer
- Roles and disposable records/files: Admin reviewer and student submitter; user reports testing project 2 and a Chapter 2 resubmission. This report does not include credentials.
- Session state: Viewer review feature and layout implementation complete; user-operated layout and workflow verification pending.

## Graphify and source context
- Question and selected graph vocabulary: How a viewed upload maps to a submitted version and review history. Selected graph vocabulary: `download-research-document.php`, `Submitted`, `version`, `comments`, `Review History`.
- Query/lookup and result: Queried the existing graph with `graphify query "download-research-document current submitted version review comments recommendation" --budget 1200`. It located the document viewer and submission/review vocabulary, but the traversal was truncated. The existing map was not rebuilt.
- Relevant source locations and verified relationships: `pages/shared/download-research-document.php` resolves a viewer upload through `research_submission_version_uploads` to a current latest version whose status is `submitted`; its POST action uses that resolved version ID rather than trusting a client-supplied ID. `includes/research-workflow.php` (`rms_submission_can_review()` and `rms_review_submission()`) enforces the existing admin/staff decision permissions and current-version/status checks, then stores the decision, comments, recommendation, and reviewer. `pages/shared/research-submission-review.php`, `pages/shared/research-detail.php`, `pages/student/research-detail.php`, `pages/researcher/submit-chapter.php`, `pages/researcher/submit-final-research.php`, and `pages/researcher/my-research.php` display recommendation data with the corresponding review record. `database/migrations/027_submission_review_recommendation.sql` adds a nullable `recommendation` field, and `database/schema/rms_db.sql` includes it in the canonical schema. `scripts/migrate.php` has a verifier for the new column.
- Missing, inferred, stale, or truncated context: Graph traversal was truncated and includes stale older code locations; all relevant relationships were verified against current source. The database schema and browser behavior have not been confirmed against the active local database.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l` on all changed PHP files | No syntax errors | All nine PHP files passed | Passed |
| S2 | Assistant static check | Run scoped `git diff --check` on feature files | No whitespace errors | Passed; only line-ending normalization warnings appeared | Passed |
| D1 | Local development database | Verify migration state and apply migration 027 through the migration runner | `research_submission_reviews.recommendation` exists as nullable TEXT and migration ledger records the migration | User authorized local `rms_db` only. Configured/connected DB name confirmed `rms_db`; migration 017 was applied, 027 pending before. `php scripts/migrate.php apply 027_submission_review_recommendation.sql` reported APPLIED; post-check reports 027 `applied / executed` | Passed |
| B1 | Admin / document viewer | Refresh the Chapter 2 submitted PDF viewer; do not submit a decision yet | Viewer shows the exact submitted version plus separate comments/recommendation fields and authorized Approve/Return controls; PDF remains viewable | Pending user-operated browser confirmation | Pending |
| B2 | Authorized admin / document viewer | Enter comments and recommendation and return the disposable submitted version | Decision succeeds once, returns to the same viewer, and reports decision/version; researcher history shows the saved reviewer, comments, and recommendation | Pending; dependent on D1 and the user's authorized browser action | Blocked |
| B3 | Admin / document viewer | Refresh the document viewer after the layout update | At desktop width, document preview is on the left and review panel on the right; at narrow width, document precedes review panel in a stacked layout | User reported viewer width about 1604px and requested the document left/review panel right. Updated source layout is awaiting browser confirmation | Pending |

## Failures, decisions, fixes, and retests
- User requested reviewer comments and a separate recommendation beside the document viewer, with the review decision submitted together.
- Implemented version-specific review controls. Review permissions continue to use the existing `rms_submission_can_review()` checks. Student and unauthorized viewer pages remain view-only.
- Added the nullable recommendation column migration and updated the canonical schema, existing decision/review-history UI, and migration verifier.
- User authorized inspection and applying the migration only to local `rms_db`. Assistant verified the configured and connected target was `rms_db`, verified migration 017 applied and 027 pending, then used the explicit migration runner for 027. Post-status reports 027 `applied / executed`. No browser interaction was performed by the assistant.
- User requested moving the review panel to the right and keeping the document on the left. Updated the document viewer to a responsive two-column layout; the toolbar remains full-width, and the narrow-screen layout stacks the document before the review panel. Review behavior, permission checks, and database behavior were not changed by this layout update.

## Assistant-run checks
- `php -l` on `includes/research-workflow.php`, `pages/researcher/my-research.php`, `pages/researcher/submit-chapter.php`, `pages/researcher/submit-final-research.php`, `pages/shared/download-research-document.php`, `pages/shared/research-detail.php`, `pages/shared/research-submission-review.php`, `pages/student/research-detail.php`, and `scripts/migrate.php` — all passed.
- `git diff --check` scoped to feature files — passed; Git emitted only line-ending normalization warnings.
- For the responsive layout update: `php -l pages/shared/download-research-document.php` and `git diff --check -- pages/shared/download-research-document.php` — reported passed by the RMS workflow implementation agent.
- `php scripts/migrate.php status` before application: migration 017 `applied / executed`, 027 `pending`; unrelated entries showed 010 `needs-verifier` and 012/014 `checksum-mismatch`.
- `php scripts/migrate.php apply 027_submission_review_recommendation.sql` — `APPLIED 027_submission_review_recommendation.sql`.
- `php scripts/migrate.php status` after application: migration 027 `applied / executed`. Existing 010/012/014 states remain unresolved and unchanged; no other migrations were synchronized or run.
- The migration runner's verifier checks that the new column exists as nullable TEXT. No user data rows were modified.

## Resume / handoff
- Last user-confirmed state: User shared the review panel in the viewer at upload ID 88, Version 3, with reviewer controls above the preview; requested document left and review controls right. The layout change has not yet been user-confirmed in browser.
- Next pending action: User refreshes the Chapter 2 viewer and confirms the document preview appears left and the review panel right at desktop width. Check narrow-width stacking only if convenient.
- Blocked/skipped checks and remaining verification gaps: Viewer layout, review submission, and decision persistence remain unverified in browser. Existing migration ledger entries 010 (`needs-verifier`) and 012/014 (`checksum-mismatch`) were not addressed; explicit apply of 027 did not run or sync them.
- User-performed data changes and cleanup status: User reports submitting a replacement PDF for Chapter 2 before this feature request. No additional data changes were made in this implementation.
