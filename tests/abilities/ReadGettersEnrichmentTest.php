<?php
/**
 * Integration tests: the five read getters return the enriched rich-post shape.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;

final class ReadGettersEnrichmentTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// The permission-layer proof drives the audited decorator, which writes an
		// activity-log row; create the (temporary) table so that INSERT runs clean.
		oversio_install_activity_log();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_get_post_returns_enriched_shape_with_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => "Para A.\n\nPara B.",
			)
		);
		$out     = oversio_exec_get_post( array( 'post_id' => $post_id ) );

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
		$out     = oversio_exec_get_post(
			array(
				'post_id'        => $post_id,
				'content_format' => 'raw',
			)
		);

		$this->assertSame( 'Raw body [sc] here', $out['post']['content'] );
	}

	public function test_get_posts_default_omits_content_keeps_light_fields(): void {
		self::factory()->post->create_many( 3, array( 'post_status' => 'publish' ) );
		$out = oversio_exec_get_posts( array() );

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
		$out = oversio_exec_get_posts( array( 'include_content' => true ) );

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
		$out     = oversio_exec_get_page( array( 'page_id' => $page_id ) );

		foreach ( array( 'content', 'excerpt', 'terms', 'author', 'featured_image', 'meta' ) as $key ) {
			$this->assertArrayHasKey( $key, $out['post'], "get-page missing {$key}" );
		}
		$this->assertStringContainsString( '<p>', $out['post']['content'] );
		// Guards the get-page → post type pin: a regression to 'post' must fail here.
		$this->assertSame( 'page', $out['post']['type'] );
	}

	public function test_get_pages_default_omits_content(): void {
		self::factory()->post->create_many(
			2,
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$out = oversio_exec_get_pages( array() );

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
		$out = oversio_exec_get_pages( array( 'include_content' => true ) );

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
		$out = oversio_exec_search_content( array( 'search' => 'Findme' ) );

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
		$out = oversio_exec_search_content(
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
			oversio_exec_get_posts( array( 'include_content' => true ) ),
			oversio_exec_search_content(
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
		// then drive the REAL gate end-to-end (not the bare oversio_exec_* helper).
		$this->in_action( 'wp_abilities_api_categories_init', 'oversio_register_categories' );
		update_option( 'oversio_enabled_abilities', array( 'oversio/get-post' ) );
		$this->in_action( 'wp_abilities_api_init', 'oversio_register_enabled_abilities' );

		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'TopSecretPass123',
				'post_content'  => 'Body holding SECRETMARKER.',
			)
		);

		$ability = wp_get_ability( 'oversio/get-post' );
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
}
