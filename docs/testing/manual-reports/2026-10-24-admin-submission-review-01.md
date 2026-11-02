# Verification: Admin submission review

- Date/time: 2026-10-24 (Asia/Taipei)
- Assistant: GitHub Copilot
- Target and expected behavior: Admin/staff review displays key submitter and submission information, the uploaded proposal/chapter/final manuscript first, and only non-empty snapshot details; review history and authorized decisions remain available.
- Environment URL: `http://localhost/rms/` (admin Research Proposals and Research Review pages reached)
- Roles and disposable records/files: Authorized admin reviewer; inspect existing records only. No credentials or records supplied in chat.
- Session state: In progress for student document-viewer return navigation; chapter/final-manuscript verification remains blocked by empty queue

## Graphify and source context
- Question and selected graph vocabulary: How title, chapter, and final manuscript versions reach authorized reviewer views; selected `researcher/submit-research.php`, `researcher/submit-chapter.php`, `final_manuscript`, `research-submission-review`, `handleRmsUpload`.
- Query/lookup and result: `graphify query "research submission review title chapter final manuscript administrator staff queue" --budget 1200` resolved the submission-review concepts but was truncated. A narrower query connected `researcher/submit-chapter.php`, `research-submission-review.php`, `file-uploader.php`, and `handleRmsUpload()`; output remained truncated. Current source was verified directly.
- Sidebar-removal lookup: Expanded to `research submission review` from the graph's labels (`research-submission-review.php`, `Research Submission Reviews`, and related review nodes). `graphify query "research submission review" --budget 1000` returned the page node at `pages/shared/research-submission-review.php` (L1) and related submission workflow nodes; results were truncated at 23 of 29 nodes. Current markup and styles were verified directly.
- Collapsed-card and oldest-first lookup: Expanded to `research submission review` from graph labels `research-submission-review.php`, `Review History`, and `Submission Versions and Reviews`. `graphify query "research submission review" --budget 1000` returned the review page and version/review model; direct source confirmed the queue query and summary are in the shared page.
- Missing-project investigation: Expanded to `research submission review versions projects` from graph labels `Submission Versions and Reviews`, `research-submission-review.php`, and `research_projects`. Graphify confirms the shared review page and version tables are related; current PHP/SQL was checked directly. Migration 017 creates version/review/upload tables but contains no project-version backfill.
- Document return navigation: Expanded to `document preview dashboard navigation research review` using tokens present in the graph vocabulary. Graphify returned the document preview helper and research-review concepts; direct source inspection confirmed the viewer had a dashboard-only back link and the review page opens research documents in a new tab.
- Student return navigation: Expanded to `document preview researcher student research dashboard navigation` using tokens present in the graph vocabulary. Graphify returned the student shell and researcher workflow nodes; current source confirms `pages/researcher/my-research.php` is the canonical student page and the student-specific route redirects there.
- Relevant source locations and verified relationships: `pages/shared/research-submission-review.php` serves the admin and authorized-staff Research Proposals, Research Review, and Research Approval views. The shared queue loads latest version snapshots and linked uploads, shows PDF previews, history, and decision controls. `includes/research-workflow.php` validates review permissions, latest-version status, final-manuscript chapter-approval prerequisites, and records decisions.
- Missing, inferred, stale, or truncated context: Graph results were truncated; direct source is authoritative. The target `research-submission-review.php` already had uncommitted changes before this task; existing changes including final-manuscript routing were preserved.

### Missing research projects
- Read-only database target: local `rms_db`; executed in a read-only transaction. No records or schema were changed.
- Matching projects:
  - Project #1, “The Effects of Online Learning on Student Performance” — active (`deleted_at` is null), project status `ongoing`, zero `research_submission_versions` rows.
  - Project #2, “The Awareness of Cybersecurity Practices Among College Students” — active (`deleted_at` is null), project status `ongoing`, zero `research_submission_versions` rows.
