# RMS Agent Guide

Use this file as the quick project map. Start with the file, symbol, or behavior named in the task; inspect its nearest helper, caller, and relevant test. Search by the specific symbol or behavior, and broaden exploration only when that path does not explain the change. Do not inventory or read the whole repository by default.

## Project Map

- `public/`: public entry pages and request entry point.
- `pages/researcher/`: canonical title and chapter submission workflow.
- `pages/{student,faculty,admin,staff}/` and `pages/shared/`: role-specific and shared authenticated pages.
- `includes/auth.php`: authentication, authorization, CSRF, and shared security helpers.
- `includes/research-workflow.php`: title/chapter workflow helpers.
- `includes/{admin,staff,faculty,student}-shell.php`: role navigation and page shells.
- `css/style.css` and `css/*-shell.css`: shared and role-specific styling.
- `database/schema/rms_db.sql` and `database/migrations/`: database schema and incremental changes.
- `tests/e2e-browser/`: browser end-to-end tests.

## Agent and Skill Routing

For each new RMS task, select the most specific available agent below and use it when the AI client supports project agents. Prefer one primary agent; add another only when the task genuinely crosses scopes. Agent definitions live in `.github/agents/`.

| Task | Primary agent |
|---|---|
| Database schema, migrations, seed data, or SQL queries | `rms-db` |
| Authentication, authorization, or security | `rms-security-auth` |
| Runtime errors or XAMPP troubleshooting | `rms-debug` |
| New or substantially changed role-specific PHP page | `rms-page-builder` |
| UI, styling, accessibility, or responsive behavior | `rms-ui` |
| Automated tests or test infrastructure | `rms-testing` |
| Measured PHP, database, or browser performance | `rms-performance` |
| Backup, restore, or retention procedures | `rms-backup` |
| Setup, feature, or developer documentation | `rms-doc` |

Choose the closest relevant installed skill when the AI client supports skills, and read its instructions before applying them. Agent and skill availability differs between AI clients: use only what is actually exposed, do not claim an unavailable agent or skill was used, and continue with the closest available guidance when there is no match. For a simple question or explanation, answer directly without an unnecessary agent handoff.

## Working Rules

- Use vanilla PHP, MySQLi, HTML, CSS, and JavaScript; do not add frameworks or UI libraries.
- Follow the task's owning module and nearby conventions. Read `CLAUDE.md` for detailed architecture, security, workflow, and UI requirements when relevant; do not read it all by default.
- Use prepared statements for user-influenced SQL, escape rendered values, enforce role access, and include CSRF validation on state-changing forms.
- Use `SITE_URL` for cross-page links and `__DIR__` for PHP includes.
- Preserve unrelated worktree changes. Do not revert or overwrite changes outside the requested task.
- Prefer a focused check for the changed behavior. Browser tests run with `npm test` from `tests/e2e-browser/` and require the `RMS_E2E_*` environment variables documented in `CLAUDE.md`. There is no configured general PHP test or lint command.

## Workflow and Security Invariants

- Students and faculty use the same canonical title-and-chapter workflow in `pages/researcher/`. Draft titles and chapters are editable; submitted versions are locked. Chapters start only after title approval.
- Administrators and staff explicitly authorized for the relevant action handle review and approval. Use the existing permissions managed through the Users page; do not add a Reviewer role or grant staff broad access by default.
- Returned or rejected submissions must retain reviewer comments/recommendations and support the existing revision and resubmission flow. Preserve current status transitions and version history; do not substitute generic research-manual stages.
- Enforce authentication, action permissions, ownership/assignment, and submission locks in backend handlers—not only in page controls. Validate inputs server-side, use prepared statements, escape output, apply CSRF checks to state-changing requests, and reuse the existing upload/download safeguards.
- For pages that handle sensitive records or documents, verify direct-route access and unauthorized or invalid requests as well as the expected UI path.

## Database and Feature Safety

- Before a database change, inspect the canonical schema, relevant migrations, and migration-runner behavior. Confirm that any intended target is the local development database `rms_db`; never silently alter production data.
- Prefer additive migrations and preserve IDs, foreign keys, history, and credentials. Back up affected data before significant changes, use the migration runner, update the canonical schema when needed, and verify the resulting schema/data.
- The repository reconciliation documents `010_demo_accounts_resync.sql` as `needs-verifier` because its administrator credential effects were not reconciled at that review. Before any authorized decision to run it, inspect the current target database and migration ledger, read `docs/MIGRATION_003_010_RECONCILIATION.md`, and verify the effects; never blindly rerun it or overwrite existing accounts.
- Implement connected, persistent behavior rather than demonstration-only interfaces. Reuse existing handlers and shared components; include validation and clear empty, success, and error states. Do not present hardcoded sample records as live data.

## Planning and Verification

- For substantial multi-file, workflow, permission, or database work, first identify current behavior, affected files/data, reusable helpers, risks, implementation steps, and acceptance tests. For small fixes, keep planning proportionate.
- Verify the requested behavior, not just page loading: run PHP syntax checks on changed PHP files and the narrowest relevant automated tests. Extend Playwright coverage for new interactions, and exercise persistence, invalid input, and unauthorized access where applicable.
- Inspect current tests and reports before relying on a historical baseline. For admin module, navigation, or permission changes, check regressions on the affected routes among the 15 administrative subpages.
- Use disposable test records and clean up only records created for the test. Run the full browser suite only when its local application, database, and credentials are available; state clearly which checks passed, failed, or could not be run.
- Review the final diff for unintended changes, permission/data implications, and duplication. Report what changed, what existing functionality was reused, database effects, actual test results, and remaining limitations. Never describe a check as passing unless it was run.
