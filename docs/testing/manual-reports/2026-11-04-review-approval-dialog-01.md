# Review approval dialog text alignment

Date: 2026-11-04 (Asia/Taipei)

## Scope

Center the title and explanatory text in the feedback approval dialog on the research submission review page. Keep the icon and action buttons in their current positions.

## Graph lookup

- Question: Which page contains the dialog and how is it related to submission reviews?
- Selected graph terms: `Research Submission`, `Staff Milestone Reviews`, `Form Submission Controls`.
- Lookup: `graphify query "Research Submission" --budget 1500`.
- Relevant node: `research-submission-review.php`, `pages/shared/research-submission-review.php:L1`.
- Graph returned submission version/review concepts but no direct edge for the dialog styles or its approval buttons. Current-source inspection confirmed the dialog markup near L736 and its event handlers near L774-L785. Graph coverage is partial for this focused visual behavior; source is authoritative.

## Checks

| Check | Owner | Expected | Observation | Status |
|---|---|---|---|---|
| Source review | Assistant | Dialog heading and description are centered; icon and action alignment remain unchanged | CSS scoped to the dialog heading and paragraph | Passed |
| PHP syntax | Assistant | Changed PHP file has no syntax errors | `php -l pages/shared/research-submission-review.php` reported no syntax errors | Passed |
| Browser appearance | User | On the review page, open approval with feedback; title and description appear centered, with icon left and buttons right | User confirmed the check passed | Passed |
