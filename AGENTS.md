# RMS Agent Guide

Read this guide once per session and follow it for every task. Before affected work, read the relevant references in the [task index](docs/agent-reference/README.md); relocated rules remain mandatory. Expand context when evidence requires it.

## Project map

- `public/`: public entry pages; `pages/researcher/`: canonical student/faculty submissions.
- `pages/{student,faculty,admin,staff}/`, `pages/shared/`: role/shared authenticated pages.
- `includes/auth.php`: authentication, permissions, CSRF; `includes/research-workflow.php`: workflow.
- `includes/{admin,staff,faculty,student}-shell.php`: navigation/shells; `css/style.css`, `css/*-shell.css`: styling.
- `database/schema/rms_db.sql`, `database/migrations/`, `scripts/migrate.php`: schema, migrations, runner.

## Routing and context

Use one primary specialist from `.github/agents/` for implementation; answer simple questions directly. Use `rms-workflow` only across scopes. If invocation is unavailable, read the selected definition and work locally; accurately report that fallback.

| Task | Specialist |
|---|---|
| Schema, migrations, seed data, SQL | `rms-db` |
| Authentication, authorization, security | `rms-security-auth` |
| Runtime errors, XAMPP | `rms-debug` |
| New/substantially changed PHP page | `rms-page-builder` |
| UI, CSS, accessibility, responsive behavior | `rms-ui` |
| Manual verification or requested automated tests | `rms-testing` |
| Measured PHP/database/browser performance | `rms-performance` |
| Backup, restore, retention | `rms-backup` |
| Setup, feature, developer documentation | `rms-doc` |

Select relevant skills through the task index; read the selected `SKILL.md` and only needed references. Use one primary visual skill; v1 design guidance is for explicit compatibility. The full `.github/skills/README.md` catalog is for discovery when the index does not fit. RMS stack, identity and safety override generic examples. Check real tool/plugin availability; discovery does not authorize installs, paid APIs, exports, publishing or database changes. Never invent agent invocations, assets or results. Setup/refresh: [Copilot workflow](docs/COPILOT_WORKFLOW.md).

## Working method

- For clear, bounded requests, carry the work through focused investigation, implementation, and the narrowest relevant check without pausing for an unnecessary approval loop. Ask only when ambiguity materially affects scope, behavior, or safe execution; otherwise follow repository conventions, state a low-risk assumption, and proceed. Get required authorization before destructive, production-data, external, or otherwise consequential actions.
- Keep the path proportionate: answer simple questions directly; for clear changes, inspect only the context needed, complete the requested outcome, and run the narrowest required check. Avoid routine progress narration, repeated searches, unrelated scope, and unnecessary delegation. Do not skip required safety or verification. Report what changed, what was actually checked, and any remaining limitation.
- Start with the named file, symbol or behavior, then its nearest helper, caller and relevant test/report. Use focused search and source ranges; avoid repository inventories and whole vendor/log/graph dumps. Broaden when needed for correctness.
- Reuse unchanged findings within a session; recheck after relevant edits, changed dependencies or contradictory evidence. Batch independent reads and keep output relevant; expand incomplete/truncated results as needed.
- For substantial multi-file, workflow, permission or database work, identify current behavior, affected files/data, reusable helpers, risks, steps and acceptance checks first. Keep small fixes proportionate. State one falsifiable hypothesis and cheapest check before edits that diagnose/fix behavior.
- Preserve unrelated worktree changes. Follow procedural PHP/MySQLi/HTML/CSS/JS conventions, `__DIR__` includes and `SITE_URL` cross-page links. No frameworks, ORMs, UI/icon libraries or incidental dependencies. Read the coding/UI references when applicable.
- Delegate only bounded tasks adding distinct expertise; sequence dependencies, separate parallel edit ownership, and integrate/review results. Count delegated usage in comparisons; delegation does not inherently save tokens.
- Keep related work in one session, unrelated work in fresh sessions. Before context exhaustion or a handoff, summarize the objective, affected files, decisions, completed checks and pending work; retain failure/security details.
- Review the final diff for unintended changes, duplication and permission/data implications. Summarize changes, reuse, database effects, actual checks and limitations. Use file/diff references; full code blocks only on request or when necessary for review. Never claim an unrun check passed.

