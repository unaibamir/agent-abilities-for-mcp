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
		aafm_install_activity_log();
		aafm_clear_activity_log();
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
}
