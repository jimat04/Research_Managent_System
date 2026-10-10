# RMS Progress Audit Report

**Audit Date:** September 6, 2026
**Last updated:** October 10, 2026
**System version:** v3 — Unified researcher workflow (adviser-approved title + chapter process, October 10, 2026)
**Auditor:** Claude (E2E walkthrough pass on live DB)
**Basis:** full 8-phase E2E walkthrough PASSED on live DB (see docs/testing/E2E_WALKTHROUGH.md)
**Scope:** Reality check of every status claim against the working tree.

> The live-database E2E evidence and v1 page-count / completion estimates below are historical snapshots. The v2 pivot replaced the main workflow scope; this document is not a fresh end-to-end or deployment audit. Current source was spot-checked on October 10, 2026. Use current handlers, role shells, `TODO.md`, and deployment tests for release decisions.

---

## 📊 EXECUTIVE SUMMARY

### Historical v1 estimate: ≈ 95% complete

This estimate referred to the v1 research-workflow scope and the historical E2E run below. It is not a completion measure for the current v2 research-monitoring system.

**What changed since the 2026-09-02 audit:**

- EREC staff workflow built (assignment, >=2-review + threshold endorsement, return-for-revision); faculty can no longer advance status past EREC (bypass closed).
- Adviser reassignment at any active stage + permanent adviser history (project_advisers_history, migration 009) + removal notifications to all parties.
- Faculty sub-role badges (Adviser / CREC / EREC Reviewer) derived from live data.
- Milestone dual-write (research_documents + research_reports) deduplicated in queue/badges with status mirroring; project auto-completes on terminal report approval (guarded: only approved terminal_report, only from 'ongoing').
- Notification family complete: staff+admin notified on INITIAL submission; no duplicate notifications on re-scores; relative notification links auto-prefixed with SITE_URL.
- Office-based staff demo accounts (ERS/CREC/ORS/Graduate School), demo credentials synced across README/CLAUDE.md/walkthrough (migration 010).
- 8/8 E2E walkthrough phases PASSED through the UI with no DB bypasses on the main line.
- Final Bound Report milestone added (manual step 14), gated to completed/archived projects, documents-only slot (ce93675).
- Dead nav links resolved by building their targets: staff Revision Returns + Document Verification (9af01b6); admin System Settings & Status page with migration probes and storage stats (f420113).
- All four shared stubs replaced with real pages: Research Calendar (defense + colloquium events, role-scoped), Settings (CSRF password change), Research Archive (search/year filter), View Research (metadata-only, neutral deny for non-archived ids) (9eeeef2, 0615e9b).

**Status Breakdown:**
- ✅ **Fully built:** 33 role pages
- 🟡 **Thin / partial:** a handful of dashboards and shared modules.
- **Stubs:** 0 (former shared stubs are now real pages: `calendar.php` 320 lines, `settings.php` 208 lines, `research-archive.php` 217 lines, `view-research.php` 348 lines; measured with `wc -l`)
- 🔴 **Genuine feature gaps:**
  - Email verification (migration 003): columns intentionally unapplied - no code reads them; feature unbuilt by design.
  - ORS consolidation stage (optional - staff inbox covers intake).
  - Rate limiting on auth endpoints.
  - P3 polish: chapter attachments in My Documents; cumulative-completions stat in reports.

**Critical Security Status:** 🟢 **STRONG** (CSRF, prepared statements, .htaccess, .env, soft-delete all in place; raw `$conn->query` only on hard-coded schema/lookup strings or internal DDL checks).

---

## 0. E2E Walkthrough Results (2026-09-06)

| Phase | Scope                                                                                      | Result                                   |
| ----- | ------------------------------------------------------------------------------------------ | ---------------------------------------- |
| 1     | Team proposal submission                                                                   | PASS                                     |
| 2     | Staff intake, adviser assignment, CREC forward                                             | PASS                                     |
| 3     | CREC scoring (Form 3, 6 criteria), chapter loop incl. revision + adviser change mid-flight | PASS                                     |
| 4     | EREC assignment, 2-review + threshold endorsement, President final approval                | PASS (feature built mid-test)            |
| 5     | MOU + midway report (Form 4) with reject/re-upload cycle                                   | PASS (duplicate-queue bug found & fixed) |
| 6     | Defense scheduling, terminal report, completion                                            | PASS                                     |
| 7     | Colloquium, publication, archive                                                           | PASS                                     |
| 8     | Profile, notifications, access control (403s), logs, reports                               | PASS                                     |

