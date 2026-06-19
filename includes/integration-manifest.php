<?php
/**
 * Static per-integration ability descriptor + derived count manifest.
 *
 * Integration abilities register only while their host plugin is active, so a live registry walk
 * cannot tell you how many abilities WooCommerce "would" expose on a site where it is not
 * installed. aafm_integration_ability_manifest() holds the full per-ability picture independent of
 * host activation, and aafm_integration_manifest() DERIVES the per-slug counts from it — one source
 * of truth, no second hand-kept tally to drift. It is the count contract for the integration
 * surface, alongside aafm_available_ability_count() for the whole catalog.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Static per-integration ability DESCRIPTOR.
 *
 * For each integration slug, an ordered list of every ability that integration exposes when its
 * host plugin is active: { name, label, risk, description } in registry order. Integration
 * abilities register only while their host plugin is active, so a live registry walk cannot
 * describe (or count) an integration whose host is inactive. This hand-maintained descriptor
 * holds the full picture independent of host activation: it lets the Integrations tab render
 * every ability — disabled — for an inactive host, and lets aafm_integration_manifest() DERIVE
 * the counts from one source instead of a second hand-kept tally.
 *
 * The label/description strings are the post-translation English source (the msgid), wrapped in
 * __() so they localize and pass Plugin Check. `description` carries the registry description;
 * the render layer prefers the matching aafm_ability_disclosures() line at render time and falls
 * back to this, mirroring the active-path hint logic so there is one disclosure source of truth.
 *
 * KEEP IN LOCKSTEP WITH THE REGISTRY. IntegrationManifestTest force-activates every host and
 * asserts this descriptor's names, risks, and per-slug counts equal the live registry's
 * integration rows. If you add or remove an integration ability, update the registry AND this
 * descriptor, or that test fails.
 *
 * @return array<string,list<array{name:string,label:string,risk:string,description:string}>>
 */
