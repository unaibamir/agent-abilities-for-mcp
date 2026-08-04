<?php
/**
 * Reusable admin section + set-row render helpers (the shared component layer).
 *
 * These are STRUCTURAL wrappers only - they never sanitize the body/control/pill
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
 * Non-collapsible (default) renders a `<section class="aafm-section aafm-card">`
 * with a `.aafm-card-head` (icon/title/description/pill) and a
 * `.aafm-card-pad aafm-section-body` body. Collapsible renders a
 * `<details class="aafm-section aafm-section--collapsible">` with a `<summary>`
 * (title + optional badge) and a `.aafm-section-body` body, honoring `open` only
 * when `collapsible` is true.
 *
 * STRUCTURAL ONLY: `body`/`pill` are echoed verbatim - the caller must have
 * escaped them. `title`/`description` are escaped here with esc_html.
 *
 * Recognised $args keys: `id` (wrapper id attribute), `class` (extra wrapper classes,
 * sanitized per class name), `title` (heading, escaped), `icon` (aafm_icon() key for the
 * head glyph), `description` (sub-heading, escaped), `pill` (pre-built pill HTML, echoed
 * verbatim), `collapsible` (bool, default false), `open` (bool, default true, honored only
 * when collapsible), `badge` (count-badge text for the summary, escaped), and `body`
 * (pre-escaped inner HTML, echoed verbatim).
 *
 * @param array<string,mixed> $args Section args (see the recognised keys above).
 * @return void
 */
function aafm_render_section( array $args ): void {
	$id          = isset( $args['id'] ) ? (string) $args['id'] : '';
	$extra_class = isset( $args['class'] ) ? (string) $args['class'] : '';
	$title       = isset( $args['title'] ) ? (string) $args['title'] : '';
	$icon        = isset( $args['icon'] ) ? (string) $args['icon'] : '';
	$description = isset( $args['description'] ) ? (string) $args['description'] : '';
	$pill        = isset( $args['pill'] ) ? (string) $args['pill'] : '';
	$collapsible = ! empty( $args['collapsible'] );
	$open        = ! isset( $args['open'] ) || ! empty( $args['open'] );
	$badge       = isset( $args['badge'] ) ? (string) $args['badge'] : '';
	$body        = isset( $args['body'] ) ? (string) $args['body'] : '';

	$id_attr = '' === $id ? '' : sprintf( ' id="%s"', esc_attr( $id ) );

	// Each extra class goes through sanitize_html_class() individually, so a caller cannot smuggle
	// a second attribute in through the class list.
	$extra = '';
	foreach ( array_filter( explode( ' ', $extra_class ) ) as $one ) {
		$one = sanitize_html_class( $one );
		if ( '' !== $one ) {
			$extra .= ' ' . $one;
		}
	}

	if ( $collapsible ) {
		$open_attr  = $open ? ' open' : '';
		$badge_html = '' === $badge
			? ''
			: sprintf( ' <span class="aafm-count-badge">%s</span>', esc_html( $badge ) );

		echo wp_kses(
			sprintf(
				'<details class="aafm-section aafm-section--collapsible%1$s"%2$s%3$s><summary>%4$s%5$s</summary><div class="aafm-section-body">%6$s</div></details>',
				esc_attr( $extra ),
				$id_attr,
				esc_attr( $open_attr ),
				esc_html( $title ),
				$badge_html,
				$body
			),
			aafm_admin_allowed_html()
		);
		return;
	}

	$icon_html = '' === $icon
		? ''
		: sprintf( '<span class="aafm-card-head-ic">%s</span>', aafm_icon( $icon ) );
	$desc_html = '' === $description
		? ''
		: sprintf( '<p class="aafm-card-head-desc">%s</p>', esc_html( $description ) );

	echo wp_kses(
		sprintf(
			'<section class="aafm-section aafm-card%1$s"%2$s><div class="aafm-card-head">%3$s<div class="aafm-card-head-text"><h3 class="aafm-card-head-title">%4$s</h3>%5$s</div>%6$s</div><div class="aafm-card-pad aafm-section-body">%7$s</div></section>',
			esc_attr( $extra ),
			$id_attr,
			$icon_html,
			esc_html( $title ),
			$desc_html,
			$pill,
			$body
		),
		aafm_admin_allowed_html()
	);
}

/**
 * Echo the toggle control for one ability row: a lock icon when a floor is holding the ability
 * down, or a checkbox otherwise.
 *
 * Shared by aafm_render_ability_row() (the Abilities tab) and
 * aafm_render_integration_ability_row() (the Integrations tab) so both surfaces render the same
 * lock treatment from one place. This exists because they didn't: the Integrations renderer never
 * learned about the lock at all and kept emitting a live checkbox for a locked ability, which is
 * exactly how a high-risk ability got enabled, saved, and logged from a card whose master switch
 * was off.
 *
 * A locked row emits NO <input> at all, rather than a disabled one. The bulk enable-all controls
 * query `input[type="checkbox"][name="aafm_abilities[]"]` inside the card, so a greyed-out but
 * present checkbox would still be swept in by them. That is the 1.5.0 bug and it must not come back.
 *
 * STRUCTURAL ONLY, same convention as the rest of this file: `$name` and `$title_id` are escaped
 * here; the caller owns everything else about the row.
 *
 * @param string            $name          Ability name.
 * @param string            $title_id      Id of the row's <h4>, referenced via aria-labelledby.
 * @param array<int,string> $enabled       Enabled ability names.
 * @param bool              $host_disabled True when the row's host integration is inactive - an
 *                                         unlocked checkbox then renders disabled so it never
 *                                         submits. Ignored when the ability is locked, since a
 *                                         locked row never renders a checkbox at all.
 * @return string|null The lock reason ('read_only' or 'high_risk') when the row is locked, so the
 *                     caller can render the matching note, or null when it is freely toggleable.
 */
