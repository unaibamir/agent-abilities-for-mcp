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
	 * Every bare `$wpdb->get_var()/get_row()/get_col()/get_results()` call in $source. A real
	 * token walk, not a regex: it tolerates a comment sitting between `$wpdb`, `->`, and the
	 * method name, and matches the method name case-insensitively, since PHP resolves both that
	 * way. Text inside a comment or docblock is never mistaken for a call, because token_get_all()
	 * classifies it as T_COMMENT/T_DOC_COMMENT rather than T_VARIABLE/T_OBJECT_OPERATOR/T_STRING.
	 *
	 * @param string $source Full file contents.
	 * @return list<array{line:int,method:string}>
	 */
	private function find_bare_wpdb_reads( string $source ): array {
		$tokens = token_get_all( $source );
		$found  = array();

		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_VARIABLE !== $token[0] || '$wpdb' !== $token[1] ) {
				continue;
			}

			list( $arrow, $arrow_index ) = $this->significant_token( $tokens, $i + 1 );
			if ( ! is_array( $arrow ) || T_OBJECT_OPERATOR !== $arrow[0] ) {
				continue;
			}

			list( $method, $method_index ) = $this->significant_token( $tokens, $arrow_index + 1 );
			if ( ! is_array( $method ) || T_STRING !== $method[0] ) {
				continue;
			}
			$name = strtolower( $method[1] );
			if ( ! in_array( $name, self::BANNED_METHODS, true ) ) {
				continue;
			}

			list( $paren ) = $this->significant_token( $tokens, $method_index + 1 );
			if ( ! is_string( $paren ) || '(' !== $paren ) {
				continue;
			}

			$found[] = array(
				'line'   => $token[2],
				'method' => $method[1],
			);
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
		$this->assertSame( 'get_var', $found[0]['method'] );
		$this->assertSame( 4, $found[0]['line'] );
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
				$violations[] = sprintf( '%s:%d $wpdb->%s()', $relative, $hit['line'], $hit['method'] );
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
