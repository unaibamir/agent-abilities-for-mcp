<?php
/**
 * Activity log detail: the identifier-only allowlist.
 *
 * The log's central privacy promise is that argument VALUES are never stored (see the
 * aafm_log_activity() docblock). The detail column is the one narrow exception, and this file is
 * the whole of it: an ability logs a detail only if it appears in aafm_activity_detail_map(), and
 * only the fields that map names, each of which must clear a type check that no free-form string
 * can pass. Default deny in both directions. There is deliberately no string or text field type,
 * and adding one is the change a reviewer should refuse. A failed call also records its WP_Error
 * code, which is an identifier by construction for an ability THIS PLUGIN ships - every
 * `new WP_Error(` under includes/ takes a string literal - but not for a bridged one, whose code a
 * foreign plugin is free to build out of its own input, so bridged results are excluded from that
 * branch. An ability contributed through the public aafm_abilities_registry filter is on the
 * trusted side of that line: a site that adds a row to the catalog supplies its own permission
 * callback and is trusted with far more than an error code already. Read the guarantee as "the
 * codes this plugin and its host write", not "every code the column can hold". Since
 * 1.6.1 there is one further writer,
 * aafm_build_activity_detail_from_exception() below, for the crash detail the choke point in
 * includes/register.php records. It is not map-driven, because a crash is not per-ability, but it
 * obeys the same rule: a fixed template filled from engine-supplied, constrained parts, and it
 * never reads the exception's message.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Render one allowlisted field, or reject it.
 *
 * A rejected field is dropped silently: detail is observability, never control flow, so a value
 * that fails its check must never turn into an error an agent sees.
 *
 * @param string            $type    One of id|key|slug|enum|count.
 * @param mixed             $value   The candidate value.
 * @param array<int,string> $allowed Allowed members, for the enum type only.
 * @return string|null The rendered value, or null when it fails its check.
 */
function aafm_activity_detail_field( string $type, $value, array $allowed = array() ): ?string {
	if ( is_array( $value ) || is_object( $value ) || null === $value || is_bool( $value ) ) {
		return null;
	}

	// is_numeric() only accepts a numeric string with trailing whitespace since PHP 8.0, so an
	// identical value can validate on 8.x and fail on the plugin's PHP 7.4 floor. Trim a string
	// candidate before the check so both cases agree; a non-string $value passes through untouched.
	// The charlist adds \f: trim()'s default charlist (" \t\n\r\0\x0B") strips a vertical tab but
	// not a form feed, while is_numeric()'s own whitespace set includes both, so a trailing \f
	// would still diverge between 7.4 and 8.x without it. Only $candidate feeds the id/count
	// branches below; key/slug/enum read the untrimmed $value, so this never loosens those.
	$candidate = is_string( $value ) ? trim( $value, " \t\n\r\0\x0B\f" ) : $value;

	switch ( $type ) {
		case 'id':
			$id = is_numeric( $candidate ) ? (int) $candidate : 0;
			return $id > 0 ? (string) $id : null;

		case 'count':
			$count = is_numeric( $candidate ) ? (int) $candidate : -1;
			return $count >= 0 ? (string) $count : null;

		case 'key':
			$key = (string) $value;
			return preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $key ) ? $key : null;

		case 'slug':
			$slug = (string) $value;
			return preg_match( '#^[a-z0-9\-]+/[a-z0-9\-]+$#', $slug ) ? $slug : null;

		case 'enum':
			$member = (string) $value;
			return in_array( $member, array_map( 'strval', $allowed ), true ) ? $member : null;
	}

	return null;
}

/**
 * The complete, auditable allowlist of identifier-safe fields that may reach the detail column.
 *
 * Default deny: an ability absent from this map logs no detail at all, and an ability present here
 * logs only the fields it names. Central by design rather than a per-ability registry key like
 * args_builder: the security property is that a reviewer can read every field that can ever reach
 * an audit row in one place, and spreading it across twenty ability files destroys that.
 *
 * @return array<string, array{template: string, args?: list<array{key: string, type: string, enum?: string|list<string>}>, result_id?: string, link?: string}>
 */
