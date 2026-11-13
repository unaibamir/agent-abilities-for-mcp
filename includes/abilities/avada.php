<?php
/**
 * Avada / Fusion Builder integration: a read ability confirming Fusion Builder ownership and
 * returning the raw shortcode markup unchanged, plus a guarded write ability that allows a
 * text-only edit inside Fusion Builder content without disturbing the shortcode tree.
 *
 * Registers ONLY when Avada is active (aafm_integration_active('avada')). Avada is a theme, not
 * a plugin, and Fusion Builder content lives in ordinary post_content as shortcodes - both
 * abilities gate on the same aafm_can_edit_post_object() every other content ability uses, since
 * an Avada page is an ordinary post/page post type, not a distinct capability family.
 *
 * The ownership marker and the shortcode tag names were verified (2026-09-05) against a real
 * installed copy (Avada 7.16.1, Fusion Builder 3.16.1) - see the docblock on
 * aafm_post_has_foreign_builder_ownership() (includes/page-builder-guard.php) for the marker
 * citation. 228-avada-guard-design.md, written without a live install, could not verify either
 * fact and left both open; both are settled here rather than re-opened at Task 21.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_avada_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_avada_full_definitions' );

/**
 * Contribute Avada ability definitions to the registry, only while Avada is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_avada_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'avada' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_avada_registry_definitions() );
}

/**
 * Contribute Avada ability definitions to the host-independent full registry (used by the
 * Integrations tab and the manifest, regardless of whether Avada is actually active here).
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_avada_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_avada_registry_definitions() );
}

/**
 * The Avada ability rows, shared by the guarded and full registries.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_avada_registry_definitions(): array {
	return array(
		'aafm/avada-get-page-content' => array(
			'label'        => __( 'Get Avada page content', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads the raw post content of an Avada/Fusion Builder page, unchanged. Fusion Builder shortcodes are returned exactly as stored, never rendered or stripped. Also returns is_avada_owned, true when Avada owns the content. With include_structure it also returns an outline of the Fusion Builder elements (tag, nesting depth, attributes, and the library item id of a global element) to find the element that holds the text before calling avada-replace-text. Requires edit access to the post.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_get_page_content',
		),
		'aafm/avada-replace-text'     => array(
			'label'        => __( 'Replace text in an Avada page', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Replaces literal text within an Avada/Fusion Builder page while requiring the Fusion shortcode structure and every attribute value to stay the same before and after (quoting style and whitespace inside a tag are not part of this guarantee). A replacement that would touch a shortcode tag, its self-closing form, or an attribute is refused rather than risk breaking the layout. Requires edit access to the post.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_replace_text',
		),
		'aafm/avada-list-layouts'     => array(
			'label'        => __( 'List Avada layouts', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists Avada Layouts in the order Avada checks them, with the Global Layout last. Each layout has its sections (header, page title bar, content, footer) with each section\'s id, title, status and whether it exists, and its conditions exactly as Avada stores them. Conditions are not evaluated, so this does not say which layout a given page gets. A section that is not published is not used. A layout whose stored data Avada itself cannot read is marked unparseable. global_stored is false when no Global Layout was ever saved, and more than 200 layouts are cut to the first 200. Requires the manage_options capability, even where Avada\'s Role Manager opens Layouts to other roles. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_list_layouts',
		),
	);
}

/**
 * Shared per-object gate for both Avada abilities: ordinary edit access to the target post,
 * exactly like aafm/replace-in-post's aafm_perm_replace_in_post() - Avada content is not a
 * distinct capability family, per this task's own research.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_avada_post_object( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? aafm_exact_object( 'post', $id ) : null;
	return $post instanceof WP_Post && aafm_can_edit_post_object( $post );
}

/**
 * Args for aafm/avada-get-page-content.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_get_page_content(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-get-page-content' ),
		'description'         => aafm_ability_description( 'aafm/avada-get-page-content' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'           => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post/page to read. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
				),
				'include_structure' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'When true, also returns structure: the Fusion Builder elements in document order with their nesting depth and attributes, and structure_status. Use it to find the element that holds the text you want to change before calling avada-replace-text.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'          => array( 'type' => 'integer' ),
				'content'          => array( 'type' => 'string' ),
				'is_avada_owned'   => array( 'type' => 'boolean' ),
				'structure_status' => array(
					'type'        => 'string',
					'enum'        => array( 'ok', 'unparseable', 'truncated' ),
					'description' => __( 'Only with include_structure. ok: the outline is complete. unparseable: the shortcodes could not be read safely (avada-replace-text refuses this page too) and no structure is returned. truncated: only the first 2,000 elements are listed.', 'agent-abilities-for-mcp' ),
				),
				'structure'        => array(
					'type'        => 'array',
					'description' => __( 'Only with include_structure, when structure_status is ok or truncated. A global_id means the element shows a library item: its text lives in that item, not on this page.', 'agent-abilities-for-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'index'        => array( 'type' => 'integer' ),
							'depth'        => array( 'type' => 'integer' ),
							'tag'          => array( 'type' => 'string' ),
							'self_closing' => array( 'type' => 'boolean' ),
							'has_closer'   => array( 'type' => 'boolean' ),
							'atts'         => array( 'type' => 'object' ),
							'global_id'    => array( 'type' => 'integer' ),
						),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_get_page_content',
		'permission_callback' => 'aafm_perm_avada_post_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/avada-get-page-content: raw post_content, unrendered, plus an explicit ownership
 * flag so a caller does not have to guess whether the returned shortcode markup is real Fusion
 * Builder content.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_get_page_content( array $input ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}

	$out = array(
		'post_id'        => $id,
		'content'        => (string) $post->post_content,
		'is_avada_owned' => 'avada' === aafm_post_has_foreign_builder_ownership( $id, true ),
	);
	if ( ! empty( $input['include_structure'] ) ) {
		$out = array_merge( $out, aafm_avada_structure( (string) $post->post_content ) );
	}
	return $out;
}

/**
 * The element outline for include_structure, built from aafm_fusion_shortcode_walk() itself so it
 * sees exactly what avada-replace-text guards. The walker's tuples are left as they are (the
 * structure check compares them for equality); index and global_id are added here.
 *
 * @param string $content Post content.
 * @return array{structure_status:string,structure?:list<array<string,mixed>>}
 */
