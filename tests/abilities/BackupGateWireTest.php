<?php
/**
 * The backup gate on the real permission path: the adapter's fire, core's re-check inside
 * execute(), the denied row, and a real tools/call.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class BackupGateWireTest extends TestCase {

	private const TOOL = 'aafm/delete-post';

	/**
	 * A post the gated tool would delete.
	 *
	 * @var int
	 */
	private $post_id = 0;

	public function set_up(): void {
		parent::set_up();
		aafm_updraft_stub_activate();
		update_option( 'aafm_backup_gate', '1' );
		update_option( 'aafm_backup_gate_abilities', array( self::TOOL, 'gatewire/writer' ) );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( self::TOOL ) );
		$this->post_id = (int) self::factory()->post->create();
		$this->acting_as( 'administrator' );
	}

	public function tear_down(): void {
		remove_all_filters( 'aafm_backup_gate_inline_checksum_max_bytes' );
		aafm_updraft_stub_deactivate();
		parent::tear_down();
	}

	/**
	 * The denied rows of one ability, newest first.
	 *
	 * @param string $ability Ability name.
	 * @return array<int,array<string,mixed>>
	 */
	private function denied_rows( string $ability = self::TOOL ): array {
		return aafm_query_activity(
			array(
				'ability' => $ability,
				'status'  => 'denied',
			)
		);
	}

	public function test_the_adapters_fire_gets_the_gate_error_and_one_denied_row(): void {
		$answer = wp_get_ability( self::TOOL )->check_permissions( array( 'post_id' => $this->post_id ) );

		$this->assertWPError( $answer );
		$this->assertSame( 'aafm_backup_gate', $answer->get_error_code() );
		$rows = $this->denied_rows();
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'Refused: backup_gate', (string) $rows[0]['detail'] );
		$this->assertStringNotContainsString( 'backup_gate_', (string) $rows[0]['detail'] );
		$this->assertInstanceOf( \WP_Post::class, get_post( $this->post_id ) );
	}

	public function test_cores_recheck_inside_execute_gets_a_plain_refusal_and_one_denied_row(): void {
		$answer = wp_get_ability( self::TOOL )->execute( array( 'post_id' => $this->post_id ) );

		$this->assertWPError( $answer );
		$this->assertNotSame( 'aafm_backup_gate', $answer->get_error_code(), 'The re-check answers bare false; core words the error.' );
		$this->assertInstanceOf( \WP_Post::class, get_post( $this->post_id ), 'The post is still there.' );
		$rows = $this->denied_rows();
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'Refused: backup_gate', (string) $rows[0]['detail'] );
		$started = aafm_query_activity(
			array(
				'ability' => self::TOOL,
				'status'  => 'started',
			)
		);
		$this->assertCount( 0, $started, 'No row is left at started.' );
	}

	public function test_a_real_tools_call_is_a_tool_error_with_the_message_and_the_tool_stays_listed(): void {
		$adapter   = \WP\MCP\Core\McpAdapter::instance();
		$server_id = 'aafm-server-backup-gate-wire-test';
		$tools     = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( self::TOOL ) ) );
		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (backup gate wire test)',
					'Test-only server carrying only the gated tool.',
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
		$server = $adapter->get_server( $server_id );
		$this->assertInstanceOf( \WP\MCP\Core\McpServer::class, $server );
		$handler = new \AAFM\Tests\Support\McpToolsHandlerShim( $server );

		$result = $handler->call_tool(
			array(
				'name'      => aafm_mcp_tool_name( self::TOOL ),
				'arguments' => array( 'post_id' => $this->post_id ),
			),
			'req-backup-gate-1'
		);

		$this->assertInstanceOf( \AAFM\Tests\Support\McpToolCallOutcome::class, $result );
		$this->assertTrue( $result->getIsError() );
		$this->assertStringContainsString( 'has none that passed', (string) wp_json_encode( $result->getContent() ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $this->post_id ) );
		$rows = aafm_query_activity( array( 'ability' => self::TOOL ) );
		$this->assertCount( 1, $rows, 'Exactly one row for the refused call.' );
		$this->assertSame( 'denied', $rows[0]['status'] );
		$this->assertStringContainsString( 'Refused: backup_gate', (string) $rows[0]['detail'] );

		$listed = array_map( static fn( $tool ) => $tool->getName(), aafm_filter_mcp_tools_list( $handler->list_tools()->getTools(), $server ) );
		$this->assertContains( aafm_mcp_tool_name( self::TOOL ), $listed, 'A gated tool stays in tools/list.' );
	}

	public function test_a_pending_large_archive_gets_the_pending_code(): void {
		add_filter( 'aafm_backup_gate_inline_checksum_max_bytes', static fn(): int => 1024 );
		aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );

		$answer = wp_get_ability( self::TOOL )->check_permissions( array( 'post_id' => $this->post_id ) );

		$this->assertWPError( $answer );
		$this->assertSame( 'aafm_backup_gate_pending', $answer->get_error_code() );
		$this->assertStringContainsString( 'Refused: backup_gate_pending', (string) $this->denied_rows()[0]['detail'] );
	}

	public function test_a_too_large_archive_gets_its_own_code_and_names_the_card(): void {
		add_filter( 'aafm_backup_gate_inline_checksum_max_bytes', static fn(): int => 1024 );
		$file = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/backup-updraft.php';
		update_option(
			'aafm_backup_verify',
			array(
				'aaaaaaaaaaa1' => array(
					'fingerprint' => aafm_site_updraft_fingerprint( 'aaaaaaaaaaa1', $file ),
					'verdict'     => 'too_large',
					'checked_at'  => time(),
				),
			)
		);

		$answer = wp_get_ability( self::TOOL )->check_permissions( array( 'post_id' => $this->post_id ) );

		$this->assertWPError( $answer );
		$this->assertSame( 'aafm_backup_gate_too_large', $answer->get_error_code() );
		$this->assertStringContainsString( 'Backup gate card', $answer->get_error_message() );
		$this->assertStringContainsString( 'Refused: backup_gate_too_large', (string) $this->denied_rows()[0]['detail'] );
	}

	public function test_a_good_backup_lets_the_call_through_and_the_answer_does_not_reach_the_next_call(): void {
		$file    = aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
		$ability = wp_get_ability( self::TOOL );

		$this->assertTrue( $ability->check_permissions( array( 'post_id' => $this->post_id ) ) );
		$this->assertCount( 0, $this->denied_rows() );

		aafm_updraft_stub_delete( $file );
		$second = $ability->check_permissions( array( 'post_id' => $this->post_id ) );
		$this->assertWPError( $second, 'The second tool call looks at the backup again.' );
		$this->assertSame( 'aafm_backup_gate', $second->get_error_code() );
	}

	public function test_a_tool_that_is_not_on_the_list_and_a_gate_that_is_off_refuse_nothing(): void {
		update_option( 'aafm_backup_gate_abilities', array( 'gatewire/writer' ) );
		$this->assertTrue( wp_get_ability( self::TOOL )->check_permissions( array( 'post_id' => $this->post_id ) ) );

		update_option( 'aafm_backup_gate_abilities', array( self::TOOL ) );
		delete_option( 'aafm_backup_gate' );
		$this->assertTrue( wp_get_ability( self::TOOL )->check_permissions( array( 'post_id' => $this->post_id ) ) );
	}

	public function test_a_bridged_wrapper_is_refused_by_its_foreign_slug_on_both_fires(): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'gate-wire-things' ) ) {
					wp_register_ability_category(
						'gate-wire-things',
						array(
							'label'       => 'Gate wire',
							'description' => 'Fixture category.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function (): void {
				aafm_register_ability_with_log(
					'aafm-bridge/gate-wire-writer',
					array(
						'category'            => 'gate-wire-things',
						'label'               => 'Writer',
						'description'         => 'Fixture.',
						'input_schema'        => array( 'type' => 'object' ),
						'execute_callback'    => static fn() => array( 'ran' => true ),
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => array( 'readonly' => false ) ),
					)
				);
			}
		);
		aafm_remember_bridge_source( 'aafm-bridge/gate-wire-writer', 'gatewire/writer' );
		$ability = wp_get_ability( 'aafm-bridge/gate-wire-writer' );

		try {
			$fire = $ability->check_permissions( array() );
			$this->assertWPError( $fire );
			$this->assertSame( 'aafm_backup_gate', $fire->get_error_code() );
			$this->assertCount( 1, $this->denied_rows( 'aafm-bridge/gate-wire-writer' ) );

			$this->assertWPError( $ability->execute( array() ), 'The re-check inside execute() refuses too.' );
			$rows = $this->denied_rows( 'aafm-bridge/gate-wire-writer' );
			$this->assertCount( 2, $rows, 'One denied row per refused call.' );
			$this->assertStringContainsString( 'Refused: backup_gate', (string) $rows[0]['detail'] );

			aafm_updraft_stub_add_set( 'aaaaaaaaaaa1', 3600 );
			$ran = $ability->execute( array() );
			$this->assertNotWPError( $ran, 'With a good backup the wrapper runs.' );
			$this->assertTrue( $ran['ran'] ?? false );
		} finally {
			wp_unregister_ability( 'aafm-bridge/gate-wire-writer' );
		}
	}
}
