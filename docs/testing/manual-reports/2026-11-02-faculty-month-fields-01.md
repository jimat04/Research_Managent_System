# Verification: Faculty research record month fields

- Date/time: 2026-11-02 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Faculty start/completion fields use an accessible month/year picker styled consistently with the admin activity calendar and continue submitting `YYYY-MM` values.
- Environment URL: `http://localhost/rms/` (configured fallback; application availability not confirmed)
- Roles and disposable records/files: Faculty; no record was changed.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Find the faculty research-record month fields and their validation; `faculty-research-record-form.php`, `date_started`, `date_completed`, `month`.
- Query/lookup and result: Existing `graphify-out/graph.json` queried with `graphify query "faculty-research-record-form.php month date_started date_completed" --budget 350`; results were unrelated/truncated and did not show the target form. Targeted current source was used.
- Positioning follow-up: `graphify query "faculty research record month picker positionPanel date_started" --budget 300` returned a broad, truncated result without a useful picker relationship; current `js/faculty-research-records.js:316` was confirmed directly.
- Relevant source locations and verified relationships: `pages/faculty/faculty-research-record-form.php:116` renders these controls as `type="month"`; line 154 places them in a two-column grid. `includes/faculty-research-records.php:185-188` validates month/year values as `YYYY-MM` and checks completion is not earlier than start. `pages/shared/activity-form.php` uses a custom day calendar; its visual pattern was adapted into a month grid in `js/faculty-research-records.js` with scoped styling in `css/faculty-research-records.css`.
- Missing, inferred, stale, or truncated context: Graph results were unrelated/truncated. The activity picker selects full dates, so the faculty control is an adapted month/year picker rather than reuse of the day-selection behavior.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | `php.exe -l pages/faculty/faculty-research-record-form.php` | No syntax errors | `No syntax errors detected` | Passed |
| S2 | Assistant static check | `node.exe --check js/faculty-research-records.js` | No JavaScript syntax errors | Exit code 0 | Passed |
| S3 | Assistant static check | Faculty stylesheet brace balance | Opening and closing brace counts match | 190 opening and 190 closing braces | Passed |
| B1 | Faculty / Add Entry form | Open the Date started month field | The popup opens with a year control and month grid | User screenshot showed the picker and controls | Passed |
| B2 | Faculty / Add Entry form | Select a month in the picker | The field shows the selected month/year and retains `YYYY-MM` form semantics | Awaiting user browser observation | Pending |
| B3 | Faculty / Add Entry form, desktop | Open the picker | The popup aligns to the field's right edge when viewport space allows | Initial screenshot showed left-edge alignment; fix is ready for retest | Pending |

## Failures, decisions, fixes, and retests
- User screenshot showed the picker opening at the field's left edge, leaving it visually detached from the calendar control. The picker is now right-aligned to the field and clamped to viewport bounds; browser retest is pending.

## Assistant-run checks

- `node.exe --check js/faculty-research-records.js` — passed.
- `php.exe -l pages/faculty/faculty-research-record-form.php` — passed.

## Resume / handoff
- Last user-confirmed state: User screenshot confirmed the custom month grid and year controls appear; the panel was left-aligned.
- Next pending action: Have the user reopen Date started and confirm that the panel aligns with the field's right edge on desktop.
- Blocked/skipped checks and remaining verification gaps: Browser layout, keyboard navigation, and actual form submission remain pending user operation.
- User-performed data changes and cleanup status: None.