function aafm_avada_structure( string $content ): array {
	$walk = aafm_fusion_shortcode_walk( $content, 0 );
	if ( null === $walk ) {
		return array( 'structure_status' => 'unparseable' );
	}
	$max   = 2000;
	$items = array();
	foreach ( array_slice( $walk, 0, $max ) as $index => $element ) {
		$item      = array(
			'index'        => $index,
			'depth'        => $element['depth'],
			'tag'          => $element['tag'],
			'self_closing' => $element['self_closing'],
			'has_closer'   => $element['has_closer'],
			'atts'         => (object) $element['atts'],
		);
		$global_id = aafm_avada_global_id( $element['tag'], $element['atts'] );
		if ( null !== $global_id ) {
			$item['global_id'] = $global_id;
		}
		$items[] = $item;
	}
	return array(
		'structure_status' => count( $walk ) > $max ? 'truncated' : 'ok',
		'structure'        => $items,
	);
}

/**
 * The library item an element shows, the two ways Avada writes it: a [fusion_global id="12"]
 * element, or an element that carries a fusion_global="12" attribute (fusion-builder
 * shortcodes/fusion-global.php, recursively_add_global_elements()).
 *
 * @param string              $tag  Shortcode tag.
 * @param array<string,mixed> $atts Parsed attributes.
 * @return int|null The item's post id, or null when the element is not global.
 */
