<?php
/**
 * MCP server registration and tool-name helpers.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Mirror the adapter's McpNameSanitizer for display purposes (connect wizard, diagnostics).
 *
 * CONFIRMED against the vendored 0.5.0 source (Phase 0.5.2): the adapter converts '/' -> '-'
 * and keeps hyphens, producing names in the charset ^[A-Za-z0-9_.-]+$. So `oversio/get-posts`
 * becomes `oversio-get-posts`. Removing the slash is the hard blocker we care about; the few
 * client surfaces that also dislike hyphens (some ChatGPT Apps) are a v1.x follow-up — Claude,
 * Cursor, and Windsurf (our v1 targets) accept hyphenated tool names.
 *
 * @param string $ability_name Ability name, e.g. "oversio/get-posts".
 * @return string Sanitized MCP tool name, e.g. "oversio-get-posts".
 */
function oversio_mcp_tool_name( string $ability_name ): string {
	return str_replace( '/', '-', trim( $ability_name ) );
}

/**
 * Build the registration-time $tools catalog: every enabled ability that exists.
 *
 * IMPORTANT (corrected on the live path in Phase 2.4): create_server() runs inside
 * mcp_adapter_init at rest_api_init priority 15, and on the adapter's streamable-HTTP
 * transport the Application Password user is NOT resolved yet at that point — the request
 * is still anonymous. So this list can only decide WHICH abilities exist, not which the
 * connection may call. Per-connection capability filtering happens later, at request time,
 * in oversio_filter_mcp_tools_list() on the adapter's `mcp_adapter_tools_list` hook, where the
 * agent user IS resolved. The hard gate remains each ability's own permission_callback at
 * execute time. (See ROADMAP "Carried issues" for the timing correction to Phase 0.5 #2.)
 *
 * @param array<int,string> $enabled Enabled ability names.
 * @return list<string>
 */
function oversio_build_server_tools( array $enabled ): array {
	$tools = array();
	foreach ( $enabled as $name ) {
		$ability = wp_get_ability( $name );
		if ( ! $ability instanceof WP_Ability ) {
			continue;
		}
		// If a user is already resolved (e.g. unit tests, or a transport that resolves auth
		// before rest_api_init), drop abilities this user cannot call. On the live HTTP path
		// the user is anonymous here, so this is a no-op and the request-time filter does the
		// real work — belt and suspenders, never advertising more than the catalog.
		if ( is_user_logged_in() ) {
			if ( ! oversio_user_can_discover_ability( $name ) ) {
				continue;
			}
		}
		$tools[] = $name;
	}
	return $tools;
}

/**
 * Whether the current user passes an ability's UNDECORATED permission callback.
 *
 * Uses the raw callback stashed at registration (oversio_remember_raw_permission) so a
 * list-time visibility check never writes a denied audit row. Unknown abilities (no
 * stashed callback) are treated as not-callable — fail closed.
 *
 * @param string              $ability_name Ability name, e.g. "oversio/trash-post".
 * @param array<string,mixed> $input        Input to pass to the permission callback.
 * @return bool
 */
function oversio_user_can_call_ability( string $ability_name, array $input = array() ): bool {
	$permission = oversio_remember_raw_permission( $ability_name );
	if ( ! is_callable( $permission ) ) {
		return false;
	}
	return true === $permission( $input );
}

/**
 * An object-INDEPENDENT discovery predicate for abilities whose execute-time
 * permission_callback needs a specific object id from the input.
 *
 * The tools/list visibility check runs with empty input, but several abilities gate
 * on a per-object capability (e.g. edit_post( $id )) that is always false when no id is
 * present. With empty input those tools would be hidden even from a fully capable user,
 * so they become undiscoverable and the agent can never call them. This map returns a
 * coarse, id-free "can this user use this kind of tool at all" check used ONLY for
 * discovery; the per-object permission_callback is left untouched and still runs as the
 * hard EXECUTE-time gate (and still denies + audits on objects the user can't act on).
 *
 * Returns null for abilities that have no per-object branch — those fall back to the
 * normal empty-input callable check, which is already correct for them.
 *
 * Page caps are derived from the 'page' post-type object so the mapping stays correct
 * if the page caps are ever remapped, rather than hardcoding 'edit_pages'/'delete_pages'.
 *
 * @param string $name Ability name, e.g. "oversio/update-post".
 * @return callable():bool|null Discovery predicate, or null when no override is needed.
 */
