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
			// Length first: a large serialized value is refused before it is decoded.
			if ( strlen( (string) $raw ) > AAFM_UNDO_MAX_CHANGE_BYTES ) {
				return 'too_large';
			}
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
 * The AIOSEO columns a change records, keyed by the savePost() patch key that writes each one.
 *
 * The patch key and the column are the same name except for the three robots flags, whose patch keys
 * are the bare words default, noindex and nofollow.
 *
 * @return array<string,string> Patch key to column.
 */
function aafm_undo_aioseo_columns(): array {
	if ( ! function_exists( 'aafm_aioseo_patch_keys' ) ) {
		return array();
	}
	$renamed = array(
		'default'  => 'robots_default',
		'noindex'  => 'robots_noindex',
		'nofollow' => 'robots_nofollow',
	);
	$columns = array();
	foreach ( aafm_aioseo_patch_keys() as $key ) {
		$columns[ $key ] = $renamed[ $key ] ?? $key;
	}
	return $columns;
}

/**
 * The AIOSEO table, which AIOSEO names with the site's own prefix.
 *
 * @return string
 */
function aafm_undo_aioseo_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'aioseo_posts';
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
		'post'   => array(),
		'meta'   => array(),
		'terms'  => array(),
		'term'   => array(),
		'aioseo' => array(),
	);
	foreach ( $field_ids as $field ) {
		$kind = (string) strstr( $field, ':', true );
		if ( 'fp' === $kind ) {
			$fingerprint = aafm_undo_object_fingerprint( $object_id, 'fp:core' !== $field );
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

	if ( array() !== $groups['aioseo'] ) {
		$known = array_values( aafm_undo_aioseo_columns() );
		foreach ( $groups['aioseo'] as $column ) {
			if ( ! in_array( $column, $known, true ) ) {
				return null;
			}
		}
		$columns = array_values( $groups['aioseo'] );
		$sql     = 'SELECT ' . implode( ', ', array_fill( 0, count( $columns ), '%i' ) ) . ' FROM %i WHERE post_id = %d';
		// A site without AIOSEO's table is the same as a post without its row: nothing to restore to.
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholders are generated from the closed column list above.
		$row      = aafm_wpdb_row( $wpdb->prepare( $sql, array_merge( $columns, array( aafm_undo_aioseo_table(), $object_id ) ) ) );
		$no_table = ! $row['ok'] && false !== stripos( (string) $wpdb->last_error, "doesn't exist" );
		$wpdb->suppress_errors( $suppressed );
		if ( ! $row['ok'] && ! $no_table ) {
			return null;
		}
		foreach ( $groups['aioseo'] as $field => $column ) {
			if ( ! is_array( $row['value'] ) ) {
				// AIOSEO's savePost() cannot put back "no row", and a row of defaults would be a false revert.
				$values[ $field ] = null;
				$flags[ $field ]  = 'no_vendor_row';
				continue;
			}
			$values[ $field ] = null === $row['value'][ $column ] ? null : (string) $row['value'][ $column ];
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
 * @param int  $post_id   Post id.
 * @param bool $with_meta False leaves the meta rows out (the fp:core field), so a later meta-only change can be told apart.
 * @return string|null
 */
function aafm_undo_object_fingerprint( int $post_id, bool $with_meta = true ): ?string {
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
	if ( $with_meta ) {
		$meta = aafm_meta_object_fingerprint( 'post', $post_id, array( '_edit_lock', '_encloseme', '_pingme' ) );
		if ( null === $meta ) {
			return null;
		}
		$parts['meta'] = $meta;
	}
	$json  = wp_json_encode( $parts );
	$bytes = is_string( $json ) ? strlen( $json ) : 0;
	return ( is_string( $json ) && $bytes <= 8 * 1024 * 1024 ) ? hash( 'sha256', $json ) : null;
}

/**
 * The fields whose difference after a restore is reported, not counted as a miss.
 *
 * @return string[]
 */
function aafm_undo_report_only_fields(): array {
	return array( 'post:post_name', 'post:post_status', 'post:post_date', 'post:post_date_gmt', 'term:parent' );
}

/**
 * Whether AIOSEO stored a value in a form it normalised from the one that was meant to land: the same
 * text trimmed, a URL with a different trailing slash or encoding, or an empty string where the row
 * held NULL. Reported as a difference, never as an exact match.
 *
 * @param string $field  Field id.
 * @param mixed  $read   What the table holds now.
 * @param mixed  $wanted What was meant to land.
 * @return bool
 */
function aafm_undo_aioseo_normalised( string $field, $read, $wanted ): bool {
	if ( 0 !== strpos( $field, 'aioseo:' ) ) {
		return false;
	}
	if ( null === $wanted ) {
		return '' === $read;
	}
	if ( ! is_string( $read ) || ! is_string( $wanted ) ) {
		return false;
	}
	$is_url = false !== strpos( $field, 'url' );
	return function_exists( 'aafm_aioseo_value_persisted' ) && aafm_aioseo_value_persisted( $read, $wanted, $is_url );
}

/**
 * Compare what a restore wanted with what the database holds now.
 *
 * Exactly certified: titles, content, excerpts, meta rows, term id lists and term name and
 * description. Reported only: slug, status, dates and a term parent core may normalise.
 *
 * @param string               $object_type 'post' or 'term'.
 * @param int                  $object_id   Object id.
 * @param array<string,mixed>  $values      Field id to the value that was meant to land.
 * @param array<string,string> $context     taxonomy for a term object.
 * @return array{certified: string[], missed: string[], differences: string[], read: array<string,mixed>|null}
 */
function aafm_undo_certify( string $object_type, int $object_id, array $values, array $context = array() ): array {
	$read = aafm_undo_read_fields( $object_type, $object_id, array_keys( $values ), $context );
	if ( null === $read ) {
		return array(
			'certified'   => array(),
			'missed'      => array_keys( $values ),
			'differences' => array(),
			'read'        => null,
		);
	}
	$certified   = array();
	$missed      = array();
	$differences = array();
	foreach ( $values as $field => $wanted ) {
		if ( $read['values'][ $field ] === $wanted ) {
			$certified[] = $field;
		} elseif ( aafm_undo_aioseo_normalised( $field, $read['values'][ $field ], $wanted ) ) {
			$differences[] = $field;
		} elseif ( in_array( $field, aafm_undo_report_only_fields(), true ) ) {
			$differences[] = $field;
		} else {
			$missed[] = $field;
		}
	}
	return array(
		'certified'   => $certified,
		'missed'      => $missed,
		'differences' => $differences,
		'read'        => $read['values'],
	);
}

/**
 * Put AIOSEO columns back through its own model, the way the forward tool writes them.
 *
 * The model's savePost() reads the title and description of its patch unconditionally, so when the restore does not
 * carry them the current values go along and are written back unchanged.
 *
 * @param int                 $post_id Post id.
 * @param array<string,mixed> $columns Column to the value that was there (strings, or null for SQL NULL).
 * @return bool False when a companion value could not be read, in which case nothing is written: a guessed
 *              title or description would overwrite a field the change never touched.
 */
function aafm_undo_write_aioseo( int $post_id, array $columns ): bool {
	$patch_keys = array_flip( aafm_undo_aioseo_columns() );
	$flags      = array( 'robots_default', 'robots_noindex', 'robots_nofollow', 'twitter_use_og' );
	$data       = array();
	foreach ( $columns as $column => $value ) {
		if ( isset( $patch_keys[ $column ] ) ) {
			$data[ $patch_keys[ $column ] ] = in_array( $column, $flags, true ) && null !== $value ? '1' === (string) $value : $value;
		}
	}
	foreach ( array( 'title', 'description' ) as $key ) {
		if ( ! array_key_exists( $key, $data ) ) {
			$now = aafm_undo_read_fields( 'post', $post_id, array( 'aioseo:' . $key ) );
			if ( null === $now ) {
				return false;
			}
			$data[ $key ] = (string) $now['values'][ 'aioseo:' . $key ];
		}
	}
	aafm_aioseo_write( $post_id, $data );
	aafm_collect_meta_touch( 'post', $post_id );
	return true;
}

/**
 * Write the given before-values back and certify what landed.
 *
 * Post columns go through one wp_update_post() (an excerpt-only change on a builder-owned post goes
 * through the pinned excerpt path), terms through wp_set_object_terms() after their caches are
 * dropped, term columns through wp_update_term(), meta rows through the write contract's exact restore.
 *
 * @param string               $object_type 'post' or 'term'.
 * @param int                  $object_id   Object id.
 * @param array<string,mixed>  $values      Field id to before-value.
 * @param array<string,string> $context     taxonomy for a term object.
 * @param bool                 $builder_excerpt True for an excerpt-only restore on a builder-owned post.
 * @return array{certified: string[], missed: string[], differences: string[], read: array<string,mixed>|null}
 */
function aafm_undo_write_fields( string $object_type, int $object_id, array $values, array $context = array(), bool $builder_excerpt = false ): array {
	$post_cols = array();
	$aioseo    = array();
	foreach ( $values as $field => $value ) {
		$kind = (string) strstr( $field, ':', true );
		$name = substr( $field, strlen( $kind ) + 1 );
		if ( 'post' === $kind ) {
			$post_cols[ $name ] = $value;
		} elseif ( 'aioseo' === $kind ) {
			$aioseo[ $name ] = $value;
		} elseif ( 'terms' === $kind ) {
			$post = aafm_exact_object_chain( 'post', $object_id );
			if ( $post instanceof WP_Post ) {
				clean_object_term_cache( $object_id, $post->post_type );
				wp_cache_set_terms_last_changed();
				wp_set_object_terms( $object_id, array_map( 'intval', (array) $value ), $name );
			}
		} elseif ( 'meta' === $kind ) {
			aafm_meta_restore_rows( $object_type, $object_id, $name, array_map( 'strval', (array) $value ) );
			// Rank Math's sitemap reads rank_math_robots but only invalidates on a post save, so a bare
			// meta write needs the same nudge the forward tool gives (rankmath.php).
			if ( 'rank_math_robots' === $name && 'post' === $object_type && class_exists( 'RankMath\\Sitemap\\Cache_Watcher' ) ) {
				\RankMath\Sitemap\Cache_Watcher::invalidate_post( $object_id );
			}
		}
	}

	if ( array() !== $aioseo ) {
		aafm_undo_write_aioseo( $object_id, $aioseo );
	}

	if ( array() !== $post_cols ) {
		$post = aafm_exact_object_chain( 'post', $object_id );
		if ( $post instanceof WP_Post ) {
			$postarr = array_merge( array( 'ID' => $object_id ), $post_cols );
			$pinned  = $builder_excerpt ? aafm_page_builder_excerpt_start( $post ) : null;
			if ( is_array( $pinned ) ) {
				aafm_page_builder_write_excerpt( $object_id, $postarr, $pinned );
			} elseif ( ! $builder_excerpt ) {
				wp_update_post( wp_slash( $postarr ), true );
			}
		}
	}

	$term_cols = array();
	foreach ( $values as $field => $value ) {
		if ( 0 === strpos( $field, 'term:' ) ) {
			$term_cols[ substr( $field, 5 ) ] = $value;
		}
	}
	if ( array() !== $term_cols ) {
		$taxonomy = (string) ( $context['taxonomy'] ?? '' );
		if ( aafm_exact_object_chain( 'term', $object_id, $taxonomy ) instanceof WP_Term ) {
			wp_update_term( $object_id, $taxonomy, wp_slash( $term_cols ) );
		}
	}

	return aafm_undo_certify( $object_type, $object_id, $values, $context );
}
