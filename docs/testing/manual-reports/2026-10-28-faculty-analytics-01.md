# Verification: faculty research analytics

- Date/time: 2026-10-28, Asia/Taipei
- Assistant: Codex
- Target and expected behavior: `faculty-dashboard.php?view=analytics` renders a distinct analytics view with aggregates from the signed-in faculty member's linked research projects, published outputs, and engagement records.
- Environment URL: `http://localhost/rms/` (configured fallback; availability not confirmed)
- Roles and disposable records/files: Faculty; read-only review, no record changes planned.
- Session state: finished

## Graphify and source context
- Question and selected graph vocabulary: How are faculty dashboard and publication data sources related? Selected terms: `faculty-dashboard.php`, `publications.php`, `research_projects`.
- Query/lookup and result: `graphify query "faculty-dashboard.php publications.php research_projects" --budget 1400` found 99 nodes, truncated (41 shown). It returned the faculty dashboard, publications page, faculty shell, and research_projects schema nodes, but did not establish analytics behavior. The engagement table did not appear in matching graph vocabulary.
- Relevant source locations and verified relationships: `pages/faculty/faculty-dashboard.php` already restricts projects to the signed-in faculty user's created/advised/member projects, filters soft-deleted projects, scopes publications by `researcher_id`, and scopes engagement by `faculty_id`. `database/schema/rms_db.sql` confirms project workflow statuses, publication date, and engagement review statuses. `database/migrations/024_faculty_research_engagement.sql` confirms the engagement status enum. These existing ownership scopes will be reused.
- Limitations: Graph output was truncated and did not expose the engagement table or current view rendering; current PHP and canonical schema were checked directly.

## Checks
| Check | Expected | Observation | Status |
|---|---|---|---|
| Assistant: `php -l pages/faculty/faculty-dashboard.php` | No syntax errors | `No syntax errors detected` | Passed |
| Assistant: CSS brace balance and `git diff --check` for changed files | Balanced CSS blocks; no whitespace errors | 352 opening and closing CSS braces; no whitespace errors reported. Git noted an existing PHP line-ending normalization warning. | Passed |
| Faculty: analytics route | Dedicated charts show linked-record aggregates; overview lists are absent; empty/unavailable data is described accurately | User reports charts and layout look right; individual numeric values were not reported | Passed |
| Faculty: narrow/mobile analytics route | Charts stack and remain readable without horizontal overflow | User reports charts and layout look right at desktop and mobile widths | Passed |

## Results and follow-up
- Database effects: none; queries are read-only and no application records are changed.
- Browser observations: user confirmed the charts and layout look right at desktop and mobile widths; no numeric values were provided for independent comparison.
- Source review: analytics route renders only three real aggregates: linked non-deleted projects by existing status, published outputs by publication year for the last five calendar years, and engagement records by the existing verified/pending/returned states. Project queries preserve the existing owner/adviser/member scope; publication and engagement queries are scoped to the signed-in faculty account. Empty and unavailable states are explicit. No schema or database writes were added.
- Next action: none.