function aafm_integration_ability_manifest(): array {
	return array(
		'yoast'       => array(
			array(
				'name'        => 'aafm/yoast-get-post',
				'label'       => __( 'Get post SEO (Yoast)', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads a post\'s Yoast SEO fields (title, description, focus keyword, canonical, social, and the three robots directives) from its _yoast_wpseo_* post meta. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/yoast-update-post',
				'label'       => __( 'Update post SEO (Yoast)', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Writes a post\'s Yoast SEO fields to its _yoast_wpseo_* post meta. URL fields are sanitized as URLs and the robots directives are validated. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/yoast-get-head',
				'label'       => __( 'Get post SEO head (Yoast)', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads the rendered SEO head markup for a post from Yoast, best-effort (empty when no head API is available). Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),
			),
		),
		'rankmath'    => array(
			array(
				'name'        => 'aafm/rankmath-get-post',
				'label'       => __( 'Get post SEO (Rank Math)', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads a post\'s Rank Math SEO fields (title, description, focus keyword, canonical, social, and robots) from its rank_math_* post meta. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/rankmath-update-post',
				'label'       => __( 'Update post SEO (Rank Math)', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Writes a post\'s Rank Math SEO fields to its rank_math_* post meta. URL fields are sanitized as URLs and robots is stored as Rank Math\'s serialized directive array. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/rankmath-get-schema',
				'label'       => __( 'Get post schema (Rank Math)', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads a post\'s structured-data (JSON-LD) schema of a given type from Rank Math\'s rank_math_schema_{Type} post meta. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/rankmath-update-schema',
				'label'       => __( 'Update post schema (Rank Math)', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Writes a post\'s structured-data (JSON-LD) schema of a given type to Rank Math\'s rank_math_schema_{Type} post meta, recursively sanitized. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/rankmath-get-head',
				'label'       => __( 'Get post SEO head (Rank Math)', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads the rendered SEO head markup for a post from Rank Math, best-effort (empty when no head API is available). Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),
			),
		),
		'aioseo'      => array(
			array(
				'name'        => 'aafm/aioseo-get-post',
				'label'       => __( 'Get post SEO (All in One SEO)', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads a post\'s SEO fields (title, description, canonical, social, and robots) from All in One SEO\'s own data store, not post meta. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/aioseo-update-post',
				'label'       => __( 'Update post SEO (All in One SEO)', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Writes a post\'s SEO fields through All in One SEO\'s own data store (not post meta). URL fields are sanitized as URLs. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/aioseo-get-head',
				'label'       => __( 'Get post SEO head (All in One SEO)', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads the rendered SEO head markup for a post from All in One SEO, best-effort (empty when no head API is available). Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),
			),
		),
		'acf'         => array(
			array(
				'name'        => 'aafm/acf-list-field-groups',
				'label'       => __( 'List ACF field groups', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists the ACF field groups and the fields inside each (key, label, and type) for discovery. It returns structure only, never stored values. Requires the edit-posts capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/acf-get-post-fields',
				'label'       => __( 'Get post ACF fields', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads all of a post\'s ACF field values, hydrated by field key. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/acf-update-post-fields',
				'label'       => __( 'Update post ACF fields', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Writes ACF field values on a post by field key, each value sanitized for its field type. Requires edit access to that post.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/acf-get-term-fields',
				'label'       => __( 'Get term ACF fields', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads all of a term\'s ACF field values, hydrated by field key. Requires edit access to that term.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/acf-update-term-fields',
				'label'       => __( 'Update term ACF fields', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Writes ACF field values on a term by field key, each value sanitized for its field type. Requires edit access to that term.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/acf-get-user-fields',
				'label'       => __( 'Get user ACF fields', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads all of a user\'s ACF field values, hydrated by field key. A field of the user_email type returns the real email address under the integration disclaimer. Requires edit access to that user.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/acf-update-user-fields',
				'label'       => __( 'Update user ACF fields', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Writes ACF field values on a user by field key, each value sanitized for its field type. Requires edit access to that user.', 'agent-abilities-for-mcp' ),
			),
		),
		'woocommerce' => array(
			array(
				'name'        => 'aafm/wc-list-products',
				'label'       => __( 'List WooCommerce products', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists WooCommerce products with their id, name, SKU, price, stock status, status, categories, and featured flag, plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-product',
				'label'       => __( 'Get WooCommerce product', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads one WooCommerce product by id, including its description, prices, stock, images, attributes, variation ids, and categories. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-product',
				'label'       => __( 'Create WooCommerce product', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Creates a WooCommerce product from a name (required) plus optional type, status, description, prices, SKU, stock, categories, tags, images, and attributes. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-update-product',
				'label'       => __( 'Update WooCommerce product', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Updates a WooCommerce product by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-product',
				'label'       => __( 'Delete WooCommerce product', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently deletes a WooCommerce product by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-product-variations',
				'label'       => __( 'List WooCommerce product variations', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists a variable product\'s variations by parent product id, each with its id, parent id, SKU, price, stock status, and status, plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-product-variation',
				'label'       => __( 'Get WooCommerce product variation', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads one product variation by id, including its parent id, prices, stock, description, image, and its chosen attribute values. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-product-variation',
				'label'       => __( 'Create WooCommerce product variation', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Creates a variation under a variable product (parent product id required) from optional status, description, prices, SKU, stock, image, and attribute values. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-update-product-variation',
				'label'       => __( 'Update WooCommerce product variation', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Updates a product variation by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-product-variation',
				'label'       => __( 'Delete WooCommerce product variation', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently deletes a product variation by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-product-attributes',
				'label'       => __( 'List WooCommerce product attributes', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists all global WooCommerce product attribute taxonomies with their id, name (label), slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-product-attribute',
				'label'       => __( 'Get WooCommerce product attribute', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads one global WooCommerce product attribute taxonomy by id, including its name, slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-product-attribute',
				'label'       => __( 'Create WooCommerce product attribute', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Creates a new global WooCommerce product attribute taxonomy from a name (required) plus optional slug, type, sort order, and archive flag. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-update-product-attribute',
				'label'       => __( 'Update WooCommerce product attribute', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Updates a global WooCommerce product attribute taxonomy by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-product-attribute',
				'label'       => __( 'Delete WooCommerce product attribute', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently removes a global WooCommerce product attribute taxonomy by id. This deletes the taxonomy and all terms within it and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-orders',
				'label'       => __( 'List WooCommerce orders', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists WooCommerce orders with their id, number, status, total, currency, date, and customer id, plus a total count. List rows are lean — no billing or shipping details. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-order',
				'label'       => __( 'Get WooCommerce order', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads one WooCommerce order by id: line items, totals, status, dates, customer note, and the full customer billing address (including email and phone) and shipping address. Customer PII is returned in full under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-order',
				'label'       => __( 'Create WooCommerce order', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Creates a WooCommerce order from optional status, customer id, billing, shipping, and line items. Returns the full order shape including PII under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-update-order',
				'label'       => __( 'Update WooCommerce order', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Updates a WooCommerce order by id, changing only the fields you send. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-update-order-status',
				'label'       => __( 'Update WooCommerce order status', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Sets the status of a WooCommerce order by id. Accepts both the short form (e.g. "completed") and the wc-prefixed form (e.g. "wc-completed"). Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-order',
				'label'       => __( 'Delete WooCommerce order', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently deletes a WooCommerce order by id. This bypasses the Trash and cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-order-notes',
				'label'       => __( 'List WooCommerce order notes', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists all notes on a WooCommerce order by order id. Returns each note\'s id, text, date, and whether it is customer-facing. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-order-note',
				'label'       => __( 'Get WooCommerce order note', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads a single note on a WooCommerce order by order id and note id. Returns the note text, date, and whether it is customer-facing. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-order-note',
				'label'       => __( 'Create WooCommerce order note', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Adds a note to a WooCommerce order by order id. Optionally marks the note as customer-facing so it appears in the customer\'s account. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-order-note',
				'label'       => __( 'Delete WooCommerce order note', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently deletes a WooCommerce order note by note id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-order-refunds',
				'label'       => __( 'List WooCommerce order refunds', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists all refunds on a WooCommerce order by order id. Returns each refund\'s id, amount, reason, and date. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-order-refund',
				'label'       => __( 'Get WooCommerce order refund', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads a single refund by refund id. Returns the refund amount, reason, and date. Reason text is returned verbatim under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-order-refund',
				'label'       => __( 'Create WooCommerce order refund', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Creates a refund on a WooCommerce order by order id. Accepts an amount, optional reason, and optional line-item breakdown. Reason text is returned verbatim under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-order-refund',
				'label'       => __( 'Delete WooCommerce order refund', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently deletes a WooCommerce order refund by refund id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-customers',
				'label'       => __( 'List WooCommerce customers', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists WooCommerce customers with their id, email, name, username, order count, and total spent. Customer email is returned in full under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-customer',
				'label'       => __( 'Get WooCommerce customer', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads one WooCommerce customer by id, including email, name, username, order count, total spent, date created, and the full billing address (including phone) and shipping address. Customer PII is returned in full under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-customer',
				'label'       => __( 'Create WooCommerce customer', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Creates a WooCommerce customer from an email and username, with optional first name, last name, and billing/shipping address. Returns the full customer shape including PII under the Integrations security disclaimer. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-update-customer',
				'label'       => __( 'Update WooCommerce customer', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Updates a WooCommerce customer by id, changing only the fields you send. An empty request body is a no-op success. Returns the full customer shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-customer',
				'label'       => __( 'Delete WooCommerce customer', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently deletes a WooCommerce customer (WordPress user) by id and reassigns their content to another user. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-coupons',
				'label'       => __( 'List WooCommerce coupons', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists WooCommerce coupons with their id, code, amount, discount type, expiry date, and usage count, plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-coupon',
				'label'       => __( 'Get WooCommerce coupon', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads one WooCommerce coupon by id: code, amount, discount type, expiry, usage limits, spend limits, product and email restrictions, and other config. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-coupon',
				'label'       => __( 'Create WooCommerce coupon', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Creates a WooCommerce coupon from a code and discount type, with optional amount, usage limits, spend limits, product restrictions, and email restrictions. Returns the full coupon shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-update-coupon',
				'label'       => __( 'Update WooCommerce coupon', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Updates a WooCommerce coupon by id, changing only the fields you send. An empty request body is a no-op success. Returns the full coupon shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-coupon',
				'label'       => __( 'Delete WooCommerce coupon', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently deletes a WooCommerce coupon by id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-shipping-zones',
				'label'       => __( 'List WooCommerce shipping zones', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists WooCommerce shipping zones with their id, name, and order. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-shipping-zone',
				'label'       => __( 'Get WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads one WooCommerce shipping zone by id, including its name, order, and zone locations. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-shipping-zone',
				'label'       => __( 'Create WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Creates a WooCommerce shipping zone from a name and optional order. Returns the full zone shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-update-shipping-zone',
				'label'       => __( 'Update WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Updates a WooCommerce shipping zone by id, changing only the fields you send. An empty request body is a no-op success. Returns the full zone shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-shipping-zone',
				'label'       => __( 'Delete WooCommerce shipping zone', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently deletes a WooCommerce shipping zone by id. The Rest of World zone (id 0) cannot be deleted. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-shipping-methods',
				'label'       => __( 'List WooCommerce shipping methods', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists the shipping methods configured in a WooCommerce shipping zone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-shipping-method',
				'label'       => __( 'Get WooCommerce shipping method', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads one shipping method from a WooCommerce shipping zone by zone id and instance id. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-shipping-method',
				'label'       => __( 'Create WooCommerce shipping method', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Adds a shipping method to a WooCommerce shipping zone. Provide the zone id and method type (e.g. flat_rate, free_shipping, local_pickup). Returns the new method shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-update-shipping-method',
				'label'       => __( 'Update WooCommerce shipping method', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Updates a shipping method in a WooCommerce shipping zone by zone id and instance id, changing only the fields you send. Returns the updated method shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-shipping-method',
				'label'       => __( 'Delete WooCommerce shipping method', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently removes a shipping method from a WooCommerce shipping zone by zone id and instance id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-tax-rates',
				'label'       => __( 'List WooCommerce tax rates', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists all WooCommerce tax rates across every tax class, returning id, country, state, rate, name, priority, compound flag, shipping flag, order, and class slug for each. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-tax-rate',
				'label'       => __( 'Get WooCommerce tax rate', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads one WooCommerce tax rate by id, returning id, country, state, rate, name, priority, compound flag, shipping flag, order, and class slug. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-tax-rate',
				'label'       => __( 'Create WooCommerce tax rate', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Creates a WooCommerce tax rate. Required fields: rate (decimal string). Optional: country, state, name, priority, compound, shipping, order, class slug. Returns the full rate shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-update-tax-rate',
				'label'       => __( 'Update WooCommerce tax rate', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Updates a WooCommerce tax rate by id, changing only the fields you send. An empty body (only id) is a no-op success. Returns the updated rate shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-tax-rate',
				'label'       => __( 'Delete WooCommerce tax rate', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently removes a WooCommerce tax rate by id. This cannot be undone. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-tax-classes',
				'label'       => __( 'List WooCommerce tax classes', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists all WooCommerce tax classes including the Standard class, returning name and slug for each. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-tax-class',
				'label'       => __( 'Get WooCommerce tax class', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads one WooCommerce tax class by slug, returning name and slug. Use slug "standard" for the built-in Standard class. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-create-tax-class',
				'label'       => __( 'Create WooCommerce tax class', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Creates a WooCommerce tax class from a name, with an optional slug. Returns the new class shape. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-delete-tax-class',
				'label'       => __( 'Delete WooCommerce tax class', 'agent-abilities-for-mcp' ),
				'risk'        => 'destructive',
				'description' => __( 'Permanently removes a WooCommerce tax class by slug. This cannot be undone. The Standard class cannot be deleted. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-sales-report',
				'label'       => __( 'Get WooCommerce sales report', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Returns a sales summary for a date range: total sales, order count, net sales, and average order value. Defaults to the current calendar month. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-top-sellers-report',
				'label'       => __( 'Get WooCommerce top sellers report', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Returns the best-selling products for a period (week, month, or year) ordered by quantity sold. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-count-orders',
				'label'       => __( 'Count WooCommerce orders', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Returns order counts broken down by WooCommerce status (pending, processing, on-hold, completed, cancelled, refunded, failed) plus a total. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-count-products',
				'label'       => __( 'Count WooCommerce products', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Returns product counts broken down by post status (publish, draft, private, pending, trash) plus a total of active (non-trash) products. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-count-customers',
				'label'       => __( 'Count WooCommerce customers', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Returns the count of registered users on the site. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-list-payment-gateways',
				'label'       => __( 'List WooCommerce payment gateways', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Lists all registered WooCommerce payment gateways with their id, title, and enabled state. Secret or credential settings are never returned. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-get-payment-gateway',
				'label'       => __( 'Get WooCommerce payment gateway', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Reads one WooCommerce payment gateway by id, including its title, description, enabled state, order, and non-secret settings. Credential and key fields are always redacted. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-count-coupons',
				'label'       => __( 'Count WooCommerce coupons', 'agent-abilities-for-mcp' ),
				'risk'        => 'read',
				'description' => __( 'Returns coupon counts broken down by post status (publish, draft, private, pending, trash) plus a total of active coupons. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
			array(
				'name'        => 'aafm/wc-update-payment-gateway',
				'label'       => __( 'Update WooCommerce payment gateway', 'agent-abilities-for-mcp' ),
				'risk'        => 'write',
				'description' => __( 'Updates a WooCommerce payment gateway by id, changing only the fields you send: enabled state, title, description, or display order. Returns the updated gateway shape with secrets redacted. Requires the manage-WooCommerce capability.', 'agent-abilities-for-mcp' ),
			),
		),
	);
}

/**
 * Per-integration ability counts, independent of whether the host plugin is active.
 *
 * DERIVED from aafm_integration_ability_manifest(): total is the row count, and read / write /
 * destructive are the per-risk tallies. The return shape is unchanged — each slug maps to
 * {total, read, write, destructive}, total === read + write + destructive — so every caller
 * (aafm_available_ability_count(), the Dashboard and Abilities counts, the Integrations card) is
 * untouched by the source change. The slugs match the integration subjects used in the registry
 * and the Integrations cards (see aafm_integration_cards()).
 *
 * @return array<string,array{total:int,read:int,write:int,destructive:int}>
 */
function aafm_integration_manifest(): array {
	$manifest = array();
	foreach ( aafm_integration_ability_manifest() as $slug => $rows ) {
		$read        = 0;
		$write       = 0;
		$destructive = 0;
		foreach ( $rows as $row ) {
			switch ( (string) ( $row['risk'] ?? 'read' ) ) {
				case 'read':
					++$read;
					break;
				case 'destructive':
					++$destructive;
					break;
				default:
					++$write;
			}
		}
		$manifest[ $slug ] = array(
			'total'       => count( $rows ),
			'read'        => $read,
			'write'       => $write,
			'destructive' => $destructive,
		);
	}
	return $manifest;
}

/**
 * The total number of abilities the catalog can expose, counted independently of which host
 * plugins are currently active.
 *
 * The single source of truth the Dashboard and the Abilities tab both read for "available /
 * total", so the two views can never disagree. It is the count of core (non-integration)
 * abilities — taken from the live registry, which always holds every core ability — plus every
 * integration's manifest total, so an inactive integration still contributes its full count.
 *
 * @return int
 */
function aafm_available_ability_count(): int {
	$manifest       = aafm_integration_manifest();
	$manifest_slugs = array_keys( $manifest );

	// Core = registry entries whose subject is not an integration slug. The registry always holds
	// every core ability regardless of host activation, so this is stable.
	$core     = 0;
	$registry = aafm_get_abilities_registry();
	foreach ( $registry as $meta ) {
		if ( ! in_array( (string) ( $meta['subject'] ?? '' ), $manifest_slugs, true ) ) {
			++$core;
		}
	}

	$manifest_total = 0;
	foreach ( $manifest as $counts ) {
		$manifest_total += (int) $counts['total'];
	}

	return $core + $manifest_total;
}
