<?php
/**
 * The initialize response advertises tools only, on both adapter result shapes.
 *
 * Adapter 0.6.1 hands the filter a DTO with toArray() and a static fromArray(). Adapter 0.7.0
 * hands it a schema Record plus the selected Schema, and rebuilds through that Schema. Either way
 * `resources` and `prompts` must be gone and every other field must survive.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP\McpSchema\Record\InitializeResult;
use WP\McpSchema\Schemas;

final class InitializeCapabilitiesFilterTest extends TestCase {

	/**
	 * The capabilities the adapter advertises by default.
	 *
	 * @return array<string, mixed>
	 */
	private static function default_result(): array {
		return array(
			'protocolVersion' => '2025-11-25',
			'capabilities'    => array(
				'prompts'   => array( 'listChanged' => false ),
				'resources' => array(
					'subscribe'   => false,
					'listChanged' => false,
				),
				'tools'     => array( 'listChanged' => false ),
			),
			'serverInfo'      => array(
				'name'    => 'Agent Abilities',
				'version' => '1.0.0',
			),
			'instructions'    => 'Use the tools.',
		);
	}

	public function test_record_shape_loses_resources_and_prompts(): void {
		// 2026-07-28 has no initialize message, so the record shape only exists on 2025-11-25.
		$schema = Schemas::create()->forVersion( '2025-11-25' );
		$result = $schema->fromArray( InitializeResult::class, self::default_result() );

		$filtered = aafm_filter_initialize_capabilities( $result, null, $schema );

		$this->assertInstanceOf( InitializeResult::class, $filtered );

		$data = json_decode( (string) wp_json_encode( $filtered ), true );
		$this->assertSame( array( 'tools' ), array_keys( $data['capabilities'] ) );
		$this->assertSame( array( 'listChanged' => false ), $data['capabilities']['tools'] );
		$this->assertSame( '2025-11-25', $data['protocolVersion'] );
		$this->assertSame( 'Agent Abilities', $data['serverInfo']['name'] );
		$this->assertSame( 'Use the tools.', $data['instructions'] );
	}

	public function test_dto_shape_loses_resources_and_prompts(): void {
		$legacy = new class( self::default_result() ) {
			/**
			 * The result fields.
			 *
			 * @var array<string, mixed>
			 */
			private array $data;

			/**
			 * Hold the result fields.
			 *
			 * @param array<string, mixed> $data Result fields.
			 */
			public function __construct( array $data ) {
				$this->data = $data;
			}

			/**
			 * The DTO accessor the 0.6.1 adapter exposes.
			 *
			 * @return array<string, mixed>
			 */
			public function toArray(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the 0.6.1 DTO.
				return $this->data;
			}

			/**
			 * The DTO factory the 0.6.1 adapter exposes.
			 *
			 * @param array<string, mixed> $data Result fields.
			 * @return self
			 */
			public static function fromArray( array $data ): self { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the 0.6.1 DTO.
				return new self( $data );
			}
		};

		$filtered = aafm_filter_initialize_capabilities( $legacy, null );

		$this->assertInstanceOf( get_class( $legacy ), $filtered );
		$data = $filtered->toArray();
		$this->assertSame( array( 'tools' ), array_keys( $data['capabilities'] ) );
		$this->assertSame( 'Use the tools.', $data['instructions'] );
	}

	public function test_unrecognised_shapes_are_returned_untouched(): void {
		$this->assertSame( 'text', aafm_filter_initialize_capabilities( 'text', null ) );

		$plain = new \stdClass();
		$this->assertSame( $plain, aafm_filter_initialize_capabilities( $plain, null ) );

		$schema = Schemas::create()->forVersion( '2025-11-25' );
		$bare   = $schema->fromArray(
			InitializeResult::class,
			array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => array(),
				'serverInfo'      => array(
					'name'    => 'x',
					'version' => '1',
				),
			)
		);
		$this->assertSame(
			wp_json_encode( $bare ),
			wp_json_encode( aafm_filter_initialize_capabilities( $bare, null, $schema ) ),
			'A result with nothing to strip comes back unchanged.'
		);
	}

	public function test_filter_is_registered_to_receive_the_schema_argument(): void {
		// The 0.7.0 adapter passes the schema as a third argument; without it the record cannot be
		// rebuilt. The hook is added inside aafm_register_mcp_server(), which the test harness
		// resets between tests, so the registration line itself is what gets pinned.
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/server.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading our own source.

		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*'mcp_adapter_initialize_response',\s*'aafm_filter_initialize_capabilities',\s*10,\s*3\s*\)/",
			$source
		);
	}
}
