<?php
/**
 * Sweep: no bare $wpdb->get_var()/get_row()/get_col()/get_results() call survives anywhere under
 * includes/, with no exemption list.
 *
 * Codex round 7, R7-2 first named this defect class: WordPress's convenience readers call
 * $wpdb->query() and then read $wpdb->last_result, but on two failure paths - wpdb::ready being
 * false, and the 'query' filter returning an empty string - query() returns false WITHOUT
 * clearing last_result, so the reader hands back the PREVIOUS query's rows. Code that checks the
 * return value and then trusts the data gets a stale answer from an unrelated query. Round 7
 * judged each of 25-26 call sites on whether a plausible exploit path existed and cleared several
 * on that basis; round 8 (R8-1) then found a live defect at one of the sites round 7 explicitly
 * cleared. Judging call sites one at a time lets the next round re-discover the ones this round
 * missed - this sweep bans the shape mechanically instead: EVERY bare call under includes/ fails
 * this test, with no per-site allowlist, so a future one is caught the moment it is written
 * rather than the next time a reviewer happens to sample it.
 *
 * Routing a call through one of the four aafm_wpdb_*() helpers (includes/option-cache.php) is the
 * fix: they call $wpdb->query() themselves and only trust $wpdb->last_result after confirming
 * query() did not return false, giving every caller an explicit {ok, value} pair instead of a
 * single value that means three different things.
 *
 * Codex round 9, R9-5: the matcher above only recognized the literal shape
 * `$wpdb->methodName(...)`. Three PHP 7.4-compatible forms reach the identical stale-read bug
 * while emitting none of those tokens in that order: a braced method name
 * (`$wpdb->{'get_row'}(...)`), a variable method name (`$method = 'get_row';
 * $wpdb->$method(...)`), and copying the object into another variable first (`$db = $wpdb;
 * $db->get_row(...)`), which never re-mentions the literal `$wpdb` token at the call site. The
 * matcher now also bans any braced or variable method dispatch on `$wpdb` unconditionally - no
 * legitimate reader call ever needs one - and bans copying the bare `$wpdb` reference into
 * anything else (`$anything = $wpdb;`) outright, since that copy is the one step every alias hop
 * needs first; closing the copy closes every hop that could follow it.
 *
 * This still cannot be a complete defense against a dynamic language, and does not try to be. It
 * does not follow $wpdb through a function parameter (`function f( $db ) { $db->get_row(...); }`
 * called as `f( $wpdb )`), a `call_user_func( array( $wpdb, 'get_row' ) )` / `array( $wpdb,
 * 'get_row' )` callable, a variable-variable (`$$name`), or `compact()`/`extract()`. Those need
 * real data-flow analysis, not a token walk, and none of them exist under includes/ today. This
 * test is a regression gate against the concrete evasions Codex has actually demonstrated, not a
 * proof that no bare $wpdb read can ever exist.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class StaleWpdbReaderSweepTest extends TestCase {

	private const BANNED_METHODS = array( 'get_var', 'get_row', 'get_col', 'get_results' );

	/**
	 * Next non-trivia token at or after $index (whitespace/comments/docblocks never count),
	 * paired with its index so a caller can keep walking from there. Every source-scanning sweep
	 * in this suite carries its own small copy of this rather than share one (see
	 * SecurityOptionWritesSweepTest::significant_token()) - matched here for consistency.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param int                                           $index  Index to start looking from.
	 * @return array{0:array{0:int,1:string,2:int}|string|null,1:int}
	 */
	private function significant_token( array $tokens, int $index ): array {
		$total = count( $tokens );
		while ( $index < $total ) {
			$token = $tokens[ $index ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				++$index;
				continue;
			}
			return array( $token, $index );
		}
		return array( null, $index );
	}

	/**
	 * Mirror of significant_token() that walks backward from $index instead of forward - needed
	 * only to look at the token immediately before a `$wpdb` mention (is it a bare `=`?). Returns
	 * the found token's own index, or null when the start of the file is reached first.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param int                                           $index  Index to start looking from.
	 */
	private function previous_significant_index( array $tokens, int $index ): ?int {
		while ( $index >= 0 ) {
			$token = $tokens[ $index ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				--$index;
				continue;
			}
			return $index;
		}
		return null;
	}

	/**
	 * Index of the `}` that closes the `{` at $open_index, tracking nesting depth so an inner
	 * brace pair (unlikely inside a method-name expression, but not impossible) doesn't return
	 * early. Null if the file ends before the brace closes (malformed source; never matches).
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens     token_get_all() output.
	 * @param int                                           $open_index Index of the opening `{`.
	 */
	private function matching_brace_index( array $tokens, int $open_index ): ?int {
		$depth = 0;
		$total = count( $tokens );
		for ( $j = $open_index; $j < $total; $j++ ) {
			$token = $tokens[ $j ];
			if ( '{' === $token || ( is_array( $token ) && T_CURLY_OPEN === $token[0] ) ) {
				++$depth;
			} elseif ( '}' === $token ) {
				--$depth;
				if ( 0 === $depth ) {
					return $j;
				}
			}
		}
		return null;
	}

	/**
	 * Every bare `$wpdb->get_var()/get_row()/get_col()/get_results()` call in $source, plus every
	 * shape that reaches the identical bug while dodging that literal token pattern - see the
	 * R9-5 paragraph in this file's own header docblock for what each variant is and why it's
	 * banned unconditionally, and for the honest limits of a token walk. A real token walk, not a
	 * regex: it tolerates a comment sitting between `$wpdb`, `->`, and the method name, and
	 * matches the method name case-insensitively, since PHP resolves both that way. Text inside a
	 * comment or docblock is never mistaken for a call, because token_get_all() classifies it as
	 * T_COMMENT/T_DOC_COMMENT rather than T_VARIABLE/T_OBJECT_OPERATOR/T_STRING.
	 *
	 * @param string $source Full file contents.
	 * @return list<array{line:int,description:string}>
	 */
	private function find_bare_wpdb_reads( string $source ): array {
		$tokens = token_get_all( $source );
		$found  = array();

		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_VARIABLE !== $token[0] || '$wpdb' !== $token[1] ) {
				continue;
			}
			$line = $token[2];

			list( $arrow, $arrow_index ) = $this->significant_token( $tokens, $i + 1 );
			if ( is_array( $arrow ) && T_OBJECT_OPERATOR === $arrow[0] ) {
				list( $next, $next_index ) = $this->significant_token( $tokens, $arrow_index + 1 );

				if ( is_array( $next ) && T_STRING === $next[0] ) {
					// $wpdb->methodName(...) - the original, literal shape.
					$name = strtolower( $next[1] );
					if ( in_array( $name, self::BANNED_METHODS, true ) ) {
						list( $paren ) = $this->significant_token( $tokens, $next_index + 1 );
						if ( is_string( $paren ) && '(' === $paren ) {
							$found[] = array(
								'line'        => $line,
								'description' => sprintf( '$wpdb->%s()', $next[1] ),
							);
						}
					}
				} elseif ( is_string( $next ) && '{' === $next ) {
					// $wpdb->{'get_row'}(...) - braced method name. Banned unconditionally
					// regardless of what the brace contains: no legitimate reader call on $wpdb
					// ever needs a computed method name.
					$close_index = $this->matching_brace_index( $tokens, $next_index );
					if ( null !== $close_index ) {
						list( $paren ) = $this->significant_token( $tokens, $close_index + 1 );
						if ( is_string( $paren ) && '(' === $paren ) {
							$found[] = array(
								'line'        => $line,
								'description' => '$wpdb->{...}() (braced method name)',
							);
						}
					}
				} elseif ( is_array( $next ) && T_VARIABLE === $next[0] ) {
					// $method = 'get_row'; $wpdb->$method(...) - variable method dispatch. Same
					// unconditional ban, same reasoning.
					list( $paren ) = $this->significant_token( $tokens, $next_index + 1 );
					if ( is_string( $paren ) && '(' === $paren ) {
						$found[] = array(
							'line'        => $line,
							'description' => sprintf( '$wpdb->%s() (dynamic method dispatch)', $next[1] ),
						);
					}
				}
			}

			// $anything = $wpdb; - copying the bare object reference into a variable, property,
			// or array element (any assignment target token_get_all() might emit) defeats every
			// check above, since none of them ever look past the literal `$wpdb` token. Ban the
			// copy itself: previous significant token is a bare `=` (comparison/compound-assign
			// operators are their own token types, never the plain string '='), and the very next
			// significant token is the statement terminator `;`, so the right-hand side is
			// exactly `$wpdb` alone - `$wpdb->prop` or `$wpdb . 'x'` on the right doesn't match,
			// only a bare copy does.
			$prev_index         = $this->previous_significant_index( $tokens, $i - 1 );
			list( $after_wpdb ) = $this->significant_token( $tokens, $i + 1 );
			$prev_is_assign     = null !== $prev_index && is_string( $tokens[ $prev_index ] ) && '=' === $tokens[ $prev_index ];
			$next_is_semicolon  = is_string( $after_wpdb ) && ';' === $after_wpdb;
			if ( $prev_is_assign && $next_is_semicolon ) {
				$found[] = array(
					'line'        => $line,
					'description' => '$wpdb copied into another variable here (aliasing defeats the literal $wpdb matcher above)',
				);
			}
		}

		return $found;
	}

	/**
	 * Proof the sweep's own matcher can fail: a planted bare call in an otherwise ordinary
	 * function must be flagged, by name and line.
	 */
	public function test_find_bare_wpdb_reads_flags_a_planted_call(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$row = \$wpdb->get_var( 'SELECT 1' );\n}\n";

		$found = $this->find_bare_wpdb_reads( $source );

		$this->assertCount( 1, $found );
		$this->assertSame( '$wpdb->get_var()', $found[0]['description'] );
		$this->assertSame( 4, $found[0]['line'] );
	}

	/**
	 * R9-5, evasion 1: a braced method name never emits the literal T_STRING token the original
	 * matcher looked for, but reaches the identical stale-read bug and must still be flagged.
	 */
	public function test_find_bare_wpdb_reads_flags_a_braced_method_name(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$row = \$wpdb->{'get_row'}( 'SELECT 1' );\n}\n";

		$found = $this->find_bare_wpdb_reads( $source );

		$this->assertCount( 1, $found );
		$this->assertSame( 4, $found[0]['line'] );
	}

	/**
	 * R9-5, evasion 2: dispatching through a variable method name never emits a T_STRING method
	 * token either, and must still be flagged.
	 */
	public function test_find_bare_wpdb_reads_flags_a_dynamic_method_dispatch(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$method = 'get_row';\n\t\$row = \$wpdb->\$method( 'SELECT 1' );\n}\n";

		$found = $this->find_bare_wpdb_reads( $source );

		$this->assertCount( 1, $found );
		$this->assertSame( 5, $found[0]['line'] );
	}

	/**
	 * R9-5, evasion 3: aliasing $wpdb into another variable, then calling the banned method
	 * through the alias, never re-mentions the literal `$wpdb` token at the call site - so the
	 * matcher instead has to catch the alias being created, on the line it's created.
	 */
	public function test_find_bare_wpdb_reads_flags_an_alias_assignment(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$db = \$wpdb;\n\t\$row = \$db->get_row( 'SELECT 1' );\n}\n";

		$found = $this->find_bare_wpdb_reads( $source );

		$this->assertCount( 1, $found );
		$this->assertSame( 4, $found[0]['line'] );
	}

	/**
	 * The alias ban must not fire on the extremely common `$table = $wpdb->prefix . '...';`
	 * shape - the right-hand side isn't a bare copy of $wpdb, so this must stay unflagged, or the
	 * sweep would self-detonate across dozens of real, safe call sites under includes/.
	 */
	public function test_find_bare_wpdb_reads_ignores_a_property_read_assignment(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$table = \$wpdb->prefix . 'aafm_oauth_codes';\n}\n";

		$this->assertSame( array(), $this->find_bare_wpdb_reads( $source ) );
	}

	/**
	 * `$wpdb->$table` used as a dynamic PROPERTY read (a real, safe pattern in
	 * includes/helpers.php, building a table name like `$wpdb->postmeta`) must stay unflagged -
	 * only a following `(` turns dynamic dispatch into a call.
	 */
	public function test_find_bare_wpdb_reads_ignores_a_dynamic_property_read(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$table = \$wpdb->\$table_var;\n}\n";

		$this->assertSame( array(), $this->find_bare_wpdb_reads( $source ) );
	}

	/**
	 * `global $wpdb;` itself must never be mistaken for an alias assignment.
	 */
	public function test_find_bare_wpdb_reads_ignores_a_global_declaration(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n}\n";

		$this->assertSame( array(), $this->find_bare_wpdb_reads( $source ) );
	}

	/**
	 * A same-named method on a DIFFERENT object (e.g. WP_User_Query::get_results(), which two
	 * real call sites under includes/ use) is not $wpdb and carries none of this defect - it must
	 * never be flagged.
	 */
	public function test_find_bare_wpdb_reads_ignores_a_different_object(): void {
		$source = "<?php\n\$rows = \$query->get_results();\n";

		$this->assertSame( array(), $this->find_bare_wpdb_reads( $source ) );
	}

	public function test_find_bare_wpdb_reads_is_case_insensitive(): void {
		$source = "<?php\n\$row = \$wpdb->GET_ROW( 'SELECT 1' );\n";

		$this->assertCount( 1, $this->find_bare_wpdb_reads( $source ) );
	}

	public function test_find_bare_wpdb_reads_tolerates_a_comment_between_arrow_and_call(): void {
		$source = "<?php\n\$row = \$wpdb /* audit */ -> /* audit */ get_row( 'SELECT 1' );\n";

		$this->assertCount( 1, $this->find_bare_wpdb_reads( $source ) );
	}

	/**
	 * A mention inside a comment or docblock (every file that already fixed a sibling call site
	 * carries a docblock quoting the old bare call in its "before" explanation) is not code and
	 * must never be flagged.
	 */
	public function test_find_bare_wpdb_reads_ignores_a_mention_inside_a_comment(): void {
		$source = "<?php\n// \$wpdb->get_var() used to be called here directly.\n/**\n * \$wpdb->get_row() also used to be bare.\n */\nfunction f(): void {}\n";

		$this->assertSame( array(), $this->find_bare_wpdb_reads( $source ) );
	}

	/**
	 * Static source scan over the whole includes/ tree, mirroring
	 * SecurityOptionWritesSweepTest::test_no_bare_option_write_names_a_security_allowlist_option()'s
	 * mechanical approach: reading source text rather than running it catches a future call the
	 * moment it is written, wherever in includes/ it lands. No exemption list - a site that
	 * genuinely cannot be converted must be fixed or reported, never allowlisted, per the whole
	 * point of banning the shape mechanically instead of re-judging call sites one round at a
	 * time.
	 */
	public function test_no_bare_wpdb_reader_call_survives_under_includes(): void {
		$includes_dir = AAFM_PLUGIN_DIR . 'includes';
		$files        = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $includes_dir, \FilesystemIterator::SKIP_DOTS )
		);

		$violations = array();
		$scanned    = 0;
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = 'includes/' . ltrim( str_replace( $includes_dir, '', $file->getPathname() ), '/' );

			$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this plugin's own local source to scan it, not a remote URL.
			$this->assertNotSame( '', $source, "The sweep must actually read {$relative} - an empty read would make this test pass by finding nothing." );
			++$scanned;

			foreach ( $this->find_bare_wpdb_reads( $source ) as $hit ) {
				$violations[] = sprintf( '%s:%d %s', $relative, $hit['line'], $hit['description'] );
			}
		}

		$this->assertGreaterThan( 50, $scanned, 'The sweep must actually walk includes/ - too few files scanned would make this test pass by finding nothing.' );
		$this->assertSame(
			array(),
			$violations,
			"A bare \$wpdb reader call was found - route it through aafm_wpdb_scalar()/aafm_wpdb_row()/aafm_wpdb_col()/aafm_wpdb_results() instead (includes/option-cache.php), and decide that call site's own ok/value fail direction:\n"
				. implode( "\n", $violations )
		);
	}
}
