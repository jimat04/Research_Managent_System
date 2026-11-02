# Verification: remove student registration department field

- Date: 2026-10-24 (Asia/Taipei)
- Assistant: Copilot
- Target and expected behavior: Student registration no longer displays a College/Department input; Program/Course offers BSIT (BS Information Technology) and BSCS (BS Computer Science); Year Level offers only 3rd Year and 4th Year. Faculty department input and backend role handling remain unchanged.
- Environment URL: http://localhost/rms/public/login.php#register
- Roles and disposable records/files: Public visitor; no records needed for the visibility check.
- Session state: Awaiting user confirmation of the student registration form.

## Graphify and source context
- Question: Which source owns public registration and role-specific field display?
- Selected vocabulary: `login.php`, `updateRegistrationFields()`, `switchToRegister()`.
- Query: `graphify query "login.php updateRegistrationFields switchToRegister" --budget 1200` returned 54 nodes and was truncated to 38. It identifies `updateRegistrationFields()` and `switchToRegister()` in `public/login.php` at `L1011` and `L991`, respectively.
- The existing graph did not include useful detail about the College/Department form control. Current source is authoritative: `public/login.php` reads department with a missing-input fallback to `null`, does not require it for registration, and retains a department control in `facultyFields`. `database/schema/rms_db.sql` defines `users.department` as nullable.
- The registration handler accepts `program` as a string. Research-submission checks explicitly use the eligible codes `BSIT` and `BSCS`; the new options preserve those exact values. Program remains optional as it was before the control changed from text input to select.
- After the user confirmed removal of College/Department, the Program/Course text input was replaced by the requested two-option selector. Per the user's follow-up, the Student Year Level choices were also narrowed to 3rd and 4th Year only.
- Coverage limitation: The Graphify query was truncated and did not establish relationships for the removed field; validation used targeted current-source inspection. The graph was not rebuilt.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant / static | Run `php -l public/login.php` | Valid PHP syntax | No syntax errors detected | Passed |
| S2 | Assistant / source | Inspect student/faculty controls, handler, schema | No College/Department input in student fields; faculty field preserved; missing POST department accepted as `null`; schema allows null | Student section contains Program/Course and Year Level only; faculty Department control remains; handler assigns `null` when missing; users.department is nullable | Passed |
| S3 | Assistant / source | Inspect program eligibility logic and select values | Dropdown values match eligible student program codes | Workflow gates use `BSIT` / `BSCS`; dropdown values use the same codes with requested full labels | Passed |
| S4 | Assistant / source | Inspect Student Year Level options | Only 3rd Year and 4th Year are selectable | Student selector contains placeholder plus `3rd` and `4th`; existing profile/admin options are unchanged | Passed |
| V1 | User / public visitor / registration form | Select Student in the role selector | Student-specific fields appear without College/Department; Program/Course and Year Level remain | User confirmed College/Department is gone and Program/Course and Year Level remain | Passed |
| V2 | User / public visitor / registration form | Open Program/Course dropdown | Options are BSIT (BS Information Technology) and BSCS (BS Computer Science) | User confirmed both expected options | Passed |
| V3 | User / public visitor / registration form | Open Year Level dropdown | Only 3rd Year and 4th Year are available | User confirmed only “3rd Year” and “4th Year” are offered | Passed |

## Failures, decisions, fixes, and retests
- No failure reported.

## Assistant-run checks
- `php -l public/login.php` — passed.
- Static source checks confirmed removal from student fields, preservation of faculty Department, nullable handler/schema behavior, exact BSIT/BSCS values, and only 3rd/4th-year student options.
- No browser interaction was performed by the assistant.
- No database change or registration submission was performed.

## Resume / handoff
- Last user-confirmed state: V1-V3 passed; College/Department is absent, both expected Program/Course options are visible, and Year Level offers only 3rd/4th Year.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: Actual registration submission was skipped; no new account was needed to verify the selectors.
- User-performed data changes and cleanup status: None; no records changed.
