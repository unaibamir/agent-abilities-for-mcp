<?php
/**
 * Stored plain-text sanitizing.
 *
 * The B-18 pair and the character set they strip. Kept in their own file, and required at the very
 * top of the plugin rather than inside aafm_bootstrap(), because their callers do not all load at
 * the same time: includes/audit/log.php and includes/oauth/clients.php are both required before
 * bootstrap runs, and activating a plugin includes its main file and fires the activation hook in
 * the SAME request without ever reaching plugins_loaded. A sanitizer that only existed after
 * bootstrap would be an undefined function on that path, which is a fatal on activation rather than
 * a bug someone notices later. Nothing here depends on anything else the plugin defines, so loading
 * it first costs nothing.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The invisible characters no plain-text field the plugin stores may carry.
 *
 * Two groups, both returned as raw UTF-8 byte sequences so the caller can strip
 * them with str_replace and never depend on a /u regex (which returns null, not
 * a string, the moment it is handed a byte sequence that is not valid UTF-8):
 *
 * 1. C0 controls except tab, LF and CR. This is exactly the set XML 1.0 forbids
 *    in a document - a single raw one of these anywhere in the site's RSS feed
 *    makes the WHOLE document not well-formed, and NUL cannot even be escaped
 *    into validity, so nothing downstream can rescue it. DEL (0x7F) and the C1
 *    range are legal XML 1.0 characters and are deliberately left alone.
 * 2. The Trojan Source bidirectional formatting characters (U+202A-U+202E,
 *    U+2066-U+2069). These reorder how a string renders without changing a byte
 *    of it, so a human reviewer can approve one title in wp-admin while a
 *    different one goes live. U+200E/U+200F (LRM/RLM) are NOT stripped: they are
 *    ordinary punctuation in real mixed-direction text and cannot reverse a run.
 *
 * @return string[] Needles for str_replace.
 */
function aafm_unsafe_text_characters(): array {
	static $needles = null;
	if ( null !== $needles ) {
		return $needles;
	}

	$needles = array();
	for ( $i = 0; $i <= 0x1F; $i++ ) {
		if ( 0x09 === $i || 0x0A === $i || 0x0D === $i ) {
			continue;
		}
		$needles[] = chr( $i );
	}
	// U+202A-U+202E then U+2066-U+2069, spelled out as their UTF-8 bytes.
	foreach ( range( 0xAA, 0xAE ) as $tail ) {
		$needles[] = "\xE2\x80" . chr( $tail );
	}
	foreach ( range( 0xA6, 0xA9 ) as $tail ) {
		$needles[] = "\xE2\x81" . chr( $tail );
	}

	return $needles;
}

/**
 * Sanitize a value destined for a stored plain-text field (a post title, a menu
 * item label, an alt attribute, and so on).
 *
 * WordPress's own sanitize_text_field() is not enough for anything the site
 * later emits. It strips tags and percent-encoded sequences, but a raw NUL is valid UTF-8, so
 * wp_check_invalid_utf8() passes it and the byte lands in the database intact;
 * bidi overrides survive the same way. The stripping runs AFTER the WordPress
 * sanitizer so this function has the last word on what is stored, whatever
 * sanitize_text_field's own filters return.
 *
 * @param string $value Raw value from input.
 * @return string Sanitized single-line plain text.
 */
function aafm_sanitize_plain_text( string $value ): string {
	return str_replace( aafm_unsafe_text_characters(), '', sanitize_text_field( $value ) );
}

/**
 * The multi-line counterpart, for a field that legitimately keeps its newlines: an order note, a
 * refund reason, a media caption.
 *
 * Same strip, different WordPress sanitizer underneath. sanitize_text_field() would collapse the
 * newlines out of a multi-line value, which is why these fields use the textarea sanitizer in the
 * first place, and that one has exactly the same gap: a raw NUL is valid UTF-8, so it passes
 * wp_check_invalid_utf8() and reaches storage.
 *
 * This matters as much as the single-line case, not less. A media caption is stored as
 * post_excerpt, and post_excerpt is rendered into the site's RSS feed, so a NUL arriving through a
 * caption breaks the whole feed document exactly the way one in a title does. The needle set
 * already spares tab, LF and CR, so multi-line text survives intact.
 *
 * @param string $value Raw value from input.
 * @return string Sanitized multi-line plain text.
 */
function aafm_sanitize_multiline_text( string $value ): string {
	return str_replace( aafm_unsafe_text_characters(), '', sanitize_textarea_field( $value ) );
}
