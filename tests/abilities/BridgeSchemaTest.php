<?php
/**
 * Bridge schema normalizer: a foreign schema must survive core's own validator untouched.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class BridgeSchemaTest extends TestCase {

	/**
	 * Every empty-schema position must survive core's own validator. Before 1.6.1's revert, seven of
	 * these coerced to stdClass and fataled inside rest_validate_value_from_schema(), because core
	 * reads schema nodes with array syntax and that is an Error against a non-ArrayAccess object.
	 *
	 * @dataProvider empty_schema_positions
	 *
	 * @param array<string,mixed> $raw                   The foreign schema as declared.
	 * @param mixed               $value                 A value to validate against it.
	 * @param bool                $expects_doing_it_wrong Whether core emits an incorrect-usage notice.
	 */
	public function test_no_normalized_schema_position_fatals_in_core_validation( array $raw, $value, bool $expects_doing_it_wrong ): void {
		if ( $expects_doing_it_wrong ) {
			$this->setExpectedIncorrectUsage( 'rest_validate_value_from_schema' );
		}

		$schema = aafm_normalize_json_schema( $raw );

		$warnings = array();
		// A scoped handler, not a suppression. Core emits a real PHP warning for a subschema with no
		// type (see the assertion below), and PHPUnit converts warnings to exceptions, which would
		// mask the ONE outcome this test exists to distinguish: a warning that lets validation
		// continue versus the uncaught Error the coercion used to raise. Catching them here keeps
		// both visible and asserted.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Not debug code: this test's whole subject is which diagnostic core emits, so it has to capture one. Scoped to a single call and restored in the finally below.
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$warnings ): bool {
				$warnings[] = $errstr;
				return true;
			},
			E_WARNING | E_NOTICE
		);
		try {
			$result = rest_validate_value_from_schema( $value, $schema );
		} finally {
			restore_error_handler();
		}

		// The real protection is that the line above did not THROW - an uncaught Error is reported by
		// PHPUnit before any assertion here runs. This asserts the call also produced a sane return.
		$this->assertTrue(
			true === $result || is_wp_error( $result ),
			'A normalized foreign schema must validate to true or a WP_Error, never fatal.'
		);

		// Whatever core complains about, it must never be the Error the stdClass coercion raised.
		foreach ( $warnings as $warning ) {
			$this->assertStringNotContainsString(
				'stdClass',
				$warning,
				'No schema position may hand core an object it reads with array syntax.'
			);
		}

		// A subschema carrying no keys at all is genuinely unvalidatable, and core says so twice: the
		// _doing_it_wrong above, then an "Undefined array key" warning at rest-api.php:2216 (7.0.2),
		// because it reads $args['type'] unconditionally on the line after warning that it is
		// missing. That warning is core's, fires with or without our normalizer, and is inert - the
		// null falls through is_array() and validation continues. It is recorded as a known
		// limitation of bridging a foreign schema with an empty subschema, not as a defect here.
		//
		// Both wordings are matched because PHP renamed the diagnostic in 8.0: the floor emits
		// E_NOTICE "Undefined index: type", 8.0+ emits E_WARNING 'Undefined array key "type"'. The
		// handler above is registered for both levels, so on 7.4 the notice was captured all along
		// and only the string comparison missed it. Matching is still full-string equality on a
		// known pair, so any OTHER diagnostic still fails this assertion.
		$reached_typeless_branch = in_array( 'Undefined array key "type"', $warnings, true )
			|| in_array( 'Undefined index: type', $warnings, true );
		$this->assertSame(
			$expects_doing_it_wrong,
			$reached_typeless_branch,
			'Only the keyless-subschema cases may reach core\'s typeless branch.'
		);
	}

	/**
	 * Every case pins every OTHER empty position so a fatal is attributable to the one it names.
	 *
	 * Three things here are load-bearing. 'patternProperties element' carries a NON-EMPTY
	 * properties, because with an empty or absent one the root stamp re-arms and the case dies at
	 * the properties position instead, exercising nothing it claims to. 'oneOf branch' declares a
	 * type for the same reason. And the third column is per-case rather than unconditional because
	 * WP_UnitTestCase_Base fails a test for an UNMET incorrect-usage expectation as well as an
	 * unexpected notice, so setting it everywhere would fail the cases that emit none.
	 *
	 * 'additionalProperties' carries a non-empty properties for the same reason as
	 * 'patternProperties element'. It belongs here because core recurses into it for every object
	 * payload with an unlisted key, which makes it the most travelled position the deleted walker
	 * touched, but its failure mode is NOT the fatal the other cases have. Core reads it behind an
	 * is_array() guard (rest_validate_object_value_from_schema(), and again in the sanitizer), so a
	 * coerced stdClass there did not crash - it made core skip validating additional properties
	 * altogether and say nothing. Measured: restoring the coercion at this one position turns this
	 * case red on the third column, because the typeless warning that proves core went INTO the
	 * subschema stops appearing. A silent hole in validation rather than a visible crash, which is
	 * the harder of the two to notice and the reason to keep it pinned.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:mixed,2:bool}>
	 */
	public function empty_schema_positions(): array {
		// Third column: does this case emit _doing_it_wrong AFTER the revert? Measured, not guessed.
		return array(
			'properties element'        => array(
				array(
					'type'       => 'object',
					'properties' => array( 'ctx' => array() ),
				),
				array( 'ctx' => array( 'a' => 1 ) ),
				true,
			),
			'patternProperties element' => array(
				array(
					'type'              => 'object',
					'properties'        => array( 'keep' => array( 'type' => 'string' ) ),
					'patternProperties' => array( '^a' => array() ),
				),
				array( 'ab' => 1 ),
				true,
			),
			'properties container'      => array(
				array(
					'type'       => 'object',
					'properties' => array(),
				),
				array( 'a' => 1 ),
				false,
			),
			'items empty'               => array(
				array(
					'type'  => 'array',
					'items' => array(),
				),
				array( 1, 2 ),
				true,
			),
			'additionalProperties'      => array(
				array(
					'type'                 => 'object',
					'properties'           => array( 'keep' => array( 'type' => 'string' ) ),
					'additionalProperties' => array(),
				),
				array( 'extra' => 1 ),
				true,
			),
			'anyOf branch'              => array(
				array(
					'type'  => 'string',
					'anyOf' => array( array() ),
				),
				'x',
				false,
			),
			'oneOf branch'              => array(
				array(
					'type'  => 'string',
					'oneOf' => array( array(), array( 'type' => 'integer' ) ),
				),
				'x',
				false,
			),
			'wholly empty'              => array(
				array(),
				array( 'a' => 1 ),
				false,
			),
		);
	}

	/**
	 * The normalizer defaults a typeless foreign schema to type:object, but it must not invent a
	 * properties key the foreign ability never declared. The adapter deletes an empty root
	 * properties before advertising anyway (SchemaTransformer::normalize()), and JSON Schema
	 * 2020-12 section 10.3.2.1 makes an omitted properties behave identically to an empty one.
	 */
	public function test_normalize_does_not_stamp_a_properties_key_on_a_schema_that_lacks_one(): void {
		$this->assertArrayNotHasKey( 'properties', aafm_normalize_json_schema( array() ) );
		$this->assertArrayNotHasKey( 'properties', aafm_normalize_json_schema( array( 'type' => 'object' ) ) );
	}

	/**
	 * The type default is not part of the coercion revert: a foreign schema that declares no type
	 * must not be advertised typeless, and a declared type must survive untouched.
	 */
	public function test_normalize_defaults_a_missing_type_and_preserves_a_declared_one(): void {
		$this->assertSame( 'object', aafm_normalize_json_schema( array() )['type'] );
		$this->assertSame( 'object', aafm_normalize_json_schema( array( 'description' => 'x' ) )['type'] );
		$this->assertSame( 'array', aafm_normalize_json_schema( array( 'type' => 'array' ) )['type'] );

		// The ! is_array() half of the same guard, which nothing reached before: this function is
		// handed whatever a foreign get_input_schema() returned, and that is third-party code free
		// to return anything at all. It must fall back, never fatal on the array access below it.
		foreach ( array( null, 'x', 5, true ) as $not_a_schema ) {
			$this->assertSame(
				array( 'type' => 'object' ),
				aafm_normalize_json_schema( $not_a_schema ),
				'A non-array foreign schema must fall back to a bare object schema.'
			);
		}
	}

	/**
	 * A declared schema round-trips untouched apart from the type default. This is the whole
	 * contract after the revert, and it is asserted on the WIRE form because array() and
	 * (object) array() are indistinguishable in PHP and opposite in JSON.
	 */
	public function test_a_declared_schema_round_trips_unchanged(): void {
		$in = array(
			'type'       => 'object',
			'properties' => array(
				'id'  => array( 'type' => 'integer' ),
				'ctx' => array(),
			),
			'required'   => array( 'id' ),
		);

		$this->assertSame( wp_json_encode( $in ), wp_json_encode( aafm_normalize_json_schema( $in ) ) );
	}

	/**
	 * REGRESSION GUARD, not a Red Gate - this test is expected to pass BOTH before and after the
	 * 1.6.1 revert, and that is correct, not a mistake. An empty PHP array is the CORRECT encoding
	 * for `required: []`, `enum: []`, and as the literal VALUE of `const`, `default`, or
	 * `examples`, and an empty `prefixItems` is a real, zero-length JSON tuple. Converting any of
	 * these to `{}` would corrupt an otherwise-valid foreign schema: `required` and `enum` MUST be
	 * JSON arrays per spec, and `prefixItems` MUST be a JSON array even when empty.
	 */
	public function test_required_enum_and_literal_value_keys_stay_arrays(): void {
		$out = aafm_normalize_json_schema(
			array(
				'type'        => 'object',
				'properties'  => array( 'id' => array( 'type' => 'string' ) ),
				'required'    => array(),
				'enum'        => array(),
				'const'       => array(),
				'default'     => array(),
				'examples'    => array(),
				'prefixItems' => array(),
			)
		);
		$this->assertSame(
			'[]',
			wp_json_encode( $out['required'] ),
			'required:[] is a real JSON array (no required properties), not a subschema position.'
		);
		$this->assertSame(
			'[]',
			wp_json_encode( $out['enum'] ),
			'enum:[] is a real JSON array (an enum of zero values), not a subschema position.'
		);
		$this->assertSame(
			'[]',
			wp_json_encode( $out['const'] ),
			'const is a literal VALUE position, not a subschema - even an empty-array value must round-trip untouched.'
		);
		$this->assertSame( '[]', wp_json_encode( $out['default'] ), 'default is a literal VALUE position, not a subschema.' );
		$this->assertSame( '[]', wp_json_encode( $out['examples'] ), 'examples is a literal VALUE position, not a subschema.' );
		$this->assertSame(
			'[]',
			wp_json_encode( $out['prefixItems'] ),
			'An empty prefixItems is a legitimate zero-length tuple and must stay a JSON array, never {}.'
		);
	}
}
