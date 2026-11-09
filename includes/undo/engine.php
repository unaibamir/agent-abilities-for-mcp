<?php
/**
 * The change history engine: capture around an agent write.
 *
 * The function aafm_undo_begin() runs before the tool executes and aafm_undo_finish() after it, both from the one
 * decorated execute closure in includes/register.php. A write-ahead pending row is stored before the
 * call; after it the same fields are read again, unchanged fields are dropped, and the row is
 * finished. The engine never fails or changes a write: any fault inside it leaves the write alone and
 * reports that the change cannot be undone.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The connection an agent call came in on: the OAuth client id, else the application password uuid,
 * else '' (an empty connection is recorded but never treated as owned by an agent).
 *
 * @return string
 */
function aafm_undo_connection_id(): string {
	$client = aafm_oauth_current_client_id();
	if ( '' !== $client ) {
		return $client;
	}
	if ( function_exists( 'rest_get_authenticated_app_password' ) ) {
		$uuid = rest_get_authenticated_app_password();
		if ( is_string( $uuid ) && '' !== $uuid ) {
			return 'app:' . $uuid;
		}
	}
	return '';
}

/**
 * The activity row opened for the self-recording tool now running, so its own log row can be reused.
 *
 * @param int|null $set    Row id to remember, or null to read.
 * @param bool     $forget Drop the stored id.
 * @return int
 */
function aafm_undo_current_row_id( ?int $set = null, bool $forget = false ): int {
	static $row_id = 0;
	if ( $forget ) {
		$row_id = 0;
		return 0;
	}
	if ( null !== $set ) {
		$row_id = $set;
	}
	return $row_id;
}

/**
 * Whether an ability carries the `undo` key in its output schema (set at registration).
 *
 * @param string    $name   Ability name.
 * @param bool|null $set    True to record the ability as a carrier.
 * @param bool      $forget Clear every carrier.
 * @return bool
 */
function aafm_undo_schema_carrier( string $name, ?bool $set = null, bool $forget = false ): bool {
	static $carriers = array();
	if ( $forget ) {
		$carriers = array();
		return false;
	}
	if ( null !== $set ) {
		$carriers[ $name ] = $set;
	}
	return ! empty( $carriers[ $name ] );
}

/**
 * The open replace-sitewide call, so its per-post engine calls can find the group.
 *
 * @param array<string,mixed>|null $handle Handle to store, or null to read.
 * @param bool                     $forget Drop the stored handle.
 * @return array<string,mixed>|null
 */
function aafm_undo_open_sitewide( ?array $handle = null, bool $forget = false ): ?array {
	static $open = null;
	if ( $forget ) {
		$open = null;
		return null;
	}
	if ( null !== $handle ) {
		$open = $handle;
	}
	return $open;
}

/**
 * Drop every request static of the engine (the test seam).
 *
 * @return void
 */
function aafm_undo_reset_request_state(): void {
	aafm_undo_pending_creates( null, true );
	aafm_undo_current_row_id( null, true );
	aafm_undo_open_sitewide( null, true );
}

/**
 * The `undo` key for a response.
 *
 * @param int[]       $change_ids Change ids.
 * @param string|null $reason     Reason code when the change cannot be undone.
 * @return array<string,mixed>
 */
function aafm_undo_response( array $change_ids, ?string $reason = null ): array {
	$undo = array(
		'undoable'   => null === $reason,
		'change_ids' => array_values( array_map( 'intval', $change_ids ) ),
	);
	if ( null !== $reason ) {
		$undo['reason'] = $reason;
	}
	return $undo;
}

/**
 * An engine fault: rethrow where the house says to, else write a separate value-free activity row
 * (never the call's own row) and carry on.
 *
 * @param \Throwable $e       The fault.
 * @param string     $ability Ability name.
 * @return void
 * @throws \Throwable When aafm_rethrow_ability_exceptions says so.
 */
function aafm_undo_engine_fault( \Throwable $e, string $ability ): void {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the house filter, documented in includes/register.php.
	$rethrow = apply_filters( 'aafm_rethrow_ability_exceptions', defined( 'WP_DEBUG' ) && WP_DEBUG && 0 !== strpos( $ability, 'aafm-bridge/' ), $e );
	if ( $rethrow ) {
		throw $e; // Keep programming errors loud in development.
	}
	aafm_log_activity(
		array_merge(
			aafm_undo_log_principal(),
			array(
				'ability'    => 'aafm/undo-capture',
				'status'     => 'error',
				'event_type' => 'ability_call',
				'detail'     => aafm_build_activity_detail_from_exception( $e ),
			)
		)
	);
}

/**
 * The principal fields the activity log wants for the current user.
 *
 * @return array<string,mixed>
 */
function aafm_undo_log_principal(): array {
	$user = wp_get_current_user();
	return array(
		'principal_user_id' => (int) $user->ID,
		'principal_login'   => (string) $user->user_login,
		'client_id'         => aafm_oauth_current_client_id(),
	);
}

/**
 * Value rules per field: split a read into the values to keep and the fields that cannot be kept.
 *
 * @param array{values: array<string,mixed>, flags: array<string,string>} $read A read.
 * @return array{kept: array<string,mixed>, flagged: array<string,string>, hashes: array<string,string>}
 */
function aafm_undo_apply_value_rules( array $read ): array {
	$kept    = array();
	$flagged = array();
	$hashes  = array();
	foreach ( $read['values'] as $field => $value ) {
		$reason = $read['flags'][ $field ] ?? aafm_undo_field_flag( $field, $value );
		if ( null !== $reason ) {
			$flagged[ $field ] = $reason;
		} else {
			$kept[ $field ] = $value;
		}
		$hashes[ $field ] = aafm_undo_hash( $value );
	}
	return array(
		'kept'    => $kept,
		'flagged' => $flagged,
		'hashes'  => $hashes,
	);
}

/**
 * JSON for a map of field id to value: an empty map is an object, never a list.
 *
 * @param array<string,mixed> $map Field map.
 * @return string|null
 */
function aafm_undo_json( array $map ): ?string {
	$json = array() === $map ? '{}' : wp_json_encode( $map );
	return is_string( $json ) ? $json : null;
}

