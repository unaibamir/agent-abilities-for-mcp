<?php
/**
 * OAuth consent screen: a standalone HTML document rendered on the front end.
 *
 * The authorize endpoint runs on `init`, outside wp-admin, so none of the admin CSS
 * is enqueued here. The page builds its own <head> and links a single same-origin
 * stylesheet (assets/consent.css) through the enqueue API (wp_enqueue_style +
 * wp_print_styles), allowed under style-src 'self'; the <svg> logo is inlined (no
 * external image fetch under img-src data:). There is no JavaScript at all and no
 * inline style block - system fonts only - so it renders under the strict consent
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
 * only when even that is empty to a neutral globe glyph (🌐) - a recognisable "a website" mark,
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

	return "\xF0\x9F\x8C\x90"; // U+1F310 GLOBE WITH MERIDIANS - neutral "a website" mark.
}

/**
 * Whether a Site Icon URL is served from this site's own origin.
 *
 * The consent page renders under a strict CSP whose img-src is `'self' data:` only, so an
 * off-origin Site Icon (WordPress can hand back a CDN/offload URL from get_site_icon_url()) is
 * silently blocked by the browser and the avatar renders empty. Rather than widen the CSP to
 * trust an arbitrary host, the caller falls back to the derived initials whenever the icon is not
 * same-origin. A URL that fails to parse a host, or whose host differs from the site host, is
 * treated as off-origin (fail closed to the initials).
 *
 * @param string $icon_url The Site Icon URL from get_site_icon_url().
 * @return bool True only when the icon host equals the site host.
 */
function aafm_oauth_site_icon_is_same_origin( string $icon_url ): bool {
	if ( '' === $icon_url ) {
		return false;
	}
	$icon_host = wp_parse_url( $icon_url, PHP_URL_HOST );
	if ( ! is_string( $icon_host ) || '' === $icon_host ) {
		return false;
	}
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	return is_string( $site_host ) && '' !== $site_host && strtolower( $icon_host ) === strtolower( $site_host );
}

/**
 * Return the connecting client's brand icon as an inline SVG, matched to the connector.
 *
 * The consent screen's client avatar sits on a dark (#0B1020) tile, so every mark below
 * is filled in a colour that reads on dark. Vendor brand marks are used with the operator's
 * explicit approval; each is a static, kses-safe primitive (path/line/polygon), no external
 * fetch. An unrecognised connector gets a neutral plug glyph - never a decorative star, which
 * would imply a rating or a "featured" status the plugin never confers.
 *
 * SECURITY: the vendor is chosen SOLELY from the redirect host, matched with an exact,
 * boundary-checked suffix against a small allowlist of each vendor's real fixed domains - never
 * from the client-supplied display name, and never with a loose substring. Dynamic client
 * registration lets a self-registered app pick any display name it likes AND register any
 * redirect host it can actually receive the authorization code at; the display name is therefore
 * pure attacker input, and a loose host substring (the old `strpos()` match) would let
 * `evil-openai.com` or `claude.ai.attacker.com` steal a trusted vendor logo and defeat the
 * "Unverified app" warning. Matching only the host, and only against the exact real domains an
 * impostor cannot receive a code at, closes that. Any host not on the allowlist renders the
 * neutral plug glyph; real vendors still get their icon.
 *
 * Every icon carries a `conn-<vendor>` class so the choice is assertable in tests and survives
 * the SVG kses pass (class is allowlisted on <svg>).
 *
 * @param string $client_name   The client's self-declared display name (deliberately UNUSED for
 *                              vendor selection - see the security note above).
 * @param string $redirect_host The host portion of the client's redirect URI.
 * @return string Inline SVG markup (run it through aafm_svg_allowed_html() on output).
 */
