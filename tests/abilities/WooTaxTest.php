<?php
/**
 * WooCommerce tax abilities: wc-list-tax-rates, wc-get-tax-rate, wc-create-tax-rate,
 * wc-update-tax-rate, wc-list-tax-classes, wc-create-tax-class.
 *
 * WooCommerce is not installed in the DDEV test environment. Tax rates are backed by a real
 * temp table (woocommerce_tax_rates) created in the test DB by WcTaxStubStore. Tax classes
 * are backed by WcTaxStubStore::$classes through the WC_Tax eval stub.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\WcTaxStubStore;
use WP_Error;

final class WooTaxTest extends TestCase {

	use IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		$this->force_integration( 'woocommerce' );
		$this->unlock_high_risk_abilities();
		$this->stub_woocommerce();
		$this->stub_wc_tax();
		$this->seed_wc_tax();
		WcTaxStubStore::create_tax_rates_table();
		WcTaxStubStore::seed_rates();
		aafm_registry_cache_should_flush( true );
		$this->register_wc_tax();
	}

	public function tear_down(): void {
		$this->reset_integration_stubs();
		WcTaxStubStore::drop_tax_rates_table();
		WcTaxStubStore::reset();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Enable and register the full WooCommerce tax ability set.
	 */
	private function register_wc_tax(): void {
		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array(
				'aafm/wc-list-tax-rates',
				'aafm/wc-get-tax-rate',
				'aafm/wc-create-tax-rate',
				'aafm/wc-update-tax-rate',
				'aafm/wc-list-tax-classes',
				'aafm/wc-create-tax-class',
			)
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	// =========================================================================
	// Integration guard
	// =========================================================================

	/**
	 * Tax abilities must be absent from the registry when WooCommerce is inactive.
	 */
	public function test_abilities_hidden_when_woocommerce_inactive(): void {
		$this->reset_integration_stubs();
		remove_all_filters( 'aafm_integration_active_woocommerce' );
		add_filter( 'aafm_woocommerce_active', '__return_false', 99 );
		$this->assertFalse( aafm_integration_active( 'woocommerce' ) );
		aafm_registry_cache_should_flush( true );

		$registry = aafm_get_abilities_registry();
		$this->assertArrayNotHasKey( 'aafm/wc-list-tax-rates', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-get-tax-rate', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-tax-rate', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-update-tax-rate', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-list-tax-classes', $registry );
		$this->assertArrayNotHasKey( 'aafm/wc-create-tax-class', $registry );

		remove_filter( 'aafm_woocommerce_active', '__return_false', 99 );
	}

	// =========================================================================
	// aafm/wc-list-tax-rates
	// =========================================================================

	/**
	 * List returns both seeded rates with the canonical shape.
	 */
	public function test_list_tax_rates_returns_seeded_rates(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'rates', $res );
		$this->assertArrayHasKey( 'total', $res );
		$this->assertCount( 2, $res['rates'] );
		$this->assertSame( 2, $res['total'] );

		$first = $res['rates'][0];
		$this->assertArrayHasKey( 'id', $first );
		$this->assertArrayHasKey( 'country', $first );
		$this->assertArrayHasKey( 'rate', $first );
		$this->assertArrayHasKey( 'name', $first );
		$this->assertArrayHasKey( 'compound', $first );
		$this->assertArrayHasKey( 'shipping', $first );
		$this->assertArrayHasKey( 'class', $first );
	}

	/**
	 * Editor (no manage_woocommerce) must be denied at the permission gate.
	 */
	public function test_list_tax_rates_requires_manage_woocommerce(): void {
		$this->acting_as( 'editor' );
		$this->assertNotTrue(
			wp_get_ability( 'aafm/wc-list-tax-rates' )->check_permissions( array() )
		);
	}

	// =========================================================================
	// aafm/wc-get-tax-rate
	// =========================================================================

	/**
	 * Getting a seeded rate by id returns the canonical rate shape.
	 */
	public function test_get_tax_rate_returns_rate(): void {
		$this->acting_as( 'administrator' );

		// Fetch id from list first.
		$list    = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute( array() );
		$rate_id = (int) $list['rates'][0]['id'];

		$res = wp_get_ability( 'aafm/wc-get-tax-rate' )->execute( array( 'rate_id' => $rate_id ) );
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( $rate_id, $res['id'] );
		$this->assertSame( 'GB', $res['country'] );
	}

	/**
	 * Unknown id returns WP_Error.
	 */
	public function test_get_tax_rate_unknown_id_returns_wp_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-get-tax-rate' )->execute( array( 'rate_id' => 999999 ) );
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// aafm/wc-create-tax-rate
	// =========================================================================

	/**
	 * Create inserts a new row and returns the full rate shape with a new id.
	 */
	public function test_create_tax_rate_inserts_and_returns(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-tax-rate' )->execute(
			array(
				'rate'    => '10.0000',
				'name'    => 'Test Rate',
				'country' => 'US',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertGreaterThan( 0, $res['id'] );
		$this->assertSame( 'US', $res['country'] );
		$this->assertSame( '10.0000', $res['rate'] );
		$this->assertSame( 'Test Rate', $res['name'] );

		// Confirm it's actually in the DB.
		$fetched = wp_get_ability( 'aafm/wc-get-tax-rate' )->execute( array( 'rate_id' => $res['id'] ) );
		$this->assertNotInstanceOf( WP_Error::class, $fetched );
		$this->assertSame( 'Test Rate', $fetched['name'] );
	}

	/**
	 * B51: a negative priority must be rejected, not sign-flipped by absint.
	 *
	 * The absint(-1) call returns 1, so a negative priority (or order) was silently persisted as its positive twin.
	 * The integer schema now carries minimum:0, so a negative is refused at input validation.
	 */
	public function test_create_tax_rate_rejects_a_negative_priority(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-tax-rate' )->execute(
			array(
				'rate'     => '10.0000',
				'priority' => -1,
			)
		);
		$this->assertInstanceOf(
			WP_Error::class,
			$res,
			'A negative priority must be refused, not stored as its absolute value.'
		);
	}

	// =========================================================================
	// aafm/wc-update-tax-rate
	// =========================================================================

	/**
	 * Update changes only the supplied field; unsupplied fields survive unchanged.
	 */
	public function test_update_tax_rate_changes_fields(): void {
		$this->acting_as( 'administrator' );

		$list             = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute( array() );
		$rate_id          = (int) $list['rates'][0]['id'];
		$original_country = $list['rates'][0]['country'];

		$res = wp_get_ability( 'aafm/wc-update-tax-rate' )->execute(
			array(
				'rate_id' => $rate_id,
				'name'    => 'Updated VAT',
			)
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Updated VAT', $res['name'] );
		// Country was not supplied; it must survive unchanged.
		$this->assertSame( $original_country, $res['country'] );
	}

	/**
	 * Updating an unknown id returns WP_Error.
	 */
	public function test_update_tax_rate_unknown_id_returns_wp_error(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-update-tax-rate' )->execute(
			array( 'rate_id' => 999999 )
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	// =========================================================================
	// aafm/wc-list-tax-classes
	// =========================================================================

	/**
	 * List includes the implicit Standard class plus the two seeded classes.
	 */
	public function test_list_tax_classes_returns_standard_plus_seeded(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-tax-classes' )->execute( array() );

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertArrayHasKey( 'classes', $res );
		$this->assertArrayHasKey( 'total', $res );

		$slugs = array_column( $res['classes'], 'slug' );
		$this->assertContains( 'standard', $slugs );
		$this->assertContains( 'reduced-rate', $slugs );
		$this->assertContains( 'zero-rate', $slugs );
		$this->assertSame( 3, $res['total'] );
	}

	/**
	 * Each class row has name and slug.
	 */
	public function test_list_tax_classes_shape(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-list-tax-classes' )->execute( array() );
		$this->assertNotInstanceOf( WP_Error::class, $res );

		foreach ( $res['classes'] as $class ) {
			$this->assertArrayHasKey( 'name', $class );
			$this->assertArrayHasKey( 'slug', $class );
		}
	}

	/**
	 * B54: wc-list-tax-rates was the only unbounded list - every sibling pages. It now accepts
	 * the standard page/per_page pair, slices the rows, and keeps total as the grand total.
	 */
	public function test_list_tax_rates_pages_like_every_other_list(): void {
		$this->acting_as( 'administrator' );

		$page1 = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute(
			array(
				'per_page' => 1,
				'page'     => 1,
			)
		);
		$page2 = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute(
			array(
				'per_page' => 1,
				'page'     => 2,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $page1, 'the list must accept the standard paging params.' );
		$this->assertNotInstanceOf( WP_Error::class, $page2 );
		$this->assertCount( 1, $page1['rates'] );
		$this->assertCount( 1, $page2['rates'] );
		$this->assertNotSame( $page1['rates'][0]['id'], $page2['rates'][0]['id'], 'pages must advance through the rate list.' );
		$this->assertSame( 2, $page1['total'], 'total is the grand total on every page.' );
		$this->assertSame( 2, $page2['total'] );
	}

	// =========================================================================
	// B30: unknown tax-class slugs on rate writes
	// =========================================================================

	/**
	 * B30: WooCommerce's format_tax_rate_class() maps any unknown class slug to '' (Standard), so
	 * a rate meant for "reduced-rate" with a typo silently landed in Standard, changed checkout
	 * tax, and reported success. The slug must be validated against the existing classes first.
	 */
	public function test_create_tax_rate_unknown_class_is_refused(): void {
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-create-tax-rate' )->execute(
			array(
				'rate'  => '5.0000',
				'class' => 'no-such-class',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res, 'an unknown tax class must be refused, never silently refiled into Standard.' );
		$this->assertSame( 'aafm_wc_unknown_tax_class', $res->get_error_code() );
		$this->assertStringContainsString( 'reduced-rate', $res->get_error_message(), 'the error must list the class slugs that do exist.' );
	}

	/**
	 * B30: the same guard applies on update - the stored class must survive a bad request.
	 */
	public function test_update_tax_rate_unknown_class_is_refused_and_nothing_changes(): void {
		$this->acting_as( 'administrator' );

		// Rate id 2 is seeded in the reduced-rate class.
		$before = wp_get_ability( 'aafm/wc-get-tax-rate' )->execute( array( 'rate_id' => 2 ) );
		$this->assertSame( 'reduced-rate', $before['class'] );

		$res = wp_get_ability( 'aafm/wc-update-tax-rate' )->execute(
			array(
				'rate_id' => 2,
				'class'   => 'typo-rate',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_wc_unknown_tax_class', $res->get_error_code() );

		$after = wp_get_ability( 'aafm/wc-get-tax-rate' )->execute( array( 'rate_id' => 2 ) );
		$this->assertSame( 'reduced-rate', $after['class'], 'a refused class change must leave the stored class untouched.' );
	}

	/**
	 * B30 control: a class slug that really exists is accepted and stored as sent.
	 */
	public function test_create_tax_rate_known_class_is_stored(): void {
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-create-tax-rate' )->execute(
			array(
				'rate'  => '5.0000',
				'class' => 'reduced-rate',
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'reduced-rate', $res['class'] );
	}

	// =========================================================================
	// aafm/wc-create-tax-class
	// =========================================================================

	/**
	 * Create adds a new class visible in the list.
	 */
	public function test_create_tax_class_succeeds(): void {
		$this->acting_as( 'administrator' );
		$res = wp_get_ability( 'aafm/wc-create-tax-class' )->execute(
			array( 'name' => 'Super Rate' )
		);
		$this->assertNotInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'super-rate', $res['slug'] );
		$this->assertSame( 'Super Rate', $res['name'] );

		// Confirm it appears in the list.
		$list  = wp_get_ability( 'aafm/wc-list-tax-classes' )->execute( array() );
		$slugs = array_column( $list['classes'], 'slug' );
		$this->assertContains( 'super-rate', $slugs );
	}

	/**
	 * B29: the old description promised a colliding slug "de-duplicates", but
	 * WC_Tax::create_tax_class() actually returns a WP_Error on collision. The true contract is
	 * a refusal, so a collision must surface as a clean, actionable error naming the slug.
	 */
	public function test_create_tax_class_colliding_slug_is_refused_with_actionable_error(): void {
		$this->acting_as( 'administrator' );

		// 'Reduced Rate' derives the slug 'reduced-rate', which seed_wc_tax() already stores.
		$res = wp_get_ability( 'aafm/wc-create-tax-class' )->execute(
			array( 'name' => 'Reduced Rate' )
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_wc_tax_class_exists', $res->get_error_code() );
		$this->assertStringContainsString( 'reduced-rate', $res->get_error_message(), 'the error must name the colliding slug.' );
	}

	/**
	 * B29: an explicitly requested colliding slug is refused the same way.
	 */
	public function test_create_tax_class_explicit_colliding_slug_is_refused(): void {
		$this->acting_as( 'administrator' );

		$res = wp_get_ability( 'aafm/wc-create-tax-class' )->execute(
			array(
				'name' => 'Something Else',
				'slug' => 'zero-rate',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'aafm_wc_tax_class_exists', $res->get_error_code() );
	}

	/**
	 * B29: the slug description must no longer promise the de-duplication WooCommerce never does.
	 */
	public function test_create_tax_class_description_matches_the_real_collision_contract(): void {
		$args        = aafm_args_wc_create_tax_class();
		$description = (string) $args['input_schema']['properties']['slug']['description'];
		$this->assertStringNotContainsString( 'reduced-rate-1', $description, 'the false de-duplication example must be gone.' );
		$this->assertStringContainsString( 'refused', $description, 'the description must state that a collision is refused.' );
	}

	/**
	 * Store failure returns WP_Error.
	 */
	public function test_create_tax_class_failure_returns_wp_error(): void {
		$this->acting_as( 'administrator' );
		WcTaxStubStore::$force_save_failure = true;
		$res                                = wp_get_ability( 'aafm/wc-create-tax-class' )->execute(
			array( 'name' => 'Failing Class' )
		);
		$this->assertInstanceOf( WP_Error::class, $res );
	}

	/**
	 * A concurrent request's create landing in the check-then-act window (simulated via the
	 * test-only aafm_wc_tax_class_check_passed hook) must not leave two classes sharing a slug.
	 */
	public function test_create_tax_class_concurrent_duplicate_slug_is_not_both_created(): void {
		$this->acting_as( 'administrator' );
		// 'Race class' is deliberately NOT one of seed_wc_tax()'s pre-seeded classes
		// (reduced-rate, zero-rate) - the point of this test is the early check passing clean and
		// the RACE catching the collision, not the early check catching an already-known one.
		$interleaved = false;
		$callback    = function ( string $slug ) use ( &$interleaved ) {
			// Simulate a second request's tax-class create landing in the window between this
			// request's collision check and its own \WC_Tax::create_tax_class() call.
			WcTaxStubStore::$classes[ $slug ] = 'Race class (concurrent)';
			$interleaved                      = true;
		};
		add_action( 'aafm_wc_tax_class_check_passed', $callback );

		$result = wp_get_ability( 'aafm/wc-create-tax-class' )->execute( array( 'name' => 'Race class' ) );

		remove_action( 'aafm_wc_tax_class_check_passed', $callback );

		$this->assertTrue( $interleaved, 'the simulated concurrent create did not run' );
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'the original request should have been refused once the slug was claimed underneath it'
		);
		$this->assertSame( 'aafm_wc_tax_class_exists', $result->get_error_code() );

		// Exactly one class is stored under this slug, holding the concurrent write's name, not
		// silently overwritten by a second create.
		$this->assertSame( 'Race class (concurrent)', WcTaxStubStore::$classes['race-class'] ?? null );
	}

	/**
	 * WC_Tax::get_tax_class_slugs()/get_tax_classes() cache their result under
	 * ['tax-rate-classes', 'taxes'] for the life of the request (class-wc-tax.php:818-833). A
	 * second call to aafm_wc_tax_class_collision_error() would silently reuse that cached
	 * snapshot instead of re-reading live state unless something explicitly evicts it first -
	 * proves the wp_cache_delete() call aafm_exec_wc_create_tax_class() makes immediately before
	 * its final guard actually does something, isolated from WC_Tax::create_tax_class()'s own
	 * (uncached, in this stub) duplicate check so this test can't pass by coincidence off a
	 * different layer catching the race instead (Codex review, 2026-09-05).
	 */
	public function test_tax_class_collision_check_forces_a_fresh_read_before_the_final_guard(): void {
		// First read (models the early check): the slug is free, populates the request cache.
		$this->assertNull( aafm_wc_tax_class_collision_error( 'cache-check', 'Cache check' ) );

		// A concurrent write lands directly in the backing store, the way another PHP-FPM
		// worker's own WC_Tax::create_tax_class() call would - it does not evict THIS process's
		// already-loaded runtime cache entry, only the shared store underneath it.
		WcTaxStubStore::$classes['cache-check'] = 'Cache check (concurrent)';

		// Without evicting the cache, a second read still returns the stale, pre-race snapshot.
		$this->assertNull(
			aafm_wc_tax_class_collision_error( 'cache-check', 'Cache check' ),
			'a cached read must not see the interleaved write - this documents why the fix is needed'
		);

		// The exact call aafm_exec_wc_create_tax_class() makes immediately before its final
		// guard. Once it runs, the next read reflects live state.
		wp_cache_delete( 'tax-rate-classes', 'taxes' );
		$fresh = aafm_wc_tax_class_collision_error( 'cache-check', 'Cache check' );
		$this->assertInstanceOf( WP_Error::class, $fresh );
		$this->assertSame( 'aafm_wc_tax_class_exists', $fresh->get_error_code() );
	}

	/**
	 * WC_Tax::create_tax_class() only checks is_wp_error() on $wpdb->insert()'s return, which is
	 * int|false and never WP_Error, so a real unique-index collision inside WC's own function can
	 * be reported as success. This proves the post-write confirmation catches that masked failure
	 * rather than trusting WC's return value.
	 */
	public function test_masked_wc_insert_failure_is_reported_as_an_error_not_a_false_success(): void {
		$this->acting_as( 'administrator' );
		// A genuinely free name/slug, not one of seed_wc_tax()'s pre-seeded classes - the point
		// of this test is the DEEPER post-write confirmation, which only ever runs once both the
		// early AND final collision checks (correctly) find nothing wrong. A colliding name here
		// (Codex review, 2026-09-05: the original draft used the pre-seeded "Reduced rate", which
		// let the early check refuse it before create_tax_class() was ever reached, leaving the
		// post-write confirmation this test claims to prove completely unexercised) would prove
		// nothing about that deeper path.
		WcTaxStubStore::$simulate_masked_insert_failure = true;

		$result = wp_get_ability( 'aafm/wc-create-tax-class' )->execute( array( 'name' => 'Fresh class' ) );

		WcTaxStubStore::$simulate_masked_insert_failure = false;

		// Both collision checks pass clean (the slug was genuinely free), so WC_Tax::create_tax_class()
		// is reached and reports its masked "success" - name never actually stored. The post-write
		// confirmation (aafm_wc_tax_class_write_unconfirmed) is what catches it here, not either
		// collision check.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aafm_wc_tax_class_write_unconfirmed', $result->get_error_code() );
	}

	// =========================================================================
	// Audit: create-tax-rate
	// =========================================================================

	/**
	 * Successful create-tax-rate is recorded in the activity log.
	 */
	public function test_create_tax_rate_audit_success(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-create-tax-rate' )->execute( array( 'rate' => '3.0000' ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-create-tax-rate', $abilities );
	}

	/**
	 * Denied create-tax-rate is recorded in the activity log.
	 */
	public function test_create_tax_rate_audit_deny(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-create-tax-rate' )->check_permissions( array( 'rate' => '3.0000' ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-create-tax-rate', $abilities );
	}

	// =========================================================================
	// Audit: update-tax-rate
	// =========================================================================

	/**
	 * Successful update-tax-rate is recorded in the activity log.
	 */
	public function test_update_tax_rate_audit_success(): void {
		$this->acting_as( 'administrator' );

		$list    = wp_get_ability( 'aafm/wc-list-tax-rates' )->execute( array() );
		$rate_id = (int) $list['rates'][0]['id'];

		wp_get_ability( 'aafm/wc-update-tax-rate' )->execute(
			array(
				'rate_id' => $rate_id,
				'name'    => 'Audit VAT',
			)
		);

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-update-tax-rate', $abilities );
	}

	/**
	 * Denied update-tax-rate is recorded in the activity log.
	 */
	public function test_update_tax_rate_audit_deny(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-update-tax-rate' )->check_permissions( array( 'rate_id' => 1 ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-update-tax-rate', $abilities );
	}

	// =========================================================================
	// Audit: create-tax-class
	// =========================================================================

	/**
	 * Successful create-tax-class is recorded in the activity log.
	 */
	public function test_create_tax_class_audit_success(): void {
		$this->acting_as( 'administrator' );
		wp_get_ability( 'aafm/wc-create-tax-class' )->execute( array( 'name' => 'Audit Class' ) );

		$success   = aafm_query_activity( array( 'status' => 'success' ) );
		$abilities = wp_list_pluck( $success, 'ability' );
		$this->assertContains( 'aafm/wc-create-tax-class', $abilities );
	}

	/**
	 * Denied create-tax-class is recorded in the activity log.
	 */
	public function test_create_tax_class_audit_deny(): void {
		$this->acting_as( 'editor' );
		wp_get_ability( 'aafm/wc-create-tax-class' )->check_permissions( array( 'name' => 'Denied' ) );

		$denied    = aafm_query_activity( array( 'status' => 'denied' ) );
		$abilities = wp_list_pluck( $denied, 'ability' );
		$this->assertContains( 'aafm/wc-create-tax-class', $abilities );
	}

	/**
	 * FIX-3 item 1 (pilot finding, delegation sweep): the by-id single-rate read now delegates to
	 * WC_Tax::_get_tax_rate() instead of a hand-rolled $wpdb->get_row(). Both queries read the
	 * identical table with no caching or hook on either side, so there is no behavioural
	 * difference to drive a test red - this pins the source-level fact instead, as the pilot's own
	 * finding predicted, and states plainly it could not go red any other way.
	 */
	public function test_get_tax_rate_by_id_delegates_to_wc_tax(): void {
		$source = (string) file_get_contents( AAFM_PLUGIN_DIR . 'includes/abilities/woocommerce/tax.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local test fixture, not a remote URL.
		$this->assertStringContainsString(
			'\WC_Tax::_get_tax_rate( $rate_id, ARRAY_A )',
			$source,
			'aafm_wc_get_tax_rate_by_id() must delegate to WC_Tax::_get_tax_rate(), the same by-id read WooCommerce\'s own REST controller uses.'
		);
	}
}
