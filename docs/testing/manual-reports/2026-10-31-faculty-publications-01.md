# Faculty publication page separation

- Date: 2026-10-31 (Asia/Taipei)
- Scope: Dedicated faculty list, add/edit, detail, and reports routes; legacy compatibility and shared archive regression checks.
- Implementation: Followed the local RMS page-builder definition. No database migrations, record mutations, or application interactions were performed by the assistant.
- Existing work: The worktree already contains extensive unrelated tracked changes and untracked archive components. Preserve them.

## Graph and source context

- Question: Which handlers, permissions, navigation, documents, and notifications depend on publication routes?
- Vocabulary: `publications`, `publication`.
- Lookup: `rtk proxy graphify query "publications publication" --budget 1500`.
- Existing graph identifies `pages/shared/publications.php:L1`, but its helper nodes at L16/L21/L30 describe an older implementation. Those helpers no longer exist in the current four-line entry page. Old E2E nodes also refer to removed tests.
- Confirmed against current source: shared and faculty entry pages use `includes/archival-record-page.php`, which dispatches list/form/detail includes; `archiveCan()` in `includes/archival-records.php` controls record access; the list separately restricts faculty reports/exports to ownership; document links retain the shared download handler.
- Limitation: Graph coverage of the current archive components and new faculty routes is stale/missing. No graph rebuild or installation was performed.

## Assistant checks

| Check | Status | Evidence |
|---|---|---|
| Immediate controller syntax check | Passed | `rtk proxy php -l includes/archival-record-page.php`: no syntax errors. |
| Immediate list syntax check | Passed | `rtk proxy php -l includes/archival-record-list.php`: no syntax errors. |
| All changed PHP syntax | Passed | `php -l` completed successfully for all 12 changed PHP files listed below, invoked through `rtk proxy powershell`. |
| Final source/diff review | Passed (static only) | Reviewed explicit faculty gate before record loading, GET-only redirects with supported query keys, existing POST validation, owner-only reports/CSV, faculty navigation, dual notification URLs, and shared document handler. No schema or permission-rule changes. |
| Focused tracked diff whitespace | Passed | `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check -- includes/auth.php includes/faculty-shell.php pages/faculty/faculty-dashboard.php pages/faculty/faculty-profile.php includes/archival-record-page.php includes/archival-record-list.php includes/archival-record-detail.php includes/archival-record-ui.php` equivalent executed through PowerShell; exit 0. Git warned about existing LF/CRLF normalization on the dashboard. Untracked components were reviewed directly. |

Linted files:

- `pages/faculty/faculty-publications.php`
- `pages/faculty/faculty-publication-form.php`
- `pages/faculty/faculty-publication-detail.php`
- `pages/faculty/faculty-publication-reports.php`
- `pages/faculty/faculty-dashboard.php`
- `pages/faculty/faculty-profile.php`
- `includes/archival-record-page.php`
- `includes/archival-record-list.php`
- `includes/archival-record-detail.php`
- `includes/archival-record-ui.php`
- `includes/faculty-shell.php`
- `includes/auth.php`

The graph lookup was repeated after routing changes and returned the same stale publication nodes. Targeted current-source review covered the new dependencies. Searches found no CSS selector tied to the old `faculty-page--publications` class. The review also confirmed that copyright requests still pass through their existing route adapter and that publication-only routing does not apply to them. Browser rendering, persistence, downloads, notification counts, and actual access responses remain unverified.

## User-operated browser checks

No browser results have been observed. Use authorized accounts and disposable records only. Guide one action at a time and wait for the user's observation. Missing prerequisites are Blocked, not bypassed.

| ID | Role / scenario | Expected result | Status |
|---|---|---|---|
| F01 | Faculty opens Publications | Faculty list URL, blue shell, one active Publications navigation item. | Pending |
| F02 | Faculty dashboard/profile publication links | List/add actions use faculty URLs. | Pending |
| F03 | Faculty search, filters, pagination, empty results | Existing behavior and owned/linked visibility; pagination retains filters. | Pending |
| F04 | Faculty adds/edits a disposable owned publication | Existing validation and upload behavior; save goes to faculty details; refresh retains data; cancel/back use faculty routes. | Pending |
| F05 | Faculty opens Reports and paginates | Owner-only summaries/records; separate reports URL; count cards, filter form, and Add record are omitted; inherited filters remain in report/CSV/pagination links; All publications returns to list. | Pending |
| F06 | Faculty prints/exports filtered reports | Print works; CSV contains all matching owned records and excludes contributor-only records. | Pending |
| F07 | Faculty opens documents and history | Shared secured preview/download and previous-document links work; history paging stays on faculty details; legacy notifications remain visible. | Pending |
| F08 | Contributor faculty opens linked record | View/download allowed where previously allowed; editing forbidden; record excluded from own reports. | Pending |
| F09 | Faculty opens unauthorized/missing/malformed record IDs | Existing 403/404 boundaries; no record/document disclosure. | Pending |
| F10 | Non-faculty opens any faculty publication route | Login required; authenticated non-faculty receives 403, including administrators. | Pending |
| F11 | Faculty follows legacy shared GET links | List/form/detail/report/CSV route correctly with supported parameters and document/history fragments. | Pending |
| F12 | Existing shared POST compatibility | Source checks preserve handlers, CSRF, access checks, and faculty success destinations; browser observation only if a naturally open pre-change form is available. | Pending |
| F13 | Admin/staff/student shared publication and copyright pages | Existing role permissions, rendering, report scope, documents, and navigation remain functional. | Pending |

