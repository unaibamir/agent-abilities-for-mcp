<?php
/**
 * Process-wide test doubles for the Site Ops redirect tools.
 *
 * Two layers. The fake adapters (fake_a, fake_b, fake_c) are adapter functions with our own
 * prefix, backed by Aafm_Siteops_Redirect_Stub; a test puts them in the registry through the
 * aafm_site_redirect_adapters filter, so no vendor symbol is defined and "not detected",
 * "not ready" and "throws" can be tested in any order. The vendor stubs further down define the
 * real vendor names, and only when a test calls an activate function, because a defined
 * function, class or constant cannot be undefined for the rest of the process.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

/**
 * Backing store and call recorder for the fake adapters and the vendor stubs.
 */
final class Aafm_Siteops_Redirect_Stub {

	/**
	 * Per fake slug: ready, can, rows, truncated, log404, throw.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public static $fake = array();

	/**
	 * Every adapter or vendor call, as "name" or "name:detail".
	 *
	 * @var array<int,string>
	 */
	public static $calls = array();

	/**
	 * Vendor stub data, keyed by vendor.
	 *
	 * @var array<string,mixed>
	 */
	public static $vendor = array();

	/**
	 * Whether any vendor stub was activated in this process.
	 *
	 * @var bool
	 */
	public static $vendors_activated = false;

	/**
	 * Forget every fake adapter setting, every recorded call and all vendor data.
	 */
	public static function reset(): void {
		self::$fake   = array();
		self::$calls  = array();
		self::$vendor = array();
	}

	/**
	 * A registry entry for a fake adapter: always detected.
	 *
	 * @param string              $label     Label.
	 * @param array<string,mixed> $overrides Keys to change.
	 * @return array<string,mixed>
	 */
	public static function entry( string $label, array $overrides = array() ): array {
		return array_merge(
			array(
				'label'            => $label,
				'plugins'          => array(),
				'detect'           => array(),
				'has_404'          => true,
				'match_path'       => 'full',
				'relative_targets' => 'host_root',
			),
			$overrides
		);
	}

	/**
	 * One normalised rule for a fake adapter.
	 *
	 * @param string              $id     Id.
	 * @param string              $source Source.
	 * @param string              $target Target.
	 * @param array<string,mixed> $more   Keys to change.
	 * @return array<string,mixed>
	 */
	public static function rule( string $id, string $source, string $target, array $more = array() ): array {
		return array_merge(
			array(
				'id'      => $id,
				'source'  => $source,
				'target'  => $target,
				'code'    => 301,
				'enabled' => true,
				'kind'    => 'exact',
				'hits'    => null,
			),
			$more
		);
	}

	/**
	 * How many times a call was recorded.
	 *
	 * @param string $name Recorded name.
	 */
	public static function count( string $name ): int {
		return count( array_keys( self::$calls, $name, true ) );
	}

	/**
	 * Run one fake adapter function.
	 *
	 * @param string           $slug Fake slug.
	 * @param string           $name Function.
	 * @param array<int,mixed> $args Arguments.
	 * @return mixed
	 * @throws \RuntimeException When the test asked this function to throw.
	 */
	public static function fake( string $slug, string $name, array $args = array() ) {
		$conf          = self::$fake[ $slug ] ?? array();
		self::$calls[] = $slug . '.' . $name . ( isset( $args[0] ) && is_string( $args[0] ) ? ':' . $args[0] : '' );
		if ( in_array( $name, (array) ( $conf['throw'] ?? array() ), true ) ) {
			throw new \RuntimeException( 'fake adapter failure' );
		}

		switch ( $name ) {
			case 'ready':
				return $conf['ready'] ?? true;
			case 'can':
				return $conf['can'][ $args[0] ] ?? true;
			case 'all':
				self::$calls[] = $slug . '.list_read';
				return $conf['all'] ?? array(
					'rows'      => $conf['rows'] ?? array(),
					'truncated' => $conf['truncated'] ?? false,
				);
			case 'list_404s':
				return $conf['log404'] ?? array();
		}
		return null;
	}

	/**
	 * The fake matcher: exact rules compared by key, in list order. A rule with 'unknown' => true
	 * stands for an enabled rule that cannot be evaluated.
	 *
	 * @param string              $slug  Fake slug.
	 * @param string              $path  Path as the dispatcher passed it.
	 * @param array<string,mixed> $cache Per-call cache.
	 * @return mixed
	 * @throws \RuntimeException When the test asked match to throw.
	 */
	public static function fake_match( string $slug, string $path, array &$cache ) {
		$conf          = self::$fake[ $slug ] ?? array();
		self::$calls[] = $slug . '.match:' . $path;
		if ( in_array( 'match', (array) ( $conf['throw'] ?? array() ), true ) ) {
			throw new \RuntimeException( 'fake adapter failure' );
		}
		if ( array_key_exists( 'match_return', $conf ) ) {
			return $conf['match_return'];
		}
		if ( ! isset( $cache['rows'] ) ) {
			self::$calls[] = $slug . '.list_read';
			$cache['rows'] = $conf['rows'] ?? array();
		}

		$unknown = false;
		foreach ( $cache['rows'] as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( ! empty( $rule['unknown'] ) ) {
				$unknown = true;
				continue;
			}
			if ( strtolower( rtrim( (string) $rule['source'], '/' ) ) !== strtolower( rtrim( $path, '/' ) ) ) {
				continue;
			}
			return $unknown
				? array( 'state' => 'unknown' )
				: array(
					'state'  => 'matched',
					'id'     => (string) $rule['id'],
					'target' => (string) $rule['target'],
					'code'   => (int) $rule['code'],
				);
		}
		return array( 'state' => $unknown ? 'unknown' : 'no_match' );
	}

	/**
	 * Keeps redirect detection off by default once a vendor stub defined a detection symbol, so
	 * no later test sees the site_redirects integration switch itself on. A test that wants it
	 * on forces it with aafm_integration_active_site_redirects.
	 *
	 * @param mixed $active Detected state.
	 * @return bool
	 */
	public static function detection_default( $active ): bool {
		return self::$vendors_activated ? false : (bool) $active;
	}
}

add_filter( 'aafm_site_redirects_active', array( 'Aafm_Siteops_Redirect_Stub', 'detection_default' ), 1 );

foreach ( array( 'fake_a', 'fake_b', 'fake_c' ) as $aafm_fake_slug ) {
	// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Generates the five adapter functions of one fake adapter; test fixture only, never shipped.
	eval(
		"function aafm_redirect_{$aafm_fake_slug}_ready() { return Aafm_Siteops_Redirect_Stub::fake( '{$aafm_fake_slug}', 'ready' ); }"
		. "function aafm_redirect_{$aafm_fake_slug}_can( string \$action ) { return Aafm_Siteops_Redirect_Stub::fake( '{$aafm_fake_slug}', 'can', array( \$action ) ); }"
		. "function aafm_redirect_{$aafm_fake_slug}_all() { return Aafm_Siteops_Redirect_Stub::fake( '{$aafm_fake_slug}', 'all' ); }"
		. "function aafm_redirect_{$aafm_fake_slug}_match( string \$path, array &\$cache ) { return Aafm_Siteops_Redirect_Stub::fake_match( '{$aafm_fake_slug}', \$path, \$cache ); }"
		. "function aafm_redirect_{$aafm_fake_slug}_list_404s() { return Aafm_Siteops_Redirect_Stub::fake( '{$aafm_fake_slug}', 'list_404s' ); }"
	);
}
unset( $aafm_fake_slug );
