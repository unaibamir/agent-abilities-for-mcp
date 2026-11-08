<?php
/**
 * The URL checker accepts a URL as written or refuses it, and never quietly rewrites it.
 *
 * The core sanitizer turns javascript: into "" and "not a url at all" into
 * http://not20a20url20at20all, and the writers stored both and answered ok.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class CheckedUrlTest extends TestCase {

	/**
	 * A valid URL is accepted.
	 *
	 * @dataProvider accepted
	 *
	 * @param string $input    What the caller sent.
	 * @param string $expected What is stored.
	 */
	public function test_a_valid_url_is_accepted( string $input, string $expected ): void {
		$this->assertSame( $expected, aafm_checked_url( $input, 'url' ) );
	}

	/**
	 * Inputs that are accepted, with what is stored.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function accepted(): array {
		return array(
			'https'           => array( 'https://example.com/a?b=1#c', 'https://example.com/a?b=1#c' ),
			'http'            => array( 'http://example.com', 'http://example.com' ),
			'mailto'          => array( 'mailto:hi@example.com', 'mailto:hi@example.com' ),
			'tel'             => array( 'tel:+15551234567', 'tel:+15551234567' ),
			'root relative'   => array( '/about/', '/about/' ),
			'anchor'          => array( '#contact', '#contact' ),
			'schemeless host' => array( 'example.com/page', 'http://example.com/page' ),
			'empty clears'    => array( '', '' ),
			'blank clears'    => array( "  \t", '' ),
			'padded'          => array( '  https://example.com/x  ', 'https://example.com/x' ),
			'punycode tld'    => array( 'example.xn--p1ai', 'http://example.xn--p1ai' ),
			'punycode path'   => array( 'example.xn--p1ai/page', 'http://example.xn--p1ai/page' ),
			'space in path'   => array( 'https://example.com/a b', 'https://example.com/a%20b' ),
			'port'            => array( 'https://example.com:8443/x', 'https://example.com:8443/x' ),
		);
	}

	/**
	 * An invalid URL is refused with a named error.
	 *
	 * @dataProvider refused
	 *
	 * @param string $input What the caller sent.
	 */
	public function test_an_invalid_url_is_refused_with_a_named_error( string $input ): void {
		$result = aafm_checked_url( $input, 'canonical' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aafm_invalid_url', $result->get_error_code() );
		$this->assertStringContainsString( 'canonical', $result->get_error_message() );
		$this->assertStringNotContainsString( $input, $result->get_error_message(), 'The refused value is not echoed back.' );
	}

	public function test_an_internationalized_host_is_accepted(): void {
		$result = aafm_checked_url( "\u{43F}\u{440}\u{438}\u{43C}\u{435}\u{440}.\u{440}\u{444}/page", 'url' );

		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'http://', $result );
	}

	/**
	 * Inputs that are refused.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function refused(): array {
		return array(
			'javascript'     => array( 'javascript:alert(1)' ),
			'mixed case'     => array( 'JaVaScRiPt:alert(1)' ),
			'data'           => array( 'data:text/html,<script>alert(1)</script>' ),
			'junk sentence'  => array( 'not a url at all' ),
			'single word'    => array( 'nonsense' ),
			'vbscript'       => array( 'vbscript:msgbox(1)' ),
			'markup in path' => array( 'https://example.com/<x>' ),
			'braces in path' => array( 'https://example.com/a{b}' ),
			'space in host'  => array( 'https://not a url.example/' ),
		);
	}
}
