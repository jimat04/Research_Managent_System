# EARIST template field mappings to RMS

## Phase 3 implemented mapping source

The current mapping implementation uses **faculty_research_records and its saved author/SDG children**, not the historical project/publication mappings below. See [EARIST document mapping](../../../docs/EARIST_DOCUMENT_MAPPING.md), [central definitions](../../../config/earist-documents.php) and the [structural/hash manifest](../../../config/earist-template-bindings.json). Phase 3 is a read-only mapping review, not DOCX generation. No supplied DOCX file was modified.

- All eight blank copies are primary references. Mabansag Annex C is provisionally selected; Liwanag remains retained and checked. Both are one document type. Visual verification is required before rendering.
- **Correction to older notes below:** Annex A currently contains four checked documentary-requirement glyphs. They are preserved template states, never evidence of supplied attachments. Temporary checklist answers start unconfirmed; an approved Phase 4 output policy is required before using those checkboxes.
- QSF-RDS-019 has destinations only for SDG 8/9. Other selected SDGs and Others remain in the full source dataset and generate a visible warning; no new destinations are invented.
- **Correction:** Annex F has a header plus seven blank review-signatory rows, not eight. Research authors do not become review signatories.
- The manifest records 70 guarded structural regions, including mixed text/checkbox cells and repeatable author/AI regions. These are preparation, not complete run-editing instructions or a writer.
- Annex D is Admin-only metadata; no active Admin reader/generator exists. Official scores, clearance results, approvals and signatures have no authorized data source and remain blank.
- No database migrations or saved document-specific answers were added. Future drafts should use record ID + document type + document version. All pre-existing templates, profile and lifecycle helpers remain unchanged.

## Faculty reusable-record source (Migration 050)

The original project-based mappings below describe the title/chapter workflow. Faculty reusable document records now have a separate source; **do not select it by matching a research title or join unrelated projects/publications by title**. No document generation is implemented in this phase, and template files are unchanged.

| Future document input | Faculty-record source / rule |
|---|---|
| Selected research identity | `faculty_research_records.research_record_id`; the faculty owner-scoped loader validates `owner_id` against the authenticated account. An Admin reader must be separately authorized in a future phase. |
| Research title and scholarly-work type | `title`, `work_type`, `work_type_label`, `work_type_other`. Configurable initial types reuse RMS labels; they are not a verified official EARIST taxonomy. Type is independent of submission targets. |
| Research dates (Annex B) | Nullable `date_started`, `date_completed` in `YYYY-MM`; never substitute `created_at` / `updated_at` or invent a day. |
| Authors (Annexes A/B/C/E/F and QSF-RDS-019) | `faculty_research_record_authors`: saved `full_name`, `position`, `affiliation`, `author_role`, ordered by `display_order`. Optional account linkage is not an access grant. Never replace snapshots with current profile data without an explicit author edit. |
| SDGs (Annex A, QSF-RDS-019) | `faculty_research_record_sdgs.sdg_number` (1–17) and parent `sdg_other`. Respect the selected template's printed fields; do not force all choices into a template with fewer printed boxes. |
| Conference | Parent `conference_title`, `conference_host`, `conference_organizer`, `conference_classification`, `conference_start_date`, `conference_end_date`, `presentation_modality`, `conference_venue`. Host/organizer/venue remain separate. |
| Conference classification caveat | Stored categories follow Annex A: Regional/National/Local Conference or International Conference. QSF-RDS-019 uses different wording, including conferences held in the Philippines; future generation must confirm the appropriate document-specific selection, not infer it from this enum or venue text. |
| Journal | Parent `journal_title`, `journal_publisher`; one optional target in this phase. |
| Applicant/profile (when appropriate) | Existing `users` first/middle/last names, rank, designation, appointment status, department, email and contact, plus `rmsSetting('institutional_affiliation')`. Applicant/presenter is not necessarily the owner or lead author; confirm document roles in the future generation flow. |
| Annex D | Admin-only future generation; no faculty generator, Admin reader or permission change is added here. Existing review scores are not an approved final certificate score. |
| Other document-specific values and signatures | Still collected/confirmed separately in a future generation phase. Author, presenter, reviewer, evaluator, issuer and signatory remain distinct roles. |

Active/archived records retain the same IDs; soft deletion retains all information and restoration returns the previous state. Future immutable document versions must survive edits and lifecycle actions. See [faculty-record architecture](../../../docs/FACULTY_RESEARCH_RECORDS.md) for multiple-target migration compatibility and future history requirements.

