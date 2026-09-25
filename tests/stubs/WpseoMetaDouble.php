<?php
/**
 * A test double for the part of Yoast SEO's WPSEO_Meta that decides what a post meta write
 * stores. Yoast deletes a field's row, and reports the write as done, when the value written is
 * that field's default; its readers then return the default for the missing row. The methods
 * below copy wordpress-seo 28.5, inc/class-wpseo-meta.php:590-603 (remove_meta_if_default) and
 * :633-635 (meta_value_is_default), with the default of each field the plugin writes.
 *
 * Loaded by the tests that use it, never by the bootstrap. Each test attaches the filter itself.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class WpseoMetaDouble {

	/**
	 * Declare WPSEO_Meta once per process, unless a real one already exists.
	 */
	public static function load(): void {
		if ( class_exists( 'WPSEO_Meta' ) ) {
			return;
		}
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a class stub for tests; never shipped.
		eval(
			<<<'PHP'
class WPSEO_Meta {
	public static $defaults = array(
		'_yoast_wpseo_title'                  => '',
		'_yoast_wpseo_metadesc'               => '',
		'_yoast_wpseo_focuskw'                => '',
		'_yoast_wpseo_canonical'              => '',
		'_yoast_wpseo_meta-robots-noindex'    => '0',
		'_yoast_wpseo_meta-robots-nofollow'   => '0',
		'_yoast_wpseo_meta-robots-adv'        => '',
		'_yoast_wpseo_opengraph-title'        => '',
		'_yoast_wpseo_opengraph-description'  => '',
		'_yoast_wpseo_opengraph-image'        => '',
		'_yoast_wpseo_twitter-title'          => '',
		'_yoast_wpseo_twitter-description'    => '',
		'_yoast_wpseo_twitter-image'          => '',
	);

	public static function remove_meta_if_default( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		if ( isset( self::$defaults[ $meta_key ] ) && self::meta_value_is_default( $meta_key, $meta_value ) === true ) {
			if ( $prev_value !== '' ) {
				delete_post_meta( $object_id, $meta_key, $prev_value );
			} else {
				delete_post_meta( $object_id, $meta_key );
			}
			return true;
		}
		return $check;
	}

	public static function meta_value_is_default( $meta_key, $meta_value ) {
		return ( isset( self::$defaults[ $meta_key ] ) && $meta_value === self::$defaults[ $meta_key ] );
	}
}
PHP
		);
	}

	/**
	 * Attach the double's filter the way Yoast does (wordpress-seo 28.5 class-wpseo-meta.php:342).
	 */
	public static function attach(): void {
		self::load();
		add_filter( 'update_post_metadata', array( 'WPSEO_Meta', 'remove_meta_if_default' ), 10, 5 );
	}

	/**
	 * Detach the double's filter.
	 */
	public static function detach(): void {
		remove_filter( 'update_post_metadata', array( 'WPSEO_Meta', 'remove_meta_if_default' ), 10 );
	}
}
