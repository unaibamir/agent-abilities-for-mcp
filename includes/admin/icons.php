<?php
/**
 * Inline SVG icon set for the admin UI.
 *
 * Each icon is a static literal copied verbatim from the approved design mockups
 * (.claude/design-mockups/*). They replace core Dashicons in admin output so the
 * rendered UI is a pixel-faithful match for the mockups. Every returned string is
 * a constant — there is no dynamic data in it — so callers echo it through the
 * existing phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped pattern.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Return the inline <svg> markup for a named icon.
 *
 * The inner paths/rects/circles are copied exactly from the mockup HTML so the
 * glyphs match 1:1. Stroke width follows the mockup for that glyph (most are
 * 1.7/1.8; the step check is 2.4, the arrow is 2). An unknown name returns an
 * empty string so a typo degrades to no icon rather than a fatal.
 *
 * @param string $name Icon key (see the switch below for the full set).
 * @return string Inline SVG markup, or '' for an unknown name.
 */
function aafm_icon( string $name ): string {
	$icons = array(
		// Tab icons (dashboard-a.html lines 44-49).
		'dashboard'             => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
		'connection'            => '<path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3M7 7h10v4a5 5 0 0 1-10 0V7ZM12 16v5"/>',
		'abilities'             => '<path d="M13 3 4 14h6l-1 7 9-11h-6l1-7Z"/>',
		'settings'              => '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.6 7.6 0 0 0 0-2l1.8-1.4-1.8-3.2-2.1.9a7.6 7.6 0 0 0-1.7-1L15.2 3H8.8l-.4 2.3a7.6 7.6 0 0 0-1.7 1l-2.1-.9-1.8 3.2L4.6 11a7.6 7.6 0 0 0 0 2l-1.8 1.4 1.8 3.2 2.1-.9a7.6 7.6 0 0 0 1.7 1l.4 2.3h6.4l.4-2.3a7.6 7.6 0 0 0 1.7-1l2.1.9 1.8-3.2L19.4 13Z"/>',
		'activity'              => '<path d="M4 6h16M4 12h16M4 18h16"/>',
		'help'                  => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .8-1 1.7M12 17h.01"/>',

		// Dashboard card / inline icons.
		'endpoint'              => '<path d="M10 13a5 5 0 0 0 7 0l2-2a5 5 0 0 0-7-7l-1 1"/><path d="M14 11a5 5 0 0 0-7 0l-2 2a5 5 0 0 0 7 7l1-1"/>',
		'clock'                 => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/>',
		'copy'                  => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',

		// Stat icons (dashboard-a.html lines 107-134).
		'bolt'                  => '<path d="M13 3 4 14h6l-1 7 9-11h-6l1-7Z"/>',
		'recent'                => '<circle cx="9" cy="8" r="3"/><path d="M3 20c0-3 3-5 6-5s6 2 6 5"/><path d="M16 7a3 3 0 0 1 0 6"/>',
		'audit'                 => '<path d="M4 5h16M4 12h16M4 19h10"/>',
		'groups'                => '<path d="M12 3 4 6v5c0 4.5 3.2 7.8 8 9 4.8-1.2 8-4.5 8-9V6l-8-3Z"/>',

		// Client icons (connection.html lines 91-98).
		'client-claude-desktop' => '<path d="M12 3a9 9 0 1 0 9 9"/><path d="M12 7v5l3 2"/>',
		'client-claude-code'    => '<path d="m8 8-4 4 4 4M16 8l4 4-4 4"/>',
		'client-cursor'         => '<path d="M4 7l8-4 8 4-8 4-8-4ZM4 7v10l8 4 8-4V7"/>',
		'client-vscode'         => '<path d="M16 3 6 13l-3 8 8-3L21 8l-5-5Z"/>',
		'client-windsurf'       => '<path d="M3 12c4-6 14-6 18 0-4 6-14 6-18 0Z"/><circle cx="12" cy="12" r="2"/>',
		'client-gemini-cli'     => '<path d="M12 3v18M3 12h18"/><circle cx="12" cy="12" r="9"/>',
		'client-manus'          => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M9 9h6v6H9z"/>',
		'client-generic'        => '<circle cx="12" cy="12" r="9"/><path d="M9 12h6"/>',

		// Settings card-head / notice icons (settings.html lines 42-66).
		'shield'                => '<path d="M12 3 4 6v5c0 4.5 3.2 7.8 8 9 4.8-1.2 8-4.5 8-9V6l-8-3Z"/>',
		'warning'               => '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4M12 17h.01"/>',
		'info'                  => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
		'success'               => '<path d="m5 12 4.5 4.5L19 7"/>',
		'error'                 => '<path d="M6 6l12 12M18 6 6 18"/>',
	);

	// Per-glyph stroke width: most are 1.7; a few need a heavier stroke to match
	// the mockup. The step-done check is 2.4, the arrow is 2, info/warning/success
	// use 1.8, the error X uses 1.8 to balance the heavier check.
	$stroke = array(
		'check'       => '2.4',
		'arrow-right' => '2',
		'info'        => '1.8',
		'warning'     => '1.8',
		'success'     => '1.8',
		'error'       => '1.8',
	);

	// The step-done check (dashboard-a.html line 61) and the navigation arrow
	// (line 85) are stroke-weight variants of glyphs the catalog already holds.
	$paths = array(
		'check'       => '<path d="m5 12 4.5 4.5L19 7"/>',
		'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
	);

	$inner = $paths[ $name ] ?? ( $icons[ $name ] ?? '' );
	if ( '' === $inner ) {
		return '';
	}

	$width = $stroke[ $name ] ?? '1.7';

	return sprintf(
		'<svg class="aafm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="%1$s" aria-hidden="true" focusable="false">%2$s</svg>',
		$width,
		$inner
	);
}
