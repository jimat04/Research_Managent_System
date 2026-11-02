# Verification: title proposal guidance

- Date/time: 2026-10-25, Asia/Taipei
- Assistant: GitHub Copilot
- Target and expected behavior: The researcher submission page clearly shows the two title-proposal requirements, a prominent DOCX download button, and the PDF-only upload instruction.
- Environment URL: http://localhost/rms/pages/researcher/submit-research.php
- Roles and disposable records/files: Student role; no records or files changed.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How does the title submission page relate to researcher submissions and uploads? Selected terms: `title`, `submission`, `researcher`, `upload`.
- Query/lookup and result: `graphify query "title submission researcher upload" --budget 1500`; the existing graph returned the `researcher/submit-research.php` node and related researcher submission/upload nodes. The complete result exceeded the requested token budget; all 34 nodes and 29 edges were returned.
- Relevant source locations and verified relationships: The graph places `researcher/submit-research.php` in the researcher submission workflow. Current source confirms the proposal uploader accepts PDF only and the DOCX template is linked from the proposal section.
- Missing, inferred, stale, or truncated context: The graph does not verify the rendered layout or download behavior. Some adjacent document nodes describe exported reports rather than the live page; current source was used as the authority.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Assistant static check | Run `php -l pages/researcher/submit-research.php` | PHP parses without errors | `No syntax errors detected` | Passed |
| 2 | Assistant static check | Confirm template asset and markup reference | DOCX exists; download link retains `download`; the helper explains PDF-only upload | `public/assets/research-proposal-template.docx` exists; page source contains the download link, two requirements, and PDF instruction | Passed |
| 3 | Student browser check / submission page | Refresh or open the submission page | Guidance is stacked and readable, with both requirements and a prominent DOCX button visible | Student confirmed the updated guidance and button are visible | Passed |
| 4 | Student browser check / submission page | After check 3, use the DOCX download button | The editable DOCX template downloads | Student confirmed the editable DOCX downloaded successfully | Passed |
