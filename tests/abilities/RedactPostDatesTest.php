<?php
/**
 * Draft GMT dates: WordPress stores a zero post_date_gmt (and, on a new draft, a zero
 * post_modified_gmt) until the post is published. Core REST reports get_gmt_from_date() of the local
 * value instead, and so do the post and revision shapes here. Stored non-zero dates pass through
 * byte for byte.
 *
 * The site runs at UTC+5 in these tests so a local date and its GMT form differ.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class RedactPostDatesTest extends TestCase {

	private const ZERO = '0000-00-00 00:00:00';

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 5 );
	}

	public function test_a_new_draft_reports_real_gmt_dates(): void {
		$this->acting_as( 'administrator' );
		$res = aafm_exec_create_page(
			array(
				'title'   => 'Draft dates',
				'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
				'status'  => 'draft',
			)
		);
		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : 'not an array' );

		$stored = get_post( (int) $res['post']['id'] );
		$this->assertSame( self::ZERO, $stored->post_date_gmt, 'Fixture: core stores a zero GMT date on a draft.' );
		$this->assertSame( get_gmt_from_date( $stored->post_date ), $res['post']['date_gmt'] );
		$this->assertSame( get_gmt_from_date( $stored->post_modified ), $res['post']['modified_gmt'] );
		$this->assertNotSame( $stored->post_date, $res['post']['date_gmt'], 'The GMT value is converted, not the local one.' );
	}

	public function test_a_revision_of_a_draft_reports_real_gmt_dates(): void {
		global $wpdb;
		$draft    = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$revision = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $draft,
			)
		);
		// A revision of a draft can carry the parent's zero GMT dates.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture: exact stored dates.
			$wpdb->posts,
			array(
				'post_date_gmt'     => self::ZERO,
				'post_modified_gmt' => self::ZERO,
			),
			array( 'ID' => $revision )
		);
		clean_post_cache( $revision );
		$rev = get_post( $revision );

		$shape = aafm_redact_revision( $rev );

		$this->assertSame( get_gmt_from_date( $rev->post_date ), $shape['date_gmt'] );
		$this->assertSame( get_gmt_from_date( $rev->post_modified ), $shape['modified_gmt'] );
	}

	public function test_a_draft_reusable_block_reports_a_real_gmt_date(): void {
		$id    = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'draft',
			)
		);
		$block = get_post( $id );
		$this->assertSame( self::ZERO, $block->post_date_gmt, 'Fixture: core stores a zero GMT date on a draft.' );

		$this->assertSame( self::ZERO, $block->post_modified_gmt, 'Fixture: core copies the zero GMT date into post_modified_gmt on insert.' );

		$this->assertSame( get_gmt_from_date( $block->post_date ), aafm_rich_block( $block )['date'] );
		$this->assertSame( get_gmt_from_date( $block->post_modified ), aafm_rich_block( $block )['modified'] );
	}

	public function test_a_published_post_keeps_its_stored_gmt_dates(): void {
		global $wpdb;
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		// Stored values that get_gmt_from_date() of the local dates would NOT produce, so any
		// conversion of a non-zero value shows up.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture: exact stored dates.
			$wpdb->posts,
			array(
				'post_date'         => '2026-01-02 10:00:00',
				'post_date_gmt'     => '2026-01-02 03:04:05',
				'post_modified'     => '2026-01-03 10:00:00',
				'post_modified_gmt' => '2026-01-03 06:07:08',
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );

		$shape = aafm_redact_post( get_post( $id ) );

		$this->assertSame( '2026-01-02 03:04:05', $shape['date_gmt'] );
		$this->assertSame( '2026-01-03 06:07:08', $shape['modified_gmt'] );
	}
}
