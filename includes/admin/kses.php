<?php
/**
 * Output-escaping allowlist helpers (wp_kses) for admin screens and the
 * OAuth consent page.
 *
 * Two functions:
 *   aafm_svg_allowed_html()   - inline SVG only (icons + consent-page logos).
 *   aafm_admin_allowed_html() - SVG set merged with every HTML element used in
 *                               admin UI: forms, tables, buttons, structural
 *                               wrappers, and every ARIA / data-* attribute the
 *                               plugin uses.
 *
 * Loaded unconditionally from the main plugin file so both helpers are available
 * for the OAuth consent page (rendered on the front end via `init`, before
 * `aafm_bootstrap()` runs the admin-only requires).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Allowed-HTML map (wp_kses) for inline SVG icons and logos.
 *
 * Covers every SVG element and attribute used by aafm_icon() and by the static
 * SVGs in the OAuth consent page, including radialGradient / linearGradient with
 * fill="url(#id)" and stroke="url(#id)" gradient references.
 *
 * All attribute names are lowercase - wp_kses normalises attribute names to
 * lowercase before comparing against this map, so mixed-case keys such as
 * "viewBox" would never match.
 *
 * @return array<string, array<string, bool>>
 */
function aafm_svg_allowed_html(): array {
	$shared = array(
		'id'        => true,
		'class'     => true,
		'fill'      => true,
		'stroke'    => true,
		'opacity'   => true,
		'transform' => true,
	);

	return array(
		'svg'            => array(
			'class'             => true,
			'width'             => true,
			'height'            => true,
			'viewbox'           => true,
			'fill'              => true,
			'stroke'            => true,
			// aafm_icon() sets the stroke weight on the <svg> element so every child
			// path/shape inherits it; these presentation attributes must survive kses
			// or the icons render at the default 1px weight instead of the design value.
			'stroke-width'      => true,
			'stroke-linecap'    => true,
			'stroke-linejoin'   => true,
			'stroke-miterlimit' => true,
			'opacity'           => true,
			'role'              => true,
			'aria-hidden'       => true,
			'aria-label'        => true,
			'focusable'         => true,
			'xmlns'             => true,
		),
		'path'           => array_merge(
			$shared,
			array(
				'd'                 => true,
				'stroke-width'      => true,
				'stroke-linecap'    => true,
				'stroke-linejoin'   => true,
				'stroke-miterlimit' => true,
			)
		),
		'circle'         => array_merge(
			$shared,
			array(
				'cx'           => true,
				'cy'           => true,
				'r'            => true,
				'stroke-width' => true,
			)
		),
		'rect'           => array_merge(
			$shared,
			array(
				'x'            => true,
				'y'            => true,
				'width'        => true,
				'height'       => true,
				'rx'           => true,
				'ry'           => true,
				'stroke-width' => true,
			)
		),
		'line'           => array_merge(
			$shared,
			array(
				'x1'             => true,
				'y1'             => true,
				'x2'             => true,
				'y2'             => true,
				'stroke-width'   => true,
				'stroke-linecap' => true,
			)
		),
		'g'              => array_merge(
			$shared,
			array(
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			)
		),
		'defs'           => array(),
		'radialgradient' => array(
			'id'            => true,
			'cx'            => true,
			'cy'            => true,
			'r'             => true,
			'gradientunits' => true,
		),
		'lineargradient' => array(
			'id'                => true,
			'x1'                => true,
			'y1'                => true,
			'x2'                => true,
			'y2'                => true,
			'gradientunits'     => true,
			'gradienttransform' => true,
		),
		'stop'           => array(
			'offset'       => true,
			'stop-color'   => true,
			'stop-opacity' => true,
		),
		'polygon'        => array_merge(
			$shared,
			array(
				'points'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			)
		),
		'polyline'       => array_merge(
			$shared,
			array(
				'points'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
			)
		),
	);
}

