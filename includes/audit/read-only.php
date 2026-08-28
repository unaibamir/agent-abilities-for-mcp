<?php
/**
 * Read-only mode: the second registration-time floor.
 *
 * A ceiling, not a bulk action. While it is on, nothing that writes can be registered as an MCP
 * tool no matter what the operator has ticked - native or bridged - and turning it on or off never
 * enables or disables a single ability on its own. The stored allow-lists are left exactly as they
 * were, so switching the mode off restores precisely what was selected before.
 *
 * It sits alongside the high-risk floor in includes/audit/high-risk.php. The two are independent
 * and both subtract: read-only mode never unlocks anything, and unlocking the high-risk category
 * never re-opens a write while read-only mode is on.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether read-only mode is currently in force.
 *
 * The filter is checked after the option and is one-directional: it can force the mode ON, never
 * off. Same asymmetry as aafm_force_block_high_risk_abilities() and for the same reason - nothing
 * a site adds should be able to widen what an agent can reach.
 *
 * @return bool
 */
function aafm_read_only_mode(): bool {
	$on = (bool) get_option( 'aafm_read_only_mode', false );

	/**
	 * Force read-only mode on regardless of the settings-screen value.
	 *
	 * One directional on purpose: a filter can turn this on, never off. Nothing a site adds
	 * should be able to widen what an agent can reach.
	 *
	 * @param bool $forced Whether to force read-only mode on.
	 */
	if ( (bool) apply_filters( 'aafm_force_read_only_mode', false ) ) {
		return true;
	}

	return $on;
}

/**
 * Whether an ability only reads.
 *
 * Resolves risk from the right place per namespace: the registry's own 'risk' key for a native
 * aafm/* ability, and the self-declared annotations (through aafm_bridge_risk()) for a foreign
 * slug or one of our aafm-bridge/* wrappers.
 *
 * Fails closed in every direction. A native name the registry does not carry, a foreign slug whose
 * plugin is not currently active, and an annotation-free bridged ability all come back false, so
 * anything that cannot be positively classified as a read is treated as a write.
 *
 * @param string $name Ability name: native aafm/*, a bridged aafm-bridge/* wrapper, or a foreign slug.
 * @return bool
 */
function aafm_ability_is_read( string $name ): bool {
	if ( '' === $name ) {
		return false;
	}

	// The full view, not the live one: an integration whose host plugin is inactive still has to
	// classify correctly, or a WooCommerce read would look like a write on a site with WooCommerce
	// switched off.
	$registry = aafm_get_abilities_registry_full();
	if ( isset( $registry[ $name ] ) ) {
		return 'read' === (string) ( $registry[ $name ]['risk'] ?? '' );
	}

	// A native name with no registry row is not classifiable. Never guess it into a read.
	if ( 0 === strpos( $name, 'aafm/' ) ) {
		return false;
	}

	if ( ! function_exists( 'wp_get_ability' ) || ! function_exists( 'wp_has_ability' ) ) {
		return false;
	}

	// wp_has_ability() first: wp_get_ability() on an unregistered slug raises a _doing_it_wrong
	// notice, and asking about a slug whose host plugin is inactive is an ordinary, expected case
	// here, not a mistake worth a notice on every admin render.
	if ( ! wp_has_ability( $name ) ) {
		return false;
	}

	$ability = wp_get_ability( $name );
	if ( ! $ability instanceof WP_Ability ) {
		return false;
	}

	$risk = aafm_bridge_risk( $ability );

	return 'read' === $risk['risk'];
}

/**
 * Why an ability cannot be toggled right now, or null when it can.
 *
 * The single lock predicate. There is deliberately no second "is it locked" boolean anywhere:
 * 1.5.0 shipped a bug because two renderers disagreed about lock state, and the fix was one shared
 * helper. This answers "is it locked" and "why" together so a row and its note can never drift.
 *
 * Read-only is checked first on purpose. When both floors apply, the note has to send the operator
 * to the switch that is actually holding the ability down: turning off the high-risk category while
 * read-only mode is on would change nothing.
 *
 * Safe for a NATIVE or a BRIDGED/foreign name. aafm_ability_is_high_risk() (which this falls
 * through to) is namespace-aware: it returns false outright for anything not starting with
 * "aafm/", so a foreign slug named in the public aafm_high_risk_abilities filter can never make
 * this report 'high_risk' for it. See includes/admin/bridge-directory.php for the caller this
 * unblocked.
 *
 * @param string $name Ability name, native or bridged.
 * @return string|null 'read_only', 'high_risk', or null when the ability is freely toggleable.
 */
