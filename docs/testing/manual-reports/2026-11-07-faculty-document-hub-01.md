# Verification: faculty document hub layout

- Date/time: 2026-11-07, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Responsive EARIST faculty document hub with legible document choices and generated-version history.
- Environment URL: Not confirmed
- Roles and disposable records/files: Faculty-owned research record; no record changes required for visual checks.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: Trace the faculty document hub, shell, review history, warnings, and download presentation. Selected available terms: `faculty`, `Generated document review`.
- Query/lookup and result: `graphify query "faculty Generated document review" --budget 1500`; 16 nodes returned, including faculty shell and separate staff document-verification source, but not the faculty hub or EARIST history helper.
- Relevant source locations and verified relationships: Current `pages/faculty/faculty-document-hub.php` renders through the faculty shell, loads per-record history via `earistDocumentFacultyHistoryList()`, renders warnings with `earistDocumentRenderHistoryStatus()` from `includes/earist-document-ui.php:16`, and keeps per-record mapping/download routes. Existing local retained-file guards remain in the generated history output.
- Missing, inferred, stale, or truncated context: Graphify does not include the current hub or EARIST history helper; no truncation. Current source is authoritative.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant PHP syntax check | Run `php -l pages/faculty/faculty-document-hub.php` | No syntax errors | `No syntax errors detected` | Passed |
| S2 | Assistant diff whitespace check | Run `git diff --check` on page and stylesheet | No whitespace errors | No errors; Git emitted line-ending normalization warnings for both files | Passed |
| B1 | Browser / faculty / document hub | Review the document-card grid at desktop width | Incomplete final card row is centered | User reports a dead space at lower right; requested that the boxes be centered | Failed |
| B1R | Browser / faculty / document hub | Refresh the hub after the card alignment change | Incomplete final card row is centered and there is no lower-right dead spot | User reports all checks passed | Passed |
| B2 | Browser / faculty / document hub | Resize to mobile width | Document choices stack; history rows become labeled cards without horizontal overflow | User reports all checks passed | Passed |
| B3 | Browser / faculty / document hub | Review complete, missing, warning, and retained-file states where available | Status is clear; warning details remain available; unavailable files have no download link | User reports all checks passed | Passed |
| B4 | Browser / faculty / document hub | Review empty history and recoverable record states where available | Empty state and restore restriction remain clear | User reports all checks passed | Passed |

## Failures, decisions, fixes, and retests
- B1: User reported the document-card grid left a dead space at lower right and requested centered boxes. Applied a centered wrapping card layout; user reports the retest and remaining checks passed.

## Assistant-run checks
- `php -l pages/faculty/faculty-document-hub.php` passed.
- `git diff --check -- pages/faculty/faculty-document-hub.php css/faculty-research-records.css` reported no whitespace errors. Git warned that working-copy LF line endings may be normalized to CRLF.
- After the centered-card adjustment, `git diff --check -- css/faculty-research-records.css` reported no whitespace errors. Git emitted its LF-to-CRLF normalization warning.
- No automated browser or database checks are planned.

## Resume / handoff
- Last user-confirmed state: User reports all document hub checks passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported.
- User-performed data changes and cleanup status: None.