- Cause: Research Proposals filters to versioned `title` submissions, while Research Review and Research Approval filter versioned submissions by status. Their query starts from `research_submission_versions` and joins projects; records existing only in `research_projects` cannot appear there. Migration 017 creates version tables but does not backfill prior projects. The `Research Proposals` page description explicitly directs older project records to Research Management.
- Likely location: Admin Research Management (`pages/admin/admin-research.php`) has a title/proponent search across active project records and an all-status option. Browser confirmation pending.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| UI-1 | Admin / login page | User entered authorized credentials and clicked Sign In | Admin dashboard appears | User confirmed the admin dashboard appeared. | Passed |
| UI-2 | Admin / Research Proposals | User opened and refreshed the proposal review page | Abstract snapshot field is not shown | Initial observation: an abstract was visible. After the display fix, user refreshed and confirmed “it's gone.” | Passed |
| UI-3 | Admin / Research Proposals | User inspected a title submission's file/details/history area without opening or changing the record | Uploaded title-proposal document is clearly labeled before non-empty details; available history/controls remain | User confirmed version/status/date first, the labeled file link, useful details, history, and available controls below. | Passed |
| UI-4 | Admin / Research Review | User opened the page to inspect chapter/final-manuscript submissions | The page loads; if records exist, the correct chapter/final document is clearly labeled and available, empty chapter content is not rendered, and decision comments/history remain | User confirmed the page loaded and showed a clear empty state because no final-manuscript queue records exist. No chapter or final-manuscript record was available to verify document rendering or decision history. | Blocked — no records |
| UI-5 | Admin / Research Proposals | User refreshed the open review page after the sidebar removal | The queue-summary/recent-submissions/review-guide sidebar is gone, and the main review content remains visible | User confirmed the panel is gone and the review content remains. | Passed |
| UI-6 | Admin / Research Proposals | User refreshed after the collapsed-card/oldest-first update and opened the first card | Cards start collapsed; each summary names the actual submitter and shows submission date/time; earliest submission appears first; selecting “View details” opens the card | User confirmed all four behaviors. The display sort was then adjusted to preserve the newest-100 query limit while presenting those fetched entries oldest-first; this does not change ordering for the two records currently available. | Passed |
| UI-7 | Admin / Research Management | User searched for “The Effects of Online Learning on Student Performance” with all statuses selected | The active project record appears as ongoing | User confirmed the project appears as ongoing. | Passed |
| UI-8 | Admin / Research Management | User searched for “The Awareness of Cybersecurity Practices Among College Students” with all statuses selected | The active project record appears as ongoing | User confirmed the project appears as ongoing. | Passed |
| UI-9 | Admin / research submission document viewer | User opens a research document from Research Review and checks its back link | The link is labeled “Research Review” and returns to the review page, not the dashboard | User confirmed the return link now goes back to Research Review instead of the dashboard. | Passed |
| UI-10 | Student / research document viewer | Student opens a research document and checks its back link | The link is labeled “My Research” and returns to the canonical student research page, not the dashboard | Awaiting user-operated browser confirmation. | Pending |

## Failures, decisions, fixes, and retests
- User chose document-first review: key submission information and uploaded file first; keep reviewer history and decision controls; hide empty snapshot content and stale abstract messaging.
- Initial admin browser check showed an abstract in the proposal review details. User explicitly requested removing it from the admin view; source data and uploaded file remain unchanged.

## Assistant-run checks
- `php -l pages\shared\research-submission-review.php` — passed; no syntax errors.
- `php -l pages\shared\research-submission-review.php` after omitting abstract snapshots — passed; no syntax errors.
- Focused search confirms no abstract labels or outdated abstract guidance remain in the shared review page; the only occurrence is the explicit display-filter key.
- `php -l pages\shared\research-submission-review.php` after removing the review-guide sidebar — passed; no syntax errors.
- `git diff --check -- pages/shared/research-submission-review.php` — passed with no whitespace errors.
- Focused search confirms no sidebar markup, summary/recent-submission helpers, or related styles remain in the shared review page.
- `php -l pages\shared\research-submission-review.php` after adding submitter/date metadata and oldest-first ordering — passed; no syntax errors.
- Focused source checks confirm review cards render without the `open` attribute; summary metadata uses `submitted_by` for identity and includes a semantic date/time; duplicate submission time was removed from expanded details. The query retains the existing newest-100 limit, and the fetched entries are sorted oldest-first in memory.
- `php -l pages\shared\download-research-document.php` — passed; no syntax errors.
- `git diff --check -- pages/shared/download-research-document.php` — passed with no whitespace errors. The manual report is a new untracked file and is not covered by `git diff --check`.
- Focused source check confirms only student `research` document previews use the My Research destination; student non-research documents continue using the dashboard.
- Research submission document viewer now links administrators and authorized research staff back to Research Review; other document types and researcher roles retain their existing dashboard destination.
- Research submission document viewer now links students viewing research documents back to My Research; source checks passed and browser confirmation is pending.
- No database changes or reviewer decisions were performed by the assistant.
- Read-only database lookup confirmed both named active projects have zero versioned submission rows; target was verified as local `rms_db`. This identifies the reason for absence from version-based review queues without modifying any data.

## Resume / handoff
- Last user-confirmed state: Admin confirmed the document viewer’s return link goes back to Research Review instead of the dashboard.
- Latest source change: Research document viewer back link for students targets My Research.
- Next pending action: Student signs in, then opens My Documents and a research document to check the back link destination.
- Blocked/skipped checks and remaining verification gaps: Chapter and final-manuscript document rendering, comments, history, and decisions could not be browser-verified because no corresponding queue records were available. No decisions were made for verification.
- User-performed data changes and cleanup status: None.
