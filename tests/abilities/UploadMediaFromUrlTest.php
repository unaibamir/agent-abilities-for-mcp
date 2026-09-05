<?php
/**
 * Aafm/upload-media-from-url: registration, permission, and the end-to-end success path through
 * the real ability object. The SSRF controls themselves have their own dedicated regression file,
 * tests/abilities/UploadMediaFromUrlSsrfTest.php.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class UploadMediaFromUrlTest extends TestCase {

	// 1x1 transparent PNG.
	private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	/**
	 * Absolute paths written by upload tests, cleaned up in tear_down().
	 *
	 * @var array<int,string>
	 */
	private array $written_files = array();

	public function set_up(): void {
		parent::set_up();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/upload-media-from-url' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function tear_down(): void {
		foreach ( $this->written_files as $file ) {
			if ( '' !== $file && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$this->written_files = array();
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_is_in_registry_as_a_write(): void {
		$registry = aafm_get_abilities_registry();

		$this->assertArrayHasKey( 'aafm/upload-media-from-url', $registry );
		$this->assertSame( 'writes', $registry['aafm/upload-media-from-url']['group'] );
		$this->assertSame( 'write', $registry['aafm/upload-media-from-url']['risk'] );
	}

	public function test_requires_upload_files_and_audits_denial(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse(
			wp_get_ability( 'aafm/upload-media-from-url' )->check_permissions(
				array(
					'url'      => 'https://example.test/pixel.png',
					'filename' => 'pixel.png',
				)
			)
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/upload-media-from-url', $abilities );
	}

	public function test_author_is_allowed(): void {
		$this->acting_as( 'author' );
		$this->assertTrue(
			wp_get_ability( 'aafm/upload-media-from-url' )->check_permissions(
				array(
					'url'      => 'https://example.test/pixel.png',
					'filename' => 'pixel.png',
				)
			)
		);
	}

	public function test_a_valid_public_https_image_is_fetched_and_uploaded(): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$png = base64_decode( self::PNG_B64, true );
		add_filter(
			'pre_http_request',
			static fn() => array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => $png,
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			)
		);
		// example.test never resolves in this container (RFC 2606) and some DNS setups sinkhole
		// an unresolvable name to a private/loopback address - pin a public IP so this test
		// exercises the upload path, not the resolver's own environmental behavior.
		add_filter( 'aafm_resolve_hostname_to_ip', static fn(): string => '203.0.113.10' );

		$this->acting_as( 'author' );
		$out = wp_get_ability( 'aafm/upload-media-from-url' )->execute(
			array(
				'url'      => 'https://example.test/pixel.png',
				'filename' => 'pixel.png',
				'alt'      => 'a single pixel',
			)
		);

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'attachment_id', $out );
		$attachment_id = (int) $out['attachment_id'];
		$file          = get_attached_file( $attachment_id );
		if ( is_string( $file ) && '' !== $file ) {
			$this->written_files[] = $file;
		}

		$this->assertSame( 'image/png', get_post_mime_type( $attachment_id ) );
		$this->assertSame( 'a single pixel', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		// Same redaction guarantee as aafm/upload-media: public URL only, never an absolute path.
		$json    = wp_json_encode( $out );
		$basedir = wp_upload_dir()['basedir'];
		$this->assertStringNotContainsString( $basedir, (string) $json );
		$this->assertStringNotContainsString( ABSPATH, (string) $json );
	}

	public function test_a_private_ip_target_is_refused_and_writes_nothing(): void {
		$before = $this->count_attachments();

		$this->acting_as( 'author' );
		$out = wp_get_ability( 'aafm/upload-media-from-url' )->execute(
			array(
				'url'      => 'https://169.254.169.254/latest/meta-data/',
				'filename' => 'x.jpg',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( $before, $this->count_attachments() );
	}

	private function count_attachments(): int {
		return (int) ( new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		) )->found_posts;
	}
}
