# RMS User-Operated Verification Guide

This is the default verification workflow for Codex and GitHub Copilot in RMS. Apply it whenever the user asks to test, check, confirm, validate, or review behavior, and whenever a feature, fix, workflow, permission, or UI change needs verification. The user does not need to name this guide, use a special prompt, or select a testing agent.

The assistant investigates and guides. **The user performs every application interaction in the integrated browser.** Give one actionable step, ask a specific question, and wait for the user's response before continuing. No E2E setup, Node.js installation, Playwright run, or `RMS_E2E_*` environment variables are required for this workflow.

## Who does what

| Work | Owner |
|---|---|
| Read relevant source, trace dependencies with Graphify, prepare checks | Copilot or Codex |
| Navigate to pages, open links, log in/out, switch accounts | User |
| Enter text, choose options, toggle controls, click buttons | User |
| Choose/upload files, preview/download documents, open verification emails | User |
| Save, submit, approve, reject, return, delete, or otherwise change application records | User |
| Run focused non-mutating checks, such as PHP syntax checks or documentation link checks | Copilot or Codex |
| Record observations, explain failures, maintain the report | Copilot or Codex |
| Investigate and fix a failure found during testing | Copilot or Codex, after asking whether to fix now or continue testing |

The user enters credentials directly into the application. Never ask for passwords, session cookies, CSRF tokens, verification links/tokens, or other secrets in chat, reports, screenshots, or logs. If evidence is needed, request only the relevant error text or a redacted image, not sensitive records or document contents.

Follow the normal application flow. Do not bypass authentication, permissions, validation, CSRF protection, submission locks, upload safeguards, or approval requirements. Do not substitute scripted browser actions, direct API requests, SQL changes, fabricated sessions, seeded workflow states, or test hooks for an action the user should perform. Do not weaken safeguards to make a check pass.

A later explicit request for automation must be addressed separately and never authorizes bypassing application safeguards. No browser E2E suite or harness README is maintained. Agree on a separate automation scope and follow the isolation requirements in [AGENTS.md](../../AGENTS.md) before any authorized mutating automation; never use the normal development database `rms_db`.

## 1. Establish the target and prepare context

1. Start with the requested behavior and the relevant [root instructions](../../AGENTS.md). Read the owning page, nearest handler/helper, permissions, and applicable workflow rules. Do not inventory the whole repository.
2. Identify the expected outcome, roles, application URL, and suitable disposable records/files. Derive routes, labels, accepted inputs, and transitions from current source. Historical walkthroughs are leads, not current acceptance criteria.
3. Ask only for information that cannot be discovered safely, such as which authorized account role or disposable record the user has available. Read the non-secret `SITE_URL` setting when needed; do not print `.env` contents or credentials. The configured fallback is `http://localhost/rms/`, not a guarantee that the app is running there.
4. Explain prerequisites and any consequences before asking the user to change application state. Use authorized local/test accounts and uniquely named disposable records. Do not use production data for mutations, provision a database, reset accounts, or perform automatic cleanup.
5. If an account, permission, prerequisite record, email, file, or browser is unavailable, guide the user through the supported process where possible. Mark dependent checks **Blocked** and continue only independent checks. Do not create an alternate route around the prerequisite.
6. Open or resume the session report before the first check. For documentation-only or other non-browser work, run the relevant static checks and record browser checks as **Skipped** with the reason; do not invent an application interaction.

## 2. Consult Graphify for every verification task

Use the concise [read-only Graphify lookup](../agent-reference/graph-lookup.md), which retains the imported skill's query controls without loading graph-build instructions. Load the imported query reference only for lookup details not covered there. This applies even to a focused function check. Reuse the existing [graph](../../graphify-out/graph.json); do not automatically install tools, start watchers, or rebuild/update the graph.

