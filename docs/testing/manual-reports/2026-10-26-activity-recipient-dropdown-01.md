# Verification: activity notice recipient dropdown

- Date: October 26, 2026 (Asia/Taipei)
- Target: Individual recipient picker in `pages/shared/activity-form.php`.
- Expected behavior: The native details disclosure remains keyboard-operable; its header, open state, and recipient option rows use a consistent responsive visual treatment without changing selection behavior.

## Graphify lookup

- Question: How does the individual notice recipient list connect to the activity form behavior?
- Selected graph terms: `activity-form.php`, `recipientCheckboxes`, `updateRecipientPicker`.
- Query: `graphify query "activity-form.php recipientCheckboxes updateRecipientPicker" --budget 1000 --context-filter call`.
- Result: The existing graph returned 55 nodes and was truncated at 30. It identified `activity-form.php`, `recipientCheckboxes`, and `updateRecipientPicker()`; many additional nodes were unrelated historical browser tests.
- Source confirmation: The activity form renders the individual recipient checkboxes inside the `<details>` disclosure under Optional budget notice. `recipientCheckboxes` and related listeners update the notice preview from selected inputs; the visual update preserves these elements and listeners.
- Limitation: The traversal was truncated. Current page markup, styles, and script were inspected directly.

## Assistant checks

| ID | Check | Expected | Result | Status |
|---|---|---|---|---|
| A1 | `php -l pages/shared/activity-form.php` | No PHP syntax errors | `No syntax errors detected`. | Passed |
| A2 | Focused source assertions for disclosure, checkbox names, and preview hooks | Existing selection behavior and accessible disclosure remain | Disclosure, checkbox input names, preview hook, selected-row state, keyboard focus states, and reduced-motion override are present. | Passed |
| A3 | CSS brace balance and `git diff --check` | CSS blocks balance; no whitespace errors | Inline CSS braces balance at 174/174; `git diff --check` has no errors and no trailing whitespace was found. | Passed |
| U1 | Admin/staff with activity management permission: open an activity form and expand the individual recipient picker | Header, open panel, selected/unselected rows, keyboard focus, and narrow layout look consistent | Awaiting user observation. | Pending |

## User observations

- None received yet. The assistant made no application interactions or recipient selections.