function oversio_ability_list_permission( string $name ): ?callable {
	switch ( $name ) {
		// Single-item reads: as discoverable as their list siblings get-posts/get-pages,
		// which gate on the generic 'read' capability.
		case 'oversio/get-post':
		case 'oversio/get-page':
			return static fn(): bool => current_user_can( 'read' );

		// oversio/get-user gates on list_users (object-independent), so it needs no case
		// here — it falls through to its real permission_callback with empty input,
		// which is the correct answer (same as the get-users list sibling).

		// oversio/get-site-settings and oversio/update-site-settings both gate on manage_options
		// (object-independent, no per-object branch), so neither needs a case — each falls
		// through to its real permission_callback with empty input, which is the correct
		// answer. Discovery is proven in SiteSettingsTest (an admin sees them, an editor
		// does not). Documented here so a future maintainer doesn't add a redundant case.

		// oversio/get-activity-log gates on manage_options (object-independent, no per-object
		// branch), so it needs no case — it falls through to its real permission_callback
		// with empty input, the correct answer. Proven in ActivityLogTest.

		// All menu abilities (reads AND writes) gate on the object-independent
		// edit_theme_options capability, so none needs a server.php case; proven in MenusTest.
		// WordPress has no per-menu capability, so there is nothing to scope per id — each menu
		// ability falls through to its real permission_callback with empty input, which is the
		// correct discovery answer for reads and writes alike.

		// The FSE family (get-active-theme, list-themes, list-templates, get-template,
		// get-global-styles, and update-template) gates on the same object-independent
		// edit_theme_options capability, so none needs a server.php case either. WordPress has no
		// per-theme or per-template capability, so there is nothing to scope per id; each falls
		// through to its real permission_callback with empty input, the correct discovery answer
		// for the reads and the single write alike. Proven in ThemesTest (an admin discovers
		// update-template, an editor does not).

		// Reusable-block reads/writes: get-block, update-block, and delete-block gate
		// per-object on edit_post/delete_post on the wp_block id, which is false with empty
		// input at discovery — so use the object-independent edit_posts/delete_posts floor;
		// the per-object permission_callback refines at execute. list-blocks and create-block
		// gate on the object-independent edit_posts floor directly, so they need no case here
		// (they fall through to oversio_perm_blocks_floor, the correct answer).
		//
		// Per-plugin SEO integrations (Yoast / Rank Math / AIOSEO): every *-get-post / *-update-post
		// / *-get-schema / *-update-schema gates per-object on edit_post($id) (SEO data is post
		// content), false with empty input — so discovery uses the object-independent edit_posts
		// floor, refined per-object at execute. The *-get-head abilities have their own
		// edit_posts-floor permission_callback, so they need no case here: each falls through to that
		// callback with empty input (the per-object edit_post refinement runs inside its execute).
		case 'oversio/yoast-get-post':
		case 'oversio/yoast-update-post':
		case 'oversio/rankmath-get-post':
		case 'oversio/rankmath-update-post':
		case 'oversio/rankmath-get-schema':
		case 'oversio/rankmath-update-schema':
		case 'oversio/aioseo-get-post':
		case 'oversio/aioseo-update-post':
			return static fn(): bool => current_user_can( 'edit_posts' );

		// ACF integration: the post/term field abilities gate per-object on edit_post($id) /
		// edit_term($term_id), false with empty input, so discovery uses the edit_posts authoring
		// floor, refined per-object at execute (term meta is gated like post meta in this catalog).
		// The user field abilities gate per-object on edit_user($id), so discovery uses the
		// edit_users floor. acf-list-field-groups gates on the object-independent edit_posts floor
		// directly, so it needs no case here: it falls through to oversio_perm_acf_list_field_groups
		// with empty input, the correct discovery answer.
		case 'oversio/acf-get-post-fields':
		case 'oversio/acf-update-post-fields':
		case 'oversio/acf-get-term-fields':
		case 'oversio/acf-update-term-fields':
			return static fn(): bool => current_user_can( 'edit_posts' );
		case 'oversio/acf-get-user-fields':
		case 'oversio/acf-update-user-fields':
			return static fn(): bool => current_user_can( 'edit_users' );
		// WooCommerce integration: every product, product-variation, global product-attribute,
		// order, order-note, order-refund, and customer ability (wc-list-products, wc-get-product,
		// wc-create-product, wc-update-product, wc-delete-product, wc-list-product-variations,
		// wc-get-product-variation, wc-create-product-variation, wc-update-product-variation,
		// wc-delete-product-variation, wc-list-product-attributes,
		// wc-create-product-attribute, wc-update-product-attribute,
		// wc-list-orders, wc-get-order, wc-create-order, wc-update-order, wc-update-order-status,
		// wc-list-order-notes, wc-create-order-note,
		// wc-list-order-refunds, wc-get-order-refund, wc-create-order-refund,
		// wc-list-customers, wc-get-customer, wc-create-customer,
		// wc-update-customer, wc-list-coupons, wc-get-coupon,
		// wc-create-coupon, wc-update-coupon, wc-list-shipping-zones,
		// wc-get-shipping-zone, wc-create-shipping-zone, wc-update-shipping-zone,
		// wc-list-shipping-methods, wc-get-shipping-method,
		// wc-create-shipping-method, wc-update-shipping-method)
		// gates on the object-independent manage_woocommerce capability, so NONE needs a
		// server.php case — each falls through to its real permission_callback with empty
		// input, the correct discovery answer. Proven in WooProductsTest / WooVariationsTest /
		// WooAttributesTest / WooOrdersTest / WooOrderNotesRefundsTest / WooCustomersTest /
		// WooCouponsTest / WooShippingTest (admin discovers, editor does not).

		case 'oversio/get-block':
		case 'oversio/update-block':
			return static fn(): bool => current_user_can( 'edit_posts' );
		case 'oversio/delete-block':
			return static fn(): bool => current_user_can( 'delete_posts' );

		// User writes: update/delete gate per-object on edit_user($id)/delete_user($id),
		// which is false with empty input — so the per-object permission_callback would
		// hide the tool from every capable admin at discovery. Use the object-independent
		// floor (edit_users / delete_users) so a capable admin can SEE the tool; the
		// per-object permission_callback still re-checks the specific user at execute time.
		// create-user gates on create_users (object-independent), so it needs no case and
		// correctly falls through to its real permission_callback with empty input.
		case 'oversio/update-user':
			return static fn(): bool => current_user_can( 'edit_users' );
		case 'oversio/delete-user':
			return static fn(): bool => current_user_can( 'delete_users' );

		// Post writes: the floor cap that the per-object edit_post()/delete_post() refine.
		case 'oversio/update-post':
		case 'oversio/replace-in-post':
		case 'oversio/set-featured-image':
			return static fn(): bool => current_user_can( 'edit_posts' );
		case 'oversio/trash-post':
		case 'oversio/delete-post':
			return static fn(): bool => current_user_can( 'delete_posts' );

		// CPT writes: the type isn't known at discovery time (empty input), so use the
		// object-independent authoring floor. The execute-time permission_callback still
		// enforces the exact type's caps + allowlist + per-object edit.
		case 'oversio/create-cpt-item':
		case 'oversio/update-cpt-item':
			return static fn(): bool => current_user_can( 'edit_posts' );

		// Governed post-meta (get/update/delete + bulk read): all gate on per-object
		// edit_post (reads included — meta can hold private data), so discovery uses the
		// same edit_posts floor as update-post, refined per-object at execute time.
		case 'oversio/get-post-meta':
		case 'oversio/get-all-post-meta':
		case 'oversio/update-post-meta':
		case 'oversio/delete-post-meta':
			return static fn(): bool => current_user_can( 'edit_posts' );

		// Governed user-meta (get/update/delete): all gate per-object on edit_user($id) —
		// reads included, since user meta can hold private data. The user id is unknown at
		// discovery (empty input), so use the object-independent edit_users floor, refined
		// per-object at execute time. Mirrors the post-meta family.
		case 'oversio/get-user-meta':
		case 'oversio/update-user-meta':
		case 'oversio/delete-user-meta':
			return static fn(): bool => current_user_can( 'edit_users' );

		// Page writes: derive edit_pages/delete_pages from the page post-type object.
		case 'oversio/update-page':
			return static function (): bool {
				$pto = get_post_type_object( 'page' );
				return $pto instanceof WP_Post_Type && current_user_can( $pto->cap->edit_posts );
			};
		case 'oversio/trash-page':
		case 'oversio/delete-page':
			return static function (): bool {
				$pto = get_post_type_object( 'page' );
				return $pto instanceof WP_Post_Type && current_user_can( $pto->cap->delete_posts );
			};

		// Comment writes: the site-wide moderate_comments floor the per-object
		// edit_comment() refines at execute time. The comment id is unknown at
		// discovery (empty input), so discovery uses the object-independent floor.
		case 'oversio/moderate-comment':
		case 'oversio/create-comment':
		case 'oversio/update-comment':
		case 'oversio/delete-comment':
			return static fn(): bool => current_user_can( 'moderate_comments' );

		// Revisions: list/get/restore all gate per-object on edit_post on the parent — reads
		// included, since a revision can hold content from when the post was private. Discovery
		// uses the same edit_posts floor as update-post, refined per-object at execute.
		case 'oversio/list-revisions':
		case 'oversio/get-revision':
		case 'oversio/restore-revision':
		case 'oversio/delete-revision':
			return static fn(): bool => current_user_can( 'edit_posts' );

		// Media writes: the attachment id is unknown at discovery (empty input), so use an
		// object-independent authoring floor. The reads (get-media-item/count-media) need NO
		// case — like get-media they fall through to their object-independent permission_callback.
		// The execute-time permission_callback still enforces per-object edit_post/delete_post
		// on the specific attachment.
		case 'oversio/update-media':
		case 'oversio/delete-media':
			return static fn(): bool => current_user_can( 'upload_files' ) || current_user_can( 'edit_posts' );

		// add-post-terms gates per-object on edit_post on the target post; the post id is
		// unknown at discovery (empty input), so use the object-independent authoring floor.
		case 'oversio/add-post-terms':
			return static fn(): bool => current_user_can( 'edit_posts' );

		// Term-meta read/write/delete gate per-object on the term (edit_term — the read
		// included, since term meta can hold private data) — the term id is unknown at
		// discovery, so use the edit_posts authoring floor, refined per-object at execute time.
		// Mirrors the post-meta family (get/update/delete-post-meta).
		case 'oversio/get-term-meta':
		case 'oversio/update-term-meta':
		case 'oversio/delete-term-meta':
			return static fn(): bool => current_user_can( 'edit_posts' );

		default:
			return null;
	}
}

