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
			'aafm_oauth_schema_version',
			'aafm_activity_log_schema_version',
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
	 * Strips one named top-level function's body out of source text, mirrors
	 * SecurityRegressionTest::strip_function_body(). Used below to scope the discovery.php
	 * exemption to the seed function alone rather than the whole file (Codex round 6, B6-7).
	 *
	 * A real token walk, not a line-based brace-depth counter (Codex round 7, R7-7): the prior
	 * regex-and-line-count version matched the exempt function's declaration line (which also
	 * carries the opening `{`) without ever counting that brace, so a bare `}` closing line made
	 * the depth counter go negative without ever satisfying its own "line contains `{`" exit
	 * condition. Stripping then continued past the function's real end through every following
	 * top-level line - silently deleting a violation placed anywhere after the exempt function,
	 * all the way to the next function declaration that happened to contain a `{`. Walking
	 * `token_get_all()`'s tokens instead finds the true opening brace after the matched T_FUNCTION
	 * + T_STRING pair and counts every brace token (including the T_CURLY_OPEN/
	 * T_DOLLAR_OPEN_CURLY_BRACES tokens PHP emits for `"{$var}"`/`"${var}"` interpolation) to its
	 * exact matching close, so only that one function's real body is ever removed.
	 *
	 * @param string $source        Full file contents.
	 * @param string $function_name Function name to strip, without parentheses.
	 * @return string The same source with that one function's body removed.
	 */
	private function strip_function_body( string $source, string $function_name ): string {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );
		$out    = '';
		$i      = 0;
		while ( $i < $count ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
				$j = $i + 1;
				while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					++$j;
				}
				$is_target = $j < $count && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] && $function_name === $tokens[ $j ][1];
				if ( $is_target ) {
					$k = $j + 1;
					while ( $k < $count && '{' !== $tokens[ $k ] ) {
						++$k;
					}
					$depth = 0;
					while ( $k < $count ) {
						$brace_token = $tokens[ $k ];
						if ( '{' === $brace_token || ( is_array( $brace_token ) && in_array( $brace_token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
							++$depth;
						} elseif ( '}' === $brace_token ) {
							--$depth;
							if ( 0 === $depth ) {
								++$k; // Consume the real closing brace, then stop: the body is fully accounted for.
								break;
							}
						}
						++$k;
					}
					$i = $k; // Resume scanning immediately after the function actually ends.
					continue;
				}
			}
			$out .= is_array( $token ) ? $token[1] : $token;
			++$i;
		}
		return $out;
	}

	/**
	 * Resolve `use function <name> as <alias>;` imports so the option-write scan also catches a
	 * call made through its alias (Codex round 7, R7-7): `use function update_option as persist;
	 * persist('aafm_oauth_enabled', '1')` would otherwise never match a regex anchored on the
	 * literal name `update_option`. Rewrites every aliased call site back to the imported name; a
	 * plain `use function <name>;` with no `as` needs no rewrite, since its call sites already use
	 * the real name.
	 *
	 * @param string $source Full file contents.
	 * @return string The same source with every aliased call site rewritten to its real name.
	 */
	private function resolve_use_function_aliases( string $source ): string {
		if ( ! preg_match_all( '/use\s+function\s+\\\\?([A-Za-z_][A-Za-z0-9_]*)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)\s*;/', $source, $import_matches, PREG_SET_ORDER ) ) {
			return $source;
		}
		foreach ( $import_matches as $import_match ) {
			list( , $real_name, $alias ) = $import_match;
			$source                      = (string) preg_replace( '/\b' . preg_quote( $alias, '/' ) . '(\s*\()/', $real_name . '$1', $source );
		}
		return $source;
	}

	/**
	 * Codex round 7, R7-7: a violation placed immediately after the exempt function's real
	 * closing brace must survive the strip. Before the token-based rewrite, the line-based
	 * counter's depth went negative on the exempt function's own closing `}` without ever
	 * satisfying its "line contains `{`" exit condition, so stripping ran on past the function's
	 * true end and silently deleted a bare update_option() sitting right after it.
	 */
	public function test_strip_function_body_does_not_leak_into_following_source(): void {
		$source = "<?php\nfunction aafm_oauth_seed_default_options(): void {\n\tadd_option( 'aafm_oauth_enabled', '0' );\n}\n\nupdate_option( 'aafm_denied_meta_keys', array() );\n\nfunction aafm_other(): void {\n\techo 'hi';\n}\n";

		$stripped = $this->strip_function_body( $source, 'aafm_oauth_seed_default_options' );

		$this->assertStringNotContainsString( "add_option( 'aafm_oauth_enabled'", $stripped, 'The exempt function\'s own body must still be removed.' );
		$this->assertStringContainsString(
			"update_option( 'aafm_denied_meta_keys'",
			$stripped,
			'A violation placed immediately after the exempt function must survive the strip, not be silently swallowed along with it.'
		);
		$this->assertStringContainsString( 'aafm_other', $stripped, 'A later, unrelated function must also survive the strip.' );
	}

	/**
	 * Codex round 7, R7-7: an option write made through a `use function ... as` alias must still
	 * be caught. Before this fix, `use function update_option as persist; persist(...)` never
	 * matched the sweep's regex, which is anchored on the literal name `update_option`.
	 */
	public function test_resolve_use_function_aliases_rewrites_an_aliased_option_write(): void {
		$source = "<?php\nuse function update_option as persist;\npersist( 'aafm_oauth_enabled', '1' );\n";

		$resolved = $this->resolve_use_function_aliases( $source );

		$this->assertMatchesRegularExpression(
			'/\bupdate_option\s*\(\s*[\'"]aafm_oauth_enabled[\'"]/',
			$resolved,
			'An aliased call must be rewritten back to its real name so the option-write regex can catch it.'
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
	 * The one bare write that really is safe is the pair of add_option() calls inside
	 * aafm_oauth_seed_default_options() (includes/oauth/discovery.php), which run once at
	 * activation, never overwrite an existing row by design, and seed both OAuth options to their
	 * safe default. A file-wide exemption for those two option names used to cover that, but it
	 * also silently permitted a bare write to either option ANYWHERE ELSE in discovery.php (Codex
	 * round 6, B6-7). The seed function's body is stripped out of discovery.php's source before
	 * the scan runs instead, so the exemption is scoped to the two calls it actually covers, and
	 * every other line in the file - including both guarded options - is checked like any other
	 * file. The regex also now tolerates the whitespace and double-quote spellings a bare write
	 * could otherwise slip past, such as `update_option ( "aafm_oauth_enabled", ...)`.
	 */
	public function test_no_bare_option_write_names_a_security_allowlist_option(): void {
		$guarded_options     = $this->guarded_security_options();
		$oauth_seed_scoped   = 'includes/oauth/discovery.php';
		$oauth_seed_function = 'aafm_oauth_seed_default_options';

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

			$source      = $this->resolve_use_function_aliases( $source );
			$scan_source = $oauth_seed_scoped === $relative
				? $this->strip_function_body( $source, $oauth_seed_function )
				: $source;

			foreach ( $guarded_options as $option ) {
				foreach ( array( 'update_option', 'delete_option', 'add_option' ) as $bare_call ) {
					$this->assertDoesNotMatchRegularExpression(
						'/\b' . $bare_call . '\s*\(\s*[\'"]' . preg_quote( $option, '/' ) . '[\'"]/',
						$scan_source,
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
