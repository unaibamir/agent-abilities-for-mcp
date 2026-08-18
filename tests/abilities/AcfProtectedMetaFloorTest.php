<?php
/**
 * The ACF protected-meta floor corpus: every container shape whose write reaches a meta key, and
 * whether the hard block must refuse it.
 *
 * ROWS IN THIS FILE ARE APPEND-ONLY. NEVER DELETE ONE.
 *
 * The block used to test the top-level field's key and name only. That is the whole key for a
 * scalar field, but a container writes one meta row per sub-field under a key ACF derives, and a
 * top-level clone with prefix_name off derives nothing at all - the sub-field's own bare name
 * becomes the meta key. So a site that had defined an ACF field named after a protected key and
 * cloned it unprefixed could write that key through acf-update-*-fields, which is exactly what the
 * dedicated meta abilities refuse.
 *
 * Both directions are pinned together and neither half is optional. The refuse rows are the
 * security property. The succeed rows are what stops the fix from being "worse than the bug": a
 * floor that mis-derives an effective meta key refuses ordinary ACF writes on live sites, and a
 * wrong refusal with a generic error is its own defect. Every container type therefore carries an
 * ordinary write that must still land, flexible content most of all - ACF writes a
 * `_{name}_layout_meta` bookkeeping row of its own, and a derivation that "completed" itself by
 * including it would refuse every flexible-content write, because the leading underscore makes
 * is_protected_meta() true.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\AcfStubStore;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\TestCase;
use WP_Error;

final class AcfProtectedMetaFloorTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * One text sub-field definition in the shape acf_get_field() returns.
	 *
	 * @param string $key   Sub-field key.
	 * @param string $name  Sub-field name - the value ACF writes the meta row under, already
	 *                      prefix_name-rewritten where the parent clone applies one.
	 * @param string $alias Sub-field `_name` (its pre-prefix name). Defaults to $name.
	 * @return array<string,mixed>
	 */
	private function sub( string $key, string $name, string $alias = '' ): array {
		return array(
			'key'   => $key,
			'name'  => $name,
			'_name' => '' !== $alias ? $alias : $name,
			'label' => $name,
			'type'  => 'text',
		);
	}

	/**
	 * The field group every row here writes against.
	 *
	 * Each container carries a sub-field named after a protected key alongside a benign twin, so a
	 * refuse row and a succeed row exercise the same definition and the refusal cannot be an
	 * accident of the shape. The names mirror what real ACF produces, measured against ACF Pro
	 * 6.8.7 through acf_clone_field(): `prefix_name => 0` leaves the sub-field name bare under BOTH
	 * display modes, and `prefix_name => 1` rewrites `name` to `{clone name}_{original}` while
	 * seamless additionally rewrites `_name` to match.
	 *
	 * @return array<string,mixed> The field-group config for stub_acf().
	 */
	private function config(): array {
		return array(
			'groups' => array(
				array(
					'key'    => 'group_floor',
					'title'  => 'Floor',
					'fields' => array(
						// A top-level clone, unprefixed, group display. THE DEFECT: ACF writes the
						// sub-field's own bare name, so `wp_capabilities` reaches a protected key.
						array(
							'key'         => 'field_bare_group',
							'name'        => 'bare_group',
							'_name'       => 'bare_group',
							'label'       => 'Bare clone, group display',
							'type'        => 'clone',
							'display'     => 'group',
							'prefix_name' => 0,
							'sub_fields'  => array(
								$this->sub( 'field_bg_email', 'email' ),
								$this->sub( 'field_bg_caps', 'wp_capabilities' ),
								$this->sub( 'field_bg_tokens', 'session_tokens' ),
							),
						),
						// The same, seamless display. Filed as the only trigger; measured to be one
						// of two, because display never touches the sub-field name.
						array(
							'key'         => 'field_bare_seam',
							'name'        => 'bare_seam',
							'_name'       => 'bare_seam',
							'label'       => 'Bare clone, seamless display',
							'type'        => 'clone',
							'display'     => 'seamless',
							'prefix_name' => 0,
							'sub_fields'  => array(
								$this->sub( 'field_bs_email', 'email' ),
								$this->sub( 'field_bs_caps', 'wp_capabilities' ),
							),
						),
						// A prefixed clone. The same hostile sub-field name composes to
						// `pre_wp_capabilities`, which is NOT protected, so this must still write.
						array(
							'key'         => 'field_pre',
							'name'        => 'pre',
							'_name'       => 'pre',
							'label'       => 'Prefixed clone',
							'type'        => 'clone',
							'display'     => 'group',
							'prefix_name' => 1,
							'sub_fields'  => array(
								$this->sub( 'field_pre_email', 'pre_email', 'email' ),
								$this->sub( 'field_pre_caps', 'pre_wp_capabilities', 'wp_capabilities' ),
							),
						),
						// An ordinary group. Its sub keys compose to `grp_*`, never bare.
						array(
							'key'        => 'field_grp',
							'name'       => 'grp',
							'_name'      => 'grp',
							'label'      => 'Group',
							'type'       => 'group',
							'sub_fields' => array(
								$this->sub( 'field_grp_email', 'email' ),
								$this->sub( 'field_grp_caps', 'wp_capabilities' ),
							),
						),
						// A group literally named `wp` holding a sub-field named `capabilities`.
						// Composes to `wp_capabilities`: prefixed is not the same as safe, which is
						// the other half of the reasoning the original report got wrong.
						array(
							'key'        => 'field_wp',
							'name'       => 'wp',
							'_name'      => 'wp',
							'label'      => 'Group named wp',
							'type'       => 'group',
							'sub_fields' => array(
								$this->sub( 'field_wp_caps', 'capabilities' ),
								$this->sub( 'field_wp_note', 'note' ),
							),
						),
						// A group whose sub-field's `name` and `_name` DIVERGE, which is what a
						// prefixed clone's field looks like once it sits inside a group. ACF's group
						// composes its key from `_name`, so this lands on `session_tokens` - while
						// composing from `name` would land on the harmless `session_cl_tokens`.
						// Measured against ACF Pro 6.8.7: a group named `wp` holding a prefixed
						// clone's `capabilities` sub-field writes `wp_capabilities`.
						array(
							'key'        => 'field_sess',
							'name'       => 'session',
							'_name'      => 'session',
							'label'      => 'Group named session',
							'type'       => 'group',
							'sub_fields' => array(
								$this->sub( 'field_sess_tok', 'cl_tokens', 'tokens' ),
								$this->sub( 'field_sess_note', 'note' ),
							),
						),
						// A repeater. Row indices sit between the name and the sub name.
						array(
							'key'        => 'field_rep',
							'name'       => 'rep',
							'_name'      => 'rep',
							'label'      => 'Repeater',
							'type'       => 'repeater',
							'sub_fields' => array(
								$this->sub( 'field_rep_email', 'email' ),
								$this->sub( 'field_rep_caps', 'wp_capabilities' ),
							),
						),
						// Flexible content. Its own `_{name}_layout_meta` row is ACF's bookkeeping,
						// not a caller-reachable key, and must not be derived.
						array(
							'key'     => 'field_flex',
							'name'    => 'flex',
							'_name'   => 'flex',
							'label'   => 'Flexible content',
							'type'    => 'flexible_content',
							'layouts' => array(
								'layout_main' => array(
									'key'        => 'layout_main',
									'name'       => 'main',
									'label'      => 'Main',
									'sub_fields' => array(
										$this->sub( 'field_flex_email', 'email' ),
										$this->sub( 'field_flex_caps', 'wp_capabilities' ),
									),
								),
							),
						),
						// A clone whose `_name` is NOT the tail of its `name`. ACF's
						// prepare_field_for_db() calls that an "unknown potential error" and bails,
						// so it writes the sub-field under its BARE name - verified by driving real
						// ACF Pro 6.8.7, which emitted `wp_capabilities`. Following the vendor's
						// bail matters: computing the prefix anyway would derive `abcwp_capabilities`
						// and miss the protected key entirely.
						array(
							'key'         => 'field_odd',
							'name'        => 'abcdef',
							'_name'       => 'xyz',
							'label'       => 'Clone with a mismatched _name',
							'type'        => 'clone',
							'display'     => 'group',
							'prefix_name' => 0,
							'sub_fields'  => array(
								$this->sub( 'field_odd_caps', 'wp_capabilities' ),
								$this->sub( 'field_odd_note', 'note' ),
							),
						),
						// A field whose KEY is itself a protected name. ACF keys conventionally
						// start with `field_`, but nothing enforces that for a locally registered
						// field, and acf_get_field() resolves a key or a name, so the caller-supplied
						// selector is checked in its own right and not only through the resolved
						// definition.
						array(
							'key'   => 'wp_capabilities',
							'name'  => 'harmless',
							'_name' => 'harmless',
							'label' => 'Field keyed as a protected name',
							'type'  => 'text',
						),
						// A plain scalar, to hold the pre-existing top-level behaviour in place.
						array(
							'key'   => 'field_plain',
							'name'  => 'plain',
							'_name' => 'plain',
							'label' => 'Plain',
							'type'  => 'text',
						),
					),
				),
			),
		);
	}

	/**
	 * Boot the ACF stubs and register the write abilities for one selector type.
	 *
	 * @param string $selector_type 'post' or 'user' - selects which denylist the floor consults.
	 * @return int The object id to write against.
	 */
	private function boot( string $selector_type ): int {
		$this->force_integration( 'acf' );
		$this->stub_acf( $this->config() );
		aafm_registry_cache_should_flush( true );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/acf-update-post-fields', 'aafm/acf-update-user-fields' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		$admin_id = $this->acting_as( 'administrator' );
		if ( 'user' === $selector_type ) {
			return (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		}
		return (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
	}

	/**
	 * Every write shape the floor has been asked to judge, and the verdict it must reach.
	 *
	 * Each row is [ selector type, sent field map, must_refuse ].
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:bool}>
	 */
	public function provide_floor_shapes(): array {
		return array(

			// ---- REFUSE: a bare clone sub-field name IS the meta key. -------------------------

			'clone group display, unprefixed, hostile sub by name' => array(
				'post',
				array( 'field_bare_group' => array( 'wp_capabilities' => 'a:1:{s:13:"administrator";b:1;}' ) ),
				true,
			),
			'clone seamless display, unprefixed, hostile sub by name' => array(
				'post',
				array( 'field_bare_seam' => array( 'wp_capabilities' => 'a:1:{s:13:"administrator";b:1;}' ) ),
				true,
			),
			// Addressed by the sub-field KEY rather than its name. ACF resolves a sent value by key
			// first, so a derivation that only understood names left this as a one-line bypass.
			'clone unprefixed, hostile sub addressed by key' => array(
				'post',
				array( 'field_bare_group' => array( 'field_bg_caps' => 'pwned' ) ),
				true,
			),
			// A second protected name in the same clone, so the refusal is not one string's luck.
			'clone unprefixed, session_tokens sub'        => array(
				'post',
				array( 'field_bare_group' => array( 'session_tokens' => 'pwned' ) ),
				true,
			),
			// The hostile sub-field alongside a benign one: the whole request must fail, not just
			// the offending key, because the floor rejects before writing anything.
			'clone unprefixed, hostile sub beside a benign sub' => array(
				'post',
				array(
					'field_bare_group' => array(
						'email'           => 'ok@example.com',
						'wp_capabilities' => 'pwned',
					),
				),
				true,
			),
			// The user denylist is wider than the post one; the selector must pick the right list.
			'clone unprefixed, hostile sub on a USER selector' => array(
				'user',
				array( 'field_bare_seam' => array( 'wp_capabilities' => 'pwned' ) ),
				true,
			),
			// Prefixed is not the same as safe: `wp` + `capabilities` composes to a blocked key.
			'group named wp composing to wp_capabilities' => array(
				'post',
				array( 'field_wp' => array( 'capabilities' => 'pwned' ) ),
				true,
			),
			// The group composes from the sub-field's `_name`, so this reaches `session_tokens`.
			// Reading `name` instead would derive the harmless `session_cl_tokens` and let it write.
			'group composing from _name reaches session_tokens' => array(
				'post',
				array( 'field_sess' => array( 'tokens' => 'pwned' ) ),
				true,
			),
			'group composing from _name, sub addressed by key' => array(
				'post',
				array( 'field_sess' => array( 'field_sess_tok' => 'pwned' ) ),
				true,
			),
			// ACF bails out of prefixing when `_name` is not the tail of `name`, so the sub-field
			// lands bare. Computing the prefix regardless would derive a key nothing blocks.
			'clone whose _name is not the tail of its name' => array(
				'post',
				array( 'field_odd' => array( 'wp_capabilities' => 'pwned' ) ),
				true,
			),
			// The caller-supplied selector is checked in its own right, not only the name it
			// resolves to.
			'field whose KEY is itself a protected name'  => array(
				'post',
				array( 'wp_capabilities' => 'pwned' ),
				true,
			),

			// ---- SUCCEED: every ordinary container write must still land. ---------------------

			'clone unprefixed, benign sub'                => array(
				'post',
				array( 'field_bare_group' => array( 'email' => 'ok@example.com' ) ),
				false,
			),
			// The hostile NAME under a prefixing clone composes to pre_wp_capabilities, which is not
			// protected. Refusing this would be reading the sub-field name instead of the real key.
			'prefixed clone, sub whose pre-prefix name is hostile' => array(
				'post',
				array(
					'field_pre' => array(
						'email'           => 'ok@example.com',
						'wp_capabilities' => 'still fine',
					),
				),
				false,
			),
			// The same shape addressed by the sub-field KEY is deliberately NOT a row here. It is
			// refused, but by the downstream write-verify rather than the floor: the re-keyer turns
			// a key-addressed value into its name before the comparison, so the sent and stored
			// shapes disagree. That is pre-existing behaviour and a separate question from this
			// floor. Asserting "refused" here would pass for a reason that has nothing to do with
			// the property being claimed, so the by-key case is pinned against the derivation
			// directly instead - see test_the_derived_keys_are_the_keys_acf_would_write().
			'group, sub whose own name is hostile but composes safely' => array(
				'post',
				array(
					'field_grp' => array(
						'wp_capabilities' => 'still fine',
						'email'           => 'ok@example.com',
					),
				),
				false,
			),
			'group named wp, benign sub'                  => array(
				'post',
				array( 'field_wp' => array( 'note' => 'still fine' ) ),
				false,
			),
			'group composing from _name, benign sub'      => array(
				'post',
				array( 'field_sess' => array( 'note' => 'still fine' ) ),
				false,
			),
			'clone with a mismatched _name, benign sub'   => array(
				'post',
				array( 'field_odd' => array( 'note' => 'still fine' ) ),
				false,
			),
			'repeater, two rows, hostile-named sub composes safely' => array(
				'post',
				array( 'field_rep' => array( array( 'wp_capabilities' => 'a' ), array( 'email' => 'b@example.com' ) ) ),
				false,
			),
			// The row that stops a future "completion" of the derivation from blocking flexible
			// content outright: ACF's own `_flex_layout_meta` row must not be derived.
			'flexible content, ordinary row'              => array(
				'post',
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'main',
							'email'         => 'ok@example.com',
						),
					),
				),
				false,
			),
			'flexible content, hostile-named sub composes safely' => array(
				'post',
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout'   => 'main',
							'wp_capabilities' => 'still fine',
						),
					),
				),
				false,
			),
			'plain scalar field'                          => array(
				'post',
				array( 'field_plain' => 'ok' ),
				false,
			),
			// A container sent a scalar: ACF writes nothing per sub-field, and the floor must not
			// invent keys from a shape that is not there.
			'clone sent a scalar instead of a map'        => array(
				'post',
				array( 'field_bare_group' => 'ok' ),
				false,
			),
			// An address matching no sub-field is skipped by ACF, so nothing is written for it and
			// nothing may be derived from it either.
			'clone, address matching no sub-field'        => array(
				'post',
				array( 'field_bare_group' => array( 'not_a_sub_field' => 'ok' ) ),
				false,
			),
		);
	}

	/**
	 * Drive one write shape through the real ability and check the verdict.
	 *
	 * A refusal is checked twice over: the ability must return an error AND nothing may have
	 * reached update_field(). The second half is what separates a floor refusal from a downstream
	 * write-verify failure, which reports the same error after the value is already stored.
	 *
	 * @dataProvider provide_floor_shapes
	 *
	 * @param string              $selector_type 'post' or 'user'.
	 * @param array<string,mixed> $fields        The field map to write.
	 * @param bool                $must_refuse   True when the floor must reject the whole request.
	 * @return void
	 */
	public function test_floor_verdict( string $selector_type, array $fields, bool $must_refuse ): void {
		$object_id = $this->boot( $selector_type );
		$slug      = 'user' === $selector_type ? 'aafm/acf-update-user-fields' : 'aafm/acf-update-post-fields';
		$arg       = 'user' === $selector_type ? 'user_id' : 'post_id';

		AcfStubStore::$written = array();
		$result                = wp_get_ability( $slug )->execute(
			array(
				$arg     => $object_id,
				'fields' => $fields,
			)
		);

		if ( $must_refuse ) {
			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				'A write whose effective meta key is hard-blocked must be refused.'
			);
			// The refusal has to happen before anything is written, not after. A floor that rejects
			// the response while the value is already in storage is not a floor.
			$this->assertSame(
				array(),
				AcfStubStore::$written,
				'A refused write must reach no update_field() call at all.'
			);
			return;
		}

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			'An ordinary ACF write must not be refused: a wrong refusal is its own defect.'
		);
		$this->assertNotSame(
			array(),
			AcfStubStore::$written,
			'An accepted write must actually reach update_field().'
		);
	}

	/**
	 * The derivation, exercised directly against the definitions above.
	 *
	 * The provider rows judge the OUTCOME, which is the property that matters, but they cannot show
	 * WHICH key produced a verdict - a refusal for the wrong reason reads exactly like a refusal for
	 * the right one, and that failure mode has already been hit twice in this release. These rows
	 * name the keys.
	 *
	 * @return void
	 */
	public function test_the_derived_keys_are_the_keys_acf_would_write(): void {
		$this->boot( 'post' );
		$defs = array();
		foreach ( $this->config()['groups'][0]['fields'] as $field ) {
			$defs[ (string) $field['key'] ] = $field;
		}

		$cases = array(
			// [ field key, sent value, expected derived keys ]
			array( 'field_bare_group', array( 'wp_capabilities' => 'x' ), array( 'bare_group', 'wp_capabilities' ) ),
			array( 'field_bare_group', array( 'field_bg_caps' => 'x' ), array( 'bare_group', 'wp_capabilities' ) ),
			array( 'field_bare_seam', array( 'wp_capabilities' => 'x' ), array( 'bare_seam', 'wp_capabilities' ) ),
			array( 'field_pre', array( 'wp_capabilities' => 'x' ), array( 'pre', 'pre_wp_capabilities' ) ),
			// Addressed by key, the case the outcome provider cannot judge cleanly: the floor must
			// derive the composed key and therefore must NOT be what refuses this write.
			array( 'field_pre', array( 'field_pre_caps' => 'x' ), array( 'pre', 'pre_wp_capabilities' ) ),
			array( 'field_grp', array( 'wp_capabilities' => 'x' ), array( 'grp', 'grp_wp_capabilities' ) ),
			array( 'field_wp', array( 'capabilities' => 'x' ), array( 'wp', 'wp_capabilities' ) ),
			// A group composes from `_name`, not `name`. Reading `name` would derive
			// `session_cl_tokens` and miss the protected key entirely.
			array( 'field_sess', array( 'tokens' => 'x' ), array( 'session', 'session_tokens' ) ),
			array( 'field_sess', array( 'field_sess_tok' => 'x' ), array( 'session', 'session_tokens' ) ),
			array(
				'field_rep',
				array( array( 'wp_capabilities' => 'a' ), array( 'wp_capabilities' => 'b' ) ),
				array( 'rep', 'rep_0_wp_capabilities', 'rep_1_wp_capabilities' ),
			),
			array(
				'field_flex',
				array(
					array(
						'acf_fc_layout'   => 'main',
						'wp_capabilities' => 'x',
					),
				),
				array( 'flex', 'flex_0_wp_capabilities' ),
			),
			array( 'field_odd', array( 'wp_capabilities' => 'x' ), array( 'abcdef', 'wp_capabilities' ) ),
			array( 'field_plain', 'x', array( 'plain' ) ),
			array( 'field_bare_group', 'x', array( 'bare_group' ) ),
			array( 'field_bare_group', array( 'not_a_sub_field' => 'x' ), array( 'bare_group' ) ),
		);

		foreach ( $cases as $case ) {
			list( $field_key, $sent, $expected ) = $case;
			$derived                             = aafm_acf_effective_meta_keys( $defs[ $field_key ], $sent );
			sort( $derived );
			sort( $expected );
			$this->assertSame(
				$expected,
				array_values( array_unique( $derived ) ),
				sprintf( 'Derived meta keys for %s must be exactly what ACF would write.', $field_key )
			);
		}

		// ACF's own bookkeeping row for a flexible-content field must NOT be derived. Including it
		// would refuse every flexible-content write, because a leading underscore is protected meta.
		$flex = aafm_acf_effective_meta_keys(
			$defs['field_flex'],
			array(
				array(
					'acf_fc_layout' => 'main',
					'email'         => 'x',
				),
			)
		);
		$this->assertNotContains(
			'_flex_layout_meta',
			$flex,
			"ACF's internal layout-meta row is not caller-reachable and must stay out of the derivation."
		);
	}

	/**
	 * The definitions above are only worth anything if they really carry sub-fields.
	 *
	 * Added under the mutation pass, and it closes a real hole: emptying every `sub_fields` list
	 * leaves the derivation with nothing to walk, so every refuse row still refuses (on the
	 * top-level name, which is benign here) - no, it does not; it stops refusing entirely and the
	 * corpus goes red. But every SUCCEED row keeps passing, which is the vacuous half. This pins the
	 * fixture itself so a stub that stops modelling ACF cannot quietly hollow the corpus out.
	 *
	 * @return void
	 */
	public function test_the_fixture_declares_sub_fields_or_this_corpus_proves_nothing(): void {
		$counts = array();
		foreach ( $this->config()['groups'][0]['fields'] as $field ) {
			$key = (string) $field['key'];
			if ( isset( $field['sub_fields'] ) ) {
				$counts[ $key ] = count( (array) $field['sub_fields'] );
				continue;
			}
			if ( isset( $field['layouts'] ) ) {
				$total = 0;
				foreach ( (array) $field['layouts'] as $layout ) {
					$total += count( (array) $layout['sub_fields'] );
				}
				$counts[ $key ] = $total;
			}
		}

		$this->assertSame(
			array(
				'field_bare_group' => 3,
				'field_bare_seam'  => 2,
				'field_pre'        => 2,
				'field_grp'        => 2,
				'field_wp'         => 2,
				'field_sess'       => 2,
				'field_rep'        => 2,
				'field_flex'       => 2,
				'field_odd'        => 2,
			),
			$counts,
			'Every container in the fixture must still declare its sub-fields.'
		);
	}
}
