# Faculty research records

Faculty research records store reusable research information for future EARIST documents. They do not submit research, initiate reviews, report progress, or create publications. There is no application-level record-count limit, and titles need not be unique.

## Phase 3 field-mapping integration

Owned Active and Archived record details now link to **Review Document Fields**. This read-only page reuses the existing faculty profile and owner-scoped record loaders for Annex A/B/C/E/F and QSF-RDS-019. It does not generate/download documents, save temporary document answers, change research records, or add a workflow. Deleted records must be restored before mapping; co-authorship grants no access. Annex D remains Admin-only and its runtime mapping is deferred.

See [EARIST document mapping](EARIST_DOCUMENT_MAPPING.md) for definitions, input validation, protected official fields, structural bindings and future persistence keyed by record ID, document type and document version. Current snapshot/lifecycle behavior and migrations 049/050/051 remain unchanged. [Phase 3 verification](testing/manual-reports/2026-10-30-earist-mapping-01.md) separates assistant checks from pending user-operated browser observations.

## File inventory

| Change | Files |
|---|---|
| Created: database/configuration | `database/migrations/050_faculty_research_records.sql`, `config/faculty-research-records.php` |
| Created: backend/pages | `includes/faculty-research-records.php`, `pages/faculty/faculty-research-records.php`, `pages/faculty/faculty-research-record-form.php`, `pages/faculty/faculty-research-record-detail.php` |
| Created: assets | `css/faculty-research-records.css`, `js/faculty-research-records.js` |
| Created: checks/documentation | `scripts/check-faculty-research-records.php`, `docs/FACULTY_RESEARCH_RECORDS.md`, `docs/testing/manual-reports/2026-10-30-faculty-records-01.md` |
| Modified | `database/schema/rms_db.sql` (append matching 050 definitions), `scripts/migrate.php` (050 verifier), `includes/faculty-shell.php` (one navigation entry), `templates/earist/blank/FIELD-MAPPINGS.md` (new faculty-source mapping and historical-scope clarification) |

Local backup and preflight metadata are under ignored, HTTP-denied `backups/`; they are not application source or sample data.

## Access and pages

Faculty navigation: **Research & Profile → My Research Records**.

- `pages/faculty/faculty-research-records.php`: owner-scoped list, title/author search, status filters, sorting, server-side pagination (10 per page), and lifecycle actions.
- `pages/faculty/faculty-research-record-form.php`: Add/Edit, registered faculty search, external authors, accessible order controls, and all five information sections.
- `pages/faculty/faculty-research-record-detail.php?id=ID`: selected record and its saved information, resolved by ID rather than title.

Each route requires a live authenticated faculty account. Administrators, research staff, and students do not get module access. Adding an author never grants access. A future Admin reader must have a separate explicit authorization path; it must not weaken the owner-scoped loader.

The first author by saved/display order is always the Lead Author. Moving another author to position one changes that author's role to Lead Author and the remaining roles to Co-Author. Choosing Lead Author in the role control moves that author to the first position. Both order and roles are saved only when the record is saved.

## Database

Migration: `database/migrations/050_faculty_research_records.sql`. It adds:

| Table | Relationships and retained information |
|---|---|
| `faculty_research_records` | `owner_id → users.user_id` with RESTRICT; independent record ID, research content, one conference, one journal, lifecycle metadata, optimistic version and creation-token hash. |
| `faculty_research_record_authors` | `research_record_id → faculty_research_records.research_record_id` with RESTRICT; optional `faculty_user_id → users.user_id` with SET NULL; independent name/position/affiliation snapshots, role and order. |
| `faculty_research_record_sdgs` | `research_record_id → faculty_research_records.research_record_id` with RESTRICT; unique `(research_record_id, sdg_number)` and range 1–17. |

Research months are nullable `CHAR(7)` in `YYYY-MM` format; conference dates are nullable `DATE`. `created_at` and `updated_at` are system metadata, not research dates. Title is `TEXT` with an application/database maximum of 2,000 characters.

Active/Archived is stored in `status`. `deleted_at` overlays deletion without changing that status. Recovering a deleted Archived record returns it to Archived; restoring it again makes it Active. Active and Archived records can be edited. Deleted records must be restored first. Normal lifecycle actions never remove parent rows, child relationships, or files.

Author editing reconciles child rows in a transaction and preserves retained author-row IDs. Removing an author or deselecting an SDG changes only that record's current relationships. Future generated documents must store immutable generation snapshots rather than depend on mutable author rows.

## Configuration and profile mapping

