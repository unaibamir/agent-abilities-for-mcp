<?php
/**
 * Phase 3 milestone: asserts the full read catalog registers with the canonical
 * read-ability shape and that nothing is enabled by default.
 *
 * This is the drift-catcher for the reads — if any read ability is missing, misnamed,
 * miscategorized, or registered without an input/output schema, permission callback,
 * or the readonly/non-destructive annotations, this test fails loudly here rather than
 * letting the gap reach the MCP server.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Fixtures\CatalogFixture;
use AAFM\Tests\TestCase;

final class ReadsCatalogTest extends TestCase {

	/**
	 * The exact, complete set of read abilities. Single source: CatalogFixture::READS.
	 *
	 * @var string[]
	 */
	private const READS = CatalogFixture::READS;

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to the
		// custom table, so it must exist before any ability is registered/invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Wave 4: integration abilities only contribute to the registry when their host
		// plugin is active. Force all three active (+ the mandatory registry-memo flush, the
		// registry is cached) so the SEO integration reads are counted here.
		add_filter( 'aafm_integration_active_yoast', '__return_true' );
		add_filter( 'aafm_integration_active_rankmath', '__return_true' );
		add_filter( 'aafm_integration_active_aioseo', '__return_true' );
		add_filter( 'aafm_integration_active_acf', '__return_true' );
		add_filter( 'aafm_integration_active_woocommerce', '__return_true' );
		aafm_registry_cache_should_flush( true );
	}

	/**
	 * Enable every read and register categories + the enabled abilities.
	 */
	private function register_all_reads(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', self::READS );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function test_registry_contains_exactly_the_expected_reads(): void {
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
			'The read group must be exactly the 81 reads — no more, no fewer.'
		);
		$this->assertCount( count( self::READS ), $reads, 'The read catalog ships exactly 81 read abilities.' );
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
