<?php
/**
 * Cache refresh after an agent changes only a post's or term's custom fields.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

/**
 * Covers aafm_collect_meta_touch() and aafm_refresh_after_meta_writes().
 */
class MetaCacheRefreshTest extends TestCase {

	/**
	 * Hook fires recorded by the spies: list of array{hook, id, refresh} where refresh is true
	 * when it fired with no ability executing (the refresh phase, after the outermost call).
	 *
	 * @var list<array{0:string,1:int,2:bool}>
	 */
	private $fired = array();

	public function set_up(): void {
		parent::set_up();
		update_option( 'aafm_allowed_meta_keys', array( 'aafm_note' ) );
		update_option( 'aafm_exposed_term_meta_keys', array( 'aafm_note' ) );
		$this->acting_as( 'administrator' );
		$this->fired = array();
		foreach ( array( 'save_post', 'post_updated', 'clean_post_cache', 'edited_term', 'clean_term_cache', 'aafm_agent_meta_written' ) as $hook ) {
			add_action(
				$hook,
				function ( ...$args ) use ( $hook ): void {
					$id = 'aafm_agent_meta_written' === $hook ? $args[1] : $args[0];
					$id = is_array( $id ) ? reset( $id ) : $id; // clean_term_cache passes a list of ids.

					$refresh       = null === aafm_executing_ability();
					$this->fired[] = array( $hook, (int) $id, $refresh );
				},
				10,
				3
			);
		}
	}

	public function tear_down(): void {
		remove_all_filters( 'aafm_meta_write_full_resave' );
		foreach ( array( 'post', 'term' ) as $kind ) {
			$touched          =& aafm_meta_touched();
			$touched[ $kind ] = array();
		}
		parent::tear_down();
	}

	private function fires( string $hook, int $id, ?bool $refresh_phase = null ): int {
		$n = 0;
		foreach ( $this->fired as $f ) {
			if ( $f[0] === $hook && $f[1] === $id && ( null === $refresh_phase || $f[2] === $refresh_phase ) ) {
				++$n;
			}
		}
		return $n;
	}

	private function published_post( array $extra = array() ): int {
		return (int) self::factory()->post->create(
			$extra + array(
				'post_status' => 'publish',
				'post_date'   => '2020-01-01 00:00:00',
			)
		);
	}

	private function run_ability( string $name, array $input ) {
		$this->register_enabled( array( $name ) );
		$this->fired = array();
		return wp_get_ability( $name )->execute( $input );
	}

	private function update_note( int $post_id, string $value = 'x' ) {
		return $this->run_ability(
			'aafm/update-post-meta',
			array(
				'post_id'  => $post_id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'value'    => $value,
			)
		);
	}

	/**
	 * Run $work the way an ability's execute() does: inside the executing stack, with the refresh
	 * when the outermost call finishes.
	 *
	 * @param callable $work Work to run.
	 */
	private function as_ability( callable $work ): void {
		aafm_executing_ability_push( 'aafm/test-only' );
		try {
			$work();
		} finally {
			aafm_executing_ability_pop();
			if ( null === aafm_executing_ability() ) {
				aafm_refresh_after_meta_writes();
			}
		}
	}

	public function test_status_quo_a_meta_only_write_fires_no_purge_hook(): void {
		$id          = $this->published_post();
		$this->fired = array();
		aafm_meta_set( 'post', $id, 'seo_title', 'x', 'post' );
		$this->assertSame( array(), $this->fired, 'Core meta writes fire none of the hooks page caches purge on; the fix lives in the ability wrapper.' );
	}

	public function test_the_default_changes_no_modified_date_and_fires_no_save_post(): void {
		$id     = $this->published_post();
		$before = get_post( $id );
		$result = $this->update_note( $id );
		$this->assertFalse( is_wp_error( $result ) );
		$after = get_post( $id );
		$this->assertSame( $before->post_modified, $after->post_modified );
		$this->assertSame( $before->post_modified_gmt, $after->post_modified_gmt );
		$this->assertSame( 0, $this->fires( 'save_post', $id ) );
		$this->assertSame( 0, $this->fires( 'post_updated', $id ) );
		$this->assertSame( 1, $this->fires( 'clean_post_cache', $id, true ) );
		$this->assertSame( 1, $this->fires( 'aafm_agent_meta_written', $id ) );
	}

	public function test_two_meta_writes_to_one_post_in_one_call_clear_it_once(): void {
		$id          = $this->published_post();
		$this->fired = array();
		$this->as_ability(
			static function () use ( $id ): void {
				update_post_meta( $id, 'aafm_note', 'a' );
				update_post_meta( $id, 'aafm_other', 'b' );
			}
		);
		$this->assertSame( 1, $this->fires( 'clean_post_cache', $id, true ) );
		$this->assertSame( 1, $this->fires( 'aafm_agent_meta_written', $id ) );
	}

