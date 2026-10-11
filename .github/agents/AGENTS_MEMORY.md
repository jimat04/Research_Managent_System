# RMS Agents Memory

Saved: 2026-08-27

This file documents all agents defined in `.github/agents/` for the **Research Management System (RMS)** project.

---

## 1. `rms-db.agent.md` — RMS Database Agent

**Description:** Owns the `rms_db` MySQL schema: migrations, indexes, seed data, and prepared-statement queries. Ensures InnoDB + utf8mb4, consistent PK naming, soft deletes, and sync between `rms_db.sql` and the working database.

**Tools:** read, search, edit, execute, todo

**Responsibilities:**
- Write migrations (ALTER TABLE / CREATE TABLE snippets)
- Write PHP queries using procedural MySQLi with prepared statements
- Manage seed data (demo accounts, realistic Filipino names, plausible research titles)
- Handle pagination, filter clauses, dashboard KPI queries, reporting queries, covering indexes

**Key Schema conventions:**
- Engine: InnoDB, utf8mb4_unicode_ci
- PKs: `<table_singular>_id` INT UNSIGNED AUTO_INCREMENT
- Timestamps: `created_at`, `updated_at`
- Soft deletes: `deleted_at DATETIME NULL`
- Statuses: ENUM for closed sets, lookup tables for extendable sets

**Existing tables:** `users`, `research_projects`, `chapters`, `chapter_content`, `comments`, `uploads`, `notifications`, `defense_schedule`, `activity_log`, `departments`, `programs`, `academic_years`, `research_categories`

---

## 2. `rms-debug.agent.md` — RMS Debug / Troubleshooting Agent

**Description:** Troubleshoots RMS running on XAMPP: blank pages, DB errors, session/login loops, upload failures, 404s, and role-bypass bugs. Uses a one-hypothesis-at-a-time approach.

**Tools:** read, search, edit, execute, todo

**Responsibilities:**
- Diagnose and fix issues in the RMS app running on XAMPP (Apache + MySQL, PHP 7.4+)
- Work one hypothesis at a time — never flood with guesses
- Handle: blank/white pages, DB errors, login/session loops, file upload failures, 404s, CSS/JS not loading, role tab issues, prepared statement warnings

**Common playbooks:**
1. White/blank page → enable errors, check Apache logs, verify include paths
2. DB errors → check MySQL running, config.php credentials, rms_db existence
3. Login/session loop → session save path, SESSION_TIMEOUT, role string matching
4. Upload failures → enctype, UPLOAD_DIR writable, php.ini settings
5. 404 → relative path consistency, filename typos, DocumentRoot
6. CSS/JS not loading → DevTools Network tab, relative path depth
7. Role tab issues → hidden input, JS click handler, POST body
8. Prepared statement warnings → count `?` vs bind_param types/args

---

## 3. `rms-doc.agent.md` — RMS Documentation Agent

**Description:** Keeps RMS setup, feature, user, developer, and inline documentation accurate with surgical edits and demo credentials synchronized to the schema.

**Tools:** read, search, edit, execute, todo

