<?php
/**
 * SEO vendor-contract tests: Yoast, AIOSEO, Rank Math.
 *
 * THE STANDING RULE FOR THIS SUITE: a stub may only model behaviour that a contract test here has
 * confirmed against the REAL vendor. When a stub and a contract test disagree, the stub is wrong.
 *
 * Run: vendor/bin/phpunit -c phpunit-contract.xml.dist (after tests/bin/install-vendors.sh).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Contract;

use AAFM\Tests\TestCase;

/**
 * Asserts the real Yoast / AIOSEO / Rank Math contracts the SEO abilities rely on.
 *
 * @group contract
 */
final class SeoContractTest extends TestCase {

	/**
	 * H1: Yoast's `meta-robots-noindex` is a tri-state enum, not a boolean, and 1 means NO-INDEX
	 * (2 = index, 0 = post-type default). Our schema once inverted this (1=index), so an agent
	 * indexed what it was told to hide. This pins the vendor's keyspace so a re-inversion fails.
	 */
	public function test_yoast_noindex_is_tristate_enum_with_1_meaning_noindex(): void {
		if ( ! class_exists( 'WPSEO_Meta' ) ) {
			$this->markTestSkipped( 'Yoast SEO not provisioned — run tests/bin/install-vendors.sh.' );
		}
		$fields = \WPSEO_Meta::$meta_fields;
		$this->assertArrayHasKey( 'advanced', $fields );
		$this->assertArrayHasKey( 'meta-robots-noindex', $fields['advanced'] );

		// PHP casts numeric string array keys to ints, so the keyspace surfaces as {0,1,2}.
		$options = array_map( 'intval', array_keys( $fields['advanced']['meta-robots-noindex']['options'] ) );
		sort( $options );
		$this->assertSame(
			array( 0, 1, 2 ),
			$options,
			'Yoast noindex is the tri-state {0=default,1=noindex,2=index}; our enum must map into this keyspace, with 1 = noindex.'
		);
	}

	/**
	 * L11: AIOSEO `Model::save()` returns void (its body ends on `$this->reset()` with no return).
	 * Production guards `false === $model->save()`, which can never be true — dead code that lets a
	 * genuinely failed write report success. The contract: save() declares no bool return.
	 */
	public function test_aioseo_model_save_returns_void_not_bool(): void {
		$model = 'AIOSEO\\Plugin\\Common\\Models\\Model';
		if ( ! class_exists( $model ) ) {
			$this->markTestSkipped( 'AIOSEO not provisioned — run tests/bin/install-vendors.sh.' );
		}
		$this->assertTrue( method_exists( $model, 'save' ), 'Model::save() exists.' );

		$method = new \ReflectionMethod( $model, 'save' );
		$this->assertFalse(
			$method->hasReturnType(),
			'Model::save() declares no return type and returns null (void) — `false === save()` is dead code (L11).'
		);
	}

	/**
	 * Rank Math's post/schema abilities are a meta-key integration (no Rank Math code symbols
	 * called); the head-rendering ability is not (M1). Pin the detection contract M6-style: the
	 * `RankMath` marker class and version constant exist.
	 */
	public function test_rankmath_detection_contract(): void {
		if ( ! class_exists( 'RankMath' ) ) {
			$this->markTestSkipped( 'Rank Math not provisioned — run tests/bin/install-vendors.sh.' );
		}
		$this->assertTrue( class_exists( 'RankMath' ), 'The RankMath marker class exists.' );
		$this->assertTrue(
			defined( 'RANK_MATH_VERSION' ),
			'RANK_MATH_VERSION is defined — the meta-key contract is version-fragile, so the floor is pinned to it.'
		);
	}

