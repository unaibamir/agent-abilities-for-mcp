<?php
/**
 * Descriptions tell the truth: every text that lists the page builders the write guard covers names
 * all of them, every builder the guard knows is named in the readme, and a capability a description
 * names (or leaves out) is the one the code enforces at discovery.
 *
 * The capability probe only sees capabilities that flip discovery on their own. An either-or family
 * (edit_posts or edit_others_posts) never flips on one capability, so it is not claimed here, and a
 * per-object check that is false on empty input is not probeable; both have their own write tests.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class DescriptionTruthSweepTest extends TestCase {

	private const INTEGRATIONS = array( 'yoast', 'rankmath', 'aioseo', 'acf', 'woocommerce', 'slim_seo', 'tec', 'event_tickets', 'avada', 'geodirectory' );

	/**
	 * Capabilities a description may name, each with the spellings the descriptions use.
	 * Spaces, hyphens and underscores are interchangeable ("manage-WooCommerce", "manage_woocommerce").
	 *
	 * @var string[]
	 */
	private const VOCABULARY = array(
		'edit_users',
		'manage_woocommerce',
		'upload_files',
		'manage_options',
		'list_users',
		'edit_theme_options',
		'moderate_comments',
		'install_plugins',
		'activate_plugins',
		'create_users',
		'delete_users',
	);

	/**
	 * Abilities whose discovery is gated by a vocabulary capability that the description deliberately
	 * leaves out, each with the reason. Keep this short: an entry is a decision, not a hiding place.
	 *
	 * @var array<string,string>
	 */
	private const UNNAMED_BY_DESIGN = array(
		// Described as edit access to the object, which is how WordPress states these capabilities to a user.
		'aafm/delete-comment:moderate_comments'  => 'Described as edit access to that comment.',
		'aafm/get-user-meta:edit_users'          => 'Described as a user the agent can edit.',
		'aafm/update-user-meta:edit_users'       => 'Described as a user the agent can edit.',
		'aafm/delete-user-meta:edit_users'       => 'Described as a user the agent can edit.',
		'aafm/acf-get-user-fields:edit_users'    => 'Described as edit access to that user.',
		'aafm/acf-update-user-fields:edit_users' => 'Described as edit access to that user.',
	);

	public function set_up(): void {
		parent::set_up();
		foreach ( self::INTEGRATIONS as $slug ) {
			add_filter( 'aafm_integration_active_' . $slug, '__return_true' );
		}
		aafm_registry_cache_should_flush( true );
		// Discovery of a read asks whether the ability is switched on, so switch the whole catalog on.
		update_option( 'aafm_high_risk_abilities_unlocked', true );
		update_option( 'aafm_enabled_abilities', array_keys( aafm_get_abilities_registry_full() ) );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function tear_down(): void {
		foreach ( self::INTEGRATIONS as $slug ) {
			remove_filter( 'aafm_integration_active_' . $slug, '__return_true' );
		}
		aafm_registry_cache_should_flush( true );
		parent::tear_down();
	}

	/**
	 * The display names of the builders the default guard covers.
	 *
	 * @return string[]
	 */
	private function builder_labels(): array {
		$labels = array();
		foreach ( array_unique( array_values( aafm_page_builder_default_markers() ) ) as $slug ) {
			$labels[] = aafm_page_builder_label( (string) $slug );
		}
		return $labels;
	}

	/**
	 * Every property description in a schema, recursively.
	 *
	 * @param mixed  $schema A schema node.
	 * @param string $path   Path for the failure message.
	 * @return array<string,string> Path => description.
	 */
	private function schema_descriptions( $schema, string $path ): array {
		$out = array();
		if ( ! is_array( $schema ) ) {
			return $out;
		}
		if ( isset( $schema['description'] ) && is_string( $schema['description'] ) ) {
			$out[ $path ] = $schema['description'];
		}
		foreach ( array( 'properties', 'items' ) as $key ) {
			if ( ! isset( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) ) {
				continue;
			}
			if ( 'items' === $key ) {
				$out = array_merge( $out, $this->schema_descriptions( $schema['items'], $path . '[]' ) );
				continue;
			}
			foreach ( $schema['properties'] as $name => $child ) {
				$out = array_merge( $out, $this->schema_descriptions( $child, $path . '.' . $name ) );
			}
		}
		return $out;
	}

	/**
	 * Every user-facing text for every ability: description, schema property descriptions and the
	 * Settings disclosure line.
	 *
	 * @return array<string,array<string,string>> Ability => (where => text).
	 */
	private function texts(): array {
		$disclosures = aafm_ability_disclosures();
		$out         = array();
		foreach ( aafm_get_abilities_registry_full() as $name => $row ) {
			$texts = array();
			if ( isset( $row['description'] ) && is_string( $row['description'] ) ) {
				$texts[ $name . ' description' ] = $row['description'];
			}
			if ( ! empty( $row['args_builder'] ) && is_callable( $row['args_builder'] ) ) {
				$args = call_user_func( $row['args_builder'] );
				foreach ( array( 'input_schema', 'output_schema' ) as $side ) {
					foreach ( $this->schema_descriptions( $args[ $side ] ?? null, $name . ' ' . $side ) as $where => $text ) {
						$texts[ $where ] = $text;
					}
				}
			}
			if ( isset( $disclosures[ $name ] ) ) {
				$texts[ $name . ' disclosure' ] = $disclosures[ $name ];
			}
			$out[ $name ] = $texts;
		}
		return $out;
	}

	public function test_every_page_builder_list_names_every_builder(): void {
		$labels  = $this->builder_labels();
		$missing = array();
		$seen    = 0;
		foreach ( $this->texts() as $texts ) {
			foreach ( $texts as $where => $text ) {
				$named = array_filter( $labels, static fn( string $label ): bool => false !== stripos( $text, $label ) );
				// A text naming one builder (the Avada-only tools) is not a list of the guard's builders.
				if ( count( $named ) < 2 ) {
					continue;
				}
				++$seen;
				$lacking = array_diff( $labels, $named );
				if ( array() !== $lacking ) {
					$missing[] = $where . ' lacks ' . implode( ', ', $lacking );
				}
			}
		}
		$this->assertGreaterThanOrEqual( 9, $seen, 'The nine description sites that list the builders were found.' );
		$this->assertSame( array(), $missing, "A description lists page builders but not all of them:\n" . implode( "\n", $missing ) );
	}

	public function test_every_builder_marker_has_a_display_name(): void {
		$readme = (string) file_get_contents( AAFM_PLUGIN_DIR . 'readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading plugin source.
		$this->assertSame( array( 'Elementor', 'Divi', 'Beaver Builder', 'Avada', 'Visual Composer' ), $this->builder_labels(), 'Update the readme FAQ and the descriptions with a new builder.' );
		foreach ( $this->builder_labels() as $label ) {
			$this->assertStringContainsString( $label, $readme, $label . ' is named in the readme.' );
		}
		$this->assertStringContainsString( 'Elementor, Divi, Beaver Builder, Avada and Visual Composer are covered', $readme );
	}

	public function test_the_helper_builds_the_list_from_the_default_markers(): void {
		$this->assertSame( 'Elementor, Divi, Beaver Builder, Avada, and Visual Composer', aafm_page_builder_names() );
		add_filter(
			'aafm_page_builder_markers',
			static function ( array $markers ): array {
				$markers['_extra'] = 'sixth-builder';
				return $markers;
			}
		);
		$this->assertSame( 'Elementor, Divi, Beaver Builder, Avada, and Visual Composer', aafm_page_builder_names(), 'A site filter does not change registry text.' );
		remove_all_filters( 'aafm_page_builder_markers' );
	}

	/**
	 * Whether a text names a capability, in any of the spellings the descriptions use.
	 *
	 * @param string $text Text.
	 * @param string $cap  Capability.
	 * @return bool
	 */
	private function names_cap( string $text, string $cap ): bool {
		return 1 === preg_match( '/\b' . str_replace( '_', '[-_ ]', preg_quote( $cap, '/' ) ) . '\b/i', $text );
	}

	/**
	 * A user with no role and every administrator capability granted directly.
	 *
	 * @return \WP_User
	 */
	private function fixture_user(): \WP_User {
		$id   = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user = new \WP_User( $id );
		$user->set_role( '' );
		foreach ( array_keys( array_filter( (array) get_role( 'administrator' )->capabilities ) ) as $cap ) {
			$user->add_cap( (string) $cap );
		}
		// The administrator role does not carry every vocabulary capability (WooCommerce adds
		// manage_woocommerce only when it is active), so grant them all directly.
		foreach ( self::VOCABULARY as $cap ) {
			$user->add_cap( $cap );
		}
		// Work on the object WordPress checks, so add_cap() takes effect without a reload.
		wp_set_current_user( $id );
		return wp_get_current_user();
	}

	/**
	 * Whether the fixture user can discover an ability.
	 *
	 * @param \WP_User $user Fixture.
	 * @param string   $name Ability.
	 * @return bool
	 */
	private function discovers( \WP_User $user, string $name ): bool {
		unset( $user );
		return aafm_user_can_discover_ability( $name );
	}

	/**
	 * Abilities the capability sweep must reach. A skipped ability would otherwise pass silently, which
	 * is how every WooCommerce ability once dropped out of the probe.
	 *
	 * @var string[]
	 */
	private const MUST_PROBE = array(
		'aafm/wc-update-customer',
		'aafm/wc-create-customer',
		'aafm/wc-get-order',
		'aafm/update-user',
		'aafm/get-site-settings',
		'aafm/list-menus',
		'aafm/get-pending-comments',
	);

	public function test_described_capabilities_are_the_ones_the_code_enforces(): void {
		$user      = $this->fixture_user();
		$texts     = $this->texts();
		$unclaimed = array();
		$unproved  = array();
		$probed    = 0;
		$reached   = array();
		foreach ( array_keys( aafm_get_abilities_registry_full() ) as $name ) {
			if ( ! $this->discovers( $user, $name ) ) {
				continue; // Not probeable on empty input; the ability's own tests cover it.
			}
			$reached[] = $name;
			$joined    = implode( "\n", $texts[ $name ] ?? array() );
			++$probed;
			foreach ( self::VOCABULARY as $cap ) {
				$user->add_cap( $cap, false );
				$flips = ! $this->discovers( $user, $name );
				$user->add_cap( $cap, true );
				$named = $this->names_cap( $joined, $cap );
				if ( $flips && ! $named && ! isset( self::UNNAMED_BY_DESIGN[ $name . ':' . $cap ] ) ) {
					$unclaimed[] = $name . ' needs ' . $cap . ' but never says so';
				}
				if ( $named && ! $flips && $this->claims_required( $name, $joined, $cap ) ) {
					$unproved[] = $name . ' says it requires ' . $cap . ' but discovery does not';
				}
			}
		}
		$this->assertGreaterThan( 100, $probed, 'Most of the catalog is probeable.' );
		$this->assertSame( array(), array_values( array_diff( self::MUST_PROBE, $reached ) ), 'These abilities must be reached by the probe; a skip here hides a description from the sweep.' );
		$this->assertSame( array(), $unclaimed, "Capabilities the code enforces and the text omits:\n" . implode( "\n", $unclaimed ) );
		$this->assertSame( array(), $unproved, "Capabilities the text requires and discovery does not:\n" . implode( "\n", $unproved ) );
	}

	/**
	 * Whether a text says the capability is REQUIRED ("Requires the X capability", "Gated by the X
	 * capability", "requires the create-users capability as well"), as opposed to mentioning it.
	 *
	 * @param string $name   Ability.
	 * @param string $joined All the ability's texts.
	 * @param string $cap    Capability.
	 * @return bool
	 */
	private function claims_required( string $name, string $joined, string $cap ): bool {
		unset( $name );
		$spelled = str_replace( '_', '[-_ ]', preg_quote( $cap, '/' ) );
		return 1 === preg_match( '/(requires?|gated by|needs?)[^.]{0,60}\b' . $spelled . '\b/i', $joined );
	}

	public function test_the_seven_rows_say_what_the_code_does(): void {
		$registry = aafm_get_abilities_registry_full();
		$customer = $registry['aafm/wc-update-customer']['description'];
		$this->assertStringContainsString( 'edit-users', $customer );
		$this->assertStringContainsString( 'manage-WooCommerce', $customer );
		$this->assertStringContainsString( 'High-risk', aafm_ability_disclosures()['aafm/wc-update-customer'] );
		$this->assertStringContainsString( 'edit-users', aafm_ability_disclosures()['aafm/wc-update-customer'] );
		foreach ( array( 'aafm/tec-update-event', 'aafm/geodirectory-update-listing' ) as $name ) {
			foreach ( $this->builder_labels() as $label ) {
				$this->assertStringContainsString( $label, $registry[ $name ]['description'], $name );
			}
		}
		foreach ( array( 'aafm/tec-get-events', 'aafm/tec-get-venues', 'aafm/tec-get-organizers' ) as $name ) {
			$this->assertStringContainsString( 'status', $registry[ $name ]['description'], $name );
		}
		$this->assertStringContainsString( 'is_avada_owned', $registry['aafm/avada-get-page-content']['description'] );
	}

	/**
	 * The behaviour itself is proved by the TEC wire test; this pins that both texts state it.
	 */
	public function test_tec_get_events_says_drafts_are_the_callers_own_unless_they_can_edit_others(): void {
		$description = aafm_get_abilities_registry_full()['aafm/tec-get-events']['description'];
		$disclosure  = aafm_ability_disclosures()['aafm/tec-get-events'];

		foreach ( array( $description, $disclosure ) as $text ) {
			$this->assertStringContainsString( "the caller's own", $text );
			$this->assertStringContainsString( 'edit other', $text );
		}
	}

	public function test_no_avada_text_uses_a_spaced_hyphen_as_a_dash(): void {
		$checked = 0;
		foreach ( $this->texts() as $name => $texts ) {
			if ( 0 !== strncmp( $name, 'aafm/avada-', 11 ) ) {
				continue;
			}
			foreach ( $texts as $where => $text ) {
				++$checked;
				$this->assertStringNotContainsString( ' - ', $text, $where );
			}
		}
		$this->assertGreaterThan( 0, $checked );
	}

	public function test_replace_sitewide_says_to_rerun_and_warns_when_the_new_text_contains_the_old(): void {
		$description = (string) aafm_get_abilities_registry()['aafm/replace-sitewide']['description'];
		$this->assertStringContainsString( 'run the call again', $description );
		$this->assertStringContainsString( 'If the new text contains the search text', $description );
		$this->assertStringContainsString( 'check total_matches rather than repeating blindly', $description );
	}
}