**Files owned:**
- `README.md` (emoji-headed sections)
- `TODO.md` (## grouped headers, `- [ ]` / `- [x]` items)
- Role-specific user guides and developer setup, architecture, contribution, and code-convention docs
- API endpoint documentation only when an API is implemented
- Inline code comments in `includes/*.php` and page-level PHP files
- (Future) `CHANGELOG.md`

**Rules:**
- Surgical edits only — never rewrite whole docs from scratch
- Tick TODO items only when fully complete (code + smoke-tested + docs updated)
- PHPDoc blocks above every new helper function
- 3–5 line block comment at top of every new page file
- Keep demo credentials in sync with `rms_db.sql` seed
- Document test/maintenance prerequisites and variable names without recording secret values

---

## 4. `rms-page-builder.agent.md` — RMS Page Builder

**Description:** Builds new role-specific PHP pages for RMS (Student / Faculty / Admin) that match the existing dashboard shell, CSS conventions, and prepared-statement database access.

**Tools:** read, search, edit, execute, todo

**Responsibilities:**
- Build new pages for all three roles (Student, Faculty, Admin)
- Follow procedural PHP 7.4+, no frameworks/ORM/Composer
- Reuse existing dashboard shell (`.layout`, `.sidebar`, `.main-content`, `.topbar`, `.content-area`)
- Always use prepared statements, `requireRole()`, `htmlspecialchars()` output escaping
- Validate uploads (extension whitelist, `uniqid()` prefixes)

**Pages scope:**
- Student: `my-research.php`, `submit-research.php`, `my-documents.php`, `progress-tracking.php`, `messages.php`, `notifications.php`, `profile.php`, `settings.php`, `calendar.php`
- Faculty: `faculty-dashboard.php`; shared title and chapter workflow lives under `pages/researcher/`.
- Admin: `admin-users.php`, `admin-research.php`, `admin-archive.php`, `admin-reports.php`, `admin-logs.php`, `admin-backup.php`, `admin-settings.php`

**Deliverables per page:** Full PHP file, SQL ALTER/INSERT, sidebar snippet for other dashboards, 5-step smoke-test checklist.

---

## 5. `rms-security-auth.agent.md` — RMS Security & Auth

**Description:** Audit login/register flows and SQL for injection, XSS, CSRF, session fixation, rate limiting, and role bypasses; implement secure procedural fixes.

**Tools:** read, search, edit, execute, todo

**User-invocable:** Yes  
**Argument hint:** "Audit or fix an RMS authentication, authorization, form, or SQL security issue"

**Responsibilities:**
- Audit and repair security-sensitive code (login.php, registration, forms, SQL, sessions, role-protected pages)
- Security checks: SQL injection, XSS (reflected/stored), CSRF, session fixation/hijacking, account enumeration, weak passwords, rate limiting, open redirects, file-upload abuse, authorization/role bypasses
- Add CSRF tokens to forms with server-side validation
- Propose `.htaccess` hardening and file-permission checks
- Never introduce frameworks, ORM, Composer packages

**Workflow:** Read → state one falsifiable hypothesis → cheapest check → smallest root-cause fix → validation → audit control-flow branches

---

## 6. `rms-ui.agent.md` — RMS UI / UX Agent

**Description:** Owns the visual language of RMS: extends `css/style.css`, builds new UI components (timelines, modals, calendars, chat, charts), enforces the purple/navy palette, and keeps the app responsive and accessible.

**Tools:** read, search, edit, execute, todo

**Responsibilities:**
- Extend `css/style.css` (no second stylesheets, no Tailwind/Bootstrap/jQuery/React)
- Build new UI components using existing CSS variable system
- Keep responsive breakpoints (desktop ≥1024px, tablet ≤768px, mobile ≤480px)
- Write vanilla JS only (no jQuery) for interactive components in `js/main.js`
- Enforce accessibility (aria-label, role="dialog", ESC-to-close, focus outlines, 4.5:1 contrast)

**CSS Variables (do not use hex directly):**
- `--primary: #5B1EBC` (purple)
- `--secondary: #0F6CBD` (blue)
- `--accent: #F57C00` (orange)
- `--success: #22c55e`, `--warning: #f59e0b`, `--danger: #ef4444`
- `--dark: #0A0833` (navy sidebar)
- `--light: #F8F9FE`, `--text-dark: #0f172a`, `--text-light: #64748b`, `--border: #e2e8f0`

**Existing components to reuse:** `.sidebar`, `.topbar`, `.stat-card`, `.data-table`, `.btn`, `.form-group`, `.badge`, `.card`

**Common new components:** Chapter progress timeline, multi-step form stepper, file-drop zone, modal dialog, deadline calendar, chat/messaging UI, notification dropdown, star/score rating, CSS bar/line chart placeholders, empty-state illustrations, toast/alert feedback.

---

## 7. `rms-testing.agent.md` — RMS Testing Agent

**Description:** Owns automated browser, integration, security, and unit-test work for RMS; distinguish configured suites from proposed coverage.

**Current test capability:** `tests/e2e-browser/` contains a Playwright suite run with `npm test` from that directory (or `npm --prefix tests/e2e-browser test` from the repository root). It requires the local app/database and credentials supplied through `RMS_E2E_*` environment variables. The PHP project does not currently have a configured PHPUnit suite or CI test workflow.

**Responsibilities:** Add focused tests, use isolated/disposable data, preserve secrets outside tracked files, and report exactly which suites ran and what remained unverified.

---

## 8. `rms-performance.agent.md` — RMS Performance Agent

**Description:** Measures and improves PHP, MySQL, and browser performance with reproducible evidence and narrowly scoped changes.

**Responsibilities:** Establish before/after baselines, inspect query plans, identify unbounded/repeated work, coordinate indexes with `rms-db`, and avoid speculative caching, arbitrary targets, or production load testing.

---

## 9. `rms-backup.agent.md` — RMS Backup and Recovery Agent

**Description:** Plans and verifies secure RMS database and upload backups, retention, and recovery procedures.

**Responsibilities:** Keep secrets out of source and logs, protect ignored backup artifacts, require explicit authorization for destructive production operations, and distinguish a successful dump from a restore that has actually been rehearsed.

---

## Agent Cross-References

| Agent | Interacts With |
|-------|---------------|
| `rms-db` | `rms-page-builder` (query patterns), `rms-security-auth` (CSRF upstream) |
| `rms-debug` | `rms-security-auth` (security bugs escalated there) |
| `rms-doc` | `rms-page-builder` (page docs), `rms-security-auth` (CSRF docs), `rms-db` (query patterns), `rms-testing` (test instructions) |
| `rms-page-builder` | `rms-db` (schema), `rms-ui` (new CSS classes), `rms-security-auth` (CSRF tokens) |
| `rms-security-auth` | All agents (security concerns escalated to it) |
| `rms-ui` | `rms-page-builder` (component requests), `rms-security-auth` (form token styling) |
| `rms-testing` | `rms-page-builder` (workflow tests), `rms-db` (isolated test data), `rms-security-auth` (security coverage) |
| `rms-performance` | `rms-db` (query plans/indexes), `rms-page-builder` (page behavior), `rms-testing` (regression checks) |
| `rms-backup` | `rms-db` (schema/data consistency), `rms-debug` (recovery diagnosis) |

---

## Local Claude Skills

Checked 2026-10-10. Skill definitions are stored under `C:\Users\jimwe\.claude\skills\`; each skill's `SKILL.md` contains its full instructions. There are no Claude agent definitions in `C:\Users\jimwe\.claude\agents\` or project `.claude/agents/`. The RMS-specific agents above are defined as GitHub Copilot agents in `.github/agents/`.

### Design and UI

`banner-design`, `brand`, `brand-guidelines`, `brandkit`, `canvas-design`, `design`, `design-system`, `design-taste-frontend`, `design-taste-frontend-v1`, `frontend-design`, `gpt-taste`, `high-end-visual-design`, `imagegen-frontend-mobile`, `imagegen-frontend-web`, `image-to-code`, `industrial-brutalist-ui`, `minimalist-ui`, `redesign-existing-projects`, `stitch-design-taste`, `ui-styling`, `ui-ux-pro-max`.

### Other capabilities

`find-skills` (discover/install skills), `full-output-enforcement` (complete code output), `slides` (slide creation), `webapp-testing` (web app testing), `web-artifacts-builder` (web artifacts).

When a request matches a skill, read and follow that skill's `SKILL.md`; this index is a local discovery aid, not a replacement for the skill instructions.
