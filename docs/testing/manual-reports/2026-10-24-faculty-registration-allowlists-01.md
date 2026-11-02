# Verification: Faculty registration allowlists

- Date/time: 2026-10-24, Asia/Taipei
- Assistant: API coding assistant
- Target and expected behavior: `public/login.php` faculty registration accepts only the two requested departments and five requested academic ranks, validates them before persistence, and leaves student choices and faculty specialization intact.
- Environment URL: Not applicable; no application interaction was performed.
- Roles and disposable records/files: None used.
- Session state: Static and browser checks passed; migration 028 was applied and verified on local `rms_db`.

## Graphify and source context
- Question and selected graph vocabulary: How does the public login/registration page relate to role field handling? Selected existing labels `login.php` and `users`.
- Query/lookup and result: Ran `graphify query "login.php users" --budget 1500`, then `graphify query "login.php" --budget 1200 --context_filter call`. Both outputs were truncated. Relevant nodes included `login.php` (`public/login.php`, L1) and `updateRegistrationFields()` (`public/login.php`, formerly around L1011); page-neighbor links referenced JavaScript role/UI handlers.
- Relevant source locations and verified relationships: Current `public/login.php` defines both allowlists, checks faculty inputs before the email lookup, sends accepted values through the prepared insert, and renders the options from those same arrays. Student program/year choices and faculty specialization remain unchanged. `database/schema/rms_db.sql` now includes `College Professor` in `users.academic_rank`; migration 028 contains the matching additive enum change, and `scripts/migrate.php` registers a post-state verifier.
- Missing, inferred, stale, or truncated context: Graph results were truncated and include references to the removed E2E browser suite. Current source and a read-only post-migration database query were checked directly and take precedence.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| PHP-1 | PHP CLI / `public/login.php` | Ran `php -l public/login.php` | No PHP syntax errors | `No syntax errors detected in public/login.php` | Passed |
| STATIC-1 | Read-only source assertions | Checked exact department/rank sets, strict faculty validation before lookup, prepared insertion bindings, unchanged student options, specialization, approval status, and distinct faculty/staff department controls | All requested source invariants hold | Eight focused assertions passed | Passed |
| DB-1 | Local `rms_db` / migration runner | Applied `028_add_college_professor_academic_rank.sql` after confirming the target and verifying the pre-migration backup | Existing ranks remain available, `College Professor` is accepted, and the runner records successful verification | Runner printed `APPLIED`; read-only post-check confirmed MariaDB 10.4.32, the full enum including prior values, `DEFAULT NULL`, column comment, and ledger status `applied` / `executed` | Passed |
| UI-1 | User / public visitor / registration form | Select Faculty and inspect Department options | Only INFORMATION TECHNOLOGY and COMPUTER SCIENCE are shown | User confirmed the only choices are INFORMATION TECHNOLOGY and COMPUTER SCIENCE | Passed |
| UI-2 | User / public visitor / registration form | Inspect Academic Rank options | Only the five requested ranks are shown | User confirmed Instructor, Assistant Professor, Associate Professor, Professor, and College Professor are visible | Passed |
| DIFF-1 | `git diff --check -- public/login.php` | Checked whitespace in the modified page | No whitespace errors in task changes | Reports trailing whitespace at the pre-existing auth tagline line 759; left untouched as unrelated worktree content | Failed (unrelated existing diff) |

## Failures, decisions, fixes, and retests
- Initially, the canonical schema lacked `College Professor`. Added additive migration 028, synchronized the canonical enum, and registered a verifier. After the user authorized local application, migration 028 ran successfully and its database state and ledger entry were verified.
- Before migration, a local-only dump was created at `backups/pre-migration-028/rms_db_before_migration_028_20261024_214427.sql` (373,186 bytes; SHA-256 `186685c1c91b4b00008c58a50a753b0c16e0aeaddd7069be064f22ad5dd1ba42`). File structure and checksum were verified; restoreability was not tested. The migration was applied only to the confirmed local `rms_db`.
- No faculty registration was submitted; actual account insertion with the new rank was not browser-tested.
- `git diff --check` found existing trailing whitespace in the unrelated auth tagline; it was not changed.

## Assistant-run checks
- `php -l public/login.php` — passed.
- Read-only Python source assertions — all eight passed: exact option lists, both strict server-side allowlists, validation order before email lookup/insertion, prepared insert binding of the validated values, preserved student options, retained specialization/approval semantics, and department-control separation.
- `php scripts/migrate.php apply 028_add_college_professor_academic_rank.sql` — applied; read-only database post-check confirmed schema and migration ledger.
- `git diff --check -- public/login.php` — surfaced only the unrelated existing whitespace noted above.

## Resume / handoff
- Last user-confirmed state: UI-1 and UI-2 passed; the department and rank dropdowns display the requested choices.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: Live registration was not exercised; no account was inserted. Backup restoreability remains untested.
- User-performed data changes and cleanup status: None.
