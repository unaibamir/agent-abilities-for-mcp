<?php
/**
 * Shared `use`-import parser for the plugin's source-scanning tests.
 *
 * 1.7.5 deferred, test-infrastructure closure round: three tests each grew their own copy of this
 * parser (SecurityRegressionTest, SecurityOptionWritesSweepTest, StoredTextSanitizerScanner), and
 * each copy was "fixed" for the one syntax case a reviewer happened to quote, then reopened when
 * the next round tried a different one - group prefixes, then whitespace, then comments, three
 * times over. All three actually need the same thing: given a token stream, tell them which
 * imported alias resolves to which real class/function/const name.
 *
 * The previous copies worked by re-assembling each import entry into a source-text fragment and
 * matching it with a regex (`\s+as\s+`, `\w+`). That is what kept breaking: a regex anchored on
 * ASCII word characters cannot see a non-ASCII alias, and re-assembling text at all means a stray
 * comment token, once appended into the fragment instead of discarded, permanently corrupts it.
 *
 * This parser never builds a text fragment. It walks tokens directly and keys off `T_AS` - the
 * same token PHP's own lexer uses for `use ... as ...` - so an alias is "whatever identifier
 * token follows T_AS", never "whatever a regex could find in reassembled text". A comment or
 * stray whitespace token in between is simply not a name/T_AS token, so it is skipped without
 * needing special-casing at every position one could appear.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

/**
 * Parses `use` import declarations (class/function/const, single or grouped) out of a PHP token
 * stream, distinguishing them from trait-use-in-a-class-body and a closure's `use (&$x)` capture,
 * neither of which is a namespace import.
 */
final class UseImportScanner {

	/**
	 * Parse every namespace-import `use` declaration in $tokens.
	 *
	 * Deliberately does NOT parse trait-use statements (`use SomeTrait;`/`use A, B { ... };` inside
	 * a class/interface/trait/enum body) or a closure's `use (&$x)` capture list - neither one
	 * imports a name into the file's namespace, so treating either as an import would resolve
	 * calls against the wrong thing. Aliases also never cross a `namespace` boundary: PHP scopes
	 * `use` imports to the namespace block they appear in, so this resets on every `T_NAMESPACE`
	 * token.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output, raw or
	 *                                                                already run through
	 *                                                                collapse_qualified_names().
	 * @return array{class:array<string,string>,function:array<string,string>,const:array<string,string>}
	 *         Each map is lower-cased local alias => resolved name, with any leading `\` stripped
	 *         but otherwise fully qualified (e.g. `WpOrg\Requests\Requests`, not just `Requests`).
	 *         Callers that want the old imprecise "same bare trailing name, regardless of
	 *         namespace" matching should reduce the result with reduce_to_trailing().
	 */
	public static function parse_aliases( array $tokens ): array {
		$aliases = array(
			'class'    => array(),
			'function' => array(),
			'const'    => array(),
		);

		$total         = count( $tokens );
		$class_body    = array();
		$pending_class = false;

		for ( $i = 0; $i < $total; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) ) {
				if ( '{' === $token ) {
					$class_body[] = $pending_class;
					$pending_class = false;
				} elseif ( '}' === $token ) {
					array_pop( $class_body );
				} elseif ( ';' === $token ) {
					$pending_class = false;
				}
				continue;
			}

			$id = $token[0];

			if ( T_NAMESPACE === $id ) {
				// `namespace Foo;` / `namespace Foo { ... }` / bare `namespace;`: a fresh scope.
				// Imports declared before this point are not visible after it.
				$aliases = array(
					'class'    => array(),
					'function' => array(),
					'const'    => array(),
				);
				continue;
			}

			if ( self::is_class_like_keyword( $id ) ) {
				$before = self::previous_significant( $tokens, $i );
				// `Foo::class` tokenizes T_CLASS the same as a real declaration; only a class
				// preceded by anything other than `::` is a declaration that opens a body.
				if ( ! ( is_array( $before ) && T_DOUBLE_COLON === $before[0] ) ) {
					$pending_class = true;
				}
				continue;
			}

			if ( T_USE !== $id ) {
				continue;
			}

			$peek = self::skip_trivia( $tokens, $i + 1, $total );
			if ( $peek < $total && '(' === $tokens[ $peek ] ) {
				continue; // A closure's `use (&$x)` capture list, not an import.
			}

			if ( array() !== $class_body && true === end( $class_body ) ) {
				continue; // A trait-use statement inside a class-like body, not an import.
			}

