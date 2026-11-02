# Verification: About hero background video

- Date/time: 2026-10-29 23:21 Asia/Taipei
- Assistant: Codex
- Target and expected behavior: The About Us hero shows the supplied EARIST building clip as a muted, looping background while keeping the headline and link readable. Reduced-motion preference leaves the poster image visible without playback.
- Environment URL: http://localhost/rms/public/about-us.php
- Roles and disposable records/files: Public visitor; no records changed.
- Session state: In progress

## Graphify and source context
- Question and selected graph vocabulary: About Us hero video; `about`, `public`, and `hero` matched existing graph labels.
- Query/lookup and result: Read-only `graphify query "about public hero" --budget 1500` returned public landing and archive references, with no direct About page node. `graphify query "about-editorial.js" --budget 1500` found `public/assets/js/about-editorial.js` and its `animate()` relationship.
- Relevant source locations and verified relationships: `public/about-us.php:125` owns the About hero and loads `css/about-editorial.css` and `public/assets/js/about-editorial.js` (lines 106 and 474). Current source confirms the video markup is in the hero, scoped styles are in the About stylesheet, and reduced-motion playback handling is in the About script.
- Missing, inferred, stale, or truncated context: Graph has no direct `public/about-us.php` node for this behavior; old visual test nodes are hints only. Current source was used as authority. No graph rebuild performed.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static check | Run `php -l public/about-us.php` | PHP syntax is valid | `No syntax errors detected` | Passed |
| S2 | Assistant static check | Run `node --check public/assets/js/about-editorial.js` | JavaScript parses | Exit code 0 | Passed |
| S3 | Assistant static check | Compare opening and closing braces in `css/about-editorial.css` | Counts match | 263 opening and 263 closing braces | Passed |
| S4 | Assistant static check | Compare opening and closing braces in `public/assets/css/public-header.css` | Counts match | 42 opening and 42 closing braces | Passed |
| S5 | Assistant static check | Run `php -l public/about-us.php` | PHP syntax is valid | `No syntax errors detected` | Passed |
| S6 | Assistant static check | Compare opening and closing braces in `css/about-editorial.css` after styling change | Counts match | 266 opening and 266 closing braces | Passed |
| S7 | Assistant static check | Compare opening and closing braces in `css/about-editorial.css` after outline change | Counts match | 266 opening and 266 closing braces | Passed |
| S8 | Assistant static check | Compare opening and closing braces in `css/about-editorial.css` after size/spacing change | Counts match | 267 opening and 267 closing braces | Passed |
| S9 | Assistant static check | Compare opening and closing braces in `css/about-editorial.css` after bottom adjustment | Counts match | 267 opening and 267 closing braces | Passed |
| S10 | Assistant static check | Compare opening and closing braces in `public/assets/css/public-theme.css` after scrollbar change | Counts match | 12 opening and 12 closing braces | Passed |
| B1 | User browser / public visitor / About Us | Open or refresh the About Us page at the environment URL | Video starts silently, loops, and the headline and link remain readable | Awaiting user observation | Pending |
| B2 | User browser / public visitor / About Us / narrow viewport | Resize to a phone-width viewport | Hero text stays readable and the video crop does not cause horizontal overflow | Awaiting user observation | Pending |
| B3 | User browser / public visitor / About Us / reduced motion | Enable the operating system or browser reduced-motion preference and reload | Video stays paused on the poster image | Awaiting user observation | Pending |
| B4 | User browser / public visitor / About Us | View the page after the shared header spacing change | The background video shows through the gap above the navigation pill | User-provided screenshot shows the video in the top gap | Passed |
| B5 | User browser / public visitor / Home | Open the public Home page | The homepage hero shows through the gap above the shared navigation pill | Awaiting user observation | Pending |
| B6 | User browser / public visitor / Research Archive | Open the Research Archive page | The page background shows through the gap above the shared navigation pill | Awaiting user observation | Pending |
| B7 | User browser / public visitor / About Us / desktop viewport | Reload the page at desktop width | The section links sit lower, leaving more open space at the bottom of the hero after its CTA | User screenshot shows the section bar lower and more open space below the CTA | Passed |
| B8 | User browser / public visitor / About Us / desktop first viewport | View the page immediately after opening it | Hero fills the first viewport and the section-link bar is below the fold | Previous screenshot showed the section-link bar within the first viewport | Failed |
| B9 | User browser / public visitor / About Us / desktop first viewport / retest | Reload the page after the viewport-height adjustment | Hero fills the first viewport and the section-link bar is below the fold | User confirmed the updated desktop layout is okay | Passed |
| B10 | User browser / public visitor / About Us | Reload the page after the shared header gap adjustment | No white line remains at the top; hero background reaches the top edge | Awaiting user observation | Pending |
| B11 | User browser / public visitor / About Us / desktop | Reload the page after the hero color/spacing adjustment | “EARIST” appears in maroon and the hero keeps its first-viewport layout | Awaiting user observation | Pending |
| B12 | User browser / public visitor / About Us | Reload after making the word backing fully white | Only “EARIST” has a solid white backing; the surrounding title remains white on video | User reports it still looks the same as the prior backing effect | Failed |
| B13 | User browser / public visitor / About Us | Reload after replacing the backing with a text outline | “EARIST” has white outer letter strokes and maroon fill, without a white rectangle | Awaiting user observation | Pending |
| B14 | User browser / public visitor / About Us / desktop | Reload after spacing/size adjustment | “EARIST” is visibly larger and has roughly a two-space gap after “at” | Awaiting user observation | Pending |
| B15 | User browser / public visitor / About Us / narrow viewport | Resize to phone width | Larger word still fits without horizontal overflow | Awaiting user observation | Pending |
| B16 | User browser / public visitor / About Us / desktop | Reload after bottom spacing change | The bottom hairline is gone and hero extends 12px farther | Awaiting user observation | Pending |
| B17 | User browser / public visitor / public pages | View the page scrollbar | Track and thumb are transparent while scrolling remains possible | Awaiting user observation | Pending |

