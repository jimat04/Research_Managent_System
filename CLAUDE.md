# RMS Claude entry point

Read and follow [AGENTS.md](AGENTS.md) before working, even if this client did not load it automatically. It owns shared safety, routing and verification requirements.

Use the [task reference index](docs/agent-reference/README.md) to load mandatory conventions for the affected work. Detailed setup, architecture, workflow/security, coding and UI guidance formerly in this file now lives there. Do not load every reference or specialist by default.

Use one specialist directly; reserve `rms-workflow` for cross-scope work. If custom agents or skills cannot be invoked, read the selected definitions and work locally without claiming an invocation. Client setup and skill refresh are documented in [Copilot workflow](docs/COPILOT_WORKFLOW.md).

The local Claude Code Graphify skill is at `.claude/skills/graphify/SKILL.md`; invoke `/graphify` with a project question. It uses the existing `graphify-out/graph.json` and points to the interactive `graphify-out/graph.html`. Follow the [read-only lookup procedure](docs/agent-reference/graph-lookup.md); graph maintenance requires explicit scope.
