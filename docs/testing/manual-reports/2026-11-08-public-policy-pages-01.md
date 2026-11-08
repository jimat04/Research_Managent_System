# Verification: public policy pages

- Date/time: 2026-11-08, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: Public Privacy Policy and Terms of Service review drafts load without signing in and are linked from the public footer/homepage.
- Environment URL: `http://localhost:8080/rms/`
- Roles and disposable records/files: None; public pages only.
- Session state: Finished; sticky `.public-header-inner` behavior passed on both policy pages.

## Graphify and source context
- Question and selected graph vocabulary: Initial lookup traced public pages and their data behavior. Follow-up lookup traced the header/layout relationship after the screenshot. Selected current labels: `Public Entry and Archive`, `public-footer.php`, and `public/index.php`.
- Query/lookup and result: Read-only direct lookup in `graphify-out/graph.json`; the semantic public-entry node has conceptual edges only. Repeated lookup after the CSS and PHP header changes found no graph node covering the public header or policy CSS.
- Relevant source locations and verified relationships: `public/index.php:101` includes `includes/public-footer.php`; `includes/public-footer.php:19` defines its quick links; `includes/contact-handler.php:60` stores public contact messages; `includes/earist-google-drive.php:124` requests `openid`, `email`, and `drive.file`, while token encryption is implemented at line 22; `docs/EARIST_GOOGLE_DOCS_SETUP.md:3,35` documents Drive working copies and their persistence after disconnect. Follow-up source confirms `public/assets/css/public-header.css:8` applies `margin: 12px auto -78px`, while `public/assets/css/policy-pages.css:8-10` sets the policy content margin.
- Missing, inferred, stale, or truncated context: Graph edges are conceptual and point to diagram images with no source locations; source code was used to confirm behavior. `graphify query --help` unexpectedly executed its default broad query; its output was not used as evidence. Existing Graphify working-tree changes were present before this task and were left intact.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Assistant command / PHP | Run `php -l` on `public/privacy-policy.php` and `public/terms-of-service.php` | Both files parse | Both commands reported no syntax errors | Passed |
| 2 | User browser / signed out / Privacy Policy | Open the local Privacy Policy page and inspect the draft notice, layout, and links | Page loads without login; review-draft notice and footer links are visible | User reported it loaded with the draft notice and footer links | Passed |
| 3 | User browser / signed out / Terms of Service | Open the local Terms page and inspect the draft notice, layout, and links | Page loads without login; review-draft notice and footer links are visible | User reported it loaded with the draft notice and footer links | Passed |
| 4 | User browser / narrow viewport / either page | Resize to a phone-width viewport and inspect reading layout and navigation | No horizontal overflow; content and links remain readable and usable | User reported readable layout with no horizontal overflow | Passed |
| 5 | User browser / policy page / initial viewport | Inspect the page heading beneath the public navigation | Header does not obscure the policy heading or intro | User screenshots show the heading and intro partly covered by the sticky header | Failed |
| 6 | User browser / Privacy Policy / initial viewport | Reload and inspect the title and intro below the navigation | Header no longer overlaps the title or intro | User reported both are fully visible below the navigation | Passed |
| 7 | User browser / Privacy Policy / section link | Activate “Information processed” in the on-page navigation | Target section heading is visible below the sticky header | User reported the section heading is clear | Passed |
| 8 | User browser / Terms of Service / initial viewport | Reload and inspect the title and intro below the navigation | Header does not overlap the title or intro | User reported the heading is still overlapped | Failed |
| 9 | User browser / Privacy Policy and Terms / initial viewport | Inspect both pages after the cache-busting update | Latest CSS is loaded; headings and intros are below navigation | User screenshot showed both page headings partly obscured; user confirmed localhost | Failed |
| 10 | User browser / Privacy Policy / scrolling | Scroll down past the title | Header moves out of view rather than sticking over text | User reported it remained sticky after the CSS-only change | Failed |
| 11 | User browser / Privacy Policy and Terms / scrolling | Reload and scroll each page after shared-header opt-out | Header moves out of view; content is not covered | User screenshots show both policy pages without the navigation overlay while reading | Passed |
| 12 | User browser / Privacy Policy / title visibility | Scroll past the title block | The page title is visible and moves with document content | User confirmed “Privacy Policy” scrolls away with the page | Passed |
| 13 | User browser / Privacy Policy / scrolling | Scroll down through the page | `.public-header-inner` stays pinned; policy title block scrolls normally | User confirmed the inner header stays at the top while the title scrolls away | Passed |
| 14 | User browser / Terms of Service / scrolling | Scroll down through the page | `.public-header-inner` stays pinned; policy title block scrolls normally | User confirmed the inner header stays at the top while the title scrolls away | Passed |

