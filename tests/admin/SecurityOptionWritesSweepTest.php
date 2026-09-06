<?php
/**
 * Codex hunt F1: the six admin AJAX handlers that save security allowlist options (exposed post
 * types, post/user/term meta allow+deny) must route every write through
 * aafm_update_option_verified(), never a bare update_option(). A stale persistent object cache
 * can otherwise make the write silently no-op while the handler still reports success (the exact
 * shape aafm_update_option_verified() exists to catch - see includes/option-cache.php).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class SecurityOptionWritesSweepTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'wp_die_handler' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		unset(
			$_POST['nonce'],
			$_REQUEST['nonce'],
			$_POST['aafm_post_types'],
			$_POST['aafm_meta_keys'],
			$_POST['aafm_deny_meta_keys'],
			$_POST['aafm_exposed_user_meta_keys'],
			$_POST['aafm_denied_user_meta_keys'],
			$_POST['aafm_exposed_term_meta_keys'],
			$_POST['aafm_denied_term_meta_keys']
		);
		wp_cache_delete( 'alloptions', 'options' );
		delete_option( 'aafm_allowed_post_types' );
		parent::tear_down();
	}

	/**
	 * Static source scan, mirrors PageBuilderGuardSweepTest's mechanical approach: a bare
	 * update_option()/delete_option()/add_option() call naming one of these six security options
	 * is a regression, whichever function it appears in. Reading the source text rather than
	 * running it catches a future edit that reintroduces a bare write even if it moves to a new
	 * helper function this list has never heard of.
	 */
	public function test_no_bare_option_write_names_a_security_allowlist_option(): void {
		$guarded_options = array(
			'aafm_allowed_post_types',
			'aafm_allowed_meta_keys',
			'aafm_denied_meta_keys',
			'aafm_exposed_user_meta_keys',
			'aafm_denied_user_meta_keys',
			'aafm_exposed_term_meta_keys',
			'aafm_denied_term_meta_keys',
		);

		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/page.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this plugin's own local source to scan it, not a remote URL.
		$this->assertNotSame( '', $source, 'The sweep must actually read page.php - an empty read would make this test pass by finding nothing.' );

		foreach ( $guarded_options as $option ) {
			foreach ( array( 'update_option', 'delete_option', 'add_option' ) as $bare_call ) {
				$this->assertDoesNotMatchRegularExpression(
					'/\b' . $bare_call . '\(\s*\'' . preg_quote( $option, '/' ) . '\'/',
					$source,
					"A bare {$bare_call}() naming {$option} was found in page.php - route it through aafm_update_option_verified() instead."
				);
			}
		}
	}

	private function intercept_die(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$die = static function (): void {
			throw new \WPDieException( 'aafm-die' );
		};
		add_filter( 'wp_die_ajax_handler', static fn() => $die );
		add_filter( 'wp_die_handler', static fn() => $die );
	}

	/**
	 * Run an AJAX handler and return its captured JSON payload. Mirrors
	 * PersistentObjectCacheSwitchTest::run_handler().
	 *
	 * @param callable $handler Handler function to invoke.
	 * @return array<string,mixed>
	 */
	private function run_handler( callable $handler ): array {
		ob_start();
		try {
			$handler();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$body = (string) ob_get_clean();
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : array();
	}

	/**
	 * Plant the object cache in the state a stale persistent cache leaves behind: the autoloaded
	 * blob still carries the option's OLD value while the database row has already changed (or
	 * never held it), matching PersistentObjectCacheSwitchTest::plant_stale_on()'s shape.
	 *
	 * @param string $option Option name.
	 * @param mixed  $stale_value Value the stale blob should keep serving.
	 * @return void
	 */
	private function plant_stale_alloptions_value( string $option, $stale_value ): void {
		$all            = wp_load_alloptions( true );
		$all[ $option ] = $stale_value;
		wp_cache_set( 'alloptions', $all, 'options' );
	}

	/**
	 * F1 stale-cache regression: submitting a stricter post-types list through the AJAX handler
	 * must land in the database and be readable from a forced cache read, even when the object
	 * cache started out serving a stale, more permissive value.
	 */
	public function test_save_post_types_recovers_from_a_stale_persistent_cache(): void {
		// A public, non-builtin CPT the plain phpunit.xml.dist suite actually registers (unlike
		// WooCommerce's 'product', which only exists under phpunit-contract.xml.dist) - same
		// pattern as tests/admin/PostTypesSaveTest.php:18-23, which sanitizes against the
		// identical eligibility check this test exercises.
		register_post_type(
			'aafm_book',
			array(
				'public' => true,
				'label'  => 'Books',
			)
		);

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		update_option( 'aafm_allowed_post_types', array( 'aafm_book' ) );
		// A stale persistent cache still serving the old, wider list after the operator has
		// already asked to narrow it - the exact B146/B147 shape this option-cache helper exists
		// to close for other options (see includes/option-cache.php).
		$this->plant_stale_alloptions_value( 'aafm_allowed_post_types', array( 'aafm_book', 'attachment' ) );

		$nonce                    = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']           = $nonce;
		$_REQUEST['nonce']        = $nonce;
		$_POST['aafm_post_types'] = array( 'aafm_book' );

		$this->intercept_die();
		$json = $this->run_handler( 'aafm_ajax_save_post_types' );

		$this->assertTrue( $json['success'] ?? false, 'The save must report success once the stale cache is repaired.' );

		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'aafm_allowed_post_types' ) );
		$this->assertSame( array( 'aafm_book' ), maybe_unserialize( $raw ), 'The database row must hold the narrowed list, not the stale wider one.' );

		wp_cache_get( 'aafm_allowed_post_types', 'options', true );
		$this->assertSame( array( 'aafm_book' ), get_option( 'aafm_allowed_post_types' ), 'A forced cache read must also see the narrowed list.' );
	}
}
