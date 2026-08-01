<?php
/**
 * Activity log detail: the identifier-only allowlist.
 *
 * The log's central privacy promise is that argument VALUES are never stored (see the
 * aafm_log_activity() docblock). The detail column is the one narrow exception, and this file is
 * the whole of it: an ability logs a detail only if it appears in aafm_activity_detail_map(), and
 * only the fields that map names, each of which must clear a type check that no free-form string
 * can pass. Default deny in both directions. There is deliberately no string or text field type,
 * and adding one is the change a reviewer should refuse.
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

	switch ( $type ) {
		case 'id':
			$id = is_numeric( $value ) ? (int) $value : 0;
			return $id > 0 ? (string) $id : null;

		case 'count':
			$count = is_numeric( $value ) ? (int) $value : -1;
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
		'aafm/update-post-meta'       => array(
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
		'aafm/create-page'            => array(
			/* translators: %s: the new page's numeric ID. */
			'template'  => __( 'Created page #%s', 'agent-abilities-for-mcp' ),
			// Dotted path, NOT a bare 'id'. aafm_insert_post() returns
			// array( 'post' => aafm_redact_post( $created ) ), so the id lives at post.id, never at
			// the top level. Every create ability in this map wraps its payload the same way; a
			// bare key silently resolves to null and the row logs no detail.
			'result_id' => 'post.id',
			'link'      => 'post',
		),
		'aafm/wc-update-order-status' => array(
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
	$entry = aafm_activity_detail_map()[ $ability ] ?? null;
	if ( null === $entry || empty( $entry['result_id'] ) || ! is_array( $result ) ) {
		return null;
	}
	$id = aafm_activity_detail_field( 'id', aafm_activity_detail_dig( $result, (string) $entry['result_id'] ) );
	return ( null === $id ) ? null : sprintf( (string) $entry['template'], $id );
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
