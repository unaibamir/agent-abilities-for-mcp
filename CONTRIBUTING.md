# Contributing to Agent Abilities for MCP

This is the developer guide for the plugin's internals: how an ability flows from a PHP definition to a tool an agent can call, where each piece of metadata lives, and what you have to touch to add or change an ability. It does not ship to wordpress.org (it's excluded from the build) — it's here for whoever works on the code.

If you're looking for what the plugin does or how to install it, read `readme.txt` instead.

## The shape of the thing

The repo root *is* the plugin. There's no build step — the files at the top level are what ships. `oversio-agent-abilities.php` is the only entry point; it defines the `OVERSIO_*` constants, then loads everything from `includes/` and wires it up on `plugins_loaded` via `oversio_bootstrap()`.

Everything uses the `oversio_` function prefix, the `OVERSIO_` constant prefix, and the `oversio-agent-abilities` text domain. The style is procedural on purpose — it's the right idiom for a wp.org plugin, and there's no first-party autoloader, just an explicit `require_once` list in the main file. Don't OOP-ify it.

## How an ability becomes a tool

Every ability is declared once in a registry, and the rest of the system reads from there. The path looks like this:

```
includes/abilities/<domain>.php
  └─ add_filter('oversio_abilities_registry', …)   → the registry entry (label, description, group, risk, subject, args_builder)
       │
       ├─ register.php  → walks the LIVE registry, calls each args_builder, hands the result to wp_register_ability()
       │                    → mcp-adapter exposes it as an MCP tool
       │
       ├─ server.php    → per-call capability gate (oversio_ability_list_permission) + the discovery floor for writes
       │
       ├─ admin/disclosures.php → the plain-language line shown in the admin (authored separately, see below)
       │
       └─ integration-manifest.php → counts + the inactive-host catalog view
```

The registry is built by 31 `add_filter('oversio_abilities_registry', …)` callbacks, one per ability file under `includes/abilities/`. Each callback adds its own `$registry['oversio/<slug>'] = array(...)` rows. A registry entry holds:

- `label` and `description` — **the single source of truth.** Nothing else re-types these.
- `group` — `reads` or `writes`.
- `risk` — `read`, `write`, or `destructive`. This drives the catalog counts and is test-locked.
- `subject` — the domain the ability belongs to (`content`, `site`, `users`, `comments`, `media`, `taxonomies`, or an integration slug). This is what partitions core from integration abilities — not the file, not the slug.
- `args_builder` — the name of the function that returns the ability's input schema and callbacks.

### Why label and description live in exactly one place

They didn't used to. A single ability's description was once typed in the registry, again in its args builder, again in the integration manifest, and a fourth plainer version in the disclosures, and they drifted into three different wordings. Now the registry entry is canonical and the other consumers derive from it:

- `oversio_ability_label( 'oversio/<slug>' )` and `oversio_ability_description( 'oversio/<slug>' )` (in `registry.php`) return the canonical strings.
- Every `oversio_args_*` builder calls those two helpers instead of repeating the text.
- The integration manifest hydrates its label/description from the registry too.

One rule you must not break: keep the `__()` call at the registry definition site, with a literal string and the text domain. The derive helpers return the already-translated string — they never wrap a variable in `__()`. Plugin Check's i18n scanner needs to see literal `__('…', 'oversio-agent-abilities')` calls, and it only sees them at the registry. Move the literal and you break translation extraction.

### The disclosure line is deliberately not derived

`includes/admin/disclosures.php` holds a separate, plainer sentence for each ability — the one a site owner reads in the admin when deciding whether to switch the ability on. It's intentionally a different, friendlier voice than the technical `description`, so it's authored by hand, not generated. A completeness test (`AbilitiesDisclosureTest`) makes sure every ability has one, so it can't silently fall behind. If you add an ability, write its disclosure line.

## Core vs. integration, and the host guard

Integration abilities (WooCommerce, Yoast, Rank Math, AIOSEO, ACF) only register when their host plugin is active. Each integration file guards its `add_filter` callback with `oversio_integration_active('<host>')`, so on a site without WooCommerce, no `oversio/wc-*` ability is ever registered or callable.

That creates a problem for counting: the admin still wants to show "WooCommerce would add 67 abilities" even when WooCommerce isn't installed. So there are two registry views:

