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

	$field_ids = array( 'post:post_status', 'post:post_title', 'post:post_content', 'post:post_excerpt', 'post:post_name', 'fp:object' );
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
	return $written < 0 ? $not_undoable( 'capture_failed' ) : array( 'status' => 'ready' );
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
	aafm_undo_update(
		$h['change_id'],
		array(
			'object_id'    => $post_id,
			'status'       => 'ready',
			'after_hashes' => wp_json_encode( $hashes ),
		)
	);
	return array( 'status' => 'ready' );
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