/**
 * Whether the current user may DISCOVER (see in tools/list) a given ability.
 *
 * Discovery is deliberately decoupled from per-object EXECUTE authorization. For abilities
 * with a per-object permission branch this uses the coarse, id-free predicate from
 * oversio_ability_list_permission() so a capable user can actually see the tool. For every
 * other ability it falls back to the real callback with empty input, which is the correct
 * object-independent check for the general-cap abilities (create-post, get-posts, …).
 *
 * Discovery never grants execution: each ability's permission_callback still runs at
 * execute time and still denies (and audits) on any specific object the user can't touch.
 *
 * @param string $ability_name Ability name, e.g. "oversio/update-post".
 * @return bool
 */
function oversio_user_can_discover_ability( string $ability_name ): bool {
	$list_permission = oversio_ability_list_permission( $ability_name );
	if ( null !== $list_permission ) {
		return true === $list_permission();
	}
	return oversio_user_can_call_ability( $ability_name, array() );
}

/**
 * Per-connection capability gate for tools/list, applied at request time.
 *
 * The adapter does NOT permission-filter tools/list itself (Phase 0.5.2); it exposes the
 * `mcp_adapter_tools_list` filter (since 0.5.0) which fires while the JSON-RPC method is
 * dispatched — by then the Application Password user IS resolved. We drop any Tool DTO whose
 * backing ability the current user cannot DISCOVER (an object-independent check), so a
 * connection only sees tools it could plausibly use, while the per-object permission_callback
 * still re-checks the specific object at execute time. Non-OVERSIO tools (no matching enabled
 * ability) are left untouched.
 *
 * @param mixed $tools  Array of Tool DTOs from the adapter.
 * @param mixed $server Adapter server instance (unused).
 * @return mixed Filtered Tool DTOs.
 */
