# Activity invitation document

- Date: 2026-10-29 (Asia/Taipei)
- Scope: Let administrators attach or replace a PDF on a research activity. Only authorized activity managers and users actively invited to a scheduled date can stream that one document through an authenticated route.
- Database effect: Added migration 048 and synchronized `database/schema/rms_db.sql`. Migration applied after user authorization and a runtime guard confirmed local rms_db. The runner verified the schema and recorded applied / executed.

## Graph lookup

- Question: Trace activity creation, file upload, invitee records, and authenticated document access.
- Terms: activity, document, invitation, upload, access, research activities.
- Lookup: `graphify query "activity document invitation upload access research_activities" --budget 1100 --context-filter call`.
- Result: Truncated (28 of 51 nodes), with existing activity form and upload endpoint hints plus stale unrelated documentation nodes. Current form, invitation tables, upload helper, and new route were checked directly; graph coverage for the new attachment path is absent.

## Checks

| Check | Role / page | Action | Expected | Result | Status |
|---|---|---|---|---|---|
| S1 | Assistant / changed PHP, migration runner and upload component | PHP syntax checks, JavaScript syntax check, scoped whitespace check | No syntax or whitespace errors | Seven changed PHP files passed `php -l`; `node --check js/file-uploader.js` and scoped `git diff --check` passed | Passed |
| B1 | Integrated browser / administrator / activity form after migration 048 | Upload a PDF while creating/editing an activity and save | PDF is attached; activity detail shows its document link | User confirmed View document appears after saving the uploaded PDF | Passed |
| B2 | Integrated browser / invited student or faculty / activity detail and document link | Open the attached PDF | Only the PDF is delivered to the invited account | Screenshot shows no document section. Read-only local inspection of activity 74 found document_upload_id NULL and six active linked invitees. No attachment exists for this activity; upload prerequisite must be completed | Blocked |
| B3 | Integrated browser / non-invited user / direct activity document route | Open the same document route | Access is denied | Migration 048 applied; awaiting user observation | Pending |

## Security and implementation notes

- Back-button follow-up: added an HTML document viewer with a Back to activity link and an authenticated same-origin PDF frame. Both viewer and stream execute the existing invitation/management checks. Nonce-based styles and restrictive CSP apply to the viewer. No database changes. PHP lint and scoped whitespace checks passed. Existing graph lacks the new document route; current route and its activity-detail caller were reviewed directly. Browser check Pending: as administrator, open View document and click Back to activity; expect the same activity detail page. Invited-user, narrow-screen, and direct stream denial checks remain Pending.

- Uploads use the existing RMS uploader validation, protected `uploads/` directory, PDF MIME/signature checks, and administrator-only upload authorization.
- The document route resolves only the PDF attached to the requested activity, then checks an active invitation for the signed-in user. It streams the file and exposes no activity roster or other uploads.
- Cancelled dates and withdrawn invitations do not grant access. Activity administrators can replace or remove the document; deleting an activity also attempts to remove its unreferenced PDF upload.
- Migration 048 executed successfully on confirmed local rms_db; a separate status check showed applied / executed. Existing activities receive a NULL document link. No activity or upload records were created. The temporary guard script was removed. The runner emitted session ini warnings because the guard had already loaded the bootstrap; schema verification and execution still succeeded.

- Follow-up diagnosis: Existing Graphify lookup for activity-form.php was truncated and referenced stale test nodes; current activity-detail.php and activity-document.php helper conditions were checked directly. Read-only local data inspection confirmed that the screenshot activity has no document link. No application records were changed; the temporary inspection script was removed. The earlier admin confirmation did not identify an activity ID and does not establish an attachment for activity 74.

- Document panel visual update: User screenshots show the document section present in both views; opening the PDF is still unconfirmed. Added a padded file row, PDF badge, separate filename/size, aligned actions, quieter removal button, keyboard focus outline, and narrow-screen wrapping. PHP lint and scoped diff whitespace checks passed. Browser appearance confirmation pending. Existing graph coverage for this document section is absent; current template and styles reviewed directly.
- Admin button contrast: user screenshot showed black View document text. Current source confirms the admin shell anchor inheritance overrides the generic button text rule. Added a scoped white foreground for the document link, including visited, hover, and focus states. PHP lint and scoped whitespace checks passed; visual retest pending. Current source used because existing graph coverage for this panel is absent.
