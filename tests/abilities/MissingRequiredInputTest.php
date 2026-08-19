<?php
/**
 * A call that omits a required argument is answered as a schema failure, not a permission verdict.
 *
 * The defect this pins: the MCP adapter runs the tool's permission check BEFORE execution and turns
 * any non-true into the literal string "Permission denied". Core does the opposite and validates
 * input first, so the direct and REST paths were already right. Over MCP, an ability whose gate
 * resolves the object it is asked about answered a missing post_id with a capability decision,
 * because get_post( 0 ) is null. An agent reads that as "stop and fetch a human" when the truthful
 * answer is "fix the call and retry".
 *
 * The guard is security-adjacent, because the permission callback is this plugin's security
 * boundary by design, so both directions are pinned here: a missing argument must never become a
 * path to access, and a well-formed argument from an unauthorised caller must still be refused.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class MissingRequiredInputTest extends TestCase {

	/**
	 * The number of registered abilities that declare required input, at minimum.
	 *
	 * A floor rather than a roster, so adding an ability needs no edit here while REMOVING the
	 * derivation cannot pass quietly. A broken derivation walks 0, and the gap between 0 and this
	 * number is what the floor is for.
	 *
	 * The number this walks is deliberately NOT pinned exactly, because it is order-dependent: the
	 * abilities registry is process-wide, so a class run on its own sees only what it registered
	 * while the same class inside a full suite also sees what earlier classes left behind. Measured
	 * at 1.7.0: 62 in isolation, where the WooCommerce, ACF and SEO host stubs are absent, and 117
	 * inside a full run. The 117 matches an independent count taken against a live site with all
	 * three hosts active, which is the reassurance that the sweep really does reach the whole
	 * catalog rather than a convenient corner of it. If a deliberate change genuinely reduces the
	 * count below this floor, lower it in the same change and say why in the commit message.
	 */
	private const REQUIRED_INPUT_ABILITY_FLOOR = 60;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
	}

	/**
	 * The derivation, and the completeness guard over it.
	 *
	 * Walks every registered ability that declares required input and asserts none of them answers
	 * an empty call with a permission verdict. Acting as an administrator throughout, so a refusal
	 * can only be caused by the missing argument and never by a capability: without that, a row
	 * could be green for the wrong reason and the whole sweep would prove nothing.
	 *
	 * Three separate things stop this passing vacuously, because a completeness guard emptying its
	 * own derivation is exactly how this codebase shipped nine unlogged deletes. The $checked
	 * counter proves the loop ran over a real set; the floor proves the set did not silently shrink;
	 * and the per-ability message assertion proves the refusal names the property rather than being
	 * any old WP_Error.
	 */
	public function test_no_ability_answers_a_missing_required_argument_with_a_permission_verdict(): void {
		$this->acting_as( 'administrator' );
		$this->register_enabled( array_keys( aafm_get_abilities_registry() ) );

		$checked = 0;
		foreach ( wp_get_abilities() as $name => $ability ) {
			$name = (string) $name;
			if ( 0 !== strpos( $name, 'aafm/' ) ) {
				continue;
			}
			$required = aafm_required_input_properties( array( 'input_schema' => $ability->get_input_schema() ) );
			if ( array() === $required ) {
				continue;
			}

			++$checked;
			$verdict = $ability->check_permissions( array() );

			$this->assertInstanceOf(
				\WP_Error::class,
				$verdict,
				$name . ' answered an empty call with a bare verdict rather than a schema error.'
			);
			$this->assertSame(
				'ability_invalid_input',
				$verdict->get_error_code(),
				$name . ' refused for some other reason than the missing argument.'
			);
			$this->assertStringContainsString(
				$required[0],
				$verdict->get_error_message(),
				$name . ' did not name the property the caller has to supply, which is the whole point.'
			);
		}

		$this->assertGreaterThanOrEqual(
			self::REQUIRED_INPUT_ABILITY_FLOOR,
			$checked,
			sprintf(
				'Only %d abilities with required input were walked. Either the derivation broke or the '
				. 'catalog shrank; if the shrink is deliberate, lower REQUIRED_INPUT_ABILITY_FLOOR in the '
				. 'same change and say why.',
				$checked
			)
		);
	}

	/**
	 * Fail-closed, direction one: the refusal is a refusal, and execution never happens.
	 *
	 * Asserted on a real destructive ability rather than a throwaway, because the claim worth
	 * pinning is that this cannot become a path to access on the abilities that would matter.
	 */
	public function test_a_missing_argument_never_becomes_a_path_to_execution(): void {
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/delete-post' ) );
		$post_id = self::factory()->post->create();

		$ability = wp_get_ability( 'aafm/delete-post' );
		$verdict = $ability->check_permissions( array() );
		$this->assertInstanceOf( \WP_Error::class, $verdict, 'An empty call is refused.' );
		$this->assertNotTrue( $verdict, 'And it is not an allow, which is the only thing that would matter.' );

		$result = $ability->execute( array() );
		$this->assertInstanceOf( \WP_Error::class, $result, 'Executing it is refused too.' );
		$this->assertInstanceOf(
			\WP_Post::class,
			get_post( $post_id ),
			'And nothing was deleted, which is what fail-closed has to mean here.'
		);
	}

	/**
	 * Fail-closed, direction two: a well-formed argument from an unauthorised caller is still
	 * refused, and from an authorised one is still allowed.
	 *
	 * This is the direction a change to a permission path can quietly break, and the pair is what
	 * makes it a discriminator rather than "refuses everything" or "allows everything".
	 */
	public function test_a_well_formed_argument_still_meets_the_capability_gate(): void {
		$this->register_enabled( array( 'aafm/delete-post' ) );
		$post_id = self::factory()->post->create();
		$ability = wp_get_ability( 'aafm/delete-post' );

		$this->acting_as( 'subscriber' );
		$this->assertNotTrue(
			$ability->check_permissions( array( 'post_id' => $post_id ) ),
			'A subscriber sending a perfectly well-formed argument is still refused.'
		);

		$this->acting_as( 'administrator' );
		$this->assertTrue(
			$ability->check_permissions( array( 'post_id' => $post_id ) ),
			'And an administrator sending the same argument is still allowed, so this is a gate and not a wall.'
		);
	}

	/**
	 * Fail-closed, direction three: unauthorised AND malformed is still refused.
	 *
	 * The schema answer arrives before the capability check, so this is the row that says the
	 * reordering did not hand anything to a caller who could not have had it.
	 */
	public function test_an_unauthorised_caller_with_a_malformed_call_is_refused_too(): void {
		$this->register_enabled( array( 'aafm/delete-post' ) );
		$post_id = self::factory()->post->create();

		$this->acting_as( 'subscriber' );
		$ability = wp_get_ability( 'aafm/delete-post' );

		$this->assertNotTrue( $ability->check_permissions( array() ), 'Refused.' );
		$this->assertInstanceOf( \WP_Error::class, $ability->execute( array() ), 'And refused on execute.' );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post_id ), 'Nothing happened to the post.' );
	}

	/**
	 * Every missing property is named, not just the first one.
	 *
	 * An agent that has to rediscover one omission per round trip is only marginally better off
	 * than one told "permission denied", so the message carrying the whole set is the deliverable
	 * rather than an incidental detail.
	 */
	public function test_the_refusal_names_every_missing_property(): void {
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/update-post-meta' ) );

		$verdict = wp_get_ability( 'aafm/update-post-meta' )->check_permissions( array( 'post_id' => 1 ) );
		$this->assertInstanceOf( \WP_Error::class, $verdict );

		$message = $verdict->get_error_message();
		$this->assertStringContainsString( 'meta_key', $message, 'Names the first omission.' );
		$this->assertStringContainsString( 'value', $message, 'And the second, rather than stopping at one.' );
		$this->assertStringNotContainsString( 'post_id', $message, 'And does not name the property that WAS supplied.' );
	}

	/**
	 * The bound, stated as a row rather than only as a sentence: this closes ABSENT arguments, not
	 * every malformed one.
	 *
	 * A required property that is present but wrongly typed still reaches the permission callback
	 * and can still be answered as a capability decision. That is deliberate. Absence is the one
	 * schema failure both the adapter and core agree on without normalizing anything, so it is the
	 * one that is safe to answer at the permission fire; running the whole validator there could
	 * refuse a call core would have accepted. array_key_exists is also core's own test
	 * (rest-api.php), so "present but null" means present here exactly as it does there, and
	 * reporting it as missing would be a false message rather than a better one.
	 *
	 * The residual is a message-accuracy gap on a call that is refused either way. It is recorded
	 * here so nobody reads the fix as wider than it is.
	 */
	public function test_a_present_but_wrongly_typed_argument_is_not_reported_as_missing(): void {
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/delete-post' ) );

		$this->assertSame(
			array(),
			array_diff( array( 'post_id' ), array_keys( array( 'post_id' => null ) ) ),
			'array_key_exists semantics: a null value is a present key, which is core\'s rule too.'
		);

		$verdict = wp_get_ability( 'aafm/delete-post' )->check_permissions( array( 'post_id' => null ) );
		$this->assertNotInstanceOf(
			\WP_Error::class,
			$verdict,
			'A present-but-null argument is not reported as missing; it is refused downstream as before.'
		);
		$this->assertNotTrue( $verdict, 'And it is still refused, which is the part that matters.' );
	}

	/**
	 * The refusal discloses NOTHING, proved as an invariance rather than argued.
	 *
	 * This branch runs BEFORE the capability check, which is the one thing about the shape that
	 * could leak: if the message varied by who asked or by what exists, an unauthorised caller could
	 * read the difference and learn something. It cannot vary, because it is built from the ability
	 * name and its own declared schema and touches no input value and no object. That claim is worth
	 * nothing as a sentence, so it is pinned as byte equality across the whole matrix that could
	 * differ: caller with the capability against caller without, and an object that exists against
	 * an id that does not.
	 *
	 * Four messages, one string. A regression that starts consulting the caller or the object breaks
	 * this before it can become a leak.
	 */
	public function test_the_refusal_is_byte_identical_whoever_asks_and_whatever_exists(): void {
		$this->register_enabled( array( 'aafm/update-post-meta' ) );
		$real_post = self::factory()->post->create();
		$no_post   = 999999;

		$messages = array();
		foreach ( array( 'administrator', 'subscriber' ) as $role ) {
			$this->acting_as( $role );
			foreach ( array(
				'exists' => $real_post,
				'absent' => $no_post,
			) as $label => $post_id ) {
				// post_id supplied, meta_key and value omitted, so the refusal is reachable while
				// the call still names an object whose existence the message must not betray.
				$verdict = wp_get_ability( 'aafm/update-post-meta' )->check_permissions( array( 'post_id' => $post_id ) );
				$this->assertInstanceOf( \WP_Error::class, $verdict, $role . '/' . $label );
				$messages[ $role . '/' . $label ] = $verdict->get_error_message();
			}
		}

		$this->assertCount(
			1,
			array_unique( array_values( $messages ) ),
			'The refusal must read identically for every caller and every object state: ' . wp_json_encode( $messages )
		);

		$one = reset( $messages );
		$this->assertStringNotContainsString(
			(string) $real_post,
			$one,
			'No object id reaches the message, so nothing about what exists can be read out of it.'
		);
		$this->assertStringNotContainsString(
			(string) $no_post,
			$one,
			'And neither does the id that does not resolve.'
		);
	}

	/**
	 * The denial is still audited, exactly as every other refusal is.
	 *
	 * The refusal returns before the permission callback, so the risk worth pinning is that it also
	 * returns before the bookkeeping. It does not: it falls through to the shared non-true branch.
	 */
	public function test_a_schema_refusal_is_audited_like_any_other_denial(): void {
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/delete-post' ) );

		wp_get_ability( 'aafm/delete-post' )->check_permissions( array() );

		$denied = aafm_query_activity( array( 'status' => 'denied' ) );
		$this->assertContains(
			'aafm/delete-post',
			wp_list_pluck( $denied, 'ability' ),
			'A refusal nobody can see afterwards is the accountability gap the wrapper exists to close.'
		);
	}

	/**
	 * An ability declaring no required input is untouched.
	 *
	 * Without this row the guard could be "refuse every empty call" and every other row here would
	 * still pass.
	 */
	public function test_an_ability_with_no_required_input_still_answers_normally(): void {
		$this->acting_as( 'administrator' );
		$this->register_enabled( array( 'aafm/get-site-info' ) );

		$this->assertSame(
			array(),
			aafm_required_input_properties( array( 'input_schema' => wp_get_ability( 'aafm/get-site-info' )->get_input_schema() ) ),
			'The fixture really does declare nothing required; a row against an ability that did would prove the opposite.'
		);
		$this->assertTrue(
			wp_get_ability( 'aafm/get-site-info' )->check_permissions( array() ),
			'So an empty call to it is an ordinary allowed call.'
		);
	}

	/**
	 * The derivation reads only the draft-4 `required` array, and says so by refusing to read the
	 * older per-property spelling.
	 *
	 * Both directions, because "returns an empty array" is also what a broken derivation returns.
	 */
	public function test_the_derivation_reads_the_schema_form_this_plugin_actually_uses(): void {
		$this->assertSame(
			array( 'post_id', 'meta_key' ),
			aafm_required_input_properties(
				array(
					'input_schema' => array(
						'type'     => 'object',
						'required' => array( 'post_id', 'meta_key' ),
					),
				)
			),
			'The draft-4 form is read.'
		);

		$this->assertSame(
			array(),
			aafm_required_input_properties(
				array(
					'input_schema' => array(
						'type'       => 'object',
						'properties' => array( 'post_id' => array( 'required' => true ) ),
					),
				)
			),
			'The draft-3 per-property spelling is deliberately not read, so a foreign schema using it '
			. 'keeps today behaviour rather than a rule nobody measured against that vendor.'
		);

		$this->assertSame( array(), aafm_required_input_properties( array() ), 'No schema at all yields nothing.' );
		$this->assertSame(
			array( 'ok' ),
			aafm_required_input_properties(
				array(
					'input_schema' => array(
						'type'     => 'object',
						'required' => array( 'ok', '', 7, null ),
					),
				)
			),
			'Non-string and empty entries are dropped rather than becoming an unsatisfiable requirement.'
		);
	}
}
