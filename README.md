# Agent Abilities for MCP - Connect Claude and ChatGPT with Permission Controls and Audit Log

WordPress MCP server for Claude and ChatGPT with limited access, a pause switch, read-only mode, drafts you approve, playbooks and an audit log.

| | |
|---|---|
| **Contributors** | unaibamir |
| **Tags** | mcp-server, ai-agent, claude, mcp, abilities-api |
| **Requires at least** | 6.9 |
| **Tested up to** | 7.1 |
| **Requires PHP** | 7.4 |
| **Stable tag** | 1.10.2 |
| **License** | [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) |

This is the GitHub home of a free WordPress plugin that adds an MCP endpoint to a site, so Claude, ChatGPT, Cursor and other MCP clients can work on it as a WordPress user you choose. It ships 184 abilities (90 core, 94 for plugins such as WooCommerce, ACF and Yoast SEO) and every one starts switched off.

The listing for site owners lives on [WordPress.org](https://wordpress.org/plugins/agent-abilities-for-mcp/). This page is for anyone who wants to read the code, wire the plugin into their own stack or send a patch.

## What the plugin does

Abilities are registered on the WordPress 6.9 Abilities API and served through the official MCP Adapter, which is bundled, so if the standalone Adapter plugin is active too the plugin simply uses whichever copy loads first. Every call to its tools runs as the WordPress user the connection belongs to, over OAuth or an Application Password, and is checked against that user's capabilities.

The admin screen has a switch for each ability, plus read-only mode, per-role and per-connection allowlists, a rate limit, an IP allowlist and Pause all access, and each OAuth connection gets its own pause, end date, rate limit and access preset. An Activity Log table in the site's own database records the acting user, the tool, the argument keys and an identifier-only note, and it never stores free-text content.

Playbooks are step-by-step instructions the owner writes, and agents read the Active ones through two read tools, `aafm/list-playbooks` and `aafm/get-playbook`. Writes through the general content tools to pages owned by Elementor, Divi, Beaver Builder, Avada or Visual Composer are refused. Avada's two dedicated tools are the exception, and they refuse changes to its shortcodes.

One scope note: the Adapter has a default server that other plugins can publish tools to, and those tools are outside this plugin's controls. A Settings choice (Automatic, Always on, Always off) decides whether that server runs, and this plugin's own tools are never on it.

## Install and connect

1. Copy the plugin to `/wp-content/plugins/agent-abilities-for-mcp`, or install it from the Plugins screen, and activate it.
2. Open Agent Abilities for MCP in the admin menu and switch on the tools you want on the Abilities tab, since none of this plugin's tools is on by default.
3. Copy the endpoint from the Connection tab. Hosted apps (ChatGPT, Claude, Manus) take the URL as a custom connector and ask you to approve it once over OAuth.
4. Click Check my connection to confirm the server answers.

The [getting started guide](https://agentabilitieswp.com/docs/getting-started/?utm_source=github&utm_medium=readme&utm_campaign=plugin-repo) and [connecting a client](https://agentabilitieswp.com/docs/connecting-a-client/?utm_source=github&utm_medium=readme&utm_campaign=plugin-repo) cover each client. Claude takes the endpoint under Customize, then Connectors, as a custom connector, while ChatGPT takes it on the web under Plugins, Add custom MCP server, and per OpenAI's [help article](https://help.openai.com/en/articles/12584461) that needs a Business or Enterprise/Edu workspace for full access, or Pro for read and fetch only.

### Endpoint and client config

The endpoint sits under your REST API (replace `example.com` with your domain):

```
https://example.com/wp-json/agent-abilities-for-mcp/mcp
```

OAuth discovery documents are served at `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`, so a server rule that blocks dotfile paths makes discovery fail, and the nginx rule that fixes it is in the [troubleshooting guide](https://agentabilitieswp.com/docs/troubleshooting/?utm_source=github&utm_medium=readme&utm_campaign=plugin-repo).

Editors and command-line clients use a config file. The standard block runs the open-source [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) bridge on your own machine, which walks you through the same one-time OAuth approval in the browser, so the file itself holds no secret:

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

VS Code keys the same block as `servers` in `.vscode/mcp.json`. If you'd rather use an Application Password, create the dedicated agent user the Connection tab offers (a Subscriber, so raise its role to match the job) and connect through the [`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote) bridge:

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

The Connection tab generates both blocks with your real endpoint, plus a Windows variant that wraps `npx` in `cmd /c`. Keep in mind that an Application Password works across the whole REST API, so this plugin's limits apply only to calls through its MCP endpoint, whereas a token it issues over OAuth works on that endpoint alone.

## For developers

### Hooks and filters

These are the main extension points. Every name starts with `aafm_`.

| Hook | Type | What it does |
|---|---|---|
| `aafm_mcp_allowed_origins` | filter | The browser origins allowed to call the endpoint, as `scheme://host[:port]`. Defaults to the site's own origin plus claude.ai, claude.com, chatgpt.com and chat.openai.com. A request with no Origin header is never affected. |
| `aafm_meta_write_full_resave` | filter | Receives `false`, `'post'` or `'term'`, and the ID. Return `true` to re-save the object after an agent changes only its custom fields, for page caches that listen to `save_post`. The default only clears the cache entry. |
| `aafm_agent_meta_written` | action | Fires with `'post'` or `'term'` and the ID after an agent changes only custom fields. |
| `aafm_oauth_min_capability` | filter | The capability needed to approve an OAuth connection. Default `edit_posts`. If any callback is attached, it overrides the Settings role list. |
| `aafm_playbook_starters` | filter | Add starter playbooks. The five built-in starters are restored after the filter, so it cannot remove or replace them. |
| `aafm_ability_called` | action | Fires with the normalized record after an Activity Log row is written. |
| `aafm_write_completed` | action | Fires with a copy of the result and the target once a write's outcome is decided, for every status. |
| `aafm_force_read_only_mode` | filter | Return `true` to force read-only mode on. It can only turn the mode on, never off. |
| `aafm_high_risk_abilities` | filter | Add `aafm/*` ability names to the high-risk set. The built-ins are merged back in, so it can only add. Bridged abilities are not affected. |
| `aafm_rate_limit_per_min`, `aafm_ip_allowlist`, `aafm_max_title_len` | filters | Adjust the stored value. A filter cannot switch off a limit the site owner set in Settings. |
| `aafm_page_builder_markers` | filter | Map of post meta key to builder name used by the page builder guard. |
| `aafm_allowed_post_types` | filter | Custom post types exposed to the content tools. Types that fail the eligibility check stay refused. |

### WP-CLI

```
wp aafm catalog export [--pretty] [--include-native]
```

It prints the abilities that other active plugins register as `aafm.catalog/v1` JSON, and `--include-native` appends this plugin's own abilities.

### Running the checks

Everything runs in DDEV on PHP 8.3:

```
ddev exec phpunit
ddev exec phpcs
ddev exec phpstan analyse
```

Don't run `wp plugin check` against the dev checkout, because the plugin is symlinked to the repo root and Plugin Check follows the link into the bundled WordPress install. [CONTRIBUTING.md](CONTRIBUTING.md) explains how to run it against a clean export, and how an ability goes from a registry row to an MCP tool.

### Contributing and security

Patches are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) first and keep each commit to one logical change. A vulnerability should go through the Report a vulnerability option on the repository's Security tab (see [SECURITY.md](SECURITY.md)) and not a public issue.

## Outbound requests

The plugin calls no AI provider and sends no telemetry. It makes two kinds of request itself: the Connection tab's checks against your own site's address and, only if you enable the off-by-default upload-media-from-url ability, a fetch of the HTTPS URL an agent supplies, which refuses private and reserved addresses, follows no redirects and carries none of your content or credentials. The bridges above run on your own machine and are not bundled.

## More documentation

- [Documentation](https://agentabilitieswp.com/docs/?utm_source=github&utm_medium=readme&utm_campaign=plugin-repo)
- [Safety controls](https://agentabilitieswp.com/docs/safety-controls/?utm_source=github&utm_medium=readme&utm_campaign=plugin-repo)
- [Connection controls](https://agentabilitieswp.com/docs/connection-controls/?utm_source=github&utm_medium=readme&utm_campaign=plugin-repo)
- [Block tools](https://agentabilitieswp.com/docs/block-tools/?utm_source=github&utm_medium=readme&utm_campaign=plugin-repo)
- [Supported clients](https://agentabilitieswp.com/clients/?utm_source=github&utm_medium=readme&utm_campaign=plugin-repo)
- [Security](https://agentabilitieswp.com/security/?utm_source=github&utm_medium=readme&utm_campaign=plugin-repo)

## Disclaimer

The Model Context Protocol is an open specification that Anthropic originally developed. Claude, ChatGPT, Cursor, VS Code, Gemini and other product names belong to their owners, and Agent Abilities for MCP is a third-party plugin that isn't affiliated with, endorsed by or sponsored by any of them.

## Changelog
### 1.10.1

* **Feature:** The new Playbooks tab holds step-by-step instructions for agents. Tick the abilities each one uses or add one of five starters. Two read tools, off until you enable them, let agents list and load Active playbooks.
* **Fix:** A paused connection now signs in and lists tools, and each tool call returns a message that the connection is paused. Claude used to show a firewall or sign-in error instead.
* **Fix:** The MCP endpoint refuses requests from web pages on other sites, as the MCP specification requires, and sends Cache-Control: no-store on every response. Clients that open with server discovery now get the playbook list too.
* **Fix:** A blocked address, the rate limit and an unknown ACF field name now say why in the refusal. An unreadable playbook list is reported rather than overwritten, and a connection row shows Saved after you save it.
* **Chore:** New FAQ answers cover refreshing a client's tools, ChatGPT plans, playbooks and refused browser clients. The delete-tool and ChatGPT menu wording is corrected.

### 1.10.0

* **Feature:** Each OAuth connection can be paused and resumed without signing in again, given an end date and a lower rate limit of its own, and shows when and from which address it was last used. Approving an app, and Quick Connect, offer Read only, Content editor, Shop manager or Custom access.
* **Feature:** The Connection tab now has OAuth and Application Passwords tabs, a token lifetimes card (the client registration switch moved there from Settings), a switch that pauses all MCP access to the site, and a list of the agent users' Application Passwords with their last use and a Revoke button.
* **Feature:** Settings can limit which roles may connect an app and can read the visitor's real address behind Cloudflare or another proxy. Connection Doctor checks MCP headers, JSON bodies, redirects and stale or blocked discovery documents; the Dashboard says why an enabled tool is missing; tabs show a dot while they have unsaved changes; the Abilities tab opens on an All view listing every ability under its category, with the same bulk buttons each category has.
* **Feature:** Agents can list the site's block types and read one block's attributes, supports and styles, plus, for ACF blocks, its field names, choices and an example payload. A new Replace block tool swaps one block in a post or page without resending the whole page. Like every other tool, they stay off until you turn them on in the Abilities tab.
* **Fix:** Plain attribute text that WordPress would only entity-encode, such as an & in an ACF field, is now stored as written for users who may post unfiltered HTML, style and script elements are removed with their contents, WooCommerce product descriptions and Events Calendar events no longer strip the backslash escapes from block attributes, and writes that return content warnings now also flag unknown blocks or ACF fields, invalid attribute JSON and ignored block gaps. A custom-field-only change now clears that post's or term's cache entry (with an action for page caches and an opt-in full re-save for caches that need one), blank-looking IP allowlist lines are dropped, Settings and the Dashboard no longer scroll sideways on a phone, new drafts report real dates, OAuth clients and tokens record their creation time in UTC, and the Activity Log and Connection Doctor show placeholders while they load. The bundled MCP Adapter moves to 0.7.1.

### 1.9.0

* **Feature:** The Activity Log can be searched and filtered by user, connection, tool, plugin, date and text. Each call shows its connection and IP address, and its duration when it ran; the log adds a 7-day summary and a Live view. The CSV export follows your filters.
* **Feature:** Optional alerts, off by default: an admin notice, an email right away or a daily email when an app is authorized to connect, an Application Password is created for a user who can edit posts or for the agent user, a high-risk tool or permanent delete runs, calls are denied in a burst, or read-only mode or the high-risk lock is switched.
* **Fix:** A denied call now records why in the Activity Log, for example "key is not on the allowlist"; the message the agent sees is unchanged. Reading a meta key now logs a note the way writing one does.
* **Chore:** The Cloudflare advice in the FAQ, README and Help tab now points to "Configure AI bot policies" instead of the deprecated "Block AI bots" switch, and adds steps for WP Engine edge traffic rules and HTTP 429 limits.

### 1.8.1

* **Fix:** Bridged tools such as WooCommerce orders and products failed in strict clients like Claude when a value didn't match the format the source plugin declared, for example a date without a timezone or an empty URL. Bridged tools no longer advertise strict formats; the values are unchanged.

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

Full release history: [agentabilitieswp.com/changelog](https://agentabilitieswp.com/changelog/?utm_source=github&utm_medium=readme&utm_campaign=plugin-repo)
