# Verification: student login redirect

- Date/time: 2026-10-24, Asia/Taipei; started after 03:36.
- Assistant: GitHub Copilot.
- Target and expected behavior: an existing student signing in from the public archive's full-record **Create an account** flow should reach the student dashboard, not be sent back to the public research archive.
- Environment URL: `http://localhost/rms/public/login.php`
- Roles and disposable records/files: the user's authorized student account; no credentials recorded.
- Session state: in progress.

## Graphify and source context

- Question and selected graph vocabulary: trace student login redirects between `public/research-archive.php`, `public/login.php`, `verify-email.php`, and `student-dashboard.php`.
- Query/lookup and result: queried the existing 1,588-node graph for the redirect flow; results were broad and truncated. A directed and undirected path lookup between the public archive and student dashboard found no path. No graph rebuild or update was run.
- Relevant source locations and verified relationships:
  - `includes/public-header.php:3,11-12` adds `?continue=research-archive` to sign-in links when the public archive header is active.
  - `public/research-archive.php:389` adds the same continuation to its unauthenticated “Login to submit your research” link.
  - `public/login.php:6,10-23,195-203,297,794` stores the continuation in the session, redirects a logged-in student with completed Research Basics to the public archive, and preserves the continuation through login form submission.
  - `pages/student/student-dashboard.php:119-121` also returns to the public archive when that continuation is present during Research Basics completion.
  - `public/verify-email.php:7` can set the same continuation when its URL carries that value.
- Missing, inferred, stale, or truncated context: graph traversal was truncated and path lookup found no connection; source inspection is the evidence for the relationships above. The current browser is on `login.php` without a visible query string, but the session may already contain a continuation. User-operated login is needed to establish the actual result.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| SRC-01 | Assistant / source | Trace login continuation handling | Identify supported paths that can route a student to the archive | Source inspection identified an explicit session continuation from archive sign-in links through login | Passed |
| SRC-02 | Assistant / code | Route completed existing students with an archive continuation to their dashboard | Preserve existing destination for incomplete-student onboarding | `public/login.php` no longer redirects completed students to the public archive from either successful-login branch; the continuation is cleared and the default dashboard map remains active | Passed |
| STATIC-01 | Assistant / PHP | Run `php -l public/login.php` | No syntax errors | `No syntax errors detected in public/login.php` | Passed |
| APP-01 | User / student / `public/login.php` | Enter the authorized student email in the email field only; do not submit yet | Email field is filled and student role remains selected | User confirmed the email field is filled and Student is selected; address not shared in chat | Passed |
| APP-02 | User / student / `public/login.php` | Enter the password directly in the browser after APP-01 is confirmed | Password field is filled; no credential is shared in chat | User confirmed the password field is filled; password not shared in chat | Passed |
| APP-03 | User / student / `public/login.php` | Submit the normal login form after APP-02 is confirmed | Student dashboard appears unless a documented continuation explains the archive destination | User clarified they checked the direct-login path themselves and it is safe; the shared integrated browser now shows the student dashboard | Passed |
| APP-04 | User-reported / student / public archive full-record flow | Open a full record, choose “Create an account,” then sign in with the existing student account | Student dashboard appears after sign-in | User reports being returned to `public/research-archive.php`; source shows that this CTA includes `continue=research-archive` and the login handler previously redirected completed students to that URL | Failed |
| APP-04-R1 | User / public / `public/research-archive.php` | Navigate to the public archive after the fix | Archive page loads | User confirmed the Research Archive loaded | Passed |
| APP-04-R2 | User / public / `public/research-archive.php` | Click the same research record card used in the original flow | Account access prompt opens | User confirmed the account access prompt appeared | Passed |
| APP-04-R3 | User / public / `public/login.php?continue=research-archive#register` | Click **Create an account** in the prompt | Registration form opens without submitting | User confirmed the registration form appeared | Passed |
| APP-04-R4 | User / public / `public/login.php?continue=research-archive#register` | Click the existing-account **Sign In** link | Student login form appears | User confirmed the student login form appeared | Passed |
| APP-04-R5 | User / student / archive-origin login form | Ensure the authorized student email is present and Student is selected | Login form is set for the existing student account | User confirmed the email is populated and Student is selected; address not shared in chat | Passed |
| APP-04-R6 | User / student / archive-origin login form | Enter the account password directly in the browser | Password field is filled; no credential is shared | User confirmed the password field is filled; password not shared in chat | Passed |
| APP-04-R7 | User / student / archive-origin login form | Submit the normal login form once | Student dashboard appears instead of the public archive | User confirmed the student dashboard appeared | Passed |

## Failures, decisions, fixes, and retests

- Original failure APP-04: the user reported returning to the public archive after signing in from the full-record account prompt. The user chose the student dashboard as the expected destination and authorized a fix. `public/login.php` now clears the continuation for completed existing students and uses the normal dashboard map in both the POST-success and already-logged-in paths. Incomplete students retain the intent so Research Basics onboarding can continue to the archive. Retest APP-04-R7 passed.
- Separate direct-login check APP-03: initial browser evidence was contradictory, but the user later confirmed the direct-login path is safe and checked it themselves. The shared browser currently shows the student dashboard. No credentials were copied into the report.

## Assistant-run checks

- Read the existing Graphify map; two broad queries were truncated and a directed/undirected path lookup found no path.
- Inspected current login, auth, public-header, archive, verification, and student-dashboard source. These files already had uncommitted changes before this session; the only application code changed for this fix is `public/login.php`.
- Read-only browser snapshots: initially the login form at `http://localhost/rms/public/login.php`; an intermediate snapshot showed a login error, and a later snapshot shows `http://localhost/rms/pages/student/student-dashboard.php`. The user clarified that direct login is safe and checked it themselves. The assistant did not operate the browser. No credentials are copied into this report.
- No credentials, database queries, browser automation, E2E tests, or database changes were performed.

## Resume / handoff

- Last user-confirmed state: after using the archive full-record **Create an account** prompt and signing in with the existing student account, the student dashboard appeared.
- Next pending action: none for the reported archive-origin redirect; the direct-login discrepancy in APP-03 was not retested.
- Blocked/skipped checks and remaining verification gaps: account-specific onboarding and already-authenticated redirects were not separately exercised.
- User-performed data changes and cleanup status: archive-origin sign-in established the user's session; the normal login flow updates the login timestamp/activity log. No research record was created or modified.