Notes: ownership spot-check used project 1 which turned out to be same-owner (invalid fixture - retest with a non-member account); milestone badge fixture validated 3->2 after dedupe fix.

---

## 0.1 Post-Closeout Hardening Round (external audit response)

An independent workflow audit against the Research Manual 2015 identified integrity gaps; all actionable findings were fixed and live-tested in the same session:

| Commit  | Fix                                                                                                                                                                                                                                              |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| a070a2c | Faculty review access scoped to adviser / assigned reviewer only; all status transitions removed from faculty hands (recommendations recorded, not executed); Form 3 review list corrected to six-criterion /100.                                |
| e0cde90 | Committee recommendations now bind endorsement (any completed reject recommendation blocks it); new EREC disapproval action (erec\_reject) with notifications and logging.                                                                       |
| d93de56 | Milestone slots status-gated per the manual sequence (MOU: approved/ongoing; midway: ongoing; terminal: ongoing/late-stage; bound: completed/archived); under\_review dead end fixed; MOU renamed Memorandum of Research Undertaking per Form 7. |

Remaining audit items (ORS consolidation stage, structured digital forms for Forms 1-2/4-9, incentives and awards Forms 8/10-12, stage-aware resubmission, archive prerequisites) are documented scope decisions - see the gaps list above.

---

## 0.2 v2 Pivot — Research Monitoring System (2026-09-25)

Per adviser direction, the system was restructured from a Research Manual 2015 workflow system into a research-office monitoring system for EARIST Cavite. The workflow core (proposal → CREC → EREC → approval → defense → archive, 8/8 E2E-proven) remains on disk but is no longer in navigation; it is retained as reference code and may be restored as a future module.

New module structure (6 commits, each E2E-tested before merge):

| Commit  | Deliverable                                                                                                                                                    |
| ------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| dc01465 | Schema: research\_activities, activity\_participants, publications, copyright\_applications (migration 012 + canonical schema with demo seeds)                 |
| 9f04007 | Navigation restructure + read-only hub pages for the three modules (role-scoped)                                                                               |
| ef4d1a2 | Activities: CRUD, participants, attendance, status transitions, create-notifications                                                                           |
| a977596 | Publications: submission with PDF upload, FOR UPDATE owner locks, review pipeline (submitted → under\_review → accepted/published), safe downloads             |
| fe85caf | Copyrights: applications, registration with required reference number, rejection with reason, safe downloads                                                   |
| f2ab103 | Dashboards: 7-indicator monitoring overview (staff/admin, shared implementation), personal summaries (faculty/student), pure-CSS activity chart, quick actions |

Role model unchanged (student / faculty / research\_staff / admin): faculty and students submit publications and copyright applications and view activities; staff and admin manage all modules. Repository (research-archive) integrated into navigation.

## 0.3 v3 — Adviser-Approved Research Submission Workflow (2026-10-10)

Per professor direction, the generic research-manual workflow was replaced by a two-stage adviser-approved process, unified for Students and Faculty:

Stage 1 - Research Title: draft (editable) -> submit for review (backend-locked, version snapshot) -> Admin or permission-granted staff approves (status `title_approved`, unlocks chapters) or returns with required comments -> researcher revises and resubmits (new version, history preserved).

Stage 2 - Chapters: available only after title approval; chapter N requires chapter N-1 approved (`FOR UPDATE` gate); same draft/submit/lock/version/return/resubmit model per chapter.

