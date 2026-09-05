<?php
/**
 * Marker detection for aafm_post_has_foreign_builder_ownership().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

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
}
