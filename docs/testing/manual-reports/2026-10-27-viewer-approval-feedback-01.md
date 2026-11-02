# Verification: viewer approval feedback confirmation

- Date/time: 2026-10-27 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: In the research document viewer, clicking Approve with nonblank comments, recommendation, or both opens a confirmation dialog that previews the entered feedback before the reviewer chooses cancel, return, or approve.
- Environment URL: http://localhost/rms/pages/shared/download-research-document.php?type=research&id=98&mode=viewer (provided by user; local availability not confirmed)
- Roles and disposable records/files: Administrator; use temporary unsaved feedback only; do not approve or return the real submission during this check.
- Session state: in progress

## Graphify and source context
- Question and selected graph vocabulary: download research document, review, approval, submission version.
- Query/lookup and result: `graphify query "download research document review approval submission version" --budget 1500`; found the viewer node but results were truncated and included unrelated stale nodes.
- Relevant source locations and verified relationships: `pages/shared/download-research-document.php` owns viewer review form and POST handler, passing comments and recommendation to `rms_review_submission()`; `includes/research-workflow.php` records them in review history. Current viewer CSP was `default-src 'none'`, so the added same-origin script is allowed via `script-src 'self'`.
- Missing, inferred, stale, or truncated context: Graph query truncated at 39/43 nodes and did not give dependable detail for the viewer handler; current source was inspected directly.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Integrated browser / administrator / provided research document viewer | Enter short temporary text in comments only, click Approve, inspect popup, then Cancel | Dialog opens and previews the comment; no approval is submitted | Awaiting user observation | Pending |
| 2 | Integrated browser / administrator / same viewer | Repeat with recommendation only, then with both fields populated; cancel each popup | Each nonempty field is shown accurately; blank fields are omitted | Awaiting user observation | Pending |
| 3 | Integrated browser / administrator / same viewer | Click Approve with both feedback fields blank | No feedback confirmation appears; normal approval submission proceeds | Not to be performed on the real record | Skipped — would submit a real approval |

## Failures, decisions, fixes, and retests
- Root cause: the previous popup listener existed only on the review queue; the document viewer has a separate form and a restrictive CSP that blocks inline scripts.
- Proposal review helper previously discarded a separate recommendation. It now retains it and applies the existing consolidated minimum-length rule when returning proposals.

## Assistant-run checks
- `php -l pages/shared/download-research-document.php`: passed.
- `php -l includes/research-workflow.php`: passed.
- `node --check js/research-document-review.js`: passed.
- `git diff --check` for changed files: passed.
- No database actions or application interactions were performed.

## Resume / handoff
- Last user-confirmed state: viewer did not show the expected popup before this change.
- Next pending action: user checks comment-only and recommendation/both cases in the integrated browser, cancels each modal, and reports what appeared.
- Blocked/skipped checks and remaining verification gaps: browser confirmation pending; actual approval is intentionally not exercised on this record.
- User-performed data changes and cleanup status: none.
