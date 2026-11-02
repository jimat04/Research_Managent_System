# Verification: BSIT/BSCS research-area refinement

- Date: 2026-10-25 (Asia/Taipei)
- Assistant: Codex
- Target: curated selections, category mappings, multiple areas, catalog administration and historical compatibility.
- Environment URL: http://localhost/rms/
- Database: confirmed loopback development `rms_db`; no automated application interactions.
- Roles/disposable records: admin, student and faculty; none created.
- Session state: implementation and assistant checks complete; user-operated browser verification pending.

## Graphify and source context
- Question: how do researcher forms validate/persist area classifications and enforce eligibility?
- Actual vocabulary: `crc_validate_candidates`, `research`, `workflow`.
- `graphify query 'crc_validate_candidates' --budget 1500` was ambiguous. Qualified retry returned 99 nodes with 44 shown; truncated context was supplemented with focused current source.
- Graph nodes: submit form `crc_validate_candidates()` L53; edit form counterpart L47; `includes/research-workflow.php` L1.
- Stale coverage: graph lacks the current research-area helpers and refers to retired E2E files. Current source confirms both forms call shared validators, project-area persistence and version snapshots; admin page reuses category mappings. No graph rebuild performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| DB01 | Assistant / read-only SQL | Inspect target, catalogs and ledger | Local development rms_db; preserve 40 areas and 5 categories | Local/development confirmed; original IDs 1–40; categories Applied=1, Basic=2, Action=3, Developmental=4, Evaluation=5; no project-area links; no 030/010 ledger rows | Passed |
| DB02 | Assistant / migration | Apply backed-up targeted migration and reapply through runner | 20 scoped areas, exact initial mappings; originals and history unchanged | 031 applied; second runner call skipped. 53 total / 20 scoped; exact 60 mappings. Original catalog/category/project/version/review hashes unchanged | Passed |
| ST01 | Assistant / static | PHP lint and focused non-mutating validation | Syntax and validation boundaries hold | Five PHP files lint clean; JS syntax clean; 60 accepted and 205 rejected pairs; eight invalid ID-input shapes rejected; five-area ordered snapshot retrieval confirmed | Passed |
| UI01 | User / admin / catalog | Open catalog | Complete original catalog plus curated scope controls | Awaiting user | Pending |
| UI02 | User / student and faculty / submit form | Exercise all five categories and selection limits | Eligible options only; selected mismatches visible; 1–5 enforced | Awaiting user | Pending |
| UI03 | User / researcher / draft edit | Save/reopen multiple areas and change category | Persistence, validation and explicit removal; legacy associations retained | Awaiting user | Pending |
| UI04 | User / admin / catalog | Change mapping, label, scope and activation on disposable area | Subsequent researcher form reflects changes; historical links survive | Awaiting user | Pending |
| SEC01 | Assistant / source | Review CSRF, ownership, locks, role restrictions | Existing boundaries retained | Admin requires admin role and CSRF. Researcher edit ownership/soft-delete/status/eligibility checks retained; locked transaction revalidates current associations and explicit removal. No module permission or navigation changes | Passed |
| SEC02 | User / unauthorized roles and direct routes | Open catalog and another disposable project's edit route | Unauthorized access denied; submitted projects locked | Awaiting user; no automated requests performed | Pending |

## Failures, decisions, fixes, and retests
- No user-observed failures. Existing gaps identified during requested implementation: picker displays all areas and silently clears mismatches; active-only edit catalog hides saved inactive areas.
- Review found an extra blank line at the canonical schema EOF; removed during implementation. A diff check with autocrlf disabled misidentified CRLF terminators as whitespace; the CRLF-aware check is the applicable result.

## Assistant-run checks
- Read-only database catalog/ledger inspection via PHP/MySQLi; no credentials or personal data printed.
- Worktree already contains unrelated changes, including modifications in affected files; preserve them.
- Before migration: affected eight tables backed up (67,456 bytes) under ignored, HTTP-denied `backups/research-area-refinement-20261025.sql.backup`. Hash baseline stored beside it. No users/credentials exported.
- `php scripts/migrate.php apply 031_curated_bsit_bscs_research_areas.sql`: APPLIED; second invocation: SKIPPED (already applied). No other migration applied or reconciled, including 010.
- Read-only PHP/MySQLi transaction: all 40 original area rows and all five category rows match baseline hashes; six projects, zero existing junction associations, 19 versions and 16 reviews match too.
- All five mappings equal the requested list-number mapping resolved to actual IDs. All 5 × 53 validator combinations checked: 60 allowed and 205 rejected. No writes in validator checks.
- `rms_validate_research_area_ids`: rejects null, empty list, duplicate IDs, zero, negative, decimal, nested input and six areas; accepts five unique IDs. `rms_research_area_snapshot` returns five labels/IDs in requested order.
- Pure helper retention check used synthetic ID inputs only: saved out-of-scope ID 40 remains in catalog and passes retention validation. No saved historical junction links exist locally; persistence and actual legacy edit behavior require user observations.
- Scope FK verified in information_schema; both reused junction tables have composite pair primary keys.
- `php -l` passed for includes/research-workflow.php, pages/researcher/submit-research.php, pages/researcher/edit-research.php, pages/admin/admin-research-areas.php and scripts/migrate.php.
- `node --check js/research-area-picker.js` passed. No JS/browser behavior execution claimed.
- Python source check confirms fresh-install curated SQL equals migration 031 (ignoring trailing newline) and new files have no trailing spaces. Fresh-install schema was not imported: no isolated disposable database is verified.
- Final CRLF-aware `git diff --check` passed for affected tracked files. Reviewed scope upsert, category filtering, explicit-removal validation and transaction checks; unrelated worktree changes retained.
- Graph fallback after edits: direct relevant nodes/adjacent edges confirmed EXTRACTED calls from both crc_validate_candidates helpers to crc_normalize_ids; current scope helpers remain absent from the stale graph.

## Resume / handoff
- Last user-confirmed browser state: none.
- Next pending browser action: admin opens the existing research-area catalog.
- Browser interaction, persistence and authorization observations remain pending.
- User-performed data changes and cleanup: none.
- Assistant database changes: added scope table, 13 catalog records, 20 scope entries, initialized curated mappings and recorded migration 031. Existing original non-scoped mappings remain; seven reused scoped entries received the new suggested mappings. No proposal/history/account rows changed.
- First browser step: role admin; open http://localhost/rms/pages/admin/admin-research-areas.php; expected complete 53-entry catalog with curated label and scope controls. Confirm catalog versus login/access/error page before continuing.
