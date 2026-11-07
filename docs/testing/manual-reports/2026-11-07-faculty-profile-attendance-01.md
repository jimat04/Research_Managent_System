# Faculty profile attendance count

Date: 2026-11-07 (Asia/Taipei)

## Verification question

Does the faculty profile render the verified activity attendance statistic without an undefined-variable warning?

## Graphify lookup

- Selected vocabulary: `profile`, `activity`, `attendance`.
- Command: `graphify query "faculty profile activity attendance" --budget 1500`.
- Result: the graph returned `profile.php` and general activity nodes, but no `pages/faculty/faculty-profile.php` node. Target-page graph coverage is missing; current source was inspected directly.
- Source confirmation: `pages/faculty/faculty-profile.php` renders the statistic at line 410 and now reads its value from `FacultyParticipationService::getMySummary()`. `includes/services/FacultyParticipationService.php` builds attendance from attended participants in conducted/completed activities and returns distinct attendee events.

## Checks

| Check | Status | Evidence |
|---|---|---|
| PHP syntax | Passed | `php -l pages/faculty/faculty-profile.php`: no syntax errors. |
| Source review | Passed | The statistic value is initialized from the shared participation summary before rendering. |
| Browser: open faculty profile overview and inspect attendance statistic | Pending | User confirmation required. |

## Browser observation

No application interaction was performed by the assistant.
