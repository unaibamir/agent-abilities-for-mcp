<?php
/**
 * ACF (and SCF fork) vendor-contract tests.
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
 * Asserts the real ACF contracts the field abilities rely on.
 *
 * @group contract
 */
final class AcfContractTest extends TestCase {

	/**
	 * Skip the class if ACF is not provisioned in the test core.
	 */
	public function set_up(): void {
		parent::set_up();
		if ( ! function_exists( 'get_field' ) || ! class_exists( 'ACF' ) ) {
			$this->markTestSkipped( 'ACF not provisioned — run tests/bin/install-vendors.sh.' );
		}
	}

	/**
	 * M6: detection must key on the `ACF` class, which the SCF fork keeps, not on `get_field()` —
	 * a bare theme helper named get_field() collides with the loose fallback.
	 */
	public function test_acf_marker_class_and_api_exist(): void {
		$this->assertTrue( class_exists( 'ACF' ), 'The ACF marker class exists (SCF keeps it too).' );
		$this->assertTrue( function_exists( 'get_field' ), 'get_field() exists.' );
		$this->assertTrue( function_exists( 'update_field' ), 'update_field() exists.' );
		$this->assertTrue( function_exists( 'acf_add_local_field_group' ), 'acf_add_local_field_group() exists.' );
	}

	/**
	 * H2 / ACF write persistence: a numeric 0 and a boolean false written through update_field
	 * must persist and read back as their real types — not be mistaken for "write failed" (the
	 * production code once aborted the whole field map when a persisted 0/false looked falsy).
	 */
	public function test_numeric_and_boolean_writes_persist_and_read_back(): void {
		acf_add_local_field_group(
			array(
				'key'      => 'group_aafm_contract',
				'title'    => 'AAFM Contract',
				'fields'   => array(
					array(
						'key'   => 'field_aafm_qty',
						'label' => 'Qty',
						'name'  => 'aafm_qty',
						'type'  => 'number',
					),
					array(
						'key'   => 'field_aafm_flag',
						'label' => 'Flag',
						'name'  => 'aafm_flag',
						'type'  => 'true_false',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
			)
		);

		$post_id = self::factory()->post->create();

		// update_field() returns a truthy value on success (the meta id on insert, true on update) —
		// never false. The production bug was treating a persisted 0/false as a failed write.
		$this->assertNotFalse( update_field( 'aafm_qty', 0, $post_id ), 'Writing 0 reports success (truthy).' );
		$this->assertNotFalse( update_field( 'aafm_flag', false, $post_id ), 'Writing false reports success (truthy).' );

		// The persisted values read back as their real types: a legitimate 0 / false is data, not failure.
		$this->assertSame( '0', (string) get_field( 'aafm_qty', $post_id ), 'A persisted 0 reads back as 0.' );
		$this->assertFalse( (bool) get_field( 'aafm_flag', $post_id ), 'A persisted false reads back as false.' );
	}
}
