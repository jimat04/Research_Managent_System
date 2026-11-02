# Verification: consolidated research facts and proponents

- Date/time: 2026-10-25, Asia/Taipei
- Assistant: GitHub Copilot
- Target and expected behavior: The research detail page places the people heading and proponent list inside the existing Research facts panel, with no redundant standalone card.
- Environment URL: http://localhost/rms/pages/shared/view-research.php?id=71
- Roles and disposable records/files: Student role; existing page viewed without modifying records or files.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: How are the shared research detail page, research facts, and proponents related? Selected terms: `view-research`, `research facts`, `proponents`, `archive detail`.
- Query/lookup and result: `graphify query "view-research research facts proponents archive detail" --budget 1400`; it found the `view-research.php` node, but truncated 10 of 48 nodes.
- Relevant source locations and verified relationships: Current `pages/shared/view-research.php` source renders the archive status and team facts in the research overview aside. The proponents heading and list are now inside that same aside.
- Missing, inferred, stale, or truncated context: Graph output was truncated and cannot confirm visual hierarchy. Current source is authoritative; browser confirmation is pending.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| 1 | Assistant static check | Run `php -l pages/shared/view-research.php` | PHP parses without errors | `No syntax errors detected` | Passed |
| 2 | Assistant static check | Check patch whitespace and panel source | No whitespace errors; proponents markup is inside facts aside | `git diff --check` reported no whitespace errors; source contains one unified aside with facts and proponents | Passed |
| 3 | Student browser check / research detail page | Refresh the page | Research facts and proponents appear together in one unified panel; no separate people card remains | Student confirmed the unified panel appears as expected | Passed |
