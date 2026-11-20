<?php
/**
 * Slim SEO with its redirect module on but a class of it missing, as after an update that renamed
 * one: nothing was evaluated, so the answer is unknown, not no_match. Runs in its own process,
 * because once a stub defines a vendor class it cannot be removed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class SiteRedirectSlimSeoDriftTest extends TestCase {

	/**
	 * The module on and its settings class missing: unknown; the module off: no_match.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_slim_seo_with_its_module_on_and_a_class_missing_answers_unknown(): void {
		aafm_siteops_redirect_load();
		$match = aafm_site_redirect_function( 'slim_seo', 'match' );
		$this->assertSame( 'aafm_redirect_slim_seo_match', $match );
		$this->assertFalse( class_exists( 'SlimSEO\Redirection\Settings', false ), 'No stub in this process.' );

		$cache = array();
		$this->assertSame( array( 'state' => 'unknown' ), $match( '/acc-none', $cache ), 'The module is on and nothing was evaluated.' );
		update_option( 'slim_seo', array( 'features' => array( 'meta_title' ) ) );
		$this->assertSame( array( 'state' => 'no_match' ), $match( '/acc-none', $cache ), 'With the module off none of it runs.' );
	}
}
