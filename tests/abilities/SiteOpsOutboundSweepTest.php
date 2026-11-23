<?php
/**
 * No Site Ops file sends a request, opens a socket, reads a remote file or schedules anything.
 *
 * Walks includes/siteops/ and includes/abilities/site-*.php by glob, so it covers every file a
 * later batch adds. The verification event of the backup gate is scheduled from
 * includes/admin/backup-gate.php, which is outside these paths on purpose: no tool file and no
 * helper a tool loads can schedule anything.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class SiteOpsOutboundSweepTest extends TestCase {

	private const NEEDLES = array( 'wp_remote_', 'wp_safe_remote_', 'curl_', 'fsockopen', 'stream_socket_client', 'WP_Http', 'Requests::', 'file_get_contents(', 'spawn_cron', 'wp_schedule_' );

	/**
	 * The files under the rule.
	 *
	 * @return string[]
	 */
	private function files(): array {
		$root = AAFM_PLUGIN_DIR . 'includes/';
		return array_merge(
			(array) glob( $root . 'siteops/*.php' ),
			(array) glob( $root . 'siteops/*/*.php' ),
			(array) glob( $root . 'abilities/site-*.php' )
		);
	}

	/**
	 * A PHP source with its comments removed and its whitespace collapsed.
	 *
	 * @param string $source PHP source.
	 * @return string
	 */
	private function code_of( string $source ): string {
		$code = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$code .= T_WHITESPACE === $token[0] ? ' ' : $token[1];
			} else {
				$code .= $token;
			}
		}
		return str_replace( ' (', '(', $code );
	}

	public function test_the_sweep_sees_the_files(): void {
		$names = array_map( 'basename', $this->files() );
		foreach ( array( 'url.php', 'vendor-call.php', 'budget.php', 'site-health.php' ) as $expected ) {
			$this->assertContains( $expected, $names );
		}
	}

	public function test_no_site_ops_file_sends_a_request_or_schedules_anything(): void {
		$found = array();
		foreach ( $this->files() as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading this plugin's own source from disk to scan it.
			$code = $this->code_of( (string) file_get_contents( $file ) );
			foreach ( self::NEEDLES as $needle ) {
				if ( false !== strpos( $code, $needle ) ) {
					$found[] = str_replace( AAFM_PLUGIN_DIR, '', $file ) . ' contains ' . $needle;
				}
			}
		}
		$this->assertSame( array(), $found );
	}

	public function test_the_scanner_ignores_comments_and_catches_code(): void {
		$this->assertSame( '', trim( str_replace( '<?php', '', $this->code_of( "<?php\n// wp_remote_get( 'x' );\n/* curl_init() */\n" ) ) ) );
		$this->assertStringContainsString( 'wp_schedule_', $this->code_of( "<?php\nwp_schedule_single_event ( 1, 'x' );\n" ) );
		$this->assertStringContainsString( 'file_get_contents(', $this->code_of( "<?php\n\$a = file_get_contents ( \$b );\n" ) );
	}
}
