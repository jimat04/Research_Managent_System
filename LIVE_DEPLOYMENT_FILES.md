# Changed application files

## Current session

- [css/style.css](css/style.css) — style the notification popover and its native disclosure control.
- [includes/activity-schedule.php](includes/activity-schedule.php) — support the scheduled activity start and completion workflow.
- [includes/faculty-shell.php](includes/faculty-shell.php) — add live notification counts to faculty navigation.
- [includes/notification-popover.php](includes/notification-popover.php) — render the notification disclosure and live-count hooks.
- [includes/student-shell.php](includes/student-shell.php) — add live notification counts to student navigation.
- [js/notification-popover.js](js/notification-popover.js) — refresh notification counts and popover contents while preserving keyboard and native disclosure behavior.
- [js/research-basics.js](js/research-basics.js) — preserve lesson progress and navigation behavior.
- [pages/researcher/submit-chapter.php](pages/researcher/submit-chapter.php) — notify administrators after a chapter submission or resubmission.
- [pages/shared/activity-detail.php](pages/shared/activity-detail.php) — start eligible activities on an authorized page visit and support staff activity-management actions.
- [pages/shared/notification-status.php](pages/shared/notification-status.php) — provide an authenticated, read-only notification status endpoint. **New file.**
- [pages/student/student-dashboard.php](pages/student/student-dashboard.php) — return students to the selected roadmap stage after saving progress.
- [public/403.php](public/403.php) — improve contrast on the forbidden page.
- [public/404.php](public/404.php) — improve contrast on the not-found page.
- [public/500.php](public/500.php) — improve contrast on the server-error page.

Deployment package: [project-update-2026-11-10.zip](deployment/project-update-2026-11-10.zip). No application files were removed in this update. Private-storage guard deletions remain excluded. See the [notification popover report](docs/testing/manual-reports/2026-11-10-notification-popover-01.md), [activity QR report](docs/testing/manual-reports/2026-11-10-activity-qr-01.md), [chapter notification report](docs/testing/manual-reports/2026-11-10-chapter-notification-01.md), [error pages report](docs/testing/manual-reports/2026-11-10-error-pages-01.md), and [workflow report](docs/testing/manual-reports/2026-11-10-workflow-02.md).

## For future sessions

Whenever a future session edits, adds, renames, or removes project files, update this list in that session with only that session's changed application files. Keep clickable links for every changed or new application file, using its repository path. Mark new files clearly and call out removals separately; do not suggest copying a deletion as a file. Keep the manifest focused on files relevant to updating the live application.
