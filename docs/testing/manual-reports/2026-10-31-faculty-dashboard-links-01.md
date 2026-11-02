# Verification: faculty dashboard links

- Date: 2026-10-31 (Asia/Taipei)
- Assistant: Codex
- Target: correct navigation from the faculty dashboard under the configured SITE_URL, including the analytics selector and chapter-cell redirects.
- Environment URL: http://localhost/rms/pages/faculty/faculty-dashboard.php
- Role: faculty; no disposable records or uploads needed for navigation checks.
- Session state: static checks complete; awaiting user-operated browser verification.

## Graphify and source context

- Question: which pages, components, and navigation handlers own the faculty dashboard links?
- Selected actual vocabulary: faculty, dashboard, publication.
- Read-only command: `rtk proxy graphify query "faculty dashboard publication" --budget 1500`.
- Result: 27 nodes and 22 edges; Graphify printed the full result at approximately 1758 tokens. No edges were dropped.
- Relevant graph locations: `pages/faculty/faculty-dashboard.php:L1`, `includes/faculty-shell.php:L1`, `pages/shared/publications.php:L1`. Graph shell function locations are stale.
- Coverage limitation: no current dashboard component/service nodes were found in the focused vocabulary inspection. Current source overrides the old architecture/specialist descriptions that still describe delegation to the student dashboard.
- Source-confirmed relationships: dashboard constructs `$urls`, passes these through `includes/faculty-dashboard/overview.php` to component templates, and loads `js/faculty-dashboard.js` through the faculty shell.
- Current destinations: advising assignments, engagement creation/history, faculty profile, shared publication listing/form, and shared research detail. Faculty detail access remains assignment-gated in `pages/shared/research-detail.php`; publication creation permits the faculty owner role in `pages/shared/publication-form.php`.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / source | Trace dashboard URLs and target pages | Site-relative deployment prefix preserved; existing target pages used | Original map used seven root-relative `/pages/` URLs, including missing review queue, engagements, and publication filenames; corrected in the requested fix | Passed |
| S2 | Assistant / PHP syntax | Lint four changed PHP files | No syntax errors | All four PHP lint commands passed | Passed |
| S3 | Assistant / JavaScript syntax | Parse dashboard JavaScript | No syntax errors | `node --check` exited successfully | Passed |
| S4 | Assistant / filesystem | Extract target paths from dashboard, queue, and matrix PHP; verify files exist | All seven unique PHP destinations exist | Python check reported seven destinations and no missing files; legacy JavaScript researcher-detail redirect absent | Passed |
| S5 | Assistant / source | Inspect analytics selector and link markup | Explicit analytics request opens section despite stored collapsed state; link outside toggle button | PHP and JS handle `view=analytics`; dashboard link includes existing `analytics-heading` fragment; anchor moved outside button | Passed |
| S6 | Assistant / diff | Check affected tracked diff whitespace | No whitespace errors | Scoped `git diff --check` passed; Git reported line-ending normalization warning only | Passed |
| B1 | User / faculty / dashboard | Click Add Engagement | Opens `/rms/pages/faculty/faculty-engagement.php` with engagement form | No user observation yet | Pending |
| B2 | User / faculty / dashboard | Check remaining profile, advising, engagement-history, and publication links one at a time | Each opens its existing faculty/shared destination under `/rms/` | No user observation yet | Pending |
| B3 | User / faculty / dashboard | Click Research Analytics, including with previously collapsed analytics | Analytics content visible; hero link targets analytics section | No user observation yet | Pending |
| B4 | User / faculty / dashboard with assigned project | Click a populated chapter cell | Opens the assigned project in shared research detail | No user observation yet; an assigned project with chapters is prerequisite | Pending |
| B5 | User / faculty / research detail | Open an existing disposable project outside this faculty account's assignments | Existing access-denied behavior preserved | No user observation or suitable disposable project confirmed | Pending |

## Failures, decisions, fixes, and retests

- The user's original redirect-link fix request authorizes the source corrections recorded above. No new browser failure has been reported.
- Reused SITE_URL, existing destination pages, existing access checks, component URL inputs, and shell script loading. No new pages, dependencies, permission grants, database changes, or application interactions.
- Changed the queue overview link label to View Advising because the existing faculty destination is the assignment overview; formal review remains controlled by existing backend authorization.
- Existing user changes were preserved, including the independent faculty dashboard implementation and untracked components/services. Git's full dashboard diff includes those pre-existing edits; this task changes only its URL map plus the identified component/JS routing behavior.

## Assistant-run checks

- `rtk proxy C:\xampp\php\php.exe -l pages/faculty/faculty-dashboard.php`: passed.
- `rtk proxy C:\xampp\php\php.exe -l includes/faculty-dashboard/components/review-queue.php`: passed; repeated after final link-label edit.
- `rtk proxy C:\xampp\php\php.exe -l includes/faculty-dashboard/components/advisee-matrix.php`: passed.
- `rtk proxy C:\xampp\php\php.exe -l includes/faculty-dashboard/components/analytics-preview.php`: passed.
- `rtk proxy node --check js/faculty-dashboard.js`: passed.
- Read-only Python source/file checks: all seven route targets exist; removed legacy chapter redirect absent. No application execution or database connection.
- Scoped `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check`: passed. Untracked component/script edits additionally reviewed directly.
- No browser or runtime authorization result is inferred from these static checks.

## Resume / handoff

- Last user-confirmed state: no browser login/page state confirmed.
- Next pending action: as faculty on the dashboard, click Add Engagement and confirm the displayed page and URL.
- Remaining gaps: browser navigation, analytics expansion/rendering, populated chapter-cell navigation, and user-operated assignment boundary check.
- User-performed data changes: none reported. No cleanup needed.
