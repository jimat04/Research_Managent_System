# Verification: Public research archive category filter

- Date/time: 2026-11-01 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The public archive category dropdown offers categories present on completed/archived public projects, including categories made inactive after those records were archived. Selecting a category filters results by the project's category ID.
- Environment URL: `http://localhost/rms/public/research-archive.php` (user-provided; runtime not confirmed)
- Roles and disposable records/files: Public page; no record changes required.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How the public research archive applies category filtering. Selected terms: `research-archive`, `category`.
- Query/lookup and result: `graphify query 'research-archive category' --budget 1500`; found the older shared research archive, but no useful edge for `public/research-archive.php`.
- Relevant source locations and verified relationships: `public/research-archive.php` reads `category_id` near L23, builds the category dropdown near L61, joins `research_categories` for project labels near L136, and applies the selected filter with a prepared `rp.category_id = ?` predicate near L158-L161. Current status/deleted filters restrict the public archive to completed/archived, non-deleted projects.
- Missing, inferred, stale, or truncated context: Graph vocabulary resolved to a different shared archive implementation; graph did not cover this public page. Direct source inspection confirmed the relationships. No database/runtime query was performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant static check | Run `php -l public/research-archive.php` | PHP syntax passes | No syntax errors detected | Passed |
| A2 | Assistant static check | Run scoped `git diff --check` | No whitespace errors | No output/errors | Passed |
| U1 | User browser / public / Research Archive | Open the category dropdown and select a category used by an archived project | The category remains selected after Search and only matching category records are shown | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- No browser failure reported. Source inspection found that dropdown options were limited to active categories, which excluded historical categories attached to public archived records. Changed options to categories present on public archive records while preserving the prepared project category filter.

## Assistant-run checks
- `php -l public/research-archive.php` — passed.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- public/research-archive.php docs/testing/manual-reports/2026-11-01-public-category-filter-01.md` — passed with no whitespace errors. Repository has extensive pre-existing changes.

## Resume / handoff
- Last user-confirmed state: Not applicable.
- Next pending action: User selects a category used by a public archived project and confirms the resulting cards match it.
- Blocked/skipped checks and remaining verification gaps: Browser behavior awaits user observation. Historical category option visibility is based on current source/data joins but has not been observed in the browser.
- User-performed data changes and cleanup status: None.
