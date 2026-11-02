# Research Management System (RMS)

A PHP and MySQL research management system for students, faculty, research staff, and administrators. Students and faculty use one research workflow: submit a title → authorized review → sequential Chapters 1–5 → submit the complete manuscript → authorized final review. A project is complete only after its final manuscript is approved. Staff access is organized as 5 modules × 3 sub-pages.

**Stack:** PHP 8.1+ · MySQL/MariaDB · Apache (XAMPP/WAMP) · Composer (phpdotenv, PHPMailer)

## Requirements

- XAMPP, WAMP, or another Apache/PHP/MySQL stack
- PHP 8.1 or newer
- MySQL or MariaDB
- [Composer](https://getcomposer.org/) (for dependencies)
- A modern browser
- Optional: an SMTP account (e.g., Gmail app password) for email notifications

## Quick Setup

1. Place the project in your local web root:

   ```text
   C:\xampp\htdocs\rms
   ```

2. Start Apache and MySQL from XAMPP.

3. Install PHP dependencies (phpdotenv and PHPMailer):

   ```bash
   composer install
   ```

4. Create your environment file and fill in your database credentials (and SMTP settings if you want email):

   ```bash
   cp .env.example .env
   ```

5. Create a database named `rms_db` in phpMyAdmin.

6. Import the canonical fresh-install schema and seed data once:

   ```text
   database/schema/rms_db.sql
   ```

   This single import includes the complete current structure, required lookup
   data, and demo accounts. No migrations are needed for a fresh installation.

   The numbered scripts in `database/migrations/` are only the upgrade path for
   existing installations. The unnumbered
   `database/migrations/rms_db_migration.sql` script is superseded and retained
   only for legacy history; do not run it after importing the canonical schema.
   For an existing database, inspect its current schema and migration ledger,
   then apply only the applicable numbered migrations in order using the
   documented migration runner. The numbered scripts are the upgrade history
   (currently through migration 066); do not treat the highest number alone as
   proof that every migration is applicable. Migration 010 remains marked
   `needs-verifier`: follow [the reconciliation procedure](docs/MIGRATION_003_010_RECONCILIATION.md)
   before deciding whether it can run. Numbered migrations are not needed after
   a fresh canonical schema import.

7. Open the app — the root `index.php` redirects to the public entry point:

   ```text
   http://localhost/rms/
   ```

## Local Demo Accounts

The canonical schema includes demo accounts for local development. Their
passwords are not published here; verify local account credentials before use.
Disable or rotate demo accounts before deploying an environment.

## Project Structure

```text
rms/
  index.php                        Root redirector → public/index.php
  public/                          Public pages (no login required)
    index.php                      Homepage with statistics
    login.php                      Login + registration (role tabs, CSRF, rate limiting)
    logout.php                     Session termination
    about-us.php (includes contact form) / research-archive.php
    verify-email.php / resend-verification.php
    research-record.php             Public research record
    403.php / 404.php / 500.php    Custom error pages
  pages/                           Authenticated pages, organized by role
      student/                       Dashboard, student-only views, and legacy workflow redirects
      faculty/                       Faculty dashboard, advising, records, and document tools
      researcher/                    Canonical titles, chapters, final manuscripts, adviser requests
    admin/                         User/research administration, reports, backups, settings,
                                   publication classification, public releases, EARIST documents
    staff/                         Dashboard, contact messages, review, defense, revisions
    faculty/                       Advising, analytics, document mapping/generation, publications,
                                   engagement, and faculty research records
    shared/                        Messaging, activities, publications/IP, research review,
                                   outputs, adviser requests, and protected document delivery
  includes/                        Core backend
    config.php                     Loads .env, database connection
    auth.php                       Auth, roles, CSRF, hashing, logging helpers
    research-workflow.php          Title/chapter/final-manuscript workflow helpers
    email.php                      PHPMailer wrapper (verification, notifications, replies)
    contact-handler.php            Public contact form handler
    admin-shell.php                Shared admin page shell
      staff-shell.php / faculty-shell.php / student-shell.php
  database/
    schema/rms_db.sql              Canonical schema + seed data
    migrations/                    Incremental migration scripts
  config/                          settings.json, skills-lock.json (dev tooling)
  css/                             style.css, about.css, role-shell styles, file-uploader.css, tokens.css/.php
  scripts/                         Maintenance: backup-database.sh, verify-connections.sh,
                                   verify-htaccess.sh, update-paths.php, migrate.php
  docs/                            Specification, setup/agent references, feature guides,
                                   planning/history, and manual verification reports
  uploads/                         proposals/ chapters/ manuscripts/ defense/
  .github/agents/                  Nine RMS specialists and workflow coordinator
  .github/skills/                  Shared skills, task index, and snapshot catalog
```

Research submission and chapter work is implemented in `pages/researcher/`; the matching student URLs remain thin compatibility redirects. Staff permissions use 5 modules with 3 sub-pages each, defined by `rmsPermissionModules()` in `includes/auth.php`.

For shared Copilot/local agent routing and skills, see [Copilot workflow setup](docs/COPILOT_WORKFLOW.md).

## Main Features

- **Authentication** — role-aware login, login rate limiting, public registration, email verification links, and role-based approval (students activate immediately; faculty/staff wait for admin approval)
- **Student workflow** — submit titles, sequential chapters, and a complete manuscript; track authorized review decisions, adviser requests, and existing project history
- **Faculty workflow** — use the shared submission workflow, manage advising and research records, and prepare institutional documents through the mapping and generation workspace
- **Admin tools** — analytics, user/research oversight, reports, activity logs, backups, publication classifications, and public research releases
- **Research office staff** — staff dashboard, authorized title/chapter/final-manuscript review, defense scheduling, legacy milestone verification, revision returns, document verification, and contact-message management
- **Shared modules** — messaging, notifications, profiles, calendar, typed research review, computed implementation progress, multi-day research activities with audience and attendance management, activity budgets and participant notices, publications, and intellectual-property records
- **Email notifications** — registration verification, account approval, and contact-form replies via PHPMailer (SMTP or `mail()`)
- **Public site** — homepage, About Us page with research-feature summary and contact form, public research archive, and branded error pages
- **Premium design language** — role-tinted academic platform with design tokens (`css/tokens.php`), dark sidebar + role accent (Admin orange, Staff teal, Faculty blue, Student purple), emoji icons, 8-point spacing grid, rounded 16–20px cards (see `CLAUDE.md` "AI Design Constitution")

## Research Workflow (v3)

Title submission and review → sequential chapter approval → complete-manuscript submission and review → approved final manuscript → completed research and outputs.

The EARIST Research Manual 2015 source is in [`docs/reference/Research-Manual_2015.md`](docs/reference/Research-Manual_2015.md); consult it when adding workflow features.

## Configuration (.env)

All configuration comes from `.env` (see `.env.example`). Key variables:

```text
DB_HOST, DB_USER, DB_PASS, DB_NAME      MySQL connection
SITE_URL                                Base URL (e.g., http://localhost/rms/)
SESSION_TIMEOUT                         Session lifetime in seconds (default: 1800)
MAX_UPLOAD_SIZE                         Max upload size in bytes (default: 10MB)
ALLOWED_FILE_TYPES                      Comma-separated upload extension whitelist
BCRYPT_COST                             Password hashing cost (default: 12)
EARIST_LIVE_GENERATION_ENABLED          First-run default for document generation (default: 0; saved Admin setting takes precedence)
MAIL_MAILER / MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD
MAIL_FROM_ADDRESS / MAIL_FROM_NAME      SMTP or mail() settings for notifications
APP_ENV / APP_DEBUG                     Development or production mode
```

## Security Notes

- Database credentials live in `.env` (gitignored), never in code
- Production deployments must use HTTPS. Configure the production Apache virtual host to set `RMS_FORCE_HTTPS=1`; session cookies use `Secure` in production and `HttpOnly; SameSite=Lax` in web environments.

## Official EARIST document templates

The seven approved EARIST DOCX templates are not distributed in this repository. Obtain the exact files and approved SHA-256 values from the institution's authorized template custodian through an approved secure channel. Do not commit or send these institutional files through an unapproved channel.

Place the files in `templates/earist/blank/` using the exact filenames in `config/earist-template-bindings.json`, then run:

```bash
php scripts/check-earist-templates.php
```

The setup check reads the manifest and reports each template as verified, missing, or mismatched. It exits nonzero unless every required file matches its approved SHA-256. Do not edit, resave, or substitute a template to make the check pass; request an approved manifest/hash update if an official template changes.

Use **Admin → System Settings → Application settings → EARIST document generation** to enable or disable generation for the current site. The saved database setting takes precedence over `EARIST_LIVE_GENERATION_ENABLED`; the environment variable supplies the initial value when no saved setting exists. It defaults to `0`. Enable generation only for an approved controlled test window, then turn it off afterward. Keep the setting off in production unless separately approved.
- Passwords are hashed and verified with PHP bcrypt helpers using `BCRYPT_COST` (default 12)
- Role-gated access through `includes/auth.php` (`requireRole()`, `requireLogin()`)
- All state-changing forms (including login/register) use session-bound CSRF tokens
- Database queries use prepared statements; output is escaped (`rms_escape()`)
- `.htaccess` protection: `includes/`, `database/`, and `config/` blocked; `.env` protected; direct HTTP access to `uploads/` denied so files are served only through authenticated application routes
- Login rate limiting and account activity logging (`activity_log` table)
- Custom 403/404/500 pages with graceful fallback

For production: set `APP_ENV=production`, disable `APP_DEBUG`, configure real SMTP credentials, review upload permissions, and remove or rotate demo accounts.

## Project Status

**Implemented areas:** security hardening (`.htaccess`, `.env` configuration, CSRF, upload soft deletes), the unified student/faculty title, chapter, and final-manuscript workflow, role-based admin/staff modules, email verification and notifications, contact-message management, custom error pages, milestone compatibility routes, defense scheduling, co-researcher team management, and authorized review decisions. See [the system specification](docs/rms-spec.md) for current workflow boundaries.

**Product scope:** Research Management System features and compatibility workflows are described in [the system specification](docs/rms-spec.md). The ORS Consolidation stage from the 2015 Manual is not part of the current adviser-approved title-and-chapter workflow. Consult the dated [progress audit](docs/progress/PROGRESS_AUDIT.md) and [priority plan](docs/planning/PRIORITY_PLAN.md) as historical context, not as a live task list.

See `docs/progress/PROGRESS_AUDIT.md` for the latest audit and `docs/planning/PRIORITY_PLAN.md` for the roadmap.

## Documentation

- `docs/rms-spec.md` — system specification
- `docs/reference/Research-Manual_2015.md` — EARIST workflow reference
- `docs/EMAIL_SYSTEM.md` — email/verification setup guide
- `docs/PUBLICATION_ARCHIVE_IMPLEMENTATION.md` — publication archive rules and current implementation notes
- `docs/FACULTY_RESEARCH_RECORDS.md` — faculty research record behavior
- `docs/EARIST_DOCUMENT_MAPPING.md` and `docs/EARIST_DOCUMENT_GENERATION.md` — institutional document workflows
- `PROJECT_STRUCTURE.md` — detailed file map
- `docs/planning/PRIORITY_PLAN.md` — dated planning snapshot; confirm items against current source before scheduling work
- `CLAUDE.md` — conventions, architecture, and the AI design system

## Troubleshooting

- **Blank page:** confirm MySQL is running, run `composer install` (missing `vendor/` breaks config loading), and check `.env` has correct `DB_*` values.
- **Database import failure:** make sure the database is named `rms_db` and import `database/schema/rms_db.sql`.
- **Upload issues:** confirm the `uploads/` subdirectories are writable by the web server.
- **Email not sending:** verify the `MAIL_*` values in `.env`; use an app password for Gmail SMTP, or set `MAIL_MAILER=mail` for local testing.
- **Login issues:** clear browser cookies, verify the demo users exist, and check PHP error logs.
- **403/404/500 pages:** these are intentional custom pages — follow the links back to login or home.

## First-Run Checklist

- [ ] Start Apache and MySQL
- [ ] Run `composer install`
- [ ] Create `.env` from `.env.example`
- [ ] Create the `rms_db` database
- [ ] Import `database/schema/rms_db.sql`
- [ ] Visit `http://localhost/rms/`
- [ ] Log in with each demo role
- [ ] Test research submission and chapter upload
- [ ] Test review actions with an administrator or explicitly authorized research staff account
- [ ] Register a test account and follow the email verification flow
- [ ] Review `.env` and security settings before production use
