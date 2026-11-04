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
}
