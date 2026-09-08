# Security Policy

## Supported versions

Security fixes go onto the current release. Older versions are not patched separately.

## Reporting a vulnerability

Report security issues privately so a fix can go out before the details are public. Please don't open a public GitHub issue or post in the WordPress.org support forum for anything security-related.

Two private channels:

- Preferred: open a private report on GitHub. Go to the repository's Security tab and choose "Report a vulnerability." The thread stays private until there's a fix.
- Email: unaibamiraziz@gmail.com

To make triage faster, include what you can: the affected version, what the issue is and what it lets an attacker do, and the steps to reproduce it. A small proof of concept helps a lot.

You'll get an acknowledgement within a few days, then an assessment and coordination on a fix and a disclosure timeline. Reporters who want credit get it once the fix ships.

The plugin has two outbound network paths and no telemetry: the Connection tab's reachability check against your own site, and the upload-media-from-url ability fetching a URL an agent supplies, which stays off until you enable it. It stores OAuth 2.1 credentials as SHA-256 hashes in its own tables. Reports most often land on capability checks, input handling, the OAuth flow, or the SSRF hardening around that URL fetch.
