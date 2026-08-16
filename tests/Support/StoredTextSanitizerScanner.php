<?php
/**
 * Raw plain-text sanitizer scanner.
 *
 * B-18 fixed a real defect: invisible and control characters (a raw NUL, a C0 control, a bidi
 * override) reached stored text, and a NUL in post_excerpt breaks the site's whole RSS feed
 * document. The fix is the aafm_sanitize_plain_text() / aafm_sanitize_multiline_text() pair, which
 * run WordPress's own sanitizer and then strip what it leaves behind.
 *
 * That sweep was declared complete three separate times and was wrong each time: it missed
 * update-user, then the order billing/shipping addresses, then the whole sanitize_textarea_field
 * family. Every miss was the same shape - fixed at one call site, left at its siblings. Sweeping
 * harder does not close that class; a scan that runs on every build does.
 *
 * What this scanner deliberately does NOT do is decide whether a call is wrong. Plenty of raw
 * sanitize_text_field() calls are correct and must stay raw, because they sanitize a query or
 * lookup parameter that is never stored - a WP_Query search term, a report date, a template id. A
 * check that flagged call presence would force those to be "fixed" and make the codebase worse. So
 * the scanner only reports WHERE the raw calls are; the destination judgement lives in the test's
 * allowlist, one written reason per entry, and a raw call nobody has justified fails the build.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds every raw call to (and callable-string reference to) the WordPress plain-text sanitizers
 * inside the ability sources, and reports each one with the function that encloses it.
 *
 * Token-based rather than grep-based on purpose. The ability files talk about these sanitizers
 * constantly in docblocks and in the tool descriptions agents read; token_get_all() hands comments
 * and string literals back as their own token types, so a mention in prose can never be mistaken
 * for a call, and a real call can never hide behind unusual spacing.
 */
final class StoredTextSanitizerScanner {

	/**
	 * The WordPress sanitizers this scanner tracks: the two the helper pair wraps.
	 *
	 * Both share the gap the helpers exist to close - a raw NUL is valid UTF-8, so
	 * wp_check_invalid_utf8() passes it and the byte reaches storage intact.
	 *
	 * @var array<int,string>
	 */
	public const SANITIZERS = array(
		'sanitize_text_field',
		'sanitize_textarea_field',
	);

	/**
	 * Scan every ability source and return one record per raw sanitizer use.
	 *
	 * @return array<int,array{file:string,function:string,sanitizer:string,line:int,form:string}>
	 *         Sorted by file, then line.
	 */
	public static function scan(): array {
		$found = array();

		foreach ( self::ability_files() as $file ) {
			foreach ( self::scan_file( $file ) as $record ) {
				$found[] = $record;
			}
		}

		usort(
			$found,
			static function ( array $a, array $b ): int {
				return array( $a['file'], $a['line'] ) <=> array( $b['file'], $b['line'] );
			}
		);

		return $found;
	}

