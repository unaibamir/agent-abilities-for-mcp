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
		// aafm_build_server_tools() as well as from tools/list, and the adapter builds its server
		// inside mcp_adapter_init, which on a web request is hooked on rest_api_init priority 15
		// (McpAdapter::instance(), vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php:59-64;
		// the init priority 20 branch is WP-CLI only). So on a WP_DEBUG site a throwing cap filter
		// fatals every REST request that arrives with a logged-in user, block editor traffic
		// included, rather than only the MCP endpoint. aafm_build_server_tools() walks the
		// permission callbacks behind an is_user_logged_in() gate, so ordinary page loads and
		// anonymous REST requests never reach it and tools/list is the rest. That was true before
		// this guard existed too - the bare `$permission( $input )` propagated identically - but the
		// switch is what makes it a choice, so say so here.
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
	// multiplier, not the per-ability row. An MCP request runs the loop twice, once when
	// aafm_build_server_tools() builds the server on rest_api_init and again in
	// aafm_filter_mcp_tools_list at tools/list, so the second pass writes nothing; a single pass
	// over the catalog still writes one row per affected ability.
	// Measured with a throwing user_has_cap filter and the whole native catalog
	// enabled: 83 abilities checked, 38 rows written (the other 45 fail closed before they reach
	// the capability check), a second pass in the same request adds zero, and a genuinely
	// different exception on an already-recorded ability still writes its own row.
	//
	// A per-ability row is the deliberate trade. The alternative bound - one row per request,
	// whatever crashed - would hide which abilities a partially broken cap filter takes out, and
	// that is the diagnostic the row exists to carry. What the callers must not do is write a row
	// per CHECK: they run over every enabled ability, and aafm_build_server_tools() does that on
	// every REST request carrying a logged-in user, block editor traffic included, so the
	// un-deduped shape was rows-per-ability times passes-per-request with nothing to bound it
	// (the pruner is retention-day based, not size based).
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
				// Its own type, never the 'ability_call' default. This row names a REAL ability,
				// so aafm_agent_call_count()'s synthetic-name exclusions cannot see it - and the
				// callers run on every REST request that carries a logged-in user, with zero MCP
				// traffic needed, so under the default type a throwing vendor cap filter flipped the
				// dashboard's "Make your first call" step and every other consumer of that count
				// with no agent involved.
				'event_type'        => 'permission_check_crashed',
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
		// per-object on edit_post/delete_post on the wp_block id (aafm_perm_block_object /
		// aafm_perm_block_delete_object), which is false with empty input at discovery. wp_block is
		// registered with the SAME literal primitive cap names as the built-in post type
		// (edit_posts, edit_others_posts, edit_published_posts, delete_posts, delete_others_posts,
		// delete_published_posts - confirmed in core's create_initial_post_types()), so the floor
		// mirrors update-post/trash-post below exactly: every OR branch map_meta_cap can resolve
		// edit_post/delete_post to for wp_block, never a standalone private-posts arm (wp_block has
		// no author-facing private-status workflow anyway). Refined per-object at execute.
		// list-blocks and create-block gate on the object-independent edit_posts floor directly, so
		// they need no case here (they fall through to aafm_perm_blocks_floor, the correct answer).
		//
		// Per-plugin SEO integrations (Yoast / Rank Math / AIOSEO): every *-get-post / *-update-post
		// / *-get-schema / *-update-schema gates per-object on edit_post($id) (SEO data is post
		// content, via aafm_can_edit_post_object -> aafm_perm_seo_post_object) - false with empty
		// input, so discovery uses the SAME floor that per-object branch can resolve to: edit_posts
		// (author, draft-shaped), edit_others_posts (any other author's post), or
		// edit_published_posts (author's own published/scheduled post). Never a standalone
		// edit_private_posts arm - core only ever pairs that with edit_others_posts, so it adds
		// nothing edit_others_posts doesn't already cover (see the update-page case below for the
		// full citation). Refined per-object at execute. The *-get-head abilities have their own
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
			return static fn(): bool => current_user_can( 'edit_posts' )
				|| current_user_can( 'edit_others_posts' )
				|| current_user_can( 'edit_published_posts' );

		// ACF integration, post fields: gates per-object on edit_post($id) (aafm_perm_acf_post ->
		// aafm_can_edit_post_object), false with empty input - same floor as the SEO family above,
		// for the same reason (both delegate to the identical shared content-edit gate).
		// acf-list-field-groups gates on the object-independent edit_posts floor directly, so it
		// needs no case here: it falls through to aafm_perm_acf_list_field_groups with empty input,
		// the correct discovery answer.
		case 'aafm/acf-get-post-fields':
		case 'aafm/acf-update-post-fields':
			return static fn(): bool => current_user_can( 'edit_posts' )
				|| current_user_can( 'edit_others_posts' )
				|| current_user_can( 'edit_published_posts' );

		// ACF integration, term fields: gates per-object on edit_term($term_id)
		// (aafm_perm_acf_term), NOT edit_post - a genuinely different mechanism from the post-fields
		// case just above, despite the superficial "ACF integration" family resemblance. edit_term
		// resolves through map_meta_cap to the TARGET TAXONOMY's own edit_terms capability (default
		// manage_categories, but a decoupled custom cap on a custom taxonomy), with no
		// author/published/private branching at all - terms have no author or visibility states the
		// way posts do. The taxonomy is unknown at discovery (empty input), so this loops the
		// exposed set, the same shape create-term/update-term and term-meta below use.
		//
		// Deliberately its OWN case, split from term-meta below (round 3), rather than sharing
		// that loop: aafm_perm_acf_term() accepts ANY existing term and checks edit_term($id)
		// directly, with NO aafm_validate_taxonomy()-style public-taxonomy restriction - unlike
		// term-meta, which routes every request through aafm_validate_term_meta_request() ->
		// aafm_validate_taxonomy() and IS restricted to public taxonomies. Sharing term-meta's
		// public-only loop was too narrow for ACF term-fields specifically: a role holding a
		// non-public taxonomy's own decoupled edit_terms cap could genuinely execute ACF
		// term-fields on that taxonomy's terms, yet was hidden from the tool. So this loops EVERY
		// registered taxonomy, public or not, matching aafm_perm_acf_term()'s real, broader floor.
		case 'aafm/acf-get-term-fields':
		case 'aafm/acf-update-term-fields':
			return static function (): bool {
				foreach ( get_taxonomies( array(), 'objects' ) as $tax_object ) {
					if ( $tax_object instanceof WP_Taxonomy && current_user_can( $tax_object->cap->edit_terms ) ) {
						return true;
					}
				}
				return false;
			};

		// ACF integration, user fields: gates per-object on edit_user($id) PLUS the object-
		// independent edit_users floor (aafm_perm_acf_user requires both, mirroring
		// aafm_perm_update_user) - and since edit_users is already required, the self-edit branch
		// of the edit_user meta cap adds nothing beyond it. So edit_users alone is the exact
		// execute-time floor, not an approximation of it; no widening needed here.
		case 'aafm/acf-get-user-fields':
		case 'aafm/acf-update-user-fields':
			return static fn(): bool => current_user_can( 'edit_users' );
		// wc-update-customer writes a user's PII and gates per-object on edit_user($customer_id),
		// which is false with empty input, so discovery uses the object-independent floor
		// (the WooCommerce cap plus edit_users). The per-object permission_callback still
		// re-checks the specific account at execute time.
		case 'aafm/wc-update-customer':
			return static fn(): bool => aafm_wc_perm() && current_user_can( 'edit_users' );
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
		// wc-list-coupons, wc-get-coupon,
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
			return static fn(): bool => current_user_can( 'edit_posts' )
				|| current_user_can( 'edit_others_posts' )
				|| current_user_can( 'edit_published_posts' );
		case 'aafm/delete-block':
			return static fn(): bool => current_user_can( 'delete_posts' )
				|| current_user_can( 'delete_others_posts' )
				|| current_user_can( 'delete_published_posts' );

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

		// Post writes: the per-object edit_post()/delete_post() (aafm_can_edit_post_object /
		// aafm_can_delete_post_object) refine one of these OR branches - author + draft-shaped
		// status -> edit_posts; ANY other author's post -> edit_others_posts; author's own
		// published/scheduled post -> edit_published_posts (never a standalone edit_private_posts
		// arm; see the update-page case below for the full citation of why). Previously this
		// checked edit_posts alone, hiding update-post/replace-in-post/set-featured-image from a
		// role holding only edit_others_posts or only edit_published_posts even though the
		// per-object check genuinely passes for them on a real post - the same class of mismatch
		// Task 8 fixed for update-page, just never carried over to its sibling here.
		case 'aafm/update-post':
		case 'aafm/replace-in-post':
		case 'aafm/set-featured-image':
			return static fn(): bool => current_user_can( 'edit_posts' )
				|| current_user_can( 'edit_others_posts' )
				|| current_user_can( 'edit_published_posts' );
		case 'aafm/trash-post':
		case 'aafm/delete-post':
			return static fn(): bool => current_user_can( 'delete_posts' )
				|| current_user_can( 'delete_others_posts' )
				|| current_user_can( 'delete_published_posts' );

		// CPT creation: the type isn't known at discovery time (empty input), and
		// aafm_perm_create_cpt_item checks nothing beyond the type's own bare
		// edit_posts-equivalent cap (create is pre-insert - no author/status branching, no
		// map_meta_cap gate). Previously this checked the literal core 'edit_posts' string,
		// which is wrong for any allowlisted CPT registered with its own capability_type (e.g.
		// 'product' -> edit_products): a role holding edit_products but not literal edit_posts
		// could genuinely create a draft product, yet was hidden from the tool. Fixed by looping
		// every type aafm_validate_post_type() would actually accept (aafm_allowed_post_types() -
		// post/page always-on plus whatever the operator has allowlisted) and checking each
		// type's OWN cap->edit_posts, the same "loop the exposed set" shape create-term/
		// update-term and the term-meta/ACF-term-fields cases below already use for taxonomies.
		case 'aafm/create-cpt-item':
			return static function (): bool {
				foreach ( aafm_allowed_post_types() as $type ) {
					$pto = get_post_type_object( $type );
					if ( $pto instanceof WP_Post_Type && current_user_can( $pto->cap->edit_posts ) ) {
						return true;
					}
				}
				return false;
			};

		// CPT updates: per-object edit (aafm_can_edit_post_object / aafm_writable_type_caps),
		// the identical shape to update-post above, but resolved through EACH exposed type's
		// OWN cap object rather than the literal core primitive names, for the same
		// custom-capability_type reason as create-cpt-item just above. Previously this shared
		// create-cpt-item's bare literal edit_posts check, which was wrong twice over: wrong for
		// a custom capability_type, AND missing the edit_others_posts/edit_published_posts
		// widening every sibling per-object case already got in round 2. aafm_writable_type_caps
		// additionally requires map_meta_cap===true before it will return a cap object at all
		// (a non-mapped type's per-object edit_post check degrades to a bare singular primitive
		// with no author/status containment and is refused outright), so the loop below skips
		// any type that fails that same gate - matching the execute-time floor exactly rather
		// than approximating it.
		case 'aafm/update-cpt-item':
			return static function (): bool {
				foreach ( aafm_allowed_post_types() as $type ) {
					$caps = aafm_type_caps( $type );
					if ( ! $caps['mapped'] || ! $caps['object'] instanceof WP_Post_Type ) {
						continue;
					}
					$pto = $caps['object'];
					if ( current_user_can( $pto->cap->edit_posts )
						|| current_user_can( $pto->cap->edit_others_posts )
						|| current_user_can( $pto->cap->edit_published_posts )
					) {
						return true;
					}
				}
				return false;
			};

		// Governed post-meta (get/update/delete + bulk read): all gate on per-object
		// edit_post (reads included - meta can hold private data), so discovery uses the
		// same widened edit_posts/edit_others_posts/edit_published_posts floor as update-post
		// just above, refined per-object at execute time.
		case 'aafm/get-post-meta':
		case 'aafm/get-all-post-meta':
		case 'aafm/update-post-meta':
		case 'aafm/delete-post-meta':
			return static fn(): bool => current_user_can( 'edit_posts' )
				|| current_user_can( 'edit_others_posts' )
				|| current_user_can( 'edit_published_posts' );

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
				if ( ! $pto instanceof WP_Post_Type ) {
					return false;
				}
				// The floor here has to be an OR across every primitive capability
				// map_meta_cap('edit_page', ...) can resolve to for SOME object, not just the
				// author-editing-their-own-page case (cap->edit_posts, which for the page post
				// type object IS the edit_pages capability). A role holding only
				// edit_others_pages can still edit another author's draft/pending page, since
				// that branch adds no other required cap. A role holding only
				// edit_published_pages can still edit a published or scheduled page they
				// authored themselves, again the only cap map_meta_cap adds for that case.
				//
				// Deliberately NO edit_private_pages arm. Verified against core's
				// map_meta_cap('edit_page', ...) (wp-includes/capabilities.php, the edit_post/
				// edit_page branch): a private page the CURRENT USER authored does not get its own
				// branch - 'private' isn't 'publish'/'future'/'trash', so it falls into the same
				// else arm as a draft and adds cap->edit_posts (already covered by the first arm
				// above), never cap->edit_private_posts. edit_private_posts only ever appears
				// paired with edit_others_posts, for SOMEONE ELSE'S private page - so a role
				// holding edit_private_pages ALONE (without edit_others_pages) can never satisfy
				// map_meta_cap for any object, on any branch. Keeping it as a standalone OR arm
				// would show the tool to a role that can never actually call it - the same class of
				// defect this comment used to defend keeping, before that was found to be wrong
				// (Codex, fix round 1) and corrected. Removal is safe: anyone who also holds
				// edit_others_pages still matches through that arm.
				return current_user_can( $pto->cap->edit_posts )
					|| current_user_can( $pto->cap->edit_others_posts )
					|| current_user_can( $pto->cap->edit_published_posts );
			};
		case 'aafm/trash-page':
		case 'aafm/delete-page':
			return static function (): bool {
				$pto = get_post_type_object( 'page' );
				if ( ! $pto instanceof WP_Post_Type ) {
					return false;
				}
				// Mirrors the update-page floor above, for the delete side: per-object
				// delete_page (aafm_can_delete_post_object) resolves to delete_posts (author,
				// draft-shaped), delete_others_posts (any other author's page), or
				// delete_published_posts (author's own published/scheduled page) - the page
				// post-type object's own cap names for those primitives. Never a standalone
				// delete_private_posts arm, for the identical reason edit_private_pages is
				// excluded above: core only ever pairs it with delete_others_posts. Previously
				// this checked delete_posts alone, hiding trash-page/delete-page from a role
				// holding only delete_others_pages or only delete_published_pages even though the
				// per-object check genuinely passes for them on a real page (the mismatch Codex
				// found, mirroring update-page's original Task 8 defect one field over).
				return current_user_can( $pto->cap->delete_posts )
					|| current_user_can( $pto->cap->delete_others_posts )
					|| current_user_can( $pto->cap->delete_published_posts );
			};

		// create-comment has NO per-object component at all (aafm_perm_create_comment is a bare
		// moderate_comments check - the comment doesn't exist yet and its author is forced to the
		// current user), so moderate_comments is the exact, complete execute-time floor for it.
		// Split into its own case (round 3) so it stops sharing a closure with the three
		// per-object comment tools below, which genuinely need a different floor.
		case 'aafm/create-comment':
			return static fn(): bool => current_user_can( 'moderate_comments' );

		// moderate-comment/update-comment/delete-comment additionally require
		// current_user_can('edit_comment', $id) beyond moderate_comments. Verified directly
		// against core (wp-includes/capabilities.php, the 'edit_comment' branch of
		// map_meta_cap()): edit_comment resolves to map_meta_cap('edit_post', user, $post->ID) on
		// the comment's PARENT POST - or, if the parent post no longer exists (an orphaned
		// comment), falls back to the single literal primitive map_meta_cap('edit_posts', user)
		// with NO type-specific or author/status branching at all.
		//
		// That parent post is NOT restricted to any allowlist: aafm_exec_create_comment() accepts
		// comment_post_ID for ANY real post get_post() returns, regardless of post_type - so a
		// comment's parent could be of a post type this plugin never exposes for content writes at
		// all. A provably-complete narrowed floor would therefore need to loop EVERY registered
		// post type site-wide (not just aafm_allowed_post_types()), branch per type on whether
		// map_meta_cap is enabled (a non-mapped type collapses to a single literal
		// cap->edit_post primitive, not the edit_posts/edit_others_posts/edit_published_posts OR
		// used everywhere else in this function), AND separately cover the orphaned-comment
		// fallback to literal edit_posts - each piece a real, independent way to get the proof
		// wrong and hide a tool from someone who can genuinely use it, which is a worse defect
		// than the current over-broad state. No built-in WordPress role produces the gap this
		// leaves (Editor and Administrator, the only two with moderate_comments, both already
		// hold edit_posts and edit_others_posts), so this stays a bare moderate_comments check -
		// left alone deliberately rather than narrowed on an incomplete proof, per the same
		// "widen freely, narrow only when provably safe" posture as the rest of this function.
		case 'aafm/moderate-comment':
		case 'aafm/update-comment':
		case 'aafm/delete-comment':
			return static fn(): bool => current_user_can( 'moderate_comments' );

		// Revisions: list/get/restore all gate per-object on edit_post on the parent
		// (aafm_revision_parent_editable -> aafm_can_edit_post_object) - reads included, since a
		// revision can hold content from when the post was private. Discovery uses the same
		// widened edit_posts/edit_others_posts/edit_published_posts floor as update-post above,
		// refined per-object at execute.
		case 'aafm/list-revisions':
		case 'aafm/get-revision':
		case 'aafm/restore-revision':
		case 'aafm/delete-revision':
			return static fn(): bool => current_user_can( 'edit_posts' )
				|| current_user_can( 'edit_others_posts' )
				|| current_user_can( 'edit_published_posts' );

		// Media writes: the attachment id is unknown at discovery (empty input), so use an
		// object-independent floor. The reads (get-media-item/count-media) need NO case - like
		// get-media they fall through to their object-independent permission_callback. The
		// execute-time permission_callback still enforces per-object edit_post/delete_post on the
		// specific attachment (aafm_perm_update_media / aafm_perm_delete_media check
		// current_user_can('edit_post'|'delete_post', $att_id) DIRECTLY - attachments are a
		// _builtin post type, so they never route through the CPT chokepoint at all). Core
		// registers 'attachment' with capability_type 'post', reusing the same literal primitive
		// names as the built-in post type for edit_posts/edit_others_posts/edit_published_posts and
		// delete_posts/delete_others_posts/delete_published_posts (only create_posts is remapped,
		// to upload_files) - so the floor mirrors update-post/trash-post above, split into its own
		// edit case and delete case since they need different primitives.
		//
		// Previously this checked upload_files as a standalone OR arm. upload_files never appears
		// anywhere in map_meta_cap's resolution of edit_post/delete_post - it only governs whether
		// wp_insert_attachment()/media_handle_upload() will accept a NEW upload, a question the
		// create-side aafm/upload-media ability answers on its own object-independent floor. A role
		// holding upload_files without also holding edit_posts/edit_others_posts/edit_published_posts
		// (default roles never produce this split, but a custom role can) would have been SHOWN
		// update-media/delete-media while genuinely unable to execute either on any attachment - the
		// same "standalone arm that can never resolve" defect Finding 1 removed from update-page,
		// just in a different family. Removed rather than widened.
		case 'aafm/update-media':
			return static fn(): bool => current_user_can( 'edit_posts' )
				|| current_user_can( 'edit_others_posts' )
				|| current_user_can( 'edit_published_posts' );
		case 'aafm/delete-media':
			return static fn(): bool => current_user_can( 'delete_posts' )
				|| current_user_can( 'delete_others_posts' )
				|| current_user_can( 'delete_published_posts' );

		// add-post-terms gates per-object on edit_post on the target post
		// (aafm_perm_add_post_terms -> aafm_can_edit_post_object); the post id is unknown at
		// discovery (empty input), so use the same widened floor as update-post above.
		case 'aafm/add-post-terms':
			return static fn(): bool => current_user_can( 'edit_posts' )
				|| current_user_can( 'edit_others_posts' )
				|| current_user_can( 'edit_published_posts' );

		// Term writes gate on the TARGET taxonomy's own manage_terms cap (aafm_perm_manage_terms),
		// and the taxonomy is unknown at discovery: with empty input the callback defaults to
		// 'category' and checks manage_categories, which hides both tools from a principal whose
		// only grant is a custom taxonomy's decoupled cap - the one user who can actually call
		// them. Discovery therefore mirrors what the callback accepts across its whole input
		// space: manage_terms on ANY public taxonomy (the same allow-list
		// aafm_validate_taxonomy() enforces). The per-taxonomy callback still runs unchanged as
		// the execute-time gate.
		case 'aafm/create-term':
		case 'aafm/update-term':
			return static function (): bool {
				foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax_object ) {
					if ( $tax_object instanceof WP_Taxonomy && current_user_can( $tax_object->cap->manage_terms ) ) {
						return true;
					}
				}
				return false;
			};

		// Term-meta read/write/delete gate per-object on the term (edit_term - the read
		// included, since term meta can hold private data) - the term id is unknown at
		// discovery, so this loops the exposed set the same way the acf-*-term-fields case above
		// does, for the identical reason: edit_term resolves through map_meta_cap to the TARGET
		// TAXONOMY's own edit_terms capability, not edit_posts. Previously this checked edit_posts,
		// which - exactly as documented on the acf-term-fields case above - is neither necessary
		// nor sufficient for edit_terms on a real taxonomy.
		//
		// PUBLIC TAXONOMIES ONLY, unlike acf-term-fields' now-broader loop (round 3): every
		// term-meta request routes through aafm_validate_term_meta_request() ->
		// aafm_validate_taxonomy(), which genuinely denies a non-public taxonomy at execute time
		// (aafm_perm_acf_term has no such restriction), so this loop staying public-only still
		// matches its own real, narrower floor exactly.
		case 'aafm/get-term-meta':
		case 'aafm/update-term-meta':
		case 'aafm/delete-term-meta':
			return static function (): bool {
				foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax_object ) {
					if ( $tax_object instanceof WP_Taxonomy && current_user_can( $tax_object->cap->edit_terms ) ) {
						return true;
					}
				}
				return false;
			};

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
	//
	// Key the map by the name the adapter ACTUALLY gives the tool, not just our sanitized form. The
	// adapter derives a tool's name as McpNameSanitizer::sanitize_name() (which aafm_mcp_tool_name
	// mirrors) and THEN runs it through the mcp_adapter_tool_name filter
	// (RegisterAbilityAsMcpTool::resolve_tool_name). A site that hooks that filter to rename, say,
	// aafm/update-user renames the tool DTO too, so a map built from our sanitized name alone would
	// miss it and leave an enabled admin-only tool ungated in tools/list for an incapable connection.
	// Apply the same filter here so the key matches the DTO's getName().
	$enabled_by_tool_name = array();
	foreach ( aafm_all_server_ability_names() as $ability_name ) {
		$tool_name = aafm_mcp_tool_name( $ability_name );
		$ability   = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;
		if ( $ability instanceof WP_Ability ) {
			/** This filter is defined by the MCP adapter (RegisterAbilityAsMcpTool::resolve_tool_name). */
			$filtered = apply_filters( 'mcp_adapter_tool_name', $tool_name, $ability ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the adapter owns this hook; we apply it to match its tool-name derivation.
			if ( is_string( $filtered ) && '' !== $filtered ) {
				$tool_name = $filtered;
			}
		}
		$enabled_by_tool_name[ $tool_name ] = $ability_name;
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
		// Bounded per source IP (B38), reusing the failed-app-password cap: a caller holding a
		// VALID credential from a blocked address hits this branch on every request, so an
		// uncapped row per denial lets one address flood the 30-day activity table. Only the
		// row is capped - the denial below is returned every time regardless.
		if ( aafm_denial_log_within_cap( 'ipb' ) ) {
			$user = wp_get_current_user();
			aafm_log_activity(
				array(
					'ability'           => '(transport)',
					'status'            => 'denied',
					'principal_user_id' => (int) $user->ID,
					'principal_login'   => (string) $user->user_login,
				)
			);
		}
		return new WP_Error( 'aafm_ip_blocked', __( 'Your network address is not allowed to use this endpoint.', 'agent-abilities-for-mcp' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * Reject a malformed JSON body on the MCP route before the adapter can crash on it: a top-level
 * scalar, or a batch containing non-object elements.
 *
 * The bundled adapter builds an HttpRequestContext whose $body property is typed ?array from
 * WP_REST_Request::get_json_params(). A top-level scalar JSON body (`"x"`, `true`, `1.5`) makes
 * get_json_params() return a scalar, and assigning that to the ?array property throws an uncaught
 * TypeError. The context is built inside the transport's REST permission_callback, BEFORE the auth
 * check, and WP 6.9 has no Throwable guard around a permission_callback, so an UNAUTHENTICATED scalar
 * body is an HTTP 500 plus a PHP fatal on every request. A null body (falsy JSON, or no body) does
 * not trigger it and stays a clean 401. rest_pre_dispatch runs before route matching and the
 * permission callback, so returning a WP_Error here closes the crash before the transport sees the
 * request. Scoped to our MCP route only; every other REST route is left untouched.
 *
 * @param mixed            $result  Short-circuit result (WP_Error/response) or null to continue.
 * @param mixed            $server  The REST server (unused).
 * @param \WP_REST_Request $request The request being dispatched.
 * @return mixed A 400 WP_Error for a scalar body, a JSON-RPC response for a batch with non-object
 *               elements, otherwise $result unchanged.
 */
function aafm_reject_scalar_mcp_body( $result, $server, $request ) {
	unset( $server );

	if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
		return $result;
	}
	if ( 'POST' !== $request->get_method() ) {
		return $result;
	}
	// Case-insensitive, like core's own route matching (the regex in class-wp-rest-server.php is
	// compiled with the `i` modifier) and the sibling checks in aafm_mcp_filter_governed_error_status()
	// and aafm_oauth_request_targets_mcp_route(). Unlike those, a miss here fails OPEN: an odd-cased
	// route that core still dispatches to the MCP endpoint would skip this guard and reach the
	// transport's ?array-typed context, which is the exact crash the guard exists to close.
	if ( 0 !== strcasecmp( rtrim( (string) $request->get_route(), '/' ), rtrim( aafm_mcp_rest_route(), '/' ) ) ) {
		return $result;
	}

	$body = $request->get_json_params();
	if ( null !== $body && ! is_array( $body ) ) {
		return new WP_Error(
			'aafm_invalid_request_body',
			__( 'The MCP request body must be a JSON object.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}

	// B39: the second crash of the same class, one level down. The vendor treats any array with a
	// 0 key as a batch (JsonRpcResponseBuilder::is_batch_request) and feeds each element into
	// process_single_message(array $message), so a non-array element ([1,2,3]) is a TypeError that
	// the transport's blanket Throwable catch converts into a blanket 500 internal_error. JSON-RPC
	// 2.0 ("rpc call with invalid Batch", jsonrpc.org/specification) answers each invalid element
	// with its own {"jsonrpc":"2.0","error":{"code":-32600,"message":"Invalid Request"},"id":null}
	// object instead. The messages are protocol strings, not UI copy, so they are deliberately not
	// translated - the vendor's own error factory does not translate them either.
	if ( is_array( $body ) && isset( $body[0] ) ) {
		$invalid = 0;
		foreach ( $body as $element ) {
			if ( ! is_array( $element ) ) {
				++$invalid;
			}
		}
		if ( 0 === $invalid ) {
			return $result; // Every element is at least array-shaped; the vendor can take it.
		}

		$error = array(
			'jsonrpc' => '2.0',
			'error'   => array(
				'code'    => -32600,
				'message' => 'Invalid Request',
			),
			'id'      => null,
		);

		// All-invalid batch: the spec's own [1,2,3] example - one error object per element,
		// returned as a batch response (HTTP 200, the status the vendor gives every batch).
		if ( count( $body ) === $invalid ) {
			return new WP_REST_Response( array_fill( 0, $invalid, $error ), 200 );
		}

		// Mixed batch: the spec wants the valid elements processed alongside per-element errors,
		// but rest_pre_dispatch cannot half-dispatch a request, so refuse the whole batch with one
		// clean invalid-request error (400, the vendor's status for -32600) rather than let the
		// vendor crash on the invalid element.
		$error['error']['message'] = 'Invalid Request: every batch element must be a JSON object';
		return new WP_REST_Response( $error, 400 );
	}

	return $result;
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
 * The maximum number of tools (native enabled + bridged) the server will register.
 *
 * The request-time tools/list path runs one real permission check per advertised tool - and for a
 * bridged tool that is a FOREIGN plugin's permission_callback - inside a single loop over the whole
 * catalog (aafm_filter_mcp_tools_list()). That loop has no cost cap of its own, so a pathological
 * enabled+bridged set (thousands of abilities) could drive it into PHP's memory_limit or
 * max_execution_time, an uncatchable fatal that returns a blank/500 the client reads as "not a
 * valid MCP server" with no activity-log row (the fatal dies before rest_post_dispatch). This cap
 * bounds that loop by bounding the catalog: the preflight registers up to this many tools and omits
 * (and logs) the overflow.
 *
 * Deliberately generous. The heaviest real install measured (a full client clone with WooCommerce,
 * ACF, LearnDash, BuddyBoss, and three SEO plugins) exposes 205 abilities in total, and the native
 * catalog ceiling is ~153, so 1000 is several times any real surface and no normal install is ever
 * trimmed - while still bounding the request-time loop to a fixed maximum.
 */
const AAFM_MAX_SERVER_TOOLS = 1000;

/**
 * The option that carries the abilities the last registration pass left OUT of the server, so an
 * admin-side notice can surface them long after the (anonymous, non-admin) MCP request that
 * detected them. Maps ability name => reason code. Absent when nothing was omitted.
 */
const AAFM_OMITTED_ABILITIES_OPTION = 'aafm_omitted_abilities';

/**
 * Depth- and count-bounded measurement walk over one schema value.
 *
 * The whole point of the preflight is to stop an unbounded/pathological schema from exhausting
 * memory or time when the adapter serializes it for tools/list - so the MEASUREMENT itself must
 * never be the thing that fatals on such a schema. This walk is therefore bounded twice over: it
 * descends at most AAFM_SCHEMA_MAX_DEPTH levels (returning a violation the moment it would go
 * deeper) and visits at most AAFM_SCHEMA_MAX_NODES nodes across the whole measurement (the shared
 * &$nodes counter is threaded across the input and output walks by the caller), returning a
 * violation the moment the count is exceeded. Either bound terminates the walk early, so even a
 * reference-cyclic array (which a plain recursive walk would loop on forever) is capped by the
 * node counter and cannot hang or overflow the stack.
 *
 * A "node" is every value visited: each array container, each object container, and each scalar
 * leaf. Objects are walked exactly like arrays here, because a well-formed JSON Schema legitimately
 * contains empty objects - a `"default": {}` value, for instance, decodes to a stdClass, since PHP's
 * `array()` encodes to `[]` and only an object encodes to `{}`. An empty stdClass therefore
 * contributes one node and no children (it passes), and a pathologically deep or large object graph
 * is bounded by exactly the same depth and node caps as an array. The danger this walk was once
 * thought to need a blanket object rejection for - a JsonSerializable whose jsonSerialize() throws,
 * recurses, or allocates unbounded during wp_json_encode() at the byte-size step - is already handled
 * downstream in aafm_schema_bounds_violation() by the try/catch (\Throwable) around wp_json_encode(),
 * the is_string() check on its result, and the AAFM_SCHEMA_MAX_BYTES cap. Only a resource is rejected
 * outright here, because json_encode() cannot represent one and it can never legitimately appear in a
 * schema.
 *
 * @param mixed $value The schema value to measure (array, scalar, object, or resource).
 * @param int   $depth Current recursion depth (callers pass 0).
 * @param int   $nodes Running node count, shared across input+output walks (by reference).
 * @return string|null A violation code ('schema_too_deep' | 'schema_too_many_nodes' |
 *                     'schema_unsafe_value') the moment a bound or a type rule is breached, or null
 *                     when this value and its whole subtree are within bounds.
 */
function aafm_schema_bounds_walk( $value, int $depth, int &$nodes ): ?string {
	if ( $depth > AAFM_SCHEMA_MAX_DEPTH ) {
		return 'schema_too_deep';
	}
	++$nodes;
	if ( $nodes > AAFM_SCHEMA_MAX_NODES ) {
		return 'schema_too_many_nodes';
	}
	// A resource can never appear in a well-formed JSON Schema and json_encode() cannot represent one,
	// so reject it outright. Objects, by contrast, are legitimate: an empty stdClass is how a `{}`
	// default round-trips through PHP. Walk into objects the same way as arrays so depth and node
	// bounds still apply; a throwing/huge JsonSerializable is caught downstream by the wp_json_encode()
	// try/catch and byte cap in aafm_schema_bounds_violation(), not here.
	if ( is_resource( $value ) ) {
		return 'schema_unsafe_value';
	}
	if ( is_array( $value ) || is_object( $value ) ) {
		foreach ( (array) $value as $child ) {
			$violation = aafm_schema_bounds_walk( $child, $depth + 1, $nodes );
			if ( null !== $violation ) {
				return $violation;
			}
		}
	}
	return null;
}

/**
 * Whether an enabled ability's combined input+output schema breaches the registration-time bounds.
 *
 * Runs the bounded walk (aafm_schema_bounds_walk()) over the input schema and then the output
 * schema, sharing a single node budget across both, and only when depth and node count are within
 * bounds does it serialize the pair to measure byte size - so wp_json_encode() never runs on an
 * oversized or pathologically deep structure and cannot itself fatal. A schema that json_encode
 * still refuses (e.g. its own 512-level encoder depth limit, or a value it cannot encode) fails
 * closed as a violation rather than being registered unmeasured.
 *
 * Returns null (no violation) for anything that is not a resolvable WP_Ability: those names are
 * dropped downstream by aafm_build_server_tools() anyway, and it is not this guard's job to
 * second-guess that.
 *
 * @param string $ability_name Ability name, e.g. "aafm/get-posts" or "aafm-bridge/some-slug".
 * @return string|null A violation code ('schema_too_deep' | 'schema_too_many_nodes' |
 *                     'schema_unsafe_value' | 'schema_too_large' | 'schema_unserializable') or null
 *                     when within bounds.
 */
function aafm_schema_bounds_violation( string $ability_name ): ?string {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;
	if ( ! $ability instanceof WP_Ability ) {
		return null;
	}

	// WP_Ability::get_input_schema()/get_output_schema() are declared to return array, so no
	// method_exists/is_array guard is needed here (and PHPStan proves it dead against the stubs).
	$input  = $ability->get_input_schema();
	$output = $ability->get_output_schema();

	$nodes     = 0;
	$violation = aafm_schema_bounds_walk( $input, 0, $nodes );
	if ( null === $violation ) {
		$violation = aafm_schema_bounds_walk( $output, 0, $nodes );
	}
	if ( null !== $violation ) {
		return $violation;
	}

	// Depth and node count are within bounds and the walk has already rejected any object/resource,
	// so the structure is small enough and plain enough to serialize safely. The try/catch is a
	// second line of defence: json_encode() can still throw on a value the walk did not model (a
	// leaked JsonSerializable buried in a way the array walk missed), and a throw here must fail the
	// ability closed as a violation, never escape as the uncatchable fatal the preflight exists to
	// prevent.
	try {
		$serialized = wp_json_encode(
			array(
				'input'  => $input,
				'output' => $output,
			)
		);
	} catch ( \Throwable $e ) {
		return 'schema_unserializable';
	}
	if ( ! is_string( $serialized ) ) {
		return 'schema_unserializable';
	}
	if ( strlen( $serialized ) > AAFM_SCHEMA_MAX_BYTES ) {
		return 'schema_too_large';
	}

	return null;
}

/**
 * Bound the server tool set before it reaches create_server(): drop any ability whose schema
 * breaches the measurement limits, then cap the total to AAFM_MAX_SERVER_TOOLS.
 *
 * Both cost centers this guards are on the tools/list path only and are otherwise unbounded: the
 * per-tool permission loop (bounded here by the tool-count cap) and the adapter's recursive schema
 * serialization (bounded here by the per-schema limits). A breach of either can fatal uncatchably
 * on a constrained host, so the fix is to keep the offending abilities out of the server entirely
 * rather than try to catch a fatal that no try/catch reaches.
 *
 * Schema-violating abilities are skipped BEFORE they consume tool-cap budget, so the cap counts
 * only abilities that will actually register - "register up to the cap" is read literally. Every
 * omission (schema breach OR cap overflow) is recorded so it is never a silent drop.
 *
 * @param array<int,string> $tools Enabled native + bridged ability names (order preserved).
 * @return list<string> The bounded, still-ordered subset to register.
 */
function aafm_preflight_bound_server_tools( array $tools ): array {
	$omitted = array();
	$kept    = aafm_preflight_partition_server_tools( $tools, $omitted );
	aafm_reconcile_omitted_abilities( $omitted );
	return $kept;
}

/**
 * The pure partition: split an ordered tool list into the kept subset and an omitted map, applying
 * the schema-bounds violations and the tool-count cap. No side effects - it neither persists nor logs
 * anything, so it is safe to call from both the direct (uncached) path and the cached wrapper.
 *
 * @param array<int,string>    $tools   Enabled native + bridged ability names (order preserved).
 * @param array<string,string> $omitted Receives the ability name => reason map (by reference, reset).
 * @return list<string> The bounded, still-ordered subset to register.
 */
function aafm_preflight_partition_server_tools( array $tools, array &$omitted ): array {
	$kept    = array();
	$omitted = array();

	foreach ( $tools as $name ) {
		if ( count( $kept ) >= AAFM_MAX_SERVER_TOOLS ) {
			$omitted[ $name ] = 'tool_cap';
			continue;
		}
		$violation = aafm_schema_bounds_violation( $name );
		if ( null !== $violation ) {
			$omitted[ $name ] = $violation;
			continue;
		}
		$kept[] = $name;
	}

	return $kept;
}

/**
 * The transient key under which a preflight decision is memoised for a given enabled set.
 *
 * Keyed by AAFM_VERSION plus the ordered enabled native+bridged ability-name set, so any change to
 * the set (enable/disable, a bridged-option save that adds or drops a wrapper) - or a plugin version
 * bump - produces a different key and forces a recompute, while a steady state reuses the same key.
 *
 * @param array<int,string> $tools Enabled native + bridged ability names, in order.
 * @return string The transient key.
 */
function aafm_preflight_cache_key( array $tools ): string {
	return 'aafm_preflight_' . md5( AAFM_VERSION . '|' . implode( "\n", $tools ) );
}

/**
 * Cached front end to aafm_preflight_bound_server_tools() for the live registration path.
 *
 * The preflight walks every enabled+bridged ability's input AND output schema and json-encodes the
 * pair to measure it. aafm_register_mcp_server() runs on mcp_adapter_init, which fires on
 * rest_api_init for EVERY REST request the site serves - so on a large install that per-request walk
 * is real, repeated, wasted work whenever the enabled set has not changed. This memoises the decision
 * (the kept list and the omitted map) keyed by aafm_preflight_cache_key(), so the expensive walk runs
 * only on a cache miss - i.e. only when the enabled set (or the plugin version) actually changes.
 *
 * Correctness is preserved two ways: the key changes the instant the enabled set changes (so a
 * different set never reads a stale decision), and the reconcile step still runs on every call
 * - hit or miss - so the persisted omission option, its admin notice, and the one-time activity
 * log stay byte-for-byte identical to the uncached path. Only the measurement walk is skipped on a
 * hit. A modest TTL bounds the one residual staleness window (a foreign plugin altering an ability's
 * schema WITHOUT changing its name), after which the next request recomputes.
 *
 * @param array<int,string> $tools Enabled native + bridged ability names, in order.
 * @return list<string> The bounded, still-ordered subset to register.
 */
function aafm_preflight_bound_server_tools_cached( array $tools ): array {
	$key    = aafm_preflight_cache_key( $tools );
	$cached = get_transient( $key );
	if ( is_array( $cached ) && isset( $cached['kept'], $cached['omitted'] )
		&& is_array( $cached['kept'] ) && is_array( $cached['omitted'] )
	) {
		// Cache hit: skip the walk, but still reconcile so the option/notice/log reflect the decision
		// (cheap: one autoloaded read + compare, which early-returns when unchanged).
		aafm_reconcile_omitted_abilities( $cached['omitted'] );
		return array_values( array_map( 'strval', $cached['kept'] ) );
	}

	// Cache miss: run the real walk once, reconcile as usual, then memoise the decision for this set.
	$omitted = array();
	$kept    = aafm_preflight_partition_server_tools( $tools, $omitted );
	aafm_reconcile_omitted_abilities( $omitted );

	set_transient(
		$key,
		array(
			'kept'    => $kept,
			'omitted' => $omitted,
		),
		HOUR_IN_SECONDS
	);

	return $kept;
}

/**
 * Persist the current omission set and log each newly-omitted ability, once.
 *
 * Runs on every registration pass (rest_api_init on the web path), so it must not write on a
 * normal install where nothing is omitted: it early-returns whenever the omission set is unchanged
 * from what is stored, so the steady state is one autoloaded read and zero writes. It writes only
 * when the set actually changes - a new breach appears, a breach clears, or a reason changes - and
 * logs an activity row only for abilities that are newly omitted or changed reason, so a stable
 * pathological config does not re-log on every MCP request. When the set goes empty again (the
 * operator fixed the offending ability), the option is deleted so the admin notice clears.
 *
 * @param array<string,string> $omitted Ability name => reason code for this pass (possibly empty).
 * @return void
 */
function aafm_reconcile_omitted_abilities( array $omitted ): void {
	ksort( $omitted );

	$stored = get_option( AAFM_OMITTED_ABILITIES_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	if ( $omitted === $stored ) {
		return; // No change: no write, no duplicate log rows.
	}

	if ( array() === $omitted ) {
		aafm_delete_option_cache_safe( AAFM_OMITTED_ABILITIES_OPTION );
		return;
	}

	update_option( AAFM_OMITTED_ABILITIES_OPTION, $omitted, true );

	foreach ( $omitted as $name => $reason ) {
		if ( isset( $stored[ $name ] ) && $stored[ $name ] === $reason ) {
			continue; // Already recorded with this reason; do not re-log.
		}
		aafm_log_activity(
			array(
				'ability'    => (string) $name,
				'status'     => 'denied',
				'event_type' => 'ability_omitted',
				'detail'     => (string) $reason,
			)
		);
	}
}

/**
 * Admin notice naming the abilities the preflight left out of the server, so the operator can fix
 * the offending ability rather than silently lose the tool.
 *
 * Reads the persisted omission set (written by aafm_reconcile_omitted_abilities() during an
 * anonymous MCP request, which is why this cannot just render from request state). Capability-gated
 * to the same manage_options the plugin's own settings screens use, and every dynamic value is
 * escaped. A normal install has no option and renders nothing.
 *
 * @return void
 */
function aafm_notice_omitted_abilities(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$omitted = get_option( AAFM_OMITTED_ABILITIES_OPTION, array() );
	if ( ! is_array( $omitted ) || array() === $omitted ) {
		return;
	}

	$items = array();
	foreach ( $omitted as $name => $reason ) {
		$items[] = sprintf(
			/* translators: 1: ability name, 2: human-readable reason it was left out. */
			esc_html__( '%1$s (%2$s)', 'agent-abilities-for-mcp' ),
			esc_html( (string) $name ),
			esc_html( aafm_omitted_reason_label( (string) $reason ) )
		);
	}

	echo '<div class="notice notice-warning"><p>';
	echo esc_html(
		_n(
			'Agent Abilities for MCP left one ability out of the agent tool list because it exceeded a safety limit:',
			'Agent Abilities for MCP left some abilities out of the agent tool list because they exceeded a safety limit:',
			count( $items ),
			'agent-abilities-for-mcp'
		)
	);
	echo ' ';
	echo wp_kses_post( implode( ', ', $items ) );
	echo '</p></div>';
}

/**
 * Human-readable label for an omission reason code, for the admin notice.
 *
 * @param string $reason One of the reason codes aafm_schema_bounds_violation()/the tool cap emit.
 * @return string A translated, operator-facing explanation.
 */
function aafm_omitted_reason_label( string $reason ): string {
	switch ( $reason ) {
		case 'schema_too_deep':
			return __( 'its schema is nested too deeply', 'agent-abilities-for-mcp' );
		case 'schema_too_many_nodes':
			return __( 'its schema has too many fields', 'agent-abilities-for-mcp' );
		case 'schema_unsafe_value':
			return __( 'its schema contains a value that is not valid JSON', 'agent-abilities-for-mcp' );
		case 'schema_too_large':
			return __( 'its schema is too large', 'agent-abilities-for-mcp' );
		case 'schema_unserializable':
			return __( 'its schema could not be serialized', 'agent-abilities-for-mcp' );
		case 'tool_cap':
			return __( 'the enabled tool limit was reached', 'agent-abilities-for-mcp' );
		default:
			return __( 'it exceeded a safety limit', 'agent-abilities-for-mcp' );
	}
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

	// Preflight bound the catalog before it becomes the server: drop any ability whose schema
	// breaches the measurement limits and cap the total tool count, so neither the adapter's
	// recursive schema serialization nor the request-time per-tool permission loop can be driven
	// into an uncatchable memory/time fatal by a pathological enabled+bridged set. Omissions are
	// logged and surfaced (aafm_reconcile_omitted_abilities), never silently dropped.
	$tools = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( aafm_all_server_ability_names() ) );

	// Per-connection capability gate at request time (the user is anonymous here; see
	// aafm_build_server_tools()). Priority 5 so it runs before any consumer reordering.
	add_filter( 'mcp_adapter_tools_list', 'aafm_filter_mcp_tools_list', 5, 2 );

	// Advertise only the capabilities we actually implement (tools); strip prompts/resources.
	add_filter( 'mcp_adapter_initialize_response', 'aafm_filter_initialize_capabilities', 10, 2 );

	// Wrap a bridged ability's bare top-level list result under a `data` key, and refuse a
	// hidden unsafe object anywhere in it (see aafm_filter_bridged_tool_call_result() in
	// bridge.php for the full rationale). Accepts 4 args (not 3) so the callback receives the
	// McpTool instance and can classify by backing ability identity rather than by wire tool
	// name alone - final gate round 3: a site can rename a tool via mcp_adapter_tool_name, so
	// the wire name is not a reliable bridged/native discriminator on its own.
	add_filter( 'mcp_adapter_tool_call_result', 'aafm_filter_bridged_tool_call_result', 10, 4 );

	// A consumer WP_Error on this filter aborts a call AFTER the adapter's permission fire consumed
	// a rate token but BEFORE execute(); release the aborted call's memo so the next same-ability
	// call consumes fresh instead of reusing the dead call's allow (B12). Last priority so it sees a
	// short-circuit from any consumer registered before it; a same-priority consumer registered later
	// runs after this hook and is not visible here.
	add_filter( 'mcp_adapter_pre_tool_call', 'aafm_release_rate_memo_on_aborted_tool_call', PHP_INT_MAX, 3 );

	// Reject a top-level scalar JSON body before the transport builds its ?array-typed context
	// (which would otherwise fatal, unauthenticated, before the auth check). Runs before route
	// matching and the permission callback, so it fires early enough to close the crash.
	add_filter( 'rest_pre_dispatch', 'aafm_reject_scalar_mcp_body', 10, 3 );

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

/**
 * Fail an MCP session-establishing response honestly when its session was never persisted.
 *
 * The bundled adapter's SessionManager::create_session()
 * (vendor/wordpress/mcp-adapter/includes/Transport/Infrastructure/SessionManager.php) writes
 * the new session into user meta with update_user_meta() and then returns the session id
 * WITHOUT checking that the write actually succeeded. If that write silently fails - a DB
 * write error, a read-only or write-again object cache, a storage inconsistency - the
 * transport still stamps a valid-looking Mcp-Session-Id on the initialize response. The
 * client "connects", then its very next request echoes that id, the adapter's session lookup
 * misses (the store never held it), and the request 404s with session_not_found. To the user
 * that is "OAuth works, then it won't connect" - one of the in-WordPress causes of that field
 * report, distinct from a CDN/WAF blocking the follow-up at the edge.
 *
 * There is no vendor hook at the create_session write to observe, and editing vendor/ is not
 * an option, so this verifies persistence AFTER dispatch. When an MCP-route response carries
 * an Mcp-Session-Id header and a user is resolved, it reads that user's stored sessions and
 * checks the id is genuinely present:
 *   - present -> the write landed; the response is returned byte-identical (the normal path).
 *   - absent  -> the write silently failed and the session is already dead. Strip the
 *                Mcp-Session-Id header so the client does not cling to a phantom id, and
 *                rewrite the body to a JSON-RPC internal_error (-32603, HTTP 500 - the same
 *                mapping the adapter's McpErrorFactory uses for that code) so the client gets
 *                an honest, retryable error on THIS request instead of a fake success followed
 *                by a mystery disconnect.
 *
 * Timing is load-bearing. The transport adds the Mcp-Session-Id header from its OWN
 * rest_post_dispatch closure at the default priority (10), registered during dispatch (see
 * HttpRequestHandler::add_session_header_to_response()). This guard must therefore run AFTER
 * that closure has stamped the header, so it is registered at priority 11 - and BEFORE
 * aafm_log_mcp_transport_outcome() (also priority 11) so that when it rewrites the body to
 * -32603, the transport logger that follows records a (transport) internal_error row for the
 * failure. A priority of 9 or 10 would run before the header exists and never detect anything.
 *
 * Scope is narrow: the MCP route only (read from the request, case-insensitive like the
 * sibling filters and core's own route matching), a WP_REST_Response actually carrying the
 * session header, and a resolved principal (get_current_user_id() > 0). Any miss returns the
 * response untouched - a persisted session, a header-less response (every non-initialize call,
 * and errors), an anonymous request, and every other REST route the site serves all pass
 * through unchanged. Only initialize ever stamps this header, so the guard is precisely
 * scoped to the one moment create_session() runs.
 *
 * @param mixed $response The dispatch result (WP_REST_Response on the REST path).
 * @param mixed $server   The REST server (unused).
 * @param mixed $request  The originating request (WP_REST_Request on the REST path).
 * @return mixed The response - untouched on the normal path, or rewritten to a -32603 error
 *               with the session header stripped and status 500 when the session was not
 *               persisted.
 */
function aafm_mcp_guard_unpersisted_session( $response, $server, $request ) {
	unset( $server );

	if ( ! function_exists( 'aafm_mcp_rest_route' ) ) {
		return $response;
	}

	// Route check first: rest_post_dispatch fires on every REST request the whole site serves,
	// and this alone rules out all but the one MCP route. Case-insensitive, matching the sibling
	// filters and core's own route regex (built with the `i` modifier).
	$route = $request instanceof WP_REST_Request ? $request->get_route() : '';
	if ( 0 !== strcasecmp( aafm_mcp_rest_route(), $route ) ) {
		return $response;
	}

	if ( ! $response instanceof WP_REST_Response ) {
		return $response;
	}

	// A batch (list) response bundles multiple per-message JSON-RPC results; rewriting the whole body
	// to a single -32603 error would destroy every sibling result - e.g. a batch that merely INCLUDED
	// an initialize would lose the results of every other call in it. The unpersisted-session rewrite
	// is only coherent for a single initialize response, so a batch is left untouched here, mirroring
	// the batch guard aafm_log_mcp_transport_outcome() already carries. array_is_list() needs PHP 8.1
	// and the floor is 7.4, so the list check is built by hand.
	$batch_data = $response->get_data();
	if ( is_array( $batch_data ) && ( array() === $batch_data || array_keys( $batch_data ) === range( 0, count( $batch_data ) - 1 ) ) ) {
		return $response;
	}

	// The new session id lives ONLY on the response header - the transport unsets it from the
	// body before responding. Read it case-insensitively and keep the exact key found, so a
	// later strip removes the right one whatever its case.
	$session_id = '';
	$header_key = '';
	foreach ( $response->get_headers() as $key => $value ) {
		if ( 0 === strcasecmp( 'Mcp-Session-Id', (string) $key ) ) {
			$session_id = is_array( $value ) ? (string) reset( $value ) : (string) $value;
			$header_key = (string) $key;
			break;
		}
	}
	if ( '' === $session_id ) {
		return $response; // No session header: not a session-establishing response - nothing to verify.
	}

	// Only a resolved principal owns a session store to check against. An anonymous response
	// cannot be verified here, so it passes through rather than being failed on a guess.
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return $response;
	}

	// Read the adapter's own session store. The meta key mirrors
	// WP\MCP\Transport\Infrastructure\SessionManager::SESSION_META_KEY, which is a private const
	// and cannot be read from here; the literal is verified against the bundled adapter copy.
	// MAINTENANCE: re-verify this key against SessionManager.php whenever the bundled adapter is
	// updated (same maintenance surface as aafm_adapter_namespace_map()).
	$sessions = get_user_meta( $user_id, 'mcp_adapter_sessions', true );
	if ( is_array( $sessions ) && isset( $sessions[ $session_id ] ) ) {
		return $response; // Persisted: the normal path. Byte-identical pass-through.
	}

	// The write silently failed: the id the client just received is not in the store, so its
	// next request would 404 session_not_found. Fail honestly on THIS request instead. Strip the
	// header by rewriting the header set (version-agnostic, avoids depending on remove_header()).
	if ( '' !== $header_key ) {
		$headers = $response->get_headers();
		unset( $headers[ $header_key ] );
		$response->set_headers( $headers );
	}

	// Preserve the JSON-RPC request id from the (successful) body so the error still correlates
	// to the initialize call; fall back to null when it is not a well-formed body.
	$data       = $response->get_data();
	$request_id = ( is_array( $data ) && array_key_exists( 'id', $data ) ) ? $data['id'] : null;

	$response->set_data(
		array(
			'jsonrpc' => '2.0',
			'id'      => $request_id,
			'error'   => array(
				'code'    => -32603,
				'message' => __( 'Session could not be persisted; please retry.', 'agent-abilities-for-mcp' ),
			),
		)
	);
	// -32603 (internal_error) maps to HTTP 500 in the adapter's McpErrorFactory; match it.
	$response->set_status( 500 );

	return $response;
}

/**
 * Map a known, reachable JSON-RPC error code to a short, human-readable name.
 *
 * The names are stable identifiers for the activity log's detail column, not UI copy, so they are
 * deliberately not translated (the same posture the adapter's own McpErrorFactory takes with its
 * protocol strings). This is the transport-logging allowlist: the five JSON-RPC standard codes plus
 * only the adapter codes that are actually reachable as a top-level transport error in this
 * tools-only server. A code not in the table returns null, and the caller treats that null as "do
 * not log" - so an arbitrary or unreachable code is dropped rather than recorded as junk.
 *
 * Three adapter codes are deliberately left out:
 *   -32002 resource_not_found and -32004 prompt_not_found - no resources or prompts are registered
 *   (create_server( ... $tools, array(), array() )), so neither can ever be emitted here.
 *   -32008 permission_denied - a denied tools/call returns HTTP 200 with isError=true, never a
 *   top-level -32008, and is already logged per-call in register.php. Logging it here would either
 *   double-count or record an unreachable code.
 *
 * The highest-value signal in what remains is -32005 session_not_found (the field-report failure:
 * initialize succeeds, the follow-up tools/list 404s on session lookup). -32003 tool_not_found is
 * kept deliberately - a tool-catalog mismatch is genuine connection/client diagnosis.
 *
 * @param int $code The JSON-RPC error code.
 * @return string|null The mapped name, or null when the code is not on the allowlist.
 */
function aafm_mcp_transport_error_name( int $code ): ?string {
	$names = array(
		-32700 => 'parse_error',       // JSON-RPC standard.
		-32600 => 'invalid_request',   // JSON-RPC standard.
		-32601 => 'method_not_found',  // JSON-RPC standard.
		-32602 => 'invalid_params',    // JSON-RPC standard.
		-32603 => 'internal_error',    // JSON-RPC standard.
		-32000 => 'server_error',      // Adapter: generic server error (includes MCP disabled).
		-32001 => 'timeout_error',     // Adapter.
		-32003 => 'tool_not_found',    // Adapter: tool-catalog mismatch, a real client-diagnosis signal.
		-32005 => 'session_not_found', // Adapter: the field-report failure - initialize succeeds, the
										// follow-up call 404s on session lookup.
		-32010 => 'unauthorized',      // Adapter: authentication required.
	);

	return $names[ $code ] ?? null;
}

/**
 * Log a single (transport) row for an MCP-route JSON-RPC error response, so a failed /mcp request is
 * self-diagnosing after the fact.
 *
 * The problem this closes: activity logging fires per ability invocation (on wp_ability_invoked, i.e.
 * tools/call), so the initialize handshake, tools/list, and session/protocol validation never wrote a
 * row. When a user reports "OAuth works, then it won't connect", an empty log could not tell apart (a)
 * the request never reached WordPress (a CDN/WAF blocked it at the edge) from (b) it reached WordPress
 * and failed session/protocol validation. After this ships, a still-empty log means "never reached
 * WordPress = the front door", and a (transport) row means "reached WP, here is the exact error".
 *
 * Pure observability. This handler only READS the already-built response and writes an audit row; it
 * returns $response untouched and changes no auth, session, or HTTP-status decision.
 * aafm_mcp_filter_governed_error_status() owns the one status rewrite (404 -> 200 for four in-band
 * codes), and that rewrite touches only the HTTP status, never error.code - so reading error.code here
 * is correct whichever order the two rest_post_dispatch handlers run in. Registered at a later priority
 * than the governed-status filter so the http:<status> it records is the FINAL status the client sees.
 *
 * The predicate is a single JSON-RPC error on the MCP route. That deliberately EXCLUDES the routine
 * anonymous 401/403 from the transport permission callback: those return a plain WP_Error, whose body
 * is core's {code,message,data:{status}} shape, not a JSON-RPC {error:{code}} object - so a bare
 * no-bearer 401 (normal noise) never matches, while a bearer-resolved-then-session/protocol failure
 * (the signal) does. Flooding is bounded exactly like the other transport-denial rows: one row per
 * source IP per window through aafm_denial_log_within_cap(), on its own 'tx' bucket, so a client stuck
 * in a reconnect loop cannot grow the 30-day table without limit.
 *
 * The determine_current_user-timing function_exists guards mirror aafm_log_failed_application_password_auth():
 * rest_post_dispatch runs well after plugins_loaded so the helpers exist in practice, but this stays
 * defensive rather than assume load order.
 *
 * @param mixed $response The dispatch result (WP_REST_Response on the REST path).
 * @param mixed $server   The REST server (unused).
 * @param mixed $request  The originating request (WP_REST_Request on the REST path).
 * @return mixed The response, always returned untouched.
 */
function aafm_log_mcp_transport_outcome( $response, $server, $request ) {
	unset( $server );

	if ( ! function_exists( 'aafm_mcp_rest_route' ) || ! function_exists( 'aafm_log_activity' ) || ! function_exists( 'aafm_denial_log_within_cap' ) ) {
		return $response;
	}

	// Route check first: rest_post_dispatch fires on every REST request the whole site serves, and
	// this alone rules out all but the one MCP route. Case-insensitive, like the sibling filter and
	// core's own route matching.
	$route = $request instanceof WP_REST_Request ? $request->get_route() : '';
	if ( 0 !== strcasecmp( aafm_mcp_rest_route(), $route ) ) {
		return $response;
	}

	if ( ! $response instanceof WP_REST_Response ) {
		return $response;
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return $response;
	}

	// A batch response is a sequential list of per-message results, so it has no top-level 'error'
	// key and the isset() below already excludes it; this states that intent explicitly. array_is_list()
	// needs PHP 8.1 and the floor is 7.4, so the list check is built by hand, mirroring the governed filter.
	if ( array() === $data || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
		return $response;
	}

	if ( ! isset( $data['error']['code'] ) || ! is_numeric( $data['error']['code'] ) ) {
		return $response;
	}

	$code = (int) $data['error']['code'];
	$name = aafm_mcp_transport_error_name( $code );

	// Allowlist gate: only log a code that is both known and actually reachable here (see
	// aafm_mcp_transport_error_name() for what is dropped and why). An unmapped code - arbitrary junk,
	// or an adapter code that can never surface as a top-level transport error in this tools-only
	// server - is ignored, so the log carries only diagnosable failures. This runs before the cap so a
	// dropped code never burns this IP's budget.
	if ( null === $name ) {
		return $response;
	}

	// Consume a cap slot only once the row is genuinely going to be written - after every cheaper
	// predicate has passed - so a non-error MCP response never burns this IP's budget.
	if ( ! aafm_denial_log_within_cap( 'tx' ) ) {
		return $response; // Bounded: this IP already used its cap for the current window.
	}

	// The one authentication code (-32010 unauthorized) reads best as 'denied'; every other
	// protocol/session/internal failure on the allowlist is an 'error'. Both are terminal statuses
	// aafm_log_activity() accepts.
	$status = ( -32010 === $code ) ? 'denied' : 'error';

	// Identifier-only detail: the mapped name (always set - the allowlist gate above guarantees it),
	// the raw code so nothing is lost, the final HTTP status, and the JSON-RPC method when it is
	// cheaply available and a clean protocol string. NEVER a token, param value, or any argument
	// content - only the shape of the failure. aafm_sanitize_activity_detail() is the independent
	// last line.
	$detail = $name . ' code:' . $code . ' http:' . (int) $response->get_status();
	$method = aafm_mcp_transport_request_method( $request );
	if ( '' !== $method ) {
		$detail .= ' method:' . $method;
	}

	// Attribute to the resolved principal and OAuth client when they are known (a session/protocol
	// error on a bearer-authenticated request has both). A pre-auth error simply carries user 0 and
	// no client id, which is itself part of the signal.
	$user = wp_get_current_user();
	aafm_log_activity(
		array(
			'ability'           => '(transport)',
			'status'            => $status,
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => (string) $user->user_login,
			'client_id'         => function_exists( 'aafm_oauth_current_client_id' ) ? aafm_oauth_current_client_id() : '',
			'detail'            => $detail,
		)
	);

	return $response;
}

/**
 * Pull the JSON-RPC `method` off an MCP request for the transport-outcome detail, when it is cheaply
 * available and safe to record.
 *
 * The method is a protocol string ('initialize', 'tools/list', 'tools/call', ...), not user content,
 * so it is genuinely useful in the audit detail. It is still attacker-influenced (the body is
 * client-supplied), so it is only accepted when it is a scalar string matching a conservative
 * protocol-name shape and short; anything else is dropped rather than logged. A batch body has no
 * single top-level method, so this returns '' for it (the outcome logger already excludes batch
 * responses anyway).
 *
 * @param mixed $request The originating request.
 * @return string The method name, or '' when absent/unsafe/unavailable.
 */
function aafm_mcp_transport_request_method( $request ): string {
	if ( ! $request instanceof WP_REST_Request ) {
		return '';
	}
	$body = $request->get_json_params();
	if ( ! is_array( $body ) || ! isset( $body['method'] ) || ! is_string( $body['method'] ) ) {
		return '';
	}
	$method = $body['method'];
	// Conservative allowlist: JSON-RPC method names in this protocol are lowercase words joined by
	// '/' (e.g. tools/list), optionally with '.' or '_' - never markup, spaces, or arbitrary text.
	if ( strlen( $method ) > 64 || 1 !== preg_match( '#^[a-zA-Z0-9._/-]+$#', $method ) ) {
		return '';
	}
	return $method;
}
