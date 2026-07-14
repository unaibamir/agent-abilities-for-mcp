<?php
/**
 * Block-content guardrail for the write abilities.
 *
 * Agents author Gutenberg block markup as a raw string. When the styling is written as inline
 * `style=""` or presentational classes that are not mirrored in the block-delimiter attribute
 * JSON, the editor's JavaScript save() regenerates different HTML than what is stored and shows
 * "This block contains unexpected or invalid content". The frontend renders fine, but the editor
 * looks broken. There is no PHP equivalent of that JS save() check, so this is a heuristic
 * detector for the specific, common mismatches that cause it - NOT a reimplementation of
 * Gutenberg's serializer.
 *
 * Two layers use this file:
 *  - Layer 1: aafm_write_content_description() is the tool-contract guidance the write abilities
 *    put on their `content` parameter, steering the agent to encode styling as block attributes.
 *  - Layer 2: aafm_scan_block_content() walks the FINAL post-KSES markup and returns structured
 *    warnings. In warn mode the write proceeds and the warnings ride back on the response; in
 *    strict mode the write is refused before anything is stored.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Guidance placed on the `content` parameter of every block-markup write ability.
 *
 * The agent reads this as the parameter contract. It states the one rule that prevents the
 * editor-invalid-content failure (styling belongs in block attributes, not inline HTML), shows a
 * short right-vs-wrong pair, and lists the sanitizer facts that otherwise produce a silent
 * mismatch (KSES strips inline rgba()/alpha colors and inline <svg>).
 *
 * @return string
 */
function aafm_write_content_description(): string {
	return __(
		'Gutenberg block markup. Put every bit of styling inside the block delimiter attributes, never as inline style="" on the HTML, or the editor flags the block as invalid content. Right: <!-- wp:heading {"style":{"color":{"text":"#ffffff"}}} --><h2 class="has-text-color" style="color:#ffffff">Hi</h2><!-- /wp:heading -->. Wrong: <!-- wp:heading --><h2 style="color:#ffffff">Hi</h2><!-- /wp:heading -->. The sanitizer strips inline rgba() and hex-with-alpha colors and inline <svg> from stored content, so use plain hex colors and an uploaded <img> or a unicode glyph for icons. For genuinely bespoke markup that has no matching block, use a core/html block.',
		'agent-abilities-for-mcp'
	);
}

/**
 * The set of core blocks the guard inspects.
 *
 * Curated on purpose: only common blocks whose save() output encodes color/size/border styling
 * that agents tend to get wrong. Third-party blocks and freeform blocks (core/html) are out of
 * scope and never inspected, so an unknown block can never be flagged.
 *
 * @return list<string>
 */
function aafm_block_guard_core_whitelist(): array {
	return array(
		'core/paragraph',
		'core/heading',
		'core/button',
		'core/buttons',
		'core/group',
		'core/columns',
		'core/column',
		'core/list',
		'core/list-item',
		'core/image',
		'core/cover',
		'core/quote',
		'core/pullquote',
		'core/media-text',
		'core/verse',
		// Site Editor (template) blocks that carry color/typography/border supports.
		'core/navigation',
		'core/post-title',
		'core/site-title',
		'core/query',
		'core/post-content',
		'core/template-part',
	);
}

/**
 * Whether the guard rejects invalid block content instead of warning. Off by default.
 *
 * Mirrors the force-draft option: a bounded, filterable boolean read from a single option.
 *
 * @return bool
 */
function aafm_block_guard_is_strict(): bool {
	/**
	 * Filters whether invalid block content is rejected (strict) rather than allowed with a warning.
	 *
	 * @param bool $strict True to reject writes whose block markup fails the guard.
	 */
	return (bool) apply_filters( 'aafm_block_guard_strict', (bool) get_option( 'aafm_block_guard_strict', false ) );
}

/**
 * Scan block markup for the mismatches that make the editor show invalid-content.
 *
 * Parses the markup, walks every block including innerBlocks, and inspects each block that is in
 * the core whitelist. Returns a flat, JSON-serializable list of warnings; an empty list means the
 * markup passed the heuristic. Non-core and freeform blocks are skipped entirely.
 *
 * @param string $content Final, post-sanitization block markup (what will be stored).
 * @return list<array{block:string,code:string,message:string}>
 */
