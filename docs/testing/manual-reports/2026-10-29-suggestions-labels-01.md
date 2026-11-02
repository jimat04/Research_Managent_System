# Suggestions label wording verification

- Date: 2026-10-29 (Asia/Taipei)
- Scope: Replace user-facing “recommendations” wording with “Suggestions” across proposal/chapter review forms, confirmation content, review history, chapter labels, and related descriptions. Database keys and internal identifiers remain unchanged.
- Expected behavior: Reviewers and students see Suggestions wording wherever the former Recommendations label or copy appeared; existing feedback continues to display.

## Graph lookup

- Question: Trace review feedback labels in the shared viewer and research review pages.
- Terms: comments, suggestions, review, feedback, label, research submission, viewer.
- Lookup: `graphify query "Comments Suggestions review feedback label research submission viewer" --budget 800 --context-filter call`.
- Result: Truncated (19 of 50 nodes); returned review-page hints and stale report-image nodes. Current source was checked directly; graph coverage is incomplete for the edited labels.

## Checks

| Check | Role / page | Action | Expected | Result | Status |
|---|---|---|---|---|---|
| S1 | Assistant / changed PHP and JavaScript | Run PHP syntax checks, JS syntax check, and scoped diff whitespace check | No syntax or whitespace errors | All 12 changed PHP files passed `php -l`; `node --check js/research-document-review.js` and scoped `git diff --check` passed | Passed |
| U1 | Integrated browser / authorized reviewer / shared review queue | Open `research-submission-review.php?module_page=research_review` and inspect a pending submission | Review input is labeled “Comments/Suggestions” | Awaiting user observation | Pending |

## Findings and limits

- Product-facing wording was changed; database columns, POST keys, and internal variable names still use `recommendation` so existing saved feedback and handlers continue to work.
- Browser confirmation of the labels and existing feedback display is pending.
