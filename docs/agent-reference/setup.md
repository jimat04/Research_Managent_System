# RMS setup and configuration

Read [AGENTS.md](../../AGENTS.md) first. Apply this reference when the [task index](README.md) selects it; paths in code spans are relative to the repository root. These conventions were relocated from CLAUDE.md without revalidating historical setup claims; current source wins.

## Project Overview

RMS is a PHP/MySQL research platform. Students and faculty share a title, sequential chapter, and final-manuscript workflow. A project is complete only after its final manuscript is approved; authorized admin/staff review controls these transitions.

**Stack:** PHP 7.4+, MySQL/MariaDB, Apache (XAMPP), Composer (vlucas/phpdotenv)

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
```
