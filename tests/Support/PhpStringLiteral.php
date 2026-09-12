<?php
/**
 * Decodes a PHP string-literal token's real runtime value, for the source-scanning tests that
 * compare a call's literal argument against a known option/sanitizer name.
 *
 * R4-6 (1.7.5 deferred, round 4): SecurityOptionWritesSweepTest's option-name check and
 * StoredTextSanitizerScanner's callable-string check both compared a T_CONSTANT_ENCAPSED_STRING
 * token's raw text with a bare `substr( $text, 1, -1 )`/`trim( $text, "'\"" )` - correct for the
 * common case, wrong for a `b`/`B`-prefixed binary string, an escaped character
 * (`"aafm_oauth_\x65nabled"` is the runtime string `aafm_oauth_enabled`, byte for byte), or a
 * constant heredoc/nowdoc. A scanner that does not decode these can be made to pass a literal
 * option/sanitizer name through unrecognised while it still resolves, at runtime, to exactly the
 * name being guarded against.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

/**
 * Static helper: no state, just decoding.
 */
final class PhpStringLiteral {

	/**
	 * The runtime string value of the literal starting at $index, or null when $index is not the
	 * start of a literal this decoder understands (a non-constant heredoc/nowdoc with
	 * interpolation, or simply not a string at all).
	 *
	 * Understands: single- and double-quoted T_CONSTANT_ENCAPSED_STRING (including a `b`/`B`
	 * prefix and the escape sequences PHP recognises in each), and a constant heredoc/nowdoc
	 * (T_START_HEREDOC/T_END_HEREDOC framing plain T_ENCAPSED_AND_WHITESPACE content, no `$`
	 * interpolation).
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Token stream.
	 * @param int                                            $index Index to decode from.
	 * @return array{0:string,1:int}|null Decoded value and the index just past the literal, or null.
	 */
	public static function decode_at( array $tokens, int $index ): ?array {
		if ( ! isset( $tokens[ $index ] ) ) {
			return null;
		}
		$token = $tokens[ $index ];

		if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			$decoded = self::decode_quoted( $token[1] );
			return null === $decoded ? null : array( $decoded, $index + 1 );
		}

		if ( is_array( $token ) && T_START_HEREDOC === $token[0] ) {
			return self::decode_heredoc( $tokens, $index );
		}

		return null;
	}

	/**
	 * A single- or double-quoted T_CONSTANT_ENCAPSED_STRING's runtime value, including an optional
	 * `b`/`B` binary prefix.
	 *
	 * @param string $raw Raw token text, quotes and prefix included.
	 * @return string|null
	 */
	private static function decode_quoted( string $raw ): ?string {
		if ( '' !== $raw && ( 'b' === $raw[0] || 'B' === $raw[0] ) ) {
			$raw = substr( $raw, 1 );
		}
		if ( strlen( $raw ) < 2 ) {
			return null;
		}
		$quote = $raw[0];
		if ( ( "'" !== $quote && '"' !== $quote ) || $raw[ strlen( $raw ) - 1 ] !== $quote ) {
			return null;
		}
		$body = substr( $raw, 1, -1 );

		if ( "'" === $quote ) {
			// Single-quoted strings recognise exactly two escapes: \\ and \'.
			return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $body );
		}

		return self::unescape_double_quoted( $body );
	}

	/**
	 * Unescape a double-quoted (or heredoc) body's simple escapes. Returns null if the body
	 * contains `$` interpolation this decoder cannot statically resolve, rather than guess.
	 *
	 * @param string $body Content between the quotes (or the heredoc's lines).
	 * @return string|null
	 */
	private static function unescape_double_quoted( string $body ): ?string {
		if ( 1 === preg_match( '/(?<!\\\\)\$[A-Za-z_{]/', $body ) ) {
			return null; // Real interpolation: not a constant value this decoder can resolve.
		}

		$result = '';
		$length = strlen( $body );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $body[ $i ];
			if ( '\\' !== $char || $i + 1 >= $length ) {
				$result .= $char;
				continue;
			}
			$next = $body[ $i + 1 ];
			switch ( $next ) {
				case 'n':
					$result .= "\n";
					++$i;
					break;
				case 't':
					$result .= "\t";
					++$i;
					break;
				case 'r':
					$result .= "\r";
					++$i;
					break;
				case 'v':
					$result .= "\v";
					++$i;
					break;
				case 'f':
					$result .= "\f";
					++$i;
					break;
				case 'e':
					$result .= "\e";
					++$i;
					break;
				case '\\':
				case '"':
				case '$':
					$result .= $next;
					++$i;
					break;
				case 'x':
					if ( 1 === preg_match( '/^x([0-9A-Fa-f]{1,2})/', substr( $body, $i + 1 ), $m ) ) {
						$result .= chr( (int) hexdec( $m[1] ) );
						$i      += strlen( $m[0] );
					} else {
						$result .= $char;
					}
					break;
				case 'u':
					if ( 1 === preg_match( '/^u\{([0-9A-Fa-f]+)\}/', substr( $body, $i + 1 ), $m ) ) {
						$result .= self::codepoint_to_utf8( (int) hexdec( $m[1] ) );
						$i      += strlen( $m[0] );
					} else {
						$result .= $char;
					}
					break;
				default:
					if ( 1 === preg_match( '/^[0-7]{1,3}/', substr( $body, $i + 1 ), $m ) ) {
						$result .= chr( (int) octdec( $m[0] ) & 0xFF );
						$i      += strlen( $m[0] );
					} else {
						$result .= $char; // Unrecognised escape: PHP keeps the backslash literally.
					}
					break;
			}
		}
		return $result;
	}

	/**
	 * A Unicode code point as UTF-8 bytes, for `\u{...}` escapes.
	 *
	 * @param int $codepoint Unicode code point.
	 * @return string
	 */
	private static function codepoint_to_utf8( int $codepoint ): string {
		return (string) mb_convert_encoding( pack( 'N', $codepoint ), 'UTF-8', 'UTF-32BE' );
	}

	/**
	 * A constant heredoc/nowdoc's runtime value: the T_START_HEREDOC token at $index, through its
	 * matching T_END_HEREDOC.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Token stream.
	 * @param int                                            $index Index of the T_START_HEREDOC token.
	 * @return array{0:string,1:int}|null
	 */
	private static function decode_heredoc( array $tokens, int $index ): ?array {
		$is_nowdoc = false !== strpos( $tokens[ $index ][1], "'" );

		$body  = '';
		$total = count( $tokens );
		$i     = $index + 1;
		for ( ; $i < $total; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && T_END_HEREDOC === $token[0] ) {
				++$i;
				break;
			}
			if ( is_array( $token ) && T_ENCAPSED_AND_WHITESPACE === $token[0] ) {
				$body .= $token[1];
				continue;
			}
			// Any other token here (a variable, a curly-brace expression) is real interpolation.
			return null;
		}

		$decoded = $is_nowdoc ? $body : self::unescape_double_quoted( $body );
		return null === $decoded ? null : array( rtrim( $decoded, "\n" ), $i );
	}
}
