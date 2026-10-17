<?php
/**
 * Tests for aafm_trim_unicode().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

/**
 * Invisible and non-ASCII spaces go from both ends; nothing inside a character is ever cut.
 */
final class TrimUnicodeTest extends TestCase {

	/**
	 * Input and expected output.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function rows(): array {
		return array(
			'nbsp alone'               => array( "\xC2\xA0", '' ),
			'zero-width space'         => array( "\xE2\x80\x8B", '' ),
			'byte-order mark'          => array( "\xEF\xBB\xBF", '' ),
			'mixed around an address'  => array( "\xE2\x80\x8B \xC2\xA010.0.0.1\xEF\xBB\xBF ", '10.0.0.1' ),
			'ideographic space'        => array( "\xE3\x80\x8010.0.0.1\xE3\x80\x80", '10.0.0.1' ),
			'ascii whitespace'         => array( "  10.0.0.1\n", '10.0.0.1' ),
			'unchanged'                => array( '10.0.0.1', '10.0.0.1' ),
			'a-grave is not cut'       => array( "\xC3\xA0", "\xC3\xA0" ),
			'invalid utf-8 kept'       => array( "\xFF10.0.0.1 ", "\xFF10.0.0.1" ),
			'trailing nul like trim()' => array( "10.0.0.1\0", '10.0.0.1' ),
		);
	}

	/**
	 * Each row.
	 *
	 * @dataProvider rows
	 *
	 * @param string $input    Input.
	 * @param string $expected Expected output.
	 */
	public function test_trims_invisible_and_unicode_spaces_only_at_the_ends( string $input, string $expected ): void {
		$this->assertSame( $expected, aafm_trim_unicode( $input ) );
	}
}
