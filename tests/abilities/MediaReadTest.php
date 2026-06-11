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
		// by pushing the action name onto $wp_current_filter — the idiom WP core's own
		// ability test trait uses. wp_register_ability() refuses to run otherwise.
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-media' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
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

		// The raw relative _wp_attached_file path is internal — not surfaced.
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
}
