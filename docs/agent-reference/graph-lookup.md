# Read-only Graphify lookup

This is the RMS fast path for the existing graph. It preserves the lookup controls from the imported [Graphify skill](../../.github/skills/graphify/SKILL.md) and [query reference](../../.github/skills/graphify/references/query.md), without loading graph-build instructions. Root [AGENTS.md](../../AGENTS.md) defines mandatory triggers. Verification also follows the [manual guide](../testing/MANUAL_TESTING_GUIDE.md).

1. Check `graphify-out/graph.json` and the query command are available. Use the existing map only; do not install dependencies, rebuild/update, start watchers, save graph feedback or write vocabulary/cache files. Existing lessons may be read when useful.
2. Inspect node labels in memory for vocabulary matching the actual behavior. Use case-folded tokens of 3-30 letters, including CamelCase parts. Select at most 12 actual vocabulary tokens; skip unmatched concepts. State the selected terms before traversal. With no relevant terms, record the limitation and inspect current source instead of inventing a graph search.
3. Query with a focused budget, normally 1500 tokens. Example for the actual `copilot` vocabulary token:

   ```powershell
   graphify query "copilot" --budget 1500
   ```

   Use BFS for nearby relationships, `--dfs` for a chain, or a supported path lookup for a specific connection. If truncated, narrow the query to the relevant symbol first; expand the budget when needed for coverage. A small budget is not a correctness limit.
4. If the CLI fails/is unavailable, read only relevant JSON nodes and their adjacent edges, retaining labels, source files/locations and confidence. Inspect JSON keys first if its format is unfamiliar; no new dependency is required. Never dump the whole map into chat. Missing graph/context is a recorded limitation, not permission to install/rebuild or bypass verification.
5. Confirm findings against current pages, callers, handlers, permissions, helpers and data dependencies. Quote useful `source_location` values. Inferred edges are hints; absent edges are unknown. Current source wins, including when old test nodes reference a removed suite.
6. For verification, record the question, chosen terms, query/direct lookup, relevant source locations, confirmed relationships and missing/stale/truncated context in the session report. Repeat when the tested behavior changes or an unexpected dependency appears, not for every browser click. Never convert browser observations into graph relationships automatically.

For command details beyond this fast path, read only the relevant section of the imported query reference. Full extraction, maintenance and export workflows require explicit scope and the full imported skill. Its graph-writing examples do not apply to routine RMS lookup.
