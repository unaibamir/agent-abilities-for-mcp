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

/*
 * Vendor stubs. Each activate function defines only what the adapter of that vendor calls, in the
 * vendor's real names and with the vendor's real return shapes, and can be called from every
 * test's set_up(). None of them defines REDIRECTION_VERSION, RANK_MATH_VERSION or SLIM_SEO_VER:
 * a test that needs an adapter detected empties its registry detect list through the filter.
 */

/**
 * 301 Redirects (EPS): the class and its table. Rules are objects in Aafm_Siteops_Redirect_Stub::$vendor['eps'], newest first.
 */
function aafm_siteops_redirect_stub_activate_eps(): void {
	global $wpdb;
	Aafm_Siteops_Redirect_Stub::$vendors_activated = true;
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}redirects ( id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, url_from VARCHAR(256), url_to VARCHAR(256), status VARCHAR(12), type VARCHAR(12), count INT )" );

	if ( ! class_exists( 'EPS_Redirects', false ) ) {
		class EPS_Redirects {
			public static function get_redirects( $active_only = false ) {
				Aafm_Siteops_Redirect_Stub::$calls[]             = 'EPS_Redirects::get_redirects:' . ( $active_only ? 'active' : 'all' );
				Aafm_Siteops_Redirect_Stub::$vendor['eps_saw_get'] = isset( $_GET['orderby'] );
				if ( array_key_exists( 'eps_return', Aafm_Siteops_Redirect_Stub::$vendor ) ) {
					return Aafm_Siteops_Redirect_Stub::$vendor['eps_return'];
				}
				$rows = Aafm_Siteops_Redirect_Stub::$vendor['eps'] ?? array();
				if ( $active_only ) {
					$rows = array_values(
						array_filter(
							$rows,
							static function ( $row ) {
								return 'inactive' !== $row->status;
							}
						)
					);
				}
				return $rows;
			}
		}
	}
}

/**
 * Redirection: its option reader, item model, URL classes and grouped 404 reader. Rules are
 * to_json() arrays in $vendor['red']; 404 groups are objects in $vendor['red_404'].
 */
function aafm_siteops_redirect_stub_activate_redirection(): void {
	Aafm_Siteops_Redirect_Stub::$vendors_activated = true;
	if ( ! defined( 'REDIRECTION_DB_VERSION' ) ) {
		define( 'REDIRECTION_DB_VERSION', '4.2' );
	}
	if ( class_exists( 'Red_Item', false ) ) {
		return;
	}

	class Red_Options {
		public static function get() {
			return Aafm_Siteops_Redirect_Stub::$vendor['red_options'] ?? array( 'database' => '4.2' );
		}
		public static function reset() {
			Aafm_Siteops_Redirect_Stub::$calls[] = 'Red_Options::reset';
		}
	}

	class Aafm_Stub_Red_Match {
		private $row;
		public function __construct( array $row ) {
			$this->row = $row;
		}
		public function is_match( $url ) {
			return true;
		}
		public function get_target_url( $original_url, $matched_url, $flags, $is_matched ) {
			return isset( $this->row['action_data']['url'] ) ? $this->row['action_data']['url'] : false;
		}
	}

	class Red_Item {
		public $source_flags;
		public $match;
		private $row;
		public function __construct( array $row ) {
			$this->row          = $row;
			$this->source_flags = (object) array( 'regex' => ! empty( $row['regex'] ) );
			$this->match        = array_key_exists( 'stub_match', $row ) ? $row['stub_match'] : new Aafm_Stub_Red_Match( $row );
		}
		public static function get_filtered( array $params ) {
			Aafm_Siteops_Redirect_Stub::$calls[] = 'Red_Item::get_filtered:' . (int) ( $params['page'] ?? 0 );
			if ( array_key_exists( 'red_return', Aafm_Siteops_Redirect_Stub::$vendor ) ) {
				return Aafm_Siteops_Redirect_Stub::$vendor['red_return'];
			}
			$rows  = Aafm_Siteops_Redirect_Stub::$vendor['red'] ?? array();
			$limit = min( 200, max( 5, (int) ( $params['per_page'] ?? 25 ) ) );
			return array(
				'items' => array_slice( $rows, max( 0, (int) ( $params['page'] ?? 0 ) ) * $limit, $limit ),
				'total' => count( $rows ),
			);
		}
		public static function get_for_url( $url ) {
			Aafm_Siteops_Redirect_Stub::$calls[] = 'Red_Item::get_for_url:' . $url;
			if ( ! empty( Aafm_Siteops_Redirect_Stub::$vendor['red_cache_on'] ) ) {
				wp_cache_set( 'aafm-stub-' . md5( (string) $url ), 1, 'redirection' );
			}
			$items = array();
			foreach ( Aafm_Siteops_Redirect_Stub::$vendor['red'] ?? array() as $row ) {
				if ( ! empty( $row['enabled'] ) ) {
					$items[] = new self( $row );
				}
			}
			return $items;
		}
		public function get_url() {
			return $this->row['url'];
		}
		public function get_id() {
			return $this->row['id'];
		}
		public function get_action_code() {
			return $this->row['action_code'];
		}
	}

	class Red_Url {
		private $url;
		public function __construct( $url = '' ) {
			$this->url = $url;
		}
		public function is_match( $requested_url, $flags ) {
			if ( ! empty( $flags->regex ) ) {
				return 1 === preg_match( '@' . str_replace( '@', '\\@', $this->url ) . '@', $requested_url );
			}
			return $requested_url === $this->url;
		}
	}

	class Red_Url_Request {
		private $url;
		public function __construct( $url ) {
			$this->url = $url;
		}
		public function get_original_url() {
			return $this->url;
		}
		public function get_decoded_url() {
			return rawurldecode( $this->url );
		}
		public function is_protected_url() {
			return 0 === strpos( $this->url, '/wp-json' );
		}
	}

	class Red_404_Log {
		public static function get_grouped( $group, array $params ) {
			Aafm_Siteops_Redirect_Stub::$calls[] = 'Red_404_Log::get_grouped:' . $group;
			if ( array_key_exists( 'red_404_return', Aafm_Siteops_Redirect_Stub::$vendor ) ) {
				return Aafm_Siteops_Redirect_Stub::$vendor['red_404_return'];
			}
			$rows = Aafm_Siteops_Redirect_Stub::$vendor['red_404'] ?? array();
			return array(
				'items' => $rows,
				'total' => count( $rows ),
			);
		}
	}
}

