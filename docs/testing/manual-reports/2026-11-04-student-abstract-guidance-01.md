# Verification: student abstract guidance

- Date/time: 2026-11-04 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The student final-manuscript form displays advisory abstract guidance and a live word count; the 200–250-word recommendation does not block submission.
- Environment URL: Not applicable to assistant-run checks.
- Roles and disposable records/files: Student role; no record or file used by assistant.
- Session state: In progress; awaiting the user's browser observation.

## Graphify and source context
- Question and selected graph vocabulary: Trace the student final-manuscript abstract field and its submission flow. Selected existing labels: `ABSTRACT`, `Manuscript changes`, `Institutional Research Workflow`.
- Query/lookup and result: Read-only Graphify query `graphify query "final manuscript abstract" --budget 1000`; Graphify returned 22 nodes and 28 edges, over the requested budget, but no node for `pages/researcher/submit-final-research.php`. A prior query with broader terms also did not cover this page. Current-source inspection was used as the fallback.
- Relevant source locations and verified relationships: `pages/researcher/submit-final-research.php` requires the student role, checks project membership, chapter approvals, final-submission locks and CSRF before saving the abstract in the existing final-manuscript version and `research_projects.abstract`. The form is shown only when submission is available. The 2015 research manual mentions abstracts and APA journal submissions but does not establish the detailed undergraduate rules in this checklist.
- Missing, inferred, stale, or truncated context: The graph lacks the target final-manuscript page and its current relationships. Graphify returned more nodes/edges than its requested output budget. A query also changed Graphify cache/map-related worktree files; these and all other pre-existing worktree changes were left untouched.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| A1 | Assistant static check | Run `php -l pages/researcher/submit-final-research.php` | PHP syntax is valid | `No syntax errors detected` | Passed |
| A2 | Assistant static check | Run `git diff --check` | No whitespace errors in the edited page | No errors reported | Passed |
| B1 | Student / Submit Final Research | Open the final-manuscript form for a project with all five chapters approved | Checklist and live advisory count are visible; submission remains available | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- `php -l pages/researcher/submit-final-research.php` — passed.
- `git diff --check` — passed.
- No application interaction, submission, database mutation, or upload was performed by the assistant.

## Resume / handoff
- Last user-confirmed state: No browser observation yet.
- Next pending action: User opens the student final-manuscript form and reports whether the guidance and count appear.
- Blocked/skipped checks and remaining verification gaps: Browser rendering, count updates, and non-blocking submission behavior remain unconfirmed by the user.
- User-performed data changes and cleanup status: None.
