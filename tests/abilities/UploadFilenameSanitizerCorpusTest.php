<?php
/**
 * Append-only regression corpus for the aafm/upload-media filename and title strip (S-3).
 *
 * WHY THIS IS A CORPUS AND NOT A SINGLE TEST. The bug it pins is this project's documented
 * archetype twice over. It was found once in round 1, reached no compiler for two rounds, and
 * was then half-fixed in a discarded attempt that covered the file name and not the title. The
 * structural answer that finally closed the comparable B2-02 loop was a single corpus pinning the
 * WHOLE union of cases rather than whichever one the current round happened to name, so that a
 * later rewrite fails loudly instead of silently deleting protection with every gate green.
 *
 * ADD ROWS, NEVER DELETE THEM. A deleted row is how coverage gets lost.
 *
 * The union has two halves and both matter:
 *
 * 1. MUST STRIP - the nine Trojan Source bidi characters and the C0 controls. These rows go red
 *    the moment aafm_sanitize_plain_text() stops being applied to the upload base. Verified by
 *    reverting the fix and watching each one fail; see the test docblocks.
 * 2. MUST NOT STRIP - LRM/RLM, emoji, accented text, and the pre-existing traversal and
 *    canonical-extension guarantees. These rows cannot go red by reverting the fix, because they
 *    are identical before and after it. They exist for the opposite direction: they fail if a
 *    later change reaches for a broader sanitizer and starts eating legitimate names. Labelled
 *    honestly rather than counted as if they proved the strip.
 *
 * Every expectation here was MEASURED against the real transform before it was written down, not
 * predicted from reading core.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Post;

final class UploadFilenameSanitizerCorpusTest extends TestCase {

	// 1x1 transparent PNG - the same fixture MediaWriteTest uses.
	private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	/**
	 * Absolute paths written by these uploads, removed in tear_down().
	 *
	 * @var array<int,string>
	 */
	private array $written_files = array();

	public function set_up(): void {
		parent::set_up();
		aafm_install_activity_log();
		aafm_clear_activity_log();

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		update_option( 'aafm_enabled_abilities', array( 'aafm/upload-media' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );
	}

	public function tear_down(): void {
		foreach ( $this->written_files as $file ) {
			if ( '' !== $file && file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$this->written_files = array();
		parent::tear_down();
	}

	/**
	 * Rows that MUST be stripped. Each one goes red if the fix is reverted.
	 *
	 * Payloads are spelled as raw byte escapes rather than literal characters so the file stays
	 * readable in a diff and a stray editor normalisation cannot silently change what is tested.
	 *
	 * @return array<string,array{0:string,1:string}> case => [ raw filename, expected base ].
	 */
	public static function stripped_payloads(): array {
		return array(
			// The nine Trojan Source bidi characters, U+202A-U+202E and U+2066-U+2069.
			'U+202E right-to-left override'  => array( "s3c-202e-\xE2\x80\xAEgnp.exe", 's3c-202e-gnp' ),
			'U+202A left-to-right embed'     => array( "s3c-202a-\xE2\x80\xAAx.png", 's3c-202a-x' ),
			'U+202B right-to-left embed'     => array( "s3c-202b-\xE2\x80\xABx.png", 's3c-202b-x' ),
			'U+202C pop directional'         => array( "s3c-202c-\xE2\x80\xACx.png", 's3c-202c-x' ),
			'U+202D left-to-right override'  => array( "s3c-202d-\xE2\x80\xADx.png", 's3c-202d-x' ),
			'U+2066 left-to-right isolate'   => array( "s3c-2066-\xE2\x81\xA6x.png", 's3c-2066-x' ),
			'U+2067 right-to-left isolate'   => array( "s3c-2067-\xE2\x81\xA7x.png", 's3c-2067-x' ),
			'U+2068 first strong isolate'    => array( "s3c-2068-\xE2\x81\xA8x.png", 's3c-2068-x' ),
			'U+2069 pop directional isolate' => array( "s3c-2069-\xE2\x81\xA9x.png", 's3c-2069-x' ),

			// C0 controls that core's sanitize_file_name() lets through untouched.
			'C0 0x01 start of heading'       => array( "s3c-01-\x01x.png", 's3c-01-x' ),
			'C0 0x08 backspace'              => array( "s3c-08-\x08x.png", 's3c-08-x' ),
			'C0 0x0B vertical tab'           => array( "s3c-0b-\x0Bx.png", 's3c-0b-x' ),
			'C0 0x0C form feed'              => array( "s3c-0c-\x0Cx.png", 's3c-0c-x' ),
			'C0 0x1F unit separator'         => array( "s3c-1f-\x1Fx.png", 's3c-1f-x' ),
		);
	}

	/**
	 * Rows that MUST SURVIVE. None can go red by reverting the fix - stated plainly rather than
	 * counted as evidence for the strip. They guard the opposite failure: a later change that
	 * over-strips and starts mangling legitimate filenames.
	 *
	 * @return array<string,array{0:string,1:string}> case => [ raw filename, expected base ].
	 */
	public static function preserved_payloads(): array {
		return array(
			// LRM/RLM are ordinary punctuation in real mixed-direction text and cannot reverse a
			// run, so aafm_unsafe_text_characters() deliberately spares them. See includes/text.php.
			'U+200E left-to-right mark kept' => array( "s3c-200e-\xE2\x80\x8Ex.png", "s3c-200e-\xE2\x80\x8Ex" ),
			'U+200F right-to-left mark kept' => array( "s3c-200f-\xE2\x80\x8Fx.png", "s3c-200f-\xE2\x80\x8Fx" ),
			'emoji kept'                     => array( "s3c-emoji-\xF0\x9F\x98\x80.png", "s3c-emoji-\xF0\x9F\x98\x80" ),
			// NUL never reaches our strip: core removes it as one of its own special characters.
			// Pinned so a future core change that stopped doing so is visible here.
			'NUL already handled by core'    => array( "s3c-00-\x00x.png", 's3c-00-x' ),
			// Tab, LF and CR are spared BY THE NEEDLE SET, but they never reach it either: core
			// collapses them into a single dash first. The observable outcome is what is pinned.
			'tab collapsed by core'          => array( "s3c-09-\x09x.png", 's3c-09-x' ),
			'LF collapsed by core'           => array( "s3c-0a-\x0Ax.png", 's3c-0a-x' ),
			'CR collapsed by core'           => array( "s3c-0d-\x0Dx.png", 's3c-0d-x' ),
			// Accent folding is core's remove_accents(), unchanged by this fix.
			'accents folded not stripped'    => array( "s3c-caf\xC3\xA9.png", 's3c-cafe' ),
			// The pre-existing traversal guarantee must not regress.
			'traversal still neutralized'    => array( '../../../../s3c-evil.png', 's3c-evil' ),
			'plain name untouched'           => array( 's3c-plain.png', 's3c-plain' ),
		);
	}

	/**
	 * Upload one payload and return the created attachment.
	 *
	 * @param string $filename Raw filename as an agent would send it.
	 * @return WP_Post
	 */
	private function upload( string $filename ): WP_Post {
		$out = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => $filename,
				'data_base64' => self::PNG_B64,
			)
		);

		$this->assertIsArray( $out, 'the upload must succeed; a sanitized name is still a valid name.' );
		$this->assertArrayHasKey( 'attachment_id', $out );

		$attachment_id = (int) $out['attachment_id'];
		$file          = get_attached_file( $attachment_id );
		if ( is_string( $file ) && '' !== $file ) {
			$this->written_files[] = $file;
		}

		$attachment = get_post( $attachment_id );
		$this->assertInstanceOf( WP_Post::class, $attachment );

		return $attachment;
	}

	/**
	 * The stored file's name with its extension removed.
	 *
	 * @param WP_Post $attachment Attachment.
	 * @return string
	 */
	private function stored_basename( WP_Post $attachment ): string {
		$relative = (string) get_post_meta( $attachment->ID, '_wp_attached_file', true );
		$name     = wp_basename( $relative );
		$ext      = pathinfo( $name, PATHINFO_EXTENSION );

		return '' !== $ext ? wp_basename( $name, '.' . $ext ) : $name;
	}

	/**
	 * MUST STRIP - the title.
	 *
	 * Can-go-red evidence: with aafm_sanitize_plain_text() removed from media.php, every row of
	 * this provider fails, because the raw codepoint is present in post_title.
	 *
	 * @dataProvider stripped_payloads
	 *
	 * @param string $filename Raw filename.
	 * @param string $expected Expected sanitized base.
	 */
	public function test_unsafe_characters_never_reach_the_attachment_title( string $filename, string $expected ): void {
		$this->acting_as( 'author' );
		$attachment = $this->upload( $filename );

		$this->assertSame( $expected, $attachment->post_title );
	}

	/**
	 * MUST STRIP - the title, for a caller who holds unfiltered_html. This is the HARDER of the
	 * two title cases and the reason the row above is not sufficient on its own.
	 *
	 * Measured while proving the reversion goes red: with the fix removed, the five C0-control
	 * rows fail on the stored filename but PASS on the title for an author. The reason is not that
	 * the upload path was safe - it is that wp_insert_post() runs the pre_post_title filter, and
	 * for a caller without unfiltered_html that includes wp_filter_kses(), whose wp_kses_no_null()
	 * deletes 0x00-0x08, 0x0B, 0x0C and 0x0E-0x1F. Confirmed directly:
	 * sanitize_post_field('post_title', ..., 'db') leaves the controls intact, wp_filter_kses()
	 * removes them, and neither touches a bidi override.
	 *
	 * An administrator on single site HOLDS unfiltered_html, so that filter is not applied and the
	 * raw control characters reach post_title - which is rendered into the site's RSS feed, where a
	 * single raw C0 control makes the whole document not well-formed. That is precisely the harm
	 * includes/text.php exists to prevent, so it is pinned here against the privileged caller
	 * rather than only the convenient one.
	 *
	 * @dataProvider stripped_payloads
	 *
	 * @param string $filename Raw filename.
	 * @param string $expected Expected sanitized base.
	 */
	public function test_unsafe_characters_never_reach_the_title_for_an_unfiltered_html_caller( string $filename, string $expected ): void {
		$this->acting_as( 'administrator' );
		$this->assertTrue( current_user_can( 'unfiltered_html' ), 'this row is only meaningful for a caller kses does not filter.' );

		$attachment = $this->upload( $filename );

		$this->assertSame( $expected, $attachment->post_title );
	}

	/**
	 * MUST STRIP - the stored file name.
	 *
	 * This is the half the discarded first attempt covered. It is kept as its own assertion rather
	 * than folded into the title test, because $base feeding both consumers is an implementation
	 * detail that a later edit is free to change; if it does, exactly one of these two goes red and
	 * names which consumer regressed.
	 *
	 * Can-go-red evidence: same reversion, every row fails.
	 *
	 * @dataProvider stripped_payloads
	 *
	 * @param string $filename Raw filename.
	 * @param string $expected Expected sanitized base.
	 */
	public function test_unsafe_characters_never_reach_the_stored_filename( string $filename, string $expected ): void {
		$this->acting_as( 'author' );
		$attachment = $this->upload( $filename );

		$this->assertSame( $expected, $this->stored_basename( $attachment ) );
	}

	/**
	 * MUST NOT STRIP - both consumers, for the over-stripping direction.
	 *
	 * Honest label: no row here can go red by reverting the S-3 fix. Each is identical before and
	 * after it. They go red if a later change over-strips.
	 *
	 * @dataProvider preserved_payloads
	 *
	 * @param string $filename Raw filename.
	 * @param string $expected Expected base.
	 */
	public function test_legitimate_names_survive_intact( string $filename, string $expected ): void {
		$this->acting_as( 'author' );
		$attachment = $this->upload( $filename );

		$this->assertSame( $expected, $attachment->post_title );
		$this->assertSame( $expected, $this->stored_basename( $attachment ) );
	}

	/**
	 * The property behind every MUST STRIP row, asserted directly against the needle set rather
	 * than against a hand-written expected string.
	 *
	 * A per-row expectation can be wrong in the same direction as the code. This one cannot: it
	 * reads the same list the sanitizer strips from, so a needle added to aafm_unsafe_text_characters()
	 * is covered here the day it is added, with no new row to remember to write.
	 */
	public function test_no_needle_survives_into_either_stored_field(): void {
		$this->acting_as( 'author' );

		$needles = aafm_unsafe_text_characters();
		$this->assertNotEmpty( $needles );

		// One name carrying every needle at once - the hardest case, not the easiest.
		$hostile    = 's3c-all-' . implode( '', $needles ) . 'tail.png';
		$attachment = $this->upload( $hostile );
		$stored     = $this->stored_basename( $attachment );

		foreach ( $needles as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$attachment->post_title,
				sprintf( 'needle 0x%s survived into post_title', bin2hex( $needle ) )
			);
			$this->assertStringNotContainsString(
				$needle,
				$stored,
				sprintf( 'needle 0x%s survived into the stored filename', bin2hex( $needle ) )
			);
		}

		// And the surrounding legitimate text is still there, so this did not pass by the name
		// having been reduced to nothing.
		$this->assertStringContainsString( 's3c-all-', $attachment->post_title );
		$this->assertStringContainsString( 'tail', $attachment->post_title );
	}

	/**
	 * A name consisting ONLY of stripped characters must fall back to 'upload', not to an empty
	 * name. The fallback runs after the strip; if the two were ordered the other way this would
	 * store an empty base and the file would be named '.png'.
	 *
	 * Can-go-red evidence: with the fix reverted the base is the raw bidi+control byte string,
	 * which is not 'upload', so this row fails.
	 */
	public function test_a_name_of_only_stripped_characters_falls_back_to_upload(): void {
		$this->acting_as( 'author' );
		$attachment = $this->upload( "\xE2\x80\xAE\x01\x02.png" );

		$this->assertSame( 'upload', $attachment->post_title );
		$this->assertSame( 'upload', $this->stored_basename( $attachment ) );
	}

	/**
	 * The canonical-extension rewrite still wins over the caller's claimed extension, on a name
	 * that also needed sanitizing. The two behaviours are independent and this pins that they
	 * compose: '.exe' becomes '.png' AND the override is stripped.
	 */
	public function test_canonical_extension_still_forced_on_a_sanitized_name(): void {
		$this->acting_as( 'author' );
		$attachment = $this->upload( "s3c-ext-\xE2\x80\xAEgnp.exe" );

		$relative = (string) get_post_meta( $attachment->ID, '_wp_attached_file', true );
		$this->assertStringEndsWith( '.png', $relative );
		$this->assertStringNotContainsString( '.exe', $relative );
		$this->assertSame( 's3c-ext-gnp', $attachment->post_title );
	}

	/**
	 * The asymmetry that made S-3 a plugin defect rather than an inherited core limitation:
	 * aafm-update-media already stripped these from a title, and aafm/upload-media did not, on the
	 * same object. Pinned so the two abilities cannot drift apart again.
	 */
	public function test_upload_and_update_agree_about_the_same_title(): void {
		$this->acting_as( 'author' );

		$hostile    = "s3c-agree-\xE2\x80\xAEgnp";
		$attachment = $this->upload( $hostile . '.png' );

		update_option( 'aafm_enabled_abilities', array( 'aafm/upload-media', 'aafm/update-media' ) );
		$this->in_action( 'wp_abilities_api_init', 'aafm_register_enabled_abilities' );

		$out = wp_get_ability( 'aafm/update-media' )->execute(
			array(
				'attachment_id' => $attachment->ID,
				'title'         => $hostile,
			)
		);
		$this->assertIsArray( $out );

		$fresh = get_post( $attachment->ID );
		$this->assertInstanceOf( WP_Post::class, $fresh );

		// Same input, same stored result, whichever ability wrote it.
		$this->assertSame( 's3c-agree-gnp', $attachment->post_title );
		$this->assertSame( 's3c-agree-gnp', $fresh->post_title );
	}
}
