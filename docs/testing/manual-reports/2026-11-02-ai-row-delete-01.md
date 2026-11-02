# Verification: AI detail row deletion

- Date/time: 2026-11-02 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Step 1 uses a two-column desktop layout: the document/source summary and Continue action are in the first column, Mapping notes are in the second. Within the source summary, the saved revision is grouped with the selected form and record details follow compactly. Step 4 places Review and save your answers in the first column and Generated Documents in the second; its history and generation actions sit together in a responsive row, with generation primary. Both panel pairs stack on narrow screens. AI-use detail rows can be removed. Removing a row removes its values from the pending answer set, renumbers remaining controls, and requires a fresh review before saving.
- Environment URL: `http://localhost/rms/` (configured fallback; application availability not confirmed)
- Roles and disposable records/files: Faculty; record ID 2 was supplied in the requested route. No record changes have been made.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Explain the Annex C `Research information changed` notice and its stale-draft checks; `draftStale`, `earistDocumentSourceFingerprint`, `earistDocumentDraftVersionContext`.
- Query/lookup and result: Existing `graphify-out/graph.json` queried with `graphify query "earistDocumentDraftVersionContext source_fingerprint draftStale" --budget 450`; the results were unrelated/truncated and did not include the current mapping-page flow. A second focused query was similarly unrelated. Graph coverage is stale or insufficient; targeted source inspection was used.
- Relevant source locations and verified relationships: `pages/faculty/faculty-document-mapping.php:157-162` sets `$draftStale` when the saved source fingerprint or record version differs, or when the input schema, mapping definition, binding version, or template hash differs. The banner is rendered at line 223 when that value is true. `includes/earist-document-service.php:74-78` fingerprints the document type and source mapping; lines 194-203 provide the current schema/mapping/binding/template versions. `earistDocumentDraftLoadForActor()` loads a draft by record, owner, and document type at lines 149-162, so opening Annex C loads its Annex C draft.
- Missing, inferred, stale, or truncated context: Graph results are unrelated/truncated and do not cover the current mapping flow. No graph relationship was inferred from the query output.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | `php.exe -l pages/faculty/faculty-document-mapping.php` | No syntax errors | `No syntax errors detected` (rerun after moving the button) | Passed |
| S2 | Assistant static check | `node.exe --check js/faculty-document-mapping.js` | No syntax errors | Exit code 0; no output | Passed |
| B1 | Faculty / requested Annex A mapping URL | Inspect the AI-use details and activate Delete row for one row | Delete row is at the lower right of each detail; only the selected row disappears and remaining values and labels stay aligned | Awaiting user browser observation | Pending |
| B2 | Faculty / same form | Review the shortened answers, then save and reopen | The deleted row stays absent; remaining rows persist | Not run; dependent on B1 and user confirmation | Pending |
| B3 | Faculty / requested Annex A mapping URL | Open Step 1 | The selected form is clearly primary; record and revision are supporting context | User screenshot showed the form last, after a dominant long record title; user questioned whether it was easy to analyze | Failed |
| B4 | Faculty / requested Annex A mapping URL | Refresh Step 1 after hierarchy update | Selected form is first and prominent; draft status and source record read as secondary context | Awaiting user browser observation | Pending |
| B5 | Faculty / requested Annex A mapping URL | Review Step 1 after action alignment update | Content and Continue action use the available desktop width without a large dead area | User screenshot showed a narrow content column and substantial unused space on the right; user asked about a desktop two-column layout | Failed |
| B6 | Faculty / requested Annex A mapping URL / desktop | Refresh Step 1 after two-column update | Form and draft appear on the left, source record on the right, and Continue below both | Superseded by the user's clarification before browser confirmation | Skipped |
| B7 | Faculty / same page / narrow viewport | Resize to a narrow viewport | The two cards stack without horizontal overflow; Continue remains usable | Superseded with B6 before browser confirmation | Skipped |
| B8 | Faculty / requested Annex A mapping URL / desktop | Refresh Step 1 after clarified two-column update | Source summary and Continue are in the first column; Mapping notes are in the second | Awaiting user browser observation | Pending |
| B9 | Faculty / same page / narrow viewport | Resize after B8 | Source summary and Mapping notes stack without horizontal overflow; Continue remains usable | Not run; dependent on B8 | Pending |
| B10 | Faculty / requested Annex A mapping URL / desktop | Open Step 4 | Review and save are in the first column; Generated Documents are in the second | User screenshot confirms the two-column desktop arrangement | Passed |
| B11 | Faculty / same page / narrow viewport | Resize after B10 | Review/save and Generated Documents stack without horizontal overflow | Not run; dependent on B10 | Pending |
| B12 | Faculty / same page / desktop | Inspect the history and generation actions | Actions are grouped side by side, with generation visually primary | User screenshot showed the two links stacked; user asked whether the placement was awkward | Failed |
| B13 | Faculty / same page / desktop | Refresh Step 4 after action-row update | History and generation actions share a row when space allows | Awaiting user browser observation | Pending |
| B14 | Faculty / same page / narrow viewport | Resize after B13 | Actions wrap/stack cleanly without overflow | Not run; dependent on B13 | Pending |
| B15 | Faculty / Step 1 / desktop | Review text grouping and vertical rhythm | Draft revision reads with the selected form; record details feel secondary and compact | User screenshot showed a detached revision chip and long divider; user said the text placement felt awkward | Failed |
| B16 | Faculty / Step 1 / desktop | Refresh after hierarchy refinement | Selected form and revision read as one group, followed by compact source details | User screenshot shows revision grouped within the selected-form panel and source details below | Passed |
| B17 | Faculty / Step 1 / desktop | Refresh after moving Continue to the shared footer | Continue appears below both columns, aligned to the right | Awaiting user browser observation | Pending |
| B18 | Faculty / Annex C mapping page | Open Annex C with its saved draft | A stale draft shows the research-information notice; saved answers remain available | User screenshot shows Annex C revision 2 and the notice. Source confirms the notice is driven by a saved/current fingerprint or version mismatch; the exact mismatched field is not visible in the screenshot | Passed |