/**
 * The identifier-only permission input of a surface: ids cast to int, keys as plain strings, never content.
 *
 * @param array<string,mixed> $surface Surface.
 * @param array<string,mixed> $input   Tool input.
 * @return string
 */
function aafm_undo_perm_input_json( array $surface, array $input ): string {
	$out = array();
	foreach ( (array) $surface['perm'] as $key ) {
		if ( isset( $input[ $key ] ) ) {
			$out[ $key ] = '_id' === substr( (string) $key, -3 ) ? absint( $input[ $key ] ) : (string) $input[ $key ];
		}
	}
	return (string) wp_json_encode( (object) $out );
}

/**
 * Read a change's fields, apply the value rules and store the write-ahead pending row.
 *
 * @param array<string,mixed> $base Base handle: ability, row_id, object_type, object_id, field_ids, context, undo_op, perm_input, group_id.
 * @return array<string,mixed> The handle: kind covered with a change_id, or failed.
 */
function aafm_undo_open_change( array $base ): array {
	$read = aafm_undo_read_fields( $base['object_type'], $base['object_id'], $base['field_ids'], $base['context'] );
	if ( null === $read ) {
		return array_merge( $base, array( 'kind' => 'failed' ) );
	}
	$rules = aafm_undo_apply_value_rules( $read );
	$json  = aafm_undo_json( $rules['kept'] );
	if ( null === $json ) {
		return array_merge( $base, array( 'kind' => 'failed' ) );
	}

	$change_id = aafm_undo_insert(
		array(
			'log_row_id'        => $base['row_id'],
			'group_id'          => $base['group_id'],
			'ability'           => $base['ability'],
			'source'            => 'agent',
			'principal_user_id' => get_current_user_id(),
			'client_id'         => aafm_undo_connection_id(),
			'object_type'       => $base['object_type'],
			'object_id'         => $base['object_id'],
			'undo_op'           => $base['undo_op'],
			'status'            => 'pending',
			'fields'            => wp_json_encode( array_keys( $rules['kept'] ) ),
			'perm_input'        => $base['perm_input'],
			'before_values'     => $json,
			'before_sha256'     => hash( 'sha256', $json ),
		)
	);
	if ( $change_id < 1 ) {
		return array_merge( $base, array( 'kind' => 'failed' ) );
	}

	return array_merge(
		$base,
		array(
			'kind'        => 'covered',
			'change_id'   => $change_id,
			'kept'        => $rules['kept'],
			'flagged'     => $rules['flagged'],
			'before_hash' => $rules['hashes'],
		)
	);
}

/**
 * Called before a native tool runs. Returns the handle aafm_undo_finish() needs, or null when the
 * call is not one the history cares about.
 *
 * @param string              $ability Ability name.
 * @param array<string,mixed> $input   Tool input.
 * @param int                 $row_id  The call's activity row id.
 * @return array<string,mixed>|null
 */
function aafm_undo_begin( string $ability, array $input, int $row_id ): ?array {
	try {
		return aafm_undo_begin_inner( $ability, $input, $row_id );
	} catch ( \Throwable $e ) {
		aafm_undo_engine_fault( $e, $ability );
		return array(
			'kind'    => 'failed',
			'ability' => $ability,
		);
	}
}

/**
 * The body of aafm_undo_begin().
 *
 * @param string              $ability Ability name.
 * @param array<string,mixed> $input   Tool input.
 * @param int                 $row_id  The call's activity row id.
 * @return array<string,mixed>|null
 */
function aafm_undo_begin_inner( string $ability, array $input, int $row_id ): ?array {
	aafm_undo_current_row_id( null, true ); // Each call sets its own row id below; none carries over from the last.
	if ( 0 !== strpos( $ability, 'aafm/' ) || aafm_ability_is_read( $ability ) ) {
		return null;
	}
	if ( in_array( $ability, aafm_undo_self_recording(), true ) ) {
		aafm_undo_current_row_id( $row_id );
		return null;
	}

	$surfaces = aafm_undo_surfaces();
	if ( ! isset( $surfaces[ $ability ] ) ) {
		$named = aafm_undo_named_refusals();
		return array(
			'kind'    => 'excluded',
			'ability' => $ability,
			'reason'  => $named[ $ability ] ?? 'not_covered',
		);
	}
	if ( aafm_undo_capture_off() ) {
		return array(
			'kind'    => 'history_off',
			'ability' => $ability,
		);
	}

	$surface = $surfaces[ $ability ];
	$base    = array(
		'ability'     => $ability,
		'row_id'      => $row_id,
		'object_type' => (string) $surface['object'],
		'object_id'   => '' !== $surface['id'] ? absint( $input[ $surface['id'] ] ?? 0 ) : 0,
		'field_ids'   => array_values( array_filter( (array) $surface['fields']( $input ), 'is_string' ) ),
		'context'     => isset( $surface['taxonomy'] ) ? array( 'taxonomy' => sanitize_key( (string) ( $input[ $surface['taxonomy'] ] ?? '' ) ) ) : array(),
		'undo_op'     => (string) ( $surface['undo_op'] ?? 'restore' ),
		'perm_input'  => aafm_undo_perm_input_json( $surface, $input ),
		'group_id'    => '',
	);

	if ( 'sitewide' === $surface['family'] ) {
		$handle                    = array(
			'kind'     => 'sitewide',
			'ability'  => $ability,
			'row_id'   => $row_id,
			'group_id' => wp_generate_password( 32, false, false ),
			'state'    => new \stdClass(),
		);
		$handle['state']->children = array();
		$handle['state']->failed   = false;
		aafm_undo_open_sitewide( $handle );
		return $handle;
	}

	if ( 'post_create' === $surface['family'] ) {
		return aafm_undo_begin_create( $base, $surface, $input );
	}

	if ( $base['object_id'] < 1 || array() === $base['field_ids'] ) {
		return null;
	}
	return aafm_undo_open_change( $base );
}

/**
 * Begin a create: a pending row with no before-values and a listener that learns the new post's id
 * even when the call errors after the insert landed.
 *
 * @param array<string,mixed> $base    Base handle.
 * @param array<string,mixed> $surface Surface.
 * @param array<string,mixed> $input   Tool input.
 * @return array<string,mixed>
 */
