<?php
/**
 * What each Other plugins group and row says about where a tool came from and how risky it is.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\Support\ForeignAbilities;
use AAFM\Tests\TestCase;

final class BridgeDirectoryLabelsTest extends TestCase {

	use ForeignAbilities;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_enabled_bridged_abilities' );
		$this->acting_as( 'administrator' );
	}

	public function tear_down(): void {
		$this->unregister_foreign_abilities();
		delete_option( 'aafm_enabled_bridged_abilities' );
		parent::tear_down();
	}

	/**
	 * Make a plugin look installed and active. A fresh file per test, because the name lookup is
	 * memoized per active-plugin list.
	 *
	 * @param string $file File relative to the plugins folder.
	 * @param string $name Name header.
	 * @return void
	 */
	private function seed_active_plugin( string $file, string $name ): void {
		wp_cache_set(
			'plugins',
			array(
				'' => array(
					$file => array(
						'Name'       => $name,
						'TextDomain' => '',
					),
				),
			),
			'plugins'
		);
		update_option( 'active_plugins', array( $file ) );
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
	 * One row's HTML, up to the next row.
	 *
	 * @param string $html Tab HTML.
	 * @param string $slug Ability slug.
	 * @return string
	 */
	private function row( string $html, string $slug ): string {
		$value = strpos( $html, 'value="' . $slug . '"' );
		$this->assertNotFalse( $value, "No row for $slug." );
		$start = (int) strrpos( substr( $html, 0, (int) $value ), 'aafm-ability-row' );
		$end   = strpos( $html, 'aafm-ability-row', (int) $value );
		return false === $end ? substr( $html, $start ) : substr( $html, $start, $end - $start );
	}

	public function test_each_group_says_who_provides_it(): void {
		$this->seed_active_plugin( 'wpforms-lite/wpforms.php', 'WPForms Lite' );
		$this->register_foreign_abilities( 'wpforms-lite', array( 'list-forms' => array( 'readonly' => true ) ) );

		$html = $this->render();

		$this->assertStringContainsString( '<p class="aafm-card-desc">Provided by WPForms Lite. Agent Abilities does not maintain these tools.</p>', $html );
	}

	public function test_an_unresolved_namespace_is_not_presented_as_a_plugin_name(): void {
		update_option( 'active_plugins', array() );
		$this->register_foreign_abilities( 'writer', array( 'do-thing' => array( 'readonly' => true ) ) );

		$html = wp_specialchars_decode( $this->render(), ENT_QUOTES );

		$this->assertStringContainsString( 'Provided by a plugin that registers its tools under the "writer" namespace. Agent Abilities does not maintain these tools.', $html );
		$this->assertStringNotContainsString( 'Provided by Writer', $html );
	}

	public function test_a_wordpress_core_tool_is_not_said_to_come_from_a_plugin(): void {
		$this->register_foreign_abilities( 'core', array( 'demo-tool' => array( 'readonly' => true ) ) );

		$html = wp_specialchars_decode( $this->render(), ENT_QUOTES );

		$this->assertStringContainsString( 'Provided by WordPress. Agent Abilities does not maintain these tools.', $html );
		$this->assertStringNotContainsString( 'under the "core" namespace', $html );
	}

	public function test_the_unavailable_group_has_no_source_line(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'gone-plugin/tool' ) );

		$html = $this->render();

		$this->assertStringContainsString( 'Unavailable (plugin', $html );
		$this->assertStringNotContainsString( 'does not maintain these tools', $html );
		$this->assertStringNotContainsString( 'did not label this tool', $html, 'An unavailable row has no annotations to read, so it must not claim the plugin left them out.' );
	}

	public function test_the_integrations_card_says_maintained_by_agent_abilities(): void {
		ob_start();
		aafm_render_integrations_tab();
		$html = (string) ob_get_clean();

		$body = strpos( $html, '<div class="aafm-integration-body">' );
		$this->assertNotFalse( $body );
		$this->assertStringContainsString(
			'<div class="aafm-integration-body"><p class="aafm-card-desc">Maintained by Agent Abilities.</p>',
			$html
		);
	}

	public function test_the_risk_badge_text_is_translated_and_capitalised(): void {
		$this->register_foreign_abilities(
			'demo-risks',
			array(
				'a-read'   => array( 'readonly' => true ),
				'b-write'  => array( 'destructive' => false ),
				'c-delete' => array( 'destructive' => true ),
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( '<span class="aafm-badge aafm-badge-read">Read</span>', $html );
		$this->assertStringContainsString( '<span class="aafm-badge aafm-badge-write">Write</span>', $html );
		$this->assertStringContainsString( '<span class="aafm-badge aafm-badge-destructive">Destructive</span>', $html );

		$translate = static function ( string $text ): string {
			return 'Read' === $text ? 'Lesen' : $text;
		};
		add_filter( 'gettext', $translate );
		$translated = $this->render();
		remove_filter( 'gettext', $translate );
		$this->assertStringContainsString( '<span class="aafm-badge aafm-badge-read">Lesen</span>', $translated );
	}

	public function test_an_unlabelled_tool_shows_the_treated_as_destructive_note_and_a_declared_one_does_not(): void {
		$this->register_foreign_abilities(
			'demo-notes',
			array(
				'silent'   => array(),
				'declared' => array( 'destructive' => true ),
			)
		);

		$html = $this->render();
		$note = 'The plugin did not label this tool, so it is treated as destructive.';

		$this->assertStringContainsString( '<p class="aafm-ability-hint aafm-muted">' . $note . '</p>', $this->row( $html, 'demo-notes/silent' ) );
		$this->assertStringNotContainsString( $note, $this->row( $html, 'demo-notes/declared' ) );
	}

	public function test_a_forms_tool_shows_the_personal_data_indicator_and_a_plain_tool_does_not(): void {
		$this->register_foreign_abilities(
			'demo-data',
			array(
				'list-entries' => array( 'readonly' => true ),
				'list-widgets' => array( 'readonly' => true ),
			)
		);

		$html = $this->render();

		$flagged = $this->row( $html, 'demo-data/list-entries' );
		$this->assertStringContainsString( 'aafm-notice-inline', $flagged );
		$this->assertStringContainsString( 'May return personal data.', $flagged );
		$this->assertStringNotContainsString( 'personal data', $this->row( $html, 'demo-data/list-widgets' ) );
		$this->assertSame( 1, substr_count( $html, aafm_bridge_personal_data_notice( 'forms' ) ), 'The group states the full notice once, under its source line.' );
		$this->assertLessThan(
			strpos( $html, 'data-bridge-bulk="enable"' ),
			strpos( $html, aafm_bridge_personal_data_notice( 'forms' ) ),
			'The group notice sits above the bulk strip.'
		);
	}

	public function test_a_group_with_no_flagged_tool_has_no_group_notice(): void {
		$this->register_foreign_abilities( 'demo-plain', array( 'list-widgets' => array( 'readonly' => true ) ) );

		$this->assertStringNotContainsString( 'personal data', $this->render() );
	}

	public function test_a_hostile_plugin_name_is_escaped_in_the_source_line(): void {
		$this->seed_active_plugin( 'hostile-one/hostile-one.php', '<img src=x onerror=alert(1)>Evil & "Co"<script>alert(1)</script>' );
		$this->register_foreign_abilities( 'hostile-one', array( 'list-things' => array( 'readonly' => true ) ) );

		$html = $this->render();

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'Provided by Evil &amp; &quot;Co&quot;. Agent Abilities does not maintain these tools.', $html );
	}

	public function test_a_name_that_is_only_a_script_tag_leaves_no_markup_behind(): void {
		$this->seed_active_plugin( 'hostile-two/hostile-two.php', '<script>alert(1)</script>' );
		$this->register_foreign_abilities( 'hostile-two', array( 'list-things' => array( 'readonly' => true ) ) );

		$html = $this->render();

		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'under the &quot;hostile-two&quot; namespace', $html );
	}

	public function test_no_new_markup_changes_the_form_fields(): void {
		$this->register_foreign_abilities(
			'demo-fields',
			array(
				'a-read'   => array( 'readonly' => true ),
				'b-write'  => array( 'destructive' => false ),
				'c-delete' => array( 'destructive' => true ),
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'name="bridged_abilities[]" value="demo-fields/a-read"', $html );
		$this->assertStringContainsString( 'name="bridged_abilities[]" value="demo-fields/b-write"', $html );
		$this->assertStringContainsString( 'name="bridged_abilities[]" value="demo-fields/c-delete"', $html );
		$this->assertMatchesRegularExpression( '/class="aafm-ability-row" data-risk="read"/', $html );
		$this->assertMatchesRegularExpression( '/class="aafm-ability-row" data-risk="write"/', $html );
		$this->assertMatchesRegularExpression( '/class="aafm-ability-row is-destructive" data-risk="destructive"/', $html );
		$this->assertSame( 3, substr_count( $html, 'name="bridged_abilities[]"' ), 'The notices add no field to the form.' );
	}

	public function test_inactive_plugin_heading_is_a_clean_string(): void {
		update_option( 'aafm_enabled_bridged_abilities', array( 'gone-plugin/tool' ) );

		$html = $this->render();

		$this->assertStringContainsString( '<h2>' . esc_html__( 'Unavailable (plugin inactive)', 'agent-abilities-for-mcp' ) . '</h2>', $html );
	}

	public function test_every_row_carries_its_slug_including_a_locked_one(): void {
		$this->register_foreign_abilities(
			'demo',
			array(
				'reader' => array( 'readonly' => true ),
				'writer' => array(),
			)
		);
		add_filter( 'aafm_high_risk_abilities', static fn( array $e ): array => array_merge( $e, array( 'demo/writer' ) ) );

		$html = $this->render();
		remove_all_filters( 'aafm_high_risk_abilities' );

		$this->assertStringNotContainsString( 'value="demo/writer"', $html, 'Precondition: the writer row is locked and has no input.' );
		$this->assertStringContainsString( 'data-slug="demo/writer"', $html );
		$this->assertStringContainsString( 'data-slug="demo/reader"', $html );
	}
}
