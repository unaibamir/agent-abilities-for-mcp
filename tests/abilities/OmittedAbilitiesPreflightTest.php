<?php
/**
 * Registration-time preflight bounds for the MCP server tool set.
 *
 * The tools/list path has two otherwise-unbounded cost centers - a per-tool permission loop and
 * the adapter's recursive schema serialization - either of which a pathological enabled+bridged
 * set can drive into an uncatchable memory/time fatal. The preflight (includes/server.php) omits
 * any ability whose schema breaches the measurement bounds and caps the total tool count, and it
 * must do both VISIBLY (activity-log row + admin notice) and without the measurement itself ever
 * being the thing that fatals.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class OmittedAbilitiesPreflightTest extends TestCase {

	/**
	 * A JsonSerializable whose jsonSerialize() always throws - the worst case a foreign schema can
	 * smuggle in. If the preflight ever let this reach wp_json_encode(), the throw would escape as the
	 * uncatchable fatal the preflight exists to prevent. The bounded walk must reject it as an unsafe
	 * value first, so jsonSerialize() is never called. Built as an anonymous class so the fixture lives
	 * with the test that needs it.
	 *
	 * @return \JsonSerializable
	 */
	private function throwing_json_serializable(): \JsonSerializable {
		return new class() implements \JsonSerializable {
			#[\ReturnTypeWillChange]
			public function jsonSerialize() {
				throw new \RuntimeException( 'jsonSerialize must never be reached by the preflight.' );
			}
		};
	}

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		delete_option( AAFM_OMITTED_ABILITIES_OPTION );
	}

	public function tear_down(): void {
		delete_option( AAFM_OMITTED_ABILITIES_OPTION );
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$slug = (string) $slug;
			if ( 0 === strncmp( $slug, 'demo/', 5 ) ) {
				wp_unregister_ability( $slug );
			}
		}
		parent::tear_down();
	}

	/**
	 * Register one fixture ability under the shared demo category, with the given schemas.
	 *
	 * @param string                   $name   Ability name, e.g. "demo/normal".
	 * @param array<string,mixed>      $input  Input schema.
	 * @param array<string,mixed>|null $output Output schema, or null to omit it.
	 * @return void
	 */
	private function register_ability( string $name, array $input, ?array $output = null ): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'demo-things' ) ) {
					wp_register_ability_category(
						'demo-things',
						array(
							'label'       => 'Demo things',
							'description' => 'Demo fixture category.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $name, $input, $output ): void {
				$args = array(
					'label'               => 'Fixture',
					'description'         => 'f',
					'category'            => 'demo-things',
					'input_schema'        => $input,
					'execute_callback'    => static fn() => array(),
					'permission_callback' => '__return_true',
				);
				if ( null !== $output ) {
					$args['output_schema'] = $output;
				}
				wp_register_ability( $name, $args );
			}
		);
	}

	/**
	 * A nested-object schema $levels array-levels deep, built iteratively (no recursion here, so the
	 * builder itself never overflows however deep we ask for).
	 *
	 * @param int $levels How many object wrappers to stack.
	 * @return array<string,mixed>
	 */
	private function deep_schema( int $levels ): array {
		$node = array( 'type' => 'string' );
		for ( $i = 0; $i < $levels; $i++ ) {
			$node = array(
				'type'       => 'object',
				'properties' => array( 'child' => $node ),
			);
		}
		return $node;
	}

	/**
	 * The measurement walk must be DEPTH-bounded: on a structure far deeper than the cap it returns
	 * a violation immediately instead of recursing to the bottom. The test completing at all is the
	 * proof it did not blow the stack or hang.
	 */
	public function test_measurement_walk_is_depth_bounded(): void {
		$nodes = 0;
		$this->assertSame(
			'schema_too_deep',
			aafm_schema_bounds_walk( $this->deep_schema( 5000 ), 0, $nodes ),
			'A 5000-level structure must trip the depth bound, not recurse to the bottom.'
		);
		$this->assertLessThanOrEqual(
			AAFM_SCHEMA_MAX_NODES,
			$nodes,
			'The walk must stop descending at the depth bound, so it visits only a handful of nodes on a deep-but-narrow structure.'
		);
	}

	/**
	 * The measurement walk must be NODE-bounded: a shallow but enormous structure trips the node cap
	 * rather than visiting every element.
	 */
	public function test_measurement_walk_is_node_bounded(): void {
		$flat  = array_fill( 0, AAFM_SCHEMA_MAX_NODES + 500, 'x' );
		$nodes = 0;
		$this->assertSame(
			'schema_too_many_nodes',
			aafm_schema_bounds_walk( $flat, 0, $nodes ),
			'A structure with more nodes than the cap must trip the node bound.'
		);
		$this->assertLessThanOrEqual( AAFM_SCHEMA_MAX_NODES + 1, $nodes, 'The walk must stop counting once it is over the cap.' );
	}

	/**
	 * A normal schema passes the preflight untouched: it stays in the tool set, nothing is recorded,
	 * and no notice fires. This is the guard that the bounds never trim a real install.
	 */
	public function test_normal_schema_passes_and_nothing_is_omitted(): void {
		$this->register_ability(
			'demo/normal',
			array(
				'type'       => 'object',
				'properties' => array(
					'id'    => array( 'type' => 'integer' ),
					'title' => array( 'type' => 'string' ),
					'meta'  => array(
						'type'       => 'object',
						'properties' => array( 'note' => array( 'type' => 'string' ) ),
					),
				),
			),
			array(
				'type'       => 'object',
				'properties' => array( 'ok' => array( 'type' => 'boolean' ) ),
			)
		);

		$kept = aafm_preflight_bound_server_tools( array( 'demo/normal' ) );

		$this->assertSame( array( 'demo/normal' ), $kept, 'A normal ability must stay in the tool set.' );
		$this->assertFalse( get_option( AAFM_OMITTED_ABILITIES_OPTION ), 'Nothing was omitted, so no option is written.' );
		$this->assertSame( array(), aafm_query_activity( array( 'ability' => 'demo/normal' ) ), 'A kept ability leaves no omission row.' );
	}

	/**
	 * An ability whose schema is nested past the depth cap is omitted from the tool set, recorded in
	 * the omission option, logged as an ability_omitted row, and named in the admin notice.
	 */
	public function test_deep_schema_ability_is_omitted_logged_and_noticed(): void {
		$this->register_ability( 'demo/deep', $this->deep_schema( 40 ) );

		$kept = aafm_preflight_bound_server_tools( array( 'demo/deep' ) );

		$this->assertNotContains( 'demo/deep', $kept, 'An over-deep schema must be kept out of the server.' );

		$stored = get_option( AAFM_OMITTED_ABILITIES_OPTION );
		$this->assertSame( array( 'demo/deep' => 'schema_too_deep' ), $stored, 'The omission and its reason must be persisted.' );

		$rows = aafm_query_activity( array( 'ability' => 'demo/deep' ) );
		$this->assertCount( 1, $rows, 'The omission must leave exactly one activity row.' );
		$this->assertSame( 'ability_omitted', $rows[0]['event_type'] );
		$this->assertSame( 'schema_too_deep', $rows[0]['detail'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		aafm_notice_omitted_abilities();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'demo/deep', $html, 'The notice must name the omitted ability.' );
		$this->assertStringContainsString( 'nested too deeply', $html, 'The notice must explain why it was omitted.' );
	}

	/**
	 * An ability whose schema is small in depth and node count but huge in serialized bytes is
	 * omitted with the size reason - byte size is bounded independently of the structural bounds.
	 */
	public function test_large_schema_ability_is_omitted(): void {
		// One property whose default is a single string longer than the byte cap: a handful of nodes,
		// shallow, but well over AAFM_SCHEMA_MAX_BYTES once serialized.
		$this->register_ability(
			'demo/large',
			array(
				'type'       => 'object',
				'properties' => array(
					'blob' => array(
						'type'    => 'string',
						'default' => str_repeat( 'a', AAFM_SCHEMA_MAX_BYTES + 1024 ),
					),
				),
			)
		);

		$kept = aafm_preflight_bound_server_tools( array( 'demo/large' ) );

		$this->assertNotContains( 'demo/large', $kept, 'An over-large schema must be kept out of the server.' );
		$this->assertSame( array( 'demo/large' => 'schema_too_large' ), get_option( AAFM_OMITTED_ABILITIES_OPTION ) );
	}

	/**
	 * When the enabled set exceeds the tool-count cap, the preflight registers up to the cap and
	 * omits the overflow with the tool_cap reason. Overflow names are capped BEFORE their schema is
	 * even measured, so they need not resolve to a registered ability.
	 */
	public function test_tool_count_cap_trims_and_logs_overflow(): void {
		$this->register_ability(
			'demo/ok',
			array(
				'type'       => 'object',
				'properties' => array(),
			)
		);

		// The cap's worth of a real, passing ability, then two overflow names.
		$names   = array_fill( 0, AAFM_MAX_SERVER_TOOLS, 'demo/ok' );
		$names[] = 'demo/overflow-a';
		$names[] = 'demo/overflow-b';

		$kept = aafm_preflight_bound_server_tools( $names );

		$this->assertCount( AAFM_MAX_SERVER_TOOLS, $kept, 'The tool set must be trimmed to exactly the cap.' );

		$stored = get_option( AAFM_OMITTED_ABILITIES_OPTION );
		$this->assertSame(
			array(
				'demo/overflow-a' => 'tool_cap',
				'demo/overflow-b' => 'tool_cap',
			),
			$stored,
			'Everything past the cap must be recorded as a tool_cap omission.'
		);

		$rows = aafm_query_activity( array( 'ability' => 'demo/overflow-a' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ability_omitted', $rows[0]['event_type'] );
		$this->assertSame( 'tool_cap', $rows[0]['detail'] );
	}

	/**
	 * A stable pathological config does not re-log on every pass, and once the operator fixes the
	 * offending ability the omission option is cleared so the notice goes away.
	 */
	public function test_omission_is_recorded_once_then_cleared_when_resolved(): void {
		$this->register_ability( 'demo/deep', $this->deep_schema( 40 ) );

		aafm_preflight_bound_server_tools( array( 'demo/deep' ) );
		aafm_preflight_bound_server_tools( array( 'demo/deep' ) );
		$this->assertCount(
			1,
			aafm_query_activity( array( 'ability' => 'demo/deep' ) ),
			'A second identical pass must not write a duplicate omission row.'
		);

		// The operator removes the offending ability from the enabled set.
		$kept = aafm_preflight_bound_server_tools( array() );
		$this->assertSame( array(), $kept );
		$this->assertFalse( get_option( AAFM_OMITTED_ABILITIES_OPTION ), 'A resolved omission set clears the option.' );
	}

	/**
	 * A deeply-nested stdClass graph $levels objects deep, built iteratively (no recursion in the
	 * builder, so it never overflows however deep we ask for). Proves objects are walked for the
	 * structural bounds exactly like arrays.
	 *
	 * @param int $levels How many stdClass wrappers to stack.
	 * @return \stdClass
	 */
	private function deep_object( int $levels ): \stdClass {
		$node = new \stdClass();
		for ( $i = 0; $i < $levels; $i++ ) {
			$wrapper        = new \stdClass();
			$wrapper->child = $node;
			$node           = $wrapper;
		}
		return $node;
	}

	/**
	 * FIX (1.7.2): the bounded walk must NOT reject a plain or empty object. A well-formed JSON Schema
	 * legitimately carries empty objects - a `"default": {}` value decodes to a stdClass, because PHP's
	 * array() encodes to [] and only an object encodes to {}. So an empty stdClass and a normal nested
	 * object are within bounds (the walk returns null); only a genuine resource is rejected outright.
	 * The walk still applies the depth and node bounds to objects, exactly as it does to arrays.
	 */
	public function test_walk_allows_objects_but_rejects_resources(): void {
		// An empty object - the exact live-bug shape (a `{}` default) - is one node and passes.
		$nodes = 0;
		$this->assertNull(
			aafm_schema_bounds_walk( new \stdClass(), 0, $nodes ),
			'An empty object is a valid JSON value and must not be a violation.'
		);
		$this->assertSame( 1, $nodes, 'An empty object contributes exactly one node and no children.' );

		// A normal object with array/scalar children walks into its subtree and passes.
		$normal = (object) array(
			'type'       => 'object',
			'properties' => array( 'note' => array( 'type' => 'string' ) ),
		);
		$nodes  = 0;
		$this->assertNull(
			aafm_schema_bounds_walk( $normal, 0, $nodes ),
			'A normal nested object is within bounds and must not be a violation.'
		);

		// A resource can never appear in a schema and cannot serialize: still rejected outright.
		$resource = stream_context_create();
		$nodes    = 0;
		$this->assertSame(
			'schema_unsafe_value',
			aafm_schema_bounds_walk( array( 'default' => $resource ), 0, $nodes ),
			'A resource cannot be represented in JSON and is an unsafe value.'
		);
	}

	/**
	 * FIX (1.7.2): objects are still bounded by depth and node caps, just like arrays. An object graph
	 * nested past the depth cap trips schema_too_deep; a flat object with more properties than the node
	 * cap trips schema_too_many_nodes. Objects are walked, not waved through.
	 */
	public function test_walk_bounds_objects_by_depth_and_nodes(): void {
		$nodes = 0;
		$this->assertSame(
			'schema_too_deep',
			aafm_schema_bounds_walk( $this->deep_object( 5000 ), 0, $nodes ),
			'A 5000-level object graph must trip the depth bound, not recurse to the bottom.'
		);

		$props = new \stdClass();
		for ( $i = 0; $i < AAFM_SCHEMA_MAX_NODES + 500; $i++ ) {
			$key           = 'p' . $i;
			$props->{$key} = 'x';
		}
		$nodes = 0;
		$this->assertSame(
			'schema_too_many_nodes',
			aafm_schema_bounds_walk( $props, 0, $nodes ),
			'An object with more properties than the node cap must trip the node bound.'
		);
	}

	/**
	 * Regression for the live bug (1.7.2): an ability whose input schema carries a stdClass empty
	 * object at `default` (a well-formed `"default": {}`) must NOT be omitted - it serializes to valid
	 * JSON and belongs in tools/list. Before the fix, the blanket object rejection dropped ~13 real
	 * bridged abilities (AIOSEO, WooCommerce, core-info) that carry exactly this shape.
	 */
	public function test_empty_object_default_schema_is_kept(): void {
		$this->register_ability(
			'demo/empty-object-default',
			array(
				'type'    => 'object',
				'default' => new \stdClass(),
			)
		);

		$kept = aafm_preflight_bound_server_tools( array( 'demo/empty-object-default' ) );

		$this->assertSame(
			array( 'demo/empty-object-default' ),
			$kept,
			'A schema with an empty-object default is well-formed and must stay in the tool set.'
		);
		$this->assertFalse(
			get_option( AAFM_OMITTED_ABILITIES_OPTION ),
			'Nothing was omitted, so no option is written.'
		);
	}

	/**
	 * FIX (1.7.2): an ability whose schema smuggles in a THROWING JsonSerializable is still OMITTED
	 * from the server with no fatal. The walk no longer rejects the object up front; instead the throw
	 * surfaces at wp_json_encode() in aafm_schema_bounds_violation(), where the try/catch (\Throwable)
	 * catches it and fails the ability closed as schema_unserializable. The preflight stays
	 * un-crashable even against a foreign schema built to defeat it.
	 */
	public function test_ability_with_throwing_serializable_is_omitted_not_fatal(): void {
		$this->register_ability(
			'demo/unsafe',
			array(
				'type'       => 'object',
				'properties' => array(
					'bad' => array(
						'type'    => 'string',
						'default' => $this->throwing_json_serializable(),
					),
				),
			)
		);

		$kept = aafm_preflight_bound_server_tools( array( 'demo/unsafe' ) );

		$this->assertNotContains( 'demo/unsafe', $kept, 'A schema whose object throws at encode must be kept out of the server.' );
		$this->assertSame(
			array( 'demo/unsafe' => 'schema_unserializable' ),
			get_option( AAFM_OMITTED_ABILITIES_OPTION ),
			'The throw is caught at wp_json_encode() and recorded as schema_unserializable.'
		);
	}

	/**
	 * FIX D (1.7.2): the cached preflight memoises its decision keyed by the enabled set, so an
	 * unchanged set on a later pass reuses the decision instead of re-walking every schema. Proven by
	 * mutating the SAME-named ability's schema out of bounds after the first pass: a cache hit still
	 * returns the old (kept) decision because it did not re-measure, while clearing the cache forces a
	 * cold walk that now omits it.
	 */
	public function test_cached_preflight_does_not_rewalk_an_unchanged_set(): void {
		$this->register_ability(
			'demo/normal',
			array(
				'type'       => 'object',
				'properties' => array(),
			)
		);

		$tools = array( 'demo/normal' );
		delete_transient( aafm_preflight_cache_key( $tools ) );

		// Cold pass: walks, keeps demo/normal, memoises the decision.
		$this->assertSame( array( 'demo/normal' ), aafm_preflight_bound_server_tools_cached( $tools ) );
		$this->assertNotFalse( get_transient( aafm_preflight_cache_key( $tools ) ), 'the decision is memoised for this set.' );

		// The same-named ability is now over-deep. A re-walk WOULD omit it; a cache hit reuses the
		// stored decision and keeps it, proving the expensive walk was skipped.
		wp_unregister_ability( 'demo/normal' );
		$this->register_ability( 'demo/normal', $this->deep_schema( 40 ) );

		$this->assertSame(
			array( 'demo/normal' ),
			aafm_preflight_bound_server_tools_cached( $tools ),
			'a cache hit reuses the decision without re-measuring the now-oversized schema.'
		);

		// Clearing the cache forces a fresh walk, which now omits it - so the cache was what kept it.
		delete_transient( aafm_preflight_cache_key( $tools ) );
		$this->assertSame(
			array(),
			aafm_preflight_bound_server_tools_cached( $tools ),
			'a cold walk omits the now-oversized schema.'
		);
		delete_transient( aafm_preflight_cache_key( $tools ) );
	}

	/**
	 * FIX D (1.7.2): a CHANGED enabled set uses a different cache key, so it recomputes rather than
	 * reading the previous set's decision. Adding an over-deep ability to the set omits it on the very
	 * next pass, with no stale carry-over from the smaller set.
	 */
	public function test_cached_preflight_recomputes_when_the_set_changes(): void {
		$this->register_ability(
			'demo/normal',
			array(
				'type'       => 'object',
				'properties' => array(),
			)
		);
		$this->register_ability( 'demo/deep', $this->deep_schema( 40 ) );

		$small = array( 'demo/normal' );
		$large = array( 'demo/normal', 'demo/deep' );
		delete_transient( aafm_preflight_cache_key( $small ) );
		delete_transient( aafm_preflight_cache_key( $large ) );

		$this->assertSame( array( 'demo/normal' ), aafm_preflight_bound_server_tools_cached( $small ) );

		// A different set -> a different key -> a fresh walk that omits the over-deep ability.
		$this->assertSame(
			array( 'demo/normal' ),
			aafm_preflight_bound_server_tools_cached( $large ),
			'the changed set recomputes and drops demo/deep, keeping only demo/normal.'
		);
		$this->assertSame(
			array( 'demo/deep' => 'schema_too_deep' ),
			get_option( AAFM_OMITTED_ABILITIES_OPTION ),
			'the changed-set pass records the new omission.'
		);

		delete_transient( aafm_preflight_cache_key( $small ) );
		delete_transient( aafm_preflight_cache_key( $large ) );
	}

	/**
	 * Drift guard: the option constant and the literal in aafm_config_option_names() (which
	 * uninstall.php reads without loading server.php) must name the same option, or a delete-data
	 * uninstall would leak the row.
	 */
	public function test_option_name_is_in_the_cleanup_list(): void {
		$this->assertSame( 'aafm_omitted_abilities', AAFM_OMITTED_ABILITIES_OPTION );
		$this->assertContains( AAFM_OMITTED_ABILITIES_OPTION, aafm_config_option_names() );
	}
}