/**
 * Allowed-HTML map (wp_kses) for the plugin's admin UI and OAuth consent page.
 *
 * Merges the SVG allowlist with every HTML element and attribute used across
 * the admin screens: form controls (input, textarea, select, option, label,
 * button), structural elements (div, section, details, summary, nav, h1-h4, p,
 * a, ul, ol, li, table, …), and all ARIA / data-* attributes the plugin uses.
 *
 * @return array<string, array<string, bool>>
 */
function aafm_admin_allowed_html(): array {
	$aria = array(
		'aria-hidden'      => true,
		'aria-label'       => true,
		'aria-live'        => true,
		'aria-selected'    => true,
		'aria-expanded'    => true,
		'aria-controls'    => true,
		'aria-labelledby'  => true,
		'aria-describedby' => true,
	);

	$data = array(
		'data-copy'        => true,
		'data-client'      => true,
		'data-os'          => true,
		'data-client-id'   => true,
		'data-user-id'     => true,
		'data-config'      => true,
		'data-client-row'  => true,
		'data-grant-row'   => true,
		'data-page'        => true,
		'data-filter'      => true,
		'data-total-pages' => true,
	);

	$global = array_merge(
		array(
			'class'    => true,
			'id'       => true,
			'title'    => true,
			'role'     => true,
			'tabindex' => true,
			'lang'     => true,
			'hidden'   => true,
		),
		$aria,
		$data
	);

	$html = array(
		'div'      => $global,
		'span'     => $global,
		'section'  => $global,
		'details'  => array_merge( $global, array( 'open' => true ) ),
		'summary'  => $global,
		'nav'      => $global,
		'header'   => $global,
		'h1'       => $global,
		'h2'       => $global,
		'h3'       => $global,
		'h4'       => $global,
		'p'        => $global,
		'a'        => array_merge(
			$global,
			array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			)
		),
		'button'   => array_merge(
			$global,
			array(
				'type'     => true,
				'name'     => true,
				'value'    => true,
				'disabled' => true,
				'form'     => true,
			)
		),
		'input'    => array_merge(
			$global,
			array(
				'type'        => true,
				'name'        => true,
				'value'       => true,
				'placeholder' => true,
				'disabled'    => true,
				'readonly'    => true,
				'checked'     => true,
				'min'         => true,
				'max'         => true,
				'step'        => true,
			)
		),
		'textarea' => array_merge(
			$global,
			array(
				'name'        => true,
				'rows'        => true,
				'cols'        => true,
				'placeholder' => true,
				'disabled'    => true,
				'readonly'    => true,
			)
		),
		'select'   => array_merge(
			$global,
			array(
				'name'     => true,
				'multiple' => true,
				'disabled' => true,
			)
		),
		'option'   => array_merge(
			$global,
			array(
				'value'    => true,
				'selected' => true,
				'disabled' => true,
			)
		),
		'label'    => array_merge( $global, array( 'for' => true ) ),
		'strong'   => $global,
		'b'        => $global,
		'em'       => $global,
		'i'        => $global,
		'code'     => $global,
		'pre'      => $global,
		'small'    => $global,
		'br'       => array(),
		'hr'       => array(),
		'ul'       => $global,
		'ol'       => $global,
		'li'       => $global,
		'dl'       => $global,
		'dt'       => $global,
		'dd'       => $global,
		'table'    => $global,
		'thead'    => $global,
		'tbody'    => $global,
		'tr'       => $global,
		'th'       => array_merge(
			$global,
			array(
				'scope'   => true,
				'colspan' => true,
				'rowspan' => true,
			)
		),
		'td'       => array_merge(
			$global,
			array(
				'colspan' => true,
				'rowspan' => true,
			)
		),
		'form'     => array_merge(
			$global,
			array(
				'method'     => true,
				'action'     => true,
				'enctype'    => true,
				'novalidate' => true,
			)
		),
	);

	return array_merge( aafm_svg_allowed_html(), $html );
}
