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

	public function test_non_empty_properties_untouched(): void {
		$in  = array(
			'type'       => 'object',
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		);
		$out = aafm_normalize_json_schema( $in );
		$this->assertSame( 'integer', $out['properties']['id']['type'] );
	}
}
