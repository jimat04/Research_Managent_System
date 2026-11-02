# Verification: user-operated verification defaults

- Date/time: 2026-10-24, Asia/Taipei; checks recorded after 03:33.
- Assistant: Codex.
- Target and expected behavior: RMS instruction entry points automatically route testing/checking/confirmation and post-change validation to the shared manual guide. The user operates every application interaction; the assistant prepares context, waits for observations, and saves results.
- Environment URL: not applicable; documentation-only change, no application requests.
- Roles and disposable records/files: none required; no accounts, credentials, uploads, or database records used.
- Session state: finished for documentation verification; live client/browser adherence remains unverified.

## Graphify and source context

- Question: which project instruction and testing documents are relevant to making user-operated verification the default?
- Selected graph vocabulary: `copilot`, `instructions`, `testing`, `workflow`, `manual`, confirmed against existing node labels.
- Command: `graphify query 'copilot instructions testing workflow manual' --budget 1500`.
- Result: exit 0; 1,588-node graph, BFS depth 2, 83 related nodes found, 43 displayed within the token budget.
- Useful source locations: `.github/copilot-instructions.md:L1` (Copilot Instructions), `AGENTS.md:L1` (RMS Agent Guide), `CLAUDE.md:L1`, `.github/agents/rms-testing.agent.md:L1`, `.github/agents/rms-doc.agent.md:L1`, and `docs/testing/E2E_WALKTHROUGH.md:L36` (historical manual checklist). These are graph-provided locations before this edit, not guaranteed current line numbers.
- Verified against source: root instructions route specialist selection; Copilot instructions refer to the root guide and specialist workflow; the testing specialist previously prioritized automation; Claude and several specialists also suggested browser automation. The documentation changes align these entry points with the shared guide.
- Limitations: query output was truncated and contains historical/automation context. Relevant instruction files were read directly; the lookup was navigation assistance, not an exhaustive dependency audit. New guide/report files have not been added to the existing map. No rebuild/update, tool installation, reflection, or saved-result graph write was performed.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| DOC-01 | Assistant / source | Review root, Copilot, Claude, workflow/testing specialists, related specialists, and skill routing | Entry points reference the shared default | 13 existing instruction documents link to the guide; automation defaults replaced or qualified | Passed |
| DOC-02 | Assistant / source | Inspect the shared guide against required rules | User owns application actions; one step/wait; no bypass; Graphify; reports; failure decisions; resume | Inline documentation check found all 18 checked requirements; manual content review covered the agreed interaction rules | Passed |
| DOC-03 | Assistant / static | Check relative Markdown links and whitespace in 14 documents | Targets exist; no trailing whitespace | Inline Python check: 60 local links resolved, no trailing whitespace | Passed |
| DOC-04 | Assistant / Git | Run scoped diff whitespace check | No whitespace errors in changed tracked documentation | Exit 0 | Passed |
| DOC-05 | Assistant / Git | Run full worktree diff whitespace check | No whitespace errors | Exit 2: existing unrelated `public/login.php:737` trailing whitespace; file was not edited in this task | Failed |
| APP-01 | User / integrated browser | Exercise application behavior | Applicable only when an application workflow is under test | Documentation-only scope; no application behavior was exercised or claimed verified | Skipped |
| CLIENT-01 | User / future Copilot or Codex session | Observe live instruction loading and one-step guidance | Client loads the default and waits for user-operated actions | No live Copilot/editor discovery or application-guidance session performed | Pending |

## Failures, decisions, fixes, and retests

- DOC-05: the full worktree whitespace check found trailing whitespace in a pre-existing modified application file. Preserve unrelated work: no fix, application edit, or database action was made. The scoped documentation check passed; the full worktree result remains failed.
- No user-reported application failure or fix/continue decision occurred. No browser retest was performed.

## Assistant-run checks

- `graphify query 'copilot instructions testing workflow manual' --budget 1500`: exit 0; contextual lookup with truncated output, verified through targeted source reads.
- `python -` with the inline documentation inspection: checked 60 local Markdown links, trailing whitespace in 14 documents, 18 required guide terms/sections, and guide links in 13 existing entry points; exit 0.
- Scoped command: `git -c safe.directory=C:/xampp/htdocs/rms -c core.safecrlf=false diff --check -- AGENTS.md CLAUDE.md .github/copilot-instructions.md .github/agents/rms-testing.agent.md .github/agents/rms-workflow.agent.md .github/agents/rms-doc.agent.md .github/agents/rms-debug.agent.md .github/agents/rms-page-builder.agent.md .github/agents/rms-ui.agent.md .github/agents/rms-security-auth.agent.md .github/agents/rms-performance.agent.md .github/skills/README.md docs/COPILOT_WORKFLOW.md`: exit 0. Git's tracked diff does not include new untracked documents; those were checked directly.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check`: exit 2 with the DOC-05 finding. Unrelated line-ending warnings do not establish an application problem.
- Reviewed task changes against an in-session snapshot of the 13 existing instruction documents to distinguish them from pre-existing worktree changes.
- No PHP changes, so PHP lint was not applicable. No Playwright/E2E tests, application requests, migrations, database operations, or infrastructure changes were performed.

## Resume / handoff

- Last user-confirmed state: the user authorized implementing the documentation plan; no browser page or login state was confirmed.
- Next pending action: when an actual application check is requested or needed, load the guide and confirm the user's current browser/role state one step at a time. A fresh Copilot chat/reload may be needed to load changed instruction files; this has not been tested.
- Remaining gaps: CLIENT-01; static documentation inspection cannot prove instruction adherence across clients. No application workflow is marked passed by this report.
- User-performed data changes and cleanup status: none. Application/database/test infrastructure and historical E2E records were left unchanged by this task.
