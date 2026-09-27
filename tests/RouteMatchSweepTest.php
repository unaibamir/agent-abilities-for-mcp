<?php
/**
 * Sweep: every "is this request the MCP route" decision goes through aafm_is_mcp_route().
 *
 * Tokenises every PHP file under includes/ plus agent-abilities-for-mcp.php and uninstall.php and
 * keys what it finds by path|enclosing function (a closure counts as the function it sits in). It
 * fails on:
 * (a) a call of aafm_mcp_rest_route() or aafm_mcp_rest_namespace_route() outside ROUTE_CALLS;
 * (b) a string literal carrying agent-abilities-for-mcp/mcp outside ROUTE_LITERALS;
 * (c) a use of AAFM_MCP_ROUTE_SEGMENT outside SEGMENT_USES;
 * (d) a string compare (strcasecmp, strcmp, strncasecmp, strncmp, stripos, strpos,
 *     str_starts_with) whose arguments carry get_route() or a variable assigned from it, outside
 *     ROUTE_COMPARES;
 * (e) AAFM_MCP_NAMESPACE concatenated with a literal that starts with /mcp, anywhere.
 * Every key carries its count, and a key that no longer matches fails, so a stale entry cannot
 * hide a new site.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class RouteMatchSweepTest extends TestCase {

	/**
	 * (a) Calls of the two route builders, key => count.
	 *
	 * @var array<string,int>
	 */
	private const ROUTE_CALLS = array(
		'includes/bootstrap.php|aafm_is_mcp_route'        => 1,
		'includes/bootstrap.php|aafm_mcp_rest_route'      => 1,
		'includes/admin/connection.php|aafm_endpoint_url' => 1,
		'includes/admin/connection.php|aafm_diagnostic_checks' => 1,
		'includes/oauth/validator.php|aafm_oauth_request_targets_mcp_route' => 1,
	);

	/**
	 * (b) Literals carrying the MCP route: the Help tab's display text, key => count.
	 *
	 * @var array<string,int>
	 */
	private const ROUTE_LITERALS = array(
		'includes/admin/page.php|aafm_render_help_tab' => 4,
	);

	/**
	 * (c) Uses of AAFM_MCP_ROUTE_SEGMENT, key => count.
	 *
	 * @var array<string,int>
	 */
	private const SEGMENT_USES = array(
		'includes/bootstrap.php|aafm_mcp_rest_namespace_route' => 1,
		'includes/server.php|aafm_register_mcp_server' => 1,
	);

	/**
	 * (d) String compares on a route read from get_route(), key => count. The OAuth sub-namespace
	 * prefix test is a different route family.
	 *
	 * @var array<string,int>
	 */
	private const ROUTE_COMPARES = array(
		'includes/oauth/rest.php|aafm_oauth_filter_malformed_json' => 1,
	);

	private const COMPARE_FUNCTIONS = array( 'strcasecmp', 'strcmp', 'strncasecmp', 'strncmp', 'stripos', 'strpos', 'str_starts_with' );

	/**
	 * Findings per rule for one source file.
	 *
	 * @param string $source Full file contents.
	 * @param string $path   Path the source is keyed under.
	 * @return array{calls:array<string,int>,literals:array<string,int>,segment:array<string,int>,compares:array<string,int>,namespace:array<string,int>}
	 */
	private function scan( string $source, string $path ): array {
		$tokens   = token_get_all( $source );
		$nullsafe = defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ? constant( 'T_NULLSAFE_OBJECT_OPERATOR' ) : -1;
		$skip     = array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, $nullsafe );
		$stack    = array();
		$pending  = null;
		$depth    = 0;
		$found    = array(
			'calls'     => array(),
			'literals'  => array(),
			'segment'   => array(),
			'compares'  => array(),
			'namespace' => array(),
		);
		$routes   = array(); // key => variables assigned from get_route() in that function.
		$total    = count( $tokens );

		for ( $i = 0; $i < $total; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
				$next = $this->next_significant( $tokens, $i + 1, 1 );
				if ( null !== $next && is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0] ) {
					$pending = $tokens[ $next ][1];
				}
				continue;
			}
			if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
				if ( null !== $pending && '{' === $token ) {
					$stack[] = array( $pending, $depth );
					$pending = null;
				}
				continue;
			}
			if ( ';' === $token ) {
				$pending = null;
				continue;
			}
			if ( '}' === $token ) {
				if ( array() !== $stack && end( $stack )[1] === $depth ) {
					array_pop( $stack );
				}
				--$depth;
				continue;
			}
			if ( ! is_array( $token ) ) {
				continue;
			}
			$key = $path . '|' . ( array() === $stack ? '{main}' : end( $stack )[0] );

			if ( in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
				if ( false !== stripos( $token[1], 'agent-abilities-for-mcp/mcp' ) ) {
					$found['literals'][ $key ] = ( $found['literals'][ $key ] ?? 0 ) + 1;
				}
				continue;
			}
			if ( T_STRING !== $token[0] ) {
				continue;
			}
			if ( 'AAFM_MCP_ROUTE_SEGMENT' === $token[1] ) {
				$found['segment'][ $key ] = ( $found['segment'][ $key ] ?? 0 ) + 1;
				continue;
			}
			if ( 'AAFM_MCP_NAMESPACE' === $token[1] ) {
				$dot    = $this->next_significant( $tokens, $i + 1, 1 );
				$string = null === $dot ? null : $this->next_significant( $tokens, $dot + 1, 1 );
				if ( null !== $string && '.' === $tokens[ $dot ] && is_array( $tokens[ $string ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $string ][0]
					&& 0 === stripos( substr( $tokens[ $string ][1], 1 ), '/mcp' )
				) {
					$found['namespace'][ $key ] = ( $found['namespace'][ $key ] ?? 0 ) + 1;
				}
				continue;
			}
			$open = $this->next_significant( $tokens, $i + 1, 1 );
			if ( null === $open || '(' !== $tokens[ $open ] ) {
				continue;
			}
			$before = $this->next_significant( $tokens, $i - 1, -1 );
			$method = null !== $before && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_OBJECT_OPERATOR, $nullsafe ), true );
			if ( 'get_route' === $token[1] && $method ) {
				$start = $i;
				while ( $start > 0 && ! in_array( $tokens[ $start - 1 ], array( ';', '{', '}' ), true ) ) {
					--$start;
				}
				$first = $this->next_significant( $tokens, $start, 1 );
				$equal = null === $first ? null : $this->next_significant( $tokens, $first + 1, 1 );
				if ( null !== $equal && is_array( $tokens[ $first ] ) && T_VARIABLE === $tokens[ $first ][0] && '=' === $tokens[ $equal ] ) {
					$routes[ $key ][] = $tokens[ $first ][1];
				}
				continue;
			}
			if ( null !== $before && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], $skip, true ) ) {
				continue;
			}
			if ( in_array( $token[1], array( 'aafm_mcp_rest_route', 'aafm_mcp_rest_namespace_route' ), true ) ) {
				$found['calls'][ $key ] = ( $found['calls'][ $key ] ?? 0 ) + 1;
			} elseif ( in_array( $token[1], self::COMPARE_FUNCTIONS, true ) && $this->reads_route( $tokens, $open, $routes[ $key ] ?? array() ) ) {
				$found['compares'][ $key ] = ( $found['compares'][ $key ] ?? 0 ) + 1;
			}
		}

		return $found;
	}

	/**
	 * Whether the parenthesised arguments opening at $open carry get_route() or one of $variables.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens    token_get_all() output.
	 * @param int                                           $open      Index of the "(".
	 * @param array<int,string>                             $variables Variables assigned from get_route().
	 */
	private function reads_route( array $tokens, int $open, array $variables ): bool {
		$nesting = 0;
		$total   = count( $tokens );
		for ( $j = $open; $j < $total; $j++ ) {
			$token = $tokens[ $j ];
			if ( '(' === $token ) {
				++$nesting;
			} elseif ( ')' === $token && 0 === --$nesting ) {
				return false;
			} elseif ( is_array( $token ) && ( ( T_STRING === $token[0] && 'get_route' === $token[1] ) || ( T_VARIABLE === $token[0] && in_array( $token[1], $variables, true ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Index of the nearest token in $step's direction that is not whitespace or a comment.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param int                                           $index  Index to start from.
	 * @param int                                           $step   1 forward, -1 back.
	 */
	private function next_significant( array $tokens, int $index, int $step ): ?int {
		$total = count( $tokens );
		for ( ; $index >= 0 && $index < $total; $index += $step ) {
			if ( is_array( $tokens[ $index ] ) && in_array( $tokens[ $index ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $index;
		}
		return null;
	}

	/**
	 * Findings across the plugin, per rule, sorted by key.
	 *
	 * @return array<string,array<string,int>>
	 */
	private function plugin_findings(): array {
		$root  = AAFM_PLUGIN_DIR;
		$paths = array( 'agent-abilities-for-mcp.php', 'uninstall.php' );
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . 'includes', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$paths[] = 'includes/' . ltrim( str_replace( $root . 'includes', '', $file->getPathname() ), '/' );
			}
		}

		$all = array();
		foreach ( $paths as $path ) {
			$source = (string) file_get_contents( $root . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this plugin's own local source to scan it, not a remote URL.
			$this->assertNotSame( '', $source, "The sweep must read {$path}." );
			foreach ( $this->scan( $source, $path ) as $rule => $keys ) {
				$all[ $rule ] = array_merge( $all[ $rule ] ?? array(), $keys );
			}
		}
		foreach ( $all as $rule => $keys ) {
			ksort( $keys );
			$all[ $rule ] = $keys;
		}
		return $all;
	}

	/**
	 * Sorted copy of a keyed list.
	 *
	 * @param array<string,int> $keys Keys and counts.
	 * @return array<string,int>
	 */
	private function sorted( array $keys ): array {
		ksort( $keys );
		return $keys;
	}

	public function test_the_scanner_finds_each_route_spelling(): void {
		$source = "<?php\nfunction f( \$r ) {\n\t\$a = aafm_mcp_rest_route();\n\t\$b = 'x/agent-abilities-for-mcp/MCP';\n\t\$c = AAFM_MCP_ROUTE_SEGMENT;\n\t\$d = AAFM_MCP_NAMESPACE . '/mcp';\n\tstrcmp( 'Mcp-Session-Id', \$a );\n\treturn 0 === strcasecmp( \$r->get_route(), \$a );\n}\nfunction g( \$o, \$r ) {\n\t\$o->aafm_mcp_rest_route();\n\t\$route = \$r->get_route();\n\tstrpos( 'a', 'b' );\n\treturn stripos( \$route, 'x' );\n}\nfunction aafm_mcp_rest_route() {}\n";

		$this->assertSame(
			array(
				'calls'     => array( 'x.php|f' => 1 ),
				'literals'  => array( 'x.php|f' => 1 ),
				'segment'   => array( 'x.php|f' => 1 ),
				'compares'  => array(
					'x.php|f' => 1,
					'x.php|g' => 1,
				),
				'namespace' => array( 'x.php|f' => 1 ),
			),
			$this->scan( $source, 'x.php' )
		);
	}

	public function test_every_mcp_route_decision_goes_through_the_shared_predicate(): void {
		$found = $this->plugin_findings();

		$this->assertSame( $this->sorted( self::ROUTE_CALLS ), $found['calls'] ?? array(), 'A route builder is called outside the keyed sites; decide "is this the MCP route" with aafm_is_mcp_route().' );
		$this->assertSame( $this->sorted( self::ROUTE_LITERALS ), $found['literals'] ?? array(), 'A literal spells the MCP route outside the keyed display sites.' );
		$this->assertSame( $this->sorted( self::SEGMENT_USES ), $found['segment'] ?? array(), 'AAFM_MCP_ROUTE_SEGMENT is used outside the keyed sites.' );
		$this->assertSame( $this->sorted( self::ROUTE_COMPARES ), $found['compares'] ?? array(), 'A function that reads get_route() compares strings; use aafm_is_mcp_route().' );
		$this->assertSame( array(), $found['namespace'] ?? array(), 'AAFM_MCP_NAMESPACE is joined to /mcp; use aafm_mcp_rest_route() through aafm_is_mcp_route().' );
	}
}
