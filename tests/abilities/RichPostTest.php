<?php
/**
 * Unit tests for oversio_rich_post() — the enriched post-assembly helper.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Abilities;

use Oversio\Tests\TestCase;
use WP_Post;
use WP_User;

final class RichPostTest extends TestCase {

	public function test_rich_post_includes_all_base_redactor_keys(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Base Keys Survive',
				'post_content' => 'Hello body.',
			)
		);
		$shape   = oversio_rich_post( get_post( $post_id ) );

		foreach ( array( 'id', 'title', 'status', 'type', 'slug', 'link', 'author_id', 'date_gmt', 'modified_gmt' ) as $key ) {
			$this->assertArrayHasKey( $key, $shape, "Missing base key {$key}" );
		}
		$this->assertSame( $post_id, $shape['id'] );
		$this->assertSame( 'Base Keys Survive', $shape['title'] );
	}

	public function test_rich_post_content_rendered_by_default(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => "First para.\n\nSecond para.",
			)
		);
		$shape   = oversio_rich_post( get_post( $post_id ) );

		$this->assertArrayHasKey( 'content', $shape );
		// the_content wraps paragraphs in <p> tags via wpautop.
		$this->assertStringContainsString( '<p>', $shape['content'] );
	}

	public function test_rich_post_content_raw_returns_stored_markup(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Raw [shortcode] body no wpautop',
			)
		);
		$shape   = oversio_rich_post( get_post( $post_id ), array( 'content_format' => 'raw' ) );

		$this->assertSame( 'Raw [shortcode] body no wpautop', $shape['content'] );
	}

	public function test_rich_post_unknown_content_format_falls_back_to_rendered(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => "Para one.\n\nPara two.",
			)
		);
		$shape   = oversio_rich_post( get_post( $post_id ), array( 'content_format' => 'bogus' ) );

		$this->assertStringContainsString( '<p>', $shape['content'] );
	}

	public function test_rich_post_uses_manual_excerpt_when_present(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => 'A hand-written excerpt.',
				'post_content' => 'The full body that should not be trimmed here.',
			)
		);
		$shape   = oversio_rich_post( get_post( $post_id ) );

		$this->assertSame( 'A hand-written excerpt.', $shape['excerpt'] );
	}

	public function test_rich_post_auto_excerpt_when_none(): void {
		$body    = str_repeat( 'word ', 200 );
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_excerpt' => '',
				'post_content' => $body,
			)
		);
		$shape   = oversio_rich_post( get_post( $post_id ) );

		$this->assertNotSame( '', $shape['excerpt'] );
		// Trimmed to 55 words + the trailing hellip from wp_trim_words.
		$this->assertLessThan( strlen( $body ), strlen( $shape['excerpt'] ) );
	}

	public function test_rich_post_terms_grouped_by_taxonomy(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$cat_id  = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'News',
			)
		);
		$tag_id  = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Alpha',
			)
		);
		wp_set_object_terms( $post_id, array( (int) $cat_id ), 'category' );
		wp_set_object_terms( $post_id, array( (int) $tag_id ), 'post_tag' );

		$shape = oversio_rich_post( get_post( $post_id ) );

		$this->assertArrayHasKey( 'terms', $shape );
		$this->assertArrayHasKey( 'category', $shape['terms'] );
		$this->assertArrayHasKey( 'post_tag', $shape['terms'] );
		$this->assertSame( 'News', $shape['terms']['category'][0]['name'] );
		$this->assertSame(
			array( 'id', 'name', 'slug' ),
			array_keys( $shape['terms']['category'][0] )
		);
	}

	public function test_rich_post_author_is_id_and_display_name_only(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Jane Writer',
				'user_email'   => 'jane@example.com',
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $user_id,
			)
		);
		$shape   = oversio_rich_post( get_post( $post_id ) );

		$this->assertSame(
			array( 'id', 'display_name' ),
			array_keys( $shape['author'] )
		);
		$this->assertSame( $user_id, $shape['author']['id'] );
		$this->assertSame( 'Jane Writer', $shape['author']['display_name'] );
		$this->assertStringNotContainsString( 'jane@example.com', (string) wp_json_encode( $shape ) );
	}

	public function test_rich_post_featured_image_null_when_absent(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$shape   = oversio_rich_post( get_post( $post_id ) );

		$this->assertArrayHasKey( 'featured_image', $shape );
		$this->assertNull( $shape['featured_image'] );
	}

	public function test_rich_post_featured_image_shape_when_present(): void {
		$post_id   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$attach_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $post_id );
		set_post_thumbnail( $post_id, $attach_id );
		update_post_meta( $attach_id, '_wp_attachment_image_alt', 'Canola field' );

		$shape = oversio_rich_post( get_post( $post_id ) );

		$this->assertIsArray( $shape['featured_image'] );
		$this->assertSame(
			array( 'id', 'url', 'alt' ),
			array_keys( $shape['featured_image'] )
		);
		$this->assertSame( (int) $attach_id, $shape['featured_image']['id'] );
		$this->assertSame( 'Canola field', $shape['featured_image']['alt'] );
	}

	public function test_rich_post_meta_only_includes_allowlisted_scalar_keys(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, 'subtitle', 'A Governed Subtitle' );
		update_post_meta( $post_id, 'secret_internal', 'should-never-leak' );
		update_post_meta( $post_id, 'structured', array( 'a', 'b' ) );

		add_filter(
			'oversio_allowed_meta_keys',
			static fn(): array => array( 'subtitle', 'structured' )
		);

		$shape = oversio_rich_post( get_post( $post_id ) );

		$this->assertArrayHasKey( 'meta', $shape );
		$this->assertSame( 'A Governed Subtitle', $shape['meta']['subtitle'] );
		// Non-allowlisted key is absent.
		$this->assertArrayNotHasKey( 'secret_internal', $shape['meta'] );
		// Allowlisted-but-non-scalar value is skipped, never dumped.
		$this->assertArrayNotHasKey( 'structured', $shape['meta'] );
		$this->assertStringNotContainsString( 'should-never-leak', (string) wp_json_encode( $shape ) );
	}

	public function test_rich_post_omits_content_when_include_content_false(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Heavy body here.',
			)
		);
		$shape   = oversio_rich_post( get_post( $post_id ), array( 'include_content' => false ) );

		$this->assertArrayNotHasKey( 'content', $shape );
		// Light fields still present.
		$this->assertArrayHasKey( 'excerpt', $shape );
		$this->assertArrayHasKey( 'terms', $shape );
		$this->assertArrayHasKey( 'author', $shape );
		$this->assertArrayHasKey( 'featured_image', $shape );
		$this->assertArrayHasKey( 'meta', $shape );
	}

	public function test_rich_post_includes_content_by_default(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'Body present.',
			)
		);
		$shape   = oversio_rich_post( get_post( $post_id ) );

		$this->assertArrayHasKey( 'content', $shape );
	}

	public function test_rich_post_protected_published_post_never_leaks_body(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'TopSecretPass123',
				'post_content'  => 'Body holding SECRETMARKER and more.',
				// Explicit empty excerpt: the WP test factory injects a default
				// "Post excerpt N" otherwise, and get_the_excerpt() on a protected
				// post would then return the core protected-post placeholder.
				'post_excerpt'  => '',
			)
		);
		$post    = get_post( $post_id );

		foreach ( array( 'rendered', 'raw' ) as $format ) {
			$shape = oversio_rich_post( $post, array( 'content_format' => $format ) );
			$json  = (string) wp_json_encode( $shape );

			$this->assertArrayNotHasKey( 'content', $shape, "content must be omitted for a protected post ({$format})" );
			$this->assertStringNotContainsString( 'TopSecretPass123', $json, "password leaked ({$format})" );
			$this->assertStringNotContainsString( 'SECRETMARKER', $json, "body marker leaked ({$format})" );
			$this->assertStringNotContainsString( 'Body holding', $json, "raw body leaked ({$format})" );
			// No manual excerpt → must fall back to '' (never the auto-excerpt of the body).
			$this->assertSame( '', $shape['excerpt'], "excerpt leaked the body ({$format})" );
		}
	}

	public function test_rich_post_protected_post_surfaces_manual_excerpt_but_not_body(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'TopSecretPass123',
				'post_content'  => 'Body holding SECRETBODY and more.',
				'post_excerpt'  => 'Safe summary',
			)
		);
		$post    = get_post( $post_id );

		foreach ( array( 'rendered', 'raw' ) as $format ) {
			$shape = oversio_rich_post( $post, array( 'content_format' => $format ) );
			$json  = (string) wp_json_encode( $shape );

			// The hand-authored excerpt IS safe to surface.
			$this->assertSame( 'Safe summary', $shape['excerpt'], "manual excerpt must survive ({$format})" );
			// The body, however, never appears — no content key, no password.
			$this->assertArrayNotHasKey( 'content', $shape, "content must be omitted for a protected post ({$format})" );
			$this->assertStringNotContainsString( 'SECRETBODY', $json, "body leaked ({$format})" );
			$this->assertStringNotContainsString( 'TopSecretPass123', $json, "password leaked ({$format})" );
		}
	}

	public function test_rich_post_output_schema_declares_nested_object_shapes(): void {
		$props = oversio_rich_post_output_properties();

		// author: object|null with id + display_name.
		$this->assertSame(
			array( 'id', 'display_name' ),
			array_keys( $props['author']['properties'] )
		);

		// featured_image: object|null with id + url + alt.
		$this->assertSame(
			array( 'id', 'url', 'alt' ),
			array_keys( $props['featured_image']['properties'] )
		);

		// terms: object whose additionalProperties is an array of {id, name, slug}.
		$term_item = $props['terms']['additionalProperties']['items'];
		$this->assertSame( 'object', $term_item['type'] );
		$this->assertSame(
			array( 'id', 'name', 'slug' ),
			array_keys( $term_item['properties'] )
		);

		// meta: free-form allowlisted scalars.
		$this->assertTrue( $props['meta']['additionalProperties'] );

		// content stays a string with a conditional-presence description, and is never
		// in a required list (it is conditionally absent).
		$this->assertSame( 'string', $props['content']['type'] );
		$this->assertArrayHasKey( 'description', $props['content'] );
		$this->assertArrayNotHasKey( 'required', $props );
	}

	public function test_rich_post_payload_conforms_to_declared_nested_schema(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Schema Author',
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $user_id,
			)
		);
		$cat_id  = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Conformance',
			)
		);
		wp_set_object_terms( $post_id, array( (int) $cat_id ), 'category' );

		$shape = oversio_rich_post( get_post( $post_id ) );

		// author keys match the declared author.properties.
		$this->assertSame(
			array( 'id', 'display_name' ),
			array_keys( $shape['author'] )
		);
		// Each grouped term matches the declared terms item shape.
		$this->assertSame(
			array( 'id', 'name', 'slug' ),
			array_keys( $shape['terms']['category'][0] )
		);
	}
}
