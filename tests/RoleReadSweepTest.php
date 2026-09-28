<?php
/**
 * Sweep (step 14, W2-T7): every `->roles`, `?->roles`, `->caps` and `->allcaps` property read in
 * includes/ and agent-abilities-for-mcp.php is classified. A WP_User's roles and caps come from its
 * caps usermeta load, and a load that fails leaves them empty, so a read that decides anything has
 * to rest on a checked load or refuse. Each read is keyed path|function|ordinal (the enclosing named
 * function; a closure counts as the function it sits in), and every key must match exactly once, so
 * a new read fails this test until it is classified here.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class RoleReadSweepTest extends TestCase {

	/**
	 * Every classified read, key => kind.
	 *
	 * @var array<string,string>
	 */
	private const CLASSIFIED = array(
		'includes/allowlist.php|aafm_ability_allowed_for_principal|1' => 'decision, current user: the object the capability check used',
		'includes/allowlist.php|aafm_ability_allowed_for_principal|2' => 'decision, other id: loaded in the checked-read scope, refuses on error',
		'includes/abilities/users.php|aafm_exec_update_user|1' => 'decision, last-admin demotion guard: target loaded in the checked-read scope',
		'includes/abilities/users.php|aafm_exec_delete_user|1' => 'decision, last-admin delete guard: victim loaded in the checked-read scope',
		'includes/oauth/clients.php|aafm_oauth_list_grants|1' => 'display: scope, fallback row on error',
		'includes/admin/dashboard.php|aafm_agent_user_candidates|1' => 'display: scope, no roles and admin on error, only when the cache lost the earlier usermeta load',
		'includes/helpers.php|aafm_redact_user|1' => 'response field: inside the scope on create/update-user, pure reads as 1.7.5',
		'includes/admin/connection.php|aafm_create_agent_user|1' => 'admin write guard: an empty role list is not agent-shaped and writes nothing',
		'includes/admin/connection.php|aafm_backfill_agent_user_marker|1' => 'admin write guard: same shape',
		'includes/admin/allowlist.php|aafm_allowlist_sanitize_row|1' => 'role registry (wp_roles()->roles), not a user load',
	);

	private const PROPERTIES = array( 'roles', 'caps', 'allcaps' );

	/**
	 * Keys of every role/caps property read in $source.
	 *
	 * @param string $source Full file contents.
	 * @param string $path   Path the source is keyed under.
	 * @return string[]
	 */
	private function read_keys( string $source, string $path ): array {
		$tokens   = token_get_all( $source );
		$nullsafe = defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ? constant( 'T_NULLSAFE_OBJECT_OPERATOR' ) : -1;
		$stack    = array(); // [ name, depth at which its body opened ].
		$pending  = null;
		$depth    = 0;
		$ordinals = array();
		$keys     = array();
		$total    = count( $tokens );

		for ( $i = 0; $i < $total; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
				$next = $this->next_significant( $tokens, $i + 1 );
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
				$pending = null; // An abstract or interface declaration has no body.
				continue;
			}
			if ( '}' === $token ) {
				if ( array() !== $stack && end( $stack )[1] === $depth ) {
					array_pop( $stack );
				}
				--$depth;
				continue;
			}
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_OBJECT_OPERATOR, $nullsafe ), true ) ) {
				continue;
			}
			$next = $this->next_significant( $tokens, $i + 1 );
			if ( null === $next || ! is_array( $tokens[ $next ] ) || T_STRING !== $tokens[ $next ][0] || ! in_array( $tokens[ $next ][1], self::PROPERTIES, true ) ) {
				continue;
			}
			$after = $this->next_significant( $tokens, $next + 1 );
			if ( null !== $after && '(' === $tokens[ $after ] ) {
				continue; // A method call, not a property read.
			}
			$base              = $path . '|' . ( array() === $stack ? '{main}' : end( $stack )[0] );
			$ordinals[ $base ] = ( $ordinals[ $base ] ?? 0 ) + 1;
			$keys[]            = $base . '|' . $ordinals[ $base ];
		}

		return $keys;
	}

	/**
	 * Index of the next token that is not whitespace or a comment, or null at the end.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param int                                           $index  Index to start from.
	 */
	private function next_significant( array $tokens, int $index ): ?int {
		$total = count( $tokens );
		for ( ; $index < $total; $index++ ) {
			if ( is_array( $tokens[ $index ] ) && in_array( $tokens[ $index ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $index;
		}
		return null;
	}

	public function test_the_scanner_keys_each_read_shape_by_its_named_function(): void {
		$source = "<?php\nfunction f( \$u ) {\n\t\$a = \$u->roles;\n\t\$b = \$u?->caps;\n\t\$c = static function () use ( \$u ) {\n\t\treturn \$u->allcaps;\n\t};\n\t\$u->caps();\n}\nfunction g( \$u ) {\n\t// \$u->roles in a comment is not a read.\n\treturn \"{\$u->roles}\";\n}\n";

		$this->assertSame(
			array( 'x.php|f|1', 'x.php|f|2', 'x.php|f|3', 'x.php|g|1' ),
			$this->read_keys( $source, 'x.php' )
		);
	}

	public function test_every_role_and_caps_read_is_classified_exactly_once(): void {
		$root  = AAFM_PLUGIN_DIR;
		$paths = array( 'agent-abilities-for-mcp.php' );
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . 'includes', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$paths[] = 'includes/' . ltrim( str_replace( $root . 'includes', '', $file->getPathname() ), '/' );
			}
		}

		$found = array();
		foreach ( $paths as $path ) {
			$source = (string) file_get_contents( $root . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this plugin's own local source to scan it, not a remote URL.
			$this->assertNotSame( '', $source, "The sweep must read {$path}." );
			$found = array_merge( $found, $this->read_keys( $source, $path ) );
		}

		sort( $found );
		$expected = array_keys( self::CLASSIFIED );
		sort( $expected );
		$this->assertSame( $expected, $found, 'A ->roles, ->caps or ->allcaps read was added, moved or removed; classify it in CLASSIFIED.' );
	}
}
