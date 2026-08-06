<?php
/**
 * A throwable whose class name is legal PHP but not ASCII.
 *
 * PHP identifiers admit bytes \x80-\xff, so the audit detail builder's character filter can strip
 * an accent out of a real class name and leave a DIFFERENT real class name behind. This fixture is
 * exactly that case: filtered, "Excepétion" reads as the ordinary "Exception".
 *
 * It lives in its own file because the PSR-4 autoloader maps a class name to a file name, and this
 * class name cannot be one. The test requires the file directly.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Fixtures;

final class Excepétion extends \RuntimeException {
}
