# EARIST Google Docs editing

Google Docs is an optional external editor for Faculty-owned EARIST generated DOCX files. RMS uploads a working copy into the Faculty user's Google Drive and opens Google's Office-file editor in a new browser tab. RMS retains the original version; only **Import latest edit** creates a new immutable RMS history version. Google Drive files remain in the user's Drive after disconnecting RMS.

Google Docs is opened in a separate browser tab. RMS retains preview, download, and immutable document history.

## Configure Google Cloud

1. Create or select a Google Cloud project and enable the Google Drive API.
2. Configure the OAuth consent screen for external users. Add the `drive.file`, `openid`, and `email` scopes and the expected test users while the app remains in testing. Complete Google's requested verification before production use. Google testing-mode refresh tokens may expire after seven days; use a published/verified OAuth app for sustained operation.
3. Create an OAuth client of type **Web application**. Register this exact authorized redirect URI:
   `https://<RMS-host>/<RMS-base-path>/pages/faculty/earist-google-drive-callback.php`
   For local XAMPP, the default with `SITE_URL=http://localhost/rms/` is `http://localhost/rms/pages/faculty/earist-google-drive-callback.php`.
4. Put these values in the gitignored RMS `.env` or the server's secret manager. Generate the token key from 32 random bytes and encode those bytes as 64 lowercase hexadecimal characters.

```dotenv
EARIST_GOOGLE_DRIVE_ENABLED=1
EARIST_GOOGLE_CLIENT_ID=...
EARIST_GOOGLE_CLIENT_SECRET=...
EARIST_GOOGLE_REDIRECT_URI=https://<RMS-host>/<RMS-base-path>/pages/faculty/earist-google-drive-callback.php
EARIST_GOOGLE_TOKEN_ENCRYPTION_KEY=<64 lowercase hex characters>
```

The redirect URI must use the same scheme, host, port, and RMS path as `SITE_URL`. Production must use HTTPS. Do not commit the client secret or encryption key. Google integration remains disabled unless every setting and the required PHP cURL/OpenSSL extensions are available.

## Apply the database migration

Review migration `068_earist_google_drive_editing.sql` and confirm the intended local target is `rms_db`. Check the migration ledger and use the RMS migration runner; do not execute the SQL manually. The migration stores encrypted per-Faculty refresh tokens and metadata linking user-created Drive copies to their source history versions. The canonical fresh-install schema includes both tables.

## Faculty workflow and access

1. In **Faculty → Generated Documents**, connect a Google account. RMS supports any Google account and requests access only to files created through the integration.
2. Select **Edit in Google Docs** for a generated DOCX. RMS uploads a working copy into the connected account's Drive and opens its Google-provided link in a new tab. Keep the file as an Office DOCX in Google Docs; converting it to a native Google document prevents RMS from importing it as a DOCX.
3. Return to RMS and choose **Import latest edit**. RMS downloads the DOCX through the Drive API, checks its package and size, carries forward the unedited QSF companion when applicable, and saves an immutable new history version.
4. Use **Disconnect Google Drive** to revoke the saved authorization and remove the encrypted refresh token from RMS. RMS does not delete any Drive copies. If Google does not confirm revocation, remove RMS from the Google Account security page as well.

The connection belongs to the signed-in Faculty RMS account. Every open/import request rechecks current Faculty ownership, Google account identity, Drive file metadata and DOCX integrity. The Google API never changes RMS's original history row. Admin Annex D and student proposal/chapter/manuscript submissions are not part of this integration.

## Troubleshooting

- **Google Docs editing is not enabled:** confirm the feature flag, credentials, redirect URI, encryption key format and PHP extensions; then reload the page.
- **Redirect URI mismatch:** the Google Cloud client URI must exactly match `EARIST_GOOGLE_REDIRECT_URI`.
- **Reconnect required:** authorization may have expired or been revoked. Connect the Google account again.
- **File cannot be imported:** confirm that the Drive file is still a DOCX, remains available to the same Google account, and is within RMS's configured DOCX storage limit.
- Google API failures are logged by exception class only; tokens and document contents are not written to RMS logs.