	/**
	 * Collapse scan() records into the stable key the allowlist is written against.
	 *
	 * The key is `<relative file>::<enclosing function>::<sanitizer>` and the value is how many
	 * uses that combination carries. Line numbers are deliberately NOT part of the key: any edit
	 * above a call shifts them, so a line-keyed allowlist goes stale on unrelated work and gets
	 * disabled. A file plus a function name plus the sanitizer only moves when someone renames the
	 * function or moves the code, which is exactly when the justification deserves re-reading.
	 *
	 * The count is part of the record for the same reason it is stable: it changes only when a raw
	 * sanitizer use is added to or removed from that function. So a second, stored-write call
	 * landing inside a function already allowlisted for its query-path call cannot inherit that
	 * function's justification silently.
	 *
	 * @param array<int,array{file:string,function:string,sanitizer:string,line:int,form:string}> $records Scan output.
	 * @return array<string,array{count:int,lines:array<int,int>}> Keyed by the stable key.
	 */
	public static function group( array $records ): array {
		$grouped = array();

		foreach ( $records as $record ) {
			$key = self::key( $record );
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'count' => 0,
					'lines' => array(),
				);
			}
			++$grouped[ $key ]['count'];
			$grouped[ $key ]['lines'][] = $record['line'];
		}

		ksort( $grouped );

		return $grouped;
	}

	/**
	 * The stable allowlist key for one record.
	 *
	 * @param array{file:string,function:string,sanitizer:string,line:int,form:string} $record Scan record.
	 * @return string
	 */
	public static function key( array $record ): string {
		return $record['file'] . '::' . $record['function'] . '::' . $record['sanitizer'];
	}

	/**
	 * Scan one file.
	 *
	 * @param string $file Absolute path.
	 * @return array<int,array{file:string,function:string,sanitizer:string,line:int,form:string}>
	 */
	public static function scan_file( string $file ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file from disk for static analysis in a test, not a remote resource.
		return self::scan_source( (string) file_get_contents( $file ), self::relative_path( $file ) );
	}

	/**
	 * Scan a source string.
	 *
	 * Split out from scan_file() so the scanner's own behaviour can be proven against known input -
	 * a docblock mention, a method of the same name, a callable string - without inventing a file
	 * on disk or relying on some real ability file keeping its current wording.
	 *
	 * @param string $source   PHP source, including its opening tag.
	 * @param string $relative The label reported as `file` on each record.
	 * @return array<int,array{file:string,function:string,sanitizer:string,line:int,form:string}>
	 */
	public static function scan_source( string $source, string $relative ): array {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );

		$function = '{file scope}';
		$found    = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) ) {
				continue;
			}

			// A named function declaration renames the enclosing scope. A closure (`function` or
			// `fn` followed straight by `(`) has no name of its own, so calls inside it keep
			// reporting against the named function that contains it - which is the scope a reader
			// of the allowlist would look for.
			if ( T_FUNCTION === $token[0] ) {
				$name = self::declared_function_name( $tokens, $i, $count );
				if ( null !== $name ) {
					$function = $name;
				}
				continue;
			}

			// The ordinary call form: sanitize_text_field( … ).
			if ( T_STRING === $token[0] && in_array( $token[1], self::SANITIZERS, true ) ) {
				if ( ! self::is_function_call( $tokens, $i, $count ) ) {
					continue;
				}
				$found[] = array(
					'file'      => $relative,
					'function'  => $function,
					'sanitizer' => (string) $token[1],
					'line'      => (int) $token[2],
					'form'      => 'call',
				);
				continue;
			}

			// The callable-string form: array_map( 'sanitize_text_field', … ). Same effect on the
			// same values, invisible to any check that only looks for a name followed by a paren.
			if ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$literal = trim( (string) $token[1], "'\"" );
				if ( in_array( $literal, self::SANITIZERS, true ) ) {
					$found[] = array(
						'file'      => $relative,
						'function'  => $function,
						'sanitizer' => $literal,
						'line'      => (int) $token[2],
						'form'      => 'callable-string',
					);
				}
			}
		}

		return $found;
	}

	/**
	 * The name declared by a `function` keyword, or null when it opens a closure.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $index  Index of the T_FUNCTION token.
	 * @param int                                           $count  Token count.
	 * @return string|null
	 */
	private static function declared_function_name( array $tokens, int $index, int $count ): ?string {
		for ( $i = $index + 1; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			// A by-reference return (`function &foo()`) puts an ampersand before the name.
			if ( '&' === $token ) {
				continue;
			}
			if ( is_array( $token ) && T_STRING === $token[0] ) {
				return (string) $token[1];
			}
			return null;
		}

		return null;
	}

	/**
	 * Whether a T_STRING at $index is being CALLED, rather than declared or reached through an
	 * object or a class.
	 *
	 * A method named sanitize_text_field on some object is a different function entirely, and a
	 * declaration of our own would be a shadow, not a use. Neither should be reported as a raw
	 * sanitizer call.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $index  Index of the T_STRING token.
	 * @param int                                           $count  Token count.
	 * @return bool
	 */
	private static function is_function_call( array $tokens, int $index, int $count ): bool {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( is_array( $token ) && in_array( $token[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				return false;
			}
			break;
		}

		for ( $i = $index + 1; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return '(' === $token;
		}

		return false;
	}

	/**
	 * Every ability source file, sorted for a stable report.
	 *
	 * @return array<int,string> Absolute paths.
	 */
	public static function ability_files(): array {
		$root = self::plugin_root() . 'includes/abilities';
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			if ( $item instanceof SplFileInfo && $item->isFile() && 'php' === strtolower( $item->getExtension() ) ) {
				$files[] = $item->getPathname();
			}
		}

		sort( $files );

		return $files;
	}

	/**
	 * Plugin root (the repo root, two directories up from tests/Support), with a trailing slash.
	 *
	 * @return string
	 */
	private static function plugin_root(): string {
		return rtrim( dirname( __DIR__, 2 ), '/' ) . '/';
	}

	/**
	 * A path relative to the plugin root, for compact evidence.
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	private static function relative_path( string $file ): string {
		$root = self::plugin_root();
		return 0 === strpos( $file, $root ) ? substr( $file, strlen( $root ) ) : $file;
	}

	/**
	 * Render records as one human line each for a test failure message.
	 *
	 * @param array<int,array{file:string,function:string,sanitizer:string,line:int,form:string}> $records Records to format.
	 * @return string
	 */
	public static function format( array $records ): string {
		$lines = array();
		foreach ( $records as $record ) {
			$lines[] = sprintf(
				'  %s:%d  %s() calls %s()%s',
				$record['file'],
				$record['line'],
				$record['function'],
				$record['sanitizer'],
				'callable-string' === $record['form'] ? ' [as a callable string]' : ''
			);
		}
		return implode( "\n", $lines );
	}
}
