# Research Management System (RMS)

A PHP and MySQL research management system for students, faculty advisers, and administrators.

## Requirements

- XAMPP, WAMP, or another Apache/PHP/MySQL stack
- PHP 7.4 or newer
- MySQL or MariaDB
- A modern browser

## Quick Setup

1. Place the project in your local web root:

   ```text
   C:\xampp\htdocs\rms
   ```

2. Start Apache and MySQL from XAMPP.

3. Create a database named `rms_db` in phpMyAdmin.

4. Import the schema and seed data from:

   ```text
   rms_db.sql
   ```

5. Open the app:

   ```text
   http://localhost/rms/
   ```

## Database Files

- `rms_db.sql` contains the main schema and seed data.
- `rms_db_migration.sql` contains migration SQL for existing installations.

## Research Manual 2015

The project keeps its working manual reference in `docs/research-manual-2015.md`. Use that file when adding workflow features so implementation stays aligned with the 2015 research process.

## Demo Credentials

| Role | Email | Password |
| --- | --- | --- |
| Student | jdelacruz@rms.edu.ph | Student@123 |
| Faculty | msantos@rms.edu.ph | Faculty@123 |
| Admin | admin@rms.edu.ph | Admin@123 |

## Project Structure

```text
rms/
  index.php
  login.php
  logout.php
  contact.php
  research-archive.php
  rms_db.sql
  rms_db_migration.sql
  includes/
    config.php
    auth.php
    module-pages.php
  pages/
    student-dashboard.php
    faculty-dashboard.php
    admin-dashboard.php
    submit-research.php
    submit-chapter.php
    faculty-review-detail.php
    module-page.php
  css/
    style.css
    about.css
  uploads/
    chapters/
    proposals/
```

Most secondary dashboard pages use `pages/module-page.php`, which routes through `includes/module-pages.php`.

## Main Features

- Student research submission and chapter upload workflow
- Faculty review, revision, approval, and comment workflow
- Admin dashboards, reports, logs, archive, and backup pages
- Messaging, notifications, profiles, settings, and calendar modules
- Public archive and contact pages

## Security Notes

- Passwords are verified with PHP password hashing helpers.
- User access is role-gated through `includes/auth.php`.
- State-changing forms use session-bound CSRF tokens.
- User-generated output is escaped in the main module rendering paths.

For production use, update the database credentials in `includes/config.php`, enable HTTPS, review upload permissions, and remove or rotate demo accounts.

## Troubleshooting

- Blank page: confirm MySQL is running and `includes/config.php` has the right database settings.
- Database import failure: make sure the database is named `rms_db` and import `rms_db.sql`.
- Upload issues: confirm the `uploads/` subdirectories are writable by the web server.
- Login issues: clear browser cookies, verify demo users exist, and check PHP error logs.

## First-Run Checklist

- [ ] Start Apache and MySQL
- [ ] Create the `rms_db` database
- [ ] Import `rms_db.sql`
- [ ] Visit `http://localhost/rms/`
- [ ] Log in with each demo role
- [ ] Test research submission and chapter upload
- [ ] Test faculty review actions
- [ ] Review `includes/config.php` before production use
