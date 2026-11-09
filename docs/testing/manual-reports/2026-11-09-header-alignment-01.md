# Verification: public header alignment

- Date/time: 2026-11-09 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Terms and Privacy pages use the shared public header structure and match the public site's header alignment.
- Environment URL: `http://localhost:8080/rms/public/terms-of-service.php` and `http://localhost:8080/rms/public/privacy-policy.php`
- Roles and disposable records/files: Public pages; no records changed.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: Trace the public page header. Selected `header` after checking available graph and query tool.
- Query/lookup and result: `graphify query "header" --budget 1500`; no matching nodes found.
- Relevant source locations and verified relationships: `includes/public-header.php` renders the standard outer `header#site-header` with inner `.public-header-inner` unless the sticky-inner flag is set; the policy pages had set that flag and `policy-pages.css` overrode `#site-header` margins. `public/assets/css/public-header.css` owns the shared outer/header-inner layout. Source confirmed directly.
- Missing, inferred, stale, or truncated context: No matching Graphify nodes. Direct source lookup used as fallback.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant PHP syntax check | Run `php -l` on both policy pages | Both pages parse | No syntax errors detected | Passed |
| S2 | Assistant source check | Inspect header include mode and page CSS overrides | Both pages use standard header wrapper; policy CSS no longer overrides the shared header | Neither page sets `publicHeaderStickyInner`; no policy header override remains | Passed |
| B1 | User browser / public page | Open Terms page in desktop light theme and compare header alignment with the RMS homepage | Brand, centered navigation, and actions align with the shared RMS public header | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- Source review found a policy-only header margin override and a markup mode that bypassed the shared header's inner wrapper. Changed both policy pages to the standard shared wrapper and removed the conflicting policy header overrides.

## Assistant-run checks
- `php -l public/terms-of-service.php` and `php -l public/privacy-policy.php`: passed.
- Targeted source search confirmed standard header mode and removal of policy-specific header overrides.

## Resume / handoff
- Last user-confirmed state: No browser observation yet.
- Next pending action: User opens the Terms page in the integrated browser and confirms desktop light-theme header alignment.
- Blocked/skipped checks and remaining verification gaps: Browser alignment is pending; narrow viewport and dark-theme checks follow after the initial observation.
- User-performed data changes and cleanup status: None.
