<?php
/**
 * The Playbooks tab: placement, AJAX guards and round trips, escaping, and the three cards.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\TestCase;

final class PlaybooksTabTest extends TestCase {

	use AjaxHarness;

	private const ACTIONS = array( 'aafm_ajax_save_playbook', 'aafm_ajax_delete_playbook', 'aafm_ajax_add_starter_playbook' );

	public function tear_down(): void {
		delete_option( 'aafm_playbooks' );
		$this->reset_ajax_harness();
		parent::tear_down();
	}

	/**
	 * The rendered tab.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		aafm_render_playbooks_tab();
		return (string) ob_get_clean();
	}

	/**
	 * Valid save fields.
	 *
	 * @param array<string,string> $over Overrides.
	 * @return array<string,string>
	 */
	private function fields( array $over = array() ): array {
		return array_merge(
			array(
				'title'       => 'Publish checklist',
				'description' => 'Check a draft.',
				'body'        => 'Read it with aafm/get-post.',
				'active'      => '1',
			),
			$over
		);
	}

	public function test_tab_is_registered_after_abilities(): void {
		$keys = array_keys( aafm_admin_tabs() );
		$this->assertSame( 'playbooks', $keys[ array_search( 'abilities', $keys, true ) + 1 ] );
	}

	public function test_ajax_requires_nonce_and_manage_options(): void {
		foreach ( self::ACTIONS as $action ) {
			$this->post_as( 'administrator', array_merge( $this->fields(), array( 'starter' => 'publish-checklist' ) ) );
			$_POST['nonce']    = 'bad';
			$_REQUEST['nonce'] = 'bad';
			$died              = false;
			ob_start();
			try {
				$action();
			} catch ( \WPDieException $e ) {
				$died = true;
			}
			$out = (string) ob_get_clean();
			$this->assertTrue( $died, $action );
			$this->assertStringNotContainsString( '"success":true', $out );

			$this->post_as( 'editor', array_merge( $this->fields(), array( 'starter' => 'publish-checklist' ) ) );
			$json = $this->run_handler( $action );
			$this->assertFalse( $json['success'] );
		}
		$this->assertNull( get_option( 'aafm_playbooks', null ) );
	}

	public function test_ajax_save_round_trip(): void {
		$this->post_as( 'administrator', $this->fields() );
		$json = $this->run_handler( 'aafm_ajax_save_playbook' );
		$this->assertTrue( $json['success'] );
		$this->assertSame( array( 'playbook', 'uses_count', 'unavailable_count' ), array_keys( $json['data'] ) );
		$this->assertSame( 1, $json['data']['uses_count'] );
		$slug = $json['data']['playbook']['slug'];

		$this->post_as(
			'administrator',
			$this->fields(
				array(
					'slug'   => $slug,
					'title'  => 'Renamed',
					'active' => '0',
				)
			)
		);
		$json = $this->run_handler( 'aafm_ajax_save_playbook' );
		$this->assertSame( 'Renamed', aafm_playbook( $slug )['title'] );
		$this->assertFalse( aafm_playbook( $slug )['active'] );

		$this->post_as( 'administrator', array( 'slug' => $slug ) );
		$json = $this->run_handler( 'aafm_ajax_delete_playbook' );
		$this->assertTrue( $json['success'] );
		$this->assertNull( get_option( 'aafm_playbooks', null ) );
	}

	public function test_two_saves_from_a_new_editor_make_one_playbook(): void {
		$this->post_as( 'administrator', $this->fields() );
		$slug = $this->run_handler( 'aafm_ajax_save_playbook' )['data']['playbook']['slug'];
		$this->post_as(
			'administrator',
			$this->fields(
				array(
					'slug' => $slug,
					'body' => 'Second body.',
				)
			)
		);
		$this->run_handler( 'aafm_ajax_save_playbook' );
		$list = aafm_playbooks();
		$this->assertCount( 1, $list );
		$this->assertSame( 'Second body.', $list[0]['body'] );
	}

	public function test_ajax_errors_name_the_range(): void {
		$this->post_as( 'administrator', $this->fields( array( 'title' => str_repeat( 'a', 81 ) ) ) );
		$json = $this->run_handler( 'aafm_ajax_save_playbook' );
		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( '80', $json['data']['message'] );
	}

	public function test_tab_escapes_titles_and_bodies(): void {
		$this->acting_as( 'administrator' );
		$saved = aafm_save_playbook(
			array(
				'title' => 'Fish & "chips" \'n\' more',
				'body'  => "a & b \"c\"\nline two",
			)
		);
		$html  = $this->render();
		$this->assertStringContainsString( 'data-playbook-title="' . esc_attr( $saved['title'] ) . '"', $html );
		$this->assertStringContainsString( '>Fish &amp; &quot;chips&quot; &#039;n&#039; more</td>', $html );
		preg_match( '/data-playbook-title="([^"]*)"/', $html, $title );
		preg_match( '/data-playbook-body="([^"]*)"/', $html, $body );
		$this->assertSame( $saved['title'], html_entity_decode( $title[1], ENT_QUOTES ) );
		$this->assertSame( $saved['body'], html_entity_decode( $body[1], ENT_QUOTES ) );
		$this->assertMatchesRegularExpression( '#<textarea id="aafm-playbook-body"[^>]*></textarea>#', $html );
	}

	public function test_edit_data_survives_the_section_kses(): void {
		$this->acting_as( 'administrator' );
		aafm_save_playbook( $this->fields() );
		$html = $this->render();
		foreach ( array( 'slug', 'title', 'description', 'body', 'active' ) as $attr ) {
			$this->assertStringContainsString( 'data-playbook-' . $attr . '="', $html );
		}
		$this->assertStringContainsString( 'data-starter="publish-checklist"', $html );
	}

	public function test_cards_use_the_section_helper(): void {
		$this->acting_as( 'administrator' );
		$html = $this->render();
		$this->assertSame( 3, preg_match_all( '/<section[^>]*class="aafm-section aafm-card/', $html ) );
		$this->assertSame( 3, substr_count( $html, 'aafm-card-head-title' ) );
		$this->assertStringContainsString( 'id="aafm-playbook-form"', $html );
		$this->assertStringContainsString( '<textarea', $html );
		$icons = array();
		foreach ( array( 'playbooks', 'terminal', 'star' ) as $icon ) {
			$icons[] = aafm_icon( $icon );
			$this->assertNotSame( '', end( $icons ) );
		}
		$this->assertCount( 3, array_unique( $icons ) );
	}

	public function test_notice_shows_when_abilities_are_off(): void {
		$this->acting_as( 'administrator' );
		aafm_save_playbook( $this->fields() );
		update_option( 'aafm_enabled_abilities', array() );
		$this->assertStringContainsString( 'aafm-notice', $this->render() );
		update_option( 'aafm_enabled_abilities', array( 'aafm/list-playbooks', 'aafm/get-playbook' ) );
		$this->assertStringNotContainsString( 'aafm-notice', $this->render() );
	}

	public function test_starter_button_is_disabled_once_added(): void {
		$this->acting_as( 'administrator' );
		$this->assertDoesNotMatchRegularExpression( '/data-starter="publish-checklist" disabled/', $this->render() );
		aafm_add_starter_playbook( 'publish-checklist' );
		$this->assertMatchesRegularExpression( '/data-starter="publish-checklist" disabled/', $this->render() );
	}
}
