# Verification: visible academic affiliation save action

- Date/time: 2026-11-02, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The academic affiliation manager keeps a save action visible while its content scrolls; the action submits the add-group form or the existing group form most recently edited.
- Page: Admin > User accounts > Manage academic groups
- Session state: In progress; browser confirmation pending

## Graphify and source context

- Question and selected graph vocabulary: Which form handler receives saves from the academic affiliation manager? Selected terms: `academic group`, `admin-users`, `modal`.
- Query/lookup and result: `graphify query "academic affiliation" --budget 1500` and `graphify query "academic group" --budget 1500` returned unrelated nodes; no useful relationship was established.
- Current source confirmation: `pages/admin/admin-users.php:81-126` handles `save_academic_group`; the modal's add and existing group forms post that action at `:1294` and `:1318`. The manager's open action is at `:1037`. The active form selection and footer submit are at `:1656-1681`, with focus tracking at `:1881-1884`.
- Missing/stale context: Graphify did not cover this feature usefully. No graph rebuild or write was performed.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l pages/admin/admin-users.php` | No PHP syntax errors | `No syntax errors detected` | Passed |
| S2 | Assistant static check | Run `git diff --check -- pages/admin/admin-users.php` | No whitespace errors | No diff-check errors; Git printed its existing LF/CRLF advisory | Passed |
| B1 | User-operated browser / admin / User accounts | Open Manage academic groups and scroll the modal content | Save action remains visible in the modal footer | User screenshot showed the add-group form compressed, the existing-groups heading overlapping it, and no visible footer action | Failed |
| B2 | User-operated browser / admin / User accounts | Focus/edit an existing group field and click the footer save action | The selected group's form submits through the existing save handler | Awaiting user observation | Pending |
| B3 | User-operated browser / admin / User accounts | Reopen Manage academic groups after the layout fix | Group sections have their full height and the save action stays visible at the modal bottom | Awaiting user observation | Pending |

## Assistant-run changes

- The manager body now scrolls independently from its footer.
- The footer save button submits the first existing group by default, switches to the add-group form when the user focuses that form, and tracks whichever save form the user last focused.
- The first browser observation exposed compressed grid rows; the modal now has a fixed viewport-based height and content-sized scrolling rows. Retest is pending.
- No database or application records were changed by Codex.

## Worktree context

- `pages/admin/admin-users.php` and many unrelated files already had modifications before this task. Those changes were preserved; this report does not attribute them to this task.
