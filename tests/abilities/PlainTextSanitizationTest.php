<?php
/**
 * Invisible-character stripping on stored plain-text fields: the C0 control
 * characters XML 1.0 forbids, and the bidirectional overrides that let a stored
 * string render as something other than what it is.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Post;

final class PlainTextSanitizationTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option(
			'aafm_enabled_abilities',
			array( 'aafm/create-post', 'aafm/update-post' )
		);
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	/**
	 * The helper itself. A NUL is valid UTF-8, so wp_check_invalid_utf8() passes
	 * it and sanitize_text_field() alone leaves it in the string.
	 */
	public function test_helper_strips_c0_controls_that_xml_forbids(): void {
		$this->assertSame( 'abcdef', aafm_sanitize_plain_text( "abc\x00def" ), 'NUL must not survive.' );
		$this->assertSame( 'abcdef', aafm_sanitize_plain_text( "abc\x01def" ) );
		$this->assertSame( 'abcdef', aafm_sanitize_plain_text( "abc\x0Bdef" ), 'Vertical tab is forbidden in XML 1.0.' );
		$this->assertSame( 'abcdef', aafm_sanitize_plain_text( "abc\x1Fdef" ) );
	}

	/**
	 * Tab, LF and CR are the three C0 characters XML 1.0 allows, and they are
	 * ordinary text. Stripping them would corrupt legitimate input.
	 *
	 * WordPress collapses these to spaces in a single-line field, so the
	 * assertion is that they are not DELETED, leaving the text either side
	 * intact and separated.
	 */
	public function test_helper_does_not_delete_tab_lf_or_cr(): void {
		foreach ( array( "\t", "\n", "\r" ) as $allowed ) {
			$out = aafm_sanitize_plain_text( 'abc' . $allowed . 'def' );
			$this->assertStringContainsString( 'abc', $out );
			$this->assertStringContainsString( 'def', $out );
			$this->assertNotSame( 'abcdef', $out, 'The character must not be silently deleted.' );
		}
	}

	/**
	 * The Trojan Source set: U+202A-U+202E and U+2066-U+2069 reorder how a run
	 * renders without changing a byte of it, so a reviewer can approve one title
	 * in wp-admin while a different one goes live.
	 */
	public function test_helper_strips_bidi_overrides(): void {
		foreach ( array( "\u{202A}", "\u{202B}", "\u{202C}", "\u{202D}", "\u{202E}", "\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}" ) as $bidi ) {
			$this->assertSame(
				'abcdef',
				aafm_sanitize_plain_text( 'abc' . $bidi . 'def' ),
				'Bidi override U+' . strtoupper( bin2hex( $bidi ) ) . ' must not survive.'
			);
		}
	}

	/**
	 * LRM and RLM are ordinary punctuation in real mixed-direction text and
	 * cannot reverse a run, so they are deliberately left alone. Stripping them
	 * would damage legitimate Arabic, Hebrew and Urdu content.
	 */
	public function test_helper_keeps_the_directional_marks_that_are_not_overrides(): void {
		$this->assertStringContainsString( "\u{200E}", aafm_sanitize_plain_text( "abc\u{200E}def" ) );
		$this->assertStringContainsString( "\u{200F}", aafm_sanitize_plain_text( "abc\u{200F}def" ) );
	}

	/**
	 * Ordinary text, including non-Latin scripts and emoji, must pass through
	 * byte for byte.
	 */
	public function test_helper_leaves_ordinary_text_alone(): void {
		foreach ( array( 'A normal title', 'عنوان عربي', 'Ünïcödé — em dash', 'Emoji 🚀 ok' ) as $clean ) {
			$this->assertSame( $clean, aafm_sanitize_plain_text( $clean ) );
		}
	}

	/**
	 * A byte sequence that is not valid UTF-8 must not blow the helper up. This
	 * is why the strip is str_replace and not a /u regex: preg_replace with the
	 * u modifier returns null, not a string, on invalid UTF-8.
	 */
	public function test_helper_returns_a_string_for_invalid_utf8(): void {
		$this->assertIsString( aafm_sanitize_plain_text( "abc\xC3\x28def" ) );
	}

	/**
	 * The headline consequence, end to end: a NUL in a title reached post_title
	 * and made the site's whole RSS feed not well-formed XML. A raw NUL is not a
	 * legal XML 1.0 character at all, not even escaped, so nothing downstream
	 * could rescue the document.
	 */
	public function test_create_post_does_not_store_a_nul_in_the_title(): void {
		$this->acting_as( 'editor' );

		$result = aafm_exec_create_post(
			array(
				'title'   => "aafm NUL\x00probe",
				'content' => 'Body copy.',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$post = get_post( (int) $result['post']['id'] );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertStringNotContainsString( "\x00", $post->post_title, 'A NUL must never reach post_title.' );
		$this->assertSame( 'aafm NULprobe', $post->post_title );
	}

	public function test_update_post_does_not_store_a_nul_in_the_title(): void {
		$this->acting_as( 'editor' );
		$id = self::factory()->post->create( array( 'post_title' => 'Clean title' ) );

		$result = aafm_exec_update_post(
			array(
				'post_id' => $id,
				'title'   => "updated\x00title",
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringNotContainsString( "\x00", get_post( $id )->post_title );
		$this->assertSame( 'updatedtitle', get_post( $id )->post_title );
	}

	/**
	 * The excerpt is the second field the traffic sim proved carried a NUL
	 * through to storage.
	 */
	public function test_create_post_does_not_store_a_nul_in_the_excerpt(): void {
		$this->acting_as( 'editor' );

		$result = aafm_exec_create_post(
			array(
				'title'   => 'Clean title',
				'content' => 'Body copy.',
				'excerpt' => "excerpt\x00probe",
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringNotContainsString( "\x00", get_post( (int) $result['post']['id'] )->post_excerpt );
	}

	/**
	 * The review-defeating variant: the title reads one way to a human in
	 * wp-admin and another way once stored.
	 */
	public function test_create_post_does_not_store_a_bidi_override_in_the_title(): void {
		$this->acting_as( 'editor' );

		$result = aafm_exec_create_post(
			array(
				'title'   => "aafm-rtl-\u{202E}gnisrever\u{202C}-tail",
				'content' => 'Body copy.',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$title = get_post( (int) $result['post']['id'] )->post_title;
		$this->assertStringNotContainsString( "\u{202E}", $title );
		$this->assertStringNotContainsString( "\u{202C}", $title );
	}

	/**
	 * The secondary symptom from the write-up: the slug generator turned the NUL
	 * into a literal "0", so the title and the slug no longer described the same
	 * string. With the title clean at the boundary this resolves on its own.
	 */
	public function test_the_slug_matches_the_cleaned_title(): void {
		$this->acting_as( 'editor' );

		$result = aafm_exec_create_post(
			array(
				'title'   => "aafm NUL\x00probe",
				'content' => 'Body copy.',
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm-nulprobe', get_post( (int) $result['post']['id'] )->post_name );
	}

	/**
	 * The create/update sibling. Found by the Codex review pass: create-user was swept and
	 * update-user was not, which is this project's documented "fixed at one call site, never
	 * swept" archetype. display_name is emitted in feeds, author markup and admin UI.
	 */
	public function test_update_user_does_not_store_a_nul_in_the_display_name(): void {
		$this->acting_as( 'administrator' );
		$id = self::factory()->user->create( array( 'display_name' => 'Clean Name' ) );

		$result = aafm_exec_update_user(
			array(
				'user_id'      => $id,
				'display_name' => "Visible\x00Name",
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertStringNotContainsString( "\x00", get_userdata( $id )->display_name );
	}

	/**
	 * The multi-line counterpart. sanitize_textarea_field() has the same gap as its single-line
	 * sibling, and a media caption is stored as post_excerpt, which the RSS feed renders. So a NUL
	 * arriving through a caption breaks the whole feed exactly the way one in a title does.
	 */
	public function test_multiline_helper_strips_invisibles_but_keeps_newlines(): void {
		$this->assertSame( 'abcdef', aafm_sanitize_multiline_text( "abc\x00def" ) );
		$this->assertSame( 'abcdef', aafm_sanitize_multiline_text( "abc\u{202E}def" ) );

		$out = aafm_sanitize_multiline_text( "line one\nline two" );
		$this->assertStringContainsString( "\n", $out, 'A newline is the whole point of the textarea sanitizer.' );
		$this->assertSame( "line one\nline two", $out );
	}

	/**
	 * B2-09. `]]>` in an excerpt ends the CDATA section the feed wraps it in, so everything after
	 * it is read as markup and the whole document stops being well-formed - the same harm B-18
	 * exists to prevent, through a character B-18 never enumerated.
	 *
	 * It is NOT the same class as a NUL, and the fix is deliberately different. A NUL is invalid
	 * XML anywhere and deceives in every context, so stripping it loses nothing. `]]>` is ordinary
	 * text that only breaks one serialization, and a post about XML has every right to contain it,
	 * so stripping it at storage would be silent data loss.
	 *
	 * WordPress already shows the right answer for the sibling field: get_the_content_feed() does
	 * str_replace( ']]>', ']]&gt;' ) on post_content (wp-includes/feed.php:197). the_excerpt_rss()
	 * has no equivalent - its only filters are convert_chars and ent2ncr, neither of which touches
	 * the sequence - so the excerpt is unprotected where the content is protected. This closes that
	 * asymmetry the same way core closed it, by escaping on the way OUT rather than mutating what
	 * is stored.
	 */
	public function test_the_feed_escape_neutralises_a_cdata_terminator(): void {
		$this->assertSame(
			'before ]]&gt; after',
			aafm_escape_feed_cdata( 'before ]]> after' ),
			'The sequence must be escaped the way core escapes it for post_content.'
		);
	}

	public function test_the_feed_escape_is_registered_on_the_excerpt_filter(): void {
		$this->assertNotFalse(
			has_filter( 'the_excerpt_rss', 'aafm_escape_feed_cdata' ),
			'The escape is worthless unless it actually runs on the field that lacks core protection.'
		);
	}

	public function test_the_feed_escape_leaves_ordinary_text_alone(): void {
		foreach ( array( 'a ] b ]] c > d', 'array[] and <p>markup</p>', 'عنوان عربي', '' ) as $clean ) {
			$this->assertSame( $clean, aafm_escape_feed_cdata( $clean ) );
		}
	}

	/**
	 * The storage side must NOT strip it. Deleting legitimate text is the failure mode this
	 * character class introduces, and the reason the fix sits at output.
	 */
	public function test_the_stored_helpers_keep_the_sequence_intact(): void {
		$this->assertSame( 'about ]]> in xml', aafm_sanitize_plain_text( 'about ]]> in xml' ) );
		$this->assertSame( 'about ]]> in xml', aafm_sanitize_multiline_text( 'about ]]> in xml' ) );
	}

	public function test_multiline_helper_leaves_ordinary_text_alone(): void {
		$this->assertSame( "Para one.\n\nPara two.", aafm_sanitize_multiline_text( "Para one.\n\nPara two." ) );
	}
}
