# Copyright authors dropdown — 2026-11-06

## Scope

Make the linked RMS contributor checkbox section on `pages/shared/copyright-form.php` collapsible while preserving contributor selection and submission behavior.

## Graphify lookup

- Question: Which current form and handler provide the linked copyright contributor checkboxes?
- Terms: `copyright`, `contributor`, `author`.
- Lookup: `graphify query "copyright contributor author" --budget 1500`.
- Result: the graph returned copyright listing nodes but no contributor form nodes; its result was complete for its selected nodes, but the form coverage is missing/stale. Direct source review is authoritative for this change.
- Confirmed current source: `pages/shared/copyright-form.php` loads contributor options at L106, restores selected IDs for edits at L183, reads and validates posted IDs at L200-L202, and renders the existing checkbox IDs at L462. The graph does not represent this form's contributor UI.

## Checks

| Check | Status | Evidence |
|---|---|---|
| Changed PHP syntax (`php -l`) | Passed | `php -l pages/shared/copyright-form.php`: no syntax errors. |
| Open disclosure; contributor checkboxes appear | Passed | User confirmed the browser check passed. |
| Save/submit behavior | Skipped | No record mutation requested; selection persistence after save not exercised |

## User browser observation

User observation: passed. The user confirmed the contributor dropdown browser check passed. No browser interaction was performed by the assistant.
