# Chapters 1 and 2: revision report

Prepared 20 October 2026 for the current local working tree.

## Deliverables

- `rms-chapter_1&2-revised.docx`: separate revised manuscript; original retained.
- `implementation-evidence.csv`: section/claim-to-source matrix with implementation limits and follow-up checks.
- `citation-audit.md`: disposition of all 26 original bibliography entries, the missing institutional reference, and added sources.
- `paragraph-changes.json`: exact before/after text for every replaced direct-body paragraph, indexed against the original DOCX. Tables and bibliography are described below; this is not Word Track Changes.
- `rms-chapters-revised-text.txt`: complete extracted textual companion for review without Word.
- `revision-validation.json`: actual package/consistency validation results.
- `implementation-source-hashes.json`: SHA-256 fingerprints of 29 cited implementation/reference files, checked again after generation.
- `word-layout-review.json`: records the unsuccessful Word rendering attempt separately from passed package checks.
- `revise_chapters.py`: reproducible standard-library builder, guarded by the original document's SHA-256 fingerprint.

## Implementation baseline

Repository HEAD at inspection: `9d996919f74f8c4e73d6fe77804146a9c2c7acb6`. Relevant PHP, configuration, and test files already had uncommitted edits. Claims are based on the **working-tree content inspected**, not solely that commit. The evidence matrix identifies the implementation paths. This work changes documentation artifacts only; it does not modify handlers, permissions, schema, accounts, or database data.

Original DOCX SHA-256:

`ae05d5ec6438175ca9290139c50c7aec1bdd5cd5845fdedef2939d4c2387ca31`

The original's unchanged hash is checked after generation. Headers, footers, styles, media, relationships, section properties, and other non-document/settings package parts are preserved. Paragraph formatting is reused; comparison and framework table text is replaced in the existing table structures. Legacy table minimum heights are removed to avoid unnecessary empty space, table headings are bold, and comparison rows are kept together with a repeating header. The revision is an evidence-aligned draft, not a certification that the study, institutional review, or acceptance evaluation is complete.

## Chapter 1 changes

- Align introduction, questions, objectives, scope, beneficiaries, and definitions with shared student/faculty title submission and Chapters 1–5.
- Separate faculty research/adviser guidance from formal administrator/authorized-staff decisions.
- Explain draft editing, submitted-version locks, snapshots, recommendations, and resubmission.
- Cover the five implemented module families while identifying title/chapter processing as the primary evaluation focus. Supporting modules need their own evaluated tasks before conclusions extend to them. The actual sample remains a methodology decision.
- Replace unverified automatic tracking-number and all-event email claims with project/version identifiers and inspected in-app notification behavior.
- Identify PHP/MySQLi and the custom frontend accurately; distinguish declared email dependency from workflow delivery.
- Treat manual-process bottlenecks, time savings, costs, and user acceptability as matters to investigate and measure.
- Distinguish internal patent/IP records from external filing and distinguish chapter-progress indicators from stored completion status.
- Correct the degree wording on the title page using the degree stated elsewhere in the original; preserve names and unverified front matter.

## Chapter 2 changes

- Replace unsupported study summaries with verified sources and clearly identify abstract-only evidence.
- Retain five foreign/foundational discussions and two verified Philippine studies; use separate local institutional-context and contribution discussions instead of inventing three additional local studies.
- Correct the Brooks/McCormack report title and the Venkatesh article year and UTAUT/TAM distinction.
- Use an explicitly identified 2016 global edition for Elmasri/Navathe and a 2020 global edition for Laudon/Laudon; limit claims to the publisher-supported concepts.
- Correct ISO reference to 2011, acknowledge the 2023 revision, retain the draft's five selected characteristics, and require consistent instrument/edition selection in the methodology.
- Replace Table 2.1 with qualified comparisons of UE RMS, DSpace, EPrints, Classroom, and the inspected EARIST RMS. Remove unsupported licensing-cost, superiority, and absent-feature claims.
- Revise all five tables: one comparison, one framework, and three IPO blocks. Label the framework Figure 2.1 and the IPO model Figure 2.2; complete the originally truncated conceptual-framework sentence.
- Separate acceptance constructs, logical architecture/access principles, and selected product-quality measures. Present evaluation as planned, not already validated.
- Rebuild the bibliography using the 16 sources actually used. Remove stale citation hyperlink fields from replaced references.

## Objective-to-evaluation mapping

