# Verification: proposal review details

- Date/time: 2026-11-08 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Improve the expanded proposal review detail hierarchy and readability while retaining document actions, title selection, comments, and decision controls.
- Environment URL: `http://localhost:8080/rms/pages/shared/research-submission-review.php?module_page=research_proposals&view=proposals&course=`
- Roles and disposable records/files: Authorized reviewer; existing proposal details for visual review only.
- Session state: In progress; awaiting user browser observation.

## Graphify and source context
- Question and selected graph vocabulary: How proposal review detail data and review decisions relate. Selected terms: `submission`, `proposal`, `review`, `title`, `details`.
- Query/lookup and result: `rtk graphify query "submission proposal review details title" --budget 1500` found conceptual nodes for submission versions/reviews and uploaded attachments. The PHP target page was not represented in the returned nodes. A narrower lookup was attempted but the graph CLI reported an ambiguous match; its advertised `get_node` command is unavailable in this installed CLI.
- Relevant source locations and verified relationships: Current source `pages/shared/research-submission-review.php` renders uploaded documents, submission snapshot details, title choices, CSRF-protected decision controls, and reviewer action handling. The graph's conceptual relationships were checked against this source.
- Missing, inferred, stale, or truncated context: Graph coverage for the live PHP review page is missing; graph relationships are conceptual hints only. Source is authoritative for this visual change.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Static PHP syntax check | Run `php -l pages/shared/research-submission-review.php` | No syntax errors | `No syntax errors detected` | Passed |
| 2 | User-operated browser, authorized reviewer, Review Proposals | Open one proposal and inspect its expanded details at desktop width | Document row, submission details, selected-title options, comment field, and decision buttons are clearer and fit without awkward wrapping | Awaiting user observation | Pending |
| 3 | User-operated browser, authorized reviewer, Review Proposals | Resize the same detail to a narrow viewport | Controls remain readable and usable without horizontal overflow | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- None observed yet.

## Assistant-run checks
- `php -l pages/shared/research-submission-review.php` — passed.
- No application interaction was performed by the assistant.

## Resume / handoff
- Last user-confirmed state: None; browser checks are pending.
- Next pending action: User opens a proposal detail, checks desktop and narrow layout, and reports whether content wraps cleanly and controls remain usable.
- Blocked/skipped checks and remaining verification gaps: Browser appearance and interaction remain unverified until the user's observation.
- User-performed data changes and cleanup status: None.
