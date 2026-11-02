# Verification: outputs statistics

- Date/time: 2026-11-01, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Research output statistic cards reflow cleanly, use concise centered labels and values, and omit the publication/copyright shortcut buttons from the output panel. Each registered-IP category applies its output-type filter before either registration-status path, keeping category lists and counts separate. The Type column identifies the specific `output_type` for generic copyright records.
- Environment URL: `http://localhost/rms/pages/shared/implementation-outputs.php?view=outputs&module_page=research_outputs`
- Roles and disposable records/files: Authorized research staff account with access to the Research Outputs module; no record changes required.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: How do the Research Outputs summary and register list filter output types and registration statuses? Selected terms: `implementation-outputs.php`, `implementationOutputsQuery()`, `copyright_applications`, `external_status`.
- Query/lookup and result: `graphify query 'implementation-outputs.php implementationOutputsQuery' --budget 1200` returned a truncated 59-node traversal. It identifies `implementationOutputsQuery()` (L48) and the page; broad traversal includes stale E2E nodes.
- Relevant source locations and verified relationships: `pages/shared/implementation-outputs.php` defines shared status and output-type predicates near L206. The patent, innovation, and generic register branches near L507, L527, and L547, plus corresponding summary counts near L1521, L1544, and L1567, apply type filters before the shared `external_status`/`status` condition. Canonical schema `database/schema/rms_db.sql` L1039 defines the copyright output types and both status fields; migration 054 adds `external_status`.
- Missing, inferred, stale, or truncated context: Graph has no SQL-expression-specific node. Its traversal was truncated and included stale E2E nodes. Query relationships were checked against current source and canonical schema. No database was queried or changed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| UI-1 | Browser / authorized research staff / Research Outputs | Refresh and inspect the statistics at the current viewport. | Each tile has a short centered label and centered value. | Awaiting user observation after latest copy update. | Pending |
| UI-2 | Browser / same role and page | Inspect the output panel above the records. | No publications/copyrights shortcut buttons; notices and records remain. | User screenshot before shortcut removal showed both buttons. Retest pending. | Pending |
| UI-3 | Browser / same role and page | Resize to a narrow mobile viewport and inspect the statistics and output panel. | Cards reflow without overflow; shortcut buttons remain absent. | Awaiting user observation. | Pending |
| SQL-1 | Assistant source review / output summaries and register branches | Compare shared output-type and status predicates for patents, innovation, and generic copyrights. | Type filters apply before either registration-status path in each list branch and corresponding summary count. | Confirmed at `pages/shared/implementation-outputs.php` L507, L527, L547, L1521, L1544, and L1567. | Passed |
| UI-4 | Browser / admin or staff with access to copyright and relevant IP categories / Research Outputs | Open the “Other copyrights” tile. | Its register results omit patent, utility-model, innovation, and technology-transfer types that have separate categories. | Awaiting user observation; no live data was queried by the assistant. | Pending |
| UI-5 | Browser / admin / Research Outputs, type=copyright | Inspect the Type column for returned records. | Each row shows its specific output type (for example, Software or Instructional Material), not the generic word “Copyright.” | Awaiting user observation; requires at least one generic copyright record. | Pending |

## Failures, decisions, fixes, and retests
- The user screenshot showed two shortcut buttons still visible after the wrapper-only change. In response to the clarified request, the shortcuts and their unused styles were removed.
- The user requested shorter text after the statistics were centered. Labels now use concise names such as “Publications,” “Innovation / tech transfer,” and “Outputs this year.” Browser review is pending.
- Patent, innovation, and generic copyright queries previously applied their output-type restrictions only when `external_status` was null. Shared the registration-status predicate and apply each category’s output-type filter before either status path in both the register list and summary count. No database schema or stored data changed.
- The generic copyright branch already returns `record_scope` with each row. The table now formats and displays that value as the Type label for generic copyright records; values are escaped before output.

## Assistant-run checks
- `php -l pages/shared/implementation-outputs.php` — passed after the shortcut removal and after centering the labels/counts.
- `php -l pages/shared/implementation-outputs.php` — passed after shortening the statistics labels.
- `php -l pages/shared/implementation-outputs.php` — passed after the shared type/status filter correction.
- `php -l pages/shared/implementation-outputs.php` — passed after rendering specific copyright output types in the table.
- Source review — passed: the table uses `record_scope` only when `record_type` is `copyright`, retains other branch labels, and escapes the rendered label.
- `rg -n 'io-quick-links|outputs-register-panel|io-link|outputs-register-heading' pages/shared/implementation-outputs.php` — passed: no shortcut markup or associated selectors remain.
- Source review of `genericCopyrightTypesSql`, `patentOutputTypesSql`, `innovationOutputTypesSql`, and `registeredOutputStatusSql` — passed: summary and list queries reuse the category and status predicates.
- `git -c safe.directory=C:/xampp/htdocs/rms diff --check -- pages/shared/implementation-outputs.php docs/testing/manual-reports/2026-11-01-outputs-statistics-01.md` — passed after the filter correction.
- No application interactions were performed by the assistant.

## Resume / handoff
- Last user-confirmed state: The screenshot showed the shortcut buttons before their removal; no post-change observation yet.
- Next pending action: User opens the “Other copyrights” tile and confirms its results contain specific Type labels and exclude records shown in separately available IP categories (UI-4 and UI-5).
- Blocked/skipped checks and remaining verification gaps: Current and mobile browser appearance awaits user observation.
- User-performed data changes and cleanup status: None.