The two Annex C files are treated as copies of one **Quality Evaluation Sheet** template type. Two cleared copies are retained so each output keeps the exact structure and page layout of its corresponding source. Annex E and Annex F are separate additional template types. The original template-blanking work described below did not include document generation or database changes; Migration 050's separate faculty-record source is documented above.

## Annex A — Pre-Submission Clearance for Scholarly Works

| Form fields | RMS mapping | Coverage |
|---|---|---|
| Scholarly work title | `research_projects.title` | Direct |
| Author name and author order/role | `research_author_snapshots.author_name`, `display_order`, `author_role`; optionally linked to `users.first_name`, `middle_name`, `last_name` / `project_members` | Direct for stored identity, order and lead/member role |
| Author position, affiliation, contact and email | `users.academic_rank`, `designation`, `department`, `program`, `contact`, `email`; `institutional_affiliation` setting for internal EARIST authors | Partial; the schema has no per-project snapshot of position/contact/affiliation, and the EARIST default must not be used for external authors |
| Type of scholarly work, target journal/conference, publisher/organizer, publication status | `publications.publication_type`, `publication_type_name`, `journal_publisher`, `status`; `research_publication_tracking.journal_status`, `journal_reference` | Partial; no complete pre-submission clearance record or journal/conference metadata set |
| Conference dates, venue and organizer | `faculty_engagement_records.activity_date`, `location`, `organizer`, `title`, `research_project_id`; alternatively `research_activities` date-range, venue and organizer columns | Partial; `research_activities` has no project link, and faculty engagement stores one activity date |
| Conference modality, indexing, peer-review status, SDG alignment, scope alignment, registration/publication fees and call deadlines | No direct fields | No mapping |
| AI declaration, tools, purposes, prompts and usage extent | No direct fields | No mapping |
| College Research Project Head checklist, reference number and remarks | `project_reviews.reviewer_id`, `reviewed_at`, `comments`, `recommendation` may hold related review metadata | Partial only; its score fields and workflow do not represent this clearance checklist |

## Annex B — Authors Certification

| Form fields | RMS mapping | Coverage |
|---|---|---|
| Scholarly work title and author identity/order | `research_projects.title`; `research_author_snapshots.author_name`, `display_order`, `author_role`; `users.first_name`, `middle_name`, `last_name`; `project_members` | Direct for title, stored identity, order and lead/member role |
| Author affiliation and role | `research_author_snapshots.program`, `author_role`; `users.department`, `program`, `academic_rank`, `designation`; `institutional_affiliation` setting for internal authors | Partial; no project-specific position/affiliation snapshot |
| Research start and completion dates | No dedicated research lifecycle dates on `research_projects` | No mapping; system `created_at` / `updated_at` are not equivalent |
| Contribution taxonomy, narrative description and percentage | `publication_contributors.contribution` and `is_primary`; project author role fields | Partial at best; the schema does not store multiple CRediT roles, descriptions and percentages for a research project |
| Certification text, signatures and signed dates | No direct fields | No mapping |

## Annex C — Quality Evaluation Sheet (both copies)

| Form fields | RMS mapping | Coverage |
|---|---|---|
| Scholarly work title and author information | `research_projects.title`; `research_author_snapshots`; `users.first_name`, `middle_name`, `last_name`, `academic_rank`, `designation`, `department` / `project_members`; `institutional_affiliation` setting for internal authors | Direct or partial as above |
| Evaluator identity and evaluation date | `project_reviews.reviewer_id`, `reviewed_at` | Partial |
| Seven weighted ratings, criterion-level remarks, computed total and overall remark | `project_reviews` has scores for a different rubric plus one `comments` field | No semantically safe field-by-field mapping; do not map these ratings into the existing score columns |
| Evaluator position and signature | No direct fields | No mapping |
| Recommendation | `project_reviews.recommendation` | Partial; the Annex C recommendation matrix has different outcomes and thresholds |

## Annex D — Certification of Quality Review (College Research Evaluation Committee)

