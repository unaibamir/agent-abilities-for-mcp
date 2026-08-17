<?php
/**
 * The ACF write-verify corpus: every container shape the read-back comparison has been asked to
 * judge, and the verdict it must reach for each.
 *
 * ROWS IN THIS FILE ARE APPEND-ONLY. NEVER DELETE ONE.
 *
 * Same reasoning as the replace-in-post and settings-redaction corpora next door, and the same
 * failure behind it. This comparison has been reshaped several times - normalised for ACF's storage
 * typing, taught to read the raw value instead of the formatted one, taught to re-key container
 * sub-fields, taught to recurse into nested containers - and every pass pinned only the shape the
 * round in front of it had found. A traffic simulation then drove the one shape nobody had pinned,
 * a repeater row carrying some of its sub-fields rather than all of them, and found the ability
 * reporting failure on a write that had persisted correctly. That defect shipped in 1.6.3 and
 * earlier: an agent told the write failed retries it, or reports failure to a user, while the data
 * is already in wp_postmeta.
 *
 * So this file holds the union. Every row records where it came from, and both directions are
 * pinned together, because a verify that only ever proves it accepts good writes is half a test -
 * over-acceptance would trade a false failure for a false success, which is the worse of the two.
 * The rows driven through AcfStubStore::$read_override are the ones that hold that second half: the
 * write "succeeds" and storage comes back holding something else, so only the read-back can catch
 * it. If you are here to change the comparison: add rows, do not remove them.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\AcfStubStore;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\TestCase;
use WP_Error;

final class AcfContainerVerifyCorpusTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		// The write abilities audit to the activity log; without its table every test here is risky.
		aafm_install_activity_log();
		aafm_clear_activity_log();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * An eight-sub-field repeater, the shape the traffic simulation drove on the live clone: three
	 * text-ish sub-fields, a true_false with a default, a select whose unset read-back is false, and
	 * a wysiwyg. Deliberately wide, because row width is exactly what the shipped comparison could
	 * not handle.
	 *
	 * @return array<string,mixed> The field-group config for stub_acf().
	 */
	private function repeater_config(): array {
		return array(
			'groups' => array(
				array(
					'key'    => 'group_forms',
					'title'  => 'Forms',
					'fields' => array(
						array(
							'key'        => 'field_emails',
							'name'       => 'emails',
							'label'      => 'Emails',
							'type'       => 'repeater',
							'sub_fields' => array(
								array(
									'key'  => 'field_email_name',
									'name' => 'name',
									'type' => 'text',
								),
								array(
									'key'           => 'field_email_active',
									'name'          => 'active',
									'type'          => 'true_false',
									'default_value' => 1,
								),
								array(
									'key'  => 'field_email_recipient_type',
									'name' => 'recipient_type',
									'type' => 'radio',
								),
								array(
									// An unset select reads back false on real ACF, not ''.
									'key'           => 'field_email_recipient_field',
									'name'          => 'recipient_field',
									'type'          => 'select',
									'default_value' => false,
								),
								array(
									'key'  => 'field_email_recipient_custom',
									'name' => 'recipient_custom',
									'type' => 'text',
								),
								array(
									'key'  => 'field_email_from',
									'name' => 'from',
									'type' => 'text',
								),
								array(
									'key'  => 'field_email_subject',
									'name' => 'subject',
									'type' => 'text',
								),
								array(
									'key'  => 'field_email_content',
									'name' => 'content',
									'type' => 'wysiwyg',
								),
							),
						),
						array(
							'key'   => 'field_headline',
							'name'  => 'headline',
							'label' => 'Headline',
							'type'  => 'text',
						),
					),
				),
			),
		);
	}

	/**
	 * A group (flat container) beside a flexible-content field whose layout nests a repeater, so the
	 * partial-write question is asked of every container family and at two depths.
	 *
	 * @return array<string,mixed> The field-group config for stub_acf().
	 */
	private function nested_config(): array {
		return array(
			'groups' => array(
				array(
					'key'    => 'group_layout',
					'title'  => 'Layout',
					'fields' => array(
						array(
							'key'        => 'field_meta',
							'name'       => 'meta',
							'label'      => 'Meta',
							'type'       => 'group',
							'sub_fields' => array(
								array(
									'key'  => 'field_meta_label',
									'name' => 'label',
									'type' => 'text',
								),
								array(
									'key'  => 'field_meta_url',
									'name' => 'url',
									'type' => 'text',
								),
								array(
									'key'  => 'field_meta_note',
									'name' => 'note',
									'type' => 'text',
								),
							),
						),
						array(
							'key'     => 'field_sections',
							'name'    => 'sections',
							'label'   => 'Sections',
							'type'    => 'flexible_content',
							'layouts' => array(
								array(
									'key'        => 'layout_section',
									'name'       => 'section',
									'sub_fields' => array(
										array(
											'key'  => 'field_section_heading',
											'name' => 'heading',
											'type' => 'text',
										),
										array(
											'key'  => 'field_section_note',
											'name' => 'note',
											'type' => 'text',
										),
										array(
											'key'        => 'field_section_items',
											'name'       => 'items',
											'type'       => 'repeater',
											'sub_fields' => array(
												array(
													'key'  => 'field_item_label',
													'name' => 'label',
													'type' => 'text',
												),
												array(
													'key'  => 'field_item_href',
													'name' => 'href',
													'type' => 'text',
												),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Three clone fields covering every way ACF names a cloned sub-field, because the naming is the
	 * whole difficulty with this type and only one of the three shapes was ever exercised.
	 *
	 * A clone's sub-field carries THREE names and they are not always the same string. Measured on
	 * the ACF Pro 6.8.7 in this repo's wp/ tree, by calling acf_clone_field() on a validated field
	 * with nothing written to the database, for a sub-field named `email` cloned into a clone named
	 * `contact`:
	 *
	 *   display=group,    prefix_name=0 -> name `email`,         _name `email`,         __name `email`
	 *   display=group,    prefix_name=1 -> name `contact_email`, _name `email`,         __name `email`
	 *   display=seamless, prefix_name=1 -> name `contact_email`, _name `contact_email`, __name `email`
	 *
	 * The middle row is the one that shipped broken. ACF accepts a write under `key` or `_name` and
	 * nothing else (class-acf-field-clone.php:551-561) and hands the raw value back keyed by `key`
	 * (:~440), while this plugin re-keyed storage to `name` - so an agent wrote `email`, the value
	 * landed in the database, storage came back as `contact_email`, the projection found no `email`
	 * key to project onto and abandoned, and the ability reported failure on a write that worked.
	 * Unlike the repeater case it does not need a partial row: for a prefixed clone EVERY write
	 * false-failed.
	 *
	 * @return array<string,mixed> The field-group config for stub_acf().
	 */
	private function clone_config(): array {
		return array(
			'groups' => array(
				array(
					'key'    => 'group_clones',
					'title'  => 'Clones',
					'fields' => array(
						array(
							'key'         => 'field_contact',
							'name'        => 'contact',
							'label'       => 'Contact',
							'type'        => 'clone',
							'display'     => 'group',
							'prefix_name' => 1,
							'sub_fields'  => array(
								array(
									'key'    => 'field_clone_email',
									'name'   => 'contact_email',
									'_name'  => 'email',
									'__name' => 'email',
									'type'   => 'text',
								),
								array(
									'key'    => 'field_clone_phone',
									'name'   => 'contact_phone',
									'_name'  => 'phone',
									'__name' => 'phone',
									'type'   => 'text',
								),
								array(
									'key'    => 'field_clone_note',
									'name'   => 'contact_note',
									'_name'  => 'note',
									'__name' => 'note',
									'type'   => 'text',
								),
							),
						),
						array(
							'key'         => 'field_plainclone',
							'name'        => 'plain',
							'label'       => 'Plain clone',
							'type'        => 'clone',
							'display'     => 'group',
							'prefix_name' => 0,
							'sub_fields'  => array(
								array(
									'key'    => 'field_plain_email',
									'name'   => 'email',
									'_name'  => 'email',
									'__name' => 'email',
									'type'   => 'text',
								),
								array(
									'key'    => 'field_plain_phone',
									'name'   => 'phone',
									'_name'  => 'phone',
									'__name' => 'phone',
									'type'   => 'text',
								),
							),
						),
						array(
							'key'         => 'field_seamless',
							'name'        => 'seam',
							'label'       => 'Seamless clone',
							'type'        => 'clone',
							'display'     => 'seamless',
							'prefix_name' => 1,
							'sub_fields'  => array(
								array(
									'key'    => 'field_seam_email',
									'name'   => 'seam_email',
									'_name'  => 'seam_email',
									'__name' => 'email',
									'type'   => 'text',
								),
								array(
									'key'    => 'field_seam_phone',
									'name'   => 'seam_phone',
									'_name'  => 'seam_phone',
									'__name' => 'phone',
									'type'   => 'text',
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Boot the ACF stubs with real-ACF container modelling on, register the ability set, and return
	 * a post id to write against.
	 *
	 * @param array<string,mixed> $config Field-group config.
	 * @return int Post id.
	 */
	private function boot( array $config ): int {
		$this->force_integration( 'acf' );
		$this->stub_acf( $config );
		// Model real ACF Pro: raw container storage is keyed by sub-field KEY, and a row read back
		// carries every sub-field the definition declares. Without the second half of that the stub
		// echoes the caller's partial row back and this whole corpus passes vacuously.
		AcfStubStore::$model_container_rekeying = true;
		aafm_registry_cache_should_flush( true );
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/acf-update-post-fields', 'aafm/acf-get-post-fields' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		$admin_id = $this->acting_as( 'administrator' );
		return (int) self::factory()->post->create( array( 'post_author' => $admin_id ) );
	}

	/**
	 * Every write shape, with the verdict the ability must report.
	 *
	 * Each row is [ config-name, sent field map, must_succeed, read_override ]. `read_override`
	 * replaces the raw read-back for a field key regardless of what was written, modelling storage
	 * that came back disagreeing with the write; null means "read back whatever really persisted".
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:bool,3:array<string,mixed>|null}>
	 */
	public function provide_write_shapes(): array {
		$full_row = array(
			'name'             => 'Row One',
			'active'           => true,
			'recipient_type'   => 'field',
			'recipient_field'  => 'email',
			'recipient_custom' => '',
			'from'             => 'me@example.test',
			'subject'          => 'Hi',
			'content'          => '<p>keep <b>bold</b></p>',
		);

		return array(
			// -----------------------------------------------------------------
			// Round-3 traffic simulation, L5-01. The shape that shipped broken:
			// a repeater row carrying three of its eight sub-fields, which is
			// the ordinary way an agent writes one. Persisted correctly and was
			// reported as a failure.
			// -----------------------------------------------------------------
			'L5-01 partial repeater row'        => array(
				'repeater',
				array(
					'field_emails' => array(
						array(
							'name'    => 'Row One',
							'content' => '<p>keep <b>bold</b></p>',
							'active'  => true,
						),
					),
				),
				true,
				null,
			),
			'L5-01 two partial repeater rows'   => array(
				'repeater',
				array(
					'field_emails' => array(
						array( 'name' => 'A' ),
						array( 'name' => 'B' ),
					),
				),
				true,
				null,
			),
			'L5-01 single sub-field row'        => array(
				'repeater',
				array( 'field_emails' => array( array( 'subject' => 'only this' ) ) ),
				true,
				null,
			),
			// Same finding, second shape: clearing a container. ACF stores "no
			// rows" and reads the whole field back as false, so the sent empty
			// list never matched and a successful clear reported failure too.
			'L5-01 clear the repeater'          => array(
				'repeater',
				array( 'field_emails' => array() ),
				true,
				null,
			),

			// -----------------------------------------------------------------
			// The accept-cases that already worked before L5-01 was fixed, kept
			// as regression pins: a relaxed comparison must not have broken the
			// shapes the earlier passes were built for.
			// -----------------------------------------------------------------
			'full eight-sub-field row'          => array(
				'repeater',
				array( 'field_emails' => array( $full_row ) ),
				true,
				null,
			),
			'full row plus a scalar field'      => array(
				'repeater',
				array(
					'field_emails'   => array( $full_row ),
					'field_headline' => 'Headline text',
				),
				true,
				null,
			),
			'scalar field alone'                => array(
				'repeater',
				array( 'field_headline' => 'Just a scalar' ),
				true,
				null,
			),
			'scalar numeric typing'             => array(
				'repeater',
				array( 'field_headline' => 42 ),
				true,
				null,
			),

			// -----------------------------------------------------------------
			// Every container family, and both depths. A group is one flat map;
			// flexible content is rows selected by layout; a repeater nested in
			// a flex layout is the two-depth case. All partial.
			// -----------------------------------------------------------------
			'partial group map'                 => array(
				'nested',
				array( 'field_meta' => array( 'label' => 'Docs' ) ),
				true,
				null,
			),
			'partial flexible-content row'      => array(
				'nested',
				array(
					'field_sections' => array(
						array(
							'acf_fc_layout' => 'section',
							'heading'       => 'Top',
						),
					),
				),
				true,
				null,
			),
			'partial at both nesting depths'    => array(
				'nested',
				array(
					'field_sections' => array(
						array(
							'acf_fc_layout' => 'section',
							'heading'       => 'Top',
							'items'         => array(
								array( 'label' => 'One' ),
								array( 'label' => 'Two' ),
							),
						),
					),
				),
				true,
				null,
			),

			// -----------------------------------------------------------------
			// The REFUSE half. Without these rows the corpus above could be
			// satisfied by a comparison that accepts everything.
			// -----------------------------------------------------------------
			// Storage dropped a row the caller sent.
			'refuse: a sent row vanished'       => array(
				'repeater',
				array(
					'field_emails' => array(
						array( 'name' => 'A' ),
						array( 'name' => 'B' ),
					),
				),
				false,
				array( 'field_emails' => array( array( 'field_email_name' => 'A' ) ) ),
			),
			// Storage kept the row but lost a sub-field the caller sent.
			'refuse: a sent sub-field vanished' => array(
				'repeater',
				array(
					'field_emails' => array(
						array(
							'name'    => 'A',
							'subject' => 'S',
						),
					),
				),
				false,
				array( 'field_emails' => array( array( 'field_email_name' => 'A' ) ) ),
			),
			// Storage kept the key but holds a different value.
			'refuse: a sent value was altered'  => array(
				'repeater',
				array( 'field_emails' => array( array( 'name' => 'A' ) ) ),
				false,
				array( 'field_emails' => array( array( 'field_email_name' => 'ALTERED' ) ) ),
			),
			// Nothing persisted at all: ACF reads an unwritten container as false.
			'refuse: nothing persisted'         => array(
				'repeater',
				array( 'field_emails' => array( array( 'name' => 'A' ) ) ),
				false,
				array( 'field_emails' => false ),
			),
			// Storage holds MORE rows than were sent. Added after a mutation run: deleting the
			// projection's row-count bail-out left the whole corpus green, because every other
			// refuse row differs in a way the missing-key check catches anyway. This is the one
			// shape only the count can see - every sent index is present and correct, and storage
			// simply carries a row nobody asked for, which is what a half-applied clear or a
			// duplicated append looks like.
			'refuse: storage has an extra row'  => array(
				'repeater',
				array( 'field_emails' => array( array( 'name' => 'A' ) ) ),
				false,
				array(
					'field_emails' => array(
						array( 'field_email_name' => 'A' ),
						array( 'field_email_name' => 'B' ),
					),
				),
			),
			// A clear that did not happen: the old rows are still there.
			'refuse: clear did not take'        => array(
				'repeater',
				array( 'field_emails' => array() ),
				false,
				array( 'field_emails' => array( array( 'field_email_name' => 'survivor' ) ) ),
			),
			// The deep case: the nested repeater lost a row.
			'refuse: nested row vanished'       => array(
				'nested',
				array(
					'field_sections' => array(
						array(
							'acf_fc_layout' => 'section',
							'heading'       => 'Top',
							'items'         => array(
								array( 'label' => 'One' ),
								array( 'label' => 'Two' ),
							),
						),
					),
				),
				false,
				array(
					'field_sections' => array(
						array(
							'acf_fc_layout'         => 'section',
							'field_section_heading' => 'Top',
							'field_section_items'   => array( array( 'field_item_label' => 'One' ) ),
						),
					),
				),
			),
			// A scalar that did not persist still fails, unchanged by all of this.
			'refuse: scalar was altered'        => array(
				'repeater',
				array( 'field_headline' => 'Sent' ),
				false,
				array( 'field_headline' => 'Stored something else' ),
			),

			// -----------------------------------------------------------------
			// CLONE. The container type the round-3 sim could not drive at all,
			// because no clone field is defined on the clone site, so the claim
			// that it shared the repeater fix was argued and never demonstrated.
			// It did not share it: a clone with "Prefix Field Names" on renames
			// the sub-field on the way out but not on the way in, and every one
			// of its writes false-failed. See clone_config() for the three name
			// shapes and where each was measured.
			// -----------------------------------------------------------------
			'clone prefixed: partial map'       => array(
				'clone',
				array( 'field_contact' => array( 'email' => 'a@example.test' ) ),
				true,
				null,
			),
			'clone prefixed: full map'          => array(
				'clone',
				array(
					'field_contact' => array(
						'email' => 'a@example.test',
						'phone' => '0100',
						'note'  => 'call after six',
					),
				),
				true,
				null,
			),
			'clone unprefixed: partial map'     => array(
				'clone',
				array( 'field_plainclone' => array( 'email' => 'b@example.test' ) ),
				true,
				null,
			),
			'clone seamless: partial map'       => array(
				'clone',
				array( 'field_seamless' => array( 'seam_email' => 'c@example.test' ) ),
				true,
				null,
			),
			// The REFUSE half for clone. Without these the accept rows above are
			// satisfiable by a comparison that stopped comparing.
			'refuse: clone sub-field vanished'  => array(
				'clone',
				array(
					'field_contact' => array(
						'email' => 'a@example.test',
						'phone' => '0100',
					),
				),
				false,
				array( 'field_contact' => array( 'field_clone_email' => 'a@example.test' ) ),
			),
			'refuse: clone value was altered'   => array(
				'clone',
				array( 'field_contact' => array( 'email' => 'a@example.test' ) ),
				false,
				array( 'field_contact' => array( 'field_clone_email' => 'ALTERED' ) ),
			),
			'refuse: clone nothing persisted'   => array(
				'clone',
				array( 'field_contact' => array( 'email' => 'a@example.test' ) ),
				false,
				array( 'field_contact' => false ),
			),
			// Sending the PREFIXED name is not a write ACF performs: its clone
			// update_value() matches `key` or `_name` and skips anything else, so
			// nothing lands and reporting failure is the correct answer. This row
			// is what stops the fix being "accept both names", which would report
			// success on a write that never happened.
			'refuse: clone sent prefixed name'  => array(
				'clone',
				array( 'field_contact' => array( 'contact_email' => 'a@example.test' ) ),
				false,
				null,
			),

			// -----------------------------------------------------------------
			// The acf_fc_layout marker, both directions. The projection keeps
			// only the keys the caller sent, so the marker's presence on one side
			// and not the other decides whether the whole projection is abandoned
			// - and abandoning it is what reinstates the original false failure.
			// -----------------------------------------------------------------
			// Stored carries the marker, sent does not: harmlessly dropped by the
			// projection, so a row written without it still verifies.
			'flex marker: stored only'          => array(
				'nested',
				array( 'field_sections' => array( array( 'heading' => 'Top' ) ) ),
				true,
				array(
					'field_sections' => array(
						array(
							'acf_fc_layout'         => 'section',
							'field_section_heading' => 'Top',
						),
					),
				),
			),
			// Sent carries the marker, storage does not. array_key_exists fails,
			// the projection is abandoned and the write is reported failed - which
			// is the right answer, because a flexible-content row with no layout
			// recorded is not the row that was asked for. Real ACF sets the marker
			// unconditionally on read (class-acf-field-flexible-content.php:569),
			// so this shape means the write genuinely did not land as sent.
			'flex marker: sent only'            => array(
				'nested',
				array(
					'field_sections' => array(
						array(
							'acf_fc_layout' => 'section',
							'heading'       => 'Top',
						),
					),
				),
				false,
				array( 'field_sections' => array( array( 'field_section_heading' => 'Top' ) ) ),
			),
		);
	}

	/**
	 * Drive one corpus row through the real ability and check the verdict it reports.
	 *
	 * @dataProvider provide_write_shapes
	 *
	 * @param string                   $config_name    'repeater', 'nested' or 'clone'.
	 * @param array<string,mixed>      $fields         The field map to write.
	 * @param bool                     $must_succeed   Whether the ability must report success.
	 * @param array<string,mixed>|null $read_override  Raw read-back override, or null.
	 */
	public function test_write_verdict( string $config_name, array $fields, bool $must_succeed, ?array $read_override ): void {
		if ( 'nested' === $config_name ) {
			$config = $this->nested_config();
		} elseif ( 'clone' === $config_name ) {
			$config = $this->clone_config();
		} else {
			$config = $this->repeater_config();
		}
		$post_id = $this->boot( $config );

		if ( null !== $read_override ) {
			AcfStubStore::$read_override = $read_override;
		}

		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => $fields,
			)
		);

		if ( ! $must_succeed ) {
			$this->assertInstanceOf(
				WP_Error::class,
				$res,
				'A write whose read-back disagrees with what was sent must report failure.'
			);
			return;
		}

		$this->assertNotInstanceOf(
			WP_Error::class,
			$res,
			$res instanceof WP_Error
				? 'The ability reported failure on a write it should accept: ' . $res->get_error_code()
				: ''
		);

		// Success alone is not evidence: it would also be reported by a write path that had quietly
		// stopped writing. Assert the values actually reached storage, sub-field by sub-field, at
		// whatever depth the caller sent them.
		foreach ( $fields as $field_key => $sent ) {
			$this->assertStoredMatchesSent( (string) $field_key, $sent, $post_id );
		}
	}

	/**
	 * Assert storage holds every scalar leaf the caller sent, resolving sub-field names to the keys
	 * real ACF stores them under. Walks the SENT shape, so a sub-field the caller never mentioned is
	 * never asserted on - the whole point of the fix.
	 *
	 * @param string $field_key Top-level field key.
	 * @param mixed  $sent      The value sent for it.
	 * @param int    $post_id   Object selector.
	 * @return void
	 */
	private function assertStoredMatchesSent( string $field_key, $sent, int $post_id ): void {
		$stored = AcfStubStore::value( $field_key, $post_id );
		$def    = acf_get_field( $field_key );

		if ( ! is_array( $sent ) ) {
			$this->assertSame(
				(string) $sent,
				(string) $stored,
				sprintf( 'Scalar field %s must hold the value that was sent.', $field_key )
			);
			return;
		}

		if ( array() === $sent ) {
			$this->assertFalse(
				is_array( $stored ) && array() !== $stored,
				sprintf( 'Container %s was cleared, so storage must not hold rows.', $field_key )
			);
			return;
		}

		// Re-key storage back to sub-field names so the sent shape can be walked against it, using
		// the production helper - the same translation the ability's own verify performs.
		$named = aafm_acf_rekey_stored_to_names( $stored, is_array( $def ) ? $def : null );
		$this->assertLeavesPresent( $named, $sent, $field_key );
	}

	/**
	 * Walk the sent value and assert each scalar leaf is present in the (name-keyed) stored value.
	 *
	 * @param mixed  $stored Stored value, name-keyed.
	 * @param mixed  $sent   Sent value.
	 * @param string $path   Human-readable path for the failure message.
	 * @return void
	 */
	private function assertLeavesPresent( $stored, $sent, string $path ): void {
		if ( ! is_array( $sent ) ) {
			$this->assertSame(
				(string) $sent,
				(string) ( is_bool( $stored ) ? ( $stored ? '1' : '' ) : $stored ),
				sprintf( 'Storage must hold what was sent at %s.', $path )
			);
			return;
		}
		$this->assertIsArray( $stored, sprintf( 'Storage must hold a container at %s.', $path ) );
		foreach ( $sent as $key => $sub ) {
			$this->assertArrayHasKey(
				$key,
				$stored,
				sprintf( 'Storage is missing %s/%s.', $path, (string) $key )
			);
			$this->assertLeavesPresent( $stored[ $key ], $sub, $path . '/' . (string) $key );
		}
	}

	/**
	 * Resolving a sub-field by its ORIGINAL name must change nothing for an ordinary sub-field.
	 *
	 * The safety of the whole `_name` change rests on this: if `_name` and `name` disagreed anywhere
	 * outside a prefixed clone, three working container types would have been broken to fix a fourth.
	 * Measured on ACF Pro 6.8.7, acf_validate_field() sets `_name = name` for every field
	 * (acf-field-functions.php:270), so the two agree everywhere except where acf_clone_field()
	 * deliberately separates them.
	 *
	 * The last assertion is the one that stops this test passing vacuously. Without it the whole
	 * thing is satisfied by a key map that ignores `_name` entirely, which is the code the fix
	 * replaced.
	 */
	public function test_the_original_name_mapping_is_a_no_op_for_ordinary_sub_fields(): void {
		$this->boot( $this->repeater_config() );

		$def = acf_get_field( 'field_emails' );
		$this->assertIsArray( $def, 'The repeater definition must resolve.' );

		// No sub-field here declares _name, so every entry must fall back to name.
		$by_name = array();
		foreach ( $def['sub_fields'] as $sub ) {
			$by_name[ (string) $sub['key'] ] = (string) $sub['name'];
		}
		$this->assertSame(
			$by_name,
			aafm_acf_sub_field_key_map( $def ),
			'With no _name declared, the map must fall back to name exactly as it always did.'
		);

		// And declaring _name equal to name, which is what real ACF does, must change nothing.
		$with_alias = $def;
		foreach ( $with_alias['sub_fields'] as $index => $sub ) {
			$with_alias['sub_fields'][ $index ]['_name'] = $sub['name'];
		}
		$this->assertSame(
			$by_name,
			aafm_acf_sub_field_key_map( $with_alias ),
			'_name equal to name is the ordinary case and must produce an identical map.'
		);

		// The discriminator: a prefixed clone MUST map to something other than name, or the map is
		// ignoring _name and this test proves nothing.
		$this->boot( $this->clone_config() );
		$clone_def = acf_get_field( 'field_contact' );
		$this->assertIsArray( $clone_def, 'The clone definition must resolve.' );
		$clone_map = aafm_acf_sub_field_key_map( $clone_def );
		$this->assertSame( 'email', $clone_map['field_clone_email'], 'A prefixed clone must map to its original name.' );
		$this->assertNotSame(
			'contact_email',
			$clone_map['field_clone_email'],
			'Mapping a prefixed clone to name is the shipped defect; the map must not do it.'
		);
	}

	/**
	 * The clone rows above are only worth anything if the stub really hydrates a clone's row.
	 *
	 * Added after a mutation run, and it closes a real hole. Stubbing out the stub's row
	 * materialisation left the entire corpus green except for ONE assertion, the sentinel in the
	 * repeater test below. Every clone row kept passing, because with no unsent sub-fields coming
	 * back there is nothing for the projection to project away and the comparison matches
	 * trivially. That is the shape of a test that passes for a reason unrelated to what its name
	 * claims, so the clone configuration needs its own sentinel rather than borrowing the
	 * repeater's.
	 */
	public function test_the_stub_hydrates_a_clone_row_or_the_clone_rows_prove_nothing(): void {
		$post_id = $this->boot( $this->clone_config() );

		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array( 'field_contact' => array( 'email' => 'a@example.test' ) ),
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res, 'A partial write to a prefixed clone must be accepted.' );

		$stored = AcfStubStore::value( 'field_contact', $post_id );
		$this->assertIsArray( $stored, 'The clone map must be in storage.' );
		$this->assertSame( 'a@example.test', $stored['field_clone_email'], 'The sent value must be stored under the sub-field key.' );
		$this->assertArrayHasKey(
			'field_clone_phone',
			$stored,
			'ACF hydrates every declared sub-field of a clone on read-back; without that the clone rows are vacuous.'
		);
		$this->assertArrayHasKey( 'field_clone_note', $stored, 'Same for the third sub-field nobody sent.' );
	}

	/**
	 * The partial-row case again, spelled out end to end rather than as a corpus row, because it is
	 * the defect the simulation actually found and it deserves to fail with a message that says so.
	 * Success AND persistence are both asserted; either alone would pass against broken code.
	 */
	public function test_partial_repeater_row_reports_success_and_the_row_is_stored(): void {
		$post_id = $this->boot( $this->repeater_config() );

		$res = wp_get_ability( 'aafm/acf-update-post-fields' )->execute(
			array(
				'post_id' => $post_id,
				'fields'  => array(
					'field_emails' => array(
						array(
							'name'    => 'Row One',
							'content' => '<p>keep <b>bold</b></p>',
							'active'  => true,
						),
					),
				),
			)
		);

		$this->assertNotInstanceOf(
			WP_Error::class,
			$res,
			'A repeater row carrying some of its sub-fields is the ordinary write; it must not be reported as a failure.'
		);

		$stored = AcfStubStore::value( 'field_emails', $post_id );
		$this->assertIsArray( $stored, 'The row must be in storage, not merely reported as written.' );
		$this->assertCount( 1, $stored, 'Exactly the one row that was sent must be stored.' );
		$this->assertSame( 'Row One', $stored[0]['field_email_name'], 'The sent name must be stored under its sub-field key.' );
		$this->assertSame( '<p>keep <b>bold</b></p>', $stored[0]['field_email_content'], 'The sent rich text must be stored intact.' );
		$this->assertSame( '1', (string) $stored[0]['field_email_active'], 'The sent boolean must be stored as ACF stores it.' );

		// And the read-back really does carry the sub-fields nobody sent - the materialisation that
		// caused the false failure. If this assertion ever fails, the stub has stopped modelling ACF
		// and every accept-row above has gone vacuous.
		$this->assertArrayHasKey(
			'field_email_from',
			$stored[0],
			'ACF hydrates every declared sub-field on read-back; the stub must model that or this corpus proves nothing.'
		);
	}
}
