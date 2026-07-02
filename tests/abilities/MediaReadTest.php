<?php
/**
 * Media read ability: capability gating + inventory shape + path/PII redaction.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class MediaReadTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to the
		// custom table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Register categories + enabled abilities inside their gated init actions, simulated
		// by pushing the action name onto $wp_current_filter - the idiom WP core's own
		// ability test trait uses. wp_register_ability() refuses to run otherwise.
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-media', 'aafm/get-media-item', 'aafm/count-media' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_get_media_is_in_registry(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/get-media', $registry );
		$this->assertSame( 'reads', $registry['aafm/get-media']['group'] );
		$this->assertSame( 'read', $registry['aafm/get-media']['risk'] );
	}

	public function test_get_media_requires_upload_or_edit_cap(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( wp_get_ability( 'aafm/get-media' )->check_permissions( array() ) );

		$this->acting_as( 'author' );
		$this->assertTrue( wp_get_ability( 'aafm/get-media' )->check_permissions( array() ) );
	}

	public function test_get_media_returns_inventory_shape(): void {
		$this->acting_as( 'author' );
		$att = self::factory()->attachment->create_object(
			'pic.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);

		$out = wp_get_ability( 'aafm/get-media' )->execute( array() );
		$ids = wp_list_pluck( $out['media'], 'id' );
		$this->assertContains( $att, $ids );

		// Inventory item exposes only the safe public field set.
		$item = $out['media'][ array_search( $att, $ids, true ) ];
		$this->assertSame(
			array( 'id', 'title', 'mime_type', 'url', 'alt', 'width', 'height' ),
			array_keys( $item )
		);
		$this->assertSame( 'image/jpeg', $item['mime_type'] );
	}

	/**
	 * Security: the absolute server file path and uploader PII must never appear in
	 * the output. The public attachment URL is fine; the on-disk path is not.
	 */
	public function test_get_media_never_leaks_absolute_path_or_pii(): void {
		$author = $this->acting_as( 'author' );
		$att    = self::factory()->attachment->create_object(
			'secret-report.pdf',
			0,
			array(
				'post_mime_type' => 'application/pdf',
				'post_type'      => 'attachment',
				'post_author'    => $author,
			)
		);
		update_post_meta( $att, '_wp_attached_file', '2026/06/secret-report.pdf' );

		$out  = wp_get_ability( 'aafm/get-media' )->execute( array() );
		$json = (string) wp_json_encode( $out );

		// The absolute filesystem path (uploads basedir) must not leak.
		$uploads = wp_get_upload_dir();
		$this->assertStringNotContainsString( $uploads['basedir'], $json );
		$this->assertStringNotContainsString( ABSPATH, $json );

		// The raw relative _wp_attached_file path is internal - not surfaced.
		$this->assertStringNotContainsString( '_wp_attached_file', $json );

		// No author email/login fields ride along on the attachment record.
		$item = $out['media'][0];
		$this->assertArrayNotHasKey( 'author_email', $item );
		$this->assertArrayNotHasKey( 'author_login', $item );
		$this->assertArrayNotHasKey( 'path', $item );
		$this->assertArrayNotHasKey( 'file', $item );
	}

	public function test_get_media_search_filter_narrows_results(): void {
		$this->acting_as( 'author' );
		$needle = self::factory()->attachment->create_object(
			'unique-needle.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_title'     => 'UniqueNeedleTitle',
			)
		);
		self::factory()->attachment->create_object(
			'haystack.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_title'     => 'Haystack',
			)
		);

		$out = wp_get_ability( 'aafm/get-media' )->execute( array( 'search' => 'UniqueNeedleTitle' ) );
		$ids = wp_list_pluck( $out['media'], 'id' );

		$this->assertContains( $needle, $ids );
		$this->assertCount( 1, $out['media'] );
	}

	public function test_get_media_item_is_in_registry_as_read(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/get-media-item', $registry );
		$this->assertSame( 'reads', $registry['aafm/get-media-item']['group'] );
		$this->assertSame( 'read', $registry['aafm/get-media-item']['risk'] );
		$this->assertSame( 'media', $registry['aafm/get-media-item']['subject'] );
	}

	public function test_get_media_item_requires_upload_or_edit_cap(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( wp_get_ability( 'aafm/get-media-item' )->check_permissions( array() ) );

		$this->acting_as( 'author' );
		$this->assertTrue( wp_get_ability( 'aafm/get-media-item' )->check_permissions( array() ) );
	}

	public function test_get_media_item_returns_rich_shape(): void {
		$this->acting_as( 'author' );
		$parent = self::factory()->post->create();
		$att    = self::factory()->attachment->create_object(
			'rich.jpg',
			$parent,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_title'     => 'Rich Title',
				'post_excerpt'   => 'A caption.',
				'post_content'   => 'A long description.',
			)
		);
		wp_update_attachment_metadata(
			$att,
			array(
				'width'    => 4,
				'height'   => 4,
				'filesize' => 123,
			)
		);

		$out = wp_get_ability( 'aafm/get-media-item' )->execute( array( 'attachment_id' => $att ) );

		$this->assertSame(
			array( 'id', 'title', 'mime_type', 'url', 'alt', 'width', 'height', 'caption', 'description', 'date_gmt', 'filesize', 'parent', 'sizes' ),
			array_keys( $out['media'] )
		);
		$this->assertSame( $att, $out['media']['id'] );
		$this->assertSame( 'A caption.', $out['media']['caption'] );
		$this->assertSame( 'A long description.', $out['media']['description'] );
		$this->assertSame( $parent, $out['media']['parent'] );
		$this->assertSame( 123, $out['media']['filesize'] );
		$this->assertIsArray( $out['media']['sizes'] );
		$this->assertArrayHasKey( 'full', $out['media']['sizes'] );
	}

	public function test_get_media_item_unknown_id_errors(): void {
		$this->acting_as( 'author' );
		$post = self::factory()->post->create(); // A NON-attachment id.
		$out  = wp_get_ability( 'aafm/get-media-item' )->execute( array( 'attachment_id' => $post ) );
		$this->assertInstanceOf( \WP_Error::class, $out );
	}

	public function test_get_media_item_never_leaks_path_or_pii(): void {
		$author = $this->acting_as( 'author' );
		$att    = self::factory()->attachment->create_object(
			'rich-secret.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
				'post_author'    => $author,
			)
		);
		update_post_meta( $att, '_wp_attached_file', '2026/06/rich-secret.jpg' );

		$out  = wp_get_ability( 'aafm/get-media-item' )->execute( array( 'attachment_id' => $att ) );
		$json = (string) wp_json_encode( $out );

		$uploads = wp_get_upload_dir();
		$this->assertStringNotContainsString( $uploads['basedir'], $json );
		$this->assertStringNotContainsString( ABSPATH, $json );
		$this->assertStringNotContainsString( '_wp_attached_file', $json );
		$this->assertArrayNotHasKey( 'author_email', $out['media'] );
		$this->assertArrayNotHasKey( 'author_login', $out['media'] );
		$this->assertArrayNotHasKey( 'path', $out['media'] );
		$this->assertArrayNotHasKey( 'file', $out['media'] );
	}

	public function test_get_media_list_stays_lean(): void {
		// Adding the rich single-item read must NOT bloat the LIST shape.
		$this->acting_as( 'author' );
		self::factory()->attachment->create_object(
			'lean.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		$out = wp_get_ability( 'aafm/get-media' )->execute( array() );
		$this->assertSame(
			array( 'id', 'title', 'mime_type', 'url', 'alt', 'width', 'height' ),
			array_keys( $out['media'][0] )
		);
	}

	public function test_count_media_is_in_registry_as_read(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertArrayHasKey( 'aafm/count-media', $registry );
		$this->assertSame( 'reads', $registry['aafm/count-media']['group'] );
		$this->assertSame( 'read', $registry['aafm/count-media']['risk'] );
	}

	public function test_count_media_requires_upload_or_edit_cap(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( wp_get_ability( 'aafm/count-media' )->check_permissions( array() ) );

		$this->acting_as( 'author' );
		$this->assertTrue( wp_get_ability( 'aafm/count-media' )->check_permissions( array() ) );
	}

	public function test_count_media_totals_and_breaks_down_by_mime(): void {
		$this->acting_as( 'author' );
		self::factory()->attachment->create_object(
			'a.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		self::factory()->attachment->create_object(
			'b.png',
			0,
			array(
				'post_mime_type' => 'image/png',
				'post_type'      => 'attachment',
			)
		);

		$out = wp_get_ability( 'aafm/count-media' )->execute( array() );
		$this->assertGreaterThanOrEqual( 2, $out['total'] );
		// by_mime is an object (schema fidelity); inspect as an array.
		$this->assertIsObject( $out['by_mime'] );
		$by_mime = (array) $out['by_mime'];
		$this->assertArrayHasKey( 'image/jpeg', $by_mime );
		$this->assertGreaterThanOrEqual( 1, $by_mime['image/jpeg'] );
	}

	public function test_count_media_filters_by_mime_type(): void {
		$this->acting_as( 'author' );
		self::factory()->attachment->create_object(
			'only.png',
			0,
			array(
				'post_mime_type' => 'image/png',
				'post_type'      => 'attachment',
			)
		);

		$out = wp_get_ability( 'aafm/count-media' )->execute( array( 'mime_type' => 'image/png' ) );
		$this->assertGreaterThanOrEqual( 1, $out['total'] );
		$this->assertSame( array( 'image/png' ), array_keys( (array) $out['by_mime'] ) );
	}

	public function test_count_media_by_mime_is_object_when_empty(): void {
		$this->acting_as( 'author' );

		// A mime filter that matches zero attachments yields an empty breakdown.
		$out = wp_get_ability( 'aafm/count-media' )->execute( array( 'mime_type' => 'application/x-nonexistent' ) );

		$this->assertSame( 0, $out['total'] );
		// The schema declares by_mime as an object; an empty PHP array would JSON-encode
		// to "[]" (a JSON array), so the value must be cast to an object to encode as "{}".
		$this->assertIsObject( $out['by_mime'] );
		$this->assertStringContainsString( '"by_mime":{}', (string) wp_json_encode( $out ) );
	}

	public function test_media_discovery_floors(): void {
		// Reads: same floor as get-media (upload_files OR edit_posts). The reads have NO
		// discovery override - like get-media itself, they fall through to their real,
		// object-independent permission_callback, which already answers correctly here.
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/get-media-item' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/count-media' ) );

		$this->acting_as( 'author' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/get-media-item' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/count-media' ) );
		// Writes: object-independent authoring floor - author can upload/edit, so discoverable.
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-media' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/delete-media' ) );

		// A subscriber cannot discover the writes either.
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/update-media' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/delete-media' ) );
	}
}