| Form fields | RMS mapping | Coverage |
|---|---|---|
| Scholarly work title | `research_projects.title` | Direct when tied to a research project |
| Author(s) Information (details not specified in the form) | `research_author_snapshots.author_name`; linked faculty may resolve through `users.first_name`, `middle_name`, `last_name` / `project_members` | Direct for author names where stored. The form does not label position or affiliation fields, so do not add or populate those based on inference. |
| Final quality review score out of 100 | `project_reviews` criterion scores are a possible related source | Ambiguous; scores are stored per reviewer across six criteria, while Annex D requests a final quality-review score. The schema has no final aggregate score or confirmed aggregation rule. Do not calculate or map automatically without an approved rule. |
| Final rating band (five score-based checkbox options) | `project_reviews.recommendation` | No safe mapping; RMS stores `approve`, `revise`, or `reject`, which does not equal Annex D's five quality-rating bands. The printed score thresholds are fixed guidance. |
| Issuance date and place | No direct field | No mapping; `project_reviews.reviewed_at` is an individual review timestamp, not the certificate issuance date/place. |
| Prepared-by / attested-by names, roles, and signatures | Printed approver names and titles in the supplied form | The printed names and roles are fixed institutional content. Signature lines are placeholders; RMS has no signature record for this certificate. |

### Annex D blank-form inspection

- The supplied Annex D file is already blank. Its title and author cells are empty; the score line and issuance date/place are blank placeholders; all five rating checkboxes are unchecked.
- Certification language, rating thresholds and explanations, printed approver names **Dr. Nancy G. Liwanag** and **Dr. Joselito D. Mabansag**, their printed titles, and institutional header/footer content are fixed and must remain.
- The supplied file was not modified. Its embedded images are institutional marks/logos plus a 1x1-pixel image; no sample signature image was found.

## EARIST-QSF-RDS-019 — Request for Research Paper Presentation

| Form fields | RMS mapping | Coverage |
|---|---|---|
| Applicant name, position, department, contact and email | `users.first_name`, `middle_name`, `last_name`, `academic_rank`, `designation`, `appointment_status`, `department`, `program`, `contact`, `email`; `institutional_affiliation` setting | Partial; applicant is not necessarily every author, and affiliation is not stored per project |
| Paper title and authors | `research_projects.title`; `research_author_snapshots`; `users.first_name`, `middle_name`, `last_name`; `project_members` | Direct or partial as above |
| Conference/event title, date, organizer, venue and presentation role | `faculty_engagement_records.title`, `activity_date`, `organizer`, `location`, `participation_role`, `research_project_id`; `research_activities` also has date ranges | Partial; no single record covers all form fields and acceptance/submission milestones |
| Abstract submitted/accepted dates, endorsement, funding request, modality and indexed status | No direct field set; `research_publication_tracking` stores only broad colloquium/journal status and date | No direct mapping |
| Registration/travel estimates | `activity_budgets` / `activity_budget_expenses` have event budget and expense fields | Partial only; these are not a paper-presentation request record |
| Post-event checklist and signatures | Upload records can retain files, but no corresponding checklist/signature fields | No direct mapping |

## Annex E — Intellectual Property Asset Evaluation Clearance (ITSO)

| Form fields | RMS mapping | Coverage |
|---|---|---|
| Scholarly work title | `research_projects.title` | Direct when the form is tied to a research project |
| Author names | `research_author_snapshots.author_name`; linked RMS faculty may also resolve through `users` / `project_members` | Direct for stored project author names; account linkage is not guaranteed for external co-authors |
| Author position | `users.academic_rank`, `users.designation` | Partial; `research_author_snapshots` has no position snapshot. Exact rank labels such as “Assistant Professor I” may need to be stored in designation rather than normalized academic rank. |
| Author affiliation | `users.department` and the configurable `institutional_affiliation` application setting for internal EARIST authors | Partial; no per-author, per-project affiliation snapshot exists. Do not apply the EARIST default to external co-authors. |
| ITSO evaluation result (three mutually distinct clearance statements) | No direct field | No mapping; `copyright_applications.status` describes a copyright application workflow and is not this IP asset evaluation decision. |
| Issuance date and place | No direct field | No mapping; these are issuance details and should be entered/confirmed for this clearance rather than inferred from project timestamps or contact-address settings. |
| Prepared-by / attested-by signatures | No direct signature field | The printed roles “ITSO Patent Searcher” and “Director, ORDS” are fixed template content. The signature lines are blank placeholders. |

## Annex F — Conflict of Interest and Confidentiality Declaration

