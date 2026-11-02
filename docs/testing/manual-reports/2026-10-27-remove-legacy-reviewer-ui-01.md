# Verification: remove legacy reviewer flag from Admin Users UI

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Admin Users no longer displays or edits the legacy reviewer flag; current role and module permissions remain unchanged; edits preserve any stored legacy value.
- Environment URL: http://localhost/rms/pages/admin/admin-users.php (configured local route; availability not confirmed)
- Roles and disposable records/files: Administrator; no accounts changed.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: admin-users, is_reviewer, legacy reviewer, user management.
- Query/lookup and result: `graphify query "admin-users is_reviewer legacy reviewer user management" --budget 1500`; found the admin-users node but results were truncated and unrelated.
- Relevant source locations and verified relationships: `pages/admin/admin-users.php` previously displayed the flag and included it in account create/edit SQL. The code no longer references it. `database/schema/rms_db.sql` and migration `002_enhance_users_for_registration.sql` retain `is_reviewer` with `DEFAULT 0`; the schema is unchanged. Current review authorization remains role/module based in `includes/research-workflow.php`.
- Missing, inferred, stale, or truncated context: Graph query truncated at 41/122 nodes and provided no reliable current dependency map; source was checked directly.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Integrated browser / administrator / User Management | Open Create User and select Faculty | No legacy reviewer checkbox appears; do not save | Awaiting user observation | Pending |
| 2 | Integrated browser / administrator / User Management | Open Edit for an existing faculty account | No legacy reviewer checkbox appears; cancel without saving | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l pages/admin/admin-users.php`: passed.
- Focused source search: no `is_reviewer`, `reviewer-mark`, or old checkbox IDs remain in `pages/admin/admin-users.php`.
- `git diff --check` for the changed PHP file: passed.
- Schema inspection confirmed the retained column default is `0` in the canonical schema and migration 002.
- No database changes or browser interactions were performed.

## Resume / handoff
- Last user-confirmed state: removal authorized; browser observations pending.
- Next pending action: user checks the Create User form without saving.
- Blocked/skipped checks and remaining verification gaps: browser confirmation pending; no account create/edit action should be submitted.
- User-performed data changes and cleanup status: none.
