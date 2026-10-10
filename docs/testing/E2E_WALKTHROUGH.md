# End-to-End Lifecycle Walkthrough — RMS

**Purpose:** Run repeatable browser tests for core RMS workflows, then use the manual lifecycle checklist below for workflows not covered by automation.

**Historical manual run:** The eight-phase walkthrough was reported complete on 2026-09-06, but its notes and Bug Log record failures and follow-up fixes. Treat that report as a historical observation, not a current clean-pass result. Re-run the relevant checks after code or schema changes.

## Automated browser tests

The repeatable Playwright suite is in `tests/e2e-browser/`. It covers registration and email verification, login/logout and role protection, the admin research destinations, the research proposal/review/approval workflow, capacity-building activities, publications/repository and intellectual-property records, and staff permission enforcement.

The local `tests/e2e-browser/test-results/playwright-results.json` artifact currently records **12 passed, 0 skipped, 0 failed**. This is a previous local run, not a guarantee about the current machine or database; rerun the suite to verify current behavior. The automated suite does not replace the manual chapter, milestone, defense, and archive checks below.

### Requirements and run

- Use a local development RMS instance at `http://localhost/rms/` (or set `RMS_E2E_BASE_URL`).
- Start Apache and MySQL, import the current `database/schema/rms_db.sql` plus the required migrations, and ensure the app can connect.
- Install Node.js/npm and make the PHP CLI callable as `php` (the suite runs a scoped data-cleanup helper).
- Use a local/test database only. The suite creates disposable student, faculty, and staff accounts and test records, then cleans up records bearing its unique test marker. Do not point it at production or a database containing real records that match the `RMS E2E` marker.
- Supply a password for generated test accounts and credentials for an authorized local admin and staff account. Keep these values in the current PowerShell session; do not commit them or place them in this document.

From PowerShell:

```powershell
Set-Location C:\xampp\htdocs\rms\tests\e2e-browser
npm.cmd install
$env:RMS_E2E_TEST_PASSWORD = '<disposable test password>'
$env:RMS_E2E_STAFF_EMAIL = '<authorized local staff email>'
$env:RMS_E2E_STAFF_PASSWORD = '<staff password>'
$env:RMS_E2E_ADMIN_EMAIL = '<authorized local admin email>'
$env:RMS_E2E_ADMIN_PASSWORD = '<admin password>'
npm.cmd test
```

Use `npm.cmd run test:headed` to watch the browser. Without the staff/admin environment variables, the corresponding role/workflow checks are skipped; set all five values for the full 12-test run. The optional `RMS_E2E_BASE_URL` and `RMS_CHROME_PATH` variables override the local app URL and Chrome executable when needed. See `tests/e2e-browser/README.md` for harness details.

## Manual lifecycle checklist

Use a separate local test dataset/account set for this checklist. Follow top to bottom in one sitting (~45–60 min). Mark a step only after verifying it in the current application. When a check fails, log it in the Bug Log and finish the run before fixing issues.

---

## 0. Setup

**Test accounts (seeded):**

| Role | Email | Password |
|---|---|---|
| Admin | admin@rms.edu.ph | Admin@123 |
| Faculty (adviser) | msantos@rms.edu.ph | Faculty@123 |
| Faculty (2nd) | jreyes@rms.edu.ph | Faculty@123 |
| Student (lead) | jdelacruz@rms.edu.ph | Student@123 |
| Student (co-researcher) | areyes@rms.edu.ph | Student@123 |
| Staff 1 (EREC Office) | staff1@rms.edu.ph | Staff@123 |
| Staff 2 (CREC Office) | staff2@rms.edu.ph | Staff@123 |

Staff demo accounts are limited to Staff 1 (EREC Office) and Staff 2 (CREC Office).

- ☐ XAMPP Apache + MySQL running
- ☐ Each office staff account can log in
- ☐ Have 2 small test PDFs ready (any PDF renamed, e.g. `test-proposal.pdf`, `test-chapter.pdf`)
- ☐ Optional but recommended: backup the DB first so you can re-run this script clean:
  `& "C:\xampp\mysql\bin\mysqldump.exe" -u root rms_db > rms_backup_before_e2e.sql`

