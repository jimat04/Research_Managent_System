# RMS Specification

**Scope:** This describes the application implemented in the current schema and role pages. It is not a verbatim specification of the EARIST Research Manual 2015. See [Research-Manual_2015.md](reference/Research-Manual_2015.md) for the manual-to-application mapping and known workflow simplifications.

**Product scope note:** The application includes research-office monitoring modules and a connected student/faculty researcher workflow. The researcher workflow is implemented under `pages/researcher/` and uses the shared authorized review queue; it is separate from the older committee, defense, and milestone routes retained for compatibility.

## Platform

- PHP 7.4+ with procedural MySQLi, MySQL/MariaDB, and Apache (the documented local setup is XAMPP).
- Canonical fresh-install schema and seed data: `database/schema/rms_db.sql`.
- Existing installations may require the applicable numbered scripts in `database/migrations/`; consult the README migration guidance before applying them.
- Authenticated pages are grouped by role under `pages/`; reusable cross-role features are under `pages/shared/`. Shared role shells are in `includes/`.

## Roles and access

The `users.role` enum defines four application roles:

| Role | Main responsibilities |
|---|---|
| `student` | Eligible active BSIT/BSCS researchers can submit proposals/titles, chapters, and a complete manuscript; all permitted users can view their existing project history. Students outside those programs cannot create or submit new workflow versions. |
| `faculty` | Use the same title, chapter, and complete-manuscript submission workflow without the student-program restriction. |
| `research_staff` | View or manage reviewer actions according to per-user research-development permissions; separate older committee, defense, and milestone pages remain for compatibility. |
| `admin` | Manage users, institutional data, reports, research-office modules, and system settings; administer authorized title, chapter, and final-manuscript decisions. |

Protected pages enforce authentication and role checks. Research staff module access can additionally be limited to `view` or `manage` permissions. A separate per-subpage download grant is opt-in and defaults to denied; module access alone does not grant downloads. Administrators remain unrestricted. The admin is not a research-staff substitute: final approval is an administration gate.

## Project lifecycle

The current project table permits these status values:

`draft`, `proposal`, `submitted`, `under_review`, `under_crec_review`, `under_erec_review`, `for_revision`, `revision_required`, `rejected`, `approved`, `ongoing`, `progress_report`, `terminal_review`, `completed`, and `archived`.

The connected researcher workflow follows this route:

1. An eligible student researcher (BSIT/BSCS) or faculty member creates and submits a research title/proposal with exactly three distinct title options, one research category, and one to five active research areas mapped to that category. The area checklist keeps all active areas visible and warns when a selected area is not mapped to the chosen category; selecting a mapped area clears mismatched selections. Student project leads and co-researchers must all be active BSIT/BSCS students; faculty participation is not limited by program. The submitted version snapshots the options and selected area labels.
2. An administrator or staff member with the relevant review permission reviews the locked title version and attached file. The reviewer selects exactly one submitted title to approve, or returns the proposal without a selection using one consolidated Comments/Recommendation field. Approval updates the project’s canonical title to the selected option. Returned submissions retain version and review history; title feedback is stored separately from chapter and final-manuscript comments and recommendations.
3. After title approval, the researcher submits Chapters 1–5 sequentially. Each chapter is locked for review; approval unlocks the next chapter, while a return retains reviewer feedback and enables a revision.
4. Once all five chapters are approved, the researcher submits one complete manuscript containing the full research. An authorized administrator or staff reviewer reviews its file and can approve it or return it with comments for resubmission.
5. Final-manuscript approval is required for completed status and completed-research reporting. Legacy `completed` or `archived` projects without an approved final manuscript are reopened as `ongoing` by migration 025. Existing records remain viewable even when their current student team is not eligible to submit.

Older committee handoffs, defense, and milestone routes are separate compatibility features and do not replace these title/chapter/final-manuscript stages. The database enum includes compatibility and intermediate states; confirm every transition in the relevant handler before changing the workflow.

## Research records and supporting features

