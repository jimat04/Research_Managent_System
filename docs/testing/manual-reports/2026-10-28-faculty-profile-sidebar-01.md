# Verification: faculty profile sidebar

- Date/time: 2026-10-28, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The faculty sidebar does not repeat the sections already available in Faculty Profile tabs; Faculty Profile remains active on all profile tabs.
- Environment URL: http://localhost/rms/
- Roles and disposable records/files: Faculty role; no record changes.
- Session state: In progress; awaiting user browser confirmation.

## Graphify and source context
- Question and selected graph vocabulary: Trace faculty profile navigation; `faculty`, `profile`.
- Query/lookup and result: `graphify query "faculty profile" --budget 1500`; BFS depth 2 found `renderFacultyShell()` and `faculty-shell.php`.
- Relevant source locations and verified relationships: Current `includes/faculty-shell.php` defines the sidebar at lines 94–103 and active matching at lines 131–134. Current `pages/faculty/faculty-profile.php` defines the profile tabs and uses `renderFacultyShell()`.
- Missing, inferred, stale, or truncated context: Graph output did not include the current faculty profile page or a direct edge from it to the sidebar; source confirmed the relationship. Graph traversal was not truncated.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l includes/faculty-shell.php` | Shell PHP parses | `No syntax errors detected` | Passed |
| B1 | Browser / faculty / Faculty Profile | Open the profile and inspect its sidebar | Research & Profile contains Faculty Profile and My IP Applications; no duplicate profile-section shortcuts; Faculty Profile is highlighted | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- `php -l includes/faculty-shell.php` passed.
- `git diff --check` completed without whitespace errors; Git printed existing line-ending warnings for unrelated working-tree files.
- No database changes or application records were made.

## Resume / handoff
- Last user-confirmed state: None.
- Next pending action: User opens `http://localhost/rms/pages/faculty/faculty-profile.php` in the integrated browser and confirms the sidebar links and active highlight.
- Blocked/skipped checks and remaining verification gaps: Browser observation pending.
- User-performed data changes and cleanup status: None.
