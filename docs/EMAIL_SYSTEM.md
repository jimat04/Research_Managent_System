# Email Notification System - Setup Guide

**Original implementation notes:** August 29, 2026
**Feature:** Student Auto-Approval + Email Verification  

> Historical implementation guide, source-checked October 10, 2026. This does not certify mail delivery in any environment.

---

## 🎯 Overview

The email notification system enables:

1. **Student Auto-Approval** — Students get `status='active'` immediately upon registration
2. **Faculty/Staff Manual Approval** — Faculty and staff remain `status='pending'` until admin approves
3. **Email Verification** — Registration sends a verification email, but verification is not required to log in.
4. **Admin Notifications** — Registration attempts to notify admins when faculty/staff register.
5. **Approval Notifications** — Account activation attempts to notify the user.
6. **Research Status Notification Helper** — A status-change email helper and template exist, but there are no current application call sites; status emails are not automatically sent by the research workflow.

---

## 📦 Dependencies

- **PHPMailer v7.1.1** — Already installed via Composer

---

## 🔧 Configuration

### 1. Database schema

The canonical fresh-install schema already contains the email-verification columns. Apply the email verification migration only when upgrading a legacy database that does not have those columns; follow the current README migration guidance rather than rerunning it on a fresh import.

### 2. Configure email settings in `.env`

Copy these settings to your `.env` file and update with your SMTP credentials:

```env
# Email Configuration
MAIL_MAILER=smtp                          # smtp or mail
MAIL_HOST=smtp.gmail.com                  # SMTP server
MAIL_PORT=587                             # SMTP port (587 for TLS, 465 for SSL)
MAIL_USERNAME=your_email@gmail.com        # SMTP username
MAIL_PASSWORD=your_app_password           # SMTP password (use app password for Gmail)
MAIL_ENCRYPTION=tls                       # tls or ssl
MAIL_FROM_ADDRESS=noreply@rms.edu.ph     # From email
MAIL_FROM_NAME="RMS Research System"      # From name
MAIL_REPLY_TO=support@rms.edu.ph         # Reply-to address
```

### 3. Gmail Setup (Recommended for Testing)

**Step-by-step for Gmail:**

