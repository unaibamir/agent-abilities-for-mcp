=== Agent Abilities for MCP - MCP Server for AI Agents ===
Contributors: unaibamir
Tags: mcp, chatgpt, ai-assistant, woocommerce, abilities
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI agents to your WordPress site as a scoped, least-privilege user over MCP. Off by default, every call audited.

== Description ==

= WordPress MCP server for AI agents, governed and off by default =

Agent Abilities for MCP is a WordPress plugin that turns your site into a governed Model Context Protocol (MCP) server. It exposes 153 curated WordPress "abilities" (tools) to AI agents like Claude, Cursor, and VS Code over MCP, so your AI client can read and, when you allow it, write to your site as a real, least-privilege WordPress user you choose. It is built on the WordPress 6.9 Abilities API and the official MCP Adapter, so there is no custom server or transport to trust.

Nothing is exposed until you turn it on. The agent only ever acts as the WordPress user you bind it to, never an admin-equivalent key, and every call is re-checked against that user's capabilities and logged before it runs, denials included. You add reach as you build trust, not all at once. Your own AI client connects in to your site; the plugin makes zero outbound calls and has no telemetry.

**Quick links:** [Documentation](https://agentabilitieswp.com/docs/) | [Getting started](https://agentabilitieswp.com/docs/getting-started/) | [Supported clients](https://agentabilitieswp.com/clients/) | [GitHub](https://github.com/unaibamir/agent-abilities-for-mcp)

= 🛡️ Least-privilege access by design =

* **Least privilege by design.** The AI agent connects as a real, scoped WordPress user through OAuth or an Application Password, never an admin-equivalent key.
* **Off by default.** Nothing is exposed until you enable it, and updates never silently widen access.
* **Two-layer capability gating.** A connection only sees the tools its user can call, and every call re-checks that capability before it runs.
* **Honest audit log.** Every call is recorded, denied attempts included, with the principal and the argument keys (never the values). It lives in your own database and clears from the admin.
* **Bounded by construction.** No arbitrary option or meta access, no remote URL fetch, no code execution. Uploads are decoded from inline data and checked by their real bytes against an image allow-list, never fetched from a URL. A created user gets the site default role, never admin, and the last administrator can never be removed. Anything destructive is off by default and capability-gated, and deletes go to Trash where the ability supports it.
* **Optional safety controls.** Switch on a per-minute rate limit, an IP allowlist, a force-to-draft mode, or a title-length cap. All four stay off until you set them.
* **No data leaves your site.** The plugin contacts no AI provider and no external service. Your AI client connects in; the plugin never reaches out.
* **Two ways to connect.** Approve an agent in the browser over OAuth, with no secret to store, or point a dedicated low-privilege user at an Application Password. A guided screen builds the client config and checks the endpoint for you.

= 🤖 Built on the WordPress Abilities API and MCP Adapter =

WordPress 6.9 ships the Abilities API and the official MCP Adapter. Agent Abilities for MCP registers a curated, governed set of abilities on top of them rather than inventing its own protocol or transport. It builds on the official MCP Adapter library (wordpress/mcp-adapter) rather than a custom server, so there is no bespoke server to trust and the plugin inherits the standard's behavior. What it adds is the governance layer: the off-by-default catalog, the capability gating, the safety controls, and the audit log for running the Model Context Protocol on WordPress.

= 📦 153 governed abilities =

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

= 🔗 Abilities from your other plugins (new in 1.1.0) =

WordPress 6.9 lets any plugin register its own abilities, not just this one. Agent Abilities for MCP can now bring those in too. When another active plugin declares abilities through the Abilities API, they appear on a dedicated **Abilities from other plugins** screen, grouped by the plugin that registered them, every one off until you turn it on. Enable one and it becomes a governed MCP tool under the same rules as the built-in catalog: scoped to the bound user, capability-checked on every call, rate-limited, and written to the same audit log. Argument values are still never stored.

So you are not limited to the integrations shipped here. Any plugin that speaks the Abilities API can be handed to your agent on your terms, and you can flip a whole plugin's set on or off at once. For fleets or record-keeping, the bundled WP-CLI command `wp aafm catalog export` prints a site's discoverable abilities as JSON.

= 🔌 Supported AI platforms =

Your AI client connects in to your site over MCP. The plugin never calls out to any AI provider, so there is no model API key to add and nothing extra to pay for.

* **Anthropic Claude:** works today through Claude Desktop, Claude Code, and other MCP-capable Claude clients.
* **Google Gemini:** works today through the Gemini CLI.
* **Any Model Context Protocol client:** anything that speaks MCP can connect, directly or through the open-source `mcp-remote` bridge that runs on your own machine.

The hosted ChatGPT and Gemini apps are not supported yet. They expect a streamable HTTP/SSE remote connector that the underlying MCP Adapter does not serve natively yet. The clients listed below all work today.

= 🧩 Compatible clients and frameworks =

Connect any MCP client that can reach your site's endpoint. With OAuth you paste the endpoint URL and approve once in the browser; with an Application Password you point a dedicated low-privilege user at the endpoint.

* **Desktop AI apps:** Claude Desktop.
* **AI code editors and IDEs:** Claude Code, Cursor, VS Code, and Windsurf.
* **Command line:** Gemini CLI.
* **AI agent frameworks:** any MCP-compatible framework can call your enabled abilities as tools.
* **Bridged clients:** clients that cannot open a remote MCP connection on their own use the open-source `mcp-remote` or `@automattic/mcp-wordpress-remote` bridge, which runs on your own machine and talks only to your site and your local client.

= ⚖️ Disclaimer =

Model Context Protocol (MCP) is an open specification originally developed by Anthropic. Claude, ChatGPT, Cursor, VS Code, Gemini, and other product names are trademarks of their respective owners. Agent Abilities for MCP is a third-party plugin and is not affiliated with, endorsed by, or sponsored by any of them.

== Installation ==

1. Upload the plugin to the `/wp-content/plugins/agent-abilities-for-mcp` directory, or install it from the WordPress plugins screen.
2. Activate it from the Plugins screen.
3. Open the Agent Abilities for MCP menu in your admin sidebar. On the Abilities tab, turn on only the abilities you want the agent to have. Everything starts off.
4. On the Connection tab, copy your site's MCP endpoint. The simplest path is OAuth: paste the endpoint into your MCP client and approve the connection once in the browser, where the agent acts as your own account.
5. Prefer not to use OAuth, or on a client that can't? Create the dedicated low-privilege agent user the Connection tab offers, generate an Application Password for it, and connect with that instead.
6. Use the connection check on the Connection tab to confirm the endpoint is reachable from your server.

For a full walkthrough, see the [getting started guide](https://agentabilitieswp.com/docs/getting-started/) and [connecting a client](https://agentabilitieswp.com/docs/connecting-a-client/).

== Frequently Asked Questions ==

**More help:** [Documentation](https://agentabilitieswp.com/docs/) | [Connecting a client](https://agentabilitieswp.com/docs/connecting-a-client/) | [Security and disclosure](https://agentabilitieswp.com/security/) | [Support forum](https://wordpress.org/support/plugin/agent-abilities-for-mcp/)

= How do I connect an AI agent to my WordPress site? =

Install and activate the plugin, then open the Agent Abilities for MCP screen and turn on the abilities you want, since everything starts off. Copy your site's MCP endpoint from the Connection tab and add it to your AI client. The simplest path is OAuth: paste the endpoint and approve the connection once in the browser. If your client cannot use OAuth, point a dedicated low-privilege user at an Application Password instead. Claude Desktop, Claude Code, Cursor, VS Code, Windsurf, and Gemini CLI all connect today, some directly and some through the mcp-remote bridge that runs on your own machine.

= Does the agent get admin access? =

No. The agent authenticates as whatever WordPress user you bind it to. Point it at the dedicated low-privilege user the plugin can create for you, and it can only do what that user can do. Each ability also re-checks the user's capability before it runs, so a connection can never call a tool its user is not allowed to use.

= Is it safe to connect an AI agent to my WordPress site? =

Yes, when the connection is scoped, which is what this plugin is built around. The agent connects as a real, least-privilege WordPress user you choose, never an admin-equivalent key. Every ability is off until you enable it, each call re-checks the user's capability before it runs, and every call is logged, denied attempts included. The plugin itself never holds an admin-equivalent key.

= What can an agent actually do? =

Only the abilities you have enabled, and only within the bound user's capabilities. The catalog is reads and guarded writes over posts, pages, terms, comments, media, post meta, and site structure, plus revision history and a search that spans every post type at once. There is no ability to change options arbitrarily, change roles, fetch a remote URL, or run code. An agent can only write post meta for keys an administrator has explicitly allowlisted, and protected, underscore-prefixed, and authentication keys can never be allowlisted. Deletes move content to Trash where the ability supports it, and the permanent ones are off by default and capability-gated.

= How does the plugin handle tools and access? =

Agent Abilities for MCP ships everything off, binds the agent to one WordPress user you pick, re-checks that user's capability on every call, and logs every call including denials. You add reach as you build trust, not all at once. It trades raw tool count for control you can audit.

= Is it free? =

Yes. Agent Abilities for MCP is free on WordPress.org, with no paid tier, no API key to buy, and no usage limits added by the plugin.

= Does it work with my other plugins? =

Yes, for a set of supported plugins. When one is active, Agent Abilities for MCP adds abilities for it under the same rules as the core: detected automatically, off until you turn them on, capability-gated, and logged. Out of the box it covers WooCommerce, Advanced Custom Fields, and SEO (Yoast, Rank Math, and All in One SEO). The WooCommerce and ACF abilities can read and write real customer and order data, including personal data such as names, emails, and addresses, so they sit behind a clear notice in the admin and stay off until you switch them on. Beyond these built-in integrations, the plugin can also bridge abilities that any of your other plugins register through the WordPress Abilities API. More integrations are planned.

= Can I expose abilities from my other plugins? =

Yes. WordPress 6.9 lets any plugin register abilities, and Agent Abilities for MCP can bridge the ones declared by your other active plugins. Open **Abilities from other plugins** in the admin, where they are grouped by the plugin that registered them and start off. Turn one on and it becomes a governed MCP tool under the same rules as everything else: scoped to the bound user, capability-checked on every call, rate-limited, and logged. You can enable or disable a whole plugin's set at once, and nothing is exposed until you choose it.

= Can an AI agent manage my WooCommerce store? =

Yes, when WooCommerce is active. The plugin adds WooCommerce abilities for reading and writing products, orders, and customers, so an AI agent can help run your store through this MCP server for WooCommerce. Those abilities reach real customer and order data, including personal data such as names, emails, and addresses, so they stay off until you enable them and sit behind a clear notice in the admin, under the same least-privilege and audit-logging rules as everything else.

= Is this the same as the WordPress Abilities API, or the official MCP adapter? =

It is built on both. WordPress 6.9 ships the Abilities API and the official MCP Adapter; Agent Abilities for MCP registers a curated, governed set of abilities on top of them rather than inventing its own protocol or transport. So there is no bespoke server to trust, and the plugin inherits the standard's behavior. What it adds is the governance layer: the off-by-default catalog, the capability gating, the safety controls, and the audit log.

= How is this different from other WordPress MCP plugins? =

Most MCP plugins for WordPress compete on how many tools they can expose. Agent Abilities for MCP competes on control. Everything is off until you enable it, the agent acts as a real least-privilege WordPress user rather than an admin-equivalent key, every call re-checks that user's capability before it runs, and every call is logged, denials included. It builds on the official WordPress Abilities API and MCP Adapter instead of a hand-rolled server, so there is no custom transport to trust. It trades raw tool count for reach you can audit and widen as you build trust.

= What's the difference between this and the WordPress REST API? =

The REST API exposes raw endpoints. MCP describes your site's abilities as discoverable tools an AI agent can reason about and call, and this plugin wraps each one in a governance layer: off by default, capability-gated on every call, and logged. It is the same underlying WordPress, governed so an agent can drive it within the limits you set.

= Which WordPress version do I need? =

WordPress 6.9 or newer, which is where the Abilities API and the official MCP Adapter the plugin builds on are available. PHP 8.0 or newer is required.

= Which AI clients work? =

Any MCP client that can reach your site's endpoint. With OAuth you paste the endpoint URL into the client and approve the connection once in the browser; clients like Claude Desktop, Claude Code, Cursor, VS Code, Windsurf, and Gemini CLI connect this way, some directly and some through the mcp-remote bridge that runs on your own machine. You can also connect with an Application Password instead of OAuth. The hosted ChatGPT and Gemini apps want a streamable HTTP/SSE remote connector, which the underlying adapter does not serve natively yet.

= Does it work with ChatGPT? =

Not the hosted ChatGPT app yet. It needs a streamable HTTP/SSE remote connector that the underlying MCP Adapter does not serve natively yet. Claude Desktop, Claude Code, Cursor, VS Code, Windsurf, and Gemini CLI all work today, some directly and some through the mcp-remote bridge that runs on your own machine.

= I'm on Windows and the config won't start. =

Windows MCP clients can't launch the npx shim by name. Wrap it in cmd: set "command" to "cmd" and put "/c", "npx" at the front of "args". The Connection tab has a Windows tab that generates this for you.

= My agent can't connect to a local or staging site. =

Local stacks like DDEV, Local, and Valet serve a self-signed certificate that Node rejects, so the proxy never reaches WordPress. For local testing only, add "NODE_TLS_REJECT_UNAUTHORIZED": "0" to the "env" block (the Connection tab adds it automatically when it detects a local site). Don't ship that setting to production. A public site has a trusted certificate and doesn't need it.

= OAuth discovery returns 403 or 404 on my server. =

When OAuth is enabled, clients find your site by fetching two documents under /.well-known/: /.well-known/oauth-protected-resource and /.well-known/oauth-authorization-server. WordPress serves both, but the request has to actually reach WordPress. Some servers deny anything that starts with a dot before PHP runs, and that blocks discovery.

On nginx the usual cause is a dotfile deny rule (location ~ /\. { deny all; }). Add a more specific block ahead of it so /.well-known/ falls through to WordPress:

location ^~ /.well-known/ {
    try_files $uri $uri/ /index.php?$args;
}

The ^~ prefix tells nginx to prefer this block over the dotfile deny. Other hidden files stay denied.

Apache usually works as-is, because the WordPress .htaccess sends anything that isn't a real file to index.php, /.well-known/ included. If a host or security plugin is blocking dotfiles, look for that rule (often in the vhost or a hardening snippet, not WordPress itself) and let /.well-known/ through.

To check, request https://your-site/.well-known/oauth-protected-resource. A working setup returns a JSON document instead of a 403 or 404.

= Is there rate limiting? =

Yes. Set a per-minute cap on the Settings tab under "Rate limit (per minute)". Each connection can make that many agent calls a minute, counted per agent user; 0 turns the limit off. Calls over the cap are denied and logged on the Activity Log tab, so you can spot a connection that keeps hitting it.

= Does it send my content to OpenAI, Anthropic, or Google? =

No. The plugin connects to no AI provider and makes no outbound requests of its own. Your own AI client connects in to your site and calls the abilities you have enabled. Whatever your AI client does with the results afterward is between you and whoever makes that client.

= Does it send data anywhere? =

No. The plugin contacts no external service and has no telemetry. Your agent talks directly to your site.

= What gets logged? =

Every ability call, whether it started, succeeded, errored, or was denied, with the acting user, the ability name, and the argument keys. Argument values are never stored. The activity log lives in your own database and can be cleared from the admin screen.

= How do I report a security issue? =

Please report security issues privately rather than in the support forum, so a fix can ship before details are public. Use the security contact listed on the plugin's GitHub repository.

== External Services ==

This plugin does not contact any external service. It registers abilities on your own site and answers the requests your AI client sends to it. It makes no outbound requests of its own and includes no analytics or telemetry.

Connecting an AI client to your site is done by the client, not by this plugin. Some MCP clients reach your endpoint directly; others use a small bridge program that runs on your own computer, such as the open-source `mcp-remote` tool or `@automattic/mcp-wordpress-remote`. Neither bridge is bundled with this plugin or run by it. You install and run it yourself, and it talks only to your site and your local AI client. Their terms are on their own pages:

* mcp-remote: https://www.npmjs.com/package/mcp-remote
* @automattic/mcp-wordpress-remote: https://www.npmjs.com/package/@automattic/mcp-wordpress-remote

== Screenshots ==

1. The dashboard walks you through setup with a three-step checklist and shows enabled abilities, agent activity, audit size, and your MCP endpoint at a glance.
2. Connect over OAuth by pasting your site URL and approving once in the browser. An Application Password is there as a fallback.
3. Nothing is exposed until you switch it on. Abilities are grouped by area, each with its own toggle and an enable-all per section.
4. Integrations for WooCommerce, ACF, and SEO show up only while the host plugin is active, and each one stays off until you turn it on.
5. The activity log records every call: who made it, which ability, and whether it succeeded or was denied.
6. Abilities from other plugins are grouped by the plugin that registers them, each off until you turn it on, with an enable-all or disable-all per plugin.

== Changelog ==

= 1.1.0 =

* Bridge abilities from your other plugins: any active plugin that registers abilities through the WordPress Abilities API can now be exposed as a governed MCP tool, opt-in per ability and off by default, on a new "Abilities from other plugins" screen grouped by the source plugin.
* Turn a whole plugin's abilities on or off at once, with each source plugin's name shown in title case.
* Bridged abilities run under the full governance layer: a capability re-check on every call, rate limiting, and the same audit log as the built-in catalog.
* Added a WP-CLI catalog exporter, `wp aafm catalog export`, that lists a site's discoverable abilities as JSON.
* Refreshed the branding with a new icon, banner, and a matching admin menu mark.
* Added a WordPress Playground blueprint so the plugin page can offer a one-click live preview.

= 1.0.0 =

* Initial release.
* 153 governed abilities: 83 across WordPress core (reads and guarded writes for posts, pages, terms, comments, media, users, post meta, revisions, blocks, templates, and site structure, plus a search that spans every post type) and 70 from auto-detected integrations for WooCommerce, Advanced Custom Fields, Yoast, Rank Math, and All in One SEO.
* Built on the WordPress Abilities API and the official MCP Adapter, with no custom transport.
* Connect over OAuth in the browser, or with a least-privilege Application Password user.
* Everything off by default, with two-layer capability gating and per-connection tool filtering.
* Optional safety controls: rate limit, IP allowlist, force-to-draft, and title-length cap.
* Audit log that records every call, denied attempts included.
* Guided connection screen with endpoint diagnostics.

== Upgrade Notice ==

= 1.1.0 =
Bridge abilities from your other plugins as governed MCP tools, all opt-in and off by default, plus refreshed branding.

= 1.0.0 =
First public release.
