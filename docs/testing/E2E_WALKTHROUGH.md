# End-to-End Lifecycle Walkthrough — RMS Test Script

**Purpose:** Walk the complete EARIST Research Manual 2015 lifecycle through the UI as all four roles, from proposal to archive, verifying every handoff between roles.

**How to use:** Follow top to bottom in one sitting (~45–60 min). Check each ☐ as you go. When something fails, don't fix it mid-run — log it in the Bug Log at the bottom and keep going. Fix bugs in a batch afterwards.

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
| Research Staff | *(your staff account)* | |

- ☐ XAMPP Apache + MySQL running
- ☐ A research_staff account exists and can log in (if not: create one via Admin → User Management)
- ☐ Have 2 small test PDFs ready (any PDF renamed, e.g. `test-proposal.pdf`, `test-chapter.pdf`)
- ☐ Optional but recommended: backup the DB first so you can re-run this script clean:
  `& "C:\xampp\mysql\bin\mysqldump.exe" -u root rms_db > rms_backup_before_e2e.sql`

---

## PHASE 1 — Student: Proposal with a team (Manual steps 1)

Log in as **Juan (jdelacruz@rms.edu.ph)**.

1. ☐ Dashboard loads without PHP warnings/errors anywhere on the page
2. ☐ **Submit Research** → fill title `E2E Test Research <today's date>`, area, abstract, category
3. ☐ **Co-researchers:** search and add **Anna Reyes** — she appears as selected
4. ☐ Try adding **yourself** → must be rejected/not offered
5. ☐ Attach proposal PDF, submit → success, no error
6. ☐ **My Research** → new project listed with correct status
7. ☐ Open the project → detail page shows title, abstract, **team: Juan (lead) + Anna (member)**
8. ☐ **Sidebar → Submit Chapter** (no params) → chapter picker appears (NOT "Invalid chapter")
9. ☐ Upload Chapter 1 via the picker → chapter shows as submitted on the project detail

Log in as **Anna (areyes@rms.edu.ph)**.

10. ☐ Notification: added as co-researcher
11. ☐ **My Research** → the project appears for her too
12. ☐ She can open the project detail (member access works)

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

29. ☐ CREC page shows Maria's scores → **Endorse to EREC** → status moves to EREC review
30. ☐ Move/endorse through EREC to **approved** (per your staff-crec flow)

Log in as **Admin (admin@rms.edu.ph)**.

31. ☐ **Research Management** → **Awaiting Final Approval** section lists the E2E project
32. ☐ **Return for revision** with a short reason (<20 chars) → must be REJECTED by validation
33. ☐ **Grant final approval** → project status becomes ongoing/in_progress
34. ☐ Juan, Anna, and Maria all receive the approval notification (spot-check at least Juan + Maria)

---

## PHASE 5 — Student: implementation milestones (Manual steps 7, 9, 10)

Log in as **Juan**.

35. ☐ **Progress Tracking** → workflow timeline shows implementation stage current; earlier stages completed
36. ☐ MOU/NDA milestone row shows **"+ Submit →"** → upload MOU PDF with remarks
37. ☐ Milestone shows **Submitted** after upload
38. ☐ Also submit a **Midway Progress Report**

Log in as **staff**.

39. ☐ **Milestones** nav badge = 2 → queue lists MOU + progress report with file links that download/open
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

52. ☐ **Archive Management → Publication & Colloquium** → E2E project listed (status completed)
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
60. ☐ **Admin → System Logs** → the whole walkthrough left an activity trail (submissions, approvals, milestone decisions)
61. ☐ **Admin → Reports** → counts moved consistently with what you did

---

## Bug Log

| # | Phase/Step | What happened | Expected | Severity (blocker/major/minor) |
|---|---|---|---|---|
| 1 | | | | |
| 2 | | | | |
| 3 | | | | |

**When done:** bring this log back to the chat — we'll turn each row into a one-task fix prompt, worst first.