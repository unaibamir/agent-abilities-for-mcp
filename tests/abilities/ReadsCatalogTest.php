<?php
/**
 * Phase 3 milestone: asserts the full 12-read catalog registers with the canonical
 * read-ability shape and that nothing is enabled by default.
 *
 * This is the drift-catcher for Phase 3 — if any read ability is missing, misnamed,
 * miscategorized, or registered without an input/output schema, permission callback,
 * or the readonly/non-destructive annotations, this test fails loudly here rather than
 * letting the gap reach the MCP server.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ReadsCatalogTest extends TestCase {

	/**
	 * The exact, complete set of read abilities Phase 3 must deliver.
	 *
	 * @var string[]
	 */
	private const READS = array(
		'aafm/get-posts',
		'aafm/get-post',
		'aafm/get-pages',
		'aafm/get-page',
		'aafm/get-terms',
		'aafm/get-taxonomies',
		'aafm/get-post-types',
		'aafm/get-site-info',
		'aafm/get-comments',
		'aafm/get-pending-comments',
		'aafm/get-media',
		'aafm/get-users',
	);

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to the
		// custom table, so it must exist before any ability is registered/invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * Core's wp_register_ability()/wp_register_ability_category() refuse to run unless
	 * their gated init action is doing_action(); simulate that by pushing the action name
	 * onto $wp_current_filter — the idiom WP core's own ability test trait uses. We do
	 * NOT call do_action() on the core hook directly: that trips the WPCS
	 * NonPrefixedHooknameFound sniff (Phase 1 carried issue).
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

	/**
	 * Enable every read and register categories + the enabled abilities.
	 */
	private function register_all_reads(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', self::READS );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_registry_contains_exactly_the_twelve_reads(): void {
		$registry = aafm_get_abilities_registry();

		$reads = array_keys(
			array_filter(
				$registry,
				static fn( array $entry ): bool => isset( $entry['group'] ) && 'reads' === $entry['group']
			)
		);

		sort( $reads );
		$expected = self::READS;
		sort( $expected );

		$this->assertSame(
			$expected,
			$reads,
			'The read group must be exactly the 12 Phase 3 reads — no more, no fewer.'
		);
		$this->assertCount( 12, $reads, 'Phase 3 ships exactly 12 read abilities.' );
	}

	public function test_each_read_is_in_the_registry_as_a_read(): void {
		$registry = aafm_get_abilities_registry();

		foreach ( self::READS as $name ) {
			$this->assertArrayHasKey( $name, $registry, $name . ' missing from registry' );
			$this->assertSame( 'reads', $registry[ $name ]['group'], $name . ' is not in the reads group' );
			$this->assertSame( 'read', $registry[ $name ]['risk'], $name . ' is not risk=read' );
			$this->assertArrayHasKey( 'args_builder', $registry[ $name ], $name . ' has no args_builder' );
			$this->assertTrue( is_callable( $registry[ $name ]['args_builder'] ), $name . ' args_builder is not callable' );
		}
	}

	public function test_every_read_registers_with_the_canonical_shape(): void {
		$this->register_all_reads();

		foreach ( self::READS as $name ) {
			$this->assertTrue( wp_has_ability( $name ), $name . ' did not register with the Abilities API' );

			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' could not be resolved' );

			// The MCP tool name is the hyphenated transform and must be a legal MCP name.
			$this->assertSame( str_replace( '/', '-', $name ), aafm_mcp_tool_name( $name ) );
			$this->assertMatchesRegularExpression(
				'/^[A-Za-z0-9_.-]+$/',
				aafm_mcp_tool_name( $name ),
				$name . ' does not map to a legal MCP tool name'
			);

			// input_schema declared (canonical reads always declare one so audited
			// arg_keys are populated) with additionalProperties locked closed.
			$input = $ability->get_input_schema();
			$this->assertIsArray( $input );
			$this->assertNotEmpty( $input, $name . ' has no input_schema' );
			$this->assertArrayHasKey( 'additionalProperties', $input, $name . ' input_schema is not closed' );
			$this->assertFalse( $input['additionalProperties'], $name . ' allows additionalProperties' );

			// output_schema is required by core — must be present and non-empty.
			$output = $ability->get_output_schema();
			$this->assertIsArray( $output );
			$this->assertNotEmpty( $output, $name . ' has no output_schema' );

			// Read annotations: readonly true, destructive false (destructive defaults TRUE).
			$annotations = $ability->get_meta_item( 'annotations' );
			$this->assertIsArray( $annotations, $name . ' has no annotations' );
			$this->assertTrue( $annotations['readonly'] ?? false, $name . ' is not annotated readonly' );
			$this->assertFalse( $annotations['destructive'] ?? true, $name . ' is not annotated non-destructive' );
		}
	}

	public function test_every_read_has_a_permission_gate(): void {
		$this->register_all_reads();

		// Anonymous (no current user) must pass through the wrapped permission_callback
		// and resolve to a real boolean/WP_Error — never a fatal, never a pass-by-absence.
		wp_set_current_user( 0 );

		foreach ( self::READS as $name ) {
			$result = wp_get_ability( $name )->check_permissions( array() );
			$this->assertTrue(
				is_bool( $result ) || is_wp_error( $result ),
				$name . ' permission_callback did not return bool|WP_Error'
			);
		}
	}

	public function test_nothing_is_enabled_by_default(): void {
		$this->assertSame( array(), aafm_get_enabled_abilities() );
	}

	public function test_disabled_reads_are_never_registered(): void {
		// The Abilities API registry is a process-wide singleton with no per-test reset, so a
		// prior test in this run may have left these registered. Clear them first, then prove
		// that a registration pass with an EMPTY enabled-list re-registers none of them —
		// the actual product guarantee ("never registered, not merely hidden").
		foreach ( self::READS as $name ) {
			if ( wp_has_ability( $name ) ) {
				wp_unregister_ability( $name );
			}
		}
		$this->assertSame( array(), aafm_get_enabled_abilities() );

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		foreach ( self::READS as $name ) {
			$this->assertFalse( wp_has_ability( $name ), $name . ' registered while disabled' );
		}
	}
}
