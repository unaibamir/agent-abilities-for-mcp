# Agent Abilities for MCP - MCP Server with Permission Controls and Audit Log

WordPress MCP server for Claude and ChatGPT. Per-capability permission controls, everything off by default, and a full audit log.

| | |
|---|---|
| **Contributors** | unaibamir |
| **Tags** | chatgpt, claude, mcp, mcp-server, woocommerce |
| **Requires at least** | 6.9 |
| **Tested up to** | 7.0 |
| **Requires PHP** | 7.4 |
| **Stable tag** | 1.6.0 |
| **License** | [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) |

## Description

### WordPress MCP server for AI agents, governed and off by default

Agent Abilities for MCP is a WordPress plugin that turns your site into a governed Model Context Protocol (MCP) server. It exposes 153 curated WordPress "abilities" (tools) to AI agents like ChatGPT, Claude, Cursor, and VS Code over MCP, so your AI client can read and, when you allow it, write to your site as a real, least-privilege WordPress user you choose. It is built on the WordPress 6.9 Abilities API and the official MCP Adapter, so there is no custom server or transport to trust.

Nothing is exposed until you turn it on. Permission controls are the point: the agent only ever acts as the WordPress user you bind it to, never an admin-equivalent key, and every call is re-checked against that user's capabilities before it runs. The audit log covers the rest. Every call is written down before it runs, denied attempts included, so you can see both what the agent did and what it was stopped from doing. You add reach as you build trust, not all at once. Your own AI client connects in to your site; Agent Abilities for MCP makes no requests to any external or third-party service and has no telemetry.

Prefer to watch first? Here is a short walkthrough of the plugin in action.

