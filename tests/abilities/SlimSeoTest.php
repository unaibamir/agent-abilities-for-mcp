<?php
/**
 * Native Slim SEO integration: slim-seo-get-post, slim-seo-update-post.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class SlimSeoTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_slim_seo', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_slim_seo', '__return_true' );
		parent::tear_down();
	}

	public function test_get_post_reads_the_slim_seo_meta_array(): void {
		$post = self::factory()->post->create_and_get();
		update_post_meta(
			$post->ID,
			'slim_seo',
			array(
				'title'          => 'Custom title',
				'description'    => 'Custom description',
				'canonical'      => 'https://example.com/canonical',
				'noindex'        => true,
				'facebook_image' => 'https://example.com/fb.jpg',
				'twitter_image'  => 'https://example.com/tw.jpg',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = aafm_exec_slim_seo_get_post( array( 'post_id' => $post->ID ) );

		$this->assertSame( 'Custom title', $out['title'] );
		$this->assertSame( 'Custom description', $out['description'] );
		$this->assertSame( 'https://example.com/canonical', $out['canonical'] );
		$this->assertTrue( $out['noindex'] );
	}

	public function test_get_post_defaults_absent_fields_to_empty_and_false(): void {
		$post = self::factory()->post->create_and_get();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = aafm_exec_slim_seo_get_post( array( 'post_id' => $post->ID ) );

		foreach ( aafm_slim_seo_fields() as $field ) {
			$this->assertSame( '', $out[ $field ], "{$field} should default to an empty string." );
		}
		$this->assertFalse( $out['noindex'] );
	}

	public function test_update_post_writes_the_slim_seo_meta_array_and_preserves_untouched_fields(): void {
		$post = self::factory()->post->create_and_get();
		update_post_meta(
			$post->ID,
			'slim_seo',
			array(
				'title'       => 'Old title',
				'description' => 'Old description',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		aafm_exec_slim_seo_update_post(
			array(
				'post_id' => $post->ID,
				'title'   => 'New title',
			)
		);

		$stored = get_post_meta( $post->ID, 'slim_seo', true );
		$this->assertSame( 'New title', $stored['title'] );
		$this->assertSame( 'Old description', $stored['description'], 'A field not passed in this update must survive untouched, matching every sibling SEO integration\'s partial-update contract.' );
	}

	public function test_update_post_writes_noindex_and_url_fields(): void {
		$post = self::factory()->post->create_and_get();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = aafm_exec_slim_seo_update_post(
			array(
				'post_id'        => $post->ID,
				'noindex'        => true,
				'facebook_image' => 'https://example.com/new-fb.jpg',
			)
		);

		$stored = get_post_meta( $post->ID, 'slim_seo', true );
		$this->assertTrue( $stored['noindex'] );
		$this->assertSame( 'https://example.com/new-fb.jpg', $stored['facebook_image'] );
		$this->assertTrue( $out['noindex'] );
	}

	/**
	 * Codex hunt F4: a site-installed update_post_metadata filter that vetoes the write must
	 * surface as a structured error, not a success response carrying the stale stored value.
	 */
	public function test_update_post_returns_an_error_when_the_write_is_vetoed(): void {
		$post = self::factory()->post->create_and_get();
		update_post_meta( $post->ID, 'slim_seo', array( 'title' => 'Old title' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$veto = static fn() => false;
		add_filter( 'update_post_metadata', $veto, 10, 0 );
		$out  = aafm_exec_slim_seo_update_post(
			array(
				'post_id' => $post->ID,
				'title'   => 'New title',
			)
		);
		remove_filter( 'update_post_metadata', $veto, 10 );

		$this->assertInstanceOf(
			\WP_Error::class,
			$out,
			'A vetoed slim_seo meta write must return an error, not a success reporting the old value.'
		);
	}

	/**
	 * Codex round 8 R8-1: the canonical-form recomputation used to run sanitize_meta() against
	 * wp_slash( $stored ) and then unslash the sanitizer's OUTPUT, feeding a slash-sensitive
	 * registered sanitizer a backslash-quote sequence that core's own write-time call, which
	 * unslashes the incoming value BEFORE sanitizing, never sees.
	 */
	public function test_update_post_confirms_a_write_whose_title_contains_a_quote_and_backslash(): void {
		$post = self::factory()->post->create_and_get();

		$slash_sensitive = static function ( $value ) {
			if ( is_array( $value ) && isset( $value['title'] ) && is_string( $value['title'] ) && false !== strpos( $value['title'], "\\'" ) ) {
				$value['title'] = 'SAW_A_SLASHED_QUOTE';
			}
			return $value;
		};
		add_filter( 'sanitize_post_meta_slim_seo', $slash_sensitive );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = aafm_exec_slim_seo_update_post(
			array(
				'post_id' => $post->ID,
				'title'   => "O'Reilly",
			)
		);
		remove_filter( 'sanitize_post_meta_slim_seo', $slash_sensitive );

		$this->assertIsArray(
			$out,
			'A title containing a quote must not be misjudged as unconfirmed because the guard fed the sanitizer a slashed form the real write never used.'
		);
		$stored = get_post_meta( $post->ID, 'slim_seo', true );
		$this->assertSame(
			"O'Reilly",
			$stored['title'],
			"precondition: the sanitizer must not have fired, since core's own call never sees a slashed value here."
		);
		$this->assertSame( "O'Reilly", $out['title'] );
	}

	/**
	 * Codex round 7, R7-4: the string-field "nothing asked" fallback used to be judged from the
	 * raw $old alone ($intended_field === $old_field), blind to whether $old was already in its
	 * canonical (sanitized) form. Resubmitting a non-canonical title is a real ask - the write is
	 * still expected to land on the canonical form a genuinely different title would have to
	 * reach - so a persistence veto that instead keeps storage at the non-canonical title must not
	 * read as a confirmed no-op purely because the caller's literal input matched it.
	 */
	public function test_update_post_string_field_rejects_a_veto_that_blocks_canonicalization_of_a_same_value_resubmission(): void {
		$post = self::factory()->post->create_and_get();

		// Stored title is deliberately NOT canonical: written before the normalizer below is
		// registered.
		update_post_meta( $post->ID, 'slim_seo', array( 'title' => 'old title' ) );

		$normalize = static function ( $value ) {
			if ( is_array( $value ) && isset( $value['title'] ) && is_string( $value['title'] ) ) {
				$value['title'] = strtoupper( $value['title'] );
			}
			return $value;
		};
		add_filter( 'sanitize_post_meta_slim_seo', $normalize );

		// A persistence veto blocks the write outright, so storage never moves off the
		// non-canonical title - canonicalization included.
		$veto = static fn() => false;
		add_filter( 'update_post_metadata', $veto, 10, 0 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = aafm_exec_slim_seo_update_post(
			array(
				'post_id' => $post->ID,
				'title'   => 'old title',
			)
		);

		remove_filter( 'update_post_metadata', $veto, 10 );
		remove_filter( 'sanitize_post_meta_slim_seo', $normalize );

		$this->assertInstanceOf(
			\WP_Error::class,
			$out,
			'A veto that blocks the canonicalization of a same-value title resubmission must not be reported as a confirmed no-op.'
		);
		$stored = get_post_meta( $post->ID, 'slim_seo', true );
		$this->assertSame( 'old title', $stored['title'], 'precondition: the veto must have genuinely kept the non-canonical title in place.' );
	}

	/**
	 * Codex round 7, R7-4: the boolean noindex fallback carried the identical raw-$old defect as
	 * the string fields above.
	 */
	public function test_update_post_noindex_rejects_a_veto_that_blocks_canonicalization_of_a_same_value_resubmission(): void {
		$post = self::factory()->post->create_and_get();

		// Stored noindex is deliberately NOT canonical: written before the normalizer below is
		// registered, which always forces it true.
		update_post_meta( $post->ID, 'slim_seo', array( 'noindex' => false ) );

		$normalize = static function ( $value ) {
			if ( is_array( $value ) ) {
				$value['noindex'] = true;
			}
			return $value;
		};
		add_filter( 'sanitize_post_meta_slim_seo', $normalize );

		// A persistence veto blocks the write outright, so storage never moves off the
		// non-canonical false - canonicalization included.
		$veto = static fn() => false;
		add_filter( 'update_post_metadata', $veto, 10, 0 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = aafm_exec_slim_seo_update_post(
			array(
				'post_id' => $post->ID,
				'noindex' => false,
			)
		);

		remove_filter( 'update_post_metadata', $veto, 10 );
		remove_filter( 'sanitize_post_meta_slim_seo', $normalize );

		$this->assertInstanceOf(
			\WP_Error::class,
			$out,
			'A veto that blocks the canonicalization of a same-value noindex resubmission must not be reported as a confirmed no-op.'
		);
		$stored = get_post_meta( $post->ID, 'slim_seo', true );
		$this->assertFalse( $stored['noindex'], 'precondition: the veto must have genuinely kept the non-canonical false in place.' );
	}

	public function test_update_post_requires_edit_access(): void {
		$post = self::factory()->post->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse( aafm_perm_seo_post_object( array( 'post_id' => $post ) ) );
	}

	public function test_get_post_returns_generic_error_for_an_unknown_id(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$out = aafm_exec_slim_seo_get_post( array( 'post_id' => 999999 ) );

		$this->assertInstanceOf( \WP_Error::class, $out );
	}

	/**
	 * Bootstrap-wiring proof (Codex-review amendment 13): enabling the integration through its
	 * own detection seam must make the ability appear in the registry WITHOUT this test itself
	 * requiring includes/abilities/slim-seo.php - proving the plugin's own bootstrap require list
	 * wires the file, not merely that the file's functions work when manually loaded.
	 */
	public function test_slim_seo_abilities_register_through_the_normal_bootstrap(): void {
		$this->register_enabled( array( 'aafm/slim-seo-get-post', 'aafm/slim-seo-update-post' ) );

		$this->assertContains( 'aafm/slim-seo-get-post', aafm_all_server_ability_names() );
		$this->assertContains( 'aafm/slim-seo-update-post', aafm_all_server_ability_names() );
	}

	public function test_update_post_a_failed_baseline_read_writes_nothing_and_loses_no_setting(): void {
		global $wpdb;
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$post = self::factory()->post->create_and_get();
		update_post_meta(
			$post->ID,
			'slim_seo',
			array(
				'title'       => 'T',
				'description' => 'D',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out = \AAFM\Tests\Support\QueryFaultInjector::break_query_with_real_error(
			array( 'meta_key, meta_value FROM', $wpdb->postmeta, ' AND meta_key = ' ),
			static function () use ( $post ) {
				$suppressed = $GLOBALS['wpdb']->suppress_errors( true );
				$result     = aafm_exec_slim_seo_update_post(
					array(
						'post_id' => $post->ID,
						'title'   => 'New',
					)
				);
				$GLOBALS['wpdb']->suppress_errors( $suppressed );
				return $result;
			},
			1
		);

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_slim_seo_write_unconfirmed', $out->get_error_code() );
		$this->assertSame( 'read_failed', $out->get_error_data()['status'] );
		$this->assertSame(
			array(
				'title'       => 'T',
				'description' => 'D',
			),
			get_post_meta( $post->ID, 'slim_seo', true )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$detail = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detail FROM %i WHERE event_type = %s ORDER BY id DESC LIMIT 1', aafm_activity_log_table(), 'write_outcome' ) );
		$this->assertSame( 'read_failed', json_decode( $detail, true )['status'] );
	}

	public function test_update_post_an_array_valued_title_cleared_under_a_veto_is_an_error(): void {
		$post = self::factory()->post->create_and_get();
		update_post_meta( $post->ID, 'slim_seo', array( 'title' => array( 'x' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$veto = static fn() => false;
		add_filter( 'update_post_metadata', $veto, 10, 0 );
		$out  = aafm_exec_slim_seo_update_post(
			array(
				'post_id' => $post->ID,
				'title'   => '',
			)
		);
		remove_filter( 'update_post_metadata', $veto, 10 );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_slim_seo_write_unconfirmed', $out->get_error_code() );
	}

	public function test_slim_seo_write_meta_refuses_a_sub_field_outside_the_vendor_list(): void {
		$post = self::factory()->post->create_and_get();
		update_post_meta( $post->ID, 'slim_seo', array( 'title' => 'T' ) );

		$result = aafm_slim_seo_write_meta(
			$post->ID,
			array(
				'title'  => 'New',
				'robots' => 'noindex',
			)
		);

		$this->assertSame( array( 'status' => 'refused' ), $result );
		$this->assertSame( array( 'title' => 'T' ), get_post_meta( $post->ID, 'slim_seo', true ) );
	}

	public function test_slim_seo_sub_field_list_equals_the_fields_the_ability_builds(): void {
		$built  = array_merge( aafm_slim_seo_fields(), array( 'noindex' ) );
		$listed = aafm_slim_seo_subfields();
		sort( $built );
		sort( $listed );
		$this->assertSame( $built, $listed );
	}

	public function test_update_post_merges_onto_the_registered_default_when_no_row_is_stored(): void {
		register_post_meta(
			'post',
			'slim_seo',
			array(
				'single'  => true,
				'type'    => 'object',
				'default' => array( 'description' => 'Registered default' ),
			)
		);
		$post = self::factory()->post->create_and_get();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$out    = aafm_exec_slim_seo_update_post(
			array(
				'post_id' => $post->ID,
				'title'   => 'New',
			)
		);
		$stored = get_post_meta( $post->ID, 'slim_seo', true );
		unregister_post_meta( 'post', 'slim_seo' );

		$this->assertIsArray( $out );
		$this->assertSame( 'Registered default', $out['description'] );
		$this->assertSame(
			array(
				'description' => 'Registered default',
				'title'       => 'New',
			),
			$stored
		);
	}

	/**
	 * Both fault shapes.
	 *
	 * @return iterable<string,array{0:string}>
	 */
	public function data_fault_shapes(): iterable {
		yield 'no-flush' => array( 'no-flush' );
		yield 'real-error' => array( 'real-error' );
	}

	/**
	 * Update title only, with the checked-read scope's own post meta load failed once.
	 *
	 * @param int    $post_id Post id.
	 * @param string $shape   Fault shape.
	 * @return mixed
	 */
	private function update_title_with_the_merge_load_failed( int $post_id, string $shape ) {
		global $wpdb;
		\AAFM\Tests\Support\QueryFaultInjector::reset_fired_count();
		$needle     = array( 'meta_key, meta_value FROM `' . $wpdb->postmeta . '`', ' IN (' );
		$run        = static fn() => aafm_exec_slim_seo_update_post(
			array(
				'post_id' => $post_id,
				'title'   => 'New',
			)
		);
		$suppressed = $wpdb->suppress_errors( true );
		try {
			return 'no-flush' === $shape
				? \AAFM\Tests\Support\QueryFaultInjector::fail_nth_query( $needle, 1, $run )
				: \AAFM\Tests\Support\QueryFaultInjector::break_query_with_real_error( $needle, $run, 1 );
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}
	}

	/**
	 * Assert a read_failed error, the stored array untouched and exactly one read_failed row.
	 *
	 * @param mixed $out     The ability's return.
	 * @param int   $post_id Post id.
	 * @param int   $before  The newest activity-log id before the call.
	 */
	private function assert_merge_read_failed( $out, int $post_id, int $before ): void {
		global $wpdb;
		$this->assertSame( 1, \AAFM\Tests\Support\QueryFaultInjector::fired_count() );
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_slim_seo_write_unconfirmed', $out->get_error_code() );
		$this->assertSame( 'read_failed', $out->get_error_data()['status'] );
		$this->assertSame(
			array(
				'title'       => 'T',
				'description' => 'D',
			),
			get_post_meta( $post_id, 'slim_seo', true )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$details = $wpdb->get_col( $wpdb->prepare( 'SELECT detail FROM %i WHERE event_type = %s AND id > %d ORDER BY id', aafm_activity_log_table(), 'write_outcome', $before ) );
		$this->assertCount( 1, $details );
		$this->assertSame( 'read_failed', json_decode( (string) $details[0], true )['status'] );
	}

	/**
	 * A failed load of the merge input writes nothing and logs one read_failed row.
	 *
	 * @dataProvider data_fault_shapes
	 * @param string $shape Fault shape.
	 */
	public function test_update_post_a_failed_merge_load_writes_nothing_and_logs_read_failed( string $shape ): void {
		global $wpdb;
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$post = self::factory()->post->create_and_get();
		update_post_meta(
			$post->ID,
			'slim_seo',
			array(
				'title'       => 'T',
				'description' => 'D',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		wp_cache_delete( $post->ID, 'post_meta' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( MAX( id ), 0 ) FROM %i', aafm_activity_log_table() ) );

		$out = $this->update_title_with_the_merge_load_failed( $post->ID, $shape );

		$this->assert_merge_read_failed( $out, $post->ID, $before );
	}

	/**
	 * With the post's meta already cached, the merge load still reaches the database, so a failed
	 * load is still read_failed.
	 *
	 * @dataProvider data_fault_shapes
	 * @param string $shape Fault shape.
	 */
	public function test_update_post_a_failed_merge_load_is_read_failed_when_the_meta_was_cached( string $shape ): void {
		global $wpdb;
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$post = self::factory()->post->create_and_get();
		update_post_meta(
			$post->ID,
			'slim_seo',
			array(
				'title'       => 'T',
				'description' => 'D',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		get_post_meta( $post->ID );
		$this->assertNotFalse( wp_cache_get( $post->ID, 'post_meta' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( MAX( id ), 0 ) FROM %i', aafm_activity_log_table() ) );

		$out = $this->update_title_with_the_merge_load_failed( $post->ID, $shape );

		$this->assert_merge_read_failed( $out, $post->ID, $before );
	}

	public function test_a_refused_sub_field_logs_one_refused_row_for_the_slim_seo_key(): void {
		global $wpdb;
		add_action( 'aafm_write_completed', 'aafm_activity_log_write_outcome', PHP_INT_MIN, 2 );
		$post_id = self::factory()->post->create();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( MAX( id ), 0 ) FROM %i', aafm_activity_log_table() ) );

		$out = aafm_slim_seo_write_meta( $post_id, array( 'unknown_field' => 'x' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$details = $wpdb->get_col( $wpdb->prepare( 'SELECT detail FROM %i WHERE event_type = %s AND id > %d ORDER BY id', aafm_activity_log_table(), 'write_outcome', $before ) );
		$this->assertSame( array( 'status' => 'refused' ), $out );
		$this->assertSame( '', get_post_meta( $post_id, 'slim_seo', true ) );
		$this->assertCount( 1, $details );
		$row = (array) json_decode( (string) $details[0], true );
		$this->assertSame( array( 'kind', 'entity', 'object_id', 'key', 'status', 'rows', 'modified_by_site', 'key_omitted' ), array_keys( $row ) );
		$this->assertSame( array( 'refused', 'slim_seo', (string) $post_id ), array( $row['status'], $row['key'], (string) $row['object_id'] ) );
	}
}
