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

		// Destructive abilities that remove nothing, so recoverability does not apply.
		$not_removals = array( 'aafm/create-user', 'aafm/update-site-settings' );

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
