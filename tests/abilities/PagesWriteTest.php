<?php
/**
 * Page write abilities: per-object caps (edit_page / delete_page / publish_pages),
 * recoverable trash, page-type pinning, and the anti-escalation guards inherited
 * from the shared post-write helpers (author-forcing, type/status pinning,
 * content sanitization).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use WP_Error;
use WP_Post;

final class PagesWriteTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The audited registration wrapper logs every permission check and execute to
		// the custom table, so it must exist before any ability is invoked.
		oversio_install_activity_log();
		oversio_clear_activity_log();

		// Register categories + enabled abilities inside their gated init actions,
		// simulated by pushing the action name onto $wp_current_filter — the idiom
		// WP core's own ability test trait uses. wp_register_ability() refuses to run
		// otherwise, and do_action() on the core hook trips the WPCS non-prefixed-
		// hookname sniff (Phase 1 carried issue).
		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option(
			'oversio_enabled_abilities',
			array( 'oversio/create-page', 'oversio/update-page', 'oversio/trash-page' )
		);
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );
	}

	public function test_page_writes_are_in_registry_as_writes(): void {
		$registry = oversio_get_abilities_registry();
		$this->assertSame( 'writes', $registry['oversio/create-page']['group'] );
		$this->assertSame( 'write', $registry['oversio/create-page']['risk'] );
		$this->assertSame( 'writes', $registry['oversio/update-page']['group'] );
		$this->assertSame( 'destructive', $registry['oversio/trash-page']['risk'] );
	}

	public function test_create_page_requires_publish_pages_and_publishes(): void {
		$this->acting_as( 'editor' );
		$out = wp_get_ability( 'oversio/create-page' )->execute(
			array(
				'title'   => 'About',
				'content' => 'Hi',
			)
		);
		$this->assertSame( 'page', get_post_type( $out['post']['id'] ) );
		$this->assertSame( 'publish', get_post_status( $out['post']['id'] ) );
	}

	public function test_create_page_publish_is_split_from_edit(): void {
		// A contributor can edit_posts/pages but not publish — create-page is gated
		// by publish_pages, so the contributor is denied.
		$this->acting_as( 'contributor' );
		$this->assertFalse(
			wp_get_ability( 'oversio/create-page' )->check_permissions( array() )
		);
	}

	public function test_subscriber_denied_create_page_is_audited(): void {
		$this->acting_as( 'subscriber' );
		$this->assertFalse(
			wp_get_ability( 'oversio/create-page' )->check_permissions( array() )
		);

		$denied    = oversio_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'oversio/create-page', $abilities );
	}

	public function test_create_page_rejects_smuggled_author_and_forces_current_user(): void {
		$victim = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent  = $this->acting_as( 'editor' );

		// 1) A caller-supplied post_author is an undeclared field. The closed input
		// schema (additionalProperties:false) rejects the whole call before execute,
		// so the spoof can never reach wp_insert_post.
		$rejected = wp_get_ability( 'oversio/create-page' )->execute(
			array(
				'title'       => 'Whose page is this',
				'content'     => 'Body',
				'post_author' => $victim,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $rejected );

		// 2) On a clean call, authorship is forced to the current (agent) user.
		$out     = wp_get_ability( 'oversio/create-page' )->execute(
			array(
				'title'   => 'My own page',
				'content' => 'Body',
			)
		);
		$created = get_post( $out['post']['id'] );
		$this->assertInstanceOf( WP_Post::class, $created );
		$this->assertSame( $agent, (int) $created->post_author );
		$this->assertNotSame( $victim, (int) $created->post_author );
	}

	public function test_create_page_rejects_smuggled_post_type_and_pins_to_page(): void {
		$this->acting_as( 'editor' );

		// 1) post_type is undeclared in the closed schema → the call is rejected.
		$rejected = wp_get_ability( 'oversio/create-page' )->execute(
			array(
				'title'     => 'Not a post',
				'content'   => 'Body',
				'post_type' => 'post',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $rejected );

		// 2) A clean create pins the type to 'page' regardless — the agent has no say.
		$out = wp_get_ability( 'oversio/create-page' )->execute(
			array(
				'title'   => 'Ordinary page',
				'content' => 'Body',
			)
		);
		$this->assertSame( 'page', get_post_type( $out['post']['id'] ) );
	}

	public function test_create_page_sanitizes_script_in_content(): void {
		$this->acting_as( 'editor' );
		$out    = wp_get_ability( 'oversio/create-page' )->execute(
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

	public function test_update_page_enforces_per_object_edit_cap(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		// Authors cannot edit pages at all (no edit_pages cap).
		$this->acting_as( 'author' );
		$this->assertFalse(
			wp_get_ability( 'oversio/update-page' )->check_permissions( array( 'page_id' => $page ) )
		);
	}

	public function test_update_page_publish_requires_publish_pages(): void {
		// An editor can edit AND publish pages — both allowed.
		$this->acting_as( 'editor' );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$this->assertTrue(
			wp_get_ability( 'oversio/update-page' )->check_permissions( array( 'page_id' => $page ) )
		);
		$this->assertTrue(
			wp_get_ability( 'oversio/update-page' )->check_permissions(
				array(
					'page_id' => $page,
					'status'  => 'publish',
				)
			)
		);
	}

	public function test_update_page_rejects_non_page_id(): void {
		// A blog post ID must not be writable through the page ability.
		$post = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$this->acting_as( 'editor' );
		$result = wp_get_ability( 'oversio/update-page' )->execute(
			array(
				'page_id' => $post,
				'title'   => 'Hijacked',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		// The post is untouched.
		$this->assertNotSame( 'Hijacked', get_post( $post )->post_title );
	}

	public function test_trash_page_requires_per_object_delete_cap(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);

		// Authors lack delete_pages entirely.
		$this->acting_as( 'author' );
		$this->assertFalse(
			wp_get_ability( 'oversio/trash-page' )->check_permissions( array( 'page_id' => $page ) )
		);
	}

	public function test_trash_page_is_recoverable_not_permanent(): void {
		$this->acting_as( 'editor' );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		wp_get_ability( 'oversio/trash-page' )->execute( array( 'page_id' => $page ) );

		$this->assertSame( 'trash', get_post_status( $page ) );
		// Still recoverable — not permanently deleted.
		$this->assertInstanceOf( WP_Post::class, get_post( $page ) );
	}

	public function test_trash_page_rejects_non_page_id(): void {
		// trash-page must not trash a blog post.
		$post = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$this->acting_as( 'editor' );
		$result = wp_get_ability( 'oversio/trash-page' )->execute( array( 'page_id' => $post ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'publish', get_post_status( $post ) );
	}

	public function test_create_page_inherits_enrichment(): void {
		$this->acting_as( 'editor' );
		$att = self::factory()->attachment->create_object(
			'p.jpg',
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_type'      => 'attachment',
			)
		);
		update_option( 'oversio_allowed_meta_keys', array( 'subtitle' ) );

		$out = wp_get_ability( 'oversio/create-page' )->execute(
			array(
				'title'          => 'Enriched Page',
				'slug'           => 'Enriched Page Slug',
				'featured_media' => $att,
				'meta'           => array( 'subtitle' => 'PageSub' ),
			)
		);

		$id = $out['post']['id'];
		$this->assertSame( 'page', get_post_type( $id ) );
		$this->assertSame( 'enriched-page-slug', get_post_field( 'post_name', $id ) );
		$this->assertSame( $att, get_post_thumbnail_id( $id ) );
		$this->assertSame( 'PageSub', get_post_meta( $id, 'subtitle', true ) );
	}
}
