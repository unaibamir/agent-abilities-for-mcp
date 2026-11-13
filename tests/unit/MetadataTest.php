<?php
/**
 * Asserts the plugin headers declare the correct minimum environment.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class MetadataTest extends TestCase {

	private function plugin_headers(): array {
		return get_plugin_data( AAFM_PLUGIN_FILE, false, false );
	}

	public function test_requires_at_least_wp_69(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertSame( '6.9', $this->plugin_headers()['RequiresWP'] );
	}

	public function test_requires_php_74(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertSame( '7.4', $this->plugin_headers()['RequiresPHP'] );
	}

	public function test_version_constant_matches_header(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertSame( $this->plugin_headers()['Version'], AAFM_VERSION );
	}

	public function test_release_version_is_one_eleven_three(): void {
		$this->assertSame( '1.11.3', AAFM_VERSION );
	}

	public function test_the_faq_explains_the_adapter_conflict_notice(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own readme.
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		$this->assertStringContainsString( '= Why do I see "Another version of MCP Adapter is already loaded"? =', $readme );
		$this->assertLessThan( strpos( $readme, '= Why do I see "Another version of MCP Adapter' ), strpos( $readme, '= Do I need to install the MCP Adapter?' ), 'It follows the adapter question.' );
	}

	public function test_readme_stable_tag_matches_version(): void {
		// Reading our own bundled readme from a local path - not a remote fetch.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(.+)$/m', $readme, $matches ) );
		$this->assertSame( AAFM_VERSION, trim( $matches[1] ) );
	}

	/**
	 * README.md's Stable tag row is the GitHub-facing half of the same fact readme.txt carries,
	 * and README.md is the file that has actually drifted before: it was still calling ChatGPT
	 * unsupported two releases after that shipped, which is what forced 1.2.1.
	 */
	public function test_readme_md_stable_tag_matches_version(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'README.md' );
		$this->assertSame( 1, preg_match( '/^\|\s*\*\*Stable tag\*\*\s*\|\s*(.+?)\s*\|$/m', $readme, $matches ) );
		$this->assertSame( AAFM_VERSION, trim( $matches[1] ) );
	}

	/**
	 * The MCP registry manifest, server.json, is the other unguarded pin. The release workflow
	 * rewrites the published value with jq at publish time, so the registry itself is never
	 * wrong, but the repo literal only earns a non-failing ::warning:: when it drifts, which
	 * means it can sit stale for a whole release cycle with nothing stopping it.
	 */
	public function test_server_json_version_matches_version(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$manifest = json_decode( (string) file_get_contents( AAFM_PLUGIN_DIR . 'server.json' ), true );
		$this->assertIsArray( $manifest );
		$this->assertArrayHasKey( 'version', $manifest );
		$this->assertSame( AAFM_VERSION, $manifest['version'] );
	}

	/**
	 * The blanket "argument values are never stored" claim is false and has been since
	 * activity-log v5 (1.5.0): aafm_activity_detail_field() stores ids, meta key names, slugs,
	 * and enum members in the detail column by design, and several of those arrive as argument
	 * values. The honest claim is that free-text content (bodies, emails, anything written) is
	 * never stored, and identifiers are. Pin both readmes so the blanket wording cannot come back
	 * and the honest scope stays stated.
	 */
	public function test_readmes_do_not_overpromise_about_stored_argument_values(): void {
		$honest_scope = array(
			'readme.txt' => 'never stores the text the AI wrote',
			'README.md'  => 'never stores free-text content',
		);
		foreach ( $honest_scope as $file => $scope ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$text = (string) file_get_contents( AAFM_PLUGIN_DIR . $file );
			$this->assertDoesNotMatchRegularExpression(
				'/values are (still )?never stored/i',
				$text,
				"{$file} must not carry the blanket never-stored claim the detail column disproves."
			);
			$this->assertStringNotContainsString(
				'never the values',
				$text,
				"{$file} must not carry the blanket never-the-values claim."
			);
			$this->assertStringContainsString(
				$scope,
				$text,
				"{$file} must state the honest scope: free text is never stored, identifiers are."
			);
		}
	}

	private const TITLE = 'Agent Abilities for MCP - Connect Claude and ChatGPT with Permission Controls and Audit Log';

	/**
	 * The listing title lives in exactly three places and they must agree: the plugin header, the
	 * readme.txt header line, and the README.md H1.
	 */
	public function test_title_is_the_same_in_the_three_places_that_carry_it(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertSame( self::TITLE, $this->plugin_headers()['Name'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		$this->assertSame( 1, preg_match( '/\A=== (.+) ===$/m', $readme, $txt ) );
		$this->assertSame( self::TITLE, $txt[1] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$md = (string) file_get_contents( AAFM_PLUGIN_DIR . 'README.md' );
		$this->assertSame( 1, preg_match( '/\A# (.+)$/m', $md, $heading ) );
		$this->assertSame( self::TITLE, $heading[1] );
	}

	/**
	 * The short description is short enough for wordpress.org, reads the same in both readmes, and
	 * is the plugin header's Description too.
	 */
	public function test_short_description_fits_the_limit_and_is_identical_everywhere_it_appears(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		$this->assertSame( 1, preg_match( '/^License URI:[^\n]*\n\n([^\n]+)\n/m', $readme, $txt ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$md = (string) file_get_contents( AAFM_PLUGIN_DIR . 'README.md' );
		$this->assertSame( 1, preg_match( '/\A# [^\n]+\n\n([^\n]+)\n/', $md, $short ) );

		$this->assertSame( $txt[1], $short[1] );
		$this->assertSame( $txt[1], $this->plugin_headers()['Description'] );
		$this->assertLessThanOrEqual( 150, strlen( $txt[1] ) );
	}

	/**
	 * Audit finding (InstaWP review): "A connection only sees the tools its user can call" is not true
	 * of a bridged tool whose own check needs an item id, which lists to anyone who can edit posts
	 * (since 1.7.8) while the other plugin still decides every call. The readmes therefore claim a
	 * per-call capability check, which is true, and must not go back to a claim about what a
	 * connection can see.
	 */
	public function test_the_readmes_claim_a_per_call_capability_check_not_a_visibility_one(): void {
		$checked = array(
			'readme.txt' => 'Each call is checked against the capabilities of the WordPress user the AI connects as',
			'README.md'  => "is checked against that user's capabilities",
		);
		foreach ( $checked as $file => $sentence ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$text = (string) file_get_contents( AAFM_PLUGIN_DIR . $file );
			$this->assertStringContainsString( $sentence, $text, $file );
			$this->assertStringNotContainsString( 'only sees the tools its user can call', $text, $file );
		}
	}

	/**
	 * Audit finding (default-server modes): read-only mode, the allowlists, the rate limit and the
	 * audit log cover the tools this plugin serves, not tools another plugin publishes straight to
	 * the Adapter's default server. Neither readme may claim they cover "other plugins" without
	 * saying which route, and the FAQ must name the default server and the core/* trade-off.
	 */
	public function test_readmes_scope_the_governance_claims_to_this_plugins_own_endpoint(): void {
		foreach ( array( 'readme.txt', 'README.md' ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$text = (string) file_get_contents( AAFM_PLUGIN_DIR . $file );
			// Old changelog entries are history and keep the wording they shipped with.
			$text = (string) preg_split( '/^(?:== Changelog ==|## Changelog)$/m', $text )[0];
			// Screenshot captions describe a screen, not the endpoint's scope.
			$text = (string) preg_replace( '/^== Screenshots ==\n.*?(?=^== |\z)/ms', '', $text );
			$this->assertStringNotContainsString( 'including tools from other plugins', $text, $file );
			$this->assertStringNotContainsString( 'including tools from your other plugins', $text, $file );
			$this->assertStringNotContainsString( 'abilities from other plugins included', $text, $file );
			$this->assertStringNotContainsString( 'updates never silently widen access', $text, $file );
			foreach ( array( 'Nothing is exposed until you turn it on', 'Nothing is exposed until you switch it on', 'governed and off by default', 'logs every call including', 'every call is logged', 'writes every ability call', 'Every ability call, whether', 'capability-gated on every call, and logged', 'Every write is capability-checked' ) as $unscoped ) {
				$this->assertStringNotContainsString( $unscoped, $text, "{$file}: '{$unscoped}' claims more than this plugin's own endpoint delivers." );
			}
		}

		// The FAQ entry names the default server, what sits outside the controls, and the core/* trade-off.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		$this->assertSame( 1, preg_match( '/= Do I need to install the MCP Adapter\?[^\n]*=\n(.+?)(?=\n= |\n== )/s', $readme, $faq ) );
		$this->assertStringContainsString( 'default server', $faq[1] );
		$this->assertStringContainsString( 'sit outside this plugin\'s controls', $faq[1] );
		$this->assertStringContainsString( 'Always on', $faq[1] );
		$this->assertStringContainsString( "WordPress core's public abilities", $faq[1] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$md = (string) file_get_contents( AAFM_PLUGIN_DIR . 'README.md' );
		$this->assertSame( 1, preg_match( '/^One scope note:([^\n]+)/m', $md, $note ) );
		$this->assertStringContainsString( 'default server', $note[1] );
		$this->assertStringContainsString( "outside this plugin's controls", $note[1] );
		$this->assertStringContainsString( 'Always on', $note[1] );
	}

	/**
	 * Audit finding (Connection Doctor): the External Services section says what the plugin
	 * requests on its own. The Doctor sends a few requests from the site to itself, so the section
	 * cannot keep describing a single same-origin call.
	 */
	public function test_external_services_describes_the_doctors_self_requests(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		$this->assertSame( 1, preg_match( '/== External Services ==(.+?)== Screenshots ==/s', $readme, $m ) );
		$this->assertStringNotContainsString( 'a same-origin call', $m[1] );
		$this->assertStringContainsString( 'requests your site makes to its own address', $m[1] );
	}

	/**
	 * The Description section plus External Services must fit wordpress.org's 2,500-word cap on the
	 * Description tab, which counts External Services inside it.
	 */
	public function test_readme_description_and_external_services_fit_the_wporg_word_cap(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		// Each section runs to the next "== Heading ==" line, so the count does not depend on section order.
		$this->assertSame( 1, preg_match( '/^== Description ==\n(.+?)(?=^== )/ms', $readme, $description ) );
		$this->assertSame( 1, preg_match( '/^== External Services ==\n(.+?)(?=^== )/ms', $readme, $external ) );
		$words = count( preg_split( '/\s+/', trim( $description[1] . ' ' . $external[1] ) ) );
		$this->assertLessThanOrEqual( 2450, $words, 'Description plus External Services is over the wordpress.org cap margin.' );
	}

	public function test_readme_ability_counts_are_not_drifted(): void {
		// Both readmes state the catalog size in digits. Assert it against the live registry so the
		// numbers can never silently fall out of sync again.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' );
		$this->assertSame( 1, preg_match( '/There are (\d+) abilities \(.*?\), (\d+) for WordPress itself and (\d+) for plugins/', $readme, $txt ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$md = (string) file_get_contents( AAFM_PLUGIN_DIR . 'README.md' );
		$this->assertSame( 1, preg_match( '/ships (\d+) abilities \((\d+) core, (\d+) for plugins/', $md, $gh ) );

		$total = count( aafm_get_abilities_registry_full() );
		$core  = aafm_core_ability_count();
		foreach ( array(
			'readme.txt' => $txt,
			'README.md'  => $gh,
		) as $file => $m ) {
			$this->assertSame( $total, (int) $m[1], "{$file}: total" );
			$this->assertSame( $core, (int) $m[2], "{$file}: core" );
			$this->assertSame( $total - $core, (int) $m[3], "{$file}: plugin integrations" );
		}
	}
}
