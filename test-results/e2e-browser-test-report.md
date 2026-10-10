# Research Management System — End-to-End Browser Test Report

- **Date:** 2026-10-10
- **Target:** `http://localhost/rms/` using the local Apache application and `rms_db`
- **Browser:** Headless Chromium through Playwright
- **Result:** **11 PASS, 2 NOT TESTED, 0 FAIL** (Playwright: 11 passed, 2 skipped)

## Adviser-approved title and chapter workflow

| Scenario | Result | Evidence |
|---|---|---|
| Student saves a title draft without creating an official submission version | PASS | Browser flow and database state asserted |
| Student edits a draft before submission | PASS | Updated abstract persisted |
| Student resubmits the title and the submitted version is preserved | PASS | Project status and title-version row asserted |
| Direct POST cannot edit the submitted title | PASS | Database title and version count remained unchanged |
| Submitted title attachment replacement and deletion are rejected | PASS | Upload endpoint rejected replacement; stored attachment count remained unchanged after crafted deletion |
| Research attachments cannot be downloaded directly from public proposal paths | PASS | Direct file request returned 403; authorized Administrator download handler returned 200 |
| Title review queue displays the submitted title; Administrator approval is recorded | PASS | Queue action and review/version/project rows asserted |
| Chapter 1 is unavailable before title approval, including a crafted direct POST | PASS | Locked page displayed and no chapter row was created |
| Chapter 1 draft can be saved and submitted | PASS | Chapter state and version rows asserted |
| Direct POST cannot edit a submitted chapter | PASS | Submitted content remained unchanged |
| Administrator returns Chapter 1 with comments; researcher resubmits | PASS | Returned feedback, version count, and subsequent state asserted |
| Chapter approval preserves both versions and unlocks Chapter 2 | PASS | Review/version counts and Chapter 2 availability asserted |
| Faculty can submit a research title and Chapter 1 through the shared interface | PASS | Faculty-owned title and submitted chapter/version rows asserted after title approval |

## Existing browser coverage

| Area | Result |
|---|---|
| Disposable registration, verification, duplicate registration, login/logout, and protected-route checks | PASS |
| Student denial of staff/admin pages | PASS |
| Admin dashboard and all 15 research destinations | PASS |
| Seminars, trainings, and workshops: recipient registration, edit persistence, and no duplicate notifications | PASS |
| Journal/conference publications and repository project relationships | PASS |
| Copyright, patent, utility-model, and innovation records with project/publication relationships | PASS |
| Staff view/manage permission checks on existing modules | PASS |
| Configured standalone staff-dashboard account test | NOT TESTED — no dedicated staff credentials supplied |
| Legacy CREC/EREC and milestone lifecycle test | NOT TESTED — outside the requested title/chapter workflow |

## Not tested

- Faculty title draft/edit/resubmission, faculty returned-title response, faculty chapter revision cycle, and faculty review-history visibility.
- Staff decisions through the new queue, including review-versus-approval permission separation and revoked permissions.
- Returned title revision/resubmission and preservation of multiple title review decisions.
- Chapter file upload replacement/removal, invalid file types, and oversized files.
- Chapter 2 submission and later chapter sequencing beyond confirming Chapter 2 unlocks after Chapter 1 approval.
- SMTP delivery and real email receipt; notifications were not used as evidence for mail delivery.
- Production deployment, external mail providers, and non-local databases.

## Safety and cleanup

- The browser run used dedicated marker-prefixed workflow records and temporary test accounts; no administrator password was changed.
- Post-run database checks found **0** marker-prefixed research projects, users, activities, or uploads.
- Migration `010_demo_accounts_resync.sql` remains `needs-verifier` and was not executed.
