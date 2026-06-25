<?php
/**
 * OAuth consent screen: a standalone HTML document rendered on the front end.
 *
 * The authorize endpoint runs on `init`, outside wp-admin, so none of the admin CSS
 * is enqueued here. The page builds its own <head> and links a single same-origin
 * stylesheet (assets/consent.css) through the enqueue API (wp_enqueue_style +
 * wp_print_styles), allowed under style-src 'self'; the <svg> logo is inlined (no
 * external image fetch under img-src data:). There is no JavaScript at all and no
 * inline style block — system fonts only — so it renders under the strict consent
 * CSP set in includes/oauth/authorize.php.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Derive up to two uppercase initials from a site name, for the site avatar.
 *
 * Multibyte-safe. When the name has no whitespace-delimited words to abbreviate (for example a
 * name made only of separators), it falls back to the first character of the trimmed name, and
 * only when even that is empty to a neutral globe glyph (🌐) — a recognisable "a website" mark,
 * rather than the old bare middle dot which read as a missing/placeholder character.
 *
 * @param string $site_name The site display name.
 * @return string One or two characters (already plain text; escape on output).
 */
function aafm_oauth_site_initials( string $site_name ): string {
	$trimmed = trim( $site_name );
	$words   = preg_split( '/\s+/', $trimmed );
	if ( ! is_array( $words ) ) {
		$words = array();
	}
	$initials = '';
	foreach ( array_slice( $words, 0, 2 ) as $word ) {
		if ( '' === $word ) {
			continue;
		}
		$initials .= function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );
	}
	$initials = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initials ) : strtoupper( $initials );

	if ( '' !== $initials ) {
		return $initials;
	}

	// No abbreviatable words: take the first character of the trimmed name if there is one,
	// otherwise a neutral globe glyph so the avatar still reads as "a site".
	if ( '' !== $trimmed ) {
		$first = function_exists( 'mb_substr' ) ? mb_substr( $trimmed, 0, 1 ) : substr( $trimmed, 0, 1 );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first ) : strtoupper( $first );
	}

	return "\xF0\x9F\x8C\x90"; // U+1F310 GLOBE WITH MERIDIANS — neutral "a website" mark.
}

/**
 * Render the OAuth consent page as a complete standalone HTML document.
 *
 * Echoes the full page (DOCTYPE through </html>). Every dynamic value is escaped at
 * the point of output: esc_html() for the names, esc_url() for the form action. The
 * nonce field and hidden OAuth inputs arrive pre-rendered (and pre-escaped by the
 * caller) and are emitted as-is inside the single POST form.
 *
 * @param array<string,mixed> $view View data: client_name, user_login, site_name,
 *                                  action_url, nonce_field (pre-rendered hidden input),
 *                                  and hidden_inputs (string[] of pre-escaped inputs).
 * @return void
 */
