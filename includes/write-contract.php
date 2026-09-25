<?php
/**
 * The write-and-confirm contract: readers, writer, delete, group writer, the checked-read scope,
 * the option rule, the post-field wrapper, and the write-outcome log observer.
 *
 * This is the only file the caller-enumeration sweep (tests/MetaWriteSweepTest.php) permits to call
 * a raw meta or option primitive. Every metadata write and delete in the plugin routes through the
 * functions here so a write's outcome is decided once, the same way, everywhere.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Status constants. PHP 7.4 floor: string constants, not an enum.
if ( ! defined( 'AAFM_WRITE_WRITTEN' ) ) {
	define( 'AAFM_WRITE_WRITTEN', 'written' );
}
if ( ! defined( 'AAFM_WRITE_UNCHANGED' ) ) {
	define( 'AAFM_WRITE_UNCHANGED', 'unchanged' );
}
if ( ! defined( 'AAFM_WRITE_DELETED' ) ) {
	define( 'AAFM_WRITE_DELETED', 'deleted' );
}
if ( ! defined( 'AAFM_WRITE_ABSENT' ) ) {
	define( 'AAFM_WRITE_ABSENT', 'absent' );
}
if ( ! defined( 'AAFM_WRITE_REFUSED' ) ) {
	define( 'AAFM_WRITE_REFUSED', 'refused' );
}
if ( ! defined( 'AAFM_WRITE_READ_FAILED' ) ) {
	define( 'AAFM_WRITE_READ_FAILED', 'read_failed' );
}
if ( ! defined( 'AAFM_WRITE_UNCONFIRMED' ) ) {
	define( 'AAFM_WRITE_UNCONFIRMED', 'unconfirmed' );
}
if ( ! defined( 'AAFM_WRITE_PARTIAL' ) ) {
	define( 'AAFM_WRITE_PARTIAL', 'partial' );
}
if ( ! defined( 'AAFM_WRITE_ACCEPTED' ) ) {
	define( 'AAFM_WRITE_ACCEPTED', 'accepted' );
}

/**
 * The plain map of write kind to its writer functions, built once with no filter.
 *
 * A writer a later migration step still has to build (the vendor kinds) is listed here from the
 * start; that step is the one that makes function_exists() true for its name.
 *
 * @return array<string,string[]>
 */
function aafm_write_writers(): array {
	$meta_writers = array( 'aafm_meta_set', 'aafm_meta_delete', 'aafm_meta_set_group' );

	return array(
		'post_meta'    => $meta_writers,
		'term_meta'    => $meta_writers,
		'user_meta'    => $meta_writers,
		'option'       => array( 'aafm_option_write', 'aafm_update_option_verified', 'aafm_persist_operator_switch', 'aafm_delete_option_cache_safe' ),
		'post_field'   => array( 'aafm_post_field_confirm_logged' ),
		'acf'          => array( 'aafm_acf_write_field' ),
		'aioseo'       => array( 'aafm_aioseo_write' ),
		'geodirectory' => array( 'aafm_geodir_write' ),
		'tec'          => array( 'aafm_tec_write' ),
		'woocommerce'  => array( 'aafm_wc_write' ),
	);
}

/**
 * The metadata table id column and object id column for a meta type.
 *
 * @param string $type 'post', 'term', 'user' or 'comment'.
 * @return array{id_column: string, object_id_column: string}
 */
function aafm_meta_columns( string $type ): array {
	return array(
		'id_column'        => 'user' === $type ? 'umeta_id' : 'meta_id',
		'object_id_column' => $type . '_id',
	);
}

/**
 * Read every row of one meta key with a failure-aware query.
 *
 * The meta_key column compares under its collation, which on a stock install ignores case,
 * accents and trailing spaces, so the query also returns rows stored under another spelling of the
 * key. Core's update_metadata() and delete_metadata() would act on those rows too, while PHP and
 * core's meta cache compare bytes. exists, count, value and values therefore cover only the rows
 * whose stored meta_key is byte-identical to $key, and aliased counts the others. A writer refuses
 * the request when aliased is above zero.
 *
 * Selects the meta id column first, then the object id column, meta_key and meta_value, ordered by
 * meta id, so that under the no-flush fault shape a query left over in $wpdb->last_result by an
 * earlier statement is read as real rows of the key rather than producing an undefined-index
 * warning.
 *
 * @param string $type 'post', 'term' or 'user'.
 * @param int    $id   Object id.
 * @param string $key  Meta key.
 * @return array{ok: bool, exists: bool, count: int, value: mixed, values: array<int, mixed>, aliased: int}
 */
function aafm_meta_row( string $type, int $id, string $key ): array {
	global $wpdb;

	$failed = array(
		'ok'      => false,
		'exists'  => false,
		'count'   => 0,
		'value'   => null,
		'values'  => array(),
		'aliased' => 0,
	);

	$table = _get_meta_table( $type );
	if ( ! $table ) {
		return $failed;
	}

	$cols             = aafm_meta_columns( $type );
	$id_column        = $cols['id_column'];
	$object_id_column = $cols['object_id_column'];

	$sql = "SELECT {$id_column}, {$object_id_column}, meta_key, meta_value FROM %i WHERE {$object_id_column} = %d AND meta_key = %s ORDER BY {$id_column}";
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $id_column/$object_id_column are internal, computed from the fixed {post,term,user} set, never from caller input.
	$view = aafm_wpdb_results( $wpdb->prepare( $sql, $table, $id, $key ) );
	if ( ! $view['ok'] ) {
		return $failed;
	}

	$values  = array();
	$aliased = 0;
	foreach ( (array) $view['value'] as $row ) {
		if ( (string) $row['meta_key'] === $key ) {
			$values[] = maybe_unserialize( $row['meta_value'] );
		} else {
			++$aliased;
		}
	}

	return array(
		'ok'      => true,
		'exists'  => array() !== $values,
		'count'   => count( $values ),
		'value'   => $values[0] ?? null,
		'values'  => $values,
		'aliased' => $aliased,
	);
}

