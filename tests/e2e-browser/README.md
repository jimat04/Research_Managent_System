# RMS browser E2E harness

This directory contains an isolated Playwright harness for the local PHP application. It uses the Chrome executable already installed on the machine and does not add browser binaries to the repository.

Prerequisites:

- Apache serves `http://localhost/rms/`.
- The local database is `rms_db`.
- Node.js and npm are installed.
- Test credentials are supplied through environment variables only.

From PowerShell:

```powershell
cd C:\xampp\htdocs\rms\tests\e2e-browser
npm.cmd install
$env:RMS_E2E_TEST_PASSWORD = '<disposable test password>'
$env:RMS_E2E_STAFF_EMAIL = '<authorized local staff email>'
$env:RMS_E2E_STAFF_PASSWORD = '<staff password>'
$env:RMS_E2E_ADMIN_EMAIL = '<authorized local admin email>'
$env:RMS_E2E_ADMIN_PASSWORD = '<admin password>'
npm.cmd test
```

The harness creates and removes its own disposable registration account. It does not commit credentials, verification tokens, cookies, or other secrets.