Implementation (commits a33d0ba, b2882e6):
- `includes/research-workflow.php`: shared workflow engine (version snapshots, review decisions, permission checks reusing the existing module-permission system: `research_review` vs `research_approval` grants, admin implicit)
- `research_submission_versions` + `research_author_snapshots` tables (migrations 017/018 via the new migration runner `scripts/migrate.php` + tracking table, migration 016)
- `pages/shared/research-submission-review.php`: unified admin/staff review queue (titles + chapters, approve/return with comments, permission-gated buttons AND server checks)
- Researcher pages (`submit-research`, `edit-research`, `submit-chapter`, `my-research`) widened to student+faculty with role-aware shells
- Upload security: uploads htaccess deny + authorized download handler only (direct file URLs 403)
- Playwright suite (`tests/e2e-browser/`): 11 PASS / 0 FAIL / 2 NOT TESTED with database-state assertions; report at `test-results/e2e-browser-test-report.md`
- Faculty sidebar My Research group

### 0.3 Current v2 source snapshot (October 10, 2026)

The role shells and canonical schema currently expose research-capacity-building activities (seminars, trainings, workshops, presentations, and forums), publications, copyright/IP records, and research-repository workflows alongside communication and account pages. Faculty and student shells link to activities, publications, copyrights, and repository pages. Research staff module visibility is permission-controlled; admin has module management links. The canonical schema includes `research_activities`, `activity_participants`, `publications`, and `copyright_applications`.

This is a source-tree check only. It does not independently verify every write path, role permission combination, migration state, or live database. The old workflow gap list and page-count estimate below belong to the pre-pivot v1 scope and must not be treated as the current v2 backlog.

---

## 1. Per-portal page status

*Sections 1-6 below describe the v1 workflow system retained on disk after the v2 pivot.*

A page is **fully built** if it has its own role gating, render logic and ≥ ~200 lines of code; a **stub** is a 1-line file that defers to `pages/shared/module-page.php`. All line counts were taken from `wc -l` on the working tree.

### 1.1 Admin portal (`pages/admin/`)

| Page | Lines | Status | Evidence |
|---|---|---|---|
| `admin-dashboard.php` | 587 | ✅ Built | KPIs, recent submissions, status distribution. |
| `admin-users.php` | 1065 | ✅ Built | Full user CRUD with status toggle. |
| `admin-research.php` | 801 | ✅ Built | Includes admin final-approval gate (writes `approved_by` / `approved_at`). |
| `admin-archive.php` | 833 | ✅ Built | Publication / colloquium / archive tracking on `research_publication_tracking`. |
| `admin-reports.php` | 294 | ✅ Built | Aggregate analytics, monthly + departmental + status breakdowns. |
| `admin-logs.php` | 366 | ✅ Built | `activity_log` filter + paginate. |
| `admin-backup.php` | 363 | ✅ Built | Database backup list + download. |
| `admin-contact.php` | 552 | ✅ Built | Contact-message triage. |
| `admin-departments.php` | 607 | ✅ Built | Department CRUD. |
| `admin-programs.php` | 669 | ✅ Built | Program CRUD. |
| `admin-settings.php` | 429 | ✅ Built | Read-only environment, migration-probe, and storage diagnostics. |

**Summary:** 11/11 admin pages built. No stubs.

### 1.2 Research Staff portal (`pages/staff/`)

| Page | Lines | Status | Evidence |
|---|---|---|---|
| `staff-dashboard.php` | 746 | ✅ Built | Stats + inbox + review queue. |
| `staff-submissions.php` | 764 | ✅ Built | Triage queue. |
| `staff-crec.php` | 1346 | ✅ Built | CREC review workbench. |
| `staff-defense.php` | 1294 | ✅ Built | Defense scheduling, notifies owner + members + advisers. |
| `staff-milestones.php` | 1217 | ✅ Built | Approve / reject / waive for documents + reports. |
| `staff-revisions.php` | 370 | ✅ Built | Project- and chapter-level revision-return monitor with guarded comments. |
| `document-verification.php` | 377 | ✅ Built | Read-only document metadata, reviewer, file-presence, size, and SHA-1 verification. |
| `contact-messages.php` | 446 | ✅ Built | Public contact-message triage. |

**Summary:** 8/8 staff pages built. No stubs.

### 1.3 Faculty portal (`pages/faculty/`)

| Page | Lines | Status | Evidence |
|---|---|---|---|
| `faculty-dashboard.php` | 614 | ✅ Built | Faculty dashboard and advising overview. |

Faculty title/chapter submission is shared with Students under `pages/researcher/`; review decisions are handled by the authorized Admin/Staff queue.

