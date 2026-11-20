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
}