/**
 * Read every row of several meta keys in one query: the multi-key preflight for a group write.
 *
 * Each key's entry follows aafm_meta_row(): exists, count, value and values cover only the rows
 * stored under exactly that spelling, and aliased counts the rows the column's collation matched
 * under another spelling. PHP cannot repeat that comparison, so one flag column per requested key
 * has the database say which requested keys each row matched. A key requested twice with the same
 * spelling is read once, and no row counts twice for one key.
 *
 * @param string   $type Object type.
 * @param int      $id   Object id.
 * @param string[] $keys Meta keys to read.
 * @return array{ok: bool, by_key: array<string, array{exists: bool, count: int, value: mixed, values: array<int, mixed>, aliased: int}>}
 */
function aafm_meta_rows( string $type, int $id, array $keys ): array {
	global $wpdb;

	$keys   = array_values( array_unique( array_map( 'strval', $keys ), SORT_STRING ) );
	$by_key = array();
	foreach ( $keys as $key ) {
		$by_key[ $key ] = array(
			'exists'  => false,
			'count'   => 0,
			'value'   => null,
			'values'  => array(),
			'aliased' => 0,
		);
	}

	$table = _get_meta_table( $type );
	if ( ! $table || array() === $keys ) {
		return array(
			'ok'     => (bool) $table,
			'by_key' => $by_key,
		);
	}

	$cols             = aafm_meta_columns( $type );
	$id_column        = $cols['id_column'];
	$object_id_column = $cols['object_id_column'];

	$flags = array();
	foreach ( array_keys( $keys ) as $index ) {
		$flags[] = "meta_key = %s AS aafm_match_{$index}";
	}
	$flag_columns = implode( ', ', $flags );
	$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
	$sql          = "SELECT {$id_column}, {$object_id_column}, meta_key, meta_value, {$flag_columns} FROM %i WHERE {$object_id_column} = %d AND meta_key IN ({$placeholders}) ORDER BY {$id_column}";
	$args         = array_merge( $keys, array( $table, $id ), $keys );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- see aafm_meta_row(); $flag_columns holds only placeholders and aliases built from list indexes.
	$view = aafm_wpdb_results( $wpdb->prepare( $sql, $args ) );

	if ( ! $view['ok'] ) {
		return array(
			'ok'     => false,
			'by_key' => $by_key,
		);
	}

	$values  = array_fill_keys( $keys, array() );
	$aliased = array_fill_keys( $keys, 0 );
	foreach ( (array) $view['value'] as $row ) {
		foreach ( $keys as $index => $key ) {
			if ( empty( $row[ "aafm_match_{$index}" ] ) ) {
				continue;
			}
			if ( (string) $row['meta_key'] === $key ) {
				$values[ $key ][] = maybe_unserialize( $row['meta_value'] );
			} else {
				++$aliased[ $key ];
			}
		}
	}
	foreach ( $keys as $key ) {
		$by_key[ $key ] = array(
			'exists'  => array() !== $values[ $key ],
			'count'   => count( $values[ $key ] ),
			'value'   => $values[ $key ][0] ?? null,
			'values'  => $values[ $key ],
			'aliased' => $aliased[ $key ],
		);
	}

	return array(
		'ok'     => true,
		'by_key' => $by_key,
	);
}

/**
 * Whether two of the requested keys are one key to the database, compared under the meta_key
 * column's own collation.
 *
 * The first branch of the union carries its key through CONCAT() with a meta_key value from an
 * empty read of the meta table, so the whole union column takes that column's collation, not the
 * connection's. COUNT( DISTINCT ) then counts the keys the way the column would, and fewer
 * distinct values than keys means two of them collide. The LEFT JOIN on a one-row derived table
 * keeps the result to one row when the meta table has no rows at all.
 *
 * @param string   $type 'post', 'term' or 'user'.
 * @param string[] $keys Meta keys, each spelled differently.
 * @return bool|null True when two keys collide, false when none do, null when the query failed.
 */
function aafm_meta_keys_collide( string $type, array $keys ): ?bool {
	global $wpdb;

	$keys = array_values( array_unique( array_map( 'strval', $keys ), SORT_STRING ) );
	if ( count( $keys ) < 2 ) {
		return false;
	}
	$table = _get_meta_table( $type );
	if ( ! $table ) {
		return null;
	}

	$union = "SELECT CONCAT( IFNULL( m.meta_key, '' ), %s ) AS k FROM ( SELECT 1 AS one ) AS d LEFT JOIN ( SELECT meta_key FROM %i LIMIT 0 ) AS m ON 1 = 1";
	$args  = array( $keys[0], $table );
	foreach ( array_slice( $keys, 1 ) as $key ) {
		$union .= ' UNION ALL SELECT %s';
		$args[] = $key;
	}
	$sql = "SELECT COUNT( DISTINCT u.k ) AS distinct_keys FROM ( {$union} ) AS u";
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $union holds only placeholders and fixed SQL.
	$view = aafm_wpdb_results( $wpdb->prepare( $sql, $args ) );
	if ( ! $view['ok'] || ! isset( $view['value'][0]['distinct_keys'] ) ) {
		return null;
	}

	return (int) $view['value'][0]['distinct_keys'] < count( $keys );
}


