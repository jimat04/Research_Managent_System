# Copilot and local agent workflow

RMS shares nine specialist definitions through `.github/agents/`. Select one specialist directly for ordinary work; use `rms-workflow` for cross-scope coordination. Start with shared [AGENTS.md](../AGENTS.md) and the compact [task reference index](agent-reference/README.md). The full [skill catalog](../.github/skills/README.md) is for discovery when the compact index does not fit. Specialist responsibilities and safety requirements remain mandatory.

## Use with Copilot

1. Open the repository root in a current VS Code version with GitHub Copilot available. Reload the window after adding these files, then start a new chat so discovery can refresh.
2. Open **Chat: Open Customizations** and check the agents and skills. Select `rms-workflow` for a task that spans scopes, or select a specialist directly. The coordinating agent selects relevant specialists and skills as work requires them.
3. Describe the intended result, affected role/page, and constraints. For example: "Use rms-ui to improve the faculty research table's mobile layout, preserving the current backend behavior."
4. Review the implementation and actual verification report. Tool availability, account access, editor version, and organization policies still control which capabilities can execute.

The tracked `.vscode/settings.json` enables skills, root agent instructions for Local sessions, Copilot instruction files, and custom agents in the Copilot harness. It contains no provider credentials or model override. Supported repository locations are used instead of deprecated custom-location settings. Other editor files remain ignored.

Copilot CLI and cloud workflows use the repository's `.github/copilot-instructions.md`, `.github/agents/`, and `.github/skills/` where those features are supported. Cloud sessions need these changes committed and available in their checkout. This setup does not push, publish, configure cloud secrets, provision a database, or install external tools.

