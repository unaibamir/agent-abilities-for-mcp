<?php
/**
 * Integration tests: the five read getters return the enriched rich-post shape.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class ReadGettersEnrichmentTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The permission-layer proof drives the audited decorator, which writes an
		// activity-log row; create the (temporary) table so that INSERT runs clean.
		aafm_install_activity_log();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_get_post_returns_enriched_shape_with_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => "Para A.\n\nPara B.",
			)
		);
		$out     = aafm_exec_get_post( array( 'post_id' => $post_id ) );

		$this->assertArrayHasKey( 'post', $out );
		foreach ( array( 'content', 'excerpt', 'terms', 'author', 'featured_image', 'meta' ) as $key ) {
			$this->assertArrayHasKey( $key, $out['post'], "get-post missing {$key}" );
		}
		$this->assertStringContainsString( '<p>', $out['post']['content'] );
	}

	public function test_get_post_raw_content_format(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Raw body [sc] here',
			)
		);
		$out     = aafm_exec_get_post(
			array(
				'post_id'        => $post_id,
				'content_format' => 'raw',
			)
		);

		$this->assertSame( 'Raw body [sc] here', $out['post']['content'] );
	}

	public function test_get_post_include_content_false_omits_content_but_keeps_length(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'twelve bytes',
			)
		);
		$out     = aafm_exec_get_post(
			array(
				'post_id'         => $post_id,
				'include_content' => false,
			)
		);

		$this->assertArrayNotHasKey( 'content', $out['post'] );
		$this->assertSame( 12, $out['post']['content_length'] );
	}

	public function test_get_posts_default_omits_content_keeps_light_fields(): void {
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		$out = aafm_exec_get_posts( array() );

		$this->assertArrayHasKey( 'posts', $out );
		$this->assertArrayHasKey( 'total', $out );
		$this->assertNotEmpty( $out['posts'] );
		$first = $out['posts'][0];
		$this->assertArrayNotHasKey( 'content', $first );
		$this->assertArrayHasKey( 'excerpt', $first );
		$this->assertArrayHasKey( 'terms', $first );
		$this->assertArrayHasKey( 'author', $first );
	}

	public function test_get_posts_include_content_true_adds_content(): void {
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Listed body.',
			)
		);
		$out = aafm_exec_get_posts( array( 'include_content' => true ) );

		$this->assertArrayHasKey( 'content', $out['posts'][0] );
	}

	public function test_get_page_returns_enriched_shape_with_content(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => "Page A.\n\nPage B.",
			)
		);
		$out     = aafm_exec_get_page( array( 'page_id' => $page_id ) );

		foreach ( array( 'content', 'excerpt', 'terms', 'author', 'featured_image', 'meta' ) as $key ) {
			$this->assertArrayHasKey( $key, $out['post'], "get-page missing {$key}" );
		}
		$this->assertStringContainsString( '<p>', $out['post']['content'] );
		// Guards the get-page → post type pin: a regression to 'post' must fail here.
		$this->assertSame( 'page', $out['post']['type'] );
	}

	public function test_get_page_include_content_false_omits_content_but_keeps_length(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'eleven byte',
			)
		);
		$out     = aafm_exec_get_page(
			array(
				'page_id'         => $page_id,
				'include_content' => false,
			)
		);

		$this->assertArrayNotHasKey( 'content', $out['post'] );
		$this->assertSame( 11, $out['post']['content_length'] );
	}

	public function test_get_pages_default_omits_content(): void {
		self::factory()->post->create_many(
			2,
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$out = aafm_exec_get_pages( array() );

		$this->assertArrayHasKey( 'total', $out );
		$this->assertNotEmpty( $out['posts'] );
		$this->assertArrayNotHasKey( 'content', $out['posts'][0] );
		$this->assertArrayHasKey( 'terms', $out['posts'][0] );
		// Guards the get-pages → get-posts delegation: every item must be a page,
		// so a regression in the post_type pin (back to 'post') is caught.
		$this->assertSame( 'page', $out['posts'][0]['type'] );
	}

	public function test_get_pages_include_content_true_adds_content(): void {
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'Listed page body.',
			)
		);
		$out = aafm_exec_get_pages( array( 'include_content' => true ) );

		$this->assertArrayHasKey( 'content', $out['posts'][0] );
	}

	public function test_search_content_default_omits_content_keeps_light_fields(): void {
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Findme Alpha',
				'post_content' => 'Body alpha.',
			)
		);
		$out = aafm_exec_search_content( array( 'search' => 'Findme' ) );

		$this->assertArrayHasKey( 'results', $out );
		$this->assertArrayHasKey( 'total', $out );
		$this->assertNotEmpty( $out['results'] );
		$first = $out['results'][0];
		$this->assertArrayNotHasKey( 'content', $first );
		$this->assertArrayHasKey( 'excerpt', $first );
		$this->assertArrayHasKey( 'terms', $first );
		$this->assertArrayHasKey( 'author', $first );
	}

	public function test_search_content_include_content_true_adds_content(): void {
		self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Findme Beta',
				'post_content' => 'Body beta.',
			)
		);
		$out = aafm_exec_search_content(
			array(
				'search'          => 'Findme',
				'include_content' => true,
			)
		);

		$this->assertArrayHasKey( 'content', $out['results'][0] );
	}

	public function test_list_getters_with_include_content_never_leak_protected_body(): void {
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'Findme Protected',
				'post_password' => 'TopSecretPass123',
				'post_content'  => 'Body holding SECRETMARKER.',
			)
		);

		$payloads = array(
			aafm_exec_get_posts( array( 'include_content' => true ) ),
			aafm_exec_search_content(
				array(
					'search'          => 'Findme',
					'include_content' => true,
				)
			),
		);

		foreach ( $payloads as $payload ) {
			$json = (string) wp_json_encode( $payload );
			$this->assertStringNotContainsString( 'TopSecretPass123', $json );
			$this->assertStringNotContainsString( 'SECRETMARKER', $json );
			$this->assertStringNotContainsString( 'Body holding', $json );
		}
	}

	public function test_get_post_through_permission_layer_never_leaks_protected_body(): void {
		// Register categories + the get-post ability inside their gated init actions,
		// then drive the REAL gate end-to-end (not the bare aafm_exec_* helper).
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-post' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'TopSecretPass123',
				'post_content'  => 'Body holding SECRETMARKER.',
			)
		);

		$ability = wp_get_ability( 'aafm/get-post' );
		$this->assertNotNull( $ability, 'get-post ability must be registered' );

		$result = $ability->execute(
			array(
				'post_id'        => $post_id,
				'content_format' => 'raw',
			)
		);
		$json   = (string) wp_json_encode( $result );

		$this->assertStringNotContainsString( 'TopSecretPass123', $json );
		$this->assertStringNotContainsString( 'SECRETMARKER', $json );
		$this->assertStringNotContainsString( 'Body holding', $json );
	}

	/**
	 * The featured image in the rich post shape is the post's own thumbnail when it has one, and
	 * null when it has none.
	 */
	public function test_rich_post_featured_image_is_the_posts_own_thumbnail_or_null(): void {
		$with       = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$without    = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$attachment = (int) self::factory()->attachment->create(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => $with,
			)
		);
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'Thumb alt' );
		// set_post_thumbnail() needs a real image file; the stored meta is what get_post_thumbnail_id() reads.
		update_post_meta( $with, '_thumbnail_id', $attachment );

		$this->assertSame(
			array(
				'id'  => $attachment,
				'url' => (string) wp_get_attachment_url( $attachment ),
				'alt' => 'Thumb alt',
			),
			aafm_rich_post( get_post( $with ) )['featured_image']
		);
		$this->assertNull( aafm_rich_post( get_post( $without ) )['featured_image'] );
	}

	/**
	 * A get_comment filter that hands back another comment does not decide the status: the load
	 * is not exact, so a status core does not recognize reads as unknown.
	 */
	public function test_comment_status_is_unknown_when_a_filter_swaps_in_another_comment(): void {
		$post    = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$asked   = (int) self::factory()->comment->create( array( 'comment_post_ID' => $post ) );
		$swapped = (int) self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post,
				'comment_approved' => 'custom-x',
			)
		);
		$swap    = static function ( $comment ) use ( $asked, $swapped ) {
			return ( $comment instanceof \WP_Comment && $asked === (int) $comment->comment_ID ) ? \WP_Comment::get_instance( $swapped ) : $comment;
		};
		add_filter( 'get_comment', $swap );
		try {
			$status = aafm_comment_status_string( $asked );
		} finally {
			remove_filter( 'get_comment', $swap );
		}

		$this->assertSame( 'unknown', $status );
	}

	/**
	 * Post id 0 is no post, even when a global post is set: list-revisions refuses it instead of
	 * listing the global post's revisions.
	 */
	public function test_list_revisions_refuses_post_id_zero_with_a_global_post_set(): void {
		$global = (int) self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'first',
			)
		);
		wp_update_post(
			array(
				'ID'           => $global,
				'post_content' => 'second',
			)
		);
		$this->assertNotEmpty( wp_get_post_revisions( $global ), 'the global post has a revision' );

		$saved           = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = get_post( $global ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the global post is the case under test; restored below.
		try {
			$out = aafm_exec_list_revisions( array( 'post_id' => 0 ) );
		} finally {
			$GLOBALS['post'] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restores the value saved above.
		}

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'aafm_error', $out->get_error_code() );
	}
}