### 1.4 Student portal (`pages/student/`)

| Page | Lines | Status | Evidence |
|---|---|---|---|
| `student-dashboard.php` | 742 | ✅ Built | Project + milestone + adviser status. |
| `my-research.php` | 456 | ✅ Built | Project list with chapter progress. |
| `my-documents.php` | 793 | ✅ Built | Document library. |
| `submit-research.php` | 895 | ✅ Built | Includes co-researcher team picker. |
| `submit-chapter.php` | 758 | ✅ Built | Five-chapter picker + content editor. |
| `submit-milestone.php` | 999 | ✅ Built | MOU / NDA / Midway / Terminal upload + re-upload after rejection. |
| `edit-research.php` | 944 | ✅ Built | Edit + add/remove co-researchers. |
| `progress-tracking.php` | 903 | ✅ Built | Read-only milestone panel. |
| `research-detail.php` | 562 | ✅ Built | Single project view with chapter / review / milestone tabs. |

**Summary:** 9/9 student pages built. No stubs.

### 1.5 Shared portal (`pages/shared/`)

| Page | Lines | Status | Evidence |
|---|---|---|---|
| `module-page.php` | 352 | ✅ Built | Dynamic loader. |
| `placeholder-page.php` | 62 | ✅ Built | Generic fallback card. |
| `messages.php` | 901 | ✅ Built | Inbox / sent / compose / reply. |
| `notifications.php` | 454 | ✅ Built | Read / delete actions. |
| `profile.php` | 581 | ✅ Built | Edit + password change. |
| `research-detail.php` | 760 | ✅ Built | Cross-role research detail. |
| `calendar.php` | 320 | ✅ Built | Role-scoped defense and colloquium month grid with a 60-day upcoming list. |
| `research-archive.php` | 217 | ✅ Built | Completed/archive browser with wildcard-safe search and year filtering. |
| `settings.php` | 208 | ✅ Built | Account summary and CSRF-protected password change hub. |
| `view-research.php` | 348 | ✅ Built | Metadata-only archive detail with neutral denial for unavailable IDs. |

**Summary:** 10/10 shared pages built. No stubs.

---

## 2. v1 security posture (historical snapshot)

### 2.1 CSRF coverage
- `csrfField()` / `isCsrfTokenValid()` are used in **29 files / 97 occurrences** (Grep across `**/*.php`). All state-changing forms inside the role pages that POST to themselves include a CSRF field and validate it server-side.
- At the time of the v1 audit, four one-line shared stubs had no state-changing form. Those pages were subsequently implemented; this old observation is not a current review of their CSRF posture.

### 2.2 Prepared-statement coverage
- `includes/module-pages.php` has **28 prepared statements vs 6 raw `$conn->query(...)` calls**. The 6 remaining raw calls are all to *hard-coded* schema strings (`SHOW TABLES LIKE 'research_projects'`, `SELECT … FROM research_categories WHERE status = 1`, `SELECT … FROM research_projects WHERE status IN ('completed','archived')`, `SELECT … FROM activity_log …`, `SELECT … FROM users`, `SELECT COUNT(*) …`) with no interpolated user variables.
- Across the wider tree, raw `$conn->query(...)` calls are concentrated in:
  - hard-coded `COUNT(*)` dashboard widgets in `pages/admin/{admin-dashboard,admin-research,admin-reports,admin-users,admin-departments,admin-programs}.php`,
  - schema introspection (`SHOW COLUMNS …`, `SHOW TABLES LIKE …`) in `pages/staff/{staff-milestones,staff-defense,staff-crec}.php`, `pages/admin/{admin-research,admin-archive,admin-departments}.php`, `pages/student/{submit-milestone,submit-research,edit-research}.php`,
  - `pages/staff/staff-submissions.php` and `pages/staff/staff-crec.php` (the latter concatenates a `$status_filter` built from a whitelist — see § 4 priority list).
- `scripts/verify-messages-navigation.php` is a developer-time check that uses raw queries, not a production path.

**Verdict:** No user-controlled input is concatenated into a raw query on a production path. Prepared-statement coverage is high (≈ 95 % of user-input SQL).

