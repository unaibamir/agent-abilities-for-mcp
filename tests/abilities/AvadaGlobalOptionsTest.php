<?php
/**
 * Tests for aafm/avada-get-global-options.
 *
 * The fixture tests/Fixtures/Avada/fusion-options.txt is a byte copy of the Global Options row Avada 7.16.1
 * wrote through Fusion_Settings::set() on a disposable site, with planted secrets (smtp_password,
 * mailchimp_api) and code (custom_css, space_head) that must never come back.
 *
 * The first test needs the Avada class to be absent, so it runs before any other test here loads
 * the stand-in (tests/Fixtures/Avada/AvadaStandIn.php); its precondition fails loudly otherwise.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class AvadaGlobalOptionsTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		if ( class_exists( 'Avada', false ) ) {
			\Avada::$option_name = '';
		}
		parent::tear_down();
	}

	private function load_avada(): void {
		require_once dirname( __DIR__ ) . '/Fixtures/Avada/AvadaStandIn.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		$raw    = (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/Avada/fusion-options.txt' );
		$stored = unserialize( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- the plugin's own fixture.
		$this->assertSame( $raw, serialize( $stored ), 'The row is stored byte for byte as Avada wrote it.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		update_option( 'fusion_options', $stored );
	}

	public function test_without_the_avada_theme_class_it_errors_instead_of_reading_fusion_options(): void {
		$this->assertFalse( class_exists( 'Avada', false ), 'Precondition: this test runs before the stand-in loads.' );
		update_option( 'fusion_options', array( 'site_width' => '1200px' ) );

		$out = aafm_exec_avada_get_global_options( array() );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_avada_theme_inactive', $out->get_error_code() );
	}

	public function test_it_returns_only_allowlisted_keys_as_stored(): void {
		$this->load_avada();

		$out = aafm_exec_avada_get_global_options( array() );

		$this->assertSame( 'fusion_options', $out['option_name'] );
		$options = (array) $out['options'];
		$this->assertSame( '1200px', $options['site_width'] );
		$this->assertSame( "Copyright 2026 Demo Shop's team", $options['footer_text'] );
		$this->assertSame( '', $options['maintenance_mode'] );
		$this->assertSame(
			array(
				'font-family' => 'Inter',
				'font-size'   => '16px',
				'line-height' => '1.72',
			),
			$options['body_typography']
		);
		$this->assertEqualsCanonicalizing( array( 'site_width', 'primary_color', 'link_color', 'footer_copyright', 'footer_text', 'maintenance_mode', 'body_typography' ), array_keys( $options ) );
		$this->assertContains( 'logo', $out['not_set'] );
		$this->assertCount( 14, $out['not_set'] );
	}

	public function test_planted_secrets_and_code_never_appear_in_the_output(): void {
		$this->load_avada();

		$wire = (string) wp_json_encode( aafm_exec_avada_get_global_options( array() ) );

		foreach ( array( 'planted-smtp-secret', 'planted-mailchimp-key', 'smtp_password', 'mailchimp_api', 'custom_css', 'space_head', 'body{color:red}', 'planted()' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $wire );
		}
	}

	public function test_keys_returns_only_those_and_reports_the_unset_ones(): void {
		$this->load_avada();

		$out = aafm_exec_avada_get_global_options( array( 'keys' => array( 'primary_color', 'logo' ) ) );

		$this->assertSame( array( 'primary_color' => '#65bc7b' ), (array) $out['options'] );
		$this->assertSame( array( 'logo' ), $out['not_set'] );
	}

	public function test_a_key_outside_the_allowlist_is_never_read_even_if_validation_is_bypassed(): void {
		$this->load_avada();

		$out = aafm_exec_avada_get_global_options( array( 'keys' => array( 'smtp_password', 'site_width' ) ) );

		$this->assertSame( array( 'site_width' => '1200px' ), (array) $out['options'] );
		$this->assertSame( array(), $out['not_set'] );
	}

	public function test_the_per_language_option_name_is_read_and_echoed(): void {
		$this->load_avada();
		\Avada::$option_name = 'fusion_options_de';
		update_option( 'fusion_options_de', array( 'site_width' => '1100px' ) );

		$out = aafm_exec_avada_get_global_options( array( 'keys' => array( 'site_width' ) ) );

		$this->assertSame( 'fusion_options_de', $out['option_name'] );
		$this->assertSame( array( 'site_width' => '1100px' ), (array) $out['options'] );
	}

	public function test_a_missing_or_malformed_row_is_an_error_not_an_empty_object(): void {
		$this->load_avada();
		delete_option( 'fusion_options' );

		$out = aafm_exec_avada_get_global_options( array() );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_avada_options_unreadable', $out->get_error_code() );

		update_option( 'fusion_options', 'not an array' );
		$out = aafm_exec_avada_get_global_options( array() );
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_avada_options_unreadable', $out->get_error_code() );
	}

	public function test_the_allowlist_is_the_approved_list_and_holds_nothing_secret(): void {
		$keys = aafm_avada_global_option_keys();

		$this->assertSame(
			array( 'site_width', 'layout', 'primary_color', 'color_palette', 'link_color', 'body_typography', 'h1_typography', 'h2_typography', 'h3_typography', 'h4_typography', 'h5_typography', 'h6_typography', 'header_position', 'header_layout', 'logo', 'page_title_bar', 'footer_widgets', 'footer_copyright', 'footer_text', 'responsive', 'maintenance_mode' ),
			$keys
		);
		foreach ( $keys as $key ) {
			// "head" as a whole segment (space_head), so the approved header_* keys pass.
			$this->assertSame( 0, preg_match( '/key|secret|token|pass|api|code|css|script|(^|_)head(_|$)/', $key ), $key . ' looks like a secret or code setting.' );
		}
		$args = aafm_args_avada_get_global_options();
		$this->assertSame( $keys, $args['input_schema']['properties']['keys']['items']['enum'] );
	}

	public function test_an_editor_is_refused_and_an_admin_allowed(): void {
		$args = aafm_args_avada_get_global_options();
		$this->assertTrue( (bool) call_user_func( $args['permission_callback'], array() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( (bool) call_user_func( $args['permission_callback'], array() ) );
	}

	public function test_it_is_a_registered_read_only_while_avada_is_active(): void {
		$this->assertSame( 'read', aafm_get_abilities_registry()['aafm/avada-get-global-options']['risk'] );

		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		add_filter( 'aafm_integration_active_avada', '__return_false' );
		aafm_flush_registry_cache();
		try {
			$this->assertArrayNotHasKey( 'aafm/avada-get-global-options', aafm_get_abilities_registry() );
		} finally {
			remove_filter( 'aafm_integration_active_avada', '__return_false' );
			add_filter( 'aafm_integration_active_avada', '__return_true' );
			aafm_flush_registry_cache();
		}
	}

	public function test_schema_and_annotations(): void {
		$args = aafm_args_avada_get_global_options();
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertTrue( $args['input_schema']['properties']['keys']['uniqueItems'] );
		$this->assertSame( 30, $args['input_schema']['properties']['keys']['maxItems'] );
		$this->assertSame( array( 'option_name', 'options', 'not_set' ), array_keys( $args['output_schema']['properties'] ) );
		$this->assertTrue( $args['meta']['annotations']['readonly'] );
		$this->assertFalse( $args['meta']['annotations']['destructive'] );
		$this->assertSame( 'aafm-reads', $args['category'] );
	}
}