### Historical findings to retest

These were reported during the 2026-09-06 manual run; their current status is unverified:

- Proposal submission appeared unresponsive, and adding a co-researcher triggered an “Abstract is required” validation error.
- Chapter 1 upload reportedly worked, but the post-submit styling and editing or re-upload after a revision request needed follow-up.
- The co-researcher notification and project-access checks were not confirmed after the proposal validation issue.

---

## PHASE 1 — Student: Proposal with a team (Manual steps 1)

Log in as **Juan (jdelacruz@rms.edu.ph)**.

1. Dashboard loads without PHP warnings/errors.
2. **Submit Research** → enter a unique title, research area, abstract, and category.
3. **Co-researchers:** find and add **Anna Reyes**; confirm she appears as a selected team member.
4. Try adding yourself as a co-researcher; confirm the form prevents it.
5. Attach a small proposal PDF and submit; confirm success.
6. **My Research** → confirm the project appears with the expected initial status.
7. Open the project; verify its title, abstract, and team (Juan as lead, Anna as member).
8. Open **Submit Chapter** without a project parameter; confirm the project/chapter picker is shown rather than an invalid-chapter error.
9. Select the project and Chapter 1, upload the chapter, and confirm its submitted state on the project detail page. Also verify chapter editing/re-upload after a revision request.

Log in as **Anna (areyes@rms.edu.ph)**.

10. Confirm Anna receives a notification that she was added as a co-researcher.
11. **My Research** → confirm the project appears for her.
12. Open the project as Anna and confirm member access.

---

## PHASE 2 — Staff: CREC intake (Manual steps 2–3)

Log in as **staff**.

13. ☐ Staff dashboard loads; Submissions Inbox badge counts the new project
14. ☐ **Submissions** → E2E project listed → forward/accept it into CREC flow
15. ☐ **For CREC Review** → assign **Maria Santos** as CREC reviewer
16. ☐ No PHP errors during assignment; activity appears in the flow

---

## PHASE 3 — Faculty: review & scoring (Manual steps 4–5)

Log in as **Maria Santos (msantos@rms.edu.ph)**.

17. ☐ Notification/queue: **My Reviews** shows the assigned E2E project
18. ☐ **Score Review** (OVPREIS Form No. 3) → enter scores on all criteria, submit → saves without error
19. ☐ **Submissions** page → E2E project listed (as reviewer)
20. ☐ **Review Queue** → Juan's Chapter 1 appears → **Request revision** with feedback text
21. ☐ **My Students** → Juan AND Anna listed with the project
22. ☐ Click **Message** on Juan → messages compose opens with Juan pre-selected → send a test message
23. ☐ **Reports** → stats reflect this project (no division-by-zero errors, page renders)

Log in as **Juan**.

24. ☐ Notifications: chapter revision requested (+ the message from Maria)
25. ☐ **Messages → Inbox** → Maria's message, opens, auto-marks read → **Reply** works
26. ☐ Re-upload Chapter 1 (revision) → status returns to submitted

Log in as **Maria** again.

27. ☐ Review Queue → revised Chapter 1 back in queue → **Approve** it
28. ☐ Juan gets an approval notification (check as Juan later or now)

---

## PHASE 4 — Staff: EREC endorsement → Admin: final approval (Manual steps 4–6)

Log in as **staff**.

29. On **CREC / EREC Review**, record the CREC disposition and forward the project into EREC review; verify its status.
30. Assign EREC reviewers, record their reviews, and recommend the project for final approval. Faculty reviewers must not grant final approval or move the project to implementation.

Log in as **Admin (admin@rms.edu.ph)**.

31. ☐ **Research Management** → **Awaiting Final Approval** section lists the E2E project
32. ☐ **Return for revision** with a short reason (<20 chars) → must be REJECTED by validation
33. **Grant final approval** → project status becomes `ongoing`.
34. ☐ Juan, Anna, and Maria all receive the approval notification (spot-check at least Juan + Maria)

---

