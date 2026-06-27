<?php
/**
 * Test fixture: OUR bundled copy of the same WP\MCP\ class the foreign fixture declares.
 *
 * Requiring this file after WP\MCP\Collide\Marker already exists would throw an uncatchable
 * "Cannot declare class … already in use" fatal — the bug FIX 1 guards against. The eager
 * loader must skip it (keeping the foreign copy) rather than require it.
 *
 * @package AgentAbilitiesForMCP
 */

namespace WP\MCP\Collide;

class Marker {
	const SOURCE = 'bundle';
}
