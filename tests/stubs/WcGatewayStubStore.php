<?php
/**
 * Process-wide backing store for the WooCommerce payment gateway stubs (Wave 4 / W4-WC7 integration tests).
 *
 * Lives in its own file so the IntegrationStubs trait file holds a single object structure (the
 * trait), satisfying Generic.Files.OneObjectStructurePerFile. Required directly from the test
 * bootstrap, never shipped.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

/**
 * Process-wide backing store for the WooCommerce payment gateway stubs.
 */
class WcGatewayStubStore {

	/**
	 * Gateways keyed by gateway id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public static array $gateways = array();

	/**
	 * When true, update_option() rejects the write entirely (value is NOT persisted), so a read-back
	 * verification sees the old value and the executor's mismatch path is exercisable. This models a
	 * genuine persistence failure, distinct from WooCommerce returning false for an unchanged value.
	 *
	 * @var bool
	 */
	public static bool $force_save_failure = false;

	/**
	 * Clear all state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$gateways           = array();
		self::$force_save_failure = false;
	}

	/**
	 * Seed default payment gateway fixtures into the store.
	 *
	 * Seeds two gateways: paypal (with an api_secret setting) and stripe (with a stripe_secret
	 * setting). The secret values are chosen so tests can assert they do NOT appear in output.
	 *
	 * @return void
	 */
	public static function seed(): void {
		self::$gateways = array(
			'paypal' => array(
				'id'          => 'paypal',
				'title'       => 'PayPal',
				'description' => 'Pay via PayPal.',
				'enabled'     => 'yes',
				'order'       => 1,
				'settings'    => array(
					'title'      => 'PayPal',
					'api_secret' => 'super-secret-value',
				),
			),
			'stripe' => array(
				'id'          => 'stripe',
				'title'       => 'Stripe',
				'description' => 'Pay via Stripe.',
				'enabled'     => 'no',
				'order'       => 2,
				'settings'    => array(
					'title'         => 'Stripe',
					'stripe_secret' => 'stripe_secret_value',
				),
			),
		);
	}

	/**
	 * Whether a gateway id exists in the store.
	 *
	 * @param string $id Gateway id.
	 * @return bool
	 */
	public static function exists( string $id ): bool {
		return isset( self::$gateways[ $id ] );
	}

	/**
	 * The stored data for a gateway id, or null.
	 *
	 * @param string $id Gateway id.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		return self::$gateways[ $id ] ?? null;
	}

	/**
	 * All stored gateways.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		return self::$gateways;
	}

	/**
	 * Persist an update_option() call on a gateway setting.
	 *
	 * Mirrors WordPress update_option()/WC_Settings_API semantics: the return value reflects whether
	 * the stored value CHANGED - false when the new value equals the existing one (no write needed)
	 * or when the gateway is unknown, true when a different value was written. The value is persisted
	 * whenever it differs. When $force_save_failure is set, the write is rejected outright (value not
	 * persisted) to simulate a genuine persistence failure for read-back verification tests.
	 *
	 * @param string $gateway_id Gateway id.
	 * @param string $key        Setting key.
	 * @param mixed  $value      Setting value.
	 * @return bool
	 */
	public static function update_option( string $gateway_id, string $key, mixed $value ): bool {
		if ( ! isset( self::$gateways[ $gateway_id ] ) ) {
			return false;
		}
		if ( self::$force_save_failure ) {
			// Reject the write: nothing persists, so a subsequent read-back sees the old value.
			return false;
		}
		$existing = self::$gateways[ $gateway_id ]['settings'][ $key ] ?? null;
		if ( $existing === $value ) {
			// Unchanged - WordPress update_option() returns false but the stored value already holds it.
			return false;
		}
		self::$gateways[ $gateway_id ]['settings'][ $key ] = $value;
		// Mirror top-level fields that are aliased from settings.
		if ( 'title' === $key ) {
			self::$gateways[ $gateway_id ]['title'] = (string) $value;
		}
		if ( 'description' === $key ) {
			self::$gateways[ $gateway_id ]['description'] = (string) $value;
		}
		if ( 'enabled' === $key ) {
			self::$gateways[ $gateway_id ]['enabled'] = (string) $value;
		}
		return true;
	}

	/**
	 * Persist a full gateway save (merge data into store).
	 *
	 * @param string              $gateway_id Gateway id.
	 * @param array<string,mixed> $data       Fields to merge.
	 * @return bool
	 */
	public static function save_gateway( string $gateway_id, array $data ): bool {
		if ( self::$force_save_failure ) {
			return false;
		}
		if ( ! isset( self::$gateways[ $gateway_id ] ) ) {
			return false;
		}
		self::$gateways[ $gateway_id ] = array_merge( self::$gateways[ $gateway_id ], $data );
		return true;
	}
}
