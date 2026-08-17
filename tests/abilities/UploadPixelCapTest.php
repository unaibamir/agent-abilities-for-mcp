<?php
/**
 * The pixel ceiling on aafm/upload-media (S-4).
 *
 * The ceiling is derived from the host's memory rather than set to a fixed number, so the tests
 * are split accordingly: the arithmetic is exercised through the pure derivation function, where
 * every edge case can be driven exactly, and the wiring is exercised end to end through the
 * public filter.
 *
 * HONEST NOTE ABOUT THE ENVIRONMENT, because it changes what these tests prove. This container
 * runs with memory_limit = -1 AND the imagick extension loaded, so on this host the real ceiling
 * is "no ceiling" for two independent reasons. Every test below therefore drives the ceiling
 * through aafm_upload_max_pixels() or aafm_upload_decode_costs_php_memory() instead of relying on
 * the ambient configuration. Nothing here should be read as evidence about what a 256M GD host
 * computes at runtime; the pure-function rows are what cover that, and they cover it exactly.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class UploadPixelCapTest extends TestCase {

	// 1x1 transparent PNG.
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
	 * A real PNG whose IHDR declares the given dimensions.
	 *
	 * The bytes stay a genuine PNG so the byte sniff still passes and this exercises the pixel
	 * guard rather than the type guard. Only the IHDR width and height are rewritten, and the
	 * chunk CRC is recomputed so the header is internally consistent. This is exactly the shape of
	 * a decompression bomb: tiny on disk, enormous once decoded.
	 *
	 * PNG layout: 8 signature, 4 length, 4 "IHDR", 13 data, 4 CRC.
	 *
	 * @param int $width  Declared width.
	 * @param int $height Declared height.
	 * @return string PNG bytes.
	 */
	private function png_declaring( int $width, int $height ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding this file's own inline fixture, not caller input.
		$png = (string) base64_decode( self::PNG_B64, true );

		$data  = pack( 'N', $width ) . pack( 'N', $height ) . substr( $png, 24, 5 );
		$chunk = 'IHDR' . $data;

		return substr( $png, 0, 12 ) . $chunk . pack( 'N', crc32( $chunk ) ) . substr( $png, 33 );
	}

	/**
	 * Count every file currently under the uploads directory.
	 *
	 * @return int
	 */
	private function count_uploaded_files(): int {
		$basedir = wp_upload_dir()['basedir'];
		if ( ! is_dir( $basedir ) ) {
			return 0;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $basedir, \FilesystemIterator::SKIP_DOTS )
		);
		$count    = 0;
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Pin the fixture builder itself before anything relies on it.
	 *
	 * A test whose fixture silently stopped declaring what it claims would pass for the wrong
	 * reason, which is the failure this project has hit before. So the declared dimensions are
	 * read back the same way the guard reads them.
	 */
	public function test_the_bomb_fixture_really_declares_the_dimensions(): void {
		$bytes = $this->png_declaring( 30000, 30000 );

		$size = getimagesizefromstring( $bytes );
		$this->assertIsArray( $size );
		$this->assertSame( 30000, $size[0] );
		$this->assertSame( 30000, $size[1] );

		// Still a real PNG by byte sniff, and still tiny on disk.
		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		$this->assertSame( 'image/png', $finfo->buffer( $bytes ) );
		$this->assertLessThan( 1024, strlen( $bytes ) );
	}

	/**
	 * The derivation arithmetic, exactly.
	 *
	 * 256 MB budget less 60 MB in use is 205520896 bytes, which at four bytes per pixel is
	 * 51380224 pixels. Written as the computed constant rather than a round number so a change to
	 * the bytes-per-pixel model cannot pass unnoticed.
	 */
	public function test_derivation_arithmetic(): void {
		$budget = 256 * 1024 * 1024;
		$in_use = 60 * 1024 * 1024;

		$this->assertSame( 51380224, aafm_derive_max_pixels( $budget, $in_use ) );
		$this->assertSame( intdiv( $budget - $in_use, 4 ), aafm_derive_max_pixels( $budget, $in_use ) );
	}

	/**
	 * Every case where no bound can be established must return 0, meaning allow.
	 *
	 * Refusing a legitimate upload is its own defect, so an unknown budget must never become a
	 * refusal. These are the exact edge cases the operator named: unlimited, shorthand that could
	 * not be read, and a limit already partly consumed.
	 *
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function no_bound_cases(): array {
		return array(
			'memory_limit is -1, unlimited'    => array( -1, 60 ),
			'budget unreadable, parsed as 0'   => array( 0, 60 ),
			'budget negative for any reason'   => array( -999, 60 ),
			'already over budget'              => array( 100, 200 ),
			'exactly at budget, zero headroom' => array( 200, 200 ),
		);
	}

	/**
	 * An undeterminable budget yields no ceiling, never a refusal.
	 *
	 * @dataProvider no_bound_cases
	 *
	 * @param int $budget Budget bytes.
	 * @param int $in_use In-use bytes.
	 */
	public function test_no_determinable_bound_means_no_cap( int $budget, int $in_use ): void {
		$this->assertSame( 0, aafm_derive_max_pixels( $budget, $in_use ) );
	}

	/**
	 * A nonsensical negative in-use figure is floored at zero rather than inflating the budget.
	 */
	public function test_negative_usage_cannot_inflate_the_budget(): void {
		$this->assertSame( 25, aafm_derive_max_pixels( 100, -1000 ) );
	}

	/**
	 * The cap stays out of the way when the decoder does not spend PHP memory, because the cost
	 * model does not hold there and refusing would be a false refusal.
	 *
	 * BOTH directions are asserted in one test, and that is the point. This container runs with
	 * memory_limit = -1, so asserting only "gate off yields 0" passes whether or not the gate
	 * exists - the unlimited budget yields 0 by itself. Mutation testing caught exactly that:
	 * replacing the gate with `true` left the original single-sided assertion green. So the test
	 * installs a finite memory limit first, which makes the gate observable, and then pins that
	 * the two branches actually differ.
	 *
	 * The finite budget is installed through core's own image_memory_limit filter rather than
	 * ini_set. That is not a shortcut, it is the only thing that works here: core defines
	 * WP_MAX_MEMORY_LIMIT once at bootstrap and, when memory_limit is -1, defines it as -1 too
	 * (wp-includes/default-constants.php, the `-1 === $current_limit_int` branch). It is a
	 * constant, so ini_set afterwards cannot make the budget finite. Going through the filter also
	 * exercises the filter integration, which is the path core itself uses.
	 */
	public function test_the_decode_cost_gate_decides_whether_a_cap_exists(): void {
		$budget = static function () {
			return '512M';
		};
		add_filter( 'image_memory_limit', $budget );

		$gd = static function () {
			return true;
		};
		add_filter( 'aafm_upload_decode_costs_php_memory', $gd );
		$with_gd = aafm_upload_max_pixels();
		remove_filter( 'aafm_upload_decode_costs_php_memory', $gd );

		$imagick = static function () {
			return false;
		};
		add_filter( 'aafm_upload_decode_costs_php_memory', $imagick );
		$with_imagick = aafm_upload_max_pixels();
		remove_filter( 'aafm_upload_decode_costs_php_memory', $imagick );

		remove_filter( 'image_memory_limit', $budget );

		$this->assertGreaterThan( 0, $with_gd, 'a GD host with a finite budget must derive a ceiling.' );
		$this->assertSame( 0, $with_imagick, 'a host whose decoder does not spend PHP memory must get no ceiling.' );
	}

	/**
	 * The real derivation runs end to end against a finite budget, not just the pure function.
	 *
	 * Without this the whole gathering half of aafm_upload_max_pixels() is unexercised on this
	 * container: the unlimited path short-circuits before the image_memory_limit filter, the
	 * WP_MAX_MEMORY_LIMIT comparison, and the subtraction are ever reached.
	 */
	public function test_the_derived_ceiling_is_consistent_with_the_budget(): void {
		$budget_filter = static function () {
			return '512M';
		};
		add_filter( 'image_memory_limit', $budget_filter );

		$gd = static function () {
			return true;
		};
		add_filter( 'aafm_upload_decode_costs_php_memory', $gd );
		$derived = aafm_upload_max_pixels();
		remove_filter( 'aafm_upload_decode_costs_php_memory', $gd );

		remove_filter( 'image_memory_limit', $budget_filter );

		$budget = wp_convert_hr_to_bytes( '512M' );

		// Bounded above by the whole budget at four bytes per pixel, and strictly positive. Not
		// pinned to a literal, because the in-use figure moves between runs.
		$this->assertGreaterThan( 0, $derived );
		$this->assertLessThanOrEqual( intdiv( $budget, 4 ), $derived );
	}

	/**
	 * The public override works in both directions: a site owner can set a ceiling, or remove one.
	 *
	 * Deliberately run WITHOUT forcing the decode-cost branch. This container derives no ceiling of
	 * its own, so if the filter only ran on the deriving path this would fail, which is what an
	 * earlier revision of the guard actually did. An override that could only relax the guard and
	 * never impose one would be half a filter, and on an ImageMagick host it would be no filter at
	 * all.
	 */
	public function test_the_ceiling_filter_is_authoritative(): void {
		$set = static function () {
			return 1234;
		};
		add_filter( 'aafm_upload_max_pixels', $set );
		$this->assertSame( 1234, aafm_upload_max_pixels() );
		remove_filter( 'aafm_upload_max_pixels', $set );

		$off = static function () {
			return 0;
		};
		add_filter( 'aafm_upload_max_pixels', $off );
		$this->assertSame( 0, aafm_upload_max_pixels() );
		remove_filter( 'aafm_upload_max_pixels', $off );

		// A negative override is clamped to "no cap" rather than refusing everything.
		$negative = static function () {
			return -5;
		};
		add_filter( 'aafm_upload_max_pixels', $negative );
		$this->assertSame( 0, aafm_upload_max_pixels() );
		remove_filter( 'aafm_upload_max_pixels', $negative );
	}

	/**
	 * THE CASE THE WHOLE FIX EXISTS FOR. An image that is tiny on disk but declares dimensions
	 * beyond the ceiling is refused from its header, before any pixel is decoded and before any
	 * byte is written.
	 *
	 * Can-go-red evidence: with the guard removed from aafm_exec_upload_media() this returns a
	 * successful upload array rather than a WP_Error, so the WP_Error assertion fails and so does
	 * the file-count assertion.
	 */
	public function test_an_image_declaring_too_many_pixels_is_refused_and_writes_nothing(): void {
		$this->acting_as( 'author' );

		$cap = static function () {
			return 4;
		};
		add_filter( 'aafm_upload_max_pixels', $cap );

		$before = $this->count_uploaded_files();
		$out    = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 's4-bomb.png',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding this test's own fixture for the ability's base64 input.
				'data_base64' => base64_encode( $this->png_declaring( 30000, 30000 ) ),
			)
		);

		remove_filter( 'aafm_upload_max_pixels', $cap );

		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'aafm_too_many_pixels', $out->get_error_code() );
		$this->assertSame( $before, $this->count_uploaded_files(), 'a refused upload must not leave a file behind.' );
	}

	/**
	 * The refusal has to be actionable. An unexplained error on a legitimate-looking upload is its
	 * own defect, so the message names the size, the ceiling, and what to do about it.
	 */
	public function test_the_refusal_explains_the_limit_and_the_remedy(): void {
		$this->acting_as( 'author' );

		$cap = static function () {
			return 4;
		};
		add_filter( 'aafm_upload_max_pixels', $cap );

		$out = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 's4-explain.png',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding this test's own fixture for the ability's base64 input.
				'data_base64' => base64_encode( $this->png_declaring( 3000, 2000 ) ),
			)
		);

		remove_filter( 'aafm_upload_max_pixels', $cap );

		$this->assertInstanceOf( WP_Error::class, $out );
		$message = $out->get_error_message();

		// What the image was.
		$this->assertStringContainsString( '3000', $message );
		$this->assertStringContainsString( '2000', $message );
		// What the limit was, and that it is expressed in the same unit as the image.
		$this->assertStringContainsString( 'megapixels', $message );
		// Why the limit exists.
		$this->assertStringContainsString( 'memory', $message );
		// How to get past it.
		$this->assertStringContainsString( 'aafm_upload_max_pixels', $message );
	}

	/**
	 * An image inside the ceiling is untouched by the guard.
	 *
	 * This is the over-refusal direction, and it is the one that matters most for the 50+ live
	 * installs: a guard that quietly started refusing ordinary uploads would be worse than the bug
	 * it replaced.
	 */
	public function test_an_image_within_the_ceiling_is_accepted(): void {
		$this->acting_as( 'author' );

		$cap = static function () {
			return 1000000;
		};
		add_filter( 'aafm_upload_max_pixels', $cap );

		$out = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 's4-ok.png',
				'data_base64' => self::PNG_B64,
			)
		);

		remove_filter( 'aafm_upload_max_pixels', $cap );

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'attachment_id', $out );

		$file = get_attached_file( (int) $out['attachment_id'] );
		if ( is_string( $file ) && '' !== $file ) {
			$this->written_files[] = $file;
		}
	}

	/**
	 * Exactly at the ceiling is accepted. One pixel over is refused.
	 *
	 * The comparison is `>`, not `>=`, and nothing else in this file sits on the boundary, so an
	 * off-by-one there would pass every other row. Added after mutation testing showed exactly
	 * that: flipping `>` to `>=` left the whole suite green.
	 *
	 * The 1x1 fixture is one pixel, so a ceiling of 1 is the boundary itself.
	 */
	public function test_the_ceiling_is_inclusive_at_the_boundary(): void {
		$this->acting_as( 'author' );

		$exactly_one = static function () {
			return 1;
		};
		add_filter( 'aafm_upload_max_pixels', $exactly_one );
		$at = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 's4-boundary-at.png',
				'data_base64' => self::PNG_B64,
			)
		);
		remove_filter( 'aafm_upload_max_pixels', $exactly_one );

		$this->assertIsArray( $at, 'an image exactly at the ceiling must be accepted.' );
		$file = get_attached_file( (int) $at['attachment_id'] );
		if ( is_string( $file ) && '' !== $file ) {
			$this->written_files[] = $file;
		}

		// Same ceiling of 1, an image of 2 pixels: now over, so refused. Kept on the same ceiling
		// rather than lowering it, because a ceiling of 0 would mean "no cap" and prove nothing.
		add_filter( 'aafm_upload_max_pixels', $exactly_one );
		$over = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 's4-boundary-over.png',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding this test's own fixture for the ability's base64 input.
				'data_base64' => base64_encode( $this->png_declaring( 2, 1 ) ),
			)
		);
		remove_filter( 'aafm_upload_max_pixels', $exactly_one );

		$this->assertInstanceOf( WP_Error::class, $over );
		$this->assertSame( 'aafm_too_many_pixels', $over->get_error_code() );
	}

	/**
	 * With the ceiling removed, the guard does not fire at all - so a site owner who overrides it
	 * to 0 genuinely gets the previous behaviour back rather than a smaller cap.
	 */
	public function test_a_zero_ceiling_disables_the_guard(): void {
		$this->acting_as( 'author' );

		$off = static function () {
			return 0;
		};
		add_filter( 'aafm_upload_max_pixels', $off );

		$out = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 's4-nocap.png',
				'data_base64' => self::PNG_B64,
			)
		);

		remove_filter( 'aafm_upload_max_pixels', $off );

		$this->assertIsArray( $out );
		$file = get_attached_file( (int) $out['attachment_id'] );
		if ( is_string( $file ) && '' !== $file ) {
			$this->written_files[] = $file;
		}
	}

	/**
	 * A header the guard cannot read is not the guard's business. It must fall through rather than
	 * refuse, because "I could not measure this" is not evidence of a bomb, and refusing on it
	 * would reject files the byte sniff already accepted.
	 *
	 * The upload may still fail further down on the written-file type check; what is asserted is
	 * only that it is not THIS guard that refused it.
	 */
	public function test_an_unreadable_header_is_not_refused_by_the_pixel_guard(): void {
		$this->acting_as( 'author' );

		$cap = static function () {
			return 4;
		};
		add_filter( 'aafm_upload_max_pixels', $cap );

		// A PNG signature with nothing usable behind it: sniffs as image/png, has no readable IHDR.
		$out = wp_get_ability( 'aafm/upload-media' )->execute(
			array(
				'filename'    => 's4-headerless.png',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding this test's own fixture for the ability's base64 input.
				'data_base64' => base64_encode( "\x89PNG\r\n\x1a\n" ),
			)
		);

		remove_filter( 'aafm_upload_max_pixels', $cap );

		if ( $out instanceof WP_Error ) {
			$this->assertNotSame(
				'aafm_too_many_pixels',
				$out->get_error_code(),
				'an unreadable header must not be treated as an oversized image.'
			);
			return;
		}

		$file = get_attached_file( (int) $out['attachment_id'] );
		if ( is_string( $file ) && '' !== $file ) {
			$this->written_files[] = $file;
		}
	}
}
