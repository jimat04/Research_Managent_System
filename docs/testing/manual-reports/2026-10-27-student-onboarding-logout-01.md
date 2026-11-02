# Verification: student onboarding logout

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Students whose Research Basics guide is incomplete can log out from the topbar beside their account name. The control is hidden after the saved completion state unlocks the sidebar. Marking a lesson complete saves progress, then smoothly navigates to the next unlocked lesson (or the final proposal prompt); reduced-motion preferences disable smooth scrolling.
- Environment URL: http://localhost/rms/ (configured fallback; browser state not inspected)
- Roles and disposable records/files: Student; no record changes
- Session state: In progress, awaiting user browser observation

## Graphify and source context
- Question and selected graph vocabulary: How does the student dashboard render and route after lesson completion? Terms: `student-dashboard`, `followHash`, `selectLesson`.
- Query/lookup and result: `graphify query "student-dashboard followHash selectLesson" --budget 1500`; BFS depth 2 returned student dashboard and shell nodes, with a complete answer slightly over budget. `followHash` and `selectLesson` were not graph labels.
- Follow-up lookup for undo behavior: `graphify query "student-dashboard lesson-progress" --budget 1000`; traversal was truncated at 25 of 49 nodes. Dashboard source was present; direct source review remains authoritative for the POST handler and client-side destination behavior.
- Follow-up lookup for the background treatment: selected `student-dashboard` and `research-basics` from available graph labels; `graphify query "student-dashboard research-basics" --budget 1000` returned the dashboard and shell but truncated 8 of 34 nodes. The CSS and logo asset relationship was confirmed directly in the current source/assets.
- Follow-up lookup for watermark placement: `graphify query "student-dashboard research-basics" --budget 700` returned the dashboard and shell but truncated 16 of 34 nodes. Current CSS confirms the logo background position is now near the top of the content area.
- Follow-up lookup for the paired RMS watermark: `graphify query "student-dashboard research-basics" --budget 700` again returned the dashboard and shell but truncated 16 of 34 nodes. Both logo asset paths and their matching CSS placement were confirmed directly.
- Follow-up lookup for the EARIST size adjustment: `graphify query "student-dashboard research-basics" --budget 500` returned the dashboard and shell but truncated 21 of 34 nodes. Current CSS confirms only the right logo's background-size changed; its position is unchanged.
- Follow-up lookup for the RMS logo position: `graphify query "student-dashboard research-basics" --budget 400` returned the dashboard node but truncated 24 of 34 nodes. Current CSS confirms the RMS logo offset changed from 5% to 3% while its top offset and size stayed the same.
- Follow-up lookup for the small additional left adjustment: `graphify query "student-dashboard research-basics" --budget 300` was truncated at 8 of 34 nodes. Current CSS confirms the RMS logo offset is now 1%; its size and top offset remain unchanged.
- Follow-up lookup for the final left adjustment: `graphify query "student-dashboard research-basics" --budget 250` was truncated at 6 of 34 nodes. Current CSS aligns the RMS background image to the left edge, retaining the existing top offset and size.
- Follow-up lookup for the small EARIST size reduction: the same focused graph query was truncated at 6 of 34 nodes. Current CSS confirms EARIST background sizing changed to a 410px maximum; its position and RMS sizing are unchanged.
- Follow-up lookup for the EARIST vertical position: `graphify query "student-dashboard research-basics" --budget 200` was truncated at 6 of 34 nodes. Current CSS confirms the EARIST top offset is now 28px while its size and right alignment are unchanged.
- Follow-up lookup for the additional vertical adjustment: the same graph query returned a truncated dashboard context; current CSS sets the EARIST top offset to 40px, preserving size and right alignment.
- Follow-up lookup for the next vertical adjustment: `graphify query "student-dashboard research-basics" --budget 150` was truncated; current CSS sets the EARIST top offset to 52px, preserving size and right alignment.
- Follow-up lookup for logo scrolling: the same Graphify query was truncated; current CSS confirms both logo backgrounds now use `background-attachment: scroll` with the page layer.
- Follow-up lookup for positioning both marks near the header: `graphify query "student-dashboard research-basics" --budget 200` was truncated at 6 of 34 nodes. Current CSS compensates for transparent padding inside the image files by using top offsets of -12px (EARIST) and -55px (RMS), while the page-content background clip keeps them below the header.
- Follow-up lookup for mobile alignment: same focused Graphify query was truncated at 6 of 34 nodes. A mobile media rule now scales both vertical offsets with viewport width and logo size so visible artwork starts at the same position below the header.
- Follow-up lookup for reverse lesson navigation: `graphify query "student-dashboard research-basics lesson-progress" --budget 900` returned the dashboard and related nodes but truncated 38 of 60. The lesson POST handler and JS transition behavior were confirmed directly in current source.
- Relevant source locations and verified relationships: The dashboard transaction commits lesson progress and computes its transition destination at `pages/student/student-dashboard.php:385-400`. Current source confirmed `js/research-basics.js` initializes the selected lesson and performs scroll navigation; completion sends the next lesson as a one-time destination. Undo now sends the prior lesson plus the undone lesson as the transition origin, which the script uses to animate upward.
- Missing, inferred, stale, or truncated context: The graph does not include the client script relationship or lesson transition; confirmed in current source. Traversal was over the requested token budget by ~66 tokens. No graph rebuild/update performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Browser / student / student dashboard | View dashboard while Research Basics is incomplete | Logout button appears beside the student account block | Awaiting user observation | Pending |
| S2 | Browser / student / student dashboard | Complete the guide through its normal control, then view dashboard | Sidebar unlocks and topbar logout button is hidden | Awaiting S1 and user observation | Pending |
| S3 | Browser / student / student dashboard | Submit the topbar Logout button | Existing logout flow signs the student out | Awaiting user observation | Pending |
| S4 | Browser / student / student dashboard | Mark the current lesson complete | Progress is saved and the page smoothly scrolls to the next unlocked lesson | Awaiting user observation | Pending |
| S5 | Browser / student / student dashboard | Undo a completed lesson after the first | Progress is removed and the page animates upward from that lesson to the previous lesson | Awaiting user observation | Pending |
| S6 | Browser / student / student dashboard | View and scroll the Research Basics lessons on mobile | Both logos scroll with the page and align just below the header; EARIST stays right, RMS stays left, and lesson text remains readable | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- None observed.

