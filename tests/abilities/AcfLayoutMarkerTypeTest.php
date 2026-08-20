<?php
/**
 * The flexible-content layout marker must reach ACF as the same string our own guard validated.
 *
 * R8C-8's sibling in shape and R5-1's sibling in consequence. The layout guard casts the marker to
 * a string before its strict membership test; ACF's get_layout() compares $layout['name'] === $name
 * with no cast at all. While the sanitizer preserved integer leaves, a JSON number sent as
 * acf_fc_layout against a layout genuinely named in digits satisfied our guard and could not be
 * resolved by ACF, which takes the destructive branch: the row's stored sub-field values deleted,
 * the unusable marker written in their place, a generic read-back failure reported afterwards.
 *
 * Numerically named layouts are ordinary. A year, a version, a step number in a page builder.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class AcfLayoutMarkerTypeTest extends TestCase {

	/**
	 * A flexible-content definition declaring one layout whose name is digits.
	 *
	 * @return array<string,mixed>
	 */
	private function def(): array {
		return array(
			'key'     => 'field_steps',
			'name'    => 'steps',
			'type'    => 'flexible_content',
			'layouts' => array(
				array(
					'key'        => 'layout_2024',
					'name'       => '2024',
					'sub_fields' => array(
						array(
							'key'   => 'field_steps_note',
							'name'  => 'note',
							'_name' => 'note',
							'type'  => 'text',
						),
					),
				),
			),
		);
	}

	/**
	 * Every scalar spelling of a declared numeric layout name, and what must leave the sanitizer.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public static function provide_markers(): array {
		return array(
			'string as sent' => array( '2024', '2024' ),
			'JSON number'    => array( 2024, '2024' ),
			'JSON float'     => array( 2024.0, '2024' ),
			'numeric string' => array( '0', '0' ),
			'boolean true'   => array( true, '1' ),
			'boolean false'  => array( false, '' ),
		);
	}

	/**
	 * The marker leaves the sanitizer as a string whatever type it arrived as.
	 *
	 * Strict comparison throughout: the whole defect is that 2024 and '2024' compare loosely equal
	 * and strictly unequal, and ACF compares strictly. A loose assertion here would pass against
	 * the broken code and pin nothing.
	 *
	 * @dataProvider provide_markers
	 *
	 * @param mixed  $sent     The marker as the caller sent it.
	 * @param string $expected The marker as ACF must receive it.
	 */
	public function test_marker_is_normalised_to_a_string( $sent, string $expected ): void {
		$clean = aafm_acf_sanitize_value(
			array(
				array(
					'acf_fc_layout' => $sent,
					'note'          => 'N',
				),
			),
			'field_steps',
			$this->def()
		);

		$this->assertIsArray( $clean );
		$this->assertArrayHasKey( 0, $clean );
		$this->assertIsArray( $clean[0] );
		$this->assertArrayHasKey( 'acf_fc_layout', $clean[0] );
		$this->assertSame( $expected, $clean[0]['acf_fc_layout'] );
	}

	/**
	 * The guard and ACF now agree, which is the property the fix exists to restore.
	 *
	 * A declared numeric layout sent as a number is accepted by the guard AND is strictly identical
	 * to the declared name by the time it would reach update_field(). Before the fix the first half
	 * held and the second did not, which is precisely how a write got accepted and then destroyed
	 * the row it was written to.
	 */
	public function test_an_accepted_numeric_marker_strictly_matches_the_declared_name(): void {
		$def   = $this->def();
		$clean = aafm_acf_sanitize_value(
			array(
				array(
					'acf_fc_layout' => 2024,
					'note'          => 'N',
				),
			),
			'field_steps',
			$def
		);

		$bad = array();
		aafm_acf_unresolved_sub_addresses( $def, $clean, 'steps', $bad );

		$this->assertSame( array(), $bad, 'A declared layout must not be reported unresolvable.' );
		$this->assertContains(
			$clean[0]['acf_fc_layout'],
			aafm_acf_declared_layout_names( $def ),
			'in_array strict: what ACF receives must be one of the declared names, not merely equal to one.'
		);
	}

	/**
	 * Normalising the type must not start letting through markers that are refused today.
	 *
	 * A number is now spelled as a string, so the risk is that a number the field does not declare
	 * finds some declared name to land on. It must not: a fix for a destructive write has no
	 * business widening what gets through, and an undeclared marker is still an undeclared marker
	 * whatever type it arrived as.
	 */
	public function test_an_undeclared_numeric_marker_is_still_refused(): void {
		$def   = $this->def();
		$clean = aafm_acf_sanitize_value(
			array(
				array(
					'acf_fc_layout' => 1999,
					'note'          => 'N',
				),
			),
			'field_steps',
			$def
		);

		$bad = array();
		aafm_acf_unresolved_sub_addresses( $def, $clean, 'steps', $bad );

		$this->assertSame( array( 'steps.0' ), $bad );
	}

	/**
	 * A declared name with a trailing space is still trimmed and ACCEPTED.
	 *
	 * This is the direction that keeps the fix honest, and it is not a free choice: an append-only
	 * corpus row (AcfUnknownSubFieldFloorTest, "flex, layout name needing a trim") already pins it.
	 * The first version of this fix returned the cast marker early and skipped the plain-text
	 * normalisation that row depends on, which turned a working write into a refusal. The suite
	 * caught it. Keeping the row here as well means a future rewrite of the marker handling fails
	 * against the type case and the trim case together, rather than trading one for the other.
	 */
	public function test_a_declared_name_with_trailing_space_is_still_accepted(): void {
		$def   = $this->def();
		$clean = aafm_acf_sanitize_value(
			array(
				array(
					'acf_fc_layout' => '2024 ',
					'note'          => 'N',
				),
			),
			'field_steps',
			$def
		);

		$this->assertSame( '2024', $clean[0]['acf_fc_layout'], 'The existing plain-text pass must still trim the marker.' );

		$bad = array();
		aafm_acf_unresolved_sub_addresses( $def, $clean, 'steps', $bad );

		$this->assertSame( array(), $bad );
	}
}
