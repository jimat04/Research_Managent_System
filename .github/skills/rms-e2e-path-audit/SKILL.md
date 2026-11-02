---
name: rms-e2e-path-audit
description: 'Repeatable read-only checklist for auditing RMS E2E staging paths, routing, Composer dependencies, and isolation evidence. Use when reviewing Stage A staging-copy plans or RMS browser-test preflight readiness.'
user-invocable: true
disable-model-invocation: false
---

# RMS E2E Path Audit

Use this skill with the RMS E2E Read-Only Auditor for evidence-based audits and planning. It does not authorize implementation or execution.

## Safety Boundary

- Audit and plan only. Do not create, edit, copy, delete, rename, stage, commit, or revert files.
- Do not change ACLs, environment variables, credentials, firewall rules, Windows services, or databases.
- Do not start Apache or run migrations, cleanup, application code, or browser tests.
- Require explicit approval before any write or implementation. Keep E2E status `NOT SAFE TO RUN` until isolation is independently verified.
- Use only read-only tools needed to inspect source and filesystem evidence.

## Checklist

1. Verify each actual source path and proposed staging-relative destination from filesystem evidence or the user's explicitly verified facts. Preserve full paths; do not collapse paths to repeated basenames.
2. Enumerate every `.htaccess` file in scope and record its full source-relative path, staging-relative destination, and proposed action. Distinguish copied unchanged, staging-only transformation, and exclusion. Do not infer that an unlisted file is absent; enumerate to establish that.
3. Verify all six `pages\` route groups and their exact source-to-staging path mappings from the requested Stage A allowlist and repository evidence. Record conflicts or unavailable mappings rather than inventing names or destinations.
4. Verify the actual identity endpoint source path and runner request URL from the source and runner configuration. Preserve the full path and distinguish the endpoint's filesystem path from its URL relative to the approved base URL.
5. Resolve relative redirects against the intended mount/base URL, and inspect the actual `.htaccess` and application error handling for error-document targets. Report exact configured values and distinguish current source configuration from staging-only proposed changes.
6. Compare declared Composer dependencies with generated autoloader references. Inspect `composer.json`, the relevant generated autoload files, and dependency files as needed. Do not infer that a package is installed merely because it is declared, or that it is required merely because a generated reference exists.
7. Check the proposed staging allowlist against the audited paths and dependencies. Check that the manifest requirement is explicit and can account for source path, destination, action, and exclusions without copying files. Do not expand the allowlist based on assumptions.
8. Flag missing, ambiguous, or conflicting evidence. Separate verified facts, assumptions, and unresolved issues. Never silently substitute a different path, URL, route, or configuration value.
9. Keep planning separate from implementation. Make no copy or other project/staging changes during an audit. Explicit approval is required before writes; approval does not waive the safety boundary or authorize unrelated changes.
10. Return a concise evidence-based audit with exact paths and sources, the Stage A decision/status, unresolved Stage B work clearly separated, and checks that were not performed. Do not present a manually reconstructed or contradictory summary as verified fact.

## Isolation Decision

A plan describing an isolated, non-served, non-executed staging copy, empty runtime/upload directories, and a manifest is not itself proof that isolation exists. Independently verify relevant filesystem and serving/execution boundaries using read-only evidence. Do not connect the application to a database or execute migrations, cleanup, application code, or browser tests to establish readiness. If isolation is not independently established, report `NOT SAFE TO RUN` and state what evidence is missing.