## Assistant-run checks
- `php -l pages/student/student-dashboard.php`: passed, no syntax errors.
- `php -l includes/student-shell.php`: passed, no syntax errors.
- `git diff --check` on affected paths: passed; Git reported existing LF-to-CRLF normalization warnings for the dashboard.
- `node --check js/research-basics.js`: passed with no syntax output.
- `php -l pages/student/student-dashboard.php` after the redirect change: passed, no syntax errors.
- `git diff --check` on the lesson completion handler and navigation script: passed; Git reported an LF-to-CRLF normalization warning for the dashboard.
- After the reverse-direction update, `php -l pages/student/student-dashboard.php` and `node --check js/research-basics.js` passed; `git diff --check` passed (Git reports an LF-to-CRLF normalization warning for the dashboard).
- CSS brace balance in `css/research-basics.css`: passed, 168 opening and 168 closing braces.
- `git diff --check` on the background CSS: passed.
- After moving and sizing the logos, shifting the RMS logo to the left edge, slightly reducing the EARIST size, aligning their visible artwork near the header on desktop and mobile, and making them scroll with the page, CSS brace balance remains 168/168 and `git diff --check` passed.
- After adding the mobile alignment override, CSS brace balance is 169/169 and `git diff --check` passed.
- No database changes or application interactions performed by the assistant.

## Resume / handoff
- Last user-confirmed state: None.
- Next pending action: In the integrated browser's mobile view, open and scroll the Research Basics lesson page; confirm both marks align below the header, move with the page, and leave lesson text readable.
- Blocked/skipped checks and remaining verification gaps: Browser confirmation of visibility, hiding after completion, and logout behavior pending. The worktree contains other pre-existing changes; they were left untouched.
- User-performed data changes and cleanup status: None.
