<?php
/**
 * Reusable admin notice/callout component (four WP-native variants).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Build the HTML for an admin notice. The message is escaped unless $args['html'] is true,
 * in which case the caller is responsible for having run it through wp_kses already.
 *
 * The leading glyph is an inline SVG from aafm_icon(), keyed by variant. Callers can
 * override it with the `icon` arg (an aafm_icon name). The legacy `dashicon` arg is still
 * accepted for back-compat and mapped to the closest aafm_icon glyph.
 *
 * @param string              $variant warning|info|success|error (unknown → info).
 * @param string              $message Plain text (escaped) or pre-kses'd HTML when $args['html'].
 * @param array<string,mixed> $args    icon (override aafm_icon name), dashicon (legacy override), inline (bool), html (bool).
 * @return string
 */
function aafm_get_notice_html( string $variant, string $message, array $args = array() ): string {
	$icons = array(
		'warning' => 'warning',
		'info'    => 'info',
		'success' => 'success',
		'error'   => 'error',
	);
	if ( ! isset( $icons[ $variant ] ) ) {
		$variant = 'info';
	}

	// Back-compat: map the old dashicon override names to the closest aafm_icon glyph.
	$dashicon_map = array(
		'dashicons-warning' => 'warning',
		'dashicons-info'    => 'info',
		'dashicons-yes-alt' => 'success',
		'dashicons-dismiss' => 'error',
		'dashicons-shield'  => 'shield',
	);

	$icon_name = $icons[ $variant ];
	if ( isset( $args['icon'] ) ) {
		$icon_name = (string) $args['icon'];
	} elseif ( isset( $args['dashicon'] ) ) {
		$legacy    = (string) $args['dashicon'];
		$icon_name = $dashicon_map[ $legacy ] ?? $icons[ $variant ];
	}

	$inline = empty( $args['inline'] ) ? '' : ' aafm-notice-inline';
	$body   = empty( $args['html'] ) ? esc_html( $message ) : $message;

	return sprintf(
		'<div class="aafm-notice aafm-notice-%1$s%2$s"><span class="aafm-notice-ic">%3$s</span><div class="aafm-notice-body">%4$s</div></div>',
		esc_attr( $variant ),
		esc_attr( $inline ),
		aafm_icon( $icon_name ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
		$body
	);
}

/**
 * Echo an admin notice. See aafm_get_notice_html().
 *
 * @param string              $variant Variant slug.
 * @param string              $message Message.
 * @param array<string,mixed> $args    Options.
 * @return void
 */
function aafm_render_notice( string $variant, string $message, array $args = array() ): void {
	echo aafm_get_notice_html( $variant, $message, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped inside the helper.
}