/**
 * One Redirection rule in the plugin's to_json() shape.
 *
 * @param int                 $id     Id.
 * @param string              $url    Source.
 * @param string              $target Target.
 * @param array<string,mixed> $more   Keys to change.
 * @return array<string,mixed>
 */
function aafm_siteops_redirect_stub_red_row( int $id, string $url, string $target, array $more = array() ): array {
	return array_merge(
		array(
			'id'          => $id,
			'url'         => $url,
			'match_url'   => $url,
			'action_code' => 301,
			'action_type' => 'url',
			'action_data' => array( 'url' => $target ),
			'match_type'  => 'url',
			'hits'        => 0,
			'regex'       => false,
			'enabled'     => true,
		),
		$more
	);
}

/**
 * Safe Redirect Manager: the post type and the functions the adapter calls, plus the two it must
 * never call, which record the call and write the transient exactly as the plugin would.
 */
function aafm_siteops_redirect_stub_activate_srm(): void {
	Aafm_Siteops_Redirect_Stub::$vendors_activated = true;
	if ( ! post_type_exists( 'redirect_rule' ) ) {
		register_post_type( 'redirect_rule', array( 'public' => false ) );
	}
	if ( function_exists( 'srm_get_redirect_data' ) ) {
		return;
	}

	function srm_get_redirect_data( $redirect_id, $optional_fields = array() ) {
		return array(
			'ID'            => $redirect_id,
			'redirect_from' => get_post_meta( $redirect_id, '_redirect_rule_from', true ),
			'redirect_to'   => get_post_meta( $redirect_id, '_redirect_rule_to', true ),
			'status_code'   => (int) get_post_meta( $redirect_id, '_redirect_rule_status_code', true ),
			'message'       => get_post_meta( $redirect_id, '_redirect_rule_message', true ),
			'enable_regex'  => (bool) get_post_meta( $redirect_id, '_redirect_rule_from_regex', true ),
			'force_https'   => get_post_meta( $redirect_id, '_force_https', true ),
		);
	}
	function srm_get_max_redirects() {
		return apply_filters( 'srm_max_redirects', 1000 );
	}
	function srm_get_valid_status_codes() {
		return apply_filters( 'srm_valid_status_codes', array( 301, 302, 303, 307, 403, 404, 410 ) );
	}
	function srm_get_redirects( $args = array(), $hard = false ) {
		Aafm_Siteops_Redirect_Stub::$calls[] = 'srm_get_redirects';
		set_transient( '_srm_redirects_' . srm_get_max_redirects(), array(), 30 * DAY_IN_SECONDS );
		return array();
	}
	function srm_match_redirect( $requested_path ) {
		Aafm_Siteops_Redirect_Stub::$calls[] = 'srm_match_redirect';
		srm_get_redirects();
		return false;
	}
}

/**
 * Create one Safe Redirect Manager rule the way the plugin stores it.
 *
 * @param string $from   Source.
 * @param string $to     Target.
 * @param int    $code   Status code.
 * @param bool   $regex  Regex flag.
 * @param string $status Post status: publish is on, draft is off.
 * @return int Post id.
 */
