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
 * and keeps hyphens, producing names in the charset ^[A-Za-z0-9_.-]+$. So `aafm/get-posts`
 * becomes `aafm-get-posts`. Removing the slash is the hard blocker we care about; the few
 * client surfaces that also dislike hyphens (some ChatGPT Apps) are a v1.x follow-up - Claude,
 * Cursor, and Windsurf (our v1 targets) accept hyphenated tool names.
 *
 * @param string $ability_name Ability name, e.g. "aafm/get-posts".
 * @return string Sanitized MCP tool name, e.g. "aafm-get-posts".
 */
function aafm_mcp_tool_name( string $ability_name ): string {
	return str_replace( '/', '-', trim( $ability_name ) );
}

/**
 * Build the registration-time $tools catalog: every enabled ability that exists.
 *
 * IMPORTANT (corrected on the live path in Phase 2.4): create_server() runs inside
 * mcp_adapter_init at rest_api_init priority 15, and on the adapter's streamable-HTTP
 * transport the Application Password user is NOT resolved yet at that point - the request
 * is still anonymous. So this list can only decide WHICH abilities exist, not which the
 * connection may call. Per-connection capability filtering happens later, at request time,
 * in aafm_filter_mcp_tools_list() on the adapter's `mcp_adapter_tools_list` hook, where the
 * agent user IS resolved. The hard gate remains each ability's own permission_callback at
 * execute time. (See ROADMAP "Carried issues" for the timing correction to Phase 0.5 #2.)
 *
 * @param array<int,string> $enabled Enabled ability names.
 * @return list<string>
 */
function aafm_build_server_tools( array $enabled ): array {
	$tools = array();
	foreach ( $enabled as $name ) {
		$ability = wp_get_ability( $name );
		if ( ! $ability instanceof WP_Ability ) {
			continue;
		}
		// If a user is already resolved (e.g. unit tests, or a transport that resolves auth
		// before rest_api_init), drop abilities this user cannot call. On the live HTTP path
		// the user is anonymous here, so this is a no-op and the request-time filter does the
		// real work - belt and suspenders, never advertising more than the catalog.
		if ( is_user_logged_in() ) {
			if ( ! aafm_user_can_discover_ability( $name ) ) {
				continue;
			}
		}
		$tools[] = $name;
	}
	return $tools;
}

/**
 * The full set of ability names the server should advertise: native enabled + bridged wrappers.
 *
 * The bridged wrapper names (aafm-bridge/<slug>) are derived from the enabled foreign slugs.
 * aafm_build_server_tools() skips any name with no registered WP_Ability, so a wrapper that
 * failed to register (host plugin inactive) is naturally excluded downstream.
 *
 * @return array<int,string>
 */
function aafm_all_server_ability_names(): array {
	$native  = function_exists( 'aafm_get_enabled_abilities' ) ? aafm_get_enabled_abilities() : array();
	$bridged = array();
	if ( function_exists( 'aafm_get_enabled_bridged_abilities' ) ) {
		foreach ( aafm_get_enabled_bridged_abilities() as $foreign_slug ) {
			$bridged[] = aafm_bridge_tool_name( $foreign_slug );
		}
	}
	return array_values( array_unique( array_merge( $native, $bridged ) ) );
}

/**
 * Whether the current user passes an ability's UNDECORATED permission callback.
 *
 * Uses the raw callback stashed at registration (aafm_remember_raw_permission) so a
 * list-time visibility check never writes a denied audit row. Unknown abilities (no
 * stashed callback) are treated as not-callable - fail closed. A callback that throws is audited
 * and denied rather than allowed to escape, except when the operator has asked for re-throws.
 *
 * @param string              $ability_name Ability name, e.g. "aafm/trash-post".
 * @param array<string,mixed> $input        Input to pass to the permission callback.
 * @return bool
 * @throws \Throwable When the aafm_rethrow_ability_exceptions filter is on, so a development site
 *                    keeps a crashing permission callback loud instead of silently hiding a tool.
 */
