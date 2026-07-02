<?php
/**
 * Ability category registration + the audited registration wrapper.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Register the two ability categories.
 *
 * @return void
 */
function aafm_register_categories(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}
	if ( ! wp_has_ability_category( 'aafm-reads' ) ) {
		wp_register_ability_category(
			'aafm-reads',
			array(
				'label'       => __( 'Agent reads', 'agent-abilities-for-mcp' ),
				'description' => __( 'Read-only abilities exposed to AI agents.', 'agent-abilities-for-mcp' ),
			)
		);
	}
	if ( ! wp_has_ability_category( 'aafm-writes' ) ) {
		wp_register_ability_category(
			'aafm-writes',
			array(
				'label'       => __( 'Agent writes', 'agent-abilities-for-mcp' ),
				'description' => __( 'Guarded write abilities exposed to AI agents.', 'agent-abilities-for-mcp' ),
			)
		);
	}
}

/**
 * Remember (or read) an ability's undecorated permission callback.
 *
 * Acts as a tiny static store keyed by ability name. Passing a callable records it;
 * passing null returns the stored callback for that name (or null if unknown). This
 * lets the MCP tools/list filter test a connection's visibility without going through
 * the audited permission decorator, which would log a denial on every hidden tool.
 *
 * @param string        $name     Ability name.
 * @param callable|null $callback Callback to store, or null to read.
 * @return callable|null Stored callback when reading; null otherwise.
 */
function aafm_remember_raw_permission( string $name, ?callable $callback = null ): ?callable {
	static $store = array();

	if ( null !== $callback ) {
		$store[ $name ] = $callback;
		return null;
	}

	return $store[ $name ] ?? null;
}

/**
 * Register an ability with a guaranteed permission callback and full audit logging.
 *
 * Refuses to register without a callable permission_callback. Decorates the permission
 * callback to log denials and the execute callback to log before + after with real status.
 *
 * @param string              $name Ability name.
 * @param array<string,mixed> $args Ability args (per the Abilities API).
 * @return WP_Ability|null
 */
function aafm_register_ability_with_log( string $name, array $args ) {
	if ( empty( $args['permission_callback'] ) || ! is_callable( $args['permission_callback'] ) ) {
		_doing_it_wrong(
			__FUNCTION__,
			esc_html(
				sprintf(
					/* translators: %s: ability name */
					__( 'Ability "%s" was not registered: a permission_callback is required.', 'agent-abilities-for-mcp' ),
					$name
				)
			),
			'1.0.0'
		);
		return null;
	}

	$original_permission = $args['permission_callback'];
	$original_execute    = $args['execute_callback'];

	// Stash the undecorated permission callback so list-time capability checks
	// (e.g. the MCP tools/list filter) can test visibility WITHOUT writing a
	// denied audit row for every tool a connection happens not to be able to call.
	aafm_remember_raw_permission( $name, $original_permission );

	$principal = static function (): array {
		$user = wp_get_current_user();
		return array(
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
		);
	};

	$args['permission_callback'] = static function ( $input = null ) use ( $original_permission, $name, $principal ) {
		// Per-principal rate gate. Discovery (tools/list) is exempt automatically: it
		// uses the raw permission stored above, which never enters this decorated closure
		// and so never consumes a token. With the limit off (default) aafm_rate_limit_consume()
		// returns true, making this block a no-op - the path stays identical to today.
		$p = $principal();
		if ( $p['principal_user_id'] > 0 && ! aafm_rate_limit_consume( $p['principal_user_id'] ) ) {
			aafm_log_activity(
				array_merge(
					$p,
					array(
						'ability'  => $name,
						'status'   => 'denied',
						'arg_keys' => is_array( $input ) ? array_keys( $input ) : array(),
					)
				)
			);
			return false;
		}

		$allowed = $original_permission( $input );
		// The WP Abilities API admits ONLY a strict true; every other return (false, WP_Error,
		// null, 0, '') is a denial. Audit any non-true result so a malformed or future permission
		// callback's denial is never silently unlogged.
		if ( true !== $allowed ) {
			aafm_log_activity(
				array_merge(
					$p,
					array(
						'ability'  => $name,
						'status'   => 'denied',
						'arg_keys' => is_array( $input ) ? array_keys( $input ) : array(),
					)
				)
			);
		}
		return $allowed;
	};

	$args['execute_callback'] = static function ( $input = null ) use ( $original_execute, $name, $principal ) {
		$arg_keys = is_array( $input ) ? array_keys( $input ) : array();

		// One row at 'started' (intent), then updated in place with the real outcome -
		// one row per call, not two. A crash mid-execute leaves a visible 'started' row.
		$row_id = aafm_log_activity(
			array_merge(
				$principal(),
				array(
					'ability'  => $name,
					'status'   => 'started',
					'arg_keys' => $arg_keys,
				)
			)
		);

		$result = $original_execute( $input );

		aafm_update_activity_status( $row_id, is_wp_error( $result ) ? 'error' : 'success' );

		return $result;
	};

	return wp_register_ability( $name, $args );
}

/**
 * Register every enabled ability from the registry on the Abilities API init pass.
 *
 * @return void
 */
function aafm_register_enabled_abilities(): void {
	$registry = aafm_get_abilities_registry();
	foreach ( aafm_get_enabled_abilities() as $name ) {
		// Idempotent: re-firing wp_abilities_api_init (in tests, or if another consumer
		// triggers the action) must not re-register and emit "already registered".
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
			continue;
		}
		if ( empty( $registry[ $name ]['args_builder'] ) || ! is_callable( $registry[ $name ]['args_builder'] ) ) {
			continue;
		}
		$args = call_user_func( $registry[ $name ]['args_builder'] );
		aafm_register_ability_with_log( $name, $args );
	}
}
