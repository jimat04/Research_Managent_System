# RMS workflow and security

Read [AGENTS.md](../../AGENTS.md) first. Apply this reference when the [task index](README.md) selects it; paths in code spans are relative to the repository root. These conventions were relocated from CLAUDE.md without revalidating historical setup claims; current source wins.

## Workflow Reference

The researcher workflow is: eligible BSIT/BSCS students (or faculty) draft and submit a research title/proposal → an authorized administrator or staff reviewer approves it or returns it with comments and recommendations → submit Chapters 1–5 in sequence, with each chapter reviewed before the next unlocks → after all five are approved, submit the complete manuscript → an authorized reviewer approves it or returns it for revision. Only final-manuscript approval completes the research. Students outside BSIT/BSCS and projects with ineligible student co-researchers retain history access but cannot submit new workflow versions.

See `docs/reference/Research-Manual_2015.md` for the source manual; do not replace the live v3 workflow with its legacy stage names.

## Workflow (v3)

Eligible student (BSIT/BSCS) or faculty title submission → authorized admin/staff review with comments and recommendations → sequential Chapter 1–5 submission and review → complete-manuscript submission after all five chapters are approved → authorized final approval or revision → completion only after final-manuscript approval.

Five chapters are reviewed sequentially. They unlock the final-manuscript submission but do not complete the project; only authorized approval of that final version completes research. Do not reintroduce legacy committee stages into this flow.

Use the statuses and transitions implemented by the schema and workflow helpers. Do not invent or rename statuses without updating all affected code and badges.

The source manual is `docs/reference/Research-Manual_2015.md`.

---

## User Roles & Focus Areas

### 🎒 Student (purple)
My Research, Submit Proposal, Submit Chapter, My Documents, Progress Tracking, Messages

### 🎓 Faculty (blue)
Dashboard, My Research, Submit Research Title, Submit Chapters, assigned-project visibility (adviser focus — guidance and advisee tracking; formal title/chapter review and approval are performed by administrators or staff authorized for the relevant actions)

### 📋 Research Staff (teal)
Dashboard, typed title/chapter review, five-module access map, revision returns, research archive, document verification, contact messages

### 🔴 Admin (orange)
Dashboard, User Management, Research Management, Reports & Analytics, System Logs, Backup

---

## Security Rules (MUST)

- Use prepared statements (`$conn->prepare()`) for ALL SQL with user input — NEVER concatenate variables into SQL strings
- CSRF tokens on every POST form (`csrfField()` + `isCsrfTokenValid()`)
- `requireLogin()` / `requireRole()` at the top of every protected page
- Passwords: `password_hash()` / `password_verify()` (bcrypt). Min 8 chars, must contain letter + number, no spaces
- Soft deletes: filter `deleted_at IS NULL` on all queries against core tables (research_projects, chapters, users)
- Uploads: always use the `handleRmsUpload()` helper (folder whitelist enforced server-side)
- Escape output with `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` or the role-specific `se()` helper
- .htaccess protects `includes/`, `database/`, `uploads/` blocks PHP execution

---
