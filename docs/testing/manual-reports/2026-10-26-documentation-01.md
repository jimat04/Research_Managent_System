# Verification: current documentation updates

- Date: October 26, 2026 (Asia/Taipei)
- Scope: README, project structure, RMS specification, and historical progress-audit pointers.
- Expected result: Current routes, activity features/migrations, and backlog guidance are described accurately; historical results remain identified as historical.

## Graphify lookup

- Question: Do the setup, structure, and specification documents match the current activity implementation and related submission notifications?
- Selected graph terms: `activity-detail.php`, `activity-form.php`, `research_activities`, `migration`.
- Query: `graphify query "activity-detail.php activity-form.php research_activities migration" --budget 1200`.
- Result: The existing graph returned 99 nodes and was truncated to 34. It identified `pages/shared/activity-detail.php`, `pages/shared/activity-form.php`, and `pages/shared/activities.php`, along with unrelated and historical nodes. No dedicated current budget-notice/custom-audience node was found in the visible results; these details were checked against current source and migrations instead.
- Source confirmation: `pages/shared/activity-form.php` and `pages/shared/activity-detail.php` are the activity UI; `includes/activity-budget.php` implements budget validation, persistence, notices, delivery and contribution responses. Migrations 034–038 cover schedule/audience/budget additions and schedule/type repairs. `pages/researcher/submit-research.php` selects active `research_staff` and `admin` accounts for new-submission notifications.
- Limitation: The existing graph is truncated and does not establish runtime behavior. Current source and migration text were authoritative for this documentation review.

## Assistant checks

| ID | Check | Expected | Result | Status |
|---|---|---|---|---|
| A1 | Review routes and migration references in changed docs against current files | Referenced routes/files exist and descriptions match source | Corrected the About Us/contact route, missing TODO references, migration range, activity capabilities, and manual filename link. | Passed |
| A2 | Review activity budget, audience, notice, and contribution claims against handlers/migrations | No unsupported behavior claims | Claims match migrations 034–038 and `includes/activity-budget.php`; notice functionality is described as in-app notification, not payment processing. | Passed |
| A3 | `git diff --check` on changed prose files and relative-link/target checks | No whitespace errors; referenced local files exist | `git diff --check` passed; relative Markdown links and referenced routes/migrations all resolve. | Passed |
| U1 | Browser interaction | Not applicable to documentation edits | Skipped; no application behavior was changed. | Skipped |

## User observations

- None requested; no browser check was needed for these documentation-only edits.
