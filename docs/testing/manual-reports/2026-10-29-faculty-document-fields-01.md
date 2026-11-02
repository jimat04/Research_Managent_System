# Verification: Faculty account fields for EARIST documents

- Date/time: 2026-10-29 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Inspect faculty registration, authentication, account schema, and profile functionality to determine reusable account fields for Annex A, Annex B, Annex C, and QSF-RDS-019.
- Environment URL: Not applicable; source and canonical schema inspection only.
- Roles and disposable records/files: No account row or application record was accessed.
- Session state: Finished

## Graphify and source context

- Question and selected graph vocabulary: How do faculty registration, authenticated account data, faculty profiles and user fields relate? `updateRegistrationFields`, `getCurrentUser`, `users`, `academic_rank`, `faculty_research_profiles`.
- Query/lookup and result: `graphify query "updateRegistrationFields getCurrentUser" --budget 1500 --context_filter call` returned 42 nodes and 66 edges and reported a complete answer over the requested budget. A broader registration/profile query was truncated; the profile-focused query returned mostly unrelated faculty overview nodes. Direct current-source inspection was used for the registration/profile-to-schema mapping.
- Relevant source locations and verified relationships: `public/login.php` lines 42–50 and 206–272 (faculty registration allowlists, validation and `users` insert), 821–910 (registration inputs), 1011 onward (role-dependent field display); `includes/auth.php` lines 31–93 (active-account check and current-user fields); `pages/shared/profile.php` lines 69–73, 98–156, 270–280 and 425–493 (profile load/update and fields); `pages/faculty/faculty-profile.php` lines 61–75, 95–120 and 345–365 (account display and research-profile editing); `database/schema/rms_db.sql` lines 626–684 (`users`, program and faculty-program assignments), 1043–1060 (`faculty_research_profiles`); migrations 002 and 028 (faculty fields and academic-rank enum).
- Missing, inferred, stale, or truncated context: Graphify does not provide a reliable path for the faculty research profile; its relevant query results were broad/truncated. Current PHP and canonical SQL were checked directly. No live faculty account values were queried, so this verifies available fields, not whether a particular account has populated them.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| FAC-01 | Assistant source review | Trace faculty registration inputs through validation and insert | Identify fields collected and persisted for faculty | Required first name, last name, email, role, department and academic rank; contact and employee ID are optional; specialization is collected. The insert writes these to `users`. | Passed |
| FAC-02 | Assistant schema review | Inspect canonical `users` and faculty profile tables | Separate existing identity fields from missing document fields | `users` contains identity, contact, department/program, specialization, academic rank and account status; no middle-name, affiliation, employment-status or designation column. `faculty_research_profiles` stores research interests/links, not employment identity. | Passed |
| FAC-03 | Assistant profile/auth review | Inspect authentication and both shared/faculty profile pages | Determine which fields are available, editable and role/account status semantics | Shared Profile edits name, contact, specialization and rank; email/role are read-only, department/program are not updated there. Faculty Research Profile edits degree, research interests and scholarly links. `users.status` is active/pending/suspended account state. | Passed |
| APP-01 | Browser / RMS application | Not exercised | Not applicable to static inspection | No application interaction or database mutation was needed. | Skipped — user requested findings only. |

## Failures, decisions, fixes, and retests

- No application failures were exercised. This was not a runtime test.
- Found a rank-list inconsistency: registration and the canonical enum include `College Professor`, but the Shared Profile edit whitelist/options omit it. No code was changed.

## Assistant-run checks

- Read-only inspection of `public/login.php`, `includes/auth.php`, `pages/shared/profile.php`, `pages/faculty/faculty-profile.php`, `database/schema/rms_db.sql`, and migrations 002, 024, 028 and 042.
- No PHP lint, automated test, browser action, live database query, account update or migration was run.

## Resume / handoff

- Last user-confirmed state: Not applicable; no browser step was required.
- Next pending action: None. Findings are ready for design review before implementation.
- Blocked/skipped checks and remaining verification gaps: Individual account values were not inspected; this report states schema/code availability only. If a future form stores appointment/employment status, its allowed values and data authority should be confirmed before implementation.
- User-performed data changes and cleanup status: None.
