# Agent Abilities for MCP - MCP Server for AI Agents

Connect AI agents to your WordPress site as a scoped, least-privilege user over MCP. Off by default, every call audited.

| | |
|---|---|
| **Contributors** | unaibamir |
| **Tags** | ai, chatgpt, claude, mcp, seo |
| **Requires at least** | 6.9 |
| **Tested up to** | 7.0 |
| **Requires PHP** | 8.0 |
| **Stable tag** | 1.2.1 |
| **License** | [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) |

## Description

### WordPress MCP server for AI agents, governed and off by default

Agent Abilities for MCP is a WordPress plugin that turns your site into a governed Model Context Protocol (MCP) server. It exposes 153 curated WordPress "abilities" (tools) to AI agents like ChatGPT, Claude, Cursor, and VS Code over MCP, so your AI client can read and, when you allow it, write to your site as a real, least-privilege WordPress user you choose. It is built on the WordPress 6.9 Abilities API and the official MCP Adapter, so there is no custom server or transport to trust.

Nothing is exposed until you turn it on. The agent only ever acts as the WordPress user you bind it to, never an admin-equivalent key, and every call is re-checked against that user's capabilities and logged before it runs, denials included. You add reach as you build trust, not all at once. Your own AI client connects in to your site; the plugin makes zero outbound calls and has no telemetry.

Prefer to watch first? Here is a short walkthrough of the plugin in action.

