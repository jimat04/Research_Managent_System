# Verification: staff sidebar alignment

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Staff sidebar uses matching admin names for shared research areas, retains staff-only tools in relevant groups, and exposes only routes supported by staff role/permission checks.
- Environment URL: Not confirmed; use the running local RMS URL already open in the integrated browser.
- Roles and disposable records/files: Research staff; no records changed.
- Session state: In progress; browser observation pending.

## Graphify and source context
- Question and selected graph vocabulary: How staff/admin sidebar groups link to staff-compatible legacy routes and permissions. Selected terms: `staff-shell.php`, `admin-shell.php`, `admin-research-module.php`, `staff_permissions`, `ongoing_research`.
- Query/lookup and result: `graphify query "staff-shell.php admin-research-module.php activities.php admin-contact.php staff permissions" --budget 2600`, then focused query `graphify query "staff-shell.php ongoing_research admin-research-module.php staff_target permissions" --budget 1500`. Both results were truncated; they surfaced the relevant shell and module-router nodes but did not provide full relationship coverage.
- Relevant source locations and verified relationships:
  - `includes/staff-shell.php` builds the permission-filtered staff research groups and sidebar links.
  - `includes/admin-shell.php` defines the shared admin research group names.
  - `pages/admin/admin-research-module.php` checks `requireModulePermission()` for staff and sends `ongoing_research` to `pages/shared/implementation-outputs.php?view=ongoing` through `staff_target`.
  - `includes/auth.php` defines the five staff modules and permission matrix.
  - Legacy staff pages `pages/staff/document-verification.php`, `staff-revisions.php`, `staff-defense.php`, and `contact-messages.php` allow `research_staff` and render the staff shell. `pages/shared/settings.php` selects the staff shell for research staff.
  - `pages/admin/admin-contact.php` allows the staff role but renders the admin shell, so it was not added to staff navigation. Other admin institute/faculty pages require the admin role and were not added.
- Missing, inferred, stale, or truncated context: Graph results were truncated and included unrelated historical/E2E nodes. Current source was checked directly and takes precedence; no graph rebuild was run.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | `php -l includes/staff-shell.php` | Run PHP syntax check | No syntax errors | Command output: `No syntax errors detected in includes/staff-shell.php` | Passed |
| S2 | `git diff --check -- includes/staff-shell.php` | Check patch whitespace | No whitespace errors | No diff-check errors; Git emitted a line-ending normalization warning | Passed |
| U1 | Integrated browser / research staff / staff dashboard | Inspect the expanded sidebar at desktop width and confirm matching group names, staff-specific links, Notifications, and Account settings | Shared research groups match admin; old compatible staff pages appear in relevant groups; admin-only links remain absent | Awaiting user observation | Pending |
| U2 | Integrated browser / research staff / staff dashboard | Resize to a narrow/mobile width and open the staff navigation | Labels remain readable and links can be reached without clipping or overflow | Not started; depends on U1 | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l includes/staff-shell.php` passed.
- `git diff --check -- includes/staff-shell.php` reported no whitespace errors. Git noted that LF will normalize to CRLF on its next touch.
- No application interaction, database mutation, or automated browser run was performed.

## Resume / handoff
- Last user-confirmed state: No browser observation yet.
- Next pending action: User checks the staff sidebar at desktop width in the integrated browser.
- Blocked/skipped checks and remaining verification gaps: Desktop and mobile visual confirmation pending; direct-route authorization remains supported by source review, not browser testing.
- User-performed data changes and cleanup status: None.