/**
 * Read a key's state back through core after a write or delete. Through core, a failed query and
 * "no meta" are the same answer, so this has no ok key.
 *
 * @param string $type Object type.
 * @param int    $id   Object id.
 * @param string $key  Meta key.
 * @return array{exists: bool, count: int, values: array<int, mixed>}
 */
function aafm_meta_readback( string $type, int $id, string $key ): array {
	wp_cache_delete( $id, $type . '_meta' );
	$raw = get_metadata_raw( $type, $id, $key, false );
	// Drop the set core just loaded, on both return paths: when its query failed, core cached an
	// empty set, and a later read in this call or a persistent cache in a later request would trust
	// it.
	wp_cache_delete( $id, $type . '_meta' );
	if ( ! is_array( $raw ) ) {
		return array(
			'exists' => false,
			'count'  => 0,
			'values' => array(),
		);
	}
	// get_metadata_raw() has already run maybe_unserialize() over every row (wp-includes/meta.php).
	// Decoding again would turn a stored string that merely looks serialized, such as 'a:0:{}', into
	// an array it never was.
	return array(
		'exists' => count( $raw ) > 0,
		'count'  => count( $raw ),
		'values' => $raw,
	);
}

/**
 * The one sanctioned getter for a pure read: exactly core's own get_metadata(), defaults and
 * filters included.
 *
 * @param string $type   Object type.
 * @param int    $id     Object id.
 * @param string $key    Meta key, or '' for every key.
 * @param bool   $single Whether to return a single value.
 * @return mixed
 */
function aafm_meta_get( string $type, int $id, string $key = '', bool $single = false ) {
	return get_metadata( $type, $id, $key, $single );
}

/**
 * Run $build with core's own metadata load made failure-aware, so a response field computed
 * alongside a write fails the call instead of returning a made-up empty value.
 *
 * Hooks update_{post,term,user,comment}_metadata_cache at the last priority for the life of
 * $build. An incoming non-null value means another plugin already took over the load and is left
 * untouched. Otherwise this determines which requested ids core's own cache does not already hold
 * (the same wp_cache_get_multiple() test core makes) and runs a copy of core's own load query for
 * them. A failed query marks the scope failed and returns false, so core returns without running
 * its own query and without caching anything. A successful query is shaped exactly as core shapes
 * it and stored with wp_cache_set_multiple() (never wp_cache_add_multiple(), so a scope suspended
 * with wp_suspend_cache_addition() still installs the rows core's own query would have read).
 *
 * @param callable $build The response builder to run inside the scope.
 * @param WP_Error $error The error to return in place of $build's result when a load failed.
 * @return array<string,mixed>|WP_Error
 */
function aafm_with_checked_reads( callable $build, WP_Error $error ) {
	$failed = false;
	$types  = array( 'post', 'term', 'user', 'comment' );

	// core's update_{type}_metadata_cache filter is called with exactly two arguments
	// (apply_filters( "update_{$meta_type}_metadata_cache", null, $object_ids ), wp-includes/meta.php),
	// so $meta_type has to be baked into a per-type closure rather than received as a third argument.
	$make_handler = static function ( string $meta_type ) use ( &$failed ): callable {
		return static function ( $check, $object_ids ) use ( $meta_type, &$failed ) {
			if ( null !== $check ) {
				return $check;
			}

			global $wpdb;
			$table = _get_meta_table( $meta_type );
			if ( ! $table ) {
				return $check;
			}

			$cache_key = $meta_type . '_meta';
			$missing   = array();
			foreach ( wp_cache_get_multiple( $object_ids, $cache_key ) as $object_id => $cached ) {
				if ( false === $cached ) {
					$missing[] = $object_id;
				}
			}
			if ( array() === $missing ) {
				return null;
			}

			$cols             = aafm_meta_columns( $meta_type );
			$id_column        = $cols['id_column'];
			$object_id_column = $cols['object_id_column'];
			$placeholders     = implode( ', ', array_fill( 0, count( $missing ), '%d' ) );
			$sql              = "SELECT {$object_id_column}, meta_key, meta_value FROM %i WHERE {$object_id_column} IN ({$placeholders}) ORDER BY {$id_column} ASC";
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- identifiers are internal, computed from the fixed {post,term,user,comment} set.
			$view = aafm_wpdb_results( $wpdb->prepare( $sql, array_merge( array( $table ), $missing ) ) );

			if ( ! $view['ok'] ) {
				$failed = true;
				return false;
			}

			$shaped = array();
			foreach ( $missing as $object_id ) {
				$shaped[ $object_id ] = array();
			}
			foreach ( (array) $view['value'] as $row ) {
				$object_id = (int) $row[ $object_id_column ];
				$meta_key  = (string) $row['meta_key'];
				if ( ! isset( $shaped[ $object_id ][ $meta_key ] ) ) {
					$shaped[ $object_id ][ $meta_key ] = array();
				}
				$shaped[ $object_id ][ $meta_key ][] = $row['meta_value'];
			}

			wp_cache_set_multiple( $shaped, $cache_key );

			return null;
		};
	};

	$handlers = array();
	foreach ( $types as $type ) {
		$handlers[ $type ] = $make_handler( $type );
		add_filter( "update_{$type}_metadata_cache", $handlers[ $type ], PHP_INT_MAX, 2 );
	}

	try {
		$built = $build();
	} finally {
		foreach ( $types as $type ) {
			remove_filter( "update_{$type}_metadata_cache", $handlers[ $type ], PHP_INT_MAX );
		}
	}

	return $failed ? $error : $built;
}

