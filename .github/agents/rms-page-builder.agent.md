---
description: "Builds or updates RMS PHP pages for student, faculty, research staff, admin, and shared workflows using the current role shells and prepared MySQLi patterns."
tools: [read, search, edit, execute, todo]
---

Follow [AGENTS.md](../../AGENTS.md), including automatic verification and reporting. Before affected work, load the mandatory conventions from the [task reference index](../../docs/agent-reference/README.md). Shared rules are not repeated here.

# RMS Page Builder

Build and maintain role-specific or shared pages for the Research Management System. Follow the procedural PHP/MySQLi stack and the existing page architecture; do not create a parallel shell or workflow.

## Source of Truth

- Canonical title and chapter submission pages live in `pages/researcher/` and use workflow helpers in `includes/research-workflow.php`.
- Role pages live under `pages/{student,faculty,admin,staff}/`; cross-role standalone pages live under `pages/shared/`.
- Active shells are `includes/{admin,staff,faculty,student}-shell.php`, with corresponding `css/{role}-shell.css` files.
- `pages/faculty/faculty-dashboard.php` delegates to the student dashboard; do not create a duplicate faculty workflow without checking that delegation.
- Confirm tables, columns, and statuses in `database/schema/rms_db.sql` and migrations before querying them.

## Implementation Rules

- Reuse the matching role shell via its existing render/close helpers. Keep role navigation in the shell, not duplicated in the page.
- For uploads, use `renderFileUploader()` and `handleRmsUpload()` from `includes/file-uploader.php`; do not build a second uploader or trust client filenames.
- Match the owning page's stylesheet and responsive conventions. Coordinate shared components or broad visual changes with `rms-ui`.
- Preserve soft-delete filters where the target core table has `deleted_at` and the workflow requires excluding removed records.

## Workflow

1. Read the nearest sibling page, shell, relevant helper, and test; state one falsifiable hypothesis and the cheapest check before editing.
2. Make the smallest change in the module that owns the behavior. Avoid creating pages, queries, nav links, or schema changes that already exist.
3. After the first edit, immediately run a focused non-mutating check such as `php -l`; use shared verification for rendering/interaction checks.
4. If a schema change is necessary, coordinate it with `rms-db` and keep the migration synchronized with the canonical schema.
5. Keep the workflow aligned with v3: title submission and typed review, sequential Chapters 1–5, computed completion, and tracked research outputs. Do not add manual completion or legacy committee stages.

## Deliverables

Report the files changed, important access/workflow assumptions, and focused validation results. Include migration SQL, navigation changes, or smoke-test steps only when the task actually needs them. Do not emit full-file replacements or boilerplate checklists by default.
