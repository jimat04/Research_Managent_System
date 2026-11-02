# Verification: faculty academic ranks

- Date: 2026-10-30 (Asia/Taipei)
- Assistant: Codex, following the rms-db specialist definition locally (no direct specialist invocation available).
- Target: the user-supplied 19 EARIST ranks, Instructor I (SG 12) through College Professor (SG 30), in registration, profile and administrator forms.
- Environment: confirmed local development `rms_db`; configured URL `http://localhost/rms/`.
- Roles / records: faculty and administrator; no application records created for this task.
- Session state: implementation and local migration complete; awaiting user browser observations.

## Graphify and source context

- Question: which pages validate/store ranks, and how does the migration runner verify upgrades?
- Vocabulary: `profile`, `admin`, `users`, `migrate`, present in node labels.
- Read-only query: `graphify query "profile admin users migrate" --budget 1500`. Returned 42 nodes / 93 edges, exceeded requested budget and output was truncated. Narrowed to direct JSON inspection of the three owning page/runner nodes and adjacent relevant edges.
- Extracted nodes: `pages/shared/profile.php:L1`, `pages/admin/admin-users.php:L1`, `scripts/migrate.php:L1`; extracted containment includes `openEditModal` at graph L1291 and `verify_migration` at graph L286. Current source confirms both functions, but line numbers have moved. Graph is stale and does not cover the new shared rank helper.
- Current source: registration has a separate rank allowlist; shared profile has duplicate options/validation; admin create/edit lack rank validation and have older options. All persist `users.academic_rank`; faculty research profile and author suggestions read that field. Author snapshots retain their recorded position independently.
- Hypothesis: replacing only dropdowns would still reject new ranks or fail persistence because the database enum accepts only seven broad/legacy values. Cheapest check: inspect allowlists and column metadata before editing. Confirmed by source and a read-only metadata query.
- Local metadata: nullable enum with Instructor, Assistant Professor, Associate Professor, Professor, Dean, Director, College Professor. Rank counts before work: NULL 5, empty string 4, Instructor 2. Migrations 028, 049 and 050 are applied; 051 absent.
- Post-change graph lookup: direct JSON path lookups still cover registration, shared profile and admin Users, but not `pages/faculty/faculty-profile.php` or `includes/faculty-ranks.php`. Targeted source confirms all four pages explicitly include the new helper, registration derives its exact allowlist from it, profile/admin validation use it, and the runner verifier derives the expected storage enum from it. No graph writes or rebuild performed.
- Current source locations: helper map `includes/faculty-ranks.php:6`, profile validation `pages/shared/profile.php:128` / options `:300` / labels `:560`, admin validation `pages/admin/admin-users.php:114` and `:203`, registration allowlist `public/login.php:47` / validation `:242`, faculty display `pages/faculty/faculty-profile.php:301`, migration verifier `scripts/migrate.php:622`.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence | Status |
|---|---|---|---|---|---|
| S01 | Assistant / read-only metadata | Confirm target, existing enum and ledger | Local development rms_db; additive upgrade possible | Target and prior migrations confirmed; no writes | Passed |
| S02 | Assistant / syntax and source review | Check changed PHP, labels, validation and enum consistency | Exact 19-rank map; legacy values preserved only for existing accounts | All six PHP files parse; 86 focused checks pass; admin inline JS syntax passes; diff reviewed against pre-edit snapshots | Passed |
| S03 | Assistant / migration | Back up and apply only migration 051 through runner | Enum accepts all ranks; existing user values unchanged | Protected full backup completed; runner returned APPLIED; all 11 complete user rows hash identically before/after; ledger applied/executed checksum matches | Passed |
| M01 | User / faculty / shared profile | Open the profile page | Edit Profile contains Academic rank | No observation | Pending |
| M01b | User / faculty / shared profile edit | Open rank dropdown | All 19 detailed ranks with correct SG labels; current legacy value retained | No observation | Pending |
| M02 | User / disposable faculty profile | Select a detailed rank, save and reopen in separate guided steps | Rank persists; SG label matches | No observation | Pending |
| M03 | User / admin / Users | Inspect create and edit faculty rank controls | Same 19 options; existing legacy value retained on edit | No observation | Pending |
| M04 | User / public registration | Inspect faculty rank control | Same 19 options; no legacy ranks available for new accounts | No observation | Pending |
| S04 | Assistant / backend source | Review invalid faculty ranks, CSRF and access boundaries | Faculty rank writes allow exact official ranks or blank; edits may retain only their own current legacy rank | Registration remains faculty-required; admin guards and existing CSRF gates retained; rank allowlists precede prepared writes; existing rank is loaded from DB for the target account; non-faculty admin edits preserve their rank value | Passed |