/**
 * Whether two metadata values are equal, the one rule every comparison in the writer and delete
 * uses: a baseline or read-back row against the canonical value, and a read-back row against the
 * baseline row at the same position.
 *
 * Two scalars compare as strings, the way core's REST layer compares a stored value with a
 * requested one. Any other pair, where either side is an array, an object or null, is equal only
 * when serialize() of both values is identical. That compares by value and type, never by object
 * instance, so a stored object equals a second decode of the same row, and null equals only null.
 * A value serialize() refuses, such as a closure, a SimpleXMLElement or an anonymous-class
 * instance, which only a filter can supply, equals no value, not even itself: the comparison
 * returns false and never throws.
 *
 * @param mixed $canonical Canonical value, or the baseline row a read-back row is compared with.
 * @param mixed $candidate Value read from storage.
 * @return bool
 */
function aafm_meta_value_equals( $canonical, $candidate ): bool {
	if ( is_scalar( $canonical ) && is_scalar( $candidate ) ) {
		return (string) $candidate === (string) $canonical;
	}
	try {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a by-value comparison of two values already in memory; nothing is stored or unserialized.
		return serialize( $candidate ) === serialize( $canonical );
	} catch ( \Throwable $e ) {
		unset( $e ); // A value serialize() refuses equals nothing.
		return false;
	}
}

/**
 * Write a metadata key and report what actually happened: written, unchanged, deleted, absent,
 * refused, read_failed or unconfirmed.
 *
 * @param string $type        'post', 'term' or 'user'.
 * @param int    $id          Object id.
 * @param string $key         Meta key.
 * @param mixed  $intended    The unslashed value to store.
 * @param string $subtype     Object subtype (post type, taxonomy, or '' for user).
 * @param bool   $scalar_only Whether a non-scalar canonical value is a validation error.
 * @return array<string,mixed>|WP_Error
 */
function aafm_meta_set( string $type, int $id, string $key, $intended, string $subtype = '', bool $scalar_only = true ) {
	$canonical = sanitize_meta( $key, $intended, $type, $subtype );

	if ( $scalar_only && ( ! is_scalar( $intended ) || ! is_scalar( $canonical ) ) ) {
		return new WP_Error( 'aafm_meta_value_invalid', __( 'Only text, number, or boolean meta values are supported.', 'agent-abilities-for-mcp' ) );
	}

	wp_cache_delete( $id, $type . '_meta' );
	$baseline = aafm_meta_row( $type, $id, $key );
	if ( ! $baseline['ok'] ) {
		$result = array( 'status' => AAFM_WRITE_READ_FAILED );
		aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
		return $result;
	}

	if ( $baseline['aliased'] > 0 ) {
		// The collation matched a row stored under another spelling: refuse before core can act on
		// it, and return nothing read from it.
		$result = array( 'status' => AAFM_WRITE_REFUSED );
		aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
		return $result;
	}

	$rows = $baseline['count'] > 1 ? $baseline['count'] : null;

	if ( $baseline['exists'] ) {
		$all_match = true;
		foreach ( $baseline['values'] as $value ) {
			if ( ! aafm_meta_value_equals( $canonical, $value ) ) {
				$all_match = false;
				break;
			}
		}
		if ( $all_match ) {
			$result = array(
				'status'   => AAFM_WRITE_UNCHANGED,
				'value'    => $baseline['value'],
				'previous' => $baseline['value'],
			);
			if ( null !== $rows ) {
				$result['rows'] = $rows;
			}
			aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
			return $result;
		}
	}

	$acknowledged = (bool) update_metadata( $type, $id, wp_slash( $key ), wp_slash( $intended ) );

	if ( ! $acknowledged ) {
		// Core loaded the object's meta for its old-value check and returns before its own cache
		// delete when the write fails, so that set is dropped here.
		wp_cache_delete( $id, $type . '_meta' );
		$result = array(
			'status'       => AAFM_WRITE_REFUSED,
			'acknowledged' => false,
		);
		if ( null !== $rows ) {
			$result['rows'] = $rows;
		}
		if ( $baseline['exists'] ) {
			$result['previous'] = $baseline['value'];
		}
		aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
		return $result;
	}

	$readback = aafm_meta_readback( $type, $id, $key );

	$result = array(
		'acknowledged' => true,
		'observed'     => array(
			'exists' => $readback['exists'],
			'count'  => $readback['count'],
		),
	);
	if ( null !== $rows ) {
		$result['rows'] = $rows;
	}
	if ( $baseline['exists'] ) {
		$result['previous'] = $baseline['value'];
	}

	if ( ! $readback['exists'] ) {
		$result['status'] = AAFM_WRITE_UNCONFIRMED;
		aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
		return $result;
	}

	$every_matches = true;
	foreach ( $readback['values'] as $value ) {
		if ( ! aafm_meta_value_equals( $canonical, $value ) ) {
			$every_matches = false;
			break;
		}
	}
	if ( $every_matches ) {
		$result['status'] = AAFM_WRITE_WRITTEN;
		$result['value']  = $readback['values'][0];
		aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
		return $result;
	}

	// Nothing moved: the same number of rows, each observed row equal to the baseline row at the
	// same position, both lists in meta id order.
	$nothing_moved = count( $readback['values'] ) === count( $baseline['values'] );
	if ( $nothing_moved ) {
		foreach ( array_values( $readback['values'] ) as $position => $value ) {
			if ( ! aafm_meta_value_equals( $baseline['values'][ $position ], $value ) ) {
				$nothing_moved = false;
				break;
			}
		}
	}
	if ( $nothing_moved ) {
		$result['status'] = AAFM_WRITE_UNCONFIRMED;
		aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
		return $result;
	}

	$result['status']           = AAFM_WRITE_WRITTEN;
	$result['value']            = $readback['values'][0];
	$result['modified_by_site'] = true;
	aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
	return $result;
}

