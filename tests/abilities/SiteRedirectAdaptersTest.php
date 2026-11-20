<?php
/**
 * The five redirect read adapters, each against a stub that returns its vendor's real shapes:
 * readiness, the exact vendor reader called, row mapping, the capability, the not-found log with
 * its time conversion, and every match outcome including unknown.
 *
 * PHPUnit proves our code against the stub. Whether the vendor behaves as its stub does is what
 * tests/acceptance/siteops/redirect.sh proves on a disposable site.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use Aafm_Siteops_Redirect_Stub as Stub;
use WP_Error;

final class SiteRedirectAdaptersTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Stub::reset();
		aafm_siteops_redirect_stub_activate_eps();
		aafm_siteops_redirect_stub_activate_redirection();
		aafm_siteops_redirect_stub_activate_srm();
		aafm_siteops_redirect_stub_activate_rankmath();
		aafm_siteops_redirect_stub_activate_slim_seo();
		Stub::$vendor['rm_modules'] = array( 'redirections', '404-monitor' );
		// Rank Math's own attachment and archive redirects off, as redirect.sh sets them; a test that needs one on says so.
		Stub::$vendor['rm_settings'] = array(
			'general.attachment_redirect_urls' => false,
			'titles.disable_date_archives'     => false,
		);
		aafm_siteops_redirect_load();
		$this->acting_as( 'administrator' );
		foreach ( array( 'srm_manage_redirects', 'rank_math_redirections', 'rank_math_404_monitor' ) as $capability ) {
			wp_get_current_user()->add_cap( $capability );
		}
	}

	/**
	 * Keep only these real adapters in the registry and treat each as detected.
	 *
	 * @param array<int,string> $slugs Registry slugs.
	 */
	private function only( array $slugs ): void {
		add_filter(
			'aafm_site_redirect_adapters',
			static function ( array $adapters ) use ( $slugs ): array {
				$out = array();
				foreach ( $slugs as $slug ) {
					$out[ $slug ] = array_merge( $adapters[ $slug ], array( 'detect' => array() ) );
				}
				return $out;
			}
		);
	}

	/**
	 * One adapter's answer for a path, as the chain follower would get it.
	 *
	 * @param string $slug Registry slug.
	 * @param string $path Full canonical path.
	 * @return array<string,mixed>
	 */
	private function match( string $slug, string $path ): array {
		$cache = array();
		return aafm_site_redirect_match( $slug, $path, $cache );
	}

	/**
	 * The normalised rows of one adapter.
	 *
	 * @param string $slug Registry slug.
	 * @return array<int,array<string,mixed>>
	 */
	private function rows( string $slug ): array {
		$all = aafm_site_redirect_call( $slug, 'all' );
		$this->assertIsArray( $all, $slug . ' all() must answer.' );
		return $all['rows'];
	}

	/**
	 * Assert a result is a WP_Error with one code.
	 *
	 * @param string $code   Expected code.
	 * @param mixed  $result Tool result.
	 */
	private function assertRefused( string $code, $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function test_the_registry_names_the_five_adapters_in_the_observed_order(): void {
		$adapters = aafm_site_redirect_adapters();

		$this->assertSame( array( 'eps_301', 'redirection', 'safe_redirect_manager', 'rankmath', 'slim_seo' ), array_keys( $adapters ) );
		$this->assertSame( array( array( 'class', 'EPS_Redirects' ) ), $adapters['eps_301']['detect'] );
		$this->assertSame( array( array( 'constant', 'REDIRECTION_VERSION' ) ), $adapters['redirection']['detect'] );
		$this->assertSame( array( array( 'function', 'srm_get_redirect_data' ), array( 'function', 'srm_get_max_redirects' ) ), $adapters['safe_redirect_manager']['detect'] );
		$this->assertSame( array( array( 'constant', 'RANK_MATH_VERSION' ) ), $adapters['rankmath']['detect'] );
		$this->assertSame( array( array( 'constant', 'SLIM_SEO_VER' ) ), $adapters['slim_seo']['detect'] );
		$this->assertSame( array( true, true, false, true, true ), array_column( $adapters, 'has_404' ) );
		$this->assertSame( array( 'full', 'full', 'full', 'full', 'below_home' ), array_column( $adapters, 'match_path' ) );
		$this->assertSame( array( 'host_root', 'host_root', 'host_root', 'home', 'home' ), array_column( $adapters, 'relative_targets' ) );

		$this->assertTrue( aafm_site_cache_detected( $adapters['eps_301'] ), 'The stub defines the class the registry detects.' );
		$this->assertTrue( aafm_site_cache_detected( $adapters['safe_redirect_manager'] ) );
		$this->assertFalse( aafm_site_cache_detected( $adapters['redirection'] ), 'No stub defines a version constant.' );
		$this->assertNull( aafm_site_redirect_call( 'redirection', 'ready' ), 'An undetected adapter is never loaded.' );
	}

	/**
	 * An EPS row object.
	 *
	 * @param int    $id     Id.
	 * @param string $from   Source, stored without a leading slash.
	 * @param mixed  $to     Target URL or post id.
	 * @param string $status Status code, or 'inactive'.
	 * @param string $type   'url' or 'post'.
	 * @return object
	 */
	private function eps_row( int $id, string $from, $to, string $status = '301', string $type = 'url' ) {
		return (object) array(
			'id'       => (string) $id,
			'url_from' => $from,
			'url_to'   => (string) $to,
			'status'   => $status,
			'type'     => $type,
			'count'    => '7',
		);
	}

	public function test_eps_ready_needs_the_class_and_the_table(): void {
		global $wpdb;
		$this->only( array( 'eps_301' ) );

		$this->assertTrue( aafm_site_redirect_call( 'eps_301', 'ready' ) );

		$wpdb->query( "DROP TABLE {$wpdb->prefix}redirects" );
		$this->assertFalse( aafm_site_redirect_call( 'eps_301', 'ready' ) );
		$this->assertSame( array(), aafm_site_redirect_ready_slugs() );
	}

	public function test_eps_rows_are_normalised_and_request_arguments_never_reach_the_plugin(): void {
		$this->only( array( 'eps_301' ) );
		$post_id             = self::factory()->post->create( array( 'post_name' => 'hello-target' ) );
		Stub::$vendor['eps'] = array(
			$this->eps_row( 9, 'old-c', 'https://example.org/x', 'inactive' ),
			$this->eps_row( 8, 'old-b', $post_id, '302' ),
			$this->eps_row( 7, 'old-a', 'http://example.org/sample-page/x' ),
			$this->eps_row( 6, 'odd', '/t', '301', 'other' ),
		);
		$_GET['orderby']     = 'count';

		$rows = $this->rows( 'eps_301' );
		unset( $_GET['orderby'] );

		$this->assertSame( 1, Stub::count( 'EPS_Redirects::get_redirects:all' ) );
		$this->assertFalse( Stub::$vendor['eps_saw_get'], 'The wrapper empties the request arguments the plugin sorts by.' );
		$this->assertSame(
			array(
				'id'      => '9',
				'source'  => '/old-c',
				'target'  => 'https://example.org/x',
				'code'    => 0,
				'enabled' => false,
				'kind'    => 'exact',
				'hits'    => 7,
			),
			$rows[0]
		);
		$this->assertSame( get_permalink( $post_id ), $rows[1]['target'], 'A stored post id is shown as its permalink.' );
		$this->assertSame( 302, $rows[1]['code'] );
		$this->assertTrue( $rows[1]['enabled'] );
		$this->assertSame( 'pattern', $rows[3]['kind'] );

		Stub::$vendor['eps_return'] = null;
		$out                        = aafm_exec_site_list_redirects( array() );
		$this->assertTrue( $out['providers'][0]['failed'], 'A reader that returns no list fails the provider.' );
	}

	public function test_eps_match_compares_the_whole_path_newest_rule_first(): void {
		$this->only( array( 'eps_301' ) );
		$post_id             = self::factory()->post->create();
		Stub::$vendor['eps'] = array(
			$this->eps_row( 9, 'old-a', '/newest' ),
			$this->eps_row( 8, 'off', '/x', 'inactive' ),
			$this->eps_row( 7, 'old-a', '/older', '302' ),
			$this->eps_row( 6, 'to-post', $post_id, '307', 'post' ),
		);

		$this->assertSame(
			array(
				'state'  => 'matched',
				'id'     => '9',
				'target' => '/newest',
				'code'   => 301,
			),
			$this->match( 'eps_301', '/old-a' )
		);
		$this->assertSame( '9', $this->match( 'eps_301', '/OLD-A/' )['id'], 'Case and the trailing slash do not matter.' );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'eps_301', '/off' ), 'A rule that is off is not matched.' );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'eps_301', '/nothing' ) );
		$this->assertSame( get_permalink( $post_id ), $this->match( 'eps_301', '/to-post' )['target'] );
		$this->assertGreaterThan( 0, Stub::count( 'EPS_Redirects::get_redirects:active' ) );
		$this->assertSame( 0, Stub::count( 'EPS_Redirects::get_redirects:all' ) );
	}

	public function test_eps_capability_and_not_found_log(): void {
		$this->only( array( 'eps_301' ) );
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 5 );
		update_option(
			'eps_redirects_404_log',
			array(
				array(
					'timestamp'  => 1700018000,
					'url'        => '/miss-1?q=secret',
					'user_agent' => 'LeakUA/1.0',
				),
				array(
					'timestamp'  => 1700000000,
					'url'        => '/miss-1?q=secret',
					'user_agent' => 'LeakUA/1.0',
				),
				array(
					'timestamp' => 'x',
					'url'       => '/miss-2',
				),
				'junk',
			)
		);

		$out = aafm_exec_site_list_404s( array() );

		$this->assertSame(
			array(
				array(
					'provider'       => 'eps_301',
					'untrusted_path' => '/miss-1',
					'count'          => 2,
					'last_seen'      => gmdate( 'c', 1700018000 - 5 * HOUR_IN_SECONDS ),
				),
				array(
					'provider'       => 'eps_301',
					'untrusted_path' => '/miss-2',
					'count'          => 1,
					'last_seen'      => null,
				),
			),
			$out['entries']
		);
		$this->assertStringNotContainsString( 'LeakUA', (string) wp_json_encode( $out ) );

		delete_option( 'eps_redirects_404_log' );
		$this->assertSame( array(), aafm_exec_site_list_404s( array() )['entries'] );

		add_filter(
			'eps_301_redirects_capability',
			static function (): string {
				return 'aafm_capability_nobody_has';
			}
		);
		$this->assertRefused( 'aafm_site_provider_capability', aafm_exec_site_list_redirects( array( 'provider' => 'eps_301' ) ) );
		$this->assertRefused( 'aafm_site_provider_capability', aafm_exec_site_list_404s( array( 'provider' => 'eps_301' ) ) );
	}

	/**
	 * Overrides for a Redirection rule that answers 410 and has no target.
	 *
	 * @return array<string,mixed>
	 */
	private function gone(): array {
		return array(
			'action_type' => 'error',
			'action_code' => 410,
			'action_data' => null,
		);
	}

	public function test_redirection_ready_reads_the_setup_state_without_changing_it(): void {
		$this->only( array( 'redirection' ) );

		$this->assertTrue( aafm_site_redirect_call( 'redirection', 'ready' ) );
		$this->assertGreaterThan( 0, Stub::count( 'Red_Options::reset' ), 'The plugin memoises its options for the request.' );

		Stub::$vendor['red_options'] = array( 'database' => '' );
		$this->assertFalse( aafm_site_redirect_call( 'redirection', 'ready' ), 'Not set up.' );

		update_option( 'redirection_version', '2.3' );
		$this->assertFalse( aafm_site_redirect_call( 'redirection', 'ready' ), 'An old install with no schema version needs an upgrade.' );

		Stub::$vendor['red_options'] = array( 'database' => '3.0' );
		$this->assertFalse( aafm_site_redirect_call( 'redirection', 'ready' ), 'Needs an upgrade.' );

		Stub::$vendor['red_options'] = array(
			'database'       => '4.2',
			'database_stage' => array( 'stage' => 'x' ),
		);
		$this->assertFalse( aafm_site_redirect_call( 'redirection', 'ready' ), 'An upgrade is in progress.' );

		Stub::$vendor['red_options'] = 'not an array';
		$this->assertFalse( aafm_site_redirect_call( 'redirection', 'ready' ) );
	}

	public function test_redirection_rows_come_from_the_items_wrapper(): void {
		$this->only( array( 'redirection' ) );
		Stub::$vendor['red'] = array(
			aafm_siteops_redirect_stub_red_row( 1, '/old-a', '/hello-world/', array( 'hits' => 42 ) ),
			aafm_siteops_redirect_stub_red_row( 2, '/rx-(\d+)', '/t/$1', array( 'regex' => true ) ),
			aafm_siteops_redirect_stub_red_row(
				3,
				'/login-only',
				'',
				array(
					'match_type'  => 'login',
					'action_data' => array( 'logged_in' => '/in' ),
					'enabled'     => false,
					'action_code' => 302,
				)
			),
			aafm_siteops_redirect_stub_red_row( 4, '/gone', '', $this->gone() ),
		);

		$rows = $this->rows( 'redirection' );

		$this->assertSame( 1, Stub::count( 'Red_Item::get_filtered:0' ) );
		$this->assertSame(
			array(
				'id'      => '1',
				'source'  => '/old-a',
				'target'  => '/hello-world/',
				'code'    => 301,
				'enabled' => true,
				'kind'    => 'exact',
				'hits'    => 42,
			),
			$rows[0]
		);
		$this->assertSame( 'pattern', $rows[1]['kind'] );
		$this->assertSame( array( 'pattern', '', false, 302 ), array( $rows[2]['kind'], $rows[2]['target'], $rows[2]['enabled'], $rows[2]['code'] ) );
		$this->assertSame( array( 'pattern', '', 410 ), array( $rows[3]['kind'], $rows[3]['target'], $rows[3]['code'] ) );

		Stub::$vendor['red_return'] = Stub::$vendor['red'];
		$out                        = aafm_exec_site_list_redirects( array() );
		$this->assertTrue( $out['providers'][0]['failed'], 'A bare list is not the plugin wrapper, so the provider fails instead of listing nothing.' );
		$this->assertSame( array(), $out['redirects'] );
	}

	public function test_redirection_reads_page_after_page_until_a_short_one(): void {
		$this->only( array( 'redirection' ) );
		$rows = array();
		for ( $i = 1; $i <= 201; $i++ ) {
			$rows[] = aafm_siteops_redirect_stub_red_row( $i, '/r' . $i, '/t' );
		}
		Stub::$vendor['red'] = $rows;

		$all = aafm_site_redirect_call( 'redirection', 'all' );

		$this->assertCount( 201, $all['rows'] );
		$this->assertFalse( $all['truncated'] );
		$this->assertSame( 1, Stub::count( 'Red_Item::get_filtered:0' ) );
		$this->assertSame( 1, Stub::count( 'Red_Item::get_filtered:1' ) );
		$this->assertSame( 0, Stub::count( 'Red_Item::get_filtered:2' ) );
	}

	public function test_redirection_match_uses_the_plugin_matcher(): void {
		$this->only( array( 'redirection' ) );
		Stub::$vendor['red'] = array(
			aafm_siteops_redirect_stub_red_row( 1, '/old-a', '/sample-page/', array( 'action_code' => 302 ) ),
			aafm_siteops_redirect_stub_red_row( 2, '/off', '/x', array( 'enabled' => false ) ),
			aafm_siteops_redirect_stub_red_row( 3, '^/rx-(\d+)$', '/t', array( 'regex' => true ) ),
			aafm_siteops_redirect_stub_red_row( 4, '/no-target', '', array( 'action_data' => null ) ),
		);

		$this->assertSame(
			array(
				'state'  => 'matched',
				'id'     => '1',
				'target' => '/sample-page/',
				'code'   => 302,
			),
			$this->match( 'redirection', '/old-a' )
		);
		$this->assertSame( '3', $this->match( 'redirection', '/rx-12' )['id'] );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'redirection', '/off' ) );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'redirection', '/nothing-here' ) );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'redirection', '/no-target' ), 'A rule whose matcher gives no target does not redirect.' );

		Stub::$calls = array();
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'redirection', '/wp-json/wp/v2/posts' ) );
		$this->assertSame( array(), Stub::$calls, 'A protected URL is answered before any rule is read.' );
	}

	public function test_redirection_rule_it_cannot_evaluate_is_unknown(): void {
		$this->only( array( 'redirection' ) );
		Stub::$vendor['red'] = array(
			aafm_siteops_redirect_stub_red_row( 1, '/old-a', '/x', array( 'stub_match' => null ) ),
			aafm_siteops_redirect_stub_red_row( 2, '/old-a', '/y' ),
		);

		$this->assertSame( array( 'state' => 'unknown' ), $this->match( 'redirection', '/old-a' ), 'A later match does not hide an earlier rule that could not be evaluated.' );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'redirection', '/other' ), 'A rule whose source does not match the path is not in question.' );
	}

	public function test_redirection_a_site_move_or_an_alias_of_this_site_gives_unknown(): void {
		$this->only( array( 'redirection' ) );
		Stub::$vendor['red'] = array( aafm_siteops_redirect_stub_red_row( 1, '/old-a', '/x' ) );
		$unknown             = static function ( string $reason ): array {
			return array(
				'state'  => 'unknown',
				'reason' => $reason,
			);
		};

		// The site-move setting sends every request to another domain before any rule is read.
		Stub::$vendor['red_options'] = array(
			'database' => '4.2',
			'relocate' => 'https://new.example.com',
		);
		$this->assertSame( $unknown( 'redirection_relocate' ), $this->match( 'redirection', '/old-a' ) );
		$this->assertSame( $unknown( 'redirection_relocate' ), $this->match( 'redirection', '/nothing-here' ) );
		Stub::$vendor['red_options']['relocate'] = 'http://example.org/';
		$this->assertSame( '1', $this->match( 'redirection', '/old-a' )['id'] ?? null, 'A move to this same domain sends nothing anywhere.' );

		// An alias equal to this site's own host is redirected to the site address.
		Stub::$vendor['red_options'] = array(
			'database' => '4.2',
			'aliases'  => array( 'example.org' ),
		);
		$this->assertSame( $unknown( 'redirection_site_alias' ), $this->match( 'redirection', '/nothing-here' ) );

		// The preferred domain, HTTPS and other aliases act on another host or scheme, never on this
		// site's own address, which is the only one the tool accepts.
		Stub::$vendor['red_options'] = array(
			'database'         => '4.2',
			'aliases'          => array( 'other.example' ),
			'preferred_domain' => 'www',
			'https'            => true,
		);
		$this->assertSame( '1', $this->match( 'redirection', '/old-a' )['id'] ?? null );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'redirection', '/nothing-here' ) );
	}

	public function test_redirection_capability_and_not_found_log(): void {
		$this->only( array( 'redirection' ) );
		Stub::$vendor['red_404'] = array(
			(object) array(
				'count' => 6,
				'url'   => '/old-a',
				'id'    => '/old-a',
			),
			(object) array(
				'count' => 1,
				'url'   => '/missing-1?utm=x',
				'id'    => '/missing-1?utm=x',
			),
		);

		$out = aafm_exec_site_list_404s( array() );

		$this->assertSame( 1, Stub::count( 'Red_404_Log::get_grouped:url' ) );
		$this->assertSame( array( '/old-a', '/missing-1' ), array_column( $out['entries'], 'untrusted_path' ) );
		$this->assertSame( array( 6, 1 ), array_column( $out['entries'], 'count' ) );
		$this->assertSame( array( null, null ), array_column( $out['entries'], 'last_seen' ) );

		Stub::$vendor['red_404_return'] = array( 'bare' );
		$this->assertSame( array(), aafm_exec_site_list_404s( array() )['entries'] );

		add_filter(
			'redirection_role',
			static function (): string {
				return 'aafm_capability_nobody_has';
			}
		);
		$this->assertRefused( 'aafm_site_provider_capability', aafm_exec_site_list_redirects( array( 'provider' => 'redirection' ) ) );
		$this->assertRefused( 'aafm_site_provider_capability', aafm_exec_site_list_404s( array( 'provider' => 'redirection' ) ) );
	}

	public function test_srm_lists_with_its_own_query_and_never_calls_the_cached_getter(): void {
		$this->only( array( 'safe_redirect_manager' ) );
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$on    = aafm_siteops_redirect_stub_srm_rule( '/old-a', '/hello-world/', 301 );
		$off   = aafm_siteops_redirect_stub_srm_rule( '/old-off', '/x', 302, false, 'draft' );
		$regex = aafm_siteops_redirect_stub_srm_rule( '/rx-(\d+)', '/t/$1', 301, true );
		$wild  = aafm_siteops_redirect_stub_srm_rule( '/blog/*', '/news/*', 301 );
		update_post_meta( $page, '_redirect_rule_from', '/not-a-rule' );

		$this->assertTrue( aafm_site_redirect_call( 'safe_redirect_manager', 'ready' ) );
		$rows = $this->rows( 'safe_redirect_manager' );

		$this->assertSame( array( (string) $on, (string) $off, (string) $regex, (string) $wild ), array_column( $rows, 'id' ) );
		$this->assertSame(
			array(
				'id'      => (string) $on,
				'source'  => '/old-a',
				'target'  => '/hello-world/',
				'code'    => 301,
				'enabled' => true,
				'kind'    => 'exact',
				'hits'    => null,
				'regex'   => false,
			),
			$rows[0]
		);
		$this->assertFalse( $rows[1]['enabled'] );
		$this->assertSame( array( 'pattern', true, '/rx-(\d+)' ), array( $rows[2]['kind'], $rows[2]['regex'], $rows[2]['source'] ) );
		$this->assertSame( array( 'pattern', false ), array( $rows[3]['kind'], $rows[3]['regex'] ) );

		$listed = aafm_exec_site_list_redirects( array() );
		$this->assertArrayNotHasKey( 'regex', $listed['redirects'][0], 'The internal key never reaches the output.' );
		$this->assertRefused( 'aafm_site_redirect_not_found', aafm_exec_site_get_redirect( array( 'id' => (string) $page ) ), 'An ordinary page is not a redirect.' );
		$this->assertSame( (string) $off, aafm_exec_site_get_redirect( array( 'id' => (string) $off ) )['redirect']['id'] );

		aafm_exec_site_test_redirect( array( 'url' => '/old-a' ) );
		$this->assertSame( 0, Stub::count( 'srm_get_redirects' ) );
		$this->assertSame( 0, Stub::count( 'srm_match_redirect' ) );
		$this->assertFalse( get_transient( '_srm_redirects_1000' ), 'No read may store the rule list.' );

		unregister_post_type( 'redirect_rule' );
		$this->assertFalse( aafm_site_redirect_call( 'safe_redirect_manager', 'ready' ) );
	}

	public function test_srm_match_exact_wildcard_and_regex(): void {
		$this->only( array( 'safe_redirect_manager' ) );
		$exact = aafm_siteops_redirect_stub_srm_rule( '/Old-A/', '/hello-world/', 307 );
		aafm_siteops_redirect_stub_srm_rule( '/old-off', '/x', 301, false, 'draft' );
		$wild  = aafm_siteops_redirect_stub_srm_rule( '/blog/*', '/news/*' );
		$host  = aafm_siteops_redirect_stub_srm_rule( '/out*', 'https://example.com*' );
		$regex = aafm_siteops_redirect_stub_srm_rule( '^/rx-(\d+)$', '/t/$1', 301, true );
		$gone  = aafm_siteops_redirect_stub_srm_rule( '/gone', '', 410 );
		aafm_siteops_redirect_stub_srm_rule( '/empty-target', '', 301 );

		$this->assertSame(
			array(
				'state'  => 'matched',
				'id'     => (string) $exact,
				'target' => '/hello-world/',
				'code'   => 307,
			),
			$this->match( 'safe_redirect_manager', '/old-a' )
		);
		$this->assertSame( (string) $exact, $this->match( 'safe_redirect_manager', '/OLD-A/' )['id'] );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'safe_redirect_manager', '/old-off' ), 'A draft rule is off.' );
		$this->assertSame( array( (string) $wild, '/news/post' ), array_slice( array_values( $this->match( 'safe_redirect_manager', '/blog/post/' ) ), 1, 2 ), 'The plugin drops the trailing slash of the request before it matches.' );
		$this->assertSame( array( (string) $host, 'https://example.com/abc' ), array_slice( array_values( $this->match( 'safe_redirect_manager', '/out/abc' ) ), 1, 2 ), 'A wildcard may extend the path, never the host.' );
		$this->assertSame( array( (string) $regex, '/t/12' ), array_slice( array_values( $this->match( 'safe_redirect_manager', '/rx-12' ) ), 1, 2 ) );
		$this->assertSame( (string) $regex, $this->match( 'safe_redirect_manager', '/rx-12/' )['id'] );
		$this->assertSame( array( (string) $gone, '', 410 ), array_slice( array_values( $this->match( 'safe_redirect_manager', '/gone' ) ), 1, 3 ) );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'safe_redirect_manager', '/empty-target' ), 'A rule with no target and a redirect code is skipped.' );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'safe_redirect_manager', '/nothing' ) );
	}

	public function test_srm_a_pattern_that_does_not_compile_is_unknown(): void {
		$this->only( array( 'safe_redirect_manager' ) );
		aafm_siteops_redirect_stub_srm_rule( '(unclosed', '/t', 301, true );
		aafm_siteops_redirect_stub_srm_rule( '/after', '/t2' );

		$this->assertSame( array( 'state' => 'unknown' ), $this->match( 'safe_redirect_manager', '/nothing' ) );
		$this->assertSame( array( 'state' => 'unknown' ), $this->match( 'safe_redirect_manager', '/after' ), 'The rule that could not be evaluated comes first and might have applied.' );
	}

	/**
	 * The five filters that can change live matching.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function srm_filters(): array {
		return array(
			'srm_registered_redirects' => array( 'srm_registered_redirects' ),
			'srm_match_query_params'   => array( 'srm_match_query_params' ),
			'srm_redirect_to'          => array( 'srm_redirect_to' ),
			'srm_redirect_only_on_404' => array( 'srm_redirect_only_on_404' ),
			'srm_requested_path'       => array( 'srm_requested_path' ),
		);
	}

	/**
	 * Each of the five filters, hooked by a site, makes the matcher decline to answer.
	 *
	 * @dataProvider srm_filters
	 *
	 * @param string $hook Filter name.
	 */
	public function test_srm_a_site_filter_on_live_matching_gives_unknown( string $hook ): void {
		$this->only( array( 'safe_redirect_manager' ) );
		aafm_siteops_redirect_stub_srm_rule( '/old-a', '/hello-world/' );
		$this->assertSame( 'matched', $this->match( 'safe_redirect_manager', '/old-a' )['state'] );

		add_filter( $hook, '__return_false' );

		$this->assertSame( array( 'state' => 'unknown' ), $this->match( 'safe_redirect_manager', '/old-a' ) );
		$this->assertSame( array( 'state' => 'unknown' ), $this->match( 'safe_redirect_manager', '/nothing' ) );
	}

	public function test_srm_a_non_ascii_path_is_unknown(): void {
		$this->only( array( 'safe_redirect_manager' ) );
		$plain = aafm_siteops_redirect_stub_srm_rule( '/caf-old', '/a' );
		aafm_siteops_redirect_stub_srm_rule( '/café-old', '/b' );
		$ascii = aafm_siteops_redirect_stub_srm_rule( '/cafe-old', '/c' );

		$this->assertSame( array( 'state' => 'unknown' ), $this->match( 'safe_redirect_manager', '/café-old' ) );
		$this->assertSame( (string) $plain, $this->match( 'safe_redirect_manager', '/caf-old' )['id'] );
		$this->assertSame( (string) $ascii, $this->match( 'safe_redirect_manager', '/cafe-old' )['id'] );

		$out = aafm_exec_site_test_redirect( array( 'url' => '/café-old' ) );
		$this->assertSame( 'unknown', $out['evaluated'][0]['result'] );
		$this->assertNull( $out['first_match'] );
		$this->assertSame( 'leaves_site_or_unknown', $out['chain_ends'] );
	}

	public function test_srm_reports_the_status_the_plugin_sends(): void {
		$this->only( array( 'safe_redirect_manager' ) );
		aafm_siteops_redirect_stub_srm_rule( '/acc-code', '/acc-page/', 999 );

		$this->assertSame( 302, $this->match( 'safe_redirect_manager', '/acc-code' )['code'], 'The plugin redirects with 302 for a stored code it does not accept.' );

		add_filter( 'srm_default_direct_status', '__return_zero' );
		$this->assertSame( array( 'state' => 'unknown' ), $this->match( 'safe_redirect_manager', '/acc-code' ) );
	}

	public function test_srm_strips_the_home_path_and_checks_its_capability(): void {
		$this->only( array( 'safe_redirect_manager' ) );
		update_option( 'home', 'http://example.org/sub' );
		update_option( 'siteurl', 'http://example.org/sub' );
		$a = aafm_siteops_redirect_stub_srm_rule( '/old-a', '/sub/hello-world/' );
		$b = aafm_siteops_redirect_stub_srm_rule( '/sub/old-b', '/sub/sample-page/' );

		$this->assertSame( (string) $a, $this->match( 'safe_redirect_manager', '/sub/old-a' )['id'] );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'safe_redirect_manager', '/sub/old-b' ) );
		$this->assertSame( (string) $b, $this->match( 'safe_redirect_manager', '/sub/sub/old-b' )['id'] );

		wp_get_current_user()->remove_cap( 'srm_manage_redirects' );
		$this->assertRefused( 'aafm_site_provider_capability', aafm_exec_site_list_redirects( array( 'provider' => 'safe_redirect_manager' ) ) );
		$this->assertRefused( 'aafm_site_no_provider', aafm_exec_site_list_404s( array() ), 'This plugin keeps no not-found log.' );
	}

	/**
	 * One Rank Math source as the plugin stores it.
	 *
	 * @param string $pattern    Pattern, stored without a leading slash.
	 * @param string $comparison Comparison type.
	 * @return array<string,string>
	 */
	private function source( string $pattern, string $comparison = 'exact' ): array {
		return array(
			'ignore'     => '',
			'pattern'    => $pattern,
			'comparison' => $comparison,
		);
	}

	/**
	 * Overrides for a Rank Math rule with a 302 code and a hit count.
	 *
	 * @return array<string,string>
	 */
	private function counted(): array {
		return array(
			'header_code' => '302',
			'hits'        => '15',
		);
	}

	public function test_rankmath_ready_follows_the_module_not_the_class(): void {
		$this->only( array( 'rankmath' ) );

		$this->assertTrue( aafm_site_redirect_call( 'rankmath', 'ready' ) );

		Stub::$vendor['rm_modules'] = array( '404-monitor' );
		$this->assertFalse( aafm_site_redirect_call( 'rankmath', 'ready' ), 'The class exists, the module is not running.' );
		$this->assertRefused( 'aafm_site_no_provider', aafm_exec_site_list_redirects( array() ) );
	}

	public function test_rankmath_rows_come_from_the_redirections_wrapper(): void {
		$this->only( array( 'rankmath' ) );
		Stub::$vendor['rm'] = array(
			aafm_siteops_redirect_stub_rm_row( 1, array( $this->source( 'old-a' ) ), 'http://example.org/hello-world/', $this->counted() ),
			aafm_siteops_redirect_stub_rm_row( 2, array( $this->source( 'rx-(\d+)', 'regex' ) ), 'http://example.org/t/' ),
			aafm_siteops_redirect_stub_rm_row( 3, array( $this->source( 'a' ), $this->source( 'b' ) ), 'http://example.org/two/' ),
			aafm_siteops_redirect_stub_rm_row( 4, array( $this->source( 'old-off' ) ), 'http://example.org/x/', array( 'status' => 'inactive' ) ),
			aafm_siteops_redirect_stub_rm_row( 5, array( $this->source( 'binned' ) ), 'http://example.org/x/', array( 'status' => 'trashed' ) ),
			array_merge( aafm_siteops_redirect_stub_rm_row( 6, array(), 'http://example.org/x/' ), array( 'sources' => '' ) ),
		);

		$rows = $this->rows( 'rankmath' );

		$this->assertSame( 1, Stub::count( 'RankMath::get_redirections:active:1' ) );
		$this->assertSame( 1, Stub::count( 'RankMath::get_redirections:inactive:1' ) );
		$this->assertSame( array( '1', '2', '3', '6', '4' ), array_column( $rows, 'id' ), 'Active rules, then inactive ones; a trashed rule is never listed.' );
		$this->assertSame(
			array(
				'id'      => '1',
				'source'  => '/old-a',
				'target'  => 'http://example.org/hello-world/',
				'code'    => 302,
				'enabled' => true,
				'kind'    => 'exact',
				'hits'    => 15,
			),
			$rows[0]
		);
		$this->assertSame( array( 'pattern', 'rx-(\d+)' ), array( $rows[1]['kind'], $rows[1]['source'] ) );
		$this->assertSame( 'pattern', $rows[2]['kind'], 'More than one source is a pattern rule.' );
		$this->assertSame( array( 'pattern', '' ), array( $rows[3]['kind'], $rows[3]['source'] ), 'Sources that are not a list.' );
		$this->assertFalse( $rows[4]['enabled'] );

		Stub::$vendor['rm_return'] = Stub::$vendor['rm'];
		$this->assertTrue( aafm_exec_site_list_redirects( array() )['providers'][0]['failed'], 'A bare list is not the plugin wrapper.' );
	}

	public function test_rankmath_match_and_capability(): void {
		$this->only( array( 'rankmath' ) );
		Stub::$vendor['rm'] = array(
			aafm_siteops_redirect_stub_rm_row( 2, array( $this->source( 'old-b' ) ), 'http://example.org/sample-page/', array( 'header_code' => '307' ) ),
		);

		$this->assertSame(
			array(
				'state'  => 'matched',
				'id'     => '2',
				'target' => 'http://example.org/sample-page/',
				'code'   => 307,
			),
			$this->match( 'rankmath', '/old-b/' )
		);
		$this->assertSame( 1, Stub::count( 'RankMath::match_redirections:old-b' ), 'The plugin matcher is asked with the slashes trimmed.' );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'rankmath', '/nothing-here' ) );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'rankmath', '/' ) );

		wp_get_current_user()->remove_cap( 'rank_math_redirections' );
		$this->assertRefused( 'aafm_site_provider_capability', aafm_exec_site_list_redirects( array( 'provider' => 'rankmath' ) ) );
		$this->assertSame( 'unknown', aafm_exec_site_test_redirect( array( 'url' => '/old-b' ) )['evaluated'][0]['result'] );

		// On a site in a subfolder the plugin removes "sub/" wherever it occurs in a request: the
		// real request /sub/sub/old-c reaches its matcher as old-c, so a rule stored as sub/old-c never fires.
		update_option( 'home', 'http://example.org/sub' );
		Stub::$vendor['rm'][] = aafm_siteops_redirect_stub_rm_row( 3, array( $this->source( 'sub/old-c' ) ), 'http://example.org/sub/x/' );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'rankmath', '/sub/sub/old-c' ) );
		$this->assertSame( 1, Stub::count( 'RankMath::match_redirections:old-c' ), 'The full path goes through strip_subdirectory() of the plugin.' );
		$this->assertSame( '2', $this->match( 'rankmath', '/sub/old-b' )['id'] ?? null );
	}

	public function test_rankmath_settings_and_filters_it_cannot_evaluate_give_unknown(): void {
		$this->only( array( 'rankmath' ) );
		Stub::$vendor['rm'] = array( aafm_siteops_redirect_stub_rm_row( 2, array( $this->source( 'old-b' ) ), 'http://example.org/sample-page/' ) );
		$unknown            = static function ( string $reason ): array {
			return array(
				'state'  => 'unknown',
				'reason' => $reason,
			);
		};
		$settings           = Stub::$vendor['rm_settings'];

		// The 404 fallback acts after the rules, so a rule still answers.
		Stub::$vendor['rm_settings']['general.redirections_fallback'] = 'homepage';
		$this->assertSame( $unknown( 'rank_math_404_fallback' ), $this->match( 'rankmath', '/nothing-here' ) );
		$this->assertSame( '2', $this->match( 'rankmath', '/old-b' )['id'] ?? null );
		Stub::$vendor['rm_settings']['general.redirections_fallback'] = 'custom';
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'rankmath', '/nothing-here' ), 'A custom fallback with no address redirects nowhere.' );
		Stub::$vendor['rm_settings']['general.redirections_custom_url'] = 'https://example.org/gone/';
		$this->assertSame( $unknown( 'rank_math_404_fallback' ), $this->match( 'rankmath', '/nothing-here' ) );

		// Its own attachment and archive redirects, both on by default on a new install.
		Stub::$vendor['rm_settings']                                     = $settings;
		Stub::$vendor['rm_settings']['general.attachment_redirect_urls'] = true;
		$this->assertSame( $unknown( 'rank_math_attachment_redirect' ), $this->match( 'rankmath', '/nothing-here' ) );
		Stub::$vendor['rm_settings']                                 = $settings;
		Stub::$vendor['rm_settings']['titles.disable_date_archives'] = true;
		$this->assertSame( $unknown( 'rank_math_archive_redirect' ), $this->match( 'rankmath', '/nothing-here' ) );
		Stub::$vendor['rm_settings']                                   = $settings;
		Stub::$vendor['rm_settings']['titles.disable_author_archives'] = true;
		$this->assertSame( $unknown( 'rank_math_archive_redirect' ), $this->match( 'rankmath', '/nothing-here' ) );
		unset( Stub::$vendor['rm_settings']['general.attachment_redirect_urls'] );
		Stub::$vendor['rm_settings']['titles.disable_author_archives'] = false;
		$this->assertSame( $unknown( 'rank_math_attachment_redirect' ), $this->match( 'rankmath', '/nothing-here' ), 'A missing attachment setting is on, as the plugin reads it.' );
		Stub::$vendor['rm_settings'] = $settings;

		// Rank Math's own front-end class on the filter is the attachment and archive case above.
		$own = array( new \RankMath\Frontend\Redirection(), 'pre_redirection' );
		add_filter( 'rank_math/redirection/pre_search', $own );
		$this->assertSame( '2', $this->match( 'rankmath', '/old-b' )['id'] ?? null );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'rankmath', '/nothing-here' ) );

		// Any other callback runs before the rules and can redirect any path.
		add_filter( 'rank_math/redirection/pre_search', '__return_null', 20 );
		$this->assertSame( $unknown( 'rank_math_pre_search_filter' ), $this->match( 'rankmath', '/old-b' ) );
		$this->assertSame( $unknown( 'rank_math_pre_search_filter' ), $this->match( 'rankmath', '/nothing-here' ) );
		remove_filter( 'rank_math/redirection/pre_search', '__return_null', 20 );
		remove_filter( 'rank_math/redirection/pre_search', $own );
	}

	public function test_rankmath_not_found_log_is_grouped_and_in_utc(): void {
		global $wpdb;
		$this->only( array( 'rankmath' ) );
		update_option( 'timezone_string', 'Asia/Karachi' );
		$table = $wpdb->prefix . 'rank_math_404_logs';
		$wpdb->insert(
			$table,
			array(
				'uri'            => 'nothing-1',
				'accessed'       => '2026-11-18 10:00:00',
				'times_accessed' => 3,
				'referer'        => 'https://ref.example/page?token=abc',
				'user_agent'     => 'LeakUA/1.0',
			)
		);
		$wpdb->insert(
			$table,
			array(
				'uri'            => 'nothing-1',
				'accessed'       => '2026-11-18 12:00:00',
				'times_accessed' => 1,
			)
		);
		$wpdb->insert(
			$table,
			array(
				'uri'            => 'nothing-2',
				'times_accessed' => 9,
			)
		);

		$out = aafm_exec_site_list_404s( array() );

		$this->assertSame(
			array(
				array(
					'provider'       => 'rankmath',
					'untrusted_path' => '/nothing-2',
					'count'          => 9,
					'last_seen'      => null,
				),
				array(
					'provider'       => 'rankmath',
					'untrusted_path' => '/nothing-1',
					'count'          => 4,
					'last_seen'      => gmdate( 'c', strtotime( '2026-11-18 07:00:00 UTC' ) ),
				),
			),
			$out['entries']
		);
		$this->assertStringNotContainsString( 'LeakUA', (string) wp_json_encode( $out ) );
		$this->assertStringNotContainsString( 'ref.example', (string) wp_json_encode( $out ) );

		Stub::$vendor['rm_modules'] = array( 'redirections' );
		$this->assertRefused( 'aafm_site_provider_capability', aafm_exec_site_list_404s( array( 'provider' => 'rankmath' ) ), 'The 404 Monitor module is off.' );
	}

	/**
	 * Overrides for a Slim SEO start-with rule that is off.
	 *
	 * @return array<string,mixed>
	 */
	private function off_start_with(): array {
		return array(
			'condition' => 'start-with',
			'enable'    => 0,
		);
	}

	public function test_with_all_five_ready_a_path_is_evaluated_in_registry_order(): void {
		$this->only( array( 'eps_301', 'redirection', 'safe_redirect_manager', 'rankmath', 'slim_seo' ) );
		Stub::$vendor['eps']  = array(
			(object) array(
				'id'       => 3,
				'url_from' => 'same',
				'url_to'   => '/t-eps',
				'status'   => '301',
				'type'     => 'url',
				'count'    => 0,
			),
		);
		Stub::$vendor['red']  = array( aafm_siteops_redirect_stub_red_row( 4, '/same', '/t-redirection' ) );
		Stub::$vendor['rm']   = array( aafm_siteops_redirect_stub_rm_row( 5, array( $this->source( 'same' ) ), 'http://example.org/t-rankmath' ) );
		Stub::$vendor['slim'] = array( 'abc123' => aafm_siteops_redirect_stub_slim_rule( 'same', 't-slim' ) );
		aafm_siteops_redirect_stub_srm_rule( '/same', '/t-srm' );

		$this->assertSame( array( 'eps_301', 'redirection', 'safe_redirect_manager', 'rankmath', 'slim_seo' ), aafm_site_redirect_ready_slugs() );

		$out = aafm_exec_site_test_redirect( array( 'url' => '/same' ) );

		$this->assertSame( array( 'eps_301', 'redirection', 'safe_redirect_manager', 'rankmath', 'slim_seo' ), array_column( $out['evaluated'], 'provider' ) );
		$this->assertSame( array( 'matched', 'matched', 'matched', 'matched', 'matched' ), array_column( $out['evaluated'], 'result' ) );
		$this->assertSame( 'eps_301', $out['first_match']['provider'], 'The provider that runs first on a real request wins.' );
		$this->assertSame( '/t-eps', $out['first_match']['target'] );
	}

	public function test_slim_seo_ready_follows_the_feature_switch(): void {
		$this->only( array( 'slim_seo' ) );

		$this->assertTrue( aafm_site_redirect_call( 'slim_seo', 'ready' ), 'No stored option means every feature is on.' );

		update_option( 'slim_seo', array( 'features' => array( 'meta_title' ) ) );
		$this->assertFalse( aafm_site_redirect_call( 'slim_seo', 'ready' ) );

		update_option( 'slim_seo', array( 'features' => array( 'meta_title', 'redirection' ) ) );
		$this->assertTrue( aafm_site_redirect_call( 'slim_seo', 'ready' ) );
		$this->assertFalse( defined( 'SLIM_SEO_VER' ), 'The stub must not switch the Slim SEO field abilities on.' );
	}

	public function test_slim_seo_rows_are_normalised(): void {
		$this->only( array( 'slim_seo' ) );
		Stub::$vendor['slim'] = array(
			'6afcd5d699922' => aafm_siteops_redirect_stub_slim_rule( 'old-a', 'hello-world/', array( 'type' => 302 ) ),
			'12345'         => aafm_siteops_redirect_stub_slim_rule( 'go', 'https://example.com/resources/', $this->off_start_with() ),
			'junk'          => 'not a rule',
		);

		$rows = $this->rows( 'slim_seo' );

		$this->assertSame( 1, Stub::count( 'SlimSEO::list' ) );
		$this->assertSame(
			array(
				array(
					'id'      => '6afcd5d699922',
					'source'  => '/old-a',
					'target'  => 'hello-world/',
					'code'    => 302,
					'enabled' => true,
					'kind'    => 'exact',
					'hits'    => null,
				),
				array(
					'id'      => '12345',
					'source'  => '/go',
					'target'  => 'https://example.com/resources/',
					'code'    => 301,
					'enabled' => false,
					'kind'    => 'pattern',
					'hits'    => null,
				),
			),
			$rows
		);
		$this->assertSame( '12345', aafm_exec_site_get_redirect( array( 'id' => '12345' ) )['redirect']['id'], 'An all-digit key is still found as a string.' );

		Stub::$vendor['slim_return'] = null;
		$this->assertTrue( aafm_exec_site_list_redirects( array() )['providers'][0]['failed'] );
	}

	/**
	 * One rule per condition: condition, stored source, a path it matches, a path it does not.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public function slim_conditions(): array {
		return array(
			'exact-match' => array( 'exact-match', 'Old-A', '/old-a/', '/old-a/more' ),
			'contain'     => array( 'contain', 'promo', '/spring/promo-page', '/spring/sale' ),
			'start-with'  => array( 'start-with', 'go', '/go/now', '/let-go' ),
			'end-with'    => array( 'end-with', '.html', '/page.html', '/page.htm' ),
			'regex'       => array( 'regex', 'blog/(.*)', '/blog/post-1', '/news/post-1' ),
		);
	}

	/**
	 * Each of the plugin's five conditions is evaluated.
	 *
	 * @dataProvider slim_conditions
	 *
	 * @param string $condition Condition.
	 * @param string $from      Stored source.
	 * @param string $hit       A path the rule matches.
	 * @param string $miss      A path it does not match.
	 */
	public function test_slim_seo_evaluates_each_condition( string $condition, string $from, string $hit, string $miss ): void {
		$this->only( array( 'slim_seo' ) );
		Stub::$vendor['slim'] = array( 'k1' => aafm_siteops_redirect_stub_slim_rule( $from, 'https://example.com/$1/', array( 'condition' => $condition ) ) );

		$matched = $this->match( 'slim_seo', $hit );
		$this->assertSame( array( 'matched', 'k1', 301 ), array( $matched['state'], $matched['id'], $matched['code'] ) );
		$this->assertSame( 'regex' === $condition ? 'https://example.com/post-1/' : 'https://example.com/$1/', $matched['target'] );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'slim_seo', $miss ) );
	}

	public function test_slim_seo_edge_rules(): void {
		$this->only( array( 'slim_seo' ) );
		Stub::$vendor['slim'] = array(
			'off'  => aafm_siteops_redirect_stub_slim_rule( 'old-a', 'x', array( 'enable' => 0 ) ),
			'gone' => aafm_siteops_redirect_stub_slim_rule( 'gone', 'ignored', array( 'type' => 410 ) ),
			'on'   => aafm_siteops_redirect_stub_slim_rule( 'old-a', 'hello-world/' ),
		);

		$this->assertSame( 'on', $this->match( 'slim_seo', '/old-a' )['id'], 'A rule that is off is skipped.' );
		$this->assertSame(
			array(
				'state'  => 'matched',
				'id'     => 'gone',
				'target' => '',
				'code'   => 410,
			),
			$this->match( 'slim_seo', '/gone' )
		);
		$this->assertSame( 'gone_or_blocked', aafm_exec_site_test_redirect( array( 'url' => '/gone' ) )['chain_ends'] );

		Stub::$vendor['slim_settings'] = array( 'case_sensitive' => 1 );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'slim_seo', '/OLD-A' ) );

		Stub::$vendor['slim_settings'] = array();
		Stub::$vendor['slim']          = array( 'all' => aafm_siteops_redirect_stub_slim_rule( '', 'everything', array( 'condition' => 'contain' ) ) );
		$this->assertSame( 'all', $this->match( 'slim_seo', '/any/path' )['id'], 'An empty source matches every request, as it does in the plugin.' );

		Stub::$vendor['slim'] = array(
			'bad'   => aafm_siteops_redirect_stub_slim_rule( '(unclosed', 'x', array( 'condition' => 'regex' ) ),
			'after' => aafm_siteops_redirect_stub_slim_rule( 'after', 'y' ),
		);
		$this->assertSame( array( 'state' => 'unknown' ), $this->match( 'slim_seo', '/nothing' ) );
		$this->assertSame( array( 'state' => 'unknown' ), $this->match( 'slim_seo', '/after' ) );
	}

	public function test_slim_seo_a_skip_filter_gives_unknown(): void {
		$this->only( array( 'slim_seo' ) );
		Stub::$vendor['slim'] = array( 'k1' => aafm_siteops_redirect_stub_slim_rule( 'old-a', 'hello-world/' ) );
		add_filter( 'slim_seo_redirection_skip', '__return_false' );

		$this->assertSame( array( 'state' => 'unknown' ), $this->match( 'slim_seo', '/old-a' ) );
		$this->assertSame( array( 'state' => 'unknown' ), $this->match( 'slim_seo', '/nothing' ) );
	}

	public function test_slim_seo_site_wide_redirects_it_cannot_evaluate_give_unknown(): void {
		$this->only( array( 'slim_seo' ) );
		Stub::$vendor['slim'] = array( 'k1' => aafm_siteops_redirect_stub_slim_rule( 'old-a', 'hello-world/' ) );
		$unknown              = static function ( string $reason ): array {
			return array(
				'state'  => 'unknown',
				'reason' => $reason,
			);
		};

		// Each acts after the rules, so a rule still answers first.
		Stub::$vendor['slim_settings'] = array( 'auto_redirection' => 1 );
		$this->assertSame( $unknown( 'slim_seo_auto_redirection' ), $this->match( 'slim_seo', '/nothing' ) );
		$this->assertSame( 'k1', $this->match( 'slim_seo', '/old-a' )['id'] ?? null );
		Stub::$vendor['slim_settings'] = array( 'redirect_404_to' => 'homepage' );
		$this->assertSame( $unknown( 'slim_seo_404_redirect' ), $this->match( 'slim_seo', '/nothing' ) );

		// The www redirect, judged on this site's own host, the only one the tool accepts.
		Stub::$vendor['slim_settings'] = array( 'redirect_www' => 'non-to-www' );
		$this->assertSame( $unknown( 'slim_seo_www_redirect' ), $this->match( 'slim_seo', '/nothing' ) );
		Stub::$vendor['slim_settings'] = array( 'redirect_www' => 'www-to-non' );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'slim_seo', '/nothing' ), 'This site has no www to remove.' );
		update_option( 'home', 'http://www.example.org' );
		$this->assertSame( $unknown( 'slim_seo_www_redirect' ), $this->match( 'slim_seo', '/nothing' ) );
		Stub::$vendor['slim_settings'] = array( 'redirect_www' => 'non-to-www' );
		$this->assertSame( array( 'state' => 'no_match' ), $this->match( 'slim_seo', '/nothing' ) );
	}

	public function test_slim_seo_is_asked_below_the_home_path_and_its_relative_target_is_under_home(): void {
		$this->only( array( 'slim_seo' ) );
		update_option( 'home', 'http://example.org/sub' );
		update_option( 'siteurl', 'http://example.org/sub' );
		Stub::$vendor['slim'] = array(
			'b' => aafm_siteops_redirect_stub_slim_rule( 'sub/old-b', 'sub/sample-page/' ),
			'a' => aafm_siteops_redirect_stub_slim_rule( 'old-a', 'sub/hello-world/' ),
		);

		$out = aafm_exec_site_test_redirect( array( 'url' => '/sub/old-a' ) );
		$this->assertSame( 'a', $out['first_match']['id'] );
		$this->assertSame( 'resolves', $out['chain_ends'], 'The target is /sub/sub/hello-world/, a page of this site.' );
		$this->assertSame( 'no_match', aafm_exec_site_test_redirect( array( 'url' => '/sub/old-b' ) )['chain_ends'] );
		$this->assertSame( 'b', aafm_exec_site_test_redirect( array( 'url' => '/sub/sub/old-b' ) )['first_match']['id'] );
	}

	public function test_slim_seo_not_found_log_needs_the_setting_and_the_table(): void {
		global $wpdb;
		$this->only( array( 'slim_seo' ) );
		update_option( 'timezone_string', 'Asia/Karachi' );
		$table = $wpdb->prefix . 'slim_seo_404';
		$wpdb->insert(
			$table,
			array(
				'url'        => 'miss-1?q=secret',
				'hit'        => 2,
				'updated_at' => '2026-11-18 10:00:00',
			)
		);

		$this->assertSame( array(), aafm_exec_site_list_404s( array() )['entries'], 'Logging is off by default.' );

		Stub::$vendor['slim_settings'] = array( 'enable_404_logs' => 1 );
		$this->assertSame(
			array(
				array(
					'provider'       => 'slim_seo',
					'untrusted_path' => '/miss-1',
					'count'          => 2,
					'last_seen'      => gmdate( 'c', strtotime( '2026-11-18 05:00:00 UTC' ) ),
				),
			),
			aafm_exec_site_list_404s( array() )['entries']
		);

		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
		$out = aafm_exec_site_list_404s( array() );
		$this->assertSame( array(), $out['entries'], 'With logging on and no table the log is empty, not an error.' );
		$this->assertFalse( $out['providers'][0]['failed'] );
	}
}
