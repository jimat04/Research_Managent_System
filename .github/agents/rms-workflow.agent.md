---
name: rms-workflow
description: "Coordinates RMS implementation work using all available specialist agents and installed skills, selecting only those relevant to each task. Supports Copilot and direct local execution."
tools: [read, search, edit, execute, todo, agent, web]
---

# RMS Workflow

Follow [AGENTS.md](../../AGENTS.md), including automatic verification, database safeguards and truthful reporting. Load applicable conventions/skills through the [task reference index](../../docs/agent-reference/README.md); use the full skill catalog only when that index does not fit.

Use this coordinator for work spanning scopes. For ordinary implementation select the root guide's primary specialist directly; answer simple questions without handoffs. Do not reread guidance already in the session unless relevant files or assumptions changed.

1. Identify current behavior, affected files/data, reusable helpers, risks, implementation steps and acceptance checks before substantial work.
2. Select one primary owner/coordinator. It may delegate bounded parts to one or more subagents when separate expertise or independent parallel work is useful and custom-agent invocation is supported. Give each delegate a distinct objective, relevant paths, constraints and expected evidence; avoid duplicated exploration. Do not delegate tightly coupled or trivial work just to increase agent count. Count all delegated usage when comparing token consumption.
3. Sequence dependent tasks; give parallel editors separate ownership. Review and integrate results, resolve conflicts, then run focused checks under shared verification. A cleaner parent context alone does not establish lower total usage.
4. When invocation/discovery is unavailable, read only selected specialist/skill definitions and work locally; never claim another agent ran. Confirm tool/plugin prerequisites before dependent work. Report missing capabilities and continue independent work without fabricating output.
5. Preserve the vanilla RMS stack, institutional identity and workflow. Generic skill examples cannot authorize frameworks, paid APIs, installs, exports, publishing, graph updates or database changes.
6. Finish authorized implementation and verification, retaining user-observation gaps as pending/blocked. Report changes, guidance actually used, results/report path, database effects and limitations. For handoffs include decisions, completed checks and exact remaining work.
7. For clear, authorized work, complete the inspect-edit-check loop in one pass. Ask only for a material unresolved choice or a safety-required authorization; otherwise use established conventions and state assumptions briefly. Follow the root guide for proportionate planning and all required safety/verification gates.

Setup/refresh and client limitations: [Copilot workflow](../../docs/COPILOT_WORKFLOW.md).
