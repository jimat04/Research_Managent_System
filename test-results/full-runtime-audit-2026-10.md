# RMS Full Runtime Audit

- **Date:** 2026-10-10
- **Target:** Local XAMPP app at `http://localhost/rms/`; database `rms_db`
- **Scope:** Live MySQL state, authenticated browser flows, crafted requests, upload access, navigation, and the existing Playwright suite
- **Code changes:** None. Audit-created user, project, chapters, versions, review records, and copied files were removed. Staff1's original all-view grants were restored.

## Verdicts

| Area | Verdict | Summary |
|---|---|---|
| 1. Database state | **FAIL** | Migration ledger differs from the requested 003 expectation; one active faculty account has no academic rank. Other checked integrity/enumeration/index checks passed. |
| 2. Fifteen module sub-pages | **PASS** | All 15 destinations rendered for Admin and staff1; staff view/manage controls matched the effective grants exercised. No PHP warning/fatal text or route errors. |
| 3. Researcher workflow loop | **PASS** | Faculty title and Chapter 1 return/revision/resubmission/approval completed; Chapter 2 unlocked. All test data was removed. |
| 4. Permission negative tests | **PASS** | View-only staff decision attempts were rejected; student-only forbidden routes returned 403; submitted title/chapter edits were unchanged by crafted POSTs. |
| 5. Upload/download security | **PARTIAL** | Direct URLs to test PDFs in all eight upload folders returned 403. The prior E2E report verifies authorized download (200). Crafted invalid uploads created no rows, but explicit server-side rejection messages were not captured. |
| 6. Navigation sweep | **PARTIAL** | 72 unique sidebar routes returned HTTP 200 without visible PHP errors or 404s. Publication hub links drop launcher context on “Clear” and “Open Repository.” |
| 7. Playwright suite | **FAIL** | 8 passed, 2 failed, 3 skipped. Both failures are described below; serial execution skipped the three remaining workflow tests. |

## 1. Database State

**Migration ledger: FAIL against requested expectation.** `schema_migrations` contains applied records for migrations 013 through 020. `003_add_email_verification.sql` is recorded `applied` with `verification_method='executed'`, rather than unapplied. This agrees with the current reconciliation document, which says 003 was applied to the local database. `010_demo_accounts_resync.sql` has no ledger row; `docs/MIGRATION_003_010_RECONCILIATION.md` says it remains `needs-verifier` and was not executed.

**Integrity: PARTIAL.** There are zero users with empty role or status. Nine users have `academic_rank IS NULL`; most are roles for which rank is not applicable, but one active faculty account is affected: `jreyes@rms.edu.ph`. Chapters without projects: 0. `research_submission_versions` with missing project, non-null missing chapter, or missing submitter: 0 each.

**Status enum reality: PASS.** Live values observed:

- `research_projects`: `archived`, `ongoing`, `submitted`, `title_approved`
- `chapters`: `approved`, `submitted`
- `publications`: `published`
- `copyright_applications`: `registered`, `rejected`

Each observed value is declared by that column's enum, and empty-string counts were 0 for all four tables.

**Indexes: PASS.** Duplicate non-unique index-definition pair count: 0.

## 2. The 15 Module Sub-Pages

All 15 launcher destinations were opened as Admin and as staff1, for 30 successful route checks. Each returned HTTP 200, displayed its expected module content/filter, had outgoing links, and showed no PHP warning/fatal/SQL exception text.

Staff1's live Users-page grants were all `view` at baseline across the five modules and 15 sub-pages. Under that grant set, review decision controls and other manage-only controls were absent. A temporary manage grant pass confirmed expected controls on activity, publication, copyright, and research-review pages; the review grant showed “Return for Revision” but not “Approve” because `research_approval` remained view-only. Grants were restored afterward.

The journal publication route displayed its one matching journal record. The conference route displayed the correct empty state because the live database contains no conference publication. Both pages use the generic heading “Publication record,” while their filters/content distinguish the requested module.

## 3. Researcher Workflow Loop

A temporary faculty account created audit project `41` (`RMS Audit Flow 1791635126930 Faculty Title`). Observed sequence:

1. Saved as draft, edited while draft, and submitted; DB recorded title version 1.
2. The edit page showed “Editing is locked.” A crafted POST after submission left the project title, submitted version, snapshot abstract, and version count unchanged.
3. Staff1, temporarily granted `research_proposals=manage` and `research_review=manage`, returned the title with comments. DB recorded `for_revision`, version 1 `returned`, and the exact comment.
4. Faculty read the comment in research-detail review history, revised, and resubmitted. DB showed version 1 returned and version 2 submitted.
5. Admin approved version 2. The project became `title_approved`; Chapter 1 was available.
6. Faculty submitted Chapter 1. Staff1 returned it with comments; faculty saw feedback, revised, and resubmitted. Chapter version 1 remained returned and version 2 was submitted.
7. Admin approved Chapter 1. The picker showed Chapter 1 approved and Chapter 2 open; Chapters 3–5 remained locked.

