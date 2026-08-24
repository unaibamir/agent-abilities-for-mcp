<?php
/**
 * The regression corpus for the WooCommerce global-attribute guard on wc-update-product.
 *
 * ROWS IN THIS FILE ARE APPEND-ONLY. NEVER DELETE ONE.
 *
 * Read that literally. This guard has been written three times. Each attempt fixed the case the
 * current review round had found and left or reopened one an earlier attempt had handled, because the
 * suite only ever pinned the newest round's findings:
 *
 *   9fab08d  introduced the guard (a read-modify-write must not demote a global attribute to a local
 *            one) and compared the caller's options against a VIEW-context read. That comparison is
 *            what R7B-1 turned out to be.
 *   f291ea7  fixed R6-1 by splitting stored from shown and handing the BUILDER the stored objects,
 *            so a display filter could no longer be persisted. It left the comparison on shown.
 *   R7B-1    a filter that hides a stored term makes the displayed set a legitimate edit request in
 *            its own right, so sending it back was classified as an unchanged echo, allowed, and
 *            applied as a no-op. The caller's removal was discarded and the same filter shaped a
 *            response that looked like it had worked.
 *
 * So this file is not a list of tests. It is the union of every case any version of this guard
 * protected or got wrong, each row labelled with the commit or finding it came from, pinned
 * simultaneously, so a fourth rewrite fails loudly instead of quietly shipping a hole. If you are
 * here to rewrite the guard: add rows, do not remove them. A row you cannot satisfy is a
 * conversation to have, not a line to delete.
 *
 * Rows are held at FUNCTION level, against real WordPress taxonomies and terms, because the two
 * things that matter about this guard are both function-level facts: which of stored and shown
 * answers which question, and what the builder is handed. The end-to-end halves live in
 * tests/contract/WooCommerceContractTest.php, against real WooCommerce, and they are the authority
 * on what reaches the database. They cannot be reproduced here: the WC_Product stub takes no
 * context argument, so shown and stored can never disagree through it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcStubStore;
use WP_Error;

final class WcGlobalAttributeGuardCorpusTest extends TestCase {

	use IntegrationStubs;

	/**
	 * The attribute taxonomy every row shares, with three real terms.
	 */
	private const TAXONOMY = 'pa_aafmcorpus';

	/**
	 * Term ids for blue, green, and red, keyed by slug.
	 *
	 * @var array<string,int>
	 */
	private array $terms = array();

	public function set_up(): void {
		parent::set_up();
		// stub_woocommerce() is what eval's the WC_Product_Attribute class the rows build, and the
		// WC_Product the two variation rows need a parent from.
		$this->stub_woocommerce(
			array(
				array(
					'id'     => 500,
					'name'   => 'Corpus Parent',
					'type'   => 'variable',
					'status' => 'publish',
				),
			)
		);

		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			register_taxonomy( self::TAXONOMY, 'product', array( 'public' => false ) );
		}
		foreach ( array( 'blue', 'green', 'red' ) as $slug ) {
			$existing = get_term_by( 'slug', $slug, self::TAXONOMY );
			if ( $existing instanceof \WP_Term ) {
				$this->terms[ $slug ] = (int) $existing->term_id;
				continue;
			}
			$created              = wp_insert_term( ucfirst( $slug ), self::TAXONOMY, array( 'slug' => $slug ) );
			$this->terms[ $slug ] = (int) $created['term_id'];
		}
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		parent::tear_down();
	}

	/**
	 * The guard's own contract: given what is stored, what was shown, and what was sent, is the
	 * request refused, and under which code?
	 *
	 * Each row is a labelled case. `stored` and `shown` are attribute specs (see attribute()); a
	 * null entry means the attribute is absent from that collection. `expected` is the WP_Error code,
	 * or null for "accepted".
	 *
	 * @dataProvider provide_guard_cases
	 *
	 * @param array<string,mixed>|null       $stored   Spec for the stored attribute, or null when absent.
	 * @param array<string,mixed>|null       $shown    Spec for the displayed attribute, or null when absent.
	 * @param array<int,array<string,mixed>> $sent Sanitized input items.
	 * @param string|null                    $expected Expected error code, or null when accepted.
	 */
	public function test_guard_corpus( ?array $stored, ?array $shown, array $sent, ?string $expected ): void {
		$error = aafm_wc_global_attribute_change_error(
			$sent,
			null === $stored ? array() : array( $this->attribute( $stored ) ),
			null === $shown ? array() : array( $this->attribute( $shown ) )
		);

		if ( null === $expected ) {
			$this->assertNull(
				$error,
				'This request must be accepted. ' . ( $error instanceof WP_Error ? 'Got: ' . $error->get_error_code() : '' )
			);
			return;
		}

		$this->assertInstanceOf( WP_Error::class, $error, 'This request must be refused.' );
		$this->assertSame( $expected, $error->get_error_code() );
	}

	/**
	 * The corpus. Append-only: see the file docblock before touching a row.
	 *
	 * @return array<string,array{0:array<string,mixed>|null,1:array<string,mixed>|null,2:array<int,array<string,mixed>>,3:string|null}>
	 */
	public function provide_guard_cases(): array {
		$tax    = self::TAXONOMY;
		$global = array(
			'id'      => 7,
			'name'    => $tax,
			'options' => array( 'blue', 'green' ),
		);

		return array(

			/*
			 * 9fab08d (B2-01/B2-03). The case the guard was built for: the field models a name and a
			 * list of literal strings, so it cannot express a change to terms in a shared taxonomy.
			 * Accepting it rebuilt the attribute with set_id( 0 ), which flipped is_taxonomy to 0 and
			 * stranded every variation keyed on it.
			 */
			'9fab08d a genuine change to a global attribute' => array(
				$global,
				$global,
				array(
					array(
						'name'    => $tax,
						'options' => array( 'blue', 'red' ),
					),
				),
				'aafm_wc_global_attribute_not_editable',
			),

			/*
			 * 9fab08d, kept by f291ea7. The ordinary read-modify-write turn: read a product, change
			 * the price, send the body back. It has to stay lossless.
			 */
			'9fab08d an unchanged echo'                    => array(
				$global,
				$global,
				array(
					array(
						'name'    => $tax,
						'options' => array( 'blue', 'green' ),
					),
				),
				null,
			),

			/*
			 * 9fab08d. A taxonomy attribute's option ORDER is not stored on the product at all (its
			 * `value` is empty; the terms live in the object-term relationship), so a reordered echo
			 * is the same set and refusing it would be pedantry.
			 */
			'9fab08d a reordered echo is the same set'     => array(
				$global,
				$global,
				array(
					array(
						'name'    => $tax,
						'options' => array( 'green', 'blue' ),
					),
				),
				null,
			),

			/*
			 * 9fab08d. Global in the view, absent from storage: a filter invented it. There is no
			 * honest write, because creating it would promote a display artefact into stored state.
			 */
			'9fab08d a global attribute a filter invented' => array(
				null,
				$global,
				array(
					array(
						'name'    => $tax,
						'options' => array( 'blue', 'green' ),
					),
				),
				'aafm_wc_global_attribute_not_editable',
			),

			/*
			 * 9fab08d. The positive controls. A custom attribute is exactly what this field models,
			 * and a name the product does not have yet is a new custom attribute. Both stay editable,
			 * or the guard would be a worse bug than the one it fixed.
			 */
			'9fab08d a custom attribute stays editable'    => array(
				array(
					'id'      => 0,
					'name'    => 'Material',
					'options' => array( 'Cotton', 'Wool' ),
				),
				array(
					'id'      => 0,
					'name'    => 'Material',
					'options' => array( 'Cotton', 'Wool' ),
				),
				array(
					array(
						'name'    => 'Material',
						'options' => array( 'Cotton', 'Linen' ),
					),
				),
				null,
			),
			'9fab08d a genuinely new name is a new custom one' => array(
				null,
				null,
				array(
					array(
						'name'    => 'Finish',
						'options' => array( 'Matte' ),
					),
				),
				null,
			),

			/*
			 * f291ea7 (R6-1). A filter that APPENDS a display-only string. aafm_wc_attribute_shape()
			 * drops an option it cannot resolve to a term read-only, so shown and stored agree again
			 * by the time they are compared and the echo is still accepted. That is deliberate, and
			 * it is why the R7B-1 direction below is the dangerous one: this direction self-heals.
			 */
			'f291ea7 a filter appended an unresolvable option' => array(
				$global,
				array(
					'id'      => 7,
					'name'    => $tax,
					'options' => array( 'blue', 'green', 'Display swatch' ),
				),
				array(
					array(
						'name'    => $tax,
						'options' => array( 'blue', 'green' ),
					),
				),
				null,
			),

			/*
			 * R7B-1. A filter HIDES one of two stored terms, so the caller is shown a set that is a
			 * legitimate edit request in its own right, and nothing in the request says which they
			 * meant. This used to be read as an echo, accepted, and applied as a no-op against stored
			 * state: the removal was discarded and the filtered response confirmed the state they
			 * asked for. Refused now, because a false success on a write is the worse failure.
			 */
			'R7B-1 a filter hid a stored term, sent set matches' => array(
				$global,
				array(
					'id'      => 7,
					'name'    => $tax,
					'options' => array( 'blue' ),
				),
				array(
					array(
						'name'    => $tax,
						'options' => array( 'blue' ),
					),
				),
				'aafm_wc_global_attribute_display_masked',
			),

			/*
			 * R7B-1, and the row that stops this being fixed by refusing every filtered attribute.
			 * A caller who sends the STORED set is asking for nothing whichever way they meant it,
			 * so there is nothing to guess and nothing to discard. Still accepted, filter and all.
			 */
			'R7B-1 the stored set is accepted despite the filter' => array(
				$global,
				array(
					'id'      => 7,
					'name'    => $tax,
					'options' => array( 'blue' ),
				),
				array(
					array(
						'name'    => $tax,
						'options' => array( 'blue', 'green' ),
					),
				),
				null,
			),

			/*
			 * R7B-1. Same masking, but the caller sends neither the stored nor the displayed set.
			 * That is an ordinary genuine change and gets the ordinary message.
			 */
			'R7B-1 a change matching neither stored nor shown' => array(
				$global,
				array(
					'id'      => 7,
					'name'    => $tax,
					'options' => array( 'blue' ),
				),
				array(
					array(
						'name'    => $tax,
						'options' => array( 'red' ),
					),
				),
				'aafm_wc_global_attribute_not_editable',
			),

			/*
			 * R7B-1 sweep. A filter that demotes a global attribute in the VIEW, so the displayed
			 * options are literal strings. Stored is still global, so the request is refused; the
			 * masked code is what says why, because the caller was handed those strings.
			 */
			'R7B-1 a filter demoted it to custom in the view' => array(
				$global,
				array(
					'id'      => 0,
					'name'    => $tax,
					'options' => array( 'Blue', 'Green' ),
				),
				array(
					array(
						'name'    => $tax,
						'options' => array( 'Blue', 'Green' ),
					),
				),
				'aafm_wc_global_attribute_display_masked',
			),

			/*
			 * R7B-1 sweep. A filter that hides the attribute from the view entirely. The caller was
			 * shown nothing for it, so shown falls back to stored: the stored set is still a no-op,
			 * and anything else is an ordinary refusal.
			 */
			'R7B-1 a filter hid the whole attribute, stored set' => array(
				$global,
				null,
				array(
					array(
						'name'    => $tax,
						'options' => array( 'blue', 'green' ),
					),
				),
				null,
			),
			'R7B-1 a filter hid the whole attribute, a change' => array(
				$global,
				null,
				array(
					array(
						'name'    => $tax,
						'options' => array( 'red' ),
					),
				),
				'aafm_wc_global_attribute_not_editable',
			),

			/*
			 * R7B-1. Precedence, pinned so it cannot drift: a request carrying both a masked
			 * attribute and an ordinary change reports the masked one. Its cause is the surprising
			 * one and its remedy is different. The whole request is refused either way, so nothing
			 * is written and the second attribute is refused again, with its own message, next turn.
			 */
			'R7B-1 masked wins over an ordinary change'    => array(
				$global,
				array(
					'id'      => 7,
					'name'    => $tax,
					'options' => array( 'blue' ),
				),
				array(
					array(
						'name'    => $tax,
						'options' => array( 'blue' ),
					),
					array(
						'name'    => 'Material',
						'options' => array( 'Linen' ),
					),
				),
				'aafm_wc_global_attribute_display_masked',
			),
		);
	}

	/**
	 * R7B-1. The refusal a masked caller gets must not tell them to do the thing that just failed.
	 *
	 * The ordinary message ends "or send its current options back unchanged to leave it alone". A
	 * caller who was shown a filtered set and sent it back did exactly that, so repeating the advice
	 * would loop them. This is why the two cases carry different codes rather than one.
	 */
	public function test_the_masked_message_does_not_repeat_the_advice_that_failed(): void {
		$error = aafm_wc_global_attribute_change_error(
			array(
				array(
					'name'    => self::TAXONOMY,
					'options' => array( 'blue' ),
				),
			),
			array(
				$this->attribute(
					array(
						'id'      => 7,
						'name'    => self::TAXONOMY,
						'options' => array( 'blue', 'green' ),
					)
				),
			),
			array(
				$this->attribute(
					array(
						'id'      => 7,
						'name'    => self::TAXONOMY,
						'options' => array( 'blue' ),
					)
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertStringNotContainsString( 'unchanged', $error->get_error_message() );
		$this->assertStringNotContainsString(
			'wc-update-product-attribute',
			$error->get_error_message(),
			'wc-update-product-attribute cannot change an attribute\'s options at all, so it must not be named as a remedy.'
		);
	}

	/**
	 * B-wc-attribute-remedy: neither refusal message may point the caller at
	 * wc-update-product-attribute as if it could change a global attribute's options - it only
	 * exposes name/slug/type/order_by/has_archives (aafm_wc_attribute_write_properties()),
	 * never options/terms. Following that pointer was a guaranteed second refusal.
	 */
	public function test_the_not_editable_message_does_not_point_at_a_tool_that_cannot_do_it(): void {
		$error = aafm_wc_global_attribute_change_error(
			array(
				array(
					'name'    => self::TAXONOMY,
					'options' => array( 'purple' ),
				),
			),
			array(
				$this->attribute(
					array(
						'id'      => 7,
						'name'    => self::TAXONOMY,
						'options' => array( 'blue', 'green' ),
					)
				),
			),
			array(
				$this->attribute(
					array(
						'id'      => 7,
						'name'    => self::TAXONOMY,
						'options' => array( 'blue', 'green' ),
					)
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertStringNotContainsString(
			'wc-update-product-attribute',
			$error->get_error_message(),
			'wc-update-product-attribute cannot change an attribute\'s options at all, so it must not be named as a remedy.'
		);
	}

	/**
	 * Behaviour 5, from f291ea7 (R6-1): the builder leaves a global attribute's object exactly as handed
	 * to it, so whatever the caller is given, the object that reaches set_attributes() is the one the
	 * caller of the builder chose. That is what makes passing STORED objects sufficient.
	 *
	 * Identity, not equality: rebuilding the object is the damage. The fresh object pins set_id( 0 ),
	 * and the attribute id IS the taxonomy binding.
	 */
	public function test_the_builder_never_rebuilds_a_global_attribute(): void {
		$stored = $this->attribute(
			array(
				'id'      => 7,
				'name'    => self::TAXONOMY,
				'options' => array( 'blue', 'green' ),
			)
		);

		$built = aafm_wc_build_product_attributes(
			array(
				array(
					'name'    => self::TAXONOMY,
					'options' => array( 'blue', 'green' ),
				),
			),
			array( $stored )
		);

		$this->assertCount( 1, $built );
		$this->assertSame( $stored, $built[0], 'The stored object must be passed through untouched, not rebuilt.' );
		$this->assertSame( 7, (int) $built[0]->get_id(), 'The attribute id is the taxonomy binding.' );
		$this->assertSame(
			array( $this->terms['blue'], $this->terms['green'] ),
			array_map( 'intval', (array) $built[0]->get_options() ),
			'Both stored term ids must survive. Losing one here is the R7B-1 harm, one layer down.'
		);
	}

	/**
	 * Behaviour 5's other half, from f291ea7 (R6-1): an attribute the caller did not mention outlives
	 * set_attributes()'s pre-null, and a custom attribute the caller DID mention is rebuilt from the
	 * sent options while keeping its visibility, variation flag, and slot.
	 */
	public function test_the_builder_preserves_the_unmentioned_and_edits_the_custom(): void {
		$global = $this->attribute(
			array(
				'id'      => 7,
				'name'    => self::TAXONOMY,
				'options' => array( 'blue' ),
			)
		);
		$custom = $this->attribute(
			array(
				'id'        => 0,
				'name'      => 'Material',
				'options'   => array( 'Cotton', 'Wool' ),
				'raw'       => true,
				'visible'   => false,
				'variation' => true,
				'position'  => 3,
			)
		);

		$built = aafm_wc_build_product_attributes(
			array(
				array(
					'name'    => 'Material',
					'options' => array( 'Cotton', 'Linen' ),
				),
			),
			array( $global, $custom )
		);

		$by_slug = array();
		foreach ( $built as $attribute ) {
			$by_slug[ sanitize_title( (string) $attribute->get_name() ) ] = $attribute;
		}

		$this->assertArrayHasKey( self::TAXONOMY, $by_slug, 'An unmentioned attribute must survive the merge.' );
		$this->assertSame( $global, $by_slug[ self::TAXONOMY ] );

		$this->assertArrayHasKey( 'material', $by_slug );
		$this->assertSame( array( 'Cotton', 'Linen' ), array_values( (array) $by_slug['material']->get_options() ) );
		$this->assertSame( 0, (int) $by_slug['material']->get_id() );
		$this->assertFalse( $by_slug['material']->get_visible(), 'A value edit must not silently unhide an attribute.' );
		$this->assertTrue( $by_slug['material']->get_variation(), 'Nor drop it from the product\'s variations.' );
		$this->assertSame( 3, (int) $by_slug['material']->get_position(), 'Nor move it.' );
	}

	/**
	 * Behaviour 9, from 9fab08d (B2-03): the read emits a global attribute's options as term SLUGS plus a
	 * taxonomy flag, and a custom attribute's as its literal strings with the flag off.
	 *
	 * This is the same function the guard compares through, which is the point: the read and the
	 * write cannot drift into disagreeing about what "unchanged" means.
	 */
	public function test_the_read_shape_emits_slugs_and_says_which_kind(): void {
		$shape = aafm_wc_attribute_shape(
			$this->attribute(
				array(
					'id'      => 7,
					'name'    => self::TAXONOMY,
					'options' => array( 'blue', 'green' ),
				)
			)
		);
		$this->assertTrue( $shape['taxonomy'] );
		$this->assertSame( array( 'blue', 'green' ), $shape['options'], 'Term ids are unusable by every write path here.' );

		$custom = aafm_wc_attribute_shape(
			$this->attribute(
				array(
					'id'      => 0,
					'name'    => 'Material',
					'options' => array( 'Cotton' ),
					'raw'     => true,
				)
			)
		);
		$this->assertFalse( $custom['taxonomy'] );
		$this->assertSame( array( 'Cotton' ), $custom['options'] );
	}

	/**
	 * Behaviour 6, from 09e1378: no path in this plugin calls WC_Product_Attribute::get_terms() or
	 * ::get_slugs(). They are the two APIs that resolve an option by name, and both share an insert
	 * branch that calls wp_insert_term(), so either one turns a display-only string into a real term
	 * on a live store.
	 *
	 * This is a source-level tripwire, deliberately, because the guarantee is "the call is never
	 * made" and the cheapest way to break it is to add the call. It is not the behavioural coverage:
	 * that is the term-count assertions in the WooCommerce contract tests, which fail if a term is
	 * created by any route at all, named or not.
	 */
	public function test_no_plugin_code_calls_the_two_term_creating_apis(): void {
		$found    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( AAFM_PLUGIN_DIR . 'includes', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || 'php' !== strtolower( (string) $file->getExtension() ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$source = (string) file_get_contents( (string) $file->getRealPath() );
			// Strip comments so the docblocks that explain the prohibition do not trip it.
			$source = (string) preg_replace( '#/\*.*?\*/|//[^\r\n]*#s', '', $source );
			if ( preg_match( '/->\s*get_(terms|slugs)\s*\(/', $source ) ) {
				$found[] = (string) $file->getFilename();
			}
		}

		$this->assertSame(
			array(),
			$found,
			'These resolve an option by name and create it on a miss. Use aafm_wc_find_attribute_term().'
		);
	}

	/**
	 * Behaviour 7, from ae074f1 (R2-9): a variation keyed on a parent attribute the parent does not use
	 * FOR VARIATIONS is refused. WooCommerce never matches such a key, so the write would land in
	 * postmeta and mean nothing.
	 */
	public function test_a_variation_attribute_the_parent_does_not_vary_on_is_refused(): void {
		$error = aafm_wc_unknown_variation_attributes_error(
			$this->corpus_parent(),
			array( 'brand' => 'Acme' )
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame(
			'aafm_wc_attribute_not_used_for_variations',
			$error->get_error_code(),
			'A key the parent DECLARES but does not vary on has its own code, distinct from a key it has never heard of.'
		);

		// And a key the parent has never heard of at all, which is the other half of R2-9.
		$unknown = aafm_wc_unknown_variation_attributes_error( $this->corpus_parent(), array( 'pa_nonesuch' => 'x' ) );
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'aafm_wc_unknown_variation_attribute', $unknown->get_error_code() );

		// Positive control: the attribute the parent DOES vary on is accepted.
		$this->assertNull(
			aafm_wc_unknown_variation_attributes_error( $this->corpus_parent(), array( self::TAXONOMY => 'blue' ) ),
			'A key the parent varies on, with a declared value, must be accepted.'
		);
	}

	/**
	 * Behaviour 8, from f285327 (R2-10): a value the parent never declared for that attribute is refused,
	 * and the error names the real options. An empty value is exempt: that is WooCommerce's
	 * "Any <attribute>", a supported configuration.
	 */
	public function test_a_variation_attribute_value_the_parent_never_declared_is_refused(): void {
		$parent_attributes = $this->corpus_parent()->get_attributes();

		$error = aafm_wc_invalid_variation_attribute_values_error( $parent_attributes, array( self::TAXONOMY => 'purple' ) );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'aafm_wc_invalid_variation_attribute_value', $error->get_error_code() );
		$this->assertStringContainsString( 'blue', $error->get_error_message(), 'The error must list the real options.' );

		$this->assertNull(
			aafm_wc_invalid_variation_attribute_values_error( $parent_attributes, array( self::TAXONOMY => 'blue' ) ),
			'A declared option must be accepted.'
		);
		$this->assertNull(
			aafm_wc_invalid_variation_attribute_values_error( $parent_attributes, array( self::TAXONOMY => '' ) ),
			'An empty value is WooCommerce\'s "Any", and refusing it would break valid variations.'
		);
	}

	/**
	 * Parent 500, holding the corpus taxonomy as a variation attribute and `brand` for display only.
	 *
	 * @return \WC_Product
	 */
	private function corpus_parent(): \WC_Product {
		$parent               = (array) WcStubStore::get( 500 );
		$parent['attributes'] = array(
			self::TAXONOMY => $this->attribute(
				array(
					'id'        => 7,
					'name'      => self::TAXONOMY,
					'options'   => array( 'blue', 'green' ),
					'variation' => true,
				)
			),
			'brand'        => $this->attribute(
				array(
					'id'      => 0,
					'name'    => 'brand',
					'options' => array( 'Acme' ),
					'raw'     => true,
				)
			),
		);
		WcStubStore::seed( 500, $parent );

		$product = wc_get_product( 500 );
		$this->assertInstanceOf( \WC_Product::class, $product );
		return $product;
	}

	/**
	 * Build one WC_Product_Attribute from a row spec, the way a live product holds it.
	 *
	 * A global attribute (id > 0) stores TERM IDS, so slugs in the spec are resolved to the real term
	 * ids seeded in set_up(). A string with no matching term is kept verbatim, which is how a
	 * display-only option enters a collection. Pass `raw` to skip resolution entirely, for a custom
	 * attribute whose options are literal strings.
	 *
	 * @param array<string,mixed> $spec Row spec: id, name, options, and optional raw/visible/variation/position.
	 * @return \WC_Product_Attribute
	 */
	private function attribute( array $spec ): \WC_Product_Attribute {
		$options = array();
		foreach ( (array) ( $spec['options'] ?? array() ) as $option ) {
			$options[] = empty( $spec['raw'] ) && isset( $this->terms[ (string) $option ] )
				? $this->terms[ (string) $option ]
				: $option;
		}

		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( (int) ( $spec['id'] ?? 0 ) );
		$attribute->set_name( (string) ( $spec['name'] ?? '' ) );
		$attribute->set_options( $options );
		$attribute->set_position( (int) ( $spec['position'] ?? 0 ) );
		$attribute->set_visible( (bool) ( $spec['visible'] ?? true ) );
		$attribute->set_variation( (bool) ( $spec['variation'] ?? false ) );
		return $attribute;
	}
}
