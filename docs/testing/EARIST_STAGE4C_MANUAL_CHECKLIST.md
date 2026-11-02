# Stage 4C manual checklist

Use the [session report](manual-reports/2026-10-30-earist-integration-01.md) for detailed results and earlier failures. The [backup report](EARIST_STAGE4C_B2_BACKUP.md) records the verified backup and temporary generation setup.

Use only the approved disposable Faculty record and synthetic Admin evaluation details. The user performs all browser actions. Report what you see for one check at a time. A check is Pending until its result is confirmed.

Document generation is temporarily enabled for this controlled test. Stop after a critical failure. Turn generation off when these checks are finished.

## Already checked

| Check | Result |
|---|---|
| Storage access and document setup | Passed. Private files were protected, Apache storage checks passed, and PDF output stayed off. |
| A1–A4, A6: Admin Annex D preparation and access | Passed. A5 (second Admin) is not applicable because only one Admin is available. |
| B1–B2: Backup and test activation | Passed. The verified backup was completed before generation was enabled. |
| C0–C0p: Faculty setup, Annex A and Annex B | Passed. Annex B opens in Word, keeps author order and contribution details, and starts the taxonomy on Page 3. |
| C1a–C1c: Annex E, Annex F and QSF Word output | Passed. Annex E fits on one page; Annex F has no blank third page; QSF Version 8 opens cleanly and matches the requested layout. |
| QSF latest download and content | Passed. User reports the newly downloaded QSF works and its content is correct; exact fields were not specified. |
| C1: Faculty form output | Passed. User reported C1 passed; specific form types and observed details were not included in the update. |
| C5: Annex B author-answer preservation | Passed. User reported C5 passed; specific observed details were not included in the update. |
| D1: Annex D confirmation requirement | Passed. Without fresh policy and evaluation-record confirmations, the request was rejected and no version was created. |
| D2: Verified Annex D generation | Passed. User confirmed the score and classification are correct, history says “Prepared for physical signing,” signature spaces are blank, and authors are numbered with Faculty Rank and Institute. |
| C2–C4: Warning review, repeat request and old review | Passed. Missing warning acknowledgment blocks generation, a repeated request produced one version, and an old review was rejected after answers changed. |
| E0–E0d, G0a: Annex A history and Word check | Passed. Version 1 downloaded twice without creating another version, and its content opened in Word. |

## Still to check

Use the approved record throughout. Each successful Generate action creates a new history version. Read the current warnings and acknowledge them before generating.

D2 history: the initial output lacked Faculty Rank and Institute; the first retest showed those fields but lacked author numbering. After the numbering fix, Admin confirmed saved answers remained visible and later reported D2 passed. The final output details were not itemized.

| ID | Who | What to do | Expected result |
|---|---|---|---|
| D3 | Admin | Review valid but incomplete answers and generate unissued Annex D. | The document is clearly unissued, with official result fields left blank. |
| D4 | Admin | Leave an Annex D review open, change saved answers in another tab, then submit the old review. | The old review is rejected; no version is created from it. |
| D5 | Admin | Repeat one generation request, then start a separate fresh request. | The repeat creates at most one version. The fresh request creates the next version. |
| E1 | Faculty and Admin | Check each account's generated document history. | Titles, versions, source versions, status and warnings are correct. Only the account's permitted documents appear. |
| E2 | Faculty and Admin | Download the same stored version twice. | Both downloads open; history gains no version. Annex A already passed this check. |
| E3 | Faculty | Check the QSF history row and confirm no separate SDG file is offered. | The history row identifies the correct version and offers only the primary DOCX. The downloaded content is already reported correct. |
| E4 | Faculty | Change the disposable source or answers, generate a later version, then reopen an older one. | The older DOCX keeps its earlier information and file contents. |
| E5 | Faculty, Staff and Admin | Try another account's Faculty or Admin DOCX link. | Access is denied and no file downloads. A second-Admin check is not applicable until one exists. |
| E6 | Signed out | Open the Admin Annex D download link. | RMS asks for login and sends no file. |
| F1 | Faculty and Admin | Archive the disposable source after both have generated documents. | Old history remains available. New versions still require a current review. |
| F2 | Faculty and Admin | Soft-delete that source through the Faculty page. | Old downloads remain available; new preparation and generation are blocked. |
| F3 | Faculty | Restore the disposable source through the supported page. | Preparation and generation become available after a new review. Old versions stay unchanged. |
| G1 | Faculty and Admin | Open the remaining generated forms in Word. | No repair dialog or clipping; authors, checkboxes and selected SDGs are correct. QSF Version 8 already passed. |
| G2 | Faculty and Admin | Inspect Annex D results and all official signature spaces. | Verified values are exact; unissued output is clearly marked; signature spaces stay blank. |
| G3 | Faculty and Admin | Check each page in Word print preview, then print representative copies. | Text, rows, page numbers and signature spaces fit. Record preview and paper printing separately. |

## Finish

After the last check, turn off live generation and confirm the Generate control is disabled. If a critical failure occurs, stop and turn it off then.

Use only the application's normal links and actions. Do not delete historical files, reset records, run database scripts or change storage permissions during this checklist. Keep failures and their retest results in the [session report](manual-reports/2026-10-30-earist-integration-01.md).