[![Agent Abilities for MCP walkthrough](https://img.youtube.com/vi/Raih7X4QgP0/hqdefault.jpg)](https://www.youtube.com/watch?v=Raih7X4QgP0)

Model Context Protocol (MCP) is an open specification originally developed by Anthropic. Claude, ChatGPT, Cursor, VS Code, Gemini, and other product names are trademarks of their respective owners. Agent Abilities for MCP is a third-party plugin and is not affiliated with, endorsed by, or sponsored by any of them.

**Quick links:** [Documentation](https://agentabilitieswp.com/docs/) | [Getting started](https://agentabilitieswp.com/docs/getting-started/) | [Supported clients](https://agentabilitieswp.com/clients/) | [Prompt Library](https://agentabilitieswp.com/prompts/) | [Website](https://agentabilitieswp.com/)

### 🛡️ Permission controls and an audit log on every call

* **Least privilege by design.** The AI agent connects as a real, scoped WordPress user through OAuth or an Application Password, never an admin-equivalent key.
* **Off by default.** Nothing is exposed until you enable it, and updates never silently widen access.
* **Read-only mode.** One switch stops every ability that writes from being registered at all, whatever is ticked, including abilities brought in from your other plugins. It turns nothing on or off by itself, so your selections are still there when you switch it back off.
* **Two-layer capability gating.** A connection only sees the tools its user can call, and every call re-checks that capability before it runs.
* **Honest audit log.** Every call is recorded, denied attempts included, with the principal and the argument keys (never the values). It lives in your own database and clears from the admin.
* **Bounded by construction.** No arbitrary option or meta access, no remote URL fetch, no code execution. Uploads are decoded from inline data and checked by their real bytes against an image allow-list, never fetched from a URL. A created user gets the site default role, never admin, and the last administrator can never be removed. Anything destructive is off by default and capability-gated, and deletes go to Trash where the ability supports it.
* **Optional safety controls.** Switch on a per-minute rate limit, an IP allowlist, a force-to-draft mode, or a title-length cap. All four stay off until you set them.
* **No data leaves your site.** The plugin contacts no AI provider and no external service. Your AI client connects in; the plugin never reaches out.
* **Two ways to connect.** Approve an agent in the browser over OAuth, with no secret to store, or point a dedicated low-privilege user at an Application Password. A guided screen builds the client config and checks the endpoint for you. An Application Password is a whole-site WordPress credential bounded only by that user's role, not something this plugin can scope down, so the allowlist, the high-risk floor, and the audit log below apply to calls made through this plugin's MCP endpoint only. OAuth does not have that limit, since a token this plugin issues only ever authenticates this one endpoint.

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

WordPress 6.9 lets any plugin register its own abilities, not just this one. Agent Abilities for MCP can now bring those in too. When another active plugin declares abilities through the Abilities API, they appear on a dedicated **Other plugins** screen, grouped by the plugin that registered them, every one off until you turn it on. Enable one and it becomes a governed MCP tool under the same rules as the built-in catalog: scoped to the bound user, capability-checked on every call, rate-limited, and written to the same audit log. Argument values are still never stored.

One limit worth knowing, because it is the other plugin's code doing the work and not ours. When a bridged ability publishes a description of what it returns, WordPress checks its answers against that description and refuses one that does not match. When it publishes no such description, there is nothing to check against, so its answer is passed through as given. The governance above still applies in full either way: permissions, scoping, rate limiting and the audit log do not depend on the other plugin declaring anything.

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

### What permission controls do I get over the AI agent?

Agent Abilities for MCP gives you three layers. Every ability is off until you enable it. The agent connects as a real WordPress user you choose, so it can only do what that user's role already allows. Every call re-checks that user's capability before it runs, and a call that fails the check is denied and recorded.

### Is there an audit log of what the agent did?

Yes. Agent Abilities for MCP writes every ability call to an audit log in your own database, denied attempts included. Each entry records the acting user, the ability name, and the argument keys. Argument values are never stored. You can clear the log from the admin screen.

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

Yes. WordPress 6.9 lets any plugin register abilities, and Agent Abilities for MCP can bridge the ones declared by your other active plugins. Open **Other plugins** in the admin, where they are grouped by the plugin that registered them and start off. Turn one on and it becomes a governed MCP tool under the same rules as everything else: scoped to the bound user, capability-checked on every call, rate-limited, and logged. You can enable or disable a whole plugin's set at once, and nothing is exposed until you choose it.

### Is this the same as the WordPress Abilities API, or the official MCP adapter?

It is built on both. WordPress 6.9 ships the Abilities API and the official MCP Adapter; Agent Abilities for MCP registers a curated, governed set of abilities on top of them rather than inventing its own protocol or transport. So there is no bespoke server to trust, and the plugin inherits the standard's behavior. What it adds is the governance layer: the off-by-default catalog, the capability gating, the safety controls, and the audit log.

### How is this different from other WordPress MCP plugins?

Most MCP plugins for WordPress compete on how many tools they can expose. Agent Abilities for MCP competes on control. Everything is off until you enable it, the agent acts as a real least-privilege WordPress user rather than an admin-equivalent key, every call re-checks that user's capability before it runs, and every call is logged, denials included. It builds on the official WordPress Abilities API and MCP Adapter instead of a hand-rolled server, so there is no custom transport to trust. It trades raw tool count for reach you can audit and widen as you build trust.

### What's the difference between this and the WordPress REST API?

The REST API exposes raw endpoints. MCP describes your site's abilities as discoverable tools an AI agent can reason about and call, and this plugin wraps each one in a governance layer: off by default, capability-gated on every call, and logged. It is the same underlying WordPress, governed so an agent can drive it within the limits you set.

### Which WordPress version do I need?

WordPress 6.9 or newer, which is where the Abilities API and the official MCP Adapter the plugin builds on are available. PHP 7.4 or newer is required.

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

No. The plugin connects to no AI provider and makes no requests to any external or third-party service. Your own AI client connects in to your site and calls the abilities you have enabled. Whatever your AI client does with the results afterward is between you and whoever makes that client.

### Does it send data anywhere?

No. The plugin contacts no external service and has no telemetry. Your agent talks directly to your site.

### What does the audit log record?

Every ability call, whether it started, succeeded, errored, or was denied, with the acting user, the ability name, and the argument keys. Argument values are never stored. The activity log lives in your own database and can be cleared from the admin screen.

### Does uninstalling the plugin revoke my agent's access?

Not by itself. Uninstalling removes the plugin's own settings and activity log, and, only if you turned on "Delete data on uninstall" first, its OAuth tables too. It never removes the dedicated agent user the plugin can create for you, or any Application Password issued to it, because those are ordinary WordPress account credentials that exist outside the plugin's own data. To fully cut off an agent, revoke its OAuth grant from the Connection tab, or delete its Application Password or user account from the Users screen, before or after you remove the plugin.

### How do I report a security issue?

Please report security issues privately rather than in the support forum, so a fix can ship before details are public. Use the security contact listed in this repository.

## External Services

This plugin does not contact any external or third-party service. It registers abilities on your own site and answers the requests your AI client sends to it. The one HTTP request it can make on its own is the Connection tab's reachability check, a same-origin call to your own site's MCP endpoint used to confirm it answers, never a request to anywhere else. It includes no analytics or telemetry.

Connecting an AI client to your site is done by the client, not by this plugin. Some MCP clients reach your endpoint directly; others use a small bridge program that runs on your own computer, such as the open-source [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) tool or [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote). Neither bridge is bundled with this plugin or run by it. You install and run it yourself, and it talks only to your site and your local AI client.

## Changelog

### 1.6.0

* **Feature:** Read-only mode, a switch on the Settings tab that stops any ability that writes from being registered as an MCP tool, whatever is ticked. It covers abilities from other plugins as well, each classified by its own annotation.
* **Feature:** Turning read-only mode on or off enables and disables nothing by itself. Your selections are left as they are, so switching the mode back off gives you exactly what you had chosen before.
* **Feature:** An "Enable all reads" button on the Abilities, Integrations and Bridge tabs, which ticks every read ability in a section and leaves the writes alone.
* **Feature:** The page header now states the site's posture on every tab: read-only, read plus write, or read plus write with high-risk unlocked. It is worked out from what would actually register, not from the stored setting.
* **Feature:** Finishing the Quick Connect wizard without choosing write access now turns read-only mode on rather than ticking a set of boxes, so it still holds months later once you have enabled other things.
* **Feature:** Turning read-only mode on or off is recorded in the activity log.
* **Chore:** The minimum PHP version is now 7.4, down from 8.0. WordPress core itself requires 7.2, and about half the sites running a plugin in this category are still on 7.4.
* **Chore:** The Settings tab is reorganised. OAuth leads, read-only mode and the high-risk switch are the first two rows of Safety controls, the longest descriptions fold behind a "See more", and Save settings now follows you down the page instead of sitting at the bottom.
* **Chore:** The tab that lists abilities registered by your other plugins is now called "Other plugins", and it has its own icon rather than sharing the one Integrations uses.
* **Chore:** The plugin's own listing now leads with what it actually gives you, permission controls and an audit log, rather than the generic "for AI agents" framing.
* **Fix:** A refund amount sent with surrounding whitespace is now trimmed before the numeric check, so it is accepted or rejected the same way on every supported PHP version.
* **Fix:** Several lists could come back in a different order on PHP 7.4 than on 8.x. They now sort the same way everywhere.
* **Fix:** An ability from another plugin that returns a result in an unexpected shape is now caught and reported instead of failing further down with a less useful error.

### 1.5.0

* **Feature:** Eight WooCommerce abilities that move money or grant authority (refunds, order status, order updates, payment gateway settings, coupon creation and updates, and tax rate creation and updates) are now locked by default behind a single audited master switch on the Settings tab.
* **Feature:** The activity log now records ability toggles and setting changes, not only ability calls, using a new event-type vocabulary and a detail column that names what changed.
* **Feature:** Every ability you enable or disable is now recorded in the activity log. Before this release, that option was written with no audit trail at all.
* **Feature:** Identifiers in the activity log's detail column now link to the object's edit screen.
* **Feature:** The activity log can now be exported as a CSV, carrying whatever filter is currently applied.
* **Feature:** A failed Application Password attempt against the MCP endpoint is now logged, and rate limited per source IP so a credential-stuffing run cannot flood the log.
* **Fix:** Creating a WooCommerce customer used to require only the capability to manage WooCommerce. It now also requires the capability to create users, since the ability creates a real WordPress account.
* **Fix:** Listing or reading a WooCommerce customer's details used to require only the capability to manage WooCommerce, which let a caller with just that one capability read any WordPress user's email, address, and phone, administrators included. Both abilities now also require the capability to list users, the same one WordPress itself requires to browse Users in wp-admin. A stock WooCommerce Shop Manager does not hold that capability, and is now denied both abilities where it was not before.
* **Fix:** A high-risk ability could still be switched on and saved as an ordinary toggle from the Integrations tab, and the activity log recorded an enable that never actually took effect.
* **Fix:** Payment gateway and shipping settings could return secrets under field names the redaction list did not match, such as passphrase and salt.
* **Fix:** Deleting a WooCommerce product variation did not check the caller's capability on that specific product the way deleting a product does.
* **Fix:** An identifier in the activity log could link to the wrong object when the detail text ahead of it contained an apostrophe.
* **Fix:** The activity log's Event and Detail columns could misalign after filtering or paging.
* **Fix:** Exporting a large activity log could produce a truncated file that still looked complete, and exporting while the log was being written could duplicate rows.
* **Fix:** Integration and ability counts on the admin screens did not refresh after a save until the page was reloaded.
* **Fix:** Bridge group headers counted destructive abilities as ordinary writes, and showed a plugin's raw slug instead of its name.
* **Fix:** Turning the high-risk switch off left a stored value behind instead of clearing the setting.
* **Chore:** Copy now says an Application Password is a whole-site credential bounded by the WordPress role it belongs to, and that this plugin's allowlist, high-risk floor, and audit log only govern calls made through its own MCP endpoint.
* **Chore:** Copy now says uninstalling does not revoke an agent's access on its own, and names what survives.
* **Chore:** Copy now says an OAuth grant's requested scope does not limit what the resulting token can do.
* **Chore:** The rate limit setting now says it ships off by default, and suggests a starting value.
* **Chore:** The listing description now leads with what the plugin is rather than how it works, and the tags swap seo for woocommerce.
* **Chore:** Regenerated the translation template.

### 1.4.3

* **Fix:** Media reads handed back the whole library to anyone who could upload a file or edit a post. An agent connected as an author now sees only what it uploaded, and the full library still goes to users who can edit other people's posts. The media count follows the same rule, so it can no longer report a total that disagrees with the list beside it.
* **Fix:** Deleting a WooCommerce product only checked that you manage the store, not whether that particular product was yours to delete.
* **Fix:** A duplicate product SKU or coupon code came back as an uncaught error rather than a message naming what it collided with.
* **Fix:** An ability bridged from another plugin could answer with a bare list where the protocol asks for an object, and some strict clients reject that outright. Bridged results are now always shaped as an object before they reach the wire.
* **Fix:** The OAuth pointer sent on a 401 compared the request path case-sensitively, so a request that differed only in casing got no pointer at all and the client had nowhere to start.
* **Fix:** The OAuth authorization response left out the issuer that RFC 9207 requires, which is how a client confirms which server actually answered it. Error redirects carry it now as well.
* **Chore:** Tightened the build checks that guard these tools, including one that quietly passed any ability whose code it could not read.

### 1.4.2

* **Fix:** Asking for a post status when creating content did nothing. "Create a post as a draft" published it live instead, and reported success. Create post, page and draft now honour the status you ask for, and refuse it when your user lacks the capability to publish.
* **Fix:** Scheduling was not treated as publishing, so a contributor who asked for a future status could put a post live without the capability to publish one. Scheduled and private now require the same permission as publishing.
* **Fix:** Editing a post's status was checked against the wrong permission, which both let some users set a status they should not have and stopped a contributor changing their own draft. It now checks the capability that actually governs publishing, using the post type's own capability names.
* **Fix:** Custom post types ignored every status except publish, so a request for pending or private silently became a draft.
* **Fix:** Updating a WooCommerce order with `line_items` added new items rather than changing the existing ones, which quietly raised the order total. There is now an `add_line_items` field that says what it does. The old field keeps working exactly as before so nothing breaks.
* **Fix:** The product type sent when updating a WooCommerce product was discarded without a word. Sending one that does not match the product now returns an error instead of pretending it worked.
* **Fix:** A WooCommerce order request that mixed valid and invalid product ids reported failure after it had already written the valid items, leaving an order with items you were told had not been added. Every id is now checked before anything is written, so a bad one fails the whole request and changes nothing.
* **Feature:** Every input on every tool now explains itself. All 505 of them, where only three abilities were fully documented before. Agents were guessing at things like which fields replace rather than merge, that prices are plain decimal strings, that country and state want two-letter codes, and that a meta key outside your allowlist is refused rather than returned empty.
* **Chore:** Added a build check that fails when any tool input goes undocumented, so this cannot drift back.

### 1.4.1

* **Fix:** OAuth errors came back in WordPress's own `{code, message, data}` shape instead of the `{error, error_description}` shape RFC 6749 requires, so no standard OAuth client could read what went wrong. Reported by an external user as issue #68, and wrong since the first release.
* **Fix:** A malformed JSON body sent to an OAuth route was rejected by WordPress before the plugin ever saw it, so it escaped with the same wrong shape and no cache headers at all.
* **Fix:** Responses that carry a credential were missing `Pragma: no-cache` next to `Cache-Control: no-store`, including the response that hands out the token.
* **Fix:** Calling a tool that does not exist, or one you have switched off, returned HTTP 404. The MCP spec reserves that status for "this session is dead, start over", so clients were being told to reconnect after an ordinary mistake. A session that really has expired still returns 404.
* **Fix:** The OAuth discovery document advertised a client-registration endpoint even when dynamic client registration was off, which is the default. A fresh install was pointing connectors at a URL that does not answer.
* **Fix:** No tool declared `openWorldHint`, and the MCP schema reads an absent value as "this tool may reach the open internet". Every ability this plugin provides now declares it false, which is what the plugin has always actually done.
* **Chore:** Rate-limited OAuth responses now send `Retry-After`.

### 1.4.0

* **Feature:** A first-run Quick Connect wizard gets a new admin connected on one screen. Turn on OAuth and copy the endpoint, or create a dedicated agent user and generate an application password, then switch on content reads and, if you want, content writes.
* **Feature:** A pointer on the admin menu greets a brand-new install and points to the plugin page so setup is easy to find.
* **Fix:** The onboarding "Connect your agent" step and the "Agent users" count no longer read any application password as a connected agent. They now track the agent users this plugin created, or an approved OAuth connection, so an unrelated application password stops showing a false "done" or padding the count.

### 1.3.2

* **Feature:** Content reads (posts, pages, search, terms, media, and products) now take an optional language argument and report which language they returned, and a single-item read can fetch a specific translation. Sites without WPML are unaffected.
* **Fix:** On a WPML site the content lists returned only the default language while the counters reported every language, so an agent was told more items existed than it could actually read. The counts now match the language the list returns.
* **Fix:** The menu-item tools reported failure on a multilingual site even when the item was created, because WPML's language filter hid it from the re-read. They now resolve the item by id and work correctly.
* **Chore:** Added a real-WPML contract test and a guard that fails the build if a read-only ability ever starts writing, and kept tooling directories out of the deployed package.

### 1.3.1

* **Fix:** wc-list-customers can now filter by role, so a customer using a role other than "customer" (for example a subscriber on an LMS or membership store) is no longer invisible to the list.
* **Fix:** wc-list-order-notes now correctly detects which notes were written by a person versus WooCommerce itself.
* **Fix:** A payment gateway's display order now reflects its real position in WooCommerce's own list instead of always reporting zero.
* **Fix:** Saving a payment gateway is now verified against the value WooCommerce actually stored, instead of assuming the write took effect.
* **Fix:** The refund executor no longer crashes on a gateway that has no tax method.
* **Fix:** Updating a WooCommerce product attribute now works the same way across WooCommerce versions instead of assuming a single schema.
* **Feature:** WooCommerce abilities now require WooCommerce 9.1 or newer. Below that, the WooCommerce tools simply do not register, with a clear reason shown on the Integrations screen, never a fatal error.
* **Fix:** rankmath-get-head now returns a clear error instead of an empty success when Rank Math's own head renderer is not available.
* **Fix:** AIOSEO write verification no longer reports failure when AIOSEO makes its own benign normalization to a saved value.
* **Fix:** A term's parent must now belong to the same hierarchical taxonomy as the term itself.
* **Fix:** Force-deleting a page no longer reports success when another plugin vetoed the delete.
* **Fix:** count-media no longer counts items sitting in the trash.
* **Fix:** upload-media now fails with a clear error instead of a fatal one when the server is missing the fileinfo PHP extension.
* **Fix:** update-site-settings now reports failure when WordPress silently reverts a value it considers invalid, instead of reporting success on a change that never took effect.
* **Fix:** Abilities from an inactive integration are no longer wiped out when the abilities form is saved.
* **Fix:** The agent-user picker now finds every user with an application password, not just the first page of users.
* **Fix:** A filtered-out ability row is now actually hidden instead of staying on screen.
* **Fix:** Corrected the reset dialog, the rate-limit help, and the privacy disclosures to match what the plugin actually does.
* **Feature:** The activity log now attributes each call to its OAuth client, shows a result count for list and read calls, and leaves a marker behind when the log is cleared.
* **Fix:** Denied OAuth bearer authentication attempts are now logged, and only when they match a real, if invalid, token.
* **Fix:** The OAuth consent screen and the authorization code redirect are never cached.
* **Chore:** The release zip no longer ships the mcp-adapter's Node package metadata, making it smaller.
* **Chore:** Added a real-vendor contract test suite that runs against pinned WooCommerce, Rank Math, AIOSEO, and ACF plugin code, to catch API-shape regressions like several of the fixes above before release instead of after.

### 1.3.0

* **Fix:** New installs now ship with OAuth off by default instead of on. Sites that already had OAuth on keep it on after updating, so existing connections keep working.
* **Fix:** The OAuth consent grant could be phished into getting an administrator to approve a malicious client.
* **Feature:** The OAuth consent screen now warns when the account approving a connection is an administrator.
* **Feature:** The settings screen now warns you before a REST API lockdown would cut off your OAuth connections.
* **Fix:** The MCP capability gate could quietly stop enforcing when another adapter copy loaded first. The plugin now checks that the running adapter still applies the filter, and matches it as a real call rather than a text match.
* **Fix:** The update-user ability did not require edit_users, so an agent could change its own account beyond its own capabilities.
* **Fix:** A bridged ability from another plugin with no destructive annotation is now treated as destructive rather than assumed safe.
* **Feature:** The Abilities Bridge directory now shows each bridged ability's effective permission, not just its name.
* **Fix:** WooCommerce customer listing returned zero customers on every real store, because it called a function WooCommerce does not have.
* **Fix:** WooCommerce shipping zones came back empty on every real store.
* **Fix:** WooCommerce order paging was ignored on stores using legacy (non-HPOS) order storage.
* **Fix:** WooCommerce product attributes were dropped when creating a product and wiped when updating one.
* **Fix:** Yoast's robots_noindex setting was inverted in the tool contract, so an agent wrote the opposite of what it asked for.
* **Fix:** Rank Math social and Twitter images set by an agent now render, because the plugin writes the attachment ID instead of a URL.
* **Fix:** AIOSEO social and Twitter images set by an agent now render. This corrects the image type, the Open Graph fallback, and a reset that was clearing a valid image.
* **Fix:** ACF field writes reported failure on numeric and boolean values even when the value saved.
* **Fix:** Partially updating a menu item wiped any field you did not pass instead of leaving it alone.
* **Fix:** The page-publish permission check did not recognize custom public statuses from other plugins, blocking valid publishes.

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
