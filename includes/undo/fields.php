<?php
/**
 * Reading and hashing the fields a change touches. Every read the engine decides on bypasses the
 * object cache and is failure-aware: a failed read is never stored as an empty value.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * SHA-256 of the JSON encoding of a field value.
 *
 * @param mixed $value A field value.
 * @return string
 */
function aafm_undo_hash( $value ): string {
	return hash( 'sha256', (string) wp_json_encode( $value ) );
}

/**
 * Whether a key looks like it names a secret, so no value of it is ever stored.
 *
 * Dashes and camelCase boundaries split into underscores first. A pattern that fails to compile
 * counts as a match. The filter can only add patterns; the defaults always stay.
 *
 * @param string $key Meta key.
 * @return bool
 */
function aafm_undo_secret_shaped_key( string $key ): bool {
	$normal   = str_replace( '-', '_', (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key ) );
	$defaults = array( '/(^|_)(pass(word)?|passwd|pwd|secret|token|api_?key|private_?key|license_?key|access_?key|auth|credential)s?($|_)/i' );
	/**
	 * Add patterns for meta keys whose values must never be kept for undo. The defaults cannot be removed.
	 *
	 * @since 1.11.2
	 * @param string[] $patterns Regular expressions matched against the normalised key.
	 */
	$added    = apply_filters( 'aafm_undo_secret_key_patterns', $defaults );
	$patterns = array_values( array_unique( array_merge( $defaults, array_filter( (array) $added, 'is_string' ) ) ) );
	foreach ( $patterns as $pattern ) {
		$hit = @preg_match( $pattern, $normal ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken pattern is a refusal, and its warning is not a log line.
		if ( false === $hit || 1 === $hit ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether every string inside a value is valid UTF-8.
 *
 * @param mixed $value A field value.
 * @return bool
 */
function aafm_undo_value_is_utf8( $value ): bool {
	if ( is_string( $value ) ) {
		return wp_check_invalid_utf8( $value ) === $value;
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			if ( ! aafm_undo_value_is_utf8( $item ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * The reason a field's value cannot be kept for restore, or null when it can.
 *
 * @param string $field Field id.
 * @param mixed  $value The value read.
 * @return string|null Reason code.
 */
function aafm_undo_field_flag( string $field, $value ): ?string {
	if ( 0 === strpos( $field, 'meta:' ) ) {
		if ( aafm_undo_secret_shaped_key( substr( $field, 5 ) ) ) {
			return 'secret_shaped';
		}
		foreach ( (array) $value as $raw ) {
			$reason = aafm_meta_raw_restorable( (string) $raw );
			if ( null !== $reason ) {
				return $reason;
			}
		}
	}
	if ( ! aafm_undo_value_is_utf8( $value ) ) {
		return 'not_utf8';
	}
	$json = wp_json_encode( $value );
	if ( ! is_string( $json ) || json_decode( $json, true ) !== $value ) {
		return 'not_exact';
	}
	return strlen( $json ) > AAFM_UNDO_MAX_CHANGE_BYTES ? 'too_large' : null;
}

/**
 * Read the current value of every named field of one object, from the database.
 *
 * Post columns come from one row read after the post cache is cleaned, meta from one raw snapshot
 * query for the whole family, term relationships from one join, term columns from one join. A meta
 * key with rows under another spelling, or a NULL value, is flagged not_exact.
 *
 * @param string               $object_type 'post' or 'term'.
 * @param int                  $object_id   Object id.
 * @param string[]             $field_ids   Field ids to read.
 * @param array<string,string> $context     taxonomy for a term object.
 * @return array{values: array<string,mixed>, flags: array<string,string>}|null Null when any read failed.
 */
function aafm_undo_read_fields( string $object_type, int $object_id, array $field_ids, array $context = array() ): ?array {
	global $wpdb;

	$values = array();
	$flags  = array();
	$groups = array(
		'post'  => array(),
		'meta'  => array(),
		'terms' => array(),
		'term'  => array(),
	);
	foreach ( $field_ids as $field ) {
		$kind = (string) strstr( $field, ':', true );
		if ( 'fp' === $kind ) {
			$fingerprint = aafm_undo_object_fingerprint( $object_id );
			if ( null === $fingerprint ) {
				return null;
			}
			$values[ $field ] = $fingerprint;
		} elseif ( isset( $groups[ $kind ] ) ) {
			$groups[ $kind ][ $field ] = substr( $field, strlen( $kind ) + 1 );
		} else {
			return null;
		}
	}

	if ( array() !== $groups['post'] ) {
		clean_post_cache( $object_id );
		$columns = array_values( $groups['post'] );
		$sql     = 'SELECT ' . implode( ', ', array_fill( 0, count( $columns ), '%i' ) ) . ' FROM %i WHERE ID = %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholders are generated from the closed column list above.
		$row = aafm_wpdb_row( $wpdb->prepare( $sql, array_merge( $columns, array( $wpdb->posts, $object_id ) ) ) );
		if ( ! $row['ok'] || ! is_array( $row['value'] ) ) {
			return null;
		}
		foreach ( $groups['post'] as $field => $column ) {
			$values[ $field ] = (string) $row['value'][ $column ];
		}
	}

	if ( array() !== $groups['meta'] ) {
		$snapshot = aafm_meta_raw_snapshot( $object_type, $object_id, array_values( $groups['meta'] ) );
		if ( ! $snapshot['ok'] ) {
			return null;
		}
		foreach ( $groups['meta'] as $field => $key ) {
			$entry            = $snapshot['by_key'][ $key ];
			$values[ $field ] = $entry['raw'];
			if ( $entry['aliased'] > 0 ) {
				$flags[ $field ] = 'not_exact';
			}
		}
	}

	if ( array() !== $groups['terms'] ) {
		$taxonomies = array_values( $groups['terms'] );
		$sql        = 'SELECT tt.taxonomy, tt.term_id FROM %i tr INNER JOIN %i tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy IN (' . implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) ) . ')';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholders are generated from the field list above.
		$rows = aafm_wpdb_results( $wpdb->prepare( $sql, array_merge( array( $wpdb->term_relationships, $wpdb->term_taxonomy, $object_id ), $taxonomies ) ) );
		if ( ! $rows['ok'] ) {
			return null;
		}
		$by_taxonomy = array_fill_keys( $taxonomies, array() );
		foreach ( (array) $rows['value'] as $row ) {
			$by_taxonomy[ (string) $row['taxonomy'] ][] = (int) $row['term_id'];
		}
		foreach ( $groups['terms'] as $field => $taxonomy ) {
			sort( $by_taxonomy[ $taxonomy ] );
			$values[ $field ] = $by_taxonomy[ $taxonomy ];
		}
	}

	if ( array() !== $groups['term'] ) {
		$sql = 'SELECT t.name, tt.description, tt.parent FROM %i t INNER JOIN %i tt ON t.term_id = tt.term_id WHERE t.term_id = %d AND tt.taxonomy = %s';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a constant statement with placeholders.
		$row = aafm_wpdb_row( $wpdb->prepare( $sql, $wpdb->terms, $wpdb->term_taxonomy, $object_id, (string) ( $context['taxonomy'] ?? '' ) ) );
		if ( ! $row['ok'] || ! is_array( $row['value'] ) ) {
			return null;
		}
		foreach ( $groups['term'] as $field => $column ) {
			$values[ $field ] = 'parent' === $column ? (int) $row['value']['parent'] : (string) $row['value'][ $column ];
		}
	}

	return array(
		'values' => $values,
		'flags'  => $flags,
	);
}

/**
 * A SHA-256 over the whole post object: the posts row (less to_ping and pinged, which cron's pinging
 * rewrites), every meta row (less the edit lock and the ping flags), every term relationship, and
 * every comment with its meta. Trashing the post would take all of that with it, so a create is
 * undone only while nothing but the agent's own call has touched it.
 *
 * Each part is read with LIMIT 1001 and the whole is bounded: more than 1,000 rows in a part, or
 * more than 8 MiB in total, returns null, as does any failed read. Null refuses the undo.
 *
 * @param int $post_id Post id.
 * @return string|null
 */
function aafm_undo_object_fingerprint( int $post_id ): ?string {
	global $wpdb;

	clean_post_cache( $post_id );
	$post = aafm_wpdb_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ID = %d', $wpdb->posts, $post_id ) );
	if ( ! $post['ok'] || ! is_array( $post['value'] ) ) {
		return null;
	}
	unset( $post['value']['to_ping'], $post['value']['pinged'] );

	$queries = array(
		'terms'       => $wpdb->prepare( 'SELECT tt.taxonomy, tt.term_id FROM %i tr INNER JOIN %i tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = %d ORDER BY tt.taxonomy, tt.term_id LIMIT 1001', $wpdb->term_relationships, $wpdb->term_taxonomy, $post_id ),
		'comments'    => $wpdb->prepare( 'SELECT * FROM %i WHERE comment_post_ID = %d ORDER BY comment_ID LIMIT 1001', $wpdb->comments, $post_id ),
		'commentmeta' => $wpdb->prepare( 'SELECT cm.* FROM %i cm INNER JOIN %i c ON c.comment_ID = cm.comment_id WHERE c.comment_post_ID = %d ORDER BY cm.meta_id LIMIT 1001', $wpdb->commentmeta, $wpdb->comments, $post_id ),
	);
	$parts   = array( 'post' => $post['value'] );
	$bytes   = 0;
	foreach ( $queries as $name => $sql ) {
		$rows = aafm_wpdb_results( $sql );
		if ( ! $rows['ok'] || count( (array) $rows['value'] ) > 1000 ) {
			return null;
		}
		$parts[ $name ] = $rows['value'];
	}
	$meta = aafm_meta_object_fingerprint( 'post', $post_id, array( '_edit_lock', '_encloseme', '_pingme' ) );
	if ( null === $meta ) {
		return null;
	}
	$parts['meta'] = $meta;
	$json          = wp_json_encode( $parts );
	$bytes         = is_string( $json ) ? strlen( $json ) : 0;
	return ( is_string( $json ) && $bytes <= 8 * 1024 * 1024 ) ? hash( 'sha256', $json ) : null;
}