1. Enable 2-factor authentication on your Google account
2. Go to [Google App Passwords](https://myaccount.google.com/apppasswords)
3. Generate a new app password for "Mail"
4. Use this 16-character password in `MAIL_PASSWORD`

**Example `.env` for Gmail:**

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=youremail@gmail.com
MAIL_PASSWORD=abcd efgh ijkl mnop
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@rms.edu.ph
MAIL_FROM_NAME="EARIST RMS"
MAIL_REPLY_TO=youremail@gmail.com
```

### 4. PHP mail() fallback

`includes/email.php` uses PHP `mail()` when usable SMTP settings are not present. This fallback depends on a working mail transport on the host and is not evidence that delivery succeeded.

```env
MAIL_MAILER=mail
```

**Note:** `mail()` requires a properly configured mail server on your system (less reliable than SMTP).

---

## 📧 Email Templates

The system includes 4 professional email templates:

### 1. **Email Verification**
- Sent to all new registrations (students, faculty, staff)
- Contains a 24-hour expiration verification link
- User must click to verify email

### 2. **Account Approval** (Faculty/Staff)
- Sent when admin activates a pending faculty/staff account
- Notifies user they can now log in

### 3. **Pending Approval Notification** (Admin)
- Sent to all active admins when faculty/staff register
- Contains user details and link to user management page

### 4. Research status change (helper only)
- `sendResearchStatusEmail()` and its template are implemented in `includes/email.php`.
- No current page or workflow calls this helper, so users do not automatically receive research-status emails.

---

## 🔐 Registration Flow

### **Students**

1. Student registers via `public/login.php#register`.
2. Account is created with `status='active'`.
3. The application attempts to send a verification email.
4. Student can log in without first verifying the email; email verification is not an access gate.

### **Faculty / Research Staff**

1. Faculty/staff registers via `public/login.php#register`.
2. Account is created with `status='pending'`.
3. Application attempts to send a verification email and notify admins.
4. Admin reviews and activates the account in `pages/admin/admin-users.php`.
5. Application attempts to send an activation email; the user can log in once active.

---

## 📁 New Files Created

| File | Purpose |
|------|---------|
| `includes/email.php` | Core email functions (sendEmail, sendVerificationEmail, etc.) |
| `public/verify-email.php` | Email verification landing page |
| `public/resend-verification.php` | Resend verification email page |
| `database/migrations/003_add_email_verification.sql` | DB schema for email verification |
| `docs/EMAIL_SYSTEM.md` | This documentation file |

---

## 🧪 Testing Checklist

### 1. Test Student Registration

- [ ] Register a new student account
- [ ] Check `status='active'` in database
- [ ] Verify verification email received
- [ ] Click verification link
- [ ] Confirm email verified in database (`email_verified=1`)
- [ ] Log in successfully

### 2. Test Faculty Registration

- [ ] Register a new faculty account
- [ ] Check `status='pending'` in database
- [ ] Verify verification email received by user
- [ ] Verify admin notification email received
- [ ] Admin activates account in User Management
- [ ] Verify approval email received by user
- [ ] Log in successfully

### 3. Test Email Verification

- [ ] Register account
- [ ] Check email for verification link
- [ ] Click link → Success page
- [ ] Try clicking link again → Already verified message
- [ ] Wait 24 hours → Link expired message
- [ ] Use "Resend verification" → New email sent

### 4. Test Email Failures

- [ ] Invalid SMTP credentials → Email fails gracefully, registration still succeeds
- [ ] Invalid token → Error message shown
- [ ] Expired token → Error message shown

---

## 🐛 Troubleshooting

### Email Not Sending

**1. Check `.env` configuration**
```bash
# Verify these are set correctly
grep MAIL_ .env
```

**2. Check PHP error log**
```bash
# Windows XAMPP
tail -n 50 C:\xampp\apache\logs\error.log

# Linux/Mac
tail -n 50 /var/log/apache2/error.log
```

**3. Test SMTP connection manually**
```php
<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email.php';

$result = sendEmail(
    'test@example.com',
    'Test Email',
    '<h1>Test</h1><p>This is a test email from RMS.</p>',
    'Test User'
);

echo $result ? 'Email sent successfully!' : 'Email failed to send.';
```

### Gmail "Less Secure Apps" Error

**Solution:** Use App Passwords instead of your regular Gmail password.

1. Enable 2FA on Google account
2. Generate app password at https://myaccount.google.com/apppasswords
3. Use the 16-character app password in `.env`

### Emails Going to Spam

**Solutions:**

1. **Use a proper domain email** — `noreply@yourdomain.edu` instead of Gmail
2. **Set up SPF records** — Add DNS records for your domain
3. **Use authenticated SMTP** — Always use SMTP with authentication
4. **Professional email templates** — Already included in this system

---

## 🚀 Future Enhancements

### Priority 2 Enhancements

- [ ] **Enforce email verification** — Block unverified users from accessing dashboard
- [ ] **Password reset via email** — Forgot password flow
- [ ] **Welcome email series** — Onboarding emails for new users
- [ ] **Research milestone notifications** — Email on CREC/EREC status changes
- [ ] **Digest emails** — Weekly summary of activity
- [ ] **Email preferences** — Let users control notification settings

### Advanced Features

- [ ] **Queue system** — Background job processing for bulk emails
- [ ] **Email analytics** — Track open rates, click rates
- [ ] **Multi-language support** — Email templates in multiple languages
- [ ] **Rich notifications** — With embedded images and better formatting

---

## 📊 Database Changes

### New Columns in `users` Table

```sql
email_verified               TINYINT(1) DEFAULT 0
email_verification_token     VARCHAR(64) DEFAULT NULL
email_verification_expires   DATETIME DEFAULT NULL
```

### New Index

```sql
INDEX idx_verification_token (email_verification_token)
```

---

## 🔒 Security Considerations

1. **Token Security**
   - 64-character cryptographically random tokens
   - 24-hour expiration
   - One-time use (deleted after verification)

2. **Rate Limiting**
   - Resend verification limited to once per 10 minutes
   - Prevents spam and abuse

3. **SMTP Security**
   - All credentials stored in `.env` (not version controlled)
   - TLS/SSL encryption supported
   - No plain-text password transmission

4. **Email Privacy**
   - User emails never exposed in URLs
   - Tokens are non-sequential and unpredictable
   - Activity logged for audit trail

---

## 📝 Activity Log Entries

The following actions are logged in `activity_log`:

- `Email verified` — When user verifies email
- `Activated user: [name]` — When admin approves account
- `User logged in` — On successful login
- `Updated user account: [name]` — When admin edits user

---

## Implementation status (source checked)

**Present in source:** student registration uses active status; faculty/research-staff registration uses pending status; verification and resend pages/helpers exist; PHPMailer is used; admin/user notification helpers are present. The canonical schema already includes verification fields, so do not rerun the legacy migration on a fresh install.

**Not established by this document:** successful delivery, production SMTP readiness, mandatory verification, or that all optional email templates have current call sites. Configure mail for the deployment and test using non-production accounts.

---

**Generated:** August 29, 2026 12:55 PM (UTC+8)  
**Version:** 1.0  
**Current verification:** This guide documents the August 2026 implementation, not a production mail-delivery test. Configure SMTP and verify delivery on the deployment host before release.