function aafm_ability_lock_reason( string $name ): ?string {
	if ( aafm_read_only_mode() && ! aafm_ability_is_read( $name ) ) {
		return 'read_only';
	}
	if ( aafm_ability_is_locked( $name ) ) {
		return 'high_risk';
	}
	return null;
}

/**
 * The one line of copy that explains a locked row, keyed by lock reason.
 *
 * Shared by both ability renderers so the note can never say "high-risk" on a row the read-only
 * floor is holding, or the other way round.
 *
 * @param string $reason Lock reason from aafm_ability_lock_reason().
 * @return string Translated note, or '' for an unrecognised reason.
 */
function aafm_ability_lock_note( string $reason ): string {
	if ( 'read_only' === $reason ) {
		return __( 'Locked while read-only mode is on. Turn off Read-only mode under Settings to make this available.', 'agent-abilities-for-mcp' );
	}
	if ( 'high_risk' === $reason ) {
		return __( 'Locked. Turn on High-risk abilities under Settings to make this available.', 'agent-abilities-for-mcp' );
	}
	return '';
}

/**
 * The site's current exposure posture, as the page header states it.
 *
 * Computed from the same helpers the floors use rather than from the raw options, so the header can
 * never claim read-only while something is actually registered, and never claim read plus write
 * while a filter is holding the mode on.
 *
 * @return array{state:string,variant:string,label:string} State key, pill variant, and pill label.
 */
function aafm_exposure_posture(): array {
	if ( aafm_read_only_mode() ) {
		return array(
			'state'   => 'read_only',
			'variant' => 'success',
			'label'   => __( 'Read-only', 'agent-abilities-for-mcp' ),
		);
	}

	if ( aafm_high_risk_unlocked() ) {
		return array(
			'state'   => 'high_risk_unlocked',
			'variant' => 'warn',
			'label'   => __( 'Read + write, high-risk unlocked', 'agent-abilities-for-mcp' ),
		);
	}

	return array(
		'state'   => 'read_write',
		'variant' => 'neutral',
		'label'   => __( 'Read + write', 'agent-abilities-for-mcp' ),
	);
}

/**
 * Record a change to the read-only mode switch.
 *
 * Mirrors aafm_log_high_risk_switch_change(): the ability column carries a synthetic ability-like
 * name because both the admin table and its AJAX re-render put that value straight into the Event
 * cell, so a blank one renders as a blank row.
 *
 * @param bool $before Previous value.
 * @param bool $after  New value.
 * @return void
 */
function aafm_log_read_only_switch_change( bool $before, bool $after ): void {
	if ( $before === $after ) {
		return;
	}
	$user = wp_get_current_user();
	aafm_log_activity(
		array(
			'ability'           => 'aafm/read-only-mode',
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
			'status'            => 'success',
			'event_type'        => 'setting_changed',
			'detail'            => $after
				? __( 'Read-only mode turned on', 'agent-abilities-for-mcp' )
				: __( 'Read-only mode turned off', 'agent-abilities-for-mcp' ),
		)
	);
}

/**
 * Persist the read-only mode switch.
 *
 * Off deletes the row rather than storing a falsy value: off is the option's out-of-the-box state,
 * and an explicit false is a state a fresh install can never be in. Same reasoning, and the same
 * fix, as the high-risk switch in 1.5.0.
 *
 * @param bool $on Whether read-only mode should be on.
 * @return void
 */
function aafm_set_read_only_mode( bool $on ): void {
	if ( $on ) {
		update_option( 'aafm_read_only_mode', true );
		return;
	}
	delete_option( 'aafm_read_only_mode' );
}
