# Verification: approved-documents-alignment

- Date/time: 2026-11-04, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Align the “View approved documents” control with the research title row on wide screens; right-align approved manuscript document actions; keep a full-width toggle on narrow screens.
- Environment URL: `http://localhost/rms/` fallback; runtime availability not confirmed.
- Roles and disposable records/files: Admin or research staff with `research_approval` view permission; existing approved record, no data changes.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Where are approved-document cards and manuscript actions rendered? `approval`, `approved`, `manuscript`, `research`, `documents`.
- Query/lookup and result: `graphify query "approval approved manuscript research documents" --budget 1500`; truncated and mostly unrelated. Confirmed current-source context: `includes/research-approval-view.php` renders the expandable document list; `pages/shared/research-submission-review.php` loads it on the approval page and maps `approved_final_manuscript` to `research_approval`.
- Relevant source locations and verified relationships: `css/research-approval.css` styles the shared list toggle and document actions; `includes/research-approval-view.php` contains the approved document summary and file-action markup; the review page loads the stylesheet for the approval list.
- Missing, inferred, stale, or truncated context: Graph results were truncated and did not expose a useful relationship for this component. Current source was checked directly. The working tree contains other unrelated modifications, including graphify outputs; none were intentionally changed for this task.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant static check | Count braces in `css/research-approval.css` | Opening and closing brace counts match | 55 opening / 55 closing braces | Passed |
| U1 | Browser / admin or authorized research staff / `pages/shared/research-submission-review.php?module_page=research_approval&view=approved_final_manuscript` | Open the approved final manuscript list at desktop width | “View approved documents” shares the research title row at the right; the title does not collide with it | Awaiting user observation | Pending |
| U2 | Browser / same role and page, narrow viewport | Resize to a narrow viewport | Toggle fills the available card width; content and document actions do not overflow | Not yet requested | Pending |

## Failures, decisions, fixes, and retests
- None recorded.

## Assistant-run checks
- `rtk proxy node -e "const s=require('fs').readFileSync('css/research-approval.css','utf8'); const o=[...s].filter(c=>c==='{').length; const c=[...s].filter(c=>c==='}').length; console.log('CSS braces:',o,'open /',c,'close'); process.exitCode=o===c?0:1"` — passed, counts matched.
- `git diff --check -- css/research-approval.css` — passed with no whitespace errors. A broader worktree check reported access errors under `storage/earist`.

## Resume / handoff
- Last user-confirmed state: No browser observation yet.
- Next pending action: Open the approved final manuscript list at desktop width and report whether the toggle shares the research title row without overlapping the title.
- Blocked/skipped checks and remaining verification gaps: Narrow viewport check pending.
- User-performed data changes and cleanup status: None.
