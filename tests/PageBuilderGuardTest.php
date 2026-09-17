<?php
/**
 * Marker detection for aafm_post_has_foreign_builder_ownership().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use AAFM\Tests\Support\QueryFaultInjector;
use WP_Error;

final class PageBuilderGuardTest extends TestCase {

	public function test_elementor_marker_is_detected(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, '_elementor_data', '[]' );
		$this->assertSame( 'elementor', aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_divi_marker_is_detected(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'et_pb_use_builder', 'on' );
		$this->assertSame( 'divi', aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_beaver_builder_marker_is_detected(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, '_fl_builder_data', array() );
		$this->assertSame( 'beaver-builder', aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_avada_marker_is_detected(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		$this->assertSame( 'avada', aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_avada_converted_marker_is_detected(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'fusion_builder_converted', 'yes' );
		$this->assertSame( 'avada', aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_visual_composer_marker_is_detected(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'vcv-pageContent', '[{"tag":"vcvpageroot"}]' );
		$this->assertSame( 'visual-composer', aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_a_plain_post_has_no_builder_ownership(): void {
		$id = self::factory()->post->create();
		$this->assertFalse( aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_divi_marker_set_to_off_is_not_ownership(): void {
		// et_pb_use_builder can legitimately be present but set to a falsy value on a post the
		// builder was toggled OFF for; only a truthy/'on' value means the builder currently owns
		// this content.
		$id = self::factory()->post->create();
		update_post_meta( $id, 'et_pb_use_builder', 'off' );
		$this->assertFalse( aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_the_filter_can_add_a_marker(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, '_optimizepress_marker', '1' );

		$add = static function ( array $markers ): array {
			$markers['_optimizepress_marker'] = 'optimizepress';
			return $markers;
		};
		add_filter( 'aafm_page_builder_markers', $add );
		try {
			$this->assertSame( 'optimizepress', aafm_post_has_foreign_builder_ownership( $id ) );
		} finally {
			remove_filter( 'aafm_page_builder_markers', $add );
		}
	}

	public function test_owned_error_names_the_builder(): void {
		$error = aafm_page_builder_owned_error( 'beaver-builder' );
		$this->assertSame( 'aafm_page_builder_owned', $error->get_error_code() );
		$this->assertStringContainsString( 'Beaver Builder', $error->get_error_message() );
	}

	/**
	 * This is a live-authorization-shaped check ("is this write currently allowed"), not a
	 * certifying one - a failed marker read must never be treated the same as a marker confirmed
	 * absent, or a database hiccup on the last marker checked lets a builder-owned write through.
	 */
	public function test_ownership_check_fails_closed_when_a_markers_read_fails(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'vcv-pageContent', '[{"tag":"vcvpageroot"}]' );

		global $wpdb;
		$result = QueryFaultInjector::break_query_with_real_error(
			array( $wpdb->postmeta, "meta_key = 'vcv-pageContent'" ),
			static fn() => aafm_post_has_foreign_builder_ownership( $id ),
			1
		);

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'A failed marker read must not be read as "no builder owns this" - it must refuse, not allow.'
		);
	}

	/**
	 * The unknown-ownership state must actually reach a real content-write call site, not just the
	 * detector function in isolation.
	 */
	public function test_update_post_refuses_a_content_write_when_ownership_cannot_be_determined(): void {
		$admin_id = $this->acting_as( 'administrator' );
		$id       = (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
		update_post_meta( $id, 'vcv-pageContent', '[{"tag":"vcvpageroot"}]' );

		global $wpdb;
		$result = QueryFaultInjector::break_query_with_real_error(
			array( $wpdb->postmeta, "meta_key = 'vcv-pageContent'" ),
			static fn() => aafm_exec_update_post(
				array(
					'post_id' => $id,
					'content' => 'New content',
				)
			),
			1
		);

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'A content write must be refused, not silently allowed, when page-builder ownership could not be determined.'
		);
	}
}