[![Agent Abilities for MCP walkthrough](https://img.youtube.com/vi/Raih7X4QgP0/hqdefault.jpg)](https://www.youtube.com/watch?v=Raih7X4QgP0)

Model Context Protocol (MCP) is an open specification originally developed by Anthropic. Claude, ChatGPT, Cursor, VS Code, Gemini, and other product names are trademarks of their respective owners. Agent Abilities for MCP is a third-party plugin and is not affiliated with, endorsed by, or sponsored by any of them.

**Quick links:** [Documentation](https://agentabilitieswp.com/docs/) | [Getting started](https://agentabilitieswp.com/docs/getting-started/) | [Supported clients](https://agentabilitieswp.com/clients/) | [Website](https://agentabilitieswp.com/)

### 🛡️ Least-privilege access by design

* **Least privilege by design.** The AI agent connects as a real, scoped WordPress user through OAuth or an Application Password, never an admin-equivalent key.
* **Off by default.** Nothing is exposed until you enable it, and updates never silently widen access.
* **Two-layer capability gating.** A connection only sees the tools its user can call, and every call re-checks that capability before it runs.
* **Honest audit log.** Every call is recorded, denied attempts included, with the principal and the argument keys (never the values). It lives in your own database and clears from the admin.
* **Bounded by construction.** No arbitrary option or meta access, no remote URL fetch, no code execution. Uploads are decoded from inline data and checked by their real bytes against an image allow-list, never fetched from a URL. A created user gets the site default role, never admin, and the last administrator can never be removed. Anything destructive is off by default and capability-gated, and deletes go to Trash where the ability supports it.
* **Optional safety controls.** Switch on a per-minute rate limit, an IP allowlist, a force-to-draft mode, or a title-length cap. All four stay off until you set them.
* **No data leaves your site.** The plugin contacts no AI provider and no external service. Your AI client connects in; the plugin never reaches out.
* **Two ways to connect.** Approve an agent in the browser over OAuth, with no secret to store, or point a dedicated low-privilege user at an Application Password. A guided screen builds the client config and checks the endpoint for you.

### 🤖 Built on the WordPress Abilities API and MCP Adapter

WordPress 6.9 ships the Abilities API and the official MCP Adapter. Agent Abilities for MCP registers a curated, governed set of abilities on top of them rather than inventing its own protocol or transport. It builds on the official MCP Adapter library (`wordpress/mcp-adapter`) rather than a custom server, so there is no bespoke server to trust and the plugin inherits the standard's behavior. What it adds is the governance layer: the off-by-default catalog, the capability gating, the safety controls, and the audit log for running the Model Context Protocol on WordPress.

### 📦 153 governed abilities

The plugin ships **153 governed abilities: 83 across WordPress core and 70 from auto-detected integrations.** Every one is off until you enable it, scoped to the bound user, capability-gated, and logged. Beyond these, it can also bridge abilities declared by your other plugins (see below).

**WordPress core (83 abilities).** Reads plus guarded writes across your whole site:

* **📝 Posts & Pages:** list, read, create, update, and delete posts and pages, with destructive actions off by default and deletes routed to Trash.
* **🏷️ Terms & Taxonomies:** manage categories, tags, and custom taxonomy terms.
* **💬 Comments:** read and moderate the comment queue.
* **🖼️ Media:** list and read the media library, and add images decoded from inline data and validated by their real bytes against an image allow-list (never fetched from a URL).
* **🗂️ Post Meta:** read and write only the meta keys an administrator has explicitly allowlisted. Protected, underscore-prefixed, and authentication keys can never be allowlisted.
* **👥 Users:** read and manage users within capability limits. A new user gets the site default role, never admin, and the last administrator can never be removed.
* **🧭 Site structure:** work with menus and the structural pieces that hold the site together.
* **🕓 Revision history:** read the revision trail for content.
* **🧱 Blocks & Templates:** work with reusable blocks, themes, and templates.
* **⚙️ Limited settings & site health:** a tightly scoped set of settings, plus read-only site health and plugin status.
* **🔍 Site-wide search:** one search that spans every post type at once.

**Integrations (70 abilities).** Detected automatically per active plugin, off until you turn them on, capability-gated, and logged. Each appears only while its host plugin is active:

* **🛒 WooCommerce MCP (52 abilities):** read and write products, orders, and customers so an AI agent can help run your store. These touch real customer and order data, including personal data such as names, emails, and addresses, so they sit behind a clear admin notice and stay off until you switch them on.
* **🧩 Advanced Custom Fields (7 abilities):** read and write ACF field data. Like WooCommerce, these can reach real personal data and sit behind the same clear notice.
* **📈 Rank Math SEO (5 abilities):** read and manage Rank Math SEO data.
* **📈 Yoast SEO (3 abilities):** read and manage Yoast SEO data.
* **📈 All in One SEO (3 abilities):** read and manage AIOSEO data.

More integrations are planned.

### 🔗 Abilities from your other plugins (new in 1.1.0)

WordPress 6.9 lets any plugin register its own abilities, not just this one. Agent Abilities for MCP can now bring those in too. When another active plugin declares abilities through the Abilities API, they appear on a dedicated **Abilities from other plugins** screen, grouped by the plugin that registered them, every one off until you turn it on. Enable one and it becomes a governed MCP tool under the same rules as the built-in catalog: scoped to the bound user, capability-checked on every call, rate-limited, and written to the same audit log. Argument values are still never stored.

So you are not limited to the integrations shipped here. Any plugin that speaks the Abilities API can be handed to your agent on your terms, and you can flip a whole plugin's set on or off at once. For fleets or record-keeping, the bundled WP-CLI command `wp aafm catalog export` prints a site's discoverable abilities as JSON.

### 🔌 Connect ChatGPT, Claude, Cursor and other MCP clients

Connect any MCP client that can reach your endpoint. Hosted cloud apps (ChatGPT, Claude, and Manus) connect by URL: you add your endpoint as a custom connector and approve the sign-in once over OAuth, with no config file to edit and no bridge to install. ChatGPT needs developer mode turned on, which requires a paid plan. The single Claude entry covers both the Claude web app and Claude Desktop, since they share the same connector flow. Editors and command-line clients (Claude Code, Cursor, VS Code, Windsurf, and Gemini CLI) connect either directly or through the open-source [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) bridge that runs on your own machine. You can also connect with an Application Password instead of OAuth, pointing a low-privilege user at the endpoint. The hosted Gemini app is not supported yet.

## Installation

1. Upload the plugin to the `/wp-content/plugins/agent-abilities-for-mcp` directory, or install it from the WordPress plugins screen.
2. Activate it from the Plugins screen.
3. Open the **Agent Abilities for MCP** menu in your admin sidebar. On the Abilities tab, turn on only the abilities you want the agent to have. Everything starts off.
4. On the Connection tab, copy your site's MCP endpoint. The simplest path is OAuth: paste the endpoint into your MCP client and approve the connection once in the browser, where the agent acts as your own account.
5. Prefer not to use OAuth, or on a client that can't? Create the dedicated low-privilege agent user the Connection tab offers, generate an Application Password for it, and connect with that instead.
6. Use the connection check on the Connection tab to confirm the endpoint is reachable from your server.

## Frequently Asked Questions

**More help:** [Documentation](https://agentabilitieswp.com/docs/) | [Connecting a client](https://agentabilitieswp.com/docs/connecting-a-client/) | [Security and disclosure](https://agentabilitieswp.com/security/) | [Support forum](https://wordpress.org/support/plugin/agent-abilities-for-mcp/)

### Does the agent get admin access?

No. The agent authenticates as whatever WordPress user you bind it to. Point it at the dedicated low-privilege user the plugin can create for you, and it can only do what that user can do. Each ability also re-checks the user's capability before it runs, so a connection can never call a tool its user is not allowed to use.

### Is it safe to connect an AI agent to my WordPress site?

Yes, when the connection is scoped, which is what this plugin is built around. The agent connects as a real, least-privilege WordPress user you choose, never an admin-equivalent key. Every ability is off until you enable it, each call re-checks the user's capability before it runs, and every call is logged, denied attempts included. The plugin itself never holds an admin-equivalent key.

### What can an agent actually do?

Only the abilities you have enabled, and only within the bound user's capabilities. The catalog is reads and guarded writes over posts, pages, terms, comments, media, post meta, and site structure, plus revision history and a search that spans every post type at once. There is no ability to change options arbitrarily, change roles, fetch a remote URL, or run code. An agent can only write post meta for keys an administrator has explicitly allowlisted, and protected, underscore-prefixed, and authentication keys can never be allowlisted. Deletes move content to Trash where the ability supports it, and the permanent ones are off by default and capability-gated.

### How does the plugin handle tools and access?

Agent Abilities for MCP ships everything off, binds the agent to one WordPress user you pick, re-checks that user's capability on every call, and logs every call including denials. You add reach as you build trust, not all at once. It trades raw tool count for control you can audit.

### Is it free?

Yes. Agent Abilities for MCP is free on WordPress.org, with no paid tier, no API key to buy, and no usage limits added by the plugin.

### Does it work with my other plugins?

Yes, for a set of supported plugins. When one is active, Agent Abilities for MCP adds abilities for it under the same rules as the core: detected automatically, off until you turn them on, capability-gated, and logged. Out of the box it covers WooCommerce, Advanced Custom Fields, and SEO (Yoast, Rank Math, and All in One SEO). The WooCommerce and ACF abilities can read and write real customer and order data, including personal data such as names, emails, and addresses, so they sit behind a clear notice in the admin and stay off until you switch them on. Beyond these built-in integrations, the plugin can also bridge abilities that any of your other plugins register through the WordPress Abilities API. More integrations are planned.

### Can I expose abilities from my other plugins?

Yes. WordPress 6.9 lets any plugin register abilities, and Agent Abilities for MCP can bridge the ones declared by your other active plugins. Open **Abilities from other plugins** in the admin, where they are grouped by the plugin that registered them and start off. Turn one on and it becomes a governed MCP tool under the same rules as everything else: scoped to the bound user, capability-checked on every call, rate-limited, and logged. You can enable or disable a whole plugin's set at once, and nothing is exposed until you choose it.

### Is this the same as the WordPress Abilities API, or the official MCP adapter?

It is built on both. WordPress 6.9 ships the Abilities API and the official MCP Adapter; Agent Abilities for MCP registers a curated, governed set of abilities on top of them rather than inventing its own protocol or transport. So there is no bespoke server to trust, and the plugin inherits the standard's behavior. What it adds is the governance layer: the off-by-default catalog, the capability gating, the safety controls, and the audit log.

### How is this different from other WordPress MCP plugins?

Most MCP plugins for WordPress compete on how many tools they can expose. Agent Abilities for MCP competes on control. Everything is off until you enable it, the agent acts as a real least-privilege WordPress user rather than an admin-equivalent key, every call re-checks that user's capability before it runs, and every call is logged, denials included. It builds on the official WordPress Abilities API and MCP Adapter instead of a hand-rolled server, so there is no custom transport to trust. It trades raw tool count for reach you can audit and widen as you build trust.

### What's the difference between this and the WordPress REST API?

The REST API exposes raw endpoints. MCP describes your site's abilities as discoverable tools an AI agent can reason about and call, and this plugin wraps each one in a governance layer: off by default, capability-gated on every call, and logged. It is the same underlying WordPress, governed so an agent can drive it within the limits you set.

### Which WordPress version do I need?

WordPress 6.9 or newer, which is where the Abilities API and the official MCP Adapter the plugin builds on are available. PHP 8.0 or newer is required.

### Which AI clients work?

Any MCP client that can reach your site's endpoint. With OAuth you paste the endpoint URL into the client and approve the connection once in the browser. Hosted cloud apps (ChatGPT, Claude, and Manus) connect this way by URL, with no bridge to install. Claude Desktop, Claude Code, Cursor, VS Code, Windsurf, and Gemini CLI also connect, some directly and some through the `mcp-remote` bridge that runs on your own machine. You can also connect with an Application Password instead of OAuth, though hosted cloud apps use OAuth only. The hosted Gemini app is not supported yet.

### Does it work with ChatGPT?

Yes. In ChatGPT, turn on developer mode, then add your site as a custom connector using your MCP endpoint URL and approve the connection once over OAuth. This needs a ChatGPT plan that allows custom connectors. Claude Desktop, Claude Code, Cursor, VS Code, Windsurf, and Gemini CLI also work, some directly and some through the `mcp-remote` bridge that runs on your own machine.

### I'm on Windows and the config won't start.

Windows MCP clients can't launch the npx shim by name. Wrap it in cmd: set `command` to `cmd` and put `/c`, `npx` at the front of `args`. The Connection tab has a Windows tab that generates this for you.

### My agent can't connect to a local or staging site.

Local stacks like DDEV, Local, and Valet serve a self-signed certificate that Node rejects, so the proxy never reaches WordPress. For local testing only, add `"NODE_TLS_REJECT_UNAUTHORIZED": "0"` to the `env` block (the Connection tab adds it automatically when it detects a local site). Don't ship that setting to production; a public site has a trusted certificate and doesn't need it.

### Is there rate limiting?

Yes. Set a per-minute cap on the Settings tab under "Rate limit (per minute)". Each connection can make that many agent calls a minute, counted per agent user; 0 turns the limit off. Calls over the cap are denied and logged on the Activity Log tab, so you can spot a connection that keeps hitting it.

### Does it send my content to OpenAI, Anthropic, or Google?

No. The plugin connects to no AI provider and makes no outbound requests of its own. Your own AI client connects in to your site and calls the abilities you have enabled. Whatever your AI client does with the results afterward is between you and whoever makes that client.

### Does it send data anywhere?

No. The plugin contacts no external service and has no telemetry. Your agent talks directly to your site.

### What gets logged?

Every ability call, whether it started, succeeded, errored, or was denied, with the acting user, the ability name, and the argument keys. Argument values are never stored. The activity log lives in your own database and can be cleared from the admin screen.

### How do I report a security issue?

Please report security issues privately rather than in the support forum, so a fix can ship before details are public. Use the security contact listed in this repository.

## External Services

This plugin does not contact any external service. It registers abilities on your own site and answers the requests your AI client sends to it. It makes no outbound requests of its own and includes no analytics or telemetry.

Connecting an AI client to your site is done by the client, not by this plugin. Some MCP clients reach your endpoint directly; others use a small bridge program that runs on your own computer, such as the open-source [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) tool or [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote). Neither bridge is bundled with this plugin or run by it. You install and run it yourself, and it talks only to your site and your local AI client.

## Changelog

### 1.2.1

* **Chore:** The plugin's website link now points to agentabilitieswp.com instead of the GitHub repository.
* **Chore:** Refreshed the documentation so the supported-client list matches what actually works. It still said ChatGPT was not supported, when ChatGPT, Claude (the claude.ai web app and Claude Desktop), and Manus have all connected by URL over OAuth since 1.2.0.

### 1.2.0

* **Fix:** Logged-out visitors could see "There has been a critical error" on every page. It happened when another active plugin checked the current user very early in the WordPress load (The Events Calendar is one example). The plugin now waits until it has finished loading before doing that work.
* **Feature:** Added ChatGPT as a connection option, plus a single Claude entry that covers both the Claude web app and Claude Desktop. Hosted apps like these connect by URL over OAuth, so they no longer show the application-password steps.
* **Fix:** Manus now connects the same way, by URL over OAuth, instead of the local-bridge config it could never run as a cloud agent.
* **Fix:** The Settings screen now saves the Enable OAuth, Dynamic Client Registration, and strict block-validation switches correctly. They were being switched off on save.
* **Fix:** No more white screen when the standalone MCP Adapter plugin is active alongside this one.
* **Fix:** The operating-system tabs in the connection guide now show the right instructions when you switch between them.
* **Chore:** Tightened up the connection snippet helpers.
* **Fix:** Tightened OAuth token scoping so an MCP access token can only authenticate the MCP endpoint and never another REST route, and closed a rare condition that could exhaust memory during connection setup.
* **Fix:** Publishing through the write abilities now always requires publish permission, including for custom public post statuses added by other plugins.
* **Fix:** Valid Cover and Media & Text blocks are no longer flagged as invalid by the block-safety check.

### 1.1.1

* AI agents that write pages, posts, or templates are now steered to keep block styling in the block attributes instead of inline CSS, the mistake that made blocks show "unexpected or invalid content" in the editor.
* Block markup is checked before it is saved, and anything that would break in the editor is flagged back to the agent to fix on its next try.
* A new strict option under Safety controls rejects a write outright when its block markup would be invalid, off by default so existing sites are unchanged.

### 1.1.0

* Bridge abilities from your other plugins: any active plugin that registers abilities through the WordPress Abilities API can now be exposed as a governed MCP tool, opt-in per ability and off by default, on a new "Abilities from other plugins" screen grouped by the source plugin.
* Turn a whole plugin's abilities on or off at once, with each source plugin's name shown in title case.
* Bridged abilities run under the full governance layer: a capability re-check on every call, rate limiting, and the same audit log as the built-in catalog.
* Added a WP-CLI catalog exporter, `wp aafm catalog export`, that lists a site's discoverable abilities as JSON.
* Refreshed the branding with a new icon, banner, and a matching admin menu mark.
* Added a WordPress Playground blueprint so the plugin page can offer a one-click live preview.

### 1.0.0

* Initial release. 153 governed abilities: 83 across WordPress core (reads and guarded writes for posts, pages, terms, comments, media, users, post meta, revisions, blocks, templates, and site structure, plus a search that spans every post type), and 70 from auto-detected integrations for WooCommerce, Advanced Custom Fields, Yoast, Rank Math, and All in One SEO. Built on the WordPress Abilities API and the official MCP Adapter, with no custom transport. Connect over OAuth in the browser or with a least-privilege Application Password user. Everything off by default, two-layer capability gating, per-connection tool filtering, optional safety controls (rate limit, IP allowlist, force-draft, title-length cap), an audit log that records denials, and a guided connection screen with diagnostics.
