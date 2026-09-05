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
 * The two Avada ability rows, shared by the guarded and full registries.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_avada_registry_definitions(): array {
	return array(
		'aafm/avada-get-page-content' => array(
			'label'        => __( 'Get Avada page content', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads the raw post content of an Avada/Fusion Builder page, unchanged - Fusion Builder shortcodes are returned exactly as stored, never rendered or stripped. Requires edit access to the post.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_get_page_content',
		),
		'aafm/avada-replace-text'     => array(
			'label'        => __( 'Replace text in an Avada page', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Replaces literal text within an Avada/Fusion Builder page while requiring the Fusion shortcode tree to stay byte-identical before and after - a replacement that would touch a shortcode tag, its self-closing form, or an attribute is refused rather than risk breaking the layout. Requires edit access to the post.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_replace_text',
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
	$post = $id ? get_post( $id ) : null;
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
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post/page to read. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'        => array( 'type' => 'integer' ),
				'content'        => array( 'type' => 'string' ),
				'is_avada_owned' => array( 'type' => 'boolean' ),
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
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}

	return array(
		'post_id'        => $id,
		'content'        => (string) $post->post_content,
		'is_avada_owned' => 'avada' === aafm_post_has_foreign_builder_ownership( $id ),
	);
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
 * Codex round C finding 2: a fixed 7-tag list misses every OTHER real Fusion Builder element
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
 * Codex round C finding 2 (bullets 2 and 3), both closed here:
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
 *   fail-closed rule, rather than silently exposing a "safe-looking" span that Codex proved is not
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
		// Codex final round 5 MEDIUM: counting each quote character independently (an odd count
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
	$post = get_post( $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// Codex round C finding 3: this ability's whole reason to exist is a FINER write path for
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

	$fresh = get_post( $id );

	return array(
		'post'         => aafm_redact_post( $fresh instanceof WP_Post ? $fresh : $post ),
		'replacements' => $replacements,
	);
}