	public function test_a_draft_a_private_post_and_a_non_public_post_type_get_no_cache_clear_but_the_action_still_fires(): void {
		register_post_type( 'aafm_hidden_type', array( 'public' => false ) );
		$ids         = array(
			(int) self::factory()->post->create( array( 'post_status' => 'draft' ) ),
			$this->published_post( array( 'post_status' => 'private' ) ),
			$this->published_post( array( 'post_type' => 'aafm_hidden_type' ) ),
		);
		$this->fired = array();
		$this->as_ability(
			static function () use ( $ids ): void {
				foreach ( $ids as $id ) {
					update_post_meta( $id, 'aafm_note', 'x' );
				}
			}
		);
		unregister_post_type( 'aafm_hidden_type' );
		foreach ( $ids as $id ) {
			$this->assertSame( 0, $this->fires( 'clean_post_cache', $id ), (string) $id );
			$this->assertSame( 1, $this->fires( 'aafm_agent_meta_written', $id ), (string) $id );
		}
	}

	public function test_term_meta_clears_a_public_taxonomy_terms_cache(): void {
		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$result  = $this->run_ability(
			'aafm/update-term-meta',
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'value'    => 'x',
			)
		);
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 1, $this->fires( 'clean_term_cache', $term_id ) );
		$this->assertSame( 0, $this->fires( 'edited_term', $term_id ) );
		$this->assertSame( 1, $this->fires( 'aafm_agent_meta_written', $term_id ) );
	}

	public function test_nothing_runs_outside_an_ability(): void {
		$id          = $this->published_post();
		$this->fired = array();
		update_post_meta( $id, 'aafm_note', 'theme' );
		aafm_refresh_after_meta_writes();
		$this->assertSame( array(), $this->fired );
	}

	public function test_origin_stamp_meta_does_not_add_a_second_clear_for_a_new_draft(): void {
		$result = $this->run_ability(
			'aafm/create-post',
			array(
				'title'  => 'Agent draft',
				'status' => 'draft',
			)
		);
		$this->assertFalse( is_wp_error( $result ) );
		$id = (int) ( $result['post']['id'] ?? 0 );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 0, $this->fires( 'clean_post_cache', $id, true ), 'A draft gets no clear from the refresh.' );
		$this->assertSame( 1, $this->fires( 'aafm_agent_meta_written', $id ) );
	}

	public function test_a_listener_that_throws_cannot_fail_the_call(): void {
		$id = $this->published_post();
		add_action(
			'clean_post_cache',
			static function (): void {
				if ( null === aafm_executing_ability() ) {
					throw new \RuntimeException( 'listener failure' );
				}
			}
		);
		$result = $this->update_note( $id );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'written', $result['status'] );
	}

	public function test_the_opt_in_full_resave_runs_save_post_once(): void {
		add_filter( 'aafm_meta_write_full_resave', '__return_true' );
		$id     = $this->published_post();
		$before = get_post( $id )->post_modified;
		$user   = get_current_user_id();
		$users  = array();
		add_action(
			'save_post',
			static function ( $post_id ) use ( &$users, $id ): void {
				if ( $id === (int) $post_id ) {
					$users[] = get_current_user_id();
				}
			}
		);
		$this->update_note( $id );
		$this->assertSame( 1, $this->fires( 'save_post', $id ) );
		$this->assertSame( array( $user ), $users );
		clean_post_cache( $id );
		$this->assertNotSame( $before, get_post( $id )->post_modified );

		$term_id = (int) self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$this->run_ability(
			'aafm/update-term-meta',
			array(
				'taxonomy' => 'category',
				'term_id'  => $term_id,
				'meta_key' => 'aafm_note', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- tool argument.
				'value'    => 'x',
			)
		);
		$this->assertSame( 1, $this->fires( 'edited_term', $term_id ) );
	}

	public function test_the_opt_in_skips_a_post_the_call_saved_after_writing_meta(): void {
		add_filter( 'aafm_meta_write_full_resave', '__return_true' );
		$id          = $this->published_post();
		$this->fired = array();
		$this->as_ability(
			static function () use ( $id ): void {
				update_post_meta( $id, 'aafm_note', 'x' );
				wp_update_post( array( 'ID' => $id ) );
			}
		);
		$this->assertSame( 1, $this->fires( 'save_post', $id ), 'Only the call\'s own save.' );
		$this->assertSame( 0, $this->fires( 'save_post', $id, true ) );
	}

	public function test_the_opt_in_covers_a_post_whose_meta_was_written_after_the_call_saved_it(): void {
		add_filter( 'aafm_meta_write_full_resave', '__return_true' );
		$id     = $this->published_post();
		$result = $this->run_ability(
			'aafm/update-post',
			array(
				'post_id' => $id,
				'meta'    => array( 'aafm_note' => 'x' ),
			)
		);
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'x', get_post_meta( $id, 'aafm_note', true ) );
		$this->assertSame( 1, $this->fires( 'save_post', $id, false ), 'The call\'s own save.' );
		$this->assertSame( 1, $this->fires( 'save_post', $id, true ), 'Exactly one re-save, because the meta landed after the call\'s save.' );
	}

	public function test_the_refresh_does_not_loop(): void {
		add_filter( 'aafm_meta_write_full_resave', '__return_true' );
		$id = $this->published_post();
		add_action(
			'save_post',
			static function ( $post_id ): void {
				update_post_meta( (int) $post_id, 'aafm_on_save', 'y' );
			}
		);
		$this->update_note( $id );
		$this->assertSame( 1, $this->fires( 'save_post', $id ) );
		$this->assertSame(
			array(
				'post' => array(),
				'term' => array(),
			),
			aafm_meta_touched()
		);
	}
}