function aafm_oauth_render_consent_page( array $view ): void {
	$client_name   = isset( $view['client_name'] ) ? (string) $view['client_name'] : '';
	$user_login    = isset( $view['user_login'] ) ? (string) $view['user_login'] : '';
	$site_name     = isset( $view['site_name'] ) ? (string) $view['site_name'] : '';
	$action_url    = isset( $view['action_url'] ) ? (string) $view['action_url'] : '';
	$nonce_field   = isset( $view['nonce_field'] ) ? (string) $view['nonce_field'] : '';
	$hidden_inputs = isset( $view['hidden_inputs'] ) && is_array( $view['hidden_inputs'] )
		? $view['hidden_inputs']
		: array();

	$lang       = esc_attr( get_bloginfo( 'language' ) );
	$initials   = esc_html( aafm_oauth_site_initials( $site_name ) );
	$plain_site = esc_html( $site_name );

	/* translators: 1: client/app name, 2: site name. */
	$title = esc_html( sprintf( __( 'Authorize %1$s · %2$s', 'agent-abilities-for-mcp' ), $client_name, $site_name ) );

	/*
	 * Headline, safe-by-construction: the client name is the only untrusted input and is
	 * escaped before interpolation into the trusted, plugin-shipped translation string.
	 * The result is HTML-safe and echoed raw below.
	 */
	$headline = sprintf(
		/* translators: 1: client/app name (already bolded + escaped), 2: site name (escaped). */
		esc_html__( '%1$s wants to connect to %2$s', 'agent-abilities-for-mcp' ),
		'<strong>' . esc_html( $client_name ) . '</strong>',
		$plain_site
	);

	// "Acting as" note. The bold phrase and the username chip are pre-escaped HTML
	// substituted into an escaped translation string — same safe-by-construction pattern.
	$acting = sprintf(
		/* translators: 1: bolded phrase "as your WordPress account", 2: the username chip. */
		esc_html__( 'The agent acts %1$s %2$s — it can do what your account is permitted to do, nothing more.', 'agent-abilities-for-mcp' ),
		'<strong>' . esc_html__( 'as your WordPress account', 'agent-abilities-for-mcp' ) . '</strong>',
		'<span class="who">' . esc_html( $user_login ) . '</span>'
	);

	// The page's stylesheet lives in assets/consent.css and is linked below under the
	// consent CSP (style-src 'self'). It is a plain file rather than inline CSS so the
	// page passes the wp.org "enqueue your resources" check; the consent screen renders
	// outside wp-admin (custom headers + exit), so admin.css is never enqueued. The token
	// values in that file stay in lockstep with includes/admin/assets/admin.css (:root).

	// Static inline SVGs (no dynamic data). The hub mark is the plugin logo.
	$mark_svg = '<svg class="mark" viewBox="0 0 64 64" role="img" aria-label="' . esc_attr__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ) . '">'
		. '<defs>'
		. '<radialGradient id="aGlow" cx="50%" cy="42%" r="55%"><stop offset="0%" stop-color="#9BC4FF"/><stop offset="55%" stop-color="#4F9DFF"/><stop offset="100%" stop-color="#2E6FD6"/></radialGradient>'
		. '<linearGradient id="aLine" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#6AA9FF"/><stop offset="100%" stop-color="#4F9DFF"/></linearGradient>'
		. '</defs>'
		. '<rect x="2" y="2" width="60" height="60" rx="16" fill="#0B1020"/>'
		. '<rect x="2.5" y="2.5" width="59" height="59" rx="15.5" fill="none" stroke="#27345a" stroke-width="1"/>'
		. '<g stroke="url(#aLine)" stroke-width="1.6" stroke-linecap="round" opacity=".85">'
		. '<line x1="32" y1="32" x2="32" y2="13"/><line x1="32" y1="32" x2="49" y2="22"/><line x1="32" y1="32" x2="49" y2="44"/><line x1="32" y1="32" x2="16" y2="46"/><line x1="32" y1="32" x2="14" y2="26"/>'
		. '</g>'
		. '<g fill="#6AA9FF"><circle cx="32" cy="13" r="3"/><circle cx="49" cy="22" r="3"/><circle cx="49" cy="44" r="3"/><circle cx="16" cy="46" r="3"/><circle cx="14" cy="26" r="3"/></g>'
		. '<circle cx="32" cy="32" r="8.5" fill="url(#aGlow)"/><circle cx="32" cy="32" r="3.4" fill="#EAF3FF"/>'
		. '<path d="M36 36 L46 40 L41 41.5 L43.5 47 L41 48 L38.5 42.5 L35.5 46 Z" fill="#EAF3FF" stroke="#0B1020" stroke-width="1" stroke-linejoin="round"/>'
		. '</svg>';

	$client_glyph = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3l1.9 5.4L19 9l-4.3 3.5L16 18l-4-3-4 3 1.3-5.5L5 9l5.1-.6z" fill="#6AA9FF"/></svg>';
	$flow_svg     = '<svg width="28" height="14" viewBox="0 0 28 14" fill="none" aria-hidden="true"><path d="M1 7h22m0 0l-5-5m5 5l-5 5" stroke="#787c82" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	$acting_icon  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="#996800" stroke-width="1.7"/><path d="M12 7.5v5.5M12 16.2v.1" stroke="#996800" stroke-width="1.9" stroke-linecap="round"/></svg>';
	$tick_svg     = '<span class="tick"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 13l4 4L19 7" stroke="#00a32a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
	$shield_svg   = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2l8 3v6c0 5-3.4 8.6-8 11-4.6-2.4-8-6-8-11V5l8-3z" stroke="#787c82" stroke-width="1.6" stroke-linejoin="round"/></svg>';

	// Governance guarantees: each a bold lead + plain description, both translatable.
	$guarantees = array(
		array( __( 'Off by default.', 'agent-abilities-for-mcp' ), __( 'The agent can only call abilities an admin has switched on in WordPress.', 'agent-abilities-for-mcp' ) ),
		array( __( 'Capped to your role.', 'agent-abilities-for-mcp' ), __( 'Every action runs inside your capabilities, never above them.', 'agent-abilities-for-mcp' ) ),
		array( __( 'Every action is logged.', 'agent-abilities-for-mcp' ), __( 'There is an audit trail of what the agent did and when.', 'agent-abilities-for-mcp' ) ),
		array( __( 'Deletes go to Trash.', 'agent-abilities-for-mcp' ), __( 'Removals are recoverable, not permanent.', 'agent-abilities-for-mcp' ) ),
	);

	echo '<!DOCTYPE html>';
	echo '<html lang="' . $lang . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	echo '<head>';
	echo '<meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<meta name="referrer" content="no-referrer">';
	echo '<title>' . $title . '</title>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $title is esc_html() above.
	// Register and flush the consent stylesheet through the enqueue API. This page builds
	// its own <head> and exits (no wp_head), so we print the queued handle here directly;
	// the CSP allows style-src 'self' for the resulting same-origin <link>.
	$consent_css_path = AAFM_PLUGIN_DIR . 'assets/consent.css';
	$consent_css_ver  = file_exists( $consent_css_path ) ? (string) filemtime( $consent_css_path ) : AAFM_VERSION;
	wp_enqueue_style( 'aafm-consent', plugins_url( 'assets/consent.css', AAFM_PLUGIN_FILE ), array(), $consent_css_ver );
	wp_print_styles( 'aafm-consent' );
	echo '</head>';
	echo '<body>';
	echo '<main>';
	echo '<div class="card">';

	// Header: logo, eyebrow, headline.
	echo '<div class="card-head">';
	echo $mark_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG markup.
	echo '<p class="eyebrow">' . esc_html__( 'Authorize connection', 'agent-abilities-for-mcp' ) . '</p>';
	echo '<h1>' . $headline . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $headline pre-escaped by construction.
	echo '</div>';

	// Client -> site connect row (decorative; the names are stated in the headline + note).
	echo '<div class="connect-row" aria-hidden="true">';
	echo '<span class="avatar client">' . $client_glyph . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
	echo '<span class="flow">' . $flow_svg . '<span>' . esc_html__( 'connect', 'agent-abilities-for-mcp' ) . '</span></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG + escaped label.
	echo '<span class="avatar site">' . $initials . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $initials esc_html() above.
	echo '</div>';

	// "Acting as" note.
	echo '<div class="acting">' . $acting_icon . '<p>' . $acting . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG + $acting pre-escaped by construction.

	// Governance guarantees.
	echo '<div class="guarantees">';
	echo '<h2>' . esc_html__( 'How this stays governed', 'agent-abilities-for-mcp' ) . '</h2>';
	echo '<ul class="trust">';
	foreach ( $guarantees as $row ) {
		echo '<li>' . $tick_svg . '<span class="txt"><b>' . esc_html( $row[0] ) . '</b> ' . esc_html( $row[1] ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG + escaped strings.
	}
	echo '</ul>';
	echo '</div>';

	// Decision form: primary Approve, secondary Deny. One POST, both submit buttons.
	echo '<form method="post" action="' . esc_url( $action_url ) . '">';
	echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered nonce markup.
	foreach ( $hidden_inputs as $input ) {
		echo $input; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered, attribute-escaped input.
	}
	printf(
		'<button type="submit" name="aafm_oauth_decision" value="approve" class="aafm-btn aafm-btn-primary">%s</button>',
		esc_html__( 'Approve & connect', 'agent-abilities-for-mcp' )
	);
	printf(
		'<button type="submit" name="aafm_oauth_decision" value="deny" class="aafm-btn aafm-btn-secondary">%s</button>',
		esc_html__( 'Deny', 'agent-abilities-for-mcp' )
	);
	echo '</form>';

	echo '</div>'; // .card

	echo '<p class="foot">' . $shield_svg . esc_html__( 'Secured by Agent Abilities for MCP', 'agent-abilities-for-mcp' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG + escaped label.

	echo '</main>';
	echo '</body>';
	echo '</html>';
}