function oversio_filter_mcp_tools_list( $tools, $server = null ) {
	unset( $server );
	if ( ! is_array( $tools ) ) {
		return $tools;
	}

	// Map our enabled abilities to their sanitized MCP tool names once.
	$enabled_by_tool_name = array();
	foreach ( oversio_get_enabled_abilities() as $ability_name ) {
		$enabled_by_tool_name[ oversio_mcp_tool_name( $ability_name ) ] = $ability_name;
	}

	$visible = array();
	foreach ( $tools as $tool ) {
		$tool_name = is_object( $tool ) && method_exists( $tool, 'getName' ) ? (string) $tool->getName() : '';

		// Only gate tools that belong to one of our enabled abilities. Discovery is
		// decoupled from per-object execute authorization (see oversio_user_can_discover_ability):
		// a capable user must SEE per-object tools (update-post, trash-post, …) even though the
		// real permission_callback still re-checks the specific object at execute time.
		if ( isset( $enabled_by_tool_name[ $tool_name ] ) ) {
			if ( ! oversio_user_can_discover_ability( $enabled_by_tool_name[ $tool_name ] ) ) {
				continue;
			}
		}
		$visible[] = $tool;
	}

	return $visible;
}

/**
 * Transport-level gate: require an authenticated user, then enforce the IP allowlist.
 * Per-ability caps do the real work. Named (not inline) so it is unit-testable and
 * PHPStan-visible.
 *
 * @param \WP_REST_Request<array<string,mixed>> $request Incoming request (unused; auth already resolved).
 * @return bool|WP_Error
 */