## Failures, decisions, fixes, and retests
- Follow-up screenshot exposed a visual failure after the earlier load/responsive checks: the public navigation overlapped the policy page heading and intro. The policy heading now overrides the global sticky `header` rule. User requested `.public-header-inner` be sticky on both policy pages; each page now renders it as a page-level banner so sticky positioning can span the document. Privacy Policy sticky behavior passed; Terms retest is pending.
- The repository did not contain the institution-approved policy wording or privacy-office contact/retention details. The new pages are explicitly marked as review drafts and must not be treated as final OAuth verification URLs until the authorized office approves and completes those details.

## Assistant-run checks
- `rtk php -l public/privacy-policy.php` — passed.
- `rtk php -l public/terms-of-service.php` — passed.
- Source check confirms policy pages render `.public-header-inner` as the sticky page-level banner, with a 92px section anchor offset and stylesheet cache versioning.
- No database changes or application data changes were made.

## Resume / handoff
- Last user-confirmed state: Privacy Policy `.public-header-inner` stays pinned while the policy title scrolls away.
- Next pending action: None for the sticky-header verification.
- Blocked/skipped checks and remaining verification gaps: Institutional approval of the copy, privacy contact details, retention schedule, and service-provider disclosures remains necessary before final publication or production Google OAuth verification.
- User-performed data changes and cleanup status: None.

## Latest follow-up
- The user confirmed the latest screenshots were from localhost. The scoped header change is now pending browser retest on both pages.
- The user clarified that the intended change is to remove the sticky navigation from the policy pages. This is now implemented; browser confirmation that it scrolls away during reading remains pending.
- After the CSS-only change, the user reported that the navigation still stayed sticky. A PHP-level page-specific static mode was added to the shared header and enabled only by the policy pages. `php -l` passed for `includes/public-header.php` and both policy pages. Browser retest is still pending.
- The user then shared local screenshots of both policy pages with the navigation no longer over the content. The PHP-level static-header check is recorded as passed; there are no remaining browser checks for the header change.
- User asked to remove the visible policy title and use the site header. The visible H1 is now visually hidden while retaining its accessible text and the browser tab title; user browser confirmation is pending.
- User then asked to remove the visible page title and use the site header. The H1 remains available to assistive technology and the browser tab title remains descriptive; browser retest of the updated visible layout is pending.
- User clarified the preferred result is a sticky site header with the large page title hidden, then corrected that both header and title should remain visible but non-sticky. The visible H1 is restored and policy pages use the static-header mode.
- User identified `header.policy-heading` as the sticky element. `landing-v2.css` has a generic `header { position: sticky; }` rule; the policy stylesheet resets that policy-title header to normal flow and removes the inherited header chrome. The graph contains no header/CSS node, so current CSS source was the evidence.
- User confirmed that “Privacy Policy” scrolls away with the page. The latest policy heading non-sticky check passed.
- User identified `header.policy-heading` as the sticky element. `landing-v2.css` has a generic `header { position: sticky; }` rule; the policy stylesheet now resets that policy-title header to normal flow and removes the inherited header chrome. The graph contains no header/CSS node, so current CSS source was the evidence.
