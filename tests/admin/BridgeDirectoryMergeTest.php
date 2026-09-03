<?php
/**
 * Bridge directory card merge (1.7.2 defect 2): namespaces that resolve to the same known
 * plugin must render as ONE card, not one per namespace.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class BridgeDirectoryMergeTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'aafm_enabled_bridged_abilities' );
	}

	public function tear_down(): void {
		delete_option( 'aafm_enabled_bridged_abilities' );
		foreach ( array_keys( wp_get_abilities() ) as $slug ) {
			$slug = (string) $slug;
			if ( 0 === strncmp( $slug, 'aioseo-posts/', 13 )
				|| 0 === strncmp( $slug, 'aioseo-settings/', 16 )
				|| 0 === strncmp( $slug, 'demo-other/', 11 )
				|| 0 === strncmp( $slug, 'wordpress-seo/', 14 )
				|| 0 === strncmp( $slug, 'rank-math/', 10 )
			) {
				wp_unregister_ability( $slug );
			}
		}
		parent::tear_down();
	}

	/**
	 * Register a throwaway category + a foreign ability under the given namespace.
	 *
	 * @param string $slug  Full ability slug ("namespace/name").
	 * @param string $label Ability label.
	 * @return void
	 */
	private function register_foreign( string $slug, string $label ): void {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'demo-things' ) ) {
					wp_register_ability_category(
						'demo-things',
						array(
							'label'       => 'Demo things',
							'description' => 'Demo fixture category.',
						)
					);
				}
			}
		);
		$this->in_action(
			'wp_abilities_api_init',
			static function () use ( $slug, $label ): void {
				wp_register_ability(
					$slug,
					array(
						'label'               => $label,
						'description'         => 'd',
						'category'            => 'demo-things',
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'execute_callback'    => static fn() => array(),
						'permission_callback' => '__return_true',
						'meta'                => array( 'annotations' => array( 'readonly' => true ) ),
					)
				);
			}
		);
	}

	/**
	 * Bug #8's original fix only relabeled every aioseo-* namespace to "All in One SEO"; the
	 * render loop stayed keyed per-namespace, so AIOSEO (which registers abilities across several
	 * namespaces - aioseo-posts, aioseo-settings, aioseo-notifications, aioseo-robots, aioseo-audit
	 * - still drew one identically-titled card PER namespace instead of one merged card. Register
	 * abilities under two of those namespaces and pin that they land on exactly ONE card, carrying
	 * abilities from both.
	 *
	 * RED against the unfixed render: two separate "<h2>All in One SEO</h2>" cards, one per
	 * namespace, each listing only its own namespace's ability.
	 */
	public function test_aioseo_namespaces_render_as_one_merged_card(): void {
		$this->register_foreign( 'aioseo-posts/list-posts', 'List posts' );
		$this->register_foreign( 'aioseo-settings/get-settings', 'Get settings' );

		ob_start();
		aafm_render_bridge_directory();
		$html = (string) ob_get_clean();

		$this->assertSame(
			1,
			substr_count( $html, '<h2>All in One SEO</h2>' ),
			'Every aioseo-* namespace must fold into ONE "All in One SEO" card, not one card per namespace.'
		);
		$this->assertStringContainsString(
			'aioseo-posts/list-posts',
			$html,
			'The merged card must still carry the aioseo-posts ability.'
		);
		$this->assertStringContainsString(
			'aioseo-settings/get-settings',
			$html,
			'The merged card must still carry the aioseo-settings ability.'
		);
	}

	/**
	 * The merge is scoped to namespaces this plugin actually recognizes as the same product
	 * (aafm_bridge_known_plugin_ids()). A namespace with no entry there must keep its own
	 * group untouched, so two genuinely unrelated plugins can never be folded together by
	 * accident (e.g. a coincidental Title Case collision).
	 */
	public function test_unrelated_namespace_is_never_merged(): void {
		$this->register_foreign( 'demo-other/one', 'One' );

		$groups = aafm_merge_bridge_groups_by_known_plugin( aafm_discover_foreign_abilities() );

		$this->assertArrayHasKey(
			'demo-other',
			$groups,
			'A namespace absent from aafm_bridge_known_plugin_labels() must keep its own, unmerged group.'
		);
	}

	/**
	 * Direct unit check on the merge helper's shape: two known-plugin namespaces collapse into
	 * one group carrying both slugs' abilities.
	 */
	public function test_merge_helper_folds_known_namespaces_into_one_group(): void {
		$this->register_foreign( 'aioseo-posts/list-posts', 'List posts' );
		$this->register_foreign( 'aioseo-settings/get-settings', 'Get settings' );

		$groups = aafm_merge_bridge_groups_by_known_plugin( aafm_discover_foreign_abilities() );

		$this->assertArrayNotHasKey( 'aioseo-posts', $groups );
		$this->assertArrayNotHasKey( 'aioseo-settings', $groups );

		// The group's stored 'label' resolves through aafm_bridge_display_label() the same way
		// every other group's does, so compare on the RESOLVED brand name rather than the raw
		// representative namespace the merge happens to keep internally.
		$matches = array_filter(
			$groups,
			static function ( array $group ): bool {
				return 'All in One SEO' === aafm_bridge_display_label( (string) ( $group['label'] ?? '' ) );
			}
		);
		$this->assertCount( 1, $matches, 'The two aioseo-* namespaces must land in exactly one merged group.' );

		$merged = array_shift( $matches );
		$slugs  = wp_list_pluck( $merged['abilities'], 'slug' );
		$this->assertContains( 'aioseo-posts/list-posts', $slugs );
		$this->assertContains( 'aioseo-settings/get-settings', $slugs );
	}

	/**
	 * 1.7.2 finding 2 (Codex re-review): the merge helper's synthetic key ('aafm-known-' .
	 * sanitize_title($known[$ns])) shared the SAME string keyspace as real raw namespace keys. A
	 * real, unmapped foreign namespace literally named 'aafm-known-all-in-one-seo' - which is
	 * exactly what the old scheme computed for AIOSEO's own merged key (sanitize_title('All in
	 * One SEO') === 'all-in-one-seo') - would silently absorb AIOSEO's abilities into its own,
	 * wrongly-labelled group instead of the two staying separate.
	 *
	 * Discovery (aafm_discover_foreign_abilities()) ksort()s by namespace, and
	 * 'aafm-known-all-in-one-seo' sorts before 'aioseo-posts', so under the OLD scheme the raw
	 * namespace's group is created
	 * FIRST and the AIOSEO group silently merges into it on the second pass (isset($merged[$key])
	 * is already true), rather than either overwriting or getting its own card.
	 *
	 * RED against the unfixed merge: 'aafm-known-all-in-one-seo/probe' and
	 * 'aioseo-posts/list-posts' end up in the SAME group, and the group's resolved label is the
	 * raw namespace's generic Title Case, never "All in One SEO".
	 */
	public function test_foreign_namespace_named_like_the_synthetic_merge_key_stays_its_own_group(): void {
		$this->register_foreign( 'aafm-known-all-in-one-seo/probe', 'Probe' );
		$this->register_foreign( 'aioseo-posts/list-posts', 'List posts' );

		$groups = aafm_merge_bridge_groups_by_known_plugin( aafm_discover_foreign_abilities() );

		$this->assertArrayHasKey(
			'aafm-known-all-in-one-seo',
			$groups,
			'An unmapped real namespace must keep its own group even when its name collides with the synthetic merge-key shape.'
		);
		$collider_slugs = wp_list_pluck( $groups['aafm-known-all-in-one-seo']['abilities'], 'slug' );
		$this->assertSame(
			array( 'aafm-known-all-in-one-seo/probe' ),
			$collider_slugs,
			"The colliding-named foreign namespace's group must carry ONLY its own ability, never AIOSEO's."
		);

		$aioseo_matches = array_filter(
			$groups,
			static function ( array $group ): bool {
				return 'All in One SEO' === aafm_bridge_display_label( (string) ( $group['label'] ?? '' ) );
			}
		);
		$this->assertCount(
			1,
			$aioseo_matches,
			'AIOSEO must still resolve to its own "All in One SEO" group, separate from the colliding-named namespace.'
		);
		$aioseo_slugs = wp_list_pluck( array_shift( $aioseo_matches )['abilities'], 'slug' );
		$this->assertSame(
			array( 'aioseo-posts/list-posts' ),
			$aioseo_slugs,
			"AIOSEO's group must carry only its own ability, never the colliding namespace's."
		);
	}

	/**
	 * 1.7.2 finding 2, the second collision this fix closes: the old key derivation
	 * ('aafm-known-' . sanitize_title($label)) de-duped known plugins on a LOSSY transform of
	 * their label, so two genuinely different plugin labels that happen to sanitize to the same
	 * slug (sanitize_title() collapses whitespace/hyphen differences and case) would wrongly land
	 * in the same merged group. The fix keys on the exact label string instead of a sanitized
	 * form of it, so only two namespaces whose labels are the literal same string ever share a
	 * key.
	 *
	 * Direct unit check on the key-derivation helper itself (aafm_bridge_merge_group_key()),
	 * independent of the real known-plugin map: 'foo bar' and 'foo-bar' are DIFFERENT labels that
	 * both sanitize_title() to 'foo-bar'.
	 */
	public function test_merge_group_key_does_not_alias_two_different_labels_that_sanitize_the_same(): void {
		$this->assertSame( 'foo-bar', sanitize_title( 'foo bar' ), 'Fixture assumption: these two labels must genuinely collide under sanitize_title().' );
		$this->assertSame( 'foo-bar', sanitize_title( 'foo-bar' ), 'Fixture assumption: these two labels must genuinely collide under sanitize_title().' );

		$this->assertNotSame(
			aafm_bridge_merge_group_key( 'foo bar' ),
			aafm_bridge_merge_group_key( 'foo-bar' ),
			'Two different known-plugin labels must never produce the same merge-group key just because they sanitize to the same slug.'
		);
	}

	/**
	 * 1.7.2 residual finding (third Codex re-review): the merge key was still built from the
	 * TRANSLATED display label (aafm_bridge_known_plugin_labels()), not a canonical untranslated
	 * plugin id. Two genuinely different known plugins - Yoast SEO and Rank Math here - whose
	 * English labels happen to translate (or get gettext-filtered) to the SAME localized string on
	 * a non-English site would therefore compute the same merge key and silently fold into one
	 * mislabeled card.
	 *
	 * Forces that collision with a 'gettext' filter that maps both source strings to one
	 * identical "translated" string, then asserts the two plugins still render as TWO separate
	 * cards. RED against the label-keyed merge: only one card would render, carrying abilities
	 * from both unrelated plugins.
	 */
	public function test_different_known_plugins_with_colliding_translated_labels_stay_separate_cards(): void {
		$this->register_foreign( 'wordpress-seo/get-post', 'Get post' );
		$this->register_foreign( 'rank-math/get-score', 'Get score' );

		$collide_translation = static function ( $translation, string $text, string $domain ) {
			if ( 'agent-abilities-for-mcp' === $domain && ( 'Yoast SEO' === $text || 'Rank Math' === $text ) ) {
				return 'Same Localized Name';
			}
			return $translation;
		};

		add_filter( 'gettext', $collide_translation, 10, 3 );
		try {
			ob_start();
			aafm_render_bridge_directory();
			$html = (string) ob_get_clean();
		} finally {
			remove_filter( 'gettext', $collide_translation, 10 );
		}

		$this->assertSame(
			2,
			substr_count( $html, '<h2>Same Localized Name</h2>' ),
			'Two different known plugins whose labels translate to the identical localized string must still render as TWO separate cards, keyed on canonical id rather than the translated label.'
		);
		$this->assertStringContainsString( 'wordpress-seo/get-post', $html );
		$this->assertStringContainsString( 'rank-math/get-score', $html );
	}
}
