<?php
/**
 * The delete sentence on the consent screen, proved for every configuration it can be read in, and
 * a guard so no other file hard-codes a Trash promise.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\ForeignAbilities;
use AAFM\Tests\TestCase;

final class ConsentDeleteClaimTest extends TestCase {

	use ForeignAbilities;

	public function set_up(): void {
		parent::set_up();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		delete_option( 'aafm_enabled_bridged_abilities' );
		delete_option( 'aafm_read_only_mode' );
	}

	public function tear_down(): void {
		$this->unregister_foreign_abilities();
		delete_option( 'aafm_enabled_abilities' );
		delete_option( 'aafm_enabled_bridged_abilities' );
		delete_option( 'aafm_read_only_mode' );
		delete_option( 'aafm_high_risk_abilities_unlocked' );
		remove_filter( 'aafm_woocommerce_active', '__return_true', 99 );
		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		parent::tear_down();
	}

	/**
	 * The rendered consent page.
	 *
	 * @return string
	 */
	private function consent(): string {
		ob_start();
		aafm_oauth_render_consent_page(
			array(
				'client_name'   => 'Demo client',
				'user_login'    => 'mcp-agent',
				'site_name'     => 'Example Site',
				'redirect_host' => 'client.example',
				'action_url'    => 'https://example.org/authorize',
				'nonce_field'   => '',
				'hidden_inputs' => array(),
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * Assert the page says exactly one of the two sentences.
	 *
	 * @param bool   $permanent Whether a permanent delete is reachable.
	 * @param string $message   Failure context.
	 * @return void
	 */
	private function assert_sentence( bool $permanent, string $message ): void {
		$html = $this->consent();
		if ( $permanent ) {
			$this->assertStringContainsString( 'Some removals are permanent.', $html, $message );
			$this->assertStringNotContainsString( 'Deletes go to Trash', $html, $message );
			$this->assertStringNotContainsString( 'recoverable, not permanent', $html, $message );
			return;
		}
		$this->assertStringContainsString( 'Deletes go to Trash.', $html, $message );
		$this->assertStringNotContainsString( 'Some removals are permanent', $html, $message );
	}

	public function test_the_consent_screen_never_promises_trash_when_any_permanent_delete_tool_is_on(): void {
		$names = aafm_permanent_delete_abilities();
		$this->assertCount( 13, $names );

		// Every floor lifted, so each of the thirteen really is on.
		add_filter( 'aafm_woocommerce_active', '__return_true', 99 );
		update_option( 'aafm_high_risk_abilities_unlocked', '1' );
		foreach ( $names as $name ) {
			update_option( 'aafm_enabled_abilities', array( $name ) );
			$this->assertContains( $name, aafm_get_enabled_abilities(), $name . ' must really be on, or this proves nothing.' );
			$this->assert_sentence( true, $name . ' enabled alone.' );
		}
	}

	public function test_a_permanent_delete_tool_a_floor_holds_back_cannot_run_so_trash_stays_true(): void {
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		delete_option( 'aafm_high_risk_abilities_unlocked' );

		foreach ( aafm_permanent_delete_abilities() as $name ) {
			update_option( 'aafm_enabled_abilities', array( $name ) );
			$this->assert_sentence( in_array( $name, aafm_get_enabled_abilities(), true ), $name . ' with the floors in place.' );
		}
	}

	public function test_a_bridged_destructive_tool_drops_the_trash_promise(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post' ) );
		$this->register_foreign_abilities( 'claimplugin', array( 'purge' => array( 'destructive' => true ) ) );
		update_option( 'aafm_enabled_bridged_abilities', array( 'claimplugin/purge' ) );

		$this->assert_sentence( true, 'A destructive bridged tool.' );
	}

	public function test_an_unlabelled_bridged_tool_drops_the_trash_promise(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post' ) );
		$this->register_foreign_abilities( 'claimplugin', array( 'silent' => array() ) );
		update_option( 'aafm_enabled_bridged_abilities', array( 'claimplugin/silent' ) );

		$this->assert_sentence( true, 'An unlabelled bridged tool fails safe to destructive.' );
	}

	public function test_only_trash_tools_keep_the_trash_promise(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post', 'aafm/trash-post' ) );

		$this->assert_sentence( false, 'Trash tools only.' );
	}

	public function test_read_only_mode_keeps_the_trash_promise(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post', 'aafm/delete-post' ) );
		update_option( 'aafm_read_only_mode', '1' );

		$this->assert_sentence( false, 'Read-only mode: nothing can delete.' );
	}

	public function test_no_source_file_hard_codes_a_trash_promise_outside_the_guarantee_function(): void {
		$files = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( AAFM_PLUGIN_DIR . 'includes', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( in_array( $file->getExtension(), array( 'php', 'css', 'js' ), true ) ) {
				$files[] = $file->getPathname();
			}
		}
		$files = array_merge( $files, (array) glob( AAFM_PLUGIN_DIR . 'assets/*.css' ) );
		$this->assertNotEmpty( $files );

		$hits = array();
		foreach ( $files as $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
			$source = (string) file_get_contents( $path );
			if ( '.php' === substr( $path, -4 ) ) {
				$source = $this->code_strings( $source, AAFM_PLUGIN_DIR . 'includes/helpers.php' === $path );
			}
			foreach ( array( 'deletes go to trash', 'recoverable, not permanent' ) as $needle ) {
				if ( false !== stripos( $source, $needle ) ) {
					$hits[] = substr( $path, strlen( AAFM_PLUGIN_DIR ) ) . ': ' . $needle;
				}
			}
		}

		$this->assertSame( array(), $hits, 'Only aafm_delete_guarantee() may state this, because only it checks whether it is true.' );
	}

	/**
	 * The string literals in a PHP file, so a comment explaining the history does not count.
	 *
	 * @param string $source         PHP source.
	 * @param bool   $skip_guarantee Leave out the body of aafm_delete_guarantee().
	 * @return string
	 */
	private function code_strings( string $source, bool $skip_guarantee ): string {
		if ( $skip_guarantee ) {
			$start = strpos( $source, 'function aafm_delete_guarantee()' );
			$this->assertNotFalse( $start );
			$source = substr( $source, 0, (int) $start ) . substr( $source, (int) strpos( $source, "\n}\n", (int) $start ) );
		}

		$out = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ), true ) ) {
				$out .= $token[1] . "\n";
			}
		}
		return $out;
	}

	public function test_the_wizard_write_note_claims_only_what_the_bundle_does(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
		$php = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/quickconnect.php' );

		$this->assertStringContainsString( 'Turning this on adds no delete tools.', $php );
		$this->assertStringNotContainsString( 'Nothing is deleted permanently', $php );

		$destructive = array_merge( aafm_permanent_delete_abilities(), aafm_recoverable_delete_abilities() );
		$this->assertSame( array(), array_values( array_intersect( aafm_quickconnect_write_abilities(), $destructive ) ), 'The sentence is true of the bundle.' );
	}
}
