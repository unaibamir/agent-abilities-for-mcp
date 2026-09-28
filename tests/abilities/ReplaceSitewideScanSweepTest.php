<?php
/**
 * Sweep (W4-T4, ledger s14hunta-4): every `new WP_Query(` and `get_posts(` call under includes/,
 * keyed `path|function|ordinal` and classed READ or WRITE-FEED.
 *
 * A WP_Query id list that feeds a write inherits WP_Query's get_col(), which hands back the
 * previous query's rows when the SELECT fails without flushing. The one WRITE-FEED call,
 * replace-sitewide's candidate scan, runs its SQL through a failure-aware posts_pre_query
 * callback. A new call fails this test until it is classed here, so a new write feed cannot
 * arrive unexamined.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\UseImportScanner;
use AAFM\Tests\TestCase;

final class ReplaceSitewideScanSweepTest extends TestCase {

	/**
	 * Every call, by key, with its class.
	 */
	private const CALLS = array(
		'includes/wpml.php|aafm_wpml_count_posts_by_status|1'           => 'READ',
		'includes/helpers.php|aafm_with_seo_render_scope|1'             => 'READ',
		'includes/abilities/blocks.php|aafm_exec_list_blocks|1'         => 'READ',
		'includes/abilities/search.php|aafm_exec_search_content|1'      => 'READ',
		'includes/abilities/geodirectory.php|aafm_exec_geodirectory_get_listings|1' => 'READ',
		'includes/abilities/geodirectory.php|aafm_exec_geodirectory_get_listings|2' => 'READ',
		'includes/abilities/media.php|aafm_exec_get_media|1'            => 'READ',
		'includes/abilities/media.php|aafm_exec_get_media|2'            => 'READ',
		'includes/abilities/media.php|aafm_query_attachment_counts_by_mime|1' => 'READ',
		'includes/abilities/posts.php|aafm_exec_get_posts|1'            => 'READ',
		'includes/abilities/woocommerce/coupons.php|aafm_exec_wc_list_coupons|1' => 'READ',
		'includes/abilities/posts.php|aafm_exec_replace_sitewide|1'     => 'READ',
		'includes/abilities/posts.php|aafm_exec_replace_sitewide|2'     => 'WRITE-FEED',
	);

	/**
	 * The named function whose body holds each call, found with a brace stack. A closure keeps the
	 * name of the function around it.
	 *
	 * @param string $source File contents.
	 * @return list<string> One enclosing function name per call, in source order.
	 */
	private function calls_in( string $source ): array {
		$tokens     = token_get_all( $source );
		$name_types = $this->name_token_types();
		$not_a_call = array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ? constant( 'T_NULLSAFE_OBJECT_OPERATOR' ) : -1 );
		$stack      = array();
		$pending    = null;
		$found      = array();
		$count      = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token   = $tokens[ $i ];
			$current = array() === $stack ? '{main}' : (string) end( $stack );
			if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
				$next    = $this->next_significant( $tokens, $i + 1 );
				$pending = null !== $next && is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0] ? $tokens[ $next ][1] : $current;
				continue;
			}
			if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				$stack[] = $pending ?? $current;
				$pending = null;
				continue;
			}
			if ( '}' === $token ) {
				array_pop( $stack );
				continue;
			}
			if ( ';' === $token ) {
				$pending = null; // An abstract or interface method has no body.
				continue;
			}
			if ( ! is_array( $token ) ) {
				continue;
			}
			$next = $this->next_significant( $tokens, $i + 1 );
			if ( T_NEW === $token[0] ) {
				while ( null !== $next && is_array( $tokens[ $next ] ) && ( in_array( $tokens[ $next ][0], array( T_NS_SEPARATOR, T_NAMESPACE ), true ) || ( T_STRING === $tokens[ $next ][0] && is_array( $tokens[ $next + 1 ] ?? null ) && T_NS_SEPARATOR === $tokens[ $next + 1 ][0] ) ) ) {
					++$next;
				}
				if ( null !== $next && is_array( $tokens[ $next ] ) && in_array( $tokens[ $next ][0], $name_types, true ) && 'wp_query' === strtolower( UseImportScanner::trailing_name_segment( $tokens[ $next ][1] ) ) ) {
					$paren = $this->next_significant( $tokens, $next + 1 );
					if ( null !== $paren && '(' === $tokens[ $paren ] ) {
						$found[] = $current;
					}
				}
				continue;
			}
			if ( in_array( $token[0], $name_types, true ) && 'get_posts' === strtolower( UseImportScanner::trailing_name_segment( $token[1] ) ) && null !== $next && '(' === $tokens[ $next ] ) {
				$prev = $this->previous_significant( $tokens, $i - 1 );
				if ( null === $prev || ! is_array( $tokens[ $prev ] ) || ! in_array( $tokens[ $prev ][0], $not_a_call, true ) ) {
					$found[] = $current;
				}
			}
		}
		return $found;
	}

	/**
	 * The token types a name can arrive as: T_STRING always, plus PHP 8's name tokens where the
	 * running PHP defines them. PHP 7.4 splits `\WP_Query` or `Ns\WP_Query` into T_NAMESPACE,
	 * T_NS_SEPARATOR and T_STRING pieces instead, so calls_in() walks past them after `new` to
	 * the last one. Same list as MetaWriteSweepTest::name_token_types().
	 *
	 * @return int[]
	 */
	private function name_token_types(): array {
		$types = array( T_STRING );
		foreach ( array( 'T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE' ) as $constant ) {
			if ( defined( $constant ) ) {
				$types[] = constant( $constant );
			}
		}
		return $types;
	}

	/**
	 * Index of the next token that is not whitespace or a comment.
	 *
	 * @param array<int,mixed> $tokens token_get_all() output.
	 * @param int              $i      Start index.
	 */
	private function next_significant( array $tokens, int $i ): ?int {
		$count = count( $tokens );
		for ( ; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || ! in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Index of the previous token that is not whitespace or a comment.
	 *
	 * @param array<int,mixed> $tokens token_get_all() output.
	 * @param int              $i      Start index.
	 */
	private function previous_significant( array $tokens, int $i ): ?int {
		for ( ; $i >= 0; $i-- ) {
			if ( ! is_array( $tokens[ $i ] ) || ! in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Every call in includes/, keyed path|function|ordinal.
	 *
	 * @return list<string>
	 */
	private function call_keys(): array {
		$root  = dirname( __DIR__, 2 ) . '/';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . 'includes', \FilesystemIterator::SKIP_DOTS ) );
		$keys  = array();
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$path = substr( $file->getPathname(), strlen( $root ) );
			$seen = array();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local source.
			foreach ( $this->calls_in( (string) file_get_contents( $file->getPathname() ) ) as $function ) {
				$seen[ $function ] = ( $seen[ $function ] ?? 0 ) + 1;
				$keys[]            = $path . '|' . $function . '|' . $seen[ $function ];
			}
		}
		sort( $keys );
		return $keys;
	}

	public function test_every_wp_query_and_get_posts_call_is_classed(): void {
		$expected = array_keys( self::CALLS );
		sort( $expected );
		$this->assertSame( $expected, $this->call_keys(), 'A WP_Query or get_posts() call was added, moved or removed; class it here.' );
	}

	/**
	 * PHP 7.4 tokenizes `\WP_Query` and `\get_posts` as T_NS_SEPARATOR plus T_STRING, PHP 8 as one
	 * name token. The scan must find all four forms on either PHP, or a CI leg misses a call.
	 */
	public function test_the_scan_finds_bare_and_fully_qualified_calls(): void {
		$source = '<?php
			function a() { new WP_Query( array() ); }
			function b() { new \WP_Query( array() ); }
			function c() { get_posts( array() ); }
			function d() { \get_posts( array() ); }';
		$this->assertSame( array( 'a', 'b', 'c', 'd' ), $this->calls_in( $source ) );
	}

	public function test_the_one_write_feed_scan_is_failure_aware(): void {
		$this->assertSame(
			array( 'includes/abilities/posts.php|aafm_exec_replace_sitewide|2' ),
			array_keys( array_filter( self::CALLS, static fn( string $kind ): bool => 'WRITE-FEED' === $kind ) )
		);
		$body = ( new \ReflectionFunction( 'aafm_exec_replace_sitewide' ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local source.
		$lines = array_slice( file( (string) $body->getFileName() ), $body->getStartLine() - 1, $body->getEndLine() - $body->getStartLine() + 1 );
		$this->assertStringContainsString( "'posts_pre_query'", implode( '', $lines ) );
	}
}
