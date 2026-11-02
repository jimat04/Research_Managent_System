# Verification: suspension revocation

- Date: 2026-10-25 (Asia/Taipei)
- Assistant: OpenCode
- Target: Existing sessions lose protected access on the next request after account suspension.
- Environment URL: local RMS; browser availability not confirmed.
- Roles and disposable records: administrator and disposable non-admin account required; not yet confirmed.
- Session state: paused for user-operated verification.

## Graphify and source context
- Question: Do shared authentication guards revalidate account status?
- Selected actual vocabulary: isLoggedIn, requireLogin, requireRole.
- Query: `graphify query "isloggedin requirelogin requirerole" --budget 1500`.
- Result: 32 nodes / 55 edges; complete output exceeded requested budget, not truncated. Graph locations are stale (isLoggedIn L10, requireLogin L79, requireRole L45); current source confirmed relationships.
- Current source: `includes/auth.php` shared guards call isLoggedIn directly or through hasRole/getCurrentUser. `public/login.php:149` rejects non-active accounts. `pages/admin/admin-users.php:127-129` changes stored status only. `pages/shared/download-research-document.php:7` and `public/api/upload.php:19` use requireLogin before protected processing.
- Graph was not rebuilt or modified. Relationship findings were confirmed against current source; graph coverage does not establish all routes are guarded.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / source | Trace status and guards | Existing-session gap identified | Previous isLoggedIn checked only session user_id | Passed |
| S2 | Assistant / PHP lint | Lint changed auth helper | No syntax errors | php -l passed | Passed |
| S3 | Assistant / diff | Check whitespace and scope | No unintended edits from this task | Only isLoggedIn changed by assistant; pre-existing changes preserved | Passed |
| B1 | User / disposable non-admin / dashboard | Establish existing active session | Dashboard accessible | No user observation yet | Pending |
| B2 | User / administrator / Users | Suspend agreed disposable account through supported UI | Status becomes suspended | Not performed | Pending |
| B3 | User / existing suspended session / protected route | Request protected page again | Login redirect; old session denied | Not performed | Pending |
| B4 | User / suspended account / direct document route | Reopen authorized disposable document URL | Access denied before document delivery | Not performed | Pending |
| B5 | User / suspended account / login | Attempt normal login | Login rejected | Not performed | Pending |
| B6 | User / active account | Confirm unaffected login and protected access | Normal access retained | Not performed | Pending |

## Failures, decisions, fixes, and retests
- User supplied high-severity suspension finding; source confirmed missing live status enforcement.
- Updated isLoggedIn to query active, non-deleted account status once per user ID per request. Invalid accounts lose all session state and the old session ID is regenerated/deleted. Existing shared guards retain their denial behavior.
- Cache is request-local, not session-persistent. Already running requests and already downloaded content are not revoked retroactively.

## Assistant-run checks
- `C:\xampp\php\php.exe -l includes/auth.php`: no syntax errors.
- `git diff --check -- includes/auth.php`: passed; Git emitted only LF-to-CRLF advisory.
- Reviewed diff against initial source; unrelated pre-existing auth changes remain untouched.
- Canonical schema includes users.deleted_at; no schema change or database operation performed.
- No application requests, scripted sessions, or browser automation performed.

## Resume / handoff
- Last user-confirmed browser state: none.
- Next action: establish whether an authorized disposable non-admin account is already signed in for revocation verification.
- Runtime behavior, persistence, direct-route denial, and active-account regression remain unverified pending user interaction.
- User-performed data changes: none reported; cleanup not applicable yet.
