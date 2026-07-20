<?php
/**
 * Annotation-correctness scanner.
 *
 * The adversarial guard against the "readonly-but-writes" class: an ability annotated
 * `readonly: true` (or grouped `risk: 'read'`) whose execute callback actually mutates state.
 * Agents introspect the annotations and plan dry-run / speculative calls on the promise that a
 * readonly tool cannot change anything, so a lying annotation is both a security hazard and a
 * silent-divergence bug. Unit tests miss it because the mock looks exactly like the real writer;
 * only reading the callback body and comparing behaviour to the claim catches it.
 *
 * This is our registry-shaped port of the WordPress `wp-abilities-verify` skill. That skill
 * enumerates by grepping `wp_register_ability(` / `wp_get_abilities()`; our abilities are declared
 * in aafm_get_abilities_registry() and built lazily through per-row args_builders, so we enumerate
 * the registry instead and read `meta.annotations.readonly` + `execute_callback` off the built args.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

use ReflectionException;
use ReflectionFunction;

/**
 * Reflects each read/readonly ability's execute callback (and one level of aafm_* delegation) and
 * flags any write-shaped call the callback makes. Pure static analysis: it reads source, it never
 * executes an ability.
 */
final class AnnotationScanner {

	/**
	 * Write-call signatures. Each is a PCRE body (no delimiters) matched against comment-stripped
	 * source lines. A match is a candidate durable write that contradicts a readonly/read claim.
	 * Function-call patterns all require a trailing `(` so the same token appearing inside a string
	 * or a WP_Error message never trips the scan. Centralised here so extending the vocabulary (e.g.
	 * when a new integration lands) is a one-line edit.
	 *
	 * @var array<int,string>
	 */
	private const WRITE_PATTERNS = array(
		// Direct database mutation via $wpdb (query is included: it can carry any statement).
		'\$wpdb\s*->\s*(?:update|insert|delete|replace|query)\s*\(',
		// Options API.
		'\b(?:update_option|add_option|delete_option)\s*\(',
		// Post writes (wp_insert_post fires save_post; the API call IS the write).
		'\b(?:wp_insert_post|wp_update_post|wp_delete_post|wp_trash_post)\s*\(',
		// Term writes.
		'\b(?:wp_insert_term|wp_update_term|wp_delete_term|wp_set_object_terms)\s*\(',
		// Comment writes.
		'\b(?:wp_insert_comment|wp_update_comment)\s*\(',
		// User writes.
		'\b(?:wp_insert_user|wp_update_user)\s*\(',
		// Object-meta writes across all four object types.
		'\b(?:update|add|delete)_(?:post|term|user|comment)_meta\s*\(',
		// Nav-menu writes.
		'\b(?:wp_update_nav_menu_item|wp_update_nav_menu)\s*\(',
		// Cron scheduling (a deferred durable write, accumulates per call).
		'\b(?:wp_schedule_event|wp_schedule_single_event)\s*\(',
		// Filesystem writes.
		'\b(?:file_put_contents|fwrite)\s*\(',
		// fopen in a write/append/create mode.
		'\bfopen\s*\([^)]*,\s*[\'"][waxc]',
		// State-changing (non-GET) HTTP.
		'\bwp_remote_post\s*\(',
	);

	/**
	 * Inline suppression marker, mirroring the wp-abilities-verify convention. A write on (or
	 * adjacent to) a line carrying `// verify-ignore: readonly -- <reason>` is recorded as a
	 * suppressed exception with its reason, not a violation. Accepts the readonly/read/all
	 * annotation names; `--` and the reason are captured for the report.
	 */
	private const SUPPRESSION_PATTERN = '~//\s*verify-ignore:\s*(readonly|read|all)\b(?:\s*--\s*(.*?))?\s*$~i';

