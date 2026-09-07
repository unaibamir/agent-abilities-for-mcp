<?php
/**
 * The single point of truth for which WP_Ability object this plugin's OWN registration actually
 * produced for a name.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Ability' ) ) {
	return; // Abilities API absent (WP below the 6.9 floor): nothing registers, so nothing to record.
}

/**
 * Codex round 11 R11-3: the R10-4 identity check (object identity, not class) closed the public-
 * subclass forgery, but the record it compares against used to be writable through a public,
 * two-argument global function - aafm_remember_registered_ability( $name, $ability ) - that
 * trusted whatever WP_Ability object it was handed. That is forgeable no matter what the function
 * is named or which file declares it: PHP gives standalone functions no real access control, and
 * this plugin is open source on wordpress.org, so an unfamiliar name is no obstacle to a
 * determined reader. A plugin loaded before AAFM's own wp_abilities_api_init callback could
 * register a reserved, enabled name directly with core's wp_register_ability() (bypassing every
 * one of this plugin's decorators entirely), then call that setter itself so the record - and
 * therefore aafm_build_server_tools()'s identity check in server.php - believed the resulting
 * undecorated object was this plugin's own. R9-7's original consequence, reached a third way.
 *
 * What actually closes it: self::register() is the ONLY thing that ever writes $store, and it
 * does not accept a ready-made ability - it performs the wp_register_ability() call itself and
 * records only what THAT call returns. There is no parameter through which a caller can
 * substitute a different object, so the record can never hold anything but the direct result of
 * a registration made through this class.
 *
 * This does not, and cannot, restrict WHO may call register(). PHP has no way to grant one
 * specific global function privileged access to a class member that a foreign plugin's code does
 * not equally have, once that member must be public for aafm_register_ability_with_log()
 * (register.php) - itself an ordinary global function - to reach it at all. What makes an
 * ordinary call safe is that its one legitimate caller has already wrapped $args's
 * permission_callback and execute_callback in this plugin's own permission, allowlist, rate-
 * limit and audit decorators before this point runs, for ANY $args it is handed, regardless of
 * who is calling - so a call routed through the normal path is always properly governed. The
 * caller this cannot defend against is code that skips aafm_register_ability_with_log() and
 * calls THIS class directly with its own, undecorated $args: that call would still register and
 * record an undecorated ability. Closing that fully would require folding
 * aafm_register_ability_with_log()'s decoration logic into this same class as register()'s only
 * caller, which was not done here - see the note on aafm_register_ability_with_log() in
 * register.php.
 */
final class AAFM_Registration_Authority {

	/**
	 * The WP_Ability object this plugin's own registration produced, keyed by ability name.
	 *
	 * @var array<string,WP_Ability>
	 */
	private static $store = array();

	/**
	 * Register $name with core and, only when that call actually succeeds, record the resulting
	 * object as this plugin's own. The store never holds anything but wp_register_ability()'s own
	 * return value - never a caller-supplied object.
	 *
	 * @param string              $name Ability name.
	 * @param array<string,mixed> $args wp_register_ability() args.
	 * @return WP_Ability|null Whatever wp_register_ability() returns.
	 */
	public static function register( string $name, array $args ): ?WP_Ability {
		$registered = wp_register_ability( $name, $args );
		if ( $registered instanceof WP_Ability ) {
			self::$store[ $name ] = $registered;
		}
		return $registered;
	}

	/**
	 * Read-only: the object this plugin's own registration produced for $name, or null if this
	 * plugin never registered it. Self-invalidating: this compares against whatever is CURRENTLY
	 * stored, so a later registration that replaces $store[$name] makes any earlier answer stale
	 * on its own, with nothing else to reset.
	 *
	 * @param string $name Ability name.
	 * @return WP_Ability|null
	 */
	public static function owned( string $name ): ?WP_Ability {
		return self::$store[ $name ] ?? null;
	}
}
