# RMS shared skills

This is the full skill catalog for discovery and maintenance. For routine work, start with root [`AGENTS.md`](../../AGENTS.md) and its compact [task reference index](../../docs/agent-reference/README.md); do not read this entire catalog by default. Use matching skills only, and read the selected `SKILL.md` before applying it. Availability does not mean every skill runs on every request.

The vanilla RMS stack, current identity, workflow, backend permissions, and isolated E2E requirements take precedence over generic skill examples. Keep one primary visual direction. Use `design-taste-frontend-v1` only for explicit compatibility needs. Preserve the RMS identity; `brand-guidelines` specifically describes Anthropic branding and is appropriate only when that branding is requested.

For every verification task, follow [the user-operated manual testing guide](../../docs/testing/MANUAL_TESTING_GUIDE.md) and [read-only graph lookup](../../docs/agent-reference/graph-lookup.md). The user performs all application interactions one confirmed step at a time; imported browser-testing scripts do not override this default. Do not automatically install tools or rebuild the map. Read automation-specific skills only for separately, explicitly requested automated testing; preserve the isolated-database requirements.

| Need | Skills |
|---|---|
| Existing UI improvements | [redesign-existing-projects](redesign-existing-projects/SKILL.md), [design-taste-frontend](design-taste-frontend/SKILL.md), [frontend-design](frontend-design/SKILL.md) |
| A requested visual direction | [high-end-visual-design](high-end-visual-design/SKILL.md), [minimalist-ui](minimalist-ui/SKILL.md), [industrial-brutalist-ui](industrial-brutalist-ui/SKILL.md), [gpt-taste](gpt-taste/SKILL.md), [design-taste-frontend-v1](design-taste-frontend-v1/SKILL.md) |
| Design data, tokens, accessibility | [ui-ux-pro-max](ui-ux-pro-max/SKILL.md), [design-system](design-system/SKILL.md), [ui-styling](ui-styling/SKILL.md), [stitch-design-taste](stitch-design-taste/SKILL.md) |
| Brand, banners, static art | [brand](brand/SKILL.md), [brandkit](brandkit/SKILL.md), [brand-guidelines](brand-guidelines/SKILL.md), [banner-design](banner-design/SKILL.md), [canvas-design](canvas-design/SKILL.md), [design](design/SKILL.md) |
| Images and visual references | [imagegen](imagegen/SKILL.md), [image-to-code](image-to-code/SKILL.md), [imagegen-frontend-web](imagegen-frontend-web/SKILL.md), [imagegen-frontend-mobile](imagegen-frontend-mobile/SKILL.md) |
| Presentations and separate artifacts | [slides](slides/SKILL.md), [web-artifacts-builder](web-artifacts-builder/SKILL.md) |
| Default browser/workflow checks | [Manual testing guide](../../docs/testing/MANUAL_TESTING_GUIDE.md) and [graphify](graphify/SKILL.md); user performs application actions |
| Explicitly requested automation and E2E readiness | [webapp-testing](webapp-testing/SKILL.md), [rms-e2e-path-audit](rms-e2e-path-audit/SKILL.md) |
| Architecture and relationships | [graphify](graphify/SKILL.md) (follow the root guide's scope; reuse the existing graph) |
| Skill discovery and maintenance | [find-skills](find-skills/SKILL.md), [skill-creator](skill-creator/SKILL.md), [skill-installer](skill-installer/SKILL.md) |
| OpenAI product documentation | [openai-docs](openai-docs/SKILL.md) |
| Requested exhaustive output | [full-output-enforcement](full-output-enforcement/SKILL.md) |

Framework-specific skills remain available for reference or explicitly requested separate artifacts. Translate applicable interface principles into vanilla RMS code; do not install React, Tailwind, shadcn, GSAP, or another UI library as an incidental step.

Image skills require a working image-generation tool; Stitch requires the appropriate integration. Some imported design workflows reference additional skills/providers that may be absent. Check each selected skill's dependencies. Use the resource paths within its snapshot when upstream examples refer to the original installation. Report missing capabilities and continue independent work; do not invent output or silently switch to a paid API. Browser helpers remain subject to the verified disposable-database prerequisite, including for scripts bundled with a skill.

[`catalog.json`](catalog.json) records each installed snapshot's source, metadata, file count, and SHA-256 digest. Complete packages include their available scripts, assets, references, and licenses. They are copied as resources; importing them does not execute their scripts. Provider-owned plugin skills remain conditional on the corresponding client's installed plugin and tools; copying instructions cannot supply those tools.

Verify with `python scripts/sync-agent-skills.py --check` from the repository root. See [`docs/COPILOT_WORKFLOW.md`](../../docs/COPILOT_WORKFLOW.md) for setup and refresh instructions.
