<?php
/**
 * Capture-only state switches. Lives on the disposable site only; does nothing without ?aafm_shot=.
 * State changes per request through query args, so nothing is written to the database between shots.
 */
if ( ! defined( 'ABSPATH' ) || ! isset( $_GET['aafm_shot'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return;
}
$aafm_shot = preg_replace( '/[^a-z-]/', '', (string) $_GET['aafm_shot'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput
if ( 'wizard' === $aafm_shot ) {
	// get_option() short-circuits only on a value that is not false (wp-includes/option.php:132-137), so
	// '__return_false' would be ignored. '' is compared against '1' by aafm_quickconnect_is_finished().
	add_filter( 'pre_option_aafm_quickconnect_finished', '__return_empty_string' );
	add_filter( 'pre_option_aafm_quickconnect_dismissed', '__return_empty_string' );
	// The wizard also stays hidden for a site that already looks configured (quickconnect.php
	// aafm_quickconnect_site_looks_configured(): an enabled ability, an active OAuth client, or any
	// agent call). Show the first-run state without touching the data.
	$aafm_empty = static function () {
		return array();
	};
	add_filter( 'pre_option_aafm_enabled_abilities', $aafm_empty );
	add_filter( 'pre_option_aafm_enabled_bridged_abilities', $aafm_empty );
	add_filter(
		'query',
		static function ( $sql ) {
			// The two COUNT(*) reads behind "active clients" and "agent calls". If the wizard still does
			// not render, shoot screenshot 1 first with AAFM_SHOTS_SEED=0 (see the tooling README).
			if ( is_string( $sql ) && false !== strpos( $sql, 'SELECT COUNT(*)' ) && ( false !== strpos( $sql, 'is_active = 1' ) || false !== strpos( $sql, 'ability_call' ) ) ) {
				return 'SELECT 0';
			}
			return $sql;
		}
	);
}
if ( 'readonly' === $aafm_shot ) {
	// The Settings checkbox shows the STORED value, the Abilities tab follows the effective one: set both.
	add_filter( 'aafm_force_read_only_mode', '__return_true' );
	add_filter(
		'pre_option_aafm_read_only_mode',
		static function () {
			return '1';
		}
	);
}
