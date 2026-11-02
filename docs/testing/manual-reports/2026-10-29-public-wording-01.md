# Verification: public wording

- Date/time: 2026-10-29, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The Home, About Us, and Research Archive public pages omit the word “Campus”; public EARIST branding displays “CAVITE” in uppercase.
- Environment URL: http://localhost/rms/public/index.php
- Roles and disposable records/files: Public, unauthenticated pages; no records used.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How the public pages share header and footer text. Selected terms: public header, public footer, campus.
- Query/lookup and result: `graphify query "public header footer campus" --budget 1500` found the Research Archive and public footer nodes, but no public header/Home/About Us nodes. Graph returned 25 nodes and 22 edges, slightly above the requested budget.
- Relevant source locations and verified relationships: `public/index.php:58,101`, `public/about-us.php:121,469`, and `public/research-archive.php:381,497` include `includes/public-header.php` and `includes/public-footer.php`. Current source confirms shared branding/contact text appears on all three pages.
- Missing, inferred, stale, or truncated context: Graph coverage is incomplete for the shared header and Home/About Us pages; source was checked directly and takes precedence. Graph query output was complete for its returned nodes.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Source scan / public pages | Search the three public pages and shared header/footer for “campus” and “Cavite” | No public campus wording; rendered Cavite text is uppercased | Source scan found no remaining “campus” in the three PHP pages/shared header; header and page copy use CAVITE. Footer uppercases configured Cavite values when rendering. | Passed |
| 2 | PHP syntax / assistant static check | Run `php -l` on the four changed PHP files | All four files parse | No syntax errors detected in `includes/public-header.php`, `includes/public-footer.php`, `public/index.php`, and `public/about-us.php`. | Passed |
| 3 | Browser / public visitor / Home | Open Home and inspect the shared brand | Header reads “EARIST CAVITE” without “Campus” | Awaiting user observation. | Pending |
| 4 | Browser / public visitor / About Us and Research Archive | After check 3, inspect each page’s shared header and footer | Neither page shows “Campus”; any Cavite address text shows “CAVITE” | Awaiting user observation. | Pending |

## Failures, decisions, fixes, and retests
- Initial broad PowerShell wildcard scan did not accept `public/*.php`; reran with explicit page paths. Initial Git commands hit the safe-directory guard; reran read-only Git checks with a per-command `safe.directory` setting. No source changes were made by either failed attempt.

## Assistant-run checks
- `php -l includes/public-header.php; php -l includes/public-footer.php; php -l public/index.php; php -l public/about-us.php` — all passed.
- `rg -n -i "campus|cavite" public/index.php public/about-us.php public/research-archive.php includes/public-header.php includes/public-footer.php` — confirms the edited wording and rendering conversion. The fallback address retains source capitalization, but is converted to uppercase at output.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check` — no whitespace errors reported; Git printed existing line-ending warnings.
- No browser interaction was performed by the assistant.

## Resume / handoff
- Last user-confirmed state: No browser observation yet.
- Next pending action: Open http://localhost/rms/public/index.php in the integrated browser and check the shared branding.
- Blocked/skipped checks and remaining verification gaps: Browser rendering awaits the user. Graph coverage for the shared header is incomplete.
- User-performed data changes and cleanup status: None.
