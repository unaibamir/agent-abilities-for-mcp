<?php
/**
 * Process-wide host stub for GeoDirectory (1.7.4 integration tests).
 *
 * Required once from the test bootstrap, but the actual post-type registration and function
 * definitions only happen when a test calls aafm_geodir_stub_activate() - mirroring
 * TecStubStore.php's own "not unconditionally at bootstrap" discipline, since defining a global
 * function is a one-way, process-wide operation that would otherwise leak into every later test.
 *
 * Codex final round 3 MEDIUM: this stub used to store fields as ordinary post meta, which meant
 * neither of the real geodir_get_post_info()'s two filter points ('geodir_post_info_query' on
 * the SQL, 'geodir_get_post_info' on the returned object - see includes/abilities/
 * geodirectory.php's own docblock) could be genuinely exercised against it, and a raw direct-DB
 * confirmation read (this plugin's own fix for the false-negative-rollback finding) had nothing
 * real to read. This stub now creates and uses the REAL detail table
 * ({$wpdb->prefix}geodir_gd_place_detail, same columns as the installed plugin's own schema-
 * creation code) and reproduces geodir_save_post_meta()'s exact raw-SQL-concatenation write and
 * geodir_get_post_info()'s exact two-filter read, so a test can exercise either filter for real.
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
	 * Registers gd_place, creates the real detail table, and defines the two GeoDirectory
	 * functions this plugin's ability file calls. Idempotent - safe to call from every
	 * GeoDirectory test's set_up().
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
			global $wpdb;
			$table = $wpdb->prefix . 'geodir_gd_place_detail';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- test-only fixture table, mirrors the installed plugin's own real schema (class-geodir-admin-install.php).
			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS {$table} (
					post_id BIGINT(20) NOT NULL,
					street VARCHAR(254) NULL,
					street2 VARCHAR(254) NULL,
					city VARCHAR(50) NULL,
					region VARCHAR(50) NULL,
					country VARCHAR(50) NULL,
					zip VARCHAR(50) NULL,
					latitude VARCHAR(22) NULL,
					longitude VARCHAR(22) NULL,
					PRIMARY KEY (post_id)
				)"
			);

			// phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.NewlineBeforeOpenBrace, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- mirrors GeoDirectory's own real function name/signature so the plugin under test calls a real, matching stand-in.
			function geodir_get_post_info( $post_id = '', $cached = true ) {
				global $wpdb;
				$post_id = (int) $post_id;
				if ( $post_id <= 0 ) {
					return false;
				}
				$table = $wpdb->prefix . 'geodir_gd_place_detail';
				// The real function applies these exact two filters (includes/post-functions.php)
				// - one on the QUERY before it runs, one on the returned object after - reproduced
				// here so a test can register either kind and prove
				// aafm_geodirectory_read_fields_unfiltered()'s direct table read genuinely bypasses
				// both, not merely that no test ever attached one.
				$query = apply_filters( 'geodir_post_info_query', $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query was already built through $wpdb->prepare() above; the filter may only reshape it, matching the real function.
				$row = $wpdb->get_row( $query, ARRAY_A );
				$row = is_array( $row ) ? $row : array();
				return apply_filters( 'geodir_get_post_info', (object) $row, $post_id );
			}
		}

		if ( ! function_exists( 'geodir_save_post_meta' ) ) {
			// phpcs:ignore Squiz.Functions.MultiLineFunctionDeclaration.NewlineBeforeOpenBrace, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- mirrors GeoDirectory's own real function name/signature.
			function geodir_save_post_meta( $post_id, $postmeta = '', $meta_value = '' ) {
				// Captured BEFORE the write below runs, so a test can inspect exactly what this
				// plugin's own code handed to the function - independent of how storage happens.
				aafm_geodir_stub_last_call( (int) $post_id, (string) $postmeta, $meta_value );
				// A test can force this ONE field to silently fail to persist, mirroring the real
				// function's own documented failure mode: its $wpdb->query() result is discarded,
				// so a genuine write failure returns nothing rather than false - proving that
				// aafm_geodirectory_write_fields()'s own read-back check is what actually catches
				// this, not geodir_save_post_meta()'s return value.
				if ( apply_filters( 'aafm_geodir_stub_simulate_write_failure', false, $postmeta ) ) {
					return null;
				}

				global $wpdb;
				$table   = $wpdb->prefix . 'geodir_gd_place_detail';
				$post_id = (int) $post_id;
				$column  = (string) $postmeta;
				$exists  = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE post_id = %d", $post_id ) );
				// Mirrors the real function's own raw-SQL-concatenation write EXACTLY
				// (post-functions.php): only post_id is prepared, $meta_value is concatenated
				// directly into the SQL string. A safer, auto-escaping $wpdb->update()/insert()
				// call here would double-escape a value this plugin's own code has already run
				// through esc_sql() - reproducing the real function's insecurity is the point,
				// since that is exactly the contract aafm_geodirectory_write_fields() is built
				// around (see this file's own docblock).
				if ( $exists ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- deliberately mirrors the real vendor function's own raw concatenation; see the comment above.
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET `{$column}` = '{$meta_value}' WHERE post_id = %d", $post_id ) );
				} else {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- deliberately mirrors the real vendor function's own raw concatenation; see the comment above.
					$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} SET post_id = %d, `{$column}` = '{$meta_value}'", $post_id ) );
				}
				return true;
			}
		}

		if ( ! function_exists( 'aafm_geodir_stub_last_call' ) ) {
			/**
			 * Records (or, with no arguments, returns) the most recent geodir_save_post_meta()
			 * call's raw arguments, so a test can inspect exactly what this plugin's own code
			 * passed in before the stub's own storage mechanism had a chance to alter it.
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
