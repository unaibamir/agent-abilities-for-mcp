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
 * family. Every miss was the same shape - fixed at one call site, left at its siblings.
 *
 * THE DESIGN RULE
 *
 * A fingerprint may be imprecise, but it may never be ambiguous.
 *
 * Imprecision is visible: a reader sees a coarse identity and knows roughly what it covers.
 * Ambiguity is invisible, and invisible is what let three earlier versions of this mechanism
 * transfer a justification silently from one call to another. So where the identity of a call
 * cannot be determined precisely, it widens to something coarser rather than falling back to a
 * value two sites could share.
 *
 * WHAT IT SEES AND DISTINGUISHES
 *
 * 1. A bare call, `sanitize_text_field( $v )`.
 * 2. A fully-qualified call, `\sanitize_text_field( $v )`, in both 7.4 and 8.x tokenization - and,
 *    as the other half of the same distinction, NOT `Vendor\sanitize_text_field( $v )`, which is
 *    somebody else's function of the same short name. Both halves are asserted on both
 *    tokenizations, because getting one right and the other wrong is how this went red on the 7.4
 *    floor while every 8.x job stayed green.
 * 3. An aliased import, `use function sanitize_text_field as clean; clean( $v )`.
 * 4. A callable string consumed by a named call, `array_map( 'sanitize_text_field', $v )`,
 *    including through a receiver chain the walk can follow to its start: `$a->map( … )`,
 *    `Foo::map( … )`, `$this->svc->mapper->map( … )`, `$rows[0]->map( … )`, `(new A())->map( … )`.
 * 5. A callable string consumed by anything else - a variable function, an invoked callable array,
 *    or a receiver chain the walk cannot follow all the way back - identified by its enclosing
 *    statement instead.
 *
 * Point 5 is what makes this list stable rather than a running tally of shapes. Five review rounds
 * each found one more receiver form, so the walk no longer tries to enumerate them: it either
 * resolves a chain completely or hands the whole statement back. A HALF-resolved chain is never
 * returned, because that is precisely how two different receivers came to share one string.
 *
 * WHAT IT CANNOT SEE AT ALL
 *
 * 6. A sanitizer reached indirectly through a variable:
 *    `$fn = 'sanitize_text_field'; $fn( $value );`. Resolving that needs variable tracking, which
 *    is a different kind of tool. Named here as a boundary rather than left for a later review
 *    round to find.
 * 7. Any dynamically constructed name, `$prefix . '_text_field'`, for the same reason.
 *
 * OUT OF SCOPE BY DESIGN
 *
 * 8. WHERE a sanitized value is stored. This scan matches sanitizer NAMES, not destinations, so it
 *    cannot prove every stored write routes through the helper - only that every raw call to a
 *    name it knows has been looked at by a human. A stored write reaching the database by some
 *    other route entirely - a bespoke sanitizer, wp_kses on a field that needed the strip, a
 *    direct $wpdb->insert of unsanitized input - is invisible to it and always was.
 *
 * That last gap is deliberate rather than an oversight. Enumerating real storage sinks means modelling
 * every WordPress and WooCommerce setter that eventually writes, which is a project rather than a
 * test, and a half-built version of it would be worse than this: it would imply a completeness it
 * did not have. This scanner is a name-level guard plus a human allowlist, and the honest claim is
 * that it closes the "fixed at one call site, left at its siblings" family for the sanitizers it
 * knows, not that a fourth miss is impossible.
 *
 * The other thing it deliberately does NOT do is decide whether a call is wrong. Plenty of raw
 * sanitize_text_field() calls are correct and must stay raw, because they sanitize a query or
 * lookup parameter that is never stored - a WP_Query search term, a report date, a template id. A
 * check that flagged call presence would force those to be "fixed" and make the codebase worse. So
 * the scanner reports WHERE the raw calls are; the destination judgement lives in the allowlist.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

use RuntimeException;

