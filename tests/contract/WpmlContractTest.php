<?php
/**
 * WPML vendor-contract tests: the plugin's language-scoped reads must agree with WPML's own
 * language view.
 *
 * THE STANDING RULE FOR THIS SUITE: a stub may only model behaviour that a contract test here has
 * confirmed against the REAL vendor. When a stub and a contract test disagree, the stub is wrong.
 *
 * WPML has no free wp.org zip (it is licensed), so tests/bin/install-vendors.sh does not provision
 * it and this leg skips in CI for now — wiring a licensed WPML zip via a secret is a pending
 * operator decision (see the note in .github/workflows/contract.yml). tests/contract/bootstrap.php
 * already loads `sitepress-multilingual-cms` when it is present in the throwaway WP_CORE_DIR test
 * core's wp-content/plugins, so dropping a real, licensed copy there is enough to make this leg
 * assert for real instead of skip.
 *
 * Run: vendor/bin/phpunit -c phpunit-contract.xml.dist (after tests/bin/install-vendors.sh).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Contract;

use AAFM\Tests\TestCase;

/**
 * Asserts the real WPML contracts the language-scoped read abilities rely on. Read-only: it
 * asserts against whatever content already exists in the test core rather than creating posts,
 * terms, or WPML config, so the contract holds regardless of how much (or how little) content the
 * test core carries.
 *
 * @group contract
 */
final class WpmlContractTest extends TestCase {

	/**
	 * Skip the whole class unless real WPML is actually loaded — the documented `wpml_loaded`
	 * action, not an undocumented ICL_* constant.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! aafm_wpml_active() ) {
			$this->markTestSkipped( 'Real WPML not loaded — see tests/contract/bootstrap.php for provisioning.' );
		}

		// The audited registration wrapper logs every permission check and execute to the custom
		// table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Administrator so count-posts' by_status breakdown is not zeroed for non-public statuses,
		// keeping the count-vs-list comparison meaningful regardless of what the test core holds.
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/count-posts', 'aafm/get-posts' ) );
	}

	/**
	 * The core honesty assertion this leg exists for (1.3.2): count-posts' `publish` tally for a
	 * language must equal get-posts' found-total for the SAME language. Before the fix these could
	 * diverge — count-posts read the language-blind wp_count_posts() while get-posts was already
	 * scoped, so a multilingual store could see a count that disagreed with its own list.
	 */
	public function test_count_posts_matches_scoped_list_total_for_default_language(): void {
		$default = aafm_wpml_default_language();
		$this->assertNotNull( $default, 'WPML must report a default language once loaded.' );

		$count = wp_get_ability( 'aafm/count-posts' )->execute( array( 'lang' => $default ) );
		$this->assertIsArray( $count, 'count-posts must return an array, not a WP_Error.' );
		$this->assertSame( $default, $count['language'], 'count-posts must echo back the resolved language.' );

		$by_status = (array) $count['by_status'];
		$this->assertArrayHasKey( 'publish', $by_status, 'by_status must carry a publish bucket.' );

		$list = wp_get_ability( 'aafm/get-posts' )->execute(
			array(
				'lang'     => $default,
				'status'   => 'publish',
				'per_page' => 1,
			)
		);
		$this->assertIsArray( $list, 'get-posts must return an array, not a WP_Error.' );
		$this->assertSame( $default, $list['language'], 'get-posts must echo back the resolved language.' );

		$this->assertSame(
			(int) $by_status['publish'],
			(int) $list['total'],
			'count-posts\' publish tally and get-posts\' found-total must agree for the same language.'
		);
	}

	/**
	 * For every active language, get-posts must report the requested language back, and every
	 * returned post that carries a `lang` field must carry that SAME language — no cross-language
	 * leakage into a list scoped to a different one.
	 */
	public function test_get_posts_respects_requested_language_with_no_cross_language_leakage(): void {
		$langs = aafm_wpml_active_language_codes();
		$this->assertNotEmpty( $langs, 'WPML must report at least one active language once loaded.' );

		foreach ( $langs as $lang ) {
			$out = wp_get_ability( 'aafm/get-posts' )->execute( array( 'lang' => $lang ) );
			$this->assertIsArray( $out, "get-posts must return an array, not a WP_Error, for lang={$lang}." );
			$this->assertSame(
				$lang,
				$out['language'],
				"get-posts must echo back the requested language {$lang}."
			);

			foreach ( $out['posts'] as $post ) {
				if ( isset( $post['lang'] ) ) {
					$this->assertSame(
						$lang,
						$post['lang'],
						"A post returned for lang={$lang} must carry that same lang — no cross-language leakage."
					);
				}
			}
		}
	}
}
