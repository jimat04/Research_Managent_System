# Verification: online readiness

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Prepare RMS for a production deployment by checking configuration safety and fresh-install schema consistency. Production startup should reject unsafe core settings and should not reveal database connection details to visitors. QR check-in must have its documented server-side hash key.
- Environment URL: InfinityFree selected; domain, URL path, and plan type are not confirmed.
- Roles and disposable records/files: None. No application or database records used.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Trace production configuration, session security, migration/schema relationships, and public file access. Selected first query terms `config`, `migration`, and `auth`; focused follow-up terms `config` and `session`; latest terms `security` and `upload`.
- Query/lookup and result: `graphify query "config migration auth" --budget 1500` returned 63 nodes and was truncated. `graphify query "config session" --budget 1500` returned 16 nodes, starting at `config.php` and `Configuration (.env)`; no session relationship was returned. `graphify query "security upload" --budget 1500` returned 20 nodes and 23 edges but no relevant `.htaccess` node.
- Relevant source locations and verified relationships: Current `includes/config.php` loads `.env` values through `rms_env()`, validates production settings before connecting to MySQL, requires the documented 64-hex-character `ACTIVITY_CHECKIN_HASH_KEY`, and returns a generic production response when a database connection fails. `scripts/migrate.php` has migration verifiers for migrations 068, 069, 070, 071, 072, and 074; migrations 073 and 075 are data-only permission cleanup/mapping migrations. The current canonical schema includes the resulting 068–075 table/column definitions and activity enum. README migration guidance was stale at 066 and now says 075. `.htaccess` hardcodes `/rms/` in `RewriteBase` and custom error paths; the install path must match or those paths must be adjusted. The root rewrite rules now deny `.git` and `.github` paths.
- Missing, inferred, stale, or truncated context: The first graph query was truncated. The focused map reflects earlier source locations and does not include the new production-validation logic; the latest graph query did not cover `.htaccess`. Current source was reviewed directly and takes precedence. The graph did not return session or migration relationships for the focused query. No database target was queried or changed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / PHP syntax | Run PHP lint on `includes/config.php` | No syntax errors | `C:\xampp\php\php.exe -l includes/config.php` reported no syntax errors | Passed |
| S2 | Assistant / whitespace review | Run `git diff --check` on `.htaccess`, `includes/config.php`, `README.md`, and `docs/agent-reference/setup.md` | No whitespace errors | Command completed with no output | Passed |
| S3 | Assistant / schema source review | Compare migrations 068–075, their registered verifiers, and canonical `database/schema/rms_db.sql` | Fresh-install schema contains current feature DDL; data-only migrations need no schema DDL | DDL from 068–072 and 074 is present; migration 070's final enum is present; migrations 073 and 075 only transform staff permission rows. No DB connection or migration execution occurred. | Passed |
| S4 | Assistant / Apache source review | Inspect and add root rewrite rules for development metadata and non-public directories | Requests for project internals are denied while PHP code can still include them locally | Rules deny `.git`, `.github`, `docs`, `graphify-out`, `scripts`, `templates`, and top-level `vendor`; `FilesMatch` protects root agent/setup manifests. Apache runtime behavior was not exercised. | Passed |
| S5 | Assistant / provider packaging review | Compare local file sizes and template provisioning with InfinityFree's published file limits | Upload files fit provider limits and all required institutional templates are available | No PHP file is at least 950,000 bytes; root `.htaccess` is 2,703 bytes; canonical SQL is 162,866 bytes; approved template checker verified 7/7. Largest DOCX in the template directory is 4,219,999 bytes. This does not verify provider PHP extensions or actual FTP behavior. | Passed |
| U1 | User / public browser / deployed site | Open the production URL after the hosting target and mount path are confirmed | HTTPS page loads without server/PHP details | InfinityFree selected, but no public URL supplied; not started | Pending |
| U2 | User / public browser / deployed site | Inspect the login page and submit no data | Login form renders over HTTPS | Awaiting production target and user browser observation | Pending |

## Failures, decisions, fixes, and retests
- No application failure was observed. Production behavior has not been exercised in a browser or against a live database.
- An ad hoc PowerShell size-summary command failed because its display labels were parsed as commands; a read-only rerun returned the vendor-directory and schema-size results. No application/source failure was involved.
- A read-only attempt to extract only `SITE_URL` from the local `.env` failed on shell quoting before reading the file. No environment values were printed; the production URL is still pending from the user.

## Assistant-run checks
- `C:\xampp\php\php.exe -l includes/config.php` — passed.
- `git diff --check -- .htaccess includes/config.php README.md docs/agent-reference/setup.md` — passed.
- `graphify query "security upload" --budget 1500` — completed, but returned no useful `.htaccess` relationships; direct source review was used.
- Apache per-directory rule syntax was checked against the [Apache 2.4 per-directory rewrite documentation](https://httpd.apache.org/docs/current/rewrite/htaccess.html). Runtime Apache behavior remains unverified.
- Read-only comparison of migrations 068–075, `scripts/migrate.php` verifiers, and `database/schema/rms_db.sql` — confirmed the fresh-install DDL is aligned for these migrations; no database operation was performed.
- Targeted source review — confirmed production mode disables PHP error display, rejects debug mode, requires HTTPS `SITE_URL`, a valid QR check-in hash key, and rejects missing database values, root database user, and the documented placeholder credentials. Production database failures are logged with a generic message and shown to visitors as a generic 503 response.
- `C:\xampp\php\php.exe scripts/check-earist-templates.php` — passed; 7/7 templates in the manifest match their approved hashes. The largest file in the template directory is 4,219,999 bytes, under InfinityFree's documented 10 MB non-code file limit.
- Read-only size scan — no repository PHP file is at least 950,000 bytes; root `.htaccess` is 2,703 bytes; canonical schema is 162,866 bytes. This leaves margin against InfinityFree's documented 1 MB PHP/JS and 10 kB `.htaccess` limits; remote upload behavior is untested.
- InfinityFree's published guidance says free hosting has no shell/Composer execution, no provider backups, permits SMTP on port 587, limits individual PHP/JS files to 1 MB and other files to 10 MB, and is not a file/backup hosting service. [File limits](https://forum.infinityfree.com/t/file-size-and-upload-limits/49308), [Composer/shell access](https://forum.infinityfree.com/t/can-i-use-composer-on-freee-hosting/9695), [backup responsibility](https://forum.infinityfree.com/t/how-to-backup-your-website/49304), [outbound ports](https://forum.infinityfree.com/t/can-i-connect-to-other-servers-from-my-website-sockets-curl-and-apis/49348), [usage restrictions](https://forum.infinityfree.com/t/what-can-i-do-with-my-hosting-account/49316/1).
- Provider announcement reports free hosting moved to PHP 8.4 in September 2026; the current account's exact PHP build and required extensions still need a user-side check: [PHP 8.4 upgrade](https://forum.infinityfree.com/t/free-hosting-is-now-upgraded-to-php-8-4/120906).

## Resume / handoff
- Last user-confirmed state: No hosting or browser state confirmed.
- Next pending action: Confirm whether this is InfinityFree free hosting or a paid plan, whether the deployment is demo-only or will contain real institutional research, and the site's public URL/path. Then set deployment guidance and `.htaccess` paths to that mount point.
- Blocked/skipped checks and remaining verification gaps: Production vhost/TLS, SMTP delivery, database schema/ledger on the deployment target, file storage permissions, institution policy approval for storing research documents, remote PHP extensions, and end-to-end user flows remain unverified. No database account or credentials were supplied. Do not run migrations until the target and current ledger are reviewed.
- User-performed data changes and cleanup status: None.
