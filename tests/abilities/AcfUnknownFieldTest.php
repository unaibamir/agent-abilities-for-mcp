<?php
/**
 * An ACF write naming a top-level field ACF cannot resolve is refused by name, unless any of the
 * names is protected meta, which stays the generic refusal. Nothing is written either way.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\Support\McpToolsHandlerShim;
use AAFM\Tests\TestCase;

final class AcfUnknownFieldTest extends TestCase {

	use IntegrationStubs;

	private const WRITERS = array( 'aafm/acf-update-post-fields', 'aafm/acf-update-term-fields', 'aafm/acf-update-user-fields' );

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Stub ACF with one text field and register the given abilities.
	 *
	 * @param string[] $enabled Abilities to enable.
	 * @return int An administrator's id.
	 */
	private function boot( array $enabled ): int {
		$this->force_integration( 'acf' );
		$this->stub_acf(
			array(
				'groups' => array(
					array(
						'key'    => 'group_unknown',
						'title'  => 'Unknown',
						'fields' => array(
							array(
								'key'   => 'field_headline',
								'name'  => 'headline',
								'label' => 'Headline',
								'type'  => 'text',
							),
						),
					),
				),
			)
		);
		aafm_registry_cache_should_flush( true );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', $enabled );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
		return $this->acting_as( 'administrator' );
	}

	/**
	 * The selector input for a writer.
	 *
	 * @param string $ability Writer.
	 * @param int    $admin   Administrator id.
	 * @return array<string,int>
	 */
	private function selector( string $ability, int $admin ): array {
		if ( 'aafm/acf-update-term-fields' === $ability ) {
			return array( 'term_id' => (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) ) );
		}
		if ( 'aafm/acf-update-user-fields' === $ability ) {
			return array( 'user_id' => $admin );
		}
		return array( 'post_id' => (int) self::factory()->post->create( array( 'post_author' => $admin ) ) );
	}

	/**
	 * Run a writer with a field map.
	 *
	 * @param string              $ability Writer.
	 * @param array<string,mixed> $fields  Field map.
	 * @param bool                $listing Also enable acf-list-field-groups.
	 * @return mixed
	 */
	private function write( string $ability, array $fields, bool $listing = true ) {
		$admin = $this->boot( $listing ? array( $ability, 'aafm/acf-list-field-groups' ) : array( $ability ) );
		return wp_get_ability( $ability )->execute( array_merge( $this->selector( $ability, $admin ), array( 'fields' => $fields ) ) );
	}

	/**
	 * Count meta rows with a key.
	 *
	 * @param string $key Meta key.
	 * @return int
	 */
	private function meta_rows( string $key ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ( SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s ) + ( SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s ) + ( SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s )", $key, $key, $key ) );
	}

	/**
	 * The three writers.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function writers(): array {
		$out = array();
		foreach ( self::WRITERS as $ability ) {
			$out[ $ability ] = array( $ability );
		}
		return $out;
	}

	/**
	 * Mixed protected and unknown names.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public function mixed_maps(): array {
		$six = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$six[ 'unknown_' . $i ] = 'v';
		}
		return array(
			'unknown first'                  => array(
				array(
					'livetest_field'  => 'v',
					'wp_capabilities' => 'x',
				),
			),
			'protected first'                => array(
				array(
					'wp_capabilities' => 'x',
					'livetest_field'  => 'v',
				),
			),
			'protected after the first five' => array( $six + array( 'wp_capabilities' => 'x' ) ),
		);
	}

	/**
	 * Each writer names an unknown field.
	 *
	 * @dataProvider writers
	 *
	 * @param string $ability Writer.
	 */
	public function test_unknown_field_is_named_for_posts_terms_and_users( string $ability ): void {
		$result = $this->write( $ability, array( 'livetest_field' => 'v' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'aafm_acf_unknown_field', $result->get_error_code() );
		$this->assertStringContainsString( 'livetest_field', $result->get_error_message() );
		$this->assertStringContainsString( 'acf-list-field-groups', $result->get_error_message() );
		$this->assertSame( 0, $this->meta_rows( 'livetest_field' ) );
	}

	public function test_pointer_only_when_the_list_tool_is_usable(): void {
		$result = $this->write( 'aafm/acf-update-post-fields', array( 'livetest_field' => 'v' ), false );
		$this->assertStringContainsString( 'livetest_field', $result->get_error_message() );
		$this->assertStringNotContainsString( 'acf-list-field-groups', $result->get_error_message() );
	}

	public function test_unknown_field_reaches_the_agent_over_mcp(): void {
		$admin   = $this->boot( array( 'aafm/acf-update-post-fields', 'aafm/acf-list-field-groups' ) );
		$post_id = (int) self::factory()->post->create( array( 'post_author' => $admin ) );
		$tools   = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( 'aafm/acf-update-post-fields' ) ) );
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $tools ): void {
				$adapter->create_server(
					'aafm-server-acf-unknown-test',
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'ACF unknown field test',
					'Test-only server.',
					AAFM_VERSION,
					array( \WP\MCP\Transport\HttpTransport::class ),
					\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
					\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
					$tools,
					array(),
					array(),
					'aafm_transport_permission_callback'
				);
			}
		);
		$handler = new McpToolsHandlerShim( $adapter->get_server( 'aafm-server-acf-unknown-test' ) );
		$call    = static function ( array $fields ) use ( $handler, $post_id ) {
			return $handler->call_tool(
				array(
					'name'      => 'aafm-acf-update-post-fields',
					'arguments' => array(
						'post_id' => $post_id,
						'fields'  => $fields,
					),
				)
			);
		};

		$unknown = $call( array( 'livetest_field' => 'v' ) );
		$this->assertSame( array( 'content', 'isError' ), array_keys( json_decode( (string) wp_json_encode( $unknown->record() ), true ) ) );
		$this->assertTrue( $unknown->getIsError() );
		$this->assertCount( 1, $unknown->getContent() );
		$this->assertSame( aafm_acf_unknown_field_error( array( 'livetest_field' ) )->get_error_message(), $unknown->getContent()[0]->getText() );

		$protected = $call( array( '_edit_lock' => 'x' ) );
		$this->assertSame( array( 'content', 'isError' ), array_keys( json_decode( (string) wp_json_encode( $protected->record() ), true ) ) );
		$this->assertTrue( $protected->getIsError() );
		$this->assertStringNotContainsString( '_edit_lock', $protected->getContent()[0]->getText() );
	}

	public function test_two_unknown_fields_are_both_named(): void {
		$result = $this->write(
			'aafm/acf-update-post-fields',
			array(
				'first_unknown'  => 'v',
				'second_unknown' => 'v',
			)
		);
		$this->assertStringContainsString( 'first_unknown, second_unknown', $result->get_error_message() );
	}

	public function test_seven_unknown_fields_name_five_and_count_the_rest(): void {
		$fields = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$fields[ 'unknown_' . $i ] = 'v';
		}
		$result = $this->write( 'aafm/acf-update-post-fields', $fields, false );
		$this->assertStringEndsWith( 'unknown_5 and 2 more.', $result->get_error_message() );
		$this->assertStringNotContainsString( 'unknown_6', $result->get_error_message() );
	}

	public function test_a_protected_name_stays_generic(): void {
		foreach ( array( array( 'aafm/acf-update-user-fields', 'wp_capabilities' ), array( 'aafm/acf-update-post-fields', '_edit_lock' ) ) as $case ) {
			$result = $this->write( $case[0], array( $case[1] => 'x' ) );
			$this->assertWPError( $result );
			$this->assertSame( 'aafm_error', $result->get_error_code() );
			$this->assertStringNotContainsString( $case[1], $result->get_error_message() );
		}
	}

	/**
	 * A protected name anywhere in the map keeps the whole refusal generic.
	 *
	 * @dataProvider mixed_maps
	 *
	 * @param array<string,string> $fields Field map.
	 */
	public function test_protected_name_mixed_with_unknown_ones_stays_generic( array $fields ): void {
		$result = $this->write( 'aafm/acf-update-user-fields', $fields );
		$this->assertSame( 'aafm_error', $result->get_error_code() );
		foreach ( array_keys( $fields ) as $name ) {
			$this->assertStringNotContainsString( $name, $result->get_error_message() );
			$this->assertSame( 'wp_capabilities' === $name ? $this->meta_rows( $name ) : 0, $this->meta_rows( $name ) );
		}
	}

	public function test_known_field_still_writes(): void {
		$result = $this->write( 'aafm/acf-update-post-fields', array( 'field_headline' => 'Hello' ) );
		$this->assertNotWPError( $result );
	}
}
