<?php
/**
 * Test fixture: an adapter tools/list handler with the per-connection capability gate STRIPPED.
 *
 * Stands in for a sibling's in-range adapter copy that reports a compatible version yet has had the
 * per-connection tools/list capability filter call removed, so our registered gate callback would
 * never fire. Its list_tools() returns the tools unfiltered.
 *
 * @package AgentAbilitiesForMCP
 */

namespace AAFM\Tests\Fixtures\CapabilityGate\Stripped;

class ToolsHandler {

	/**
	 * Return the tool list without applying any capability filter.
	 *
	 * @param array<int,mixed> $tools Tool DTOs.
	 * @return array<int,mixed>
	 */
	public function list_tools( array $tools ): array {
		// The per-connection capability filter has been removed from this copy.
		return $tools;
	}
}