function aafm_user_can_call_ability( string $ability_name, array $input = array() ): bool {
	$permission = aafm_remember_raw_permission( $ability_name );
	if ( ! is_callable( $permission ) ) {
		return false;
	}

	// This is the UNDECORATED callback, so the Throwable guard in the decorated closure
	// (aafm_register_ability_with_log(), register.php) does not cover it. For a bridged wrapper
	// that callback delegates into a foreign ability, and this function runs inside
	// aafm_filter_mcp_tools_list()'s per-tool loop - so one throwing vendor callback would take
	// down the ENTIRE tools/list response, hiding every healthy tool too, not just its own.
	// aafm_user_can_discover_ability() carries the same guard over the one branch that never
	// reaches this function, so every path into the listing loop is covered.
	try {
		return true === $permission( $input );
	} catch ( \Throwable $e ) {
		return aafm_deny_crashed_permission_check( $ability_name, $e );
	}
}

/**
 * Fail closed on a permission callback that threw, and leave a record of why.
 *
 * Shared by both discovery choke points so one throwing vendor callback is handled identically
 * whichever branch reached it.
 *
 * @param string     $ability_name Ability whose permission check crashed.
 * @param \Throwable $e            The caught throwable.
 * @return bool Always false.
 * @throws \Throwable When the aafm_rethrow_ability_exceptions filter is on, so a development site
 *                    keeps a crashing permission callback loud instead of silently hiding a tool.
 */
