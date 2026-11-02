---
type: "query"
date: "2026-10-21T17:22:46.575746+00:00"
question: "Why does hasModulePermission() connect Shared Module Permissions to the shared pages and helpers?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["hasModulePermission()", "getStaffPermissionMatrix()", "requireModulePermission()", "rms_submission_can_review()", "handleRmsUpload()"]
---

# Q: Why does hasModulePermission() connect Shared Module Permissions to the shared pages and helpers?

## Answer

The graph places hasModulePermission() (includes/auth.php:L504) as a cross-community bridge. It has extracted calls to getStaffPermissionMatrix() (L521) and requireModulePermission() (L602). It also links to handleRmsUpload() (includes/file-uploader.php:L254), rms_submission_can_review() (includes/research-workflow.php:L106), and closures in shared activity/publication/output pages (activities.php:L84, activity-detail.php:L48, activity-form.php:L56, implementation-outputs.php:L587, publication-form.php:L42). Those cross-file calls are marked INFERRED, so treat them as candidate relationships rather than verified calls; this graph traversal does not establish why they occur. Expanded from the original question via graph vocabulary: [module, permission, auth, shared, activity, research, workflow, staff, implementation].

## Outcome

- Signal: useful

## Source Nodes

- hasModulePermission()
- getStaffPermissionMatrix()
- requireModulePermission()
- rms_submission_can_review()
- handleRmsUpload()