/**
 * The emitted target for a metadata writer or delete outcome: kind post_meta/term_meta/user_meta,
 * no entity, the object id, the meta key.
 *
 * @param string $type Object type.
 * @param int    $id   Object id.
 * @param string $key  Meta key.
 * @return array{kind: string, entity: null, object_id: int, key: string}
 */
function aafm_meta_write_target( string $type, int $id, string $key ): array {
	return array(
		'kind'      => $type . '_meta',
		'entity'    => null,
		'object_id' => $id,
		'key'       => $key,
	);
}

/**
 * Delete every row of a metadata key and report what happened: deleted, absent, refused or
 * read_failed.
 *
 * @param string $type 'post', 'term' or 'user'.
 * @param int    $id   Object id.
 * @param string $key  Meta key.
 * @return array<string,mixed>
 */
function aafm_meta_delete( string $type, int $id, string $key ): array {
	wp_cache_delete( $id, $type . '_meta' );
	$baseline = aafm_meta_row( $type, $id, $key );
	if ( ! $baseline['ok'] ) {
		$result = array( 'status' => AAFM_WRITE_READ_FAILED );
		aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
		return $result;
	}

	if ( $baseline['aliased'] > 0 ) {
		// The collation matched a row stored under another spelling: refuse before core can act on
		// it, and return nothing read from it.
		$result = array( 'status' => AAFM_WRITE_REFUSED );
		aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
		return $result;
	}

	$rows = $baseline['count'] > 1 ? $baseline['count'] : null;

	if ( ! $baseline['exists'] ) {
		$result = array( 'status' => AAFM_WRITE_ABSENT );
		aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
		return $result;
	}

	$acknowledged = (bool) delete_metadata( $type, $id, wp_slash( $key ) );

	if ( ! $acknowledged ) {
		$result = array(
			'status'       => AAFM_WRITE_REFUSED,
			'acknowledged' => false,
			'previous'     => $baseline['value'],
		);
		if ( null !== $rows ) {
			$result['rows'] = $rows;
		}
		aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
		return $result;
	}

	$readback = aafm_meta_readback( $type, $id, $key );

	$result = array(
		'acknowledged' => true,
		'previous'     => $baseline['value'],
		'observed'     => array(
			'exists' => $readback['exists'],
			'count'  => $readback['count'],
		),
	);
	if ( null !== $rows ) {
		$result['rows'] = $rows;
	}

	$result['status'] = ( 0 === $readback['count'] ) ? AAFM_WRITE_DELETED : AAFM_WRITE_REFUSED;
	aafm_emit_write_outcome( $result, aafm_meta_write_target( $type, $id, $key ) );
	return $result;
}

/**
 * Write several metadata keys on one object, reusing a single preflight baseline.
 *
 * Every member's value is canonicalised and validated first; a validation failure on any member
 * returns that member's WP_Error for the whole group before anything is read or written. When two
 * requested keys are one key under the meta_key column's collation, every member is refused with
 * nothing written, and when that comparison fails every member is read_failed. The object's meta
 * cache is then cleared once and every baseline is read in one query; a failed preflight reports
 * read_failed for every key with nothing written. Members are then written in order, continuing
 * past a failed key so every key gets a status, and a member whose key matched a row stored under
 * another spelling is refused as aafm_meta_set() refuses it.
 *
 * PHP stores a numeric-string array key such as '123' as an int, so each key, in $intended_by_key
 * and in $array_keys, is cast back to a string before any check or call uses it. For the same
 * reason the returned `keys` map can hold int keys: a caller that passes one on casts it with
 * (string), and a caller that puts the map on the wire encodes it as a JSON object, never a list.
 *
 * @param string                 $type            'post', 'term' or 'user'.
 * @param int                    $id              Object id.
 * @param array<string,mixed>    $intended_by_key Meta key => intended value.
 * @param string                 $subtype         Object subtype.
 * @param array<int,string|int>  $array_keys      Members that may hold a non-scalar (array) value.
 * @param array<array-key,mixed> $absent_defaults Meta key => the value the site stores as no row.
 *                                               A member whose value equals its mapped value is
 *                                               unchanged when no row exists, and written when
 *                                               the row is gone after the write and a
 *                                               failure-aware read confirms it.
 * @return array{status: string, keys: array<array-key, array<string,mixed>>}|WP_Error
 */