function aafm_scan_block_content( string $content ): array {
	if ( '' === trim( $content ) ) {
		return array();
	}

	$warnings = array();
	aafm_scan_blocks_recursive( parse_blocks( $content ), $warnings );

	return $warnings;
}

/**
 * Recursively inspect a parsed block list, appending warnings for whitelisted core blocks.
 *
 * @param array<int|string,array<string,mixed>>                $blocks   Parsed blocks (from parse_blocks or innerBlocks).
 * @param list<array{block:string,code:string,message:string}> $warnings Accumulator, by reference.
 * @return void
 */
function aafm_scan_blocks_recursive( array $blocks, array &$warnings ): void {
	$whitelist = aafm_block_guard_core_whitelist();

	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		if ( '' !== $name && in_array( $name, $whitelist, true ) ) {
			foreach ( aafm_inspect_block( $block ) as $warning ) {
				$warnings[] = $warning;
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			aafm_scan_blocks_recursive( $block['innerBlocks'], $warnings );
		}
	}
}

/**
 * Inspect a single whitelisted core block for attribute/markup mismatches.
 *
 * Three conservative checks, each targeting a known editor-invalid-content trigger:
 *  A. A presentational class on the root element with no matching value in the block attributes.
 *  B. An inline style="" on the root element when the block carries no styling attributes at all.
 *  C. An attribute that declares a color/size whose inline value is missing from the stored HTML
 *     (the KSES-dropped-rgba() case), which leaves a class or attribute with no value behind it.
 *
 * @param array<string,mixed> $block One parsed block.
 * @return list<array{block:string,code:string,message:string}>
 */
function aafm_inspect_block( array $block ): array {
	$name     = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
	$attrs    = ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) ? $block['attrs'] : array();
	$root     = aafm_block_root_markup( isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '', $name );
	$classes  = $root['classes'];
	$style    = $root['style'];
	$warnings = array();

	$style_attr = ( isset( $attrs['style'] ) && is_array( $attrs['style'] ) ) ? $attrs['style'] : array();

	$has_text_color   = ! empty( $attrs['textColor'] ) || null !== aafm_arr_path( $style_attr, array( 'color', 'text' ) );
	$has_background   = ! empty( $attrs['backgroundColor'] ) || ! empty( $attrs['gradient'] )
		|| null !== aafm_arr_path( $style_attr, array( 'color', 'background' ) )
		|| null !== aafm_arr_path( $style_attr, array( 'color', 'gradient' ) );
	$has_border_color = ! empty( $attrs['borderColor'] ) || null !== aafm_arr_path( $style_attr, array( 'border', 'color' ) );
	$has_custom_font  = null !== aafm_arr_path( $style_attr, array( 'typography', 'fontSize' ) );
	$has_preset_font  = ! empty( $attrs['fontSize'] );

	// Check A: presentational class present in the HTML but not backed by an attribute.
	foreach ( $classes as $class ) {
		if ( 'has-text-color' === $class && ! $has_text_color ) {
			$warnings[] = aafm_block_warning( $name, 'text_color_class_without_attr', $class );
		} elseif ( 'has-background' === $class && ! $has_background ) {
			$warnings[] = aafm_block_warning( $name, 'background_class_without_attr', $class );
		} elseif ( 'has-border-color' === $class && ! $has_border_color ) {
			$warnings[] = aafm_block_warning( $name, 'border_color_class_without_attr', $class );
		} elseif ( 'has-custom-font-size' === $class && ! $has_custom_font ) {
			$warnings[] = aafm_block_warning( $name, 'custom_font_size_class_without_attr', $class );
		} elseif ( 'has-custom-font-size' !== $class
			&& 1 === preg_match( '/^has-[a-z0-9]+(?:-[a-z0-9]+)*-font-size$/', $class )
			&& ! $has_preset_font ) {
			$warnings[] = aafm_block_warning( $name, 'font_size_class_without_attr', $class );
		}
	}

	// Check B: raw inline style on the root with nothing declared in the block attributes.
	$has_any_style_attr = ! empty( $style_attr )
		|| ! empty( $attrs['textColor'] )
		|| ! empty( $attrs['backgroundColor'] )
		|| ! empty( $attrs['gradient'] )
		|| ! empty( $attrs['fontSize'] )
		|| ! empty( $attrs['borderColor'] );
	if ( '' !== $style && ! $has_any_style_attr ) {
		$warnings[] = aafm_block_warning( $name, 'inline_style_without_attrs', '' );
	}

	// Check C: an attribute declares a value the stored inline style has lost (KSES-dropped).
	if ( null !== aafm_arr_path( $style_attr, array( 'color', 'text' ) ) && ! aafm_style_declares( $style, 'color' ) ) {
		$warnings[] = aafm_block_warning( $name, 'color_attr_value_dropped', '' );
	}
	if ( null !== aafm_arr_path( $style_attr, array( 'color', 'background' ) ) && ! aafm_style_declares( $style, 'background' ) ) {
		$warnings[] = aafm_block_warning( $name, 'background_attr_value_dropped', '' );
	}
	if ( null !== aafm_arr_path( $style_attr, array( 'typography', 'fontSize' ) ) && ! aafm_style_declares( $style, 'font-size' ) ) {
		$warnings[] = aafm_block_warning( $name, 'font_size_attr_value_dropped', '' );
	}

	return $warnings;
}

