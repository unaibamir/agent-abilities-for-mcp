<?php
/**
 * Codex hunt F1: the six admin AJAX handlers that save security allowlist options (exposed post
 * types, post/user/term meta allow+deny) must route every write through
 * aafm_update_option_verified(), never a bare update_option(). A stale persistent object cache
 * can otherwise make the write silently no-op while the handler still reports success (the exact
 * shape aafm_update_option_verified() exists to catch - see includes/option-cache.php).
 *
 * Codex round 5, R5-3 widened this from a fixed scan of includes/admin/page.php alone: the same
 * risk applies to every security/configuration option this plugin defines, wherever in includes/
 * it might be written, not only the original seven. The scan now walks the whole includes/ tree
 * and checks the full guarded list, with an explicit, file-scoped allowlist for the rare bare
 * write that really is safe.
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
	 * Every security/configuration option this plugin defines, wherever it might be written.
	 * Kept as one list (rather than per-file) so a future addition to any of the writers above
	 * only needs one new line here, not one per file it happens to touch.
	 *
	 * @return list<string>
	 */
	private function guarded_security_options(): array {
		return array(
			'aafm_enabled_abilities',
			'aafm_enabled_bridged_abilities',
			'aafm_ability_allowlist_overrides',
			'aafm_allowed_post_types',
			'aafm_allowed_meta_keys',
			'aafm_denied_meta_keys',
			'aafm_exposed_user_meta_keys',
			'aafm_denied_user_meta_keys',
			'aafm_exposed_term_meta_keys',
			'aafm_denied_term_meta_keys',
			'aafm_high_risk_abilities_unlocked',
			'aafm_read_only_mode',
			'aafm_oauth_enabled',
			'aafm_oauth_dcr_enabled',
			'aafm_oauth_toggle_migrated',
			'aafm_oauth_dcr_default_on_migrated',
			'aafm_rate_limit_per_min',
			'aafm_max_title_len',
			'aafm_log_retention_days',
			'aafm_force_draft',
			'aafm_block_guard_strict',
			'aafm_delete_data_on_uninstall',
			'aafm_ip_allowlist',
		);
	}

	/**
	 * Static source scan, mirrors PageBuilderGuardSweepTest's mechanical approach, widened from
	 * includes/admin/page.php alone to every file under includes/ (Codex round 5, R5-3): a bare
	 * update_option()/delete_option()/add_option() call naming one of the guarded security
	 * options is a regression, whichever file or function it appears in. Reading the source text
	 * rather than running it catches a future edit that reintroduces a bare write even if it
	 * moves to a new file or helper function this list has never heard of.
	 *
	 * A file-scoped allowlist covers the one bare write that really is safe: the two add_option()
	 * calls in aafm_oauth_seed_default_options() (includes/oauth/discovery.php), which run once
	 * at activation, never overwrite an existing row by design, and seed both OAuth options to
	 * their safe default. Every other file still fails the scan for the same two option names.
	 */
	public function test_no_bare_option_write_names_a_security_allowlist_option(): void {
		$guarded_options = $this->guarded_security_options();

		$allowlist = array(
			'includes/oauth/discovery.php' => array( 'aafm_oauth_enabled', 'aafm_oauth_dcr_enabled' ),
		);

		$includes_dir = AAFM_PLUGIN_DIR . 'includes';
		$files        = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $includes_dir, \FilesystemIterator::SKIP_DOTS )
		);

		$scanned = 0;
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = 'includes/' . ltrim( str_replace( $includes_dir, '', $file->getPathname() ), '/' );
			if ( 'includes/option-cache.php' === $relative ) {
				// This file IS the verified-write primitive: its own bare update_option()/
				// delete_option() calls write through a $variable option name, never a literal
				// guarded one, and are what every other file's verified helper call routes
				// through. Scanning it would just be checking the lock against itself.
				continue;
			}

			$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this plugin's own local source to scan it, not a remote URL.
			$this->assertNotSame( '', $source, "The sweep must actually read {$relative} - an empty read would make this test pass by finding nothing." );
			++$scanned;

			$exempt = $allowlist[ $relative ] ?? array();
			foreach ( $guarded_options as $option ) {
				if ( in_array( $option, $exempt, true ) ) {
					continue;
				}
				foreach ( array( 'update_option', 'delete_option', 'add_option' ) as $bare_call ) {
					$this->assertDoesNotMatchRegularExpression(
						'/\b' . $bare_call . '\(\s*\'' . preg_quote( $option, '/' ) . '\'/',
						$source,
						"A bare {$bare_call}() naming {$option} was found in {$relative} - route it through aafm_update_option_verified() instead."
					);
				}
			}
		}

		$this->assertGreaterThan( 50, $scanned, 'The sweep must actually walk includes/ - too few files scanned would make this test pass by finding nothing.' );
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
