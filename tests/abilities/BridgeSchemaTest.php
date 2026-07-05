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

	public function test_non_empty_properties_untouched(): void {
		$in  = array(
			'type'       => 'object',
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
		);
		$out = aafm_normalize_json_schema( $in );
		$this->assertSame( 'integer', $out['properties']['id']['type'] );
	}
}
