<?php
declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class MenuStructureTest extends TestCase {

	public function test_admin_tabs_map_has_expected_slugs(): void {
		$tabs = aafm_admin_tabs();
		$this->assertSame(
			array( 'dashboard', 'connection', 'abilities', 'settings', 'activity', 'help' ),
			array_keys( $tabs )
		);
		$this->assertSame( 'Dashboard', $tabs['dashboard'] );
	}
}
