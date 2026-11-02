# Verification: academic affiliation

- Date/time: 2026-11-02 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Role-specific Academic Affiliation for Admin, Research Staff, and Faculty; administrator catalog management and user filtering; Student accounts/modules and current reports remain outside scope.
- Environment URL: `http://localhost/rms/`
- Roles and disposable records/files: None used.
- Session state: In progress; migration 063 is applied, and a taxonomy correction is prepared in pending migration 065.

## Graphify and source context
- Question and selected graph vocabulary: Trace role profiles, program catalogs, migration/schema conventions, and the effect of the Food Technology classification correction. Selected graph terms: `programs`, `users`, `migration`, `schema`.
- Query/lookup and result: `graphify query "programs users migration schema" --budget 1000`; 26 nodes and 25 edges returned (the graph tool reported the complete result exceeded the requested budget).
- Relevant source locations and verified relationships: Current `pages/shared/profile.php` requires login and exposes affiliation editing only for `admin`, `research_staff`, and `faculty`; `pages/admin/admin-users.php` requires the `admin` role; `includes/academic-affiliation.php` validates active group/program mappings and synchronizes only mapped Faculty programs with the legacy assignment table. `database/migrations/063_academic_affiliation.sql` defines the reusable affiliation relations and seed mappings.
- Missing, inferred, stale, or truncated context: The existing graph predates this implementation and has no nodes for `includes/academic-affiliation.php`, migrations 063/065, or the new affiliation tables. Current schema confirms BIT-DM is Drafting/Mechanical Technology and BIT-Auto is Automotive Technology, both linked to Industrial Technology. No graph rebuild was performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant static check | Run PHP syntax checks on changed PHP files | All changed PHP parses | Five changed PHP files reported no syntax errors | Passed |
| A2 | Assistant static check | Run JavaScript syntax check | Affiliation editor script parses | `node --check js/academic-affiliation.js` exited 0 | Passed |
| A3 | Assistant static check | Check targeted tracked diff whitespace | No patch whitespace errors | `git diff --check` reported no errors; Git emitted only LF-to-CRLF normalization warnings for two existing page files | Passed |
| A4 | Assistant read-only database check | Read migration status | 063 is recorded applied and verified; unrelated 064 is not applied | `063_academic_affiliation.sql` is `applied / executed`; `064_upload_soft_deletes.sql` is `ready-to-sync` (detected by read-only verification only) | Passed |
| A5 | Assistant database backup | Create protected full SQL backup before migration | Correct local DB is backed up with dump and inventory integrity verified | `backups/academic-affiliation-063-20261101-212259-2f97f4b3/rms_db.sql.backup`; local `localhost/rms_db`, 68 InnoDB tables, 4,523 rows, 1,665,300 bytes; footer/table/row inventory passed; SHA-256 `b1115cdb6bafda8f29e12c2507544d9b20745b366007f58a0a263d2582aa1a66`. Restore was not rehearsed. | Passed |
| A6 | Migration runner | Apply only migration 063 | New schema/catalog/backfill passes registered verifier | `php scripts/migrate.php apply 063_academic_affiliation.sql` returned `APPLIED`; subsequent status reports `applied / executed` | Passed |
| A7 | Assistant source/static check | Correct Food Technology from specialization-only to regular affiliation program `BIT-FoodTech` | Fresh-install schema and new migration agree; existing shared Student catalog remains unchanged | Migration 065 changes only affiliation catalog/mapping; 063 verifier accepts pre- and post-correction states; PHP lint and targeted diff checks passed | Passed |
| A8 | Assistant database status | Check correction migration | 065 is not applied without its own backup and authorization | `065_academic_affiliation_food_technology_program.sql` is prepared but pending; no further database changes made | Passed |
| B1 | User browser / Admin / existing Admin > Users page | Open the page and inspect affiliation controls | Admin page loads with group manager, group/program filters, and affiliation summary column | Awaiting user observation | Pending |
| B2 | User browser / Research Staff / shared Profile page | Review EARIST, rank/designation, affiliation selection and saved profile | Staff affiliation saves while office assignment remains available | Awaiting user observation | Pending |
| B3 | User browser / Faculty / shared Profile and Faculty profile pages | Review dependent program selection, BIT-FoodTech, and preserved multi-program assignments | Saved valid assignments display and remain editable; BIT-FoodTech appears as a regular Industrial Technology program | Waiting for migration 065 and user observation | Blocked |
| B4 | User browser / Student / existing profile and student module | Review student options and modules | Student workflow remains unchanged and no affiliation editor appears | Awaiting user observation; runtime behavior is unconfirmed | Pending |
| B5 | Source scope review | Inspect changed-file scope | Existing report queries, dashboards, and exports are not modified by this feature | Implementation changes are confined to affiliation helper, migration/schema, migration verifier, shared/admin/faculty profile pages, and editor JS; no report integration was added | Passed |

