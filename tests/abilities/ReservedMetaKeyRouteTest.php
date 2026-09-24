<?php
/**
 * A post-meta call naming a WordPress-reserved key is refused with the route that does the job.
 *
 * The defect this pins came in from a wordpress.org user as "plugin does not allow to update image
 * alt". WordPress stores image alt text in `_wp_attachment_image_alt`, so an agent asked to fix alt
 * text calls aafm-update-post-meta on exactly that key. It is protected meta, the shared meta gate
 * returns a bare false, and the adapter converts any non-true into the literal string "Permission
 * denied" (ToolsHandler.php:150) before any of this plugin's error handling runs. The agent reads
 * that as a privilege problem and gives up, when the truthful answer is that no user can reach the
 * key through that tool and aafm-update-media's `alt` parameter does the job.
 *
 * The security boundary is the whole design, so it is asserted rather than described. There are two
 * reasons a meta call is refused, and only one of them may be explained:
 *
 *   (a) the key is structurally unreachable - hard-blocked for an administrator exactly as for a
 *       subscriber, whatever the site holds. Explaining it discloses nothing, because the answer
 *       does not depend on who asked.
 *   (b) the caller lacks a capability, or the operator has not allowlisted the key. Those DO depend
 *       on the caller and on site configuration, and must stay exactly as opaque as they were.
 *
 * (b) is pinned as byte equality against the literal the adapter sends today, across roles, rather
 * than as a claim. Assertions are on the wire body the client actually receives - the adapter's
 * CallToolResult - not on the PHP return value, because the PHP value is not what leaked.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- every meta_key here is an
// ability call argument or a schema property name, never a WP_Query meta clause.

namespace AAFM\Tests\Abilities;

use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\TestCase;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Utils\ContentBlockHelper;
use WP\McpSchema\Server\Tools\DTO\CallToolResult;

final class ReservedMetaKeyRouteTest extends TestCase {

	use IntegrationStubs;

	/**
	 * The exact string the adapter substitutes when a permission check returns a non-WP_Error
	 * denial. Hardcoded at ToolsHandler.php:150 and untranslated in this suite's locale, so it is
	 * the byte-for-byte baseline that case (b) must keep.
	 */
	private const BARE_REFUSAL = 'Permission denied';

	/**
	 * The alt key an agent reaches for, which is the whole reason this file exists.
	 */
	private const ALT_KEY = '_wp_attachment_image_alt';

	public function set_up(): void {
		parent::set_up();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * Reproduce the adapter's permission branch and return the wire body a client would receive.
	 *
	 * Mirrors ToolsHandler::handle_tools_call() lines 149-166 plus create_error_result(): the
	 * permission verdict goes through McpTool so AbilityArgumentNormalizer runs exactly as it does
	 * in production, a WP_Error's own message is echoed, and anything else becomes the hardcoded
	 * literal. The result is the real CallToolResult DTO, so what these tests compare is the
	 * serialized body and not a PHP return value.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $args    Call arguments.
	 * @return array<string,mixed> The CallToolResult as it serializes onto the wire.
	 */
	private function wire_body( string $ability, array $args ): array {
		$tool       = McpTool::fromAbility( wp_get_ability( $ability ) );
		$permission = $tool->check_permission( $args );

		$this->assertNotTrue( $permission, $ability . ' was expected to refuse this call.' );

		$message = self::BARE_REFUSAL;
		if ( is_wp_error( $permission ) ) {
			$message = $permission->get_error_message();
		}

		return CallToolResult::fromArray(
			array(
				'content'           => array( ContentBlockHelper::text( $message ) ),
				'structuredContent' => null,
				'isError'           => true,
			)
		)->toArray();
	}

	/**
	 * Pull the single text block out of a wire body.
	 *
	 * @param array<string,mixed> $body Wire body from wire_body().
	 * @return string
	 */
	private function wire_text( array $body ): string {
		$this->assertTrue( (bool) $body['isError'], 'The body must be flagged as an error.' );
		$block = $body['content'][0];
		$block = is_array( $block ) ? $block : $block->toArray();

		return (string) $block['text'];
	}

	/**
	 * Enable and register the three keyed post-meta abilities plus the media abilities they name.
	 *
	 * @return void
	 */
	private function register_meta_abilities(): void {
		$this->register_enabled(
			array(
				'aafm/get-post-meta',
				'aafm/update-post-meta',
				'aafm/delete-post-meta',
				'aafm/get-media-item',
				'aafm/update-media',
				'aafm/set-featured-image',
			)
		);
	}

	/**
	 * The reported defect, on the wire, for the operation the user actually tried.
	 *
	 * An administrator - so the refusal cannot be a capability - asks to write the alt key and is
	 * told what is true: no user reaches it here, and aafm-update-media does the job.
	 */
	public function test_writing_the_alt_key_names_the_media_ability_instead_of_denying_permission(): void {
		$this->acting_as( 'administrator' );
		$this->register_meta_abilities();
		$post_id = self::factory()->post->create();

		$text = $this->wire_text(
			$this->wire_body(
				'aafm/update-post-meta',
				array(
					'post_id'  => $post_id,
					'meta_key' => self::ALT_KEY,
					'value'    => 'A cat on a windowsill',
				)
			)
		);

		$this->assertNotSame( self::BARE_REFUSAL, $text, 'This is the bare refusal the report was about.' );
		$this->assertStringContainsString( self::ALT_KEY, $text, 'The refusal names the key that cannot be reached.' );
		$this->assertStringContainsString( 'no user can reach it', $text, 'And says the reason is structural, not a privilege.' );
		$this->assertStringContainsString( 'aafm-update-media', $text, 'And names the ability that writes alt text.' );
		$this->assertStringContainsString( '"alt"', $text, 'And the parameter on it, so the retry is one hop not two.' );
	}

	/**
	 * Read and delete get their own routes, not the write one.
	 *
	 * A single message reused across all three operations would send a reader to a write tool, so
	 * the per-operation split is the deliverable rather than an incidental detail.
	 */
	public function test_each_operation_on_the_alt_key_names_the_route_for_that_operation(): void {
		$this->acting_as( 'administrator' );
		$this->register_meta_abilities();
		$post_id = self::factory()->post->create();

		$read = $this->wire_text(
			$this->wire_body(
				'aafm/get-post-meta',
				array(
					'post_id'  => $post_id,
					'meta_key' => self::ALT_KEY,
				)
			)
		);
		$this->assertStringContainsString( 'aafm-get-media-item', $read, 'A read is sent to the read ability.' );
		$this->assertStringNotContainsString( 'aafm-update-media', $read, 'And not to the write one.' );

		$delete = $this->wire_text(
			$this->wire_body(
				'aafm/delete-post-meta',
				array(
					'post_id'  => $post_id,
					'meta_key' => self::ALT_KEY,
				)
			)
		);
		$this->assertStringContainsString( 'aafm-update-media', $delete, 'A delete is sent to the ability that can clear it.' );
		$this->assertStringContainsString( 'empty', $delete, 'And told how to clear it, since there is no delete-alt ability.' );
	}

	/**
	 * `_thumbnail_id` is handled by the same map, and its delete invents no route.
	 *
	 * Nothing in this plugin calls delete_post_thumbnail(), so there is no ability that removes a
	 * featured image. The refusal has to stop after saying the key is unreachable rather than name
	 * a tool that would not do it - the same rule the tool descriptions follow.
	 */
	public function test_the_thumbnail_key_is_routed_too_and_its_delete_names_no_route(): void {
		$this->acting_as( 'administrator' );
		$this->register_meta_abilities();
		$post_id = self::factory()->post->create();

		$write = $this->wire_text(
			$this->wire_body(
				'aafm/update-post-meta',
				array(
					'post_id'  => $post_id,
					'meta_key' => '_thumbnail_id',
					'value'    => 12,
				)
			)
		);
		$this->assertStringContainsString( '_thumbnail_id', $write );
		$this->assertStringContainsString( 'aafm-set-featured-image', $write, 'A featured-image write has a real route.' );

		$delete = $this->wire_text(
			$this->wire_body(
				'aafm/delete-post-meta',
				array(
					'post_id'  => $post_id,
					'meta_key' => '_thumbnail_id',
				)
			)
		);
		$this->assertStringContainsString( '_thumbnail_id', $delete, 'The delete still explains why it is unreachable.' );
		$this->assertStringNotContainsString(
			'aafm-',
			$delete,
			'But names no ability, because none removes a featured image and a route that does not exist must not be claimed.'
		);
	}

	/**
	 * B-price-and-metadesc-routing: _regular_price/_price (WooCommerce) and _yoast_wpseo_metadesc
	 * (Yoast) are protected meta with real, already-shipped alternative tools, exactly like
	 * _wp_attachment_image_alt. Refusing them with a bare "Permission denied" sent an agent
	 * asked to update a price or a meta description down the same dead end alt-text used to.
	 */
	public function test_the_price_and_metadesc_keys_are_routed_too(): void {
		$this->acting_as( 'administrator' );
		$this->register_meta_abilities();
		$post_id = self::factory()->post->create();

		$price_write = $this->wire_text(
			$this->wire_body(
				'aafm/update-post-meta',
				array(
					'post_id'  => $post_id,
					'meta_key' => '_regular_price',
					'value'    => '19.99',
				)
			)
		);
		$this->assertStringContainsString( '_regular_price', $price_write );
		$this->assertStringContainsString( 'aafm-wc-update-product', $price_write );
		$this->assertStringContainsString( '"regular_price"', $price_write );

		// The write assertions above are not enough on their own: a delete route with its own
		// wrong or dead call would pass them silently. WooProductsTest::
		// test_update_product_can_clear_regular_and_sale_price_with_an_empty_string() is the test
		// that actually executes this route's call against aafm-wc-update-product's schema; this
		// half only pins the wording an agent reads.
		$price_delete = $this->wire_text(
			$this->wire_body(
				'aafm/delete-post-meta',
				array(
					'post_id'  => $post_id,
					'meta_key' => '_regular_price',
				)
			)
		);
		$this->assertStringContainsString( '_regular_price', $price_delete );
		$this->assertStringContainsString( 'aafm-wc-update-product', $price_delete, 'The delete route names the real clearing call.' );
		$this->assertStringContainsString( '"regular_price"', $price_delete );

		$computed_write = $this->wire_text(
			$this->wire_body(
				'aafm/update-post-meta',
				array(
					'post_id'  => $post_id,
					'meta_key' => '_price',
					'value'    => '19.99',
				)
			)
		);
		$this->assertStringContainsString( '_price', $computed_write );
		$this->assertStringContainsString( 'aafm-wc-update-product', $computed_write );

		$metadesc_write = $this->wire_text(
			$this->wire_body(
				'aafm/update-post-meta',
				array(
					'post_id'  => $post_id,
					'meta_key' => '_yoast_wpseo_metadesc',
					'value'    => 'A description',
				)
			)
		);
		$this->assertStringContainsString( '_yoast_wpseo_metadesc', $metadesc_write );
		$this->assertStringContainsString( 'aafm-yoast-update-post', $metadesc_write );
		$this->assertStringContainsString( '"description"', $metadesc_write );
	}

	/**
	 * B-thumbnail-in-description: the schema description an agent reads BEFORE ever calling the
	 * tool only mentioned the alt-text route, not the thumbnail one, even though the runtime
	 * routing table (aafm_reserved_post_meta_routes()) already has a working _thumbnail_id
	 * route. An agent discovers the second route only by trial and error otherwise.
	 */
	public function test_the_meta_key_descriptions_also_mention_the_thumbnail_route(): void {
		$this->register_meta_abilities();

		foreach (
			array(
				'aafm/get-post-meta'    => 'aafm-get-post or aafm-get-page',
				'aafm/update-post-meta' => 'aafm-set-featured-image',
				'aafm/delete-post-meta' => 'aafm-set-featured-image',
			) as $ability => $expected_mention
		) {
			$schema      = wp_get_ability( $ability )->get_input_schema();
			$description = (string) $schema['properties']['meta_key']['description'];

			$this->assertStringContainsString( '_thumbnail_id', $description, $ability . ' must mention the thumbnail key too.' );
			$this->assertStringContainsString( $expected_mention, $description, $ability . ' must name the real thumbnail route.' );
		}
	}

	/**
	 * Every message a client can receive from this path uses the DASH tool name.
	 *
	 * The transform is aafm_mcp_tool_name(), str_replace( '/', '-', ... ) at includes/server.php:24, so the slash
	 * form is an internal ability name a client has never seen. Emitting it would send an agent
	 * looking for a tool that is not in its list.
	 */
	public function test_no_message_emits_the_internal_slash_form_of_a_tool_name(): void {
		$this->acting_as( 'administrator' );
		$this->register_meta_abilities();
		$post_id = self::factory()->post->create();

		$seen = 0;
		foreach ( array( 'aafm/get-post-meta', 'aafm/update-post-meta', 'aafm/delete-post-meta' ) as $ability ) {
			foreach ( array_keys( aafm_reserved_post_meta_routes() ) as $key ) {
				$args = array(
					'post_id'  => $post_id,
					'meta_key' => $key,
				);
				if ( 'aafm/update-post-meta' === $ability ) {
					$args['value'] = '1';
				}
				++$seen;
				$this->assertStringNotContainsString(
					'aafm/',
					$this->wire_text( $this->wire_body( $ability, $args ) ),
					$ability . ' emitted the slash form for ' . $key . ', which no client can call.'
				);
			}
		}

		$this->assertSame( 15, $seen, 'Three abilities times five mapped keys; a shrunk map must not pass this quietly.' );
	}

	/**
	 * Case (b), direction one: a caller who lacks the capability keeps the bare refusal, byte for
	 * byte, and it reads identically whoever asks.
	 *
	 * This is the row that says the fix did not turn a UX improvement into an oracle. The key here
	 * is an ordinary allowlisted one, so the ONLY thing separating the administrator from the
	 * subscriber is the capability - exactly the thing the message must not describe.
	 */
	public function test_a_capability_refusal_is_unchanged_and_identical_across_roles(): void {
		$this->register_meta_abilities();
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );

		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create( array( 'post_author' => $author_id ) );

		$bodies = array();
		foreach ( array( 'subscriber', 'contributor' ) as $role ) {
			$this->acting_as( $role );
			$bodies[ $role ] = $this->wire_body(
				'aafm/update-post-meta',
				array(
					'post_id'  => $post_id,
					'meta_key' => 'subtitle',
					'value'    => 'nope',
				)
			);
			$this->assertSame(
				self::BARE_REFUSAL,
				$this->wire_text( $bodies[ $role ] ),
				'A capability refusal must still be the adapter literal, unchanged by this fix.'
			);
		}

		$this->assertSame(
			wp_json_encode( $bodies['subscriber'] ),
			wp_json_encode( $bodies['contributor'] ),
			'And byte-identical between two callers who differ only in what they can do.'
		);

		$this->acting_as( 'administrator' );
		$this->assertTrue(
			wp_get_ability( 'aafm/update-post-meta' )->check_permissions(
				array(
					'post_id'  => $post_id,
					'meta_key' => 'subtitle',
					'value'    => 'yes',
				)
			),
			'And an administrator on the same call is still allowed, so this is a gate and not a wall.'
		);
	}

	/**
	 * Case (b), direction two: a key the operator simply has not allowlisted keeps the bare refusal
	 * too, and reads identically to the capability refusal above.
	 *
	 * Which keys an operator exposed is site configuration. If a not-allowlisted key answered
	 * differently from a capability failure, the difference itself would enumerate the allowlist.
	 */
	public function test_a_not_allowlisted_key_is_indistinguishable_from_a_capability_refusal(): void {
		$this->register_meta_abilities();
		update_option( 'aafm_allowed_meta_keys', array() );
		$post_id = self::factory()->post->create();

		$texts = array();
		foreach ( array( 'administrator', 'subscriber' ) as $role ) {
			$this->acting_as( $role );
			$texts[ $role ] = $this->wire_text(
				$this->wire_body(
					'aafm/update-post-meta',
					array(
						'post_id'  => $post_id,
						'meta_key' => 'not_on_the_list',
						'value'    => 'x',
					)
				)
			);
		}

		$this->assertSame(
			array(
				'administrator' => self::BARE_REFUSAL,
				'subscriber'    => self::BARE_REFUSAL,
			),
			$texts,
			'Allowlist state must not be readable out of the refusal, by anyone.'
		);
	}

	/**
	 * Case (b), direction three: the fix did NOT widen into a general hard-block oracle.
	 *
	 * The map is two literals that stock WordPress protects on every install. Every other
	 * hard-blocked key - including ones a site or a filter added - keeps the bare refusal, so the
	 * message cannot be used to probe which keys this particular site blocks.
	 */
	public function test_a_hard_blocked_key_outside_the_map_still_gets_the_bare_refusal(): void {
		$this->acting_as( 'administrator' );
		$this->register_meta_abilities();
		update_option( 'aafm_allowed_meta_keys', array( '*' ) );
		$post_id = self::factory()->post->create();

		foreach ( array( '_edit_lock', 'wp_capabilities', '_wp_page_template' ) as $key ) {
			$this->assertTrue( aafm_hard_blocked_meta_key( $key ), $key . ' is hard-blocked, which is the premise.' );
			$this->assertSame(
				self::BARE_REFUSAL,
				$this->wire_text(
					$this->wire_body(
						'aafm/get-post-meta',
						array(
							'post_id'  => $post_id,
							'meta_key' => $key,
						)
					)
				),
				$key . ' must keep the opaque refusal: only the two published core keys are explained.'
			);
		}
	}

	/**
	 * Both at once: unauthorised AND reserved.
	 *
	 * The refusal arrives before the capability check, so the answer is the structural one. That is
	 * the correct choice and it is the safe one: the message is built from the tool name and a
	 * literal from the map, so a subscriber receives the same bytes an administrator does and learns
	 * nothing about its own privileges, about the post, or about the site. The alternative - showing
	 * the specific message only to callers who would otherwise have been allowed - would turn it
	 * into a capability oracle, which is strictly worse than the bare refusal it replaced.
	 */
	public function test_an_unauthorised_caller_on_a_reserved_key_gets_the_same_bytes_and_no_more(): void {
		$this->register_meta_abilities();
		$post_id = self::factory()->post->create();
		$args    = array(
			'post_id'  => $post_id,
			'meta_key' => self::ALT_KEY,
			'value'    => 'leaked?',
		);

		$bodies = array();
		foreach ( array( 'administrator', 'subscriber' ) as $role ) {
			$this->acting_as( $role );
			$bodies[ $role ] = $this->wire_body( 'aafm/update-post-meta', $args );
		}

		$this->assertSame(
			wp_json_encode( $bodies['administrator'] ),
			wp_json_encode( $bodies['subscriber'] ),
			'The structural refusal must be byte-identical for a caller who could never have been allowed.'
		);

		$text = $this->wire_text( $bodies['subscriber'] );
		$this->assertStringNotContainsString( (string) $post_id, $text, 'No object id reaches the message.' );
		$this->assertStringNotContainsString( 'leaked?', $text, 'And neither does the caller\'s own value.' );
	}

	/**
	 * Fail-closed: the refusal is a refusal, and the write did not happen.
	 *
	 * Asserted on the stored meta rather than on the response shape, because "looked like an error"
	 * is not the claim worth pinning. Run as an administrator so nothing else could have stopped it.
	 */
	public function test_the_reserved_key_refusal_never_becomes_a_write(): void {
		// Core's execute() refuses to relay a permission WP_Error to the caller and _doing_it_wrong()s
		// over one before returning its own generic refusal. That is core policy, not a defect here,
		// and it is the reason this message reaches an MCP client but never a REST one: the adapter
		// stops at the permission verdict and never calls execute(). Expected rather than worked
		// around, so the property is asserted instead of being a surprise in a later run.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->acting_as( 'administrator' );
		$this->register_meta_abilities();
		update_option( 'aafm_allowed_meta_keys', array( '*' ) );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, self::ALT_KEY, 'original alt' );

		$ability = wp_get_ability( 'aafm/update-post-meta' );
		$args    = array(
			'post_id'  => $post_id,
			'meta_key' => self::ALT_KEY,
			'value'    => 'overwritten alt',
		);

		$this->assertNotTrue( $ability->check_permissions( $args ), 'Refused.' );
		$this->assertInstanceOf( \WP_Error::class, $ability->execute( $args ), 'And refused on execute.' );
		$this->assertSame(
			'original alt',
			get_post_meta( $post_id, self::ALT_KEY, true ),
			'The stored value is untouched, which is what fail-closed has to mean here.'
		);

		$delete = wp_get_ability( 'aafm/delete-post-meta' );
		$args   = array(
			'post_id'  => $post_id,
			'meta_key' => self::ALT_KEY,
		);
		$this->assertNotTrue( $delete->check_permissions( $args ), 'The delete is refused too.' );
		$this->assertInstanceOf( \WP_Error::class, $delete->execute( $args ) );
		$this->assertSame(
			'original alt',
			get_post_meta( $post_id, self::ALT_KEY, true ),
			'And nothing was removed.'
		);
	}

	/**
	 * The re-check against the live hard-block, rather than trusting the static map.
	 *
	 * WordPress exposes is_protected_meta() as a filter, so a site can genuinely unprotect the alt key and allowlist
	 * it, and there the write works. On that site the structural message would be a lie and refusing
	 * would be a regression, so the helper must fall silent and leave the call on its normal path.
	 */
	public function test_a_site_that_unprotects_the_key_is_left_on_its_normal_path(): void {
		$this->acting_as( 'administrator' );
		$this->register_meta_abilities();
		update_option( 'aafm_allowed_meta_keys', array( self::ALT_KEY ) );

		$unprotect = static function ( $is_protected, $key ) {
			return self::ALT_KEY === $key ? false : $is_protected;
		};
		add_filter( 'is_protected_meta', $unprotect, 10, 2 );

		$this->assertFalse(
			aafm_hard_blocked_meta_key( self::ALT_KEY ),
			'Premise: on this site the key is not hard-blocked.'
		);
		$this->assertNull(
			aafm_unreachable_meta_key_error(
				'aafm/update-post-meta',
				array( 'meta_key' => self::ALT_KEY )
			),
			'So the helper must say nothing rather than refuse a call this site allows.'
		);

		$post_id = self::factory()->post->create();
		$this->assertTrue(
			wp_get_ability( 'aafm/update-post-meta' )->check_permissions(
				array(
					'post_id'  => $post_id,
					'meta_key' => self::ALT_KEY,
					'value'    => 'allowed here',
				)
			),
			'And the call still succeeds, so the fix cost that site nothing.'
		);

		remove_filter( 'is_protected_meta', $unprotect, 10 );
	}

	/**
	 * The helper is scoped to the three keyed post-meta abilities and nothing else.
	 *
	 * It runs inside the registration wrapper, which wraps every ability including bridged foreign
	 * ones. An ability that merely happens to carry a `meta_key` argument must be untouched.
	 */
	public function test_the_helper_ignores_abilities_outside_the_three_it_owns(): void {
		$args = array( 'meta_key' => self::ALT_KEY );

		foreach ( array( 'aafm/get-term-meta', 'aafm/update-term-meta', 'aafm/get-all-post-meta', 'aafm-bridge/whatever' ) as $name ) {
			$this->assertNull(
				aafm_unreachable_meta_key_error( $name, $args ),
				$name . ' is not one of the three post-meta abilities and must be left alone.'
			);
		}

		$this->assertInstanceOf(
			\WP_Error::class,
			aafm_unreachable_meta_key_error( 'aafm/get-post-meta', $args ),
			'While the ability that IS owned still answers, so the scoping test is a discriminator.'
		);
	}

	/**
	 * A non-scalar or absent meta_key cannot fatal the helper, and is not answered by it.
	 *
	 * This reads raw caller input at the permission fire, before any schema validation has run, so
	 * an array arriving where a string was declared has to be survivable.
	 */
	public function test_a_malformed_meta_key_argument_is_survived_and_left_alone(): void {
		foreach ( array( array(), array( 'meta_key' => array( 'x' ) ), array( 'meta_key' => null ), array( 'meta_key' => '   ' ) ) as $args ) {
			$this->assertNull(
				aafm_unreachable_meta_key_error( 'aafm/update-post-meta', $args ),
				'A malformed meta_key is left to the existing schema and permission answers.'
			);
		}

		$this->assertInstanceOf(
			\WP_Error::class,
			aafm_unreachable_meta_key_error( 'aafm/update-post-meta', array( 'meta_key' => '  ' . self::ALT_KEY . ' ' ) ),
			'While a padded spelling is still recognised, matching the trim the hard-block itself does.'
		);
		$this->assertInstanceOf(
			\WP_Error::class,
			aafm_unreachable_meta_key_error( 'aafm/update-post-meta', array( 'meta_key' => '_WP_Attachment_Image_Alt' ) ),
			'And so is a mixed-case spelling, which is still protected meta.'
		);
	}

	/**
	 * Every route in the map names an ability this plugin actually registers.
	 *
	 * The standing rule on this codebase is that an error must never claim a route that is not
	 * wired. A human pass over the map cannot keep proving that as abilities get renamed, so it is
	 * a test: each dash-form name mentioned in any route sentence must resolve back to a registered
	 * ability.
	 */
	public function test_every_route_the_map_names_resolves_to_a_registered_ability(): void {
		// The map now names WooCommerce and Yoast tools too, and both are host-gated out of the
		// live registry unless their plugin is detected active; force detection on so this sweep
		// walks the same registry a real site with those plugins installed would have.
		$this->force_integration( 'woocommerce' );
		$this->force_integration( 'yoast' );

		$registry = array_keys( aafm_get_abilities_registry() );
		$dash     = array_map( 'aafm_mcp_tool_name', $registry );

		$mentioned = 0;
		foreach ( aafm_reserved_post_meta_routes() as $key => $routes ) {
			foreach ( $routes as $operation => $sentence ) {
				if ( '' === $sentence ) {
					continue;
				}
				preg_match_all( '/aafm-[a-z0-9-]+/', $sentence, $matches );
				$this->assertNotEmpty(
					$matches[0],
					$key . '/' . $operation . ' claims a route but names no ability.'
				);
				foreach ( $matches[0] as $tool ) {
					++$mentioned;
					$this->assertContains(
						$tool,
						$dash,
						$key . '/' . $operation . ' names ' . $tool . ', which this plugin does not register.'
					);
				}
			}
		}

		$this->assertGreaterThanOrEqual(
			5,
			$mentioned,
			'The sweep must have walked real routes; an emptied map must not pass this quietly.'
		);
	}

	/**
	 * The database compares meta keys ignoring case, accents and trailing spaces, so the user-meta
	 * hard block has to refuse an accented or mixed-case spelling of a blocked key too.
	 */
	public function test_the_user_meta_hard_block_refuses_accented_and_mixed_case_spellings(): void {
		global $wpdb;
		$per_blog = ucfirst( $wpdb->prefix ) . '2_Capabilitiés';

		foreach ( array( 'two_factor_sécret', 'wp_capabilitiés', 'séssion_tokens', $per_blog ) as $key ) {
			$this->assertTrue( aafm_hard_blocked_user_meta_key( $key ), $key . ' must be hard-blocked.' );
			$this->assertWPError( aafm_validate_user_meta_key( $key ), $key . ' must not validate.' );
		}
	}

	public function test_the_post_meta_hard_block_refuses_accented_page_builder_markers(): void {
		update_option( 'aafm_allowed_meta_keys', array( '*' ) );

		foreach ( array( 'ét_pb_use_builder', 'fusion_buildér_status' ) as $key ) {
			$this->assertTrue( aafm_hard_blocked_meta_key( $key ), $key . ' must be hard-blocked.' );
			$this->assertWPError( aafm_validate_meta_key( $key ), $key . ' must not validate under allow-star.' );
		}
	}

	public function test_the_deny_list_refuses_case_and_accent_variants_of_a_denied_key(): void {
		update_option( 'aafm_allowed_meta_keys', array( '*' ) );
		update_option( 'aafm_denied_meta_keys', array( 'secret', 'Café' ) );

		foreach ( array( 'secret', 'SECRET', 'sécret', 'cafe' ) as $key ) {
			$this->assertWPError( aafm_validate_meta_key( $key ), $key . ' must be denied.' );
		}
		$this->assertSame( 'secretary', aafm_validate_meta_key( 'secretary' ) );
	}

	public function test_the_allowlist_stays_byte_exact(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle' ) );

		$this->assertSame( 'subtitle', aafm_validate_meta_key( 'subtitle' ) );
		$this->assertWPError( aafm_validate_meta_key( 'Subtitle' ) );
		$this->assertWPError( aafm_validate_meta_key( 'subtitlé' ) );
	}

	/**
	 * Allow every key on the post, term and user scopes, so only the hard block and the deny list
	 * can refuse one.
	 */
	private function allow_star_everywhere(): void {
		update_option( 'aafm_allowed_meta_keys', array( '*' ) );
		update_option( 'aafm_exposed_term_meta_keys', array( '*' ) );
		update_option( 'aafm_exposed_user_meta_keys', array( '*' ) );
	}

	/**
	 * Assert a key is refused by both hard-block floors and by validate on both scopes.
	 *
	 * @param string $key     Requested key.
	 * @param string $message Failure context.
	 */
	private function assert_refused_on_both_floors( string $key, string $message ): void {
		$this->assertTrue( aafm_hard_blocked_meta_key( $key ), "post floor: $message" );
		$this->assertTrue( aafm_hard_blocked_user_meta_key( $key ), "user floor: $message" );
		$this->assertWPError( aafm_validate_meta_key( $key ), "post validate: $message" );
		$this->assertWPError( aafm_validate_user_meta_key( $key ), "user validate: $message" );
	}

	/**
	 * A filter-added entry spelled with a combining mark keeps its refusal for a request that
	 * differs from it only by the case of an ASCII letter, with or without intl.
	 */
	public function test_a_filter_added_entry_with_a_combining_mark_refuses_its_case_variant(): void {
		$this->allow_star_everywhere();
		$entry = static function ( array $extra ): array {
			$extra[] = "x\u{0307}secret";
			return $extra;
		};
		add_filter( 'aafm_hard_blocked_meta_keys', $entry );
		add_filter( 'aafm_hard_blocked_user_meta_keys', $entry );

		$this->assert_refused_on_both_floors( "X\u{0307}secret", 'case variant of a filter-added entry' );

		remove_filter( 'aafm_hard_blocked_meta_keys', $entry );
		remove_filter( 'aafm_hard_blocked_user_meta_keys', $entry );
	}

	/**
	 * The gate does not depend on the site locale: German and Danish accent maps turn these
	 * letters into two-letter spellings, and the database does not.
	 */
	public function test_the_gate_refuses_umlaut_and_ring_spellings_under_german_and_danish_locales(): void {
		$this->allow_star_everywhere();
		update_option( 'aafm_denied_meta_keys', array( 'secret' ) );

		foreach ( array( 'de_DE', 'da_DK' ) as $locale ) {
			$force = static function () use ( $locale ): string {
				return $locale;
			};
			add_filter( 'locale', $force );

			$this->assertSame( $locale, get_locale() );
			$this->assertTrue( aafm_hard_blocked_user_meta_key( 'session_tökens' ), "session_tökens under $locale" );
			$this->assertTrue( aafm_hard_blocked_user_meta_key( 'wp_capåbilities' ), "wp_capåbilities under $locale" );
			$this->assertWPError( aafm_validate_meta_key( 'sécret' ), "sécret with secret denied under $locale" );

			remove_filter( 'locale', $force );
		}
	}

	/**
	 * Code points the collation ignores, and a decomposed accent, still name the blocked key.
	 */
	public function test_the_gate_refuses_ignorable_code_points_and_a_decomposed_accent(): void {
		$this->allow_star_everywhere();

		foreach ( array( "wp_capa\u{0001}bilities", "wp_capa\u{200B}bilities", 'wp_capabilit' . "i\u{0301}" . 'es' ) as $key ) {
			$this->assert_refused_on_both_floors( $key, bin2hex( $key ) );
		}
	}

	/**
	 * A trailing no-break space: the ASCII reduction always brings it back to session_tokens, so
	 * the gate refuses it on every database, including one whose collation matches it to the real
	 * row.
	 */
	public function test_a_trailing_no_break_space_spelling_of_session_tokens_is_always_refused(): void {
		global $wpdb;
		$this->allow_star_everywhere();
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'session_tokens', array( 'x' => 1 ) );

		$spelling = "session_tokens\u{00A0}";
		$refused  = aafm_hard_blocked_user_meta_key( $spelling );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d AND meta_key = %s', $wpdb->usermeta, $user_id, $spelling ) );

		if ( $count > 0 ) {
			$this->assertTrue( $refused, 'the database matches the real row, so the gate must refuse' );
		}
		$this->assertTrue( $refused, 'the gate refuses this spelling whatever the database says' );
		$this->assertWPError( aafm_validate_user_meta_key( $spelling ) );
	}

	/**
	 * The list branch: a request the database equates with a filter-added entry, spelled with a
	 * character the ASCII reduction drops instead of mapping, is refused with no row stored.
	 */
	public function test_the_list_branch_refuses_a_spelling_only_the_database_equates(): void {
		global $wpdb;
		$this->allow_star_everywhere();
		$entry = static function ( array $extra ): array {
			$extra[] = 'aafm_t_secret_zz';
			return $extra;
		};
		add_filter( 'aafm_hard_blocked_meta_keys', $entry );
		add_filter( 'aafm_hard_blocked_user_meta_keys', $entry );

		$key = "aafm_t_\u{1D42C}ecret_zz";
		$this->assertNotSame( 'aafm_t_secret_zz', preg_replace( '/[^A-Za-z0-9_-]/', '', remove_accents( $key, 'en_US' ) ), 'premise: the ASCII reduction does not bring the request back to the entry' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key = %s', $wpdb->usermeta, 'aafm_t_secret_zz' ) );
		$this->assertSame( 0, $stored, 'premise: no row is stored under the entry' );

		$this->assert_refused_on_both_floors( $key, 'list branch' );

		remove_filter( 'aafm_hard_blocked_meta_keys', $entry );
		remove_filter( 'aafm_hard_blocked_user_meta_keys', $entry );
	}

	/**
	 * The row branch: with the real per-blog capabilities row and a planted variant stored, a
	 * third spelling that neither the list nor the ASCII reduction catches is refused, because
	 * the stored spellings come back distinct by bytes.
	 */
	public function test_the_row_branch_refuses_a_spelling_that_reaches_a_stored_per_blog_capabilities_row(): void {
		global $wpdb;
		$this->allow_star_everywhere();
		$user_id = self::factory()->user->create();
		$real    = $wpdb->prefix . '2_capabilities';
		// The planted variant goes first, so a distinct read that collapsed spellings would return it.
		add_user_meta( $user_id, $wpdb->prefix . '2_capabilitiés', 'planted' );
		add_user_meta( $user_id, $real, array( 'subscriber' => true ) );

		$key = $wpdb->prefix . "2_capabilitie\u{1D42C}";
		$this->assertFalse( (bool) preg_match( '/^' . preg_quote( $wpdb->prefix, '/' ) . '\d*_?(capabilities|user_level)$/i', preg_replace( '/[^A-Za-z0-9_-]/', '', remove_accents( $key, 'en_US' ) ) ), 'premise: the ASCII reduction misses it' );

		$this->assert_refused_on_both_floors( $key, 'row branch' );
	}

	/**
	 * The gate query's own failure refuses a non-ASCII key on every scope, in both fault shapes,
	 * and leaves an ASCII key alone.
	 *
	 * @return iterable<string,array{0:string}>
	 */
	public function data_gate_fault_shapes(): iterable {
		yield 'no-flush' => array( 'no-flush' );
		yield 'real-error' => array( 'real-error' );
	}

	/**
	 * One fault shape.
	 *
	 * @dataProvider data_gate_fault_shapes
	 * @param string $shape Fault shape.
	 */
	public function test_a_failed_gate_query_refuses_a_non_ascii_key_on_every_scope( string $shape ): void {
		global $wpdb;
		$this->allow_star_everywhere();

		$run = static function (): array {
			return array(
				'post'  => aafm_validate_meta_key( 'plain_kéy' ),
				'term'  => aafm_validate_term_meta_key( 'plain_kéy' ),
				'user'  => aafm_validate_user_meta_key( 'plain_kéy' ),
				'ascii' => aafm_validate_meta_key( 'plain_key-1' ),
			);
		};

		\AAFM\Tests\Support\QueryFaultInjector::reset_fired_count();
		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		try {
			$result = 'no-flush' === $shape
				? \AAFM\Tests\Support\QueryFaultInjector::fail_query( 'aafm_gate_match', $run )
				: \AAFM\Tests\Support\QueryFaultInjector::break_query_with_real_error( 'aafm_gate_match', $run );
		} finally {
			ob_end_clean();
			$wpdb->suppress_errors( $suppressed );
		}

		$this->assertGreaterThan( 0, \AAFM\Tests\Support\QueryFaultInjector::fired_count() );
		$this->assertWPError( $result['post'] );
		$this->assertWPError( $result['term'] );
		$this->assertWPError( $result['user'] );
		$this->assertSame( 'plain_key-1', $result['ascii'] );
		$this->assertSame( 'plain_kéy', aafm_validate_meta_key( 'plain_kéy' ), 'with no fault the same key validates' );
	}

	public function test_the_gate_query_runs_only_for_a_non_ascii_key_and_once_per_check(): void {
		global $wpdb;
		$count   = 0;
		$counter = static function ( string $query ) use ( &$count ): string {
			if ( false !== strpos( $query, 'aafm_gate_match' ) ) {
				++$count;
			}
			return $query;
		};
		add_filter( 'query', $counter );

		aafm_hard_blocked_meta_key( 'plain_key-1' );
		aafm_hard_blocked_user_meta_key( 'plain_key-1' );
		$builtins = array( 'session_tokens', '_application_passwords', 'wp_capabilities', 'wp_user_level', 'two_factor_secret', 'et_pb_use_builder', $wpdb->prefix . 'capabilities' );
		foreach ( $builtins as $key ) {
			aafm_hard_blocked_meta_key( $key );
			aafm_hard_blocked_user_meta_key( $key );
		}
		$ascii_queries = $count;

		aafm_hard_blocked_meta_key( 'plain_kéy' );
		$one_check = $count - $ascii_queries;

		remove_filter( 'query', $counter );

		$this->assertSame( 0, $ascii_queries, 'an ASCII key and every built-in cost no gate query' );
		$this->assertSame( 1, $one_check, 'one non-ASCII hard-block check costs exactly one gate query' );
	}

	/**
	 * The fast path's basis, checked against each meta table's own meta_key column: no character
	 * of [A-Za-z0-9_-] is ignorable, and two of them compare equal only when strtolower() makes
	 * them equal.
	 */
	public function test_the_ascii_fast_path_basis_holds_on_every_meta_table_column(): void {
		global $wpdb;
		$chars = array_merge( range( 'A', 'Z' ), range( 'a', 'z' ), range( '0', '9' ), array( '_', '-' ) );

		foreach ( array( $wpdb->postmeta, $wpdb->termmeta, $wpdb->usermeta ) as $table ) {
			// A union of the characters whose column takes the table's meta_key collation.
			$union = "SELECT CONCAT( IFNULL( m.meta_key, '' ), %s ) AS c FROM ( SELECT 1 AS one ) AS d LEFT JOIN ( SELECT meta_key FROM %i LIMIT 0 ) AS m ON 1 = 1" . str_repeat( ' UNION ALL SELECT %s', count( $chars ) - 1 );
			$args  = array_merge( array( $chars[0], $table ), array_slice( $chars, 1 ) );

			$sql = "SELECT a.c FROM ( {$union} ) AS a WHERE CONCAT( 'x', a.c, 'y' ) = 'xy'";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$ignorable = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
			$this->assertSame( array(), $ignorable, "no ignorable character on $table" );

			$sql = "SELECT a.c AS x, b.c AS y FROM ( {$union} ) AS a JOIN ( {$union} ) AS b ON a.c = b.c WHERE CAST( a.c AS BINARY ) <> CAST( b.c AS BINARY )";
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$pairs = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, $args ) ), ARRAY_A );
			$this->assertNotEmpty( $pairs, "premise: case folds on $table" );
			foreach ( $pairs as $pair ) {
				$this->assertSame( strtolower( $pair['x'] ), strtolower( $pair['y'] ), "only case variants compare equal on $table" );
			}
		}
	}

	/**
	 * Run $run and count the queries that carry the gate's marker.
	 *
	 * @param callable $run Code to measure.
	 * @return int
	 */
	private function gate_queries( callable $run ): int {
		$count   = 0;
		$counter = static function ( string $query ) use ( &$count ): string {
			if ( false !== strpos( $query, 'aafm_gate_match' ) ) {
				++$count;
			}
			return $query;
		};
		add_filter( 'query', $counter );
		try {
			$run();
		} finally {
			remove_filter( 'query', $counter );
		}
		return $count;
	}

	/**
	 * Floor 3 matches the key byte for byte against the allowlist and re-runs no hard block on its
	 * entries, so a non-ASCII entry elsewhere in the list costs an ASCII key nothing.
	 */
	public function test_validating_an_ascii_key_costs_no_gate_query_when_the_allowlist_holds_a_non_ascii_entry(): void {
		$allow = static function (): array {
			return array( 'plain_key-1', 'clé_publique' );
		};
		add_filter( 'aafm_allowed_meta_keys', $allow );
		update_option( 'aafm_denied_meta_keys', array( 'secret' ) );

		$result  = null;
		$queries = $this->gate_queries(
			static function () use ( &$result ): void {
				$result = aafm_validate_meta_key( 'plain_key-1' );
			}
		);

		remove_filter( 'aafm_allowed_meta_keys', $allow );

		$this->assertSame( 'plain_key-1', $result );
		$this->assertSame( 1, $queries );
	}

	/**
	 * The rich-post meta loop validates every allowlisted key. Its gate queries grow with the
	 * number of non-ASCII entries, not with its square.
	 */
	public function test_the_rich_post_meta_loop_costs_gate_queries_linear_in_the_allowlist(): void {
		$this->acting_as( 'administrator' );
		$post = get_post( self::factory()->post->create() );

		$counts = array();
		foreach ( array( 10, 20 ) as $n ) {
			$keys = array();
			for ( $i = 0; $i < $n; $i++ ) {
				$keys[] = 0 === $i % 2 ? "plain_key_$i" : "clé_$i";
			}
			update_option( 'aafm_allowed_meta_keys', $keys );
			$counts[ $n ] = $this->gate_queries(
				static function () use ( $post ): void {
					aafm_rich_post( $post, array( 'include_content' => false ) );
				}
			);
		}

		// Per non-ASCII entry: two in the allowlist getter the loop reads (before and after its
		// filter) and one when the loop validates that key.
		$this->assertSame(
			array(
				10 => 27,
				20 => 52,
			),
			$counts
		);
	}

	/**
	 * A hard-blocked allowlist entry is still refused (by floor 1) and still left out of the
	 * allowlist the admin screen shows and exports.
	 */
	public function test_a_hard_blocked_allowlist_entry_is_refused_and_left_out_of_the_allowlist(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle', 'wp_capabilities', 'wp_capabilitiés' ) );

		$this->assertWPError( aafm_validate_meta_key( 'wp_capabilities' ) );
		$this->assertWPError( aafm_validate_meta_key( 'wp_capabilitiés' ) );
		$this->assertSame( 'subtitle', aafm_validate_meta_key( 'subtitle' ) );
		$this->assertSame( array( 'subtitle' ), aafm_allowed_meta_keys() );
	}

	/**
	 * The allowlist getters the admin screen and the export read keep their output: the stored
	 * option floored before and after the post filter, and unioned with the filter for term and
	 * user meta, with blocked keys, empties, `*` and duplicates dropped.
	 */
	public function test_the_allowlist_getters_keep_their_output(): void {
		global $wpdb;
		update_option( 'aafm_allowed_meta_keys', array( 'subtitle', 'wp_capabilities', 'clé', '', '*', 'subtitle', 'ét_pb_use_builder' ) );
		update_option( 'aafm_exposed_term_meta_keys', array( 'color', 'wp_capabilitiés', '*' ) );
		update_option( 'aafm_exposed_user_meta_keys', array( 'nickname', 'séssion_tokens', $wpdb->prefix . 'capabilities' ) );

		$seen_default = null;
		$post_filter  = static function ( array $base ) use ( &$seen_default ): array {
			$seen_default = $base;
			return array_merge( $base, array( 'from_filter', 'session_tokens', '_edit_lock' ) );
		};
		$add_filter   = static function ( array $base ): array {
			return array_merge( $base, array( 'from_filter', 'two_factor_secret' ) );
		};
		add_filter( 'aafm_allowed_meta_keys', $post_filter );
		add_filter( 'aafm_allowed_term_meta_keys', $add_filter );
		add_filter( 'aafm_allowed_user_meta_keys', $add_filter );

		$post = aafm_allowed_meta_keys();
		$term = aafm_allowed_term_meta_keys();
		$user = aafm_allowed_user_meta_keys();

		remove_filter( 'aafm_allowed_meta_keys', $post_filter );
		remove_filter( 'aafm_allowed_term_meta_keys', $add_filter );
		remove_filter( 'aafm_allowed_user_meta_keys', $add_filter );

		$this->assertSame( array( 'subtitle', 'clé', '*', 'subtitle' ), $seen_default, 'the post filter gets the floored option as its default' );
		$this->assertSame( array( 'subtitle', 'clé', 'from_filter' ), $post );
		$this->assertSame( array( 'color', 'from_filter', 'two_factor_secret' ), $term, 'the term floor is the post-meta hard block, which does not list two_factor_secret' );
		$this->assertSame( array( 'nickname', 'from_filter' ), $user );
	}

	/**
	 * A post allowlist filter that returns the user allowlist gets that list floored, so a key the
	 * user floor blocks never reaches the post allowlist.
	 */
	public function test_a_nested_allowlist_getter_stays_floored_inside_another_scopes_filter(): void {
		update_option( 'aafm_exposed_user_meta_keys', array( 'two_factor_secret' ) );
		$nested = null;
		$filter = static function () use ( &$nested ): array {
			$nested = aafm_allowed_user_meta_keys();
			return $nested;
		};
		add_filter( 'aafm_allowed_meta_keys', $filter );

		$result = aafm_validate_meta_key( 'two_factor_secret' );

		remove_filter( 'aafm_allowed_meta_keys', $filter );

		$this->assertWPError( $result );
		$this->assertSame( array(), $nested );
	}

	/**
	 * An allowlist filter that protects the requested key while it runs: the floor that follows
	 * the filter sees the new state and refuses, on every scope.
	 *
	 * @return iterable<string,array{0:string,1:string,2:string,3:string}>
	 */
	public function data_scopes_with_an_arming_filter(): iterable {
		yield 'post' => array( 'aafm_allowed_meta_keys', 'aafm_allowed_meta_keys', 'aafm_validate_meta_key', 'post' );
		yield 'term' => array( 'aafm_exposed_term_meta_keys', 'aafm_allowed_term_meta_keys', 'aafm_validate_term_meta_key', 'term' );
		yield 'user' => array( 'aafm_exposed_user_meta_keys', 'aafm_allowed_user_meta_keys', 'aafm_validate_user_meta_key', 'user' );
	}

	/**
	 * One scope.
	 *
	 * @dataProvider data_scopes_with_an_arming_filter
	 * @param string $option   Allowlist option.
	 * @param string $tag      Allowlist filter tag.
	 * @param string $validate Validate function.
	 * @param string $scope    Scope label.
	 */
	public function test_a_key_an_allowlist_filter_protects_while_it_runs_is_refused( string $option, string $tag, string $validate, string $scope ): void {
		update_option( $option, array( 'private_key' ) );
		$armed   = false;
		$protect = static function ( $is_protected, $meta_key ) use ( &$armed ) {
			return ( $armed && 'private_key' === $meta_key ) ? true : $is_protected;
		};
		$arm     = static function ( array $keys ) use ( &$armed ): array {
			$armed = true;
			return $keys;
		};
		add_filter( 'is_protected_meta', $protect, 10, 2 );
		add_filter( $tag, $arm );

		$result = $validate( 'private_key' );

		remove_filter( $tag, $arm );
		remove_filter( 'is_protected_meta', $protect, 10 );

		$this->assertWPError( $result, "$scope: the key is protected once the filter has run" );
	}

	/**
	 * A post filter that branches on its default: validate answers exactly as membership in the
	 * list the admin screen shows.
	 */
	public function test_validate_agrees_with_the_admin_allowlist_for_a_filter_that_branches_on_its_default(): void {
		update_option( 'aafm_allowed_meta_keys', array( 'wp_capabilities' ) );
		$filters = array(
			'widens when the default is non-empty' => static function ( array $base ): array {
				return array() !== $base ? array_merge( $base, array( 'seo_title' ) ) : $base;
			},
			'fills only an empty default'          => static function ( array $base ): array {
				return array() === $base ? array( 'mirror_key' ) : $base;
			},
			'appends a key'                        => static function ( array $base ): array {
				return array_merge( $base, array( 'plain_extra' ) );
			},
		);

		foreach ( $filters as $label => $filter ) {
			add_filter( 'aafm_allowed_meta_keys', $filter );
			$listed = aafm_allowed_meta_keys();
			foreach ( array( 'seo_title', 'mirror_key', 'plain_extra', 'wp_capabilities' ) as $key ) {
				$this->assertSame( in_array( $key, $listed, true ), is_string( aafm_validate_meta_key( $key ) ), "$label: $key" );
			}
			if ( 'widens when the default is non-empty' === $label ) {
				$this->assertWPError( aafm_validate_meta_key( 'seo_title' ) );
			}
			remove_filter( 'aafm_allowed_meta_keys', $filter );
		}
	}

	/**
	 * The list form of each scope's hard block gives the same answer per key as the single-key
	 * hard block, and both match the literal expectation, for a batch that needs every check.
	 */
	public function test_the_list_form_hard_block_matches_the_single_key_answer_for_every_fixture(): void {
		global $wpdb;
		$user_id = self::factory()->user->create();
		add_user_meta( $user_id, $wpdb->prefix . '2_capabilitiés', 'planted' );
		add_user_meta( $user_id, $wpdb->prefix . '2_capabilities', array( 'subscriber' => true ) );
		$entry = static function ( array $extra ): array {
			$extra[] = 'aafm_t_secret_zz';
			$extra[] = "x\u{0307}secret";
			return $extra;
		};
		add_filter( 'aafm_hard_blocked_meta_keys', $entry );
		add_filter( 'aafm_hard_blocked_user_meta_keys', $entry );
		$force = static function (): string {
			return 'de_DE';
		};
		add_filter( 'locale', $force );

		$keys     = array(
			'plain_key-1',
			'session_tokens',
			'Wp_Capabilities',
			"X\u{0307}secret",
			'session_tökens',
			'wp_capåbilities',
			"wp_capa\u{0001}bilities",
			"wp_capa\u{200B}bilities",
			'wp_capabilit' . "i\u{0301}" . 'es',
			"session_tokens\u{00A0}",
			"aafm_t_\u{1D42C}ecret_zz",
			$wpdb->prefix . "2_capabilitie\u{1D42C}",
			'plain_kéy',
			'ét_pb_use_builder',
			'',
		);
		$expected = array(
			'post' => array( false, true, true, true, true, true, true, true, true, true, true, true, false, true, true ),
			'user' => array( false, true, true, true, true, true, true, true, true, true, true, true, false, false, true ),
		);

		$single = array(
			'post' => array_map( 'aafm_hard_blocked_meta_key', $keys ),
			'user' => array_map( 'aafm_hard_blocked_user_meta_key', $keys ),
		);
		$batch  = array(
			'post' => aafm_hard_blocked_meta_keys( $keys, 'post' ),
			'user' => aafm_hard_blocked_meta_keys( $keys, 'user' ),
		);

		remove_filter( 'locale', $force );
		remove_filter( 'aafm_hard_blocked_meta_keys', $entry );
		remove_filter( 'aafm_hard_blocked_user_meta_keys', $entry );

		$this->assertSame( $expected, $single, 'single-key answers' );
		$this->assertSame( $expected, $batch, 'list-form answers' );
	}

	/**
	 * Validating one key costs at most four gate queries whatever the allowlist length.
	 */
	public function test_validating_a_key_costs_at_most_four_gate_queries_whatever_the_allowlist_length(): void {
		$counts = array();
		foreach ( array( 10, 20 ) as $n ) {
			$keys = array();
			for ( $i = 0; $i < $n; $i++ ) {
				$keys[] = 0 === $i % 2 ? "plain_key_$i" : "clé_$i";
			}
			update_option( 'aafm_allowed_meta_keys', $keys );
			update_option( 'aafm_denied_meta_keys', array( 'secrét' ) );
			$counts[ $n ] = array(
				'ascii'     => $this->gate_queries(
					static function (): void {
						aafm_validate_meta_key( 'plain_key_0' );
					}
				),
				'non_ascii' => $this->gate_queries(
					static function (): void {
						aafm_validate_meta_key( 'clé_1' );
					}
				),
			);
		}

		$this->assertSame(
			array(
				10 => array(
					'ascii'     => 3,
					'non_ascii' => 4,
				),
				20 => array(
					'ascii'     => 3,
					'non_ascii' => 4,
				),
			),
			$counts
		);
	}

	/**
	 * When the batched query fails, every entry that needed it is dropped and its key refused; an
	 * all-ASCII list makes no query at all.
	 *
	 * @dataProvider data_gate_fault_shapes
	 * @param string $shape Fault shape.
	 */
	public function test_a_failed_batched_floor_query_drops_the_entries_that_needed_it( string $shape ): void {
		global $wpdb;
		update_option( 'aafm_allowed_meta_keys', array( 'plain_key-1', 'clé_1', 'plain_key-2', 'clé_2' ) );

		$run = static function (): array {
			return array(
				'list'     => aafm_allowed_meta_keys(),
				'validate' => aafm_validate_meta_key( 'clé_1' ),
			);
		};

		\AAFM\Tests\Support\QueryFaultInjector::reset_fired_count();
		$suppressed = $wpdb->suppress_errors( true );
		ob_start();
		try {
			$result = 'no-flush' === $shape
				? \AAFM\Tests\Support\QueryFaultInjector::fail_query( 'aafm_gate_match', $run )
				: \AAFM\Tests\Support\QueryFaultInjector::break_query_with_real_error( 'aafm_gate_match', $run );
		} finally {
			ob_end_clean();
			$wpdb->suppress_errors( $suppressed );
		}

		$this->assertGreaterThan( 0, \AAFM\Tests\Support\QueryFaultInjector::fired_count() );
		$this->assertSame( array( 'plain_key-1', 'plain_key-2' ), $result['list'] );
		$this->assertWPError( $result['validate'] );

		update_option( 'aafm_allowed_meta_keys', array( 'plain_key-1', 'plain_key-2' ) );
		$this->assertSame(
			0,
			$this->gate_queries(
				static function (): void {
					aafm_allowed_meta_keys();
					aafm_validate_meta_key( 'plain_key-1' );
				}
			)
		);
	}
}