function aafm_activity_detail_map(): array {
	return array(
		'aafm/update-post-meta'            => array(
			/* translators: 1: meta key name. 2: the post's numeric ID. */
			'template' => __( 'Updated meta key `%1$s` on post #%2$s', 'agent-abilities-for-mcp' ),
			'args'     => array(
				array(
					'key'  => 'meta_key',
					'type' => 'key',
				),
				array(
					'key'  => 'post_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/create-page'                 => array(
			/* translators: %s: the new page's numeric ID. */
			'template'  => __( 'Created page #%s', 'agent-abilities-for-mcp' ),
			// Dotted path, NOT a bare 'id'. aafm_insert_post() returns
			// array( 'post' => aafm_redact_post( $created ) ), so the id lives at post.id, never at
			// the top level. Every create ability in this map wraps its payload the same way; a
			// bare key silently resolves to null and the row logs no detail.
			'result_id' => 'post.id',
			'link'      => 'post',
		),
		'aafm/wc-update-order-status'      => array(
			/* translators: 1: the order's numeric ID. 2: the new order status key. */
			'template' => __( 'Set order #%1$s to status `%2$s`', 'agent-abilities-for-mcp' ),
			'args'     => array(
				array(
					'key'  => 'order_id',
					'type' => 'id',
				),
				array(
					'key'  => 'status',
					'type' => 'enum',
					'enum' => 'aafm_activity_order_statuses',
				),
			),
			'link'     => 'order',
		),

		// The starter set below extends the three entries above to fifteen abilities. Every key
		// name is verified against the ability's own args builder (or, for a result entry, the
		// executor's actual return shape) before being written here - see LESSONS-LEARNED and
		// DetailTest for why a guessed key is a silent-wrong-answer bug, not a loud one.
		'aafm/create-post'                 => array(
			/* translators: %s: the new post's numeric ID. */
			'template'  => __( 'Created post #%s', 'agent-abilities-for-mcp' ),
			// aafm_exec_create_post() delegates to aafm_insert_post(), which returns
			// array( 'post' => aafm_redact_post( … ) ) - same wrapped shape as create-page.
			'result_id' => 'post.id',
			'link'      => 'post',
		),
		'aafm/create-draft'                => array(
			/* translators: %s: the new draft's numeric ID. */
			'template'  => __( 'Created draft #%s', 'agent-abilities-for-mcp' ),
			'result_id' => 'post.id',
			'link'      => 'post',
		),
		'aafm/create-user'                 => array(
			/* translators: %s: the new user's numeric ID. */
			'template'  => __( 'Created user #%s', 'agent-abilities-for-mcp' ),
			// aafm_exec_create_user() returns array( 'user' => aafm_rich_user( … ) ), never a
			// top-level id.
			'result_id' => 'user.id',
			'link'      => 'user',
		),
		'aafm/delete-post-meta'            => array(
			/* translators: 1: meta key name. 2: the post's numeric ID. */
			'template' => __( 'Deleted meta key `%1$s` on post #%2$s', 'agent-abilities-for-mcp' ),
			'args'     => array(
				array(
					'key'  => 'meta_key',
					'type' => 'key',
				),
				array(
					'key'  => 'post_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/update-post'                 => array(
			/* translators: %s: the post's numeric ID. */
			'template' => __( 'Updated post #%s', 'agent-abilities-for-mcp' ),
			'args'     => array(
				array(
					'key'  => 'post_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/update-page'                 => array(
			/* translators: %s: the page's numeric ID. */
			'template' => __( 'Updated page #%s', 'agent-abilities-for-mcp' ),
			// aafm_args_update_page() names its own id field page_id, not post_id - confirmed
			// against pages.php. update-post and update-page take different keys even though
			// both end up writing the same wp_posts row.
			'args'     => array(
				array(
					'key'  => 'page_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/delete-post'                 => array(
			/* translators: %s: the post's numeric ID. */
			'template' => __( 'Deleted post #%s', 'agent-abilities-for-mcp' ),
			'args'     => array(
				array(
					'key'  => 'post_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/delete-page'                 => array(
			/* translators: %s: the page's numeric ID. */
			'template' => __( 'Deleted page #%s', 'agent-abilities-for-mcp' ),
			// page_id, not post_id - see aafm/update-page above.
			'args'     => array(
				array(
					'key'  => 'page_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/trash-post'                  => array(
			/* translators: %s: the post's numeric ID. */
			'template' => __( 'Trashed post #%s', 'agent-abilities-for-mcp' ),
			'args'     => array(
				array(
					'key'  => 'post_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/trash-page'                  => array(
			/* translators: %s: the page's numeric ID. */
			'template' => __( 'Trashed page #%s', 'agent-abilities-for-mcp' ),
			// page_id, not post_id - see aafm/update-page above.
			'args'     => array(
				array(
					'key'  => 'page_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/restore-revision'            => array(
			/* translators: %s: the restored revision's numeric ID. */
			'template' => __( 'Restored revision #%s', 'agent-abilities-for-mcp' ),
			// revision_id, confirmed against aafm_args_restore_revision(). post_id is also a
			// required arg on this ability but is deliberately not declared here - the map's job
			// is to name the one identifier worth showing, not to mirror the whole schema.
			'args'     => array(
				array(
					'key'  => 'revision_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/update-user'                 => array(
			/* translators: %s: the user's numeric ID. */
			'template' => __( 'Updated user #%s', 'agent-abilities-for-mcp' ),
			'args'     => array(
				array(
					'key'  => 'user_id',
					'type' => 'id',
				),
			),
			'link'     => 'user',
		),
		'aafm/delete-user'                 => array(
			/* translators: %s: the user's numeric ID. */
			'template' => __( 'Deleted user #%s', 'agent-abilities-for-mcp' ),
			'args'     => array(
				array(
					'key'  => 'user_id',
					'type' => 'id',
				),
			),
			'link'     => 'user',
		),
		'aafm/wc-create-order-refund'      => array(
			/* translators: %s: the order's numeric ID. */
			'template' => __( 'Refunded order #%s', 'agent-abilities-for-mcp' ),
			'args'     => array(
				array(
					'key'  => 'order_id',
					'type' => 'id',
				),
			),
			'link'     => 'order',
		),
		'aafm/wc-update-payment-gateway'   => array(
			/* translators: %s: the payment gateway's id slug. */
			'template' => __( 'Updated payment gateway `%s`', 'agent-abilities-for-mcp' ),
			// gateway_id is a slug ("bacs", "cod", "stripe"), never a numeric id, so it takes the
			// key type and declares no link - there is no post/user/term/order to point it at.
			'args'     => array(
				array(
					'key'  => 'gateway_id',
					'type' => 'key',
				),
			),
		),

		// The permanent-delete sweep (B2-07). Every ability named by
		// aafm_permanent_delete_abilities() in helpers.php now records the identifier of what it
		// destroyed. Before this, nine of those thirteen logged detail:null, so the audit trail
		// could say that an agent permanently deleted something but never say WHAT - measured on
		// the wire, where aafm/delete-comment resolved to detail:null carrying only the key NAME
		// "comment_id", one second after aafm/delete-post resolved to "Deleted post #355935".
		// That asymmetry shipped in 1.6.3 and earlier.
		//
		// The completeness of this block is not maintained by hand. DetailTest's
		// test_every_permanent_delete_ability_records_an_identifier derives the destructive set
		// from aafm_permanent_delete_abilities() at runtime and fails the moment a fourteenth
		// permanent delete is added without an entry here, which is the guard whose absence let
		// the original nine ship.
		//
		// Every key below is read from that ability's OWN args builder, never inferred from a
		// sibling. aafm/delete-user-meta is why that rule is written down: it names its parameter
		// `key` where the post-meta abilities name theirs `meta_key`, and its schema description
		// says in as many words that the two "are not interchangeable". A sibling-shaped guess of
		// `meta_key` there resolves to null through the all-or-nothing rule in
		// aafm_build_activity_detail(), logging exactly the blindness this block removes, with
		// every gate still green.
		//
		// All nine read the caller's arguments rather than a result_id, and that is forced rather
		// than stylistic: register.php builds the args detail on the opening 'started' row before
		// the execute callback runs, which is the only moment the object still exists. A permanent
		// delete has no identifier left to dig out of its return value.
		'aafm/delete-comment'              => array(
			/* translators: %s: the comment's numeric ID. */
			'template' => __( 'Deleted comment #%s', 'agent-abilities-for-mcp' ),
			// comment_id, confirmed against aafm_args_delete_comment() in comments.php. No link:
			// aafm_activity_detail_link() resolves post, user, term and order, and there is no
			// comment type. Adding one is a change to that renderer, not to this map.
			'args'     => array(
				array(
					'key'  => 'comment_id',
					'type' => 'id',
				),
			),
		),
		'aafm/delete-media'                => array(
			/* translators: %s: the attachment's numeric ID. */
			'template' => __( 'Deleted media #%s', 'agent-abilities-for-mcp' ),
			// attachment_id, not media_id or post_id - confirmed against aafm_args_delete_media().
			'args'     => array(
				array(
					'key'  => 'attachment_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/delete-menu'                 => array(
			/* translators: %s: the navigation menu's numeric ID. */
			'template' => __( 'Deleted menu #%s', 'agent-abilities-for-mcp' ),
			// menu_id, confirmed against aafm_args_delete_menu(). A navigation menu is a nav_menu
			// TERM, so the link type is term rather than post.
			'args'     => array(
				array(
					'key'  => 'menu_id',
					'type' => 'id',
				),
			),
			'link'     => 'term',
		),
		'aafm/delete-menu-item'            => array(
			/* translators: %s: the menu item's numeric ID. */
			'template' => __( 'Deleted menu item #%s', 'agent-abilities-for-mcp' ),
			// item_id, NOT menu_item_id and not the menu_id its sibling above takes - confirmed
			// against aafm_args_delete_menu_item(). A menu item is a nav_menu_item post.
			'args'     => array(
				array(
					'key'  => 'item_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/delete-revision'             => array(
			/* translators: %s: the deleted revision's numeric ID. */
			'template' => __( 'Deleted revision #%s', 'agent-abilities-for-mcp' ),
			// revision_id, confirmed against aafm_args_delete_revision(). post_id is also required
			// by that ability and is deliberately not declared, matching aafm/restore-revision
			// above: the map names the one identifier worth showing, and a second id field would
			// fail the one-linkable-id rule.
			'args'     => array(
				array(
					'key'  => 'revision_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/delete-term-meta'            => array(
			/* translators: 1: meta key name. 2: the term's numeric ID. */
			'template' => __( 'Deleted meta key `%1$s` on term #%2$s', 'agent-abilities-for-mcp' ),
			// meta_key and term_id, confirmed against aafm_args_delete_term_meta(). The ability
			// also takes an optional taxonomy, which is not an identifier of the thing deleted and
			// is left out. The term itself survives, so unlike its neighbours here this link
			// resolves to a live edit screen.
			'args'     => array(
				array(
					'key'  => 'meta_key',
					'type' => 'key',
				),
				array(
					'key'  => 'term_id',
					'type' => 'id',
				),
			),
			'link'     => 'term',
		),
		'aafm/delete-user-meta'            => array(
			/* translators: 1: meta key name. 2: the user's numeric ID. */
			'template' => __( 'Deleted meta key `%1$s` on user #%2$s', 'agent-abilities-for-mcp' ),
			// `key`, NOT `meta_key` - see the block comment above. Confirmed against
			// aafm_args_delete_user_meta(), whose own description states the post-meta abilities
			// name the equivalent parameter differently and that the two are not interchangeable.
			'args'     => array(
				array(
					'key'  => 'key',
					'type' => 'key',
				),
				array(
					'key'  => 'user_id',
					'type' => 'id',
				),
			),
			'link'     => 'user',
		),
		'aafm/wc-delete-product'           => array(
			/* translators: %s: the product's numeric ID. */
			'template' => __( 'Deleted product #%s', 'agent-abilities-for-mcp' ),
			// product_id, confirmed against aafm_args_wc_delete_product(). A product is a post, so
			// the link type is post; 'order' is only for rows whose id is a WooCommerce order.
			'args'     => array(
				array(
					'key'  => 'product_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
		'aafm/wc-delete-product-variation' => array(
			/* translators: %s: the variation's numeric ID. */
			'template' => __( 'Deleted product variation #%s', 'agent-abilities-for-mcp' ),
			// variation_id, not product_id - confirmed against
			// aafm_args_wc_delete_product_variation(). A variation is its own product_variation
			// post, so the id here is not the parent's.
			'args'     => array(
				array(
					'key'  => 'variation_id',
					'type' => 'id',
				),
			),
			'link'     => 'post',
		),
	);
}

/**
 * Resolve an enum declaration to its allowed members.
 *
 * A declaration may be a literal list or the name of a callable returning one, so a set that is
 * only knowable at runtime (WooCommerce's order statuses) does not have to be duplicated here.
 * An unresolvable declaration yields an empty set, which rejects every value: fail closed.
 *
 * @param mixed $declared Literal list, or the name of a callable returning one.
 * @return array<int,string>
 */
function aafm_activity_detail_enum( $declared ): array {
	if ( is_array( $declared ) ) {
		return array_values( array_map( 'strval', $declared ) );
	}
	if ( is_string( $declared ) && function_exists( $declared ) ) {
		$members = call_user_func( $declared );
		return is_array( $members ) ? array_values( array_map( 'strval', $members ) ) : array();
	}
	return array();
}

/**
 * The order-status keys WooCommerce itself defines, or an empty set when WooCommerce is absent.
 *
 * Reads wc_get_order_statuses() rather than hardcoding, so a site with a custom status registered
 * through WooCommerce's own API logs it correctly and a typo still fails the enum check.
 *
 * @return array<int,string>
 */
function aafm_activity_order_statuses(): array {
	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		return array();
	}
	return array_map(
		static fn( string $key ): string => (string) preg_replace( '/^wc-/', '', $key ),
		array_keys( wc_get_order_statuses() )
	);
}

/**
 * Build a row's detail from an ability's own arguments, or return null.
 *
 * Null is the normal outcome: most abilities declare nothing, and one that declares a field the
 * caller did not send (or sent in a shape that fails its type check) logs no detail rather than a
 * half-built string. Detail is observability, never control flow.
 *
 * @param string              $ability Ability name.
 * @param array<string,mixed> $args    The call's own arguments.
 * @return string|null
 */
function aafm_build_activity_detail( string $ability, array $args ): ?string {
	$entry = aafm_activity_detail_map()[ $ability ] ?? null;
	if ( null === $entry || empty( $entry['args'] ) ) {
		return null;
	}

	$values = array();
	foreach ( $entry['args'] as $field ) {
		$allowed  = isset( $field['enum'] ) ? aafm_activity_detail_enum( $field['enum'] ) : array();
		$rendered = aafm_activity_detail_field(
			(string) $field['type'],
			$args[ $field['key'] ] ?? null,
			$allowed
		);
		if ( null === $rendered ) {
			return null; // All or nothing: never emit a template with a hole in it.
		}
		$values[] = $rendered;
	}

	return vsprintf( (string) $entry['template'], $values );
}

/**
 * Build a row's detail from an ability's RETURN value, for creates whose identifier does not
 * exist until after execution. Only a single positive integer is ever read.
 *
 * The result_id is a dot-separated path, because no create ability in this plugin returns its id at
 * the top level: the post writers return array( 'post' => aafm_redact_post( … ) ) and the user
 * writer returns array( 'user' => aafm_rich_user( … ) ). A bare key would resolve to null on
 * every one of them and the feature would silently log nothing.
 *
 * @param string $ability Ability name.
 * @param mixed  $result  The execute callback's return value.
 * @return string|null
 */
function aafm_build_activity_detail_from_result( string $ability, $result ): ?string {
	// A failed call records WHY it failed, for every FIRST-PARTY ability rather than only the mapped
	// ones. The error CODE and never get_error_message(): a code is an identifier by construction,
	// while a message is free-form prose that routinely interpolates the value that failed (see
	// aafm_wc_invalid_coupon_amount, which quotes the rejected amount). This branch sits before the
	// is_array() return below, which a WP_Error would otherwise fall straight through.
	//
	// A bridged ability is excluded, and "a code is an identifier by construction" is exactly why.
	// That holds for the codes this plugin ships: every `new WP_Error(` under includes/ takes a
	// string literal. It also holds, by trust rather than by inspection, for an ability a site adds
	// through the aafm_abilities_registry filter, which supplies its own permission callback and so
	// is trusted with a great deal more than an error code. It does not hold for a foreign plugin's,
	// which is third-party code the operator merely enabled, free to build a code out of
	// its input - `new WP_Error( 'duplicate_sku_' . $sku )` passes the key type's own character
	// check and would land an argument value in this column, on the wire through
	// aafm/get-activity-log, and in the CSV export. This file's header, the aafm_ability_resolved
	// docblock and the admin panel all promise that cannot happen, and a promise with a
	// third-party-shaped hole in it is not one. A bridged crash still records its exception class
	// and throw site, which the engine supplies and no input can reach, so a bridged row is not
	// left blind - it just does not carry a string a foreign plugin composed.
	if ( is_wp_error( $result ) ) {
		if ( str_starts_with( $ability, AAFM_BRIDGE_NAMESPACE . '/' ) ) {
			return null;
		}
		$code = aafm_activity_detail_field( 'key', $result->get_error_code() );
		return ( null === $code || '' === $code ) ? null : $code;
	}

	$entry = aafm_activity_detail_map()[ $ability ] ?? null;
	if ( null === $entry || empty( $entry['result_id'] ) || ! is_array( $result ) ) {
		return null;
	}
	$id = aafm_activity_detail_field( 'id', aafm_activity_detail_dig( $result, (string) $entry['result_id'] ) );
	return ( null === $id ) ? null : sprintf( (string) $entry['template'], $id );
}

/**
 * Build a row's detail from a caught throwable: its class and where it was thrown, never its
 * message.
 *
 * Deliberately NOT a new aafm_activity_detail_field() type: the parts here come from the PHP
 * engine, not from caller input, so they need constraining rather than validating, and adding a
 * free-form string type is the change this file's header tells a reviewer to refuse.
 *
 * $e->getMessage() is never read. A vendor exception message routinely interpolates the value that
 * caused it - a rejected email address, a duplicated SKU, a download filename - and the log's
 * central promise, stated in the wp.org listing and not only in this codebase, is that argument
 * VALUES are never stored. The class and the throw site identify the defect more precisely than the
 * prose does anyway.
 *
 * get_class() needs the guard below, not only the message: PHP renders an anonymous class as
 * "Parent@anonymous\0<absolute file path>:<line>$<counter>", so the raw class name of an anonymous
 * throwable carries the site's install path AND a NUL byte, and aafm_sanitize_activity_detail()
 * removes neither (a NUL is valid UTF-8, so wp_check_invalid_utf8() passes it through). Cutting at
 * the NUL leaves "Parent@anonymous", which is all the useful part. PHP 7.4 writes "class" where
 * 8.0+ writes the parent's name, so on the floor the parent is derived instead - see below - and
 * a row records the same shape on every supported version.
 *
 * A class name that arrived here longer or wider than the row can hold gets a trailing '~', so a
 * reader can tell a recorded name from a recorded name that is only close. That covers characters
 * the filter removed, a name cut at the 128-character cap, and a name the filter emptied entirely.
 *
 * @param \Throwable $e The caught exception or error.
 * @return string A bounded, value-free detail, e.g. "WC_Data_Exception at abstract-wc-data.php:1001".
 */
function aafm_build_activity_detail_from_exception( \Throwable $e ): string {
	$class = get_class( $e );
	$nul   = strpos( $class, "\0" );
	if ( false !== $nul ) {
		$class = substr( $class, 0, $nul );
	}

	// PHP 8.0 renders an anonymous class as "Parent@anonymous...", but 7.4 - this plugin's floor -
	// renders it as the literal "class@anonymous...", so the cut above leaves a name that
	// identifies nothing. get_parent_class() returns the same parent on both versions, so deriving
	// it here makes a 7.4 site's row name the thrown class as precisely as an 8.x site's, rather
	// than leaving the floor with a quietly less useful audit log than the docblock promises.
	//
	// Keyed on the string the engine actually produced, not on PHP_VERSION_ID: it fires exactly
	// when the name is uninformative and is a no-op the moment the engine supplies a parent itself.
	// A parent that is itself anonymous carries its own NUL, so it gets the same cut. The
	// is_string() guard is belt and braces - PHP rejects a userland class that implements Throwable
	// without extending Exception or Error, so an anonymous throwable always has a parent, and
	// get_parent_class() returns false only when there is none.
	if ( 'class@anonymous' === $class ) {
		$parent = get_parent_class( $e );
		if ( is_string( $parent ) ) {
			$parent_nul = strpos( $parent, "\0" );
			$class      = ( false === $parent_nul ? $parent : substr( $parent, 0, $parent_nul ) ) . '@anonymous';
		}
	}

	// A PHP class name is [A-Za-z0-9_\] plus the '@anonymous' marker kept above; anything else is
	// not a class name and has no business in an audit row.
	//
	// PHP does allow bytes \x80-\xff in a class name, though, so the filter can turn a real class
	// into a DIFFERENT real one: `Exceptiéon` records as `Exception`, which reads as an ordinary
	// name and points a forensic reader at the wrong class entirely. A trailing '~' says the name
	// that reached this row is not the name that was thrown. The filter is not widened to \p{L}\p{N}
	// instead, because /u makes preg_replace() return null on invalid UTF-8 and this is the one
	// place that cannot afford to.
	//
	// One marker for every way the name can be cut, compared against what came in rather than
	// against the filter alone. The cap cuts too: a 150-character class truncates to exactly 128,
	// and an unmarked 128 characters can be a real class whose whole name is those 128 characters,
	// which is the collision the marker exists to prevent. A name the filter empties falls back to
	// the Throwable sentinel and is marked as well, so total loss never reads quieter than partial
	// loss. The anonymous-class path is untouched: the NUL cut above has already reduced it to
	// "Parent@anonymous", which the filter and the cap both leave alone.
	$raw      = $class;
	$filtered = (string) preg_replace( '/[^A-Za-z0-9_\\\\@]/', '', $class );
	$class    = '' === $filtered ? 'Throwable' : mb_substr( $filtered, 0, 128 );
	if ( $class !== $raw ) {
		$class .= '~';
	}

	// basename() drops the install path; the character filter absorbs the " : evaluated code"
	// suffix PHP appends to getFile() when the throw happens inside a runtime-evaluated string.
	$file = (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', basename( $e->getFile() ) );
	$file = '' === $file ? 'unknown' : mb_substr( $file, 0, 64 );

	return sprintf( '%1$s at %2$s:%3$d', $class, $file, max( 0, $e->getLine() ) );
}

/**
 * Walk a dot-separated path into a nested array, or return null.
 *
 * Deliberately tiny and total: any missing segment, or a non-array encountered mid-path, yields
 * null rather than a warning. Callers treat null as "no detail", which is always a safe outcome.
 *
 * @param array<string,mixed> $data Source array.
 * @param string              $path Dot-separated path, e.g. 'post.id'.
 * @return mixed
 */
function aafm_activity_detail_dig( array $data, string $path ) {
	$cursor = $data;
	foreach ( explode( '.', $path ) as $segment ) {
		if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
			return null;
		}
		$cursor = $cursor[ $segment ];
	}
	return $cursor;
}

/**
 * The object type an ability's detail identifier refers to, for render-time linking.
 *
 * @param string $ability Ability name.
 * @return string|null One of post|user|term|order, or null when the ability declares no link.
 */
function aafm_activity_detail_link_type( string $ability ): ?string {
	$link = aafm_activity_detail_map()[ $ability ]['link'] ?? null;
	return in_array( $link, array( 'post', 'user', 'term', 'order' ), true ) ? $link : null;
}
