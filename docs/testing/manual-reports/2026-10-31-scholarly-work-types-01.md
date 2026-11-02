# Scholarly work type options

- Date/time: 2026-10-31 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Faculty Add/Edit Research Record shows the eight scholarly work types named in EARIST Faculty Manual 2018–2019, Section 2.4.1.1, while keeping existing RMS options available.
- Environment URL: `http://localhost/rms/`
- Session state: in progress

## Graphify and source context

- Question and selected graph vocabulary: How does the faculty research record form validate and reuse the scholarly work type? Selected terms: `Faculty`, `Research`.
- Query/lookup and result: `graphify query "faculty research" --budget 1500` returned the faculty shell and canonical researcher submission nodes, but no faculty research record helper or configuration node.
- Relevant source locations and verified relationships: `config/faculty-research-records.php` supplies choices through `facultyRecordWorkTypes()` in `includes/faculty-research-records.php`; the form renders them in `pages/faculty/faculty-research-record-form.php`; validation accepts configured keys and snapshots the matching label; document mapping uses the saved label in `includes/earist-document-mapping.php`.
- Missing, inferred, stale, or truncated context: Graphify does not cover the faculty research record feature; current source was inspected directly. No schema or database changes were made.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l config/faculty-research-records.php` | PHP syntax is valid | `No syntax errors detected in config/faculty-research-records.php` | Passed |
| B1 | User browser / faculty / Add Research Record | Open the work-type dropdown | It includes Books, Magazines, Journals, Research Articles, Monographs, Lectures, Modules, and Instructional Materials, alongside the existing Conference, Book chapter, and Other choices | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests

- Added the eight categories named in the supplied Faculty Manual reference. Retained the pre-existing RMS choices and the stable `journal` key; saved records keep their snapshotted labels.
- No database migration is needed: the selected value and label fit the existing fields and are rendered through the current mapping.

## Assistant-run checks

- `php -l config/faculty-research-records.php` — passed.
- Read-only Graphify query and current form, validation, storage, and document-mapping source review — completed; no application interaction or database mutation was performed.

## Resume / handoff

- Next pending action: user opens the faculty Add Research Record form, checks the work-type list, and reports whether all eight manual categories appear.
