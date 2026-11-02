# RMS coding conventions

Read [AGENTS.md](../../AGENTS.md) first. Apply this reference when the [task index](README.md) selects it; paths in code spans are relative to the repository root. These conventions were relocated from CLAUDE.md without revalidating historical setup claims; current source wins.

## Important Conventions

- All includes use `__DIR__` for reliable relative paths (never hardcode paths)
- All user input is escaped before output with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` or a local escaping helper
- State-changing forms include `<?= csrfField() ?>` and validate with `isCsrfTokenValid()`
- Role-gating: admins always pass `requireRole()` checks (admins are added automatically to the allowed list)
- Database queries use prepared statements exclusively
- Activity logging via `logActivity()` for audit trail

## File Naming

| Pattern | Example | Purpose |
|---|---|---|
| `{role}-dashboard.php` | `student-dashboard.php` | Role entry page |
| `{role}-{feature}.php` | `faculty-dashboard.php` | Role-specific feature |
| `admin-{feature}.php` | `admin-users.php` | Admin-only pages |
| `pages/researcher/{name}.php` | `submit-research.php` | Canonical shared student/faculty workflow |
| `includes/{name}.php` | `includes/auth.php` | Core backend logic |
| `database/migrations/*.sql` | `014_add_training_activity_type.sql` | Schema changes |
| `scripts/{name}.{php,sh}` | `scripts/backup-database.sh` | Maintenance utilities |

## Code Generation Rules

- Follow existing folder structure (`pages/{role}/`, `includes/`, `css/`, `js/`, `public/`)
- Use `__DIR__` for includes (never relative paths that break)
- SITE_URL for cross-page links; relative paths are OK only within the same directory
- Match existing coding style (procedural PHP, brace placement, function naming)
- Do NOT introduce PHP frameworks, ORMs, CSS frameworks, JS libraries, or icon packs
- Pages must work without JS where possible (progressive enhancement)
- Generate production-ready code that passes visual QA: proper contrast, hover states, focus rings, responsive behavior, accessible labels

---

## Context Management
- Monitor your context usage. If a conversation or multi-step task becomes long (>150k tokens), proactively summarize past steps and keep your responses concise.
- Avoid reading massive vendor/log files completely; use targeted line ranges or search commands instead.
