# RMS setup and configuration

Read [AGENTS.md](../../AGENTS.md) first. Apply this reference when the [task index](README.md) selects it; paths in code spans are relative to the repository root. These conventions were relocated from CLAUDE.md without revalidating historical setup claims; current source wins.

## Project Overview

RMS is a PHP/MySQL research platform. Students and faculty share a title, sequential chapter, and final-manuscript workflow. A project is complete only after its final manuscript is approved; authorized admin/staff review controls these transitions.

**Stack:** PHP 8.1+, MySQL/MariaDB, Apache (XAMPP), Composer (vlucas/phpdotenv)

## Setup Commands

```bash
# 1. Create database (via phpMyAdmin)
#    - Name: rms_db

# 2. Import schema + seed data
mysql -u root rms_db < database/schema/rms_db.sql

# 3. Configure environment
cp .env.example .env   # Edit .env with DB credentials, SITE_URL

# 4. Access the app
http://localhost/rms/
```

The canonical schema includes demo accounts for local development. Their
passwords are not published here; verify local account credentials before use.
Disable or rotate demo accounts before deploying an environment.


**Troubleshooting:** Blank page → MySQL not running or wrong `DB_*` in `.env`. Upload fails → `uploads/` subdirs need write permission for web server.

## Configuration

All configuration via `.env` (loaded by `includes/config.php` via phpdotenv). Key variables:

```
DB_HOST, DB_USER, DB_PASS, DB_NAME  — MySQL connection
SITE_URL                              — Base URL (e.g., http://localhost/rms/)
SESSION_TIMEOUT                       — Session lifetime in seconds (default: 1800)
BCRYPT_COST                           — Password hashing cost (default: 12)
MAX_UPLOAD_SIZE                       — Max upload size in bytes (default: 10MB)
ALLOWED_FILE_TYPES                    — Comma-separated list of allowed extensions
APP_ENV                               — 'development' or 'production'
APP_DEBUG                             — Enable PHP error display
REQUIRE_EMAIL_VERIFICATION             — Default 1; 0 skips the login email gate only with APP_ENV=development and a localhost/loopback SITE_URL
EARIST_LIVE_GENERATION_ENABLED          — First-run default (0); a saved Admin System Settings value takes precedence
EARIST_GOOGLE_DRIVE_ENABLED              — Optional Faculty Google Docs editor; defaults to 0 and needs OAuth credentials plus a server-held token-encryption key
```

Configure the optional Faculty Google Drive editor using [EARIST Google Docs editing](../EARIST_GOOGLE_DOCS_SETUP.md). It stores working copies in the user's Drive and requires migration 068 before activation.

## Official EARIST templates

The approved DOCX templates are excluded from Git. Obtain them and their approved SHA-256 manifest from the authorized institutional custodian through an approved secure channel. Copy the files, without resaving or modifying them, to `templates/earist/blank/` with the exact filenames in `config/earist-template-bindings.json`. Run `php scripts/check-earist-templates.php`; setup is incomplete if any file is missing or its hash differs. The checker is read-only and does not generate documents.

Manage live document generation at **Admin → System Settings → Application settings → EARIST document generation**. The saved `application_settings` value takes precedence over the environment fallback; `EARIST_LIVE_GENERATION_ENABLED` defaults to `0` and supplies the initial value when no database setting exists. Enable it only for an approved controlled test window, then turn it off when that window closes.

## Production transport and sessions

Set `SITE_URL` to the HTTPS application URL and configure the production Apache virtual host with `SetEnvIf Host . RMS_FORCE_HTTPS=1`. Set `ACTIVITY_CHECKIN_HASH_KEY` to 64 hexadecimal characters generated from 32 random bytes. The root `.htaccess` uses this server-owned flag to redirect HTTP requests; local development is unaffected. The current rewrite base and custom error paths assume the app is mounted at `/rms/`; update them together if the virtual host maps it at the domain root or a different path prefix. If TLS terminates at a trusted reverse proxy, configure Apache to recognize the trusted proxy's HTTPS state before enabling the redirect. Production session cookies are marked `Secure`, `HttpOnly` and `SameSite=Lax`; development cookies omit `Secure` when served over HTTP.