function aafm_undo_begin_create( array $base, array $surface, array $input ): array {
	global $wp_current_filter;

	$post_type = '@input' === $surface['post_type'] ? sanitize_key( (string) ( $input['post_type'] ?? 'post' ) ) : (string) $surface['post_type'];
	$state     = new \stdClass();
	$state->id = 0;
	$baseline  = count( (array) $wp_current_filter );
	$listener  = static function ( $post_id, $post, $update ) use ( $state, $baseline, $post_type ): void {
		global $wp_current_filter;
		// Only the outer insert's own action: a nested insert (from save_post or a later callback) runs deeper.
		if ( $update || 0 !== $state->id || ! $post instanceof \WP_Post || $post_type !== $post->post_type || count( (array) $wp_current_filter ) !== $baseline + 1 ) {
			return;
		}
		$state->id = (int) $post_id;
	};
	add_action( 'wp_insert_post', $listener, PHP_INT_MIN, 3 );

	$field_ids = array( 'post:post_status', 'post:post_title', 'post:post_content', 'post:post_excerpt', 'post:post_name', 'fp:object', 'fp:core' );
	$change_id = aafm_undo_insert(
		array(
			'log_row_id'        => $base['row_id'],
			'ability'           => $base['ability'],
			'source'            => 'agent',
			'principal_user_id' => get_current_user_id(),
			'client_id'         => aafm_undo_connection_id(),
			'object_type'       => 'post',
			'undo_op'           => 'trash',
			'status'            => 'pending',
			'fields'            => wp_json_encode( $field_ids ),
			'perm_input'        => '{}',
			'before_values'     => '{}',
			'before_sha256'     => hash( 'sha256', '{}' ),
		)
	);

	return array_merge(
		$base,
		array(
			'kind'      => $change_id > 0 ? 'create' : 'failed',
			'change_id' => $change_id,
			'state'     => $state,
			'listener'  => $listener,
			'field_ids' => $field_ids,
		)
	);
}

/**
 * Finish a change after the call: read the same fields, drop the unchanged ones and settle the row.
 *
 * @param array<string,mixed> $h A covered handle from aafm_undo_open_change().
 * @return array{status: string, reason?: string}
 */
function aafm_undo_finalize_change( array $h ): array {
	$not_undoable = static function ( string $reason ) use ( $h ): array {
		aafm_undo_update(
			$h['change_id'],
			array(
				'status'         => 'not_undoable',
				'reason'         => $reason,
				'before_values'  => null,
				'snapshot_bytes' => 0,
			)
		);
		return array(
			'status' => 'not_undoable',
			'reason' => $reason,
		);
	};

	$after = aafm_undo_read_fields( $h['object_type'], $h['object_id'], $h['field_ids'], $h['context'] );
	if ( null === $after ) {
		return $not_undoable( 'capture_failed' );
	}
	$changed = array();
	foreach ( $after['values'] as $field => $value ) {
		$hash = aafm_undo_hash( $value );
		if ( ( $h['before_hash'][ $field ] ?? null ) !== $hash ) {
			$changed[ $field ] = $hash;
		}
	}
	if ( array() === $changed ) {
		aafm_undo_delete( $h['change_id'] );
		return array(
			'status' => 'no_change',
			'reason' => 'no_change',
		);
	}
	foreach ( array_keys( $changed ) as $field ) {
		if ( isset( $h['flagged'][ $field ] ) ) {
			return $not_undoable( $h['flagged'][ $field ] );
		}
	}

	$before = array_intersect_key( $h['kept'], $changed );
	$json   = aafm_undo_json( $before );
	if ( null === $json || strlen( $json ) > AAFM_UNDO_MAX_CHANGE_BYTES ) {
		return $not_undoable( null === $json ? 'capture_failed' : 'too_large' );
	}
	$written = aafm_undo_update(
		$h['change_id'],
		array(
			'status'         => 'ready',
			'fields'         => wp_json_encode( array_keys( $changed ) ),
			'before_values'  => $json,
			'before_sha256'  => hash( 'sha256', $json ),
			'after_hashes'   => wp_json_encode( $changed ),
			'snapshot_bytes' => strlen( $json ),
		)
	);
	return $written < 1 ? $not_undoable( 'capture_failed' ) : array( 'status' => 'ready' );
}

/**
 * Settle a create's row once the post exists: its after-hashes include the whole-object fingerprint.
 *
 * @param array<string,mixed> $h      Create handle.
 * @param mixed               $result The tool's result.
 * @return array{status: string, reason?: string}
 */
function aafm_undo_finalize_create( array $h, $result ): array {
	remove_action( 'wp_insert_post', $h['listener'], PHP_INT_MIN );
	$post_id = is_array( $result ) ? absint( $result['post']['id'] ?? 0 ) : 0;
	if ( $post_id < 1 ) {
		$post_id = (int) $h['state']->id;
	}
	if ( $post_id < 1 ) {
		aafm_undo_delete( $h['change_id'] );
		return array(
			'status' => 'no_change',
			'reason' => 'no_change',
		);
	}

	$after = aafm_undo_read_fields( 'post', $post_id, $h['field_ids'] );
	if ( null === $after ) {
		aafm_undo_update(
			$h['change_id'],
			array(
				'object_id' => $post_id,
				'status'    => 'not_undoable',
				'reason'    => 'too_large' === ( $h['reason'] ?? '' ) ? 'too_large' : 'capture_failed',
			)
		);
		return array(
			'status' => 'not_undoable',
			'reason' => 'capture_failed',
		);
	}
	$hashes = array();
	foreach ( $after['values'] as $field => $value ) {
		$hashes[ $field ] = aafm_undo_hash( $value );
	}
	$written = aafm_undo_update(
		$h['change_id'],
		array(
			'object_id'    => $post_id,
			'status'       => 'ready',
			'after_hashes' => wp_json_encode( $hashes ),
		)
	);
	if ( $written > 0 ) {
		aafm_undo_pending_creates( array( (int) $h['change_id'], $post_id ) );
	}
	if ( $written < 1 ) {
		aafm_undo_update(
			$h['change_id'],
			array(
				'object_id' => $post_id,
				'status'    => 'not_undoable',
				'reason'    => 'capture_failed',
			)
		);
		return array(
			'status' => 'not_undoable',
			'reason' => 'capture_failed',
		);
	}
	return array( 'status' => 'ready' );
}

