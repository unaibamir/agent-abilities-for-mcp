<?php
/**
 * CPT governance: read/write routing through the post-type allowlist gates.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use WP_Error;

final class CptGovernanceTest extends TestCase {

	/**
	 * Register the write abilities so the smuggle test can invoke update-post end-to-end.
	 *
	 * Mirrors the idiom in PostsWriteTest: push the gated init action name onto
	 * $wp_current_filter so wp_register_ability() will run, without firing the core
	 * hook directly (which trips the WPCS non-prefixed-hookname sniff).
	 */
	public function set_up(): void {
		parent::set_up();
		oversio_install_activity_log();
		oversio_clear_activity_log();

		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option(
			'oversio_enabled_abilities',
			array( 'oversio/create-draft', 'oversio/create-post', 'oversio/update-post', 'oversio/trash-post' )
		);
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}

	public function test_get_post_denies_cpt_object_until_allowlisted(): void {
		register_post_type(
			'oversio_book',
			array(
				'public'          => true,
				'map_meta_cap'    => true,
				'capability_type' => 'post',
				'label'           => 'Books',
			)
		);
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'oversio_book',
				'post_status' => 'publish',
			)
		);

		delete_option( 'oversio_allowed_post_types' );
		$this->assertFalse( oversio_perm_get_post( array( 'post_id' => $id ) ), 'CPT read must be denied before opt-in.' );

		update_option( 'oversio_allowed_post_types', array( 'oversio_book' ) );
		$this->assertTrue( oversio_perm_get_post( array( 'post_id' => $id ) ), 'CPT read allowed after opt-in.' );
	}

	public function test_get_post_denies_attachment_object(): void {
		$this->acting_as( 'administrator' );
		$att = self::factory()->attachment->create();
		$this->assertFalse( oversio_perm_get_post( array( 'post_id' => $att ) ), 'Attachment must not be readable via get-post.' );
	}

	public function test_post_and_page_behaviour_is_unchanged(): void {
		$this->acting_as( 'administrator' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$this->assertTrue( oversio_perm_get_post( array( 'post_id' => $post ) ) );
		$this->assertTrue( oversio_perm_update_post( array( 'post_id' => $post ) ) );
		$this->assertTrue( oversio_perm_trash_post( array( 'post_id' => $post ) ) );
		$this->assertTrue( oversio_perm_get_post( array( 'post_id' => $page ) ) );
	}

	public function test_redaction_returns_exactly_ten_keys_for_a_cpt(): void {
		register_post_type(
			'oversio_book',
			array(
				'public'          => true,
				'map_meta_cap'    => true,
				'capability_type' => 'post',
				'label'           => 'Books',
			)
		);
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'oversio_book',
				'post_status' => 'publish',
				'post_title'  => 'A Book',
			)
		);
		update_post_meta( $id, '_price', '9999' );
		update_post_meta( $id, 'isbn', '123-secret' );

		$out  = oversio_redact_post( get_post( $id ) );
		$keys = array_keys( $out );
		sort( $keys );
		$this->assertSame(
			array( 'author_id', 'date_gmt', 'excerpt', 'id', 'link', 'modified_gmt', 'slug', 'status', 'title', 'type' ),
			$keys
		);
		$json = wp_json_encode( $out );
		$this->assertStringNotContainsString( '9999', $json );
		$this->assertStringNotContainsString( '123-secret', $json );
	}

	public function test_non_allowlisted_cpt_read_is_denied(): void {
		register_post_type(
			'oversio_book',
			array(
				'public' => true,
				'label'  => 'Books',
			)
		);
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'oversio_book',
				'post_status' => 'publish',
			)
		);
		delete_option( 'oversio_allowed_post_types' );
		$this->assertFalse( oversio_perm_get_post( array( 'post_id' => $id ) ) );
	}

	public function test_floor_rejects_internal_types_forced_into_the_option(): void {
		update_option( 'oversio_allowed_post_types', array( 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template' ) );
		$this->assertInstanceOf( WP_Error::class, oversio_validate_post_type( 'attachment' ) );
		$this->assertInstanceOf( WP_Error::class, oversio_validate_post_type( 'revision' ) );
		$this->assertInstanceOf( WP_Error::class, oversio_validate_post_type( 'nav_menu_item' ) );
		$this->assertInstanceOf( WP_Error::class, oversio_validate_post_type( 'wp_block' ) );
		$this->assertInstanceOf( WP_Error::class, oversio_validate_post_type( 'wp_template' ) );
	}

	public function test_private_cpt_forced_into_the_option_is_still_denied(): void {
		register_post_type(
			'oversio_priv',
			array(
				'public' => false,
				'label'  => 'Priv',
			)
		);
		update_option( 'oversio_allowed_post_types', array( 'oversio_priv' ) );
		$this->assertInstanceOf( WP_Error::class, oversio_validate_post_type( 'oversio_priv' ) );
	}

	public function test_map_meta_cap_false_write_is_refused_even_with_singular_caps(): void {
		register_post_type(
			'oversio_unmapped',
			array(
				'public'          => true,
				'map_meta_cap'    => false,
				'capability_type' => array( 'oversio_unmapped', 'oversio_unmappeds' ),
				'label'           => 'Unmapped',
			)
		);
		update_option( 'oversio_allowed_post_types', array( 'oversio_unmapped' ) );

		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$obj   = self::factory()->post->create(
			array(
				'post_type'   => 'oversio_unmapped',
				'post_status' => 'draft',
				'post_author' => $owner,
			)
		);

		$agent = self::factory()->user->create( array( 'role' => 'author' ) );
		$role  = get_role( 'author' );
		$role->add_cap( 'edit_oversio_unmapped' );
		$role->add_cap( 'delete_oversio_unmapped' );
		wp_set_current_user( $agent );

		// The footgun: bare singular caps would let the agent edit ANOTHER author's draft.
		$this->assertFalse( oversio_perm_update_post( array( 'post_id' => $obj ) ), 'Non-mapped CPT edit must be refused.' );
		$this->assertFalse( oversio_perm_trash_post( array( 'post_id' => $obj ) ), 'Non-mapped CPT trash must be refused.' );

		$role->remove_cap( 'edit_oversio_unmapped' );
		$role->remove_cap( 'delete_oversio_unmapped' );
	}

	public function test_set_featured_image_denied_on_non_allowlisted_cpt(): void {
		register_post_type(
			'oversio_book',
			array(
				'public'          => true,
				'map_meta_cap'    => true,
				'capability_type' => 'post',
				'label'           => 'Books',
			)
		);
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'oversio_book',
				'post_status' => 'publish',
			)
		);

		// Not opted in: even an administrator (who can edit_post) must be refused,
		// because the type is not exposed to agents.
		delete_option( 'oversio_allowed_post_types' );
		$this->assertFalse(
			oversio_perm_set_featured_image( array( 'post_id' => $id ) ),
			'Featured-image write must be denied on a non-allowlisted CPT.'
		);
	}

	public function test_set_featured_image_refuses_map_meta_cap_false_footgun(): void {
		register_post_type(
			'oversio_unmapped',
			array(
				'public'          => true,
				'map_meta_cap'    => false,
				'capability_type' => array( 'oversio_unmapped', 'oversio_unmappeds' ),
				'label'           => 'Unmapped',
			)
		);
		update_option( 'oversio_allowed_post_types', array( 'oversio_unmapped' ) );

		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$obj   = self::factory()->post->create(
			array(
				'post_type'   => 'oversio_unmapped',
				'post_status' => 'draft',
				'post_author' => $owner,
			)
		);

		$agent = self::factory()->user->create( array( 'role' => 'author' ) );
		$role  = get_role( 'author' );
		$role->add_cap( 'edit_oversio_unmapped' );
		wp_set_current_user( $agent );

		// The footgun: bare edit_post on a non-mapped type degrades to the singular
		// edit_oversio_unmapped, which would let the agent re-thumbnail ANOTHER author's draft.
		$this->assertFalse(
			oversio_perm_set_featured_image( array( 'post_id' => $obj ) ),
			'Featured-image write on a map_meta_cap=false CPT must be refused.'
		);

		$role->remove_cap( 'edit_oversio_unmapped' );
	}

	public function test_set_featured_image_still_works_for_a_normal_post(): void {
		$this->acting_as( 'administrator' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertTrue(
			oversio_perm_set_featured_image( array( 'post_id' => $post ) ),
			'Featured-image write on a normal post must still be allowed (no regression).'
		);
	}

	public function test_closed_schema_rejects_smuggled_fields(): void {
		// Part 1 — the static shape: the write schema is additionalProperties:false, so
		// post_type / post_author / meta_input are not declared.
		$schema = oversio_write_content_schema( true );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertArrayNotHasKey( 'post_type', $schema['properties'] );
		$this->assertArrayNotHasKey( 'post_author', $schema['properties'] );
		$this->assertArrayNotHasKey( 'meta_input', $schema['properties'] );

		// Part 2 — runtime proof that the smuggle is INERT, not just that the constant says so.
		// Observed Abilities-API behaviour in this version: a call carrying an undeclared field
		// is REJECTED with a WP_Error before the execute callback runs (it does not silently
		// strip + proceed). This matches PostsWriteTest's create-post smuggle assertions.
		$owner  = $this->acting_as( 'editor' );
		$id     = self::factory()->post->create(
			array(
				'post_author' => $owner,
				'post_status' => 'draft',
			)
		);
		$victim = self::factory()->user->create( array( 'role' => 'author' ) );

		$rejected = wp_get_ability( 'oversio/update-post' )->execute(
			array(
				'post_id'     => $id,
				'title'       => 'x',
				'post_author' => $victim, // undeclared → closed schema rejects the whole call.
			)
		);
		$this->assertInstanceOf( WP_Error::class, $rejected, 'Smuggled post_author must be rejected before execute.' );

		// The smuggled re-assignment never reached wp_update_post: authorship is unchanged.
		$after = get_post( $id );
		$this->assertSame( $owner, (int) $after->post_author, 'Author must be unchanged after a rejected smuggle.' );
		$this->assertNotSame( $victim, (int) $after->post_author );
		// And the declared-but-companion field did not apply either (the whole call was rejected).
		$this->assertNotSame( 'x', $after->post_title );
	}

	public function test_allowlist_empty_by_default_is_post_and_page_only(): void {
		delete_option( 'oversio_allowed_post_types' );
		$this->assertSame( array( 'post', 'page' ), oversio_allowed_post_types() );
	}
}
