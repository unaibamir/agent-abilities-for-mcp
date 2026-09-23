<?php
/**
 * The checked-read scope (aafm_with_checked_reads()): makes core's own metadata load
 * failure-aware for the life of a response builder, without changing a healthy read.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\Support\QueryFaultInjector;
use AAFM\Tests\TestCase;
use WP_Error;

final class CheckedReadScopeTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		QueryFaultInjector::reset_fired_count();
	}

	public function test_a_healthy_build_returns_the_same_array_as_running_outside_the_scope(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		wp_cache_delete( $post_id, 'post_meta' );

		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$outside = $build();

		wp_cache_delete( $post_id, 'post_meta' );
		$inside = aafm_with_checked_reads( $build, new WP_Error( 'aafm_error', 'unused' ) );

		$this->assertSame( $outside, $inside );
	}

	public function test_a_faulted_load_no_flush_returns_the_passed_error_and_caches_nothing(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		wp_cache_delete( $post_id, 'post_meta' );

		$error = new WP_Error( 'aafm_error', 'scope failed' );
		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = QueryFaultInjector::fail_query(
			$wpdb->postmeta,
			static function () use ( $build, $error ) {
				return aafm_with_checked_reads( $build, $error );
			}
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( $error, $result );
		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count() );
		$found = null;
		wp_cache_get( $post_id, 'post_meta', false, $found );
		$this->assertFalse( (bool) $found, 'a failed load must leave no cache entry for the object' );
	}

	public function test_a_faulted_load_real_error_returns_the_passed_error_and_caches_nothing(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		wp_cache_delete( $post_id, 'post_meta' );

		$error = new WP_Error( 'aafm_error', 'scope failed' );
		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$result = QueryFaultInjector::break_query_with_real_error(
			$wpdb->postmeta,
			static function () use ( $build, $error ) {
				return aafm_with_checked_reads( $build, $error );
			}
		);

		$this->assertSame( $error, $result );
		$this->assertGreaterThan( 0, QueryFaultInjector::fired_count() );
		$found = null;
		wp_cache_get( $post_id, 'post_meta', false, $found );
		$this->assertFalse( (bool) $found, 'a failed load must leave no cache entry for the object' );
	}

	public function test_a_faulted_load_under_suspended_cache_addition_still_fails_the_call(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		wp_cache_delete( $post_id, 'post_meta' );

		$error = new WP_Error( 'aafm_error', 'scope failed' );
		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$was_suspended = wp_suspend_cache_addition();
		wp_suspend_cache_addition( true );

		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		$result = QueryFaultInjector::fail_query(
			$wpdb->postmeta,
			static function () use ( $build, $error ) {
				return aafm_with_checked_reads( $build, $error );
			}
		);
		ob_end_clean();
		$wpdb->suppress_errors( $suppressed );

		wp_suspend_cache_addition( $was_suspended );

		$this->assertSame( $error, $result );
	}

	public function test_a_healthy_load_under_suspended_cache_addition_still_populates_the_cache(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		wp_cache_delete( $post_id, 'post_meta' );

		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$was_suspended = wp_suspend_cache_addition();
		wp_suspend_cache_addition( true );

		$result = aafm_with_checked_reads( $build, new WP_Error( 'aafm_error', 'unused' ) );

		wp_suspend_cache_addition( $was_suspended );

		$this->assertSame( array( 'value' => 'value' ), $result );
		$found = null;
		wp_cache_get( $post_id, 'post_meta', false, $found );
		$this->assertNotFalse( $found, 'the scope must use wp_cache_set(), which installs even while additions are suspended' );
	}

	public function test_an_already_cached_object_runs_no_load_query(): void {
		global $wpdb;
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'aafm_scope_key', 'value' );
		// Warm the cache with an ordinary read first, so the scope's own handler sees a hit.
		get_post_meta( $post_id, 'aafm_scope_key', true );

		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$queries = array();
		$track   = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $track );
		$result = aafm_with_checked_reads( $build, new WP_Error( 'aafm_error', 'unused' ) );
		remove_filter( 'query', $track );

		$this->assertSame( array( 'value' => 'value' ), $result );
		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( (string) $wpdb->postmeta, $query, 'a cached object must not trigger a load query' );
		}
	}

	public function test_a_non_null_value_from_another_callback_passes_through_untouched(): void {
		// Core's own update_meta_cache() (wp-includes/meta.php) casts the whole filter chain's
		// final $check to bool once any callback returns non-null, so the scope's own contribution
		// is proven by two invariants rather than by a value surviving that cast: it must not run
		// its own load query once another callback has already taken over, and it must not treat
		// that takeover as ITS OWN failure (the built result is not swapped for the passed error).
		global $wpdb;
		$post_id = self::factory()->post->create();
		wp_cache_delete( $post_id, 'post_meta' );

		add_filter(
			'update_post_metadata_cache',
			static function () {
				return true; // Another plugin claims the load already happened.
			},
			5,
			1
		);

		$error = new WP_Error( 'aafm_error', 'unused' );
		$build = static function () use ( $post_id ) {
			return array( 'value' => get_post_meta( $post_id, 'aafm_scope_key', true ) );
		};

		$queries = array();
		$track   = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $track );
		$result = aafm_with_checked_reads( $build, $error );
		remove_filter( 'query', $track );

		remove_all_filters( 'update_post_metadata_cache' );

		$this->assertNotSame( $error, $result, 'a foreign takeover must not be reported as the scope\'s own failure' );
		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( (string) $wpdb->postmeta, $query, 'the scope must not run its own load query once another callback already took over' );
		}
	}
}