function aafm_meta_set_group( string $type, int $id, array $intended_by_key, string $subtype = '', array $array_keys = array(), array $absent_defaults = array() ) {
	$array_keys = array_map( 'strval', $array_keys );

	$members = array();
	foreach ( $intended_by_key as $key => $intended ) {
		$key         = (string) $key;
		$scalar_only = ! in_array( $key, $array_keys, true );
		$canonical   = sanitize_meta( $key, $intended, $type, $subtype );
		if ( $scalar_only && ( ! is_scalar( $intended ) || ! is_scalar( $canonical ) ) ) {
			return new WP_Error( 'aafm_meta_value_invalid', __( 'Only text, number, or boolean meta values are supported.', 'agent-abilities-for-mcp' ) );
		}
		$members[] = array(
			'key'         => $key,
			'intended'    => $intended,
			'canonical'   => $canonical,
			'scalar_only' => $scalar_only,
		);
	}

	// Two keys the column's collation treats as one would have core act on the same rows twice
	// from one stale baseline, so the whole group refuses before any read of the rows.
	$collide = aafm_meta_keys_collide( $type, array_column( $members, 'key' ) );
	if ( true === $collide ) {
		$keys = array();
		foreach ( $members as $member ) {
			$entry                  = array( 'status' => AAFM_WRITE_REFUSED );
			$keys[ $member['key'] ] = $entry;
			aafm_emit_write_outcome( $entry, aafm_meta_write_target( $type, $id, $member['key'] ) );
		}
		return array(
			'status' => AAFM_WRITE_REFUSED,
			'keys'   => $keys,
		);
	}

	wp_cache_delete( $id, $type . '_meta' );
	$preflight = null === $collide ? null : aafm_meta_rows( $type, $id, array_column( $members, 'key' ) );
	if ( null === $preflight || ! $preflight['ok'] ) {
		$keys = array();
		foreach ( $members as $member ) {
			$entry                  = array( 'status' => AAFM_WRITE_READ_FAILED );
			$keys[ $member['key'] ] = $entry;
			aafm_emit_write_outcome( $entry, aafm_meta_write_target( $type, $id, $member['key'] ) );
		}
		return array(
			'status' => AAFM_WRITE_READ_FAILED,
			'keys'   => $keys,
		);
	}

	$keys        = array();
	$any_written = false;
	$any_ok      = false;
	$first_bad   = null;

	foreach ( $members as $member ) {
		$key      = $member['key'];
		$baseline = $preflight['by_key'][ $key ];
		$rows     = $baseline['count'] > 1 ? $baseline['count'] : null;

		$entry = aafm_meta_set_group_member( $type, $id, $key, $member['intended'], $member['canonical'], $baseline, $rows, $member['scalar_only'], $absent_defaults );

		$keys[ $key ] = $entry;
		aafm_emit_write_outcome( $entry, aafm_meta_write_target( $type, $id, $key ) );

		if ( in_array( $entry['status'], array( AAFM_WRITE_WRITTEN, AAFM_WRITE_UNCHANGED ), true ) ) {
			$any_ok = true;
		}
		if ( AAFM_WRITE_WRITTEN === $entry['status'] ) {
			$any_written = true;
		} elseif ( AAFM_WRITE_UNCHANGED !== $entry['status'] && null === $first_bad ) {
			$first_bad = $entry['status'];
		}
	}

	if ( $any_ok && null === $first_bad ) {
		$status = $any_written ? AAFM_WRITE_WRITTEN : AAFM_WRITE_UNCHANGED;
	} elseif ( $any_written ) {
		$status = AAFM_WRITE_PARTIAL;
	} else {
		$status = $first_bad ?? AAFM_WRITE_UNCHANGED;
	}

	return array(
		'status' => $status,
		'keys'   => $keys,
	);
}

/**
 * One group member's write, reusing a baseline the preflight already read.
 *
 * @param string                 $type      Object type.
 * @param int                    $id        Object id.
 * @param string                 $key       Meta key.
 * @param mixed                  $intended  Intended value.
 * @param mixed                  $canonical Canonical form of the intended value.
 * @param array<string,mixed>    $baseline  This key's preflight baseline.
 * @param int|null               $rows      Baseline row count above 1, or null.
 * @param bool                   $scalar_only Whether this member is scalar-only.
 * @param array<array-key,mixed> $absent_defaults Meta key => the value the site stores as no row.
 * @return array<string,mixed>
 */
function aafm_meta_set_group_member( string $type, int $id, string $key, $intended, $canonical, array $baseline, ?int $rows, bool $scalar_only, array $absent_defaults = array() ): array {
	unset( $scalar_only );

	if ( $baseline['aliased'] > 0 ) {
		return array( 'status' => AAFM_WRITE_REFUSED );
	}

	// A site can store one value of a key as no row at all (Yoast SEO deletes a field set to its
	// default). For such a value, no row is the answer the write asked for.
	$declared = array_key_exists( $key, $absent_defaults ) && aafm_meta_value_equals( $absent_defaults[ $key ], $canonical );

	if ( $declared && ! $baseline['exists'] ) {
		return array(
			'status' => AAFM_WRITE_UNCHANGED,
			'value'  => $absent_defaults[ $key ],
		);
	}

	if ( $baseline['exists'] ) {
		$all_match = true;
		foreach ( $baseline['values'] as $value ) {
			if ( ! aafm_meta_value_equals( $canonical, $value ) ) {
				$all_match = false;
				break;
			}
		}
		if ( $all_match ) {
			$result = array(
				'status'   => AAFM_WRITE_UNCHANGED,
				'value'    => $baseline['value'],
				'previous' => $baseline['value'],
			);
			if ( null !== $rows ) {
				$result['rows'] = $rows;
			}
			return $result;
		}
	}

	$acknowledged = (bool) update_metadata( $type, $id, wp_slash( $key ), wp_slash( $intended ) );

	if ( ! $acknowledged ) {
		// Core loaded the object's meta for its old-value check and returns before its own cache
		// delete when the write fails, so that set is dropped here.
		wp_cache_delete( $id, $type . '_meta' );
		$result = array(
			'status'       => AAFM_WRITE_REFUSED,
			'acknowledged' => false,
		);
		if ( null !== $rows ) {
			$result['rows'] = $rows;
		}
		if ( $baseline['exists'] ) {
			$result['previous'] = $baseline['value'];
		}
		return $result;
	}

	$readback = aafm_meta_readback( $type, $id, $key );

	$result = array(
		'acknowledged' => true,
		'observed'     => array(
			'exists' => $readback['exists'],
			'count'  => $readback['count'],
		),
	);
	if ( null !== $rows ) {
		$result['rows'] = $rows;
	}
	if ( $baseline['exists'] ) {
		$result['previous'] = $baseline['value'];
	}

	if ( ! $readback['exists'] ) {
		// Core cannot tell a failed read-back from no row, so a declared value is certified only
		// when a failure-aware read also finds no row. A failed read never certifies it.
		if ( $declared ) {
			$confirm = aafm_meta_row( $type, $id, $key );
			if ( $confirm['ok'] && ! $confirm['exists'] ) {
				$result['status'] = AAFM_WRITE_WRITTEN;
				$result['value']  = $absent_defaults[ $key ];
				return $result;
			}
		}
		$result['status'] = AAFM_WRITE_UNCONFIRMED;
		return $result;
	}

	$every_matches = true;
	foreach ( $readback['values'] as $value ) {
		if ( ! aafm_meta_value_equals( $canonical, $value ) ) {
			$every_matches = false;
			break;
		}
	}
	if ( $every_matches ) {
		$result['status'] = AAFM_WRITE_WRITTEN;
		$result['value']  = $readback['values'][0];
		return $result;
	}

	// Nothing moved: the same number of rows, each observed row equal to the baseline row at the
	// same position, both lists in meta id order.
	$nothing_moved = count( $readback['values'] ) === count( $baseline['values'] );
	if ( $nothing_moved ) {
		foreach ( array_values( $readback['values'] ) as $position => $value ) {
			if ( ! aafm_meta_value_equals( $baseline['values'][ $position ], $value ) ) {
				$nothing_moved = false;
				break;
			}
		}
	}
	if ( $nothing_moved ) {
		$result['status'] = AAFM_WRITE_UNCONFIRMED;
		return $result;
	}

	$result['status']           = AAFM_WRITE_WRITTEN;
	$result['value']            = $readback['values'][0];
	$result['modified_by_site'] = true;
	return $result;
}

