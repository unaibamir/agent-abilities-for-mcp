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
	 * Rank Math is a detection + meta-key integration (it calls no Rank Math code symbols). Pin the
	 * detection contract M6-style: the `RankMath` marker class and version constant exist.
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
}
