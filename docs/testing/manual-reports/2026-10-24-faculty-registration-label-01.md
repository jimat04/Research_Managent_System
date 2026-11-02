# Verification: faculty registration label

- Date: 2026-10-24 (Asia/Taipei)
- Assistant: Copilot
- Target and expected behavior: The public registration role selector displays “Faculty” instead of “Faculty/Adviser,” while retaining the `faculty` role value.
- Environment URL: http://localhost/rms/public/login.php#
- Roles and disposable records/files: Public visitor; no records needed.
- Session state: Awaiting user verification of the visible option label.

## Graphify and source context
- Question: Which page and handler provide the public registration role selector?
- Selected vocabulary: `login.php`, `updateRegistrationFields()`, `faculty`.
- Query: `graphify query "login.php updateRegistrationFields faculty" --budget 1200` returned 59 nodes and was truncated to 38. It confirms `public/login.php` contains `updateRegistrationFields()` at `L1011` and belongs to the Login Security Roles community.
- Current-source change was verified by the implementation specialist: only display wording changed from “Faculty/Adviser” to “Faculty”; the `value="faculty"` remained unchanged. A related login message label was also renamed for consistent wording. No role semantics or behavior changed.
- Coverage limitation: The existing graph lookup was broad and truncated; stale E2E test nodes appeared. Current changed source was validated directly by the implementing specialist; the graph was not rebuilt.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / static | Run `php -l public/login.php` | Valid PHP syntax | Implementing specialist reported no syntax errors | Passed |
| S2 | Assistant / source | Check selector value and visible wording | `faculty` value retained; option displays “Faculty” | Implementing specialist reported the option value was unchanged and old wording absent in public PHP pages | Passed |
| V1 | User / public visitor / registration form | Open the role dropdown | Option appears as “👨‍🏫 Faculty” | User confirmed the option displays “👨‍🏫 Faculty” | Passed |

## Failures, decisions, fixes, and retests
- No failure reported. Browser confirmation is pending.

## Assistant-run checks
- `php -l public/login.php` — passed, as reported by the implementing specialist.
- No browser interaction was performed by the assistant.

## Resume / handoff
- Last user-confirmed state: V1 passed; the dropdown displays “👨‍🏫 Faculty.”
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None.
- User-performed data changes and cleanup status: None; no application records changed.