/**
 * Finds every raw use of the WordPress plain-text sanitizers in shipped first-party PHP, and
 * reports each one with the function that encloses it and the text of what it sanitizes.
 *
 * Token-based rather than grep-based on purpose. These files talk about the sanitizers constantly
 * in docblocks and in the tool descriptions agents read; token_get_all() hands comments and string
 * literals back as their own token types, so a mention in prose can never be mistaken for a call,
 * and a real call can never hide behind unusual spacing.
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
	 * Cache for the shipped-file list, which costs a subprocess to compute.
	 *
	 * @var array<int,string>|null
	 */
	private static ?array $shipped_cache = null;

	/**
	 * Scan every shipped source file and return one record per raw sanitizer use.
	 *
	 * @return array<int,array{file:string,function:string,sanitizer:string,line:int,form:string,call:string}>
	 *         Sorted by file, then line.
	 */
	public static function scan(): array {
		$found = array();

		foreach ( self::source_files() as $file ) {
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
	 * Collapse scan() records into the shape the allowlist is written against.
	 *
	 * The key is `<relative file>::<enclosing function>::<sanitizer>`, and the value is the list of
	 * normalised call texts in that function. Two decisions are load-bearing here.
	 *
	 * Line numbers are NOT part of the key. Any edit above a call shifts them, so a line-keyed
	 * allowlist goes stale on unrelated work and gets disabled. A file plus a function name plus the
	 * sanitizer only moves when someone renames the function or moves the code, which is exactly
	 * when the justification deserves re-reading.
	 *
	 * The value is the call TEXT rather than a count, and that is the fix for a real hole Codex
	 * found (R3-3). A bare count is transferable: convert a justified query call to the helper and
	 * add an unsafe stored write to the same function in one edit, and the count is unchanged, so
	 * the old reason silently covers the new call. Recording what each call actually sanitizes means
	 * the new call does not match the justified one and the build fails.
	 *
	 * The cost is honest and bounded: editing a justified call - renaming its variable, changing the
	 * input key - changes its text and forces the allowlist entry to be updated. That is a real
	 * papercut, but it fires exactly when what is being sanitized has changed, which is when a
	 * second look is warranted. It does not fire on unrelated edits elsewhere in the file, which was
	 * the failure mode that ruled line numbers out.
	 *
	 * A callable-string entry looks different from a direct-call one, and that is intentional: its
	 * identity is the CONSUMING call, `array_map( 'sanitize_text_field', $req['grant_types'] )`,
	 * rather than the bare literal. The literal alone is the same in every use, so it could not
	 * distinguish two of them in one function (R4-3).
	 *
	 * @param array<int,array{file:string,function:string,sanitizer:string,line:int,form:string,call:string}> $records Scan output.
	 * @return array<string,array{calls:array<int,string>,lines:array<int,int>}> Keyed by the stable key.
	 */
	public static function group( array $records ): array {
		$grouped = array();

		foreach ( $records as $record ) {
			$key = self::key( $record );
			if ( ! isset( $grouped[ $key ] ) ) {
				$grouped[ $key ] = array(
					'calls' => array(),
					'lines' => array(),
				);
			}
			$grouped[ $key ]['calls'][] = $record['call'];
			$grouped[ $key ]['lines'][] = $record['line'];
		}

		foreach ( $grouped as $key => $entry ) {
			sort( $entry['calls'] );
			$grouped[ $key ]['calls'] = $entry['calls'];
		}

		ksort( $grouped );

		return $grouped;
	}

	/**
	 * The stable allowlist key for one record.
	 *
	 * @param array{file:string,function:string,sanitizer:string,line:int,form:string,call:string} $record Scan record.
	 * @return string
	 */
	public static function key( array $record ): string {
		return $record['file'] . '::' . $record['function'] . '::' . $record['sanitizer'];
	}

	/**
	 * Scan one file.
	 *
	 * @param string $file Absolute path.
	 * @return array<int,array{file:string,function:string,sanitizer:string,line:int,form:string,call:string}>
	 */
	public static function scan_file( string $file ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a plugin source file from disk for static analysis in a test, not a remote resource.
		return self::scan_source( (string) file_get_contents( $file ), self::relative_path( $file ) );
	}

	/**
	 * Scan a source string.
	 *
	 * Split out from scan_file() so the scanner's own behaviour can be proven against known input -
	 * a docblock mention, a qualified call, an aliased import, a method of the same name - without
	 * inventing a file on disk or relying on some real source file keeping its current wording.
	 *
	 * @param string $source   PHP source, including its opening tag.
	 * @param string $relative The label reported as `file` on each record.
	 * @return array<int,array{file:string,function:string,sanitizer:string,line:int,form:string,call:string}>
	 */
	public static function scan_source( string $source, string $relative ): array {
		$tokens = token_get_all( $source );
		$count  = count( $tokens );

		// `use function sanitize_text_field as clean;` renames a tracked sanitizer for the rest of
		// the file. Resolved first so the call loop below can match the local name.
		$aliases = self::collect_function_aliases( $tokens, $count );

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

			$sanitizer = self::called_sanitizer( $tokens, $i, $count, $aliases );
			if ( null !== $sanitizer ) {
				$found[] = array(
					'file'      => $relative,
					'function'  => $function,
					'sanitizer' => $sanitizer,
					'line'      => (int) $token[2],
					'form'      => 'call',
					'call'      => self::call_text( $tokens, $i, $count, $sanitizer ),
				);
				continue;
			}

			// The callable-string form: array_map( 'sanitize_text_field', … ). Same effect on the
			// same values, invisible to any check that only looks for a name followed by a paren.
			if ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$literal = trim( (string) $token[1], "'\"" );
				$literal = ltrim( $literal, '\\' );
				if ( in_array( $literal, self::SANITIZERS, true ) ) {
					$found[] = array(
						'file'      => $relative,
						'function'  => $function,
						'sanitizer' => $literal,
						'line'      => (int) $token[2],
						'form'      => 'callable-string',
						'call'      => self::enclosing_call_text( $tokens, $i, $count, $literal ),
					);
				}
			}
		}

		return $found;
	}

	/**
	 * The tracked sanitizer being CALLED at $index, or null.
	 *
	 * Covers the spellings that all resolve to the same global function:
	 *
	 * - `sanitize_text_field(...)`            - T_STRING, the ordinary case.
	 * - `\sanitize_text_field(...)`           - T_NAME_FULLY_QUALIFIED on PHP 8, which the old
	 *                                           T_STRING-only match missed entirely. This is the
	 *                                           bypass Codex found: a fully-qualified call is the
	 *                                           natural spelling inside a namespaced file and was
	 *                                           invisible to the scan.
	 * - `\sanitize_text_field(...)` on 7.4    - T_NS_SEPARATOR followed by T_STRING, which the
	 *                                           lookbehind already tolerated, so both tokenizations
	 *                                           are handled.
	 * - an aliased import                     - resolved via $aliases.
	 *
	 * A partially-qualified name (`Some\Ns\sanitize_text_field`) is a DIFFERENT function and is
	 * deliberately not matched. PHP 8 makes that free: the whole name arrives as one
	 * T_NAME_QUALIFIED token and the strpos check above rejects it. PHP 7.4 does not, because it
	 * splits the name into T_STRING, T_NS_SEPARATOR, T_STRING, so the tail arrives here looking
	 * exactly like a bare call and was reported as one. That is what is_namespace_qualified()
	 * exists for, and it is the difference between a leading separator with nothing in front of it
	 * (the global function, still matched) and a name in front of it (someone else's function,
	 * ignored).
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens  All tokens.
	 * @param int                                           $index   Current index.
	 * @param int                                           $count   Token count.
	 * @param array<string,string>                          $aliases Local name => tracked sanitizer.
	 * @return string|null The tracked sanitizer name, or null.
	 */
	private static function called_sanitizer( array $tokens, int $index, int $count, array $aliases ): ?string {
		$token = $tokens[ $index ];
		if ( ! is_array( $token ) ) {
			return null;
		}

		$name = null;

		if ( T_STRING === $token[0] ) {
			// On 7.4 this may be the tail of a qualified name rather than a bare one.
			if ( self::is_namespace_qualified( $tokens, $index ) ) {
				return null;
			}
			$name = (string) $token[1];
		} elseif ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $token[0] ) {
			// Leading backslash only: this is the global function, spelled explicitly.
			$name = ltrim( (string) $token[1], '\\' );
			if ( false !== strpos( $name, '\\' ) ) {
				return null;
			}
		}

		if ( null === $name ) {
			return null;
		}

		$resolved = null;
		if ( in_array( $name, self::SANITIZERS, true ) ) {
			$resolved = $name;
		} elseif ( isset( $aliases[ $name ] ) ) {
			$resolved = $aliases[ $name ];
		}

		if ( null === $resolved || ! self::is_function_call( $tokens, $index, $count ) ) {
			return null;
		}

		return $resolved;
	}

	/**
	 * Local aliases imported for a tracked sanitizer via `use function`.
	 *
	 * `use function sanitize_text_field as clean;` makes every later `clean( $v )` a raw sanitizer
	 * call under a name no grep would ever look for. The import is also recorded when the name is
	 * NOT renamed, which is harmless (the local name equals the tracked one) and keeps the parser
	 * simple.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $count  Token count.
	 * @return array<string,string> Local name => tracked sanitizer name.
	 */
	private static function collect_function_aliases( array $tokens, int $count ): array {
		$aliases = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_USE !== $token[0] ) {
				continue;
			}

			// Only `use function …`, never a class import or a closure's `use ( … )`.
			$next = self::next_significant( $tokens, $i + 1, $count );
			if ( null === $next || ! is_array( $tokens[ $next ] ) || T_FUNCTION !== $tokens[ $next ][0] ) {
				continue;
			}

			// Collect the statement's text up to the terminating semicolon, then read the
			// comma-separated `original as alias` clauses out of it.
			$statement = '';
			for ( $j = $next + 1; $j < $count; $j++ ) {
				if ( ';' === $tokens[ $j ] ) {
					break;
				}
				$statement .= is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
			}

			foreach ( explode( ',', $statement ) as $clause ) {
				$parts    = preg_split( '~\s+as\s+~i', trim( $clause ) );
				$original = ltrim( trim( (string) ( $parts[0] ?? '' ) ), '\\' );
				$local    = trim( (string) ( $parts[1] ?? $original ) );

				if ( '' === $local || ! in_array( $original, self::SANITIZERS, true ) ) {
					continue;
				}
				$aliases[ $local ] = $original;
			}
		}

		return $aliases;
	}

	/**
	 * The normalised text of a call's argument list, used as its fingerprint in the allowlist.
	 *
	 * Whitespace is collapsed so reindenting a block does not churn the allowlist. The text is NOT
	 * truncated: identity has to be the whole thing, or two long calls sharing a prefix would
	 * fingerprint identically and the transfer hole reopens one layer down. format() caps the
	 * rendering instead, so only terminal output is shortened, never the value being compared.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens    All tokens.
	 * @param int                                           $index     Index of the name token.
	 * @param int                                           $count     Token count.
	 * @param string                                        $sanitizer The resolved sanitizer name.
	 * @return string
	 */
	private static function call_text( array $tokens, int $index, int $count, string $sanitizer ): string {
		$args = self::balanced_args_text( $tokens, $index, $count );

		return null === $args ? $sanitizer . '(?)' : $sanitizer . $args;
	}

	/**
	 * The normalised text of the call that CONSUMES a callable string, e.g.
	 * `array_map( 'sanitize_text_field', $req['grant_types'] )`.
	 *
	 * R4-3. Recording only the literal made every callable-string use in a function identical, so
	 * the multiset could not tell two of them apart. aafm_oauth_register_client() already has two,
	 * over grant_types and response_types; converting one to the helper and adding an unsafe map
	 * over stored text left the allowlist byte-for-byte unchanged and every test green. Naming the
	 * consuming call and its arguments is what distinguishes them.
	 *
	 * The walk stops at the FIRST enclosing call, which is the one that actually receives the
	 * callable. In `array_values( array_map( 'sanitize_text_field', array_map( 'strval', $x ) ) )`
	 * that is the middle array_map, not the outer array_values and not the inner one - the case
	 * most likely to be got subtly wrong, so it has its own test.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens  All tokens.
	 * @param int                                           $index   Index of the string literal.
	 * @param int                                           $count   Token count.
	 * @param string                                        $literal The sanitizer name in the string.
	 * @return string
	 */
	private static function enclosing_call_text( array $tokens, int $index, int $count, string $literal ): string {
		$depth = 0;
		$open  = null;

		for ( $i = $index - 1; $i >= 0; $i-- ) {
			if ( ')' === $tokens[ $i ] ) {
				++$depth;
				continue;
			}
			if ( '(' === $tokens[ $i ] ) {
				if ( 0 === $depth ) {
					$open = $i;
					break;
				}
				--$depth;
			}
		}

		// The callee sits immediately before its opening parenthesis, but it may be the tail of a
		// member-access chain rather than a bare name. Taking only the last name token made
		// `$a->map( … )` and `$b->map( … )` identical (R5-3), so the whole chain is captured.
		$callee = null;
		if ( null !== $open ) {
			$last = self::prev_significant( $tokens, $open - 1 );
			if ( null !== $last && is_array( $tokens[ $last ] ) && self::is_name_token( $tokens[ $last ][0] ) ) {
				$callee = self::extend_over_namespace( $tokens, self::callee_start_index( $tokens, $last ) );
			}
		}

		if ( null !== $callee ) {
			// The chain is only trustworthy if it was consumed to its start. If a chain operator
			// still sits in front of it, the walk met something it does not understand and stopped
			// halfway, and a HALF chain is exactly the ambiguity this is meant to prevent - two
			// different receivers reducing to the same text. Fall through to the statement instead
			// of returning a partial answer that looks precise.
			$before = self::prev_significant( $tokens, $callee - 1 );
			$whole  = null === $before || ! is_array( $tokens[ $before ] ) || ! self::is_chain_operator( $tokens[ $before ][0] );

			// A start that is itself a subscript open is not a start. The walk steps over `[ … ]` to
			// whatever owns it, which for `$rows[0]->map( … )` is the variable `$rows` and resolves
			// fine. But when the owner is anonymous - `[new A()][0]`, `alpha()[0][1]`, a
			// parenthesized ternary - there is no name to land on and the walk stops holding only
			// the trailing subscript, so `[new A()][0]` and `[new B()][0]` both reduce to
			// `[0]->map( … )`. That is a HALF chain, and the same ambiguity R5-3 and R6-5 each fixed
			// for one receiver form and left at this one. Hand back the statement instead.
			if ( '[' === $tokens[ $callee ] ) {
				$whole = false;
			}

			$args = $whole ? self::balanced_args_text( $tokens, (int) $last, $count ) : null;
			if ( null !== $args ) {
				return self::text_between( $tokens, $callee, (int) $last ) . $args;
			}
		}

		// No named callee: a variable function, an invoked callable array, or something else this
		// scan cannot name. The old fallback was the literal alone, which is the SAME string at
		// every such site, so two of them silently matched - R4-3's collision through another door.
		// The enclosing statement is imprecise but never ambiguous, and imprecision is visible to a
		// reader where ambiguity is not.
		return "'" . $literal . "' in " . self::enclosing_statement_text( $tokens, $index );
	}

	/**
	 * The index where a callee's member-access chain begins.
	 *
	 * Walks back from the final name token through `->`, `?->` and `::` links, taking the variable,
	 * name or bracketed group that precedes each one. `$this->svc->map` starts at `$this`;
	 * `Foo::map` starts at `Foo`; a bare `array_map` starts at itself.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens     All tokens.
	 * @param int                                           $name_index Index of the final name token.
	 * @return int
	 */
	private static function callee_start_index( array $tokens, int $name_index ): int {
		$start = $name_index;

		while ( true ) {
			$operator = self::prev_significant( $tokens, $start - 1 );
			if ( null === $operator || ! is_array( $tokens[ $operator ] ) || ! self::is_chain_operator( $tokens[ $operator ][0] ) ) {
				return $start;
			}

			$element = self::prev_significant( $tokens, $operator - 1 );
			if ( null === $element ) {
				return $start;
			}

			// A subscript or a call in the chain: step over the balanced group to whatever owns it.
			if ( ']' === $tokens[ $element ] || ')' === $tokens[ $element ] ) {
				$owner = self::matching_open_owner( $tokens, $element );
				if ( null === $owner ) {
					return $start;
				}
				$element = $owner;
			} elseif ( ! is_array( $tokens[ $element ] )
				|| ! ( T_VARIABLE === $tokens[ $element ][0] || self::is_name_token( $tokens[ $element ][0] ) ) ) {
				return $start;
			}

			$start = $element;
		}
	}

	/**
	 * Step back over a balanced `[...]` or `(...)` group and return the index of the variable or
	 * name that owns it.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $close  Index of the closing bracket.
	 * @return int|null
	 */
	private static function matching_open_owner( array $tokens, int $close ): ?int {
		$closer = $tokens[ $close ];
		$opener = ']' === $closer ? '[' : '(';
		$depth  = 0;

		for ( $i = $close; $i >= 0; $i-- ) {
			if ( $closer === $tokens[ $i ] ) {
				++$depth;
				continue;
			}
			if ( $opener === $tokens[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$owner = self::prev_significant( $tokens, $i - 1 );
					if ( null !== $owner && is_array( $tokens[ $owner ] )
						&& ( T_VARIABLE === $tokens[ $owner ][0] || self::is_name_token( $tokens[ $owner ][0] ) ) ) {
						return $owner;
					}
					// Nothing owns the group, so the group itself is the chain element:
					// `(new A())->map( … )`. Returning null here dropped the receiver entirely
					// and let two different receivers share one fingerprint (R6-5).
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * The normalised text of the statement containing a token, bounded by the nearest `;` or `{`.
	 *
	 * The fallback identity when no callee can be named. Bounded by statement rather than by line
	 * so it stays stable when the code is reflowed, and it is deliberately allowed to be long: a
	 * verbose identity a human can read beats a short one two sites can share.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $index  Index inside the statement.
	 * @return string
	 */
	private static function enclosing_statement_text( array $tokens, int $index ): string {
		$start = 0;
		for ( $i = $index; $i >= 0; $i-- ) {
			if ( ';' === $tokens[ $i ] || '{' === $tokens[ $i ] || '}' === $tokens[ $i ] ) {
				$start = $i + 1;
				break;
			}
			if ( is_array( $tokens[ $i ] ) && T_OPEN_TAG === $tokens[ $i ][0] ) {
				$start = $i + 1;
				break;
			}
		}

		$count = count( $tokens );
		$end   = $count - 1;
		for ( $i = $index; $i < $count; $i++ ) {
			if ( ';' === $tokens[ $i ] || '{' === $tokens[ $i ] ) {
				$end = $i;
				break;
			}
		}

		return self::text_between( $tokens, $start, $end );
	}

	/**
	 * Whitespace-normalised source text spanning two token indexes, inclusive.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $from   First index.
	 * @param int                                           $to     Last index.
	 * @return string
	 */
	private static function text_between( array $tokens, int $from, int $to ): string {
		$text = '';
		for ( $i = $from; $i <= $to; $i++ ) {
			if ( ! isset( $tokens[ $i ] ) ) {
				break;
			}
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$text .= is_array( $token ) ? $token[1] : $token;
		}

		return (string) preg_replace( '~\s+~', ' ', trim( $text ) );
	}

	/**
	 * The index of the previous token that is not whitespace or a comment.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $from   Index to start from, walking back.
	 * @return int|null
	 */
	private static function prev_significant( array $tokens, int $from ): ?int {
		for ( $i = $from; $i >= 0; $i-- ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $i;
		}
		return null;
	}

	/**
	 * Whether a token id links two parts of a member-access chain.
	 *
	 * @param int $id Token id.
	 * @return bool
	 */
	private static function is_chain_operator( int $id ): bool {
		if ( T_OBJECT_OPERATOR === $id || T_DOUBLE_COLON === $id ) {
			return true;
		}
		return defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && constant( 'T_NULLSAFE_OBJECT_OPERATOR' ) === $id;
	}

	/**
	 * The whitespace-normalised text from a name token's opening parenthesis through its matching
	 * close, inclusive.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $index  Index of the name token.
	 * @param int                                           $count  Token count.
	 * @return string|null Null when the name is not followed by a call.
	 */
	private static function balanced_args_text( array $tokens, int $index, int $count ): ?string {
		$open = self::next_significant( $tokens, $index + 1, $count );
		if ( null === $open || '(' !== $tokens[ $open ] ) {
			return null;
		}

		$depth = 0;
		$text  = '';
		for ( $i = $open; $i < $count; $i++ ) {
			$piece = is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
			if ( '(' === $tokens[ $i ] ) {
				++$depth;
			} elseif ( ')' === $tokens[ $i ] ) {
				--$depth;
			}
			$text .= $piece;
			if ( 0 === $depth ) {
				break;
			}
		}

		return (string) preg_replace( '~\s+~', ' ', trim( $text ) );
	}

	/**
	 * Extend a start index back over a namespace qualifier written as separate 7.4 tokens.
	 *
	 * PHP 8 hands back `Vendor\Mapper` as ONE T_NAME_QUALIFIED token, so a chain walk that lands on
	 * it already holds the whole name. PHP 7.4 splits it into T_STRING, T_NS_SEPARATOR, T_STRING, so
	 * the same walk lands on `Mapper` alone, sees a separator rather than a chain operator in front
	 * of it, and stops. `\Vendor\Mapper::map( … )` and `\Other\Mapper::map( … )` then reduce to the
	 * same `Mapper::map( … )` and one call's allowlist reason silently covers the other.
	 *
	 * That is the ambiguity this whole class exists to prevent, and it was reachable only on the
	 * plugin's actual PHP floor: every 8.x job was green while both 7.4 jobs failed. The class
	 * docblock already claimed both tokenizations were handled, which was true of a bare qualified
	 * CALL and false of a qualified RECEIVER.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $start  Index of the name token landed on.
	 * @return int
	 */
	private static function extend_over_namespace( array $tokens, int $start ): int {
		while ( true ) {
			$separator = self::prev_significant( $tokens, $start - 1 );
			if ( null === $separator || ! is_array( $tokens[ $separator ] ) || T_NS_SEPARATOR !== $tokens[ $separator ][0] ) {
				return $start;
			}

			// The separator itself is part of the name: `\Vendor\Mapper` begins at the leading slash,
			// which is what PHP 8 reports as the single token's text.
			$start = $separator;

			$previous = self::prev_significant( $tokens, $start - 1 );
			if ( null === $previous || ! is_array( $tokens[ $previous ] ) || ! self::is_name_token( $tokens[ $previous ][0] ) ) {
				return $start;
			}

			$start = $previous;
		}
	}

	/**
	 * Whether the name token at $index carries a namespace qualifier in front of it, written as the
	 * separate tokens PHP 7.4 produces.
	 *
	 * The counterpart to extend_over_namespace(), and the same 7.4 split-token cause pointing the
	 * other way. That one lost a receiver's namespace and made two receivers collide; this one
	 * gained a call that was never ours. `Vendor\sanitize_text_field( $v )` is one
	 * T_NAME_QUALIFIED token on PHP 8, which the caller rejects on the backslash it contains. On
	 * 7.4 it is T_STRING, T_NS_SEPARATOR, T_STRING, so the tail is an ordinary T_STRING spelled
	 * `sanitize_text_field` sitting in front of a parenthesis - indistinguishable from a bare call
	 * without looking behind it. Every 8.x job was green and both 7.4 jobs failed, on the version
	 * the plugin actually supports.
	 *
	 * The distinction is what precedes the separator, and the two cases must not be confused:
	 *
	 * - nothing name-like     - `\sanitize_text_field( $v )`, the global function spelled
	 *                           explicitly. Still a match, and there is a test that fails if it
	 *                           stops being one.
	 * - a name or `namespace` - `Vendor\sanitize_text_field( $v )`, `\Vendor\Helpers\…`,
	 *                           `namespace\…`. A different function. Not a match.
	 *
	 * Read as "qualified only when something name-like precedes the separator" rather than as an
	 * allowlist of tokens that may precede a global call, deliberately: an unfamiliar token then
	 * errs toward reporting the call, and an over-report is visible in the allowlist where a
	 * missed call is a silent bypass.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $index  Index of the name token.
	 * @return bool
	 */
	private static function is_namespace_qualified( array $tokens, int $index ): bool {
		$separator = self::prev_significant( $tokens, $index - 1 );
		if ( null === $separator || ! is_array( $tokens[ $separator ] ) || T_NS_SEPARATOR !== $tokens[ $separator ][0] ) {
			return false;
		}

		$before = self::prev_significant( $tokens, $separator - 1 );
		if ( null === $before || ! is_array( $tokens[ $before ] ) ) {
			return false;
		}

		return self::is_name_token( $tokens[ $before ][0] ) || T_NAMESPACE === $tokens[ $before ][0];
	}

	/**
	 * Whether a token id is a function-name token in either PHP 7.4 or PHP 8 tokenization.
	 *
	 * @param int $id Token id.
	 * @return bool
	 */
	private static function is_name_token( int $id ): bool {
		if ( T_STRING === $id ) {
			return true;
		}
		foreach ( array( 'T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED' ) as $name ) {
			if ( defined( $name ) && constant( $name ) === $id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The index of the next token that is not whitespace or a comment.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $from   Index to start from.
	 * @param int                                           $count  Token count.
	 * @return int|null
	 */
	private static function next_significant( array $tokens, int $from, int $count ): ?int {
		for ( $i = $from; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $i;
		}
		return null;
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
	 * Whether the name token at $index is being CALLED, rather than declared or reached through an
	 * object or a class.
	 *
	 * A method named sanitize_text_field on some object is a different function entirely, and a
	 * declaration of our own would be a shadow, not a use. Neither should be reported as a raw
	 * sanitizer call.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens All tokens.
	 * @param int                                           $index  Index of the name token.
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

		$next = self::next_significant( $tokens, $index + 1, $count );

		return null !== $next && '(' === $tokens[ $next ];
	}

	/**
	 * Every shipped first-party PHP file, sorted for a stable report.
	 *
	 * Derived from `git archive`, which is literally what the release zip is built from, rather
	 * than from a list somebody maintains by hand. That is the whole point: a hand-picked list has
	 * its hole exactly where the next file lands, and "it has no sanitizer calls today" is the
	 * argument that was made about update-user and about the order addresses, both of which later
	 * needed fixing. uninstall.php was the file that proved it - it ships, it was outside the scan,
	 * and nobody noticed until Codex read the source list (R3-3).
	 *
	 * Two boundaries, both deliberate and both stated so they are not mistaken for oversights:
	 *
	 * - vendor/ is excluded. It ships, but it is third-party code this project does not author and
	 *   cannot fix; allowlisting hundreds of vendor calls would bury the entries that matter.
	 * - The list comes from HEAD, so a PHP file added but not yet committed is not scanned until it
	 *   is committed. It cannot ship before then either, so the guard still runs before release.
	 *
	 * @throws RuntimeException When the shipped list cannot be determined, which must fail loudly
	 *                          rather than silently scanning nothing.
	 * @return array<int,string> Absolute paths.
	 */
	public static function source_files(): array {
		if ( null !== self::$shipped_cache ) {
			return self::$shipped_cache;
		}

		$root = self::plugin_root();

		$output = array();
		$status = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- asking git what the release archive contains is the only authoritative answer, and this is a test-only static analysis helper.
		exec( 'cd ' . escapeshellarg( rtrim( $root, '/' ) ) . ' && git archive HEAD 2>/dev/null | tar -t 2>/dev/null', $output, $status );

		if ( 0 !== $status || array() === $output ) {
			throw new RuntimeException(
				'Could not determine the shipped file list from "git archive HEAD". The sanitizer scan '
				. 'covers whatever actually ships, so it must not fall back to a guess.'
			);
		}

		$files = array();
		foreach ( $output as $path ) {
			$path = trim( (string) $path );
			if ( '' === $path || 'php' !== strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				continue;
			}
			if ( 0 === strpos( $path, 'vendor/' ) ) {
				continue;
			}
			$absolute = $root . $path;
			if ( is_file( $absolute ) ) {
				$files[] = $absolute;
			}
		}

		sort( $files );
		self::$shipped_cache = $files;

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
	 * @param array<int,array{file:string,function:string,sanitizer:string,line:int,form:string,call:string}> $records Records to format.
	 * @return string
	 */
	public static function format( array $records ): string {
		$lines = array();
		foreach ( $records as $record ) {
			$call = $record['call'];
			// Rendering only. The stored identity stays whole, because truncating what is COMPARED
			// would let two long calls sharing a prefix collide.
			if ( strlen( $call ) > 120 ) {
				$call = substr( $call, 0, 117 ) . '...';
			}
			$lines[] = sprintf(
				'  %s:%d  %s()  %s',
				$record['file'],
				$record['line'],
				$record['function'],
				$call
			);
		}
		return implode( "\n", $lines );
	}
}