function aafm_avada_global_id( string $tag, array $atts ): ?int {
	$raw = 'fusion_global' === $tag ? ( $atts['id'] ?? null ) : ( $atts['fusion_global'] ?? null );
	return is_string( $raw ) && 1 === preg_match( '/^[1-9][0-9]*$/', $raw ) ? (int) $raw : null;
}

/**
 * Args for aafm/avada-replace-text.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_replace_text(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-replace-text' ),
		'description'         => aafm_ability_description( 'aafm/avada-replace-text' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post to edit.', 'agent-abilities-for-mcp' ),
				),
				'search'  => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Literal text to find in the post content. Matched as-is, not as a regular expression.', 'agent-abilities-for-mcp' ),
				),
				'replace' => array(
					'type'        => 'string',
					'description' => __( 'Literal text to substitute for every match of search. Sanitized the same way as a full content update.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id', 'search', 'replace' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post'         => array( 'type' => 'object' ),
				'replacements' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_replace_text',
		'permission_callback' => 'aafm_perm_avada_post_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * The Fusion shortcode tags this guard recognizes.
 *
 * A fixed 7-tag list misses every OTHER real Fusion Builder element
 * (fusion_button, fusion_alert, fusion_imageframe, the awb_* family, and dozens more) - an edit
 * inside an unrecognized shortcode is invisible to this guard entirely, since only recognized
 * tags contribute a tuple to the signature at all. Fixed by reading the REAL, complete set of
 * registered shortcodes off WordPress core's own global registry (populated by Fusion Builder's
 * own add_shortcode() calls when the theme/plugin is active) and filtering to the fusion_ and
 * awb_ prefixed families - self-updating against whatever this specific site's Fusion Builder version actually
 * registered, rather than a hand-maintained snapshot. The small hardcoded baseline stays as a
 * fallback merged in: PHPUnit never loads the real plugin, so $shortcode_tags carries none of
 * these names in tests, and a genuinely blank registry (e.g. Fusion Builder not yet fully loaded)
 * must not silently reduce this guard's own test coverage to zero.
 *
 * @return string[]
 */
function aafm_fusion_shortcode_tags(): array {
	global $shortcode_tags;

	$baseline = array(
		'fusion_builder_container',
		'fusion_builder_row',
		'fusion_builder_row_inner',
		'fusion_builder_column',
		'fusion_builder_column_inner',
		'fusion_separator',
		'fusion_text',
	);

	$discovered = array();
	foreach ( array_keys( (array) $shortcode_tags ) as $tag ) {
		if ( 0 === strpos( (string) $tag, 'fusion_' ) || 0 === strpos( (string) $tag, 'awb_' ) ) {
			$discovered[] = (string) $tag;
		}
	}

	return array_values( array_unique( array_merge( $baseline, $discovered ) ) );
}

/**
 * Whether every quote character in a shortcode's raw attribute string that actually opens a
 * quoted value also closes it - tracking which quote character is the ACTIVE delimiter, not
 * just counting '"' and "'" independently.
 *
 * A quote of the OTHER kind encountered while already inside a quoted value (title="Bob's
 * title") is ordinary content, not a second delimiter, and must not count against the balance -
 * see aafm_fusion_shortcode_walk()'s own docblock for why an unclosed delimiter (the literal-']'
 * trap) must still fail closed.
 *
 * @param string $attribute_str Raw attribute string as captured by get_shortcode_regex().
 * @return bool True when no quoted value was left open at the end of the string.
 */
function aafm_fusion_attribute_quotes_balanced( string $attribute_str ): bool {
	$active_delimiter = null;
	$length           = strlen( $attribute_str );
	for ( $i = 0; $i < $length; $i++ ) {
		$char = $attribute_str[ $i ];
		if ( null === $active_delimiter ) {
			if ( '"' === $char || "'" === $char ) {
				$active_delimiter = $char;
			}
		} elseif ( $char === $active_delimiter ) {
			$active_delimiter = null;
		}
	}
	return null === $active_delimiter;
}

