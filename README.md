# Research Management System (RMS)

A PHP and MySQL research management system for students, faculty advisers, research staff, and administrators. Built around the **EARIST Research Manual 2015** workflow — from proposal submission, through CREC/EREC evaluation and defense, to archive and publication.

**Stack:** PHP 7.4+ · MySQL/MariaDB · Apache (XAMPP/WAMP) · Composer (phpdotenv, PHPMailer)

## Requirements

- XAMPP, WAMP, or another Apache/PHP/MySQL stack
- PHP 7.4 or newer
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

6. Import the main schema and seed data:

   ```text
   database/schema/rms_db.sql
   ```

   For an existing installation, apply the incremental scripts in `database/migrations/` instead.

7. Open the app — the root `index.php` redirects to the public entry point:

   ```text
   http://localhost/rms/
   ```

## Demo Credentials

| Role | Email | Password |
| --- | --- | --- |
| Student | jdelacruz@rms.edu.ph | Student@123 |
| Faculty | msantos@rms.edu.ph | Faculty@123 |
| Admin | admin@rms.edu.ph | Admin@123 |

## Project Structure

```text
rms/
  index.php                        Root redirector → public/index.php
  public/                          Public pages (no login required)
    index.php                      Homepage with statistics
    login.php                      Login + registration (role tabs, CSRF, rate limiting)
    logout.php                     Session termination
    about.php / features.php / contact.php / research-archive.php
    verify-email.php / resend-verification.php
    403.php / 404.php / 500.php    Custom error pages
  pages/                           Authenticated pages, organized by role
    student/                       student-dashboard, my-research, submit-research,
                                   submit-chapter, my-documents, progress-tracking
    faculty/                       faculty-dashboard, faculty-submissions, faculty-review,
                                   faculty-review-detail, faculty-students, faculty-reports
    admin/                         admin-dashboard, admin-users, admin-research,
                                   admin-archive, admin-reports, admin-logs, admin-backup
    staff/                         staff-dashboard, contact-messages
    shared/                        module-page.php + messages, notifications, profile,
                                   settings, calendar, research-archive, research-detail, view-research
  includes/                        Core backend
    config.php                     Loads .env, database connection
    auth.php                       Auth, roles, CSRF, hashing, logging helpers
    module-pages.php               Module renderer + form action handlers
    email.php                      PHPMailer wrapper (verification, notifications, replies)
    contact-handler.php            Public contact form handler
    admin-shell.php                Shared admin page shell
  database/
    schema/rms_db.sql              Main schema + seed data (19 tables)
    migrations/                    Incremental migration scripts
  config/                          settings.json, skills-lock.json (dev tooling)
  css/                             style.css, about.css, admin-shell.css, tokens.css/.php
  scripts/                         Maintenance: backup-database.sh, verify-connections.sh,
                                   verify-htaccess.sh, update-paths.php
  docs/                            rms-spec.md, research-manual-2015.md,
                                   planning/, progress/ reports
  uploads/                         proposals/ chapters/ manuscripts/ defense/
  .github/agents/                  AI agent definitions (db, ui, security, testing, docs, page-builder)
```

Secondary pages (e.g., `messages.php`) delegate to `pages/shared/module-page.php`, which routes rendering and actions through `includes/module-pages.php`.

## Main Features

- **Authentication** — login with role tabs and wrong-role guidance, login rate limiting, public registration, email verification links, and role-based approval (students activate immediately; faculty/staff wait for admin approval)
- **Student workflow** — create/submit research, upload chapters and proposals, track the CREC/EREC status workflow and manual milestones, view adviser feedback
- **Faculty workflow** — assigned advisees, submission review queue, CREC/EREC evaluation, revision requests, approvals, comments, and reports
- **Admin tools** — analytics dashboard, user management, research oversight, archive, reports, activity logs, and database backups
- **Research office staff** — staff dashboard plus contact-message management
- **Shared modules** — messaging, notifications, profiles, settings, and calendar
- **Email notifications** — registration verification, account approval, and contact-form replies via PHPMailer (SMTP or `mail()`)
- **Public site** — homepage, about, features, contact form, public research archive, and branded error pages
- **Premium design language** — editorial/academic theme with design tokens (`css/tokens.php`), charcoal + academic gold palette, Lucide icons, bento-grid dashboards

## Research Manual 2015

The system implements the EARIST Research Manual 2015 workflow:

Proposal Submission → CREC Evaluation → EREC Research Forum → Approval/Revision → President Approval → MOU/NDA → Implementation → Midway Progress → Terminal Report → Final Bound Report → Research Colloquium → Archive

The working reference lives in `docs/research-manual-2015.md` — keep implementation aligned with it when adding workflow features.

## Configuration (.env)

All configuration comes from `.env` (see `.env.example`). Key variables:

```text
DB_HOST, DB_USER, DB_PASS, DB_NAME      MySQL connection
SITE_URL                                Base URL (e.g., http://localhost/rms/)
SESSION_TIMEOUT                         Session lifetime in seconds (default: 1800)
MAX_UPLOAD_SIZE                         Max upload size in bytes (default: 10MB)
ALLOWED_FILE_TYPES                      Comma-separated upload extension whitelist
BCRYPT_COST                             Password hashing cost (default: 12)
MAIL_MAILER / MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD
MAIL_FROM_ADDRESS / MAIL_FROM_NAME      SMTP or mail() settings for notifications
APP_ENV / APP_DEBUG                     Development or production mode
```

## Security Notes

- Database credentials live in `.env` (gitignored), never in code
- Passwords are hashed and verified with PHP bcrypt helpers (cost 12)
- Role-gated access through `includes/auth.php` (`requireRole()`, `requireLogin()`)
- All state-changing forms (including login/register) use session-bound CSRF tokens
- Database queries use prepared statements; output is escaped (`rms_escape()`)
- `.htaccess` protection: `includes/`, `database/`, `config/` blocked; security headers; `.env` protected; `uploads/` blocks PHP execution
- Login rate limiting and account activity logging (`activity_log` table)
- Custom 403/404/500 pages with graceful fallback

For production: set `APP_ENV=production`, disable `APP_DEBUG`, configure real SMTP credentials, review upload permissions, and remove or rotate demo accounts.

## Project Status

**Completed:** security hardening (`.htaccess`, `.env` migration, CSRF, rate limiting), page restructure into `public/` + role-based `pages/`, module-page system, student/faculty/admin/staff dashboards, email verification + notification system, contact-message management, custom error pages, and the design-token UI layer.

**In progress / known gaps:** some secondary pages are thin module stubs (calendar, messages, settings, etc.), co-researcher/team-member UI on submissions, upload forms for manual-milestone documents (MOU/NDA, progress and terminal reports), and admin notification on new submissions. Tracked in `TODO.md`.

See `docs/progress/PROGRESS_AUDIT.md` for the latest audit and `docs/planning/PRIORITY_PLAN.md` for the roadmap.

## Documentation

- `docs/rms-spec.md` — system specification
- `docs/research-manual-2015.md` — EARIST workflow reference
- `docs/EMAIL_SYSTEM.md` — email/verification setup guide
- `PROJECT_STRUCTURE.md` — detailed file map
- `TODO.md` — current task list
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
- [ ] Test faculty review actions
- [ ] Register a test account and follow the email verification flow
- [ ] Review `.env` and security settings before production use