- **Chapters and completion:** Five approved chapters unlock the complete-manuscript stage but do not themselves complete a project. Completion requires an approved final-manuscript submission recorded in `research_submission_versions`.
- **Research proposal titles and areas:** Title options are stored on `research_projects` and snapshotted per submitted version; the approved option becomes the canonical project title. `research_areas` and `category_research_areas` provide the predefined catalog and category mappings maintained by administrators, while `research_project_areas` records each proposal’s selected areas. The researcher checklist shows all active areas and warns about category mismatches; selecting a mapped area clears mismatched selections, and server-side validation remains authoritative. The legacy free-text `research_area` field remains for older records.
- **Reports and documents:** `research_reports` and `research_documents` track report/document type, submission, review, and approval or revision state. Upload records are also associated with projects.
- **Review:** `project_reviews` records reviewer, committee level (`crec` or `erec`), six Form No. 3 score fields, comments, and recommendation.
- **Defenses:** `defense_schedule` tracks proposal, pre-oral, and final defense schedules and their status. Defense scheduling is an academic extension, not a step specified by the Manual's faculty-grant procedure.
- **Research capacity building:** Seminars, trainings, workshops, and related activities have optional multi-day schedules (up to a 366-day selection window); managers explicitly choose the session dates. Each date stores its times, optional venue/speaker overrides, audience, participants, and attendance. Audience can be all users, a role, selected users, or a custom combination. Schedule edits preserve activity and participant history; affected users receive deduplicated in-app invitations, cancellation notices, or schedule updates. Migrations `034_activity_multi_day_schedule.sql` through `036_activity_budgets_and_notices.sql` add date-level schedules, custom audiences, budgets, expenses, contribution responses, and confirmed in-app budget notices; migrations 037 and 038 repair partially applied schedule/type changes on existing installations. The canonical schema includes the current structure for fresh installs. For upgrades, inspect the database and migration ledger, then apply only the applicable scripts using the migration runner before using these features.
- **Activity budgets and notices:** Managers can record activity format, funding source, pending/confirmed budget status, organizational allocation, and estimated/actual expenses scoped to a schedule date and audience. Participant charges can be none, optional, or mandatory. Eligible invited participants may respond to a contribution prompt when enabled. Managers can confirm a budget notice to selected roles and/or active users; delivery status and notice history are retained for review and retry. These are recorded planning and notification features, not payment processing or verified disbursements.
- **Publications and intellectual property:** Publications can link to research projects and use configurable type, subtype, scope, and form rules. Author order, metadata, evidence, research-area links, and existing record history are retained. Copyright applications track research outputs, patents, utility models, innovation, and technology-transfer records.
- **Faculty research records:** Faculty can maintain research and engagement records with ordered authors, SDG links, program associations, and report views. Engagement records can link to research activities.
- **Academic affiliation:** Users can have grouped academic affiliations and programs; the supported Food Technology specialization is represented in the affiliation catalog.
- **Institutional document workflows:** Faculty can map document fields and prepare supported EARIST forms through the document workspace. Generated files and source documents use protected storage and authenticated delivery; template and generation prerequisites are checked before generation.
- **Public research releases:** Administrators can manage which eligible research records are released to the public research archive. Public visibility follows the release record rather than exposing all internal research records.
- **Adviser requests:** Student researchers can request an adviser and use the shared conversation and protected document flow associated with the request.
- **Upload history:** Upload records support soft deletion so replacement/removal does not silently erase retained file history.
- **Research archive:** Publication, colloquium, and archive states are tracked separately in `research_publication_tracking`; archived author details can be retained in `research_author_snapshots`.
- **Shared features:** Messaging, notifications, profiles, calendar, research details, publications, copyrights, activities, and repository pages are shared across permitted roles.

Use the actual form validation and upload handlers as the source of truth for accepted file types, size limits, and required fields; they can vary by upload purpose.

## Data and workflow boundaries

- The canonical schema uses `InnoDB` and `utf8mb4`. Project status and report/document/review statuses are distinct fields and must not be conflated.
- Projects carry an academic-year reference; the current schema does not implement a Thesis 1 → Thesis 2 progression gate.
- The current RMS roles and screens consolidate several institutional duties. College Dean, VPREIS, and Budget Office responsibilities are not modeled as separate application roles or independent workflow stages.
- The numbered migration history in this checkout extends through migration 066. For existing installations, inspect the target schema and migration ledger and apply only applicable migrations; the canonical schema is the fresh-install source of truth.
- The Manual's 14-step research procedure and the thesis/chapter/defense extensions are documented separately; do not describe academic extensions as Manual-mandated grant steps.

## Sources of truth

For implementation details, inspect `database/schema/rms_db.sql`, the relevant `pages/` handler, and the role shell before changing this specification. For institutional procedures, consult `docs/reference/Research-Manual_2015.md` and its companion summary; preserve the source manual transcription as reference material.
