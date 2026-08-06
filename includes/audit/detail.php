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
 * code, which is an identifier by construction for a first-party ability - every `new WP_Error(`
 * under includes/ takes a string literal - but not for a bridged one, whose code a foreign plugin
 * is free to build out of its own input, so bridged results are excluded from that branch. Since
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
		'aafm/update-post-meta'          => array(
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
		'aafm/create-page'               => array(
			/* translators: %s: the new page's numeric ID. */
			'template'  => __( 'Created page #%s', 'agent-abilities-for-mcp' ),
			// Dotted path, NOT a bare 'id'. aafm_insert_post() returns
			// array( 'post' => aafm_redact_post( $created ) ), so the id lives at post.id, never at
			// the top level. Every create ability in this map wraps its payload the same way; a
			// bare key silently resolves to null and the row logs no detail.
			'result_id' => 'post.id',
			'link'      => 'post',
		),
		'aafm/wc-update-order-status'    => array(
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
		'aafm/create-post'               => array(
			/* translators: %s: the new post's numeric ID. */
			'template'  => __( 'Created post #%s', 'agent-abilities-for-mcp' ),
			// aafm_exec_create_post() delegates to aafm_insert_post(), which returns
			// array( 'post' => aafm_redact_post( … ) ) - same wrapped shape as create-page.
			'result_id' => 'post.id',
			'link'      => 'post',
		),
		'aafm/create-draft'              => array(
			/* translators: %s: the new draft's numeric ID. */
			'template'  => __( 'Created draft #%s', 'agent-abilities-for-mcp' ),
			'result_id' => 'post.id',
			'link'      => 'post',
		),
		'aafm/create-user'               => array(
			/* translators: %s: the new user's numeric ID. */
			'template'  => __( 'Created user #%s', 'agent-abilities-for-mcp' ),
			// aafm_exec_create_user() returns array( 'user' => aafm_rich_user( … ) ), never a
			// top-level id.
			'result_id' => 'user.id',
			'link'      => 'user',
		),
		'aafm/delete-post-meta'          => array(
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
		'aafm/update-post'               => array(
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
		'aafm/update-page'               => array(
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
		'aafm/delete-post'               => array(
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
		'aafm/delete-page'               => array(
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
		'aafm/trash-post'                => array(
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
		'aafm/trash-page'                => array(
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
		'aafm/restore-revision'          => array(
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
		'aafm/update-user'               => array(
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
		'aafm/delete-user'               => array(
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
		'aafm/wc-create-order-refund'    => array(
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
		'aafm/wc-update-payment-gateway' => array(
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
	// That holds for OUR codes: every `new WP_Error(` under includes/ takes a string literal. It
	// does not hold for a foreign plugin's, which is third-party code free to build a code out of
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
 * the NUL leaves "Parent@anonymous", which is all the useful part.
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
