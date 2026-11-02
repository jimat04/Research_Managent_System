# Verification: Faculty research record action buttons

- Date/time: 2026-11-02 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: On a faculty research-record detail page, Edit is primary, Archive is secondary, Delete is visibly destructive, and the action controls align in one row. The EARIST documents CTA accurately says it opens document selection and aligns at the right of its desktop panel.
- Environment URL: `http://localhost/rms/` (configured fallback; application availability not confirmed)
- Roles and disposable records/files: Faculty; screenshot showed research record #2. No record action was activated.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Confirm the destination of the research-record EARIST CTA; `faculty-document-hub.php`, `Choose a document`, `Start field review`.
- Query/lookup and result: Existing `graphify-out/graph.json` queried with `graphify query "faculty-research-record-detail.php faculty-document-hub.php document generator link" --budget 350`; results were unrelated/truncated and did not show the route relationship. Targeted current source was used.
- Relevant source locations and verified relationships: `pages/faculty/faculty-research-record-detail.php:38` links to `faculty-document-hub.php?id=...`. `pages/faculty/faculty-document-hub.php:75-84` shows the destination section titled “Choose a document,” with a “Start field review” link for each form. Therefore “Open DOCX Generator” would misstate the destination. The detail page action was renamed to “Choose a document” and aligned right on desktop.
- Missing, inferred, stale, or truncated context: Graph results were unrelated/truncated; no graph relationship was inferred.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | PHP syntax check on changed detail page and action renderer | No syntax errors | Both files reported `No syntax errors detected` | Passed |
| S2 | Assistant static check | CSS brace-count check on `css/faculty-research-records.css` | Balanced braces | 168 opening and 168 closing braces | Passed |
| B1 | Faculty / research record detail #2 | Refresh and inspect the action row | Edit is primary, Archive secondary, Delete danger-styled; controls align | Awaiting user browser observation | Pending |
| B2 | Faculty / research record detail #2 | Inspect the EARIST documents CTA | Button says “Choose a document” and sits at the right edge on desktop; it stacks cleanly on mobile | Awaiting user browser observation | Pending |

## Failures, decisions, fixes, and retests
- User screenshot showed Archive blue with dark text and Delete vertically offset. Codex changed action hierarchy and alignment; browser retest is pending.

## Assistant-run checks
- `php.exe -l pages/faculty/faculty-research-record-detail.php` — passed.
- `php.exe -l includes/faculty-research-records.php` — passed.
- CSS brace-count check on `css/faculty-research-records.css` — passed after CTA adjustment (168 opening and 168 closing braces).

## Resume / handoff
- Last user-confirmed state: Faculty research-record detail page for record #2; screenshot showed misstyled/unaligned actions.
- Next pending action: Ask the user to inspect the detail-page action row and document CTA.
- Blocked/skipped checks and remaining verification gaps: Browser check is pending. No state-changing button was clicked.
- User-performed data changes and cleanup status: None.