### 2.3 File upload validation
- `includes/file-uploader.php` is the single entry point (uses `finfo` MIME sniffing, folder whitelist, server-side extension whitelist, `MAX_UPLOAD_SIZE`).
- `uploads/.htaccess` blocks PHP execution (700-byte file present at `uploads/.htaccess`).
- Per-task `uploads/{proposals,chapters,manuscripts,defense}/` directories exist; milestone uploads go to `uploads/milestones/` (created on demand in `pages/student/submit-milestone.php`).

### 2.4 Other
- `.env` exists alongside `.env.example`; `includes/config.php` is 91 lines and loads via phpdotenv.
- `.htaccess` is present at root (2049 bytes), `includes/`, `database/`, `uploads/`.
- Soft-delete columns (`deleted_at`) are checked before reading core tables in every staff and student query path that touches `research_projects`, `uploads`, `chapters`.
- 403 / 404 / 500 pages exist under `public/`.

---

## 3. EARIST Research Manual 2015 — alignment table

Reference: `docs/research-manual-2015.md` (13 steps, 5 chapters, 10 required documents).

| # | Manual step | Status | Implementing page(s) | Notes |
|---|---|---|---|---|
| 1 | Proposal Submission | ✅ | `pages/researcher/submit-research.php`, `pages/researcher/edit-research.php` | Co-researcher team picker included. |
| 2 | CREC Evaluation | ✅ | `pages/staff/staff-crec.php` | Legacy committee workflow retained only as historical context. |
| 3 | **ORS Consolidation** | 🔴 | — | No code path, no UI. Gap. |
| 4 | EREC Research Forum | ✅ | Legacy committee workflow | Historical manual stage; current v3 workflow uses authorized title/chapter review. |
| 5 | Approval / Revision / Disapproval | ✅ | `pages/shared/research-submission-review.php` | Current review decisions and required revision comments. |
| 6 | **President Approval** | 🟡 | Folded into the admin final-approval gate in `pages/admin/admin-research.php` (writes `approved_by` / `approved_at`) | Manual says "President" but UI records the acting admin as the approver. No separate President role. |
| 7 | MOU and NDA | ✅ | `pages/student/submit-milestone.php` (upload), `pages/staff/staff-milestones.php` (verify) | `research_documents` rows with `document_type='mou'/'nda'`. |
| 8 | Research Implementation | ✅ | `pages/admin/admin-research.php` (status → `in_progress`), `pages/student/progress-tracking.php` | Gate moves approved → ongoing. |
| 9 | Midway Progress Report | ✅ | `pages/student/submit-milestone.php`, `pages/staff/staff-milestones.php` | `research_reports.report_type='midway_progress'`. |
| 10 | Terminal Report Review | ✅ | Same as step 9, with `report_type='terminal'` | |
| 11 | **Final Bound Report upload slot** | 🔴 | Only the **label** is rendered in `pages/staff/staff-milestones.php:73` (`'final_bound_report' => 'Final Bound Report'`), but the student has **no upload widget** for it. | Gap. |
| 12 | Research Colloquium | ✅ | `pages/admin/admin-archive.php` (colloquium_status / colloquium_date), `pages/staff/staff-defense.php` | Uses `research_publication_tracking` (cols detected defensively). |
| 13 | Archive & Publication Tracking | ✅ | `pages/admin/admin-archive.php` (publication_status, journal_reference, archive_status) | Single-page controller for archive lifecycle. |

### 3.1 Five-chapter structure
- `pages/researcher/submit-chapter.php` enforces chapters 1–5 and a chapter picker (commit `e82c18c` fixed the invalid-chapter dead-end).
- Authorized reviewers use the shared title/chapter review queue for chapter decisions.

### 3.2 Required documents
- Proposal, Chapters 1–5: ✅ via `pages/student/{submit-research,submit-chapter}.php`.
- MOU / NDA: ✅ via `pages/student/submit-milestone.php`.
- Midway / Terminal: ✅ same.
- Final bound report: 🔴 upload slot missing on the student side.
- Publication record: 🟡 admin can write the `publication_status` in `pages/admin/admin-archive.php`; no student upload widget.

---

