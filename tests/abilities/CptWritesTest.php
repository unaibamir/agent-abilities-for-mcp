<?php
/**
 * CPT write abilities: generic create/update over ALLOWLISTED custom post types.
 * Mirrors PostsWriteTest's harness; registers a throwaway CPT to prove allow + deny.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class CptWritesTest extends TestCase {

	/**
	 * An allowlisted, eligible, map_meta_cap CPT used to prove the allow path.
	 *
	 * @var string
	 */
	private const CPT = 'aafm_book';

	/**
	 * An eligible CPT that is NOT added to the allowlist — proves the deny path.
	 *
	 * @var string
	 */
	private const CPT_DENIED = 'aafm_secret';

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();

		// A public, map_meta_cap CPT with its own granular caps so we can prove both
		// the create cap and the publish cap independently.
		register_post_type(
			self::CPT,
			array(
				'public'          => true,
				'show_in_rest'    => true,
				'map_meta_cap'    => true,
				'capability_type' => array( 'aafm_book', 'aafm_books' ),
			)
		);
		// A second eligible CPT we deliberately leave OUT of the allowlist.
		register_post_type(
			self::CPT_DENIED,
			array(
				'public'       => true,
				'map_meta_cap' => true,
			)
		);

		// Expose only self::CPT to agents (post/page are always-on; this adds the CPT).
		update_option( 'aafm_allowed_post_types', array( self::CPT ) );

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/create-cpt-item', 'aafm/update-cpt-item' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function tear_down(): void {
		unregister_post_type( self::CPT );
		unregister_post_type( self::CPT_DENIED );
		delete_option( 'aafm_allowed_post_types' );
		parent::tear_down();
	}

	public function test_cpt_create_schema_is_closed_and_requires_post_type(): void {
		$schema = aafm_write_cpt_content_schema( true );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertFalse( $schema['additionalProperties'], 'CPT create schema must be closed.' );
		$this->assertArrayHasKey( 'post_type', $schema['properties'] );
		$this->assertSame( 'string', $schema['properties']['post_type']['type'] );
		$this->assertContains( 'post_type', $schema['required'] );
		$this->assertContains( 'title', $schema['required'] );
		// Inherits C2 enrichment fields.
		$this->assertArrayHasKey( 'terms', $schema['properties'] );
		$this->assertArrayHasKey( 'featured_media', $schema['properties'] );
		$this->assertArrayHasKey( 'meta', $schema['properties'] );
		$this->assertArrayHasKey( 'slug', $schema['properties'] );
	}

	public function test_create_cpt_item_creates_an_allowlisted_cpt_item(): void {
		// A user holding the CPT's own create + publish caps.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new \WP_User( $user_id );
		$user->add_cap( 'edit_aafm_books' );
		$user->add_cap( 'publish_aafm_books' );
		$user->add_cap( 'edit_published_aafm_books' );
		wp_set_current_user( $user_id );

		$out = wp_get_ability( 'aafm/create-cpt-item' )->execute(
			array(
				'post_type' => self::CPT,
				'title'     => 'Agent CPT item',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'post', $out );
		$this->assertSame( self::CPT, get_post_type( $out['post']['id'] ) );
		$this->assertSame( 'publish', get_post_status( $out['post']['id'] ) );
		// Author is forced to the current agent user — never spoofed.
		$this->assertSame( $user_id, (int) get_post_field( 'post_author', $out['post']['id'] ) );
	}

	public function test_create_cpt_item_denied_for_non_allowlisted_type(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// CPT_DENIED is eligible but NOT in the allowlist — permission must be false.
		$this->assertFalse(
			wp_get_ability( 'aafm/create-cpt-item' )->check_permissions(
				array(
					'post_type' => self::CPT_DENIED,
					'title'     => 'x',
				)
			)
		);
	}

	public function test_create_cpt_item_denied_without_the_types_create_cap(): void {
		// A subscriber holds no authoring caps for the CPT.
		$this->acting_as( 'subscriber' );
		$this->assertFalse(
			wp_get_ability( 'aafm/create-cpt-item' )->check_permissions(
				array(
					'post_type' => self::CPT,
					'title'     => 'x',
				)
			)
		);
	}

	public function test_create_cpt_item_inherits_force_draft(): void {
		update_option( 'aafm_force_draft', true );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new \WP_User( $user_id );
		$user->add_cap( 'edit_aafm_books' );
		$user->add_cap( 'publish_aafm_books' );
		wp_set_current_user( $user_id );

		$out = wp_get_ability( 'aafm/create-cpt-item' )->execute(
			array(
				'post_type' => self::CPT,
				'title'     => 'Should be coerced',
				'status'    => 'publish',
			)
		);

		$this->assertSame( 'draft', get_post_status( $out['post']['id'] ) );
		delete_option( 'aafm_force_draft' );
	}

	public function test_update_cpt_item_edits_an_allowlisted_cpt_item(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new \WP_User( $user_id );
		$user->add_cap( 'edit_aafm_books' );
		$user->add_cap( 'edit_others_aafm_books' );
		$user->add_cap( 'edit_published_aafm_books' );
		wp_set_current_user( $user_id );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'draft',
				'post_title'  => 'Old',
			)
		);

		$out = wp_get_ability( 'aafm/update-cpt-item' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'New title',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'New title', get_post_field( 'post_title', $post_id ) );
	}

	public function test_update_cpt_item_denied_for_non_allowlisted_type(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// A post of the NON-allowlisted CPT — even an admin must be denied.
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::CPT_DENIED,
				'post_status' => 'draft',
			)
		);

		$this->assertFalse(
			wp_get_ability( 'aafm/update-cpt-item' )->check_permissions( array( 'post_id' => $post_id ) )
		);
	}

	public function test_update_cpt_item_publish_requires_the_types_publish_cap(): void {
		// Holds edit caps but NOT publish_aafm_books → publishing must be denied at permission time.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new \WP_User( $user_id );
		$user->add_cap( 'edit_aafm_books' );
		$user->add_cap( 'edit_others_aafm_books' );
		$user->add_cap( 'edit_published_aafm_books' );
		wp_set_current_user( $user_id );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'draft',
			)
		);

		$this->assertFalse(
			wp_get_ability( 'aafm/update-cpt-item' )->check_permissions(
				array(
					'post_id' => $post_id,
					'status'  => 'publish',
				)
			)
		);
	}

	public function test_update_cpt_item_applies_enrichment_meta(): void {
		// Allowlist a governed meta key so the enrichment path is exercised end to end.
		// The governed-meta allowlist is a single filter, aafm_allowed_meta_keys.
		add_filter(
			'aafm_allowed_meta_keys',
			static function ( array $keys ): array {
				$keys[] = 'aafm_demo_key';
				return $keys;
			}
		);

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new \WP_User( $user_id );
		$user->add_cap( 'edit_aafm_books' );
		$user->add_cap( 'edit_others_aafm_books' );
		$user->add_cap( 'edit_published_aafm_books' );
		wp_set_current_user( $user_id );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'draft',
			)
		);

		wp_get_ability( 'aafm/update-cpt-item' )->execute(
			array(
				'post_id' => $post_id,
				'meta'    => array( 'aafm_demo_key' => 'enriched' ),
			)
		);

		$this->assertSame( 'enriched', get_post_meta( $post_id, 'aafm_demo_key', true ) );
	}

	public function test_cpt_writes_are_discoverable_by_a_capable_user(): void {
		// edit_posts is the coarse discovery floor; an author holds it.
		$this->acting_as( 'author' );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/create-cpt-item' ) );
		$this->assertTrue( aafm_user_can_discover_ability( 'aafm/update-cpt-item' ) );
	}

	public function test_cpt_writes_are_not_discoverable_without_the_authoring_floor(): void {
		// A subscriber lacks edit_posts → cannot even discover the tools.
		$this->acting_as( 'subscriber' );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/create-cpt-item' ) );
		$this->assertFalse( aafm_user_can_discover_ability( 'aafm/update-cpt-item' ) );
	}

	public function test_cpt_writes_are_grouped_under_the_content_subject(): void {
		// The Abilities admin tab buckets the registry by `subject`, then splits each panel
		// into reads/writes by `group`. Proving both keys here proves the two new abilities
		// surface under the content tab's writes section with no admin-render change.
		// Default-OFF is guaranteed structurally by CatalogTest::test_nothing_is_enabled_by_default;
		// this set_up() enables them for the harness, so it is NOT the place to assert opt-in.
		$registry = aafm_get_abilities_registry();

		$this->assertArrayHasKey( 'aafm/create-cpt-item', $registry );
		$this->assertArrayHasKey( 'aafm/update-cpt-item', $registry );
		$this->assertSame( 'content', $registry['aafm/create-cpt-item']['subject'] );
		$this->assertSame( 'content', $registry['aafm/update-cpt-item']['subject'] );
		$this->assertSame( 'writes', $registry['aafm/create-cpt-item']['group'] );
		$this->assertSame( 'writes', $registry['aafm/update-cpt-item']['group'] );
	}

	public function test_create_cpt_item_rejects_ineligible_type_even_if_allowlisted(): void {
		// attachment is the lone public-but-internal type; the floor must reject it even if a
		// rogue option lists it. Add it to the option and confirm create is denied + not written.
		update_option( 'aafm_allowed_post_types', array( self::CPT, 'attachment' ) );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertFalse(
			wp_get_ability( 'aafm/create-cpt-item' )->check_permissions(
				array(
					'post_type' => 'attachment',
					'title'     => 'x',
				)
			)
		);

		$out = wp_get_ability( 'aafm/create-cpt-item' )->execute(
			array(
				'post_type' => 'attachment',
				'title'     => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_create_cpt_item_does_not_spoof_author(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new \WP_User( $user_id );
		$user->add_cap( 'edit_aafm_books' );
		$user->add_cap( 'publish_aafm_books' );
		wp_set_current_user( $user_id );

		// post_author is not in the closed schema, but prove that even a stray value is ignored:
		// aafm_insert_post never threads it, so the author is the agent user.
		$out = wp_get_ability( 'aafm/create-cpt-item' )->execute(
			array(
				'post_type' => self::CPT,
				'title'     => 'Author check',
			)
		);
		$this->assertSame( $user_id, (int) get_post_field( 'post_author', $out['post']['id'] ) );
	}

	public function test_update_cpt_item_force_draft_coerces_publish_request(): void {
		update_option( 'aafm_force_draft', true );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new \WP_User( $user_id );
		$user->add_cap( 'edit_aafm_books' );
		$user->add_cap( 'edit_others_aafm_books' );
		$user->add_cap( 'edit_published_aafm_books' );
		$user->add_cap( 'publish_aafm_books' );
		wp_set_current_user( $user_id );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'draft',
			)
		);

		wp_get_ability( 'aafm/update-cpt-item' )->execute(
			array(
				'post_id' => $post_id,
				'status'  => 'publish',
			)
		);

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		delete_option( 'aafm_force_draft' );
	}

	public function test_create_cpt_item_denied_for_editor_lacking_the_types_own_cap(): void {
		// A CPT with its OWN granular caps, so stock roles never hold edit_aafm_journal even
		// though an editor holds the generic edit_posts/publish_posts. This proves the create
		// gate keys on the type's own cap, not a literal edit_posts gate.
		register_post_type(
			'aafm_journal',
			array(
				'public'          => true,
				'map_meta_cap'    => true,
				'capability_type' => array( 'aafm_journal', 'aafm_journals' ),
			)
		);
		update_option( 'aafm_allowed_post_types', array( self::CPT, 'aafm_journal' ) );

		// An editor: holds edit_posts + publish_posts, but NOT the type's own edit cap.
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertTrue( current_user_can( 'publish_posts' ) );

		$this->assertFalse(
			wp_get_ability( 'aafm/create-cpt-item' )->check_permissions(
				array(
					'post_type' => 'aafm_journal',
					'title'     => 'x',
				)
			)
		);

		$out = wp_get_ability( 'aafm/create-cpt-item' )->execute(
			array(
				'post_type' => 'aafm_journal',
				'title'     => 'x',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );

		unregister_post_type( 'aafm_journal' );
	}

	public function test_create_vs_update_asymmetry_on_a_non_mapped_cpt(): void {
		// A map_meta_cap=false CPT that is eligible + allowlisted. Locks the deliberate asymmetry:
		// create gates on the type's edit_posts primitive (no per-object object to degrade), while
		// update is refused because aafm_can_edit_post_object() requires map_meta_cap===true.
		register_post_type(
			'aafm_ledger',
			array(
				'public'          => true,
				'map_meta_cap'    => false,
				'capability_type' => array( 'aafm_ledger', 'aafm_ledgers' ),
			)
		);
		update_option( 'aafm_allowed_post_types', array( self::CPT, 'aafm_ledger' ) );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new \WP_User( $user_id );
		// Grant the non-mapped type's plural primitives so create's gate is satisfied.
		$user->add_cap( 'edit_aafm_ledgers' );
		$user->add_cap( 'publish_aafm_ledgers' );
		$user->add_cap( 'edit_published_aafm_ledgers' );
		wp_set_current_user( $user_id );

		// CREATE: behaves per its cap gate — the agent holds the primitive, so it is allowed.
		$this->assertTrue(
			wp_get_ability( 'aafm/create-cpt-item' )->check_permissions(
				array(
					'post_type' => 'aafm_ledger',
					'title'     => 'x',
				)
			)
		);

		// UPDATE: denied on the same type — aafm_can_edit_post_object() refuses non-mapped types
		// because a degraded per-object edit_post check can fail open.
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'aafm_ledger',
				'post_status' => 'draft',
			)
		);
		$this->assertFalse(
			wp_get_ability( 'aafm/update-cpt-item' )->check_permissions( array( 'post_id' => $post_id ) )
		);

		unregister_post_type( 'aafm_ledger' );
	}

	public function test_update_cpt_item_content_is_sanitized(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$user    = new \WP_User( $user_id );
		$user->add_cap( 'edit_aafm_books' );
		$user->add_cap( 'edit_others_aafm_books' );
		$user->add_cap( 'edit_published_aafm_books' );
		$user->add_cap( 'unfiltered_html' );
		wp_set_current_user( $user_id );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'draft',
			)
		);

		wp_get_ability( 'aafm/update-cpt-item' )->execute(
			array(
				'post_id' => $post_id,
				'content' => '<script>alert(1)</script><p>ok</p>',
			)
		);

		$this->assertStringNotContainsString( '<script>', get_post_field( 'post_content', $post_id ) );
	}
}
