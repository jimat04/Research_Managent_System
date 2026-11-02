# Copilot context comparison protocol

This is an operator-run acceptance check, not routine agent context. The cleanup changes instructions only. Reduced file size does not establish lower total token usage or unchanged work quality.

## Baseline and controls

- Original worktree sizes/hashes were captured before editing in [context-baseline.json](context-baseline.json). No live Copilot usage was observable from the Codex session; baseline task usage is **not measured**.
- Original instruction files were also copied to a local temporary snapshot before editing. The session report records its location. Preserve that snapshot if comparison will happen after temporary-file cleanup; never restore it over the active worktree.
- Compare isolated source copies/checkouts of the same application source state, with only the instruction cleanup differing. The current repository has substantial uncommitted work: an older HEAD checkout is not an equivalent baseline. Overlay the original instruction snapshot in the before-copy and the cleanup in the after-copy. Do not transfer secrets, runtime uploads or private records to cloud.
- In each client keep model, reasoning level if configurable, extensions/tools, permissions and agent selection identical between before/after. Use fresh sessions with no unrelated attached files. Record client/version/model/settings and source identifiers. Do not change account settings or disable guidance to achieve savings.
- Run all four prompts below in VS Code before/after. In CLI and cloud, run the permission prompt plus the discovery check before/after. If a result is borderline or highly variable, repeat that matched pair and retain both results. Do not average different models or clients together.
- The operator initiates client sessions. Cloud comparison requires the intended source/instructions in an authorized checkout; do not push/publish or alter production data automatically. Unavailable clients, source copies or usage telemetry are recorded as blocked, not simulated.

## Fixed prompts

Use exactly the same prompt and specialist in each matched pair. Each task is source-only: no edits, application interactions, API requests, database connections or graph writes. Reports belong in the operator's comparison record, not benchmark-run source copies. If a session instruction requires saving a verification report, record its output as a proposed report for the operator to save outside those source copies. This benchmark's read-only scope prohibits writing inside them.

| ID / specialist | Prompt |
|---|---|
| UI / rms-ui | Review the faculty research list's narrow-screen overflow risk using current source and its owning stylesheet/shell. Identify the relevant rules and source locations, and propose the smallest change only if evidence supports one. State which desktop/mobile/theme checks remain unconfirmed. Work read-only: do not edit files, interact with the application, connect to its database, or write graph/cache/report files. |
| Permission / rms-security-auth | Trace research-staff access to title/chapter review from navigation through backend handlers and permission helpers. Explain allowed/denied cases, ownership or assignment constraints, CSRF and submitted-version locks with current source locations. Do not assume staff have broad approval access. Work read-only: do not edit files, interact with the application, connect to its database, or write graph/cache/report files. |
| Workflow / rms-page-builder | Explain title approval, sequential chapter submission/review, returned-version revision/resubmission, retained reviewer comments/history, and final-manuscript completion gating. Identify relevant helpers, eligibility constraints and backend locks from current source; list focused user-operated acceptance checks as pending. Work read-only: do not edit files, interact with the application, connect to its database, or write graph/cache/report files. |
| Database / rms-db | Plan support for an optional internal review note on submission decisions, first checking whether existing comments/recommendations already meet the need. Inspect the relevant canonical schema, migrations and runner code; propose no duplicate storage. If a change is needed, describe additive migration/schema synchronization, permissions, data preservation, backup and verification. Explain the needs-verifier restriction on migration 010; do not run it. Work read-only: do not edit files, interact with the application, connect to its database, or write graph/cache/report files. |

Discovery check (all three clients): ask the permission session to identify which repository instruction entry points, specialist and task references it actually loaded, with paths. Confirm using exposed customization/context/tool details where available; an agent's unsupported assertion alone does not prove automatic discovery. The same safety and manual-verification triggers must apply when a specialist is chosen directly and when guidance is read locally because invocation is unavailable.

## Evidence and quality gates

For each matched pair record:

- Exposed input/output token usage, cached input separately when available, and any delegated usage. Include all requests/agents; avoid double-counting cache categories or treating final context occupancy as total consumption. Missing telemetry stays unknown.
- File-read/search counts, repeated unchanged reads and tool-output volume where observable. Label bytes/characters/whitespace words as proxies; do not convert them into exact tokens or billed savings.
- Completion and correctness against independently inspected current source: relevant callers/helpers, accurate locations, correct role/ownership/CSRF/lock handling, retained status/history/eligibility/final approval rules, migration safeguards, component/identity preservation and honest pending verification.
- No invented bugs, results, tools, infrastructure or live measurements; no skipped required investigation and no safeguard bypasses. A visible UI control does not establish backend security.

| Client / task / source | Model and settings | Before usage / reads / output | After usage / reads / output | Quality result and evidence | Status |
|---|---|---|---|---|---|
| VS Code / UI | Not yet recorded | Not measured | Not measured | Awaiting matched sessions | Pending |
| VS Code / Permission | Not yet recorded | Not measured | Not measured | Awaiting matched sessions | Pending |
| VS Code / Workflow | Not yet recorded | Not measured | Not measured | Awaiting matched sessions | Pending |
| VS Code / Database | Not yet recorded | Not measured | Not measured | Awaiting matched sessions | Pending |
| CLI / Permission + discovery | Not yet recorded | Not measured | Not measured | Awaiting client | Pending |
| Cloud / Permission + discovery | Not yet recorded | Not measured | Not measured | Awaiting authorized equivalent checkout | Pending |

The static target is at least 60% less combined UTF-8 text in the three original startup candidates, calculated against the captured worktree baseline. Also report character counts and the bytes in required task references; do not describe relocated text as eliminated task context. Clients load different instruction sets, so report per-client observed context separately.

Accept actual usage/quality claims only after matched runs show complete, correct work with no quality regression and lower measured usage (or clearly labeled proxy evidence if tokens are unavailable). Compare each task and report the median matched reduction where comparable, without promising a fixed task-level percentage. If a check regresses, restore the missing requirement or improve routing and repeat affected checks; retain the original failure. Static documentation delivery can be complete while client acceptance remains pending.

## Local Codex vs Copilot workflow comparison

This is a separate, operator-run comparison of the user's local Codex and Copilot in VS Code. It measures the end-to-end client/model/tool experience, not the effect of instructions alone. Do not change models, provider settings, permissions, or project safeguards to improve a result.

- Use the same prompt, acceptance criteria, and exact source snapshot in fresh sessions. Run each client on its own isolated local copy for any edit; never let one client's changes become the other client's starting state. Do not copy credentials, runtime uploads, private records, or production data.
- Cover at least one read-only investigation/analysis task, one bounded edit, and one focused checking/review task. Keep checks source-only unless the user separately approves and operates the required application verification.
- Record client/version, model if exposed, selected agent, loaded guidance where verifiable, permissions, and source identifier. Because clients may use different models and tools, report those differences rather than claiming a controlled model-only comparison.
- For each task, record elapsed time from prompt submission to a usable result, turns/questions, observable tool and file activity, edits, focused checks, and an independent correctness/requirement assessment. Evaluate correctness and required verification before speed.
- Record token or cost data only when the client exposes it; otherwise mark it unknown. Report per-task results and do not turn anecdotal speed, fewer tool calls, or text-size proxies into a measured performance claim.

The user initiates the local Codex runs and confirms any VS Code discovery that the assistant cannot observe. If equivalent source copies, telemetry, or discovery evidence are unavailable, mark that part blocked or unknown rather than simulating it.
