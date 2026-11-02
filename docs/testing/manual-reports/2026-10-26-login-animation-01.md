# Verification: login entrance animation timing

- Date: October 26, 2026 (Asia/Taipei)
- Target: `public/login.php` branding entrance sequence.
- Expected behavior: Current brand elements enter in order without waiting for elements removed from the page; reduced-motion users continue to see the static page.

## Graphify lookup

- Question: How is login animation connected to the shared public motion script?
- Selected graph terms: `login.php`, `public-motion.js`, `switchToLogin`.
- Query: `graphify query "login.php public-motion.js switchToLogin" --budget 1000 --context-filter call`.
- Result: The graph returned 67 nodes and truncated at 32. It found the login page and shared motion script, with unrelated and historical browser-test nodes. Current `public/login.php` does not include `public-motion.js`; its login entrance is inline CSS.
- Source confirmation: `public/login.php` line 649 onward controls staggered brand content; lines 704 onward contain the current branding children. The previous subtitle was removed while the divider/tagline delays remained at 0.40/0.50 seconds. The main seam animation uses a separate 0.15-second initial delay and 1.1-second draw.
- Limitation: Existing graph results are truncated and do not describe the inline CSS sequence. Current source was used to confirm the trigger and delay values.

## Assistant checks

| ID | Check | Expected | Result | Status |
|---|---|---|---|---|
| A1 | `php -l public/login.php` | No syntax errors | No syntax errors detected after both timing adjustments. | Passed |
| A2 | Focused source assertion for ordered delays and reduced-motion guard | Four current brand items have sequential timing; reduced-motion gate remains | Logo/title/divider/tagline now start at 0/0.06/0.12/0.18 seconds. Form card and seam draw start at 0; reduced-motion guard remains. | Passed |
| A3 | `git diff --check` and whitespace review | No whitespace errors | No diff-check errors; no trailing whitespace in the changed page/report. | Passed |
| U1 | Browser: open the login page with normal motion preference (first adjustment) | Branding and divider entrance begin promptly and run in sequence | User reported the animation still felt delayed. Preserved as a failed first observation; a second timing adjustment was applied. | Failed |
| U2 | Browser: reopen/refresh login after second adjustment | Branding and form animation start without an initial pause | User confirmed the login animation now looks good. | Passed |

## User observations

- User reported that the first adjustment still felt delayed, then confirmed the second adjustment resolved it. The assistant did not interact with the application.
