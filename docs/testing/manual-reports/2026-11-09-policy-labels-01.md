# Verification: policy labels

- Date/time: 2026-11-09 (Asia/Taipei)
- Assistant: Codex
- Target and expected behavior: The Terms of Service and Privacy Policy page titles, review labels, and cross-links/public footer labels omit the “Draft” label. Both page review notices are removed while their policy text continues to describe approval requirements.
- Environment URL: `http://localhost:8080/rms/public/terms-of-service.php` and `http://localhost:8080/rms/public/privacy-policy.php`
- Roles and disposable records/files: Public pages; no records changed.
- Session state: Finished

## Graphify and source context
- Question and selected graph vocabulary: Find graph context for public policy labels. Selected `policy` after checking available graph and query tool.
- Query/lookup and result: `graphify query "policy" --budget 1500`; no matching nodes found. Repeated for both notice removals; the result was unchanged.
- Relevant source locations and verified relationships: `public/terms-of-service.php` supplies the terms title, review notice, and privacy link; `public/privacy-policy.php` supplies the privacy title, review notice, and terms link; `includes/public-footer.php` supplies public policy footer labels. Confirmed against current source.
- Missing, inferred, stale, or truncated context: No matching Graphify nodes. CLI ran successfully; source inspection used as fallback.

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|
| S1 | Assistant static source check | Search affected files for “Draft” | Only policy body text that describes the unapproved content may retain the term | `rg -n -i 'Draft' public/terms-of-service.php public/privacy-policy.php includes/public-footer.php` found only body paragraphs explaining approval limitations; visible titles, review labels, cross-links, and footer labels no longer contain it | Passed |
| S2 | Assistant PHP syntax check | Run `php -l` on changed PHP files | All files parse | No syntax errors in `public/terms-of-service.php`, `public/privacy-policy.php`, or `includes/public-footer.php` | Passed |
| S3 | Assistant static source check | Search Terms page for review notice text | The quoted notice is absent and the intro still identifies the terms as unapproved | Search found no review notice; intro still says the terms are not an approved institutional agreement | Passed |
| S4 | Assistant static source check | Search Privacy page for review notice text | The review notice is absent and policy text still describes approval requirements | No review notice remains; body text still says institutional confirmation is needed before final publication | Passed |
| B1 | User browser / public page | Open Terms of Service URL and inspect title, intro, and policy links | Heading says “Terms of Service”; intro says terms are not an approved agreement; removed review notice is absent; visible policy labels omit “Draft” | User confirmed “all passed” for both pages | Passed |
| B2 | User browser / public page | Open Privacy Policy URL and inspect title, content, and policy links | Heading says “Privacy Policy”; removed review notice is absent; institutional-confirmation text and clean policy labels remain | User confirmed “all passed” for both pages | Passed |

## Failures, decisions, fixes, and retests
- None.

## Assistant-run checks
- `php -l public/terms-of-service.php`, `php -l public/privacy-policy.php`, and `php -l includes/public-footer.php`: passed.
- Targeted `rg` for “Draft” in the affected files: only body copy describing the unapproved content remains.
- After removing the Terms review notice, `php -l public/terms-of-service.php` passed; targeted source search confirmed the notice is gone and the unapproved-status intro remains.
- After removing the Privacy review notice, `php -l public/privacy-policy.php` passed; targeted source search confirmed the notice is gone and policy text still describes institutional confirmation requirements.

## Resume / handoff
- Last user-confirmed state: User confirmed “all passed” for both policy pages.
- Next pending action: None.
- Blocked/skipped checks and remaining verification gaps: None reported.
- User-performed data changes and cleanup status: None.
