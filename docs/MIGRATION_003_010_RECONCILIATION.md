# Migration 003 and 010 reconciliation

Date reviewed: 2026-10-10

> Database observations below describe the local database and migration-runner state at the time of this reconciliation. They are not a current live-database snapshot; inspect the target database and migration ledger before applying or resolving a migration.

## Migration 003

`003_add_email_verification.sql` is required by the current application. Registration calls `sendVerificationEmail()`, which writes `email_verification_token` and `email_verification_expires`. `public/verify-email.php` reads those fields and marks `email_verified`, while `public/resend-verification.php` reads and refreshes the same token state. The feature is also documented in `README.md` and `docs/EMAIL_SYSTEM.md`.

The live local `rms_db` did not contain any of the three columns or `idx_verification_token`. The migration was therefore applied through `scripts/migrate.php` after a full development-database backup. The canonical fresh-install schema now includes the same columns and index.

The current login handler checks account status and role but does not block an otherwise active account solely because `email_verified` is `0`; the email-system documentation lists hard access enforcement as a separate future enhancement. This reconciliation restores the schema required by the existing registration, token, verification, and resend flows without changing that access policy or locking existing accounts out.

## Migration 010

`010_demo_accounts_resync.sql` performs only updates. It resets the password and active status for the admin, faculty, student, and four office demo accounts, and sets the two faculty demo accounts as reviewers. It does not insert or delete rows.

The current database matches eight of the nine targeted account states. The admin account has a different password hash, which is consistent with a credential changed after the demo sync. The runner now verifies every value changed by the migration, plus the role predicates required by its conditional updates. If any target is missing or has drifted, the migration remains `needs-verifier` and is not executed or marked applied, protecting the changed credential and preserving migration history.

Migration 010 is intentionally unresolved until the admin credential is reconciled by an authorized operator. No data was overwritten to force verification.
