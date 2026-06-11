<?php
/**
 * Term write abilities: cap gate, taxonomy allow-list, term/taxonomy confinement,
 * description sanitization, and the circular-hierarchy guard on reparenting.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;
use WP_Term;

final class TermsWriteTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to
		// the custom table, so it must exist before any ability is invoked.
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// Register categories + enabled abilities inside their gated init actions,
		// simulated by pushing the action name onto $wp_current_filter — the idiom
		// WP core's own ability test trait uses. wp_register_ability() refuses to run
		// otherwise, and do_action() on the core hook trips the WPCS non-prefixed-
		// hookname sniff (Phase 1 carried issue).
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/create-term', 'aafm/update-term' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * Run a callback inside a simulated Abilities API init action.
	 *
	 * @param string   $action   Action name to simulate.
	 * @param callable $callback Callback to invoke while the action is "running".
	 */
	private function in_action( string $action, callable $callback ): void {
		global $wp_current_filter;
		$wp_current_filter[] = $action;
		$callback();
		array_pop( $wp_current_filter );
	}

	public function test_writes_are_in_registry_as_writes(): void {
		$registry = aafm_get_abilities_registry();
		$this->assertSame( 'writes', $registry['aafm/create-term']['group'] );
		$this->assertSame( 'write', $registry['aafm/create-term']['risk'] );
		$this->assertSame( 'writes', $registry['aafm/update-term']['group'] );
		$this->assertSame( 'write', $registry['aafm/update-term']['risk'] );
	}

	public function test_create_term_requires_manage_categories(): void {
		$this->acting_as( 'author' );
		$this->assertFalse( wp_get_ability( 'aafm/create-term' )->check_permissions( array() ) );
		$this->acting_as( 'editor' );
		$this->assertTrue( wp_get_ability( 'aafm/create-term' )->check_permissions( array() ) );
	}

	public function test_create_term_gates_on_the_target_taxonomy_own_cap(): void {
		// A public taxonomy can declare its own manage_terms cap, decoupled from
		// manage_categories. (For the built-in post_tag, core's map_meta_cap folds
		// manage_post_tags back into manage_categories, so they can't be separated;
		// a custom taxonomy is where the distinction is real.) wp_insert_term does no
		// internal cap check, so the gate must resolve the named taxonomy's real
		// manage_terms cap rather than hardcoding manage_categories — otherwise an
		// actor who can only manage categories could write into the custom taxonomy.
		register_taxonomy(
			'aafm_genre',
			'post',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'capabilities' => array( 'manage_terms' => 'manage_aafm_genres' ),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = get_userdata( $user_id );
		$user->add_cap( 'manage_categories' );
		wp_set_current_user( $user_id );

		$ability = wp_get_ability( 'aafm/create-term' );
		// Can manage categories...
		$this->assertTrue( $ability->check_permissions( array( 'taxonomy' => 'category' ) ) );
		// ...but lacks manage_aafm_genres, so the custom-taxonomy write is denied.
		$this->assertFalse( $ability->check_permissions( array( 'taxonomy' => 'aafm_genre' ) ) );

		unregister_taxonomy( 'aafm_genre' );
	}

	public function test_low_priv_create_term_denial_is_audited(): void {
		// A subscriber lacks manage_categories: denied, and the denial is audited.
		$this->acting_as( 'subscriber' );
		$this->assertFalse( wp_get_ability( 'aafm/create-term' )->check_permissions( array() ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/create-term', $abilities );
	}

	public function test_create_term_inserts(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'aafm/create-term' )->execute(
			array(
				'taxonomy' => 'category',
				'name'     => 'Reviews',
			)
		);
		$this->assertIsInt( $out['term']['id'] );
		$this->assertSame( 'Reviews', $out['term']['name'] );
	}

	public function test_create_term_rejects_non_public_taxonomy(): void {
		// nav_menu is a private/internal taxonomy: default-deny, never written into.
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'aafm/create-term' )->execute(
			array(
				'taxonomy' => 'nav_menu',
				'name'     => 'Sneaky menu',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_create_term_rejects_unknown_taxonomy(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'aafm/create-term' )->execute(
			array(
				'taxonomy' => 'totally_fake',
				'name'     => 'Phantom',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_create_term_sanitizes_script_in_description(): void {
		$this->acting_as( 'editor' );
		$out  = wp_get_ability( 'aafm/create-term' )->execute(
			array(
				'taxonomy'    => 'category',
				'name'        => 'Hardened',
				'description' => 'Hello<script>alert(1)</script> world',
			)
		);
		$term = get_term( (int) $out['term']['id'] );
		$this->assertInstanceOf( WP_Term::class, $term );
		$this->assertStringNotContainsString( '<script', $term->description );
		$this->assertStringContainsString( 'Hello', $term->description );
	}

	public function test_update_term_rejects_term_from_other_taxonomy(): void {
		// A tag exists, but the caller claims it is a category. The per-object guard
		// must reject the term/taxonomy mismatch rather than touch the wrong object.
		$this->acting_as( 'editor' );
		$tag = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Untouchable',
			)
		);

		$out = wp_get_ability( 'aafm/update-term' )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $tag,
				'name'     => 'Hijacked',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );

		// The tag is untouched.
		$still = get_term( $tag, 'post_tag' );
		$this->assertInstanceOf( WP_Term::class, $still );
		$this->assertSame( 'Untouchable', $still->name );
	}

	public function test_update_term_blocks_circular_parent(): void {
		$this->acting_as( 'editor' );
		$parent = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$child  = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'parent'   => $parent,
			)
		);

		// Making the parent a child of its own child is circular -> rejected.
		$out = wp_get_ability( 'aafm/update-term' )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $parent,
				'parent'   => $child,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );

		// The parent's hierarchy is unchanged.
		$reloaded = get_term( $parent, 'category' );
		$this->assertSame( 0, (int) $reloaded->parent );
	}

	public function test_update_term_updates_name(): void {
		$this->acting_as( 'editor' );
		$term = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Before',
			)
		);

		$out = wp_get_ability( 'aafm/update-term' )->execute(
			array(
				'taxonomy' => 'category',
				'term_id'  => $term,
				'name'     => 'After',
			)
		);
		$this->assertSame( 'After', $out['term']['name'] );
		$this->assertSame( 'After', get_term( $term, 'category' )->name );
	}
}
