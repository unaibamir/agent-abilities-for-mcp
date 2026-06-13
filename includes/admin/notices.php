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
 * @param string              $variant warning|info|success|error (unknown → info).
 * @param string              $message Plain text (escaped) or pre-kses'd HTML when $args['html'].
 * @param array<string,mixed> $args    dashicon (override), inline (bool), html (bool).
 * @return string
 */
function aafm_get_notice_html( string $variant, string $message, array $args = array() ): string {
	$icons = array(
		'warning' => 'dashicons-warning',
		'info'    => 'dashicons-info',
		'success' => 'dashicons-yes-alt',
		'error'   => 'dashicons-dismiss',
	);
	if ( ! isset( $icons[ $variant ] ) ) {
		$variant = 'info';
	}
	$dashicon = isset( $args['dashicon'] ) ? sanitize_html_class( (string) $args['dashicon'] ) : $icons[ $variant ];
	$inline   = empty( $args['inline'] ) ? '' : ' aafm-notice-inline';
	$body     = empty( $args['html'] ) ? esc_html( $message ) : $message;

	return sprintf(
		'<div class="aafm-notice aafm-notice-%1$s%2$s"><span class="dashicons %3$s" aria-hidden="true"></span><div class="aafm-notice-body">%4$s</div></div>',
		esc_attr( $variant ),
		esc_attr( $inline ),
		esc_attr( $dashicon ),
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
