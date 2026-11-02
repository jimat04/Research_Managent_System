# Verification: approved title proposal visibility

- Date/time: 2026-11-02 04:10 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Hide new research proposal actions for a student with an approved title in their current project; block the direct proposal route and show a centered, responsive status card pointing to My Research.
- Environment URL: http://localhost/rms/pages/student/student-dashboard.php
- Roles and disposable records/files: Student account with a currently assigned title-approved project; no records changed.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How does the student shell render the proposal page and its status state? Selected terms: `student-shell.php`, `submit-research.php`, `renderStudentShell`.
- Query/lookup and result: `rtk graphify query "renderStudentShell" --context call --budget 1500`; one node returned (`renderStudentShell()` in `includes/student-shell.php`). No CSS relationships are represented by this query.
- Relevant source locations and verified relationships: `includes/student-shell.php` loads `css/student-shell.css`; `pages/researcher/submit-research.php:19-30` renders the approved-title state. The centered layout styles are in `css/student-shell.css:599-686`, with responsive rules at `:726-732`.
- Missing, inferred, stale, or truncated context: Graphify does not represent the page-specific CSS relationship. Direct source confirmed the stylesheet is loaded by the student shell before the status markup. The earlier query included stale `pages/student/submit-research.php` and removed E2E nodes; the focused query was not truncated.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l` on the four changed PHP files | No syntax errors | PHP reported no syntax errors for all four files | Passed |
| S2 | Assistant source review | Inspect the approved-title helper, shell/dashboard visibility conditions, and direct-route guard | The check follows the current assignment and approved-title state; direct access renders a notice without the form | Confirmed in current source; no database or application records changed | Passed |
| S3 | Assistant diff check | `git diff --check` on the four changed PHP files | No whitespace errors | No errors; Git emitted existing line-ending and ignore-path warnings | Passed |
| S4 | Assistant static check | `php -l pages/researcher/submit-research.php` after the centered-state markup change | No PHP syntax errors | PHP reported no syntax errors | Passed |
| S5 | Assistant diff check | `git diff --check` on `css/student-shell.css` and `pages/researcher/submit-research.php` | No whitespace errors | No errors; Git emitted an existing line-ending warning | Passed |
| S6 | Assistant static check | Count braces in `css/student-shell.css` | Opening and closing CSS block counts match | 176 opening and 176 closing braces | Passed |
| B1 | Browser / student with approved title / dashboard | Refresh the student dashboard | Sidebar and dashboard omit the new-proposal action; My Research remains available | User confirmed: “all passed” | Passed |
| B2 | Browser / student with approved title / direct proposal route | Navigate to `pages/researcher/submit-research.php` | Approved-title message appears; proposal form is unavailable | User confirmed: “all passed” | Passed |
| B3 | Browser / student with approved title / direct proposal route / desktop | Refresh the route at desktop width | Status card and message are centered in the page content; action is clear and readable | Awaiting user observation | Pending |
| B4 | Browser / student with approved title / direct proposal route / mobile | Narrow the viewport after B3 | Card remains centered, text wraps cleanly, and action fits without horizontal scrolling | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- `rtk proxy php -l includes/research-workflow.php` — passed.
- `rtk proxy php -l includes/student-shell.php` — passed.
- `rtk proxy php -l pages/student/student-dashboard.php` — passed.
- `rtk proxy php -l pages/researcher/submit-research.php` — passed.
- `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check -- includes/research-workflow.php includes/student-shell.php pages/student/student-dashboard.php pages/researcher/submit-research.php` — passed with existing line-ending and ignore-path warnings.

## Resume / handoff
- Last user-confirmed state: User reported that the original proposal visibility and direct-route checks passed; the newly centered card has not yet been reviewed in the browser.
- Next pending action: Role: student with a currently assigned title-approved project. Page: `pages/researcher/submit-research.php`. Action: refresh at desktop width. Expected: the status card and message are centered and readable. Confirm whether that matches or you see something different.
- Blocked/skipped checks and remaining verification gaps: B3 and B4 are pending. No database or application records changed.
- User-performed data changes and cleanup status: None.
