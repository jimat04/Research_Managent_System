# Verification: Copilot context cleanup

- Date: 2026-10-24 (Asia/Taipei)
- Assistant: Codex
- Target: consolidate instruction context without weakening RMS safety, workflow or verification requirements.
- Environment URL: not applicable; documentation/configuration guidance only.
- Roles and disposable records/files: no accounts or application records used.
- Session state: static verification in progress; live client comparisons pending.

## Graphify and source context

- Question: which instruction entry points, specialists and skill references govern Copilot work?
- Selected existing vocabulary: `copilot agents skills documentation`.
- Command: `graphify query "copilot agents skills documentation" --budget 900`.
- Result: 61 nodes found; 25 displayed, with 36 truncated. Relevant nodes included `.github/copilot-instructions.md:L1`, `AGENTS.md:L1`, `CLAUDE.md:L1`, and `.github/agents/rms-doc.agent.md:L1`/`L10`/`L26` (pre-cleanup locations).
- Confirmed by source: root routing, repeated manual-verification policies, specialist ownership and fallback, full skill-catalog loading, and detailed CLAUDE conventions.
- Limitations: map has historical removed E2E-suite references; source/manual guide take precedence. No graph rebuild/update or feedback writes were performed. Narrow direct-node/edge lookup for the changed instruction relationship is pending before final checks.

## Baseline captured before edits

- Original files: AGENTS 10,641 bytes; CLAUDE 22,744 bytes; Copilot instructions 3,521 bytes. Combined: **36,906 UTF-8 bytes / 36,715 decoded characters**. These are text-size proxies, not tokens.
- File sizes, whitespace-word counts and SHA-256 hashes for 16 relevant guidance files: [baseline manifest](../../agent-reference/context-baseline.json).
- Original instruction snapshot: `C:\Users\jimwe\AppData\Local\Temp\rms-context-baseline-20261024-5w_hh1_6`. Local temporary recovery/comparison copy only; not a cloud artifact. It contains the original documented setup examples, so do not attach its raw contents to chat/logs. Preserve locally if needed before temp cleanup.
- Existing worktree: substantial unrelated modifications/deletions/untracked files. Cleanup is based on current contents, not HEAD; unrelated work is preserved.
- Actual baseline client/task usage: **not measured**. No connected VS Code/CLI/cloud session telemetry is exposed here. Original instruction snapshot enables a later matched comparison in isolated equivalent source copies.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| C01 | Assistant/static | Measure three entry files against baseline | At least 60% less combined text | Awaiting final measurement | Pending |
| C02 | Assistant/source | Audit retained requirements and relocation | No missing safety/workflow/verification controls | Requirement map prepared before rewrite; final review pending | Pending |
| C03 | Assistant/static | Check edited/new Markdown links and agent routing | Valid local targets, all nine specialists, correct fallback | Pending command | Pending |
| C04 | Assistant/static | Check installed skill snapshot integrity | Packages/catalog unchanged and check passes | Pending command | Pending |
| C05 | Assistant/static | Review session diff and whitespace | Only intended guidance changes, no accidental rewrites | Pending command | Pending |
| C06 | Operator/VS Code | Four matched source-only tasks and discovery | Complete equivalent-quality work with measured savings | Client sessions/telemetry unavailable to assistant | Blocked |
| C07 | Operator/CLI | Permission task and discovery before/after | Shared safety/reference loading and measured usage | Client sessions/telemetry unavailable to assistant | Blocked |
| C08 | Operator/cloud | Permission task and discovery before/after | Equivalent authorized source checkout and shared rules | No connected cloud session or published equivalent checkout | Blocked |
| C09 | Application browser | Exercise RMS routes | Not applicable to instruction-only edits | No application code changed; no interaction invented | Skipped |
| C10 | Assistant/PHP | PHP syntax checks | Only needed for changed PHP | No PHP changed | Skipped |

## Failures, decisions, fixes, and retests

- No application behavior was tested and no application failure was found.
- Initial Git status encountered ownership protection. Used command-scoped `git -c safe.directory=C:/xampp/htdocs/rms ...`; did not alter global Git configuration. Existing home-ignore permission warnings are environmental.
- No fixes outside the instruction cleanup, database operations, publishing, model/settings changes or automatic cleanup.

## Assistant-run checks

- Final commands/results will be appended after execution. Live token reductions and unchanged task quality remain unconfirmed regardless of static size reduction.

## Resume / handoff

- Last user-confirmed client/browser state: none in this session.
- Next operator action: select the existing `rms-security-auth` specialist in VS Code's customization UI and confirm it is available; then use the [comparison protocol](../../agent-reference/context-benchmark.md), one confirmed operator action at a time.
- Preserve original instructions for baseline comparison; do not overwrite the active dirty worktree to run a baseline.
- User data changes and cleanup: none. Remaining work: client discovery, matched before/after usage and independent quality comparison.