/**
 * Build a structural signature of every Fusion shortcode in $content: an ordered list of
 * (tag, self_closing, atts, depth) tuples, walked recursively.
 *
 * WordPress's get_shortcode_regex() matches a whole "[tag]...[/tag]" span - INCLUDING any shortcodes nested
 * inside it - as a single capture (group 5 is the entire inner content, unparsed); it does not
 * emit separate open/close events the way an HTML tokenizer does. So nesting is only discoverable
 * by re-scanning each match's own inner content one level deeper, exactly how do_shortcode_tag()
 * itself recurses into a shortcode's children at render time. WordPress's own regex is not
 * guaranteed to resolve a shortcode nested inside ANOTHER instance of the identical tag name
 * (Fusion Builder avoids that specific case with distinct "_inner" tag names - see
 * aafm_fusion_shortcode_tags()); this walk inherits that same substrate limitation, the same
 * limitation do_shortcode() itself has, rather than introducing a new one.
 *
 * Both closed here:
 * - A tuple now records whether a real closing tag was actually matched (`has_closer`), not just
 *   the tag/self_closing/atts/depth. Without it, `[fusion_text]Hello[/fusion_text]` and a version
 *   with the closing tag stripped produce IDENTICAL tuples - the "content+closer" span in
 *   get_shortcode_regex() is entirely OPTIONAL, so an absent closer still yields a match, just
 *   with an empty (rather than genuinely absent) inner-content capture, and nothing before this
 *   fix distinguished the two.
 * - An attribute string with an unclosed quoted value (tracked by
 *   aafm_fusion_attribute_quotes_balanced(), which follows which quote character is actually the
 *   ACTIVE delimiter rather than counting `"` and `'` independently - a plain apostrophe inside a
 *   double-quoted value, title="Bob's title", is ordinary content, not a second delimiter) means
 *   WordPress's own regex mis-parsed the match - the classic trap is a literal `]` inside a
 *   quoted attribute value, which truncates the captured attribute span mid-quote (confirmed:
 *   `content="a[1]"` captures only `content="a[1`, an unterminated quote). This function does not
 *   attempt to recover the real boundary; it fails closed (null) instead, per the design's own
 *   fail-closed rule, rather than silently exposing a "safe-looking" span that is not
 *   actually protecting the real boundary WordPress's own parser lost track of.
 *
 * @param string $content Content to scan at this nesting level.
 * @param int    $depth   Current nesting depth (0 = top level).
 * @return array<int,array{tag:string,self_closing:bool,has_closer:bool,atts:array<string,mixed>,depth:int}>|null Null means "could not be parsed" - the caller must refuse rather than assume safety.
 */
function aafm_fusion_shortcode_walk( string $content, int $depth ): ?array {
	$pattern = '/' . get_shortcode_regex( aafm_fusion_shortcode_tags() ) . '/';
	$matched = preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER );
	if ( false === $matched ) {
		return null; // Regex engine error (e.g. a backtrack-limit trip on pathological input).
	}

	$signature = array();
	foreach ( $matches as $match ) {
		$tag           = (string) $match[2];
		$attribute_str = (string) $match[3];
		$self_closing  = '/' === ( $match[4] ?? '' );
		$inner         = (string) ( $match[5] ?? '' );

		// A quote that opens but never closes means get_shortcode_regex()'s attribute-span
		// capture ran off the rails (the literal-']'-in-a-quoted-value trap) - refuse rather than
		// trust a span that does not cover what it looks like it covers.
		//
		// Counting each quote character independently (an odd count
		// of '"' OR an odd count of "'") false-positives on a perfectly ordinary attribute like
		// title="Bob's title" - one apostrophe INSIDE a double-quoted value is not a delimiter at
		// all, just a literal character, but the old count-parity check could not tell the two
		// apart. Track which quote character is actually the ACTIVE delimiter instead: a quote of
		// the other kind encountered while already inside a quoted value is ordinary content, not
		// a second delimiter.
		if ( ! aafm_fusion_attribute_quotes_balanced( $attribute_str ) ) {
			return null;
		}

		$atts = shortcode_parse_atts( $attribute_str );
		$atts = is_array( $atts ) ? $atts : array();

		$has_closer = $self_closing || (bool) preg_match( '/\[\/' . preg_quote( $tag, '/' ) . '\]$/', $match[0] );

		$signature[] = array(
			'tag'          => $tag,
			'self_closing' => $self_closing,
			'has_closer'   => $has_closer,
			'atts'         => $atts,
			'depth'        => $depth,
		);

		if ( ! $self_closing && '' !== $inner ) {
			$child = aafm_fusion_shortcode_walk( $inner, $depth + 1 );
			if ( null === $child ) {
				return null;
			}
			$signature = array_merge( $signature, $child );
		}
	}

	return $signature;
}

