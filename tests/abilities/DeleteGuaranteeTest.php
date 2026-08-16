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
	 * The other half of unknown: an ability with NO risk key at all.
	 *
	 * The registry reads risk with a `?? ''` fallback, so a row that never declares one used to
	 * land on "not destructive" and keep the Trash promise. That is a false reassurance, and it is
	 * not worth the same as the false alarm on the other side: warning about permanence on a site
	 * that has none costs an annotation, while promising recoverability about a permanent delete
	 * costs the site owner their data. So an unannotated ability resolves to permanent.
	 */
	public function test_an_ability_with_no_risk_annotation_drops_the_trash_promise(): void {
		$this->with_registered_ability(
			'aafm/third-party-unannotated',
			array( 'risk' => null ),
			function (): void {
				$this->assertArrayNotHasKey(
					'risk',
					aafm_get_abilities_registry()['aafm/third-party-unannotated'],
					'The fixture must carry NO risk key, not a blank one - that is the case under test.'
				);

				update_option( 'aafm_enabled_abilities', array( 'aafm/third-party-unannotated' ) );

				$this->assertTrue(
					aafm_enabled_can_delete_permanently(),
					'An ability that declares no risk must fail closed, not inherit the Trash promise.'
				);
				$this->assertSame( 'Some removals are permanent.', aafm_delete_guarantee()[0] );
			}
		);
	}

	/**
	 * The blank spelling of the same thing. A row carrying `risk => ''` says no more than a row
	 * carrying no risk at all, so it must not resolve differently.
	 */
	public function test_an_ability_with_a_blank_risk_annotation_drops_the_trash_promise(): void {
		$this->with_registered_ability(
			'aafm/third-party-blank-risk',
			array( 'risk' => '' ),
			function (): void {
				update_option( 'aafm_enabled_abilities', array( 'aafm/third-party-blank-risk' ) );

				$this->assertTrue( aafm_enabled_can_delete_permanently() );
			}
		);
	}

	/**
	 * The regression the fail-closed rule could cause, pinned. Every ability this plugin ships
	 * declares a risk, so the rule must never fire for one of ours - if a native row ever loses its
	 * annotation, the whole consent screen would start warning about permanence on every site.
	 */
	public function test_every_shipped_ability_declares_a_risk(): void {
		$missing = array();
		foreach ( aafm_get_abilities_registry_full() as $name => $row ) {
			if ( ! array_key_exists( 'risk', $row ) || '' === (string) $row['risk'] ) {
				$missing[] = $name;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"These abilities declare no risk. aafm_enabled_can_delete_permanently() fails closed on a\n"
			. "missing annotation, so each of these would make the consent screen warn about permanent\n"
			. "removals on every site that enables it. Annotate them.\n  "
			. implode( "\n  ", $missing )
		);
	}

	/**
	 * The escape hatches have to reach the UNANNOTATED case too, not only the annotated one.
	 *
	 * Failing closed on a missing risk is only defensible if the author has a way out of the false
	 * alarm. If the hatches worked solely for a row already declaring risk: destructive, an
	 * unannotated ability would be stuck warning forever and the fail-closed rule would be a trap
	 * rather than a default. The ordering in aafm_enabled_can_delete_permanently() is what makes
	 * this work - both list checks run BEFORE the risk is ever read - and ordering is exactly the
	 * kind of thing that survives a refactor by luck, so it is pinned rather than reasoned about.
	 */
	public function test_the_recoverable_hatch_works_for_an_ability_with_no_risk_annotation(): void {
		$declare = static function ( array $slugs ): array {
			$slugs[] = 'aafm/third-party-bin-unannotated';
			return $slugs;
		};
		add_filter( 'aafm_recoverable_delete_abilities', $declare );

		try {
			$this->with_registered_ability(
				'aafm/third-party-bin-unannotated',
				array( 'risk' => null ),
				function (): void {
					update_option( 'aafm_enabled_abilities', array( 'aafm/third-party-bin-unannotated' ) );

					$this->assertFalse(
						aafm_enabled_can_delete_permanently(),
						'Declaring an unannotated ability recoverable must clear the warning, or the author has no way out.'
					);
					$this->assertSame( 'Deletes go to Trash.', aafm_delete_guarantee()[0] );
				}
			);
		} finally {
			remove_filter( 'aafm_recoverable_delete_abilities', $declare );
		}
	}

	/**
	 * The same reach for the other hatch.
	 */
	public function test_the_non_removal_hatch_works_for_an_ability_with_no_risk_annotation(): void {
		$declare = static function ( array $slugs ): array {
			$slugs[] = 'aafm/third-party-rotate-unannotated';
			return $slugs;
		};
		add_filter( 'aafm_non_removal_destructive_abilities', $declare );

		try {
			$this->with_registered_ability(
				'aafm/third-party-rotate-unannotated',
				array( 'risk' => null ),
				function (): void {
					update_option( 'aafm_enabled_abilities', array( 'aafm/third-party-rotate-unannotated' ) );

					$this->assertFalse(
						aafm_enabled_can_delete_permanently(),
						'Declaring an unannotated ability a non-removal must clear the warning.'
					);
				}
			);
		} finally {
			remove_filter( 'aafm_non_removal_destructive_abilities', $declare );
		}
	}

	/**
	 * The thirteen hand-classified permanent slugs keep their precedence over every annotation.
	 *
	 * They are classified by hand precisely because the risk annotation cannot answer
	 * recoverability, so a row claiming risk: read must not be able to talk one of them off the
	 * permanent side. The permanent check runs first in the loop for this reason; this pins that
	 * ordering rather than trusting it.
	 */
	public function test_a_hand_classified_permanent_slug_outranks_its_risk_annotation(): void {
		$this->with_registered_ability(
			'aafm/delete-post',
			array( 'risk' => 'read' ),
			function (): void {
				update_option( 'aafm_enabled_abilities', array( 'aafm/delete-post' ) );

				$this->assertTrue(
					aafm_enabled_can_delete_permanently(),
					'A hand-classified permanent delete must stay permanent whatever its annotation says.'
				);
			}
		);
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
	 * Passing null for a field removes it from the row entirely, which is how a test builds the
	 * row a careless extender would: one with no risk annotation at all, rather than a blank one.
	 *
	 * @param string              $name Ability name to inject.
	 * @param array<string,mixed> $row  Registry row fields to merge over the defaults; a null value removes the key.
	 * @param callable():void     $body Assertions to run while it is registered.
	 */
	private function with_registered_ability( string $name, array $row, callable $body ): void {
		$inject = static function ( array $registry ) use ( $name, $row ): array {
			$built = array_merge(
				array(
					'label' => 'Third Party Ability',
					'group' => 'writes',
					'risk'  => 'destructive',
				),
				$row
			);
			foreach ( $row as $key => $value ) {
				if ( null === $value ) {
					unset( $built[ $key ] );
				}
			}
			$registry[ $name ] = $built;
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