## Failures, decisions, fixes, and retests

- No application failures observed; browser checks not run. Existing broad ranks were not assigned an assumed level. Database values remain unchanged and may be retained during edits.
- Fresh installs and upgrades accept the same 25 enum members: six legacy categories plus 19 official detailed ranks. Old enum member order remains intact. The forms offer only the 19 official choices, plus the edited account's current legacy value when needed.
- Existing research author snapshots are historical and were not rewritten. Their source helpers already read the saved rank for new author suggestions.

## Assistant-run checks

- Read-only Graphify lookup and targeted current-source inspection; limitations recorded above.
- Read-only local database metadata, aggregate rank counts and selected migration ledger checks. No application interactions, credentials printed or database changes.
- Pre-edit snapshots of the six affected existing files retained in the system temporary directory to distinguish task changes from the pre-existing dirty worktree.
- `php -l` passed for `includes/faculty-ranks.php`, `public/login.php`, `pages/shared/profile.php`, `pages/admin/admin-users.php`, `pages/faculty/faculty-profile.php` and `scripts/migrate.php` after final PHP edits.
- Inline PHP-only checks via stdin (no database/bootstrap) passed 86 assertions: exact supplied rank/SG list; all valid ranks and labels; whitespace/unknown/type rejection; blank optional rank; current legacy retention and rejection when assigning another legacy rank; canonical schema and migration enum agreement.
- Extracted the pure inline admin JavaScript into a temporary file and ran `node --check`; passed. This is a syntax check, not a browser interaction or runtime pass.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- public/login.php pages/shared/profile.php pages/admin/admin-users.php pages/faculty/faculty-profile.php database/schema/rms_db.sql scripts/migrate.php` passed (line-ending warnings only). New helper, migration and report have no trailing whitespace. Task diff reviewed against pre-edit snapshots; unrelated worktree changes preserved.
- Protected ignored backup: `backups/faculty-ranks-051-2026-10-30-65e593e5.sql.backup`, 661413 bytes, SHA-256 `453c679b1bc209e068b809056bfb95e0f203c3011577353ceb6e24fb95382d83`. mysqldump exit 0 and completion footer confirmed; `backups/.htaccess` denies web access. Backup contains the whole local database; contents were not printed.
- Applied only `php scripts/migrate.php apply 051_complete_faculty_academic_ranks.sql`; runner returned `APPLIED 051_complete_faculty_academic_ranks.sql`, including its registered postcondition verifier. Migration SHA-256 `ce70488e6c58dbab063f7fcf93c54820390b1cd7896205d9f6cf83aaa78d46c2` matches the applied ledger.
- Post-migration read-only check confirmed exact enum values/order, nullable/default/comment, all 19 official names, and all 11 complete user rows unchanged by deterministic JSON SHA-256 comparison (`894ba6cf7b36836fbd2a2acb9ac6bbc11412a759ab3e392f9ac59816b2ac3186`). No account changes, rank backfills or application records were made; only enum metadata and migration ledger changed.
- Rollback guidance is in migration 051: keep the additive enum if reverting PHP; narrowing after detailed ranks are saved could lose data. Migration 010 was not run or altered.

## Resume / handoff

- Last user-confirmed browser state: none for this task.
- Next action: as faculty, open `http://localhost/rms/pages/shared/profile.php`; expected Edit Profile section contains Academic rank. Confirm whether that field is visible, or whether login/an error is shown, before proceeding.
- Browser persistence and role-boundary observations remain pending. No automatic browser actions or record cleanup.
- Full 15-admin-subpage navigation sweep skipped: this change adds no navigation or permission rules. Admin faculty create/edit dropdown and persistence checks remain pending.
