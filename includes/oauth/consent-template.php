<?php
/**
 * OAuth consent screen: a standalone HTML document rendered on the front end.
 *
 * The authorize endpoint runs on `init`, outside wp-admin, so none of the admin CSS
 * is enqueued here. The page therefore inlines a small <style> block that reuses the
 * shipped "Direction A" design tokens (copied verbatim from includes/admin/assets/admin.css)
 * so the consent card matches the rest of the plugin without loading any external asset.
 * Everything is self-contained: no external scripts, no external styles, CSP-clean.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

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

	/* translators: %s: the application/client requesting access. */
	$headline = sprintf( __( 'Authorize %s', 'agent-abilities-for-mcp' ), $client_name );

	// Direction A design tokens, copied verbatim from includes/admin/assets/admin.css.
	// Admin CSS is not enqueued on this front-end page, so the card styles itself.
	$styles = '
		:root {
			--aafm-surface:#fff; --aafm-surface-2:#f6f7f7; --aafm-text:#1d2327;
			--aafm-text-muted:#50575e; --aafm-border:#dcdcde; --aafm-border-strong:#c3c4c7;
			--aafm-accent:#2271b1; --aafm-accent-hover:#135e96; --aafm-accent-tint:#f0f6fc;
			--aafm-red:#d63638; --aafm-radius:8px; --aafm-radius-sm:5px;
		}
		* { box-sizing: border-box; }
		body {
			margin: 0;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
			background: var(--aafm-surface-2);
			color: var(--aafm-text);
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
			font-size: 14px;
			line-height: 1.5;
		}
		.aafm-card {
			width: 100%;
			max-width: 440px;
			background: var(--aafm-surface);
			border: 1px solid var(--aafm-border);
			border-radius: var(--aafm-radius);
			box-shadow: 0 1px 2px rgba(0,0,0,.04);
			padding: 28px;
		}
		.aafm-card h1 {
			margin: 0 0 12px;
			font-size: 20px;
			line-height: 1.3;
			color: var(--aafm-text);
		}
		.aafm-card p {
			margin: 0 0 20px;
			color: var(--aafm-text-muted);
		}
		.aafm-card strong { color: var(--aafm-text); }
		.aafm-actions {
			display: flex;
			gap: 10px;
			margin-top: 8px;
		}
		.aafm-btn {
			flex: 1 1 auto;
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 9px 16px;
			border-radius: var(--aafm-radius-sm);
			border: 1px solid transparent;
			font-size: 14px;
			font-weight: 600;
			cursor: pointer;
		}
		.aafm-btn-primary {
			background: var(--aafm-accent);
			color: #fff;
			border-color: var(--aafm-accent);
		}
		.aafm-btn-primary:hover { background: var(--aafm-accent-hover); border-color: var(--aafm-accent-hover); }
		.aafm-btn-secondary {
			background: var(--aafm-surface);
			color: var(--aafm-accent);
			border-color: var(--aafm-border-strong);
		}
		.aafm-btn-secondary:hover { background: var(--aafm-accent-tint); border-color: var(--aafm-accent); }
	';

	// Page language and charset for a standalone front-end document.
	$lang = esc_attr( get_bloginfo( 'language' ) );

	echo '<!DOCTYPE html>';
	echo '<html lang="' . $lang . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	echo '<head>';
	echo '<meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<meta name="referrer" content="no-referrer">';
	echo '<title>' . esc_html( $headline ) . '</title>';
	echo '<style>' . $styles . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline stylesheet, no dynamic data.
	echo '</head>';
	echo '<body>';
	echo '<main class="aafm-card">';

	echo '<h1>' . esc_html( $headline ) . '</h1>';

	printf(
		'<p>%s</p>',
		sprintf(
			/* translators: 1: site name, 2: WordPress username the agent acts as. */
			esc_html__( 'This will let it access %1$s as %2$s. It can do anything your account can do.', 'agent-abilities-for-mcp' ),
			'<strong>' . esc_html( $site_name ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner value escaped.
			'<strong>' . esc_html( $user_login ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inner value escaped.
		)
	);

	echo '<form method="post" action="' . esc_url( $action_url ) . '">';

	// Pre-rendered, pre-escaped nonce field from wp_nonce_field().
	echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered nonce markup.

	// Pre-built hidden OAuth inputs (each value already passed through esc_attr()).
	foreach ( $hidden_inputs as $input ) {
		echo $input; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered, attribute-escaped input.
	}

	echo '<div class="aafm-actions">';
	printf(
		'<button type="submit" name="aafm_oauth_decision" value="deny" class="aafm-btn aafm-btn-secondary">%s</button>',
		esc_html__( 'Deny', 'agent-abilities-for-mcp' )
	);
	printf(
		'<button type="submit" name="aafm_oauth_decision" value="approve" class="aafm-btn aafm-btn-primary">%s</button>',
		esc_html__( 'Approve', 'agent-abilities-for-mcp' )
	);
	echo '</div>';

	echo '</form>';
	echo '</main>';
	echo '</body>';
	echo '</html>';
}
