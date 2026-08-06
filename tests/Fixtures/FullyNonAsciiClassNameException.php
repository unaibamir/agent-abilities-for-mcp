<?php
/**
 * A throwable whose class name survives the audit detail builder's character filter not at all.
 *
 * PHP identifiers admit bytes \x80-\xff, and a class at GLOBAL scope has no namespace separators to
 * survive either, so this is the one name the filter can empty completely. That is the most lossy
 * case there is, and it used to be the one case the lossy marker missed.
 *
 * It lives in its own file, outside the namespace and outside PSR-4, for both of those reasons: the
 * autoloader cannot map this name to a file, and a namespace would leave ASCII behind. The test
 * requires the file directly.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

// phpcs:ignore PEAR.NamingConventions.ValidClassName.StartWithCapital, Squiz.Classes.ValidClassName.NotCamelCaps -- the point of the fixture is a name no convention covers.
final class Ééü extends \RuntimeException {
}