/**
 * The creates whose whole-object fingerprint is re-taken once the request is over.
 *
 * @param array{0:int,1:int}|null $add    Change id and post id to settle at shutdown, or null to read the list.
 * @param bool                    $forget Drop the list (the test seam).
 * @return array<int,int> Change id to post id.
 */
function aafm_undo_pending_creates( ?array $add = null, bool $forget = false ): array {
	static $pending = array();

	if ( $forget ) {
		$pending = array();
		return $pending;
	}
	if ( null !== $add ) {
		if ( array() === $pending ) {
			add_action( 'shutdown', 'aafm_undo_settle_creates', PHP_INT_MAX );
		}
		$pending[ $add[0] ] = $add[1];
	}
	return $pending;
}

/**
 * Re-take the whole-object fingerprint of this request's creates at the last shutdown callback.
 *
 * Other plugins keep bookkeeping on a new post after the call returns (WPML adds its word count in the
 * shutdown phase). The fingerprint exists to catch a later edit by someone else, so it is taken again
 * after that bookkeeping, but only when the part of the object that is not meta (the post row, terms and
 * comments) still hashes to what the call left: anything else changed in between is not absorbed.
 *
 * WordPress calls a shutdown callback with an empty string, so this takes no argument.
 *
 * @return void
 */
function aafm_undo_settle_creates(): void {
	try {
		foreach ( aafm_undo_pending_creates() as $change_id => $post_id ) {
			$view = aafm_undo_get_view( (int) $change_id );
			$row  = $view['row'];
			if ( ! $view['ok'] || null === $row || 'ready' !== $row['status'] ) {
				continue;
			}
			$after = (array) json_decode( (string) $row['after_hashes'], true );
			$core  = aafm_undo_object_fingerprint( (int) $post_id, false );
			$full  = aafm_undo_object_fingerprint( (int) $post_id );
			if ( null === $core || null === $full || ! isset( $after['fp:object'], $after['fp:core'] ) || aafm_undo_hash( $core ) !== $after['fp:core'] ) {
				continue;
			}
			$after['fp:object'] = aafm_undo_hash( $full );
			aafm_undo_update( (int) $change_id, array( 'after_hashes' => wp_json_encode( $after ) ), array( 'ready' ) );
		}
	} catch ( \Throwable $e ) {
		aafm_undo_engine_fault( $e, 'aafm/undo-capture' );
	}
	aafm_undo_pending_creates( null, true );
	remove_action( 'shutdown', 'aafm_undo_settle_creates', PHP_INT_MAX );
}

/**
 * Called after a native tool ran: settle the change and add the `undo` key to an array result.
 *
 * @param array<string,mixed>|null $handle From aafm_undo_begin().
 * @param mixed                    $result The tool's result.
 * @return mixed The result, with `undo` added on an array result of a native write.
 */
function aafm_undo_finish( ?array $handle, $result ) {
	if ( null === $handle ) {
		return $result;
	}
	try {
		$undo = aafm_undo_finish_inner( $handle, $result );
	} catch ( \Throwable $e ) {
		aafm_undo_engine_fault( $e, (string) $handle['ability'] );
		$undo = aafm_undo_response( array(), 'capture_failed' );
	}
	if ( is_array( $result ) && aafm_undo_schema_carrier( (string) $handle['ability'] ) ) {
		$result['undo'] = $undo;
	}
	return $result;
}

/**
 * The body of aafm_undo_finish().
 *
 * @param array<string,mixed> $h      Handle.
 * @param mixed               $result The tool's result.
 * @return array<string,mixed> The `undo` value.
 */
function aafm_undo_finish_inner( array $h, $result ): array {
	switch ( $h['kind'] ) {
		case 'excluded':
			return aafm_undo_response( array(), $h['reason'] );
		case 'history_off':
			return aafm_undo_response( array(), 'history_off' );
		case 'failed':
			if ( isset( $h['listener'] ) ) {
				remove_action( 'wp_insert_post', $h['listener'], PHP_INT_MIN );
			}
			return aafm_undo_response( array(), 'capture_failed' );
		case 'sitewide':
			aafm_undo_open_sitewide( null, true );
			$ids = $h['state']->children;
			if ( array() === $ids && ! $h['state']->failed ) {
				return aafm_undo_response( array(), 'no_change' );
			}
			aafm_undo_enforce_caps();
			return aafm_undo_response( $ids, $h['state']->failed ? 'capture_failed' : null );
		case 'create':
			$outcome = aafm_undo_finalize_create( $h, $result );
			break;
		default:
			$outcome = aafm_undo_finalize_change( $h );
	}

	if ( 'ready' === $outcome['status'] ) {
		aafm_undo_enforce_caps();
		return aafm_undo_response( array( (int) $h['change_id'] ) );
	}
	return aafm_undo_response( array(), $outcome['reason'] ?? 'capture_failed' );
}

/**
 * Begin one post's change inside a replace-sitewide call. A no-op outside a decorated call.
 *
 * @param string              $ability     Ability name.
 * @param string              $object_type 'post'.
 * @param int                 $object_id   Post id.
 * @param string[]            $field_ids   Fields to record.
 * @param array<string,mixed> $perm_input Identifier-only permission input.
 * @return array<string,mixed>|null
 */
function aafm_undo_begin_object( string $ability, string $object_type, int $object_id, array $field_ids, array $perm_input ): ?array {
	$open = aafm_undo_open_sitewide();
	if ( null === $open || aafm_undo_capture_off() ) {
		return null;
	}
	try {
		$handle = aafm_undo_open_change(
			array(
				'ability'     => $ability,
				'row_id'      => (int) $open['row_id'],
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'field_ids'   => $field_ids,
				'context'     => array(),
				'undo_op'     => 'restore',
				'perm_input'  => (string) wp_json_encode( (object) $perm_input ),
				'group_id'    => $open['group_id'],
			)
		);
	} catch ( \Throwable $e ) {
		aafm_undo_engine_fault( $e, $ability );
		$handle = array( 'kind' => 'failed' );
	}
	if ( 'failed' === $handle['kind'] ) {
		$open['state']->failed = true;
		return null;
	}
	$handle['state'] = $open['state'];
	return $handle;
}

