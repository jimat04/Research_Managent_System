# Verification: IP sidebar links

- Date/time: 2026-10-28 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Faculty and student sidebars expose Copyrights, Patents & Utility Models, and Innovation & Technology Transfer links. Category links open the matching view; authenticated users see only their own records.
- Environment URL: Local RMS; configured base URL not inspected.
- Roles and disposable records/files: Faculty and student accounts; no record changes planned.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: Do role sidebars link faculty/student users to the shared intellectual property records page? Terms: copyrights, faculty-shell, student-shell, ip_scope.
- Query/lookup and result: `graphify query "Copyrights applicant faculty student sidebar ip_scope" --budget 1500`; result was truncated by one node, but returned both shell nodes and the shared copyrights page. Graph coverage for direct menu links is incomplete; confirmed current source directly.
- Relevant source locations and verified relationships: `includes/faculty-shell.php` lines 97–99 adds three links; lines 130–138 match the `ip_scope` selection. `includes/student-shell.php` lines 107–109 adds the corresponding links; lines 118–127 distinguish the selected IP scope. `css/student-shell.css` makes long sidebar labels wrap. `pages/shared/copyrights.php` lines 70, 248–253 filter faculty/student records by logged-in `applicant_id`.

## Checklist
| ID | Check | Status | Result |
|---|---|---|---|
| S1 | `php -l includes/faculty-shell.php` | Passed | No syntax errors. |
| S2 | `php -l includes/student-shell.php` | Passed | No syntax errors. |
| S3 | `git diff --check` on both shell files | Passed | No whitespace errors reported. |
| B1 | Student sidebar shows IP category links and highlights the selected category | Passed | User confirmed the student sidebar works after the label-wrap and active-link fixes. |
| B2 | Faculty sidebar shows all three links and opens each matching IP category | Passed | User confirmed the faculty sidebar works. |

## Observations
- Assistant-run checks: Static syntax and whitespace checks passed. No database changes.
- User observations: Student desktop screenshot showed the technology transfer link clipped at the right edge; label wrapping fix applied. User then reported filtered links lacked active highlighting; query-string path matching was fixed. User confirmed the student sidebar is all good and later confirmed the faculty sidebar is all good.
- Limitations: None reported for the faculty and student sidebar checks.
