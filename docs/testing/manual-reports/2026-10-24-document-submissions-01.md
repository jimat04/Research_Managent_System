# Verification: Document submissions

- Date/time: 2026-10-24 (Asia/Taipei)
- Assistant: GitHub Copilot
- Target and expected behavior: Proposal and chapter forms guide students to prepare documents externally and upload them; a blank editable DOCX starter is available for proposals.
- Environment URL: `http://localhost/rms/` (proposal submission page was reachable in the user's browser)
- Roles and disposable records/files: Student role; no records or files created for verification.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How the canonical proposal and chapter submission pages handle documents; selected `researcher/submit-research.php`, `researcher/submit-chapter.php`, `proposal document`, `chapter upload`.
- Query/lookup and result: Focused queries for the canonical proposal/chapter submission and upload workflow returned both researcher page nodes, `handleRmsUpload()`, and the shared file uploader, but results were truncated. Current source was checked directly.
- Relevant source locations and verified relationships: `pages/researcher/submit-research.php` handles title metadata, proposal-file validation, upload via `handleRmsUpload()`, and title submission version capture. `pages/researcher/submit-chapter.php` checks project ownership, eligibility, sequential chapter approval, submission locks, chapter uploads, and version capture; it now uses the existing chapter section labels as guidance.
- Missing, inferred, stale, or truncated context: Graphify output was truncated and its test nodes may not reflect current files. Direct source is the implementation authority.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| UI-1 | Student / canonical proposal submission page | User refreshed the page after the sentence removal and updated DOCX was in place | Essential project/team inputs remain; no separate abstract input; guidance requests only the research title and concise research description, without the removed sentence | User confirmed the refreshed page matches the expected final proposal guidance and link. | Passed |
| UI-2 | Student / proposal submission page | User clicked the DOCX download link | The supplied blank DOCX downloads | User confirmed the DOCX downloaded. | Passed |
| UI-3 | Student / canonical chapter submission route | User opens the route, selects a project, then selects a chapter | The normal project picker and chapter picker lead to the selected chapter page | User confirmed they chose a project, then a chapter, and the pages appeared. | Passed |
| UI-4 | Student / canonical chapter form for an available project and chapter | User inspects the currently selected chapter form | Chapter-specific notes appear instead of text-entry fields; chapter DOCX starter link and upload control appear | User confirmed the heading, section guidance, DOCX link, and uploader are visible, and confirmed there are no chapter text-entry fields. | Passed |
| UI-5 | Student / unlocked chapter form | User clicked Submit for Review without selecting a file | The page reports that a completed chapter document is required; no submission is saved | User observed: “Upload the completed chapter document before submitting for review.” The handler checks errors before opening the transaction. | Passed |
| UI-6 | Student / chapter form | User clicked the chapter DOCX download link | The supplied blank chapter DOCX downloads | User confirmed the supplied chapter DOCX downloaded. | Passed |

## Failures, decisions, fixes, and retests
- User confirmed the initial proposal should contain only the research title and a concise research description, and requested removal of the sentence about later chapter content. The user updated the DOCX at the existing page link target; no new folder is needed.
- User requested a chapter DOCX starter link. `public/assets/research-chapter-template.docx` was found, validated, and linked from the chapter guidance.
- User confirmed the project picker leads to the chapter picker and selected chapter page.

## Assistant-run checks
- `php -l pages\researcher\submit-research.php` — passed after the latest sentence removal; no syntax errors.
- `php -l pages\researcher\submit-chapter.php` — passed; no syntax errors.
- DOCX package validation with Python `zipfile.testzip()` and XML parsing — passed for the user's blank `public/assets/research-proposal-template.docx` starter (3,124,689 bytes); it has no macros, OLE binary parts, or external relationships.
- DOCX package validation with Python `zipfile.testzip()` and XML relationship parsing — passed for `public/assets/research-chapter-template.docx`; no macros, OLE binary parts, or external relationships were found. It is byte-identical to the supplied proposal starter.
- Final `php -l` checks passed for both canonical pages after adding the chapter template link.
- `git diff --check` passed. Both DOCX files are explicitly unignored for version control.
- Focused search for removed abstract/chapter text inputs and the former content-or-file submit message — no matches found in the two canonical pages.
- `git diff --check` — passed. `git check-ignore` found the DOCX asset is not ignored after adding its specific `.gitignore` exception.
- No database changes were made by the assistant. The user's missing-file test was rejected before transaction start.
- The assistant did not operate the browser; all browser observations above are user-confirmed.

## Resume / handoff
- Last user-confirmed state: Proposal and chapter guidance, both DOCX downloads, chapter pickers, absence of chapter text inputs, and missing-file validation were confirmed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: User did not open the downloaded DOCX in a word processor or perform a valid document upload/submission. Successful submission and database persistence were not exercised.
- User-performed data changes and cleanup status: None.