			$i = self::parse_use_statement( $tokens, $i, $total, $aliases ) - 1;
		}

		return $aliases;
	}

	/**
	 * Reduce a parse_aliases() map to the old "bare trailing name segment" form that
	 * SecurityRegressionTest and SecurityOptionWritesSweepTest match call sites against: an
	 * intentionally imprecise match (any qualifier, same final segment) that would rather
	 * over-report a same-named call than silently miss a real one under a different namespace.
	 *
	 * @param array<string,string> $map Lower-cased alias => fully qualified name.
	 * @return array<string,string> Lower-cased alias => bare trailing name segment.
	 */
	public static function reduce_to_trailing( array $map ): array {
		$reduced = array();
		foreach ( $map as $alias => $full ) {
			$reduced[ $alias ] = self::trailing_name_segment( $full );
		}
		return $reduced;
	}

	/**
	 * The bare, unqualified segment of a (possibly qualified) name - `Requests` from
	 * `WpOrg\Requests\Requests`, or the name unchanged when it was never qualified.
	 *
	 * @param string $text A name, with or without a leading `\`.
	 * @return string
	 */
	public static function trailing_name_segment( string $text ): string {
		$pos = strrpos( $text, '\\' );
		return false === $pos ? $text : substr( $text, $pos + 1 );
	}

	/**
	 * Whether a name (as collapse_qualified_names() would hand back) was written fully qualified,
	 * i.e. with a leading `\`. A fully qualified call bypasses every `use` import - PHP resolves it
	 * to the literal global name regardless of any local alias sharing that bare name - so a caller
	 * matching against an alias map must skip the lookup entirely when this is true, rather than
	 * let an unrelated `use X as <this bare name>;` re-target it.
	 *
	 * @param string $text Token text.
	 * @return bool
	 */
	public static function is_fully_qualified( string $text ): bool {
		return '' !== $text && '\\' === $text[0];
	}

	/**
	 * Codex round 7, R7-6: on PHP 8, `\wp_safe_remote_get` tokenizes as ONE T_NAME_FULLY_QUALIFIED
	 * token (never a bare T_STRING), and `Foo\Bar` as ONE T_NAME_QUALIFIED token; on PHP 7.4 the
	 * same source is a T_NS_SEPARATOR/T_STRING run instead. Collapse every such run - on either PHP
	 * version - into a single T_STRING-shaped token carrying the full qualified text (leading `\`
	 * included, when present), so every caller can match by name regardless of how the call was
	 * qualified or which PHP version tokenized the file.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @return array<int,array{0:int,1:string,2:int}|string>
	 */
	public static function collapse_qualified_names( array $tokens ): array {
		$name_ids = self::name_token_ids();

		$collapsed = array();
		$total     = count( $tokens );
		$i         = 0;
		while ( $i < $total ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || ! in_array( $token[0], $name_ids, true ) ) {
				$collapsed[] = $token;
				++$i;
				continue;
			}
			$text = '';
			$line = $token[2];
			while ( $i < $total && is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], $name_ids, true ) ) {
				$text .= $tokens[ $i ][1];
				++$i;
			}
			$collapsed[] = array( T_STRING, $text, $line );
		}
		return $collapsed;
	}

	/**
	 * Parse one `use` statement starting at $tokens[$use_index] (the T_USE token itself), recording
	 * every alias it declares into $aliases, and returning the index to resume scanning from - the
	 * statement's terminating `;` (or, on malformed input, wherever parsing gave up).
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Full token stream.
	 * @param int                                            $use_index Index of the T_USE token.
	 * @param int                                            $total Token count.
	 * @param array{class:array<string,string>,function:array<string,string>,const:array<string,string>} $aliases Mutated in place.
	 * @return int
	 */
	private static function parse_use_statement( array $tokens, int $use_index, int $total, array &$aliases ): int {
		$pos = self::skip_trivia( $tokens, $use_index + 1, $total );

		$statement_kind = 'class';
		if ( $pos < $total && is_array( $tokens[ $pos ] ) && T_FUNCTION === $tokens[ $pos ][0] ) {
			$statement_kind = 'function';
			$pos            = self::skip_trivia( $tokens, $pos + 1, $total );
		} elseif ( $pos < $total && is_array( $tokens[ $pos ] ) && T_CONST === $tokens[ $pos ][0] ) {
			$statement_kind = 'const';
			$pos            = self::skip_trivia( $tokens, $pos + 1, $total );
		}

		do {
			$pos  = self::parse_import_member( $tokens, $pos, $total, $statement_kind, $aliases );
			$pos  = self::skip_trivia( $tokens, $pos, $total );
			$more = $pos < $total && ',' === $tokens[ $pos ];
			if ( $more ) {
				$pos  = self::skip_trivia( $tokens, $pos + 1, $total );
				$more = $pos < $total && ';' !== $tokens[ $pos ];
			}
		} while ( $more );

		return $pos;
	}

	/**
	 * Parse one comma-separated member of a `use` statement: either a plain (optionally aliased)
	 * qualified name, or a qualified prefix followed by a `{ ... }` group whose own members may
	 * each override the statement's import kind with a leading `function`/`const`.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Full token stream.
	 * @param int                                            $pos Index to start parsing from.
	 * @param int                                            $total Token count.
	 * @param string                                         $kind Statement-level import kind ('class'|'function'|'const').
	 * @param array{class:array<string,string>,function:array<string,string>,const:array<string,string>} $aliases Mutated in place.
	 * @return int Index just past this member.
	 */
	private static function parse_import_member( array $tokens, int $pos, int $total, string $kind, array &$aliases ): int {
		$name = '';
		$pos  = self::consume_name( $tokens, $pos, $total, $name );
		$pos  = self::skip_trivia( $tokens, $pos, $total );

		if ( $pos < $total && '{' === $tokens[ $pos ] ) {
			return self::parse_import_group( $tokens, $pos, $total, $kind, $name, $aliases );
		}

		$alias = self::parse_optional_alias( $tokens, $pos, $total, $pos );
		self::record( $aliases, $kind, $name, $alias );

		return $pos;
	}

	/**
	 * Parse a `{ ... }` group of import members sharing $prefix, starting at the `{` itself.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Full token stream.
	 * @param int                                            $pos Index of the opening `{`.
	 * @param int                                            $total Token count.
	 * @param string                                         $kind Statement-level import kind, the default for a member with no override.
	 * @param string                                         $prefix The qualified name preceding the group - B6 (1.7.5 deferred round 1): every
	 *                                                                member, not only the first, is resolved against this same prefix.
	 * @param array{class:array<string,string>,function:array<string,string>,const:array<string,string>} $aliases Mutated in place.
	 * @return int Index just past the closing `}`.
	 */
	private static function parse_import_group( array $tokens, int $pos, int $total, string $kind, string $prefix, array &$aliases ): int {
		$pos = self::skip_trivia( $tokens, $pos + 1, $total );

		while ( $pos < $total && '}' !== $tokens[ $pos ] ) {
			$member_kind = $kind;
			if ( is_array( $tokens[ $pos ] ) && T_FUNCTION === $tokens[ $pos ][0] ) {
				$member_kind = 'function';
				$pos         = self::skip_trivia( $tokens, $pos + 1, $total );
			} elseif ( is_array( $tokens[ $pos ] ) && T_CONST === $tokens[ $pos ][0] ) {
				$member_kind = 'const';
				$pos         = self::skip_trivia( $tokens, $pos + 1, $total );
			}

			$member = '';
			$pos    = self::consume_name( $tokens, $pos, $total, $member );
			$pos    = self::skip_trivia( $tokens, $pos, $total );

			$alias = self::parse_optional_alias( $tokens, $pos, $total, $pos );
			self::record( $aliases, $member_kind, $prefix . $member, $alias );

			$pos = self::skip_trivia( $tokens, $pos, $total );
			if ( $pos < $total && ',' === $tokens[ $pos ] ) {
				$pos = self::skip_trivia( $tokens, $pos + 1, $total ); // Trailing comma before `}` is fine: the loop condition re-checks for it.
			} else {
				break;
			}
		}

		$pos = self::skip_trivia( $tokens, $pos, $total );
		if ( $pos < $total && '}' === $tokens[ $pos ] ) {
			++$pos;
		}
		return $pos;
	}

	/**
	 * `as <alias>`, if present at $pos. Keys off T_AS - the same token PHP's lexer uses here -
	 * rather than a regex over reassembled text, so a comment or non-ASCII alias identifier never
	 * has to be special-cased: whatever is not T_AS or a name token is simply not part of this.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Full token stream.
	 * @param int                                            $pos Index to start from.
	 * @param int                                            $total Token count.
	 * @param int                                            $out_pos Set to the index just past the alias (or unchanged if there is none).
	 * @return string|null
	 */
	private static function parse_optional_alias( array $tokens, int $pos, int $total, int &$out_pos ): ?string {
		$out_pos = $pos;
		if ( ! ( $pos < $total && is_array( $tokens[ $pos ] ) && T_AS === $tokens[ $pos ][0] ) ) {
			return null;
		}
		$pos = self::skip_trivia( $tokens, $pos + 1, $total );
		if ( ! ( $pos < $total && is_array( $tokens[ $pos ] ) && T_STRING === $tokens[ $pos ][0] ) ) {
			return null; // Malformed; leave $out_pos at the T_AS so the caller does not lose sync.
		}
		$out_pos = $pos + 1;
		return $tokens[ $pos ][1];
	}

	/**
	 * Record one parsed import. The local name is the explicit alias when there is one, otherwise
	 * the imported name's own trailing segment - `use Foo\Bar;` makes `Bar` usable unqualified.
	 *
	 * Codex round 8, R8-5: keyed lower-case, since PHP resolves class/function/const names and
	 * `use` aliases case-insensitively (class constants and property/method names are the only
	 * case-sensitive part of a call).
	 *
	 * @param array{class:array<string,string>,function:array<string,string>,const:array<string,string>} $aliases Mutated in place.
	 * @param string $kind 'class'|'function'|'const'.
	 * @param string $name Imported name, possibly qualified, possibly with a leading `\`.
	 * @param string|null $alias Explicit alias, or null.
	 */
	private static function record( array &$aliases, string $kind, string $name, ?string $alias ): void {
		$full = ltrim( $name, '\\' );
		if ( '' === $full ) {
			return;
		}
		$local = $alias ?? self::trailing_name_segment( $full );
		if ( '' === $local ) {
			return;
		}
		$aliases[ $kind ][ strtolower( $local ) ] = $full;
	}

	/**
	 * Concatenate consecutive name-token text starting at $pos: T_STRING/T_NS_SEPARATOR runs (PHP
	 * 7.4) or a single combined T_NAME_QUALIFIED/T_NAME_FULLY_QUALIFIED/T_NAME_RELATIVE token
	 * (PHP 8), either of which this treats as an opaque "one name" unit - the caller never needs to
	 * know which tokenization produced it.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Full token stream.
	 * @param int                                            $pos Index to start from.
	 * @param int                                            $total Token count.
	 * @param string                                         $name Set to the concatenated name text.
	 * @return int Index just past the name.
	 */
	private static function consume_name( array $tokens, int $pos, int $total, string &$name ): int {
		$name = '';
		while ( $pos < $total && is_array( $tokens[ $pos ] ) && self::is_name_token( $tokens[ $pos ][0] ) ) {
			$name .= $tokens[ $pos ][1];
			++$pos;
		}
		return $pos;
	}

	/**
	 * Index of the previous significant (non-whitespace, non-comment) token before $index, or null.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Full token stream.
	 * @param int                                            $index Index to look before.
	 * @return array{0:int,1:string,2:int}|string|null
	 */
	private static function previous_significant( array $tokens, int $index ) {
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $token;
		}
		return null;
	}

	/**
	 * Index of the next non-trivia (non-whitespace, non-comment) token at or after $pos.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens Full token stream.
	 * @param int                                            $pos Index to start from.
	 * @param int                                            $total Token count.
	 * @return int
	 */
	private static function skip_trivia( array $tokens, int $pos, int $total ): int {
		while ( $pos < $total && is_array( $tokens[ $pos ] ) && in_array( $tokens[ $pos ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			++$pos;
		}
		return $pos;
	}

	/**
	 * Whether $id is one of T_CLASS/T_INTERFACE/T_TRAIT/T_ENUM - the keywords that open a
	 * class-like body whose own `use` statements are trait-use, not namespace imports.
	 *
	 * @param int $id Token id.
	 * @return bool
	 */
	private static function is_class_like_keyword( int $id ): bool {
		if ( T_CLASS === $id || T_INTERFACE === $id || T_TRAIT === $id ) {
			return true;
		}
		return defined( 'T_ENUM' ) && constant( 'T_ENUM' ) === $id;
	}

	/**
	 * Whether $id is a name-token type in either PHP 7.4 or PHP 8 tokenization, including the
	 * `namespace` keyword itself (the head of a PHP 7.4-tokenized relative name, `namespace\Foo`).
	 *
	 * @param int $id Token id.
	 * @return bool
	 */
	private static function is_name_token( int $id ): bool {
		static $ids = null;
		if ( null === $ids ) {
			$ids = array_merge( array( T_STRING, T_NS_SEPARATOR, T_NAMESPACE ), self::name_token_ids() );
		}
		return in_array( $id, $ids, true );
	}

	/**
	 * The PHP 8 combined name-token ids (T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
	 * T_NAME_RELATIVE), or an empty list on PHP 7.4, where they are not defined.
	 *
	 * @return array<int,int>
	 */
	private static function name_token_ids(): array {
		$ids = array( T_STRING, T_NS_SEPARATOR );
		foreach ( array( 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE' ) as $const ) {
			if ( defined( $const ) ) {
				$ids[] = constant( $const );
			}
		}
		return $ids;
	}
}
