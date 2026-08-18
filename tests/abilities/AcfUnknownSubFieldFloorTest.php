<?php
/**
 * The unknown-sub-field floor corpus: every address a container write can carry, and whether the
 * request must be refused before anything is written.
 *
 * ROWS IN THIS FILE ARE APPEND-ONLY. NEVER DELETE ONE.
 *
 * The defect this exists for shipped in 1.6.3 and earlier. A container write carrying a sub-field
 * name the definition does not declare was reported as a FAILURE while the write LANDED: ACF wrote
 * every sub-field it recognised, ignored the address it did not, the read-back verify then could
 * not find that address in storage, and the whole request came back an error. Measured end to end
 * against real ACF, a sub-field went from `BEFORE` to `AFTER-WRITE-LANDED` on the very call that
 * returned the error. An agent told the write failed retries it, or reports failure to a user,
 * while the data is already in wp_postmeta - the silent-wrong-answer class.
 *
 * Both directions are pinned together and the succeed rows are not the optional half. This is a
 * refusal added to a live write path, so the way to be worse than the bug is to refuse an ordinary
 * ACF write: a flexible-content row carrying the `acf_fc_layout` marker every such row must carry,
 * a prefixed clone addressed by `_name`, a container nested inside another container, a link's
 * structured value, a checkbox list. Every one of those is here as a MUST-SUCCEED row, and each of
 * them also asserts the write really reached update_field(), because a refusal that had quietly
 * become "refuse nothing, write nothing" would otherwise read as success.
 *
 * Addressing a sub-field by its KEY is a second instance of that same class, at a different
 * trigger, and it is now fixed too. ACF documents the form (/resources/add_row keys a whole row by
 * sub-field keys) and writes the row for it, but this plugin's read-back re-keyed storage to
 * sub-field NAMES while comparing against the sent KEY, so the sent address could never be found
 * and the request failed anyway - with the data already written. Present in shipped 1.6.3, where
 * the same re-keyer feeds the same comparison. The rows for it were `fail-after-write` while this
 * floor was the only thing that had been changed; they are now `accept-by-key`, which asserts the
 * strictly stronger pair: this floor still refuses nothing, AND the value physically reached
 * storage. The over-acceptance direction is pinned next door in AcfContainerVerifyCorpusTest.
 *
 * The shapes are the ones measured against real ACF Pro 6.8.7 on the bench, by a zero-write probe
 * that short-circuits acf/pre_update_metadata and captures the meta keys ACF's own update_value()
 * would write. What that probe established, and what these rows encode: ACF resolves a row's
 * values against its OWN sub-field definitions, by the sub-field's key and then its name/_name, and
 * an address matching none of them is never read and never written - for all four container types.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\AcfStubStore;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\TestCase;
use WP_Error;

final class AcfUnknownSubFieldFloorTest extends TestCase {

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
	 * @param string $name  Sub-field name, already prefix_name-rewritten where a clone applies one.
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
	 * The field group every row here writes against: one of each container type, a container with
	 * no declared shape, and two non-containers whose values are arrays.
	 *
	 * @return array<string,mixed> The field-group config for stub_acf().
	 */
	private function config(): array {
		return array(
			'groups' => array(
				array(
					'key'    => 'group_addr',
					'title'  => 'Addresses',
					'fields' => array(
						array(
							'key'        => 'field_grp',
							'name'       => 'grp',
							'label'      => 'Group',
							'type'       => 'group',
							'sub_fields' => array(
								$this->sub( 'field_grp_a', 'alpha' ),
								$this->sub( 'field_grp_b', 'beta' ),
							),
						),
						array(
							'key'        => 'field_rep',
							'name'       => 'rep',
							'label'      => 'Repeater',
							'type'       => 'repeater',
							'sub_fields' => array(
								$this->sub( 'field_rep_t', 'title' ),
								array(
									'key'        => 'field_rep_g',
									'name'       => 'inner',
									'_name'      => 'inner',
									'label'      => 'Inner',
									'type'       => 'group',
									'sub_fields' => array( $this->sub( 'field_rep_g_x', 'x' ) ),
								),
							),
						),
						array(
							'key'     => 'field_flex',
							'name'    => 'flex',
							'label'   => 'Flex',
							'type'    => 'flexible_content',
							'layouts' => array(
								array(
									'key'        => 'layout_hero',
									'name'       => 'hero',
									'sub_fields' => array( $this->sub( 'field_flex_h', 'heading' ) ),
								),
								array(
									'key'        => 'layout_cards',
									'name'       => 'cards',
									'sub_fields' => array(
										$this->sub( 'field_flex_c', 'caption' ),
										array(
											'key'        => 'field_flex_r',
											'name'       => 'items',
											'_name'      => 'items',
											'label'      => 'Items',
											'type'       => 'repeater',
											'sub_fields' => array( $this->sub( 'field_flex_r_n', 'note' ) ),
										),
									),
								),
								// A DECLARED layout that declares no sub-fields of its own. Legal
								// in ACF and measured writing correctly on the bench against ACF
								// Pro 6.8.7. This is the case the layout check must NOT catch: an
								// undeclared layout and a declared-but-empty one look identical to
								// aafm_acf_sub_field_defs(), which is exactly why the declared
								// layout NAMES are read separately from the sub-field defs.
								array(
									'key'        => 'layout_blank',
									'name'       => 'blank',
									'sub_fields' => array(),
								),
							),
						),
						// Flexible content nested inside a group: the recursion path for the
						// layout check, and the shape that proves an offending row is carried back
						// OUT of a nested container instead of being lost at the depth it was
						// found. Confirmed a real ACF shape on the bench rather than a stub
						// invention - ACF wrote grp_inner_flex_0_txt for it with no plugin code in
						// the path.
						array(
							'key'        => 'field_gflex',
							'name'       => 'gflex',
							'label'      => 'Group holding flex',
							'type'       => 'group',
							'sub_fields' => array(
								array(
									'key'     => 'field_gflex_f',
									'name'    => 'inner',
									'_name'   => 'inner',
									'label'   => 'Inner flex',
									'type'    => 'flexible_content',
									'layouts' => array(
										array(
											'key'        => 'layout_inner',
											'name'       => 'inner_block',
											'sub_fields' => array( $this->sub( 'field_gflex_t', 'txt' ) ),
										),
									),
								),
							),
						),
						// A top-level clone with prefix_name off: the sub-field keeps its bare name.
						array(
							'key'         => 'field_cl0',
							'name'        => 'cl0',
							'label'       => 'Clone bare',
							'type'        => 'clone',
							'display'     => 'group',
							'prefix_name' => 0,
							'sub_fields'  => array( $this->sub( 'field_cl0_e', 'email' ) ),
						),
						// A prefixed clone: `name` and `_name` diverge, and ACF accepts a write
						// under `key` or `_name` only. Measured on ACF Pro 6.8.7: the sub-field's
						// KEY is not rewritten by acf_clone_field(), only its name.
						array(
							'key'         => 'field_cl1',
							'name'        => 'cl1',
							'label'       => 'Clone prefixed',
							'type'        => 'clone',
							'display'     => 'group',
							'prefix_name' => 1,
							'sub_fields'  => array( $this->sub( 'field_cl1_e', 'cl1_email', 'email' ) ),
						),
						// A container declaring no shape at all: nothing can be judged undeclared
						// against it, so it must fall through untouched.
						array(
							'key'        => 'field_noshape',
							'name'       => 'noshape',
							'label'      => 'No shape',
							'type'       => 'group',
							'sub_fields' => array(),
						),
						// Non-containers whose values are arrays: a link's structured return format
						// and a multi-value checkbox. Neither declares sub-fields and neither is
						// addressed the way a container is, so the walk must not descend into them.
						array(
							'key'   => 'field_link',
							'name'  => 'lnk',
							'_name' => 'lnk',
							'label' => 'Link',
							'type'  => 'link',
						),
						array(
							'key'   => 'field_chk',
							'name'  => 'chk',
							'_name' => 'chk',
							'label' => 'Checkbox',
							'type'  => 'checkbox',
						),
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
	 * Boot the ACF stubs with real-ACF container modelling on and register the write ability.
	 *
	 * The modelling flag is load-bearing here, not decoration. With it off the stub stores whatever
	 * the caller sent, verbatim, so an undeclared address round-trips and the defect this file
	 * exists for cannot be reproduced at all.
	 *
	 * @return int The post id to write against.
	 */
	private function boot(): int {
		$this->force_integration( 'acf' );
		$this->stub_acf( $this->config() );
		AcfStubStore::$model_container_rekeying = true;
		aafm_registry_cache_should_flush( true );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/acf-update-post-fields' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		$admin_id = $this->acting_as( 'administrator' );
		return (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
	}

	/**
	 * Every address shape the floor has been asked to judge, and the verdict it must reach.
	 *
	 * Each row is [ sent field map, verdict ]. The verdict has four states, and the last two are the
	 * reason there are more than two:
	 *
	 *   'accept'             the request succeeds and the values reach update_field().
	 *   'refuse-before-write' this floor rejects it and NOTHING reaches update_field(). The
	 *                        distinction from the row below is the entire defect.
	 *   'fail-after-write'   the request comes back an error AFTER the write landed. That is the
	 *                        defect's own shape, kept as a verdict of its own because an error and
	 *                        an empty AcfStubStore::$written are two different claims and only the
	 *                        pair says the request never ran.
	 *   'accept-by-key'      a sub-field addressed by its field KEY. ACF documents this form
	 *                        (/resources/add_row keys a whole row by sub-field keys) and writes the
	 *                        row for it, so it must succeed - and until the by-key fix it did NOT:
	 *                        the read-back re-keyed storage to sub-field NAMES while comparing
	 *                        against the sent KEY, which no re-keyed storage can hold, so the
	 *                        request came back an error with the data already written. Measured
	 *                        against real ACF free 6.3.6: a sub-field went from `BEFORE` to
	 *                        `AFTER-BY-KEY` on the call that returned WP_Error(aafm_error). Present
	 *                        in shipped 1.6.3, where the same re-keyer feeds the same comparison.
	 *
	 * THESE ROWS WERE `fail-after-write` AND THE FIX TURNED THEM RED, WHICH WAS THE HANDSHAKE. What
	 * they asserted then was that THIS floor is not what refuses a by-key address - by elimination,
	 * from the error code. That claim is not weakened by flipping them, it is made positively and
	 * more precisely: `accept-by-key` asserts the detector flags NOTHING for the exact address, by
	 * name, calling aafm_acf_unresolved_sub_addresses() directly. A wrong-reason pass in the other
	 * direction - the verify going blind and accepting everything - is what the flip newly risks,
	 * so these rows also assert the value physically reached storage under the sub-field's own key,
	 * and AcfContainerVerifyCorpusTest carries the by-key row whose storage disagrees and must
	 * still FAIL. Success alone would prove nothing at all here.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public function provide_address_shapes(): array {
		return array(
			// ---------------------------------------------------------------
			// MUST SUCCEED. Each of these is a way an agent legitimately
			// addresses a sub-field, and refusing any of them would be worse
			// than the defect being fixed.
			// ---------------------------------------------------------------
			'group by name'                         => array(
				array( 'field_grp' => array( 'alpha' => 'A' ) ),
				'accept',
			),
			// By-KEY addressing: this floor must stay silent, and it does. It now SUCCEEDS as ACF's
			// own documented row-by-field-keys form should; see the provider docblock for what
			// these rows assert instead of the error code they used to.
			'group by sub-field KEY'                => array(
				array( 'field_grp' => array( 'field_grp_a' => 'A' ) ),
				'accept-by-key',
			),
			'group, both sub-fields'                => array(
				array(
					'field_grp' => array(
						'alpha' => 'A',
						'beta'  => 'B',
					),
				),
				'accept',
			),
			'repeater row'                          => array(
				array( 'field_rep' => array( array( 'title' => 'T' ) ) ),
				'accept',
			),
			'repeater row, nested group'            => array(
				array(
					'field_rep' => array(
						array(
							'title' => 'T',
							'inner' => array( 'x' => 'X' ),
						),
					),
				),
				'accept',
			),
			// By KEY at BOTH depths: the nested container's own address and its sub-field's. A fix
			// that rewrote only the top level would leave this one failing.
			'repeater, nested group by KEY'         => array(
				array( 'field_rep' => array( array( 'field_rep_g' => array( 'field_rep_g_x' => 'X' ) ) ) ),
				'accept-by-key',
			),
			// The acf_fc_layout marker resolves to no sub-field by design. Flagging it would refuse
			// EVERY flexible-content write - the same trap ACF's `_layout_meta` row set for the
			// effective-key derivation next door.
			'flex row with layout marker'           => array(
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'hero',
							'heading'       => 'H',
						),
					),
				),
				'accept',
			),
			'flex, second layout'                   => array(
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'cards',
							'caption'       => 'C',
						),
					),
				),
				'accept',
			),
			'flex, two rows two layouts'            => array(
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'hero',
							'heading'       => 'H',
						),
						array(
							'acf_fc_layout' => 'cards',
							'caption'       => 'C',
						),
					),
				),
				'accept',
			),
			'flex, repeater nested in layout'       => array(
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'cards',
							'items'         => array( array( 'note' => 'N' ) ),
						),
					),
				),
				'accept',
			),
			// The layout check's over-block direction, and the rows that stop it becoming "refuse
			// any flexible-content row that looks unusual".
			//
			// A DECLARED layout with no sub-fields of its own. It reaches the layout check, passes
			// it because the NAME is declared, and then falls through the no-declared-shape
			// softening exactly as before. Measured writing correctly against real ACF Pro 6.8.7.
			'flex, declared layout with no subs'    => array(
				array( 'field_flex' => array( array( 'acf_fc_layout' => 'blank' ) ) ),
				'accept',
			),
			// A layout name carrying a TRAILING SPACE. Core's sanitize_text_field() trims it before
			// the floors ever see it, so the cleaned name resolves and the write must land - which
			// it does against real ACF, measured at the database. This is c07e520's rule reaching
			// the layout name: the floors judge the post-sanitize value, so a name that only
			// differs by a transformation the sanitizer performs is not a different layout. A check
			// written against the RAW marker would refuse this and break an ordinary write.
			'flex, layout name needing a trim'      => array(
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'hero ',
							'heading'       => 'H',
						),
					),
				),
				'accept',
			),
			// Flexible content nested in a group, written correctly. The must-succeed half of the
			// recursion rows below: the layout check has to reach this depth without refusing it.
			'flex nested in a group'                => array(
				array(
					'field_gflex' => array(
						'inner' => array(
							array(
								'acf_fc_layout' => 'inner_block',
								'txt'           => 'T',
							),
						),
					),
				),
				'accept',
			),
			'clone prefix=0 by bare name'           => array(
				array( 'field_cl0' => array( 'email' => 'e@example.test' ) ),
				'accept',
			),
			'clone prefix=1 by _name'               => array(
				array( 'field_cl1' => array( 'email' => 'e@example.test' ) ),
				'accept',
			),
			// A prefixed clone is where `name` and `_name` diverge, so the key must resolve to the
			// name ACF accepts on write (`_name`) and to the same name storage reads back under.
			'clone prefix=1 by sub-field KEY'       => array(
				array( 'field_cl1' => array( 'field_cl1_e' => 'e@example.test' ) ),
				'accept-by-key',
			),
			// A definition with no declared shape cannot say what is undeclared. Refusing a whole
			// write on no information would be far worse than the defect; this falls through to the
			// read-back verify exactly as it did before.
			// Refused, but by the read-back verify and not by this floor, and NOTHING of the
			// caller's reaches storage - measured against real ACF, where the post held only core's
			// own _pingme/_encloseme rows afterwards. So the failure it reports is a true one, and
			// the fall-through leaves that untouched rather than inventing a refusal on a shape it
			// cannot judge.
			'container with no declared shape'      => array(
				array( 'field_noshape' => array( 'whatever' => 'W' ) ),
				'fail-after-write',
			),
			// Non-containers whose value is an array. `url`/`title`/`target` are members of a link's
			// return format, not sub-fields, and a checkbox value is a plain list.
			'link structured array'                 => array(
				array(
					'field_link' => array(
						'url'    => 'https://example.test/',
						'title'  => 'T',
						'target' => '_blank',
					),
				),
				'accept',
			),
			'checkbox list value'                   => array(
				array( 'field_chk' => array( 'a', 'b' ) ),
				'accept',
			),
			'plain scalar field'                    => array(
				array( 'field_plain' => 'ok' ),
				'accept',
			),
			// A container sent a scalar: there are no addresses to judge.
			'container sent a scalar'               => array(
				array( 'field_grp' => 'not-a-map' ),
				'accept',
			),
			// The address carries an invisible character the sanitizer strips, so what actually
			// reaches update_field() is the declared name and the write lands normally. Judging
			// the RAW value instead would refuse a write that succeeds today - a false refusal
			// invented out of a difference the write itself never sees.
			'address with a stripped bidi char'     => array(
				array( 'field_grp' => array( "alpha\u{202E}" => 'A' ) ),
				'accept',
			),

			// ---------------------------------------------------------------
			// MUST REFUSE, and refuse BEFORE anything is written. Every one of
			// these is a shape where ACF writes the sub-fields it recognises
			// and ignores the address it does not - which is precisely how the
			// request came back an error with the data already stored.
			// ---------------------------------------------------------------
			'group, undeclared address'             => array(
				array(
					'field_grp' => array(
						'alpha'     => 'A',
						'nosuchsub' => 'X',
					),
				),
				'refuse-before-write',
			),
			'repeater, undeclared address'          => array(
				array(
					'field_rep' => array(
						array(
							'title' => 'T',
							'nope'  => 'X',
						),
					),
				),
				'refuse-before-write',
			),
			// Only the SECOND row carries it, so a walk that stops after row 0 goes red here.
			'repeater, second row only'             => array(
				array(
					'field_rep' => array(
						array( 'title' => 'T' ),
						array( 'nope' => 'X' ),
					),
				),
				'refuse-before-write',
			),
			'repeater, nested group address'        => array(
				array( 'field_rep' => array( array( 'inner' => array( 'nope' => 'X' ) ) ) ),
				'refuse-before-write',
			),
			// Per-layout resolution: `caption` is declared by the `cards` layout and is genuinely
			// undeclared in a `hero` row. ACF writes nothing for it.
			'flex, sub-field of other layout'       => array(
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'hero',
							'caption'       => 'C',
						),
					),
				),
				'refuse-before-write',
			),
			'flex, undeclared in its layout'        => array(
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'cards',
							'nope'          => 'X',
						),
					),
				),
				'refuse-before-write',
			),
			'flex, nested repeater address'         => array(
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'cards',
							'items'         => array( array( 'nope' => 'N' ) ),
						),
					),
				),
				'refuse-before-write',
			),
			'clone prefix=0, undeclared'            => array(
				array( 'field_cl0' => array( 'nosuch' => 'X' ) ),
				'refuse-before-write',
			),
			'clone prefix=1, undeclared'            => array(
				array( 'field_cl1' => array( 'nosuch' => 'X' ) ),
				'refuse-before-write',
			),
			// A second field in the map is enough: the refusal is per REQUEST, so a legitimate
			// field alongside an offending one must not be written either.
			'offending field beside a good one'     => array(
				array(
					'field_plain' => 'ok',
					'field_grp'   => array( 'nosuchsub' => 'X' ),
				),
				'refuse-before-write',
			),

			// ---------------------------------------------------------------
			// The layout marker. These are the WORST rows in this file, because
			// letting one through does not merely leave a write unwritten - it
			// DELETES the field's existing content and then reports failure.
			//
			// Measured at the database against real ACF Pro 6.8.7: a field
			// holding heading='BEFORE-F8' and body='BODY-BEFORE' was sent a
			// single row marked `no_such_layout`. ACF replaced the whole field
			// value, BOTH sub-field rows were deleted, the stored value became
			// one unusable layout name, and the call returned an error. The
			// agent is told the write failed while the previous content is
			// already gone, with no recovery path. Present in shipped 1.6.3,
			// whose floor pass opens with `unset( $raw )` and never looks at
			// the value at all.
			//
			// They carry their own verdict because they carry their own error
			// code. Sharing `refuse-before-write` would assert the wrong
			// refusal is acceptable, and the whole point of an early floor is
			// which one fired.
			// ---------------------------------------------------------------
			// The layout is named and the field does not declare it.
			'flex, undeclared layout'               => array(
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'no_such_layout',
							'heading'       => 'H',
						),
					),
				),
				'refuse-unknown-layout',
			),
			// The marker is absent entirely. ACF documents it as required
			// (/resources/rest-api: "arrays of layout objects with a required
			// 'acf_fc_layout' property"; every row of /resources/update_field's
			// flex example carries one). Measured, a marker-less row is dropped
			// and the field is left EMPTY, so this is the same data loss by a
			// second door. It is a separate row from the one above because the
			// two are separate INPUTS even though one predicate refuses both:
			// blinding that predicate to the named case alone leaves this row
			// as the thing that dies.
			'flex, no layout marker at all'         => array(
				array( 'field_flex' => array( array( 'heading' => 'H' ) ) ),
				'refuse-unknown-layout',
			),
			// The layout addressed by its layout KEY. Sub-FIELDS are addressable
			// by key and ACF documents that, which is why this row is not
			// obvious - but a layout is NOT. Measured with zero plugin code in
			// the path: update_field() given `acf_fc_layout => layout_hero`
			// deleted the sub-field row and stored the key as the layout name,
			// exactly like a nonsense string. So refusing it is not an
			// over-block, it is the same destruction under a plausible address.
			'flex, layout addressed by KEY'         => array(
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'layout_hero',
							'heading'       => 'H',
						),
					),
				),
				'refuse-unknown-layout',
			),
			// One good row and one bad one. The refusal is per REQUEST, so the
			// good row must not be written either - and this is the row that
			// would go red if the walk ever stopped at the first row it liked.
			'flex, second row bad'                  => array(
				array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'hero',
							'heading'       => 'H',
						),
						array(
							'acf_fc_layout' => 'no_such_layout',
							'caption'       => 'C',
						),
					),
				),
				'refuse-unknown-layout',
			),
			// A bad layout on a flexible-content field nested inside a group.
			// The offending row is found one level down, so this is what proves
			// it is carried back out of the recursion rather than discarded at
			// the depth it was seen. Driven end to end against real ACF too:
			// the address came back as `gflex.inner.0` and the nested value
			// survived untouched.
			'flex nested in a group, bad layout'    => array(
				array(
					'field_gflex' => array(
						'inner' => array(
							array(
								'acf_fc_layout' => 'no_such_layout',
								'txt'           => 'T',
							),
						),
					),
				),
				'refuse-unknown-layout',
			),
			// ---------------------------------------------------------------
			// The same failure at its third trigger, and the milder one: the
			// marker sent to a container that has no layouts at all. ACF
			// resolves a repeater's, group's or clone's row against that
			// container's own sub-fields and never reads a marker, so the key
			// names nothing. Measured against real ACF Pro 6.8.7: a group row
			// sent {alpha, acf_fc_layout} moved alpha from GROUP-BEFORE to
			// GROUP-AFTER and the request STILL returned an error, and a
			// repeater row behaved identically. No data is destroyed - the
			// caller's intent lands - but the request lies about it, which is
			// the same silent wrong answer R4-1 exists for.
			//
			// These are `refuse-before-write`, not the layout verdict: the key
			// is judged as the ordinary undeclared address it is, which is why
			// the skip is scoped rather than removed. Removing it outright
			// refuses every flexible-content write, and the accept rows above
			// are what catch that.
			// ---------------------------------------------------------------
			'group row carrying a layout marker'    => array(
				array(
					'field_grp' => array(
						'alpha'         => 'A',
						'acf_fc_layout' => 'bogus',
					),
				),
				'refuse-before-write',
			),
			'repeater row carrying a layout marker' => array(
				array(
					'field_rep' => array(
						array(
							'title'         => 'T',
							'acf_fc_layout' => 'bogus',
						),
					),
				),
				'refuse-before-write',
			),
		);
	}

	/**
	 * Drive one address shape through the real ability and check the verdict.
	 *
	 * A refusal is checked three ways over, and the SECOND is the one that matters. Before the fix
	 * this request ALSO returned a WP_Error - after the recognised sub-fields had already been
	 * written. So asserting the error alone proves nothing at all, which is exactly why the
	 * `fail-after-write` verdict exists as a state of its own: an error and an empty
	 * AcfStubStore::$written are two different claims, and only the pair together says the request
	 * never ran.
	 *
	 * @dataProvider provide_address_shapes
	 *
	 * @param array<string,mixed> $fields  The field map to write.
	 * @param string              $verdict 'accept', 'refuse-before-write' or 'fail-after-write'.
	 * @return void
	 */
	public function test_address_verdict( array $fields, string $verdict ): void {
		$post_id = $this->boot();

		AcfStubStore::$written = array();
		$result                = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => $fields,
			)
		);

		if ( 'refuse-before-write' === $verdict ) {
			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				'A write addressing a sub-field the definition does not declare must be refused.'
			);
			$this->assertSame(
				array(),
				AcfStubStore::$written,
				'The refusal must land BEFORE any write: this is the whole defect, not the error itself.'
			);
			$this->assertSame(
				'aafm_acf_unknown_sub_field',
				$result->get_error_code(),
				'The refusal must name its own reason so an agent can correct the address.'
			);
			return;
		}

		if ( 'refuse-unknown-layout' === $verdict ) {
			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				'A flexible-content row whose layout the field does not declare must be refused.'
			);
			// THE ASSERTION THAT MATTERS. Before the fix this request also returned a WP_Error,
			// so the error proves nothing on its own - it came back AFTER ACF had already replaced
			// the field value and deleted the row's sub-fields. An empty $written is the claim
			// that the destruction never happened.
			$this->assertSame(
				array(),
				AcfStubStore::$written,
				'The refusal must land BEFORE update_field(): reaching it is what destroys the existing rows.'
			);
			$this->assertSame(
				'aafm_acf_unknown_layout',
				$result->get_error_code(),
				'The refusal must name the layout as the cause, not read as an unknown sub-field or a generic failure.'
			);
			return;
		}

		if ( 'fail-after-write' === $verdict ) {
			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				'This shape still fails; the row exists to record WHICH floor is responsible.'
			);
			$this->assertNotSame(
				'aafm_acf_unknown_sub_field',
				$result->get_error_code(),
				'This floor must NOT be what refuses this shape - that would be the over-block it exists to avoid.'
			);
			return;
		}

		if ( 'accept-by-key' === $verdict ) {
			// The cause-discriminator these rows used to carry, made positively. It used to read
			// the error code and conclude "not this floor" by elimination; now it names the address
			// and asks the detector directly, which is the same claim with the guesswork removed.
			// It runs FIRST so a regression here reports "the floor started flagging a by-key
			// address" rather than a downstream mismatch that would send a reader to the wrong file.
			foreach ( $fields as $field_key => $sent ) {
				$def = acf_get_field( (string) $field_key );
				$this->assertIsArray( $def, 'The by-key fixture field must resolve.' );
				$this->assertSame(
					array(),
					aafm_acf_unresolved_sub_addresses( $def, $sent ),
					'A sub-field addressed by its KEY is an address ACF resolves, so this floor must flag nothing.'
				);
			}

			$this->assertNotInstanceOf(
				WP_Error::class,
				$result,
				$result instanceof WP_Error
					? 'ACF documents addressing a sub-field by its field key and writes the row for it, so the write must not report failure. Got: ' . $result->get_error_code()
					: ''
			);

			// Success is NOT the assertion. The risk this flip introduces is the opposite of the
			// defect - a verify that has gone blind and accepts anything - so prove the value
			// physically landed, in the RAW store, under the sub-field's own key, with no
			// production helper on the expectation side. The read-back's own re-keying is exactly
			// what is under test here and cannot be the thing that certifies it.
			$this->assertNotSame(
				array(),
				AcfStubStore::$written,
				'An accepted write must actually reach update_field().'
			);
			foreach ( $fields as $field_key => $sent ) {
				$this->assertStoredUnderSentAddress( (string) $field_key, $sent, $post_id );
			}
			return;
		}

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			$result instanceof WP_Error
				? 'An ordinary ACF write must not be refused: a wrong refusal is its own defect. Got: ' . $result->get_error_code()
				: ''
		);
		$this->assertNotSame(
			array(),
			AcfStubStore::$written,
			'An accepted write must actually reach update_field(); "refuse nothing, write nothing" is not success.'
		);
	}

	/**
	 * Assert every leaf of a by-KEY send physically reached the raw store, address for address.
	 *
	 * Deliberately does NOT call aafm_acf_rekey_stored_to_names() or the projection. Those two are
	 * exactly what the by-key fix changed, so using them to certify it would be a test that agrees
	 * with the code by construction. The stub writes a container row keyed by each sub-field's own
	 * `key` (AcfStubStore::rekey_row), so for a send addressed by KEY the sent address IS the
	 * storage address and the walk is a literal one.
	 *
	 * That equivalence is the row's premise rather than a coincidence, so it is asserted: every
	 * address walked must be a declared sub-field KEY. A by-NAME row given this verdict by mistake
	 * fails here, by name, instead of quietly asserting against the wrong storage address.
	 *
	 * @param string $field_key Top-level field key.
	 * @param mixed  $sent      The value sent for it.
	 * @param int    $post_id   Object selector.
	 * @return void
	 */
	private function assertStoredUnderSentAddress( string $field_key, $sent, int $post_id ): void {
		$def = acf_get_field( $field_key );
		$this->assertIsArray( $def, 'The by-key fixture field must resolve.' );
		$this->assertIsArray( $sent, 'A by-key row sends a container value.' );
		$this->assertLandedUnderKeys( AcfStubStore::value( $field_key, $post_id ), $sent, $def, $field_key );
	}

	/**
	 * The recursion behind assertStoredUnderSentAddress().
	 *
	 * @param mixed               $stored Raw stored value at this depth, keyed by sub-field key.
	 * @param mixed               $sent   Sent value at this depth.
	 * @param array<string,mixed> $def    The container definition for this depth.
	 * @param string              $path   Human-readable path for the failure message.
	 * @return void
	 */
	private function assertLandedUnderKeys( $stored, $sent, array $def, string $path ): void {
		$this->assertIsArray( $stored, sprintf( 'Storage must hold a container at %s.', $path ) );

		// A repeater or flexible-content value is a list of rows; a group or clone is one flat map.
		if ( array_keys( $sent ) === range( 0, count( $sent ) - 1 ) ) {
			foreach ( $sent as $index => $row ) {
				$this->assertArrayHasKey( $index, $stored, sprintf( 'Storage is missing row %s/%d.', $path, (int) $index ) );
				$this->assertLandedUnderKeys( $stored[ $index ], $row, $def, $path . '/' . (int) $index );
			}
			return;
		}

		$layout   = isset( $sent['acf_fc_layout'] ) ? (string) $sent['acf_fc_layout'] : '';
		$sub_defs = array();
		foreach ( aafm_acf_sub_field_defs( $def, $layout ) as $sub ) {
			$sub_defs[ (string) $sub['key'] ] = $sub;
		}

		foreach ( $sent as $address => $value ) {
			if ( 'acf_fc_layout' === $address ) {
				continue;
			}
			$address = (string) $address;
			$this->assertArrayHasKey(
				$address,
				$sub_defs,
				sprintf( 'This verdict is for addresses that ARE sub-field keys; %s/%s is not one.', $path, $address )
			);
			$this->assertArrayHasKey(
				$address,
				$stored,
				sprintf( 'The write did not land under %s/%s.', $path, $address )
			);
			if ( is_array( $value ) ) {
				$this->assertLandedUnderKeys( $stored[ $address ], $value, $sub_defs[ $address ], $path . '/' . $address );
				continue;
			}
			$this->assertSame(
				(string) $value,
				(string) $stored[ $address ],
				sprintf( 'Storage must hold the value that was sent at %s/%s.', $path, $address )
			);
		}
	}

	/**
	 * The defect itself, pinned at the outcome rather than at the verdict.
	 *
	 * The provider row above says the request is refused and nothing is written. This says what
	 * that means in storage: the sub-field the caller DID declare correctly keeps its previous
	 * value, because the request never ran. Before the fix this exact call returned an error and
	 * left `alpha` holding the new value - reproduced end to end against real ACF, where a
	 * sub-field went from `BEFORE` to `AFTER-WRITE-LANDED` on the call that reported the failure.
	 *
	 * @return void
	 */
	public function test_the_landed_write_no_longer_lands(): void {
		$post_id = $this->boot();

		$seeded = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_grp' => array( 'alpha' => 'BEFORE' ) ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $seeded, 'The seeding write is an ordinary one and must succeed.' );
		$this->assertSame(
			'BEFORE',
			AcfStubStore::value( 'field_grp', $post_id )['field_grp_a'] ?? null,
			'Storage holds the seeded value, keyed the way real ACF keys it.'
		);

		$result = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array(
					'field_grp' => array(
						'alpha'     => 'AFTER-WRITE-LANDED',
						'nosuchsub' => 'X',
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'The request is refused.' );
		$this->assertSame(
			'BEFORE',
			AcfStubStore::value( 'field_grp', $post_id )['field_grp_a'] ?? null,
			'A refused request must leave storage exactly as it was: reporting failure while the write lands is the defect.'
		);
	}

	/**
	 * The message hands the caller its own address back, so the error is actionable.
	 *
	 * @return void
	 */
	public function test_the_refusal_names_the_address_the_caller_sent(): void {
		$post_id = $this->boot();

		$result = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'cards',
							'items'         => array( array( 'nope' => 'N' ) ),
						),
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString(
			'flex.0.items.0.nope',
			$result->get_error_message(),
			'The message must locate the address inside the structure the caller sent.'
		);
	}

	/**
	 * The bad-layout channel, exercised directly, so the ROW that offended is named.
	 *
	 * The outcome rows in the provider prove a refusal happens and that nothing was written; they
	 * cannot show which row produced it, and a walk that flagged every flexible-content row would
	 * satisfy them just as well. These name the address, and the accept cases at the end are what
	 * make the flagging discriminate rather than blanket.
	 *
	 * @return void
	 */
	public function test_the_unresolvable_layout_rows_are_named_by_address(): void {
		$this->boot();
		$defs = array();
		foreach ( $this->config()['groups'][0]['fields'] as $field ) {
			$defs[ (string) $field['key'] ] = $field;
		}

		$cases = array(
			// [ field key, sent value, expected offending row addresses ]
			array( 'field_flex', array( array( 'acf_fc_layout' => 'nosuch' ) ), array( 'flex.0' ) ),
			array( 'field_flex', array( array( 'heading' => 'H' ) ), array( 'flex.0' ) ),
			// The row INDEX has to be the offending row's own position, not zero and not the first
			// row's. A walk that stopped early, or that reported a fixed index, dies here.
			array(
				'field_flex',
				array(
					array(
						'acf_fc_layout' => 'hero',
						'heading'       => 'H',
					),
					array( 'acf_fc_layout' => 'nosuch' ),
				),
				array( 'flex.1' ),
			),
			array(
				'field_flex',
				array(
					array( 'acf_fc_layout' => 'nosuch' ),
					array( 'acf_fc_layout' => 'alsonot' ),
				),
				array( 'flex.0', 'flex.1' ),
			),
			// Carried back out of a nested container rather than lost at depth.
			array(
				'field_gflex',
				array( 'inner' => array( array( 'acf_fc_layout' => 'nosuch' ) ) ),
				array( 'gflex.inner.0' ),
			),
			// And the cases that must stay silent, or the rows above would be satisfied by a
			// detector that simply flags every flexible-content row it sees.
			array(
				'field_flex',
				array(
					array(
						'acf_fc_layout' => 'hero',
						'heading'       => 'H',
					),
				),
				array(),
			),
			array( 'field_flex', array( array( 'acf_fc_layout' => 'blank' ) ), array() ),
			array( 'field_gflex', array( 'inner' => array( array( 'acf_fc_layout' => 'inner_block' ) ) ), array() ),
			// A container with no layouts at all is judged on this ground by nothing.
			array( 'field_rep', array( array( 'title' => 'T' ) ), array() ),
			array( 'field_grp', array( 'alpha' => 'A' ), array() ),
		);

		foreach ( $cases as $case ) {
			list( $field_key, $sent, $expected ) = $case;
			$bad                                 = array();
			aafm_acf_unresolved_sub_addresses( $defs[ $field_key ], $sent, '', $bad );
			$this->assertSame(
				$expected,
				$bad,
				sprintf( '%s: the rows whose layout cannot resolve are not the ones reported.', $field_key )
			);
		}
	}

	/**
	 * A row whose layout cannot resolve reports THAT, and does not also report its sub-fields.
	 *
	 * Added while chasing a surviving mutant rather than writing it off. Removing the early return
	 * after a row is flagged left the whole corpus green at full assertion count, because for an
	 * undeclared layout name the no-shape softening happens to swallow the rest anyway. The one
	 * shape where it does not is a row with NO marker carrying an address no layout declares: the
	 * marker-less lookup falls back to every layout, so without the early return that address is
	 * reported as an unknown sub-field alongside the real problem. Which layout a row belongs to
	 * decides what its sub-fields even are, so complaining about them first is telling the caller
	 * to fix the wrong thing.
	 *
	 * @return void
	 */
	public function test_a_row_with_an_unresolvable_layout_is_not_also_reported_sub_field_by_sub_field(): void {
		$this->boot();
		$defs = array();
		foreach ( $this->config()['groups'][0]['fields'] as $field ) {
			$defs[ (string) $field['key'] ] = $field;
		}

		$bad        = array();
		$unresolved = aafm_acf_unresolved_sub_addresses(
			$defs['field_flex'],
			array( array( 'heading_typo' => 'X' ) ),
			'',
			$bad
		);

		$this->assertSame( array( 'flex.0' ), $bad, 'The row itself is what could not be resolved.' );
		$this->assertSame(
			array(),
			$unresolved,
			'Once the row has no resolvable layout, its addresses are not separately reported.'
		);
	}

	/**
	 * The layout refusal locates the offending row inside the structure the caller sent, and does
	 * not read as an unknown sub-field.
	 *
	 * @return void
	 */
	public function test_the_layout_refusal_names_the_row_and_its_own_cause(): void {
		$post_id = $this->boot();

		$result = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array(
					'field_flex' => array(
						array(
							'acf_fc_layout' => 'hero',
							'heading'       => 'H',
						),
						array(
							'acf_fc_layout' => 'no_such_layout',
							'heading'       => 'H',
						),
					),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aafm_acf_unknown_layout', $result->get_error_code() );
		$this->assertStringContainsString(
			'flex.1',
			$result->get_error_message(),
			'The message must locate the offending row, so an agent can fix that row rather than the request.'
		);
		$this->assertStringNotContainsString(
			'flex.0',
			$result->get_error_message(),
			'The row that was fine must not be named, or the message stops telling the caller anything.'
		);
	}

	/**
	 * The message is bounded: a caller sending many undeclared addresses gets a readable list, not
	 * an unbounded echo of its own request.
	 *
	 * Added under the mutation pass. Removing the cap left the whole corpus green, so the bound was
	 * carried by nothing at all.
	 *
	 * @return void
	 */
	public function test_the_refusal_message_is_bounded(): void {
		$post_id = $this->boot();

		$row = array();
		foreach ( range( 1, 9 ) as $i ) {
			$row[ 'nosuch' . $i ] = 'X';
		}
		$result = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_grp' => $row ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$named = 0;
		foreach ( range( 1, 9 ) as $i ) {
			$named += false === strpos( $result->get_error_message(), 'grp.nosuch' . $i ) ? 0 : 1;
		}
		$this->assertSame( 5, $named, 'At most five addresses are named, and the cap is what decides that.' );
	}

	/**
	 * The detector, exercised directly against the definitions above.
	 *
	 * The provider rows judge the OUTCOME, which is the property that matters, but they cannot show
	 * WHICH address produced a verdict - a refusal for the wrong reason reads exactly like a
	 * refusal for the right one. These rows name the addresses.
	 *
	 * @return void
	 */
	public function test_the_flagged_addresses_are_the_ones_acf_ignores(): void {
		$this->boot();
		$defs = array();
		foreach ( $this->config()['groups'][0]['fields'] as $field ) {
			$defs[ (string) $field['key'] ] = $field;
		}

		$cases = array(
			// [ field key, sent value, expected flagged addresses ]
			array( 'field_grp', array( 'alpha' => 'A' ), array() ),
			array( 'field_grp', array( 'field_grp_a' => 'A' ), array() ),
			array( 'field_grp', array( 'nosuchsub' => 'X' ), array( 'grp.nosuchsub' ) ),
			array(
				'field_grp',
				array(
					'alpha' => 'A',
					'two'   => 'X',
					'three' => 'Y',
				),
				array( 'grp.two', 'grp.three' ),
			),
			array( 'field_rep', array( array( 'title' => 'T' ) ), array() ),
			array( 'field_rep', array( array( 'nope' => 'X' ) ), array( 'rep.0.nope' ) ),
			// The layout marker sent to a container that has no layouts. It is skipped only for
			// flexible content; anywhere else it is an ordinary address naming nothing, and these
			// two rows are what say so by name rather than by outcome.
			array(
				'field_grp',
				array(
					'alpha'         => 'A',
					'acf_fc_layout' => 'bogus',
				),
				array( 'grp.acf_fc_layout' ),
			),
			array(
				'field_rep',
				array(
					array(
						'title'         => 'T',
						'acf_fc_layout' => 'bogus',
					),
				),
				array( 'rep.0.acf_fc_layout' ),
			),

			array(
				'field_rep',
				array( array( 'title' => 'T' ), array( 'nope' => 'X' ) ),
				array( 'rep.1.nope' ),
			),
			array(
				'field_rep',
				array( array( 'inner' => array( 'nope' => 'X' ) ) ),
				array( 'rep.0.inner.nope' ),
			),
			array(
				'field_flex',
				array(
					array(
						'acf_fc_layout' => 'hero',
						'heading'       => 'H',
					),
				),
				array(),
			),
			array(
				'field_flex',
				array(
					array(
						'acf_fc_layout' => 'hero',
						'caption'       => 'C',
					),
				),
				array( 'flex.0.caption' ),
			),
			array(
				'field_flex',
				array(
					array(
						'acf_fc_layout' => 'cards',
						'items'         => array( array( 'nope' => 'N' ) ),
					),
				),
				array( 'flex.0.items.0.nope' ),
			),
			array( 'field_cl0', array( 'email' => 'e' ), array() ),
			array( 'field_cl0', array( 'nosuch' => 'X' ), array( 'cl0.nosuch' ) ),
			array( 'field_cl1', array( 'email' => 'e' ), array() ),
			array( 'field_cl1', array( 'field_cl1_e' => 'e' ), array() ),
			array( 'field_cl1', array( 'nosuch' => 'X' ), array( 'cl1.nosuch' ) ),
			array( 'field_noshape', array( 'whatever' => 'W' ), array() ),
			array(
				'field_link',
				array(
					'url'   => 'https://example.test/',
					'title' => 'T',
				),
				array(),
			),
			array( 'field_chk', array( 'a', 'b' ), array() ),
			array( 'field_plain', 'x', array() ),
			array( 'field_grp', 'x', array() ),
		);

		foreach ( $cases as $case ) {
			list( $field_key, $sent, $expected ) = $case;
			$flagged                             = aafm_acf_unresolved_sub_addresses( $defs[ $field_key ], $sent );
			sort( $flagged );
			sort( $expected );
			$this->assertSame(
				$expected,
				$flagged,
				sprintf( 'Flagged addresses for %s must be exactly the ones ACF ignores.', $field_key )
			);
		}
	}

	/**
	 * The two walks over the caller's structure must agree, or they will drift apart.
	 *
	 * One walk, aafm_acf_effective_meta_keys(), derives the meta keys ACF will write from the sent
	 * value; the other, aafm_acf_unresolved_sub_addresses(), walks the same value to find the
	 * addresses ACF will ignore. They share every resolution rule through the same helpers, but they are still two
	 * walks, and two walks that can disagree are how this project's documented "fixed at one call
	 * site, never swept" archetype starts. So pin the relationship rather than trusting it: an
	 * address the derivation turns into a meta key must NOT be flagged, and an address the detector
	 * flags must contribute NO key to the derivation.
	 *
	 * Attribution is the difficulty, and the naive version of this test got it wrong. Asking "did
	 * the derivation emit anything beyond the field's own name" credits the wrong address as soon
	 * as the path runs through a resolved container: `rep.0.inner.nope` makes the derivation emit
	 * `rep_0_inner`, which `inner` earned and `nope` did not. So each probe carries the same value
	 * twice, once with the address under test and once without it, and the address is judged by
	 * the DIFFERENCE it makes. That is exact, and it is attributable to one address.
	 *
	 * @return void
	 */
	public function test_the_key_derivation_and_the_detector_agree(): void {
		$this->boot();
		$defs = array();
		foreach ( $this->config()['groups'][0]['fields'] as $field ) {
			$defs[ (string) $field['key'] ] = $field;
		}

		// [ field key, value WITH the address, the same value WITHOUT it, a label ].
		$probes = array(
			array(
				'field_grp',
				array(
					'alpha' => 'A',
					'beta'  => 'B',
				),
				array( 'beta' => 'B' ),
				'grp.alpha (name)',
				'derivation',
			),
			array(
				'field_grp',
				array(
					'field_grp_a' => 'A',
					'beta'        => 'B',
				),
				array( 'beta' => 'B' ),
				'grp.field_grp_a (key)',
				'derivation',
			),
			array(
				'field_grp',
				array(
					'nosuchsub' => 'X',
					'beta'      => 'B',
				),
				array( 'beta' => 'B' ),
				'grp.nosuchsub',
				'detector',
			),
			array(
				'field_rep',
				array(
					array(
						'title' => 'T',
						'inner' => array( 'x' => 'X' ),
					),
				),
				array( array( 'inner' => array( 'x' => 'X' ) ) ),
				'rep.0.title',
				'derivation',
			),
			array(
				'field_rep',
				array(
					array(
						'title' => 'T',
						'nope'  => 'X',
					),
				),
				array( array( 'title' => 'T' ) ),
				'rep.0.nope',
				'detector',
			),
			array(
				'field_rep',
				array( array( 'inner' => array( 'x' => 'X' ) ) ),
				array( array( 'inner' => array() ) ),
				'rep.0.inner.x',
				'derivation',
			),
			array(
				'field_rep',
				array(
					array(
						'inner' => array(
							'x'    => 'X',
							'nope' => 'N',
						),
					),
				),
				array( array( 'inner' => array( 'x' => 'X' ) ) ),
				'rep.0.inner.nope',
				'detector',
			),
			array(
				'field_flex',
				array(
					array(
						'acf_fc_layout' => 'hero',
						'heading'       => 'H',
					),
				),
				array( array( 'acf_fc_layout' => 'hero' ) ),
				'flex.0.heading',
				'derivation',
			),
			array(
				'field_flex',
				array(
					array(
						'acf_fc_layout' => 'hero',
						'caption'       => 'C',
					),
				),
				array( array( 'acf_fc_layout' => 'hero' ) ),
				'flex.0.caption (wrong layout)',
				'detector',
			),
			array(
				'field_flex',
				array(
					array(
						'acf_fc_layout' => 'cards',
						'items'         => array( array( 'note' => 'N' ) ),
					),
				),
				array(
					array(
						'acf_fc_layout' => 'cards',
						'items'         => array( array() ),
					),
				),
				'flex.0.items.0.note',
				'derivation',
			),
			array(
				'field_flex',
				array(
					array(
						'acf_fc_layout' => 'cards',
						'items'         => array(
							array(
								'note' => 'N',
								'nope' => 'X',
							),
						),
					),
				),
				array(
					array(
						'acf_fc_layout' => 'cards',
						'items'         => array( array( 'note' => 'N' ) ),
					),
				),
				'flex.0.items.0.nope',
				'detector',
			),

			// The layout marker on a container with no layouts: the detector claims it and the
			// derivation must stay silent, because ACF writes no meta key for it on any type.
			array(
				'field_grp',
				array(
					'alpha'         => 'A',
					'acf_fc_layout' => 'bogus',
				),
				array( 'alpha' => 'A' ),
				'grp.acf_fc_layout',
				'detector',
			),
			array(
				'field_rep',
				array(
					array(
						'title'         => 'T',
						'acf_fc_layout' => 'bogus',
					),
				),
				array( array( 'title' => 'T' ) ),
				'rep.0.acf_fc_layout',
				'detector',
			),
			array( 'field_cl0', array( 'email' => 'e' ), array(), 'cl0.email', 'derivation' ),
			array( 'field_cl0', array( 'nosuch' => 'X' ), array(), 'cl0.nosuch', 'detector' ),
			array( 'field_cl1', array( 'email' => 'e' ), array(), 'cl1.email (_name)', 'derivation' ),
			array( 'field_cl1', array( 'field_cl1_e' => 'e' ), array(), 'cl1.field_cl1_e (key)', 'derivation' ),
			array( 'field_cl1', array( 'nosuch' => 'X' ), array(), 'cl1.nosuch', 'detector' ),
			// The no-shape container: both walks say nothing, which IS agreement. Listed rather
			// than exempted, so the fall-through cannot quietly become a disagreement.
			array( 'field_noshape', array( 'whatever' => 'W' ), array(), 'noshape.whatever', 'neither' ),
			array(
				'field_link',
				array(
					'url'   => 'https://example.test/',
					'title' => 'T',
				),
				array( 'url' => 'https://example.test/' ),
				'lnk.title (not a sub-field)',
				'neither',
			),
		);

		$seen = array(
			'derivation' => 0,
			'detector'   => 0,
			'neither'    => 0,
		);
		foreach ( $probes as $probe ) {
			list( $field_key, $with, $without, $label, $expected ) = $probe;
			$def = $defs[ $field_key ];

			$keys_with    = aafm_acf_effective_meta_keys( $def, $with );
			$keys_without = aafm_acf_effective_meta_keys( $def, $without );
			sort( $keys_with );
			sort( $keys_without );
			$derives = $keys_with !== $keys_without;

			$flags = count( aafm_acf_unresolved_sub_addresses( $def, $with ) )
				!== count( aafm_acf_unresolved_sub_addresses( $def, $without ) );

			// The invariant, stated as one claimant per address. "Never both" alone would be
			// satisfied by a detector that flags nothing, so the claimant is named instead.
			$actual = $derives && $flags ? 'both' : ( $derives ? 'derivation' : ( $flags ? 'detector' : 'neither' ) );
			$this->assertSame(
				$expected,
				$actual,
				sprintf( '%s: expected the %s to claim this address, got %s. The walks have drifted.', $label, $expected, $actual )
			);
			++$seen[ $expected ];
		}

		// Both walks must have done real work here, or the per-probe assertion above holds
		// vacuously: a derivation that derives nothing, or a detector that flags nothing, would
		// still satisfy every 'neither' row.
		$this->assertSame(
			array(
				'derivation' => 9,
				'detector'   => 9,
				'neither'    => 2,
			),
			$seen,
			'The agreement corpus must not shrink or lose either side of the comparison.'
		);
	}

	/**
	 * The definitions above are only worth anything if they really carry sub-fields.
	 *
	 * A fixture whose containers declare nothing would send every MUST-SUCCEED row through the
	 * no-shape fall-through and every MUST-REFUSE row would stop refusing - so the succeed half
	 * would pass while proving nothing at all. Anti-vacuity applied to the fixture itself.
	 *
	 * @return void
	 */
	public function test_the_fixture_declares_sub_fields_or_this_corpus_proves_nothing(): void {
		$counts = array();
		foreach ( $this->config()['groups'][0]['fields'] as $field ) {
			$key = (string) $field['key'];
			if ( isset( $field['layouts'] ) ) {
				$total = 0;
				foreach ( (array) $field['layouts'] as $layout ) {
					$total += count( (array) $layout['sub_fields'] );
				}
				$counts[ $key ] = $total;
				continue;
			}
			if ( isset( $field['sub_fields'] ) ) {
				$counts[ $key ] = count( (array) $field['sub_fields'] );
			}
		}

		$this->assertSame(
			array(
				'field_grp'     => 2,
				'field_rep'     => 2,
				'field_flex'    => 3,
				'field_gflex'   => 1,
				'field_cl0'     => 1,
				'field_cl1'     => 1,
				'field_noshape' => 0,
			),
			$counts,
			'Every container in the fixture must still declare the sub-fields its rows depend on.'
		);

		// The sub-field count above cannot see the `blank` layout, because a layout declaring no
		// sub-fields contributes zero to it. That layout is the entire must-succeed case for the
		// layout check, so the declared layout NAMES are pinned separately or the fixture could
		// lose it silently.
		$this->assertSame(
			array( 'hero', 'cards', 'blank' ),
			aafm_acf_declared_layout_names( $this->config()['groups'][0]['fields'][2] ),
			'The flexible-content fixture must keep declaring an empty layout as well as full ones.'
		);
	}
}
