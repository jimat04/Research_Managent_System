# RMS task references

Read [AGENTS.md](../../AGENTS.md) first. Before affected work, load the matching rows below; these conventions are mandatory, not optional background. Read additional rows only when the task crosses those concerns. Code paths in the references are repository-relative.

| Affected work | Required reference |
|---|---|
| Setup, environment/configuration, XAMPP prerequisites | [Setup](setup.md) |
| Routing, role/module boundaries, shell/helper relationships | [Architecture](architecture.md) |
| PHP/SQL, authentication, permissions, research states or sensitive documents | [Workflow and security](workflow-security.md) |
| Code generation/edits, include/link/query/audit conventions | [Coding](coding.md) |
| UI, new pages/components, responsive behavior or visual QA | [UI](ui.md) |
| Any verification or architecture/relationship trace if necessary | [Graph lookup](graph-lookup.md); verification also requires the [manual guide](../testing/MANUAL_TESTING_GUIDE.md) |

Select the specialist from the root table. For skills, prefer these narrow defaults:

- Existing UI redesign: [redesign-existing-projects](../../.github/skills/redesign-existing-projects/SKILL.md). A small local CSS fix can use the UI reference and specialist without loading a broad design workflow.
- New substantial visual work: [design-taste-frontend](../../.github/skills/design-taste-frontend/SKILL.md), using the RMS identity/stack. Choose one primary visual skill.
- Explicitly requested images: [imagegen](../../.github/skills/imagegen/SKILL.md), only with available image-generation tools.
- Default verification: manual guide and graph lookup above; do not load automation skills unless automation is separately requested/scoped.
- Other needs or an explicitly named skill: use native skill discovery or search the [full catalog](../../.github/skills/README.md) for that need, then read the selected SKILL.md and necessary references. Do not load all listed packages. v1 design is for explicit compatibility; Anthropic brand guidance applies only when that branding is requested.

The installed packages/catalog remain intact. Ordinary graph lookup uses the concise procedure above; load the full imported Graphify skill only for explicitly scoped graph maintenance or a lookup detail not covered there. Its scripts never authorize installs, graph writes, automated application actions or database changes.

Maintenance only: [requirement map](requirement-map.md), [size baseline](context-baseline.json), [usage comparison](context-benchmark.md). Do not routinely read these during application tasks.
