# RMS Specification

**Scope:** This describes the application implemented in the current schema and role pages. It is not a verbatim specification of the EARIST Research Manual 2015. See [research-manual-2015.md](./research-manual-2015.md) for the manual-to-application mapping and known workflow simplifications.

**Product scope note:** The current navigation centers on research-office monitoring modules (activities, publications, copyrights/IP, and repository records). The proposal-to-archive lifecycle below describes workflow code retained in the repository; it is a legacy/academic workflow and should not be interpreted as the current primary navigation flow or a recent end-to-end test.

## Platform

- PHP 7.4+ with procedural MySQLi, MySQL/MariaDB, and Apache (the documented local setup is XAMPP).
- Canonical fresh-install schema and seed data: `database/schema/rms_db.sql`.
- Existing installations may require the applicable numbered scripts in `database/migrations/`; consult the README migration guidance before applying them.
- Authenticated pages are grouped by role under `pages/`; reusable cross-role features are under `pages/shared/`. Shared role shells are in `includes/`.

## Roles and access

The `users.role` enum defines four application roles:

| Role | Main responsibilities |
|---|---|
| `student` | Use research activities, publications, copyright/IP, repository, communication, and account features; legacy proposal/chapter flows remain in the codebase. |
| `faculty` | Use research activities, publications, copyright/IP, repository, communication, and account features; legacy assigned proposal-review screens remain in the codebase. |
| `research_staff` | View or manage research-office modules according to per-user permissions; legacy proposal intake, committee handoffs, defense, and milestone pages remain in the codebase. |
| `admin` | Manage users, institutional data, reports, research-office modules, and system settings; an admin final-approval gate also remains in the legacy project workflow. |

Protected pages enforce authentication and role checks. Research staff module access can additionally be limited to `view` or `manage` permissions. The admin is not a research-staff substitute: final approval is an administration gate.

## Project lifecycle

The current project table permits these status values:

`draft`, `proposal`, `submitted`, `under_review`, `under_crec_review`, `under_erec_review`, `for_revision`, `revision_required`, `rejected`, `approved`, `ongoing`, `progress_report`, `terminal_review`, `completed`, and `archived`.

The retained project-workflow code follows this route:

1. A student creates and submits a proposal. A project may have a lead researcher and co-researchers recorded in `project_members`.
2. Research staff process the submission, assign an adviser/reviewers as applicable, and forward it into CREC review.
3. Assigned faculty reviewers record CREC and EREC evaluations in `project_reviews`. Form No. 3 uses six weighted criteria totalling 100 points.
4. Research staff records the committee handoffs. EREC may recommend a proposal for final approval; faculty reviewers do not grant the final administrative approval.
5. An administrator grants final approval or returns the proposal for revision. Final approval moves the project to `ongoing`.
6. During implementation, students submit required chapters, project documents, and progress/terminal reports as applicable. Research staff coordinates verification, defense schedules, and completion.
7. Completed research can be managed for colloquium, publication, repository, and archive tracking. Archiving updates the archive tracking record and, when eligible, the project status.

The database enum includes compatibility and intermediate states. Do not assume every enum value is a distinct page, button, or currently reachable transition; confirm a transition in the relevant handler before changing the workflow.

## Research records and supporting features

- **Chapters:** Projects support a five-chapter student research structure; chapter submissions and review state are tracked separately from the project lifecycle.
- **Reports and documents:** `research_reports` and `research_documents` track report/document type, submission, review, and approval or revision state. Upload records are also associated with projects.
- **Review:** `project_reviews` records reviewer, committee level (`crec` or `erec`), six Form No. 3 score fields, comments, and recommendation.
- **Defenses:** `defense_schedule` tracks proposal, pre-oral, and final defense schedules and their status. Defense scheduling is an academic extension, not a step specified by the Manual's faculty-grant procedure.
- **Research capacity building:** Seminars, trainings, workshops, and related activities have activity and participant records.
- **Publications and intellectual property:** Publications can link to research projects. Copyright applications track research outputs, patents, utility models, innovation, and technology-transfer records.
- **Research archive:** Publication, colloquium, and archive states are tracked separately in `research_publication_tracking`; archived author details can be retained in `research_author_snapshots`.
- **Shared features:** Messaging, notifications, profiles, calendar, research details, publications, copyrights, activities, and repository pages are shared across permitted roles.

Use the actual form validation and upload handlers as the source of truth for accepted file types, size limits, and required fields; they can vary by upload purpose.

## Data and workflow boundaries

- The canonical schema uses `InnoDB` and `utf8mb4`. Project status and report/document/review statuses are distinct fields and must not be conflated.
- Projects carry an academic-year reference; the current schema does not implement a Thesis 1 → Thesis 2 progression gate.
- The current RMS roles and screens consolidate several institutional duties. College Dean, VPREIS, and Budget Office responsibilities are not modeled as separate application roles or independent workflow stages.
- The Manual's 14-step research procedure and the thesis/chapter/defense extensions are documented separately; do not describe academic extensions as Manual-mandated grant steps.

## Sources of truth

For implementation details, inspect `database/schema/rms_db.sql`, the relevant `pages/` handler, and the role shell before changing this specification. For institutional procedures, consult `docs/reference/Research-Manual_2015.md` and its companion summary; preserve the source manual transcription as reference material.
