<?php
/**
 * The plain-data predicate that decides whether a bridged result may be relayed.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;

final class BridgeResultPlainDataTest extends TestCase {

	/**
	 * Plain data the predicate must accept.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public function plain_provider(): array {
		$populated           = (object) array(
			'saved' => true,
			'x'     => array( 1, 2 ),
		);
		$nested              = new \stdClass();
		$nested->inner       = new \stdClass();
		$nested->inner->leaf = 'x';

		return array(
			'null'                 => array( null ),
			'bool'                 => array( true ),
			'int'                  => array( 7 ),
			'float'                => array( 1.5 ),
			'string'               => array( 'ok' ),
			'empty array'          => array( array() ),
			'nested arrays'        => array( array( 'a' => array( 'b' => array( 1, 'two', null ) ) ) ),
			'empty stdClass'       => array( new \stdClass() ),
			'populated stdClass'   => array( $populated ),
			'stdClass in stdClass' => array( $nested ),
			'stdClass in an array' => array(
				array(
					'changed' => $populated,
					'ids'     => array( 1, 2 ),
				),
			),
		);
	}

	/**
	 * Plain data is accepted.
	 *
	 * @dataProvider plain_provider
	 * @param mixed $value Plain data.
	 */
	public function test_plain_data_is_accepted( $value ): void {
		$this->assertTrue( aafm_bridge_result_is_plain_data( $value ) );
	}

	/**
	 * Values the predicate must refuse.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public function refused_provider(): array {
		$private_state = new class() implements \JsonSerializable {
			/**
			 * Private state invisible to get_object_vars() from outside the class.
			 *
			 * @var string
			 */
			private string $api_key = 'sk-secret';

			#[\ReturnTypeWillChange]
			public function jsonSerialize() {
				return array( 'api_key' => $this->api_key );
			}
		};
		$subclass      = new class() extends \stdClass {
			/**
			 * Private state a stdClass subclass can carry.
			 *
			 * @var string
			 */
			private string $secret = 'still leaky';
		};
		$self          = new \stdClass();
		$self->self    = $self;

		return array(
			'JsonSerializable with private state' => array( $private_state ),
			'stdClass subclass'                   => array( $subclass ),
			'stdClass subclass nested'            => array( array( 'result' => $subclass ) ),
			'self-referencing stdClass'           => array( $self ),
			'ArrayObject'                         => array( new \ArrayObject( array( 'a' => 1 ) ) ),
			'DateTime'                            => array( new \DateTime( '2026-01-01' ) ),
			'Closure'                             => array( static fn() => 1 ),
			'WP_Error inside an array'            => array( array( 'result' => new \WP_Error( 'x', 'y' ) ) ),
			'refused class inside a stdClass'     => array( (object) array( 'inner' => new \ArrayObject() ) ),
		);
	}

	/**
	 * Everything outside the plain-data list is refused.
	 *
	 * @dataProvider refused_provider
	 * @param mixed $value Data that must be refused.
	 */
	public function test_everything_else_is_refused( $value ): void {
		$this->assertFalse( aafm_bridge_result_is_plain_data( $value ) );
	}

	public function test_core_objects_that_can_hold_credentials_are_refused_at_any_depth(): void {
		$user = new \WP_User( self::factory()->user->create() );
		$post = self::factory()->post->create_and_get();

		$this->assertFalse( aafm_bridge_result_is_plain_data( $user ), 'A bare WP_User carries user_pass in ->data.' );
		$this->assertFalse( aafm_bridge_result_is_plain_data( $post ) );
		$this->assertFalse( aafm_bridge_result_is_plain_data( array( 'result' => $user ) ) );
		$this->assertFalse(
			aafm_bridge_result_is_plain_data( (object) array( 'a' => (object) array( 'b' => (object) array( 'user' => $user ) ) ) ),
			'A WP_User three levels deep inside stdClass objects.'
		);
	}

	public function test_a_wp_term_is_accepted_but_a_term_carrying_an_object_is_not(): void {
		$term = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'category',
				'name'     => 'Plain data term',
			)
		);
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertTrue( aafm_bridge_result_is_plain_data( $term ) );
		$this->assertTrue( aafm_bridge_result_is_plain_data( array( 'options' => array( $term ) ) ) );

		// WP_Term allows dynamic properties, so a plugin can hang anything on it.
		$term->leak = new \WP_User( self::factory()->user->create() );
		$this->assertFalse( aafm_bridge_result_is_plain_data( $term ), 'The walk covers a term\'s properties like a stdClass.' );
	}

	public function test_a_resource_is_refused(): void {
		$this->assertFalse( aafm_bridge_result_is_plain_data( array( 'h' => stream_context_create() ) ) );
	}

	public function test_an_array_nested_past_the_bound_is_refused_and_one_inside_it_is_accepted(): void {
		$inside = 'leaf';
		for ( $i = 0; $i < AAFM_SCHEMA_MAX_DEPTH; $i++ ) {
			$inside = array( $inside );
		}
		$past = 'leaf';
		for ( $i = 0; $i <= AAFM_SCHEMA_MAX_DEPTH; $i++ ) {
			$past = array( $past );
		}

		$this->assertTrue( aafm_bridge_result_is_plain_data( $inside ), '30 nested arrays stay inside the bound.' );
		$this->assertFalse( aafm_bridge_result_is_plain_data( $past ), '31 nested arrays are past it.' );
	}
}
