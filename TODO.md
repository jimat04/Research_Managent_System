# TODO

## Auth login fix
- [ ] Update `login.php` to validate credentials using prepared statements (avoid SQL injection).
- [ ] Ensure the demo credentials shown match the DB seeding strategy (or remove hard-coded demo passwords from UI).
- [ ] Add clearer errors for wrong role vs wrong password (without leaking which account exists).
- [ ] Add basic rate limiting for repeated failed logins.
- [ ] Verify student/faculty/admin role tabs correctly set `role` in POST.
- [ ] Smoke test: login with existing DB users.

## Seeded role-specific passwords
- [x] Update `database.sql` so seeded admin/faculty/student users use different password hashes (Admin@123 / Faculty@123 / Student@123)
