# Verification: EARIST Annex E and Annex F blank templates

- Date: 2026-10-29 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Prepare new blank DOCX copies of the supplied Annex E and Annex F files, preserve fixed institutional/template content, and update RMS field mappings without changing the source forms.
- Source files: `C:\Users\jimwe\Downloads\ANNEX E_RESEARCH PORTAL_Intellectual Property Asset Evaluation Clearance (ITSO).docx`; `C:\Users\jimwe\Downloads\ANNEX F_RESEARCH_Conflict of Interest and Confidentiality Declaration.docx`.
- Output files: `templates/earist/blank/ANNEX E - Intellectual Property Asset Evaluation Clearance ITSO - Blank Template.docx`; `templates/earist/blank/ANNEX F - Conflict of Interest and Confidentiality Declaration - Blank Template.docx`.
- Environment: Local workspace; no application/database mutation.
- Session state: Document analysis, blank-copy creation, mapping update, and static package comparisons complete.

## Graphify and mapping context

- Question and selected existing graph vocabulary: How do RMS author snapshots and IP application records relate to the form fields? Selected existing labels `research_author_snapshots`, `copyright_applications`.
- Query/lookup: `graphify query "research_author_snapshots copyright_applications" --budget 1000` returned 44 nodes and was truncated at 25. It confirmed the author snapshot migration node, `research_author_snapshots`, `copyright_applications`-related records/pages, but was not treated as complete.
- Source/schema confirmation: Direct inspection of `database/schema/rms_db.sql` confirmed `research_author_snapshots`, `users`, `project_members`, `copyright_applications`, and `copyright_contributors`. There is no dedicated IP asset evaluation clearance or conflict/confidentiality certification/signatory record. `copyright_applications.status` is a separate application workflow and is not mapped to Annex E's evaluation outcomes.
- Limitations: The graph does not contain a reliable representation of the DOCX contents and did not establish form-to-schema relationships. Form fields were extracted from the original DOCX package and mappings checked against current schema/source.

## Attached-document instruction handling

The form's certification statements, explanatory text, and printed office/approver-role labels were treated as fixed institutional form content. They do not change the user's instructions for this work. No embedded form text was followed as an instruction to the assistant.

## Checks

| ID | Method | Action | Expected | Observed / evidence | Status |
|---|---|---|---|---|---|
| DOCX-01 | Read-only DOCX package/XML inspection | Identify populated and blank fields in both source forms | Separate completed research values from fixed content and placeholders | Each source has a populated research title and two author lines containing name, position, and affiliation. Annex E's three outcome boxes are unchecked; issue date/place and prepared/attested signature lines are blank. Annex F's eight signatory rows are blank; declarations are printed text. Embedded media are institutional seals/logos and a 1x1 pixel image, not sample signatures. | Passed |
| DOCX-02 | Exact package comparison | Create blank copies and compare all ZIP package entries | Only variable values are removed; all other original package parts remain unchanged | For both source/output pairs, the only differing package entry is `word/document.xml`. In that part, exact raw-text comparison confirms only the title and two populated author entries were removed. All other entries are byte-identical. | Passed |
| DOCX-03 | DOCX integrity check | Validate ZIP CRC, XML parsing, retained fixed labels and absence of removed values | Output packages remain readable and contain fixed content/placeholders | Both packages pass `ZipFile.testzip()`, parse as XML, retain title labels/ITSO roles/declaration text/signatory headings, and contain none of the cleared title/author strings in XML parts. | Passed |
| MAP-01 | RMS schema/source review | Map shared and unique fields for Annex E/F | Reuse safe existing fields and mark unsupported semantics unmapped | `FIELD-MAPPINGS.md` now includes E/F field tables, repeated-input matrix, E/F-specific inputs, and limits. Research title and author snapshot names map conditionally; ITSO result, issuance place/date, COI attestations, review-role assignment, signatures, and signer dates have no semantically safe record. | Passed |
| APP-01 | User-operated browser | Not applicable to DOCX-only processing | No application interaction required | No browser action, account change, source DOCX overwrite, database change, or generator implementation occurred. | Skipped — document-only task |

## Cleared content

- Annex E: scholarly work title; author 1 full name/position/affiliation; author 2 full name/position/affiliation.
- Annex F: scholarly work title; author 1 full name/position/affiliation; author 2 full name/position/affiliation.

## Resume / handoff

- Updated mapping: `templates/earist/blank/FIELD-MAPPINGS.md`.
- Annex E/F blank copies are new files; supplied source documents were not edited or overwritten.
- No generator, source code, or database was modified for this document task.
- The updated mappings should be reviewed before any future automatic document-generation implementation, especially signatory role assignment and the absence of IP/COI workflow records.
