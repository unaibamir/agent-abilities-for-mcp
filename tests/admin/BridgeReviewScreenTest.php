<?php
/**
 * The "New tools since your last review" card on the Other plugins tab, and the starter button.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\AjaxHarness;
use AAFM\Tests\Support\ForeignAbilities;
use AAFM\Tests\TestCase;

final class BridgeReviewScreenTest extends TestCase {

	use AjaxHarness;
	use ForeignAbilities;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_bridge_review' );
		delete_option( 'aafm_enabled_bridged_abilities' );
		$this->acting_as( 'administrator' );
		aafm_bridge_review_sync();
	}

	public function tear_down(): void {
		$this->unregister_foreign_abilities();
		delete_option( 'aafm_bridge_review' );
		delete_option( 'aafm_enabled_bridged_abilities' );
		delete_option( 'aafm_read_only_mode' );
		$this->reset_ajax_harness();
		parent::tear_down();
	}

	/**
	 * Register a plugin with reads, a write and an unlabelled tool, after the baseline.
	 *
	 * @return void
	 */
	private function register_new_plugin(): void {
		$this->register_foreign_abilities(
			'screenplugin',
			array(
				'list-things'  => array( 'readonly' => true ),
				'list-entries' => array( 'readonly' => true ),
				'save-thing'   => array( 'destructive' => false ),
				'silent-thing' => array(),
			)
		);
	}

	/**
	 * The tab's HTML.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		aafm_render_bridge_directory();
		return (string) ob_get_clean();
	}

	/**
	 * Only the review card's HTML.
	 *
	 * @param string $html Tab HTML.
	 * @return string
	 */
	private function card( string $html ): string {
		$start = strpos( $html, '<section class="aafm-section aafm-card aafm-bridge-review"' );
		$this->assertNotFalse( $start, 'The review card is missing.' );
		$end = strpos( $html, '</section>', (int) $start );
		return substr( $html, (int) $start, (int) $end - (int) $start );
	}

	public function test_the_card_is_absent_when_nothing_is_new(): void {
		$this->assertStringNotContainsString( 'aafm-bridge-review', $this->render() );
	}

	public function test_the_card_lists_new_tools_grouped_by_plugin_with_labels(): void {
		$this->register_new_plugin();
		$this->register_foreign_abilities( 'otherplugin', array( 'list-widgets' => array( 'readonly' => true ) ) );

		$card = $this->card( $this->render() );

		$this->assertStringContainsString( 'New tools since your last review', $card );
		$this->assertStringContainsString( '<h3>Screenplugin', $card );
		$this->assertStringContainsString( '<h3>Otherplugin', $card );
		$this->assertStringContainsString( '4 new tools', $card );
		$this->assertStringContainsString( '1 new tool<', $card );
		$this->assertStringContainsString( '<code>screenplugin/save-thing</code>', $card );
		$this->assertStringContainsString( '<span class="aafm-badge aafm-badge-write">Write</span>', $card );
		$this->assertStringContainsString( '<span class="aafm-badge aafm-badge-read">Read</span>', $card );
		$this->assertStringContainsString( 'May return personal data.', $card, 'list-entries is flagged.' );
		$this->assertStringContainsString( 'treated as destructive', $card, 'An unlabelled tool does not look like the plugin called it destructive.' );
	}

	public function test_a_hostile_plugin_name_stays_text_in_the_card(): void {
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					'hostile-card/hostile-card.php' => array(
						'Name'       => 'Evil "Co" <b>bold</b>',
						'TextDomain' => '',
					),
				),
			),
			'plugins'
		);
		update_option( 'active_plugins', array( 'hostile-card/hostile-card.php' ) );
		$this->register_foreign_abilities( 'hostile-card', array( 'list-widgets' => array( 'readonly' => true ) ) );

		$card = $this->card( $this->render() );

		$this->assertStringNotContainsString( '<b>', $card );
		$this->assertStringContainsString( '<h3>Evil &quot;Co&quot; bold', $card );
		$this->assertStringContainsString( 'data-bridge-name="Evil &quot;Co&quot; bold"', $card );
	}

	public function test_the_card_has_no_bridged_ability_checkboxes(): void {
		$this->register_new_plugin();

		$card = $this->card( $this->render() );

		$this->assertStringNotContainsString( 'bridged_abilities[]', $card );
		$this->assertStringNotContainsString( '<input', $card );
	}

	public function test_a_save_while_the_card_is_open_leaves_new_tools_off(): void {
		$this->register_new_plugin();
		$this->assertStringContainsString( 'aafm-bridge-review', $this->render() );

		$this->intercept_die();
		$nonce                      = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']             = $nonce;
		$_REQUEST['nonce']          = $nonce;
		$_POST['bridged_abilities'] = array();
		$this->run_handler( 'aafm_ajax_save_bridged_abilities' );

		$this->assertSame( array(), aafm_get_stored_bridged_abilities_raw() );
		$this->assertContains( 'screenplugin/list-things', aafm_bridge_new_tools(), 'A save reviews nothing.' );
	}

	public function test_each_group_has_the_starter_button_and_a_plugin_with_no_reads_says_so(): void {
		$this->register_new_plugin();
		$this->register_foreign_abilities( 'writesonly', array( 'save-thing' => array( 'destructive' => false ) ) );

		$html = $this->render();
		$form = substr( $html, (int) strpos( $html, '<form id="aafm-bridge-form"' ) );

		$this->assertMatchesRegularExpression( '/class="aafm-btn aafm-btn-secondary aafm-bridge-starter" data-bridge-plugin="screenplugin" data-bridge-name="Screenplugin">Allow only read tools</', $form );
		$this->assertMatchesRegularExpression( '/class="aafm-btn aafm-btn-secondary aafm-bridge-starter" data-bridge-plugin="writesonly" data-bridge-name="Writesonly">Allow only read tools</', $form );
		$this->assertSame( 1, substr_count( $form, 'labels none of its tools as read-only' ) );
		$this->assertStringContainsString( '<details class="aafm-card aafm-integration-card aafm-integration-writesonly" data-bridge-plugin="writesonly">', $form );
	}

	public function test_the_unavailable_group_has_no_starter(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'gone-plugin/tool' ) );

		$html = $this->render();

		$this->assertStringContainsString( '<details class="aafm-card aafm-integration-card aafm-integration-unavailable is-disabled" data-bridge-plugin="unavailable">', $html );
		$this->assertStringNotContainsString( 'aafm-bridge-starter" data-bridge-plugin="unavailable"', $html );
	}

	public function test_a_known_plugin_group_uses_the_key_the_handlers_take(): void {
		$this->register_foreign_abilities( 'aioseo-posts', array( 'list-things' => array( 'readonly' => true ) ) );
		$this->register_foreign_abilities( 'aioseo-settings', array( 'get-things' => array( 'readonly' => true ) ) );

		$card = $this->card( $this->render() );

		$this->assertArrayHasKey( 'AAFM-KNOWN:aioseo', aafm_bridge_group_slugs( aafm_bridge_foreign_slugs() ) );
		$this->assertStringContainsString( 'data-bridge-plugin="AAFM-KNOWN:aioseo"', $card, 'kses keeps the attribute and its colon.' );
		$this->assertStringContainsString( '2 new tools', $card, 'The merged plugin is one block.' );
	}

	public function test_the_card_markup_uses_the_shared_section_helper_and_one_h2(): void {
		$this->register_new_plugin();

		$card = $this->card( $this->render() );

		$this->assertStringContainsString( '<div class="aafm-card-head">', $card );
		$this->assertStringContainsString( '<h2 class="aafm-card-head-title">New tools since your last review</h2>', $card );
		$this->assertStringContainsString( '<div class="aafm-card-pad aafm-section-body"><p class="aafm-card-desc">', $card );
		$this->assertSame( 1, substr_count( $card, '<h2' ) );
		$this->assertSame( 1, substr_count( $card, 'aafm-card ' ), 'No card inside the card.' );
	}

	public function test_the_review_card_body_has_one_status_live_region(): void {
		$this->register_new_plugin();

		$card = $this->card( $this->render() );

		$this->assertSame( 1, substr_count( $card, 'role="status"' ) );
		$this->assertStringContainsString( '<p class="aafm-bridge-review-status" role="status"></p>', $card );
	}

	public function test_the_card_description_says_the_starter_applies_on_confirmation(): void {
		$this->register_new_plugin();

		$desc = $this->card( $this->render() );
		$desc = substr( $desc, (int) strpos( $desc, 'aafm-card-desc' ) );
		$desc = substr( $desc, 0, (int) strpos( $desc, '</p>' ) );

		$this->assertStringContainsString( 'Save', $desc );
		$this->assertStringContainsString( 'confirm', $desc );
	}

	public function test_the_card_has_a_button_per_plugin_and_one_mark_all(): void {
		$this->register_new_plugin();
		$this->register_foreign_abilities( 'otherplugin', array( 'list-widgets' => array( 'readonly' => true ) ) );

		$card = $this->card( $this->render() );

		$this->assertSame( 2, substr_count( $card, '>Mark as reviewed<' ) );
		$this->assertSame( 2, substr_count( $card, '>Allow only read tools<' ) );
		$this->assertSame( 1, substr_count( $card, '>Mark all as reviewed<' ) );
		$this->assertStringContainsString( 'aafm-bridge-mark-reviewed" data-bridge-plugin="all"', $card );
		$this->assertStringContainsString( 'aafm-bridge-mark-reviewed" data-bridge-plugin="otherplugin" data-bridge-name="Otherplugin"', $card );
		$this->assertStringContainsString( 'type="button"', $card, 'kses keeps the button type, so a click never submits anything.' );
	}

	public function test_mark_all_as_reviewed_hides_the_card_on_the_next_render(): void {
		$this->register_new_plugin();
		$this->assertStringContainsString( 'aafm-bridge-review', $this->render() );

		$this->intercept_die();
		$nonce             = wp_create_nonce( 'aafm_admin' );
		$_POST['nonce']    = $nonce;
		$_REQUEST['nonce'] = $nonce;
		$_POST['plugin']   = 'all';
		$json              = $this->run_handler( 'aafm_ajax_bridge_mark_reviewed' );

		$this->assertTrue( $json['success'] );
		$this->assertStringNotContainsString( 'aafm-bridge-review', $this->render() );
		$this->assertSame( array(), aafm_get_stored_bridged_abilities_raw(), 'Reviewing enables nothing.' );
	}

	public function test_a_locked_write_that_is_stored_on_is_counted_by_the_starter_confirm(): void {
		$this->register_new_plugin();
		update_option( 'aafm_enabled_bridged_abilities', array( 'screenplugin/save-thing' ) );
		update_option( 'aafm_read_only_mode', '1' );

		$html = $this->render();

		$this->assertMatchesRegularExpression( '/data-risk="write" data-aafm-stored-on="1"/', $html, 'A locked row has no switch, so the confirm reads this instead.' );
		$this->assertDoesNotMatchRegularExpression( '/data-risk="destructive" data-aafm-stored-on/', $html, 'A locked tool that is not stored is not counted.' );
	}

	public function test_all_new_strings_are_translatable(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file in a test.
		$php   = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/admin/bridge-directory.php' );
		$start = strpos( $php, 'function aafm_render_bridge_review_card(' );
		$this->assertNotFalse( $start );
		$body = substr( $php, (int) $start, (int) strpos( $php, "\n}\n", (int) $start ) - (int) $start );

		$this->assertDoesNotMatchRegularExpression( '/>[A-Z][a-z]+( [a-z]+)*<|>[A-Z][a-z]+( [a-z]+)*\'/', $body, 'A bare English literal between tags is not translatable.' );
		$this->assertStringContainsString( 'esc_html__( \'Mark as reviewed\'', $body );
		$this->assertStringContainsString( 'esc_html__( \'Mark all as reviewed\'', $body );
	}
}
