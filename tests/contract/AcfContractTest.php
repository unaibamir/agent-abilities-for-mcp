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

	/**
	 * R4-1 / the unknown-sub-field floor, against the real vendor rather than the stub.
	 *
	 * Two contracts in one test, because the fix depends on both and only the vendor can settle
	 * them. First, ACF resolves a container row against its OWN declared sub-fields and writes
	 * nothing whatsoever for an address matching none of them - so an undeclared sub-field name is
	 * not a value that lands somewhere unexpected, it is a value that lands nowhere. Second, the
	 * recognised sub-fields in that same row DO land, which is what made the old behaviour a
	 * silent wrong answer: the plugin reported the request as failed with the data already stored.
	 *
	 * The container here is a `group`, deliberately. This suite pins ACF free/SCF, which has no
	 * repeater, flexible-content or clone field at all - those are Pro - so `group` is the only
	 * container the real vendor can exercise here. The other three are proved against ACF Pro
	 * 6.8.7 by zero-write probe on the bench and modelled by the stub in the unit corpus.
	 */
	public function test_an_undeclared_sub_field_address_is_refused_before_anything_is_written(): void {
		acf_add_local_field_group(
			array(
				'key'      => 'group_aafm_contract_addr',
				'title'    => 'AAFM Contract Addresses',
				'fields'   => array(
					array(
						'key'        => 'field_aafm_c_grp',
						'label'      => 'Grp',
						'name'       => 'aafm_c_grp',
						'type'       => 'group',
						'sub_fields' => array(
							array(
								'key'   => 'field_aafm_c_alpha',
								'label' => 'Alpha',
								'name'  => 'alpha',
								'type'  => 'text',
							),
							array(
								'key'   => 'field_aafm_c_beta',
								'label' => 'Beta',
								'name'  => 'beta',
								'type'  => 'text',
							),
						),
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

		// The vendor half: an undeclared address writes no meta row, while its declared neighbour does.
		update_field( 'field_aafm_c_grp', array( 'alpha' => 'BEFORE' ), $post_id );
		$this->assertSame(
			'BEFORE',
			get_post_meta( $post_id, 'aafm_c_grp_alpha', true ),
			'A declared sub-field writes its own meta row.'
		);
		update_field(
			'field_aafm_c_grp',
			array(
				'alpha'     => 'SECOND',
				'nosuchsub' => 'X',
			),
			$post_id
		);
		$this->assertSame(
			'SECOND',
			get_post_meta( $post_id, 'aafm_c_grp_alpha', true ),
			'The recognised sub-field still lands when an undeclared address travels beside it.'
		);
		$this->assertFalse(
			metadata_exists( 'post', $post_id, 'aafm_c_grp_nosuchsub' ),
			'ACF writes NOTHING for an address its definition does not declare.'
		);

		// The plugin half: the same shape through the write path is refused, and the refusal
		// happens before the write rather than after it. `alpha` keeping its previous value is the
		// assertion that separates this fix from the defect - both return a WP_Error.
		$result = aafm_acf_write_fields(
			array(
				'field_aafm_c_grp' => array(
					'alpha'     => 'MUST-NOT-LAND',
					'nosuchsub' => 'X',
				),
			),
			$post_id,
			'post'
		);

		$this->assertInstanceOf( \WP_Error::class, $result, 'The request is refused.' );
		$this->assertSame(
			'aafm_acf_unknown_sub_field',
			$result->get_error_code(),
			'The refusal names its own reason so an agent can correct the address.'
		);
		$this->assertSame(
			'SECOND',
			get_post_meta( $post_id, 'aafm_c_grp_alpha', true ),
			'A refused request leaves storage untouched; reporting failure while the write lands is the defect.'
		);

		// And an ordinary write to the same field still succeeds, so this is not "refuse everything".
		$ok = aafm_acf_write_fields(
			array( 'field_aafm_c_grp' => array( 'alpha' => 'AFTER' ) ),
			$post_id,
			'post'
		);
		$this->assertNotInstanceOf( \WP_Error::class, $ok, 'An ordinary group write must still be accepted.' );
		$this->assertSame(
			'AFTER',
			get_post_meta( $post_id, 'aafm_c_grp_alpha', true ),
			'The accepted write really reached storage.'
		);
	}
}
