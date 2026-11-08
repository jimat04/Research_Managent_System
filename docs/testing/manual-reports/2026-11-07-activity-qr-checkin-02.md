# Verification: activity QR check-in fullscreen controls

- Date/time: 2026-11-07 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Admin and authorized research staff can enlarge a generated attendance QR for an in-person activity, exit with the button or Escape, use a centered styled confirmation before revoking a QR, and use the controls by keyboard.
- Environment URL: Not verified
- Roles and disposable records/files: Admin or authorized research staff; no records selected
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: Trace the activity detail QR controls and management permission. Selected terms: `activity-detail.php`, `attendance`, `checkin`, `hasModulePermission`, `research_staff`.
- Query/lookup and result: `rtk graphify query "activity checkin attendance QR" --budget 1500` returned 14 nodes. Follow-up `rtk graphify query "activity-detail.php activity-delete-dialog revoke_checkin_qr" --budget 1500` returned 44 nodes and was truncated; it exposed `activity-detail.php` but not the QR dialog behavior.
- Relevant source locations and verified relationships: Current `pages/shared/activity-detail.php` maps admin and research staff to their shells and gates management UI with `$canManage`; staff permission uses `hasModulePermission()`. QR tools and both dialogs are inside that gated section. The fullscreen buttons are shown only for a renderable current QR. Revoke forms submit the existing CSRF-protected `revoke_checkin_qr` action.
- Missing, inferred, stale, or truncated context: Graph queries did not expose QR or confirmation dialog relationships; current source is authoritative. Browser support and rendered layout remain for user verification.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l pages/shared/activity-detail.php` | No PHP syntax errors | `No syntax errors detected in pages/shared/activity-detail.php` | Passed |
| S2 | Assistant diff check | Run `git diff --check -- pages/shared/activity-detail.php docs/testing/manual-reports/2026-11-07-activity-qr-checkin-02.md` and review the relevant diff | No introduced whitespace errors; controls remain inside manager-gated QR panel | Diff check returned no whitespace errors; source review confirms the markup is under `$canManage` and only rendered for a current valid QR | Passed |
| S3 | Assistant CSS review | Check updated normal and fullscreen sizing rules | QR panel is constrained and centered at page size; fullscreen still uses viewport width and the larger QR | Added 680px panel cap, centered 360px preview with 320px QR area, and explicit viewport sizing in fullscreen; syntax and diff checks passed again | Passed |
| B1 | User browser / admin or research staff / activity detail | Open an activity with a current generated QR | QR and **Full screen** button are visible | User screenshot shows the generated QR, **Full screen**, regenerate, and revoke controls | Passed |
| B1a | User browser / same role and activity | Refresh activity detail after the layout adjustment | QR panel is centered and the large right-side empty area is reduced | User reports all remaining browser checks passed | Passed |
| B2 | User browser / same role and page | Activate **Exit full screen**, then reopen and press Escape | Both methods return to the activity detail page | User reports all remaining browser checks passed | Passed |
| B3 | User browser / same role and page | In fullscreen, use Tab and Enter/Space to reach and activate Exit | Visible focus and keyboard exit work | User reports all remaining browser checks passed | Passed |
| B4 | User browser / same role and page | Open the existing QR revoke confirmation | App-styled dialog is centered and clearly explains revocation | User screenshot shows browser-native “Code” confirmation offset left; user requested a designed, centered popup | Failed |
| B4a | User browser / same role and page | After refreshing, click **Revoke QR code** once and dismiss the custom dialog | A centered styled confirmation appears; dismissing it keeps the QR active | User reports all remaining browser checks passed | Passed |

## Failures, decisions, fixes, and retests
- User screenshot showed the QR preview left aligned inside a wide panel, leaving a large blank area on the right. Adjusted the normal panel to a centered 680px maximum width, enlarged and centered the QR preview, and explicitly restored full viewport width in fullscreen. Awaiting B1a browser retest.
- User screenshot showed the browser-native revoke prompt offset left of center. Per the user's request, added an accessible centered dialog using the page's existing modal styling; cancellation, Escape, backdrop close, and a no-JavaScript native-confirm fallback are wired. Revoke still requires the user to affirm inside the dialog. B4a retest passed per the user's report that all remaining browser checks passed.

## Assistant-run checks
- `rtk php -l pages/shared/activity-detail.php`: no syntax errors.
- `rtk git diff --check -- pages/shared/activity-detail.php docs/testing/manual-reports/2026-11-07-activity-qr-checkin-02.md`: no whitespace errors reported.
- After the layout adjustment, reran `rtk php -l pages/shared/activity-detail.php` and `rtk git diff --check -- pages/shared/activity-detail.php`; both passed.
- After adding the revoke dialog, reran `rtk php -l pages/shared/activity-detail.php` and `rtk git diff --check -- pages/shared/activity-detail.php`; both passed.

## Resume / handoff
- Last user-confirmed state: User reports all remaining browser checks passed.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported. Individual browser observations were summarized as “all passed” without separate details.
- User-performed data changes and cleanup status: No data changes reported; no database changes made by this task.
