<?php
/**
 * The four Site Ops redirect tools against fake adapters: provider resolution, capability
 * refusals, failed providers, the id rule, not-found merging and labelling, and the path each
 * provider is asked with on a site whose home URL has a path.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use Aafm_Siteops_Redirect_Stub as Stub;
use WP_Error;

final class SiteRedirectsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Stub::reset();
		add_filter( 'aafm_integration_active_site_redirects', '__return_true' );
		aafm_flush_registry_cache();
		aafm_siteops_redirect_load();
		$this->acting_as( 'administrator' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_site_redirects', '__return_true' );
		aafm_flush_registry_cache();
		parent::tear_down();
	}

	/**
	 * Replace the registry with fake adapters.
	 *
	 * @param array<string,array<string,mixed>> $entries Registry entries.
	 */
	private function fakes( array $entries ): void {
		add_filter(
			'aafm_site_redirect_adapters',
			static function () use ( $entries ) {
				return $entries;
			}
		);
	}

	/**
	 * Two ready fake adapters, A before B.
	 */
	private function two_fakes(): void {
		$this->fakes(
			array(
				'fake_a' => Stub::entry( 'Fake A' ),
				'fake_b' => Stub::entry( 'Fake B' ),
			)
		);
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

	public function test_the_four_tools_are_registered_as_reads_of_the_site_redirects_subject(): void {
		$registry = aafm_get_abilities_registry();
		foreach ( array( 'aafm/site-list-redirects', 'aafm/site-get-redirect', 'aafm/site-list-404s', 'aafm/site-test-redirect' ) as $name ) {
			$this->assertArrayHasKey( $name, $registry );
			$this->assertSame( 'reads', $registry[ $name ]['group'] );
			$this->assertSame( 'read', $registry[ $name ]['risk'] );
			$this->assertSame( 'site_redirects', $registry[ $name ]['subject'] );
			$args = call_user_func( $registry[ $name ]['args_builder'] );
			$this->assertSame( 'aafm_perm_manage_options', $args['permission_callback'] );
			$this->assertFalse( $args['input_schema']['additionalProperties'] );
			$this->assertTrue( $args['meta']['annotations']['readonly'] );
			$this->assertStringEndsWith( 'Requires the manage-options capability.', $args['description'] );
		}
	}

	public function test_the_tools_are_absent_while_no_redirect_plugin_is_detected(): void {
		remove_filter( 'aafm_integration_active_site_redirects', '__return_true' );
		add_filter( 'aafm_site_redirects_active', '__return_false', 99 );
		aafm_flush_registry_cache();

		$this->assertArrayNotHasKey( 'aafm/site-list-redirects', aafm_get_abilities_registry() );
		$this->assertArrayHasKey( 'aafm/site-list-redirects', aafm_get_abilities_registry_full() );
	}

	public function test_detection_follows_the_registry_detect_lists(): void {
		remove_filter( 'aafm_site_redirects_active', array( 'Aafm_Siteops_Redirect_Stub', 'detection_default' ), 1 );

		$this->fakes( array( 'fake_a' => Stub::entry( 'Fake A', array( 'detect' => array( array( 'class', 'Aafm_No_Such_Vendor_Class' ) ) ) ) ) );
		$this->assertFalse( aafm_site_redirects_active() );
		$this->assertSame( array(), aafm_site_redirect_ready_slugs(), 'An undetected entry is never asked whether it is ready.' );
		$this->assertSame( 0, Stub::count( 'fake_a.ready' ) );

		remove_all_filters( 'aafm_site_redirect_adapters' );
		$this->fakes( array( 'fake_a' => Stub::entry( 'Fake A' ) ) );
		$this->assertTrue( aafm_site_redirects_active() );

		add_filter( 'aafm_site_redirects_active', '__return_false', 99 );
		$this->assertFalse( aafm_site_redirects_active(), 'The seam can force detection off.' );
	}

	public function test_ready_slugs_are_not_memoised_between_two_calls(): void {
		$this->fakes( array( 'fake_a' => Stub::entry( 'Fake A' ) ) );

		$this->assertSame( array( 'fake_a' ), aafm_site_redirect_ready_slugs() );
		Stub::$fake['fake_a']['ready'] = false;
		$this->assertSame( array(), aafm_site_redirect_ready_slugs() );
		$this->assertRefused( 'aafm_site_no_provider', aafm_exec_site_list_redirects( array() ) );

		Stub::$fake['fake_a']['ready'] = 1;
		$this->assertSame( array(), aafm_site_redirect_ready_slugs(), 'Only exactly true is ready.' );

		Stub::$fake['fake_a'] = array( 'throw' => array( 'ready' ) );
		$this->assertSame( array(), aafm_site_redirect_ready_slugs(), 'A ready() that throws is not ready.' );
	}

	public function test_list_redirects_refusals(): void {
		$this->fakes( array() );
		$this->assertRefused( 'aafm_site_no_provider', aafm_exec_site_list_redirects( array() ) );

		$this->two_fakes();
		Stub::$fake['fake_b']['ready'] = false;
		$this->assertRefused( 'aafm_site_provider_not_ready', aafm_exec_site_list_redirects( array( 'provider' => 'fake_b' ) ) );
		$this->assertRefused( 'aafm_site_provider_not_ready', aafm_exec_site_list_redirects( array( 'provider' => 'never_registered' ) ) );

		Stub::$fake['fake_a']['can'] = array( 'read' => false );
		$this->assertRefused( 'aafm_site_provider_capability', aafm_exec_site_list_redirects( array( 'provider' => 'fake_a' ) ) );
		$this->assertSame( 0, Stub::count( 'fake_a.all' ), 'No rule is read for a caller who lacks the plugin capability.' );
	}

	public function test_list_redirects_lists_every_ready_provider_in_registry_order(): void {
		$this->two_fakes();
		Stub::$fake['fake_a']['rows'] = array(
			Stub::rule( '1', '/old-a', 'https://user:secret@example.org/x?key=abc', array( 'hits' => 3 ) ),
			Stub::rule( '2', '/off', '/t', array( 'enabled' => false ) ),
		);
		Stub::$fake['fake_b']['rows'] = array(
			Stub::rule( 'k.1', '^/rx-(\d+)$', '/t/$1', array( 'kind' => 'pattern' ) ),
		);

		$out = aafm_exec_site_list_redirects( array() );

		$this->assertSame( array( 'redirects', 'total', 'page', 'per_page', 'truncated', 'dropped', 'providers' ), array_keys( $out ) );
		$this->assertSame( 3, $out['total'] );
		$this->assertSame( array( 'fake_a', 'fake_a', 'fake_b' ), array_column( $out['redirects'], 'provider' ) );
		$this->assertSame(
			array(
				'provider'       => 'fake_a',
				'id'             => '1',
				'source'         => '/old-a',
				'target'         => 'https://example.org/x',
				'target_trimmed' => true,
				'code'           => 301,
				'enabled'        => true,
				'kind'           => 'exact',
				'hits'           => 3,
			),
			$out['redirects'][0]
		);
		$this->assertSame( 'pattern', $out['redirects'][2]['kind'] );
		$this->assertSame( 1, $out['page'] );
		$this->assertSame( 20, $out['per_page'] );
		$this->assertFalse( $out['truncated'] );
		$this->assertSame( 0, $out['dropped'] );
		$this->assertSame(
			array(
				array(
					'provider' => 'fake_a',
					'label'    => 'Fake A',
					'ready'    => true,
					'included' => true,
					'failed'   => false,
				),
				array(
					'provider' => 'fake_b',
					'label'    => 'Fake B',
					'ready'    => true,
					'included' => true,
					'failed'   => false,
				),
			),
			$out['providers']
		);
	}

	public function test_list_redirects_filters_and_pages(): void {
		$this->two_fakes();
		Stub::$fake['fake_a']['rows'] = array(
			Stub::rule( '1', '/alpha', '/one' ),
			Stub::rule( '2', '/beta', '/two', array( 'enabled' => false ) ),
			Stub::rule( '3', '/gamma', '/ALPHA-target' ),
		);
		Stub::$fake['fake_b']['rows'] = array( Stub::rule( '4', '/delta', '/four' ) );

		$this->assertSame( array( '1', '3', '4' ), array_column( aafm_exec_site_list_redirects( array( 'status' => 'enabled' ) )['redirects'], 'id' ) );
		$this->assertSame( array( '2' ), array_column( aafm_exec_site_list_redirects( array( 'status' => 'disabled' ) )['redirects'], 'id' ) );
		$this->assertSame( array( '1', '3' ), array_column( aafm_exec_site_list_redirects( array( 'search' => 'alpha' ) )['redirects'], 'id' ), 'Search covers source and target, any case.' );
		$this->assertSame( array( '4' ), array_column( aafm_exec_site_list_redirects( array( 'provider' => 'fake_b' ) )['redirects'], 'id' ) );

		$page = aafm_exec_site_list_redirects(
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertSame( array( '3', '4' ), array_column( $page['redirects'], 'id' ) );
		$this->assertSame( 4, $page['total'], 'total is the grand total, not the page count.' );
		$this->assertSame( 2, $page['page'] );
		$this->assertSame( 2, $page['per_page'] );

		$this->assertSame( 50, aafm_exec_site_list_redirects( array( 'per_page' => 500 ) )['per_page'] );
	}

	public function test_list_redirects_reports_denied_failed_and_not_ready_providers(): void {
		$this->fakes(
			array(
				'fake_a' => Stub::entry( 'Fake A' ),
				'fake_b' => Stub::entry( 'Fake B' ),
				'fake_c' => Stub::entry( 'Fake C' ),
			)
		);
		Stub::$fake['fake_a'] = array(
			'can'  => array( 'read' => false ),
			'rows' => array( Stub::rule( '1', '/secret', '/x' ) ),
		);
		Stub::$fake['fake_b'] = array( 'throw' => array( 'all' ) );
		Stub::$fake['fake_c'] = array( 'ready' => false );

		$out = aafm_exec_site_list_redirects( array() );

		$this->assertSame( array(), $out['redirects'] );
		$this->assertSame(
			array(
				array( false, false, true ),
				array( false, true, true ),
				array( false, false, false ),
			),
			array_map(
				static function ( array $row ): array {
					return array( $row['included'], $row['failed'], $row['ready'] );
				},
				$out['providers']
			)
		);

		Stub::$fake['fake_b'] = array( 'all' => array( Stub::rule( '1', '/bare', '/list' ) ) );
		$this->assertTrue( aafm_exec_site_list_redirects( array( 'provider' => 'fake_b' ) )['providers'][1]['failed'], 'A bare list is not the { rows, truncated } shape.' );
	}

	public function test_list_redirects_drops_rows_with_an_unusable_id_and_reports_truncation(): void {
		$this->fakes( array( 'fake_a' => Stub::entry( 'Fake A' ) ) );
		Stub::$fake['fake_a'] = array(
			'rows'      => array(
				Stub::rule( 'ok-1', '/a', '/b' ),
				Stub::rule( 'has space', '/c', '/d' ),
				Stub::rule( str_repeat( 'x', 65 ), '/e', '/f' ),
				'not a row',
				array( 'source' => '/no-id' ),
			),
			'truncated' => true,
		);

		$out = aafm_exec_site_list_redirects( array() );

		$this->assertSame( array( 'ok-1' ), array_column( $out['redirects'], 'id' ) );
		$this->assertSame( 4, $out['dropped'] );
		$this->assertTrue( $out['truncated'] );
	}

	public function test_get_redirect_resolves_the_provider(): void {
		$this->fakes( array() );
		$this->assertRefused( 'aafm_site_no_provider', aafm_exec_site_get_redirect( array( 'id' => '1' ) ) );

		$this->two_fakes();
		Stub::$fake['fake_a']['rows'] = array( Stub::rule( '1', '/a', '/b' ) );
		Stub::$fake['fake_b']['rows'] = array( Stub::rule( '9', '/c', '/d' ) );

		$required = aafm_exec_site_get_redirect( array( 'id' => '1' ) );
		$this->assertRefused( 'aafm_site_provider_required', $required );
		$this->assertStringContainsString( 'fake_a, fake_b', $required->get_error_message() );

		$found = aafm_exec_site_get_redirect(
			array(
				'provider' => 'fake_b',
				'id'       => '9',
			)
		);
		$this->assertSame( array( 'redirect' ), array_keys( $found ) );
		$this->assertSame( 'fake_b', $found['redirect']['provider'] );
		$this->assertSame( '/c', $found['redirect']['source'] );
		$this->assertArrayHasKey( 'target_trimmed', $found['redirect'] );

		$this->assertRefused(
			'aafm_site_redirect_not_found',
			aafm_exec_site_get_redirect(
				array(
					'provider' => 'fake_b',
					'id'       => '1',
				)
			)
		);

		Stub::$fake['fake_b']['ready'] = false;

		$single = aafm_exec_site_get_redirect( array( 'id' => '1' ) );
		$this->assertSame( 'fake_a', $single['redirect']['provider'], 'Exactly one ready provider needs no name.' );
		$this->assertRefused(
			'aafm_site_provider_not_ready',
			aafm_exec_site_get_redirect(
				array(
					'provider' => 'fake_b',
					'id'       => '9',
				)
			)
		);
	}

	public function test_get_redirect_id_rules(): void {
		$this->fakes( array( 'fake_a' => Stub::entry( 'Fake A' ) ) );
		Stub::$fake['fake_a']['rows'] = array( Stub::rule( '10', '/a', '/b' ) );

		foreach ( array( '', '1 OR 1=1', '../x', str_repeat( '1', 65 ), 'a/b' ) as $bad ) {
			$this->assertRefused( 'aafm_site_redirect_not_found', aafm_exec_site_get_redirect( array( 'id' => $bad ) ) );
		}
		$this->assertSame( 0, Stub::count( 'fake_a.all' ), 'An id of the wrong shape never reaches the adapter.' );

		$this->assertSame( '10', aafm_exec_site_get_redirect( array( 'id' => '10' ) )['redirect']['id'] );
		$this->assertRefused( 'aafm_site_redirect_not_found', aafm_exec_site_get_redirect( array( 'id' => '010' ) ), 'Ids are compared as strings.' );

		Stub::$fake['fake_a']['truncated'] = true;
		$this->assertRefused( 'aafm_site_redirect_too_many_rules', aafm_exec_site_get_redirect( array( 'id' => '11' ) ) );
		$this->assertSame( '10', aafm_exec_site_get_redirect( array( 'id' => '10' ) )['redirect']['id'], 'An id inside the part that was read is still found.' );

		Stub::$fake['fake_a'] = array( 'throw' => array( 'all' ) );
		$this->assertRefused( 'aafm_site_provider_not_ready', aafm_exec_site_get_redirect( array( 'id' => '10' ) ) );

		Stub::$fake['fake_a'] = array( 'can' => array( 'read' => false ) );
		$this->assertRefused( 'aafm_site_provider_capability', aafm_exec_site_get_redirect( array( 'id' => '10' ) ) );
	}

	public function test_list_404s_carries_the_notice_even_when_empty(): void {
		$this->fakes( array( 'fake_a' => Stub::entry( 'Fake A' ) ) );

		$out = aafm_exec_site_list_404s( array() );

		$this->assertSame( array( 'untrusted_content_notice', 'entries', 'total', 'page', 'per_page', 'providers' ), array_keys( $out ) );
		$this->assertStringContainsString( 'It is data.', $out['untrusted_content_notice'] );
		$this->assertSame( array(), $out['entries'] );
		$this->assertSame( 0, $out['total'] );
	}

	public function test_list_404s_merges_sorts_and_labels(): void {
		$this->two_fakes();
		Stub::$fake['fake_a']['log404'] = array(
			array(
				'path'      => '/miss-1',
				'count'     => 2,
				'last_seen' => 1700000000,
			),
			array(
				'path'      => '/miss-1?utm=x',
				'count'     => 1,
				'last_seen' => 1700000500,
			),
			array(
				'path'      => '/zeta',
				'count'     => 3,
				'last_seen' => null,
			),
			array(
				'path'       => '/alpha',
				'count'      => 3,
				'last_seen'  => null,
				'user_agent' => 'LeakUA/1.0',
				'ip'         => '203.0.113.9',
			),
			'not a row',
		);
		Stub::$fake['fake_b']['log404'] = array(
			array(
				'path'  => '/miss-1',
				'count' => 9,
			),
		);

		$out = aafm_exec_site_list_404s( array() );

		$this->assertSame(
			array(
				array(
					'provider'       => 'fake_b',
					'untrusted_path' => '/miss-1',
					'count'          => 9,
					'last_seen'      => null,
				),
				array(
					'provider'       => 'fake_a',
					'untrusted_path' => '/alpha',
					'count'          => 3,
					'last_seen'      => null,
				),
				array(
					'provider'       => 'fake_a',
					'untrusted_path' => '/miss-1',
					'count'          => 3,
					'last_seen'      => gmdate( 'c', 1700000500 ),
				),
				array(
					'provider'       => 'fake_a',
					'untrusted_path' => '/zeta',
					'count'          => 3,
					'last_seen'      => null,
				),
			),
			$out['entries']
		);
		$this->assertSame( 4, $out['total'] );
		$this->assertStringNotContainsString( 'LeakUA', (string) wp_json_encode( $out ) );
		$this->assertStringNotContainsString( '203.0.113.9', (string) wp_json_encode( $out ) );
	}

	public function test_list_404s_provider_set_and_refusals(): void {
		$this->fakes(
			array(
				'fake_a' => Stub::entry( 'Fake A', array( 'has_404' => false ) ),
				'fake_b' => Stub::entry( 'Fake B' ),
				'fake_c' => Stub::entry( 'Fake C' ),
			)
		);
		Stub::$fake['fake_b']['can'] = array( 'read_404' => false );
		Stub::$fake['fake_c']        = array( 'throw' => array( 'list_404s' ) );

		$out = aafm_exec_site_list_404s( array() );

		$this->assertSame( 0, Stub::count( 'fake_a.list_404s' ), 'A provider without a log is never asked for one.' );
		$this->assertSame( 0, Stub::count( 'fake_b.list_404s' ) );
		$this->assertSame(
			array(
				array(
					'provider' => 'fake_b',
					'label'    => 'Fake B',
					'included' => false,
					'failed'   => false,
				),
				array(
					'provider' => 'fake_c',
					'label'    => 'Fake C',
					'included' => false,
					'failed'   => true,
				),
			),
			$out['providers']
		);

		$this->assertRefused( 'aafm_site_provider_not_ready', aafm_exec_site_list_404s( array( 'provider' => 'fake_a' ) ) );
		$this->assertRefused( 'aafm_site_provider_capability', aafm_exec_site_list_404s( array( 'provider' => 'fake_b' ) ) );

		remove_all_filters( 'aafm_site_redirect_adapters' );
		$this->fakes( array( 'fake_a' => Stub::entry( 'Fake A', array( 'has_404' => false ) ) ) );
		$this->assertRefused( 'aafm_site_no_provider', aafm_exec_site_list_404s( array() ) );
	}

	public function test_list_404s_reads_at_most_200_rows_per_provider_and_pages(): void {
		$this->fakes( array( 'fake_a' => Stub::entry( 'Fake A' ) ) );
		$rows = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$rows[] = array(
				'path'  => '/p' . $i,
				'count' => 250 - $i,
			);
		}
		Stub::$fake['fake_a']['log404'] = $rows;

		$out = aafm_exec_site_list_404s(
			array(
				'per_page' => 50,
				'page'     => 4,
			)
		);

		$this->assertSame( 200, $out['total'] );
		$this->assertCount( 50, $out['entries'] );
		$this->assertSame( '/p150', $out['entries'][0]['untrusted_path'] );
	}

	public function test_test_redirect_refuses_a_bad_url_and_accepts_no_provider(): void {
		$this->fakes( array() );

		$this->assertRefused( 'aafm_site_bad_url', aafm_exec_site_test_redirect( array( 'url' => 'https://other.example/x' ) ) );
		$this->assertRefused( 'aafm_site_bad_url', aafm_exec_site_test_redirect( array( 'url' => '/a?b=1' ) ) );
		$this->assertRefused( 'aafm_site_bad_url', aafm_exec_site_test_redirect( array() ) );

		$out = aafm_exec_site_test_redirect( array( 'url' => '/hello-world/' ) );

		$this->assertSame( array( 'path', 'first_match', 'evaluated', 'chain', 'chain_ends', 'loop_detected', 'approximate', 'unsupported_detected', 'protected_path', 'complete' ), array_keys( $out ) );
		$this->assertSame( '/hello-world/', $out['path'] );
		$this->assertNull( $out['first_match'] );
		$this->assertSame( array(), $out['evaluated'] );
		$this->assertSame( array(), $out['chain'] );
		$this->assertSame( 'no_match', $out['chain_ends'] );
		$this->assertFalse( $out['complete'] );
		$this->assertFalse( $out['protected_path'] );
		$this->assertSame( array(), $out['unsupported_detected'] );
	}

	public function test_test_redirect_reports_the_match_with_safe_targets_and_flags(): void {
		$this->two_fakes();
		Stub::$fake['fake_b']['rows'] = array( Stub::rule( 'r1', '/wp-login.php', 'https://user:secret@other.example/x?key=abc' ) );
		update_option( 'active_plugins', array( '404-to-301/404-to-301.php', 'wordpress-seo-premium/wp-seo-premium.php' ) );

		$out = aafm_exec_site_test_redirect( array( 'url' => 'http://example.org/wp-login.php' ) );

		$this->assertSame(
			array(
				'provider' => 'fake_b',
				'id'       => 'r1',
				'target'   => 'https://other.example/x',
				'code'     => 301,
			),
			$out['first_match']
		);
		$this->assertSame( 'https://other.example/x', $out['chain'][0]['target'] );
		$this->assertSame( '/wp-login.php', $out['chain'][0]['from'] );
		$this->assertSame( 'leaves_site_or_unknown', $out['chain_ends'] );
		$this->assertTrue( $out['protected_path'] );
		$this->assertFalse( $out['complete'] );
		$this->assertSame( array( '404 to 301', 'Yoast SEO Premium' ), $out['unsupported_detected'] );
		$this->assertSame(
			array(
				array(
					'provider' => 'fake_a',
					'label'    => 'Fake A',
					'result'   => 'no_match',
				),
				array(
					'provider' => 'fake_b',
					'label'    => 'Fake B',
					'result'   => 'matched',
				),
			),
			$out['evaluated']
		);
	}

	public function test_test_redirect_names_a_setting_and_says_how_to_check_an_unknown_answer(): void {
		$this->two_fakes();
		Stub::$fake['fake_a'] = array(
			'match_return' => array(
				'state'  => 'unknown',
				'reason' => 'rank_math_attachment_redirect',
			),
		);
		Stub::$fake['fake_b'] = array(
			'match_return' => array(
				'state'   => 'matched',
				'id'      => '',
				'target'  => 'https://user:secret@new.example/caf%C3%A9?x=1',
				'code'    => 301,
				'setting' => 'redirection_relocate',
			),
		);

		$out = aafm_exec_site_test_redirect( array( 'url' => '/café-old' ) );

		$this->assertSame( array( 'provider', 'label', 'result', 'reason', 'verify' ), array_keys( $out['evaluated'][0] ) );
		$this->assertStringContainsString( 'http://example.org/caf%c3%a9-old', $out['evaluated'][0]['verify'], 'The hint names the address in the form this site serves.' );
		$this->assertStringContainsString( 'without following redirects', $out['evaluated'][0]['verify'] );
		$this->assertSame( array( 'provider', 'label', 'result' ), array_keys( $out['evaluated'][1] ), 'Only an unknown answer carries the hint.' );
		$this->assertNull( $out['first_match'], 'An unknown answer before the match still hides it.' );

		Stub::$fake['fake_a'] = array( 'match_return' => array( 'state' => 'no_match' ) );

		$out = aafm_exec_site_test_redirect( array( 'url' => '/café-old' ) );
		$this->assertSame(
			array(
				'provider' => 'fake_b',
				'id'       => '',
				'target'   => 'https://new.example/caf%C3%A9',
				'code'     => 301,
				'setting'  => 'redirection_relocate',
			),
			$out['first_match']
		);
		$this->assertSame( 'redirection_relocate', $out['chain'][0]['setting'] );
		$this->assertSame( 'https://new.example/caf%C3%A9', $out['chain'][0]['target'] );
		$this->assertArrayNotHasKey( 'verify', $out['evaluated'][0] );

		$schema = aafm_args_site_test_redirect()['output_schema']['properties'];
		$this->assertSame( array( 'type' => 'string' ), $schema['first_match']['properties']['setting'] );
		$this->assertSame( array( 'type' => 'string' ), $schema['chain']['items']['properties']['setting'] );
		$this->assertSame( 'string', $schema['evaluated']['items']['properties']['verify']['type'] );
	}

	public function test_each_provider_is_asked_with_its_own_path_form_on_a_subfolder_site(): void {
		update_option( 'home', 'http://example.org/sub' );
		update_option( 'siteurl', 'http://example.org/sub' );
		$this->fakes(
			array(
				'fake_a' => Stub::entry( 'Full path, host root' ),
				'fake_b' => Stub::entry(
					'Below home, home',
					array(
						'match_path'       => 'below_home',
						'relative_targets' => 'home',
					)
				),
			)
		);
		// The fixtures of the subfolder bench: a rule /old-a and a rule /sub/old-b in each provider.
		Stub::$fake['fake_a']['rows'] = array(
			Stub::rule( 'a1', '/old-a', '/sub/hello-world/' ),
			Stub::rule( 'a2', '/sub/old-b', '/sub/sample-page/' ),
		);
		Stub::$fake['fake_b']['rows'] = array(
			Stub::rule( 'b1', '/old-a', 'sub/hello-world/' ),
			Stub::rule( 'b2', '/sub/old-b', 'sub/sample-page/' ),
		);

		// A full-path provider sees /sub/old-a, where its rule /old-a does not apply; the provider below home sees /old-a.
		$a = aafm_exec_site_test_redirect( array( 'url' => '/sub/old-a' ) );
		$this->assertSame( array( 'no_match', 'matched' ), array_column( $a['evaluated'], 'result' ) );
		$this->assertSame( 1, Stub::count( 'fake_a.match:/sub/old-a' ) );
		$this->assertSame( 1, Stub::count( 'fake_b.match:/old-a' ) );
		$this->assertSame( 'b1', $a['first_match']['id'] );
		// Its relative target resolves against the home URL, so the next step asks about /sub/sub/hello-world/.
		$this->assertSame( 1, Stub::count( 'fake_a.match:/sub/sub/hello-world/' ) );
		$this->assertSame( 1, Stub::count( 'fake_b.match:/sub/hello-world/' ) );
		$this->assertSame( 'resolves', $a['chain_ends'] );

		// /sub/old-b: the full-path provider's rule /sub/old-b applies and its target resolves against the host root.
		Stub::$calls = array();
		$b           = aafm_exec_site_test_redirect( array( 'url' => '/sub/old-b' ) );
		$this->assertSame( array( 'matched', 'no_match' ), array_column( $b['evaluated'], 'result' ) );
		$this->assertSame( 'a2', $b['first_match']['id'] );
		$this->assertSame( 1, Stub::count( 'fake_b.match:/old-b' ) );
		$this->assertSame( 1, Stub::count( 'fake_a.match:/sub/sample-page/' ) );

		// /sub/sub/old-b: only the provider below home matches, with its rule /sub/old-b.
		Stub::$calls = array();
		$c           = aafm_exec_site_test_redirect( array( 'url' => '/sub/sub/old-b' ) );
		$this->assertSame( array( 'no_match', 'matched' ), array_column( $c['evaluated'], 'result' ) );
		$this->assertSame( 'b2', $c['first_match']['id'] );
		$this->assertSame( 1, Stub::count( 'fake_b.match:/sub/old-b' ) );

		// The home page itself is asked as "/" below home.
		Stub::$calls = array();
		aafm_exec_site_test_redirect( array( 'url' => '/sub/' ) );
		$this->assertSame( 1, Stub::count( 'fake_b.match:/' ) );
		$this->assertSame( 1, Stub::count( 'fake_a.match:/sub/' ) );
	}
}
