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
- [ ] Rewrite register email-exists check in login.php to use prepared statements
- [ ] Rewrite register INSERT in login.php to use prepared statements
- [ ] Add CSRF token to login & register forms
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
- [ ] pages/submit-research.php: add co-researcher/team member UI
- [ ] pages/submit-research.php: createNotification() for admin on new submission
- [ ] Build UI for required manual documents and report milestones
- [x] Add read-only Research Manual milestone panel to student research detail page
- [ ] Add upload/update forms for required manual documents and reports