/**
 * Finish one post's change inside a replace-sitewide call. The caps run once, from the outer finish.
 *
 * @param array<string,mixed>|null $handle From aafm_undo_begin_object().
 * @return void
 */
function aafm_undo_finish_object( ?array $handle ): void {
	if ( null === $handle ) {
		return;
	}
	try {
		$outcome = aafm_undo_finalize_change( $handle );
		if ( 'ready' === $outcome['status'] ) {
			$handle['state']->children[] = (int) $handle['change_id'];
		} elseif ( 'no_change' !== $outcome['status'] ) {
			$handle['state']->failed = true;
		}
	} catch ( \Throwable $e ) {
		aafm_undo_engine_fault( $e, (string) $handle['ability'] );
		$handle['state']->failed = true;
	}
}

/**
 * A refusal: a WP_Error carrying the reason code and its message, never a value.
 *
 * @param string $reason Reason code.
 * @return \WP_Error
 */
function aafm_undo_refusal( string $reason ): \WP_Error {
	return new \WP_Error( 'aafm_undo_' . $reason, aafm_undo_reason_message( $reason ) );
}

/**
 * A row's decoded columns.
 *
 * @param array<string,mixed> $row A change row.
 * @return array{fields: string[], before: array<string,mixed>, after: array<string,string>, perm: array<string,mixed>, taxonomy: string}
 */
function aafm_undo_decode_row( array $row ): array {
	$perm = json_decode( (string) ( $row['perm_input'] ?? '' ), true );
	$perm = is_array( $perm ) ? $perm : array();
	return array(
		'fields'   => array_values( array_filter( (array) json_decode( (string) ( $row['fields'] ?? '[]' ), true ), 'is_string' ) ),
		'before'   => (array) json_decode( (string) ( $row['before_values'] ?? '{}' ), true ),
		'after'    => (array) json_decode( (string) ( $row['after_hashes'] ?? '{}' ), true ),
		'perm'     => $perm,
		'taxonomy' => isset( $perm['taxonomy'] ) ? sanitize_key( (string) $perm['taxonomy'] ) : '',
	);
}

/**
 * Whether a row can be undone right now, from the row alone. Used by the Changes tab and by list-changes.
 *
 * @param array<string,mixed> $row    A change row.
 * @param string              $source 'owner' or 'agent'.
 * @return array{undoable: bool, reason?: string}
 */
function aafm_undo_row_state( array $row, string $source ): array {
	$no = static fn( string $reason ): array => array(
		'undoable' => false,
		'reason'   => $reason,
	);

	$status = (string) $row['status'];
	if ( aafm_undo_claim_is_stale( $row ) ) {
		$status = 'ready'; // A claim that never settled can be taken again: the conflict check keeps a retry safe.
	}
	if ( 'reverted' === $status ) {
		return $no( 'already_reverted' );
	}
	if ( 'undoing' === $status ) {
		return $no( 'interrupted' );
	}
	if ( 'pending' === $status ) {
		$young = strtotime( (string) $row['created_at'] . ' UTC' ) > time() - AAFM_UNDO_PENDING_GRACE;
		return $no( $young ? 'interrupted' : 'did_not_finish' );
	}
	if ( aafm_undo_is_aged( $row ) || 'expired' === $status || null === $row['before_values'] ) {
		return 'not_undoable' === $status && '' !== (string) $row['reason'] ? $no( (string) $row['reason'] ) : $no( 'expired' );
	}
	if ( 'not_undoable' === $status ) {
		return $no( '' !== (string) $row['reason'] ? (string) $row['reason'] : 'not_allowed' );
	}
	// The Changes tab never loads the values, so it hands over the hash comparison the database already made.
	$hash_ok = array_key_exists( 'hash_ok', $row ) ? (bool) $row['hash_ok'] : hash_equals( (string) $row['before_sha256'], hash( 'sha256', (string) $row['before_values'] ) );
	if ( 'ready' !== $status || ! $hash_ok ) {
		return $no( 'read_failed' );
	}
	if ( 'agent' === $source ) {
		$refusal = aafm_undo_agent_refusal( $row );
		if ( null !== $refusal ) {
			return $no( $refusal );
		}
	}
	return array( 'undoable' => true );
}

/**
 * Read the target's fields as they are now and store them as a new change row, so a revert can itself
 * be reverted. Ignores the capture switch and the surface map. A value-rule refusal or a failed insert
 * refuses the revert, because a revert never overwrites data it could not record.
 *
 * The one read also returns the target's own fields, so the caller's last conflict check runs on the
 * same bytes the record was taken from.
 *
 * @param array<string,mixed> $target A change row.
 * @param string              $source 'owner' or 'agent'.
 * @return array{ok: bool, id: int, reason?: string, fields?: string[], read?: array<string,mixed>}
 */
function aafm_undo_record_current( array $target, string $source ): array {
	$decoded = aafm_undo_decode_row( $target );
	$op      = (string) $target['undo_op'];
	$revert  = $decoded['fields'];
	$new_op  = 'restore';
	if ( 'trash' === $op || 'untrash' === $op ) {
		$revert = array( 'post:post_status', 'post:post_name' );
		$new_op = 'trash' === $op ? 'untrash' : 'trash';
	}
	$context = '' !== $decoded['taxonomy'] ? array( 'taxonomy' => $decoded['taxonomy'] ) : array();
	$read    = aafm_undo_read_fields( (string) $target['object_type'], (int) $target['object_id'], array_values( array_unique( array_merge( $decoded['fields'], $revert ) ) ), $context );
	if ( null === $read ) {
		return array(
			'ok'     => false,
			'id'     => 0,
			'reason' => 'read_failed',
		);
	}

	$mine  = array(
		'values' => array_intersect_key( $read['values'], array_flip( $revert ) ),
		'flags'  => array_intersect_key( $read['flags'], array_flip( $revert ) ),
	);
	$rules = aafm_undo_apply_value_rules( $mine );
	if ( array() !== $rules['flagged'] ) {
		return array(
			'ok'     => false,
			'id'     => 0,
			'reason' => (string) reset( $rules['flagged'] ),
		);
	}
	$json = aafm_undo_json( $rules['kept'] );
	if ( null === $json ) {
		return array(
			'ok'     => false,
			'id'     => 0,
			'reason' => 'read_failed',
		);
	}
	$id = aafm_undo_insert(
		array(
			'ability'           => 'owner' === $source ? 'aafm/owner-revert' : 'aafm/undo-change',
			'source'            => $source,
			'principal_user_id' => get_current_user_id(),
			'client_id'         => 'agent' === $source ? aafm_undo_connection_id() : '',
			'object_type'       => (string) $target['object_type'],
			'object_id'         => (int) $target['object_id'],
			'undo_op'           => $new_op,
			'status'            => 'pending',
			'fields'            => wp_json_encode( $revert ),
			'perm_input'        => (string) $target['perm_input'],
			'before_values'     => $json,
			'before_sha256'     => hash( 'sha256', $json ),
			'reverts_change_id' => (int) $target['id'],
		)
	);
	return $id > 0 ? array(
		'ok'     => true,
		'id'     => $id,
		'fields' => $revert,
		'read'   => $read['values'],
	) : array(
		'ok'     => false,
		'id'     => 0,
		'reason' => 'read_failed',
	);
}

