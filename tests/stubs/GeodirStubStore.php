<?php
/**
 * Process-wide host stub for GeoDirectory (1.7.4 integration tests).
 *
 * Required once from the test bootstrap, but the actual post-type registration and function
 * definitions only happen when a test calls aafm_geodir_stub_activate() - mirroring
 * TecStubStore.php's own "not unconditionally at bootstrap" discipline, since defining a global
 * function is a one-way, process-wide operation that would otherwise leak into every later test.
 *
 * The real geodir_get_post_info()/geodir_save_post_meta() read/write a per-post-type custom
 * database table (see includes/abilities/geodirectory.php's own docblock); this stub uses
 * ordinary post meta instead, since the abilities under test only depend on the documented
 * field set round-tripping, not on the real plugin's table layout.
 *
 * gd_place is registered with the SAME capability_type/map_meta_cap args the installed plugin
 * itself uses (verified 2026-09-05, includes/class-geodir-post-types.php:150,154), so
 * current_user_can() exercises this plugin's real capability mapping, not a hand-rolled model.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace {

	/**
	 * Registers gd_place and defines the two GeoDirectory functions this plugin's ability file
	 * calls. Idempotent - safe to call from every GeoDirectory test's set_up().
	 *
	 * @return void
	 */
	function aafm_geodir_stub_activate(): void {
		if ( ! post_type_exists( 'gd_place' ) ) {
			register_post_type(
				'gd_place',
				array(
					'public'          => true,
					'capability_type' => 'post',
					'map_meta_cap'    => true,
					'supports'        => array( 'title', 'editor' ),
				)
			);
		}

		if ( ! function_exists( 'geodir_get_post_info' ) ) {
			// phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.NewlineBeforeOpenBrace, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- mirrors GeoDirectory's own real function name/signature so the plugin under test calls a real, matching stand-in.
			function geodir_get_post_info( $post_id = '', $cached = true ) {
				$post_id = (int) $post_id;
				if ( $post_id <= 0 ) {
					return false;
				}
				$row = array();
				foreach ( array( 'street', 'street2', 'city', 'region', 'country', 'zip', 'latitude', 'longitude' ) as $field ) {
					$row[ $field ] = get_post_meta( $post_id, '_aafm_test_gd_' . $field, true );
				}
				return (object) $row;
			}
		}

		if ( ! function_exists( 'geodir_save_post_meta' ) ) {
			// phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.NewlineBeforeOpenBrace, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- mirrors GeoDirectory's own real function name/signature.
			function geodir_save_post_meta( $post_id, $postmeta = '', $meta_value = '' ) {
				// Captured BEFORE update_post_meta() runs: that function calls wp_unslash() on
				// $meta_value internally (wp-includes/meta.php), which would strip a caller's own
				// esc_sql() backslash before this stub's round trip ever reaches the DB - the real
				// geodir_save_post_meta() has no such unslashing (it concatenates raw into SQL), so
				// only this raw-argument capture, not the round-tripped value, can prove what this
				// plugin's own code actually handed to the function.
				aafm_geodir_stub_last_call( (int) $post_id, (string) $postmeta, $meta_value );
				update_post_meta( (int) $post_id, '_aafm_test_gd_' . $postmeta, $meta_value );
				return true;
			}
		}

		if ( ! function_exists( 'aafm_geodir_stub_last_call' ) ) {
			/**
			 * Records (or, with no arguments, returns) the most recent geodir_save_post_meta()
			 * call's raw arguments, so a test can inspect exactly what this plugin's own code
			 * passed in before the stub's own storage mechanism (ordinary post meta, unslashed by
			 * update_post_meta()) had a chance to alter it.
			 *
			 * @param int|null    $post_id  Listing post id, or null to read the last call.
			 * @param string|null $postmeta Column name.
			 * @param mixed       $value    Raw value as received.
			 * @return array{post_id:int,postmeta:string,value:mixed}|null
			 */
			function aafm_geodir_stub_last_call( ?int $post_id = null, ?string $postmeta = null, $value = null ) {
				static $last = null;
				if ( null === $post_id ) {
					return $last;
				}
				$last = array(
					'post_id'  => $post_id,
					'postmeta' => $postmeta,
					'value'    => $value,
				);
				return $last;
			}
		}
	}
}
