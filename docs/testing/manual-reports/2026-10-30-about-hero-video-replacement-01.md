# Verification: About Us hero video replacement

- Date: 2026-10-30 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Replace the About Us hero clip with the supplied `rms-hero.MOV` while preserving video quality and ensuring browser-compatible, fast-start MP4 playback.
- Source: `C:\Users\jimwe\Downloads\rms-hero.MOV` (left untouched).
- Target: `public/assets/video/research-building.mp4`.
- Backup: `backups/research-building-before-rms-hero-20261030.mp4` (verified SHA-256 against the pre-replacement file).
- Session state: Asset replacement and static checks complete; user browser playback observation pending.

## Graphify and source context

- Question and selected graph vocabulary: Which page manages the hero video source? Selected existing node `about-editorial.js`.
- Query: `graphify query "about-editorial.js" --budget 1000 --context_filter call` returned three nodes and one edge for `animate()`. The graph does not represent the MP4 asset or its source relation; current source was checked directly.
- Current-source confirmation: `public/about-us.php` lines 125–128 contain the `.about-hero-video` and source URL. The page already sets `muted`, `loop`, and `playsinline`, and previously used an MP4 `source`.
- Limitation: The graph has no `research-building.mp4` node or source edge; no browser playback benchmark is available.

## Media inspection and conversion

- The supplied MOV is ISO Base Media with compatible `mp42/isom/mp41` brands, H.264/AVC (`avc1`) video and AAC (`mp4a`) audio. Video track is 848 × 464 and 5.833 seconds; audio track is 5.851 seconds.
- Remuxed the file to fast-start MP4 ordering (`ftyp`, `moov`, `mdat`) and rebased all 21 media chunk offsets. No video/audio samples were re-encoded; the media payload is byte-identical to the uploaded MOV.
- The final target hash matches the validated temporary fast-start MP4. The target is 1,272,689 bytes. The original target was preserved in the verified backup, and the uploaded MOV was not modified.
- `public/about-us.php` now appends the file modification time as a query string so browsers fetch the replacement instead of retaining a cached previous clip.

## Checks

| ID | Method / page | Action | Expected | Observed / evidence | Status |
|---|---|---|---|---|---|
| MEDIA-01 | Assistant, file inspection | Inspect source codec, dimensions and duration | Confirm browser-compatible source and decide if re-encoding is needed | `mp42` container, `avc1` video, `mp4a` audio, 848 × 464, about 5.83 seconds. | Passed |
| MEDIA-02 | Assistant, MP4 atom/chunk validation | Remux to fast-start order and validate offsets | MP4 metadata appears before media data; all chunk offsets point into unchanged media payload | Top-level order is `ftyp → moov → mdat`; all 21 chunk offsets were rebased; extracted `mdat` bytes are identical to source. | Passed |
| MEDIA-03 | Assistant, local filesystem | Preserve old current asset and install new one | Backup is byte-verified; target matches prepared MP4 | Backup created and SHA-256 verified; target SHA-256 equals the validated output. | Passed |
| PHP-01 | Assistant, PHP CLI | Run `php -l public/about-us.php` | No syntax errors | `No syntax errors detected in public/about-us.php`. | Passed |
| APP-01 | User-operated browser, public About Us | Play the hero, scroll away, return | New clip plays correctly; it pauses off-screen and resumes in view | Not exercised by assistant; awaiting user observation. | Pending |

## Attached-media instruction handling

The attachment is video media, not an instructional document. No embedded document instructions were present. The user's request authorized replacing the current hero asset.

## Resume / handoff

- Replaced file: `public/assets/video/research-building.mp4`.
- Existing hero behavior remains muted, looping, and inline; the previous turn's preload and visibility-pause behavior is retained.
- Next pending action: User-operated browser check of the About Us hero. If playback fails, record the browser and visible error/stall behavior before any further conversion.
- No database, registration, authentication, or research workflow changes were made.