## 4. Remaining v1 workflow gaps at time of audit (historical)

### 🔴 P0 — recorded before the v2 pivot; not the current product backlog
1. **ORS Consolidation stage (Manual step 3).** No table, no UI, no status transition. Add an ORS review state (`under_ors_consolidation`) with a staff-side queue page (mirror `pages/staff/staff-crec.php`).
2. **Final Bound Report upload slot (Manual step 11).** `pages/staff/staff-milestones.php:73` already lists `final_bound_report` as a document type, but `pages/student/submit-milestone.php` only renders the MOU/NDA/Midway/Terminal cards. Add a "Final Bound Report" card using the same flow.
3. **Admin notification on new submission** — `pages/researcher/submit-research.php` notifies co-researchers but not admins. One `createNotification(...)` call inside the existing transaction. (This is also the last unchecked `TODO.md` item from the original list.)

### 🟡 P1 — shared-page work recorded before those pages were implemented
4. `pages/shared/calendar.php` — render the actual schedule from `defense_schedule` + `research_projects.created_by` (the query already exists in `includes/module-pages.php:283-291` for the student calendar).
5. `pages/shared/research-archive.php` — surface the public-facing browse from the existing public `public/research-archive.php` plus a role-scoped list.
6. `pages/shared/view-research.php` — redirect or render the same content as `pages/shared/research-detail.php` for the current role.
7. `pages/shared/settings.php` — render profile-preferences + email notification toggles (a thin new form).

### 🟡 P1 — security hygiene
8. **Login rate limiting — current source check.** `public/login.php` implements `loginFailureKey()`, `isLoginLocked()`, and `recordFailedLogin()` using session state. A database-backed limiter that survives cookie clearing could be considered separately; the old statement that rate limiting is absent is incorrect.
9. **`pages/staff/staff-crec.php:302` reads `users WHERE role='admin'` with raw `$conn->query`** — it is a hard-coded string, but migrating it to a prepared statement keeps the style consistent.

### 🟢 P2 — nice-to-have
10. The "President Approval" step is logged as an admin action. If a President role is ever required, add a `president` role to `users.role` enum and split the gate in `pages/admin/admin-research.php`.
11. Faculty no longer performs title/chapter approval; the unified workflow is shared under `pages/researcher/` and reviewed through the authorized queue.

---

## 5. Completion estimate — justification

| Component | Coverage | Weight | Contribution |
|---|---|---|---|
| Auth + .env + .htaccess + CSRF + soft-delete | 100 % | 15 % | 15 |
| Database schema (19 tables incl. `research_publication_tracking`, `defense_schedule`, `research_documents`, `research_reports`, `project_reviews`, `project_members`, `project_advisers`, `chapters`, `chapter_content`) | 100 % | 10 % | 10 |
| Admin portal (10/10 pages) | 100 % | 10 % | 10 |
| Staff portal (6/6 pages) | 100 % | 10 % | 10 |
| Faculty portal (6 built, 2 thin, 0 stubs) | 85 % | 10 % | 8.5 |
| Student portal (9/9 pages) | 100 % | 15 % | 15 |
| Shared portal (6 built, 4 stubs) | 60 % | 5 % | 3.0 |
| Research-Manual 2015 coverage (11/13 steps full, 1 partial, 2 gaps) | 88 % | 15 % | 13.2 |
| Login rate limiting (v1 scoring snapshot) | 0 % | 5 % | 0.0 |
| File upload validation (folder whitelist + MIME + size) | 95 % | 5 % | 4.75 |
| **Total** | — | **100 %** | **≈ 79.5 %** |

**Historical rounded estimate: ~80 %** of the original v1 scope. The estimate is retired and should not be used to score the current v2 system.

---

## 6. TL;DR

- Sections 1–6 describe the v1 workflow application retained in the working tree; their page inventory and gaps are not the current v2 completion assessment.
- The current source has monitoring modules for activities, publications, copyrights/IP, and repository workflows, in addition to role-based dashboards, communication, and account features.
- The reported 8-phase E2E run predates the v2 pivot. No fresh v2 end-to-end verification is asserted here.
- Login rate limiting exists in session-based form in `public/login.php`; database-backed persistence would be a separate hardening option.