## Failures, decisions, fixes, and retests
- During source review, found that an empty editor row could submit an invalid blank group, Faculty saves could replace unmapped legacy program assignments, and an active group without active mappings could be hidden. Updated the group selector to require a selection for each added row, narrowed Faculty synchronization to catalog-mapped programs, and retained active groups with no mapped programs. Static syntax checks were run after these changes.
- No browser observations have been provided yet.
- The original specialization-only classification followed the earlier task wording. The user clarified that Food Technology should be the regular affiliation program `BIT-FoodTech`; migration 065 now represents that correction without changing shared Student programs. BIT-DM and BIT-Auto remain linked to their existing canonical records.

## Assistant-run checks
- `rtk proxy powershell -NoProfile -Command "php -l includes/academic-affiliation.php; php -l pages/shared/profile.php; php -l pages/admin/admin-users.php; php -l pages/faculty/faculty-profile.php; php -l scripts/migrate.php"` — all five files reported no syntax errors.
- `rtk proxy node --check js/academic-affiliation.js` — exited 0.
- `rtk proxy git -c safe.directory=C:/xampp/htdocs/rms diff --check -- includes/academic-affiliation.php pages/shared/profile.php pages/admin/admin-users.php pages/faculty/faculty-profile.php scripts/migrate.php database/schema/rms_db.sql` — no whitespace errors; line-ending normalization warnings only.
- Earlier read-only preflight identified the intended local database as `rms_db`, found no existing affiliation tables or Food Technology/Food Trades shared program, and found two existing Faculty program assignments. The migration mirrors applicable catalog rows and backfills only explicitly mapped current Faculty assignments.
- `scripts/backup-academic-affiliation-063.php` was a temporary CLI-only backup helper and has been removed after use. Its protected backup artifact and verification summary remain under ignored `backups/`; no database credentials or row contents were printed.
- `rtk proxy php scripts/migrate.php apply 063_academic_affiliation.sql` — runner returned `APPLIED 063_academic_affiliation.sql`; the registered structural/seed verifier passed.
- `rtk proxy php scripts/migrate.php status` — 063 is `applied / executed`. Migration 064 was not applied; read-only status reports `ready-to-sync` because its schema verifier detects existing schema. Other pre-existing `needs-verifier`, `checksum-mismatch`, and `ready-to-sync` entries were left untouched.
- `php -l scripts/migrate.php`, `php -l pages/admin/admin-users.php`, and `php -l includes/academic-affiliation.php` — no syntax errors after the catalog correction. Targeted `git diff --check` — no whitespace errors.
- The corrected database state is represented by `database/migrations/065_academic_affiliation_food_technology_program.sql`; it has not been applied. A fresh backup of the current post-063 database is required before any 065 application.
- Limitations: Database DDL and seed verification passed through the migration runner, but rendered UI, application saves, invalid submissions, persistence, and role behavior still need user-operated browser checks.

## Resume / handoff
- Last user-confirmed state: The user authorized migration application. Assistant verified a protected database backup and applied only 063; no user browser actions have been reported.
- Next pending action: Obtain authorization for a fresh backup of current local `rms_db`; after backup and target verification, obtain explicit authorization to apply 065. Then resume the Admin browser check and the remaining role checks.
- Blocked/skipped checks and remaining verification gaps: B1, B2, and B4 await browser observations; B3 is blocked until 065 is applied. No live UI or save behavior has been confirmed.
- User-performed data changes and cleanup status: None. Assistant database effects are migration 063 schema/catalog seeds, mapped Faculty backfill, and its ledger row. Migration 065 is not applied.
