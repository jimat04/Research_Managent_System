# RMS Agent Index

Reviewed: 2026-10-13

This is a routing/reference index, not a substitute for the agent definitions. `AGENTS.md` is the project map and routing source of truth; each `.agent.md` file owns the detailed guidance for its specialist.

The `rms-workflow.agent.md` coordinator routes Copilot/local implementation tasks to the nine specialists below. Installed shared skills and their task index live in [`.github/skills/README.md`](../skills/README.md); setup, refresh, and local fallback are documented in [`docs/COPILOT_WORKFLOW.md`](../../docs/COPILOT_WORKFLOW.md).

## Current Specialists

| Agent | Owns |
|---|---|
| `rms-db.agent.md` | MySQL/MariaDB schema, migrations, seed data, and prepared queries. Canonical schema: `database/schema/rms_db.sql`. |
| `rms-debug.agent.md` | Runtime diagnosis for PHP/MySQL/XAMPP, auth redirects, uploads, paths, and missing assets. |
| `rms-doc.agent.md` | Setup, feature, user, developer, and test documentation. |
| `rms-page-builder.agent.md` | Student/faculty/staff/admin and shared PHP pages using the current shells and workflow. |
| `rms-security-auth.agent.md` | Authentication, authorization, CSRF, session, SQL, XSS, and upload security. |
| `rms-ui.agent.md` | Responsive/accessibility styling for role shells and public light/dark pages. |
| `rms-testing.agent.md` | User-operated manual verification and separately scoped testing work. |
| `rms-performance.agent.md` | Measured PHP, database, and browser performance. |
| `rms-backup.agent.md` | Secure database/upload backup, retention, and recovery planning. |

## Shared Project Facts

- Stack: procedural PHP, MySQLi, HTML, CSS, and vanilla JavaScript; do not add frameworks or UI libraries.
- Public pages live in `public/`; authenticated pages live in `pages/{student,faculty,admin,staff}/` and `pages/shared/`.
- Canonical title/chapter workflow pages live in `pages/researcher/`, with helpers in `includes/research-workflow.php`.
- Active shells are in `includes/{admin,staff,faculty,student}-shell.php`; role stylesheets are in `css/`.
- Auth/CSRF helpers are in `includes/auth.php`; upload handling is in `includes/file-uploader.php`.
- Use `SITE_URL` for cross-page links, `__DIR__` for PHP includes, prepared statements for input-influenced SQL, and output escaping for rendered values.
- Preserve the v3 workflow: versioned title submission and typed review, sequential Chapters 1–5, computed completion, and tracked outputs. Do not restore legacy manual completion or committee-stage workflows.

## Test Capability

- Current verification follows the [user-operated manual testing guide](../../docs/testing/MANUAL_TESTING_GUIDE.md); there is no maintained browser E2E suite.
- Automation requires a separately agreed scope and a verified isolated target; do not assume a harness or test README exists.
- There is no configured PHPUnit suite, general PHP lint/build command, or test CI workflow. Do not claim otherwise or add test dependencies without an agreed task scope.

## Collaboration Boundaries

| Concern | Coordinate with |
|---|---|
| Page SQL or schema | `rms-db` |
| Page structure or workflow | `rms-page-builder` |
| Shared styling or accessibility | `rms-ui` |
| Authentication or security defect | `rms-security-auth` |
| Test coverage or test harness | `rms-testing` |
| User-facing or developer documentation | `rms-doc` |
| Slow query or measured latency | `rms-performance` and `rms-db` |
| Restore or retention plan | `rms-backup` and `rms-db` |

Keep collaboration narrow: one primary specialist per task, adding another only when the work crosses ownership boundaries.
