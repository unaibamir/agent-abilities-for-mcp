<?php
/**
 * Post write abilities: draft-forcing, publish gate, per-object caps, recoverable
 * trash, and the anti-escalation guards (author-forcing, type/status pinning,
 * content sanitization).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;
use WP_Post;

final class PostsWriteTest extends TestCase {

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
			array( 'aafm/create-draft', 'aafm/create-post', 'aafm/update-post', 'aafm/trash-post' )
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
		$this->assertSame( 'writes', $registry['aafm/create-draft']['group'] );
		$this->assertSame( 'write', $registry['aafm/create-draft']['risk'] );
		$this->assertSame( 'writes', $registry['aafm/create-post']['group'] );
		$this->assertSame( 'writes', $registry['aafm/update-post']['group'] );
		$this->assertSame( 'destructive', $registry['aafm/trash-post']['risk'] );
	}

	public function test_create_draft_forces_draft_status(): void {
		$this->acting_as( 'author' );
		// Even if the agent asks to publish, create-draft must produce a draft.
		$out = wp_get_ability( 'aafm/create-draft' )->execute(
			array(
				'title'   => 'Agent draft',
				'content' => 'Body',
				'status'  => 'publish',
			)
		);
		$this->assertSame( 'draft', get_post_status( $out['post']['id'] ) );
	}

	public function test_create_draft_needs_only_edit_posts_not_publish(): void {
		// A contributor can edit_posts but not publish_posts.
		$this->acting_as( 'contributor' );
		$this->assertTrue( wp_get_ability( 'aafm/create-draft' )->check_permissions( array() ) );
		$this->assertFalse( wp_get_ability( 'aafm/create-post' )->check_permissions( array() ) );
	}

	public function test_create_post_requires_publish_cap_and_publishes(): void {
		$this->acting_as( 'author' );
		$out = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title'   => 'Live post',
				'content' => 'Body',
			)
		);
		$this->assertSame( 'publish', get_post_status( $out['post']['id'] ) );
	}

	public function test_subscriber_denied_create_draft_is_audited(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse( wp_get_ability( 'aafm/create-draft' )->check_permissions( array() ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/create-draft', $abilities );
	}

	public function test_create_post_rejects_smuggled_author_and_forces_current_user(): void {
		// A second user the agent will try to impersonate as the post author.
		$victim = self::factory()->user->create( array( 'role' => 'author' ) );

		$agent = $this->acting_as( 'author' );

		// 1) A caller-supplied post_author is an undeclared field. The closed input
		// schema (additionalProperties:false) rejects the whole call before execute,
		// so the spoof can never reach wp_insert_post.
		$rejected = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title'       => 'Whose post is this',
				'content'     => 'Body',
				'post_author' => $victim,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $rejected );

		// 2) On a clean call, authorship is forced to the current (agent) user —
		// wp_insert_post defaults post_author to the current user; we never thread it.
		$out     = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title'   => 'My own post',
				'content' => 'Body',
			)
		);
		$created = get_post( $out['post']['id'] );
		$this->assertInstanceOf( WP_Post::class, $created );
		$this->assertSame( $agent, (int) $created->post_author );
		$this->assertNotSame( $victim, (int) $created->post_author );
	}

	public function test_create_post_rejects_smuggled_post_type_and_pins_to_post(): void {
		$this->acting_as( 'editor' );

		// 1) post_type is undeclared in the closed schema → the call is rejected.
		$rejected = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title'     => 'Not a nav item',
				'content'   => 'Body',
				'post_type' => 'nav_menu_item',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $rejected );

		// 2) A clean create pins the type to 'post' regardless — the agent has no say.
		$out = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title'   => 'Ordinary post',
				'content' => 'Body',
			)
		);
		$this->assertSame( 'post', get_post_type( $out['post']['id'] ) );
	}

	public function test_create_draft_sanitizes_script_in_content(): void {
		$this->acting_as( 'editor' );
		$out    = wp_get_ability( 'aafm/create-draft' )->execute(
			array(
				'title'   => 'XSS attempt',
				'content' => 'Hello<script>alert(1)</script> world',
			)
		);
		$stored = get_post( $out['post']['id'] );
		$this->assertInstanceOf( WP_Post::class, $stored );
		$this->assertStringNotContainsString( '<script', $stored->post_content );
		$this->assertStringContainsString( 'Hello', $stored->post_content );
	}

	public function test_create_draft_strips_script_tags_from_title(): void {
		$this->acting_as( 'editor' );
		$out    = wp_get_ability( 'aafm/create-draft' )->execute(
			array(
				'title'   => 'Clean<script>alert(1)</script>Title',
				'content' => 'Body',
			)
		);
		$stored = get_post( $out['post']['id'] );
		$this->assertStringNotContainsString( '<script', $stored->post_title );
	}

	public function test_update_post_enforces_per_object_cap(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$post  = self::factory()->post->create(
			array(
				'post_author' => $owner,
				'post_status' => 'publish',
			)
		);

		// A different author may not edit someone else's post.
		$this->acting_as( 'author' );
		$this->assertFalse(
			wp_get_ability( 'aafm/update-post' )->check_permissions( array( 'post_id' => $post ) )
		);
	}

	public function test_update_post_publish_requires_publish_cap(): void {
		// A contributor owns a draft: may edit it, but may NOT publish it.
		$contributor = $this->acting_as( 'contributor' );
		$post        = self::factory()->post->create(
			array(
				'post_author' => $contributor,
				'post_status' => 'draft',
			)
		);

		// Editing without a status change is allowed for the owner-contributor.
		$this->assertTrue(
			wp_get_ability( 'aafm/update-post' )->check_permissions( array( 'post_id' => $post ) )
		);
		// Attempting to flip to publish is gated by publish_posts, which a contributor lacks.
		$this->assertFalse(
			wp_get_ability( 'aafm/update-post' )->check_permissions(
				array(
					'post_id' => $post,
					'status'  => 'publish',
				)
			)
		);
	}

	public function test_trash_post_requires_per_object_delete_cap(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$post  = self::factory()->post->create(
			array(
				'post_author' => $owner,
				'post_status' => 'publish',
			)
		);

		// A different author cannot delete someone else's post.
		$this->acting_as( 'author' );
		$this->assertFalse(
			wp_get_ability( 'aafm/trash-post' )->check_permissions( array( 'post_id' => $post ) )
		);
	}

	public function test_trash_post_is_recoverable_not_permanent(): void {
		$this->acting_as( 'editor' );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_get_ability( 'aafm/trash-post' )->execute( array( 'post_id' => $post ) );

		$this->assertSame( 'trash', get_post_status( $post ) );
		// Still recoverable — not permanently deleted.
		$this->assertInstanceOf( WP_Post::class, get_post( $post ) );
	}

	public function test_write_schema_exposes_optional_enrichment_fields(): void {
		$props = aafm_write_content_schema( true )['properties'];

		// terms: object whose values are arrays of integers.
		$this->assertSame( 'object', $props['terms']['type'] );
		$this->assertSame( 'array', $props['terms']['additionalProperties']['type'] );
		$this->assertSame( 'integer', $props['terms']['additionalProperties']['items']['type'] );

		// featured_media: integer >= 1.
		$this->assertSame( 'integer', $props['featured_media']['type'] );
		$this->assertSame( 1, $props['featured_media']['minimum'] );

		// slug: string.
		$this->assertSame( 'string', $props['slug']['type'] );

		// meta: object of scalar values.
		$this->assertSame( 'object', $props['meta']['type'] );
		$this->assertSame(
			array( 'string', 'number', 'boolean', 'integer' ),
			$props['meta']['additionalProperties']['type']
		);

		// Schema stays closed — the first anti-escalation layer.
		$this->assertFalse( aafm_write_content_schema( true )['additionalProperties'] );
	}

	public function test_term_validator_accepts_real_terms_in_allowed_taxonomy(): void {
		$this->acting_as( 'editor' );
		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$ids = aafm_validate_term_ids_for_taxonomy( 'category', array( $term ) );
		$this->assertSame( array( $term ), $ids );
	}

	public function test_term_validator_rejects_nonexistent_term_id(): void {
		$this->acting_as( 'editor' );
		$err = aafm_validate_term_ids_for_taxonomy( 'category', array( 99999999 ) );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	public function test_term_validator_rejects_cross_taxonomy_id(): void {
		$this->acting_as( 'editor' );
		// A tag term id must be rejected when offered as a category id.
		$tag = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$err = aafm_validate_term_ids_for_taxonomy( 'category', array( $tag ) );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	public function test_term_validator_rejects_non_public_or_unknown_taxonomy(): void {
		$this->acting_as( 'editor' );
		$err = aafm_validate_term_ids_for_taxonomy( 'totally_made_up_tax', array( 1 ) );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	public function test_term_validator_denies_without_assign_terms_cap(): void {
		// A subscriber has no assign_terms capability on the category taxonomy.
		$this->acting_as( 'subscriber' );
		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$err  = aafm_validate_term_ids_for_taxonomy( 'category', array( $term ) );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	public function test_featured_validator_accepts_real_attachment(): void {
		$this->acting_as( 'editor' );
		$att = self::factory()->attachment->create_object(
			'feature.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		$this->assertSame( $att, aafm_validate_featured_attachment_id( $att ) );
	}

	public function test_featured_validator_rejects_plain_post_id(): void {
		$this->acting_as( 'editor' );
		$plain = self::factory()->post->create();
		$this->assertInstanceOf( WP_Error::class, aafm_validate_featured_attachment_id( $plain ) );
	}

	public function test_featured_validator_rejects_zero_and_missing(): void {
		$this->acting_as( 'editor' );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_featured_attachment_id( 0 ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_featured_attachment_id( 88888888 ) );
	}

	public function test_meta_validator_accepts_allowlisted_scalar(): void {
		$this->acting_as( 'editor' );
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );

		$out = aafm_validate_meta_payload( array( 'subtitle' => 'Hello' ) );
		$this->assertSame( array( 'subtitle' => 'Hello' ), $out );
	}

	public function test_meta_validator_rejects_non_allowlisted_key(): void {
		$this->acting_as( 'editor' );
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );

		$err = aafm_validate_meta_payload( array( 'not_allowed' => 'x' ) );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	public function test_meta_validator_rejects_hard_blocked_key(): void {
		$this->acting_as( 'editor' );
		// Even if an operator mistakenly allowlists a capabilities key, the hard floor wins.
		update_option( 'aafm_allowed_meta_keys', array( 'wp_capabilities' ) );

		$err = aafm_validate_meta_payload( array( 'wp_capabilities' => 'administrator' ) );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	public function test_meta_validator_rejects_non_scalar_value(): void {
		$this->acting_as( 'editor' );
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );

		$err = aafm_validate_meta_payload( array( 'subtitle' => array( 'nested' => 1 ) ) );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	public function test_validate_enrichment_returns_normalized_bundle(): void {
		$this->acting_as( 'editor' );
		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$att  = self::factory()->attachment->create_object(
			'b.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );

		$bundle = aafm_validate_write_enrichment(
			array(
				'terms'          => array( 'category' => array( $term ) ),
				'featured_media' => $att,
				'meta'           => array( 'subtitle' => 'Hi' ),
			)
		);

		$this->assertSame( array( 'category' => array( $term ) ), $bundle['terms'] );
		$this->assertSame( $att, $bundle['featured_media'] );
		$this->assertSame( array( 'subtitle' => 'Hi' ), $bundle['meta'] );
	}

	public function test_validate_enrichment_rejects_bad_taxonomy_before_apply(): void {
		$this->acting_as( 'editor' );
		$err = aafm_validate_write_enrichment(
			array( 'terms' => array( 'bogus_tax' => array( 1 ) ) )
		);
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	public function test_apply_enrichment_sets_terms_featured_and_meta(): void {
		$this->acting_as( 'editor' );
		$post = self::factory()->post->create();
		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$att  = self::factory()->attachment->create_object(
			'c.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );

		$bundle = array(
			'terms'          => array( 'category' => array( $term ) ),
			'featured_media' => $att,
			'meta'           => array( 'subtitle' => 'Applied' ),
		);
		$this->assertNull( aafm_apply_write_enrichment( $post, $bundle ) );

		$this->assertContains( $term, wp_get_post_terms( $post, 'category', array( 'fields' => 'ids' ) ) );
		$this->assertSame( $att, get_post_thumbnail_id( $post ) );
		$this->assertSame( 'Applied', get_post_meta( $post, 'subtitle', true ) );
	}

	public function test_create_applies_slug_terms_featured_and_meta(): void {
		$this->acting_as( 'editor' );
		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$att  = self::factory()->attachment->create_object(
			'd.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );

		$out = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title'          => 'Enriched',
				'content'        => 'Body',
				'slug'           => 'My Custom Slug',
				'terms'          => array( 'category' => array( $term ) ),
				'featured_media' => $att,
				'meta'           => array( 'subtitle' => 'Sub' ),
			)
		);

		$id = $out['post']['id'];
		$this->assertSame( 'my-custom-slug', get_post_field( 'post_name', $id ) );
		$this->assertContains( $term, wp_get_post_terms( $id, 'category', array( 'fields' => 'ids' ) ) );
		$this->assertSame( $att, get_post_thumbnail_id( $id ) );
		$this->assertSame( 'Sub', get_post_meta( $id, 'subtitle', true ) );
	}

	public function test_create_rejects_bad_enrichment_and_writes_no_post(): void {
		$this->acting_as( 'editor' );
		$before = (int) wp_count_posts( 'post' )->publish;

		$out = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title' => 'Should not persist',
				'terms' => array( 'category' => array( 99999999 ) ), // Nonexistent term.
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( $before, (int) wp_count_posts( 'post' )->publish );
	}

	public function test_update_applies_slug_terms_featured_and_meta(): void {
		$this->acting_as( 'editor' );
		$post = self::factory()->post->create();
		$term = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$att  = self::factory()->attachment->create_object(
			'e.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );

		$out = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id'        => $post,
				'slug'           => 'Renamed Slug',
				'terms'          => array( 'category' => array( $term ) ),
				'featured_media' => $att,
				'meta'           => array( 'subtitle' => 'Updated' ),
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'renamed-slug', get_post_field( 'post_name', $post ) );
		$this->assertContains( $term, wp_get_post_terms( $post, 'category', array( 'fields' => 'ids' ) ) );
		$this->assertSame( $att, get_post_thumbnail_id( $post ) );
		$this->assertSame( 'Updated', get_post_meta( $post, 'subtitle', true ) );
	}

	public function test_update_rejects_bad_enrichment_and_leaves_post_unchanged(): void {
		$this->acting_as( 'editor' );
		$post = self::factory()->post->create( array( 'post_title' => 'Original' ) );

		$out = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id' => $post,
				'title'   => 'Changed Title',
				'meta'    => array( 'never_allowed_key' => 'x' ), // Non-allowlisted.
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
		// Validation happens before wp_update_post, so the title must NOT have changed.
		$this->assertSame( 'Original', get_post_field( 'post_title', $post ) );
	}

	public function test_write_descriptions_mention_optional_fields(): void {
		$desc = aafm_args_create_draft()['description'];
		$this->assertStringContainsString( 'terms', $desc );
		$this->assertStringContainsString( 'featured_media', $desc );
		$this->assertStringContainsString( 'slug', $desc );
		$this->assertStringContainsString( 'meta', $desc );
	}

	public function test_create_denies_term_assign_without_assign_terms_cap(): void {
		// The per-taxonomy assign_terms gate must deny at the ability level for a creating
		// user who lacks that exact cap. Core maps category->cap->assign_terms to a cap
		// every editor/contributor holds, so a custom public taxonomy whose assign_terms is
		// a primitive cap no standard role owns isolates the gate cleanly.
		register_taxonomy(
			'aafm_gated_tax',
			'post',
			array(
				'public'       => true,
				'hierarchical' => true,
				'capabilities' => array(
					'assign_terms' => 'aafm_assign_gated_terms',
				),
			)
		);

		$this->acting_as( 'editor' );
		$term = self::factory()->term->create( array( 'taxonomy' => 'aafm_gated_tax' ) );

		$out = wp_get_ability( 'aafm/create-draft' )->execute(
			array(
				'title' => 'Draft with terms',
				'terms' => array( 'aafm_gated_tax' => array( $term ) ),
			)
		);

		unregister_taxonomy( 'aafm_gated_tax' );

		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_update_denies_term_assign_without_assign_terms_cap(): void {
		// Symmetric to the create-path gate: the update path shares the same
		// aafm_validate_write_enrichment()->aafm_validate_term_ids_for_taxonomy() gate, so a
		// user lacking the taxonomy's primitive assign_terms cap must be denied before any
		// write. A custom public taxonomy whose assign_terms is a cap no standard role holds
		// isolates the gate; this test locks the update path against a refactor that drops it.
		register_taxonomy(
			'aafm_gated_tax',
			'post',
			array(
				'public'       => true,
				'hierarchical' => true,
				'capabilities' => array(
					'assign_terms' => 'aafm_assign_gated_terms',
				),
			)
		);

		// The acting editor CAN edit this post but lacks the gated assign_terms cap.
		$editor = $this->acting_as( 'editor' );
		$post   = self::factory()->post->create( array( 'post_author' => $editor ) );
		$term   = self::factory()->term->create( array( 'taxonomy' => 'aafm_gated_tax' ) );

		$out = wp_get_ability( 'aafm/update-post' )->execute(
			array(
				'post_id' => $post,
				'terms'   => array( 'aafm_gated_tax' => array( $term ) ),
			)
		);

		$assigned = wp_get_post_terms( $post, 'aafm_gated_tax', array( 'fields' => 'ids' ) );

		unregister_taxonomy( 'aafm_gated_tax' );

		$this->assertInstanceOf( WP_Error::class, $out );
		// The gate denied before the write — the post's terms in that taxonomy are unchanged.
		$this->assertSame( array(), $assigned );
	}

	public function test_create_rejects_non_allowlisted_meta_at_ability_level(): void {
		$this->acting_as( 'editor' );
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );

		$out = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title' => 'Bad meta',
				'meta'  => array( 'arbitrary_unlisted' => 'x' ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $out );
	}

	public function test_enrichment_does_not_weaken_author_forcing(): void {
		// Anti-escalation: even with enrichment present, post_author is the agent, never spoofed.
		$me = $this->acting_as( 'editor' );
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );

		$out = wp_get_ability( 'aafm/create-post' )->execute(
			array(
				'title' => 'Authored',
				'meta'  => array( 'subtitle' => 'x' ),
			)
		);

		$this->assertSame( $me, (int) get_post_field( 'post_author', $out['post']['id'] ) );
	}
}
