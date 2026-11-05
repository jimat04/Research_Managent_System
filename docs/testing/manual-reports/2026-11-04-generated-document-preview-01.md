# Verification: Generated Document Preview

- Date/time: 2026-11-04, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Faculty generated-document history previews saved primary DOCX and QSF companion files; Admin Annex D history previews its saved DOCX. Preview does not create or update history.
- Environment URL: Configured fallback is `http://localhost/rms/`; live runtime URL not confirmed.
- Roles and disposable records/files: Faculty generated history and Admin Annex D history; use only already-approved disposable records if available. No record or file created by the assistant.
- Session state: Finished; user confirmed all outstanding browser checks passed after the full-screen grid centering fix.

## Graphify and source context

- Question and selected graph vocabulary: How do RMS preview helpers relate to secured generated-document history? Selected available graph terms: `rmsDocumentPreviewContentType()` and `document-preview.php`.
- Query/lookup and result: Initial query `graphify query "rmsDocumentPreviewContentType() document-preview.php" --budget 1500`; BFS depth 2 returned 16 nodes. Follow-up query `graphify query "faculty-shell.php document-preview.php" --budget 1500` returned 27 nodes/23 edges with an over-budget notice. The repeat query at budget 1000 returned 26 of 27 nodes and was truncated. Available edges connect the Faculty shell to its close/render helpers and the shared content-type helper to the preview helper. Neither query covers the new EARIST history route, dialog CSS, or DOCX renderer.
- Relevant source locations and verified relationships: `pages/shared/download-earist-document.php:7-21` requires login, resolves Faculty/Admin history by scope, and returns DOCX bytes. `includes/earist-document-generation.php:333-359` scopes Faculty history to the current actor, validates the history snapshot and resolves the stored manifest file. `includes/earist-annex-d-service.php:108-111` delegates Admin history to the shared resolver with Admin scope. Current source confirms these details; Graphify did not establish the newer route relationships.
- Missing, inferred, stale, or truncated context: Graphify coverage for EARIST history and the bundled `docx-preview` library is missing. No graph updates or rebuilds were performed.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / PHP CLI | Run `php -l` on the shared UI helper and both history pages | No PHP syntax errors | All three files reported no syntax errors. | Passed |
| S2 | Assistant / Node CLI | Run `node --check` on the preview script | No JavaScript syntax errors | Command exited successfully with no output. | Passed |
| S3 | Assistant / source review | Confirm preview requests use the existing generated-document route and scope resolution | Preview reuses the same stored-file authorization and validation as download | Confirmed from current route and helper source; no separate preview access path was added. | Passed |
| S4 | Assistant / Git diff | Check changed tracked files for whitespace errors | No whitespace errors | `git diff --check` exited successfully; Git emitted only existing LF/CRLF conversion notices. | Passed |
| B1 | User / Faculty / Generated Documents | Open the history page and confirm preview controls appear for saved documents | Saved primary files have Preview DOCX; QSF rows also have Preview QSF companion | User screenshot shows the history page and Preview DOCX controls. A QSF companion row was not shown. | Passed (primary controls) |
| B2 | User / Faculty / Generated Documents | Click Preview DOCX for a saved primary document | Dialog is centered, renders the saved version, offers Download DOCX, and can be closed | User screenshot shows the saved Annex C Version 4 rendering and Download DOCX control, but the dialog is flush left. User requested centering. | Failed (alignment) |
| B2R | User / Faculty / Generated Documents | Reopen the same preview after the first centering fix | Dialog is centered in the viewport; document content and download remain available | User screenshot shows the panel's top-left corner at the viewport midpoint and the panel extending offscreen. Translate-based positioning did not center it. | Failed |
| B2R2 | User / Faculty / Generated Documents | Reopen the same preview after the full-screen grid adjustment | Dialog content is centered in the viewport; document content and download remain available | User reported all outstanding browser checks passed after the latest centering change. | Passed (user confirmation) |
| B3 | User / Faculty / Generated Documents | Click Preview QSF companion on a QSF row | Dialog renders the saved companion; download remains available | User reported all outstanding browser checks passed; no individual observations provided. | Passed (user confirmation) |
| B4 | User / Admin / Annex D History | Click Preview DOCX for a saved Annex D version | Dialog renders the saved version; history remains unchanged | User reported all outstanding browser checks passed; no individual observations provided. | Passed (user confirmation) |
| B5 | User / authorized session and signed-out session | Try the normal preview route for an unauthorized history ID, then while signed out | Unauthorized request is denied and signed-out request requires login | User reported all outstanding browser checks passed; no individual observations provided. | Passed (user confirmation) |
| B6 | User / Faculty / Generated Documents | Reopen history after previewing a document | No new version was created by preview | User reported all outstanding browser checks passed; no individual observations provided. | Passed (user confirmation) |

## Failures, decisions, fixes, and retests

- Follow-up screenshot showed the first fix positioned the panel's top-left at the viewport midpoint and let it overflow. Replaced translate positioning with a full-screen dialog grid and centered inner panel. User later reported all outstanding browser checks passed; B2R2 records the successful retest. Individual observations were not provided.

- User screenshot exposed a left-aligned preview dialog while the DOCX content rendered. User requested “center it.” Updated `.ed-docx-preview` to use explicit fixed positioning at the viewport center while retaining responsive size limits. Retest is pending; the original failure remains recorded as B2.

## Assistant-run checks

- `rtk proxy php -l includes/earist-document-ui.php` — passed.
- `rtk proxy php -l pages/faculty/faculty-generated-documents.php` — passed.
- `rtk proxy php -l pages/admin/admin-earist-annex-d.php` — passed.
- `rtk proxy node --check public/assets/js/earist-document-preview.js` — passed.
- After the first centering attempt, `git diff --check` exited successfully for changed tracked files; Git reported only LF/CRLF conversion notices. After the full-screen grid adjustment, CSS brace counts match (131/131), and `git diff --check` again exited successfully.
- `git diff --check` for changed tracked files — passed; Git reported line-ending conversion notices.
- Read-only source review confirms the new dialog fetches the same authenticated route as the download link. No database or application records were changed by the assistant.

## Resume / handoff

- Last user-confirmed state: User reports all outstanding browser checks passed after the latest centering change.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: No outstanding checks reported. Individual browser observations were not itemized.
- User-performed data changes and cleanup status: None reported.