The discovery layout follows [GitHub's skills documentation](https://docs.github.com/en/copilot/how-tos/copilot-on-github/customize-copilot/customize-cloud-agent/add-skills), [custom-agent configuration](https://docs.github.com/en/copilot/reference/custom-agents-configuration), and [VS Code skill discovery](https://code.visualstudio.com/docs/agent-customization/agent-skills). The relevant settings are documented in [VS Code's AI settings reference](https://code.visualstudio.com/docs/agents/reference/ai-settings). Discovery makes guidance available; it does not guarantee automatic invocation.

## Use locally in another client

Start from `AGENTS.md`. If the client supports these custom-agent and skill locations, use its discovery controls. Otherwise read only the selected specialist definition and applicable task references/skills directly; add the coordinator only for cross-scope work. Execute the guidance in the current session and report that fallback accurately. `CLAUDE.md` is a short compatibility entry point; its former detailed conventions now live in `docs/agent-reference/`.

Example: "Read AGENTS.md and .github/agents/rms-ui.agent.md. Apply .github/skills/redesign-existing-projects/SKILL.md to the requested page using the existing vanilla stack."

No user-home installation is required to consume the snapshots. Do not copy `config/settings.json` into editor settings: it is a separate Claude/provider configuration. `config/skills-lock.json` is also separate; an entry there does not establish that its referenced skill exists or is installed here.

## Context use and usage comparison

Load shared guidance once per session, then only the references required by the affected work. Reuse unchanged findings; recheck after relevant edits or contradictory evidence. Search named behaviors and inspect necessary source ranges, expanding whenever correctness requires it. Keep related work in one session; start fresh for unrelated work. A handoff should include the objective, affected files, decisions, completed checks and pending work.

Do not change models, disable instructions, exclude relevant source or reduce required verification to claim savings. Skills load on demand; their presence alone does not mean all bodies enter context. Delegation can reduce the parent's context while increasing total usage, so count all participating agents. Actual instructions loaded depend on client/version/settings; confirm discovery in each client.

The [comparison protocol](agent-reference/context-benchmark.md) provides fixed read-only prompts, quality gates and a baseline/results format for VS Code, CLI and cloud. The [requirement map](agent-reference/requirement-map.md) records where original guidance was retained. Neither document is routine application-task context. Repository byte/character counts are proxies, not measured tokens or billed savings. Live client comparisons remain required before claiming unchanged task quality or actual usage savings.

## Personal VS Code workflow preference

The active VS Code profile can apply a user-level instruction across workspaces. The current preference uses a focused investigate/edit/check/report loop, avoids repeated questions for routine reversible changes, and asks when ambiguity materially affects the result or safe execution. It is not committed with the repository. RMS `AGENTS.md`, task references, and safety requirements remain authoritative; a personal preference cannot waive them. Other clients only receive this preference if their own user-level instructions are configured.

To compare local Codex and Copilot rather than assume one is faster, use the matched-task protocol in [the context benchmark](agent-reference/context-benchmark.md). It separates elapsed time and interaction counts from correctness, verification, and usage telemetry; unavailable measurements remain unknown.

## Default verification with Copilot and Codex

Follow [the user-operated verification guide](testing/MANUAL_TESTING_GUIDE.md) whenever you ask to test/check/confirm behavior, or when the assistant identifies verification needed after a feature, fix, workflow, permission, or UI change. The root and Copilot instructions activate it without a special prompt or testing-agent selection. These repository instructions apply to clients that load them; they do not install a global setting outside RMS.

The assistant consults the existing Graphify map, checks current source, and prepares relevant checks. You perform every application interaction in the integrated browser: navigation, login/logout, account switching, input, button clicks, uploads/downloads, and record changes. The assistant gives one actionable step with an expected outcome and a specific question, waits for your response, and records your observation. It must not replace your actions with scripts, API calls, database edits, fabricated sessions, or bypassed safeguards. Enter credentials only in the application, never in chat.

Reports are saved in `docs/testing/manual-reports/YYYY-MM-DD-workflow-NN.md` using Asia/Taipei dates. Failures are recorded before the assistant asks whether to fix now or continue independent checks; authorized fixes are retested by you. The report retains pending/blocked/skipped checks and supports resuming a session. Source review and focused non-mutating static checks remain assistant work and are reported separately from your browser confirmations.

No E2E setup, Playwright execution, or `RMS_E2E_*` variables are required for this default. Existing automation remains available only if explicitly requested separately, with all database isolation safeguards intact. Optional prompt: "Help me test [workflow] using the manual testing guide and Graphify. I will operate the integrated browser; wait for my observations after each step and save the results."

## How work is routed

The root guide maps tasks to all nine specialists: `rms-db`, `rms-security-auth`, `rms-debug`, `rms-page-builder`, `rms-ui`, `rms-testing`, `rms-performance`, `rms-backup`, and `rms-doc`. The coordinator chooses the primary owner, adds help for distinct scopes, integrates results, and runs focused checks. It reads specialist definitions directly if delegation is unavailable.

Skills provide task-specific guidance. Choose one primary visual direction rather than loading conflicting design systems. Root RMS stack, identity, permissions, workflow history, migration rules, and test isolation remain authoritative. Preserve existing worktree changes. Do not add frameworks or change a database because a generic skill example suggests it.

Generated images, browser automation, Stitch, API providers, and external design dependencies require tools actually available in the chosen client. Some skills refer to upstream helpers not installed here; inspect prerequisites before depending on them. Provider-owned skills such as Sites, Work Pets, and plugin management remain available only through the client's corresponding plugin; repository snapshots cannot install their connector backends. Report a missing capability and continue independent work instead of claiming it ran.

## Verify and refresh snapshots

Python 3.9 or newer is required for the sync helper. Verification uses only the repository, without network, home-directory access, application execution, or a database:

```powershell
python scripts/sync-agent-skills.py --check
```

The catalog records original source locations, required metadata, aggregate SHA-256 hashes over sorted resource paths and file contents, and file counts. Verification detects added, missing, and modified snapshot resources. It checks required name/description fields and directory naming; it is not a full YAML parser or a test of external provider integrations.

The scoped `.gitattributes` rule preserves snapshot bytes during Git checkout so newline conversion does not invalidate their hashes on a different operating system.

To discover local updates without writing, run:

```powershell
python scripts/sync-agent-skills.py
```

To copy installed packages and update the catalog:

```powershell
python scripts/sync-agent-skills.py --sync
python scripts/sync-agent-skills.py --check
```

Default source precedence is `~/.agents/skills`, `~/.copilot/skills`, `~/.claude/skills`, `~/.codex/skills`, then the public `imagegen`, `openai-docs`, `skill-creator`, and `skill-installer` packages under `~/.codex/skills/.system`. First source wins duplicate names. Internal system-only agents are excluded. Use repeated `--source <directory>` arguments to supply different locations in priority order.

Refresh preserves sources and previously imported skills that are no longer installed locally. It stops before writing if an existing snapshot has local edits, source resources were removed, or a linked/credential-like resource needs manual review. Caches and dependency environments are excluded. Review upstream changes before using refreshed scripts; the helper copies files without executing them. Update the task index if adding a new skill, then review `git diff --check` and the scoped diff. No automatic hook or scheduled refresh is installed.

This is tooling guidance only. PHP/database behavior is unchanged. Do not run mutating browser tests on the development database to validate this setup. A live Copilot discovery check must be performed in the editor; catalog verification cannot establish editor/account or plugin availability.