| Objective | Required evidence / acceptance check | Current state |
|---|---|---|
| Document existing practice | Authorized interviews, observation, sample documents, and actual processing/retrieval baseline | Not supplied; revised as planned investigation |
| Align architecture and workflow | Role/action map, source-to-requirement matrix, and institutional validation | Source alignment completed; institutional validation pending |
| Provide title/chapter processing | Draft save, submit/lock, authorized decision, recommendations, resubmit, next-chapter eligibility, version/file history | Implemented paths inspected; runtime acceptance checks not run |
| Provide connected supporting records | Create/update/read and ownership/permission checks for activities, publications, and IP records; archive visibility checks | Relevant handlers/schema inspected; supporting-module evaluation tasks still needed |
| Verify correctness and access | Expected tasks, invalid inputs, unauthorized direct routes, cross-owner requests, CSRF rejection, persistence and locked-attachment checks | Proposed methodology; no new application test result claimed |
| Evaluate selected quality characteristics | Instrument tied to ISO/IEC 25010:2011; respondent task observations; technical measurements | Proposed; no respondent sample or results invented |
| Compare improvement | Comparable baseline/RMS tasks, timing definitions, conditions, sample, and limitations | Planned; ratings do not substitute for timing measurements |

## Implementation issue identified during documentation review

`pages/researcher/my-research.php` derives a five-chapter display percentage from approved chapter counts. `pages/shared/implementation-outputs.php` uses an aggregate for its completed listing that accepts any positive number of existing chapters when all those chapters are approved, or a stored completed/archived status. The public research archive filters stored completed/archived statuses. Consequently, a claim that approval of exactly five chapters consistently completes and archives a project across all views would overstate the inspected implementation.

The revised paper documents sequential chapters, computed **chapter progress**, and separate implementation/archive listings. A runtime and requirements review should reconcile completion classification before a stronger end-to-end completion claim is added. Application behavior was not changed as part of the document revision.

## Unresolved institutional and manuscript matters

1. Confirm the final evaluation scope and respondent sample. Documenting faculty and supporting modules does not prove that those users/modules were evaluated.
2. Supply evidence for present campus workflow, the degree of paper use, actual delays, record-loss experiences, and retrieval practices. The manual alone does not establish these facts.
3. Confirm the institutional manual's authenticity, current applicability, and applicability to undergraduate research. Any claim of OVPREIS Forms 1–6 equivalence needs a field-and-action mapping approved by the relevant office.
4. Confirm the ISO edition and questionnaire with the adviser; justify using a withdrawn historical model or explicitly redesign the instrument for a newer edition. No prior instrument or adviser decision was available for review.
5. Confirm the author's name order: the original title page uses **John James E. Formaran**, while its certification uses **James John E. Formaran**. Names were not guessed or silently standardized.
6. Complete the original abstract, acknowledgements, dedication, defense/approval details, dates, and institutional sign-off as appropriate. The original school year **2027** is preserved; it was not treated as a completed defense or evaluation date.
7. The original contents/table/figure/appendix lists contain manual placeholders and obsolete page references. They require final pagination after front matter and methodology decisions are complete. The new Figure 2.1/2.2 labels are in the body; the unfinished front-matter lists are not presented as final.
8. Obtain additional verified recent/local studies if required by the thesis handbook. The paper does not retain unsupported entries merely to maintain a study count.
9. Record actual deployment versions, environment, measurements, test results, and evaluation findings in the methodology/results chapters. These were not inferred from source inspection.

## Validation

Actual generation checks are recorded in `revision-validation.json`: source hash preserved; ZIP integrity; all XML/relationship parts parse; expected tables and replacements present; unsupported legacy study claims removed; and all package parts other than document/settings unchanged during generation.

Word rendering was attempted with macros and link updates disabled and a request to open the revised copy read-only. Automation did not return a pagination or PDF result and was stopped; no PDF was produced. Only the Word instance created for this rendering attempt was terminated after its process identity and creation time were checked; the pre-existing Word instance was left untouched. A subsequent bounded attempt failed because the Word automation object did not provide its window handle; it did not reach document opening. Details are in `word-layout-review.json`. Final rendered pagination, table wrapping, and page appearance remain unverified.

Package checks do not establish rendered layout quality. Application/runtime tests and database operations were not run for this documentation-only change. No PHP files changed, so PHP syntax tests were not applicable. No mutating E2E tests were executed against `rms_db` or another database.