function aafm_deny_crashed_permission_check( string $ability_name, \Throwable $e ): bool {
	/** This filter is documented in includes/register.php, at the execute-side catch. */
	if ( apply_filters( 'aafm_rethrow_ability_exceptions', defined( 'WP_DEBUG' ) && WP_DEBUG, $e ) ) {
		// Worth knowing before turning this on: the callers below are reached from
		// aafm_build_server_tools() as well as from tools/list, and the adapter builds its server on
		// init priority 20, so on a WP_DEBUG site a throwing cap filter fatals every logged-in page
		// load rather than only the MCP endpoint. That was true before this guard existed too - the
		// bare `$permission( $input )` propagated identically - but the switch is what makes it a
		// choice, so say so here.
		throw $e;
	}

	// Fail closed, consistent with the not-callable branch in aafm_user_can_call_ability(). Ordinary
	// denials are deliberately not audited (see that function's docblock) because that would write a
	// row per tool per listing - but a crash is not an ordinary denial, and staying silent would
	// hide the tool from discovery with no record anywhere, since the audited tools/call path is
	// then never reached.
	//
	// Once per (ability, failure) per request, though, not once per check. Read that bound
	// literally, because the ability name is half the key: this removes the REPEAT-CHECK
	// multiplier, not the per-ability row. An MCP request runs the loop twice, once in
	// aafm_build_server_tools() on init and again in aafm_filter_mcp_tools_list at tools/list, so
	// the second pass writes nothing; a single pass over the catalog still writes one row per
	// affected ability. Measured with a throwing user_has_cap filter and the whole native catalog
	// enabled: 83 abilities checked, 38 rows written (the other 45 fail closed before they reach
	// the capability check), a second pass in the same request adds zero, and a genuinely
	// different exception on an already-recorded ability still writes its own row.
	//
	// A per-ability row is the deliberate trade. The alternative bound - one row per request,
	// whatever crashed - would hide which abilities a partially broken cap filter takes out, and
	// that is the diagnostic the row exists to carry. What the callers must not do is write a row
	// per CHECK: they run over every enabled ability, and aafm_build_server_tools() does that on
	// init for every logged-in page load, so the un-deduped shape was rows-per-ability times
	// passes-per-request with nothing to bound it (the pruner is retention-day based, not size
	// based).
	//
	// The static is request-scoped on php-fpm and mod_php, which is what WordPress almost always
	// runs on. Under a persistent worker SAPI (FrankenPHP worker mode, RoadRunner, Swoole) or
	// inside one long-lived wp CLI process it outlives the request, so a repeat of the byte-identical
	// crash in a LATER request is not re-recorded. Keying it on something request-unique costs more
	// than it buys: the suppressed row would be an exact duplicate of one already in the table.
	static $seen = array();
	$detail      = aafm_build_activity_detail_from_exception( $e );
	$key         = $ability_name . '|' . $detail;
	if ( ! isset( $seen[ $key ] ) ) {
		$seen[ $key ] = true;
		$user         = wp_get_current_user();
		aafm_log_activity(
			array(
				'ability'           => $ability_name,
				'status'            => 'denied',
				'principal_user_id' => (int) $user->ID,
				'principal_login'   => (string) $user->user_login,
				'client_id'         => aafm_oauth_current_client_id(),
				'detail'            => $detail,
			)
		);
	}

	return false;
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
 * Returns null for abilities that have no per-object branch - those fall back to the
 * normal empty-input callable check, which is already correct for them.
 *
 * Page caps are derived from the 'page' post-type object so the mapping stays correct
 * if the page caps are ever remapped, rather than hardcoding 'edit_pages'/'delete_pages'.
 *
 * @param string $name Ability name, e.g. "aafm/update-post".
 * @return callable():bool|null Discovery predicate, or null when no override is needed.
 */
function aafm_ability_list_permission( string $name ): ?callable {
	switch ( $name ) {
		// Single-item reads: as discoverable as their list siblings get-posts/get-pages,
		// which gate on the generic 'read' capability.
		case 'aafm/get-post':
		case 'aafm/get-page':
			return static fn(): bool => current_user_can( 'read' );

		// aafm/get-user gates on list_users (object-independent), so it needs no case
		// here - it falls through to its real permission_callback with empty input,
		// which is the correct answer (same as the get-users list sibling).

		// aafm/get-site-settings and aafm/update-site-settings both gate on manage_options
		// (object-independent, no per-object branch), so neither needs a case - each falls
		// through to its real permission_callback with empty input, which is the correct
		// answer. Discovery is proven in SiteSettingsTest (an admin sees them, an editor
		// does not). Documented here so a future maintainer doesn't add a redundant case.

		// aafm/get-activity-log gates on manage_options (object-independent, no per-object
		// branch), so it needs no case - it falls through to its real permission_callback
		// with empty input, the correct answer. Proven in ActivityLogTest.

		// All menu abilities (reads AND writes) gate on the object-independent
		// edit_theme_options capability, so none needs a server.php case; proven in MenusTest.
		// WordPress has no per-menu capability, so there is nothing to scope per id - each menu
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
		// input at discovery - so use the object-independent edit_posts/delete_posts floor;
		// the per-object permission_callback refines at execute. list-blocks and create-block
		// gate on the object-independent edit_posts floor directly, so they need no case here
		// (they fall through to aafm_perm_blocks_floor, the correct answer).
		//
		// Per-plugin SEO integrations (Yoast / Rank Math / AIOSEO): every *-get-post / *-update-post
		// / *-get-schema / *-update-schema gates per-object on edit_post($id) (SEO data is post
		// content), false with empty input - so discovery uses the object-independent edit_posts
		// floor, refined per-object at execute. The *-get-head abilities have their own
		// edit_posts-floor permission_callback, so they need no case here: each falls through to that
		// callback with empty input (the per-object edit_post refinement runs inside its execute).
		case 'aafm/yoast-get-post':
		case 'aafm/yoast-update-post':
		case 'aafm/rankmath-get-post':
		case 'aafm/rankmath-update-post':
		case 'aafm/rankmath-get-schema':
		case 'aafm/rankmath-update-schema':
		case 'aafm/aioseo-get-post':
		case 'aafm/aioseo-update-post':
			return static fn(): bool => current_user_can( 'edit_posts' );

		// ACF integration: the post/term field abilities gate per-object on edit_post($id) /
		// edit_term($term_id), false with empty input, so discovery uses the edit_posts authoring
		// floor, refined per-object at execute (term meta is gated like post meta in this catalog).
		// The user field abilities gate per-object on edit_user($id), so discovery uses the
		// edit_users floor. acf-list-field-groups gates on the object-independent edit_posts floor
		// directly, so it needs no case here: it falls through to aafm_perm_acf_list_field_groups
		// with empty input, the correct discovery answer.
		case 'aafm/acf-get-post-fields':
		case 'aafm/acf-update-post-fields':
		case 'aafm/acf-get-term-fields':
		case 'aafm/acf-update-term-fields':
			return static fn(): bool => current_user_can( 'edit_posts' );
		case 'aafm/acf-get-user-fields':
		case 'aafm/acf-update-user-fields':
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
		// server.php case - each falls through to its real permission_callback with empty
		// input, the correct discovery answer. Proven in WooProductsTest / WooVariationsTest /
		// WooAttributesTest / WooOrdersTest / WooOrderNotesRefundsTest / WooCustomersTest /
		// WooCouponsTest / WooShippingTest (admin discovers, editor does not).

		case 'aafm/get-block':
		case 'aafm/update-block':
			return static fn(): bool => current_user_can( 'edit_posts' );
		case 'aafm/delete-block':
			return static fn(): bool => current_user_can( 'delete_posts' );

		// User writes: update/delete gate per-object on edit_user($id)/delete_user($id),
		// which is false with empty input - so the per-object permission_callback would
		// hide the tool from every capable admin at discovery. Use the object-independent
		// floor (edit_users / delete_users) so a capable admin can SEE the tool; the
		// per-object permission_callback still re-checks the specific user at execute time.
		// create-user gates on create_users (object-independent), so it needs no case and
		// correctly falls through to its real permission_callback with empty input.
		case 'aafm/update-user':
			return static fn(): bool => current_user_can( 'edit_users' );
		case 'aafm/delete-user':
			return static fn(): bool => current_user_can( 'delete_users' );

		// Post writes: the floor cap that the per-object edit_post()/delete_post() refine.
		case 'aafm/update-post':
		case 'aafm/replace-in-post':
		case 'aafm/set-featured-image':
			return static fn(): bool => current_user_can( 'edit_posts' );
		case 'aafm/trash-post':
		case 'aafm/delete-post':
			return static fn(): bool => current_user_can( 'delete_posts' );

		// CPT writes: the type isn't known at discovery time (empty input), so use the
		// object-independent authoring floor. The execute-time permission_callback still
		// enforces the exact type's caps + allowlist + per-object edit.
		case 'aafm/create-cpt-item':
		case 'aafm/update-cpt-item':
			return static fn(): bool => current_user_can( 'edit_posts' );

		// Governed post-meta (get/update/delete + bulk read): all gate on per-object
		// edit_post (reads included - meta can hold private data), so discovery uses the
		// same edit_posts floor as update-post, refined per-object at execute time.
		case 'aafm/get-post-meta':
		case 'aafm/get-all-post-meta':
		case 'aafm/update-post-meta':
		case 'aafm/delete-post-meta':
			return static fn(): bool => current_user_can( 'edit_posts' );

		// Governed user-meta (get/update/delete): all gate per-object on edit_user($id) -
		// reads included, since user meta can hold private data. The user id is unknown at
		// discovery (empty input), so use the object-independent edit_users floor, refined
		// per-object at execute time. Mirrors the post-meta family.
		case 'aafm/get-user-meta':
		case 'aafm/update-user-meta':
		case 'aafm/delete-user-meta':
			return static fn(): bool => current_user_can( 'edit_users' );

		// Page writes: derive edit_pages/delete_pages from the page post-type object.
		case 'aafm/update-page':
			return static function (): bool {
				$pto = get_post_type_object( 'page' );
				return $pto instanceof WP_Post_Type && current_user_can( $pto->cap->edit_posts );
			};
		case 'aafm/trash-page':
		case 'aafm/delete-page':
			return static function (): bool {
				$pto = get_post_type_object( 'page' );
				return $pto instanceof WP_Post_Type && current_user_can( $pto->cap->delete_posts );
			};

		// Comment writes: the site-wide moderate_comments floor the per-object
		// edit_comment() refines at execute time. The comment id is unknown at
		// discovery (empty input), so discovery uses the object-independent floor.
		case 'aafm/moderate-comment':
		case 'aafm/create-comment':
		case 'aafm/update-comment':
		case 'aafm/delete-comment':
			return static fn(): bool => current_user_can( 'moderate_comments' );

		// Revisions: list/get/restore all gate per-object on edit_post on the parent - reads
		// included, since a revision can hold content from when the post was private. Discovery
		// uses the same edit_posts floor as update-post, refined per-object at execute.
		case 'aafm/list-revisions':
		case 'aafm/get-revision':
		case 'aafm/restore-revision':
		case 'aafm/delete-revision':
			return static fn(): bool => current_user_can( 'edit_posts' );

		// Media writes: the attachment id is unknown at discovery (empty input), so use an
		// object-independent authoring floor. The reads (get-media-item/count-media) need NO
		// case - like get-media they fall through to their object-independent permission_callback.
		// The execute-time permission_callback still enforces per-object edit_post/delete_post
		// on the specific attachment.
		case 'aafm/update-media':
		case 'aafm/delete-media':
			return static fn(): bool => current_user_can( 'upload_files' ) || current_user_can( 'edit_posts' );

		// add-post-terms gates per-object on edit_post on the target post; the post id is
		// unknown at discovery (empty input), so use the object-independent authoring floor.
		case 'aafm/add-post-terms':
			return static fn(): bool => current_user_can( 'edit_posts' );

		// Term-meta read/write/delete gate per-object on the term (edit_term - the read
		// included, since term meta can hold private data) - the term id is unknown at
		// discovery, so use the edit_posts authoring floor, refined per-object at execute time.
		// Mirrors the post-meta family (get/update/delete-post-meta).
		case 'aafm/get-term-meta':
		case 'aafm/update-term-meta':
		case 'aafm/delete-term-meta':
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
 * aafm_ability_list_permission() so a capable user can actually see the tool. For every
 * other ability it falls back to the real callback with empty input, which is the correct
 * object-independent check for the general-cap abilities (create-post, get-posts, …).
 *
 * Discovery never grants execution: each ability's permission_callback still runs at
 * execute time and still denies (and audits) on any specific object the user can't touch.
 *
 * @param string $ability_name Ability name, e.g. "aafm/update-post".
 * @return bool
 * @throws \Throwable When the aafm_rethrow_ability_exceptions filter is on.
 */
function aafm_user_can_discover_ability( string $ability_name ): bool {
	$list_permission = aafm_ability_list_permission( $ability_name );
	if ( null !== $list_permission ) {
		// The short-circuit branch needs the same Throwable floor as the fallthrough, and for a
		// closer reason than it looks: every predicate in aafm_ability_list_permission() is a
		// current_user_can() call, which fires map_meta_cap and user_has_cap, and any plugin may hook
		// those. A membership plugin whose user_has_cap callback throws on an unexpected $args shape
		// would take down the whole tools/list response from here, which is verbatim the failure this
		// release exists to stop, just arriving through a native ability instead of a bridged one.
		try {
			return true === $list_permission();
		} catch ( \Throwable $e ) {
			return aafm_deny_crashed_permission_check( $ability_name, $e );
		}
	}
	return aafm_user_can_call_ability( $ability_name, array() );
}

/**
 * Per-connection capability gate for tools/list, applied at request time.
 *
 * The adapter does NOT permission-filter tools/list itself (Phase 0.5.2); it exposes the
 * `mcp_adapter_tools_list` filter (since 0.5.0) which fires while the JSON-RPC method is
 * dispatched - by then the Application Password user IS resolved. We drop any Tool DTO whose
 * backing ability the current user cannot DISCOVER (an object-independent check), so a
 * connection only sees tools it could plausibly use, while the per-object permission_callback
 * still re-checks the specific object at execute time. Non-AAFM tools (no matching enabled
 * ability) are left untouched.
 *
 * @param mixed $tools  Array of Tool DTOs from the adapter.
 * @param mixed $server Adapter server instance (unused).
 * @return mixed Filtered Tool DTOs.
 */
function aafm_filter_mcp_tools_list( $tools, $server = null ) {
	unset( $server );
	if ( ! is_array( $tools ) ) {
		return $tools;
	}

	// Map every server ability - native AND bridged wrappers - to its sanitized MCP tool
	// name once. Bridged wrappers must be gated here too: their raw permission (delegating
	// to the foreign ability) was stashed at registration, so aafm_user_can_discover_ability()
	// resolves the wrapper name and fails closed for an incapable connection. Building the map
	// from natives only would let a wrapper bypass this request-time capability check.
	$enabled_by_tool_name = array();
	foreach ( aafm_all_server_ability_names() as $ability_name ) {
		$enabled_by_tool_name[ aafm_mcp_tool_name( $ability_name ) ] = $ability_name;
	}

	$visible = array();
	foreach ( $tools as $tool ) {
		$tool_name = is_object( $tool ) && method_exists( $tool, 'getName' ) ? (string) $tool->getName() : '';

		// Only gate tools that belong to one of our enabled abilities. Discovery is
		// decoupled from per-object execute authorization (see aafm_user_can_discover_ability):
		// a capable user must SEE per-object tools (update-post, trash-post, …) even though the
		// real permission_callback still re-checks the specific object at execute time.
		if ( isset( $enabled_by_tool_name[ $tool_name ] ) ) {
			if ( ! aafm_user_can_discover_ability( $enabled_by_tool_name[ $tool_name ] ) ) {
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
function aafm_transport_permission_callback( $request ) {
	unset( $request );

	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'aafm_unauthenticated', __( 'Authentication required.', 'agent-abilities-for-mcp' ), array( 'status' => 401 ) );
	}

	if ( ! aafm_ip_is_allowed( aafm_source_ip() ) ) {
		$user = wp_get_current_user();
		aafm_log_activity(
			array(
				'ability'           => '(transport)',
				'status'            => 'denied',
				'principal_user_id' => (int) $user->ID,
				'principal_login'   => (string) $user->user_login,
			)
		);
		return new WP_Error( 'aafm_ip_blocked', __( 'Your network address is not allowed to use this endpoint.', 'agent-abilities-for-mcp' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * Drop the unimplemented `resources` and `prompts` capabilities from the initialize response.
 *
 * The adapter advertises prompts/resources/tools capabilities by default, but this plugin only
 * implements tools - every ability is a tool, and there is no resource or prompt provider. A
 * truthful capability set keeps a client from issuing resources/list or prompts/list calls that
 * could only error. Rebuilds the DTO from its array form with the two unimplemented keys removed,
 * leaving `tools` intact. Defensive: any non-DTO/non-array shape is returned untouched.
 *
 * @param mixed $result The InitializeResult DTO from the adapter.
 * @param mixed $server  The MCP server instance (unused).
 * @return mixed The (possibly rebuilt) initialize result.
 */
function aafm_filter_initialize_capabilities( $result, $server = null ) {
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
function aafm_register_mcp_server( $adapter ): void {
	// Idempotent: the adapter keeps one server per ID and emits an incorrect-usage notice
	// if asked to create a duplicate. Bail if ours already exists so a re-entrant init
	// (or a diagnostics route lookup that re-fires rest_api_init) never trips that notice.
	if ( null !== $adapter->get_server( 'aafm-server' ) ) {
		return;
	}

	$tools = aafm_build_server_tools( aafm_all_server_ability_names() );

	// Per-connection capability gate at request time (the user is anonymous here; see
	// aafm_build_server_tools()). Priority 5 so it runs before any consumer reordering.
	add_filter( 'mcp_adapter_tools_list', 'aafm_filter_mcp_tools_list', 5, 2 );

	// Advertise only the capabilities we actually implement (tools); strip prompts/resources.
	add_filter( 'mcp_adapter_initialize_response', 'aafm_filter_initialize_capabilities', 10, 2 );

	// Wrap a bridged ability's bare top-level list result under a `data` key (see
	// aafm_filter_bridged_tool_call_result() in bridge.php for the full rationale).
	add_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result', 10, 3 );

	$adapter->create_server(
		'aafm-server',
		AAFM_MCP_NAMESPACE,
		AAFM_MCP_ROUTE_SEGMENT,
		__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ),
		__( 'Curated, governed WordPress abilities for AI agents.', 'agent-abilities-for-mcp' ),
		AAFM_VERSION,
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
		$tools,
		array(),
		array(),
		'aafm_transport_permission_callback'
	);
}

/**
 * Stop four specific JSON-RPC "not found" errors on the MCP route from claiming the
 * session died.
 *
 * The MCP 2025-06-18 spec reserves the transport-level HTTP 404 to mean the session is
 * terminated and the client MUST re-initialize. The bundled adapter's
 * McpErrorFactory::mcp_error_to_http_status() maps five different JSON-RPC error codes to
 * that same 404 in one bucket: -32601 (method not found), -32002 (resource not found),
 * -32003 (tool not found), -32004 (prompt not found), and -32005 (session not found). The
 * first four are ordinary application-level errors an agent can read and correct from
 * within the same session - calling an unknown tool, or a real tool the operator has not
 * enabled (never registered, so the lookup fails the same way as a genuinely unknown one) -
 * but the adapter's map tells the client its session is dead regardless. There is no filter,
 * hook, or injectable strategy anywhere in the adapter that can change this mapping at the
 * source (confirmed by direct source review; `McpErrorFactory` is a static utility with no
 * DI entry), and editing vendor/ is not an option, so this reshapes the response after
 * dispatch instead.
 *
 * Rewrites ONLY the exact four codes above, from 404 to 200 (the same status the adapter
 * already uses for every other application-level error, e.g. INVALID_PARAMS), leaving the
 * JSON-RPC error body untouched so the client still sees which error occurred. -32005 is
 * deliberately excluded and MUST keep its 404: that is the one code for which the
 * session-terminated signal is correct, and rewriting it would make a client cling to a
 * dead session. This is an explicit allowlist, not "every code except -32005" - a future
 * JSON-RPC error code the adapter maps to 404 stays 404 here until this list is deliberately
 * updated to include it.
 *
 * Scoped narrowly: the MCP route only, read from the request rather than a global, a 404
 * status, and a single (non-batch) JSON-RPC error response. The route comparison is
 * case-insensitive (`strcasecmp()`), matching how core itself matches REST routes
 * (`class-wp-rest-server.php`'s route regex is built with the `i` modifier) and the same
 * fix aafm_oauth_filter_malformed_json() already needed for the same reason - without it,
 * a request to e.g. `/agent-abilities-for-mcp/MCP` would still reach the adapter and
 * produce the same governed 404, but a case-sensitive comparison here would miss it. A
 * batch response is always a plain list of per-message results, so it has no top-level
 * 'error' key and the code check further down already excludes it on its own; the batch
 * check states that intent explicitly rather than providing protection the next check
 * does not already give. Any miss returns the response untouched.
 *
 * rest_post_dispatch fires on every REST request the whole site serves, not only ours, so
 * the guards are ordered cheapest-and-most-discriminating first: the route check runs
 * before anything is even read off $response, since it alone already rules out every
 * request except the one MCP route this filter cares about.
 *
 * @param mixed           $response The dispatch result (WP_REST_Response on the REST path).
 * @param \WP_REST_Server $server   The REST server (unused).
 * @param mixed           $request  The originating request (WP_REST_Request on the REST path).
 * @return mixed The response with its status rewritten to 200 when every condition matches,
 *               the original response untouched otherwise.
 */
function aafm_mcp_filter_governed_error_status( $response, $server, $request ) {
	unset( $server );

	$route = $request instanceof WP_REST_Request ? $request->get_route() : '';
	if ( 0 !== strcasecmp( aafm_mcp_rest_route(), $route ) ) {
		return $response;
	}

	if ( ! $response instanceof WP_REST_Response ) {
		return $response;
	}

	if ( 404 !== (int) $response->get_status() ) {
		return $response;
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return $response;
	}

	// A batch response is always a sequential list of per-message results (integer keys
	// starting at 0), so it has no top-level 'error' key and the isset() check just below
	// already excludes it on its own - this guard adds no protection beyond that. It exists
	// purely to state the intent explicitly, so a batch staying unrewritten does not depend
	// on the accident of which check happens to run first.
	// array_is_list() needs PHP 8.1; this plugin's floor is PHP 7.4, so build the check
	// by hand.
	if ( array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
		return $response;
	}

	if ( ! isset( $data['error']['code'] ) || ! is_numeric( $data['error']['code'] ) ) {
		return $response;
	}

	$code = (int) $data['error']['code'];

	// Allowlist only: method not found, resource not found, tool not found, prompt not
	// found. -32005 (session not found) is deliberately absent from this list.
	$reportable_in_band = array( -32601, -32002, -32003, -32004 );
	if ( ! in_array( $code, $reportable_in_band, true ) ) {
		return $response;
	}

	$response->set_status( 200 );

	return $response;
}