/**
 * Whether a text replacement preserves the Fusion shortcode structure: the same ordered
 * (tag, self_closing, atts, depth) tuples before and after. A replacement that lands inside a
 * shortcode tag, its self-closing form, or an attribute value changes its own tuple and is
 * refused by this same equality check - there is no separate "attribute zone" branch to write,
 * mirroring how aafm_replacement_preserves_structure() needs no bespoke HTML-attribute case
 * either. Either side failing to parse (null) refuses the write, per
 * 228-avada-guard-design.md §2 step 4's fail-closed rule.
 *
 * 'atts' is shortcode_parse_atts()'s output, a normalized associative array -
 * it discards the original quoting style and interior whitespace entirely, so two attribute
 * strings differing only in quote character or whitespace (or a duplicate attribute that
 * shadows an earlier one) parse to the identical array and pass this check unchanged. This
 * compares structure and attribute VALUES, not the raw bytes inside a tag - not the
 * byte-identical guarantee the disclosure used to claim.
 *
 * @param string $before Content before the replacement.
 * @param string $after  Content after the replacement.
 * @return bool
 */
function aafm_fusion_shortcode_structure_preserved( string $before, string $after ): bool {
	$original = aafm_fusion_shortcode_walk( $before, 0 );
	$result   = aafm_fusion_shortcode_walk( $after, 0 );

	return null !== $original && null !== $result && $original === $result;
}

