# Verification: adviser request migration

- Date/time: 2026-11-01, Asia/Taipei
- Assistant: GitHub Copilot
- Target and expected behavior: Apply migration `057_student_adviser_requests.sql` to the authorized local `rms_db`; both adviser request tables and their constraints should be present and migration tracking should show the migration applied.
- Environment URL: http://localhost/rms/
- Roles and disposable records/files: No browser records or credentials used.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: How do research projects, adviser assignments, and schema migration tracking relate to the adviser request workflow? Selected existing labels: `research_projects`, `project_advisers`, `schema_migrations`. The graph has no adviser-request table label.
- Query/lookup and result: `graphify query "research_projects project_advisers schema_migrations" --budget 1500` returned 75 nodes and was truncated at 40. Direct JSON lookup of `database_schema_rms_db_schema_migrations` showed the canonical schema contains the migration ledger; its extracted `contains` relationship points to `database/schema/rms_db.sql` at L159. The CLI has no `get_node` command, so the documented JSON fallback was used.
- Follow-up visibility lookup: Selected actual label `researcher/my-research.php`; `graphify query "researcher/my-research.php" --budget 1500` returned 63 nodes and was truncated, while the 2142-budget retry returned all nodes/edges but mostly stale E2E references. Current source was confirmed directly. `includes/student-shell.php` has no adviser-request navigation link.
- Relevant source locations and verified relationships: `database/migrations/057_student_adviser_requests.sql` creates request and message tables; `database/schema/rms_db.sql` contains matching canonical definitions; `scripts/migrate.php` supports explicit single-file execution and registered schema verification. Current source inspection confirmed these statements and the implementation path.
- Missing, inferred, stale, or truncated context: Graphify has no adviser-request table node; the broad query was truncated and the exact migration-node relationship is unavailable. This stale/incomplete graph context was not used as authority.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| DB-1 | CLI, local DB | Confirm configured database and migration ledger state | Target is `rms_db`; migration is not already applied or is safely reconciled | `DB_NAME=rms_db`, `RMS_E2E_MODE=0`, `SELECTED_DB=rms_db`; adviser tables absent; `schema_migrations` present; migration 057 untracked. MariaDB 10.4.32 and all referenced parent tables/key columns are present and type-compatible. | Passed |
| DB-2 | Migration runner | Apply only `057_student_adviser_requests.sql` | Runner reports applied or safely reconciled | `APPLIED 057_student_adviser_requests.sql` | Passed |
| DB-3 | Read-only schema query | Verify both tables, required columns, keys, and ledger row | Complete expected schema and applied tracking record | `adviser_requests`: InnoDB/utf8mb4_unicode_ci, 10 columns, 6 indexes, 4 FKs; `pending_project_id` is `STORED GENERATED`. `adviser_request_messages`: InnoDB/utf8mb4_unicode_ci, 6 columns, 3 indexes, 2 FKs. Ledger: `applied`, method `executed`, checksum present. Runner's registered verifier passed during apply. | Passed |
| UI-1 | Integrated browser, student/faculty | User-operated workflow verification | Student request is visible to faculty; accept/decline is reflected in the workflow | Not performed; no browser interaction or research records were created. | Skipped |
| UI-2 | Integrated browser, student | Locate adviser action on My Research | Eligible project card has a visible `Request adviser` action | User reports they cannot see it; exact page state, project visibility, and project status are not yet known. | Pending |

## Failures, decisions, fixes, and retests
- Initial runtime failure reported: `adviser_requests` is missing from `rms_db`; page guards were added in source before this migration task.
- User explicitly authorized applying the migration safely.
- Visibility follow-up: current source shows the action only within a project card, not in the student sidebar. Rendering requires project status `title_approved`, `approved`, or `ongoing`, no assigned adviser, and no pending request. Awaiting the user's observation of the project card/status to diagnose further.

## Assistant-run checks
- `php -l scripts/migrate.php` — no syntax errors detected.
- Read-only PHP CLI probe — confirmed selected database `rms_db`, non-E2E mode, absent adviser tables, untracked migration, MariaDB version, and required FK parent tables/types. The probe printed no credentials or application records.
- `php scripts/migrate.php apply 057_student_adviser_requests.sql` — `APPLIED 057_student_adviser_requests.sql`.
- Read-only PHP CLI verification — confirmed both tables' engine/collation, column/index/FK counts, the stored generated pending key, and the applied ledger row; no application rows were read or changed.
- Initial probe quoting attempt failed before executing SQL; corrected stdin-based probe succeeded. `graphify get_node` was unsupported; direct graph JSON lookup succeeded.

## Resume / handoff
- Last user-confirmed state: User reports the adviser action is not visible on student pages; exact page/project state is unknown.
- Next pending action: User opens My Research as a student and reports whether the project card appears, its displayed status, and whether `Request adviser` appears.
- Blocked/skipped checks and remaining verification gaps: Browser request/accept/decline workflow remains unverified; this migration task did not exercise application interactions. Graphify has stale references to deleted E2E tests; current source takes precedence.
- User-performed data changes and cleanup status: None. The assistant created two empty tables and recorded migration 057 in `schema_migrations`.