/**
 * Pull the class list and inline style off the element that carries a block's presentational styling.
 *
 * For most blocks (heading, paragraph, group, columns) WordPress's block-supports classes land on
 * the block's root save element, so the first element in innerHTML is the right one. core/button is
 * the exception: its save() puts the presentational classes and inline style on the inner
 * `<a class="wp-block-button__link">`, while the wrapping `<div class="wp-block-button">` is just a
 * layout element. So for core/button we target that anchor and fall back to the first element if it
 * is absent. We deliberately do NOT scan arbitrary descendants: an inline-styled `<a>` or `<span>`
 * inside paragraph or heading text is legitimate content that cannot be a block attribute.
 *
 * @param string $html       The block's innerHTML.
 * @param string $block_name The block name, used to pick the styling element.
 * @return array{classes:list<string>,style:string}
 */
function aafm_block_root_markup( string $html, string $block_name = '' ): array {
	// core/button carries its block-supports styling on the inner button link, not the wrapper div.
	if ( 'core/button' === $block_name
		&& 1 === preg_match(
			'/<a\b([^>]*\bclass\s*=\s*(?:"[^"]*wp-block-button__link[^"]*"|\'[^\']*wp-block-button__link[^\']*\')[^>]*)>/i',
			$html,
			$anchor
		) ) {
		return aafm_extract_tag_attrs( $anchor[1] );
	}

	if ( 1 !== preg_match( '/<([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>/', $html, $tag ) ) {
		return array(
			'classes' => array(),
			'style'   => '',
		);
	}

	return aafm_extract_tag_attrs( $tag[2] );
}

/**
 * Extract the class list and inline style from a single element's attribute string.
 *
 * @param string $attr_str The raw attribute portion of one opening tag.
 * @return array{classes:list<string>,style:string}
 */
function aafm_extract_tag_attrs( string $attr_str ): array {
	$result = array(
		'classes' => array(),
		'style'   => '',
	);

	if ( 1 === preg_match( '/\bclass\s*=\s*("|\')(.*?)\1/i', $attr_str, $class_match ) ) {
		$split             = preg_split( '/\s+/', trim( $class_match[2] ) );
		$result['classes'] = array_values( array_filter( is_array( $split ) ? $split : array() ) );
	}

	if ( 1 === preg_match( '/\bstyle\s*=\s*("|\')(.*?)\1/i', $attr_str, $style_match ) ) {
		$result['style'] = trim( $style_match[2] );
	}

	return $result;
}

/**
 * Whether an inline style string declares a given CSS property.
 *
 * Matches the property at the start of a declaration (line start or after a semicolon) so a
 * `color` query does not falsely match `background-color`. The `background` property matches both
 * `background:` and `background-color:`.
 *
 * @param string $style    Inline style string (property:value; ...).
 * @param string $property One of 'color', 'background', or 'font-size'.
 * @return bool
 */