	/**
	 * M1 (part 1 of 2 - must run before the companion test below mutates registration state): on a
	 * fresh/unregistered install, rank_math()->head is genuinely absent. Pin that our renderer's
	 * isset() guard condition is reachable against the real vendor, not just a stub assumption.
	 * Declared before _once_registration_is_resolved so PHPUnit's declaration-order default (this
	 * suite sets no executionOrder) runs this first; the companion test self-skips if that ever changes.
	 */
	public function test_rankmath_head_is_absent_before_registration_is_resolved(): void {
		if ( ! function_exists( 'rank_math' ) ) {
			$this->markTestSkipped( 'Rank Math not provisioned — run tests/bin/install-vendors.sh.' );
		}
		$plugin = rank_math();
		if ( true !== $plugin->registration->invalid ) {
			$this->markTestSkipped( 'This test core has already resolved Rank Math registration.' );
		}
		$this->assertFalse(
			isset( $plugin->head ),
			'rank_math()->head must be unset while registration is unresolved - our renderer must guard on this, not assume it.'
		);
	}

	/**
	 * M1 (part 2 of 2): rankmath-get-head used to register no production callback on the
	 * aafm_seo_rendered_head seam at all, so it always returned head:'' with success.
	 * aafm_rankmath_rendered_head() now calls rank_math()->head->head() - pin the two-stage real
	 * shape that call depends on.
	 *
	 * Stage 1: rank_math()->frontend only exists once RankMath::init_frontend() sees a valid/skipped
	 * registration (`if ( $this->container['registration']->invalid ) return;`). A fresh install
	 * (this contract DB, and any real site that has not completed or skipped the Rank Math setup
	 * wizard) starts invalid - the companion test above pins that state. This flips the same public
	 * $invalid flag the real setup wizard flips on "Skip" and re-runs the real init_frontend().
	 *
	 * Stage 2: even once ->frontend exists, rank_math()->head is STILL absent - Rank Math only builds
	 * it inside Frontend::integrations(), which Rank Math itself hooks to the 'wp' action. That
	 * action never fires while dispatching a REST request (core's REST bootstrap short-circuits
	 * parse_request() and exits before 'wp' runs), which is how every ability call reaches our
	 * renderer - so the renderer must call frontend->integrations() itself. This pins that ->head is
	 * genuinely absent immediately after init_frontend() (proving the self-heal call is necessary,
	 * not redundant), then calls integrations() and confirms ->head appears with a public head()
	 * method. Mutates the shared rank_math() singleton, so it must run after the companion test above
	 * (see that test's docblock).
	 */
	public function test_rankmath_head_renderer_shape_once_registration_is_resolved(): void {
		if ( ! function_exists( 'rank_math' ) ) {
			$this->markTestSkipped( 'Rank Math not provisioned — run tests/bin/install-vendors.sh.' );
		}
		$plugin = rank_math();
		$this->assertIsObject( $plugin, 'rank_math() must return an object.' );
		// registration/frontend/head live in RankMath's private $container array, surfaced only
		// through its __get/__isset magic methods - assertObjectHasProperty() checks real (declared
		// or dynamic) properties via property_exists() and would false-negative here, so isset() is
		// used throughout instead.
		$this->assertTrue( isset( $plugin->registration ), 'rank_math() must expose a ->registration object.' );

		$plugin->registration->invalid = false;
		$plugin->init_frontend();

		$this->assertTrue( isset( $plugin->frontend ), 'rank_math() must expose a ->frontend object once registration is resolved.' );
		$this->assertTrue(
			method_exists( $plugin->frontend, 'integrations' ),
			'rank_math()->frontend must expose a public integrations() method - the call our renderer self-heals with.'
		);
		$this->assertFalse(
			isset( $plugin->head ),
			"rank_math()->head must still be absent right after init_frontend() - it is only built inside integrations(), which real Rank Math hooks to 'wp', an action that never fires for a REST-dispatched request. This is exactly why the renderer must call integrations() itself."
		);

		$plugin->frontend->integrations();

		$this->assertTrue( isset( $plugin->head ), 'rank_math()->head must appear once integrations() has run.' );
		$this->assertIsObject( $plugin->head, 'rank_math()->head must be an object.' );
		$this->assertTrue(
			method_exists( $plugin->head, 'head' ),
			'rank_math()->head must expose a public head() method - the call aafm_rankmath_rendered_head() makes.'
		);
	}
}
