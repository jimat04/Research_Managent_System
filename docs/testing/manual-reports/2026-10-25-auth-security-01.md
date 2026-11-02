# Verification: authentication security hardening

- Date: 2026-10-25 (Asia/Taipei)
- Assistant: Codex
- Target: revoke invalid sessions, persist login throttling, enforce verification, and protect logout.
- Environment URL: `http://localhost/rms/public/login.php` (user-reported browser page).
- Roles and disposable records: no browser records used.
- Session state: browser verification in progress.

## Graphify and source context

- Question and selected graph vocabulary: authentication, login, logout, session, CSRF, verification.
- Existing `graphify-out/graph.json` was inspected read-only. Relevant nodes included `includes_auth_requirelogin`, `includes_auth_iscsrftokenvalid`, `public_login_isloginlocked`, `public_login_recordfailedlogin`, `public_login_resetfailedlogins`, and `includes_auth_csrffield`.
- Confirmed against current source: protected pages call `requireLogin`/`requireRole`; login uses the CSRF helper; shell navigation owns logout links. Graph coverage does not describe the new database table or every shell caller.

## Checks

| ID | Method | Expected | Observed | Status |
|---|---|---|---|---|
| AUTH-01 | `php -l` on changed authentication and login files | No PHP syntax errors | Passed for all changed PHP files | Passed |
| AUTH-02 | Source inspection of `includes/auth.php` | Suspended/deleted accounts and sessions with stale password hashes are rejected centrally | Live account query checks active, non-deleted status and session password hash | Passed |
| AUTH-03 | Source inspection of `public/login.php` | Failed-login counters survive new sessions | Counters use `login_rate_limits` keyed by IP/email hash | Passed |
| AUTH-04 | Source inspection of verification path | Unverified non-admin accounts cannot sign in | Login checks `email_verified`; migration preserves legacy accounts without verification tokens | Passed |
| AUTH-05 | Source inspection of logout flows | Logout requires POST and CSRF; navigation submits forms | `public/logout.php` validates method/token; role shells and resend page use POST forms | Passed |
| AUTH-06 | Browser suspension/password-change/verification/logout checks | User confirms expected access boundaries and messages | Not performed; user must operate the browser | Pending |
| AUTH-07 | User / browser / login page | Open the login page | User reported that the login page appears normally | Passed |
| UI-01 | Static CSS/markup check | Logout controls remain valid after the redesign | Student, faculty, staff and admin shells have POST buttons with role-scoped hover and focus styles; CSS brace counts match | Passed |
| UI-02 | User / browser / authenticated role shell | Hover, focus and submit logout in each role | Awaiting user observation | Pending |

## Assistant-run checks

- `php -l includes/auth.php public/login.php public/logout.php public/resend-verification.php includes/student-shell.php includes/faculty-shell.php includes/staff-shell.php includes/admin-shell.php pages/admin/admin-users.php`: all passed individually.
- Local `rms_db` target and migration ledger were checked. A backup of the affected users table was saved to `C:\tmp\rms-users-before-auth-hardening-20261025.sql`. `php scripts/migrate.php apply 032_auth_security_hardening.sql` reported `APPLIED`; a subsequent status check reported `applied / executed`.
- A focused source review identified that the new throttle's duplicate-key update could lock before the fifth failure because MySQL evaluates assignments in order. The expression was corrected to compare the updated count, and `php -l public/login.php` passed again. The exact five-attempt boundary remains Pending browser verification.
- Logout redesign added role-scoped danger buttons and keyboard focus states in the four shell stylesheets. Existing POST+CSRF form behavior remains intact.
- User reported `Logout requires a valid form submission.` Source review showed this came from a stale or direct GET logout request. GET now presents a CSRF-protected confirmation form; session destruction remains POST-only. `php -l public/logout.php` passed.

## Resume / handoff

- Last user-confirmed state: the RMS login page appears normally.
- Next pending action: select the role tab for an authorized account on the login page; then continue through sign-in and access checks one step at a time.
- Browser checks remain pending: existing sessions should be forced to login after deployment; suspended users should lose access; five failed attempts for the same IP/email should lock across a new session; unverified users should see the verification message; GET logout should return 405 and shell logout should work through POST.
