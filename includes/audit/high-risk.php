<?php
/**
 * The high-risk floor: the abilities that stay unreachable until the operator says otherwise.
 *
 * Membership is a security decision, not a preference, so both filters here are one-directional:
 * one can only widen the locked set, the other can only force it shut. Neither can open it. The
 * settings screen is the only thing that unlocks the category, and it takes a second deliberate
 * action in a second place to do it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The abilities that move money or grant authority, and are therefore locked until the operator
 * deliberately unlocks the category.
 *
 * These are not ordinary write abilities. Every one of them was verified by reading its execute
 * callback, and every one is otherwise gated by nothing but a capability check and the same
 * checkbox that governs a read. The floor exists so that reaching this category takes two
 * deliberate actions in two separate places rather than one tick among many.
 *
 * @return list<string>
 */
function aafm_high_risk_abilities_builtin(): array {
	return array(
		'aafm/wc-create-order-refund',
		'aafm/wc-update-order-status',
		'aafm/wc-update-order',
		'aafm/wc-update-customer',
		'aafm/wc-update-payment-gateway',
		'aafm/wc-create-coupon',
		'aafm/wc-update-coupon',
		'aafm/wc-create-tax-rate',
		'aafm/wc-update-tax-rate',
	);
}

/**
 * The full high-risk set: the built-ins, plus anything a filter adds.
 *
 * The built-ins are re-merged AFTER the filter runs, so a filter can only ever add an ability to
 * the locked category, never remove one. Same asymmetry as aafm_hard_blocked_meta_key(), and for
 * the same reason: a filter that could narrow this list would be a filter that could switch the
 * floor off.
 *
 * @return list<string>
 */
function aafm_high_risk_abilities(): array {
	$builtin = aafm_high_risk_abilities_builtin();

	/**
	 * Filters EXTRA ability names to treat as high-risk. Built-ins are re-merged after, so
	 * this can only ADD abilities to the locked set, never remove one.
	 *
	 * Covers native aafm/* abilities ONLY. Naming a bridged ability (aafm-bridge/*) here has
	 * no effect: the bridge keeps its own enabled option and its own registration walk, and
	 * aafm_all_server_ability_names() merges bridged names in after the floor has already run
	 * over the native set. Locking a neighbouring plugin's ability is not something this
	 * filter can do yet, and there is no warning if you try.
	 *
	 * @param list<string> $extra Extra ability names.
	 */
	$extra = apply_filters( 'aafm_high_risk_abilities', array() );
	$extra = is_array( $extra ) ? array_map( 'strval', $extra ) : array();

	return array_values( array_unique( array_merge( $extra, $builtin ) ) );
}

/**
 * Persist the high-risk unlock switch.
 *
 * Off deletes the row rather than storing a falsy value: locked is the option's out-of-the-box
 * (row-absent) state, and an explicit false is a state a fresh install can never be in. The write
 * goes through aafm_persist_operator_switch(), which also clears any stale persistent-cache copy
 * and reads the value back, so a lock that a stale cache would otherwise swallow is caught.
 *
 * @param bool $unlocked Whether the category should be unlocked.
 * @return bool True when the switch now reads back as $unlocked.
 */
function aafm_set_high_risk_unlocked( bool $unlocked ): bool {
	return aafm_persist_operator_switch( 'aafm_high_risk_abilities_unlocked', $unlocked );
}

/**
 * Whether the operator has unlocked the high-risk category.
 *
 * The code filter is checked AFTER the option and can only force the floor shut, never open it:
 * an agency or managed host can guarantee the category stays unreachable regardless of what a
 * site admin later flips in wp-admin, and no filter can grant what the settings screen refused.
 *
 * @return bool
 */
function aafm_high_risk_unlocked(): bool {
	$unlocked = (bool) get_option( 'aafm_high_risk_abilities_unlocked', false );

	/**
	 * Force the high-risk floor shut regardless of the settings-screen value.
	 *
	 * @param bool $forced Whether to force the floor shut.
	 */
	if ( (bool) apply_filters( 'aafm_force_block_high_risk_abilities', false ) ) {
		return false;
	}

	return $unlocked;
}

/**
 * Whether an ability belongs to the high-risk category. Independent of the lock state: a
 * high-risk ability stays high-risk (and stays badged) once unlocked and enabled.
 *
 * @param string $name Ability name.
 * @return bool
 */
function aafm_ability_is_high_risk( string $name ): bool {
	// Namespace-aware: the high-risk set only ever governs NATIVE aafm/* abilities. A bridged
	// wrapper (aafm-bridge/*) or a foreign slug must never match here, even when a site names one
	// in the aafm_high_risk_abilities filter - that filter's own docblock already says naming a
	// bridged ability there has no effect, because the high-risk floor only subtracts inside
	// aafm_get_enabled_abilities(), which walks the native registry and never touches the bridge.
	// Without this guard, aafm_ability_is_locked() (and therefore aafm_ability_lock_reason())
	// would still report a lock the floor does not enforce - the exact class of defect 1.5.0
	// shipped. This is what makes it safe for aafm_ability_lock_reason() to accept a bridged slug;
	// see that function's docblock in includes/audit/read-only.php.
	if ( ! str_starts_with( $name, 'aafm/' ) ) {
		return false;
	}
	return in_array( $name, aafm_high_risk_abilities(), true );
}

/**
 * Whether an ability is currently locked: high-risk AND the category is not unlocked.
 *
 * @param string $name Ability name.
 * @return bool
 */
function aafm_ability_is_locked( string $name ): bool {
	return aafm_ability_is_high_risk( $name ) && ! aafm_high_risk_unlocked();
}
