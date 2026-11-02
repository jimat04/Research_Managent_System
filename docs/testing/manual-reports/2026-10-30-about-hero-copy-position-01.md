# Verification: About Us hero copy position

- Date: 2026-10-30 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: Move the About Us hero kicker, heading, description, and archive button lower without changing the hero/video layout.
- Environment: Local source edit; browser observation pending.
- Session state: CSS change and static checks complete; user browser observation pending.

## Graphify and source context

- Question and selected terms: How is the About Us page and its presentation script represented? Selected graph labels `about-editorial.js`, `about.php`.
- Query: `graphify query "about-editorial.js about.php" --budget 1000 --context_filter call` returned 63 nodes and was truncated at 31. The result did not provide a useful CSS relationship. Current source was checked directly.
- Confirmed source: `public/about-us.php` lines 125–136 wraps the kicker, heading, lead, and CTA in `.about-hero-copy`; `css/about-editorial.css` line 105 defines the hero flex layout, line 133 styles the copy, and lines 502–506 contain the phone breakpoint.
- Limitations: Graphify has no useful node/edge for the hero CSS rule; its truncated results were not used as evidence of layout behavior.

## Checks

| ID | Method / role / page | Action | Expected | Observed / evidence | Status |
|---|---|---|---|---|---|
| CSS-01 | Assistant, source check | Inspect the hero-copy positioning rules | Offset only the copy; preserve section/video dimensions | Added a responsive 24–42px downward transform to `.about-hero-copy`, with an 18px offset at the phone breakpoint. No hero, video, or section dimensions changed. | Passed |
| CSS-02 | Assistant, static CSS check | Check brace balance and whitespace errors | Balanced stylesheet; no diff whitespace issues | `css/about-editorial.css` has 268 opening and 268 closing braces; `git diff --check -- css/about-editorial.css` reported no errors. | Passed |
| APP-01 | User-operated browser, public About Us | View the hero at desktop/mobile sizes | Copy appears lower and remains visible/readable | Not exercised by assistant; awaiting user observation. | Pending |

## Resume / handoff

- Changed file: `css/about-editorial.css`.
- Next pending action: Ask the user to view the About Us hero and confirm the new copy position, especially on their target screen size.
- No database, application workflow, or research data was changed.
