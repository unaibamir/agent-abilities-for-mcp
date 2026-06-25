<?php
/**
 * Reusable admin section + set-row render helpers (the shared component layer).
 *
 * These are STRUCTURAL wrappers only — they never sanitize the body/control/pill
 * markup; the call site escapes its own leaves (the file's established
 * leaf-escape convention, mirrored from includes/admin/notices.php). Do NOT pass
 * raw user data as `body`, `control`, or `pill`: those args are echoed verbatim.
 * Dynamic plain-text args (title/description/label/opt/help) ARE escaped here.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Echo a settings section wrapper.
 *
 * Non-collapsible (default) renders a `<section class="oversio-section oversio-card">`
 * with a `.oversio-card-head` (icon/title/description/pill) and a
 * `.oversio-card-pad oversio-section-body` body. Collapsible renders a
 * `<details class="oversio-section oversio-section--collapsible">` with a `<summary>`
 * (title + optional badge) and a `.oversio-section-body` body, honoring `open` only
 * when `collapsible` is true.
 *
 * STRUCTURAL ONLY: `body`/`pill` are echoed verbatim — the caller must have
 * escaped them. `title`/`description` are escaped here with esc_html.
 *
 * Recognised $args keys: `id` (wrapper id attribute), `title` (heading, escaped),
 * `icon` (oversio_icon() key for the head glyph), `description` (sub-heading, escaped),
 * `pill` (pre-built pill HTML, echoed verbatim), `collapsible` (bool, default false),
 * `open` (bool, default true, honored only when collapsible), `badge` (count-badge
 * text for the summary, escaped), and `body` (pre-escaped inner HTML, echoed verbatim).
 *
 * @param array<string,mixed> $args Section args (see the recognised keys above).
 * @return void
 */
function oversio_render_section( array $args ): void {
	$id          = isset( $args['id'] ) ? (string) $args['id'] : '';
	$title       = isset( $args['title'] ) ? (string) $args['title'] : '';
	$icon        = isset( $args['icon'] ) ? (string) $args['icon'] : '';
	$description = isset( $args['description'] ) ? (string) $args['description'] : '';
	$pill        = isset( $args['pill'] ) ? (string) $args['pill'] : '';
	$collapsible = ! empty( $args['collapsible'] );
	$open        = ! isset( $args['open'] ) || ! empty( $args['open'] );
	$badge       = isset( $args['badge'] ) ? (string) $args['badge'] : '';
	$body        = isset( $args['body'] ) ? (string) $args['body'] : '';

	$id_attr = '' === $id ? '' : sprintf( ' id="%s"', esc_attr( $id ) );

	if ( $collapsible ) {
		$open_attr  = $open ? ' open' : '';
		$badge_html = '' === $badge
			? ''
			: sprintf( ' <span class="oversio-count-badge">%s</span>', esc_html( $badge ) );

		printf(
			'<details class="oversio-section oversio-section--collapsible"%1$s%2$s><summary>%3$s%4$s</summary><div class="oversio-section-body">%5$s</div></details>',
			$id_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd above.
			esc_attr( $open_attr ),
			esc_html( $title ),
			$badge_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html'd above.
			$body // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by the caller (leaf-escape convention).
		);
		return;
	}

	$icon_html = '' === $icon
		? ''
		: sprintf( '<span class="oversio-card-head-ic">%s</span>', oversio_icon( $icon ) );
	$desc_html = '' === $description
		? ''
		: sprintf( '<p class="oversio-card-head-desc">%s</p>', esc_html( $description ) );

	printf(
		'<section class="oversio-section oversio-card"%1$s><div class="oversio-card-head">%2$s<div class="oversio-card-head-text"><h3 class="oversio-card-head-title">%3$s</h3>%4$s</div>%5$s</div><div class="oversio-card-pad oversio-section-body">%6$s</div></section>',
		$id_attr, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr'd above.
		$icon_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG from oversio_icon().
		esc_html( $title ),
		$desc_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html'd above.
		$pill, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built pill HTML, escaped by the caller.
		$body // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by the caller (leaf-escape convention).
	);
}

/**
 * Echo a labelled set-row: a `.oversio-set-label` (label text + optional `.opt`
 * sub-label) beside a `.oversio-set-control` (the pre-built control markup), with an
 * optional `.help` element below.
 *
 * STRUCTURAL ONLY: `control` is echoed verbatim — the caller owns its escaping.
 * `label`/`opt`/`help` are escaped here with esc_html.
 *
 * Recognised $args keys: `label` (row label, escaped), `opt` (optional sub-label
 * rendered in a `<span class="opt">`, escaped), `control` (pre-built control HTML,
 * echoed verbatim), and `help` (optional help text in a `.help` element, escaped).
 *
 * @param array<string,mixed> $args Row args (see the recognised keys above).
 * @return void
 */
function oversio_render_set_row( array $args ): void {
	$label   = isset( $args['label'] ) ? (string) $args['label'] : '';
	$opt     = isset( $args['opt'] ) ? (string) $args['opt'] : '';
	$control = isset( $args['control'] ) ? (string) $args['control'] : '';
	$help    = isset( $args['help'] ) ? (string) $args['help'] : '';

	$opt_html  = '' === $opt
		? ''
		: sprintf( ' <span class="opt">%s</span>', esc_html( $opt ) );
	$help_html = '' === $help
		? ''
		: sprintf( '<p class="help">%s</p>', esc_html( $help ) );

	printf(
		'<div class="oversio-set-row"><div class="oversio-set-label">%1$s%2$s</div><div class="oversio-set-control">%3$s%4$s</div></div>',
		esc_html( $label ),
		$opt_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html'd above.
		$control, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built control HTML, escaped by the caller.
		$help_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html'd above.
	);
}
