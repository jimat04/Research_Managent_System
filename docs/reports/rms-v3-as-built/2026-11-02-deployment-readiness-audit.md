# Deployment-readiness source audit

- Date: 2026-11-02 (Asia/Taipei)
- Base commit: `46926a90a5a38040e6b560107da5d52d588ff9aa`
- Source state: focused working-tree inspection after these remediation edits; unrelated pre-existing worktree changes were present and preserved.
- Scope: PHP version requirements, official EARIST template provisioning, document-generation activation, session/HTTPS configuration, and bcrypt cost configuration.
- Method: focused source and documentation inspection. This is not runtime certification, a database audit, or a complete refresh of the 20 October as-built report.

The 20 October Word/PDF report remains a historical snapshot at `9d996919f74f8c4e73d6fe77804146a9c2c7acb6`. This audit covers only the deployment-readiness topics listed above; it does not update the full workflow, schema, module, or role analysis in that report.

## Findings and current implementation

| Area | Current implementation | Evidence and limitation |
|---|---|---|
| PHP requirement | Documentation now states PHP 8.1 or newer. | `pages/shared/download-research-document.php` uses PHP 8 `match`; `scripts/migrate.php` declares `never`. `README.md`, `docs/agent-reference/setup.md`, and `docs/rms-spec.md` now state PHP 8.1+. |
| Official templates | The seven approved DOCX files remain outside Git. `scripts/check-earist-templates.php` checks every manifest entry and reports missing files or SHA-256 mismatches without changing files. | `config/earist-template-bindings.json` is the source of filenames and hashes. Clean-clone provisioning depends on the authorized institutional custodian. The assistant's local read-only run verified 7/7 files; this does not establish that a clean clone has those files. |
| Generation activation | Admins can control the site-wide gate at System Settings. The saved `application_settings` value overrides the `EARIST_LIVE_GENERATION_ENABLED` environment fallback, which defaults to `false`. | The setting is validated and audit-logged through the existing application settings transaction; `earistDocumentStorageConfig()` applies it to Faculty/Admin generation routes. This source audit did not exercise a live generation request. |
| HTTPS and session cookies | The application sets `HttpOnly` and `SameSite=Lax`, uses `Secure` for production or active HTTPS, and enables strict session mode/cookie-only transport. `.htaccess` redirects when the production Apache environment flag `RMS_FORCE_HTTPS=1` is configured. | `includes/config.php`, `.htaccess`, and `docs/agent-reference/setup.md`. Production redirect behavior depends on the production virtual-host setting and actual TLS/proxy configuration; it was not browser-tested in this audit. |
| Password hash cost | `BCRYPT_COST` is now used by the shared hash helper and admin account creation/password reset. | `includes/auth.php`, `pages/admin/admin-users.php`, and `includes/config.php`. The default remains 12. |

## Excluded scope and verification

The automated browser suite was not restored in this change. It requires a separately agreed test scope and a verified isolated target; the normal `rms_db` database must not be used for mutating automation.

Assistant verification is limited to PHP syntax, the read-only 7/7 template check, and focused static checks recorded in the 2 November manual report. No application interaction, database operation, migration, template generation, or production-server configuration change was performed. Browser behavior remains pending user confirmation.
