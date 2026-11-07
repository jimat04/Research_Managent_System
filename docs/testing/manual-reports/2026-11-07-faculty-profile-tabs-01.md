# Faculty profile engagement tab removal

Date: 2026-11-07 (Asia/Taipei)

## Verification question

Does the faculty profile omit the redundant Engagement history tab while keeping submission access and achievement totals?

## Graphify lookup

- Selected vocabulary: `profile`, `engagement`, `achievements`, `tabs`.
- Command: `graphify query "faculty profile engagement achievements tabs" --budget 1500`.
- Result: graph returned `pages/shared/profile.php` and `includes/faculty-shell.php`, but no `pages/faculty/faculty-profile.php` node. Graph coverage for the target page is missing; current source was inspected directly.
- Source confirmation: `pages/faculty/faculty-profile.php` tab allowlist normalizes unsupported tabs to `overview`; the rendered tab links are built from `$tabLinks`. The overview still links to engagement submission, and achievements still shows verified engagement totals.

## Checks

| Check | Status | Evidence |
|---|---|---|
| PHP syntax | Passed | `php -l pages/faculty/faculty-profile.php`: no syntax errors. |
| Tab source review | Passed | Engagement is absent from `$allowedTabs` and `$tabLinks`; `?tab=engagement` falls back to overview. |
| Browser: faculty profile shows no Engagement history tab | Passed | User confirmed all browser checks passed. |
| Browser: old `?tab=engagement` URL opens Profile overview | Passed | User confirmed all browser checks passed. |

## Browser observation

User confirmed all browser checks passed. No application interaction was performed by the assistant.