function aafm_render_ability_toggle_control( string $name, string $title_id, array $enabled, bool $host_disabled = false ): ?string {
	$reason = aafm_ability_lock_reason( $name );

	if ( null !== $reason ) {
		printf(
			'<span class="aafm-ability-locked" role="img" aria-label="%1$s">%2$s</span>',
			esc_attr__( 'Locked', 'agent-abilities-for-mcp' ),
			wp_kses( aafm_icon( 'lock' ), aafm_svg_allowed_html() )
		);
		return $reason;
	}

	printf(
		'<label class="aafm-switch"><input type="checkbox" name="aafm_abilities[]" value="%1$s" aria-labelledby="%2$s" %3$s%4$s><span class="aafm-switch-track"></span></label>',
		esc_attr( $name ),
		esc_attr( $title_id ),
		$host_disabled ? '' : checked( in_array( $name, $enabled, true ), true, false ),
		$host_disabled ? ' disabled' : ''
	);

	return null;
}

/**
 * Echo a labelled set-row: a `.aafm-set-label` (label text + optional `.opt`
 * sub-label) beside a `.aafm-set-control` (the pre-built control markup), with an
 * optional `.help` element below.
 *
 * STRUCTURAL ONLY: `control` and `pill` are echoed verbatim - the caller owns their
 * escaping. `label`/`opt`/`help` are escaped here with esc_html.
 *
 * Recognised $args keys: `label` (row label, escaped), `opt` (optional sub-label
 * rendered in a `<span class="opt">`, escaped), `class` (extra wrapper classes,
 * sanitized per class name), `pill` (pre-built pill HTML for the label cell, echoed
 * verbatim), `control` (pre-built control HTML, echoed verbatim), and `help` (optional
 * help text in a `.help` element, escaped).
 *
 * @param array<string,mixed> $args Row args (see the recognised keys above).
 * @return void
 */
function aafm_render_set_row( array $args ): void {
	$label       = isset( $args['label'] ) ? (string) $args['label'] : '';
	$opt         = isset( $args['opt'] ) ? (string) $args['opt'] : '';
	$extra_class = isset( $args['class'] ) ? (string) $args['class'] : '';
	$pill        = isset( $args['pill'] ) ? (string) $args['pill'] : '';
	$control     = isset( $args['control'] ) ? (string) $args['control'] : '';
	$help        = isset( $args['help'] ) ? (string) $args['help'] : '';

	// Same per-class sanitize as aafm_render_section(), so a caller cannot smuggle a second
	// attribute in through the class list.
	$extra = '';
	foreach ( array_filter( explode( ' ', $extra_class ) ) as $one ) {
		$one = sanitize_html_class( $one );
		if ( '' !== $one ) {
			$extra .= ' ' . $one;
		}
	}

	$opt_html  = '' === $opt
		? ''
		: sprintf( ' <span class="opt">%s</span>', esc_html( $opt ) );
	$help_html = '' === $help
		? ''
		: sprintf( '<p class="help">%s</p>', esc_html( $help ) );

	echo wp_kses(
		sprintf(
			'<div class="aafm-set-row%1$s"><div class="aafm-set-label">%2$s%3$s%4$s</div><div class="aafm-set-control">%5$s%6$s</div></div>',
			esc_attr( $extra ),
			esc_html( $label ),
			$opt_html,
			$pill,
			$control,
			$help_html
		),
		aafm_admin_allowed_html()
	);
}

/**
 * Build a "See more" disclosure for the tail of a long set-row description.
 *
 * A native `<details>`/`<summary>`, the same affordance the integration cards, the abilities
 * list, and the Help accordion already use - so it needs no JavaScript, survives a page with
 * scripts off, and gets its keyboard and screen-reader behaviour from the browser. Only the
 * three rows whose copy runs much longer than the rest of the tab use it; the point is to
 * keep the visual rhythm of the tab, not to hide anything, so nothing here is truncated -
 * every sentence stays in the markup either side of the fold.
 *
 * STRUCTURAL ONLY, same convention as the rest of this file: `$body` is echoed verbatim and
 * the caller owns its escaping. `$label` is the accessible name and IS escaped here.
 *
 * @param string $label Accessible name for the summary, e.g. "See more about read-only mode".
 *                      The visible text stays the short shared "See more".
 * @param string $body  Pre-escaped HTML for the collapsed remainder.
 * @return string
 */
function aafm_get_set_more_html( string $label, string $body ): string {
	return sprintf(
		'<details class="aafm-set-more"><summary aria-label="%1$s">%2$s</summary><div class="aafm-set-more-body">%3$s</div></details>',
		esc_attr( $label ),
		esc_html__( 'See more', 'agent-abilities-for-mcp' ),
		$body
	);
}
