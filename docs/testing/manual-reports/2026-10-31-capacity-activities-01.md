# Verification: capacity activities navigation

- Date/time: 2026-10-31 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Admin sidebar shows Research Capacity Building > Research Activities > Add (Upcoming, Completed) and View (Upcoming, Ongoing, Completed). Existing module destinations and access checks remain in effect.
- Environment URL: http://localhost/rms/ (guide fallback; actual availability not confirmed)
- Roles and disposable records/files: Administrator; no records or files needed
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: Where does the admin sidebar build its Research Capacity Building navigation? Selected graph labels: `Admin Dashboard Navigation`, `Research Capacity Building`, `Research Capacity Building Module`.
- Query/lookup and result: `graphify query "Research Capacity Building Admin Dashboard Navigation" --budget 1500`; BFS returned 50 nodes and was truncated. Results pointed to historical docs/screenshots and did not establish current shell relationships.
- Relevant source locations and verified relationships: `includes/admin-shell.php` defines the nav hierarchy and renders nested groups; its active-link helper recursively opens groups for active destinations. `pages/admin/admin-research-module.php` maps activity module keys to shared routes and applies staff module permissions. `includes/auth.php` supplies matching permission labels.
- Missing, inferred, stale, or truncated context: Graph output was historical and truncated; current source was reviewed directly. No useful current shell source-location edge was returned.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l includes/admin-shell.php` and `php -l includes/auth.php` | Both changed PHP files parse | Both reported no syntax errors | Passed |
| S2 | Assistant diff check | Run `git diff --check -- includes/admin-shell.php includes/auth.php` | No whitespace errors | No output; command succeeded | Passed |
| B1 | User browser / Administrator / admin sidebar | Open the Research Capacity Building section in the sidebar | Research Activities appears above the Add and View groups | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l includes/admin-shell.php` — passed.
- `php -l includes/auth.php` — passed.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- includes/admin-shell.php includes/auth.php` — passed with no output.
- Graphify lookup — completed with historical/truncated coverage; see above.

## Resume / handoff
- Last user-confirmed state: none yet.
- Next pending action: User opens Research Capacity Building in the admin sidebar and reports whether Research Activities appears above Add and View.
- Blocked/skipped checks and remaining verification gaps: Browser hierarchy and responsive behavior have not been observed.
- User-performed data changes and cleanup status: none.
