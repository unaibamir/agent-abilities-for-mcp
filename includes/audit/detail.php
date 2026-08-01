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
