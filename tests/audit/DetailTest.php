<?php
/**
 * The activity-log detail allowlist: the type validators that keep free text out of the log.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class DetailTest extends TestCase {

	/**
	 * A value that clears its type check comes back rendered as a string.
	 *
	 * @dataProvider accepted_values
	 *
	 * @param string $type     The field type.
	 * @param mixed  $value    The candidate value.
	 * @param string $expected The rendered value.
	 */
	public function test_accepted_values( string $type, $value, string $expected ): void {
		$this->assertSame( $expected, aafm_activity_detail_field( $type, $value ) );
	}

	/**
	 * Values that must clear their type check.
	 *
	 * @return array<string,array{0:string,1:mixed,2:string}>
	 */
	public static function accepted_values(): array {
		return array(
			'positive int id'   => array( 'id', 482, '482' ),
			'numeric string id' => array( 'id', '482', '482' ),
			'meta key'          => array( 'key', '_yoast_wpseo_metadesc', '_yoast_wpseo_metadesc' ),
			'hyphen key'        => array( 'key', 'bacs', 'bacs' ),
			'ability slug'      => array( 'slug', 'aafm/wc-create-refund', 'aafm/wc-create-refund' ),
			'zero count'        => array( 'count', 0, '0' ),
		);
	}

	/**
	 * A value that fails its type check comes back as null, never as a partial render.
	 *
	 * @dataProvider rejected_values
	 *
	 * @param string $type  The field type.
	 * @param mixed  $value The candidate value.
	 */
	public function test_rejected_values( string $type, $value ): void {
		$this->assertNull( aafm_activity_detail_field( $type, $value ) );
	}

	/**
	 * Values that must fail their type check.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public static function rejected_values(): array {
		return array(
			'zero id'            => array( 'id', 0 ),
			'negative id'        => array( 'id', -1 ),
			'non numeric id'     => array( 'id', 'abc' ),
			'key with space'     => array( 'key', 'my key' ),
			'key with markup'    => array( 'key', '<b>k</b>' ),
			'key with quote'     => array( 'key', "k'x" ),
			'over long key'      => array( 'key', str_repeat( 'a', 65 ) ),
			'sentence as key'    => array( 'key', 'The quick brown fox jumped' ),
			'slug without slash' => array( 'slug', 'wc-create-refund' ),
			'negative count'     => array( 'count', -3 ),
			'unknown type'       => array( 'string', 'anything at all' ),
			'array value'        => array( 'id', array( 1, 2 ) ),
			'null value'         => array( 'id', null ),
		);
	}

	public function test_enum_accepts_only_declared_members(): void {
		$statuses = array( 'refunded', 'cancelled', 'completed' );
		$this->assertSame( 'refunded', aafm_activity_detail_field( 'enum', 'refunded', $statuses ) );
		$this->assertNull( aafm_activity_detail_field( 'enum', 'processing', $statuses ) );
	}

	public function test_there_is_no_free_text_field_type(): void {
		foreach ( array( 'string', 'text', 'raw', 'value', 'content' ) as $forbidden ) {
			$this->assertNull(
				aafm_activity_detail_field( $forbidden, 'a sentence a user typed' ),
				"Field type '{$forbidden}' must not exist. See 146 section 6.2."
			);
		}
	}
}
