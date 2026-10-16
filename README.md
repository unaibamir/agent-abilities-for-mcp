# Agent Abilities for MCP - Connect Claude and ChatGPT with Permission Controls and Audit Log

WordPress MCP server for Claude and ChatGPT. Run one site or many with limited access, read-only mode, drafts you approve and an audit log.

| | |
|---|---|
| **Contributors** | unaibamir |
| **Tags** | chatgpt, claude, ai-agent, mcp, mcp-server |
| **Requires at least** | 6.9 |
| **Tested up to** | 7.1 |
| **Requires PHP** | 7.4 |
| **Stable tag** | 1.8.1 |
| **License** | [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) |

## Description

### WordPress MCP server for ChatGPT and Claude, with limited access and an audit log

Agent Abilities for MCP connects ChatGPT, Claude, Cursor and other AI agents to your WordPress site over the Model Context Protocol (MCP), through 179 curated WordPress abilities (tools). The agent acts as a real WordPress user you choose, never with an admin key, so it can only do what that user's role allows. Run it on one site or on several, each with its own user, switches and audit log. It is built on the WordPress 6.9 Abilities API and comes with the official MCP Adapter bundled, so there is nothing else to install.

Nothing this plugin serves is exposed until you turn it on. Every call to this plugin's tools is checked against the chosen user's permissions and written to the audit log before it runs, denied attempts included. An Editor account can write and publish posts but cannot install plugins or change settings, so you add reach as you build trust. To approve new posts before they go live, switch on Force draft and review what the agent wrote on the Needs review card on the Dashboard. See External Services below for exactly what the plugin can reach on its own.

Model Context Protocol (MCP) is an open specification originally developed by Anthropic. Claude, ChatGPT, Cursor, VS Code, Gemini, and other product names are trademarks of their respective owners. Agent Abilities for MCP is a third-party plugin and is not affiliated with, endorsed by, or sponsored by any of them.

