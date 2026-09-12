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
	 *
	 * Derived from aafm_config_option_names() - the reset's own canonical list of every
	 * configuration option a reset clears - merged with the handful of security-relevant
	 * options that live outside that list on purpose: migration guards, schema-version stamps,
	 * an allowlist-override option, and aafm_delete_data_on_uninstall (which a reset
	 * deliberately preserves, so it can never appear in the reset's own list). Deriving from
	 * the canonical list rather than hand-keeping a second one is the fix for what Codex round 9
	 * (R9-10) found: four options - the Quick Connect wizard's two flags, the menu-pointer flag,
	 * and the review-request state - existed only in aafm_config_option_names(), never in this
	 * file's own separately hand-kept copy, so their bare update_option()/add_option() calls went
	 * unnoticed by this sweep even though the writers were sitting right there in includes/.
	 *
	 * @return list<string>
	 */
	private function guarded_security_options(): array {
		return array_values(
			array_unique(
				array_merge(
					aafm_config_option_names(),
					array(
						'aafm_ability_allowlist_overrides',
						'aafm_oauth_toggle_migrated',
						'aafm_oauth_dcr_default_on_migrated',
						'aafm_oauth_dcr_default_on_touched',
						'aafm_oauth_schema_version',
						'aafm_activity_log_schema_version',
						'aafm_delete_data_on_uninstall',
					)
				)
			)
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
	 * The inverse of strip_function_body(): one named top-level function's body ALONE (opening
	 * brace through its matching close), rather than the rest of the file with it removed.
	 *
	 * F11 (1.7.5 deferred): $allowed_add_option_calls below used to exempt a (file, option)
	 * pair anywhere in that file, not one specific call site - a second, unreviewed
	 * `add_option( 'aafm_menu_pointer_active', ... )` added in a different function would still
	 * pass. Scanning this function's isolated body separately from the rest of the file (with
	 * strip_function_body() removing it there) lets the exemption apply to exactly the one named
	 * call site the accepted seed-once idiom actually lives in.
	 *
	 * @param string $source        Full file contents.
	 * @param string $function_name Function name to extract, without parentheses.
	 * @return string That one function's body, or '' if the function was not found.
	 */
	private function extract_function_body( string $source, string $function_name ): string {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; ++$i ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
				continue;
			}
			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				++$j;
			}
			if ( ! ( $j < $count && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] && $function_name === $tokens[ $j ][1] ) ) {
				continue;
			}
			$k = $j + 1;
			while ( $k < $count && '{' !== $tokens[ $k ] ) {
				++$k;
			}
			$start = $k;
			$depth = 0;
			while ( $k < $count ) {
				$brace_token = $tokens[ $k ];
				if ( '{' === $brace_token || ( is_array( $brace_token ) && in_array( $brace_token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
				} elseif ( '}' === $brace_token ) {
					--$depth;
					if ( 0 === $depth ) {
						++$k; // Include the real closing brace in the extracted body.
						break;
					}
				}
				++$k;
			}
			$body = '';
			for ( $n = $start; $n < $k; ++$n ) {
				$body .= is_array( $tokens[ $n ] ) ? $tokens[ $n ][1] : $tokens[ $n ];
			}
			return $body;
		}
		return '';
	}

	/**
	 * Find the next (direction 1) or previous (direction -1) significant token around a given
	 * index, mirrors SecurityRegressionTest::significant_token(): whitespace, comments, and
	 * docblocks never count as significant, so a comment sitting between a call's name and its
	 * opening paren cannot hide the call from the scan (Codex round 8, R8-6).
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param int                                           $index Index to look around.
	 * @param int                                           $direction 1 for next, -1 for previous.
	 * @return array{0:int,1:string,2:int}|string|null
	 */
	private function significant_token( array $tokens, int $index, int $direction ) {
		$i     = $index + $direction;
		$total = count( $tokens );
		while ( $i >= 0 && $i < $total ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$i += $direction;
				continue;
			}
			return $token;
		}
		return null;
	}

	/**
	 * `use function <name> as <alias>;` imports, mapped by lower-cased alias (Codex round 8,
	 * R8-6: PHP resolves both function names and their aliases case-insensitively, so a call
	 * through the alias in ANY case must still resolve). Only the single, non-grouped form is
	 * handled - this codebase does not use the grouped `use function {A, B as C};` form today.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @return array<string,string> Lower-cased alias => real bare name.
	 */
	private function parse_use_function_aliases( array $tokens ): array {
		$aliases = array();
		$total   = count( $tokens );
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_USE !== $token[0] ) {
				continue;
			}
			$j = $i + 1;
			while ( $j < $total && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				++$j;
			}
			if ( ! ( $j < $total && is_array( $tokens[ $j ] ) && T_FUNCTION === $tokens[ $j ][0] ) ) {
				continue; // Only `use function ...;` imports matter for option-write call names.
			}
			$entry = '';
			$k     = $j + 1;
			while ( $k < $total && ';' !== $tokens[ $k ] ) {
				$t      = $tokens[ $k ];
				$entry .= is_array( $t ) ? $t[1] : $t;
				++$k;
			}
			if ( preg_match( '/^\s*\\\\?([A-Za-z_][A-Za-z0-9_]*)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)\s*$/i', $entry, $m ) ) {
				$aliases[ strtolower( $m[2] ) ] = $m[1];
			}
		}
		return $aliases;
	}

	/**
	 * Whether a collapsed name token resolves - directly, or through an imported alias - to the
	 * given target function name. Case-insensitive throughout (Codex round 8, R8-6): PHP resolves
	 * function names and `use` aliases case-insensitively, so a differently-cased call or alias
	 * reference is still the same call.
	 *
	 * @param string               $token_text Token text.
	 * @param string               $target Bare target function name.
	 * @param array<string,string> $aliases Lower-cased alias => real bare name.
	 * @return bool
	 */
	private function resolves_to_option_write_target( string $token_text, string $target, array $aliases ): bool {
		$resolved = $aliases[ strtolower( $token_text ) ] ?? $token_text;
		return 0 === strcasecmp( $target, $resolved );
	}

	/**
	 * Codex round 8, R8-6: whether $tokens contains a real call to $name whose first argument is
	 * the literal string $option. Replaces this test's own regex, which was case-sensitive and
	 * required literal whitespace (never a comment) between the function name and its opening
	 * paren - an uppercase call name, or a call with an inline comment before the paren, evaded
	 * it entirely. A real call is identified the same way SecurityRegressionTest's scanner
	 * already proved for R8-5: the name/alias match is case-insensitive, the gap to the opening
	 * paren tolerates comments (significant_token() already skips them), and a method call,
	 * static call, or declaration is never mistaken for a real call.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Tokens from token_get_all().
	 * @param string                                        $name Bare function name to match.
	 * @param string                                        $option Literal option name to match as the first argument.
	 * @param array<string,string>                          $aliases Lower-cased alias => real bare name.
	 * @return bool
	 */
	private function has_bare_option_write( array $tokens, string $name, string $option, array $aliases ): bool {
		return $this->count_bare_option_writes( $tokens, $name, $option, $aliases ) > 0;
	}

	/**
	 * R2-7 (1.7.5 deferred, round 2): the exact same matcher as has_bare_option_write(), but
	 * counting every match rather than stopping at the first - F11's exemption needs to tell
	 * "exactly the one accepted seed call" apart from "that call plus another one added later in
	 * the same function," and a boolean existence check cannot make that distinction.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Tokens from token_get_all().
	 * @param string                                        $name Bare function name to match.
	 * @param string                                        $option Literal option name to match as the first argument.
	 * @param array<string,string>                          $aliases Lower-cased alias => real bare name.
	 * @return int
	 */
	private function count_bare_option_writes( array $tokens, string $name, string $option, array $aliases ): int {
		$count   = count( $tokens );
		$matches = 0;
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! $this->resolves_to_option_write_target( $token[1], $name, $aliases ) ) {
				continue;
			}
			$open = $this->significant_token( $tokens, $i, 1 );
			if ( ! is_string( $open ) || '(' !== $open ) {
				continue;
			}
			$prev = $this->significant_token( $tokens, $i, -1 );
			if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				continue;
			}

			$open_index = $i + 1;
			while ( $open_index < $count && '(' !== $tokens[ $open_index ] ) {
				++$open_index;
			}
			$first_arg = $this->significant_token( $tokens, $open_index, 1 );
			if ( is_array( $first_arg ) && T_CONSTANT_ENCAPSED_STRING === $first_arg[0] && substr( $first_arg[1], 1, -1 ) === $option ) {
				++$matches;
			}
		}
		return $matches;
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
	public function test_has_bare_option_write_matches_an_aliased_call(): void {
		$tokens  = token_get_all( "<?php\nuse function update_option as persist;\npersist( 'aafm_oauth_enabled', '1' );\n" );
		$aliases = $this->parse_use_function_aliases( $tokens );

		$this->assertTrue( $this->has_bare_option_write( $tokens, 'update_option', 'aafm_oauth_enabled', $aliases ) );
	}

	/**
	 * Codex round 8, R8-6: the retired regex was case-sensitive and required literal whitespace
	 * (not a comment) between the function name and its opening paren, so an uppercase call, or
	 * one with a comment before the paren, evaded it entirely.
	 */
	public function test_has_bare_option_write_matches_an_uppercase_call_with_a_comment_before_the_paren(): void {
		$tokens = token_get_all( "<?php\nUPDATE_OPTION /* audit */ ( 'aafm_oauth_enabled', '1' );\n" );

		$this->assertTrue( $this->has_bare_option_write( $tokens, 'update_option', 'aafm_oauth_enabled', array() ) );
	}

	public function test_has_bare_option_write_is_false_for_a_different_option_name(): void {
		$tokens = token_get_all( "<?php\nupdate_option( 'aafm_unrelated_option', '1' );\n" );

		$this->assertFalse( $this->has_bare_option_write( $tokens, 'update_option', 'aafm_oauth_enabled', array() ) );
	}

	/**
	 * R2-7 (1.7.5 deferred, round 2): count_bare_option_writes() must tell one matching call
	 * apart from two - has_bare_option_write() (a plain existence check) cannot, which is exactly
	 * what let a second, unreviewed add_option() inside the exempt function pass the sweep
	 * unnoticed. Fails if the counter reverts to stopping at the first match.
	 */
	public function test_count_bare_option_writes_distinguishes_one_call_from_two(): void {
		$one  = token_get_all( "<?php\nadd_option( 'aafm_menu_pointer_active', '1' );\n" );
		$two  = token_get_all( "<?php\nadd_option( 'aafm_menu_pointer_active', '1' );\nadd_option( 'aafm_menu_pointer_active', '1' );\n" );
		$none = token_get_all( "<?php\necho 'no write here';\n" );

		$this->assertSame( 1, $this->count_bare_option_writes( $one, 'add_option', 'aafm_menu_pointer_active', array() ) );
		$this->assertSame( 2, $this->count_bare_option_writes( $two, 'add_option', 'aafm_menu_pointer_active', array() ) );
		$this->assertSame( 0, $this->count_bare_option_writes( $none, 'add_option', 'aafm_menu_pointer_active', array() ) );
	}

	/**
	 * R3-8 (1.7.5 deferred, round 3): extract_function_body() returns only the function's own
	 * tokens - a file-level `use function add_option as seed;` never survives that extraction, so
	 * re-parsing aliases from the extracted body alone (the old call site inside
	 * test_no_bare_option_write_names_a_security_allowlist_option()) always finds none, and an
	 * aliased second call inside the function resolves to nothing recognisable. Resolving against
	 * the WHOLE FILE's alias map (computed once, before extraction, and reused here) still counts
	 * it.
	 *
	 * What would break this: passing an alias map parsed from $body_tokens alone (instead of the
	 * whole-file $file_aliases below) makes this assert 1 instead of 2 - the exact bypass this
	 * fixture reproduces.
	 */
	public function test_count_bare_option_writes_resolves_an_alias_only_the_whole_file_would_see(): void {
		$source       = <<<'PHP'
<?php
use function add_option as seed;
function aafm_oauth_seed_default_options() {
	add_option( 'aafm_oauth_enabled', '0', '', true );
	seed( 'aafm_oauth_enabled', '0', '', true );
}
PHP;
		$file_tokens  = token_get_all( $source );
		$file_aliases = $this->parse_use_function_aliases( $file_tokens );
		$body_tokens  = token_get_all( '<?php ' . $this->extract_function_body( $source, 'aafm_oauth_seed_default_options' ) );

		$this->assertSame(
			2,
			$this->count_bare_option_writes( $body_tokens, 'add_option', 'aafm_oauth_enabled', $file_aliases ),
			'The aliased call must count too, using the whole file\'s alias map.'
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
	 * The bare writes that really are safe are add_option() calls whose whole point is
	 * add_option()'s no-op-if-present behaviour, never update_option()'s overwrite: the pair
	 * inside aafm_oauth_seed_default_options() (includes/oauth/discovery.php), which run once at
	 * activation and seed both OAuth options to their safe default. A file-wide exemption for an
	 * option name used to cover this case, but it also silently permitted a bare write to that
	 * option ANYWHERE ELSE in the file (Codex round 6, B6-7). The exempt function's body is
	 * stripped out of its file's source before the scan runs instead, so the exemption is scoped
	 * to the one call it actually covers, and every other line in the file - including every
	 * guarded option - is checked like any other file.
	 *
	 * The single seed call inside aafm_quickconnect_flag_menu_pointer()
	 * (includes/admin/onboarding-pointer.php) used to get the same whole-function strip, but that
	 * hid more than the one safe call: the function's own certification logic (Codex round 10,
	 * R10-9) sat inside the same stripped body, so a later edit that ripped the certification back
	 * out - or pasted in an unrelated bare write to a different guarded option - would have left
	 * this test green either way. $allowed_add_option_calls named the exact (file, option) pair
	 * instead: only a bare add_option() naming that option in that file was let through, so the
	 * function's full body, certification included, stayed part of the scan.
	 *
	 * F11 (1.7.5 deferred): that (file, option) pair was still too wide - it let a bare
	 * add_option() naming the option through ANYWHERE in the file, not only the one accepted
	 * call site, so a second, unreviewed occurrence pasted into a different function would have
	 * passed too. $allowed_add_option_calls now also names the one function the exemption is
	 * scoped to: any matching call found in the file with that function's body removed still
	 * fails like any other guarded write, and the loop separately proves the exempted call
	 * genuinely exists inside that function, so removing the seed call is still noticed.
	 *
	 * The scan is token-based rather than regex-based (Codex round 8, R8-6): the retired regex
	 * was case-sensitive and required literal whitespace, never a comment, between the function
	 * name and its opening paren, so `UPDATE_OPTION( ... )` or a call with an inline comment
	 * before the paren evaded it entirely.
	 */
	public function test_no_bare_option_write_names_a_security_allowlist_option(): void {
		$guarded_options = $this->guarded_security_options();
		// Codex round 10, R10-9: a bare add_option() naming this exact option in this exact file
		// is the accepted seed-once idiom (aafm_quickconnect_flag_menu_pointer(), includes/admin/
		// onboarding-pointer.php) - never update_option() or delete_option(), and never any other
		// guarded option, both of which stay violations anywhere in the file.
		//
		// F11 (1.7.5 deferred): this used to key only by (file, option), so a SECOND, unreviewed
		// add_option() naming the same option in a DIFFERENT function of this same file would
		// still pass. 'function' scopes the exemption to that one call site: the loop below
		// verifies no matching call exists anywhere in the file OUTSIDE that function, and that
		// one genuinely exists inside it (so removing the seed call is itself still noticed).
		//
		// R3-8 (1.7.5 deferred, round 3): aafm_oauth_seed_default_options() (includes/oauth/
		// discovery.php) used to get its own, separate, whole-function-body strip ($exempt_functions
		// below, now removed) instead of this precise per-call exemption - the exact defect this
		// file's own docblock above already explains onboarding-pointer.php was rescued from. That
		// blanket strip hid EVERY write inside the function, not just its two accepted seed calls,
		// so a duplicate seed or an unrelated bare write to a different guarded option pasted into
		// the same function was invisible to this scan. Both accepted seeds now go through the
		// exact same named-function, named-option, count-of-exactly-one exemption as the pointer's.
		$allowed_add_option_calls = array(
			'includes/admin/onboarding-pointer.php' => array(
				'options'  => array( 'aafm_menu_pointer_active' ),
				'function' => 'aafm_quickconnect_flag_menu_pointer',
			),
			'includes/oauth/discovery.php'           => array(
				'options'  => array( 'aafm_oauth_enabled', 'aafm_oauth_dcr_enabled' ),
				'function' => 'aafm_oauth_seed_default_options',
			),
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

			$tokens  = token_get_all( $source );
			$aliases = $this->parse_use_function_aliases( $tokens );

			$pointer_exemption = $allowed_add_option_calls[ $relative ] ?? null;

			foreach ( $guarded_options as $option ) {
				foreach ( array( 'update_option', 'delete_option', 'add_option' ) as $bare_call ) {
					if ( 'add_option' === $bare_call && null !== $pointer_exemption
						&& in_array( $option, $pointer_exemption['options'], true )
					) {
						// F11 (1.7.5 deferred): exempt exactly the one accepted seed-once call
						// site, not this (file, option) pair anywhere in the file. Check the file
						// with that function's body removed - any matching call surviving there
						// is a second, unreviewed occurrence and must still fail.
						$outside_tokens = token_get_all( $this->strip_function_body( $source, $pointer_exemption['function'] ) );
						$this->assertFalse(
							$this->has_bare_option_write( $outside_tokens, $bare_call, $option, $this->parse_use_function_aliases( $outside_tokens ) ),
							"A bare {$bare_call}() naming {$option} was found in {$relative} outside {$pointer_exemption['function']}() - the accepted seed-once idiom is scoped to that one function only."
						);
						// And prove the exempted call exists EXACTLY ONCE inside that function
						// (R2-7, 1.7.5 deferred round 2): a bare existence check would still pass
						// if a second, unreviewed call to the same option were added alongside
						// the accepted seed call, inside the same exempt function - the exemption
						// is for one specific call, not an unlimited allowance for that function.
						//
						// R3-8 (1.7.5 deferred, round 3): extract_function_body() returns ONLY the
						// body's own tokens - no file-level `use` statements survive the extraction,
						// so re-parsing aliases from $body_tokens alone always finds none. A second
						// call added via a file-level `use function add_option as seed;` then
						// resolves to nothing recognisable and is silently missed, leaving
						// $body_matches at 1 (the original call only) even with a bypass alongside
						// it. Resolve against $aliases, the whole file's real alias map computed
						// above, not a re-parse of the isolated snippet that lost that context.
						$body_tokens   = token_get_all( '<?php ' . $this->extract_function_body( $source, $pointer_exemption['function'] ) );
						$body_matches  = $this->count_bare_option_writes( $body_tokens, $bare_call, $option, $aliases );
						$this->assertSame(
							1,
							$body_matches,
							"Expected exactly one bare {$bare_call}() naming {$option} inside {$pointer_exemption['function']}() in {$relative} - update this exemption if that seed call moved, was removed, or another one was added alongside it."
						);
						continue;
					}
					$this->assertFalse(
						$this->has_bare_option_write( $tokens, $bare_call, $option, $aliases ),
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
