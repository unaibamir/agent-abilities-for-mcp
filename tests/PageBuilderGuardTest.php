<?php
/**
 * Marker detection for aafm_post_has_foreign_builder_ownership().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

use AAFM\Tests\Support\QueryFaultInjector;

final class PageBuilderGuardTest extends TestCase {

	private const UNKNOWN_OWNER_MESSAGE = 'This content may belong to a page builder, and the plugin could not tell which one, so it refused the write. Edit the content in the page builder directly, or try again.';

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

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
	}

	/**
	 * Run one guard call and count the queries that read the marker rows directly.
	 *
	 * @param int  $id        Post id.
	 * @param bool $pure_read Pass the pure-read flag.
	 * @return array{0: mixed, 1: int} The guard's answer and the raw marker query count.
	 */
	private function guard_counting_raw_reads( int $id, bool $pure_read = false ): array {
		$count   = 0;
		$counter = static function ( string $query ) use ( &$count ): string {
			if ( false !== strpos( $query, 'aafm_match_' ) ) {
				++$count;
			}
			return $query;
		};
		add_filter( 'query', $counter );
		try {
			$answer = $pure_read ? aafm_post_has_foreign_builder_ownership( $id, true ) : aafm_post_has_foreign_builder_ownership( $id );
		} finally {
			remove_filter( 'query', $counter );
		}
		return array( $answer, $count );
	}

	public function test_the_unknown_ownership_answer_is_the_string_unknown(): void {
		$this->assertTrue( defined( 'AAFM_BUILDER_OWNERSHIP_UNKNOWN' ) );
		$this->assertSame( 'unknown', constant( 'AAFM_BUILDER_OWNERSHIP_UNKNOWN' ) );
	}

	public function test_a_failed_marker_read_refuses_as_unknown(): void {
		$id = self::factory()->post->create();

		$answer = QueryFaultInjector::fail_query(
			'aafm_match_',
			static function () use ( $id ) {
				return aafm_post_has_foreign_builder_ownership( $id );
			}
		);

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertSame( 'unknown', $answer );
	}

	public function test_an_avada_marker_with_a_failed_marker_read_refuses_as_unknown(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'fusion_builder_status', 'active' );

		$answer = QueryFaultInjector::break_query_with_real_error(
			'aafm_match_',
			static function () use ( $id ) {
				return aafm_post_has_foreign_builder_ownership( $id );
			}
		);

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertSame( 'unknown', $answer, 'Unknown ownership beats a marker that did match.' );
	}

	public function test_avada_plus_visual_composer_refuses_as_unknown(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		update_post_meta( $id, 'vcv-pageContent', '[{"tag":"vcvpageroot"}]' );

		$this->assertSame( 'unknown', aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_both_avada_markers_count_as_one_builder(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		update_post_meta( $id, 'fusion_builder_converted', 'yes' );

		$this->assertSame( 'avada', aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_a_marker_supplied_only_by_a_read_filter_refuses(): void {
		$id     = self::factory()->post->create();
		$supply = static function ( $value, $object_id, $meta_key ) use ( $id ) {
			return ( $id === (int) $object_id && '_elementor_data' === $meta_key ) ? '[]' : $value;
		};
		add_filter( 'get_post_metadata', $supply, 10, 3 );
		try {
			$answer = aafm_post_has_foreign_builder_ownership( $id );
		} finally {
			remove_filter( 'get_post_metadata', $supply, 10 );
		}

		$this->assertSame( 'elementor', $answer );
	}

	public function test_a_stored_marker_that_a_read_filter_hides_still_refuses(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, '_elementor_data', '[{"id":"a1"}]' );
		$hide = static function ( $value, $object_id, $meta_key ) use ( $id ) {
			return ( $id === (int) $object_id && '_elementor_data' === $meta_key ) ? '' : $value;
		};
		add_filter( 'get_post_metadata', $hide, 10, 3 );
		try {
			$this->assertSame( '', get_post_meta( $id, '_elementor_data', true ), 'precondition: core no longer sees the marker' );
			$answer = aafm_post_has_foreign_builder_ownership( $id );
		} finally {
			remove_filter( 'get_post_metadata', $hide, 10 );
		}

		$this->assertSame( 'elementor', $answer );
	}

	public function test_a_marker_stored_under_another_spelling_refuses_as_unknown(): void {
		$id = self::factory()->post->create();
		add_metadata( 'post', $id, 'ET_PB_USE_BUILDER', 'on' );
		$this->assertSame( '', get_post_meta( $id, 'et_pb_use_builder', true ), 'precondition: core sees no row under the listed spelling' );

		$this->assertSame( 'unknown', aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_a_guard_call_reads_every_marker_in_one_query(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'et_pb_use_builder', 'on' );

		list( $answer, $count ) = $this->guard_counting_raw_reads( $id );

		$this->assertSame( 'divi', $answer );
		$this->assertSame( 1, $count );
	}

	public function test_an_empty_marker_map_is_no_ownership_and_reads_nothing(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, '_elementor_data', '[]' );
		add_filter( 'aafm_page_builder_markers', '__return_empty_array' );
		try {
			list( $answer, $count ) = $this->guard_counting_raw_reads( $id );
		} finally {
			remove_filter( 'aafm_page_builder_markers', '__return_empty_array' );
		}

		$this->assertFalse( $answer );
		$this->assertSame( 0, $count );
	}

	public function test_a_post_that_does_not_exist_has_no_ownership(): void {
		$this->assertFalse( aafm_post_has_foreign_builder_ownership( 999999 ) );
	}

	public function test_a_marker_held_only_in_the_meta_cache_refuses(): void {
		$id = self::factory()->post->create();
		update_meta_cache( 'post', array( $id ) );
		$cached = wp_cache_get( $id, 'post_meta' );
		$cached = is_array( $cached ) ? $cached : array();

		$cached['fusion_builder_status'] = array( 'active' );
		wp_cache_set( $id, $cached, 'post_meta' );

		$this->assertSame( 'avada', aafm_post_has_foreign_builder_ownership( $id ) );
	}

	public function test_pure_read_mode_keeps_the_first_marker_in_map_order(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		update_post_meta( $id, 'vcv-pageContent', '[{"tag":"vcvpageroot"}]' );

		list( $answer, $count ) = $this->guard_counting_raw_reads( $id, true );

		$this->assertSame( 'avada', $answer );
		$this->assertSame( 0, $count, 'Pure-read mode reads through core only.' );
	}

	public function test_pure_read_mode_reads_a_failed_load_as_no_ownership(): void {
		$id = self::factory()->post->create();
		update_post_meta( $id, 'fusion_builder_status', 'active' );
		wp_cache_delete( $id, 'post_meta' );

		$answer = QueryFaultInjector::break_query_with_real_error(
			array( 'SELECT post_id, meta_key, meta_value FROM', 'postmeta' ),
			static function () use ( $id ) {
				return aafm_post_has_foreign_builder_ownership( $id, true );
			},
			1
		);
		wp_cache_delete( $id, 'post_meta' );

		$this->assertSame( 1, QueryFaultInjector::fired_count() );
		$this->assertFalse( $answer );
	}

	public function test_owned_error_for_unknown_ownership_says_the_builder_could_not_be_determined(): void {
		$error = aafm_page_builder_owned_error( 'unknown' );
		$this->assertSame( 'aafm_page_builder_owned', $error->get_error_code() );
		$this->assertSame( array( 'status' => 409 ), $error->get_error_data() );
		$this->assertSame( self::UNKNOWN_OWNER_MESSAGE, $error->get_error_message() );
	}

	public function test_a_marker_whose_first_row_is_off_is_no_ownership(): void {
		$id = self::factory()->post->create();
		add_metadata( 'post', $id, 'et_pb_use_builder', 'off' );
		add_metadata( 'post', $id, 'et_pb_use_builder', 'on' );

		$this->assertFalse( aafm_post_has_foreign_builder_ownership( $id ) );
	}
}