1. Check that the graph and query command are available. Read existing lessons if useful; keep this preparation read-only and keep new findings in the session report instead of running graph reflection or saving results into the graph automatically.
2. Match the behavior to actual node labels/vocabulary, following the lookup procedure's constrained query expansion. Keep vocabulary inspection in memory. State the selected terms, then run a focused query, for example `graphify query "<terms present in the graph>" --budget 1500`. The angle-bracket text is an instruction placeholder, not a literal command argument.
3. Retrieve only relevant pages, callers, handlers, shared helpers, permission checks, data dependencies, and existing test references. Use a path lookup when tracing a specific connection. Do not dump the entire map.
4. Verify findings against current source, including source locations and graph confidence. Treat inferred edges as hints and missing edges as unknowns. Code wins when the map and source disagree.
5. Record the question, selected terms, command or direct lookup, useful source locations, verified relationships, and limitations in the report. Note truncated results or stale coverage; narrow the query when necessary.
6. If the CLI is unavailable or fails, inspect the existing JSON's relevant nodes and edges directly without requiring a new dependency. If no useful graph context exists, record that limitation and continue with targeted source inspection.
7. Repeat a focused lookup when the tested behavior changes or an unexpected dependency appears, not for every click. Do not treat browser observations as established graph relationships. The report can inform a later explicitly requested graph update.

## 3. Prepare the checks

Create the smallest checklist that can establish the requested behavior. Include the expected path, relevant invalid input, persistence after refresh/reopening, and applicable role/ownership boundaries. For sensitive records/documents, include user-performed direct-route checks when appropriate, using authorized disposable records and normal browser navigation. Never ask the user to paste secrets into a URL or manually bypass request protections.

Preserve RMS rules: student/faculty title and chapter submissions use the canonical researcher workflow; drafts remain editable, submitted versions lock, and chapters require title approval. Review actions require admin or explicitly authorized staff permissions. Returned/rejected submissions retain comments and revision history. Derive additional constraints from current code rather than assuming generic research stages.

Explain what browser confirmation can establish and what still requires source review or another verification method. A visible validation message alone does not prove server-side authorization or CSRF enforcement. Record unverified backend behavior honestly; do not attempt hidden requests to fill the gap.

## 4. Guide one action, then wait

Use this format for each browser step:

> **Check / step:** [identifier and purpose]
>
> **Role:** [role needed]
>
> **Page:** [verified link or navigation path]
>
> **Action:** [one user-performed action]
>
> **Expected:** [observable result]
>
> **Confirm:** [specific question about the result]

Keep navigation, data entry, button clicks, file selection, and role changes separate when they have distinct outcomes. Do not give a full sequence of unconfirmed actions. If the user is on a different page or reports a different state, resolve that before continuing. A form entry and its submission are separate steps.

Wait for the user's response to the current question. Accept natural-language replies; offer concise choices such as "matched / different result / blocked" when helpful. A reply such as "done" confirms only the action unless it also establishes the expected outcome. Ask what appeared before recording a pass. Silence is not confirmation. If the user supplies observations for multiple checks, record only what those observations actually establish.

After each response, update the report before the next step. An expected result is not an observation, and the assistant must not claim to have operated or seen the user's browser.

### Example conversation

These exchanges illustrate the interaction pattern, not a verified RMS test run. Resolve exact labels, routes, prerequisites, and file requirements from current source before using them.

**Assistant:** Role: student. Page: the verified RMS login URL. Action: open that URL in your integrated browser. Expected: the login form appears. Confirm: do you see the login form or a different page?

**User:** I see the login form.

**Assistant:** On that form, enter your authorized student credentials directly in the browser. Expected: the fields are filled. Confirm: are the fields ready? Do not send the credentials here.

**User:** They are ready.

**Assistant:** Click the login button. Expected: the student dashboard appears. Confirm: do you see the dashboard, an error, or another page?

**User:** The student dashboard appears.

**Assistant:** Open the verified title-submission page using its navigation link. Expected: the submission form appears. Confirm: is that form visible?