function aafm_oauth_connector_icon( string $client_name, string $redirect_host ): string {
	unset( $client_name ); // Vendor selection is by verified host ONLY; the display name is attacker-controlled.

	// Normalise the host: lowercase, and strip any explicit :port suffix so a defensive
	// "host:443" cannot slip past the exact-match allowlist. A real vendor never carries a port.
	$host = strtolower( trim( $redirect_host ) );
	$host = (string) preg_replace( '/:\d+$/', '', $host );

	// Exact host, or a genuine subdomain of it (boundary-checked with a leading dot so
	// `evilclaude.ai` does NOT match `claude.ai` while `foo.claude.ai` does). No substring match.
	$host_is = static function ( string $host, array $domains ): bool {
		foreach ( $domains as $domain ) {
			if ( $host === $domain ) {
				return true;
			}
			$suffix = '.' . $domain;
			if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	};

	// Claude / Anthropic - the Anthropic radial burst, in the brand clay.
	if ( $host_is( $host, array( 'claude.ai', 'claude.com', 'anthropic.com' ) ) ) {
		return '<svg class="conn-icon conn-claude" width="26" height="26" viewBox="0 0 24 24" aria-hidden="true">'
			. '<g stroke="#D97757" stroke-width="1.9" stroke-linecap="round">'
			. '<line x1="12" y1="2.5" x2="12" y2="9.4"/><line x1="12" y1="14.6" x2="12" y2="21.5"/>'
			. '<line x1="2.5" y1="12" x2="9.4" y2="12"/><line x1="14.6" y1="12" x2="21.5" y2="12"/>'
			. '<line x1="5.28" y1="5.28" x2="10.16" y2="10.16"/><line x1="13.84" y1="13.84" x2="18.72" y2="18.72"/>'
			. '<line x1="5.28" y1="18.72" x2="10.16" y2="13.84"/><line x1="13.84" y1="10.16" x2="18.72" y2="5.28"/>'
			. '</g></svg>';
	}

	// ChatGPT / OpenAI - the official OpenAI knot mark (single path), in white for the dark tile.
	if ( $host_is( $host, array( 'chatgpt.com', 'openai.com' ) ) ) {
		return '<svg class="conn-icon conn-openai" width="25" height="25" viewBox="0 0 24 24" aria-hidden="true">'
			. '<path fill="#ffffff" d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7476-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997z"/>'
			. '</svg>';
	}

	// Cursor - the isometric cube, three white faces at descending opacity (its layered look).
	if ( $host_is( $host, array( 'cursor.com', 'cursor.sh' ) ) ) {
		return '<svg class="conn-icon conn-cursor" width="25" height="25" viewBox="0 0 24 24" aria-hidden="true">'
			. '<polygon points="12,3 20.5,7.5 12,12 3.5,7.5" fill="#ffffff" opacity=".95"/>'
			. '<polygon points="3.5,7.5 12,12 12,21 3.5,16.5" fill="#ffffff" opacity=".55"/>'
			. '<polygon points="20.5,7.5 12,12 12,21 20.5,16.5" fill="#ffffff" opacity=".75"/>'
			. '</svg>';
	}

	// VS Code - the official ribbon mark (single path), in the VS Code blue.
	if ( $host_is( $host, array( 'vscode.dev', 'visualstudio.com' ) ) ) {
		return '<svg class="conn-icon conn-vscode" width="25" height="25" viewBox="0 0 24 24" aria-hidden="true">'
			. '<path fill="#2AA2E8" d="M23.15 2.587 18.21.21a1.494 1.494 0 0 0-1.705.29l-9.46 8.63-4.12-3.128a.999.999 0 0 0-1.276.057L.327 7.261A1 1 0 0 0 .326 8.74L3.899 12 .326 15.26a1 1 0 0 0 .001 1.479L1.65 17.94a.999.999 0 0 0 1.276.057l4.12-3.128 9.46 8.63a1.492 1.492 0 0 0 1.704.29l4.942-2.377A1.5 1.5 0 0 0 24 20.06V3.939a1.5 1.5 0 0 0-.85-1.352zm-5.146 14.861L10.826 12l7.178-5.448z"/>'
			. '</svg>';
	}

	// Unknown connector - a neutral plug glyph (two prongs, body, cord). Not a star.
	return '<svg class="conn-icon conn-generic" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
		. '<path d="M9 2v4M15 2v4" stroke="#B9C0CC" stroke-width="1.8" stroke-linecap="round"/>'
		. '<path d="M7 6h10v3a5 5 0 0 1-10 0V6Z" stroke="#B9C0CC" stroke-width="1.8" stroke-linejoin="round"/>'
		. '<path d="M12 14v6" stroke="#B9C0CC" stroke-width="1.8" stroke-linecap="round"/>'
		. '</svg>';
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
	$client_name    = isset( $view['client_name'] ) ? (string) $view['client_name'] : '';
	$user_login     = isset( $view['user_login'] ) ? (string) $view['user_login'] : '';
	$site_name      = isset( $view['site_name'] ) ? (string) $view['site_name'] : '';
	$redirect_host  = isset( $view['redirect_host'] ) ? (string) $view['redirect_host'] : '';
	$high_privilege = ! empty( $view['high_privilege'] );
	$action_url     = isset( $view['action_url'] ) ? (string) $view['action_url'] : '';
	$nonce_field    = isset( $view['nonce_field'] ) ? (string) $view['nonce_field'] : '';
	$hidden_inputs  = isset( $view['hidden_inputs'] ) && is_array( $view['hidden_inputs'] )
		? $view['hidden_inputs']
		: array();

	$plain_site = esc_html( $site_name );

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
	// substituted into an escaped translation string - same safe-by-construction pattern.
	$acting = sprintf(
		/* translators: 1: bolded phrase "as your WordPress account", 2: the username chip. */
		esc_html__( 'The agent acts %1$s %2$s - it can do what your account is permitted to do, nothing more.', 'agent-abilities-for-mcp' ),
		'<strong>' . esc_html__( 'as your WordPress account', 'agent-abilities-for-mcp' ) . '</strong>',
		'<span class="who">' . esc_html( $user_login ) . '</span>'
	);

	// The page's stylesheet lives in assets/consent.css and is linked below under the
	// consent CSP (style-src 'self'). It is a plain file rather than inline CSS so the
	// page passes the wp.org "enqueue your resources" check; the consent screen renders
	// outside wp-admin (custom headers + exit), so admin.css is never enqueued. The token
	// values in that file stay in lockstep with includes/admin/assets/admin.css (:root).

	// Static inline SVG (no dynamic data). The mark is the plugin's real brand logo, matching the
	// site favicon exactly (agentabilitieswp.com/favicon.svg): a white shield + check on a solid
	// brand-blue rounded square. The .mark CSS supplies the sizing and drop shadow.
	$mark_svg = '<svg class="mark" viewBox="0 0 32 32" role="img" aria-label="' . esc_attr__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ) . '">'
		. '<rect width="32" height="32" rx="7" fill="#2271b1"/>'
		. '<path d="M16 5 7 8.4v6.9c0 5.4 3.7 9.6 9 11.2 5.3-1.6 9-5.8 9-11.2V8.4L16 5Z" fill="none" stroke="#ffffff" stroke-width="1.9" stroke-linejoin="round"/>'
		. '<path d="m11.6 15.6 3 3 6-6.4" fill="none" stroke="#ffffff" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/>'
		. '</svg>';

	$client_glyph = aafm_oauth_connector_icon( $client_name, $redirect_host );
	$flow_svg     = '<svg width="28" height="14" viewBox="0 0 28 14" fill="none" aria-hidden="true"><path d="M1 7h22m0 0l-5-5m5 5l-5 5" stroke="#787c82" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	$acting_icon  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="#996800" stroke-width="1.7"/><path d="M12 7.5v5.5M12 16.2v.1" stroke="#996800" stroke-width="1.9" stroke-linecap="round"/></svg>';
	$dest_icon    = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 11l18-8-8 18-2-8-8-2z" stroke="#8a2011" stroke-width="1.7" stroke-linejoin="round"/></svg>';
	$warn_icon    = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 3l9.5 17H2.5z" stroke="#8a2011" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 9.5v4.2M12 16.6v.1" stroke="#8a2011" stroke-width="1.9" stroke-linecap="round"/></svg>';
	$tick_svg     = '<span class="tick"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 13l4 4L19 7" stroke="#00a32a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
	$shield_svg   = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 2l8 3v6c0 5-3.4 8.6-8 11-4.6-2.4-8-6-8-11V5l8-3z" stroke="#787c82" stroke-width="1.6" stroke-linejoin="round"/></svg>';

	// Governance guarantees: each a bold lead + plain description, both translatable. The delete
	// guarantee is the one that depends on configuration - thirteen native abilities and any
	// destructive bridged one remove content for good - so it is read from the enabled set rather
	// than asserted. See aafm_delete_guarantee().
	$delete_guarantee = aafm_delete_guarantee();
	$guarantees       = array(
		array( __( 'Off by default.', 'agent-abilities-for-mcp' ), __( 'The agent can only call abilities an admin has switched on in WordPress.', 'agent-abilities-for-mcp' ) ),
		array( __( 'Capped to your role.', 'agent-abilities-for-mcp' ), __( 'Every action runs inside your capabilities, never above them.', 'agent-abilities-for-mcp' ) ),
		array( __( 'Every action is logged.', 'agent-abilities-for-mcp' ), __( 'There is an audit trail of what the agent did and when.', 'agent-abilities-for-mcp' ) ),
		array( $delete_guarantee[0], $delete_guarantee[1] ),
	);

	echo '<!DOCTYPE html>';
	echo '<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">';
	echo '<head>';
	echo '<meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<meta name="referrer" content="no-referrer">';
	/* translators: 1: client/app name, 2: site name. */
	echo '<title>' . esc_html( sprintf( __( 'Authorize %1$s · %2$s', 'agent-abilities-for-mcp' ), $client_name, $site_name ) ) . '</title>';
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
	echo wp_kses( $mark_svg, aafm_svg_allowed_html() );
	echo '<p class="eyebrow">' . esc_html__( 'Authorize connection', 'agent-abilities-for-mcp' ) . '</p>';
	echo '<h1>' . wp_kses( $headline, aafm_admin_allowed_html() ) . '</h1>';
	echo '</div>';

	// Client -> site connect row (decorative; the names are stated in the headline + note).
	echo '<div class="connect-row" aria-hidden="true">';
	echo '<span class="avatar client">' . wp_kses( $client_glyph, aafm_svg_allowed_html() ) . '</span>';
	echo '<span class="flow">' . wp_kses( $flow_svg, aafm_svg_allowed_html() ) . '<span>' . esc_html__( 'connect', 'agent-abilities-for-mcp' ) . '</span></span>';
	// Site avatar: the admin's uploaded Customizer Site Icon when one is set AND served from this
	// site's own origin, else the derived initials. The Site Icon is emitted as a plain <img> (URL
	// escaped with esc_url) rather than inlined as a data URI; the consent CSP widens img-src to
	// 'self' data: for exactly this - a same-origin-only relaxation, no external host. But
	// get_site_icon_url() can return an off-origin CDN/offload URL, which that CSP silently blocks,
	// leaving the avatar empty - so an off-origin icon falls back to the initials rather than a
	// broken image. The row is aria-hidden, so the image is decorative (alt="").
	$site_icon_url = ( function_exists( 'has_site_icon' ) && has_site_icon() ) ? (string) get_site_icon_url( 96 ) : '';
	if ( '' !== $site_icon_url && aafm_oauth_site_icon_is_same_origin( $site_icon_url ) ) {
		echo '<span class="avatar site has-icon"><img src="' . esc_url( $site_icon_url ) . '" alt="" width="46" height="46" decoding="async"></span>';
	} else {
		echo '<span class="avatar site">' . esc_html( aafm_oauth_site_initials( $site_name ) ) . '</span>';
	}
	echo '</div>';

	// Destination + unverified-app caution. The redirect host is the single most decision-relevant
	// fact for the human - it says WHERE approving this sends account access - and the app was
	// self-registered through public dynamic client registration, so it is flagged as unverified
	// rather than presented as a vetted integration. Both are plain text (esc_html); the SVGs go
	// through the SVG kses allowlist. This block is styled by assets/consent.css under the consent
	// CSP, but the words alone convey the warning even if the stylesheet is blocked.
	echo '<div class="destination">';
	if ( '' !== $redirect_host ) {
		echo '<div class="dest-line">';
		echo wp_kses( $dest_icon, aafm_svg_allowed_html() );
		echo '<p><span class="dest-label">' . esc_html__( 'Will send access to', 'agent-abilities-for-mcp' ) . '</span> <span class="dest-host">' . esc_html( $redirect_host ) . '</span></p>';
		echo '</div>';
	}
	echo '<p class="unverified">';
	echo '<span class="badge">' . wp_kses( $warn_icon, aafm_svg_allowed_html() ) . esc_html__( 'Unverified app', 'agent-abilities-for-mcp' ) . '</span> ';
	echo esc_html__( 'This app registered itself and has not been reviewed by anyone. Approve it only if you started this connection and recognise where it is sending access.', 'agent-abilities-for-mcp' );
	echo '</p>';
	echo '</div>';

	// "Acting as" note.
	echo wp_kses( '<div class="acting">' . $acting_icon . '<p>' . $acting . '</p></div>', aafm_admin_allowed_html() );

	// High-privilege warning. A token is minted with the approving user's own capabilities, so
	// approving as an administrator (or any user who can escalate) hands the app full administrative
	// reach. Make that unmissable and steer the operator toward connecting as a limited agent user.
	// Plain text so the warning survives even if the stylesheet is blocked.
	if ( $high_privilege ) {
		echo '<div class="hi-priv">';
		echo wp_kses( $warn_icon, aafm_svg_allowed_html() );
		echo '<p><strong>' . esc_html__( 'This is an administrator account.', 'agent-abilities-for-mcp' ) . '</strong> '
			. esc_html__( 'Approving lets the app act with full administrator access, including changing settings, users, and content. If you do not need that, deny this and connect as a dedicated agent user on a limited role instead.', 'agent-abilities-for-mcp' )
			. '</p>';
		echo '</div>';
	}

	// Governance guarantees.
	echo '<div class="guarantees">';
	echo '<h2>' . esc_html__( 'How this stays governed', 'agent-abilities-for-mcp' ) . '</h2>';
	echo '<ul class="trust">';
	foreach ( $guarantees as $row ) {
		echo '<li>' . wp_kses( $tick_svg, aafm_admin_allowed_html() ) . '<span class="txt"><b>' . esc_html( $row[0] ) . '</b> ' . esc_html( $row[1] ) . '</span></li>';
	}
	echo '</ul>';
	echo '</div>';

	// Decision form: primary Approve, secondary Deny. One POST, both submit buttons.
	echo '<form method="post" action="' . esc_url( $action_url ) . '">';
	echo wp_kses( $nonce_field, aafm_admin_allowed_html() );
	foreach ( $hidden_inputs as $input ) {
		echo wp_kses( $input, aafm_admin_allowed_html() );
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

	echo '<p class="foot">' . wp_kses( $shield_svg, aafm_svg_allowed_html() ) . esc_html__( 'Secured by Agent Abilities for MCP', 'agent-abilities-for-mcp' ) . '</p>';

	echo '</main>';
	echo '</body>';
	echo '</html>';
}