## Failures, decisions, fixes, and retests
- B8: The prior screenshot showed the section-link bar within the desktop first viewport, which did not meet the user's clarified goal. The user directed a fix. Set the desktop hero minimum height to one viewport; B9 is the retest.
- B10: The user's screenshot still showed a thin white top line. Source calculation found a 2px residual gap from the shared header's top and bottom margins. Increased the negative bottom margin by another 2px at each breakpoint so the hero reaches the top edge; B10 is the retest.
- B11: Added the requested 2px to the About hero's desktop bottom padding and styled “EARIST” in maroon on a light backing for contrast; browser retest pending.
- B12: Changed the word-only backing from translucent white to solid white to match the supplied reference; browser retest pending.
- B12: User reports the solid backing did not achieve the intended double-layer letter treatment. The user clarified that the letters should have white outer strokes with maroon fill; B13 retests that effect.
- B14/B15: Added extra inline spacing and enlarged “EARIST”; the narrow layout uses a smaller scale increase to help it fit. Browser checks pending.
- B16/B17: Removed the hero bottom border, extended its desktop minimum height by 12px, and made the public-page scrollbar track and thumb transparent. Browser checks pending.

## Assistant-run checks
- `php -l public/about-us.php` — passed.
- `node --check public/assets/js/about-editorial.js` — passed (exit code 0).
- PowerShell CSS brace count — passed (263/263).
- PowerShell shared-header CSS brace count — passed (42/42).
- Converted the supplied 5.83-second H.264 MOV to an H.264 MP4 with audio removed, 24 fps, and fast-start metadata; output is `public/assets/video/research-building.mp4` (747,840 bytes).
- No database changes.
- For the shared public header follow-up, Graphify vocabulary `Public Navigation` was matched from existing node labels; `graphify query "Public Navigation" --budget 900` returned historical screenshot nodes and the archive page, but no header stylesheet relationship. Current source confirms the shared stylesheet is loaded by Home (`public/index.php:33`), About (`public/about-us.php:105`), and Research Archive (`public/research-archive.php:377`); each includes `includes/public-header.php`.
- For the desktop hero spacing follow-up, selected graph vocabulary was `about-editorial.js`. A broad About layout lookup was truncated, so it was narrowed to `graphify query "about-editorial.js" --budget 800`; this returned the script, its `animate()` helper, and an older About layout screenshot node. No graph node describes hero spacing. Current source confirms `.about-page .about-hero` owns the hero padding and the section navigation follows the hero in `public/about-us.php`.
- User-provided screenshot shows the About hero video visible through the top gap around the shared header. This confirms that top-gap appearance only; it does not establish looping or muted playback.
- Desktop spacing implementation sets the hero minimum height to `100svh` and preserves its enlarged bottom padding at widths of 981px and above. The retest remains pending.
- PHP lint passed after adding the highlighted hero word; About CSS braces remain balanced (266/266).
- For the maroon hero text and 2px bottom adjustment, repeated the read-only Graphify lookup using matched vocabulary `about-editorial.js`; it returned only the About script, its `animate()` helper, and an old visual layout node. No graph relationship covers this PHP/CSS markup, so current source was checked directly.
- For the solid backing follow-up, repeated `graphify query "about-editorial.js" --budget 800`; graph coverage still contains only the About script and old visual test node, with no direct CSS relationship. Current source confirms the word-only selector lives in `css/about-editorial.css` and is loaded by About Us.
- For the double-layer text follow-up, repeated `graphify query "about-editorial.js" --budget 800`; it still has no direct CSS/hero-title relationship. Current source now applies a 2px white text stroke behind the maroon fill; CSS braces remain balanced (266/266).
- For word spacing and size, repeated `graphify query "about-editorial.js" --budget 800`; no direct hero typography node is present. Current CSS increases the word size and margin, with a smaller relative size increase on narrow screens. CSS braces remain balanced (267/267).
- For the bottom edge and scrollbar follow-up, the focused `Public Navigation` and `about-editorial.js` Graphify queries returned only historical public-page/navigation and script nodes; neither exposes these CSS rules. Current source confirms the hero rules in `css/about-editorial.css` and public scrollbar styling in `public/assets/css/public-theme.css`, which is loaded by Home, About, and Research Archive. CSS braces passed in both files.

## Resume / handoff
- Last user-confirmed state: User confirmed the updated About Us desktop layout is okay.
- Next pending action: User reloads About Us and confirms the bottom edge and transparent scrollbar.
- Blocked/skipped checks and remaining verification gaps: B10 top-line, B11 hero styling, B13 text-outline, B14 desktop size/gap, B15 narrow layout, and B16/B17 bottom/scrollbar checks; video loop/mute behavior; reduced-motion; Home; and Research Archive browser checks are pending.
- User-performed data changes and cleanup status: None.
