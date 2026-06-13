=== Agent Abilities for MCP ===
Contributors: unaibamir
Tags: mcp, ai, agents, abilities, model context protocol
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give an AI agent scoped, audited access to your WordPress site over the Model Context Protocol — least privilege by design, off by default.

== Description ==

Give an AI agent access to your WordPress site without handing it the keys. Agent Abilities for MCP connects agents over the Model Context Protocol as a WordPress user you choose — point it at a dedicated low-privilege account and it can only ever do what that account is allowed to do. Everything is off until you turn it on, and every action is logged. No admin-equivalent key, no custom transport, no custom OAuth — it is built on the WordPress Abilities API and the official MCP Adapter.

Twenty-four core abilities cover reading and (optionally) writing posts, pages, terms, comments, media, and site structure. You decide, per ability, what an agent can touch.

Highlights:

* Least privilege by design — the agent connects as a real, scoped WordPress user through Application Passwords. No admin-equivalent key.
* Off by default — nothing is exposed until you enable it; updates never silently widen access.
* Two-layer capability gating — a connection only sees the tools its user can call, and every call re-checks the user's capability before it runs.
* Honest audit log — every call is recorded, including denied attempts, with the principal and the argument keys (never the values).
* Safe by construction — no arbitrary option or meta access, no URL fetch, no user creation, no code execution. Deletes go to Trash, not gone. Uploads are decoded from inline data, sniffed by their real bytes against an image allow-list, and never fetched from a URL.
* Guided setup — create the agent user, copy a client config, and run a connection check from one screen.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/agent-abilities-for-mcp` directory, or install through the WordPress plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open Settings → Agent Abilities. On the Connection tab, create the dedicated agent user and copy the endpoint and client config.
4. On the Abilities tab, enable only the abilities you want the agent to have. Everything starts off.
5. Create an Application Password for the agent user, point your MCP client at the endpoint, and use the Connection tab to confirm it is reachable.

== Frequently Asked Questions ==

= Does the agent get admin access? =

No. The agent authenticates as whatever WordPress user you bind it to. Point it at the dedicated low-privilege user the plugin can create for you, and it can only do what that user can do. Each ability also re-checks the user's capability before it runs, so a connection can never call a tool its user is not allowed to use.

= What can an agent actually do? =

Only the abilities you have enabled, and only within the bound user's capabilities. The catalog is reads and guarded writes over posts, pages, terms, comments, media, and site structure. There is no ability to change options arbitrarily, create users, change roles, fetch a remote URL, or run code. An agent can only write post meta for keys an administrator has explicitly allowlisted, and protected, underscore-prefixed, and authentication keys can never be allowlisted. Deletes move content to Trash so they are recoverable.

= Which AI clients work? =

Any MCP client that can reach a WordPress REST endpoint with an Application Password. Claude Desktop, Claude Code, Cursor, and Windsurf connect through the @automattic/mcp-wordpress-remote proxy. ChatGPT and Gemini remote connectors expect streamable HTTP/SSE, which the underlying adapter does not yet serve natively.

= I'm on Windows and the config won't start. =

Windows MCP clients can't launch the npx shim by name. Wrap it in cmd: set "command" to "cmd" and put "/c", "npx" at the front of "args". The Connection tab has a Windows tab that generates this for you.

= My agent can't connect to a local or staging site. =

Local stacks like DDEV, Local, and Valet serve a self-signed certificate that Node rejects, so the proxy never reaches WordPress. For local testing only, add "NODE_TLS_REJECT_UNAUTHORIZED": "0" to the "env" block (the Connection tab adds it automatically when it detects a local site). Don't ship that setting to production — a public site has a trusted certificate and doesn't need it.

= Is there rate limiting? =

Yes. Set a per-minute cap on the Settings tab under "Rate limit (per minute)". Each connection can make that many agent calls a minute, counted per agent user; 0 turns the limit off. Calls over the cap are denied and logged on the Activity Log tab, so you can spot a connection that keeps hitting it.

= Does it send data anywhere? =

No. The plugin contacts no external service. Your agent talks directly to your site.

= What gets logged? =

Every ability call — started, succeeded, errored, or denied — with the acting user, the ability name, and the argument keys. Argument values are never stored. The activity log lives in your own database and can be cleared from the admin screen.

== Changelog ==

= 1.0.0 =
* Initial release: 24 governed core abilities, least-privilege Application Password auth, per-connection tool filtering, two-layer capability gating, an audit log that records denials, and a guided connection wizard with diagnostics.
