<?php
/**
 * The playbook index in the server instructions: present only when a playbook is Active and the
 * caller can see both playbook tools, capped at 600 characters, decided per request.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;
use WP\McpSchema\Schemas;
use WP\McpSchema\Record\InitializeResult;

final class PlaybookIndexTest extends TestCase {

	private const BOTH = array( 'aafm/list-playbooks', 'aafm/get-playbook' );

	public function tear_down(): void {
		delete_option( 'aafm_playbooks' );
		delete_option( 'aafm_site_brief' );
		delete_option( 'aafm_ability_allowlist_overrides' );
		unset( $GLOBALS['HTTP_RAW_POST_DATA'], $_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_ACCEPT'] );
		parent::tear_down();
	}

	/**
	 * Store playbooks straight in the row.
	 *
	 * @param int  $count       How many.
	 * @param bool $active      Active switch.
	 * @param int  $desc_length Description length.
	 * @return void
	 */
	private function seed( int $count, bool $active = true, int $desc_length = 20 ): void {
		$list = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$list[] = array(
				'slug'        => 'playbook-' . $i,
				'title'       => 'Playbook ' . $i,
				'description' => str_repeat( 'd', $desc_length ),
				'body'        => 'Steps.',
				'active'      => $active,
			);
		}
		update_option( 'aafm_playbooks', $list, false );
	}

	/**
	 * Run the initialize filter over a record-shape result and return its instructions.
	 *
	 * @return string
	 */
	private function filtered_instructions(): string {
		$schema = Schemas::create()->forVersion( '2025-11-25' );
		$result = $schema->fromArray(
			InitializeResult::class,
			array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
				'serverInfo'      => array(
					'name'    => 'Agent Abilities',
					'version' => '1.0.0',
				),
				'instructions'    => aafm_server_description(),
			)
		);
		$data   = json_decode( (string) wp_json_encode( aafm_filter_initialize_capabilities( $result, null, $schema ) ), true );
		return (string) $data['instructions'];
	}

	/**
	 * Assert the instructions match 1.10.0 (the server description alone), with and without a brief.
	 *
	 * @return void
	 */
	private function assert_unchanged(): void {
		$this->assertSame( aafm_server_description(), $this->filtered_instructions() );
		$this->assertSame( 'Curated, governed WordPress abilities for AI agents.', aafm_server_description() );
		update_option( 'aafm_site_brief', 'British spelling.' );
		$this->assertSame( aafm_server_description(), $this->filtered_instructions() );
		$this->assertStringNotContainsString( 'playbook-1', aafm_server_description() );
		delete_option( 'aafm_site_brief' );
	}

	public function test_no_index_without_active_playbooks(): void {
		$this->register_enabled( self::BOTH );
		$this->acting_as( 'administrator' );
		$this->assert_unchanged();
		$this->seed( 2, false );
		$this->assert_unchanged();
	}

	public function test_no_index_while_either_ability_is_off(): void {
		$this->seed( 2 );
		$this->acting_as( 'administrator' );
		foreach ( self::BOTH as $only ) {
			$this->register_enabled( array( $only ) );
			$this->assert_unchanged();
		}
	}

	public function test_no_index_for_a_connection_that_cannot_see_the_tools(): void {
		$this->seed( 2 );
		$this->register_enabled( self::BOTH );
		update_option(
			'aafm_ability_allowlist_overrides',
			array(
				array(
					'scope_type'        => 'role',
					'scope_id'          => 'editor',
					'allowed_abilities' => array( 'aafm/get-posts' ),
				),
			)
		);
		$this->acting_as( 'editor' );
		$this->assert_unchanged();
	}

	public function test_index_follows_the_current_enabled_set(): void {
		$this->seed( 1 );
		$this->register_enabled( self::BOTH );
		$this->acting_as( 'administrator' );
		$this->assertStringContainsString( 'playbook-1', $this->filtered_instructions() );
		update_option( 'aafm_enabled_abilities', array( 'aafm/list-playbooks' ) );
		$this->assertStringNotContainsString( 'playbook-1', $this->filtered_instructions() );
	}

	public function test_index_is_appended_on_the_legacy_dto_shape(): void {
		$this->seed( 1 );
		$this->register_enabled( self::BOTH );
		$this->acting_as( 'administrator' );
		$legacy   = new class() {
			/**
			 * The DTO accessor the 0.6.1 adapter exposes.
			 *
			 * @return array<string, mixed>
			 */
			public function toArray(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the 0.6.1 DTO.
				return array(
					'capabilities' => array(
						'prompts'   => array(),
						'resources' => array(),
						'tools'     => array( 'listChanged' => false ),
					),
					'instructions' => 'Use the tools.',
				);
			}

			/**
			 * The DTO factory the 0.6.1 adapter exposes.
			 *
			 * @param array<string, mixed> $data Result fields.
			 * @return object
			 */
			public static function fromArray( array $data ): object { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the 0.6.1 DTO.
				return (object) $data;
			}
		};
		$filtered = aafm_filter_initialize_capabilities( $legacy, null );
		$this->assertStringStartsWith( "Use the tools.\n\n", $filtered->instructions );
		$this->assertStringContainsString( '- playbook-1:', $filtered->instructions );
		$this->assertSame( array( 'tools' ), array_keys( $filtered->capabilities ) );
	}

	public function test_index_lists_active_playbooks_after_the_brief(): void {
		update_option( 'aafm_site_brief', 'British spelling.' );
		$this->seed( 2 );
		$this->register_enabled( self::BOTH );
		$this->acting_as( 'administrator' );
		$instructions = $this->filtered_instructions();
		$this->assertStringStartsWith( aafm_server_description() . "\n\n", $instructions );
		$this->assertStringEndsWith( aafm_playbook_index(), $instructions );
		$this->assertStringContainsString( '- playbook-2:', $instructions );
	}

	public function test_index_is_capped_at_600_characters(): void {
		$this->seed( 50, true, 200 );
		$this->register_enabled( self::BOTH );
		$this->acting_as( 'administrator' );
		$index = aafm_playbook_index();
		$this->assertLessThanOrEqual( 600, mb_strlen( $index ) );
		$listed = substr_count( $index, "\n- playbook-" );
		$this->assertStringEndsWith( sprintf( '- and %d more: call list-playbooks.', 50 - $listed ), $index );
	}
}