function aafm_style_declares( string $style, string $property ): bool {
	if ( '' === $style ) {
		return false;
	}
	$pattern = 'background' === $property
		? '/(?:^|;)\s*background(?:-color)?\s*:/i'
		: '/(?:^|;)\s*' . preg_quote( $property, '/' ) . '\s*:/i';

	return 1 === preg_match( $pattern, $style );
}

/**
 * Read a nested value out of an attribute array by path, or null when any hop is missing.
 *
 * @param array<string,mixed> $arr  Attribute array.
 * @param array<int,string>   $path Ordered keys to descend.
 * @return mixed|null The value at the path, or null.
 */
function aafm_arr_path( array $arr, array $path ) {
	$node = $arr;
	foreach ( $path as $key ) {
		if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
			return null;
		}
		$node = $node[ $key ];
	}
	return ( '' === $node || array() === $node ) ? null : $node;
}

/**
 * Build one structured warning row.
 *
 * The message is human, translatable prose (it rides back to the agent and can surface in a
 * strict-mode error); the code is a stable machine token for programmatic handling.
 *
 * @param string $block      Block name (for example core/heading).
 * @param string $code       Machine code for the warning type.
 * @param string $class_name Offending class name, when the warning is about a class ('' otherwise).
 * @return array{block:string,code:string,message:string}
 */
function aafm_block_warning( string $block, string $code, string $class_name ): array {
	switch ( $code ) {
		case 'text_color_class_without_attr':
		case 'background_class_without_attr':
		case 'border_color_class_without_attr':
		case 'custom_font_size_class_without_attr':
		case 'font_size_class_without_attr':
			$message = sprintf(
				/* translators: 1: block name, 2: CSS class name. */
				__( 'The %1$s block has the "%2$s" class but no matching value in its block attributes. Encode the styling inside the block delimiter so the editor does not flag the block as invalid.', 'agent-abilities-for-mcp' ),
				$block,
				$class_name
			);
			break;
		case 'inline_style_without_attrs':
			$message = sprintf(
				/* translators: %s: block name. */
				__( 'The %s block uses an inline style="" attribute with no styling in its block attributes. Move the styling into the block delimiter, or the editor will flag the block as invalid.', 'agent-abilities-for-mcp' ),
				$block
			);
			break;
		case 'color_attr_value_dropped':
		case 'background_attr_value_dropped':
		case 'font_size_attr_value_dropped':
			$message = sprintf(
				/* translators: %s: block name. */
				__( 'The %s block declares a color or size in its attributes, but the value is missing from the stored HTML (the sanitizer likely dropped an rgba() or alpha color). Use a plain hex color so the attribute and the markup agree.', 'agent-abilities-for-mcp' ),
				$block
			);
			break;
		default:
			$message = sprintf(
				/* translators: %s: block name. */
				__( 'The %s block may render as invalid content in the editor. Encode all styling as block attributes.', 'agent-abilities-for-mcp' ),
				$block
			);
			break;
	}

	return array(
		'block'   => $block,
		'code'    => $code,
		'message' => $message,
	);
}

/**
 * Run the guard against final content and, in strict mode, return a blocking error.
 *
 * The single chokepoint every write executor calls. It returns the scan warnings in every case;
 * when strict mode is on and the scan found anything, it ALSO returns a WP_Error the caller must
 * return before writing, so invalid markup is never stored. The caller attaches the warnings to
 * its success response in warn mode.
 *
 * @param string $content Final, post-sanitization block markup.
 * @return array{warnings:list<array{block:string,code:string,message:string}>,error:WP_Error|null}
 */
function aafm_block_guard_evaluate( string $content ): array {
	$warnings = aafm_scan_block_content( $content );
	$error    = null;

	if ( ! empty( $warnings ) && aafm_block_guard_is_strict() ) {
		$blocks = implode( ', ', array_values( array_unique( wp_list_pluck( $warnings, 'block' ) ) ) );
		$error  = new WP_Error(
			'aafm_invalid_block_content',
			sprintf(
				/* translators: %s: comma-separated list of block names. */
				__( 'The block content was rejected because these blocks would show as invalid in the editor: %s. Encode all styling as block attributes inside the block delimiters and try again.', 'agent-abilities-for-mcp' ),
				$blocks
			)
		);
	}

	return array(
		'warnings' => $warnings,
		'error'    => $error,
	);
}