/**
 * Write a core or foreign option and report what happened, by the option rule: true from
 * update_option() is written; on false, the option's own database row (never get_option(), whose
 * option_{name} filters can answer with something other than the row) decides unchanged, refused
 * or unconfirmed.
 *
 * @param string              $option Option name.
 * @param mixed               $value  Value to store.
 * @param array<string,mixed> $target Optional override of the emitted target ({kind, entity, object_id, key}).
 * @return array<string,mixed>
 */
function aafm_option_write( string $option, $value, array $target = array() ): array {
	$written = update_option( $option, $value );

	if ( $written ) {
		$result = array(
			'status'   => AAFM_WRITE_WRITTEN,
			'returned' => true,
		);
	} else {
		$views = aafm_read_option_views( $option );
		if ( $views['db_error'] ) {
			$result = array(
				'status'   => AAFM_WRITE_UNCONFIRMED,
				'returned' => false,
			);
		} else {
			$canonical = sanitize_option( $option, $value );
			$stored    = $views['db_found'] ? $views['db_value'] : false;
			$result    = aafm_option_value_matches( $stored, $canonical )
				? array(
					'status'   => AAFM_WRITE_UNCHANGED,
					'returned' => false,
				)
				: array(
					'status'   => AAFM_WRITE_REFUSED,
					'returned' => false,
				);
		}
	}

	$emit_target = array_merge(
		array(
			'kind'      => 'option',
			'entity'    => null,
			'object_id' => null,
			'key'       => $option,
		),
		$target
	);
	aafm_emit_write_outcome( $result, $emit_target );

	return $result;
}

/**
 * Confirm a post-field write and emit its outcome, without changing anything about the
 * confirmation itself: calls the untouched aafm_post_field_write_confirmed() with the same
 * arguments and returns its bool unchanged.
 *
 * @param int      $post_id             Post id.
 * @param string   $field               Post field name.
 * @param string   $intended            The unslashed value the write attempted to persist.
 * @param string   $old                 The field's value before the write ran.
 * @param int|null $sanitize_context_id The id to recompute the canonical form with.
 * @return bool
 */
function aafm_post_field_confirm_logged( int $post_id, string $field, string $intended, string $old, ?int $sanitize_context_id = null ): bool {
	$confirmed = aafm_post_field_write_confirmed( $post_id, $field, $intended, $old, $sanitize_context_id );

	aafm_emit_write_outcome(
		array( 'status' => $confirmed ? AAFM_WRITE_WRITTEN : AAFM_WRITE_UNCONFIRMED ),
		array(
			'kind'      => 'post_field',
			'entity'    => null,
			'object_id' => $post_id,
			'key'       => $field,
		)
	);

	return $confirmed;
}

/**
 * The one emission point every writer calls once its status is decided.
 *
 * Writes the WP_DEBUG diagnostic line and fires aafm_write_completed for every status, so the log
 * observer and any other listener see one call per outcome, never more.
 *
 * @param array<string,mixed> $result The write's result: status, plus whichever of value, previous,
 *                                    rows, acknowledged, observed, modified_by_site, keys and
 *                                    returned apply to that status.
 * @param array<string,mixed> $target {kind, entity, object_id, key}.
 * @return void
 */
