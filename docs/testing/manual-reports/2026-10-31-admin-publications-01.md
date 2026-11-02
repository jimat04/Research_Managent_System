# Admin publication page separation

- Date: 2026-10-31 (Asia/Taipei).
- Request: Apply the faculty publication page separation to administrators and connect the admin sidebar.
- Implementation specialist: Local `rms-page-builder` definition; no specialist agent invocation.
- Scope: Four admin-only publication pages, admin sidebar/module routing, archive navigation and reports, notification compatibility.
- Database effects: No migrations or data changes performed. Shared handlers continue to own validation, authorization, uploads, and audit history.
- Existing work: Preserved faculty separation and unrelated changes. Captured the ten existing affected files in a temporary read-only comparison snapshot before editing.

## Graph and source context

- Question: Which publication routes, module launchers, shell active states, forms, documents, and notifications need admin destinations?
- Terms: `publications`, `publication`.
- Command: `rtk proxy graphify query "publications publication" --budget 1500`.
- Result: Graph references `pages/shared/publications.php:L1`, with old helper nodes at L16/L21/L30 and obsolete E2E nodes. Current source has a thin archive entry page. Graph coverage is stale; no rebuild/install performed.
- Current source confirms the shared archive controller dispatches list/form/detail; admin module launcher supports separate `staff_target`; `archiveCan()` preserves admin management and faculty ownership/contributor rules; faculty-only report restriction remains separate from admin reports.

## Assistant checks

| Check | Status | Evidence |
|---|---|---|
| First controller syntax check | Passed | `rtk proxy php -l includes/archival-record-page.php`. |
| First list syntax check | Passed | `rtk proxy php -l includes/archival-record-list.php`. |
| Final PHP syntax checks | Passed | Ran `php -l` through `rtk proxy powershell` on all 14 changed PHP files listed below; all returned no syntax errors. |
| Session diff and source review | Passed (static only) | Compared ten existing files against the pre-edit snapshot using `git diff --no-index --unified=1`; inspected four new entry pages. Role gates precede record loading; GET redirects preserve selectors; POST handlers, document URLs, and faculty owner-only report conditions remain intact. |
| Module destination review | Passed (static only) | Compared all 22 current launcher entries to the snapshot in memory: only three admin publication destinations changed, their staff destinations equal the old shared targets, their permission names match, and every other entry is unchanged. This covers the standard 15 subpage mappings plus additional activity entries. |
| Session diff whitespace | Passed | `git -c core.autocrlf=false -c core.whitespace=blank-at-eol,blank-at-eof,space-before-tab,cr-at-eol diff --no-index --check` against the snapshot passed for all ten existing files. |

Linted files:

- `pages/admin/admin-publications.php`
- `pages/admin/admin-publication-form.php`
- `pages/admin/admin-publication-detail.php`
- `pages/admin/admin-publication-reports.php`
- `includes/archival-record-page.php`
- `includes/archival-record-list.php`
- `includes/archival-record-form.php`
- `includes/archival-record-detail.php`
- `includes/archival-record-ui.php`
- `includes/admin-shell.php`
- `includes/auth.php`
- `pages/admin/admin-research-module.php`
- `pages/admin/admin-reports.php`
- `pages/admin/admin-researchers.php`

## User-operated browser checks

All browser outcomes are Pending. Use normal authorized accounts and disposable records. Guide one action at a time and wait for the observation; never fabricate sessions or send substitute requests. Missing prerequisites are Blocked.

| ID | Role / scenario | Expected outcome | Status |
|---|---|---|---|
| A01 | Admin clicks All Publications | `admin-publications.php` opens in orange admin shell with All Publications highlighted. | Pending |
| A02 | Admin opens Journal Publications / Conference Papers & Presentations / Research Repository | Module launcher opens the new admin list with existing selectors and matching sidebar item. | Pending |
| A03 | Admin filters, paginates, opens reports, clears report filters | Filters/context retained; separate admin reports URL; institute-wide summaries and CSV scope. | Pending |
| A04 | Admin adds/edits disposable publication | Existing validation, owner selection, CSRF and upload behavior; save/back/cancel use admin routes and retain module context; data persists on reopen. | Pending |
| A05 | Admin views documents, history, historical notifications | Shared secured download/preview works; historical links remain readable; history pagination uses admin detail route and section context. | Pending |
| A06 | Admin deactivates/restores disposable publication | Existing permissions, confirmation/form behavior and audit preserved; success redirects to admin detail; availability persists. | Pending |
| A07 | Admin uses legacy shared GET URLs or naturally open pre-change POST form | GET redirects preserve supported selectors/report/export; POST retains existing handler and CSRF, with admin success destination. | Pending |
| A08 | Admin opens invalid/missing record ID; other role opens admin publication route | Existing 404 record behavior; unauthenticated login required; authenticated non-admin denied before record loading. | Pending |
| A09 | Authorized staff opens publication modules | Shared destinations retained; assigned-module and download permissions still apply. | Pending |
| A10 | Faculty/student publication flows and shared copyright/IP pages | Existing role routes/access and owner-only faculty reports preserved. | Pending |
| A11 | Admin researcher profile/report publication links | Direct links use admin detail/report URLs. | Pending |
| A12 | Admin navigation among affected standard subpages | Journal Publications, Conference Presentations, Research Repository, and Research Outputs links remain connected; other standard module routes have no intended destination changes. | Pending |

## Failures and retests

- No application failures observed. An initial patch attempt encountered a PowerShell text-decoding mismatch; it was reapplied using explicit UTF-8 reads. This was a tooling issue, not a browser result.
- Initial whitespace-check settings treated preserved CRLF endings as trailing whitespace and treated the expected no-index difference exit code as failure. Recognizing CRLF and the no-index exit convention resolved the check without changing source line endings.

## Resume

- Last user-confirmed browser state: none.
- Next step: A01, administrator clicks All Publications under Publication & Dissemination.
- User-performed changes/cleanup: none observed; any future cleanup uses supported actions for session-created records only.