	/**
	 * Per-file source line cache (filename => 0-indexed array of lines), so a helper reflected once
	 * per ability is not re-read from disk for every caller.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static array $file_cache = array();

	/**
	 * Scan a registry for read/readonly abilities whose callback writes.
	 *
	 * For every row where `meta.annotations.readonly === true` OR `risk === 'read'`, the execute
	 * callback body is reflected and matched against WRITE_PATTERNS, then one level of aafm_*
	 * delegation from that body is followed and matched the same way (a read callback that hands off
	 * to a shared helper which writes is exactly the case a shallow grep misses). Depth is capped at
	 * one hop on purpose: it catches the shared-helper case without the runtime cost or false-trail
	 * risk of unbounded call-graph walking.
	 *
	 * @param array<string,array<string,mixed>> $registry Registry keyed by ability name.
	 * @return array{scanned:int,abilities:array<int,string>,violations:array<int,array<string,mixed>>,suppressed:array<int,array<string,mixed>>}
	 */
	public static function scan( array $registry ): array {
		$abilities  = array();
		$violations = array();
		$suppressed = array();

		foreach ( $registry as $name => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$risk    = isset( $row['risk'] ) ? (string) $row['risk'] : '';
			$args    = self::build_args( $row );
			$claim   = array();
			$execute = isset( $args['execute_callback'] ) && is_string( $args['execute_callback'] )
				? $args['execute_callback']
				: null;

			$readonly = $args['meta']['annotations']['readonly'] ?? null;
			if ( true === $readonly ) {
				$claim[] = 'readonly';
			}
			if ( 'read' === $risk ) {
				$claim[] = 'risk:read';
			}

			// Not a read/readonly claim, or nothing reflectable to read: outside this guard's scope.
			if ( array() === $claim || null === $execute ) {
				continue;
			}

			$abilities[]  = (string) $name;
			$claim_string = implode( '+', $claim );

			foreach ( self::scan_callback( $execute ) as $finding ) {
				$finding['ability'] = (string) $name;
				$finding['claim']   = $claim_string;
				if ( null !== $finding['reason'] ) {
					$suppressed[] = $finding;
				} else {
					$violations[] = $finding;
				}
			}
		}

		sort( $abilities );

		return array(
			'scanned'    => count( $abilities ),
			'abilities'  => $abilities,
			'violations' => $violations,
			'suppressed' => $suppressed,
		);
	}

	/**
	 * Build the full ability args from a registry row via its args_builder.
	 *
	 * The live registry row carries only UI metadata + an `args_builder` reference; the annotations
	 * and execute callback live on what that builder returns (see includes/register.php, which calls
	 * the builder at registration time). All builders are zero-argument.
	 *
	 * @param array<string,mixed> $row Registry row.
	 * @return array<string,mixed> Built args, or empty when the row has no callable builder.
	 */
	private static function build_args( array $row ): array {
		$builder = $row['args_builder'] ?? null;
		if ( ! is_string( $builder ) || ! function_exists( $builder ) ) {
			return array();
		}
		$args = call_user_func( $builder );
		return is_array( $args ) ? $args : array();
	}

	/**
	 * Scan one ability's execute callback plus one level of aafm_* delegation.
	 *
	 * @param string $execute Execute-callback function name.
	 * @return array<int,array<string,mixed>> Findings (each with keys call/file/line/via/reason).
	 */
	private static function scan_callback( string $execute ): array {
		$findings = self::scan_function( $execute, null );

		// One-level delegation: any user-defined aafm_* function this body calls is reflected and
		// scanned too, then we stop. Not the callback itself, and deduplicated.
		$body = self::body_of( $execute );
		if ( null === $body ) {
			return $findings;
		}

		$delegates = self::collect_aafm_calls( $body['code'], $execute );
		foreach ( $delegates as $delegate ) {
			if ( ! self::is_scannable_plugin_function( $delegate ) ) {
				continue;
			}
			foreach ( self::scan_function( $delegate, $execute ) as $finding ) {
				$findings[] = $finding;
			}
		}

		return $findings;
	}

	/**
	 * Match WRITE_PATTERNS against a single function's body.
	 *
	 * @param string      $fn_name Function name to reflect and read.
	 * @param string|null $via      The delegating callback name when this is a one-hop delegate, else null.
	 * @return array<int,array<string,mixed>> Findings for this body.
	 */
	private static function scan_function( string $fn_name, ?string $via ): array {
		$body = self::body_of( $fn_name );
		if ( null === $body ) {
			return array();
		}

		$findings = array();
		foreach ( $body['lines'] as $line_number => $raw ) {
			$code = self::strip_comment( $raw );
			if ( '' === trim( $code ) ) {
				continue;
			}
			foreach ( self::WRITE_PATTERNS as $pattern ) {
				if ( 1 !== preg_match( '~' . $pattern . '~', $code, $matches ) ) {
					continue;
				}
				$reason     = self::suppression_reason( $body['lines'], $line_number );
				$findings[] = array(
					'call'   => trim( $matches[0] ),
					'file'   => self::relative_path( $body['file'] ),
					'line'   => $line_number,
					'via'    => $via,
					'reason' => $reason,
				);
			}
		}

		return $findings;
	}

	/**
	 * Reflect a function and return its body: the file, and the source lines keyed by real
	 * (1-indexed) line number, plus the comment-stripped code joined for delegate discovery.
	 *
	 * @param string $fn_name Function name.
	 * @return array{file:string,lines:array<int,string>,code:string}|null Null when not reflectable.
	 */
	private static function body_of( string $fn_name ): ?array {
		if ( ! function_exists( $fn_name ) ) {
			return null;
		}
		try {
			$reflection = new ReflectionFunction( $fn_name );
		} catch ( ReflectionException $e ) {
			return null;
		}

		$file = (string) $reflection->getFileName();
		$all  = self::file_lines( $file );
		if ( array() === $all ) {
			return null;
		}

		$start = (int) $reflection->getStartLine();
		$end   = (int) $reflection->getEndLine();

		$lines = array();
		$code  = '';
		for ( $number = $start; $number <= $end; $number++ ) {
			$text             = $all[ $number - 1 ] ?? '';
			$lines[ $number ] = $text;
			$code            .= self::strip_comment( $text ) . "\n";
		}

		return array(
			'file'  => $file,
			'lines' => $lines,
			'code'  => $code,
		);
	}

