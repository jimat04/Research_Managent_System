# Archived RMS Lifecycle Verification Notes

> **Current testing:** Use the [collaborative user-operated manual testing guide](MANUAL_TESTING_GUIDE.md) for all new checks. It defines the current preparation, one-action-at-a-time browser confirmation, reporting, and safety workflow. This file is an archive, not an executable test plan.

The former browser E2E harness and its setup instructions were removed on 2026-10-24. Do not use this archive to configure automation, create accounts, or modify application data. No browser E2E suite is currently maintained.

## Historical observations

- An eight-phase manual lifecycle walkthrough was reported complete on 2026-09-06. Its notes and bug log recorded failures and follow-up fixes, so that result was not a clean pass and does not establish current behavior.
- A prior local automated run was recorded as **12 passed, 0 skipped, 0 failed**. This is historical evidence only; other archived records may report different runs/counts. None of these results verifies the current working tree.
- The old walkthrough included seeded-account details, a disposable project lifecycle checklist, and automation setup. Those procedural details are intentionally retired; derive any new checks from current source and use the manual testing guide.

## Retained historical findings

These items were recorded during the 2026-09-06 manual walkthrough. Their current status has not been retested here.

| Area | Historical observation | Recorded follow-up |
|---|---|---|
| Proposal submission | Submission appeared unresponsive; adding a co-researcher triggered an “Abstract is required” validation error. | Proposal submission and co-researcher notification/access needed follow-up. |
| Chapter 1 | Upload reportedly worked; post-submit styling and editing/re-upload after a revision request needed follow-up. | Revision and resubmission needed confirmation. |
| Ownership access | An ownership spot-check used project 1, which had the same owner and was an invalid fixture. | Retest with a non-member account. |
| Milestone queues | Dual-written milestones appeared as duplicate queue/badge rows; fixes were recorded in commits `25dc6d1` and `7070546`, with a badge fixture moving from 3 to 2. | One logical milestone per queue/badge; mirrored statuses stay synchronized. Recorded as fixed at that time, not currently verified. |
| EREC/final approval | Faculty could advance an EREC-stage project to `approved` and then `ongoing`, bypassing committee endorsement; a fix was recorded in commit `66acc00`. | Faculty records recommendations; EREC staff endorses; the President grants final approval. Recorded as fixed at that time, not currently verified. |

## Verification boundary

This archive is not a current test report. Do not mark any listed behavior Passed based on these historical notes. For new verification, follow the manual guide, have the user perform each application interaction, and record current observations in a dated session report.