## Failures, decisions, and retests

- No application failures observed; browser verification has not started.

## Resume / handoff

- Last user-provided visual evidence: screenshot shows the Publications section open with one active child also labeled Publications. This does not establish the URL or other workflow checks.
- Next browser action: refresh the faculty Reports page and confirm count cards, search/filter form, and Add record are absent.
- User-performed data changes: none observed.
- Cleanup: none performed; any later cleanup is user-operated and limited to session-created records.

## Follow-up: faculty sidebar links

- User selected separate links: My Publications, Add Publication, and Reports.
- Updated only the faculty navigation entries and the faculty publication active-page selection in the archive controller/shell adapter. Reused existing icons, navigation markup, keyboard behavior, and styles. No schema, record, or permission changes.
- Active mapping: list/detail/edit highlight My Publications; new-record form highlights Add Publication; report page highlights Reports. The section opens through the existing active-child behavior.
- Graph lookup repeated with `publications publication`, budget 1500; same stale archive coverage. Verified against the current controller, shell adapter, faculty navigation renderer, and stylesheet.
- Static checks: `php -l` passed for `includes/faculty-shell.php`, `includes/archival-record-page.php`, and `includes/archival-record-ui.php`; focused faculty-shell `git diff --check` produced no errors. Reviewed all three edited sections. Initial combined check command had a shell quoting error and was rerun successfully; no application interaction occurred.
- Browser checks Pending: refreshed three-link display; Add Publication link and highlight; Reports link and highlight; My Publications highlight on details/edit; keyboard focus and narrow-screen sidebar behavior.

## Follow-up: report-only presentation

- User supplied screenshots of count cards, the filter form, and Add record, and requested their removal from Reports.
- Scoped the display condition to faculty publication reports. Omitted those three elements from the rendered report HTML; My Publications and other role/module views retain their existing controls.
- Kept report summaries, supporting record table, print, CSV, navigation, existing query filters, owner-only scope, and pagination. Changed the report empty-state text so it no longer directs the user to removed controls. No database or permission changes.
- Graph lookup repeated using `publications publication`, budget 1500; coverage remains stale. Current-source review confirmed both display regions are controlled by the same faculty-report condition, with summaries and records outside it.
- Assistant checks: `rtk proxy php -l includes/archival-record-list.php` passed; reviewed the exact edited lines against the pre-edit in-memory source. No browser interaction or automated application request performed.
- Browser checks Pending: removed controls absent on faculty Reports; summaries/print/CSV still available; My Publications still has count cards/filter/Add record; empty report copy; other role/module views unchanged.

## Follow-up: remove faculty publication Back button — 2026-11-01

- User requested removal of the Back button on `faculty-publication-form.php`.
- Source confirmed this route uses `includes/archival-record-form.php`. The existing `facultyPublication` flag identifies faculty publication requests, including add/edit and legacy shared POST validation responses.
- Wrapped only the heading Back link in `if (!$facultyPublication)`. Cancel/save actions and other role/module Back links retain their existing behavior. No data, permissions, CSS, or handlers changed.
- Graph lookup: `rtk proxy graphify query "publication" --budget 1000`. The graph points to `pages/shared/publication-form.php:L1` and obsolete helpers at L16/L21, so current route/controller/form source was used to confirm the relationship. No rebuild.
- Assistant/static: `rtk proxy php -l includes/archival-record-form.php` passed. Reviewed the one-line patch for scope and preserved links.
- Browser verification Pending: faculty refreshes the publication form; heading Back button should be absent. Add/edit rendering and other role/module Back links remain unverified in the browser.
- Next action: faculty refreshes the currently open publication form and reports whether Back is gone.