/**
 * Execute aafm/avada-replace-text. Mirrors aafm_exec_replace_in_post()'s shape exactly, but
 * refuses via aafm_fusion_shortcode_structure_preserved() instead of the HTML-tokenizer guard -
 * Fusion Builder content is shortcodes, not the HTML structure that guard understands.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_replace_text( array $input ) {
	$id   = absint( $input['post_id'] );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// This ability's whole reason to exist is a FINER write path for
	// Avada-owned content specifically - without this check it could edit an Elementor/Divi/
	// Beaver-Builder-owned post (which carries no Fusion shortcodes at all, so both structural
	// signatures come back empty and equal) even though the generic write path
	// (aafm_exec_replace_in_post()) explicitly refuses those same posts. Require genuine,
	// verified Avada ownership, not merely "no foreign builder marker at all".
	if ( 'avada' !== aafm_post_has_foreign_builder_ownership( $id ) ) {
		return new WP_Error(
			'aafm_not_avada_owned',
			__( 'This post is not owned by Avada/Fusion Builder.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}

	$search  = (string) $input['search'];
	$replace = (string) $input['replace'];
	$content = (string) $post->post_content;

	$replacements = substr_count( $content, $search );
	if ( 0 === $replacements ) {
		return array(
			'post'         => aafm_redact_post( $post ),
			'replacements' => 0,
		);
	}

	$inserted = wp_kses_post( $replace );
	$new      = str_replace( $search, $inserted, $content );

	if ( ! aafm_fusion_shortcode_structure_preserved( $content, $new ) ) {
		return new WP_Error(
			'aafm_replace_inside_shortcode',
			__( 'That replacement would change the Fusion Builder shortcode structure rather than its text. Choose a search term that appears in plain text between or inside elements, not one that would alter a shortcode tag, its self-closing form, or an attribute. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}

	$updated = wp_update_post(
		wp_slash(
			array(
				'ID'           => $id,
				'post_content' => $new,
			)
		),
		true
	);
	if ( is_wp_error( $updated ) ) {
		return aafm_generic_error();
	}

	$fresh = aafm_exact_object( 'post', $id );
	// $replacements was computed from the ORIGINAL content before
	// wp_update_post() ever ran, and never re-validated against what actually landed in
	// storage - a wp_insert_post_data (or similar) filter revising the content on save would
	// report a count and structure that no longer matched what was stored. Confirm the exact
	// intended content actually landed before reporting success. Compare
	// against the CANONICAL sanitize_post_field() form, not $new itself, so a legitimate
	// normalization is not mistaken for a veto.
	if ( ! $fresh instanceof WP_Post || ! aafm_post_field_confirm_logged( $id, 'post_content', $new, $content ) ) {
		return new WP_Error(
			'aafm_avada_write_unconfirmed',
			__( 'The replacement could not be confirmed as saved.', 'agent-abilities-for-mcp' )
		);
	}

	return array(
		'post'         => aafm_redact_post( $fresh ),
		'replacements' => $replacements,
	);
}

/**
 * Args for aafm/avada-list-layouts.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_list_layouts(): array {
	$section = array(
		'type'       => 'object',
		'properties' => array(
			'id'     => array( 'type' => 'integer' ),
			'exists' => array( 'type' => 'boolean' ),
			'title'  => array( 'type' => 'string' ),
			'status' => array( 'type' => 'string' ),
		),
	);
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-list-layouts' ),
		'description'         => aafm_ability_description( 'aafm/avada-list-layouts' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'include_conditions' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'When false, each layout is returned without its conditions.', 'agent-abilities-for-mcp' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'layouts'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'           => array(
								'type'        => array( 'integer', 'string' ),
								'description' => __( 'The layout post id, or "global" for the Global Layout.', 'agent-abilities-for-mcp' ),
							),
							'title'        => array( 'type' => 'string' ),
							'status'       => array( 'type' => 'string' ),
							'order'        => array(
								'type'        => 'integer',
								'description' => __( 'Position in the order Avada checks layouts, from 0. The first layout whose conditions match is used.', 'agent-abilities-for-mcp' ),
							),
							'parse_status' => array(
								'type'        => 'string',
								'enum'        => array( 'ok', 'unparseable' ),
								'description' => __( 'unparseable: Avada cannot read this layout\'s stored data, so no sections or conditions are returned for it.', 'agent-abilities-for-mcp' ),
							),
							'sections'     => array(
								'type'                 => 'object',
								'description'          => __( 'Keyed by section type (header, page_title_bar, content, footer). Only when parse_status is ok.', 'agent-abilities-for-mcp' ),
								'additionalProperties' => $section,
							),
							'conditions'   => array(
								'type'        => 'array',
								'description' => __( 'The stored conditions, unchanged: mode (include or exclude), type, the value under the key named by type, and parent for a child condition. Only when parse_status is ok and include_conditions is true.', 'agent-abilities-for-mcp' ),
								'items'       => array( 'type' => 'object' ),
							),
						),
					),
				),
				'global_stored' => array( 'type' => 'boolean' ),
				'truncated'     => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_list_layouts',
		'permission_callback' => 'aafm_perm_manage_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Execute aafm/avada-list-layouts. Reads the layout posts, the layout order and the Global Layout
 * option the way Avada Builder's Fusion_Template_Builder does, without calling Avada (its query
 * helper caches), and decodes each one with Avada's own expression, since that decides what renders.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_avada_list_layouts( array $input ): array {
	$with_conditions = ! array_key_exists( 'include_conditions', $input ) || ! empty( $input['include_conditions'] );

	// Fusion_Template_Builder::get_registered_layouts_posts(): every status but trash and auto-draft.
	$query = new WP_Query(
		array(
			'post_type'      => 'fusion_tb_layout',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);
	$posts = (array) $query->posts;

	// Same function: ordered ids first, the rest in query order, ordered ids with no post dropped.
	$settings = get_option( 'fusion_builder_settings', array() );
	$order    = is_array( $settings ) && isset( $settings['awb_layout_order'] ) && is_string( $settings['awb_layout_order'] ) ? $settings['awb_layout_order'] : '';
	if ( '' !== $order ) {
		$by_id = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$by_id[ $post->ID ] = $post;
			}
		}
		$posts = array_replace( array_flip( explode( ',', str_replace( 'global,', '', $order ) ) ), $by_id );
	}
	$posts = array_values(
		array_filter(
			$posts,
			static function ( $post ): bool {
				return $post instanceof WP_Post;
			}
		)
	);

	$max     = 200;
	$layouts = array();
	foreach ( array_slice( $posts, 0, $max ) as $position => $post ) {
		// Fusion_Template_Builder::set_overrides() and get_conditions() decode layouts this way.
		$data      = json_decode( str_replace( "'", "\'", wp_unslash( $post->post_content ) ), true );
		$layouts[] = array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'status' => $post->post_status,
			'order'  => $position,
		) + aafm_avada_layout_detail( $data, $with_conditions );
	}

	$global = get_option( 'fusion_tb_layout_default' );
	if ( false !== $global ) {
		// Fusion_Template_Builder::get_default_layout() decodes the Global Layout this way.
		$data      = is_string( $global ) ? json_decode( wp_unslash( $global ), true ) : null;
		$layouts[] = array(
			'id'     => 'global',
			'title'  => __( 'Global Layout', 'agent-abilities-for-mcp' ),
			'status' => 'publish',
			'order'  => count( $layouts ),
		) + aafm_avada_layout_detail( $data, $with_conditions );
	}

	return array(
		'layouts'       => $layouts,
		'global_stored' => false !== $global,
		'truncated'     => count( $posts ) > $max,
	);
}

/**
 * Sections and conditions of one decoded layout, or unparseable with neither when the decode did
 * not give an array (never empty ones standing in for a failed read).
 *
 * @param mixed $data            Decoded layout data.
 * @param bool  $with_conditions Whether to include the conditions.
 * @return array<string,mixed>
 */
function aafm_avada_layout_detail( $data, bool $with_conditions ): array {
	if ( ! is_array( $data ) ) {
		return array( 'parse_status' => 'unparseable' );
	}
	$sections = array();
	$terms    = isset( $data['template_terms'] ) && is_array( $data['template_terms'] ) ? $data['template_terms'] : array();
	foreach ( $terms as $type => $section_id ) {
		if ( ! $section_id ) {
			continue; // Avada skips an empty slot.
		}
		$id      = is_numeric( $section_id ) ? absint( $section_id ) : 0;
		$section = $id > 0 ? get_post( $id ) : null;
		$entry   = array(
			'id'     => $id,
			'exists' => $section instanceof WP_Post,
		);
		if ( $section instanceof WP_Post ) {
			$entry['title']  = $section->post_title;
			$entry['status'] = $section->post_status;
		}
		$sections[ (string) $type ] = $entry;
	}
	$out = array(
		'parse_status' => 'ok',
		'sections'     => (object) $sections,
	);
	if ( $with_conditions ) {
		$out['conditions'] = isset( $data['conditions'] ) && is_array( $data['conditions'] ) ? array_values( $data['conditions'] ) : array();
	}
	return $out;
}
