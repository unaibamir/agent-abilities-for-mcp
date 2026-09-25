<?php
/**
 * Sweep: no banned meta, option, post-field or vendor write primitive survives anywhere under
 * includes/, agent-abilities-for-mcp.php or uninstall.php, except inside includes/write-contract.php
 * itself. Built on StaleWpdbReaderSweepTest's tokenizer pattern (tests/StaleWpdbReaderSweepTest.php),
 * so there is one way to walk PHP source for a banned call shape, not two.
 *
 * The ban list: every core metadata primitive (update/add/delete/get, including the *_by_mid and
 * alternate-spelling forms), a raw $wpdb write naming a meta table (as a method call or as SQL text
 * assembled into a string), ACF's update_field(), the vendor "save the object" methods WooCommerce,
 * TEC, AIOSEO and GeoDirectory each expose, and the untouched post-field confirmer called directly
 * instead of through its logged wrapper. Production call sites migrate one family at a time; the
 * legacy list in tests/Fixtures/meta-sweep-legacy.txt is the per-site checklist for that migration
 * and shrinks as each family moves.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class MetaWriteSweepTest extends TestCase {

	/**
	 * Bare function names banned everywhere in the scanned set.
	 *
	 * @var string[]
	 */
	private const BANNED_FUNCTIONS = array(
		'update_post_meta',
		'update_term_meta',
		'update_user_meta',
		'update_comment_meta',
		'update_metadata',
		'add_post_meta',
		'add_term_meta',
		'add_user_meta',
		'add_comment_meta',
		'add_metadata',
		'delete_post_meta',
		'delete_term_meta',
		'delete_user_meta',
		'delete_comment_meta',
		'delete_metadata',
		'get_post_meta',
		'get_term_meta',
		'get_user_meta',
		'get_comment_meta',
		'get_metadata',
		'get_metadata_raw',
		'get_metadata_by_mid',
		'update_metadata_by_mid',
		'delete_metadata_by_mid',
		'metadata_exists',
		'delete_post_meta_by_key',
		'update_user_option',
		'delete_user_option',
		'get_user_option',
		'get_post_custom',
		'get_post_custom_keys',
		'get_post_custom_values',
		'update_field',
		'aafm_post_field_write_confirmed',
		'geodir_save_post_meta',
		'wc_delete_order_item',
		'wc_create_refund',
		'wc_create_attribute',
		'wc_update_attribute',
		'wc_create_new_customer',
	);

	/**
	 * The update_option( function is banned as a bare call, and as a string literal, only inside
	 * includes/abilities/ (the site-settings ability); elsewhere (the option-cache helpers,
	 * server.php) it is this plugin's own sanctioned write path and stays unbanned.
	 */
	private const PATH_SCOPED_FUNCTION = 'update_option';
	private const PATH_SCOPED_PREFIX   = 'includes/abilities/';

	/**
	 * Method names banned after -> or ?->. 'delete' is exempt when its
	 * receiver is $wpdb, matching this plugin's own oauth/clients.php table cleanup.
	 *
	 * @var string[]
	 */
	private const BANNED_METHODS = array( 'save', 'create', 'add_product', 'update_status', 'add_order_note', 'add_shipping_method', 'calculate_totals', 'update_option', 'delete' );

	/**
	 * Static method names banned after :: (case-sensitive: these are the vendors' own names).
	 *
	 * @var string[]
	 */
	private const BANNED_STATIC = array( 'savePost', '_insert_tax_rate', '_update_tax_rate', 'create_tax_class' );

	/**
	 * Meta and WooCommerce table name fragments a raw $wpdb mutating call or a mutating SQL string
	 * must never mention.
	 *
	 * @var string[]
	 */
	private const BANNED_TABLE_FRAGMENTS = array( 'postmeta', 'termmeta', 'usermeta', 'commentmeta', 'woocommerce_' );

	private const WPDB_MUTATORS = array( 'insert', 'update', 'delete', 'replace' );

	/**
	 * The one file the sweep exempts entirely: the write-and-confirm contract itself.
	 */
	private const EXEMPT_PATH = 'includes/write-contract.php';

	/**
	 * Next non-trivia token at or after $index, paired with its index. Mirrors
	 * StaleWpdbReaderSweepTest::significant_token().
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param int                                           $index  Index to start looking from.
	 * @return array{0:array{0:int,1:string,2:int}|string|null,1:int}
	 */
	private function significant_token( array $tokens, int $index ): array {
		$total = count( $tokens );
		while ( $index < $total ) {
			$token = $tokens[ $index ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				++$index;
				continue;
			}
			return array( $token, $index );
		}
		return array( null, $index );
	}

	/**
	 * Mirror of significant_token() walking backward. Mirrors
	 * StaleWpdbReaderSweepTest::previous_significant_index().
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param int                                           $index  Index to start looking from.
	 */
	private function previous_significant_index( array $tokens, int $index ): ?int {
		while ( $index >= 0 ) {
			$token = $tokens[ $index ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				--$index;
				continue;
			}
			return $index;
		}
		return null;
	}

	/**
	 * Index of the closing bracket matching the opener at $open_index, tracking nesting depth.
	 * Brackets come only from punctuation tokens, which token_get_all() returns as plain strings,
	 * never from string content: the literal `}` at the end of `"{$m}}"` is part of a string token
	 * and closes nothing. For braces, an interpolation opener inside a string, `{$` or `${`, opens a
	 * level like `{` does, because its closer is a plain `}`.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens     token_get_all() output.
	 * @param int                                           $open_index Index of the opening bracket.
	 * @param string                                        $open_char  '(' or '{'.
	 * @param string                                        $close_char ')' or '}'.
	 */
	private function matching_bracket_index( array $tokens, int $open_index, string $open_char, string $close_char ): ?int {
		$depth = 0;
		$total = count( $tokens );
		for ( $j = $open_index; $j < $total; $j++ ) {
			$token = $tokens[ $j ];
			if ( $open_char === $token || ( '{' === $open_char && is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
			} elseif ( $close_char === $token ) {
				--$depth;
				if ( 0 === $depth ) {
					return $j;
				}
			}
		}
		return null;
	}

	/**
	 * The raw text between a call's opening '(' (exclusive) and its matching ')' (exclusive), by
	 * concatenating token text. Used only to test whether a call's arguments mention a banned table
	 * name; never parsed as PHP.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens          token_get_all() output.
	 * @param int                                           $open_paren_idx Index of the opening '('.
	 */
	private function call_args_text( array $tokens, int $open_paren_idx ): string {
		$close = $this->matching_bracket_index( $tokens, $open_paren_idx, '(', ')' );
		if ( null === $close ) {
			return '';
		}
		$text = '';
		for ( $k = $open_paren_idx + 1; $k < $close; $k++ ) {
			$token = $tokens[ $k ];
			$text .= is_array( $token ) ? $token[1] : $token;
		}
		return $text;
	}

	/**
	 * The receiver variable name immediately before an object-operator token, or null.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens        token_get_all() output.
	 * @param int                                           $operator_idx Index of the -> / ?-> token.
	 */
	private function receiver_before_operator( array $tokens, int $operator_idx ): ?string {
		$prev_idx = $this->previous_significant_index( $tokens, $operator_idx - 1 );
		if ( null === $prev_idx ) {
			return null;
		}
		$prev = $tokens[ $prev_idx ];
		return ( is_array( $prev ) && T_VARIABLE === $prev[0] ) ? $prev[1] : null;
	}

	/**
	 * A quoted literal's raw text without PHP's optional binary prefix: `b'x'` and `B"x"` are the
	 * same literals as `'x'` and `"x"`.
	 *
	 * @param string $raw The raw token text, quotes included.
	 */
	private function strip_binary_prefix( string $raw ): string {
		return ( '' !== $raw && ( 'b' === $raw[0] || 'B' === $raw[0] ) ) ? substr( $raw, 1 ) : $raw;
	}

	/**
	 * Decode a T_CONSTANT_ENCAPSED_STRING token's literal value the way PHP decodes it, binary prefix
	 * included, so a name spelled with an escape such as `\x70` reads as the name it spells.
	 *
	 * @param string $raw The raw token text, quotes included.
	 */
	private function decode_string_literal( string $raw ): string {
		$context = '"' === $this->strip_binary_prefix( $raw )[0] ? 'double' : 'single';
		return $this->decode_sql_literal( $raw, $context );
	}

	/**
	 * A string literal, or one literal part of an interpolated string, heredoc or nowdoc, decoded
	 * the way PHP decodes it, for the SQL-string join.
	 *
	 * A double-quoted literal, a heredoc and each literal part of an interpolated string decode
	 * \n, \t, \r, \v, \e, \f, \\, \$, octal \[0-7]{1,3}, hex \x[0-9A-Fa-f]{1,2} and \u{...},
	 * plus \" in a double-quoted string only; any other backslash stays as written. A single-quoted
	 * literal decodes only \\ and \', and a nowdoc decodes nothing.
	 *
	 * @param string $text    The text: a whole quoted literal for 'single' and 'double', binary
	 *                        prefix included, otherwise the literal part's raw token text.
	 * @param string $context 'single', 'double' (a quoted literal), 'interpolated' (a part of a
	 *                        double-quoted interpolated string), 'heredoc' or 'nowdoc'.
	 */
	private function decode_sql_literal( string $text, string $context ): string {
		if ( 'single' === $context ) {
			return (string) preg_replace( "/\\\\([\\\\'])/", '$1', substr( $this->strip_binary_prefix( $text ), 1, -1 ) );
		}
		if ( 'nowdoc' === $context ) {
			return $text;
		}
		$in_double_quotes = in_array( $context, array( 'double', 'interpolated' ), true );
		if ( 'double' === $context ) {
			$text = substr( $this->strip_binary_prefix( $text ), 1, -1 );
		}
		$simple = array(
			'n'  => "\n",
			't'  => "\t",
			'r'  => "\r",
			'v'  => "\v",
			'e'  => "\e",
			'f'  => "\f",
			'\\' => '\\',
			'$'  => '$',
		);
		return (string) preg_replace_callback(
			'/\\\\(?:([ntrvef\\\\$"])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
			static function ( array $m ) use ( $simple, $in_double_quotes ): string {
				if ( isset( $m[4] ) && '' !== $m[4] ) {
					return mb_chr( (int) hexdec( $m[4] ), 'UTF-8' );
				}
				if ( isset( $m[3] ) && '' !== $m[3] ) {
					return chr( (int) hexdec( $m[3] ) );
				}
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					return chr( octdec( $m[2] ) % 256 );
				}
				if ( '"' === $m[1] ) {
					return $in_double_quotes ? '"' : $m[0];
				}
				return $simple[ $m[1] ];
			},
			$text
		);
	}

	/**
	 * The object-operator token types this scan treats as a method dispatch: T_OBJECT_OPERATOR
	 * always, plus 'T_NULLSAFE_OBJECT_OPERATOR' where the running PHP defines it (PHP 8+; the
	 * constant does not exist on the PHP 7.4 floor, so it is read through defined()/constant(),
	 * the guard tests/Support/StoredTextSanitizerScanner.php already uses).
	 *
	 * @return int[]
	 */
	private function operator_tokens(): array {
		$tokens = array( T_OBJECT_OPERATOR );
		if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$tokens[] = constant( 'T_NULLSAFE_OBJECT_OPERATOR' );
		}
		return $tokens;
	}

	/**
	 * The token types a callable's own name can arrive as: T_STRING always, plus PHP 8's
	 * 'T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED' and 'T_NAME_RELATIVE' where the running PHP
	 * defines them, so `\update_post_meta(` and `namespace\delete_post_meta(` are matched on
	 * PHP 8 the same way a bare `update_post_meta(` is. PHP 7.4 tokenizes the same source as
	 * T_NS_SEPARATOR (or, after T_NAMESPACE for the relative form) plus a T_STRING, which the
	 * T_STRING branch already matches, so the floor needs no separate handling.
	 *
	 * @return int[]
	 */
	private function name_token_types(): array {
		$types = array( T_STRING );
		foreach ( array( 'T_NAME_FULLY_QUALIFIED', 'T_NAME_QUALIFIED', 'T_NAME_RELATIVE' ) as $constant ) {
			if ( defined( $constant ) ) {
				$types[] = constant( $constant );
			}
		}
		return $types;
	}

	/**
	 * A name token's own callable name, with any namespace prefix stripped: `\update_post_meta` and
	 * `namespace\delete_post_meta` both reduce to their last segment, so a namespaced call is
	 * matched by the same name a bare call would be. Over-matching a namespaced function that
	 * merely shares a banned name is accepted: it only widens detection over this plugin's own
	 * source.
	 *
	 * @param string $raw The raw token text.
	 */
	private function last_name_segment( string $raw ): string {
		$pos = strrpos( $raw, '\\' );
		return false === $pos ? $raw : substr( $raw, $pos + 1 );
	}

	/**
	 * Test whether $text contains a mutation verb (INSERT, UPDATE, DELETE, REPLACE) as a whole
	 * word, case-insensitively.
	 *
	 * @param string $text Accumulated statement text.
	 */
	private function contains_mutation_verb( string $text ): bool {
		return (bool) preg_match( '/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $text );
	}

	/**
	 * Test whether $text mentions a banned table fragment.
	 *
	 * @param string $text Accumulated statement text.
	 */
	private function contains_banned_table_fragment( string $text ): bool {
		foreach ( self::BANNED_TABLE_FRAGMENTS as $fragment ) {
			if ( false !== stripos( $text, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Close out one SQL statement: if its accumulated text carries both a mutation verb and a
	 * banned table fragment, record one violation at the statement's first contributing token.
	 *
	 * @param string                                                            $text       Accumulated statement text.
	 * @param int|null                                                          $start_idx  Index of the statement's first contributing token, or null when nothing was joined.
	 * @param array<int,array{0:int,1:string,2:int}|string>                     $tokens     token_get_all() output, for the start token's line.
	 * @param list<array{line:int,callable:string,kind:string,token_index:int}> $found By reference: violations found so far.
	 */
	private function record_sql_statement( string $text, ?int $start_idx, array $tokens, array &$found ): void {
		if ( '' === $text || null === $start_idx ) {
			return;
		}
		if ( ! $this->contains_mutation_verb( $text ) || ! $this->contains_banned_table_fragment( $text ) ) {
			return;
		}
		$start_token = $tokens[ $start_idx ];
		$found[]     = array(
			'line'        => is_array( $start_token ) ? $start_token[2] : 0,
			'callable'    => 'sql',
			'kind'        => 'sql',
			'token_index' => $start_idx,
		);
	}

	/**
	 * Every violation in $source: banned bare/dynamic function calls, banned method/static vendor
	 * calls, banned function names (and, under includes/abilities/, the update_option name) as
	 * string literals, the 'meta_input' literal, a raw $wpdb mutating call or SQL string naming a
	 * banned table, and (only for a comments.php-shaped source) a get_comment() call that follows
	 * one of that file's write calls without going through aafm_comment_readback().
	 *
	 * @param string $source       Full file contents.
	 * @param string $virtual_path Path the fixture pretends to live at, so path-scoped rules apply.
	 * @return list<array{line:int,callable:string,kind:string,token_index:int}>
	 */
	private function find_violations( string $source, string $virtual_path ): array {
		if ( self::EXEMPT_PATH === $virtual_path ) {
			return array();
		}

		$tokens              = token_get_all( $source );
		$total               = count( $tokens );
		$found               = array();
		$scoped_to_abilities = 0 === strpos( $virtual_path, self::PATH_SCOPED_PREFIX );
		$is_comments_file    = 'includes/abilities/comments.php' === $virtual_path;
		$wpdb_aliases        = array( '$wpdb' => true );
		$comment_writes_seen = false;
		$brace_depth         = 0;
		$operator_tokens     = $this->operator_tokens();
		$name_token_types    = $this->name_token_types();

		// SQL-string statements. The buffer holds every string literal (single, double, heredoc and
		// nowdoc, including the literal parts of an interpolated string, each decoded the way PHP
		// decodes it) and every $wpdb->name property name read since the previous statement
		// boundary, each term followed by one space. A boundary is a ';', a block '{' or '}', a close
		// tag, or a ':' that does not close a pending ternary '?', such as the ':' of a case or
		// default label, of an alternative-syntax block or of a return type. An interpolation brace
		// (`{$` or `${` and the '}' that closes it) and a selector brace (`->{`, `?->{`, `::{`, or the
		// `{` of an out-of-string `${`) are not boundaries. At every boundary record_sql_statement() checks the joined text for a
		// mutation verb plus a banned table fragment and the buffer resets, so a literal in an if,
		// switch or foreach header, or in a case label, never joins the statement after it. Terms
		// joined by any means inside one statement (concatenation, an argument list, an array
		// literal, a ternary) are seen together. $statement_start_idx is the statement's first
		// contributing token, the first literal or property name the buffer takes, so a violation is
		// keyed in the function that holds its SQL even when that SQL opens the function body. Known
		// limit: a closure body inside one expression splits that expression.
		$statement_buffer    = '';
		$statement_start_idx = null;
		$pending_ternaries   = 0;
		// What each open brace or string is, innermost last: 'block', 'selector' or 'interpolation'
		// for a brace; 'double', 'heredoc' or 'nowdoc' for a string whose literal parts arrive as
		// separate tokens. A closing '}' ends a statement only when it closes a block, and the
		// innermost string decides how its literal parts decode.
		$nesting = array();
		// The token range of a braced method selector, `$wpdb->{'update'}(...)`: the selector is the
		// method's name in another spelling, never SQL text, so it is kept out of the buffer. A
		// braced property read with no call after it is not suppressed; its literal joins like any
		// other.
		$suppressed_literal_indices = array();

		for ( $i = 0; $i < $total; $i++ ) {
			$token = $tokens[ $i ];
			$text  = is_array( $token ) ? $token[1] : $token;
			$ttype = is_array( $token ) ? $token[0] : null;

			$boundary = false;
			if ( T_CURLY_OPEN === $ttype || T_DOLLAR_OPEN_CURLY_BRACES === $ttype ) {
				$nesting[] = 'interpolation';
			} elseif ( T_START_HEREDOC === $ttype ) {
				$nesting[] = false !== strpos( $text, "'" ) ? 'nowdoc' : 'heredoc';
			} elseif ( T_END_HEREDOC === $ttype ) {
				array_pop( $nesting );
			} elseif ( T_CLOSE_TAG === $ttype ) {
				$boundary = true;
			} elseif ( '"' === $token && 'double' === end( $nesting ) ) {
				array_pop( $nesting );
			} elseif ( '"' === $token || 'b"' === $token || 'B"' === $token ) {
				// An interpolated string opens on `"` or, with the binary prefix, on `b"` or `B"`,
				// and always closes on a plain `"`.
				$nesting[] = 'double';
			} elseif ( '{' === $token ) {
				// A selector brace follows `->`, `?->` or `::`, or is the `{` of an out-of-string
				// `${` variable-variable. Any other `{` opens a block.
				$prev_idx    = $this->previous_significant_index( $tokens, $i - 1 );
				$prev_token  = null !== $prev_idx ? $tokens[ $prev_idx ] : null;
				$is_selector = '$' === $prev_token || ( is_array( $prev_token ) && ( T_DOUBLE_COLON === $prev_token[0] || in_array( $prev_token[0], $operator_tokens, true ) ) );
				$nesting[]   = $is_selector ? 'selector' : 'block';
				$boundary    = ! $is_selector;
			} elseif ( '}' === $token ) {
				$boundary = ! in_array( array_pop( $nesting ), array( 'selector', 'interpolation' ), true );
			} elseif ( ';' === $token ) {
				$boundary = true;
			} elseif ( '?' === $token ) {
				++$pending_ternaries;
			} elseif ( ':' === $token ) {
				if ( $pending_ternaries > 0 ) {
					--$pending_ternaries;
				} else {
					$boundary = true;
				}
			}
			if ( $boundary ) {
				$this->record_sql_statement( $statement_buffer, $statement_start_idx, $tokens, $found );
				$statement_buffer    = '';
				$statement_start_idx = null;
				$pending_ternaries   = 0;
			}

			if ( isset( $suppressed_literal_indices[ $i ] ) ) {
				continue;
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $ttype || T_ENCAPSED_AND_WHITESPACE === $ttype ) {
				if ( T_CONSTANT_ENCAPSED_STRING === $ttype ) {
					$context = '"' === $this->strip_binary_prefix( $text )[0] ? 'double' : 'single';
				} else {
					$innermost = end( $nesting );
					$context   = 'double' === $innermost ? 'interpolated' : ( 'nowdoc' === $innermost ? 'nowdoc' : 'heredoc' );
				}
				if ( null === $statement_start_idx ) {
					$statement_start_idx = $i;
				}
				$statement_buffer .= $this->decode_sql_literal( $text, $context ) . ' ';
			}

			// The comment-confirmation rule is scoped to one function body: a top-level function
			// boundary (brace depth returning to 0) resets whether a write call has been seen, so a
			// pre-write existence check in the NEXT function is never mistaken for a post-write read
			// carried over from an earlier one.
			if ( $is_comments_file ) {
				if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$brace_depth;
				} elseif ( '}' === $token ) {
					--$brace_depth;
					if ( 0 === $brace_depth ) {
						$comment_writes_seen = false;
					}
				}
			}

			// Track a bare `$x = $wpdb;` (or `$x = $alias;`) alias assignment, so a later
			// dispatch through $x is still recognised as $wpdb.
			if ( is_array( $token ) && T_VARIABLE === $token[0] && isset( $wpdb_aliases[ $token[1] ] ) ) {
				list( $after ) = $this->significant_token( $tokens, $i + 1 );
				$prev_idx      = $this->previous_significant_index( $tokens, $i - 1 );
				if ( is_string( $after ) && ';' === $after && null !== $prev_idx && is_string( $tokens[ $prev_idx ] ) && '=' === $tokens[ $prev_idx ] ) {
					$target_idx = $this->previous_significant_index( $tokens, $prev_idx - 1 );
					if ( null !== $target_idx && is_array( $tokens[ $target_idx ] ) && T_VARIABLE === $tokens[ $target_idx ][0] ) {
						$wpdb_aliases[ $tokens[ $target_idx ][1] ] = true;
					}
				}
			}

			// A $wpdb (or alias) dispatch: a mutating method call naming a banned table, or (only
			// when it is not a call at all) a property read whose name joins the enclosing SQL
			// statement's accumulated text. A method name is a plain name, a braced selector that is
			// exactly one quoted literal, or dynamic: a variable (`$wpdb->$m(`) or any other braced
			// selector (`$wpdb->{$m}(`), whose callable is the selector as written.
			if ( is_array( $token ) && T_VARIABLE === $token[0] && isset( $wpdb_aliases[ $token[1] ] ) ) {
				list( $op, $op_idx ) = $this->significant_token( $tokens, $i + 1 );
				if ( is_array( $op ) && in_array( $op[0], $operator_tokens, true ) ) {
					list( $method_tok, $method_idx ) = $this->significant_token( $tokens, $op_idx + 1 );
					$method_name                     = null;
					$callable                        = null;
					$after_name_idx                  = null;
					$selector_close                  = null;
					if ( is_array( $method_tok ) && T_STRING === $method_tok[0] ) {
						$method_name    = strtolower( $method_tok[1] );
						$callable       = '$wpdb->' . $method_name;
						$after_name_idx = $method_idx + 1;
					} elseif ( is_string( $method_tok ) && '{' === $method_tok ) {
						$selector_close = $this->matching_bracket_index( $tokens, $method_idx, '{', '}' );
						if ( null !== $selector_close ) {
							$inner       = '';
							$significant = array();
							for ( $b = $method_idx + 1; $b < $selector_close; $b++ ) {
								$btok   = $tokens[ $b ];
								$inner .= is_array( $btok ) ? $btok[1] : $btok;
								if ( ! is_array( $btok ) || ! in_array( $btok[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
									$significant[] = $btok;
								}
							}
							if ( 1 === count( $significant ) && is_array( $significant[0] ) && T_CONSTANT_ENCAPSED_STRING === $significant[0][0] ) {
								$method_name = strtolower( $this->decode_string_literal( $significant[0][1] ) );
								$callable    = '$wpdb->' . $method_name;
							} else {
								$method_name = '$dynamic';
								$callable    = '$wpdb->{' . trim( $inner ) . '}';
							}
							$after_name_idx = $selector_close + 1;
						}
					} elseif ( is_array( $method_tok ) && T_VARIABLE === $method_tok[0] ) {
						$method_name    = '$dynamic';
						$callable       = '$wpdb->' . $method_tok[1];
						$after_name_idx = $method_idx + 1;
					}
					if ( null !== $method_name && null !== $after_name_idx ) {
						list( $paren, $paren_idx ) = $this->significant_token( $tokens, $after_name_idx );
						$is_call                   = is_string( $paren ) && '(' === $paren;
						if ( $is_call ) {
							if ( null !== $selector_close ) {
								for ( $b = $method_idx + 1; $b < $selector_close; $b++ ) {
									$suppressed_literal_indices[ $b ] = true;
								}
							}
							$is_mutator = in_array( $method_name, self::WPDB_MUTATORS, true ) || '$dynamic' === $method_name;
							if ( $is_mutator ) {
								$args = $this->call_args_text( $tokens, $paren_idx );
								if ( $this->contains_banned_table_fragment( $args ) ) {
									$found[] = array(
										'line'        => $token[2],
										'callable'    => $callable,
										'kind'        => 'wpdb_table',
										'token_index' => $i,
									);
								}
							}
						} elseif ( is_array( $method_tok ) && T_STRING === $method_tok[0] ) {
							// A non-call $wpdb->name property read: its name joins the enclosing
							// statement's accumulated text, and it can be the statement's first
							// contributing token.
							if ( null === $statement_start_idx ) {
								$statement_start_idx = $method_idx;
							}
							$statement_buffer .= $method_tok[1] . ' ';
						}
					}
				}
			}

			if ( ! is_array( $token ) || ! in_array( $ttype, $name_token_types, true ) ) {
				continue;
			}
			$name  = $this->last_name_segment( $token[1] );
			$lname = strtolower( $name );

			$prev_idx = $this->previous_significant_index( $tokens, $i - 1 );
			$prev     = null !== $prev_idx ? $tokens[ $prev_idx ] : null;

			$preceded_by_operator = is_array( $prev ) && in_array( $prev[0], $operator_tokens, true );
			$preceded_by_colons   = is_array( $prev ) && T_DOUBLE_COLON === $prev[0];
			$preceded_by_function = is_array( $prev ) && T_FUNCTION === $prev[0];
			$preceded_by_new      = is_array( $prev ) && T_NEW === $prev[0];

			list( $next )     = $this->significant_token( $tokens, $i + 1 );
			$followed_by_call = is_string( $next ) && '(' === $next;

			// A comments.php-shaped source: track the write set, and flag a get_comment( that
			// follows one, per file.
			if ( $is_comments_file && ! $preceded_by_operator && ! $preceded_by_colons && $followed_by_call ) {
				$comment_write_names = array( 'wp_insert_comment', 'wp_set_comment_status', 'wp_spam_comment', 'wp_unspam_comment', 'wp_trash_comment', 'wp_untrash_comment', 'wp_update_comment', 'wp_delete_comment', 'wp_new_comment' );
				if ( in_array( $name, $comment_write_names, true ) ) {
					$comment_writes_seen = true;
				} elseif ( 'get_comment' === $name && $comment_writes_seen ) {
					$found[] = array(
						'line'        => $token[2],
						'callable'    => 'get_comment',
						'kind'        => 'comment_readback',
						'token_index' => $i,
					);
				}
			}

			if ( $preceded_by_function || $preceded_by_new ) {
				continue;
			}

			if ( $preceded_by_operator ) {
				if ( in_array( $lname, self::BANNED_METHODS, true ) && $followed_by_call ) {
					if ( 'delete' === $lname ) {
						$op_idx   = $this->previous_significant_index( $tokens, $i - 1 );
						$op_idx   = null !== $op_idx ? $op_idx : $i - 1;
						$receiver = $this->receiver_before_operator( $tokens, $op_idx );
						if ( '$wpdb' === $receiver ) {
							continue;
						}
					}
					$found[] = array(
						'line'        => $token[2],
						'callable'    => $name,
						'kind'        => 'method',
						'token_index' => $i,
					);
				}
				continue;
			}

			if ( $preceded_by_colons ) {
				if ( in_array( $name, self::BANNED_STATIC, true ) && $followed_by_call ) {
					$found[] = array(
						'line'        => $token[2],
						'callable'    => $name,
						'kind'        => 'static',
						'token_index' => $i,
					);
				}
				continue;
			}

			// A bare/dynamic function call.
			if ( $followed_by_call ) {
				if ( self::PATH_SCOPED_FUNCTION === $lname ) {
					if ( $scoped_to_abilities ) {
						$found[] = array(
							'line'        => $token[2],
							'callable'    => $name,
							'kind'        => 'function',
							'token_index' => $i,
						);
					}
				} elseif ( in_array( $lname, self::BANNED_FUNCTIONS, true ) ) {
					$found[] = array(
						'line'        => $token[2],
						'callable'    => $name,
						'kind'        => 'function',
						'token_index' => $i,
					);
				}
				continue;
			}
		}

		// Flush a statement still open at end of file (no boundary ever closed it).
		$this->record_sql_statement( $statement_buffer, $statement_start_idx, $tokens, $found );

		// String-literal scan: a banned function name, the path-scoped update_option literal when
		// the virtual path is scoped to it, or 'meta_input', as a quoted string - except as the
		// sole argument of function_exists(), and except when inside a comment (token_get_all
		// already classifies comment text as T_COMMENT/T_DOC_COMMENT, never as a string token).
		for ( $i = 0; $i < $total; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_CONSTANT_ENCAPSED_STRING !== $token[0] ) {
				continue;
			}
			$value = $this->decode_string_literal( $token[1] );

			if ( 'meta_input' === $value ) {
				$found[] = array(
					'line'        => $token[2],
					'callable'    => 'meta_input',
					'kind'        => 'string_literal',
					'token_index' => $i,
				);
				continue;
			}

			// PHP resolves a string callable with a leading namespace separator, such as
			// '\update_post_meta', to the global function, so the name is read without it.
			$value                  = ltrim( $value, '\\' );
			$lvalue                 = strtolower( $value );
			$is_banned_literal      = in_array( $lvalue, self::BANNED_FUNCTIONS, true );
			$is_path_scoped_literal = $scoped_to_abilities && self::PATH_SCOPED_FUNCTION === $lvalue;
			if ( ! $is_banned_literal && ! $is_path_scoped_literal ) {
				continue;
			}

			$prev_idx = $this->previous_significant_index( $tokens, $i - 1 );
			if ( null !== $prev_idx && is_string( $tokens[ $prev_idx ] ) && '(' === $tokens[ $prev_idx ] ) {
				$fn_idx = $this->previous_significant_index( $tokens, $prev_idx - 1 );
				if ( null !== $fn_idx && is_array( $tokens[ $fn_idx ] ) && in_array( $tokens[ $fn_idx ][0], $name_token_types, true ) && 'function_exists' === strtolower( $this->last_name_segment( $tokens[ $fn_idx ][1] ) ) ) {
					list( $close ) = $this->significant_token( $tokens, $i + 1 );
					if ( is_string( $close ) && ')' === $close ) {
						continue; // Exempt: the sole argument of function_exists(), qualified name included.
					}
				}
			}

			$found[] = array(
				'line'        => $token[2],
				'callable'    => $value,
				'kind'        => 'string_literal',
				'token_index' => $i,
			);
		}

		usort(
			$found,
			static function ( array $a, array $b ): int {
				return $a['line'] <=> $b['line'];
			}
		);

		return $found;
	}

	/**
	 * The enclosing named function for a given token index, or '{main}' at file scope. Used only to
	 * build a violation's identity key, never to change what counts as a violation.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param int                                           $index  Index of the violating token.
	 */
	private function enclosing_function( array $tokens, int $index ): string {
		$name  = '{main}';
		$depth = 0;
		for ( $j = 0; $j < $index; $j++ ) {
			$token = $tokens[ $j ];
			if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
				list( $next ) = $this->significant_token( $tokens, $j + 1 );
				if ( is_array( $next ) && T_STRING === $next[0] ) {
					$candidate = $next[1];
					// Confirm this function's body actually encloses $index by finding its opening
					// brace and matching close.
					for ( $k = $j; $k < $index; $k++ ) {
						if ( '{' === $tokens[ $k ] ) {
							$close = $this->matching_bracket_index( $tokens, $k, '{', '}' );
							if ( null !== $close && $index < $close ) {
								$name = $candidate;
							}
							break;
						}
					}
				}
			}
			if ( '{' === $token || ( is_array( $token ) && T_CURLY_OPEN === $token[0] ) ) {
				++$depth;
			} elseif ( '}' === $token ) {
				--$depth;
			}
		}
		unset( $depth );
		return $name;
	}

	/**
	 * Every violation's full identity key (path|function|callable|ordinal), in the order
	 * find_violations() returns them, computed from the violation's own token_index rather than by
	 * re-locating it in the source text afterward.
	 *
	 * @param string $source       Full file contents.
	 * @param string $virtual_path Path the fixture pretends to live at.
	 * @return string[]
	 */
	private function violation_keys( string $source, string $virtual_path ): array {
		$tokens     = token_get_all( $source );
		$violations = $this->find_violations( $source, $virtual_path );
		$ordinals   = array();
		$keys       = array();
		foreach ( $violations as $violation ) {
			$enclosing                = $this->enclosing_function( $tokens, $violation['token_index'] );
			$ordinal_key              = $virtual_path . '|' . $enclosing . '|' . $violation['callable'];
			$ordinals[ $ordinal_key ] = ( $ordinals[ $ordinal_key ] ?? 0 ) + 1;
			$keys[]                   = $ordinal_key . '|' . $ordinals[ $ordinal_key ];
		}
		return $keys;
	}

	/**
	 * Negative fixtures (must be flagged, each asserting its full identity key).
	 */
	public function test_flags_a_plain_banned_function_call(): void {
		$source = "<?php\nfunction f() {\n\tupdate_post_meta( 1, 'k', 'v' );\n}\n";
		$this->assertSame( array( 'includes/abilities/fixture.php|f|update_post_meta|1' ), $this->violation_keys( $source, 'includes/abilities/fixture.php' ) );
	}

	public function test_flags_a_fully_qualified_banned_function_call(): void {
		$source = "<?php\n\\update_post_meta( 1, 'k', 'v' );\n";
		$this->assertSame( array( 'includes/fixture.php|{main}|update_post_meta|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_namespace_relative_banned_function_call(): void {
		$source = "<?php\nnamespace\\delete_post_meta( 1, 'k' );\n";
		$this->assertSame( array( 'includes/fixture.php|{main}|delete_post_meta|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_braced_wpdb_method_touching_a_meta_table(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$wpdb->{'update'}( \$wpdb->postmeta, array(), array() );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|$wpdb->update|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_dynamic_wpdb_method_touching_a_meta_table(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$m = 'update';\n\t\$wpdb->\$m( \$wpdb->postmeta, array(), array() );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|$wpdb->$m|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_an_aliased_wpdb_method_touching_a_meta_table(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$db = \$wpdb;\n\t\$db->update( \$wpdb->postmeta, array(), array() );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|$wpdb->update|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_variable_function_holding_a_banned_name(): void {
		$source = "<?php\nfunction f() {\n\t\$fn = 'update_post_meta';\n\t\$fn( 1, 'k', 'v' );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|update_post_meta|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_call_user_func_of_a_banned_name(): void {
		$source = "<?php\ncall_user_func( 'update_post_meta', 1, 'k', 'v' );\n";
		$this->assertSame( array( 'includes/fixture.php|{main}|update_post_meta|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_the_meta_input_literal(): void {
		$source = "<?php\n\$args = array( 'meta_input' => array( 'k' => 'v' ) );\n";
		$this->assertSame( array( 'includes/fixture.php|{main}|meta_input|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_dollar_product_save(): void {
		$this->assertSame( array( 'includes/fixture.php|{main}|save|1' ), $this->violation_keys( "<?php\n\$product->save();\n", 'includes/fixture.php' ) );
	}

	public function test_flags_nullsafe_order_save(): void {
		$this->assertSame( array( 'includes/fixture.php|{main}|save|1' ), $this->violation_keys( "<?php\n\$order?->save();\n", 'includes/fixture.php' ) );
	}

	public function test_flags_static_save_post(): void {
		$this->assertSame( array( 'includes/fixture.php|{main}|savePost|1' ), $this->violation_keys( "<?php\n\$class::savePost( \$id, \$data );\n", 'includes/fixture.php' ) );
	}

	public function test_flags_order_add_product(): void {
		$this->assertSame( array( 'includes/fixture.php|{main}|add_product|1' ), $this->violation_keys( "<?php\n\$order->add_product( \$p, 1 );\n", 'includes/fixture.php' ) );
	}

	public function test_flags_product_delete_true(): void {
		$this->assertSame( array( 'includes/fixture.php|{main}|delete|1' ), $this->violation_keys( "<?php\n\$product->delete( true );\n", 'includes/fixture.php' ) );
	}

	public function test_flags_wc_tax_insert_tax_rate(): void {
		$this->assertSame( array( 'includes/fixture.php|{main}|_insert_tax_rate|1' ), $this->violation_keys( "<?php\n\\WC_Tax::_insert_tax_rate( \$data );\n", 'includes/fixture.php' ) );
	}

	public function test_flags_wc_create_refund(): void {
		$this->assertSame( array( 'includes/fixture.php|{main}|wc_create_refund|1' ), $this->violation_keys( "<?php\nwc_create_refund( \$args );\n", 'includes/fixture.php' ) );
	}

	public function test_flags_wpdb_update_naming_the_shipping_zone_methods_table(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$wpdb->update( \$wpdb->prefix . 'woocommerce_shipping_zone_methods', array(), array() );\n}\n";
		$this->assertSame( array( 'includes/abilities/woocommerce/shipping.php|f|$wpdb->update|1' ), $this->violation_keys( $source, 'includes/abilities/woocommerce/shipping.php' ) );
	}

	public function test_flags_a_sql_string_delete_from_postmeta(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$wpdb->query( \"DELETE FROM {\$wpdb->postmeta} WHERE meta_id = 1\" );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_sql_string_update_on_a_woocommerce_table(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$wpdb->query( \"UPDATE {\$wpdb->prefix}woocommerce_order_items SET order_item_name = 'x'\" );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_sql_string_assembled_by_concatenation_before_the_call(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$sql = \"DELETE FROM \" . \$wpdb->usermeta . \" WHERE umeta_id = 1\";\n\t\$wpdb->query( \$sql );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_sql_string_built_with_prepares_own_i_placeholder(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$wpdb->query( \$wpdb->prepare( \"DELETE FROM %i WHERE meta_id = %d\", \$wpdb->postmeta, 1 ) );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_sql_string_with_a_parenthesized_ternary_concatenation(): void {
		$source = "<?php\nfunction f( \$x ) {\n\tglobal \$wpdb;\n\t\$sql = \"DELETE FROM \" . ( \$x ? \$wpdb->postmeta : \$wpdb->termmeta ) . \" WHERE 1\";\n\t\$wpdb->query( \$sql );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_sql_string_in_a_heredoc(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$sql = <<<SQL\nDELETE FROM {\$wpdb->postmeta} WHERE meta_id = 1\nSQL;\n\t\$wpdb->query( \$sql );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_sql_string_in_a_nowdoc(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$sql = <<<'SQL'\nDELETE FROM wp_postmeta WHERE meta_id = 1\nSQL;\n\t\$wpdb->query( \$sql );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_sql_string_joining_a_braced_property_read(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$wpdb->query( 'DELETE FROM ' . \$wpdb->{'postmeta'} . ' WHERE meta_id = 1' );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_keys_a_sql_string_that_opens_a_function_body_inside_that_function(): void {
		$source = "<?php\nfunction h() {}\nfunction g( \$wpdb ) {\n\t\$wpdb->query( \"DELETE FROM {\$wpdb->postmeta} WHERE meta_id = 1\" );\n\t\$wpdb->query( \"DELETE FROM {\$wpdb->usermeta} WHERE umeta_id = 1\" );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|g|sql|1', 'includes/fixture.php|g|sql|2' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_braced_variable_wpdb_method_selector_touching_a_meta_table(): void {
		$source = "<?php\nfunction f( \$m ) {\n\tglobal \$wpdb;\n\t\$wpdb->{\$m}( \$wpdb->postmeta, array( 'meta_value' => 'x' ), array( 'meta_id' => 1 ) );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|$wpdb->{$m}|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_braced_concatenated_wpdb_method_selector_touching_a_meta_table(): void {
		$source = "<?php\nfunction f( \$m ) {\n\tglobal \$wpdb;\n\t\$wpdb->{'up' . 'date'}( \$wpdb->postmeta, array( 'meta_value' => 'x' ), array( 'meta_id' => 1 ) );\n}\n";
		$this->assertSame( array( "includes/fixture.php|f|\$wpdb->{'up' . 'date'}|1" ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_an_if_header_literal_never_joins_the_next_functions_sql(): void {
		$source = "<?php\n" . 'function h( $a ) { if ( \'x\' === $a ) {} } function g( $wpdb ) { $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE 1" ); }';
		$this->assertSame( array( 'includes/fixture.php|g|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_a_verb_in_an_if_header_never_joins_the_next_functions_read(): void {
		$source = "<?php\n" . 'function h( $a ) { if ( \'delete\' === $a ) {} } function g( $wpdb ) { return $wpdb->get_var( "SELECT meta_value FROM {$wpdb->postmeta}" ); }';
		$this->assertSame( array(), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_a_verb_in_a_case_label_never_joins_the_read_after_it(): void {
		$source = "<?php\n" . 'function g( $wpdb, $a ) { switch ( $a ) { case \'delete\': return $wpdb->get_col( "SELECT meta_key FROM {$wpdb->postmeta}" ); } }';
		$this->assertSame( array(), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_a_verb_before_a_close_tag_never_joins_the_read_after_it(): void {
		$source = '<?php $label = \'delete\' ?><?php $wpdb->get_var( "SELECT meta_value FROM {$wpdb->postmeta}" );';
		$this->assertSame( array(), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_a_ternary_colon_is_not_a_statement_boundary(): void {
		$source = "<?php\n" . 'function f( $wpdb, $a ) { $wpdb->query( ( $a ? \'DELETE\' : \'SELECT *\' ) . \' FROM \' . $wpdb->postmeta ); }';
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_verb_after_an_escaped_newline_in_an_interpolated_string(): void {
		$source = "<?php\n" . 'function f( $wpdb ) { $wpdb->query( "\nDELETE FROM {$wpdb->postmeta} WHERE meta_id = 1" ); }';
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_verb_after_an_escaped_tab_in_a_double_quoted_string(): void {
		$source = "<?php\n" . 'function f( $wpdb ) { $wpdb->query( "\tDELETE FROM wp_postmeta WHERE meta_id = 1" ); }';
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_a_single_quoted_backslash_n_stays_two_characters(): void {
		$source = "<?php\n" . 'function f( $wpdb ) { $wpdb->query( \'\nDELETE FROM wp_postmeta WHERE meta_id = 1\' ); }';
		$this->assertSame( array(), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_braced_dollar_brace_interpolated_wpdb_method_selector(): void {
		$source = "<?php\n" . 'function f( $wpdb, $m ) { $wpdb->{"${m}"}( $wpdb->postmeta, array( \'meta_value\' => \'x\' ), array( \'meta_id\' => 1 ) ); }';
		$this->assertSame( array( 'includes/fixture.php|f|$wpdb->{"${m}"}|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_update_option_under_an_abilities_path(): void {
		$this->assertSame( array( 'includes/abilities/settings.php|{main}|update_option|1' ), $this->violation_keys( "<?php\nupdate_option( 'x', 1 );\n", 'includes/abilities/settings.php' ) );
	}

	public function test_flags_a_dynamic_update_option_string_literal_under_an_abilities_path(): void {
		$source = "<?php\nfunction f() {\n\t\$fn = 'update_option';\n\t\$fn( 'x', 1 );\n}\n";
		$this->assertSame( array( 'includes/abilities/fixture.php|f|update_option|1' ), $this->violation_keys( $source, 'includes/abilities/fixture.php' ) );
	}

	public function test_flags_the_untouched_post_field_confirmer(): void {
		$this->assertSame( array( 'includes/fixture.php|{main}|aafm_post_field_write_confirmed|1' ), $this->violation_keys( "<?php\naafm_post_field_write_confirmed( 1, 'post_title', 'a', '' );\n", 'includes/fixture.php' ) );
	}

	public function test_flags_a_get_comment_after_wp_update_comment_in_the_comments_file(): void {
		$source = "<?php\nfunction f( \$id ) {\n\twp_update_comment( array( 'comment_ID' => \$id ) );\n\t\$c = get_comment( \$id );\n}\n";
		$this->assertSame( array( 'includes/abilities/comments.php|f|get_comment|1' ), $this->violation_keys( $source, 'includes/abilities/comments.php' ) );
	}

	public function test_flags_a_get_comment_after_wp_trash_comment_in_the_comments_file(): void {
		$source = "<?php\nfunction f( \$id ) {\n\twp_trash_comment( \$id );\n\t\$c = get_comment( \$id );\n}\n";
		$this->assertSame( array( 'includes/abilities/comments.php|f|get_comment|1' ), $this->violation_keys( $source, 'includes/abilities/comments.php' ) );
	}

	public function test_a_dollar_brace_interpolation_keeps_the_comments_file_write_scope_open(): void {
		$source = "<?php\n" . 'function f( $id, $m ) { wp_update_comment( array( \'comment_ID\' => $id ) ); $s = "${m}"; $c = get_comment( $id ); }';
		$this->assertSame( array( 'includes/abilities/comments.php|f|get_comment|1' ), $this->violation_keys( $source, 'includes/abilities/comments.php' ) );
	}

	public function test_flags_a_verb_after_an_escaped_newline_in_a_binary_double_quoted_string(): void {
		$source = "<?php\n" . 'function f( $wpdb ) { $wpdb->query( b"\nDELETE FROM wp_postmeta WHERE meta_id = 1" ); }';
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_a_binary_interpolated_string_keeps_the_string_nesting_in_step(): void {
		$source = "<?php\n" . 'function f( $wpdb, $a, $y ) { $wpdb->query( "DELETE FROM {$a[b"x$y"]} {$wpdb->postmeta}" ); }';
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_binary_string_literal_holding_a_banned_name(): void {
		$source = "<?php\n" . 'function f() {' . "\n" . 'call_user_func( b\'update_post_meta\', 1, \'k\', \'v\' );' . "\n" . 'call_user_func( B"delete_post_meta", 1, \'k\' ); }';
		$this->assertSame( array( 'includes/fixture.php|f|update_post_meta|1', 'includes/fixture.php|f|delete_post_meta|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_banned_name_spelled_with_an_escape_in_a_binary_string(): void {
		$source = "<?php\n" . 'function f() { call_user_func(b"update_\x70ost_meta", 1, \'k\', \'v\'); }';
		$this->assertSame( array( 'includes/fixture.php|f|update_post_meta|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_banned_name_spelled_with_an_escape_in_a_double_quoted_string(): void {
		$source = "<?php\n" . 'function f() { call_user_func("update_\x70ost_meta", 1, \'k\', \'v\'); }';
		$this->assertSame( array( 'includes/fixture.php|f|update_post_meta|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_braced_wpdb_method_selector_spelled_with_an_escape(): void {
		$source = "<?php\n" . 'function f($wpdb) { $wpdb->{b"\x75pdate"}($wpdb->postmeta, [], []); }';
		$this->assertSame( array( 'includes/fixture.php|f|$wpdb->update|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_banned_name_literal_with_a_leading_namespace_separator(): void {
		$source = "<?php\n" . 'function f() { call_user_func( \'\update_post_meta\', 1, \'k\', \'v\' ); }';
		$this->assertSame( array( 'includes/fixture.php|f|update_post_meta|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_sql_string_joined_across_an_out_of_string_variable_variable(): void {
		$source = "<?php\n" . 'function f( $wpdb ) { $wpdb->query( \'DELETE FROM \' . ${\'t\'} . $wpdb->postmeta ); }';
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_sql_string_joined_across_a_static_member_selector(): void {
		$source = "<?php\n" . 'function f( $wpdb ) { $wpdb->query( \'DELETE FROM \' . Foo::{\'t\'}() . $wpdb->postmeta ); }';
		$this->assertSame( array( 'includes/fixture.php|f|sql|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_a_brace_inside_string_content_never_closes_a_wpdb_method_selector(): void {
		$source = "<?php\n" . 'function f( $wpdb, $m ) { $wpdb->{substr("{$m}}",0,-1)}( $wpdb->postmeta, array( \'meta_value\' => \'x\' ), array( \'meta_id\' => 1 ) ); }';
		$this->assertSame( array( 'includes/fixture.php|f|$wpdb->{substr("{$m}}",0,-1)}|1' ), $this->violation_keys( $source, 'includes/fixture.php' ) );
	}

	/**
	 * Positive fixtures (must not be flagged).
	 */
	public function test_ignores_a_banned_name_in_a_comment_and_a_docblock(): void {
		$source = "<?php\n// update_post_meta( 1, 'k', 'v' ) used to be called here.\n/**\n * get_post_meta( 1 ) also used to be bare.\n */\nfunction f(): void {}\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_a_function_declaration_that_merely_starts_with_a_banned_substring(): void {
		$source = "<?php\nfunction aafm_exec_update_post_meta( \$id ) {\n\treturn \$id;\n}\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_a_method_call_named_update_field(): void {
		$source = "<?php\n\$obj->update_field( 'k', 'v' );\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_function_exists_of_update_field(): void {
		$source = "<?php\nif ( function_exists( 'update_field' ) ) {\n\t\$x = 1;\n}\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_a_fully_qualified_function_exists_of_update_field(): void {
		$source = "<?php\nif ( \\function_exists( 'update_field' ) ) {\n\t\$x = 1;\n}\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_function_exists_of_wc_delete_order_item(): void {
		$source = "<?php\nif ( function_exists( 'wc_delete_order_item' ) ) {\n\t\$x = 1;\n}\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_weak_reference_create(): void {
		$source = "<?php\n\$ref = \\WeakReference::create( \$x );\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_a_plain_wpdb_delete_naming_no_banned_table(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$wpdb->delete( \$table, \$where );\n}\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_update_option_outside_an_abilities_path(): void {
		$this->assertSame( array(), $this->find_violations( "<?php\nupdate_option( 'x', 1 );\n", 'includes/option-cache.php' ) );
	}

	public function test_ignores_the_update_option_string_literal_outside_an_abilities_path(): void {
		$this->assertSame( array(), $this->find_violations( "<?php\n\$x = 'update_option';\n", 'includes/option-cache.php' ) );
	}

	public function test_ignores_method_exists_of_save(): void {
		$source = "<?php\nif ( method_exists( \$item, 'save' ) ) {\n\t\$x = 1;\n}\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_a_get_comment_before_the_write_in_the_comments_file(): void {
		$source = "<?php\nfunction f( \$id ) {\n\t\$c = get_comment( \$id );\n\twp_update_comment( array( 'comment_ID' => \$id ) );\n}\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/abilities/comments.php' ) );
	}

	public function test_ignores_a_sql_string_select_naming_a_meta_table(): void {
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$wpdb->query( \"SELECT meta_value FROM {\$wpdb->postmeta}\" );\n}\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_the_meta_key_aggregate_read_shape(): void {
		// The shape of includes/admin/page.php's detected-meta-keys query: a multi-line SELECT
		// joining the postmeta table, no mutation verb anywhere in the statement.
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\t\$view = aafm_wpdb_col( \$wpdb->prepare( \"SELECT DISTINCT pm.meta_key FROM {\$wpdb->postmeta} pm INNER JOIN ( SELECT ID FROM {\$wpdb->posts} WHERE post_type IN (%s) ORDER BY ID DESC LIMIT 5000 ) p ON p.ID = pm.post_id ORDER BY pm.meta_key ASC LIMIT 200\", \$types ) );\n}\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/admin/page.php' ) );
	}

	public function test_ignores_the_media_search_exists_subquery_shape(): void {
		// The shape of includes/abilities/media.php's search filter: an EXISTS subquery naming the
		// postmeta table inside a WHERE fragment, no mutation verb anywhere in the statement.
		$source = "<?php\nfunction f() {\n\tglobal \$wpdb;\n\treturn \$where . \$wpdb->prepare( \" AND ( EXISTS ( SELECT 1 FROM {\$wpdb->postmeta} pm WHERE pm.post_id = {\$wpdb->posts}.ID AND pm.meta_value LIKE %s ) )\", \$like );\n}\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/abilities/media.php' ) );
	}

	public function test_ignores_the_exempt_write_contract_file(): void {
		$source = "<?php\nupdate_post_meta( 1, 'k', 'v' );\n\$product->save();\n\$wpdb->query( \"DELETE FROM {\$wpdb->postmeta}\" );\n";
		$this->assertSame( array(), $this->find_violations( $source, 'includes/write-contract.php' ) );
	}

	// --- The production scan and the legacy list ---------------------------

	/**
	 * Every scanned file's path relative to the plugin root, plus its source.
	 *
	 * @return array<string,string> path => source.
	 */
	private function scanned_files(): array {
		$root  = rtrim( AAFM_PLUGIN_DIR, '/' );
		$files = array();

		$top_level = array( 'agent-abilities-for-mcp.php', 'uninstall.php' );
		foreach ( $top_level as $name ) {
			$path = $root . '/' . $name;
			if ( is_file( $path ) ) {
				$files[ $name ] = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- this plugin's own local source.
			}
		}

		$includes_dir = $root . '/includes';
		$iterator     = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $includes_dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative           = 'includes/' . ltrim( str_replace( $includes_dir, '', $file->getPathname() ), '/' );
			$files[ $relative ] = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- this plugin's own local source.
		}

		return $files;
	}

	/**
	 * The production sweep: every banned call under the scanned set, outside the exempt file, must
	 * be listed in tests/Fixtures/meta-sweep-legacy.txt (path|function|callable|ordinal) - growth is
	 * banned, and a listed entry that no longer matches must be removed by the step that moved it.
	 * Both directions are checked: an unlisted violation fails the test, and so does a listed line
	 * the scan no longer sees.
	 */
	public function test_no_unlisted_violation_survives_under_the_scanned_set(): void {
		$legacy_path = AAFM_PLUGIN_DIR . 'tests/Fixtures/meta-sweep-legacy.txt';
		$this->assertFileExists( $legacy_path, 'the legacy list must exist, generated from the scanner\'s own first run.' );
		$legacy = array_filter( array_map( 'trim', file( $legacy_path ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$files = $this->scanned_files();
		$this->assertGreaterThan( 50, count( $files ), 'the sweep must actually walk the scanned set.' );

		$unlisted = array();
		$seen     = array_fill_keys( $legacy, false );
		foreach ( $files as $path => $source ) {
			foreach ( $this->violation_keys( $source, $path ) as $line_key ) {
				if ( array_key_exists( $line_key, $seen ) ) {
					$seen[ $line_key ] = true;
				} else {
					$unlisted[] = $line_key;
				}
			}
		}

		$this->assertSame(
			array(),
			$unlisted,
			"A banned call was found that the legacy list does not name (growth is banned):\n" . implode( "\n", $unlisted )
		);

		$stale = array_keys( array_filter( $seen, static fn( bool $was_seen ): bool => ! $was_seen ) );
		$this->assertSame(
			array(),
			$stale,
			"A legacy list entry no longer matches any violation; its site has moved, so this line must be deleted:\n" . implode( "\n", $stale )
		);
	}

	// --- Object loads checked by identity ----------------------------------

	/**
	 * Core functions that load a post, term, user or comment by id. Under a `query` filter that
	 * empties the load's SELECT they hand back another object's row, so every call goes through
	 * aafm_exact_object(), follows its own exact load (the precede wrappers), or is listed in
	 * tests/Fixtures/object-loader-legacy.txt.
	 */
	private const OBJECT_LOADERS = array( 'get_post', 'get_term', 'get_userdata', 'get_comment', 'get_post_type', 'get_post_field', 'get_term_by', 'get_user_by', 'get_post_thumbnail_id', 'wp_get_post_revision', 'wp_attachment_is_image', 'wp_get_nav_menu_object', 'term_is_ancestor_of', 'get_edit_term_link' );

	/**
	 * Loaders that load by id only when their first argument names an id field.
	 */
	private const BY_FIELD_LOADERS = array( 'get_term_by', 'get_user_by' );

	/**
	 * The field names that make get_term_by() and get_user_by() a load by id.
	 */
	private const ID_FIELDS = array( 'id', 'ID', 'term_id' );

	/**
	 * Wrappers whose call stays as written when an exact load of the same argument comes earlier in
	 * the same function, each mapped to that load's type. Core then reads the exact object from its
	 * cache.
	 */
	private const PRECEDE_WRAPPERS = array(
		'get_post_thumbnail_id'  => 'post',
		'wp_get_post_revision'   => 'post',
		'wp_attachment_is_image' => 'post',
		'wp_get_nav_menu_object' => 'term',
		'term_is_ancestor_of'    => 'term',
		'get_edit_term_link'     => 'term',
	);

	/**
	 * The helpers whose own loads are the checked ones.
	 */
	private const EXACT_LOAD_HELPERS = array( 'aafm_exact_object', 'aafm_comment_readback' );

	/**
	 * A call's top-level arguments, each as its token text with whitespace and comments removed, so
	 * two spellings of one argument compare equal.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens         token_get_all() output.
	 * @param int                                           $open_paren_idx Index of the opening '('.
	 * @return string[]
	 */
	private function call_arguments( array $tokens, int $open_paren_idx ): array {
		$close = $this->matching_bracket_index( $tokens, $open_paren_idx, '(', ')' );
		if ( null === $close ) {
			return array();
		}
		$args    = array();
		$current = '';
		$depth   = 0;
		for ( $k = $open_paren_idx + 1; $k < $close; $k++ ) {
			$token = $tokens[ $k ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$text = is_array( $token ) ? $token[1] : $token;
			if ( in_array( $text, array( '(', '[', '{', '${' ), true ) ) {
				++$depth;
			} elseif ( in_array( $text, array( ')', ']', '}' ), true ) ) {
				--$depth;
			} elseif ( 0 === $depth && ',' === $text ) {
				$args[]  = $current;
				$current = '';
				continue;
			}
			$current .= $text;
		}
		if ( '' !== $current ) {
			$args[] = $current;
		}
		return $args;
	}

	/**
	 * An argument's value when it is one quoted literal and nothing else, or null.
	 *
	 * @param string|null $arg Argument text from call_arguments().
	 */
	private function literal_argument( ?string $arg ): ?string {
		if ( null === $arg || ! preg_match( '/^[bB]?(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\$]|\\\\.)*")$/s', $arg ) ) {
			return null;
		}
		return $this->decode_string_literal( $arg );
	}

	/**
	 * Pair a precede wrapper's call with the nearest earlier unused exact load in the same function
	 * whose second argument is the wrapper's first. The load is used up either way; the pair holds
	 * only when the load's type is the wrapper's, and its taxonomy is 'nav_menu' for a menu, the
	 * wrapper's own third argument for term_is_ancestor_of(), and absent for get_edit_term_link().
	 *
	 * @param array<int,array{args:string[],used:bool}> $loads   The function's exact loads so far.
	 * @param string                                    $wrapper The wrapper's name.
	 * @param string[]                                  $args    The wrapper's arguments.
	 */
	private function consume_exact_load( array &$loads, string $wrapper, array $args ): bool {
		if ( ! isset( $args[0] ) ) {
			return false;
		}
		for ( $n = count( $loads ) - 1; $n >= 0; $n-- ) {
			if ( $loads[ $n ]['used'] || ( $loads[ $n ]['args'][1] ?? null ) !== $args[0] ) {
				continue;
			}
			$loads[ $n ]['used'] = true;
			$load                = $loads[ $n ]['args'];
			if ( self::PRECEDE_WRAPPERS[ $wrapper ] !== $this->literal_argument( $load[0] ?? null ) ) {
				return false;
			}
			if ( 'wp_get_nav_menu_object' === $wrapper ) {
				return 'nav_menu' === $this->literal_argument( $load[2] ?? null );
			}
			if ( 'term_is_ancestor_of' === $wrapper ) {
				return ( $load[2] ?? null ) === ( $args[2] ?? null );
			}
			if ( 'get_edit_term_link' === $wrapper ) {
				return ! isset( $load[2] );
			}
			return true;
		}
		return false;
	}

	/**
	 * Every raw object load's identity key (path|function|loader|ordinal). A load inside the exact
	 * helpers and a precede wrapper paired with its own exact load have no key.
	 *
	 * @param string $source       Full file contents.
	 * @param string $virtual_path Path the fixture pretends to live at.
	 * @return string[]
	 */
	private function object_load_keys( string $source, string $virtual_path ): array {
		$tokens      = token_get_all( $source );
		$name_types  = $this->name_token_types();
		$not_a_call  = array_merge( $this->operator_tokens(), array( T_DOUBLE_COLON, T_FUNCTION ) );
		$exact_loads = array();
		$ordinals    = array();
		$keys        = array();
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], $name_types, true ) ) {
				continue;
			}
			$name     = $this->last_name_segment( $token[1] );
			$is_exact = 'aafm_exact_object' === $name;
			if ( ! $is_exact && ! in_array( $name, self::OBJECT_LOADERS, true ) ) {
				continue;
			}
			list( $open, $open_idx ) = $this->significant_token( $tokens, $i + 1 );
			if ( '(' !== $open ) {
				continue;
			}
			$prev_idx = $this->previous_significant_index( $tokens, $i - 1 );
			if ( null !== $prev_idx && is_array( $tokens[ $prev_idx ] ) && in_array( $tokens[ $prev_idx ][0], $not_a_call, true ) ) {
				continue;
			}
			$function = $this->enclosing_function( $tokens, $i );
			$args     = $this->call_arguments( $tokens, $open_idx );
			if ( $is_exact ) {
				$exact_loads[ $function ][] = array(
					'args' => $args,
					'used' => false,
				);
				continue;
			}
			if ( in_array( $function, self::EXACT_LOAD_HELPERS, true ) ) {
				continue;
			}
			if ( in_array( $name, self::BY_FIELD_LOADERS, true ) ) {
				list( $field ) = $this->significant_token( $tokens, $open_idx + 1 );
				if ( ! is_array( $field ) || T_CONSTANT_ENCAPSED_STRING !== $field[0] || ! in_array( $this->decode_string_literal( $field[1] ), self::ID_FIELDS, true ) ) {
					continue;
				}
			}
			if ( isset( self::PRECEDE_WRAPPERS[ $name ] ) ) {
				$exact_loads[ $function ] = $exact_loads[ $function ] ?? array();
				if ( $this->consume_exact_load( $exact_loads[ $function ], $name, $args ) ) {
					continue;
				}
			}
			$ordinal_key              = $virtual_path . '|' . $function . '|' . $name;
			$ordinals[ $ordinal_key ] = ( $ordinals[ $ordinal_key ] ?? 0 ) + 1;
			$keys[]                   = $ordinal_key . '|' . $ordinals[ $ordinal_key ];
		}
		return $keys;
	}

	public function test_flags_a_raw_post_load(): void {
		$source = "<?php\nfunction f( \$id ) {\n\t\$post = get_post( \$id );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|get_post|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_fully_qualified_raw_load(): void {
		$source = "<?php\nfunction f( \$id ) {\n\t\$post = \\get_post( \$id );\n\t\$term = \\get_term( \$id );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|get_post|1', 'includes/fixture.php|f|get_term|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_each_raw_load_of_one_loader_with_its_own_ordinal(): void {
		$source = "<?php\nfunction f( \$a, \$b ) {\n\tget_userdata( \$a );\n\tget_userdata( \$b );\n\tget_comment( \$a );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|get_userdata|1', 'includes/fixture.php|f|get_userdata|2', 'includes/fixture.php|f|get_comment|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_the_field_wrappers_that_load_by_id(): void {
		$source = "<?php\nfunction f( \$id ) {\n\tget_post_type( \$id );\n\tget_post_field( 'post_content', \$id, 'raw' );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|get_post_type|1', 'includes/fixture.php|f|get_post_field|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_get_term_by_and_get_user_by_only_by_id(): void {
		$source = "<?php\nfunction f( \$id, \$tax ) {\n\tget_term_by( 'id', \$id, \$tax );\n\tget_term_by( 'term_id', \$id, \$tax );\n\tget_user_by( 'ID', \$id );\n\tget_term_by( 'name', \$id, \$tax );\n\tget_term_by( 'slug', \$id, \$tax );\n\tget_user_by( 'email', \$id );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|get_term_by|1', 'includes/fixture.php|f|get_term_by|2', 'includes/fixture.php|f|get_user_by|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_a_method_a_static_call_and_a_declaration_named_like_a_loader(): void {
		$source = "<?php\nfunction get_comment_x() {}\nfunction f( \$repo, \$id ) {\n\t\$repo->get_post( \$id );\n\t\$repo?->get_term( \$id );\n\tRepo::get_userdata( \$id );\n}\n";
		$this->assertSame( array(), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_ignores_the_loads_inside_the_exact_helpers(): void {
		$source = "<?php\nfunction aafm_exact_object( \$type, \$id ) {\n\treturn get_post( \$id );\n}\nfunction aafm_comment_readback( \$id ) {\n\treturn get_comment( \$id );\n}\n";
		$this->assertSame( array(), $this->object_load_keys( $source, 'includes/write-contract.php' ) );
	}

	public function test_a_precede_wrapper_after_its_own_exact_load_is_paired(): void {
		$source = "<?php\nfunction f( \$menu_id, \$post_id, \$id ) {\n\tif ( ! aafm_exact_object( 'term', \$menu_id, 'nav_menu' ) instanceof WP_Term ) {\n\t\treturn;\n\t}\n\twp_get_nav_menu_object( \$menu_id );\n\tif ( aafm_exact_object( 'post', \$post_id ) instanceof WP_Post ) {\n\t\tget_post_thumbnail_id( \$post_id );\n\t}\n\t\$url = aafm_exact_object( 'term', \$id ) instanceof WP_Term ? get_edit_term_link( \$id ) : null;\n}\n";
		$this->assertSame( array(), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_precede_wrapper_with_no_exact_load(): void {
		$source = "<?php\nfunction f( \$x ) {\n\twp_get_nav_menu_object( \$x );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|wp_get_nav_menu_object|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_precede_wrapper_whose_exact_load_names_another_argument(): void {
		$source = "<?php\nfunction f( \$x, \$y ) {\n\taafm_exact_object( 'term', \$y, 'nav_menu' );\n\twp_get_nav_menu_object( \$x );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|wp_get_nav_menu_object|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_second_precede_wrapper_call_after_one_exact_load(): void {
		$source = "<?php\nfunction f( \$id ) {\n\taafm_exact_object( 'term', \$id );\n\tget_edit_term_link( \$id );\n\tget_edit_term_link( \$id );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|get_edit_term_link|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_menu_load_preceded_by_a_post_load(): void {
		$source = "<?php\nfunction f( \$menu_id ) {\n\taafm_exact_object( 'post', \$menu_id );\n\twp_get_nav_menu_object( \$menu_id );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|wp_get_nav_menu_object|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_post_wrapper_preceded_by_a_term_load(): void {
		$source = "<?php\nfunction f( \$id ) {\n\taafm_exact_object( 'term', \$id );\n\tget_post_thumbnail_id( \$id );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|get_post_thumbnail_id|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_menu_load_preceded_by_an_untyped_term_load(): void {
		$source = "<?php\nfunction f( \$menu_id ) {\n\taafm_exact_object( 'term', \$menu_id );\n\twp_get_nav_menu_object( \$menu_id );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|wp_get_nav_menu_object|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_an_ancestor_check_pairs_only_with_a_load_under_its_own_taxonomy(): void {
		$paired   = "<?php\nfunction f( \$id, \$parent, \$taxonomy ) {\n\taafm_exact_object( 'term', \$id, \$taxonomy );\n\tterm_is_ancestor_of( \$id, \$parent, \$taxonomy );\n}\n";
		$unpaired = "<?php\nfunction f( \$id, \$parent, \$taxonomy ) {\n\taafm_exact_object( 'term', \$id );\n\tterm_is_ancestor_of( \$id, \$parent, \$taxonomy );\n}\n";
		$this->assertSame( array(), $this->object_load_keys( $paired, 'includes/fixture.php' ) );
		$this->assertSame( array( 'includes/fixture.php|f|term_is_ancestor_of|1' ), $this->object_load_keys( $unpaired, 'includes/fixture.php' ) );
	}

	public function test_flags_an_edit_term_link_preceded_by_a_load_under_a_taxonomy(): void {
		$source = "<?php\nfunction f( \$id ) {\n\taafm_exact_object( 'term', \$id, 'category' );\n\tget_edit_term_link( \$id );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|get_edit_term_link|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_precede_wrapper_whose_exact_load_comes_after_it(): void {
		$source = "<?php\nfunction f( \$id ) {\n\twp_get_post_revision( \$id );\n\taafm_exact_object( 'post', \$id );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|wp_get_post_revision|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_a_precede_wrapper_whose_exact_load_is_in_another_function(): void {
		$source = "<?php\nfunction g( \$id ) {\n\taafm_exact_object( 'post', \$id );\n}\nfunction f( \$id ) {\n\twp_attachment_is_image( \$id );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|wp_attachment_is_image|1' ), $this->object_load_keys( $source, 'includes/fixture.php' ) );
	}

	/**
	 * The production sweep for object loads: every raw load under the scanned set that is neither
	 * inside the exact helpers nor paired with its own exact load must be listed in
	 * tests/Fixtures/object-loader-legacy.txt (path|function|loader|ordinal). The list may only
	 * shrink: an unlisted load fails, and so does a listed line the scan no longer sees.
	 */
	public function test_no_unlisted_object_load_survives_under_the_scanned_set(): void {
		$legacy_path = AAFM_PLUGIN_DIR . 'tests/Fixtures/object-loader-legacy.txt';
		$this->assertFileExists( $legacy_path, 'the object-loader list must exist, generated from the scanner\'s own first run.' );
		$legacy = array_filter( array_map( 'trim', file( $legacy_path ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$files = $this->scanned_files();
		$this->assertGreaterThan( 50, count( $files ), 'the sweep must actually walk the scanned set.' );

		$unlisted = array();
		$seen     = array_fill_keys( $legacy, false );
		foreach ( $files as $path => $source ) {
			foreach ( $this->object_load_keys( $source, $path ) as $line_key ) {
				if ( array_key_exists( $line_key, $seen ) ) {
					$seen[ $line_key ] = true;
				} else {
					$unlisted[] = $line_key;
				}
			}
		}

		$this->assertSame(
			array(),
			$unlisted,
			"An object load was found that is not checked by id and that the list does not name:\n" . implode( "\n", $unlisted )
		);

		$stale = array_keys( array_filter( $seen, static fn( bool $was_seen ): bool => ! $was_seen ) );
		$this->assertSame(
			array(),
			$stale,
			"A list entry no longer matches any object load; its site has moved, so this line must be deleted:\n" . implode( "\n", $stale )
		);
	}

	// --- Metadata writer errors ---------------------------------------------

	/**
	 * The metadata writers whose statuses a caller's error reports.
	 */
	private const META_WRITERS = array( 'aafm_meta_set', 'aafm_meta_delete', 'aafm_meta_set_group' );

	/**
	 * Every `new WP_Error(` that follows a metadata writer call in the same named function and
	 * does not carry aafm_meta_write_error()'s error_data in its own arguments, either by calling it
	 * there or through `$v->get_error_data()` on a variable assigned from it earlier in the
	 * function, keyed path|function|ordinal. Such an error reports a writer status without the four
	 * error_data keys that writer's errors carry.
	 *
	 * @param string $source       Full file contents.
	 * @param string $virtual_path Path the fixture pretends to live at.
	 * @return string[]
	 */
	private function meta_writer_error_keys( string $source, string $virtual_path ): array {
		$tokens     = token_get_all( $source );
		$name_types = $this->name_token_types();
		$not_a_call = array_merge( $this->operator_tokens(), array( T_DOUBLE_COLON, T_FUNCTION ) );
		$writer_at  = array();
		$error_vars = array();
		$ordinals   = array();
		$keys       = array();
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], $name_types, true ) ) {
				continue;
			}
			$name = $this->last_name_segment( $token[1] );
			if ( in_array( $name, self::META_WRITERS, true ) ) {
				list( $open ) = $this->significant_token( $tokens, $i + 1 );
				$prev_idx     = $this->previous_significant_index( $tokens, $i - 1 );
				if ( '(' === $open && ! ( null !== $prev_idx && is_array( $tokens[ $prev_idx ] ) && in_array( $tokens[ $prev_idx ][0], $not_a_call, true ) ) ) {
					$writer_at[ $this->enclosing_function( $tokens, $i ) ] = true;
				}
				continue;
			}
			if ( 'aafm_meta_write_error' === $name ) {
				$assign_idx = $this->previous_significant_index( $tokens, $i - 1 );
				$var_idx    = null === $assign_idx ? null : $this->previous_significant_index( $tokens, $assign_idx - 1 );
				if ( '=' === ( $tokens[ $assign_idx ] ?? null ) && null !== $var_idx && is_array( $tokens[ $var_idx ] ) && T_VARIABLE === $tokens[ $var_idx ][0] ) {
					$error_vars[ $this->enclosing_function( $tokens, $i ) ][] = $tokens[ $var_idx ][1];
				}
				continue;
			}
			if ( 'WP_Error' !== $name ) {
				continue;
			}
			$prev_idx = $this->previous_significant_index( $tokens, $i - 1 );
			if ( null === $prev_idx || ! is_array( $tokens[ $prev_idx ] ) || T_NEW !== $tokens[ $prev_idx ][0] ) {
				continue;
			}
			$function = $this->enclosing_function( $tokens, $i );
			if ( '{main}' === $function || empty( $writer_at[ $function ] ) ) {
				continue;
			}
			list( $open, $open_idx ) = $this->significant_token( $tokens, $i + 1 );
			$args                    = '(' === $open ? $this->call_args_text( $tokens, $open_idx ) : '';
			if ( preg_match( '/(^|[^a-zA-Z0-9_>:])aafm_meta_write_error\s*\(/', $args ) ) {
				continue;
			}
			foreach ( $error_vars[ $function ] ?? array() as $var ) {
				if ( preg_match( '/' . preg_quote( $var, '/' ) . '\s*->\s*get_error_data\s*\(/', $args ) ) {
					continue 2;
				}
			}
			$ordinal_key              = $virtual_path . '|' . $function;
			$ordinals[ $ordinal_key ] = ( $ordinals[ $ordinal_key ] ?? 0 ) + 1;
			$keys[]                   = $ordinal_key . '|' . $ordinals[ $ordinal_key ];
		}
		return $keys;
	}

	public function test_flags_an_error_after_a_metadata_write_without_the_writer_error_data(): void {
		$source = "<?php\nfunction f( \$id ) {\n\t\$r = aafm_meta_delete( 'post', \$id, 'k' );\n\treturn new WP_Error( 'x', 'y' );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|1' ), $this->meta_writer_error_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_passes_an_error_after_a_metadata_write_that_carries_the_writer_error_data(): void {
		$source = "<?php\nfunction f( \$id ) {\n\t\$r = aafm_meta_set( 'post', \$id, 'k', 'v' );\n\treturn new WP_Error( 'x', 'y', aafm_meta_write_error( \$r['status'], 'write', 'post', \$id, 'k' )->get_error_data() );\n}\n";
		$this->assertSame( array(), $this->meta_writer_error_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_passes_an_error_carrying_the_error_data_of_a_writer_error_built_first(): void {
		$source = "<?php\nfunction f( \$id ) {\n\t\$r = aafm_meta_set( 'post', \$id, 'k', 'v' );\n\t\$error = aafm_meta_write_error( \$r['status'], 'write', 'post', \$id, 'k' );\n\treturn new WP_Error( 'x', \$error->get_error_message(), \$error->get_error_data() );\n}\n";
		$this->assertSame( array(), $this->meta_writer_error_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_flags_an_error_carrying_the_error_data_of_some_other_error(): void {
		$source = "<?php\nfunction f( \$id ) {\n\t\$r = aafm_meta_set( 'post', \$id, 'k', 'v' );\n\t\$error = aafm_meta_write_error( \$r['status'], 'write', 'post', \$id, 'k' );\n\t\$other = new WP_Error( 'a', 'b' );\n\treturn new WP_Error( 'x', 'y', \$other->get_error_data() );\n}\n";
		$this->assertSame( array( 'includes/fixture.php|f|1', 'includes/fixture.php|f|2' ), $this->meta_writer_error_keys( $source, 'includes/fixture.php' ) );
	}

	public function test_passes_an_error_before_the_first_metadata_write(): void {
		$source = "<?php\nfunction f( \$id ) {\n\tif ( ! \$id ) {\n\t\treturn new WP_Error( 'x', 'y' );\n\t}\n\treturn aafm_meta_set_group( 'post', \$id, array() );\n}\n";
		$this->assertSame( array(), $this->meta_writer_error_keys( $source, 'includes/fixture.php' ) );
	}

	/**
	 * Under the scanned set, an error returned after a metadata write carries the writer's own
	 * error_data (status, kind, object_id, key) through aafm_meta_write_error().
	 */
	public function test_every_error_after_a_metadata_write_carries_the_writer_error_data(): void {
		$files = $this->scanned_files();
		$this->assertGreaterThan( 50, count( $files ), 'the sweep must actually walk the scanned set.' );

		$found = array();
		foreach ( $files as $path => $source ) {
			foreach ( $this->meta_writer_error_keys( $source, $path ) as $key ) {
				$found[] = $key;
			}
		}

		$this->assertSame( array(), $found, "An error after a metadata write is missing the writer's error_data:\n" . implode( "\n", $found ) );
	}
}
