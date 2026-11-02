# Verification: Legacy title-submission backfill

- Date: 2026-10-24 (Asia/Taipei)
- Target: Add a clearly labeled, approved legacy title-history version for each of the two specified ongoing projects, and display the approximate date without inventing reviewer details.
- Session status: Migration applied, database rows verified in local `rms_db`, and both legacy entries confirmed in the admin browser.

## Graphify and source context

- Query: How legacy title-version metadata is displayed in the shared research submission review page.
- Vocabulary selected from the existing `graphify-out/.vocab.txt`: `research`, `review`, `submit`, `title`, `version`, `project`.
- Lookup: `graphify query "research review submit title version project" --budget 1500`.
- Result and limitation: Graphify returned 45 nodes and 39 edges and reported the result exceeded the requested budget; its starting nodes were broad submission/review concepts and did not establish the exact current page-to-migration relationship. No graph update or rebuild was performed.
- Source verification: `pages/shared/research-submission-review.php` reads version snapshots for review cards and retrieves title-version history; the existing schema defines version `status`, `submitted_by`, `submitted_at`, `snapshot_json`, and separate reviewer decisions. Migration 017 creates version/review/upload tables without backfilling existing projects. The migration snapshot shape was aligned with the page's `legacy_import.imported` and approximate-date metadata handling. Current source is authoritative.

## Source changes reviewed

- `database/migrations/026_backfill_legacy_title_submissions.sql` targets only project IDs 1 and 2 paired with the exact requested titles, active (`deleted_at IS NULL`) ongoing records, and projects without a title version. It inserts an approved version 1 using `created_by` and `created_at`, stores the date as approximate in the legacy metadata, and inserts no review decision or reviewer identity.
- `scripts/migrate.php` registers a verifier because the existing migration runner refuses to execute an unregistered migration without a conclusive verifier. The verifier checks both exact project identities, ongoing/active status, version state, legacy metadata, approximate date, and absence of fabricated review decisions. It was hardened to refuse ambiguous or unexpected states.
- `pages/shared/research-submission-review.php` conditionally labels imported records in collapsed summaries, submission details, and history; it describes the date as an approximate project-creation date and avoids presenting the current account's course as original submitter detail. Ordinary-record output remains on the existing branches.
- Before applying: a read-only preflight reconfirmed projects #1 and #2 have their exact expected titles, are active and ongoing, and have zero title versions. A full local backup was created at `backups/rms_db_before_legacy_title_backfill_20261023_235452.sql` (344,535 bytes); the backup header and both project titles were verified. The backup is ignored by Git.
- Migration runner status showed migration 026 pending and the exact legacy records eligible. Other unrelated migration statuses were left unchanged; only migration 026 was explicitly applied.

## Checks

| Check | Method | Result |
|---|---|---|
| PHP syntax — shared review page | `php -l pages/shared/research-submission-review.php` | Passed: no syntax errors. |
| PHP syntax — migration runner | `php -l scripts/migrate.php` | Passed: no syntax errors. |
| Diff whitespace | `git diff --check` for the migration runner, review page, new migration, and this report | Passed; Git emitted only a working-copy LF-to-CRLF notice for `scripts/migrate.php`. |
| New migration trailing whitespace | Read-only PowerShell `Select-String` check | Passed: no trailing whitespace. |
| Migration status | `php scripts\migrate.php status` against local `rms_db` | Migration 026 was `pending` before application. Existing unrelated pending/verifier statuses were not acted on. |
| Migration execution | `php scripts\migrate.php apply 026_backfill_legacy_title_submissions.sql` | Passed: `APPLIED 026_backfill_legacy_title_submissions.sql`; ledger records `applied (executed)`. |
| Post-migration data verification | PHP read-only transaction against verified local `rms_db` | Passed: project #1 now has approved title version 1 (version ID 115), approximate date `2026-08-31 09:30:58`, zero review decisions; project #2 has approved title version 1 (version ID 116), approximate date `2026-09-02 22:39:16`, zero review decisions. Both carry `legacy_title_submission_backfill` metadata. |
| Browser interaction | Admin / Research Approval | User refreshed the page and confirmed both collapsed legacy cards appear with clear approximate-date labels and no fabricated reviewer decision. |

## Limitations

- The user confirmed the browser-rendered cards and labels. No new reviewer decision or identity was created; the approved state reflects the projects' existing ongoing lifecycle state.
- The two legacy entries intentionally have no uploaded document attached by this backfill. Existing files were not inferred or linked.
- No commit was created.