**Quick links:** [Documentation](https://agentabilitieswp.com/docs/) | [Getting started](https://agentabilitieswp.com/docs/getting-started/) | [Supported clients](https://agentabilitieswp.com/clients/) | [Prompt Library](https://agentabilitieswp.com/prompts/) | [Website](https://agentabilitieswp.com/)

### What is a WordPress MCP server?

A WordPress MCP server lets an AI assistant work on your site directly, instead of copying text between a chat window and wp-admin. MCP (Model Context Protocol) is an open standard that tells an AI client which tools a service offers and how to call them, so Claude, ChatGPT, or any other MCP client can list your posts, draft one, or update a WooCommerce order.

Short walkthrough:

[![Agent Abilities for MCP walkthrough](https://img.youtube.com/vi/Raih7X4QgP0/hqdefault.jpg)](https://www.youtube.com/watch?v=Raih7X4QgP0)

### What people use it for

* **Run several sites from one AI chat.** Install it on each site and add each one to ChatGPT or Claude as its own connector. Each site keeps its own user, its own switches and its own audit log, so an agency can hand one client's site to the agent without touching the others.
* **Let ChatGPT or Claude write and edit posts without admin access.** Connect the agent as an Editor, Author or Contributor account. It can only do what that role can do in wp-admin.
* **Have the agent save drafts for you to approve.** Turn on Force draft on create and anything the agent creates or tries to publish is saved as a draft. The Needs review card on the Dashboard lists the drafts and pending posts an agent starts, with Preview, Open in editor, Publish and Discard (Discard moves the post to the Trash). To stop it changing posts that are already live as well, connect it as a Contributor.
* **Let AI read your site without changing anything.** Read-only mode stops every write tool from loading, including write tools you have bridged from your other plugins.
* **Tell the agent about your site once.** Write a few lines in Site Brief on the Settings tab, for example your house style or what to leave alone. This plugin sends it to every agent that connects, when its session starts.
* **Find out why an agent cannot connect.** Connection Doctor on the Connection tab checks the connection from your own server and names the fix, for example a host that strips the Authorization header.
* **Fix typos and refresh old posts.** The agent can search your posts, read one and update it, and every step through this plugin is logged.
* **Use your other plugins through the agent.** Plugins that register WordPress abilities, such as WPForms, show up on the Other plugins screen and stay off until you switch them on.

### 🛡️ Permission controls and an audit log on every call

* **Least privilege by design.** The AI agent connects as a real, scoped WordPress user through OAuth or an Application Password, never an admin-equivalent key.
* **Off by default.** None of this plugin's tools are exposed until you enable them, and an update never switches one on. The one thing an update can change is the Adapter's default server, which other plugins publish to. The FAQ explains it.
* **Read-only mode.** One switch stops every write ability from registering at all, whatever is ticked, abilities bridged from other plugins included, and your selections are untouched when you switch it back off. Tools another plugin publishes straight to the Adapter's default server are outside it.
* **Two-layer capability gating.** A connection only sees the tools its user can call, and every call re-checks that capability before it runs. One exception: a tool bridged from another plugin whose own check needs an item id is listed to anyone who can edit posts, and that plugin's check still decides every call.
* **Per-role and per-connection allowlist.** Narrow what one role, or one connection, can reach on top of the global list. The lists only narrow: an ability has to clear the global list, the role's list, and that connection's list before an agent can call it. A site that never opens the screen sees no change.
* **Honest audit log.** Every call to this plugin's tools is recorded, denied attempts included, along with the user or connection that made the call, the argument keys, and a short identifier-only note of what it touched. Free-text argument content is never stored, and the log lives in your own database.
* **Bounded by construction.** No arbitrary option or meta access, no code execution. Uploads are validated by their real bytes against an image allow-list, and a URL upload refuses private, loopback, or link-local targets with no redirects followed. A new user gets the default role, never admin, and the last administrator can never be removed. Anything destructive is off by default, capability-gated, and routed to Trash where supported.
* **Optional safety controls.** Switch on a per-minute rate limit, an IP allowlist, a force-to-draft mode, or a title-length cap. All four stay off until you set them.
* **No content leaves your site.** The plugin contacts no AI provider and has no telemetry; see External Services below for the two outbound requests it can make on its own.
* **Two ways to connect.** Approve an agent over OAuth (no secret in a config file) or point a dedicated low-privilege user at an Application Password; a guided screen builds the config for you. An Application Password is a whole-site credential scoped only by that user's role, so this plugin's own limits (allowlist, high-risk floor, audit log) apply only to calls through its MCP endpoint. OAuth has no such limit, since a token this plugin issues only ever authenticates this one endpoint.

### 🤖 Built on the WordPress Abilities API and MCP Adapter

WordPress 6.9 ships the Abilities API. The official MCP Adapter (`wordpress/mcp-adapter`) comes bundled with this plugin, so you do not install it separately. If the standalone Adapter plugin is active too, this plugin works with whichever copy loads first, from 0.6.1 through 0.7.x. Agent Abilities for MCP registers a curated, governed set of abilities on top of them rather than inventing its own protocol or server, so there's no bespoke transport to trust. A Settings switch (Automatic, Always on, Always off) controls the Adapter's default server, which other plugins can publish tools to. Automatic, the default, starts it only when another plugin publishes tools there, and this plugin's own tools never appear on it.

### 📦 179 governed abilities

The plugin ships **179 governed abilities: 85 across WordPress core and 94 from auto-detected integrations**, every one off until you enable it, scoped, capability-gated, and logged. It can also bridge abilities from your other plugins (see below).

**WordPress core (85 abilities).** Reads plus guarded writes across your whole site:

* **📝 Posts & Pages:** list, read, create, update, and delete, with destructive actions off by default and deletes routed to Trash.
* **🏷️ Terms & Taxonomies:** manage categories, tags, and custom taxonomy terms.
* **💬 Comments:** read and moderate the comment queue.
* **🖼️ Media:** list and read the library, and add images from inline data or an HTTPS URL, validated by their real bytes against an allow-list.
* **🗂️ Post Meta:** read and write only administrator-allowlisted meta keys; protected, underscore-prefixed, and authentication keys can never be allowlisted.
* **👥 Users:** read and manage users within capability limits; a new user gets the default role, never admin, and the last administrator can't be removed.
* **🧭 Site structure:** work with menus and the structural pieces that hold the site together.
* **🕓 Revision history:** read the revision trail for content.
* **🧱 Blocks & Templates:** work with reusable blocks, themes, and templates.
* **⚙️ Limited settings & site health:** a tightly scoped set of settings, plus read-only site health and plugin status.
* **🔍 Site-wide search:** one search that spans every post type at once.

**Integrations (94 abilities).** Detected automatically per active plugin, off until you turn them on, capability-gated, logged, and only present while that plugin is active:

* **🛒 WooCommerce MCP (52 abilities):** read and write products, orders, and customers to help run your store. These touch personal data (names, emails, addresses), so they sit behind a clear admin notice and stay off until enabled.
* **🧩 Advanced Custom Fields (7 abilities):** read and write ACF field data; like WooCommerce, these can reach personal data and sit behind the same notice.
* **📈 Rank Math SEO (5 abilities):** read and manage Rank Math SEO data.
* **📈 Yoast SEO (3 abilities):** read and manage Yoast SEO data.
* **📈 All in One SEO (3 abilities):** read and manage AIOSEO data.
* **📅 The Events Calendar (13 abilities):** read and manage events, venues, and organizers.
* **🎫 Event Tickets (3 abilities):** read tickets and attendees for an event. No ticket-purchase write is exposed.
* **📈 Slim SEO (2 abilities):** read and manage Slim SEO data.
* **🎨 Avada / Fusion Builder (2 abilities):** read a page's raw Fusion Builder markup and replace text without disturbing the shortcode layout.
* **📍 GeoDirectory (4 abilities, off by default):** read and manage business and place listings; stays off even when active, turn it on in the Integrations tab.

### 🔗 Abilities from your other plugins (new in 1.1.0)

WordPress 6.9 lets any plugin register its own abilities, not just this one, and Agent Abilities for MCP can bring those in too. Abilities from other active plugins appear on a dedicated **Other plugins** screen, grouped by plugin, every one off until you enable it - then it becomes a governed MCP tool under the same rules as the built-in catalog: scoped, capability-checked, rate-limited, and logged with identifiers only.

One limit worth knowing: since it's the other plugin's code doing the work, WordPress can only check a bridged ability's answer against a description if that plugin publishes one. The governance above (permissions, scoping, rate limiting, the audit log) applies in full either way.

Some plugins skip this screen and publish tools straight to the Adapter's default server. Those tools are outside this plugin's controls (see the FAQ on the default server).

Any plugin that speaks the Abilities API can be handed to your agent, with a whole plugin's set toggled at once. The bundled WP-CLI command `wp aafm catalog export` prints a site's discoverable abilities as JSON.

### Page builder pages: refused, not silently broken

Elementor, Divi, Beaver Builder, and Avada keep their layout outside the normal post content, so a write that reports success can leave the front end exactly as it was. Since 1.7.4 the plugin checks before it writes and refuses the call with an error naming the builder that owns the page. This is a guard, not an integration with any of them, and OptimizePress is not covered yet.

### Connect Claude to WordPress

Install the plugin, switch on the abilities you want Claude to have, add your site's MCP endpoint as a custom connector in Claude, and approve the OAuth sign-in once. No API key or config file. The claude.ai app, Claude Desktop, and Claude Code share this flow, and Claude only ever acts as the WordPress user who approved it.

### Connect ChatGPT to WordPress

Turn on Developer Mode in ChatGPT (Settings, then Connectors, then Advanced), add your site's MCP endpoint as a custom connector, and approve it once over OAuth - a beta feature on ChatGPT's paid plans, not the plugin's limit. If it will not connect, run Connection Doctor on the Connection tab. ChatGPT then reaches only the abilities you switched on, acting as the WordPress user that approved the connection.

### 🔌 Connect ChatGPT, Claude, Cursor and other MCP clients

Connect any MCP client that can reach your endpoint. Hosted cloud apps (ChatGPT, Claude, and Manus) connect by URL: you add your endpoint as a custom connector and approve the sign-in once over OAuth, with no config file to edit and no bridge to install. ChatGPT needs developer mode turned on, which requires a paid plan. The single Claude entry covers both the Claude web app and Claude Desktop, since they share the same connector flow. Editors and command-line clients (Claude Code, Cursor, VS Code, Windsurf, and Gemini CLI) connect either directly or through the open-source [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) bridge that runs on your own machine. You can also connect with an Application Password instead of OAuth, pointing a low-privilege user at the endpoint. The hosted Gemini app is not supported yet.

## Installation

1. Upload the plugin to the `/wp-content/plugins/agent-abilities-for-mcp` directory, or install it from the WordPress plugins screen.
2. Activate it from the Plugins screen.
3. Open the **Agent Abilities for MCP** menu in your admin sidebar. On the Abilities tab, turn on only the abilities you want the agent to have. Everything starts off.
4. On the Connection tab, copy your site's MCP endpoint. The simplest path is OAuth: paste the endpoint into your MCP client and approve the connection once in the browser, where the agent acts as your own account.
5. Prefer not to use OAuth, or on a client that can't? Create the dedicated low-privilege agent user the Connection tab offers, generate an Application Password for it, and connect with that instead.
6. Run Check my connection on the Connection tab to confirm the endpoint is reachable from your server.

## Configuration

Your site's MCP endpoint lives at a fixed path under your REST API (replace `example.com` with your own domain):

```
https://example.com/wp-json/agent-abilities-for-mcp/mcp
```

Hosted apps (ChatGPT, Claude, and Manus) take that URL directly: add it as a custom connector and approve the connection once over OAuth. Editors and command-line clients use a config file instead. The standard block runs the open-source [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) bridge on your own machine, which walks you through the same one-time OAuth approval in the browser, so the config file holds no secret. The bridge keeps the tokens it is issued on your own machine, under `~/.mcp-auth`:

```json
{
  "mcpServers": {
    "agent-abilities": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://example.com/wp-json/agent-abilities-for-mcp/mcp"]
    }
  }
}
```

VS Code keys the same block as `servers` in `.vscode/mcp.json` rather than `mcpServers`. Prefer an Application Password over OAuth? Create the dedicated low-privilege agent user the Connection tab offers, then connect through the [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote) bridge with the credentials in its `env` block:

```json
{
  "mcpServers": {
    "agent-abilities": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://example.com/wp-json/agent-abilities-for-mcp/mcp",
        "WP_API_USERNAME": "your-agent-user",
        "WP_API_PASSWORD": "PASTE-APPLICATION-PASSWORD-HERE"
      }
    }
  }
}
```

You do not have to write either block by hand: the Connection tab generates both, filled in with your real endpoint, with a Windows variant and a reachability check.

## Frequently Asked Questions

**More help:** [Documentation](https://agentabilitieswp.com/docs/) | [Connecting a client](https://agentabilitieswp.com/docs/connecting-a-client/) | [Security and disclosure](https://agentabilitieswp.com/security/) | [Support forum](https://wordpress.org/support/plugin/agent-abilities-for-mcp/)

### How do I connect Claude to WordPress?

Install the plugin, enable the abilities you want on the Abilities tab, then copy your MCP endpoint from the Connection tab and add it to Claude as a custom connector. Approve the sign-in once in the browser. The claude.ai web app and Claude Desktop use that same flow; Claude Code connects from the command line.

### Does the agent get admin access?

No. The agent authenticates as whatever WordPress user you bind it to. Point it at the dedicated low-privilege user the plugin can create for you, and it can only do what that user can do. Each of this plugin's abilities also re-checks the user's capability before it runs, so a connection can never call one of them if its user is not allowed to use it.

### What permission controls do I get over the AI agent?

Agent Abilities for MCP gives you three layers for its own tools. Every ability is off until you enable it. The agent connects as a real WordPress user you choose, so it can only do what that user's role already allows. Every call re-checks that user's capability before it runs, and a call that fails the check is denied and recorded.

### Can I give one connection less access than another?

Yes. Beyond the site-wide list of enabled abilities, you can add a per-role allowlist, a per-connection allowlist, or both, on the Connections screen. Either one only narrows what was already enabled: an ability still has to clear the global list, then the matching role's list, then the matching connection's list, before an agent can call it. There is no finer-grained scoping below role and OAuth connection - an Application Password inherits its user's whole role, with no separate per-password limit. Leave the screen alone and nothing changes; every existing connection keeps working exactly as it did before.

### Is there an audit log of what the agent did?

Yes. Agent Abilities for MCP writes every call to one of its abilities to an audit log in your own database, denied attempts included. Each entry records the acting user, the ability name, the argument keys, and a short note of what the call touched: small identifying values only, such as ids, meta key names, slugs, and status values. For a failed call to another plugin's bridged ability, it also records that plugin's error code, never its message. Free-text argument content, a post body or an email address, is never stored. You can clear the log from the admin screen.

### Is it safe to connect an AI agent to my WordPress site?

Yes, when the connection is scoped, which is what this plugin is built around. The agent connects as a real, least-privilege WordPress user you choose, never an admin-equivalent key. Every ability is off until you enable it, each call to its tools re-checks the user's capability before it runs, and every one of those calls is logged, denied attempts included. Tools another plugin publishes to the Adapter's default server are outside these checks; the FAQ on the default server explains. The plugin itself never holds an admin-equivalent key.

### What can an agent actually do?

Only the abilities you have enabled, and only within the bound user's capabilities. The catalog is reads and guarded writes over posts, pages, terms, comments, media, post meta, and site structure, plus revision history and a search that spans every post type at once. There is no ability to change options arbitrarily, change roles, or run code. The one ability that fetches a remote URL is a media upload: it only accepts https, refuses a target that resolves to a private, loopback, or link-local address, never follows a redirect, and stays off until you enable it like every other ability. An agent can only write post meta for keys an administrator has explicitly allowlisted, and protected, underscore-prefixed, and authentication keys can never be allowlisted. Deletes move content to Trash where the ability supports it, and the permanent ones are off by default and capability-gated.

### Will an agent break my Elementor or Divi page?

No, it will refuse the write instead. Elementor, Divi, Beaver Builder, and Avada all store their layout somewhere other than the normal post content, so an unguarded edit could report success while changing nothing you can see, or corrupt the builder's own markup. The plugin checks page-builder ownership before any content write and refuses the call with an error naming the builder that owns the page, so you find out immediately rather than discovering it later. Edit that page in its own builder instead. This is a refusal guard, not an integration with any of these builders, and OptimizePress is not covered yet.

### How does the plugin handle tools and access?

Agent Abilities for MCP ships everything off, binds the agent to one WordPress user you pick, re-checks that user's capability on every call to its tools, and logs every one of those calls including denials. You add reach as you build trust, not all at once. It trades raw tool count for control you can audit.

### Is it free?

Yes. Agent Abilities for MCP is free on WordPress.org, with no paid tier, no API key to buy, and no usage limits added by the plugin.

### Does it work with my other plugins?

Yes, for a set of supported plugins. When one is active, Agent Abilities for MCP adds abilities for it under the same rules as the core: detected automatically, off until you turn them on, capability-gated, and logged. Out of the box it covers WooCommerce, Advanced Custom Fields, and SEO (Yoast, Rank Math, and All in One SEO). The WooCommerce and ACF abilities can read and write real customer and order data, including personal data such as names, emails, and addresses, so they sit behind a clear notice in the admin and stay off until you switch them on. Beyond these built-in integrations, the plugin can also bridge abilities that any of your other plugins register through the WordPress Abilities API. More integrations are planned.

### Can I expose abilities from my other plugins?

Yes. WordPress 6.9 lets any plugin register abilities, and Agent Abilities for MCP can bridge the ones declared by your other active plugins. Open **Other plugins** in the admin, where they are grouped by the plugin that registered them and start off. Turn one on and it becomes a governed MCP tool under the same rules as everything else: scoped to the bound user, capability-checked on every call, rate-limited, and logged. You can enable or disable a whole plugin's set at once, and nothing is bridged until you choose it.

### Can an agent manage my events, tickets, or directory listings?

Yes, when the matching plugin is active. With The Events Calendar active, an agent can read and manage events, venues, and organizers; with Event Tickets active, it can read tickets and attendees, though no ticket-purchase write is exposed. With GeoDirectory active, it can read and manage business and place listings, but that integration stays off even when the plugin is active - you turn it on yourself on the Integrations tab, unlike most other integrations, which are simply off until enabled. All of these abilities follow the same rules as everything else: off until you switch them on, capability-gated, and logged.

### Is this the same as the WordPress Abilities API, or the official MCP adapter?

It is built on both. WordPress 6.9 ships the Abilities API, and the official MCP Adapter comes bundled with this plugin; Agent Abilities for MCP registers a curated, governed set of abilities on top of them rather than inventing its own protocol or transport. So there is no bespoke server to trust, and the plugin inherits the standard's behavior. What it adds is the governance layer: the off-by-default catalog, the capability gating, the safety controls, and the audit log.

### How is this different from other WordPress MCP plugins?

Most MCP plugins for WordPress compete on how many tools they can expose. Agent Abilities for MCP competes on control. Everything is off until you enable it, the agent acts as a real least-privilege WordPress user rather than an admin-equivalent key, every call to its tools re-checks that user's capability before it runs, and every one of those calls is logged, denials included. It builds on the official WordPress Abilities API and MCP Adapter instead of a hand-rolled server, so there is no custom transport to trust. It trades raw tool count for reach you can audit and widen as you build trust.

### What's the difference between this and the WordPress REST API?

The REST API exposes raw endpoints. MCP describes your site's abilities as discoverable tools an AI agent can reason about and call, and this plugin wraps each one in a governance layer: off by default, capability-gated on every call to this plugin's tools, and logged. It is the same underlying WordPress, governed so an agent can drive it within the limits you set.

### Which WordPress version do I need?

WordPress 6.9 or newer, which is where the Abilities API and the official MCP Adapter the plugin builds on are available. PHP 7.4 or newer is required.

### Which AI clients work?

Any MCP client that can reach your site's endpoint. With OAuth you paste the endpoint URL into the client and approve the connection once in the browser. Hosted cloud apps (ChatGPT, Claude, and Manus) connect this way by URL, with no bridge to install. Claude Desktop, Claude Code, Cursor, VS Code, Windsurf, and Gemini CLI also connect, some directly and some through the `mcp-remote` bridge that runs on your own machine. You can also connect with an Application Password instead of OAuth, though hosted cloud apps use OAuth only. The hosted Gemini app is not supported yet.

### Does it work with ChatGPT?

Yes. In ChatGPT, turn on developer mode, then add your site as a custom connector using your MCP endpoint URL and approve the connection once over OAuth. This needs a ChatGPT plan that allows custom connectors. Claude Desktop, Claude Code, Cursor, VS Code, Windsurf, and Gemini CLI also work, some directly and some through the `mcp-remote` bridge that runs on your own machine.

### Do I need to expose my computer to connect a remote site?

No. Your WordPress site is the MCP server, so the endpoint is already public over HTTPS on the site itself, not on your computer. Connecting a hosted client like ChatGPT or Claude to a remote WordPress install means pasting that endpoint URL into the client and approving one OAuth sign-in in the browser. No tunnel, no reverse proxy, no local bridge process to keep running. A bridge is only needed for a client that can't open a remote MCP connection on its own, covered above.

### Can ChatGPT edit my WordPress site?

Only the parts you allow. ChatGPT reaches your site through a custom connector you add yourself, it acts as the WordPress user that approved the connection, and it sees nothing beyond the abilities you switched on. Every write through this plugin's endpoint is capability-checked before it runs and recorded in the audit log, and you can stop all writes at once with read-only mode.

### Can I manage multiple WordPress sites from ChatGPT or Claude?

Yes. Install the plugin on each site and add each site's endpoint to your AI client as its own connector. The agent can then work across your sites from one place, and each site keeps its own user, its own allowed tools and its own audit log.

### How do I give an AI agent limited access instead of admin?

Connect it as a WordPress user with a smaller role. An Editor can write, edit and publish any post; an Author only their own; a Contributor can write but not publish. The plugin can create a dedicated low-privilege user for you, and you can narrow the tools further for one role or one connection.

### Can the agent only save drafts so I approve before anything goes live?

Yes. Turn on Force draft on create in Settings. Anything the agent creates, and any request to publish, is saved as a draft instead. Edits to a post that is already published still go live, so if you want every change reviewed, connect the agent as a Contributor, who cannot edit published posts at all. The Needs review card on the Dashboard lists those drafts so you can preview, publish or discard them.

### Why can't ChatGPT or Claude connect to my WordPress site?

Open the Connection tab and click Check my connection (the Connection Doctor). It runs the checks from your own server and names the fix, for example a host that strips the Authorization header. The other usual causes are a CDN or firewall blocking the /mcp path and a local site with a self-signed certificate, and both have their own answers below.

### Can I tell the AI agent about my site, such as my house style or what to leave alone?

Yes. Site Brief on the Settings tab takes a few lines about your site, up to 1,500 characters. The plugin sends it to every agent that connects when its session starts. Whether the agent follows it depends on the client. Only you can change it.

### Where do I review what the agent wrote before it goes live?

The Needs review card on the Dashboard lists the drafts and pending posts an agent starts, with Preview, Open in editor, Publish and Discard buttons. Discard moves the post to the Trash, and is unavailable when the Trash is off. Turn on Force draft on create in Settings to hold new posts, and requests to publish, as drafts so they land there.

### Can AI read my site without being able to change anything?

Yes. Switch on read-only mode and no write tool registers, whatever else is ticked, including write abilities you bridged from other plugins. The agent can still search and read. Your selections are kept for when you switch it back off. Tools another plugin publishes straight to the Adapter's default server are not covered; the next answer explains.

### Do I need to install the MCP Adapter separately?

No. The official MCP Adapter is bundled with this plugin, and if you also run the standalone Adapter plugin, the two work together. The Adapter has a built-in default server that other plugins can publish tools to. Settings has a choice for it: Automatic (the default), Always on or Always off. Automatic starts the server only when another active plugin publishes tools there, so a site that updates from an earlier version gets the default server if one does, and the Activity Log notes the first time. WordPress core's own public abilities do not count as a publisher, so if you ran the standalone Adapter only to reach those, choose Always on. This plugin's own tools are never on that server, whatever you choose. They are served only by its governed endpoint. The tools that are on the default server are not governed by this plugin: its read-only mode, allowlists, IP allowlist, rate limit and audit log do not apply to them. Choose Always off if you want none of it.

### I'm on Windows and the config won't start.

Windows MCP clients can't launch the npx shim by name. Wrap it in cmd: set `command` to `cmd` and put `/c`, `npx` at the front of `args`. The Connection tab has a Windows tab that generates this for you.

### My agent can't connect to a local or staging site.

Local stacks like DDEV, Local, and Valet serve a self-signed certificate that Node rejects, so the proxy never reaches WordPress. For local testing only, add `"NODE_TLS_REJECT_UNAUTHORIZED": "0"` to the `env` block (the Connection tab adds it automatically when it detects a local site). Don't ship that setting to production; a public site has a trusted certificate and doesn't need it.

### My agent can't connect and my site is behind Cloudflare or another CDN.

A CDN or firewall in front of WordPress can stop the agent before its request reaches your site. The common culprit is Cloudflare's "Block AI Bots" setting (and Super Bot Fight Mode): the agent finishes signing in, but its MCP request is blocked at the edge because it comes from the AI client's servers with an AI User-Agent. The sign-in shows up in the Activity Log, and no ability calls follow it.

To confirm, open your CDN's firewall or security event log and look for a blocked request to the plugin's MCP endpoint (its path ends in `/mcp`) from the AI client's IP range, around the time you tried to connect. The entry names the rule that blocked it.

The fix is to let that endpoint through. On Cloudflare, either turn off "Block AI Bots" under Security, Bots, or add a rule that skips bot protection for the `/mcp` path so the rest of your site stays covered. Other CDNs and security plugins have the same kind of allowlist or exception.

### Is there rate limiting?

Yes. Set a per-minute cap on the Settings tab under "Rate limit (per minute)". Each connection can make that many agent calls a minute, counted per agent user; 0 turns the limit off. Calls over the cap are denied and logged on the Activity Log tab, so you can spot a connection that keeps hitting it.

### Does it send my content to OpenAI, Anthropic, or Google?

No. The plugin never connects to OpenAI, Anthropic, Google, or any other AI provider. Your own AI client connects in to your site and calls the abilities you have enabled. Whatever your AI client does with the results afterward is between you and whoever makes that client. (The one thing the plugin itself can fetch is a URL, if you enable the URL-upload ability - see "Does it send data anywhere?" below.)

### Does it send data anywhere?

No AI provider and no telemetry. Your agent talks directly to your site. The one exception is the off-by-default upload-media-from-url ability: if you turn it on, it fetches the URL your AI client gives it (HTTPS only, private and reserved addresses refused, no redirects followed) so that file can be added to your media library. That fetch is an ordinary HTTP GET - like any request to that address, its destination sees the URL, your site's IP, and when the request happened - but it carries none of your content, credentials, or other site data; it only pulls in the file at the URL you asked it to fetch.

### What does the audit log record?

Every call to one of this plugin's abilities, whether it started, succeeded, errored, or was denied, with the acting user, the ability name, the argument keys, and a short note of what the call touched: small identifying values only, such as ids, meta key names, slugs, and status values. For a failed call to another plugin's bridged ability, it also records that plugin's error code, never its message. Free-text argument content, a post body or an email address, is never stored. The activity log lives in your own database and can be cleared from the admin screen.

### Does uninstalling the plugin revoke my agent's access?

Not by itself. Uninstalling removes the plugin's own settings and activity log, and, only if you turned on "Delete data on uninstall" first, its OAuth tables too. It never removes the dedicated agent user the plugin can create for you, or any Application Password issued to it, because those are ordinary WordPress account credentials that exist outside the plugin's own data. To fully cut off an agent, revoke its OAuth grant from the Connection tab, or delete its Application Password or user account from the Users screen, before or after you remove the plugin.

### How do I report a security issue?

Please report security issues privately rather than in the support forum, so a fix can ship before details are public. Use the security contact listed in this repository.

## External Services

This plugin contacts no AI provider and includes no analytics or telemetry.

It makes two kinds of outbound HTTP request on its own: the Connection tab's checks (a few requests your site makes to its own address, to see whether the MCP endpoint answers and, with OAuth on, whether the discovery documents load), and, only when you enable the off-by-default upload-media-from-url ability, a fetch of the exact HTTPS URL your AI client supplies so that file can be added to your media library. That fetch is SSRF-hardened against private, reserved, and redirect-based targets, and carries none of your content, credentials, or site data - it only reads what's already public at that URL.

Connecting a client is done by the client, not this plugin. Some reach your endpoint directly; others use a bridge such as the open-source [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) or [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote), run on your own machine and not bundled with this plugin.

## Changelog
### 1.8.1

* **Fix:** Bridged tools whose dates carry no timezone, such as WooCommerce orders, failed in strict clients like Claude. Bridged tools no longer advertise a strict date format, and the dates themselves are unchanged.

### 1.8.0

* **Feature:** A Site Brief box on the Settings tab lets you describe your site in a few lines. Every agent that connects to this plugin reads it.
* **Feature:** The Connection tab has a Connection Doctor. One click checks the connection from your own server and tells you what to fix, for example a host that strips the Authorization header.
* **Feature:** A Needs review card on the Dashboard lists the drafts your agents wrote, with Preview, Open in editor, Publish and Discard buttons. Discard moves the post to the Trash.
* **Fix:** Other plugins' tools on the MCP Adapter's default server stopped working while this plugin was active. A new Settings switch (Automatic, Always on, Always off) brings that server back, and existing sites move to Automatic, which starts it only when another plugin publishes tools there. This plugin's own tools never appear on it, so a site that used it only for WordPress core abilities should pick Always on.
* **Fix:** With Force draft on, an agent that sent "publish" again for a post that was already live took it offline. The post now stays live. A "WP_MCP_VERSION already defined" warning next to the standalone MCP Adapter 0.6.x and a Settings crash with an older adapter copy are also fixed, and the plugin title now names Claude and ChatGPT.

### 1.7.10

* **Fix:** Query Monitor showed a "bundled dependency" deprecation warning from the MCP Adapter on every request that started it; it no longer does.

### 1.7.9

* **Fix:** With the standalone MCP Adapter plugin (0.7.0) active and loading before this one, the /mcp endpoint returned a 404. It now serves in either load order.
* **Fix:** The standalone MCP Adapter plugin showed a red "Another version of MCP Adapter is already loaded" notice while this plugin's copy was in use. It no longer does.
* **Fix:** The adapter's per-request "bundled dependency" deprecation line no longer lands in the PHP error log.
* **Chore:** The bundled MCP Adapter moves from 0.6.1 to 0.7.0, and adapter versions 0.6.1 through 0.7.x are supported. The adapter itself now returns error -32602 instead of -32003 for an unknown tool, and clients must send the MCP-Protocol-Version header after initialize.

### 1.7.8

* **Feature:** A bridged ability's error now says which plugin returned it, and the Activity Log records that plugin's error code (the code only, never its message).
* **Fix:** 37 bridged tools from WooCommerce, Meta Box, SiteOrigin, Premium Addons, SEOPress, WPForms, Elementor and ElementsKit worked when called but never showed in the tool list. They now list for users who can edit posts, and the source plugin still decides whether a call is allowed.
* **Fix:** Deleting through bridged ACF reported an error after the item was already deleted. It now returns ACF's own result.
* **Fix:** Bridged results holding plain objects (such as a PixelYourSite response) were refused after the write had already happened. They now come through, and a refused call is logged as an error instead of success.
* **Fix:** On WordPress 7.0 and later, the raw exception text from a throwing plugin no longer reaches the client.

### 1.7.7

* **Feature:** The Abilities tab now has one Save changes button that saves every section on it, with an "Unsaved changes" note and a warning if you leave the page with edits pending.
* **Feature:** The Activity Log gets a row when an exposed list changes (content types, or post, user or term meta keys), giving the list name and how many keys were added and removed.
* **Fix:** Edits to exposed meta keys or content types were lost when you pressed only the main Save. The four separate section buttons are gone, and the one Save covers them.
* **Fix:** When a section fails to save, the page names it and keeps your edits so you can retry. Sections that did save stay saved.
* **Fix:** The Activity Log table scrolled sideways when a value such as the detail JSON was long; the columns now share the page width and long values wrap.

### 1.7.6

* **Fix:** An OAuth token issued for the MCP endpoint also signed its holder in on other REST routes, admin-ajax.php, admin-post.php, the comment form, the OAuth authorize screen and discovery URLs. It now works on the MCP route only.
* **Fix:** Meta, SEO, media, WooCommerce and The Events Calendar writes now check the database for what actually landed and report written, unchanged, refused or a failed read. The Activity Log records each outcome, identifiers only.
* **Fix:** Read-only mode, the high-risk lock, ability switches and the allowlist are read from the database on every MCP request. A failed read keeps the stricter setting, so a stale object cache can't loosen them.
* **Fix:** WP-CLI printed a pair of "called incorrectly" notices for each enabled bridge tool whose source plugin was inactive; those tools are now skipped quietly. Activity Log rows for denied calls now read "Attempted: ..." instead of looking like the change happened.
* **Fix:** Under a persistent object cache, the review prompt wiped other plugins' cached "option doesn't exist" entries; it now clears only its own. Smaller fixes land in replace-sitewide, WooCommerce gateway ordering, the Rank Math and AIOSEO head previews, and several tool descriptions.

### 1.7.5

* **Feature:** The Connection tab swaps the allowlist textarea for a searchable checkbox picker and adds role and client selects, a Client ID copy control, paginated tables, and cross-tab abilities search.
* **Fix:** A zero-match abilities search left the Save button stranded on an empty card; the savebar now hides and returns with the results.
* **Fix:** The client picker's hover and selected states looked identical; hover is now a border outline only.
* **Fix:** Ability order now survives a re-save, a stale search clears on keyboard navigation, a large allowlist no longer renders every checkbox at load, and new controls have accessible names and translations.
* **Fix:** Post, block and template writes now confirm what landed, a stuck OAuth migration can no longer re-enable a switch you disabled, comment counts no longer hint at hidden comments, and a failed token revoke no longer reports success.

### 1.7.4

* **Feature:** 26 new abilities cover The Events Calendar, Event Tickets, Slim SEO, Avada, and GeoDirectory, plus uploading media straight from a URL. All of them stay off until you turn them on.
* **Feature:** A page-builder guard refuses a write that would silently do nothing on a page owned by Elementor, Divi, Beaver Builder, or Avada. A new allowlist narrows what each role or connection can reach.
* **Fix:** The bump to mcp-adapter 0.6.1 closes a multisite bug where a real subsite connection was reported as failed.
* **Fix:** Several OAuth paths treated a failed database read as permission granted. A deactivated client, a missing client record, or an unreadable registration limit now refuse the request instead of letting it through.
* **Fix:** Grants belonging to a deleted user are cleared out, the Connections screen shows each identity's current role, and a batch of smaller fixes lands across WooCommerce, the admin screens, and the connection tab.

### 1.7.3

* **Fix:** The plugin is now fully compatible with a persistent WordPress object cache (Redis, Memcached, or a host's own drop-in): a switch you turn off stays off, and a change that did not take is reported instead of logged as done.
* **Fix:** Reset to defaults and delete-on-uninstall clear the plugin's own option cache entries too, so a stale cache cannot bring a setting back after a reset.

### 1.7.2

* **Feature:** The consent screen an agent sees at sign-in was rebuilt. It carries a real brand mark, your Site Icon, and the connector's own icon when the plugin recognizes it by its verified redirect host.
* **Feature:** The Activity Log now records every MCP request that reaches WordPress. A sign-in with no calls after it means a CDN or firewall stopped the agent before it arrived.
* **Fix:** ChatGPT could not connect on sites where dynamic client registration was off. It has its own switch on the Settings tab again, on by default, and existing installs get switched on when they update.
* **Fix:** Three connection bugs are gone: the OAuth authorize step mangled percent-encoded redirect addresses, a failed session write handed back a session ID that did not exist, and a very large tool schema could exhaust memory while the tool list was built.
* **Fix:** Smaller correctness fixes across WooCommerce, ACF, and Rank Math, plus a wrong capability check on delete-revision, OAuth options left behind at uninstall, and a new readme FAQ for CDNs that block the agent at the edge.

### 1.7.1

* **Fix:** lang:"all" measured a partial set of languages and reported success anyway. It now queries every configured WPML language for posts, pages, media, terms, search, and WooCommerce products, and the shared count helper sums across all of them.
* **Fix:** A tool's visibility in tools/list could disagree with what its execute-time permission check actually allowed, both ways, hiding tools an agent could use and advertising ones it couldn't. Discovery is reconciled with execute-time permissions across custom post types, pages, and ACF term fields.
* **Fix:** The RFC 9728 protected-resource-metadata route 404'd at the path agents actually request it at, breaking Claude connections for at least one user who reported it. It now resolves at the path-suffixed URL the spec calls for.
* **Fix:** moderate-comment reported failure when a comment was already in the requested state (already approved, already spam, and so on), even though nothing was wrong. It reports success on a no-op now, and no longer logs one as an audit error.
* **Fix:** Several code paths could short-circuit or reject a call before it finished, leaving a stuck or unreadable "started" row in the audit log rather than a real outcome, including on WordPress cores before 7.1. Every invocation now gets a unique token and closes out its row cleanly.
* **Fix:** Bridged output from a third-party plugin's own ability could hide an unsafe object a level or two deep. The bridge now recurses into the result to find it, and refuses a bare or ambiguous object rather than assuming it's safe. Bridged output isn't redacted the way this plugin's own abilities' output is, and the readme now says so.
* **Fix:** Media uploads now go through WordPress's own sideload handling and re-sanitize the resulting attachment's content, and the pixel-size cap that no longer matched that path is gone.
* **Fix:** A batch of smaller correctness fixes across WooCommerce, ACF, AIOSEO, Rank Math, and Yoast: writes and reads route through each vendor's own functions instead of re-deriving their behavior, plus fixes to tool descriptions, cache invalidation, and a few pages that had the wrong discovery floor.
* **Chore:** Uninstall now clears both daily cron events, not just the one tied to deleting data.

### 1.7.0

* **Feature:** Sections on the Abilities tab now have an "Enable all writes" button beside "Enable all reads". It ticks the ordinary writes and leaves deletes and high-risk abilities alone.
* **Feature:** The audit log records more. Permanent deletes name what they removed, and term meta, user meta and site settings updates name the keys they wrote. It logs names and identifiers, not the free text a call carried.
* **Fix:** An authorize request in flight when you revoked a grant could still mint a code, and the token endpoint never re-checked consent, so that code redeemed into a working token after the screen said the revoke had succeeded. Consent is checked again at redemption.
* **Fix:** Payment gateway settings returned camelCase credential keys in full and marked none of them redacted. Authorize.Net's apiLoginID and transactionKey are the real case.
* **Fix:** Four ACF writes destroyed stored content and then reported failure: an unresolvable flexible content layout, clearing through a near-matching address, a protected-meta check that read the caller's address instead of ACF's, and a sub-field write landing under an undeclared name. All are refused before anything is written.
* **Fix:** WooCommerce counted refunds as orders, so a store with six orders and three refunds reported nine. Variations were validated against a display filter rather than the parent product, a variation delete reported failure on success, and an order lookup that threw escaped its rollback.
* **Fix:** Two refusals over MCP said "Permission denied" when that was not the problem. Setting alt text hit it because WordPress stores alt under a protected key, and the refusal now names aafm-update-media instead. A call missing a required argument hit it too, and now gets the schema error naming what is missing.
* **Fix:** Invisible characters are stripped from text on its way into storage, covering WooCommerce, SEO and ACF fields, order addresses and stored post text. Permanent deletes stop offering recovery the site cannot deliver, and an ability declaring no risk is treated as a permanent delete. Plus smaller fixes across shipping zones, menu listings on WordPress 6.9, ACF container writes, the first-run wizard, and refusal messages that named the wrong cause.
* **Chore:** Tested against WordPress 7.1. An accessibility pass over the admin screens covers keyboard operation, focus rings under forced colors, and proper names on toggles and table headers. OAuth housekeeping is sturdier, with InnoDB tables so token work rolls back and a cleanup cron that heals itself on subsites. A notice now asks for a wordpress.org review once the plugin has been carrying traffic for a while, and it can be dismissed for good. Vendor repository metadata no longer ships to wordpress.org.

### 1.6.3

* **Chore:** Condensed the changelog so the full release history fits within the wordpress.org listing's length limit, in both readmes. Same releases, fewer lines, with the security and data-integrity fixes still called out one by one.
* **Chore:** Corrected a code comment that slightly overstated when a consumer plugin's short-circuit is visible to the rate-limit release hook.

Full release history: [agentabilitieswp.com/changelog](https://agentabilitieswp.com/changelog/)
