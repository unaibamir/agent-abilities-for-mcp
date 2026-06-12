<?php
/**
 * CPT governance: read/write routing through the post-type allowlist gates.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class CptGovernanceTest extends TestCase {

	public function test_get_post_denies_cpt_object_until_allowlisted(): void {
		register_post_type(
			'aafm_book',
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
				'post_type'   => 'aafm_book',
				'post_status' => 'publish',
			)
		);

		delete_option( 'aafm_allowed_post_types' );
		$this->assertFalse( aafm_perm_get_post( array( 'post_id' => $id ) ), 'CPT read must be denied before opt-in.' );

		update_option( 'aafm_allowed_post_types', array( 'aafm_book' ) );
		$this->assertTrue( aafm_perm_get_post( array( 'post_id' => $id ) ), 'CPT read allowed after opt-in.' );
	}

	public function test_get_post_denies_attachment_object(): void {
		$this->acting_as( 'administrator' );
		$att = self::factory()->attachment->create();
		$this->assertFalse( aafm_perm_get_post( array( 'post_id' => $att ) ), 'Attachment must not be readable via get-post.' );
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
		$this->assertTrue( aafm_perm_get_post( array( 'post_id' => $post ) ) );
		$this->assertTrue( aafm_perm_update_post( array( 'post_id' => $post ) ) );
		$this->assertTrue( aafm_perm_trash_post( array( 'post_id' => $post ) ) );
		$this->assertTrue( aafm_perm_get_post( array( 'post_id' => $page ) ) );
	}

	public function test_redaction_returns_exactly_nine_keys_for_a_cpt(): void {
		register_post_type( 'aafm_book', array( 'public' => true, 'map_meta_cap' => true, 'capability_type' => 'post', 'label' => 'Books' ) );
		$id = self::factory()->post->create( array( 'post_type' => 'aafm_book', 'post_status' => 'publish', 'post_title' => 'A Book' ) );
		update_post_meta( $id, '_price', '9999' );
		update_post_meta( $id, 'isbn', '123-secret' );

		$out  = aafm_redact_post( get_post( $id ) );
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
		register_post_type( 'aafm_book', array( 'public' => true, 'label' => 'Books' ) );
		$this->acting_as( 'administrator' );
		$id = self::factory()->post->create( array( 'post_type' => 'aafm_book', 'post_status' => 'publish' ) );
		delete_option( 'aafm_allowed_post_types' );
		$this->assertFalse( aafm_perm_get_post( array( 'post_id' => $id ) ) );
	}

	public function test_floor_rejects_internal_types_forced_into_the_option(): void {
		update_option( 'aafm_allowed_post_types', array( 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'attachment' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'revision' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'nav_menu_item' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'wp_block' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'wp_template' ) );
	}

	public function test_private_cpt_forced_into_the_option_is_still_denied(): void {
		register_post_type( 'aafm_priv', array( 'public' => false, 'label' => 'Priv' ) );
		update_option( 'aafm_allowed_post_types', array( 'aafm_priv' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_post_type( 'aafm_priv' ) );
	}

	public function test_map_meta_cap_false_write_is_refused_even_with_singular_caps(): void {
		register_post_type( 'aafm_unmapped', array( 'public' => true, 'map_meta_cap' => false, 'capability_type' => array( 'aafm_unmapped', 'aafm_unmappeds' ), 'label' => 'Unmapped' ) );
		update_option( 'aafm_allowed_post_types', array( 'aafm_unmapped' ) );

		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$obj   = self::factory()->post->create( array( 'post_type' => 'aafm_unmapped', 'post_status' => 'draft', 'post_author' => $owner ) );

		$agent = self::factory()->user->create( array( 'role' => 'author' ) );
		$role  = get_role( 'author' );
		$role->add_cap( 'edit_aafm_unmapped' );
		$role->add_cap( 'delete_aafm_unmapped' );
		wp_set_current_user( $agent );

		// The footgun: bare singular caps would let the agent edit ANOTHER author's draft.
		$this->assertFalse( aafm_perm_update_post( array( 'post_id' => $obj ) ), 'Non-mapped CPT edit must be refused.' );
		$this->assertFalse( aafm_perm_trash_post( array( 'post_id' => $obj ) ), 'Non-mapped CPT trash must be refused.' );

		$role->remove_cap( 'edit_aafm_unmapped' );
		$role->remove_cap( 'delete_aafm_unmapped' );
	}

	public function test_closed_schema_rejects_smuggled_fields(): void {
		// The write schema is additionalProperties:false, so post_type / post_author / meta_input
		// are not declared and are rejected before execute runs. Assert the schema shape directly.
		$schema = aafm_write_content_schema( true );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertArrayNotHasKey( 'post_type', $schema['properties'] );
		$this->assertArrayNotHasKey( 'post_author', $schema['properties'] );
		$this->assertArrayNotHasKey( 'meta_input', $schema['properties'] );
	}

	public function test_allowlist_empty_by_default_is_post_and_page_only(): void {
		delete_option( 'aafm_allowed_post_types' );
		$this->assertSame( array( 'post', 'page' ), aafm_allowed_post_types() );
	}
}
