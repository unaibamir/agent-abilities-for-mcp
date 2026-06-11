<!-- Thanks for contributing to Agent Abilities for MCP. Please fill in the sections below. -->

## What?
<!-- Link the issue this closes (e.g. "Closes #12") and describe what the PR does in a sentence or two. -->
Closes #

## Why?
<!-- What problem does this solve, and why now? -->

## How?
<!-- Implementation notes: the approach, any trade-offs, anything a reviewer should look at closely. -->

## Security checklist
<!-- This plugin's whole premise is being safe by default. Confirm the relevant items. -->
- [ ] Input is sanitized and output is escaped.
- [ ] Every ability / REST / admin action has a real capability check (and nonce, for admin actions).
- [ ] No new dangerous primitive (arbitrary option/meta write, URL fetch / SSRF, permanent delete, unredacted PII).
- [ ] Database access uses `$wpdb->prepare()`.

## Testing
<!-- How did you verify this? Commands run, scenarios covered, new tests added. -->
- [ ] `composer phpcs` is clean.
- [ ] `composer phpstan` is clean.
- [ ] `composer test` (PHPUnit) passes, with coverage for the change.

## Changelog
<!-- One line, prefixed with the type: Added / Changed / Fixed / Removed / Security / Developer. -->

## AI assistance
<!-- Optional but appreciated, per the WordPress AI guidelines: note any AI tooling used and how you reviewed its output. Remove if not applicable. -->
