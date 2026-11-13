<?php
/**
 * Stand-in for the Avada theme class, loaded by AvadaGlobalOptionsTest only. Its get_option_name()
 * has the signature and body of the real one in themes/Avada/includes/class-avada.php (7.16.1); the
 * two names are public here so a test can set the per-language name the theme sets itself.
 *
 * @package AgentAbilitiesForMCP
 */

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.PrefixAllGlobals -- it stands in for the theme's own class name.

/**
 * The Avada theme class, reduced to its option name.
 */
class Avada {

	/**
	 * The per-language option name, empty when none was set.
	 *
	 * @var string
	 */
	public static $option_name = '';

	/**
	 * The default option name.
	 *
	 * @var string
	 */
	public static $original_option_name = 'fusion_options';

	/**
	 * As Avada::get_option_name().
	 *
	 * @return string
	 */
	public static function get_option_name() {
		if ( empty( self::$option_name ) ) {
			return self::$original_option_name;
		}
		return self::$option_name;
	}
}
