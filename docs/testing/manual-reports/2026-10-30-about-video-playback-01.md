# Verification: About Us hero video playback

- Date: 2026-10-30 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Reduce unnecessary hero-video work when it is off-screen or the browser tab is hidden, and request full buffering for the small looping clip to reduce playback stalls.
- Environment: Local RMS source inspection; browser playback observation pending.
- Roles and records: Public About Us page; no account or database changes.
- Session state: Source change and static checks complete; user browser observation pending.

## Graphify and source context

- Question and selected vocabulary: Where is the public hero video managed? Selected existing graph label `about-editorial.js`.
- Query: `graphify query "about-editorial.js" --budget 1000 --context_filter call` returned three nodes and one containment edge for `animate()` in `public/assets/js/about-editorial.js`. It did not include the video selector/behavior.
- Current-source confirmation: `public/about-us.php` lines 126–128 contain the `.about-hero-video` element and `research-building.mp4` source. `public/assets/js/about-editorial.js` lines 22–47 manage playback; before the change, the video was started once and continued looping without checking viewport or tab visibility. `css/about-editorial.css` lines 115–127 place it as a full-hero background with a scrim.
- Asset evidence: `public/assets/video/research-building.mp4` is 747,840 bytes, approximately 5.83 seconds, with an 848 × 464 video track. The asset is small; browser-side decoding while off-screen and buffering remain plausible contributors, but no device playback measurement was available.
- Missing context: No before/after browser frame-rate, buffering, network, or CPU measurements were available. The Graphify map does not represent the video selector; current source was used.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence | Status |
|---|---|---|---|---|---|
| SRC-01 | Assistant source/asset inspection | Trace the authored video and its playback lifecycle | Identify the actual video and avoid speculative large-asset changes | Only authored video found in application source is the About Us hero clip; asset is 747,840 bytes / about 5.83 s / 848 × 464. Existing playback kept looping after scrolling away. | Passed |
| PHP-01 | Assistant, PHP CLI | Run `php -l public/about-us.php` | No syntax errors | `No syntax errors detected in public/about-us.php`. | Passed |
| JS-01 | Assistant, Node CLI | Run `node --check public/assets/js/about-editorial.js` | No JavaScript syntax errors | Command exited 0. | Passed |
| APP-01 | User-operated browser, public About Us | Observe hero playback, scroll away, then return | Video plays smoothly in view, pauses off-screen/hidden, and resumes when visible | Not exercised by assistant; awaiting user observation. | Pending |

## Changes

- Changed the hero video from `preload="metadata"` to `preload="auto"` for this sub-1 MB clip.
- The playback helper now pauses when reduced motion is requested, the document is hidden, or the video is less than 10% visible. It resumes when the hero returns to view or the tab becomes visible. Existing muted/loop/inline behavior remains.

## Failures, decisions, fixes, and retests

- No syntax-check failures. The user-reported lag was not independently measured, so this is a targeted playback-resource improvement rather than a confirmed benchmark result.
- No video re-encoding was performed; no local encoder was available, and the existing asset is already small and moderate in resolution.

## Resume / handoff

- Next pending action: Ask the user to test the About Us hero in the integrated browser and report whether playback is smooth and pauses/resumes correctly. If the lag occurs on a different page/video, inspect that specific source instead.
- No database, auth, registration, workflow, or research records were changed.