	/**
	 * Whether a `// verify-ignore: readonly|read|all` marker sits on, just above, or just below a
	 * flagged line, and its reason. A suppressed write is a recorded exception, not a violation.
	 *
	 * @param array<int,string> $lines       Body lines keyed by real line number.
	 * @param int               $line_number The flagged line.
	 * @return string|null The reason (possibly empty string) when suppressed, else null.
	 */
	private static function suppression_reason( array $lines, int $line_number ): ?string {
		foreach ( array( $line_number, $line_number - 1, $line_number + 1 ) as $candidate ) {
			if ( ! isset( $lines[ $candidate ] ) ) {
				continue;
			}
			if ( 1 === preg_match( self::SUPPRESSION_PATTERN, $lines[ $candidate ], $matches ) ) {
				return isset( $matches[2] ) ? trim( $matches[2] ) : '';
			}
		}
		return null;
	}

	/**
	 * Distinct aafm_* function names called in a body, excluding the callback itself.
	 *
	 * @param string $code The comment-stripped body.
	 * @param string $owner The owning function name, excluded from its own delegate list.
	 * @return array<int,string> Unique delegate names.
	 */
	private static function collect_aafm_calls( string $code, string $owner ): array {
		if ( 1 !== preg_match_all( '~\b(aafm_[a-z0-9_]+)\s*\(~', $code, $matches ) && 0 === count( $matches[1] ) ) {
			return array();
		}
		$names = array_values( array_unique( $matches[1] ) );
		return array_values( array_diff( $names, array( $owner ) ) );
	}

	/**
	 * Whether a delegate is a user-defined function that lives inside this plugin (so vendor and
	 * core helpers are never followed).
	 *
	 * @param string $fn_name Function name.
	 * @return bool
	 */
	private static function is_scannable_plugin_function( string $fn_name ): bool {
		if ( ! function_exists( $fn_name ) ) {
			return false;
		}
		try {
			$reflection = new ReflectionFunction( $fn_name );
		} catch ( ReflectionException $e ) {
			return false;
		}
		if ( $reflection->isInternal() ) {
			return false;
		}
		$file = (string) $reflection->getFileName();
		return '' !== $file && 0 === strpos( $file, self::plugin_root() );
	}

	/**
	 * Read a file into a 0-indexed line array, cached per path.
	 *
	 * @param string $file Absolute path.
	 * @return array<int,string> Lines, or empty when unreadable.
	 */
	private static function file_lines( string $file ): array {
		if ( '' === $file ) {
			return array();
		}
		if ( isset( self::$file_cache[ $file ] ) ) {
			return self::$file_cache[ $file ];
		}
		if ( ! is_readable( $file ) ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file from disk for static reflection in a test, not a remote resource.
		$contents                  = (string) file_get_contents( $file );
		self::$file_cache[ $file ] = explode( "\n", $contents );
		return self::$file_cache[ $file ];
	}

	/**
	 * Drop a trailing `//` line comment so a write token inside a comment (including the
	 * verify-ignore marker itself) is never matched as code. Full docblock/comment lines collapse
	 * to empty. Suppression detection runs on the raw line beforehand, so this never hides a marker.
	 *
	 * @param string $line Raw source line.
	 * @return string Code portion.
	 */
	private static function strip_comment( string $line ): string {
		$trimmed = ltrim( $line );
		if ( '' === $trimmed || 0 === strpos( $trimmed, '*' ) || 0 === strpos( $trimmed, '//' ) || 0 === strpos( $trimmed, '/*' ) || 0 === strpos( $trimmed, '#' ) ) {
			return '';
		}
		return (string) preg_replace( '~//.*$~', '', $line );
	}

	/**
	 * Plugin root (the repo root, two directories up from tests/Support), with a trailing slash.
	 *
	 * @return string
	 */
	private static function plugin_root(): string {
		$root = dirname( __DIR__, 2 );
		return rtrim( $root, '/' ) . '/';
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
	 * Render findings as one human line each for a test failure message.
	 *
	 * @param array<int,array<string,mixed>> $findings Findings to format.
	 * @return string
	 */
	public static function format( array $findings ): string {
		$lines = array();
		foreach ( $findings as $finding ) {
			$via     = null !== ( $finding['via'] ?? null ) ? ' (via ' . $finding['via'] . '())' : '';
			$lines[] = sprintf(
				'  %s | %s | %s%s | %s:%d',
				$finding['ability'] ?? '?',
				$finding['claim'] ?? '?',
				$finding['call'] ?? '?',
				$via,
				$finding['file'] ?? '?',
				(int) ( $finding['line'] ?? 0 )
			);
		}
		return implode( "\n", $lines );
	}
}