## Safety invariants

- Enforce authentication, action permissions, ownership/assignment and submission locks in backend handlers. Validate server-side, prepare user-influenced SQL, escape rendered values, validate CSRF for state changes and reuse upload/download safeguards.
- Students/faculty share `pages/researcher/`: drafts editable, submitted versions locked, chapters only after title approval. Preserve sequential reviews, implemented statuses, comments/recommendations, revision/resubmission and version history. Only approved final manuscripts complete research. Review/approval belongs to administrators or staff explicitly authorized through Users permissions; no Reviewer role, broad staff grants or generic manual stages. Read workflow/security details before affected work.
- Before database work inspect the canonical schema, relevant migrations and runner; confirm the intended local target is `rms_db`, never silently production. Prefer additive migrations; preserve IDs, FKs, history and credentials. Back up affected data before significant changes, use the runner, synchronize the canonical schema and verify results.
- `010_demo_accounts_resync.sql` remains `needs-verifier`: before any authorized decision to run it, inspect the current target and migration ledger, read `docs/MIGRATION_003_010_RECONCILIATION.md`, and verify credential effects. Never blindly rerun it or overwrite accounts.
- Build connected, persistent behavior using existing handlers/components, validation and empty/success/error states. Never present sample records as live data. Never expose secrets in chat, logs or reports.

## Verification (automatic)

Whenever testing/checking/confirmation/validation is requested, or a feature, fix, workflow, permission or UI change needs verification, follow the [manual guide](docs/testing/MANUAL_TESTING_GUIDE.md). No special prompt or testing-agent selection is needed.

- Consult the existing Graphify map at the start of **every verification task**, including focused function checks, and for architecture/relationship traces. Follow the [read-only lookup procedure](docs/agent-reference/graph-lookup.md), confirm graph hints against current source, repeat when tested behavior/dependencies change, and record missing/stale/truncated coverage. Fall back to direct nodes/edges or targeted source if unavailable; never automatically install/rebuild/update. Skip for unrelated/simple tasks needing neither verification nor tracing.
- The user performs **every application interaction** in the integrated browser: navigation, login/logout, account switching, inputs/selections, clicks, uploads/downloads and record changes. Give one step with role, page, expected outcome and a specific question; wait for the observation. Never request credentials/tokens or substitute scripts, direct API requests, database edits, fabricated sessions, seeded states or test hooks; never bypass safeguards.
- Assistant work includes source review and focused non-mutating checks; run `php -l` on changed PHP. There is no general PHP test/lint/build command, PHPUnit suite, test CI workflow or maintained browser E2E suite. Verify behavior, persistence, invalid input and access boundaries; include direct-route/unauthorized checks for sensitive records/documents. Check affected routes among the 15 admin subpages for admin/navigation/permission changes.
- Use current tests/reports, not historical passes. Save/update `docs/testing/manual-reports/YYYY-MM-DD-workflow-NN.md` using Asia/Taipei dates. Separate assistant/static results from user observations; retain Pending/Passed/Failed/Blocked/Skipped status and reasons. Record failures, ask fix-now versus continue-independent-checks before fixing findings, preserve failures and retest authorized fixes. Missing prerequisites are blocked, not bypassed.
- Use disposable records; user cleanup through supported actions is limited to session-created records. No automatic cleanup/reset. Separately requested automation needs agreed scope and a verified isolated disposable target, never normal `rms_db`; a name alone proves no isolation. If isolation is absent/unverified, do not run mutating automation or create/modify a database without explicit approval. Automation never authorizes bypassing safeguards.
