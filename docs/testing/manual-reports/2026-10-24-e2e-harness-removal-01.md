# Verification: remove browser E2E harness references

- Date: 2026-10-24 (Asia/Taipei).
- Target: delete the browser E2E directory, correct active project/testing guidance, and direct current verification to the collaborative user-operated manual guide.
- Expected: the full `tests/e2e-browser/` directory is absent; active instructions do not claim the suite or README exists; historical reports remain intact; no application/browser/database work occurs.
- Environment URL: not applicable (documentation and repository change only).
- Roles and disposable data: none; no accounts, application records, or uploads used.

## Graphify and source context

- Question: identify current testing guidance and historical browser-test documentation before removing stale suite instructions.
- Selected terms, verified against the existing graph vocabulary: `browser`, `testing`, `manual`, `documentation`, `workflow`.
- Command: `graphify query "browser testing manual documentation workflow" --budget 1500`.
- Result: exit 0; 96 nodes found, 43 displayed before truncation. Relevant nodes included `Manual lifecycle checklist` (`docs/testing/E2E_WALKTHROUGH.md`), `Documentation Rules` (`.github/agents/rms-doc.agent.md`), `RMS Agent Guide` (`AGENTS.md`), `CLAUDE.md`, `RMS Testing Agent` (`.github/agents/rms-testing.agent.md`), `RMS Agent Index` (`.github/agents/AGENTS_MEMORY.md`), `RMS browser E2E harness`, and `RMS E2E Database Candidate`.
- Source review confirmed the active guidance and testing-doc references directly against the current files before editing.
- Limitation: the graph is a pre-change snapshot that still contains nodes for the removed directory; output was truncated. It was not rebuilt or updated. The map was used for navigation only, with final references checked through repository search.

## Checks

| ID | Method | Expected | Result | Status |
|---|---|---|---|---|
| DOC-01 | Assistant/source review of active instructions and testing docs | Manual guide is the current workflow; no active suite/README claims | Reviewed and revised the root guide, specialist guidance, manual guide, uploader scenarios, and archived walkthrough/candidate docs | Passed |
| FS-01 | Assistant/file system | Target directory and all contents are gone | Recursive deletion completed; `Test-Path tests/e2e-browser` returned false | Passed |
| REF-01 | Assistant/repository search | No active operational reference points to the removed directory; historical evidence is preserved | No active project guidance or testing document refers to the removed directory/README. Remaining exact path mentions are in this report and preserved historical material: thesis implementation evidence, `.github/agents/AGENTS_ANALYSIS.md`, and the old progress audit. | Passed |
| DOC-02 | Assistant/Git | Scoped whitespace check on changed documentation is clean | `git diff --check` scoped to the changed tracked guidance/testing docs and database candidate README exited 0. | Passed |
| DOC-03 | Assistant/static | Relative Markdown links in changed docs resolve | Python check covered 14 Markdown files and 20 relative links; 0 unresolved. | Passed |
| DOC-04 | Assistant/static | Broader whitespace scan reports no issues | The scan found 8 trailing-whitespace lines in unchanged portions of the already-modified `docs/FILE_UPLOADER_TESTING.md` (lines 47, 221–224, 226, 307–308). The scoped Git diff check confirms no whitespace issue was introduced by this change. These unrelated lines were preserved. | Failed |
| APP-01 | User/integrated browser | No app interaction required for this documentation/repository task | No browser interaction or application behavior claimed | Skipped |
| DB-01 | Assistant/database | No database work required | No database connection, query, migration, or mutation performed | Skipped |

## Files changed

- Active guidance: `AGENTS.md`, `CLAUDE.md`, `.github/copilot-instructions.md`, `.github/agents/rms-testing.agent.md`, `.github/agents/rms-performance.agent.md`, `.github/agents/rms-doc.agent.md`, `.github/agents/rms-debug.agent.md`, and `.github/agents/AGENTS_MEMORY.md`.
- Testing documentation: `docs/testing/MANUAL_TESTING_GUIDE.md`, `docs/testing/E2E_WALKTHROUGH.md`, and `docs/FILE_UPLOADER_TESTING.md`.
- Archived candidate note: `database/e2e/README.md` now says its material is historical and not for provisioning/testing.
- Removed: the entire `tests/e2e-browser/` tree, including modified and untracked files.
- Preserved historical evidence: `.github/agents/AGENTS_ANALYSIS.md`, progress/audit reports, and prior test-result archives were not edited.

## Application/data effects and remaining gaps

- No application data, database, account, or upload effects; no cleanup required.
- No browser outcome was requested or confirmed.
- The pre-existing Graphify artifact remains stale until separately rebuilt by an authorized task.
- Historical audit documents can still describe the former suite and its old results; they are archive evidence, not current operational instructions.
- Thesis implementation evidence, the historical progress audit, `AGENTS_ANALYSIS.md`, and old test-result archives were deliberately left intact per scope.