function oversio_transport_permission_callback( $request ) {
	unset( $request );

	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'oversio_unauthenticated', __( 'Authentication required.', 'oversio-agent-abilities' ), array( 'status' => 401 ) );
	}

	if ( ! oversio_ip_is_allowed( oversio_source_ip() ) ) {
		$user = wp_get_current_user();
		oversio_log_activity(
			array(
				'ability'           => '(transport)',
				'status'            => 'denied',
				'principal_user_id' => (int) $user->ID,
				'principal_login'   => (string) $user->user_login,
			)
		);
		return new WP_Error( 'oversio_ip_blocked', __( 'Your network address is not allowed to use this endpoint.', 'oversio-agent-abilities' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * Drop the unimplemented `resources` and `prompts` capabilities from the initialize response.
 *
 * The adapter advertises prompts/resources/tools capabilities by default, but this plugin only
 * implements tools — every ability is a tool, and there is no resource or prompt provider. A
 * truthful capability set keeps a client from issuing resources/list or prompts/list calls that
 * could only error. Rebuilds the DTO from its array form with the two unimplemented keys removed,
 * leaving `tools` intact. Defensive: any non-DTO/non-array shape is returned untouched.
 *
 * @param mixed $result The InitializeResult DTO from the adapter.
 * @param mixed $server  The MCP server instance (unused).
 * @return mixed The (possibly rebuilt) initialize result.
 */
function oversio_filter_initialize_capabilities( $result, $server = null ) {
	unset( $server );

	if ( ! is_object( $result ) || ! method_exists( $result, 'toArray' ) ) {
		return $result;
	}

	$data = $result->toArray();
	if ( ! is_array( $data ) || ! isset( $data['capabilities'] ) || ! is_array( $data['capabilities'] ) ) {
		return $result;
	}

	unset( $data['capabilities']['resources'], $data['capabilities']['prompts'] );

	if ( ! class_exists( \WP\McpSchema\Common\Protocol\DTO\InitializeResult::class ) ) {
		return $result;
	}

	return \WP\McpSchema\Common\Protocol\DTO\InitializeResult::fromArray( $data );
}

/**
 * Register the single governed MCP server inside mcp_adapter_init.
 *
 * Phase 0.5.1 confirmed the 13-argument create_server() signature and corrected the
 * transport + error-handler FQCNs against the vendored 0.5.0 source.
 *
 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
 * @return void
 */
function oversio_register_mcp_server( $adapter ): void {
	// Idempotent: the adapter keeps one server per ID and emits an incorrect-usage notice
	// if asked to create a duplicate. Bail if ours already exists so a re-entrant init
	// (or a diagnostics route lookup that re-fires rest_api_init) never trips that notice.
	if ( null !== $adapter->get_server( 'oversio-server' ) ) {
		return;
	}

	$tools = oversio_build_server_tools( oversio_get_enabled_abilities() );

	// Per-connection capability gate at request time (the user is anonymous here; see
	// oversio_build_server_tools()). Priority 5 so it runs before any consumer reordering.
	add_filter( 'mcp_adapter_tools_list', 'oversio_filter_mcp_tools_list', 5, 2 );

	// Advertise only the capabilities we actually implement (tools); strip prompts/resources.
	add_filter( 'mcp_adapter_initialize_response', 'oversio_filter_initialize_capabilities', 10, 2 );

	$adapter->create_server(
		'oversio-server',
		OVERSIO_MCP_NAMESPACE,
		OVERSIO_MCP_ROUTE_SEGMENT,
		__( 'Agent Abilities for MCP', 'oversio-agent-abilities' ),
		__( 'Curated, governed WordPress abilities for AI agents.', 'oversio-agent-abilities' ),
		OVERSIO_VERSION,
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
		$tools,
		array(),
		array(),
		'oversio_transport_permission_callback'
	);
}
