# Verification: admin-user-course

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Student account edits expose a clearly labeled Course field and save its value through the existing `users.program` field.
- Environment URL: local RMS admin user management page
- Roles and disposable records/files: administrator; no record edited during assistant review
- Session state: in progress; static checks complete, browser check pending

## Graphify and source context
- Question and selected graph vocabulary: `admin-users.php`, `course`, `student`, `users`; selected from existing graph labels.
- Query/lookup and result: Read-only Graphify map inspection found `openEditModal()` and `admin-users.php` nodes, plus the existing student edit flow.
- Relevant source locations and verified relationships: `pages/admin/admin-users.php` selects `program` and `year_level`, renders `editStudentFields`, sets those values in `openEditModal()` only for student-role records, and persists `program` to `users.program` in the prepared edit statement. Canonical `users.program` is `varchar(120)`; researcher eligibility code uses this value.
- Missing, inferred, stale, or truncated context: Graph edges are hints; current PHP source confirmed these relationships. No graph rebuild was performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| C1 | PHP CLI / source | `php -l pages/admin/admin-users.php` | No syntax errors | No syntax errors detected | Passed |
| C2 | Integrated browser / admin / User Management | Edit a student account and inspect student fields | Course field appears with the current value; non-student edit does not show it | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- User clarified the request applies to editing existing students only. Create Account remains limited to faculty and research staff.
- The edit form already stored this data under Program; relabeled it Course, added a course-code hint, and matched the existing 120-character column limit. No database change.

## Assistant-run checks
- `php -l pages/admin/admin-users.php` — passed.
- `git diff --check -- pages/admin/admin-users.php` — passed.

## Resume / handoff
- Last user-confirmed state: none; no user record changed.
- Next pending action: inspect the Course field while editing a student account, then compare with a non-student edit if available.
- Blocked/skipped checks and remaining verification gaps: browser behavior pending user observation.
- User-performed data changes and cleanup status: none.

### Course column follow-up

- Graphify terms: `admin-users.php`, `course`, `student`; the page node and user edit helpers are in the existing map. Source confirms the directory query already selects `program`.
- Added a Course table column after ID. It shows the escaped `users.program` value for student accounts and an em dash for other roles or students without a course. The mobile table uses the `Course` data label.
- `php -l pages/admin/admin-users.php` passed; `git diff --check` passed.
- Browser check: Pending. User should inspect the Course column for student and non-student rows at desktop/mobile widths.

### Registration ID example clarification

- User clarified that `0316-00000C` is the student ID example shown during registration. Updated the Student/Employee ID placeholder and the student-role placeholder in `public/login.php` to match. This remains separate from Course/Program (BSIT/BSCS), which is what the admin table Course column displays.
- Graphify terms: `login`, `student ID`, `registration`; the map has the `public/login.php` page node and login/switch helpers but no specific form-field node. Current source confirms both placeholder locations.
- `php -l public/login.php` passed; `git diff --check` passed for both affected PHP pages.
- Registration browser check: Pending user observation.