| Form fields | RMS mapping | Coverage |
|---|---|---|
| Scholarly work title | `research_projects.title` | Direct when tied to a research project |
| Author names | `research_author_snapshots.author_name`; linked RMS faculty may also resolve through `users` / `project_members` | Direct for stored project author names; account linkage is not guaranteed for external co-authors |
| Author position | `users.academic_rank`, `users.designation` | Partial; the author snapshot does not store position, and exact labels may differ from the academic-rank enum. |
| Author affiliation | `users.department` and the configurable `institutional_affiliation` setting for internal EARIST authors | Partial; no per-author, per-project affiliation snapshot exists. Do not apply the EARIST default to external co-authors. |
| Certification signatory name | `users.first_name`, `middle_name`, `last_name`; a reviewer can sometimes be associated through `project_reviews.reviewer_id` | Partial only; the signatory list is not stored as a certification record, and not every participant is necessarily a RMS reviewer. |
| Position / role in the review | No direct field | Do not substitute `users.role` or academic rank; those represent account role and employment rank, not a person's assignment in this review. |
| Signature and date for each signatory | No direct field | No mapping; signatures and individual certification dates are absent from RMS. |
| Conflict-of-interest and confidentiality attestations | No direct field | The declarations are fixed printed statements; the form has no filled response or disclosure detail to map. |

## Repeated inputs across the seven template types

The two Annex C source files remain one template type. “Yes” means the form requests that information; it does not mean each form uses the same person or the same workflow role.

| Common input | Annex A | Annex B | Annex C | Annex D | QSF-RDS-019 | Annex E | Annex F | Suggested RMS field / caveat |
|---|---|---|---|---|---|---|---|
| Scholarly work / research title | Yes | Yes | Yes | Yes | Yes | Yes | Yes | `research_projects.title` |
| Research author names | Yes | Yes | Yes | Yes | Yes | Yes | Yes | `research_author_snapshots.author_name`; linked accounts via `users` / `project_members` when available |
| Author position | Yes | Yes | Yes | Not specified | Applicant position only; not necessarily every author | Yes | Yes | `users.academic_rank` and `users.designation`; no historical project-author position snapshot |
| Author affiliation | Yes | Yes | Yes | Not specified | Applicant department/affiliation only; not necessarily every author | Yes | Yes | `users.department` plus `institutional_affiliation` for internal EARIST authors; no per-author affiliation snapshot |
| Signature / sign-off fields (different roles) | Yes | Yes | Yes | Yes | Yes | Yes | Yes | No shared RMS mapping; keep author, evaluator, approver, issuer, and conflict-declaration signatories separate, with their own dates and signature handling |

## Annex E/F document-specific inputs

| Document | Inputs to request only when that document is selected | Fixed or already-blank content to preserve |
|---|---|---|
| Annex E | Selected ITSO evaluation outcome; issuance date and place; authorized preparer/attester sign-off if entered at generation time | Three outcome statements and empty checkboxes; printed “ITSO Patent Searcher” and “Director, ORDS” roles; blank signature lines |
| Annex F | Each conflict/confidentiality signatory's name, position or review role, signature, and date (up to the eight existing rows) | All printed certification declarations; blank signatory table; no conflict response checkbox or free-text disclosure field is present |

## Annex E/F cleared values and preserved blank fields

- Cleared from each new blank copy: the populated scholarly-work title and the two populated author name/position/affiliation entries.
- Annex E's three evaluation-result boxes were already unchecked and remain as printed options. Its issuance date/place and preparer/attester signature lines were already blank and remain placeholders.
- Annex F's eight-row certification signatory table was already blank. Its names, review roles, signatures, and dates remain empty placeholders. The fixed conflict/confidentiality declarations remain unchanged.
- The letterhead, institutional seals/logos, ITSO and ORDS labels, form title, tables, checkboxes, signature lines, headers, footers, and all other package parts are preserved from the corresponding originals.
- For each source/output pair, package comparison found only `word/document.xml` changed. Within that part, only the three populated values (title and two author entries) were removed; all other DOCX package entries are byte-identical.
- Annex D is an already-blank supplied file and was inspected only; it was not altered or copied over.

## Review notes before generation work

- RMS has no complete record for Annex A clearances, Annex B contribution certifications, Annex C's seven-criterion rubric, Annex D's final aggregate rating/issuance/sign-off record, Annex E's ITSO IP asset evaluation clearance, Annex F's conflict/confidentiality signatories, or EARIST-QSF-RDS-019 requests. Several fields therefore have no safe existing database source.
- Do not infer a field from similarly named but differently defined data (for example, do not substitute `research_areas` for SDG alignment, or the existing `project_reviews` score columns for Annex C's rubric).
- The source Annex C copies differ in completed evaluation content, signature media, and a page-break detail. Both cleared files are kept as one template type; confirm the institution's canonical copy before a future generator selects one.
- Annex A's four checked marks under “Documentary Requirements” were treated as completed-form selections and reset to empty boxes. Confirm whether these are intended as pre-checked institutional defaults before automatic generation.
