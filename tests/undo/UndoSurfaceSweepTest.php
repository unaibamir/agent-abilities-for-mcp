<?php
/**
 * Every native writing tool is covered, refused with a reason, or self-recording; exactly one of them.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Undo;

use AAFM\Tests\TestCase;

final class UndoSurfaceSweepTest extends TestCase {

	/**
	 * Names of every registry ability that writes.
	 *
	 * @return list<string>
	 */
	private function writers(): array {
		$out = array();
		foreach ( aafm_get_abilities_registry_full() as $name => $definition ) {
			if ( 'read' !== $definition['risk'] ) {
				$out[] = (string) $name;
			}
		}
		return $out;
	}

	public function test_every_native_writing_ability_is_in_exactly_one_list(): void {
		$covered = array_keys( aafm_undo_surfaces() );
		$refused = array_keys( aafm_undo_refusals() );
		$self    = aafm_undo_self_recording();
		$this->assertSame( array(), array_values( array_intersect( $covered, $refused ) ) );
		$this->assertSame( array(), array_values( array_intersect( $covered, $self ) ) );
		$this->assertSame( array(), array_values( array_intersect( $refused, $self ) ) );
		foreach ( $this->writers() as $name ) {
			$this->assertCount( 1, array_filter( array( in_array( $name, $covered, true ), in_array( $name, $refused, true ), in_array( $name, $self, true ) ) ), "{$name} must be in exactly one list." );
		}
		foreach ( $covered as $name ) {
			$this->assertContains( $name, $this->writers(), "{$name} is covered but is not a registered writing ability." );
		}
	}

	public function test_covered_and_refused_counts_match_the_registry(): void {
		$writers = $this->writers();
		$this->assertCount( 24, aafm_undo_surfaces(), 'Run 1 covers 24 tools; AIOSEO joins in Task 5.' );
		$this->assertCount( count( $writers ) - 24, aafm_undo_refusals() );
		$this->assertSame( 'not_covered', aafm_undo_refusals()['aafm/aioseo-update-post'] );
	}

	public function test_every_input_property_of_a_covered_tool_is_handled(): void {
		$registry = aafm_get_abilities_registry_full();
		foreach ( aafm_undo_surfaces() as $name => $surface ) {
			$schema = $registry[ $name ]['args_builder']()['input_schema'] ?? array();
			$props  = array_keys( $schema['properties'] ?? array() );
			sort( $props );
			$handles = $surface['handles'];
			sort( $handles );
			$this->assertSame( $props, $handles, "{$name} must name every input property it understands." );
		}
	}

	public function test_every_reason_code_has_a_message(): void {
		$reasons = aafm_undo_reasons();
		foreach ( array_merge( array_values( aafm_undo_refusals() ), array_values( aafm_undo_named_refusals() ) ) as $code ) {
			$this->assertArrayHasKey( $code, $reasons );
		}
		foreach ( $reasons as $code => $message ) {
			$this->assertNotSame( '', $message, "{$code} needs a message." );
			$this->assertStringNotContainsString( '—', $message );
			$this->assertStringNotContainsString( '–', $message );
		}
	}

	public function test_every_create_surface_names_its_inverse_and_inverse_key(): void {
		foreach ( aafm_undo_surfaces() as $name => $surface ) {
			if ( 'post_create' !== $surface['family'] ) {
				continue;
			}
			$this->assertContains( $surface['inverse'] ?? '', array( 'aafm/trash-post', 'aafm/trash-page' ), $name );
			$this->assertContains( $surface['inverse_perm'] ?? '', array( 'post_id', 'page_id' ), $name );
		}
	}
}
