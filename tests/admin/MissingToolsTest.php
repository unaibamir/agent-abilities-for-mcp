<?php
/**
 * Tests for the Dashboard's "Why is my tool missing?" card.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

/**
 * Covers aafm_missing_tools_data() and aafm_render_missing_tools_card().
 */
class MissingToolsTest extends TestCase {

	public function tear_down(): void {
		remove_all_filters( 'aafm_abilities_registry_integrations' );
		remove_all_filters( 'aafm_high_risk_abilities' );
		remove_all_filters( 'query' );
		aafm_registry_cache_should_flush( true );
		parent::tear_down();
	}

	/**
	 * Rows keyed by ability name.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function rows(): array {
		return array_column( aafm_missing_tools_data()['rows'], null, 'name' );
	}

	private function add_inactive_host_ability( string $name ): void {
		add_filter(
			'aafm_abilities_registry_integrations',
			static function ( array $registry ) use ( $name ): array {
				$registry[ $name ] = array(
					'subject' => 'woocommerce',
					'label'   => 'Fake inactive tool',
				);
				return $registry;
			}
		);
		aafm_registry_cache_should_flush( true );
	}

	private function lock_create_post(): void {
		add_filter(
			'aafm_high_risk_abilities',
			static function ( array $names ): array {
				$names[] = 'aafm/create-post';
				return $names;
			}
		);
	}

	public function test_a_healthy_enabled_ability_is_not_listed(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		$this->assertSame( array(), aafm_missing_tools_data()['rows'] );
	}

	public function test_a_stored_name_that_no_longer_exists_is_listed_as_unknown(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/gone-tool' ) );
		$rows = $this->rows();
		$this->assertSame( array( 'aafm/gone-tool' ), array_keys( $rows ) );
		$this->assertSame( 'unknown', $rows['aafm/gone-tool']['reason'] );
		$this->assertSame( 'aafm/gone-tool', $rows['aafm/gone-tool']['label'] );
	}

	public function test_an_ability_whose_plugin_is_inactive_or_too_old_is_listed_as_host_inactive(): void {
		$this->add_inactive_host_ability( 'aafm/fake-inactive-host' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/fake-inactive-host' ) );
		$row = $this->rows()['aafm/fake-inactive-host'];
		$this->assertSame( 'host_inactive', $row['reason'] );
		$this->assertSame( 'Fake inactive tool', $row['label'] );
		$this->assertStringContainsString( 'not active, or is older than this tool needs', $row['reason_text'] );
	}

	public function test_read_only_mode_lists_the_enabled_writes_with_the_read_only_reason(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/create-post' ) );
		aafm_set_read_only_mode( true );
		$rows = $this->rows();
		$this->assertSame( array( 'aafm/create-post' ), array_keys( $rows ) );
		$this->assertSame( 'read_only', $rows['aafm/create-post']['reason'] );
		$this->assertSame( aafm_ability_lock_note( 'read_only' ), $rows['aafm/create-post']['reason_text'] );
	}

	public function test_the_high_risk_lock_lists_the_enabled_high_risk_tools(): void {
		$this->lock_create_post();
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts', 'aafm/create-post' ) );
		$rows = $this->rows();
		$this->assertSame( array( 'aafm/create-post' ), array_keys( $rows ) );
		$this->assertSame( 'high_risk', $rows['aafm/create-post']['reason'] );
	}

	public function test_the_omitted_option_lists_each_name_with_its_stored_reason_label(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		update_option( AAFM_OMITTED_ABILITIES_OPTION, array( 'aafm/get-posts' => 'tool_cap' ) );
		$row = $this->rows()['aafm/get-posts'];
		$this->assertSame( 'omitted', $row['reason'] );
		$this->assertSame( ucfirst( aafm_omitted_reason_label( 'tool_cap' ) ) . '.', $row['reason_text'] );
	}

	public function test_precedence_is_unknown_then_host_then_lock_then_omitted_and_one_row_per_name(): void {
		$this->add_inactive_host_ability( 'aafm/fake-inactive-host' );
		$this->lock_create_post();
		update_option( 'aafm_enabled_abilities', array( 'aafm/gone-tool', 'aafm/fake-inactive-host', 'aafm/create-post', 'aafm/get-posts' ) );
		update_option(
			AAFM_OMITTED_ABILITIES_OPTION,
			array(
				'aafm/gone-tool'          => 'tool_cap',
				'aafm/fake-inactive-host' => 'tool_cap',
				'aafm/create-post'        => 'tool_cap',
				'aafm/get-posts'          => 'tool_cap',
			)
		);
		$data = aafm_missing_tools_data();
		$this->assertCount( 4, $data['rows'] );
		$this->assertSame(
			array( 'unknown', 'host_inactive', 'high_risk', 'omitted' ),
			array_column( $data['rows'], 'reason' )
		);
	}

	public function test_the_card_renders_nothing_when_nothing_is_missing_and_escapes_names_when_it_does(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-posts' ) );
		ob_start();
		aafm_render_missing_tools_card( aafm_missing_tools_data() );
		$this->assertSame( '', (string) ob_get_clean() );

		update_option( 'aafm_enabled_abilities', array( 'aafm/<script>x' ) );
		ob_start();
		aafm_render_missing_tools_card( aafm_missing_tools_data() );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="aafm-missing-tools"', $html );
		$this->assertStringContainsString( 'Why is my tool missing?', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'aafm/&lt;script&gt;x', $html );
	}

	public function test_data_is_json_safe_and_rendering_writes_nothing(): void {
		update_option( 'aafm_enabled_abilities', array( 'aafm/gone-tool' ) );
		$writes = array();
		add_filter(
			'query',
			static function ( $sql ) use ( &$writes ) {
				if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', (string) $sql ) ) {
					$writes[] = $sql;
				}
				return $sql;
			}
		);
		$data = aafm_missing_tools_data();
		ob_start();
		aafm_render_missing_tools_card( $data );
		ob_end_clean();
		$this->assertSame( array(), $writes );
		$this->assertSame( $data, json_decode( (string) wp_json_encode( $data ), true ) );
	}
}
