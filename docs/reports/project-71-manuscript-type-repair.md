# Project 71: manuscript type repair

Date: 2026-10-25 (Asia/Taipei)

Read-only diagnostic against confirmed loopback `rms_db` found:

- Project 71 is recorded as completed and has five active approved chapters.
- Submission 128 is approved, has no chapter ID, has the snapshot marker `Complete research manuscript`, and has a manuscript upload.
- Its submission type is the empty enum error value. The live column permits only title/chapter.
- No ledger row was found for migration 025 or 033. The only blank submission type found was submission 128.
- The canonical schema already includes final_manuscript; no fresh-install schema edit is needed.

Prepared migration: `033_repair_final_manuscript_submission_type.sql`. It temporarily permits existing empty values, repairs only rows identified by both snapshot and associated project manuscript upload, and restores the canonical enum. It does not approve anything or change IDs, snapshots, files, project statuses, comments, or review decisions. The registered verifier refuses unknown empty values, conflicting version numbers and unexpected column definitions.

Pending explicit authorization to change existing database data:

1. Reconfirm loopback rms_db and the affected row/ledger state.
2. Back up research_submission_versions and schema_migrations, including CREATE definitions, to protected local storage; verify backup before applying. Do not print snapshot contents or credentials.
3. Apply only `php scripts/migrate.php apply 033_repair_final_manuscript_submission_type.sql`. Do not run 025 (it also updates project statuses), 010, or a blanket sync/apply.
4. Verify the enum, submission 128 type/unchanged status and relationships, and migration ledger. MySQL DDL is not transactionally reversible; if failure occurs inspect the recorded failure before retrying.
5. User refreshes Completed Research and Approved Final Manuscript, and confirms project 71 appears. No application interactions should be automated.

Current state: user reports applying repair. Read-only verification confirms canonical enum and final_manuscript typing; version 1 is returned and version 2 approved. User confirmed project 71 appears in Completed: browser retest passed. Migration 033 has no ledger row, so runner execution and backup remain unverified. Approved Final Manuscript browser confirmation remains pending. No assistant database writes were performed. Revision action is available on all eligible directory rows, including Completed.