/**
 * The object checks both sources share: the object exists, the user may edit or trash it, nothing
 * the change depends on is gone, and a page builder has not taken the post over.
 *
 * @param array<string,mixed> $row Change row.
 * @param array<string,mixed> $d   Decoded row.
 * @return string|null Reason code of the first refusal.
 */
function aafm_undo_object_checks( array $row, array $d ): ?string {
	$id   = (int) $row['object_id'];
	$op   = (string) $row['undo_op'];
	$term = 'term' === $row['object_type'];

	// The post load comes first and the term load last, so the sweep pairs term_is_ancestor_of() below with the term load.
	$object = ! $term ? aafm_exact_object_chain( 'post', $id ) : aafm_exact_object_chain( 'term', $id, $d['taxonomy'] );
	if ( ! $object instanceof \WP_Post && ! $object instanceof \WP_Term ) {
		return 'dependency_gone';
	}
	foreach ( $d['fields'] as $field ) {
		if ( 0 === strpos( (string) $field, 'aioseo:' ) && ( ! function_exists( 'aafm_aioseo_model_available' ) || ! aafm_aioseo_model_available() ) ) {
			return 'dependency_gone';
		}
	}
	if ( $term ) {
		if ( ! aafm_user_can_checked( 'edit_term', $id ) ) {
			return 'not_allowed';
		}
		$parent = $d['before']['term:parent'] ?? null;
		if ( is_int( $parent ) && $parent > 0 && ( $parent === $id || term_is_ancestor_of( $id, $parent, $d['taxonomy'] ) ) ) {
			return 'not_allowed';
		}
	} elseif ( 'restore' === $op ) {
		$wanted = $d['before']['post:post_status'] ?? null;
		$public = is_string( $wanted ) && in_array( $wanted, array( 'publish', 'future' ), true );
		if ( ! aafm_user_can_checked( 'edit_post', $id ) || ( $public && ! aafm_user_can_checked( 'publish_post', $id ) ) ) {
			return 'not_allowed';
		}
	} elseif ( ! aafm_user_can_checked( 'delete_post', $id ) ) {
		return 'not_allowed';
	}

	if ( ! $term && 'restore' === $op ) {
		$owner = aafm_post_has_foreign_builder_ownership( $id );
		if ( false !== $owner ) {
			$post_fields = array_filter( $d['fields'], static fn( string $f ): bool => 0 === strpos( $f, 'post:' ) );
			if ( AAFM_BUILDER_OWNERSHIP_UNKNOWN === $owner && array() !== $post_fields ) {
				return 'builder_owned';
			}
			if ( array() !== $post_fields && array( 'post:post_excerpt' ) !== array_values( $post_fields ) ) {
				return 'builder_owned';
			}
		}
	}

	// Anything the before-values point at must still exist.
	$ids = array();
	foreach ( $d['before'] as $field => $value ) {
		if ( 'meta:_thumbnail_id' === $field && isset( $value[0] ) && absint( $value[0] ) > 0 ) {
			if ( ! aafm_exact_object( 'post', absint( $value[0] ) ) instanceof \WP_Post ) {
				return 'dependency_gone';
			}
		} elseif ( 0 === strpos( (string) $field, 'terms:' ) ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) $value ) );
		}
	}
	if ( array() !== $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( $ids ) );
		$sql = 'SELECT COUNT(*) FROM %i WHERE term_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are generated from the id list.
		$have = aafm_wpdb_scalar( $wpdb->prepare( $sql, array_merge( array( $wpdb->term_taxonomy ), $ids ) ) );
		if ( ! $have['ok'] || (int) $have['value'] < count( $ids ) ) {
			return $have['ok'] ? 'dependency_gone' : 'read_failed';
		}
	}

	if ( in_array( $op, array( 'trash', 'untrash' ), true ) ) {
		if ( 'trash' === $op && ! aafm_trash_is_enabled() ) {
			return 'trash_off';
		}
	}
	return null;
}

/**
 * Revert one recorded change, as the owner or as an agent.
 *
 * Order: load and check ownership, check state, claim the row, check the object, re-read every field
 * and refuse when one changed since, replay what WordPress would store, record the current values as
 * a new change, compare once more, write, certify.
 *
 * @param int    $change_id Change id.
 * @param string $source    'owner' or 'agent'.
 * @return array<string,mixed>|\WP_Error outcome, change_id, new_change_id, fields, and differences when there are any.
 */
