<?php
/**
 * The activity-log event-type vocabulary: the five literals every later caller binds to.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Audit;

use AAFM\Tests\TestCase;

final class EventTypeTest extends TestCase {

	public function test_event_type_vocabulary_is_the_documented_five(): void {
		$this->assertSame(
			array( 'ability_call', 'ability_enabled', 'ability_disabled', 'setting_changed', 'log_cleared' ),
			aafm_activity_event_types()
		);
	}

	public function test_ability_call_is_first_so_it_reads_as_the_default(): void {
		$types = aafm_activity_event_types();
		$this->assertSame( 'ability_call', $types[0] );
	}
}