## Failures, decisions, fixes, and retests
- User screenshots showed, first, the selected form last and the long record title visually dominant, then a large unused area at the right of the content column. Codex initially put the research record in column two; user clarified that Mapping notes should occupy column two. Codex corrected Step 1 accordingly and applied the same two-column pattern to Step 4 with Generated Documents beside review/save. A later screenshot showed the history and generation links stacked awkwardly; Codex grouped them in a responsive action row, with generation primary. The latest Step 1 screenshot showed the draft revision detached below the selected-form card and source title separated by a long rule; Codex grouped the draft revision into the selected-form card and tightened source spacing. The user's Annex C screenshot shows the existing staleness notice; source inspection confirms the possible comparisons but not which saved metadata differs for that draft.

## Assistant-run checks
- `php.exe -l pages/faculty/faculty-document-mapping.php` — passed, including rerun after the layout adjustment.
- `php.exe -l pages/faculty/faculty-document-mapping.php` — passed after compacting Step 1.
- CSS brace-count check on `css/faculty-document-mapping.css` — passed (69 opening and 69 closing braces).
- `php.exe -l pages/faculty/faculty-document-mapping.php` — passed after making the selected form the primary item.
- CSS brace-count check on `css/faculty-document-mapping.css` — passed after hierarchy update (73 opening and 73 closing braces).
- `php.exe -l pages/faculty/faculty-document-mapping.php` — passed after introducing the two-column layout.
- CSS brace-count check on `css/faculty-document-mapping.css` — passed after introducing responsive columns (78 opening and 78 closing braces).
- `php.exe -l pages/faculty/faculty-document-mapping.php` — passed after placing Mapping notes in the second column.
- CSS brace-count check on `css/faculty-document-mapping.css` — passed after the clarified layout (79 opening and 79 closing braces).
- `php.exe -l pages/faculty/faculty-document-mapping.php` — passed after placing Generated Documents beside review/save in Step 4.
- CSS brace-count check on `css/faculty-document-mapping.css` — passed after adding the Step 4 layout (84 opening and 84 closing braces).
- `php.exe -l pages/faculty/faculty-document-mapping.php` — passed after grouping history and generation actions.
- CSS brace-count check on `css/faculty-document-mapping.css` — passed after adding the responsive action row (87 opening and 87 closing braces).
- `php.exe -l pages/faculty/faculty-document-mapping.php` — passed after grouping draft status with the selected form.
- CSS brace-count check on `css/faculty-document-mapping.css` — passed after tightening Step 1 spacing (90 opening and 90 closing braces).
- `php.exe -l pages/faculty/faculty-document-mapping.php` — passed after moving Continue to the shared Step 1 footer.
- CSS brace-count check on `css/faculty-document-mapping.css` — passed after the footer adjustment (90 opening and 90 closing braces).
- CSS brace-count check on `css/faculty-document-mapping.css` — passed after aligning Continue with the content column (74 opening and 74 closing braces).
- `php.exe -l pages/faculty/faculty-document-mapping.php` — passed after removing the duplicate document picker.
- CSS brace-count check on `css/faculty-document-mapping.css` — passed (68 opening and 68 closing braces). The first invocation had a PowerShell quoting error; the corrected command passed.
- `node.exe --check js/faculty-document-mapping.js` — passed (exit code 0).
- Read-only git diff/status inspection showed this workspace currently reports the project files as untracked; no unrelated worktree content was changed intentionally. `git diff --check` emitted repository-wide line-ending warnings because of the current worktree state, so it is not recorded as a scoped clean-diff check.

## Resume / handoff
- Last user-confirmed state: Faculty opened Annex C on research record #2; screenshot shows saved revision 2 and the research-information notice.
- Next pending action: Ask the user to inspect the shared Step 1 footer at desktop width. Step 4 and AI row behavior/persistence checks remain pending from earlier requests.
- Blocked/skipped checks and remaining verification gaps: Browser behavior and persistence await user-operated verification. Graphify coverage is stale/insufficient as noted above.
- User-performed data changes and cleanup status: None.
