# TODO

## Auth login fix
- [x] Update `login.php` to validate credentials using prepared statements (avoid SQL injection).
- [x] Ensure the demo credentials shown match the DB seeding strategy (or remove hard-coded demo passwords from UI).
- [x] Add clearer errors for wrong role vs wrong password (without leaking which account exists).
- [x] Add basic rate limiting for repeated failed logins.
- [x] Verify student/faculty/admin role tabs correctly set `role` in POST.
- [x] Smoke test: login with existing DB users.

## Seeded role-specific passwords
- [x] Update `database.sql` so seeded admin/faculty/student users use different password hashes (Admin@123 / Faculty@123 / Student@123)
## Register flow hardening
- [x] Rewrite register email-exists check in login.php to use prepared statements
- [x] Rewrite register INSERT in login.php to use prepared statements
- [x] Add CSRF token to login & register forms
## Schema improvements
- [x] Add deleted_at soft-delete columns to core tables
- [x] Add missing created_at/updated_at timestamps
- [x] Fix ON DELETE CASCADE that would destroy content when a user is deleted
- [x] Add dashboard-friendly compound indexes
- [x] Deduplicate seed data (departments/programs/academic_years/categories)
- [x] Create messages table (referenced in README)
- [ ] Optional: rename project_id → research_id across schema (breaking change — do with page-builder rollout)
- [ ] Optional: create settings, defense_evaluations, defense_scores tables when features need them
## Pages built
- [x] pages/my-research.php (student research list with CREC/EREC statuses, chapter progress, multi-student via project_members)
## Pages built (continued)
- [x] pages/submit-research.php (create new research, draft/submit statuses, optional proposal upload)
- [x] uploads/proposals, chapters, defense, manuscripts directories created
- [x] Add Research Manual 2015 reference doc
- [x] Add schema support for MOU/NDA, progress reports, terminal reports, colloquium, and publication tracking
- [x] pages/submit-research.php: add co-researcher/team member UI
- [ ] pages/submit-research.php: createNotification() for admin on new submission
- [x] Build UI for required manual documents and report milestones ← **pages/student/submit-milestone.php + pages/staff/staff-milestones.php**
- [x] Add read-only Research Manual milestone panel to student research detail page
- [x] Add upload/update forms for required manual documents and reports ← **same pages**

## Security hardening
- [x] Convert includes/module-pages.php raw queries to prepared statements (28 prepared statements)

---

## Manual workflow completion (this sprint)

Completed against the EARIST Research Manual 2015 + the late-2026 feature push:

- [x] **Milestone submission UI (student)** — `pages/student/submit-milestone.php` handles MOU / NDA / Midway Progress / Terminal uploads with re-upload after `rejected`.
- [x] **Milestone verification queue (staff)** — `pages/staff/staff-milestones.php` provides approve / reject / waive for both `research_documents` and `research_reports`, with notifications back to the student.
- [x] **Defense scheduling with notifications** — `pages/staff/staff-defense.php` schedules proposal / pre-oral / final defenses; notifies project owner, all `project_members`, and all `project_advisers`.
- [x] **Co-researcher team management on submit and edit** — `pages/student/submit-research.php` and `pages/student/edit-research.php` add/remove team members (cap 5), insert `project_members` rows, and notify invitees.
- [x] **Admin final approval gate** — `pages/admin/admin-research.php` writes `approved_by` / `approved_at` and moves approved projects to `in_progress` (commit `9a8c395`).
- [x] **Publication, colloquium, and archive tracking on admin archive page** — `pages/admin/admin-archive.php` reads/writes `research_publication_tracking.colloquium_status`, `colloquium_date`, `journal_status`, `journal_reference`, `archive_status` (commit `1129b47`).
- [x] **Chapter picker fix in `submit-chapter.php`** — replaces the previous invalid-chapter dead-end with a populated chapter select (commit `e82c18c`).

## Still genuinely open

- [ ] **ORS Consolidation stage (Manual step 3)** — no status, no page, no UI. See `docs/progress/PROGRESS_AUDIT.md` § 4 priority 1.
- [ ] **Final Bound Report upload slot (Manual step 11)** — `pages/staff/staff-milestones.php` already lists the document type, but `pages/student/submit-milestone.php` has no upload card for it.
- [ ] **`pages/student/submit-research.php` admin notification** — co-researchers are notified, admin is not.
- [ ] **Replace the four 1-line shared stubs** — `pages/shared/{calendar,research-archive,settings,view-research}.php` are `require __DIR__ . '/module-page.php';` with no real content.

## Notes — follow-ups (not regressions)

- **Login rate limiting is in place, not missing.** `public/login.php` implements `loginFailureKey()` (keyed by client IP + session id), `isLoginLocked()`, `recordFailedLogin()`, and `resetFailedLogins()`. After 5 failed attempts the IP is locked out for 15 minutes and the user sees `"Too many failed attempts. Please try again in N minute(s)."` (message at `public/login.php:106`). It is **session-based**: clearing cookies resets the counter, so it is not a true per-account / per-IP lockout across sessions. A DB-backed `login_attempts` table (keyed by IP and/or email) would survive cookie clearing and is worth doing as a P2 hardening upgrade — not a fix for a missing feature.
