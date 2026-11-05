# EARIST DOCX editing service

Faculty document editing uses a separately hosted ONLYOFFICE Docs Community service. RMS keeps the source DOCX immutable and publishes callback saves as new history versions. The feature remains disabled unless all required environment settings are valid.

## Before enabling

- Deploy ONLYOFFICE Docs at a stable HTTPS URL reachable by both the Faculty browser and the RMS web server. Configure the editor's JWT secret to exactly match RMS.
- For local development, set these in the gitignored RMS `.env`; for deployment, provide them through the web-server environment or an approved secret manager, outside source control:
  - `EARIST_DOCX_EDITOR_ENABLED=1`
  - `EARIST_ONLYOFFICE_URL=https://docs.example.edu`
  - `EARIST_ONLYOFFICE_JWT_SECRET=` followed by a randomly generated secret of at least 32 characters
  - `EARIST_DOCX_EDITOR_RMS_URL=https://rms.example.edu/` using the RMS address the ONLYOFFICE server can reach for file reads and callbacks. For local Docker Desktop, this may be `http://host.docker.internal:8080/rms/`.
- Ensure PHP cURL is enabled and both servers can reach one another with valid certificates. The ONLYOFFICE callback must reach `pages/shared/earist-document-edit-callback.php`; ONLYOFFICE must also reach the short-lived document URL that RMS provides. In production use HTTPS for both service addresses.
- Review migration `067_earist_document_editing.sql` against the intended local database and apply it only through the approved RMS migration process. The canonical schema in `database/schema/rms_db.sql` includes the same definitions for fresh installs.
- Confirm protected EARIST storage directories and `.htaccess` guards are present and match the file storage guide.
- Complete the institution's AGPL review before deployment. ONLYOFFICE Docs Community is distributed under AGPLv3 and requires the applicable source and branding notices. Review the current [ONLYOFFICE Community licensing FAQ](https://helpcenter.onlyoffice.com/docs/faq/docs-community.aspx), [callback handler documentation](https://api.onlyoffice.com/docs/docs-api/usage-api/callback-handler), and [JWT security documentation](https://api.onlyoffice.com/docs/docs-api/get-started/how-it-works/security/).

For a production deployment, do not set `EARIST_DOCX_EDITOR_ENABLED=1` until service connectivity, JWT request validation, migration state, storage protection, HTTPS, and AGPL/branding acceptance have all been confirmed. Without complete configuration, RMS leaves the Edit DOCX action disabled while preview and download remain available.

## Local Docker Desktop service

The local development instance uses the official `onlyoffice/documentserver` Community image in a container named `rms-onlyoffice`. Docker publishes it at `http://localhost:8081` bound to `127.0.0.1`, with named volumes `rms-docs-data`, `rms-docs-logs`, `rms-docs-lib`, and `rms-docs-db`. It restarts unless explicitly stopped. The downloaded image digest and verification results are recorded in the current manual report.

The RMS `.env` points the browser to `http://localhost:8081` and gives ONLYOFFICE the container-reachable RMS address `http://host.docker.internal:8080/rms/`. The local flag is enabled only for a controlled browser test window; set `EARIST_DOCX_EDITOR_ENABLED=0` again when that window ends. The matching ONLYOFFICE JWT configuration is stored in `%LOCALAPPDATA%\RMS-ONLYOFFICE\documentserver.env`; it is not printed or committed.

This local container uses HTTP and `ALLOW_PRIVATE_IP_ADDRESS=true` so it can reach the XAMPP host. Keep it bound to loopback and use only for local development. Do not reuse this HTTP/private-network configuration in production; production requires reachable HTTPS endpoints, verified JWT behavior, storage protection, and the institution's AGPL/branding acceptance.

## Save and history behavior

- One active editor session is allowed per saved history entry. Sessions expire after two hours; the file read URL expires after ten minutes.
- Callback JWTs are verified with HS256 and the configured shared secret. RMS accepts callbacks only for the session's document key and configured service origin.
- A valid completed DOCX is stored in a fresh private file set. The selected file is replaced in the new set; an unselected QSF companion is carried forward unchanged.
- RMS inserts a new generated-history row with a link to the selected source version. It does not modify the source row or the mapped-answer draft.
