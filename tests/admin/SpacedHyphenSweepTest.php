<?php
/**
 * No translatable string uses a spaced hyphen (" - ") as a dash. The copy rule is no dashes at
 * all, and a spaced hyphen reads as one. The sweep covers every literal passed to a gettext call
 * in plugin PHP, the English fallbacks admin.js carries for its localized keys, and the
 * plugin header's name. Anything legitimately spelled with " - " goes in the allowlist below,
 * with the reason next to it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class SpacedHyphenSweepTest extends TestCase {

	/**
	 * Strings allowed to contain " - ", each with the reason.
	 *
	 * @var array<string,string>
	 */
	private const ALLOWED = array(
		// The approved wp.org listing name (plugin header and readme title), a product name, not prose.
		'Agent Abilities for MCP - Connect Claude and ChatGPT with Permission Controls and Audit Log' => 'listing name',
	);

	/**
	 * Gettext functions whose leading string arguments are msgids.
	 *
	 * @var array<string,int> Function name => how many leading string arguments are msgids.
	 */
	private const GETTEXT = array(
		'__'         => 1,
		'_e'         => 1,
		'esc_html__' => 1,
		'esc_html_e' => 1,
		'esc_attr__' => 1,
		'esc_attr_e' => 1,
		'_x'         => 1,
		'_ex'        => 1,
		'esc_html_x' => 1,
		'esc_attr_x' => 1,
		'_n'         => 2,
		'_nx'        => 2,
		'_n_noop'    => 2,
		'_nx_noop'   => 2,
	);

	public function test_no_translatable_string_uses_a_spaced_hyphen_as_a_dash(): void {
		$strings = array_merge( $this->php_msgids(), $this->js_fallbacks(), $this->header_name() );
		$this->assertGreaterThan( 2000, count( $strings ), 'The sweep found too few strings to be reading the plugin.' );

		$offenders = array();
		foreach ( $strings as $where => $text ) {
			if ( false !== strpos( $text, ' - ' ) && ! isset( self::ALLOWED[ $text ] ) ) {
				$offenders[] = $where . ': ' . $text;
			}
		}
		$this->assertSame( array(), $offenders );
	}

	/**
	 * Every literal msgid in plugin PHP, keyed by file:line:index.
	 *
	 * @return array<string,string>
	 */
	private function php_msgids(): array {
		$found = array();
		$iter  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( AAFM_PLUGIN_DIR . 'includes', \FilesystemIterator::SKIP_DOTS ) );
		$files = array( AAFM_PLUGIN_DIR . 'agent-abilities-for-mcp.php', AAFM_PLUGIN_DIR . 'uninstall.php' );
		foreach ( $iter as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		foreach ( $files as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
			$tokens = token_get_all( (string) file_get_contents( $path ) );
			$count  = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! isset( self::GETTEXT[ $tokens[ $i ][1] ] ) ) {
					continue;
				}
				$want = self::GETTEXT[ $tokens[ $i ][1] ];
				$j    = $this->skip_space( $tokens, $i + 1 );
				if ( '(' !== ( $tokens[ $j ] ?? null ) ) {
					continue;
				}
				for ( $arg = 0; $arg < $want; $arg++ ) {
					$j = $this->skip_space( $tokens, $j + 1 );
					if ( ! is_array( $tokens[ $j ] ?? null ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $j ][0] ) {
						break;
					}
					$found[ str_replace( AAFM_PLUGIN_DIR, '', $path ) . ':' . $tokens[ $j ][2] . ':' . $arg ] = $this->unquote( $tokens[ $j ][1] );
					$j = $this->skip_space( $tokens, $j + 1 );
					if ( ',' !== ( $tokens[ $j ] ?? null ) ) {
						break;
					}
				}
			}
		}
		return $found;
	}

	/**
	 * The English fallback admin.js shows when a key is missing: #t( 'key', 'fallback' ).
	 *
	 * @return array<string,string>
	 */
	private function js_fallbacks(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
		$js = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/assets/admin.js' );
		preg_match_all( "/#t\\(\\s*'([A-Za-z0-9_]+)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/", $js, $m );
		$found = array();
		foreach ( $m[1] as $n => $key ) {
			$found[ 'admin.js:' . $key . ':' . $n ] = stripslashes( $m[2][ $n ] );
		}
		return $found;
	}

	/**
	 * The plugin header's Name, which the POT carries as a translatable string.
	 *
	 * @return array<string,string>
	 */
	private function header_name(): array {
		$data = get_file_data( AAFM_PLUGIN_DIR . 'agent-abilities-for-mcp.php', array( 'name' => 'Plugin Name' ) );
		return array( 'agent-abilities-for-mcp.php:Plugin Name' => (string) $data['name'] );
	}

	/**
	 * Index of the next token that is not whitespace or a comment.
	 *
	 * @param array<int,mixed> $tokens Tokens.
	 * @param int              $j      Start index.
	 */
	private function skip_space( array $tokens, int $j ): int {
		while ( isset( $tokens[ $j ] ) && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			++$j;
		}
		return $j;
	}

	/**
	 * The value of a single- or double-quoted PHP literal (escapes are left as written, which is
	 * enough to see a spaced hyphen).
	 *
	 * @param string $literal Literal with quotes.
	 */
	private function unquote( string $literal ): string {
		return substr( $literal, 1, -1 );
	}
}
