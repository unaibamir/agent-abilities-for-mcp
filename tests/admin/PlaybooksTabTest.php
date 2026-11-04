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
				'body'        => 'Read it with the Get post tool.',
				'active'      => '1',
				'abilities'   => '["aafm/get-post"]',
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
		$this->assertSame( array( 'aafm/get-post' ), $json['data']['playbook']['abilities'] );
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
		// Core encodes an apostrophe as &#039; or, on newer versions, &apos;.
		$this->assertMatchesRegularExpression( '/>Fish &amp; &quot;chips&quot; (&#039;|&apos;)n(&#039;|&apos;) more<\/td>/', $html );
		preg_match( '/data-playbook-title="([^"]*)"/', $html, $title );
		preg_match( '/data-playbook-body="([^"]*)"/', $html, $body );
		$this->assertSame( $saved['title'], html_entity_decode( $title[1], ENT_QUOTES | ENT_HTML5 ) );
		$this->assertSame( $saved['body'], html_entity_decode( $body[1], ENT_QUOTES | ENT_HTML5 ) );
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
		$this->assertMatchesRegularExpression( '/id="aafm-playbooks-list".*id="aafm-playbook-starters".*id="aafm-playbook-editor"/s', $html, 'Cards run Your playbooks, Starter playbooks, Write a playbook.' );
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
		$this->assertStringContainsString( 'aafm-notice-info', $this->render() );
		update_option( 'aafm_enabled_abilities', array( 'aafm/list-playbooks', 'aafm/get-playbook' ) );
		$this->assertStringNotContainsString( 'aafm-notice-info', $this->render() );
	}

	public function test_starter_button_is_disabled_once_added(): void {
		$this->acting_as( 'administrator' );
		$this->assertDoesNotMatchRegularExpression( '/data-starter="publish-checklist"[^>]*disabled/', $this->render() );
		aafm_add_starter_playbook( 'publish-checklist' );
		$this->assertMatchesRegularExpression( '/data-starter="publish-checklist"[^>]*disabled/', $this->render() );
	}

	public function test_picker_renders_every_ability_with_its_state(): void {
		$this->acting_as( 'administrator' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post' ) );
		aafm_save_playbook( $this->fields() );
		$html = $this->render();
		$this->assertStringContainsString( '<fieldset class="aafm-allowlist-group"><legend>', $html );
		$count = 0;
		foreach ( aafm_allowlist_ability_catalog() as $group ) {
			$count += count( $group['abilities'] );
		}
		$this->assertSame( $count, substr_count( $html, 'name="abilities[]"' ) );
		$this->assertMatchesRegularExpression( '#value="aafm/get-post"> Get post</label>#', $html, 'An enabled ability has no pill.' );
		$this->assertMatchesRegularExpression( '#value="aafm/get-posts"> Get posts <span class="aafm-pill aafm-pill-warn">Off</span>#', $html );
		$this->assertMatchesRegularExpression( '#value="aafm/wc-get-product"> [^<]+ <span class="aafm-pill aafm-pill-neutral">Plugin not active</span>#', $html );
		$this->assertStringContainsString( 'data-playbook-abilities="[&quot;aafm\/get-post&quot;]"', $html );
		$this->assertStringContainsString( 'data-aafm-untracked', $html );
	}

	public function test_buttons_and_links_name_their_target(): void {
		$this->acting_as( 'administrator' );
		aafm_save_playbook( $this->fields( array( 'title' => 'Fish & chips' ) ) );
		$html = $this->render();
		$this->assertStringContainsString( 'aria-label="Edit Fish &amp; chips"', $html );
		$this->assertStringContainsString( 'aria-label="Delete Fish &amp; chips"', $html );
		$this->assertStringContainsString( 'aria-label="Add Publish checklist to my playbooks"', $html );
		$this->assertSame( 2, substr_count( $html, '<a href="' . AAFM_PLAYBOOK_LIBRARY_URL . '" target="_blank" rel="noopener noreferrer">' ) );
		$this->assertStringContainsString( '(opens in a new tab)', $html );
		$this->assertStringContainsString( 'class="aafm-save-status aafm-playbook-starters-status"', $html );
		preg_match( '/Plain text\..*?agents cannot\./s', $html, $help );
		$this->assertNotEmpty( $help );
		$this->assertStringNotContainsString( 'aafm/', $help[0] );
	}

	public function test_shop_starter_is_addable_with_woocommerce_inactive(): void {
		$this->acting_as( 'administrator' );
		$html = $this->render();
		$this->assertMatchesRegularExpression( '#<strong>Shop tidy-up</strong> <span class="aafm-pill aafm-pill-neutral">Needs WooCommerce</span>.*?data-starter="shop-tidy-up" aria-label="[^"]+">#s', $html, 'Listed with its pill and an enabled button.' );
	}

	public function test_an_owner_playbook_with_a_starter_title_does_not_disable_the_starter(): void {
		$this->acting_as( 'administrator' );
		aafm_save_playbook( $this->fields( array( 'title' => 'Publish checklist' ) ) );
		$this->assertDoesNotMatchRegularExpression( '/data-starter="publish-checklist"[^>]*disabled/', $this->render() );
		aafm_add_starter_playbook( 'draft-a-post' );
		$this->assertMatchesRegularExpression( '/data-starter="draft-a-post"[^>]*disabled/', $this->render() );
	}
}
