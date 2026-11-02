# Verification: EARIST DOCX blank templates

- Date/time: 2026-10-29 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Clear prior research-specific values and sample signatures from five EARIST DOCX sources while retaining fixed form content, layout, and all unrelated package content.
- Environment URL: Not applicable; no RMS application behavior was changed.
- Roles and disposable records/files: Five user-provided DOCX files; no application records or database were used.
- Session state: Finished

## Graphify and source context

- Question and selected graph vocabulary: Which existing RMS schema objects can safely represent titles, authors, publication/presentation details, and review data? `research_projects`, `publications`, `publication_contributors`, `project_reviews`.
- Query/lookup and result: `graphify query "research_projects publications publication_contributors project_reviews" --budget 1500`; existing graph returned 42 nodes and 46 edges and reported a complete answer over the requested budget. Graph coverage did not include all current schema objects, including `research_author_snapshots`; schema findings were therefore checked directly.
- Relevant source locations and verified relationships: `database/schema/rms_db.sql` lines 324–379 (`project_members`, `project_reviews`, `research_author_snapshots`), 464–517 (`research_projects` and classifications), 626–660 (`users`), 760–815 (`research_activities`), 964–1025 (`publications` and publication contributors), and 1070–1090 (`faculty_engagement_records`). The schema confirms project-title, author identity/role, publication, activity, and partial reviewer links; it does not contain a field-for-field store for these five forms.
- Missing, inferred, stale, or truncated context: The graph response exceeded its requested token budget because it emitted the complete traversal; schema relationships for author snapshots and activity records were confirmed from current SQL. No application behavior was changed or traced.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| DOCX-01 | Assistant ZIP/XML inspection | Compare each blank DOCX package with its source | Only targeted text nodes and sample-signature image pixels change | All package member names match. For each file, only `word/document.xml` and the identified signature image assets differ. All non-target package members are byte-identical. | Passed |
| DOCX-02 | Assistant XML structure audit | Compare form structure after blanking text nodes in both versions | Tables, paragraphs, breaks, drawings, and layout elements remain structurally intact | Table/row/cell/section/drawing counts are unchanged per source. Annex A adds only `pageBreakBefore` to the existing process-flow image paragraph to retain its fifth page. | Passed |
| DOCX-03 | Assistant field audit | Search cleared documents for source titles, sample author/evaluator identity strings, dates, fees, and AI-tool entries | Targeted values are absent from their form content | Targeted sample values are absent; printed institutional approver names, instructions, form/revision identifiers, guidelines, headers, footers and fixed media remain. | Passed |
| DOCX-04 | Assistant signature-image audit | Inspect signature image assets in blank outputs | No sample signature pixels remain while image boxes retain their original dimensions | PNG signature assets are fully transparent at original pixel dimensions; the presentation-request JPEG is a same-size blank white image. Drawings and relationships remain. | Passed |
| DOCX-05 | Assistant Microsoft Word read-only open and repagination | Open each source/output pair and compare page counts | Every blank copy remains readable and retains its source page count | Annex A 5/5 pages; Annex B 3/3; both Annex C copies 7/7; RDS-019 3/3. | Passed |
| APP-01 | Browser / RMS application | Not exercised | Not applicable to this document-only conversion | No application interaction or database mutation was needed. | Skipped — no app behavior changed. |

## Failures, decisions, fixes, and retests

- The first XML-reserialized Annex A output was rejected by Word because namespace declarations required by the original document were dropped. It was rebuilt with byte-preserving text substitutions, and Word then opened all five blank files successfully.
- Clearing Annex A's long entries initially reduced it from five to four pages. The process-flow image is anchored in the final empty paragraph; adding `pageBreakBefore` to that existing image paragraph kept the flow graphic on its original fifth page. Word then reported 5/5 pages for the source/output pair.

## Assistant-run checks

- Inspected text nodes from `word/document.xml`, headers, and footers in all five source DOCX files; inspected media relationships to distinguish institutional artwork/process-flow content from sample signatures.
- Compared source and output ZIP member sets. For each pair, only `word/document.xml` and explicitly identified signature image assets changed; all other uncompressed package members matched byte for byte.
- Audited normalized `word/document.xml` structures and package members. Paragraph, table, row, cell, section-property, and drawing counts match within each pair. The Annex A blank copy has one explicit `pageBreakBefore` on the existing process-flow image paragraph to retain the source's fifth-page flow; no form field or text was added.
- Checked targeted source values are cleared and confirmed the signature PNGs have fully transparent pixels. The presentation request signature JPEG was replaced with a same-dimension white JPEG.
- Word read-only open and repagination succeeded for all ten DOCX files, with matching page counts. No screenshot or pixel-level page comparison was performed. No PHP, database, or application tests were relevant; no database changes occurred.

## Resume / handoff

- Last user-confirmed state: Not applicable; no browser step was required.
- Next pending action: None for this conversion. Field mappings are in `templates/earist/blank/FIELD-MAPPINGS.md`.
- Blocked/skipped checks and remaining verification gaps: No pixel-level page comparison was performed. Before a generator chooses one Annex C variant, confirm which source copy is canonical. Confirm whether Annex A's documentary requirement marks are institutional defaults.
- User-performed data changes and cleanup status: None.