function aafm_emit_write_outcome( array $result, array $target ): void {
	// Under WP_DEBUG alone: a verbose line for whoever is actively debugging, carrying identifiers
	// only, never a value. The key goes through the same allowlist the log row uses, so a malformed
	// key, including one that ends in a newline, prints as `-`.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$key = aafm_activity_detail_field( 'key', $target['key'] ?? null );
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated behind WP_DEBUG, identifiers only, never a value.
			sprintf(
				'aafm write_outcome status=%1$s kind=%2$s object_id=%3$s key=%4$s rows=%5$s',
				(string) ( $result['status'] ?? '' ),
				(string) ( $target['kind'] ?? '' ),
				isset( $target['object_id'] ) ? (string) $target['object_id'] : '',
				null !== $key ? $key : '-',
				isset( $result['rows'] ) ? (string) $result['rows'] : ''
			)
		);
	}

	// Observers get a detached copy, so nothing they do to their arguments reaches the result the
	// writer returns: each entry is round-tripped through serialize(), which copies nested objects
	// too. An entry serialize() refuses, or whose round trip throws, reaches observers as null with
	// its key kept. `returned` is not round-tripped, so a vendor object is never re-created: a
	// scalar or null passes as is, and an array or object reaches observers as null.
	$copy = array();
	foreach ( $result as $name => $entry ) {
		if ( 'returned' === $name ) {
			$copy[ $name ] = ( null === $entry || is_scalar( $entry ) ) ? $entry : null;
			continue;
		}
		try {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a deep copy of a value already in memory, never input from outside this request.
			$copy[ $name ] = unserialize( serialize( $entry ) );
		} catch ( \Throwable $e ) {
			unset( $e );
			$copy[ $name ] = null;
		}
	}

	/**
	 * Fires once a write's outcome is decided, for every status.
	 *
	 * @param array $result A detached copy of the write's result (see aafm_emit_write_outcome()'s own docblock).
	 * @param array $target {kind, entity, object_id, key}.
	 */
	try {
		do_action( 'aafm_write_completed', $copy, $target );
	} catch ( \Throwable $e ) {
		unset( $e ); // An observer's own failure must never change the write's already-decided result.
	}
}

/**
 * The write-outcome log observer: one activity-log row per emission, carrying identifiers only.
 *
 * Registered on aafm_write_completed when this file loads, at the earliest possible priority so a
 * listener registered anywhere else that throws can never stop this row. Detached during uninstall
 * teardown and by the test fixture (tests/TestCase.php), and nowhere else.
 *
 * @param array<string,mixed> $result The write's result (see aafm_emit_write_outcome()'s docblock).
 * @param array<string,mixed> $target {kind, entity, object_id, key}.
 * @return void
 */
function aafm_activity_log_write_outcome( array $result, array $target ): void {
	// A one-time site migration on plugins_loaded (aafm_oauth_preserve_toggle_on_upgrade(),
	// aafm_oauth_dcr_adopt_on_by_default()) can write an option before this plugin's own
	// activation has ever run - the PHPUnit bootstrap hits this on its very first request, ahead
	// of any test's own aafm_install_activity_log() call. On every real site the table already
	// exists by then, because activation creates it before plugins_loaded ever fires again; this
	// guard only protects the one bootstrap ordering that skips activation outright.
	if ( ! aafm_activity_log_table_present( aafm_activity_log_table() ) ) {
		return;
	}

	$status = (string) ( $result['status'] ?? '' );

	$success_statuses = array( AAFM_WRITE_WRITTEN, AAFM_WRITE_UNCHANGED, AAFM_WRITE_DELETED, AAFM_WRITE_ABSENT, AAFM_WRITE_ACCEPTED );
	$log_status       = in_array( $status, $success_statuses, true ) ? 'success' : 'error';

	$kinds    = array_keys( aafm_write_writers() );
	$entities = array( 'post', 'user', 'term', 'option', 'event', 'venue', 'organizer', 'product', 'variation', 'coupon', 'attribute', 'customer', 'shipping_zone', 'shipping_method', 'payment_gateway', 'tax_rate', 'tax_class', 'order', 'order_item', 'order_note', 'order_refund' );
	$statuses = array( AAFM_WRITE_WRITTEN, AAFM_WRITE_UNCHANGED, AAFM_WRITE_DELETED, AAFM_WRITE_ABSENT, AAFM_WRITE_REFUSED, AAFM_WRITE_READ_FAILED, AAFM_WRITE_UNCONFIRMED, AAFM_WRITE_PARTIAL, AAFM_WRITE_ACCEPTED );

	$key_field   = aafm_activity_detail_field( 'key', $target['key'] ?? null );
	$key_omitted = ( isset( $target['key'] ) && null === $key_field );

	$detail = array(
		'kind'             => aafm_activity_detail_field( 'enum', $target['kind'] ?? null, $kinds ),
		'entity'           => aafm_activity_detail_field( 'enum', $target['entity'] ?? null, $entities ),
		'object_id'        => aafm_activity_detail_field( 'id', $target['object_id'] ?? null ),
		'key'              => $key_field,
		'status'           => aafm_activity_detail_field( 'enum', $status, $statuses ),
		'rows'             => aafm_activity_detail_field( 'count', $result['rows'] ?? null ),
		'modified_by_site' => ! empty( $result['modified_by_site'] ),
		'key_omitted'      => $key_omitted,
	);

	// Resolving the current user before init has fired settles it ahead of core knowing whether this
	// is even a REST request, and a request that authenticates by application password only checks
	// for one from then on - so a write this early is logged as a system write, principal 0, rather
	// than risk caching "nobody" for a request that has not been authenticated yet.
	if ( did_action( 'init' ) ) {
		$user              = wp_get_current_user();
		$principal_user_id = (int) $user->ID;
		$principal_login   = $user->user_login ? (string) $user->user_login : '';
		$client_id         = function_exists( 'aafm_oauth_current_client_id' ) ? aafm_oauth_current_client_id() : '';
	} else {
		$principal_user_id = 0;
		$principal_login   = '';
		$client_id         = '';
	}

	aafm_log_activity(
		array(
			'ability'           => 'aafm/write-outcome',
			'principal_user_id' => $principal_user_id,
			'principal_login'   => $principal_login,
			'status'            => $log_status,
			'client_id'         => $client_id,
			'event_type'        => 'write_outcome',
			'detail'            => wp_json_encode( $detail ),
		)
	);
}
add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