function aafm_undo_apply( int $change_id, string $source ) {
	$view = aafm_undo_get_view( $change_id );
	if ( ! $view['ok'] ) {
		return aafm_undo_refusal( 'read_failed' );
	}
	$row = $view['row'];
	if ( null === $row ) {
		return aafm_generic_error();
	}
	if ( 'agent' === $source ) {
		$connection = aafm_undo_connection_id();
		if ( 'agent' !== $row['source'] || get_current_user_id() !== (int) $row['principal_user_id'] || '' === $connection || $row['client_id'] !== $connection ) {
			return aafm_generic_error();
		}
	}
	$state = aafm_undo_row_state( $row, $source );
	if ( ! $state['undoable'] ) {
		return aafm_undo_refusal( $state['reason'] ?? 'not_allowed' );
	}
	if ( aafm_undo_claim_is_stale( $row ) ) {
		aafm_undo_reclaim_stale( $change_id, (string) $row['reason'] );
	}
	$claimed_at = (string) time();
	$claimed    = aafm_undo_update(
		$change_id,
		array(
			'status' => 'undoing',
			'reason' => $claimed_at,
		),
		array( 'ready' )
	);
	if ( 1 !== $claimed ) {
		return aafm_undo_refusal( 'interrupted' );
	}

	try {
		return aafm_undo_apply_claimed( $row, $source, $claimed_at );
	} catch ( \Throwable $e ) {
		// Anything that threw outside the write's own settlement puts the row back; a retry is safe either way.
		aafm_undo_update(
			$change_id,
			array(
				'status' => 'ready',
				'reason' => '',
			),
			array( 'undoing' )
		);
		aafm_undo_engine_fault( $e, 'owner' === $source ? 'aafm/owner-revert' : 'aafm/undo-change' );
		return new \WP_Error( 'aafm_undo_failed', __( 'The undo did not finish, so check the item before trying again.', 'agent-abilities-for-mcp' ) );
	}
}

/**
 * The part of a revert that runs while the row is claimed: object checks, conflict check, record, write and settle.
 *
 * @param array<string,mixed> $row        The claimed change row.
 * @param string              $source     'owner' or 'agent'.
 * @param string              $claimed_at The claim time written to the row's reason column.
 * @return array<string,mixed>|\WP_Error
 */
function aafm_undo_apply_claimed( array $row, string $source, string $claimed_at ) {
	$change_id = (int) $row['id'];
	// Every refusal from here until a write lands puts the row back.
	$release = static function ( string $reason ) use ( $change_id ) {
		aafm_undo_update(
			$change_id,
			array(
				'status' => 'ready',
				'reason' => '',
			),
			array( 'undoing' )
		);
		return aafm_undo_refusal( $reason );
	};

	$d       = aafm_undo_decode_row( $row );
	$op      = (string) $row['undo_op'];
	$type    = (string) $row['object_type'];
	$id      = (int) $row['object_id'];
	$context = '' !== $d['taxonomy'] ? array( 'taxonomy' => $d['taxonomy'] ) : array();

	$refusal = aafm_undo_object_checks( $row, $d );
	if ( null !== $refusal ) {
		return $release( $refusal );
	}
	if ( 'untrash' === $op || 'trash' === $op ) {
		$status_now = aafm_undo_read_fields( 'post', $id, array( 'post:post_status' ) );
		if ( null === $status_now ) {
			return $release( 'read_failed' );
		}
		if ( 'untrash' === $op && 'trash' !== $status_now['values']['post:post_status'] ) {
			return $release( 'not_in_trash' );
		}
	}

	// Conflict: every recorded field must still hash to what the call left.
	$conflict = aafm_undo_conflicts( $type, $id, $d, $context );
	if ( null === $conflict ) {
		return $release( 'read_failed' );
	}
	if ( array() !== $conflict ) {
		return $release( 'changed_since' );
	}

	// What WordPress would store for these values must be the values: no lossy restore reported as success.
	foreach ( $d['before'] as $field => $value ) {
		if ( in_array( $field, array( 'post:post_title', 'post:post_content', 'post:post_excerpt' ), true ) && is_string( $value ) && aafm_post_field_canonical_replay( substr( $field, 5 ), $value, $id ) !== $value ) {
			return $release( 'would_change' );
		}
		if ( in_array( $field, array( 'term:name', 'term:description' ), true ) && is_string( $value ) && wp_unslash( sanitize_term_field( substr( $field, 5 ), wp_slash( $value ), $id, $d['taxonomy'], 'db' ) ) !== $value ) {
			return $release( 'would_change' );
		}
		if ( 0 === strpos( (string) $field, 'meta:' ) && ! aafm_meta_restore_unfiltered( $type, substr( (string) $field, 5 ), (string) get_object_subtype( $type, $id ), array_map( 'strval', (array) $value ) ) ) {
			return $release( 'meta_filtered' );
		}
	}

	// The last read before the write: record the current values and compare that same read once more.
	$recorded = aafm_undo_record_current( $row, $source );
	if ( ! $recorded['ok'] ) {
		return $release( (string) ( $recorded['reason'] ?? 'read_failed' ) );
	}
	$new_id = $recorded['id'];
	foreach ( $d['after'] as $field => $hash ) {
		if ( ! array_key_exists( $field, $recorded['read'] ?? array() ) || aafm_undo_hash( $recorded['read'][ $field ] ?? null ) !== $hash ) {
			aafm_undo_delete( $new_id );
			return $release( 'changed_since' );
		}
	}

	// Still the holder of the claim? A claim left past the grace can be taken by another request, and this
	// one may have been paused in a slow hook; if so it must not write on top of the other.
	$held = aafm_undo_get_view( $change_id );
	if ( ! $held['ok'] || null === $held['row'] || 'undoing' !== $held['row']['status'] || (string) $held['row']['reason'] !== $claimed_at ) {
		aafm_undo_delete( $new_id );
		return aafm_undo_refusal( 'interrupted' );
	}

	// ponytail: a human save landing between the read above and the write below wins or loses the way two WordPress
	// editors saving at once do; core has no compare-and-swap and row locks held across wp_update_post() would risk
	// lock waits with other plugins. Add a lock only if that window ever shows up in practice.
	$thrown = null;
	$wrote  = array(
		'certified'   => array(),
		'missed'      => $d['fields'],
		'differences' => array(),
		'read'        => null,
	);
	try {
		$wrote = aafm_undo_run_write( $row, $d, $context, $source );
	} catch ( \Throwable $e ) {
		// A hook threw mid-write: settle by what landed, below, then report the fault.
		$thrown = $e;
	}

	$state_now = aafm_undo_read_fields( $type, $id, $recorded['fields'] ?? array(), $context );
	$fault     = static function () use ( $thrown, $source ): void {
		if ( null !== $thrown ) {
			aafm_undo_engine_fault( $thrown, 'owner' === $source ? 'aafm/owner-revert' : 'aafm/undo-change' );
		}
	};
	if ( null === $state_now ) {
		// The write may or may not have landed. Keep the recovery row, and a retry is safe: it refuses as changed_since when the write landed.
		aafm_undo_update(
			$change_id,
			array(
				'status' => 'ready',
				'reason' => '',
			),
			array( 'undoing' )
		);
		$fault();
		return new \WP_Error( 'aafm_undo_failed', __( 'The undo could not be confirmed, so check the item before trying again.', 'agent-abilities-for-mcp' ) );
	}
	$hashes = array();
	$landed = false;
	foreach ( $state_now['values'] as $field => $value ) {
		$hashes[ $field ] = aafm_undo_hash( $value );
		$landed           = $landed || aafm_undo_hash( $recorded['read'][ $field ] ?? null ) !== $hashes[ $field ];
	}

	if ( ! $landed ) {
		aafm_undo_delete( $new_id );
		aafm_undo_update(
			$change_id,
			array(
				'status' => 'ready',
				'reason' => '',
			),
			array( 'undoing' )
		);
		$fault();
		return new \WP_Error( 'aafm_undo_failed', __( 'The undo did not change anything, so nothing was written.', 'agent-abilities-for-mcp' ) );
	}

	$new_saved = 1 === aafm_undo_update(
		$new_id,
		array(
			'status'       => 'ready',
			'after_hashes' => wp_json_encode( $hashes ),
		)
	);
	$old_saved = 1 === aafm_undo_update(
		$change_id,
		array(
			'status'                => 'reverted',
			'reason'                => '',
			'reverted_by_change_id' => $new_id,
		),
		array( 'undoing' )
	);
	aafm_undo_enforce_caps();
	$fault();

	if ( ! $new_saved || ! $old_saved ) {
		return new \WP_Error( 'aafm_undo_failed', __( 'The undo was applied, but its history entry could not be saved. Check the item.', 'agent-abilities-for-mcp' ) );
	}
	if ( array() !== $wrote['missed'] ) {
		return new \WP_Error(
			'aafm_undo_failed',
			sprintf(
				/* translators: 1: comma-separated field names, 2: change id */
				__( 'The undo did not fully apply: %1$s did not match. Change %2$d records the current state and can be reverted.', 'agent-abilities-for-mcp' ),
				implode( ', ', array_map( static fn( string $f ): string => (string) substr( $f, (int) strpos( $f, ':' ) + 1 ), $wrote['missed'] ) ),
				$new_id
			)
		);
	}
	$out = array(
		'outcome'       => array() === $wrote['differences'] ? 'reverted' : 'reverted_with_differences',
		'change_id'     => $change_id,
		'new_change_id' => $new_id,
		// The fingerprints of a create are bookkeeping, not fields anyone sees.
		'fields'        => array_map( static fn( string $f ): string => (string) substr( $f, (int) strpos( $f, ':' ) + 1 ), array_values( array_filter( $d['fields'], static fn( string $f ): bool => 0 !== strpos( $f, 'fp:' ) ) ) ),
	);
	if ( array() !== $wrote['differences'] ) {
		$out['differences'] = array_map( static fn( string $f ): string => (string) substr( $f, (int) strpos( $f, ':' ) + 1 ), $wrote['differences'] );
	}
	return $out;
}