## PHASE 5 — Student: implementation milestones (Manual steps 7, 9, 10)

Log in as **Juan**.

35. ☐ **Progress Tracking** → workflow timeline shows implementation stage current; earlier stages completed
36. ☐ MOU/NDA milestone row shows **"+ Submit →"** → upload MOU PDF with remarks
37. ☐ Milestone shows **Submitted** after upload
38. ☐ Also submit a **Midway Progress Report**

Log in as **staff**.

39. **Milestones** → confirm the MOU and progress report each appear once in the queue and their file links open/download.
40. ☐ **Reject** the progress report with a reason under 10 chars → must be rejected by validation
41. ☐ Reject it properly with a valid reason → **Approve** the MOU

Log in as **Juan**.

42. ☐ Notifications: MOU approved, report rejected (with reason)
43. ☐ Progress Tracking: MOU = Approved; report = Rejected with re-upload available
44. ☐ Re-upload the progress report → back to Submitted (staff can approve it now or later)
45. ☐ **My Documents** → every file uploaded so far is listed under the right project/type, sizes shown, downloads work

---

## PHASE 6 — Defense & completion (Manual steps 8, 11)

Log in as **staff**.

46. ☐ **Defense Schedule** → schedule a **final** defense for the E2E project (future date, venue)
47. ☐ Try scheduling a SECOND final defense for the same project → must be blocked as duplicate
48. ☐ Juan + Anna + Maria all notified (spot-check Juan)
49. ☐ Juan's Progress Tracking shows the scheduled defense
50. ☐ After the "defense", mark it **Done**
51. ☐ Move the project to **completed** (via the appropriate staff/admin flow)

---

## PHASE 7 — Admin: colloquium, publication, archive (Manual steps 12–13)

Log in as **Admin**.

52. Open **Archive Management** (`pages/admin/admin-archive.php`) → **Completed research record** → confirm the completed project is listed.
53. ☐ Set colloquium = **scheduled** with date/venue → Juan notified
54. ☐ Set journal = **published** with reference → saves correctly
55. ☐ Set archive = **archived** → project status flips to **archived**
56. ☐ Project appears in archive listings; no longer in active lists

---

## PHASE 8 — Cross-cutting checks

57. ☐ **Profile** (as any role): edit name/contact → saves; change password with WRONG current password → rejected; with correct → works (change it back!)
58. ☐ **Notifications page:** mark one read, mark all read, delete one — all work
59. ☐ **Access control spot-checks (important!):**
    - As **Juan**, paste a staff URL: `/pages/staff/staff-milestones.php` → must be denied
    - As **Juan**, try another student's project: `/pages/student/research-detail.php?id=<some other id>` → denied
    - As **Maria**, try an admin URL: `/pages/admin/admin-users.php` → denied
    - Logged out, paste any internal page URL → redirected to login
60. **Admin → Activity log** → confirm the walkthrough left an activity trail (submissions, approvals, and milestone decisions).
61. ☐ **Admin → Reports** → counts moved consistently with what you did

---

## Bug Log

| # | Phase/Step | What happened | Expected | Severity (blocker/major/minor) |
|---|---|---|---|---|
| 1 | | | | |
| 2 | | | | |
| 3 | | | | |
| 4 | Phase 8 / Step 59 | Ownership spot-check used project 1, which turned out to have the same owner (invalid fixture). | Retest ownership access with a non-member account. | minor (fixture follow-up) |
| 5 | Phase 5 / Steps 39–44 | Dual-written milestones appeared as duplicate queue/badge rows; found and fixed in the same session (`25dc6d1`, `7070546`). Badge fixture validated 3->2 after the dedupe fix. | Each logical milestone appears once and mirrored statuses stay synchronized. | major — FIXED |
| 6 | Phase 4 / Steps 29–30 | Faculty could advance an EREC-stage project to `approved` and then `ongoing`, bypassing committee endorsement; found and fixed in the same session (`66acc00`). | Faculty records recommendations only; EREC staff endorses and the President grants final approval. | blocker — FIXED |

**When done:** bring this log back to the chat — we'll turn each row into a one-task fix prompt, worst first.