**User:** Yes, the form is visible.

**Assistant:** In the title field, enter the unique disposable title agreed for this session. Expected: the title appears in the field. Confirm: does it appear correctly?

**User:** Yes.

**Assistant:** Use the form's file chooser to select the agreed small test PDF. Expected: the selected filename appears without a validation error. Confirm: is the filename shown, or do you see an error?

**User:** Done.

**Assistant:** Is the selected filename visible, and is there any error message?

**User:** The filename is visible and there is no error.

Continue with any remaining fields, one confirmed step at a time. Only then ask for a save/submission click, explain its consequences, and confirm its actual result. File selection alone does not establish successful upload or persistence.

## 5. Handle failures, pause, and resume

- On a failure, preserve the expected outcome and exact non-sensitive observation, current role/page, disposable record identifier, and reproduction steps. Ask: "Investigate and fix this now, or record it and continue independent checks?" Wait for the answer before changing application code to address that failure.
- If the user chooses a fix, inspect the relevant source and Graphify context, make the authorized change, run suitable non-mutating checks, then guide the user through the affected browser check again. Keep the original failure and append the retest result. Do not fabricate an intermediate application state to speed up the retest.
- Mark dependent checks **Blocked** when the failure prevents them. Do not mark unexecuted checks as passed or failed.
- When pausing, save the current role/page without session details, relevant disposable identifiers, and exact next action. On resuming, read the report and ask the user to confirm the current page/login state before continuing. Never assume a session survived.
- At completion, report confirmed results, failures, blocked/skipped checks, assistant-run checks, database effects of user actions, and remaining gaps. Cleanup is a separate user-performed supported action, limited to session-created records; never delete records or reset a database automatically.

## 6. Save the session report

Use `docs/testing/manual-reports/YYYY-MM-DD-workflow-NN.md` with the local date in **Asia/Taipei**. Use a short lowercase hyphenated workflow name and the next unused two-digit suffix for that date/workflow. Never overwrite another session. Update the same file throughout a session, including fixes and retests; a small follow-up check belongs in that session rather than a new report.

Create the report directory when saving the first session. Do not commit credentials, private document contents, full verification URLs, or raw network/session dumps. Keep observations concise and attributed to the user or assistant-run command.

Use these check statuses consistently:

| Status | Meaning |
|---|---|
| Pending | Prepared or awaiting the user's observation; no established result yet |
| Passed | The user explicitly confirmed the expected browser outcome, or a named assistant-run static check actually passed |
| Failed | An observed result or executed check differs from the expected outcome |
| Blocked | A missing prerequisite or failure prevents execution |
| Skipped | Intentionally not exercised, with a stated reason |

Use the following report structure, replacing the prompts with actual session information:

```markdown
# Verification: [workflow]

- Date/time: [Asia/Taipei]
- Assistant: [Codex or GitHub Copilot]
- Target and expected behavior: [scope]
- Environment URL: [non-secret URL, or not applicable]
- Roles and disposable records/files: [no credentials]
- Session state: [in progress, paused, or finished]

## Graphify and source context
- Question and selected graph vocabulary:
- Query/lookup and result:
- Relevant source locations and verified relationships:
- Missing, inferred, stale, or truncated context:

## Checks
| ID | Method / role / page | Action | Expected | Observed / evidence source | Status |
|---|---|---|---|---|---|

## Failures, decisions, fixes, and retests
- Preserve initial failures; record the user's fix/continue decision and each retest.

## Assistant-run checks
- Exact non-mutating commands, actual results, and limitations.

## Resume / handoff
- Last user-confirmed state:
- Next pending action:
- Blocked/skipped checks and remaining verification gaps:
- User-performed data changes and cleanup status:
```

Optional starting prompt (this workflow also activates without it):

> Use the RMS manual testing guide and Graphify to help me test [function/workflow]. I will operate the integrated browser. Give me one step at a time, wait for my observations, and save the results.