The project, review/version rows, upload, and temporary faculty account were deleted after verification. Post-cleanup checks found zero audit project/version/account/file rows.

## 4. Permission Negative Tests

**View-only staff:** With staff1 at view-only, the review card had no Approve or Return buttons. Crafted POSTs for both `returned` and `approved`, using a valid session CSRF token, returned the application message “You are not authorized.” The submitted version remained unchanged and no review rows were created.

**Student route denial:** As `jdelacruz@rms.edu.ph`, the review queue and implementation outputs returned HTTP 403 `Access denied`. All six current `pages/staff/*.php` routes (`contact-messages`, `document-verification`, `staff-dashboard`, `staff-defense`, `staff-milestones`, and `staff-revisions`) returned HTTP 403 via the 403 page. None returned a 500 or visible PHP warning/fatal.

**Submitted researcher edits:** Crafted title and Chapter 1 POSTs returned HTTP 200 but did not alter submitted state/content or increase the version count. The title's submitted snapshot stayed unchanged; Chapter 1 remained submitted with its original background and one version.

## 5. Upload/Download Security

A uniquely marked PDF was temporarily placed in each of `uploads/proposals`, `chapters`, `defense`, `manuscripts`, `copyright`, `copyrights`, `publications`, and `milestones`. Direct GETs for all eight URLs returned HTTP 403; Apache logged `client denied by server configuration`. All copied files were removed.

The prior [E2E report](e2e-browser-test-report.md) records direct proposal-path denial (403) and authorized download-handler success (200); that authorized-handler scenario was not repeated in this audit.

Multipart requests containing a `.php` file and a 10 MiB + 1 byte PDF were sent directly to the researcher title form, bypassing browser file-input validation. Both returned HTTP 200 to the form, no PHP warning/fatal text appeared, and post-request DB checks found no project or upload rows for either attempt. The exact validation alert was not retained, so server-side rejection is supported by non-persistence but not conclusively evidenced by a rejection response; this remains PARTIAL.

## 6. Navigation Sweep

Authenticated sidebar link crawls found no 404, navigation failure, or visible PHP warning/fatal across:

- Admin: 29 links, 28 unique routes; all 28 returned HTTP 200.
- Staff1: 24 links, 22 unique routes; all 22 returned HTTP 200.
- Faculty: 13 links, 11 unique routes; all 11 returned HTTP 200.
- Student: 13 links, 11 unique routes; all 11 returned HTTP 200.

The implementation-outputs copyrights link reaches the registered records hub with `module_page=research_outputs`; that hub's “Back to research outputs” link returns to the originating outputs view. Publication module pages preserve their initial `module_page`, but the “Clear” and “Open Repository” links omit it, losing the originating launcher context. No route failure resulted.

## 7. Existing Playwright Suite

Ran the complete suite once: **8 passed, 2 failed, 3 skipped**. Failures:

1. `configured admin account loads the dashboard and all 15 research destinations` expected the `ongoing_research` launcher to resolve to `/pages/admin/admin-research.php`; the live launcher resolves to `/pages/shared/implementation-outputs.php?view=ongoing&module_page=ongoing_research` (HTTP 200). This is a stale test target expectation, not a runtime 404.
2. `title drafts remain editable, submitted versions lock, and title approval unlocks Chapter 1` failed waiting for the draft-edit success text `/updated successfully|changes saved/i`. The rendered page showed the edited abstract but no matching confirmation text. The remaining three serial workflow tests were skipped after this failure.

The suite's post-run marker cleanup was verified: zero matching E2E users/projects/activities/publications/copyrights/chapters/uploads remained. Generated screenshots/results from the run were removed, and the tracked Playwright `.last-run.json` was restored to its clean pre-audit state.

## Prioritized Follow-Up

1. Reconcile the expected migration state for 003 with the actual `applied/executed` ledger row and documentation; decide whether the stated “unapplied” expectation is stale. Keep 010 explicitly unresolved until its verifier passes.
2. Resolve the active faculty account's missing `academic_rank` (`jreyes@rms.edu.ph`) or confirm NULL is an allowed account state.
3. Update the E2E launcher expectation for `ongoing_research` and correct the draft-save success assertion; rerun the suite so the three skipped serial tests execute.
4. Capture/validate an explicit server-side rejection response for disguised and oversized multipart uploads.
5. Preserve `module_page` in publication “Clear” and “Open Repository” links if those links are expected to return to their originating module context.
