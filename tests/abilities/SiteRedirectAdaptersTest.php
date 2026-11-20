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
}
