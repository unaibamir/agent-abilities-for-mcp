<?php
/**
 * Bridge schema normalizer: empty object-containers must serialize as {} not [].
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use stdClass;

final class BridgeSchemaTest extends TestCase {

	public function test_empty_schema_becomes_object_schema(): void {
		$out = aafm_normalize_json_schema( array() );
		$this->assertSame( 'object', $out['type'] );
		$this->assertInstanceOf( stdClass::class, $out['properties'] );
		$this->assertSame( '{"type":"object","properties":{}}', wp_json_encode( $out ) );
	}

	public function test_empty_properties_serialize_as_object(): void {
		$in  = array(
			'type'       => 'object',
			'properties' => array(),
		);
		$out = aafm_normalize_json_schema( $in );
		$this->assertStringContainsString( '"properties":{}', (string) wp_json_encode( $out ) );
	}

	public function test_nested_empty_property_object_preserved(): void {
		$in  = array(
			'type'       => 'object',
			'properties' => array(
				'meta' => array(
					'type'       => 'object',
					'properties' => array(),
				),
			),
		);
		$out = aafm_normalize_json_schema( $in );
		$this->assertStringContainsString( '"meta":{"type":"object","properties":{}}', (string) wp_json_encode( $out ) );
	}

	public function test_deeply_nested_schema_normalizes_without_unbounded_recursion(): void {
		// A hostile/buggy foreign schema could nest far beyond any real need. Build one well past
		// the depth cap and assert normalization returns rather than exhausting the stack.
		$schema = array(
			'type'       => 'object',
			'properties' => array(),
		);
		$node   = &$schema;
		for ( $i = 0; $i < 200; $i++ ) {
			$node['properties']['child'] = array(
				'type'       => 'object',
				'properties' => array(),
			);
			$node                        = &$node['properties']['child'];
		}
		unset( $node );

		$out = aafm_normalize_json_schema( $schema );
		$this->assertIsArray( $out );
		$this->assertSame( 'object', $out['type'] );
	}

	public function test_self_referential_schema_terminates(): void {
		// A cyclic reference would recurse forever without the depth cap. The cap terminates it.
		$schema                       = array(
			'type'       => 'object',
			'properties' => array(),
		);
		$schema['properties']['loop'] = &$schema;

		$out = aafm_normalize_json_schema( $schema );
		$this->assertIsArray( $out );
		$this->assertSame( 'object', $out['type'] );
	}

	public function test_empty_items_serialize_as_object(): void {
		$out = aafm_normalize_json_schema(
			array(
				'type'  => 'array',
				'items' => array(),
			)
		);
		$this->assertStringContainsString( '"items":{}', (string) wp_json_encode( $out ) );
	}

	public function test_empty_additional_properties_serialize_as_object(): void {
		$out = aafm_normalize_json_schema(
			array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => array(),
			)
		);
		$this->assertStringContainsString( '"additionalProperties":{}', (string) wp_json_encode( $out ) );
	}

	public function test_tuple_items_are_recursed_per_element(): void {
		$out = aafm_normalize_json_schema(
			array(
				'type'  => 'array',
				'items' => array(
					array(
						'type'       => 'object',
						'properties' => array(),
					),
					array( 'type' => 'string' ),
				),
			)
		);
		// The empty properties INSIDE the first tuple element must be coerced to {}.
		$this->assertStringContainsString( '"properties":{}', (string) wp_json_encode( $out ) );
		$this->assertSame( 'string', $out['items'][1]['type'] );
	}

	public function test_non_empty_properties_untouched(): void {
		$in  = array(
			'type'       => 'object',
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		);
		$out = aafm_normalize_json_schema( $in );
		$this->assertSame( 'integer', $out['properties']['id']['type'] );
	}

	/**
	 * Task 9 (element position, part 1 of L1): every existing test above only exercised a WHOLE
	 * empty container (e.g. properties => array()). A subschema that is itself empty but lives
	 * INSIDE an otherwise non-empty container - the ordinary PHP way to write "this property
	 * accepts anything" - survived untouched before this fix and serialized as "ctx":[], which a
	 * strict MCP client rejects as a malformed inputSchema.
	 */
	public function test_empty_property_element_becomes_object(): void {
		$out = aafm_normalize_json_schema(
			array(
				'type'       => 'object',
				'properties' => array(
					'ctx' => array(),
				),
			)
		);
		$this->assertSame( '{"type":"object","properties":{"ctx":{}}}', wp_json_encode( $out ) );
	}

	/**
	 * Same element-position gap, inside $defs: `'$defs' => array( 'Any' => array() )` is a real
	 * pattern a foreign ability can declare (a named schema with no constraints), and the empty
	 * value at the 'Any' element position must become {}, not survive as [].
	 */
	public function test_empty_defs_element_becomes_object(): void {
		$out = aafm_normalize_json_schema(
			array(
				'type'  => 'object',
				'$defs' => array( 'Any' => array() ),
			)
		);
		$this->assertStringContainsString( '"$defs":{"Any":{}}', (string) wp_json_encode( $out ) );
	}

	/**
	 * Same element-position gap, inside a oneOf branch: `oneOf => array( array(), array('type' =>
	 * 'string') )` means "anything, or specifically a string". The bare array() branch must become
	 * {}, not [].
	 */
	public function test_empty_oneof_branch_becomes_object(): void {
		$out = aafm_normalize_json_schema(
			array(
				'oneOf' => array( array(), array( 'type' => 'string' ) ),
			)
		);
		$this->assertStringContainsString( '"oneOf":[{},{"type":"string"}]', (string) wp_json_encode( $out ) );
	}

	/**
	 * Same element-position gap, inside a tuple-form items list (the pre-2020-12 syntax): the
	 * empty first element must become {}, not survive as [].
	 */
	public function test_empty_tuple_items_element_becomes_object(): void {
		$out = aafm_normalize_json_schema(
			array(
				'type'  => 'array',
				'items' => array( array(), array( 'type' => 'string' ) ),
			)
		);
		$this->assertStringContainsString( '"items":[{},{"type":"string"}]', (string) wp_json_encode( $out ) );
	}

	/**
	 * Same element-position gap, inside prefixItems (the 2020-12 tuple keyword). Its ELEMENTS are
	 * ordinary subschemas and get the same {} fix as tuple-form items; the container itself is
	 * covered separately in test_required_enum_and_literal_value_keys_stay_arrays() below, which
	 * proves an empty prefixItems container stays [].
	 */
	public function test_empty_prefixitems_element_becomes_object(): void {
		$out = aafm_normalize_json_schema(
			array(
				'type'        => 'array',
				'prefixItems' => array( array(), array( 'type' => 'string' ) ),
			)
		);
		$this->assertStringContainsString( '"prefixItems":[{},{"type":"string"}]', (string) wp_json_encode( $out ) );
	}

	/**
	 * Task 9 (L1, unvisited applicator keywords): `not` was never walked before this fix, so an
	 * empty `not` subschema (a legal "not: no constraints", i.e. "reject everything") serialized
	 * as [] instead of {}.
	 */
	public function test_not_subschema_is_recursed(): void {
		$out = aafm_normalize_json_schema(
			array(
				'type' => 'object',
				'not'  => array(),
			)
		);
		$this->assertStringContainsString( '"not":{}', (string) wp_json_encode( $out ) );
	}

	/**
	 * L1: if/then/else were never walked before this fix.
	 */
	public function test_conditional_subschemas_are_recursed(): void {
		$out     = aafm_normalize_json_schema(
			array(
				'type' => 'object',
				'if'   => array(),
				'then' => array( 'properties' => array() ),
				'else' => array(),
			)
		);
		$encoded = (string) wp_json_encode( $out );
		$this->assertStringContainsString( '"if":{}', $encoded );
		$this->assertStringContainsString( '"then":{"properties":{}}', $encoded );
		$this->assertStringContainsString( '"else":{}', $encoded );
	}

	/**
	 * L1: propertyNames and contains were never walked before this fix.
	 */
	public function test_property_names_and_contains_are_recursed(): void {
		$out     = aafm_normalize_json_schema(
			array(
				'type'          => 'object',
				'propertyNames' => array(),
				'contains'      => array(),
			)
		);
		$encoded = (string) wp_json_encode( $out );
		$this->assertStringContainsString( '"propertyNames":{}', $encoded );
		$this->assertStringContainsString( '"contains":{}', $encoded );
	}

	/**
	 * L1: additionalItems, unevaluatedProperties, and unevaluatedItems were never walked before
	 * this fix.
	 */
	public function test_additional_items_and_unevaluated_keywords_are_recursed(): void {
		$out     = aafm_normalize_json_schema(
			array(
				'type'                  => 'array',
				'additionalItems'       => array(),
				'unevaluatedProperties' => array(),
				'unevaluatedItems'      => array(),
			)
		);
		$encoded = (string) wp_json_encode( $out );
		$this->assertStringContainsString( '"additionalItems":{}', $encoded );
		$this->assertStringContainsString( '"unevaluatedProperties":{}', $encoded );
		$this->assertStringContainsString( '"unevaluatedItems":{}', $encoded );
	}

	/**
	 * L1: dependentSchemas (an object-keyed map, like properties) was never walked before this
	 * fix.
	 */
	public function test_dependent_schemas_map_is_recursed(): void {
		$out = aafm_normalize_json_schema(
			array(
				'type'             => 'object',
				'dependentSchemas' => array( 'creditCard' => array() ),
			)
		);
		$this->assertStringContainsString( '"dependentSchemas":{"creditCard":{}}', (string) wp_json_encode( $out ) );
	}

	/**
	 * REGRESSION GUARD, not a Red Gate - this test is expected to pass BOTH before and after the
	 * element-position fix above, and that is correct, not a mistake. An empty PHP array is the
	 * CORRECT encoding for `required: []`, `enum: []`, and as the literal VALUE of `const`,
	 * `default`, or `examples` - none of these keys are ever recursed into as a $node in their own
	 * right by aafm_normalize_schema_node() (they are not in $object_keyed, $single_subschema, the
	 * tuple branches, or the composite-array branch), so the new `$depth > 0 && array() === $node`
	 * check can never reach them. An empty `prefixItems` is likewise a real, zero-length JSON
	 * tuple and must stay `[]`. Converting any of these to `{}` would corrupt an otherwise-valid
	 * foreign schema: `required` and `enum` MUST be JSON arrays per spec, and `prefixItems` MUST
	 * be a JSON array even when empty. Confirmed passing against both unfixed and fixed
	 * includes/bridge.php - see the Task 9 report's "Regression guard" section for the run.
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
