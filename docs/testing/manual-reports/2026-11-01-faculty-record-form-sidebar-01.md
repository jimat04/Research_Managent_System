# Verification: faculty research record form sidebar highlight

- Date: 2026-11-01, Asia/Taipei
- Assistant: Codex
- Scope: correct the faculty sidebar active item for the faculty research record Add/Edit form.
- Expected behavior: opening the form marks **Add Entry** as the current page; portfolio list and detail routes remain unchanged.
- Specialist workflow: read `.github/agents/rms-ui.agent.md` and applied its guidance locally; no specialist invocation was available.

## Graphify and source context

- Question: how does the faculty sidebar choose the active item for the faculty research record form?
- Selected actual vocabulary: faculty, shell, research record.
- Lookup: `graphify query "Faculty and Student Workspace PHP Handlers and Shared Helpers" --budget 1200` (existing graph, read-only).
- Result: `faculty-shell.php` and the faculty workspace appeared among 73 nodes; output was truncated at the requested budget. Current source was inspected directly and is authoritative for this narrow behavior.
- Confirmed source: `includes/faculty-shell.php` links **Add Entry** to `faculty-research-record-form.php` and matches links against the supplied current-page slug. The form had supplied `faculty-research-records`, which selects **View Portfolio**. It now supplies `faculty-research-record-form`.
- Limitation: the graph lookup was broad and truncated; no conclusion depends on omitted graph nodes or edges.

## Assistant checks

| ID | Check | Expected | Observed | Status |
|---|---|---|---|---|
| S01 | `php -l pages/faculty/faculty-research-record-form.php` | No PHP syntax errors | No syntax errors detected | Passed |
| S02 | Source review of form shell call and faculty matcher | Form slug matches the Add Entry link; list/detail routes remain on the portfolio slug | Form now supplies `faculty-research-record-form`; list and detail still supply `faculty-research-records` | Passed |
| S03 | Scoped final source review | Form shell call names the form route; related portfolio list/detail calls are unchanged | Confirmed in source. The target PHP page is untracked in Git, so Git cannot provide a tracked-file diff for it. | Passed with limitation |

## Manual browser check

| ID | Role / page | Action | Expected | Observation | Status |
|---|---|---|---|---|---|
| M01 | Faculty / Research Portfolio | Click **Add Entry** in the faculty sidebar | The research record form opens and **Add Entry** is visibly highlighted with current-page semantics | Awaiting user observation | Pending |

No browser interaction was performed by the assistant. No database changes or record mutations are part of this check.