function aafm_siteops_redirect_stub_srm_rule( string $from, string $to, int $code = 301, bool $regex = false, string $status = 'publish' ): int {
	$id = (int) wp_insert_post(
		array(
			'post_type'   => 'redirect_rule',
			'post_status' => $status,
			'post_title'  => '',
		)
	);
	// Core unslashes meta values on the way in, so a pattern with a backslash is slashed first.
	update_post_meta( $id, '_redirect_rule_from', wp_slash( $from ) );
	update_post_meta( $id, '_redirect_rule_to', wp_slash( $to ) );
	update_post_meta( $id, '_redirect_rule_status_code', $code );
	update_post_meta( $id, '_redirect_rule_from_regex', $regex ? 1 : 0 );
	return $id;
}

/**
 * Rank Math: Helper, the redirections DB class, Redirection::strip_subdirectory(), its own front-end
 * redirect class and the 404 log table. Rules are rows in $vendor['rm'] in the plugin's stored shape
 * (sources serialized); modules in $vendor['rm_modules']; settings by their dotted id in
 * $vendor['rm_settings'].
 */
function aafm_siteops_redirect_stub_activate_rankmath(): void {
	global $wpdb;
	Aafm_Siteops_Redirect_Stub::$vendors_activated = true;
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rank_math_404_logs ( id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, uri VARCHAR(255) NOT NULL, accessed DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00', times_accessed BIGINT NOT NULL DEFAULT 1, referer VARCHAR(255) NOT NULL DEFAULT '', user_agent VARCHAR(255) NOT NULL DEFAULT '' )" );
	if ( class_exists( 'RankMath\\Helper', false ) ) {
		return;
	}

	class Aafm_Stub_Rank_Math_Helper {
		public static function is_module_active( $id ) {
			return in_array( $id, Aafm_Siteops_Redirect_Stub::$vendor['rm_modules'] ?? array(), true );
		}
		public static function get_settings( $field_id = '', $default_value = false ) {
			// The plugin's own reader: 'on' and 'off' come back as true and false, a missing key as the default.
			return array_key_exists( $field_id, Aafm_Siteops_Redirect_Stub::$vendor['rm_settings'] ?? array() ) ? Aafm_Siteops_Redirect_Stub::$vendor['rm_settings'][ $field_id ] : $default_value;
		}
		public static function is_invalid_registration() {
			return ! empty( Aafm_Siteops_Redirect_Stub::$vendor['rm_invalid'] );
		}
	}

	// Rank Math's own front-end class, which hooks rank_math/redirection/pre_search for its attachment and archive redirects.
	class Aafm_Stub_Rank_Math_Frontend_Redirection {
		public function pre_redirection( $check ) {
			return $check;
		}
	}

	class Aafm_Stub_Rank_Math_Db {
		public static function get_redirections( $args = array() ) {
			Aafm_Siteops_Redirect_Stub::$calls[] = 'RankMath::get_redirections:' . ( $args['status'] ?? 'any' ) . ':' . ( $args['paged'] ?? 1 );
			if ( array_key_exists( 'rm_return', Aafm_Siteops_Redirect_Stub::$vendor ) ) {
				return Aafm_Siteops_Redirect_Stub::$vendor['rm_return'];
			}
			$rows = array_values(
				array_filter(
					Aafm_Siteops_Redirect_Stub::$vendor['rm'] ?? array(),
					static function ( $row ) use ( $args ) {
						return ! isset( $args['status'] ) || 'any' === $args['status'] ? 'trashed' !== $row['status'] : $row['status'] === $args['status'];
					}
				)
			);
			$limit        = (int) ( $args['limit'] ?? 10 );
			$redirections = array_slice( $rows, ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * $limit, $limit );
			$count        = (string) count( $rows );
			return compact( 'redirections', 'count' );
		}
		public static function match_redirections( $uri, $all = false ) {
			Aafm_Siteops_Redirect_Stub::$calls[] = 'RankMath::match_redirections:' . $uri;
			if ( empty( $uri ) ) {
				return false;
			}
			foreach ( Aafm_Siteops_Redirect_Stub::$vendor['rm'] ?? array() as $row ) {
				if ( 'active' !== $row['status'] ) {
					continue;
				}
				$row['sources'] = maybe_unserialize( $row['sources'] );
				foreach ( (array) $row['sources'] as $source ) {
					$hit = 'regex' === $source['comparison'] ? 1 === preg_match( '@' . $source['pattern'] . '@', $uri ) : untrailingslashit( $uri ) === $source['pattern'];
					if ( $hit ) {
						return $row;
					}
				}
			}
			return false;
		}
	}

	class Aafm_Stub_Rank_Math_Redirection {
		// The plugin's own body (class-redirection.php:480-484): every occurrence of the home
		// directory name and a slash is removed, not only a leading one.
		public static function strip_subdirectory( $url ) {
			$home_dir = ltrim( home_url( '', 'relative' ), '/' );
			return $home_dir ? str_replace( trailingslashit( $home_dir ), '', $url ) : $url;
		}
	}

	class_alias( 'Aafm_Stub_Rank_Math_Helper', 'RankMath\\Helper' );
	class_alias( 'Aafm_Stub_Rank_Math_Db', 'RankMath\\Redirections\\DB' );
	class_alias( 'Aafm_Stub_Rank_Math_Redirection', 'RankMath\\Redirections\\Redirection' );
	class_alias( 'Aafm_Stub_Rank_Math_Frontend_Redirection', 'RankMath\\Frontend\\Redirection' );
}

/**
 * One Rank Math rule as the list reader returns it: every scalar a string, sources serialized.
 *
 * @param int                             $id      Id.
 * @param array<int,array<string,string>> $sources Sources.
 * @param string                          $to      Target, which the plugin stores absolute.
 * @param array<string,string>            $more    Keys to change.
 * @return array<string,string>
 */
function aafm_siteops_redirect_stub_rm_row( int $id, array $sources, string $to, array $more = array() ): array {
	return array_merge(
		array(
			'id'          => (string) $id,
			'sources'     => maybe_serialize( $sources ),
			'url_to'      => $to,
			'header_code' => '301',
			'hits'        => '0',
			'status'      => 'active',
		),
		$more
	);
}

/**
 * Slim SEO: the redirect list, its URL helper, its settings reader, the 404 log class and table.
 * Rules are in $vendor['slim'] keyed by id, newest first; settings in $vendor['slim_settings'].
 */
function aafm_siteops_redirect_stub_activate_slim_seo(): void {
	global $wpdb;
	Aafm_Siteops_Redirect_Stub::$vendors_activated = true;
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}slim_seo_404 ( id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, url VARCHAR(255) NOT NULL, hit BIGINT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP )" );
	if ( class_exists( 'SlimSEO\\Redirection\\Database\\Redirects', false ) ) {
		return;
	}

	class Aafm_Stub_Slim_Redirects {
		public function list() {
			Aafm_Siteops_Redirect_Stub::$calls[] = 'SlimSEO::list';
			if ( array_key_exists( 'slim_return', Aafm_Siteops_Redirect_Stub::$vendor ) ) {
				return Aafm_Siteops_Redirect_Stub::$vendor['slim_return'];
			}
			return Aafm_Siteops_Redirect_Stub::$vendor['slim'] ?? array();
		}
	}

	class Aafm_Stub_Slim_Helper {
		public static function normalize_url( string $url, $unslash = true, $ltrim = true, $rtrim = true ): string {
			$url = $unslash ? wp_unslash( $url ) : $url;
			$url = html_entity_decode( $url, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8' );
			$url = str_replace( apply_filters( 'slim_seo_redirection_home_url', home_url(), '' ), '', $url );
			$url = $ltrim ? ltrim( $url, '/' ) : $url;
			$url = $rtrim ? rtrim( $url, '/' ) : $url;
			return $url ? $url : '/';
		}
	}

	class Aafm_Stub_Slim_Settings {
		public static function get( string $name ) {
			return Aafm_Siteops_Redirect_Stub::$vendor['slim_settings'][ $name ] ?? false;
		}
	}

	class Aafm_Stub_Slim_Log404 {}

	class_alias( 'Aafm_Stub_Slim_Redirects', 'SlimSEO\\Redirection\\Database\\Redirects' );
	class_alias( 'Aafm_Stub_Slim_Helper', 'SlimSEO\\Redirection\\Helper' );
	class_alias( 'Aafm_Stub_Slim_Settings', 'SlimSEO\\Redirection\\Settings' );
	class_alias( 'Aafm_Stub_Slim_Log404', 'SlimSEO\\Redirection\\Database\\Log404' );
}

/**
 * One Slim SEO rule as the plugin stores it.
 *
 * @param string              $from Source, stored without a leading slash.
 * @param string              $to   Target.
 * @param array<string,mixed> $more Keys to change.
 * @return array<string,mixed>
 */
function aafm_siteops_redirect_stub_slim_rule( string $from, string $to, array $more = array() ): array {
	return array_merge(
		array(
			'type'             => 301,
			'condition'        => 'exact-match',
			'from'             => $from,
			'to'               => $to,
			'note'             => '',
			'enable'           => 1,
			'ignoreParameters' => 0,
		),
		$more
	);
}
