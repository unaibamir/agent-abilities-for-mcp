<?php
/**
 * Media writes: featured image by existing attachment id only; upload-media is
 * base64-hardened (no URL fetch, byte-sniffed mime allowlist, SVG rejected,
 * size-capped, filename sanitized, WordPress owns the path).
 *
 * This is the single most dangerous ability in the catalog — file upload is
 * exactly where competitors shipped SSRF, arbitrary-file-write, and RCE CVEs.
 * Every §6.2 control carries an explicit regression test here.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;
use WP_Post;

final class MediaWriteTest extends TestCase {

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
		// The audited registration wrapper logs every permission check and execute to
		// the custom table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Register categories + enabled abilities inside their gated init actions,
		// simulated by pushing the action name onto $wp_current_filter — the idiom WP
		// core's own ability test trait uses. do_action() on the core hook trips the
		// WPCS non-prefixed-hookname sniff (Phase 1 carried issue).
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/set-featured-image', 'aafm/upload-media', 'aafm/update-media', 'aafm/delete-media' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function tear_down(): void {
		// Remove any uploaded fixture bytes that leaked into the uploads dir.
		foreach ( $this->written_files as $file ) {
			if ( '' !== $file && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$this->written_files = array();
		parent::tear_down();
	}

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * @param string   $action   Action name to simulate.
	 * @param callable $callback Callback to invoke while the action is "running".
	 */
	private function in_action( string $action, callable $callback ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$callback();
		array_pop( $wp_current_filter );
	}

	/**
	 * Track an attachment's file (and its generated sub-sizes) for cleanup.
	 *
	 * @param int $attachment_id Attachment post id.
	 */
	private function track_attachment_files( int $attachment_id ): void {
		$file = get_attached_file( $attachment_id );
		if ( is_string( $file ) && '' !== $file ) {
			$this->written_files[] = $file;
		}
	}


	public function test_both_writes_are_in_registry_as_writes(): void {
		$registry = aafm_get_abilities_registry();

		$this->assertArrayHasKey( 'aafm/set-featured-image', $registry );
		$this->assertSame( 'writes', $registry['aafm/set-featured-image']['group'] );
		$this->assertSame( 'write', $registry['aafm/set-featured-image']['risk'] );

		$this->assertArrayHasKey( 'aafm/upload-media', $registry );
		$this->assertSame( 'writes', $registry['aafm/upload-media']['group'] );
		$this->assertSame( 'write', $registry['aafm/upload-media']['risk'] );

		// Both are additive writes (not destructive), annotated honestly.
		$featured = aafm_args_set_featured_image();
		$this->assertFalse( $featured['meta']['annotations']['readonly'] );
		$this->assertFalse( $featured['meta']['annotations']['destructive'] );

		$upload = aafm_args_upload_media();
		$this->assertFalse( $upload['meta']['annotations']['readonly'] );
		$this->assertFalse( $upload['meta']['annotations']['destructive'] );

		// Closed input schemas (the first anti-escalation layer).
		$this->assertFalse( $featured['input_schema']['additionalProperties'] );
		$this->assertFalse( $upload['input_schema']['additionalProperties'] );
	}


	/**
	 * (e) A caller who cannot edit the TARGET post cannot set its thumbnail.
	 */
	public function test_set_featured_image_requires_per_object_edit_and_audits_denial(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$post  = self::factory()->post->create( array( 'post_author' => $owner ) );

		$this->acting_as( 'author' ); // a different author — cannot edit someone else's post.
		$this->assertFalse(
			wp_get_ability( 'aafm/set-featured-image' )->check_permissions(
				array(
					'post_id'       => $post,
					'attachment_id' => 1,
				)
			)
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/set-featured-image', $abilities );
	}

	/**
	 * (e) A non-image / non-attachment id is rejected with a WP_Error and the
	 * thumbnail is never set.
	 */
	public function test_set_featured_image_rejects_non_image_attachment_id(): void {
		$this->acting_as( 'editor' );
		$post = self::factory()->post->create();

		// A plain post id (not an attachment) must be rejected.
		$plain = self::factory()->post->create();
		$out   = wp_get_ability( 'aafm/set-featured-image' )->execute(
			array(
				'post_id'       => $post,
				'attachment_id' => $plain,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertFalse( has_post_thumbnail( $post ) );

		// A non-image attachment (e.g. a PDF) must also be rejected.
		$pdf  = self::factory()->attachment->create_object(
			array(
				'file'           => 'doc.pdf',
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
			)
		);
		$out2 = wp_get_ability( 'aafm/set-featured-image' )->execute(
			array(
				'post_id'       => $post,
				'attachment_id' => $pdf,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out2 );
		$this->assertFalse( has_post_thumbnail( $post ) );
	}

	public function test_set_featured_image_sets_an_image_attachment(): void {
		$this->acting_as( 'editor' );
		$post  = self::factory()->post->create();
		$image = self::factory()->attachment->create_object(
			array(
				'file'           => 'photo.png',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			)
		);

		$out = wp_get_ability( 'aafm/set-featured-image' )->execute(
			array(
				'post_id'       => $post,
				'attachment_id' => $image,
			)
		);

		$this->assertTrue( $out['set'] );
		$this->assertSame( $image, (int) get_post_thumbnail_id( $post ) );
	}


	/**
	 * (a) A caller without upload_files is denied, and the denial is audited.
	 */
	public function test_upload_media_requires_upload_files_and_audits_denial(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse(
			wp_get_ability( 'aafm/upload-media' )->check_permissions(
				array(
					'filename'    => 'pixel.png',
					'data_base64' => self::PNG_B64,
				)
			)
		);

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/upload-media', $abilities );
	}

	public function test_upload_media_author_is_allowed(): void {
		$this->acting_as( 'author' );
		$this->assertTrue(
			wp_get_ability( 'aafm/upload-media' )->check_permissions(
				array(
					'filename'    => 'pixel.png',
					'data_base64' => self::PNG_B64,
				)
			)
		);
	}


	/**
	 * (c) A valid small PNG is accepted and returns a redacted attachment with NO
	 * absolute server path.
	 */
	public function test_upload_media_accepts_valid_png_and_returns_redacted_attachment(): void {
		$this->acting_as( 'author' );
		$out = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 'pixel.png',
				'data_base64' => self::PNG_B64,
				'alt'         => 'a single pixel',
			)
		);

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'attachment_id', $out );
		$attachment_id = (int) $out['attachment_id'];
		$this->track_attachment_files( $attachment_id );

		$this->assertSame( 'image/png', get_post_mime_type( $attachment_id ) );
		$this->assertSame( 'a single pixel', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		// The output is the redacted media shape — public URL only, never an
		// absolute server path or the raw _wp_attached_file value.
		$this->assertArrayHasKey( 'media', $out );
		$this->assertArrayHasKey( 'url', $out['media'] );
		$this->assertSame( $attachment_id, $out['media']['id'] );

		$json     = wp_json_encode( $out );
		$basedir  = wp_upload_dir()['basedir'];
		$relative = get_post_meta( $attachment_id, '_wp_attached_file', true );

		$this->assertStringNotContainsString( $basedir, (string) $json );
		$this->assertStringNotContainsString( ABSPATH, (string) $json );
		$this->assertArrayNotHasKey( 'file', $out['media'] );
		$this->assertArrayNotHasKey( 'path', $out['media'] );
		if ( is_string( $relative ) && '' !== $relative ) {
			$this->assertStringNotContainsString( $relative, (string) $json );
		}
	}


	/**
	 * (b) SVG is rejected (XSS/script-capable) and NO file is written.
	 */
	public function test_upload_media_rejects_svg_and_writes_nothing(): void {
		$this->acting_as( 'author' );
		$before = $this->count_uploaded_files();

		// Building an SVG fixture to prove the upload rejects script-capable XML.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$svg = base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' );
		$out = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 'x.svg',
				'data_base64' => $svg,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( $before, $this->count_uploaded_files(), 'A rejected upload must not leave a file behind.' );
	}

	/**
	 * (b) A .php payload is rejected on its real (non-image) bytes and NO file is
	 * written — even though the caller named it shell.php with image intent.
	 */
	public function test_upload_media_rejects_php_payload_and_writes_nothing(): void {
		$this->acting_as( 'author' );
		$before = $this->count_uploaded_files();

		// Building a PHP-payload fixture to prove non-image bytes are rejected.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$php = base64_encode( "<?php echo 'pwned'; ?>" );
		$out = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 'shell.php',
				'data_base64' => $php,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( $before, $this->count_uploaded_files() );
	}

	/**
	 * Bad base64 is rejected before any write.
	 */
	public function test_upload_media_rejects_invalid_base64(): void {
		$this->acting_as( 'author' );
		$out = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 'pixel.png',
				'data_base64' => 'not really base64 @@@@',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}


	/**
	 * The supplied .jpg name is NOT trusted — PNG bytes are stored as the real
	 * type (png), proving the declared extension/mime can't drive the write.
	 */
	public function test_upload_media_normalizes_to_the_real_type_not_the_name(): void {
		$this->acting_as( 'author' );
		$out = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 'liar.jpg',
				'data_base64' => self::PNG_B64,
			)
		);

		$this->assertIsArray( $out );
		$attachment_id = (int) $out['attachment_id'];
		$this->track_attachment_files( $attachment_id );

		$this->assertSame( 'image/png', get_post_mime_type( $attachment_id ) );
		// The stored filename carries the canonical .png extension, never .jpg.
		$file = get_attached_file( $attachment_id );
		$this->assertStringEndsWith( '.png', (string) $file );
	}

	/**
	 * (d) A path-traversal filename is neutralized — the file lands inside the
	 * uploads dir under a sanitized name, never outside it.
	 */
	public function test_upload_media_neutralizes_path_traversal_filename(): void {
		$this->acting_as( 'author' );
		$out = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => '../../../../evil.png',
				'data_base64' => self::PNG_B64,
			)
		);

		$this->assertIsArray( $out );
		$attachment_id = (int) $out['attachment_id'];
		$this->track_attachment_files( $attachment_id );

		$file    = (string) get_attached_file( $attachment_id );
		$basedir = wp_upload_dir()['basedir'];

		// The realpath stays confined to the uploads basedir (no traversal escape).
		$this->assertStringStartsWith( $basedir, $file );
		$this->assertStringNotContainsString( '..', $file );
		// The basename was sanitized — no traversal segments survive.
		$this->assertSame( basename( $file ), wp_basename( $file ) );
		$this->assertStringContainsString( 'evil', basename( $file ) );
		$instance = get_post( $attachment_id );
		$this->assertInstanceOf( WP_Post::class, $instance );
	}

	public function test_update_media_is_in_registry_as_write(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/update-media', $registry );
		$this->assertSame( 'writes', $registry['aafm/update-media']['group'] );
		$this->assertSame( 'write', $registry['aafm/update-media']['risk'] );
	}

	public function test_update_media_writes_fields(): void {
		$this->acting_as( 'administrator' );
		$att = self::factory()->attachment->create_object(
			'edit-me.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);

		$out = wp_get_ability( 'aafm/update-media' )->execute(
			array(
				'attachment_id' => $att,
				'title'         => 'New Title',
				'alt'           => 'New Alt',
				'caption'       => 'New Caption',
				'description'   => 'New Description',
			)
		);

		$this->assertInstanceOf( WP_Post::class, get_post( $att ) );
		$this->assertSame( 'New Title', get_the_title( $att ) );
		$this->assertSame( 'New Alt', get_post_meta( $att, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'New Caption', get_post( $att )->post_excerpt );
		$this->assertSame( 'New Description', get_post( $att )->post_content );
		$this->assertSame( $att, $out['media']['id'] );
		$this->assertSame( 'New Title', $out['media']['title'] );
	}

	public function test_update_media_requires_at_least_one_field(): void {
		$this->acting_as( 'administrator' );
		$att = self::factory()->attachment->create_object(
			'noop.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		$out = wp_get_ability( 'aafm/update-media' )->execute( array( 'attachment_id' => $att ) );
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_update_media_denied_for_non_editor(): void {
		// Author cannot edit_post() an attachment owned by someone else.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$att   = self::factory()->attachment->create_object(
			'owned.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_author'    => $admin,
			)
		);
		$this->acting_as( 'author' );
		$this->assertFalse(
			wp_get_ability( 'aafm/update-media' )->check_permissions( array( 'attachment_id' => $att ) )
		);
	}

	public function test_update_media_rejects_non_attachment_id(): void {
		$this->acting_as( 'administrator' );
		$post = self::factory()->post->create();
		$this->assertFalse(
			wp_get_ability( 'aafm/update-media' )->check_permissions( array( 'attachment_id' => $post ) )
		);
	}

	public function test_delete_media_is_in_registry_as_destructive_write(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/delete-media', $registry );
		$this->assertSame( 'writes', $registry['aafm/delete-media']['group'] );
		$this->assertSame( 'destructive', $registry['aafm/delete-media']['risk'] );
	}

	public function test_delete_media_permanently_removes_attachment(): void {
		$this->acting_as( 'administrator' );
		$att = self::factory()->attachment->create_object(
			'gone.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);

		$out = wp_get_ability( 'aafm/delete-media' )->execute( array( 'attachment_id' => $att ) );

		$this->assertTrue( $out['deleted'] );
		$this->assertSame( $att, $out['attachment_id'] );
		$this->assertNull( get_post( $att ) );
	}

	public function test_delete_media_denied_for_non_owner(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$att   = self::factory()->attachment->create_object(
			'protected.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_author'    => $admin,
			)
		);
		$this->acting_as( 'author' );
		$this->assertFalse(
			wp_get_ability( 'aafm/delete-media' )->check_permissions( array( 'attachment_id' => $att ) )
		);
	}

	public function test_delete_media_rejects_non_attachment_id(): void {
		$this->acting_as( 'administrator' );
		$post = self::factory()->post->create();
		$this->assertFalse(
			wp_get_ability( 'aafm/delete-media' )->check_permissions( array( 'attachment_id' => $post ) )
		);
	}

	public function test_media_writes_never_leak_path_and_sanitize_html(): void {
		$this->acting_as( 'administrator' );
		$att = self::factory()->attachment->create_object(
			'adv.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		update_post_meta( $att, '_wp_attached_file', '2026/06/adv.jpg' );

		$out  = wp_get_ability( 'aafm/update-media' )->execute(
			array(
				'attachment_id' => $att,
				'description'   => 'safe <script>alert(1)</script> text',
			)
		);
		$json = (string) wp_json_encode( $out );

		// Description is wp_kses_post-sanitized: the script tag is stripped.
		$this->assertStringNotContainsString( '<script>', $json );

		// No server path / PII leaks in the rich write payload.
		$uploads = wp_get_upload_dir();
		$this->assertStringNotContainsString( $uploads['basedir'], $json );
		$this->assertStringNotContainsString( ABSPATH, $json );
		$this->assertStringNotContainsString( '_wp_attached_file', $json );
		$this->assertArrayNotHasKey( 'author_email', $out['media'] );
		$this->assertArrayNotHasKey( 'path', $out['media'] );
	}

	/**
	 * Count files currently under the uploads dir so a rejected upload can be
	 * proven to write nothing.
	 *
	 * @return int
	 */
	private function count_uploaded_files(): int {
		$basedir = wp_upload_dir()['basedir'];
		if ( ! is_dir( $basedir ) ) {
			return 0;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $basedir, \FilesystemIterator::SKIP_DOTS )
		);
		$count    = 0;
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				++$count;
			}
		}
		return $count;
	}
}