Edit `config/faculty-research-records.php` to configure scholarly-work options. Initial values reuse RMS publication labels: Journal, Conference, Book chapter, Other. These are **RMS classifications, not a verified official EARIST taxonomy**. Stable keys must be lowercase identifiers, at most 64 characters; labels are limited to 120 characters. The reserved key `other` requires an additional description.

Keep keys stable when changing labels. The selected label is saved with each record; a removed option remains selectable on its existing records. A label/configuration change does not automatically rewrite saved records.

The selected type does not restrict or imply a conference/journal target. Both optional sections can coexist regardless of type. Conference categories use the supplied Annex A's Regional/National/Local and International options. QSF-RDS-019 has different wording and requires future document-specific confirmation.

The helper queries existing `users` fields using the authenticated owner ID; no faculty profile table is added. Suggested name includes middle name. Position starts with academic rank, then designation when rank is absent; it remains editable. Affiliation uses `rmsSetting('institutional_affiliation')` for internal faculty. External affiliations are always manually entered. Existing author snapshots are not refreshed from profile changes. Existing internal snapshots remain usable when an account is suspended, removed, or no longer linked; new internal selections require an active faculty account.

The existing Account Profile page lets the owner complete missing account information. Record-specific overrides never update user accounts, roles or statuses.

## Integrity and safeguards

- Ownership is enforced on list/search, detail, edit and lifecycle operations. Related-author/SDG reads join back to the owner-scoped parent.
- POST handlers validate CSRF. Delete also requires an explicit confirmation checkbox; JavaScript adds a confirmation dialog. The checkbox/confirmation text remains usable without JavaScript.
- Parent, author and SDG saves use one transaction. Lifecycle changes update only lifecycle metadata and version/timestamp. Audit entries use the existing `logActivity()` helper in the transaction.
- A server-issued form token is scoped to the authenticated session and hashed under a unique owner/token index. Retries return the previously created ID; titles remain non-unique. Add forms expire after 24 hours or session expiry.
- Every saved change increments an optimistic version. Stale edits/actions fail instead of overwriting newer changes. After a conflict, submitted input remains on the form; reconcile it against the latest record opened separately.
- The final form-completion marker detects truncated form submissions before replacing child relationships. PHP input/POST limits still apply; increasing them is an administrator operation when genuinely needed.
- One Lead Author is required, independently of owner identity and author order. Duplicate registered accounts and normalized name/affiliation combinations are rejected. For different people with the same name and affiliation, use their accurate distinguishing full names/details; automatic identity matching cannot establish real-world identity.
- Optional dates and text fields are validated on the server; output is escaped. Error messages do not include SQL or account details. JavaScript improves author ordering and validation; server-rendered author actions remain available without JavaScript.

## Future multi-target and document compatibility

This phase stores one optional conference and one optional journal on the parent, accessed through the central helper. To add multiple targets later, introduce child target tables keyed by the same stable record ID, copy each nonempty legacy target into one child row, and adapt the helper to expose an explicitly selected target. Keep existing parent fields during migration/compatibility rollout. Record IDs, ownership, author IDs and SDGs need not change. Do not choose targets by title or silently combine targets.

No generation/history tables or Admin readers exist in this phase. A future generated document should reference the stable record ID, document type, generating user, generation time, version, file location and status, and capture an immutable input snapshot. Edits/archive/delete/restore must not rewrite generated versions. Once such history exists, recommend archiving in deletion confirmation while retaining explicitly confirmed recoverable deletion.

## Installation and checks

Before applying 050, confirm the configured local development `rms_db`, inspect the ledger and existing table names, and make a protected database backup. Use the migration runner with only this filename:

```powershell
C:/xampp/php/php.exe scripts/migrate.php apply 050_faculty_research_records.sql
```

The registered verifier checks column types/nullability, storage, indexes, foreign-key rules, check-constraint presence and lifecycle defaults. Canonical fresh-install schema includes the same three definitions. Migration 049 is unchanged. Do not sync/reapply unrelated migrations as part of this module.

Safe rollback starts by removing access to the module while preserving its tables and data. Any later physical removal/restoration requires a separately reviewed data-retention decision and backup; never drop tables after faculty have saved records merely to roll back the UI.

Non-mutating validation command:

```powershell
C:/xampp/php/php.exe scripts/check-faculty-research-records.php
```

This checks input validation and snapshots without connecting to a database. It does not prove browser rendering, live transactional persistence, authorization across sessions, or restoration behavior. Follow the [manual report/checklist](testing/manual-reports/2026-10-30-faculty-records-01.md) for user-operated browser verification. No application requests or browser actions are automated.
