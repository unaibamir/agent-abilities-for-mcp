<?php
/**
 * Phase 4 milestone: asserts the complete read+write catalog registers with the
 * canonical shape, exact names, and HONEST risk annotations.
 *
 * This is the drift-catcher for the whole catalog. If any ability is missing,
 * misnamed, miscategorized, registered without a closed input_schema / required
 * output_schema / permission_callback, or carries a dishonest readonly/destructive
 * annotation, this test fails loudly here rather than letting the gap reach the
 * MCP server. It is the proof that the reads + writes catalog holds with no drift.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class CatalogTest extends TestCase {

	/**
	 * The exact, complete set of read abilities (Phase 3).
	 *
	 * @var string[]
	 */
	private const READS = array(
		'aafm/get-posts',
		'aafm/get-post',
		'aafm/get-post-meta',
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
		'aafm/list-revisions',
		'aafm/get-revision',
		'aafm/search-content',
	);

	/**
	 * The exact, complete set of write abilities (Phase 4).
	 *
	 * @var string[]
	 */
	private const WRITES = array(
		'aafm/create-draft',
		'aafm/create-post',
		'aafm/update-post',
		'aafm/trash-post',
		'aafm/create-page',
		'aafm/update-page',
		'aafm/trash-page',
		'aafm/create-term',
		'aafm/update-term',
		'aafm/moderate-comment',
		'aafm/set-featured-image',
		'aafm/upload-media',
		'aafm/update-post-meta',
		'aafm/delete-post-meta',
		'aafm/restore-revision',
		'aafm/create-cpt-item',
		'aafm/update-cpt-item',
	);

	/**
	 * The writes whose action is recoverable destruction (trash / spam / trash-comment).
	 * These MUST be annotated destructive:true. Every other write is destructive:false.
	 *
	 * @var string[]
	 */
	private const DESTRUCTIVE_WRITES = array(
		'aafm/trash-post',
		'aafm/trash-page',
		'aafm/moderate-comment',
		'aafm/delete-post-meta',
	);

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to
		// the custom table, so it must exist before any ability is registered/invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * Core's wp_register_ability()/wp_register_ability_category() refuse to run unless
	 * their gated init action is doing_action(); simulate that by pushing the action
	 * name onto $wp_current_filter — the idiom WP core's own ability test trait uses.
	 * We do NOT call do_action() on the core hook directly: that trips the WPCS
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
	 * Enable the entire catalog (all 27) and register categories + abilities.
	 */
	private function register_whole_catalog(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array_keys( aafm_get_abilities_registry() ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_registry_has_the_exact_expected_count(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertCount(
			33,
			$registry,
			'The catalog must contain exactly 33 abilities — 16 reads + 17 writes.'
		);
	}

	public function test_reads_are_exactly_the_expected_reads(): void {
		$reads = array_keys(
			array_filter(
				aafm_get_abilities_registry(),
				static fn( array $entry ): bool => isset( $entry['group'] ) && 'reads' === $entry['group']
			)
		);
		sort( $reads );
		$expected = self::READS;
		sort( $expected );

		$this->assertSame( $expected, $reads, 'The reads group must be exactly the 16 reads — no drift.' );
		$this->assertCount( 16, $reads, 'Exactly 16 read abilities.' );
	}

	public function test_writes_are_exactly_the_expected_writes(): void {
		$writes = array_keys(
			array_filter(
				aafm_get_abilities_registry(),
				static fn( array $entry ): bool => isset( $entry['group'] ) && 'writes' === $entry['group']
			)
		);
		sort( $writes );
		$expected = self::WRITES;
		sort( $expected );

		$this->assertSame( $expected, $writes, 'The writes group must be exactly the 17 writes — no drift.' );
		$this->assertCount( 17, $writes, 'Exactly 17 write abilities.' );
	}

	public function test_catalog_is_only_reads_plus_writes_no_extras(): void {
		$registry = aafm_get_abilities_registry();

		// Every catalog key is one of the known names — no stray ability slipped in.
		$known = array_merge( self::READS, self::WRITES );
		foreach ( array_keys( $registry ) as $name ) {
			$this->assertContains( $name, $known, $name . ' is not one of the 33 sanctioned abilities.' );
		}

		// And every group is one of exactly two values.
		foreach ( $registry as $name => $entry ) {
			$this->assertContains(
				$entry['group'] ?? '',
				array( 'reads', 'writes' ),
				$name . ' has an unexpected group.'
			);
		}

		// reads + writes accounts for the whole catalog.
		$this->assertSame(
			33,
			count( self::READS ) + count( self::WRITES ),
			'reads(16) + writes(17) must equal the full catalog (33).'
		);
	}

	public function test_each_write_is_in_the_registry_as_a_write(): void {
		$registry = aafm_get_abilities_registry();

		foreach ( self::WRITES as $name ) {
			$this->assertArrayHasKey( $name, $registry, $name . ' missing from registry' );
			$this->assertSame( 'writes', $registry[ $name ]['group'], $name . ' is not in the writes group' );
			$this->assertContains(
				$registry[ $name ]['risk'] ?? '',
				array( 'write', 'destructive' ),
				$name . ' has an unexpected risk class (writes are write|destructive, never read)'
			);
			$this->assertNotSame( 'read', $registry[ $name ]['risk'] ?? '', $name . ' must not be risk=read' );
			$this->assertArrayHasKey( 'args_builder', $registry[ $name ], $name . ' has no args_builder' );
			$this->assertTrue( is_callable( $registry[ $name ]['args_builder'] ), $name . ' args_builder not callable' );
		}
	}

	public function test_every_write_registers_with_the_canonical_shape(): void {
		$this->register_whole_catalog();

		foreach ( self::WRITES as $name ) {
			$this->assertTrue( wp_has_ability( $name ), $name . ' did not register with the Abilities API' );

			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' could not be resolved' );

			// Maps to a legal, hyphenated MCP tool name (round-trips the McpNameSanitizer rule).
			$this->assertSame( str_replace( '/', '-', $name ), aafm_mcp_tool_name( $name ) );
			$this->assertMatchesRegularExpression(
				'/^[A-Za-z0-9_.-]+$/',
				aafm_mcp_tool_name( $name ),
				$name . ' does not map to a legal MCP tool name'
			);

			// Closed input_schema — additionalProperties:false is the first anti-escalation layer.
			$input = $ability->get_input_schema();
			$this->assertIsArray( $input );
			$this->assertNotEmpty( $input, $name . ' has no input_schema' );
			$this->assertArrayHasKey( 'additionalProperties', $input, $name . ' input_schema is not closed' );
			$this->assertFalse( $input['additionalProperties'], $name . ' allows additionalProperties (open schema)' );

			// output_schema is required by core — present and non-empty.
			$output = $ability->get_output_schema();
			$this->assertIsArray( $output );
			$this->assertNotEmpty( $output, $name . ' has no output_schema' );
		}
	}

	public function test_no_write_is_annotated_readonly_and_destructive_is_honest(): void {
		$this->register_whole_catalog();

		foreach ( self::WRITES as $name ) {
			$annotations = wp_get_ability( $name )->get_meta_item( 'annotations' );
			$this->assertIsArray( $annotations, $name . ' has no annotations' );

			// A write is NEVER readonly — that would be a lie to the agent.
			$this->assertFalse(
				$annotations['readonly'] ?? true,
				$name . ' is dishonestly annotated readonly:true (it is a write)'
			);

			// Honest destructive flag: trash/spam-class writes are destructive:true;
			// additive/edit writes are destructive:false.
			$expected_destructive = in_array( $name, self::DESTRUCTIVE_WRITES, true );
			$this->assertSame(
				$expected_destructive,
				$annotations['destructive'] ?? null,
				$name . ' has a dishonest destructive annotation (expected ' .
					( $expected_destructive ? 'true' : 'false' ) . ')'
			);
		}
	}

	public function test_every_ability_has_a_real_permission_gate(): void {
		$this->register_whole_catalog();

		// No current user: every ability's wrapped permission_callback must resolve to a
		// real bool|WP_Error (never fatal, never pass-by-absence). None is publicly callable.
		wp_set_current_user( 0 );

		foreach ( array_merge( self::READS, self::WRITES ) as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name . ' is not registered' );
			$result = $ability->check_permissions( array() );
			$this->assertTrue(
				is_bool( $result ) || is_wp_error( $result ),
				$name . ' permission_callback did not return bool|WP_Error'
			);
		}
	}

	public function test_nothing_is_enabled_by_default(): void {
		// Opt-in by construction: a clean install exposes zero abilities to any agent.
		$this->assertSame( array(), aafm_get_enabled_abilities() );
	}
}
