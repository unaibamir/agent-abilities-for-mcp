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
 * The Fusion shortcode tags this guard recognizes, confirmed against a real installed copy
 * (Fusion Builder 3.16.1) via its own add_shortcode() call sites (inc/class-fusion-row-element.php,
 * inc/class-fusion-column-element.php). Nested rows/columns use their own distinct "_inner" tag
 * names rather than nesting the SAME tag inside itself - this is deliberate on ThemeFusion's part,
 * since WordPress's shortcode regex cannot reliably resolve a shortcode nested inside another
 * instance of the identical tag (see aafm_fusion_shortcode_walk()'s docblock). Extend this list as
 * new fixture content is encountered, per 228-avada-guard-design.md's own "not a closed list" note.
 *
 * @return string[]
 */
function aafm_fusion_shortcode_tags(): array {
	return array(
		'fusion_builder_container',
		'fusion_builder_row',
		'fusion_builder_row_inner',
		'fusion_builder_column',
		'fusion_builder_column_inner',
		'fusion_separator',
		'fusion_text',
	);
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
 * @param string $content Content to scan at this nesting level.
 * @param int    $depth   Current nesting depth (0 = top level).
 * @return array<int,array{tag:string,self_closing:bool,atts:array<string,mixed>,depth:int}>|null Null means "could not be parsed" - the caller must refuse rather than assume safety.
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

		$atts = shortcode_parse_atts( $attribute_str );
		$atts = is_array( $atts ) ? $atts : array();

		$signature[] = array(
			'tag'          => $tag,
			'self_closing' => $self_closing,
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