- `oversio_get_abilities_registry()` — the **live** registry. Host-gated. This is the only thing `register.php` walks, so only active hosts ever expose tools. An inactive host registers nothing.
- `oversio_get_abilities_registry_full()` — the live registry overlaid with every integration's rows regardless of host activation, contributed through the `oversio_abilities_registry_integrations` filter. This feeds the catalog counts, the manifest, and the derive helpers, so an inactive integration's label and description still resolve.

The line that matters: the full view is for counting and deriving strings only. It must never reach the registration path. If it did, an inactive host would leak live tools — which is exactly the thing the guard exists to prevent.

Counts come from `oversio_core_ability_count()` (core, host-independent), the per-integration `oversio_integration_manifest()` (derived from the order descriptor in `oversio_integration_ability_order()`), and `oversio_available_ability_count()` which adds them up. The readme's advertised core count is checked against `oversio_core_ability_count()` by a test, so it can't drift.

### The catalog is locked

`tests/abilities/CatalogTest.php` and `ReadsCatalogTest.php` lock the exact set: 168 abilities, 81 reads and 87 writes, with the per-slug `risk` pinned. They share one fixture, `tests/Fixtures/CatalogFixture.php` — the single list of read and write slugs. `IntegrationManifestTest` separately locks WooCommerce at 67/32/23/12. Any ability you add or remove has to update the fixture and these counts, or the tests fail. That's the tripwire that keeps the catalog honest.

## Adding an ability

1. In the right `includes/abilities/<domain>.php` (or a WooCommerce sub-domain file), add the registry row in the `add_filter('oversio_abilities_registry', …)` callback: `label` and `description` as literal `__()` strings, plus `group`, `risk`, `subject`, and `args_builder`.
2. Write the `oversio_args_<slug>()` builder: the input schema (closed — `additionalProperties: false`), the execute callback, and the permission callback. Pull the label and description from `oversio_ability_label()` / `oversio_ability_description()` — don't retype them.
3. Write the disclosure line in `includes/admin/disclosures.php`.
4. If it's an integration ability, add it to the order descriptor in `includes/integration-manifest.php` (slug + risk, in registry order).
5. Update `tests/Fixtures/CatalogFixture.php` and the locked counts.
6. Security is not optional: sanitize every input, escape every output, gate on a real capability in the permission callback, and never expose raw PII, secrets, or filesystem paths. Deletes go to trash, not permanent removal. Look at a neighbouring ability for the pattern before writing a new one.

## WooCommerce is split by domain

`includes/abilities/woocommerce.php` used to be one 7,700-line file. It's now `includes/abilities/woocommerce/`, one file per sub-domain: `products`, `variations`, `attributes`, `orders` (with order notes and refunds), `customers`, `coupons`, `shipping`, `tax`, `reports`, `gateways`, plus `_shared.php`. The shared file loads first (the `require_once` order in the main plugin file matters) because it holds the helpers the domain files lean on — `oversio_wc_perm`, `oversio_wc_sanitize_price`, `oversio_wc_date_string`. Each domain file registers only its own slugs through its own guarded filter callback, the same pattern every other integration uses.

## Running the checks

Everything runs inside DDEV (PHP 8.3) — never the host:

```bash
ddev exec phpunit          # the test suite
ddev exec phpcs            # WordPress Coding Standards
ddev exec phpstan analyse  # static analysis
```

All three have to be clean before anything is commit-ready.

### Plugin Check, the one with a trap

Run Plugin Check the way CI does — stage the distributable tree first. **Do not** run `ddev wp plugin check oversio-agent-abilities` directly against the dev checkout: the plugin is a symlink to the repo root, so a naked run recurses into the bundled WordPress under `wp/` and either hangs at full CPU or spits out false text-domain errors.

Instead, mirror `.github/workflows/plugin-check.yml`: `rsync` the tree into a real directory named exactly `oversio-agent-abilities` (the name matters — Plugin Check derives the expected text domain from the folder), using `.distignore` as the exclude list so dev-only files don't get scanned, then run the check with `--exclude-directories=vendor,languages`. The folder name has to be exact or you'll get ~105 false "text domain mismatch" errors. Fail only on errors; the single DirectDB warning on the internal audit table is an accepted false positive.

## Commits

One logical change per commit, each independently revertable. Stage deliberately — never `git add -A` over mixed work, and never commit anything under `wp/` or stray `vendor/` drift. Write commit messages as a plain imperative subject with a prose body when one helps. Keep the git history clean and human; the public repo reads as human-authored.
