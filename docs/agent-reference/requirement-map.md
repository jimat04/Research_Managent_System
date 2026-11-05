# Instruction requirement map

This map was prepared before the context cleanup on 2026-10-24. It maps the current worktree's requirements, not an older Git revision. Exact original bytes and hashes were captured before editing; sizes are in [context-baseline.json](context-baseline.json). This is maintenance/audit material, not routine task context.

| Original requirement group | Retained owner after cleanup |
|---|---|
| AGENTS: project map, focused exploration, preserve unrelated work | Root AGENTS: project map and working method |
| AGENTS: nine specialists, simple-question exception, coordinator for cross-scope work, direct-read fallback | Root AGENTS: routing; reference index; workflow coordinator |
| AGENTS/Copilot/CLAUDE: relevant skills only, read selected instructions, one visual direction, tool availability, stack/identity precedence | Root AGENTS: routing; reference index; skills README for exceptional capabilities |
| AGENTS/CLAUDE/Copilot/specialists: Graphify on every verification and relationship trace, actual vocabulary, source confirmation, fallback, repeat on dependency changes, no installs/rebuilds | Root AGENTS: verification triggers; graph-lookup reference; manual guide section 2 |
| AGENTS/CLAUDE/Copilot/specialists: automatic manual verification, one user browser action at a time, question/wait, no substitute requests or bypasses | Root AGENTS: verification; unchanged manual guide sections 1 and 3-6 |
| AGENTS: separate static/user results, pending/blocked checks, failures before fix decisions, preserved failures/retests, no secret requests | Root AGENTS: verification; manual guide |
| AGENTS: vanilla stack, SITE_URL, __DIR__, PHP syntax checks; no general test command | Root AGENTS: safety and working method; coding reference |
| AGENTS: canonical shared title/chapter flow, draft edits/submission locks, authorized admin/staff reviews, no Reviewer role/broad staff grant, revision comments/history | Root AGENTS: safety; workflow-security reference |
| AGENTS: backend auth/action permissions/ownership/assignment/locks, server validation, prepared SQL, escaping, CSRF, existing uploads/downloads, sensitive direct-route checks | Root AGENTS: safety/verification; workflow-security reference; manual guide |
| AGENTS: inspect schema/migrations/runner, local rms_db target, no silent production changes, additive changes, preserve IDs/FKs/history/credentials, backups, runner/schema synchronization and verification | Root AGENTS: safety; database specialist |
| AGENTS: migration 010 needs-verifier, current target/ledger and reconciliation inspection before authorized use, no blind rerun/account overwrite | Root AGENTS: safety |
| AGENTS: persistent connected features, existing components, validation and empty/success/error states, no fake live records | Root AGENTS: safety; coding reference |
| AGENTS: substantial-work planning, behavior/persistence/invalid/access checks, affected admin routes among 15 subpages, current reports over historical baselines | Root AGENTS: working method/verification; manual guide |
| AGENTS: disposable records, user cleanup only, no reset; explicit automation scope and verified isolated disposable target, never rms_db, no database creation/modification without approval | Root AGENTS: verification; manual guide and testing specialist |
| AGENTS: final diff, duplication/permission/data implications, reuse/database effects, actual results and limitations | Root AGENTS: working method |
| CLAUDE: project overview, stack versions, setup commands, existing demo account documentation, troubleshooting, configuration variable names | Setup reference (moved, not revalidated credentials) |
| CLAUDE: layout, additional files, request flow, v3 modules, shared pages/shells, security model and auth helpers | Architecture reference |
| CLAUDE: workflow eligibility/history, sequential chapters, final manuscript approval required for completion, no legacy stages/status inventions, role focus | Workflow-security reference; root safety summary |
| CLAUDE: include/escaping/CSRF/role/query/audit conventions, filenames, procedural style, progressive enhancement, visual QA, context management | Coding reference; root working method |
| CLAUDE: required EARIST identity, stack exclusions, exact role/brand/status colors, emoji, typography/spacing, shells/nav/cards/tables/buttons/forms, responsive/motion, component reuse and design goal | UI reference (all detailed design sections retained) |
| CLAUDE: password policy/hashing, soft-delete filters, handleRmsUpload, protected routes, .htaccess | Workflow-security reference |
| Copilot: supported locations/settings, conditional clients/plugins, no invented tools/agents/results or implicit installation/paid API/publishing/database permissions | Compact Copilot entry; root routing; Copilot workflow setup guide |
| Workflow coordinator: one accountable primary owner, multiple bounded subagents when useful, sequential dependencies/disjoint edits, integration and fallback | Workflow coordinator; root working method; task reference index |
| All specialists: shared safety, source discovery, Graphify/manual verification and reporting | Root AGENTS plus mandatory reference index routing; specialist-specific controls retained in each definition |
| Security specialist: full replaced block in every answer | Replaced by concise file/diff references plus security rationale; full blocks on request or when needed for review (authorized output-format change) |
| Manual guide: all verification behavior and report status definitions | Guide retained; only Graphify entry link changes to the concise read-only lookup reference |
| Imported skill bodies/resources, snapshot catalog/hashes, editor models/settings, application/database behavior | Unchanged |

Do not infer that relocating a rule makes it optional. The root guide requires reading the relevant references before affected work. Common rules have one owner; clients that do not automatically load AGENTS must read it explicitly.
