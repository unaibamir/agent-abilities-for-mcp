<?php
/**
 * Standing guard for the readonly-but-writes class.
 *
 * Reflects every read/readonly ability's execute callback (and one hop of aafm_* delegation) and
 * fails if any of them makes a write-shaped call the annotation swears it does not. This is the
 * cheap, always-on gate that runs before a wave of new abilities is authored: an ability grouped
 * `risk: read` / annotated `readonly: true` that quietly mutates state is a security-and-trust bug
 * agents cannot see, and unit tests of the callback's happy path never surface it.
 *
 * Enumerates the bare unit registry, which holds the core / always-on reads. Integration reads
 * (wc-*, acf-*, seo-*) only join the registry when their host plugin is active, so they are covered
 * by the parallel contract test (tests/contract/AnnotationCorrectnessContractTest.php) where the
 * real vendors are loaded.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\Support\AnnotationScanner;
use AAFM\Tests\TestCase;

require_once dirname( __DIR__ ) . '/Fixtures/AnnotationScannerFixtures.php';

final class AnnotationCorrectnessTest extends TestCase {

	/**
	 * No read/readonly ability may make an unsuppressed write-shaped call.
	 */
	public function test_no_readonly_ability_writes(): void {
		$result = AnnotationScanner::scan( aafm_get_abilities_registry() );

		// A broken enumeration that scans nothing must not pass silently: this env always exposes
		// the core reads, so a zero here means the scanner stopped seeing the registry, not that
		// the plugin is clean.
		$this->assertGreaterThan(
			0,
			$result['scanned'],
			'The scanner found no read/readonly abilities to check, so enumeration is broken.'
		);

		$this->assertSame(
			array(),
			$result['violations'],
			sprintf(
				"A readonly/read ability makes a write-shaped call (ability | claim | write-call | file:line):\n%s",
				AnnotationScanner::format( $result['violations'] )
			)
		);
	}

	/**
	 * A closure execute_callback on a claimed-read ability must be a violation, not a silent pass.
	 *
	 * This is the exact hole the gate had: $execute went null for a closure and the row was
	 * dropped by the same continue that legitimately skips non-read abilities.
	 */
	public function test_closure_execute_callback_on_a_readonly_ability_is_a_violation(): void {
		$registry = array(
			'aafm/fixture-closure-read' => array(
				'risk'         => 'read',
				'args_builder' => 'aafm_test_fixture_closure_args',
			),
		);

		$result = AnnotationScanner::scan( $registry );

		$this->assertNotEmpty(
			$result['violations'],
			'A readonly ability whose execute_callback is a closure must be a violation, not a silent pass.'
		);
		$this->assertSame( 'aafm/fixture-closure-read', $result['violations'][0]['ability'] );
	}

	/**
	 * An args_builder that names no real function is a violation on a claimed-read ability, not an
	 * empty-args pass. build_args() used to return array() for both "no builder" and "builder
	 * unresolvable", and an empty array read exactly like a legitimate non-read row.
	 */
	public function test_unresolvable_args_builder_on_a_readonly_ability_is_a_violation(): void {
		$registry = array(
			'aafm/fixture-no-builder' => array(
				'risk'         => 'read',
				'args_builder' => 'aafm_test_fixture_builder_that_does_not_exist',
			),
		);

		$result = AnnotationScanner::scan( $registry );

		$this->assertNotEmpty( $result['violations'] );
	}

	/**
	 * A named, resolvable execute_callback (function_exists() true, is_string() true) that still
	 * cannot be reflected - an internal PHP function has no source - must be a violation, not a
	 * pass. The closure/missing-name guard checks whether $execute is a string at all; it never
	 * checks whether that string is actually readable, so this string execute_callback sailed
	 * through the exact same hole from the other side.
	 */
	public function test_unreflectable_named_function_on_a_readonly_ability_is_a_violation(): void {
		$registry = array(
			'aafm/fixture-internal-function-read' => array(
				'risk'         => 'read',
				'args_builder' => 'aafm_test_fixture_internal_function_args',
			),
		);

		$result = AnnotationScanner::scan( $registry );

		$this->assertNotEmpty(
			$result['violations'],
			'A readonly ability whose execute_callback cannot be reflected must be a violation, not a silent pass.'
		);
		$this->assertSame( 'aafm/fixture-internal-function-read', $result['violations'][0]['ability'] );
	}

	/**
	 * A write reached only through dynamic dispatch (call_user_func() naming the delegate at
	 * runtime, never a literal aafm_*( call in source) must be a violation. The one-hop delegate
	 * scanner only follows literal calls it can see in the comment-stripped source; it has no way
	 * to know call_user_func('aafm_writer') means the same thing, so the delegate - and the write
	 * inside it - was never reached.
	 */
	public function test_dynamic_dispatch_hiding_a_write_is_a_violation(): void {
		$registry = array(
			'aafm/fixture-dynamic-dispatch-read' => array(
				'risk'         => 'read',
				'args_builder' => 'aafm_test_fixture_dynamic_dispatch_args',
			),
		);

		$result = AnnotationScanner::scan( $registry );

		$this->assertNotEmpty(
			$result['violations'],
			'A readonly ability that reaches a write only through dynamic dispatch must be a violation, not a silent pass.'
		);
		$this->assertSame( 'aafm/fixture-dynamic-dispatch-read', $result['violations'][0]['ability'] );
	}

	/**
	 * A gate that cannot say what it did not look at will hide the next hole the way it hid this
	 * one: every ability the scan passes over, for a legitimate reason, must be named and the
	 * reason recorded, not folded into an unexplained gap between the registry size and `scanned`.
	 */
	public function test_scan_reports_what_it_skipped_and_why(): void {
		$result = AnnotationScanner::scan( aafm_get_abilities_registry() );

		$this->assertArrayHasKey( 'skipped', $result );
		$this->assertIsInt( $result['scanned'] );
		foreach ( $result['skipped'] as $row ) {
			$this->assertArrayHasKey( 'ability', $row );
			$this->assertNotSame( '', $row['reason'], 'Every skip must carry a reason.' );
		}
	}
}
