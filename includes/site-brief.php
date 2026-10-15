<?php
/**
 * Site Brief: the standing rules the site owner writes for every connected agent.
 *
 * The text reaches each MCP client through the server's start-up instructions (the server
 * description is returned as `instructions` on initialize). Only a user who can manage options
 * writes it, through the Settings save. No ability writes it, so an agent can never change the
 * rules it is given. It is cleaned when saved and again when read, so a value written straight
 * to the database is held to the same rules.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const AAFM_SITE_BRIEF_OPTION = 'aafm_site_brief';

/**
 * The longest brief kept, in characters (about 250 words).
 */
const AAFM_SITE_BRIEF_MAX = 1500;

/**
 * Clean a brief: plain multi-line text without markup, control characters or bidirectional
 * override characters, LF line endings, no run of more than one blank line, at most
 * AAFM_SITE_BRIEF_MAX characters.
 *
 * @param string $raw Raw text.
 * @return string Clean text, '' when nothing is left.
 */
function aafm_clean_site_brief( string $raw ): string {
	$text = aafm_sanitize_multiline_text( $raw );
	$text = (string) preg_replace( '/\r\n|\r/', "\n", $text );
	$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );

	return trim( mb_substr( trim( $text ), 0, AAFM_SITE_BRIEF_MAX ) );
}

/**
 * The stored brief, cleaned.
 *
 * @return string '' when no brief is set or the row is not text.
 */
function aafm_site_brief(): string {
	$stored = get_option( AAFM_SITE_BRIEF_OPTION, '' );

	return is_string( $stored ) ? aafm_clean_site_brief( $stored ) : '';
}

/**
 * The MCP server description: the plain sentence, plus the owner's brief when there is one.
 *
 * @return string
 */
function aafm_server_description(): string {
	$base  = __( 'Curated, governed WordPress abilities for AI agents.', 'agent-abilities-for-mcp' );
	$brief = aafm_site_brief();
	if ( '' === $brief ) {
		return $base;
	}

	return $base . "\n\n" . __( 'Rules from the site owner. Follow them on every task for this site:', 'agent-abilities-for-mcp' ) . "\n" . $brief;
}

/**
 * Record a change to the brief in the activity log. Never writes the text itself.
 *
 * @param string $before Brief before the save.
 * @param string $after  Brief after the save.
 * @return void
 */
function aafm_log_site_brief_change( string $before, string $after ): void {
	if ( $before === $after ) {
		return;
	}
	$user = wp_get_current_user();
	aafm_log_activity(
		array(
			'ability'           => 'aafm/site-brief',
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
			'status'            => 'success',
			'event_type'        => 'setting_changed',
			'detail'            => __( 'Site brief updated', 'agent-abilities-for-mcp' ),
		)
	);
}

/**
 * Render the Site brief card on the Settings tab. Called from aafm_render_settings_tab(), inside
 * #aafm-settings-form, so the one Save button covers it.
 *
 * @return void
 */
function aafm_render_site_brief_section(): void {
	ob_start();
	aafm_render_set_row(
		array(
			'label'   => __( 'Rules for every agent', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'Optional', 'agent-abilities-for-mcp' ),
			'control' => sprintf(
				'<textarea id="aafm-site-brief" name="aafm_site_brief" rows="6" class="large-text" aria-label="%1$s">%2$s</textarea>',
				esc_attr__( 'Site brief: rules every connected agent reads at the start of a session', 'agent-abilities-for-mcp' ),
				esc_textarea( aafm_site_brief() )
			),
			'help'    => sprintf(
				/* translators: %s: the maximum number of characters, for example 1,500. */
				__( 'Plain text, up to %s characters. Every agent that connects gets this text when its session starts, so keep passwords and private details out of it. Only you can change it; agents cannot. For example: British spelling, never touch the pricing page, drafts only on Fridays.', 'agent-abilities-for-mcp' ),
				number_format_i18n( AAFM_SITE_BRIEF_MAX )
			),
		)
	);
	$body = (string) ob_get_clean();

	aafm_render_section(
		array(
			'icon'  => 'audit',
			'title' => __( 'Site brief', 'agent-abilities-for-mcp' ),
			'body'  => $body,
		)
	);
}
