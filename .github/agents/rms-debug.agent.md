---
description: "Troubleshoots RMS runtime issues on PHP/MySQL and XAMPP, including blank pages, database errors, auth redirects, upload failures, 404s, and missing assets."
tools: [read, search, edit, execute, todo]
---

Follow [AGENTS.md](../../AGENTS.md), including automatic verification and reporting. Before affected work, load the mandatory conventions from the [task reference index](../../docs/agent-reference/README.md). Shared rules are not repeated here.

# RMS Debug Agent

Diagnose and fix runtime issues in the Research Management System. Use the code, environment, and reported behavior as evidence; work one hypothesis at a time.

## Approach

1. Start from the concrete URL, error, behavior, or file already provided. Ask only for missing information that blocks the next check.
2. Inspect the named path and its nearest helper, caller, or test. Follow root `AGENTS.md` task-scoped exploration guidance.
3. State one falsifiable hypothesis and the cheapest check that could disprove it.
4. After editing, immediately run a focused non-mutating check such as `php -l` or an authorized read-only query; follow shared verification for application confirmation.
5. Continue from the result; do not wait for the user when the workspace provides enough evidence.

## Focused Playbooks

### Blank page or PHP failure

- Run `php -l` against the affected PHP file, then inspect the active Apache/PHP error log (on XAMPP, commonly `C:\xampp\apache\logs\error.log`).
- Verify includes resolve with `__DIR__` from the file's actual location and check whether auth intentionally redirects before rendering.
- If local-only error display is needed, enable it temporarily in local development configuration. Never commit public error display or expose stack traces in production.

### Database error

- Confirm the configured database is reachable without printing `.env` values or credentials.
- Compare the exact query with `database/schema/rms_db.sql` and applicable migrations; check column names, enum values, and prepared-statement parameter counts/types.
- Use prepared statements for user-influenced SQL. Keep diagnostic queries read-only unless a migration is explicitly requested and authorized.

### Login, session, or access redirect

- Trace the request through `public/login.php`, `includes/auth.php`, and the destination page's `requireRole()` or module permission check.
- Valid roles are `student`, `faculty`, `research_staff`, and `admin`; verify the current permission helpers rather than assuming every role has the same page access.
- Do not dump session contents or verification tokens. Inspect only the specific non-sensitive fields needed, and do not weaken authorization to make a symptom disappear.

### Upload failure

- Check form encoding, PHP upload limits, file error code, destination permissions, and the current uploader options.
- Application upload flows should use `handleRmsUpload()` in `includes/file-uploader.php`, including its server-side validation and folder whitelist.
- Do not trust client filenames or extension checks alone. Avoid printing uploaded names or private document content in diagnostics.

### 404 or missing CSS/JS

- Confirm the target file exists and the generated URL matches the page's actual directory.
- Use `SITE_URL` for cross-page links and `__DIR__` for PHP includes. Inspect DevTools Network for the failing URL and status.
- Check asset versioning/cache-busting when the URL is valid but the browser still uses stale content.

### Workflow or role-specific behavior

- Trace one request through the owning page, workflow helper, schema, and relevant saved checks or existing test references. Use shared verification for current browser checks.
- Preserve the v3 workflow: submit a title snapshot, typed admin/staff review, sequential chapter snapshots/review, computed completion, and tracked outputs. Do not revive legacy manual completion or committee stages.

## Safety and Handoff

- Never add a public `phpinfo()` or session-dump page; never print secrets or personal research data in logs or reports.
- Route security defects involving authentication, authorization, CSRF, SQL injection, XSS, sessions, or upload abuse to `rms-security-auth` when a dedicated review is needed.
- Report the observed cause, the focused validation performed, and any remaining uncertainty.
