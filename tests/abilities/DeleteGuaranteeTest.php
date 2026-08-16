<?php
/**
 * The delete guarantee shown on the OAuth consent screen and the Quick Connect
 * wizard, and the classification it reads from.
 *
 * The screen used to promise "Deletes go to Trash. Removals are recoverable,
 * not permanent" unconditionally, while thirteen native abilities remove content
 * for good. These pin the claim to what the site can actually do.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class DeleteGuaranteeTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
	}

	/**
	 * The drift guard. Risk and recoverability are separate axes, so a new
	 * destructive ability must be classified deliberately rather than inheriting
	 * the recoverable side by omission and quietly making the consent screen lie
	 * again.
	 */
	public function test_every_destructive_ability_is_classified_on_the_recoverability_axis(): void {
		$permanent   = aafm_permanent_delete_abilities();
		$recoverable = aafm_recoverable_delete_abilities();

		// Destructive abilities that remove nothing, so recoverability does not apply. Read from
		// the source rather than restated here: the runtime classifier reads the same list, and a
		// second copy in the test would let the two drift into disagreeing about the same ability.
		$not_removals = aafm_non_removal_destructive_abilities();

		$unclassified = array();
		foreach ( aafm_get_abilities_registry_full() as $name => $row ) {
			if ( 'destructive' !== (string) ( $row['risk'] ?? '' ) ) {
				continue;
			}
			if ( in_array( $name, $permanent, true )
				|| in_array( $name, $recoverable, true )
				|| in_array( $name, $not_removals, true ) ) {
				continue;
			}
			$unclassified[] = $name;
		}

		$this->assertSame(
			array(),
			$unclassified,
			'A destructive ability is not classified as permanent or recoverable. Add it to the right '
			. 'list in helpers.php, or the consent screen may promise recoverability it does not have.'
		);
	}

	/**
	 * No ability may sit on both sides at once.
	 */
	public function test_the_two_classifications_do_not_overlap(): void {
		$this->assertSame(
			array(),
			array_values( array_intersect( aafm_permanent_delete_abilities(), aafm_recoverable_delete_abilities() ) )
		);
	}

	/**
	 * Every classified name must be a real ability. A typo would silently drop an
	 * ability out of the permanent set and bring the false promise back.
	 */
	public function test_every_classified_name_is_a_real_ability(): void {
		$catalog = aafm_get_abilities_registry_full();
		foreach ( array_merge( aafm_permanent_delete_abilities(), aafm_recoverable_delete_abilities() ) as $name ) {
			$this->assertArrayHasKey( $name, $catalog, $name . ' is classified but is not in the catalog.' );
		}
	}

	public function test_a_site_with_no_permanent_delete_enabled_keeps_the_trash_promise(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post', 'aafm/trash-post' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		$this->assertFalse( aafm_enabled_can_delete_permanently() );
		$this->assertSame( 'Deletes go to Trash.', aafm_delete_guarantee()[0] );
		$this->assertSame( 'Removals are recoverable, not permanent.', aafm_delete_guarantee()[1] );
	}

	/**
	 * The reproduction: one permanent-delete ability enabled is enough for the
	 * old sentence to be false.
	 */
	public function test_one_enabled_permanent_delete_drops_the_trash_promise(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post', 'aafm/delete-post' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		$this->assertTrue( aafm_enabled_can_delete_permanently() );
		$this->assertNotSame( 'Deletes go to Trash.', aafm_delete_guarantee()[0] );
		$this->assertStringNotContainsString( 'recoverable, not permanent', aafm_delete_guarantee()[1] );
		$this->assertSame( 'Some removals are permanent.', aafm_delete_guarantee()[0] );
	}

	/**
	 * Read-only mode removes every write from the enabled set, so nothing can
	 * delete at all and the recoverable wording is true again.
	 */
	public function test_read_only_mode_restores_the_trash_promise(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post', 'aafm/delete-post' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
		$this->assertTrue( aafm_enabled_can_delete_permanently() );

		update_option( 'aafm_read_only_mode', '1' );
		$this->assertFalse( aafm_enabled_can_delete_permanently(), 'Read-only mode leaves nothing that can delete.' );
		$this->assertSame( 'Deletes go to Trash.', aafm_delete_guarantee()[0] );
	}

	/**
	 * The consent screen is the surface the finding was reported against, so the
	 * rendered HTML is asserted directly rather than only the helper behind it.
	 */
	public function test_the_consent_screen_does_not_promise_recoverability_when_deletes_are_permanent(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/delete-post' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		$this->assertStringNotContainsString( 'Removals are recoverable', $this->render_consent_screen() );
	}

	public function test_the_consent_screen_keeps_the_promise_when_nothing_deletes_permanently(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/trash-post' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		$this->assertStringContainsString( 'Removals are recoverable', $this->render_consent_screen() );
	}

	/**
	 * R2-11. The three classification lists name NATIVE abilities, so a destructive ability added
	 * by a third party through the aafm_abilities_registry filter appears in none of them. The
	 * guarantee used to read that silence as "not a permanent delete" and keep promising the
	 * Trash, which is the same false promise the thirteen native slugs caused, arriving through a
	 * door the hardcoded list cannot see.
	 *
	 * Unknown plus destructive resolves to permanent, matching the rule already applied to bridged
	 * abilities a few lines up: we did not write it, so we cannot promise its removals land
	 * anywhere recoverable.
	 */
	public function test_a_filter_added_destructive_ability_drops_the_trash_promise(): void {
		$this->with_registered_ability(
			'aafm/third-party-purge',
			array( 'risk' => 'destructive' ),
			function (): void {
				update_option( 'aafm_enabled_abilities', array( 'aafm/third-party-purge' ) );

				$this->assertTrue(
					aafm_enabled_can_delete_permanently(),
					'An unclassified destructive ability must resolve to permanent, not inherit the Trash promise.'
				);
				$this->assertSame( 'Some removals are permanent.', aafm_delete_guarantee()[0] );
			}
		);
	}

	/**
	 * The extension point. A third party that knows its destructive ability IS recoverable can say
	 * so, and only then does the softer wording come back. Silence produces the conservative
	 * answer, so forgetting to declare never downgrades a warning.
	 */
	public function test_a_third_party_can_declare_its_destructive_ability_recoverable(): void {
		$declare = static function ( array $slugs ): array {
			$slugs[] = 'aafm/third-party-bin';
			return $slugs;
		};
		add_filter( 'aafm_recoverable_delete_abilities', $declare );

		try {
			$this->with_registered_ability(
				'aafm/third-party-bin',
				array( 'risk' => 'destructive' ),
				function (): void {
					update_option( 'aafm_enabled_abilities', array( 'aafm/third-party-bin' ) );

					$this->assertFalse( aafm_enabled_can_delete_permanently() );
					$this->assertSame( 'Deletes go to Trash.', aafm_delete_guarantee()[0] );
				}
			);
		} finally {
			remove_filter( 'aafm_recoverable_delete_abilities', $declare );
		}
	}

	/**
	 * The same escape hatch for the other shape: risk "destructive" but nothing is removed. Two
	 * native abilities are already in that position (create-user rotates a password, and
	 * update-site-settings overwrites options), and a third-party one must not be forced to
	 * over-warn about deletions it never performs.
	 */
	public function test_a_third_party_can_declare_its_destructive_ability_removes_nothing(): void {
		$declare = static function ( array $slugs ): array {
			$slugs[] = 'aafm/third-party-rotate';
			return $slugs;
		};
		add_filter( 'aafm_non_removal_destructive_abilities', $declare );

		try {
			$this->with_registered_ability(
				'aafm/third-party-rotate',
				array( 'risk' => 'destructive' ),
				function (): void {
					update_option( 'aafm_enabled_abilities', array( 'aafm/third-party-rotate' ) );

					$this->assertFalse( aafm_enabled_can_delete_permanently() );
				}
			);
		} finally {
			remove_filter( 'aafm_non_removal_destructive_abilities', $declare );
		}
	}

	/**
	 * A filter-added ability that is not destructive at all must not trip the warning. Without
	 * this, "unknown means permanent" would over-warn on every third-party read.
	 */
	public function test_a_filter_added_read_ability_keeps_the_trash_promise(): void {
		$this->with_registered_ability(
			'aafm/third-party-report',
			array( 'risk' => 'read' ),
			function (): void {
				update_option( 'aafm_enabled_abilities', array( 'aafm/third-party-report' ) );

				$this->assertFalse( aafm_enabled_can_delete_permanently() );
				$this->assertSame( 'Deletes go to Trash.', aafm_delete_guarantee()[0] );
			}
		);
	}

	/**
	 * The two native non-removal abilities are the regression this rule could most easily cause:
	 * both are risk "destructive" and neither deletes anything, so treating unknown-destructive as
	 * permanent must not sweep them up. create-user in particular is ordinary configuration.
	 */
	public function test_the_native_non_removal_abilities_keep_the_trash_promise(): void {
		update_option( 'aafm_enabled_abilities', aafm_non_removal_destructive_abilities() );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		$this->assertNotSame( array(), aafm_non_removal_destructive_abilities() );
		$this->assertFalse(
			aafm_enabled_can_delete_permanently(),
			'An ability that deletes nothing must not make the screen warn about permanent removals.'
		);
	}

	/**
	 * Run $body with $name present in the live registry, then restore the registry exactly.
	 *
	 * The registry is memoized per request, so adding the filter is not enough on its own: the
	 * cache has to be flushed on the way in AND on the way out, or the synthetic ability leaks
	 * into whichever test runs next in this process.
	 *
	 * @param string              $name Ability name to inject.
	 * @param array<string,mixed> $row  Registry row fields to merge over the defaults.
	 * @param callable():void     $body Assertions to run while it is registered.
	 */
	private function with_registered_ability( string $name, array $row, callable $body ): void {
		$inject = static function ( array $registry ) use ( $name, $row ): array {
			$registry[ $name ] = array_merge(
				array(
					'label' => 'Third Party Ability',
					'group' => 'writes',
					'risk'  => 'destructive',
				),
				$row
			);
			return $registry;
		};

		add_filter( 'aafm_abilities_registry', $inject );
		aafm_flush_registry_cache();

		try {
			$body();
		} finally {
			remove_filter( 'aafm_abilities_registry', $inject );
			aafm_flush_registry_cache();
		}
	}

	/**
	 * Render the consent screen and return its HTML.
	 *
	 * @return string
	 */
	private function render_consent_screen(): string {
		// The consent page enqueues and prints its own stylesheet, and wp_print_styles() emits a
		// handle once per PHP process. Without this reset the first render here consumes the
		// handle and a LATER test in another file that asserts the <link> is present fails,
		// with nothing in its own file to explain why. Reset before and after so this test
		// neither inherits nor leaves that state.
		$GLOBALS['wp_styles'] = null;

		ob_start();
		aafm_oauth_render_consent_page(
			array(
				'client_name'    => 'Test Client',
				'user_login'     => 'admin',
				'site_name'      => 'Test Site',
				'redirect_host'  => 'example.com',
				'high_privilege' => false,
				'action_url'     => 'https://example.com/authorize',
				'nonce_field'    => '',
				'hidden_inputs'  => array(),
			)
		);
		$html                 = (string) ob_get_clean();
		$GLOBALS['wp_styles'] = null;
		return $html;
	}
}
