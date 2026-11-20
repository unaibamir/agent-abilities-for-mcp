<?php
/**
 * The canonical URL check and the post to URL helper of the Site Ops tools.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class SiteopsUrlTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/url.php';
		update_option( 'home', 'https://example.com' );
		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * The rule class of a refusal.
	 *
	 * @param mixed $result Result of the URL check.
	 * @return string
	 */
	private function rule( $result ): string {
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_site_bad_url', $result->get_error_code() );
		$data = $result->get_error_data();
		return is_array( $data ) && isset( $data['rule'] ) ? (string) $data['rule'] : '';
	}

	/**
	 * Inputs that must be refused on https://example.com, with the rule class.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function refusals(): array {
		return array(
			'empty'                  => array( '', 'length' ),
			'2001 bytes'             => array( '/' . str_repeat( 'a', 2000 ), 'length' ),
			'decoded path over 1024' => array( '/' . str_repeat( 'a', 1100 ), 'length' ),
			'tab'                    => array( "/a\tb", 'characters' ),
			'backslash'              => array( '/a\\b', 'characters' ),
			'http on https site'     => array( 'http://example.com/a', 'origin' ),
			'port on portless site'  => array( 'https://example.com:8443/a', 'origin' ),
			'userinfo'               => array( 'https://user@example.com/a', 'origin' ),
			'userinfo and password'  => array( 'https://user:secret@example.com/a', 'origin' ),
			'protocol relative'      => array( '//example.com/a', 'origin' ),
			'no scheme'              => array( 'example.com/a', 'origin' ),
			'another host'           => array( 'https://other.example/a', 'origin' ),
			'trailing dot host'      => array( 'https://example.com./a', 'origin' ),
			'query on a path'        => array( '/a?x=1', 'query' ),
			'query on a url'         => array( 'https://example.com/a?x=1', 'query' ),
			'bare question mark'     => array( '/a?', 'query' ),
			'fragment on a path'     => array( '/a#f', 'fragment' ),
			'fragment on a url'      => array( 'https://example.com/a#f', 'fragment' ),
			'two dots segment'       => array( '/a/../b', 'traversal' ),
			'encoded two dots'       => array( '/%2e%2e/b', 'traversal' ),
			'double encoded dots'    => array( '/%252e%252e/b', 'characters' ),
			'dots then encoded sep'  => array( '/a/..%2fb', 'traversal' ),
			'trailing single dot'    => array( '/a/.', 'traversal' ),
			'two dots in a segment'  => array( '/a..b', 'traversal' ),
			'star'                   => array( '/a/*', 'characters' ),
			'open bracket'           => array( '/a/[x]', 'characters' ),
			'open brace'             => array( '/a/{x}', 'characters' ),
			'raw space'              => array( '/a b', 'characters' ),
			'encoded space'          => array( '/a%20b', 'characters' ),
			'encoded nul'            => array( '/a%00b', 'characters' ),
			'invalid utf8'           => array( '/a%ff', 'characters' ),
		);
	}

	/**
	 * Each refused input names its rule class.
	 *
	 * @dataProvider refusals
	 *
	 * @param string $input Raw input.
	 * @param string $rule  Expected rule class.
	 */
	public function test_refusals( string $input, string $rule ): void {
		$this->assertSame( $rule, $this->rule( aafm_siteops_canonical_url( $input ) ) );
	}

	public function test_a_plain_path_and_a_full_url_give_the_same_result(): void {
		$expected = array(
			'url'          => 'https://example.com/about/',
			'path'         => '/about/',
			'path_encoded' => '/about/',
			'is_home'      => false,
		);
		$this->assertSame( $expected, aafm_siteops_canonical_url( '/about/' ) );
		$this->assertSame( $expected, aafm_siteops_canonical_url( 'https://example.com/about/' ) );
		$this->assertSame( $expected, aafm_siteops_canonical_url( 'https://EXAMPLE.com/about/' ) );
		$this->assertSame( $expected, aafm_siteops_canonical_url( 'HTTPS://example.com:443/about/' ) );
	}

	public function test_runs_of_slashes_collapse(): void {
		$result = aafm_siteops_canonical_url( '/a//b/' );
		$this->assertIsArray( $result );
		$this->assertSame( '/a/b/', $result['path'] );
	}

	/**
	 * Non-Latin slugs, raw.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function slugs(): array {
		return array(
			'latin accent' => array( 'café-été' ),
			'cjk'          => array( '你好' ),
			'hindi'        => array( 'नमस्ते' ),
			'thai'         => array( 'สวัสดี' ),
		);
	}

	/**
	 * A slug in raw, lower-case encoded and upper-case encoded form is one URL.
	 *
	 * @dataProvider slugs
	 *
	 * @param string $slug Raw slug.
	 */
	public function test_a_non_latin_slug_gives_the_same_result_in_every_spelling( string $slug ): void {
		$lower = strtolower( rawurlencode( $slug ) );
		$upper = rawurlencode( $slug );
		$this->assertNotSame( $lower, $upper );

		$expected = array(
			'url'          => 'https://example.com/' . $lower . '/',
			'path'         => '/' . $slug . '/',
			'path_encoded' => '/' . $lower . '/',
			'is_home'      => false,
		);
		$this->assertSame( $expected, aafm_siteops_canonical_url( '/' . $slug . '/' ) );
		$this->assertSame( $expected, aafm_siteops_canonical_url( '/' . $lower . '/' ) );
		$this->assertSame( $expected, aafm_siteops_canonical_url( '/' . $upper . '/' ) );
		$this->assertSame( $expected, aafm_siteops_canonical_url( 'https://example.com/' . $upper . '/' ) );
	}

	public function test_the_bare_origin_is_the_home_url_with_its_slash(): void {
		$expected = array(
			'url'          => 'https://example.com/',
			'path'         => '/',
			'path_encoded' => '/',
			'is_home'      => true,
		);
		$this->assertSame( $expected, aafm_siteops_canonical_url( 'https://example.com' ) );
		$this->assertSame( $expected, aafm_siteops_canonical_url( 'https://example.com', true ) );
		$this->assertSame( $expected, aafm_siteops_canonical_url( '/' ) );
	}

	public function test_a_port_on_the_site_must_be_given(): void {
		update_option( 'home', 'http://localhost:8072' );
		$this->assertSame( 'origin', $this->rule( aafm_siteops_canonical_url( 'http://localhost/a' ) ) );
		$this->assertSame( 'origin', $this->rule( aafm_siteops_canonical_url( 'http://localhost:80/a' ) ) );
		$result = aafm_siteops_canonical_url( 'http://localhost:8072/a/' );
		$this->assertIsArray( $result );
		$this->assertSame( 'http://localhost:8072/a/', $result['url'] );
	}

	public function test_a_home_path_is_enforced(): void {
		update_option( 'home', 'https://example.com/blog' );
		$home = array(
			'url'          => 'https://example.com/blog/',
			'path'         => '/blog/',
			'path_encoded' => '/blog/',
			'is_home'      => true,
		);
		$this->assertSame( $home, aafm_siteops_canonical_url( '/blog' ) );
		$this->assertSame( $home, aafm_siteops_canonical_url( 'https://example.com/blog', true ) );
		$inner = aafm_siteops_canonical_url( '/blog/x/' );
		$this->assertIsArray( $inner );
		$this->assertSame( '/blog/x/', $inner['path'] );
		$this->assertFalse( $inner['is_home'] );
		$this->assertSame( 'origin', $this->rule( aafm_siteops_canonical_url( '/blog-other/x' ) ) );
		$this->assertSame( 'origin', $this->rule( aafm_siteops_canonical_url( '/x' ) ) );
	}

	public function test_the_trailing_slash_is_kept_as_given_by_default(): void {
		$with    = aafm_siteops_canonical_url( '/about/' );
		$without = aafm_siteops_canonical_url( '/about' );
		$this->assertIsArray( $with );
		$this->assertIsArray( $without );
		$this->assertSame( '/about/', $with['path'] );
		$this->assertSame( '/about', $without['path'] );
	}

	public function test_permalink_slash_follows_a_trailing_slash_structure(): void {
		foreach ( array( '/about', '/about/' ) as $input ) {
			$result = aafm_siteops_canonical_url( $input, true );
			$this->assertIsArray( $result );
			$this->assertSame( 'https://example.com/about/', $result['url'] );
		}
		$file = aafm_siteops_canonical_url( '/file.txt', true );
		$this->assertIsArray( $file );
		$this->assertSame( '/file.txt', $file['path'] );
	}

	public function test_permalink_slash_follows_a_structure_without_a_trailing_slash(): void {
		$this->set_permalink_structure( '/%postname%' );
		foreach ( array( '/about', '/about/' ) as $input ) {
			$result = aafm_siteops_canonical_url( $input, true );
			$this->assertIsArray( $result );
			$this->assertSame( 'https://example.com/about', $result['url'] );
		}
		$home = aafm_siteops_canonical_url( 'https://example.com', true );
		$this->assertIsArray( $home );
		$this->assertSame( 'https://example.com/', $home['url'] );
	}

	public function test_post_url_of_a_published_post(): void {
		$id     = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_name'   => 'hello-there',
			)
		);
		$result = aafm_siteops_post_url( $id );
		$this->assertIsArray( $result );
		$this->assertSame( 'https://example.com/hello-there/', $result['url'] );
		$this->assertFalse( $result['is_home'] );
	}

	public function test_post_url_allows_a_password_protected_post(): void {
		$id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_name'     => 'locked',
				'post_password' => 'secret',
			)
		);
		$this->assertIsArray( aafm_siteops_post_url( $id ) );
	}

	public function test_post_url_refuses_what_is_not_public(): void {
		register_post_type( 'aafm_hidden', array( 'public' => false ) );
		$cases = array(
			'id 0'       => 0,
			'negative'   => -3,
			'missing'    => 999999,
			'draft'      => self::factory()->post->create( array( 'post_status' => 'draft' ) ),
			'private'    => self::factory()->post->create( array( 'post_status' => 'private' ) ),
			'not viewed' => self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => 'aafm_hidden',
				)
			),
		);
		foreach ( $cases as $label => $id ) {
			$result = aafm_siteops_post_url( (int) $id );
			$this->assertInstanceOf( \WP_Error::class, $result, $label );
			$this->assertSame( 'aafm_site_post_not_public', $result->get_error_code(), $label );
		}
		unregister_post_type( 'aafm_hidden' );
	}

	public function test_post_url_on_plain_permalinks_is_a_bad_url_of_class_query(): void {
		$this->set_permalink_structure( '' );
		$id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$result = aafm_siteops_post_url( $id );
		$this->assertSame( 'query', $this->rule( $result ) );
		$this->assertStringContainsString( 'plain permalinks', $result->get_error_message() );
	}

	public function test_post_url_of_the_static_front_page_is_the_home_url(): void {
		$id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
				'post_name'   => 'front',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $id );
		$result = aafm_siteops_post_url( $id );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['is_home'] );
		$this->assertSame( 'https://example.com/', $result['url'] );
	}

	public function test_no_refusal_message_repeats_the_input(): void {
		$result = aafm_siteops_canonical_url( '/zz-marker-zz/../b' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringNotContainsString( 'zz-marker-zz', $result->get_error_message() );
	}
}