/**
 * The fields of a row whose current hash is not the one the call left, or null when a read failed.
 *
 * @param string               $type    'post' or 'term'.
 * @param int                  $id      Object id.
 * @param array<string,mixed>  $d       Decoded row.
 * @param array<string,string> $context taxonomy for a term.
 * @return string[]|null
 */
function aafm_undo_conflicts( string $type, int $id, array $d, array $context ): ?array {
	$read = aafm_undo_read_fields( $type, $id, array_map( 'strval', array_keys( $d['after'] ) ), $context );
	if ( null === $read ) {
		return null;
	}
	$changed = array();
	foreach ( $d['after'] as $field => $hash ) {
		if ( aafm_undo_hash( $read['values'][ $field ] ?? null ) !== $hash ) {
			$changed[] = (string) $field;
		}
	}
	return $changed;
}

/**
 * Do the write for one change, as the owner or as an agent: trash, untrash or restore the fields.
 *
 * @param array<string,mixed>  $row     Change row.
 * @param array<string,mixed>  $d       Decoded row.
 * @param array<string,string> $context taxonomy for a term.
 * @param string               $source  'owner' or 'agent'.
 * @return array{certified: string[], missed: string[], differences: string[], read: array<string,mixed>|null}
 */
function aafm_undo_run_write( array $row, array $d, array $context, string $source ): array {
	$op = (string) $row['undo_op'];
	$id = (int) $row['object_id'];
	if ( 'owner' === $source ) {
		aafm_executing_ability_push( 'aafm/owner-revert' );
	}
	try {
		if ( 'trash' === $op || 'untrash' === $op ) {
			aafm_exact_object_chain( 'post', $id );
			if ( 'trash' === $op ) {
				wp_trash_post( $id );
				$wanted = array( 'post:post_status' => 'trash' );
			} else {
				$status = (string) ( $d['before']['post:post_status'] ?? 'draft' );
				$filter = static fn() => $status;
				add_filter( 'wp_untrash_post_status', $filter, 10, 0 );
				try {
					wp_untrash_post( $id );
				} finally {
					remove_filter( 'wp_untrash_post_status', $filter, 10 );
				}
				$wanted = array(
					'post:post_status' => $status,
					'post:post_name'   => (string) ( $d['before']['post:post_name'] ?? '' ),
				);
			}
			$result = aafm_undo_certify( 'post', $id, $wanted );
			// A trashed post's slug is rewritten by core and untrash restores it: both reported, status certified.
			if ( 'trash' === $op ) {
				$result['read'] = $result['read'] ?? null;
			}
			return $result;
		}
		$post_fields     = array_filter( $d['fields'], static fn( string $f ): bool => 0 === strpos( $f, 'post:' ) );
		$builder_excerpt = 'post' === $row['object_type'] && false !== aafm_post_has_foreign_builder_ownership( $id ) && array() !== $post_fields;
		return aafm_undo_write_fields( (string) $row['object_type'], $id, $d['before'], $context, $builder_excerpt );
	} finally {
		if ( 'owner' === $source ) {
			aafm_executing_ability_pop();
			if ( null === aafm_executing_ability() ) {
				aafm_refresh_after_meta_writes();
			}
		}
	}
}
