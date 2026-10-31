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
		'Gutenberg block markup. Put every bit of styling inside the block delimiter attributes, never as inline style="" on the HTML, or the editor flags the block as invalid content. Right: <!-- wp:heading {"style":{"color":{"text":"#ffffff"}}} --><h2 class="has-text-color" style="color:#ffffff">Hi</h2><!-- /wp:heading -->. Wrong: <!-- wp:heading --><h2 style="color:#ffffff">Hi</h2><!-- /wp:heading -->. The sanitizer strips inline rgba() and hex-with-alpha colors and inline <svg> from stored content, so use plain hex colors and an uploaded <img> or a unicode glyph for icons. For genuinely bespoke markup that has no matching block, use a core/html block. For users who can post unfiltered HTML, &, < and > in plain block attribute text are stored as written when WordPress would only entity-encode them; a value that already holds an entity, or that WordPress changes in any other way, keeps WordPress\'s sanitized value; HTML tags inside attribute values are still sanitized, and other users get WordPress\'s own sanitizing. <style> and <script> elements are removed with their contents. ACF blocks take {"name","data","mode"} attributes with field NAMES as the data keys (see aafm-get-block-type). On WordPress 7.0+, metadata.blockVisibility.viewport with mobile, tablet or desktop set to false hides a block on that screen size; on 6.9, metadata.blockVisibility:false hides it everywhere. With fluid typography on (aafm-get-global-styles settings.typography.fluid), px font sizes scale down on small screens. WordPress ignores a blockGap that contains (, \\, &, =, } or /*, so clamp(), calc() and var() are dropped there, while 24px or a preset such as var:preset|spacing|40 work.',
		'agent-abilities-for-mcp'
	);
}

/**
 * The set of core blocks the guard inspects.
 *
 * Curated on purpose: only common blocks whose save() output encodes color/size/border styling
 * that agents tend to get wrong. Editor-invalid-content checks cover only this core whitelist.
 * Informational checks run on every named block, including third-party blocks.
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
	// An array or an object is not a stored switch, and reads as on.
	$is_on = static function ( $stored ): bool {
		return is_array( $stored ) || is_object( $stored ) || (bool) $stored;
	};

	$raw = get_option( 'aafm_block_guard_strict', false );
	$on  = $is_on( $raw );
	if ( false === $raw ) {
		// Off may be a failed read's default: the row decides, and an unreadable row means on.
		$row = aafm_policy_row( 'aafm_block_guard_strict' );
		$on  = ! $row['ok'] || ( $row['found'] && $is_on( $row['value'] ) );
	}

	// A cache copy that disagrees with the row reads on when either does; an unreadable row means on.
	$row = aafm_policy_row_if_stale( 'aafm_block_guard_strict' );
	if ( null !== $row ) {
		$on = $on || ! $row['ok'] || ( $row['found'] && $is_on( $row['value'] ) );
	}

	/**
	 * Filters whether invalid block content is rejected (strict) rather than allowed with a warning.
	 *
	 * @param bool $strict True to reject writes whose block markup fails the guard.
	 */
	return (bool) apply_filters( 'aafm_block_guard_strict', $on );
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
		if ( '' !== $name ) {
			foreach ( aafm_inspect_block_silent_drops( $block ) as $warning ) {
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

	// Check B: the root inline style declares a PRESENTATIONAL property the guard understands and
	// mirrors as a block attribute (color, background/background-color, font-size, border-color) with
	// no matching attribute behind it. Only these properties can cause the save() mismatch, so a
	// structural style the guard does not back - min-height on core/cover, grid-template-columns on
	// core/media-text, max-width - is legitimate save() output and must never trip this check.
	if ( '' !== $style ) {
		if ( aafm_style_declares( $style, 'color' ) && ! $has_text_color ) {
			$warnings[] = aafm_block_warning( $name, 'inline_style_without_attrs', '' );
		} elseif ( aafm_style_declares( $style, 'background' ) && ! $has_background ) {
			$warnings[] = aafm_block_warning( $name, 'inline_style_without_attrs', '' );
		} elseif ( aafm_style_declares( $style, 'font-size' ) && ! $has_custom_font && ! $has_preset_font ) {
			$warnings[] = aafm_block_warning( $name, 'inline_style_without_attrs', '' );
		} elseif ( aafm_style_declares( $style, 'border-color' ) && ! $has_border_color ) {
			$warnings[] = aafm_block_warning( $name, 'inline_style_without_attrs', '' );
		}
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
 * Informational checks on any named block for things WordPress drops without saying so.
 *
 * - unregistered_block: no block type of that name is registered (a deactivated plugin's block
 *   reads the same way, which is why it never refuses).
 * - unknown_acf_field: an ACF block's data holds a key that resolves to none of its fields.
 * - block_gap_dropped: a style.spacing.blockGap value core skips when it renders the layout.
 *
 * @param array<string,mixed> $block One parsed block.
 * @return list<array{block:string,code:string,message:string}>
 */
function aafm_inspect_block_silent_drops( array $block ): array {
	$name     = (string) $block['blockName'];
	$attrs    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
	$warnings = array();

	if ( ! WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
		$warnings[] = aafm_block_warning( $name, 'unregistered_block', '' );
	}

	$fields = aafm_acf_block_fields( $name );
	if ( null !== $fields && isset( $attrs['data'] ) && is_array( $attrs['data'] ) ) {
		$unknown = array();
		foreach ( array_keys( $attrs['data'] ) as $key ) {
			$key = (string) $key;
			if ( '_' !== substr( $key, 0, 1 ) && ! aafm_acf_block_key_resolves( $key, $fields ) ) {
				$unknown[] = $key;
			}
		}
		if ( array() !== $unknown ) {
			$warnings[] = aafm_block_warning( $name, 'unknown_acf_field', implode( ', ', array_slice( $unknown, 0, 10 ) ) );
		}
	}

	$gap     = aafm_arr_path( $attrs, array( 'style', 'spacing', 'blockGap' ) );
	$dropped = array();
	foreach ( is_array( $gap ) ? $gap : array( $gap ) as $value ) {
		if ( is_string( $value ) && '' !== $value && aafm_block_gap_is_dropped( $value ) ) {
			$dropped[] = $value;
		}
	}
	if ( array() !== $dropped ) {
		$warnings[] = aafm_block_warning( $name, 'block_gap_dropped', implode( ', ', $dropped ) );
	}

	return $warnings;
}

/**
 * Whether core drops this blockGap value when it renders the layout.
 *
 * WordPress 7.1 has wp_sanitize_block_gap_value(). Before it, 6.9.4 inlines the same pattern in
 * wp_render_layout_support_flag() (wp-includes/block-supports/layout.php:801 and :804).
 *
 * @param string $value One blockGap value.
 * @return bool
 */
function aafm_block_gap_is_dropped( string $value ): bool {
	if ( function_exists( 'wp_sanitize_block_gap_value' ) ) {
		return null === wp_sanitize_block_gap_value( $value );
	}
	return 1 === preg_match( '%[\\\(&=}]|/\*%', $value );
}

/**
 * Whether an ACF block data key resolves against a block's field tree.
 *
 * A key resolves when it is a field name or a field key (ACF turns field-key data into names on
 * save); <group>_<rest> with <rest> resolving against the group's
 * sub-fields; <repeater>_<n>_<rest> or <flexible>_<n>_<rest> with <rest> resolving against the
 * repeater's sub-fields or any layout's; or, at a clone's own level, its name or one of its
 * sub-field names (loaded with any prefix already applied). The tree is depth-capped at 5 by
 * aafm_acf_block_fields(), so the recursion is too.
 *
 * @param string                         $key    Data key.
 * @param array<int,array<string,mixed>> $fields Fields at this level.
 * @return bool
 */
function aafm_acf_block_key_resolves( string $key, array $fields ): bool {
	foreach ( $fields as $field ) {
		$name = (string) $field['name'];
		$type = (string) $field['type'];
		$subs = isset( $field['sub_fields'] ) ? (array) $field['sub_fields'] : array();
		if ( $key === $name || $key === (string) $field['key'] || ( 'clone' === $type && aafm_acf_block_key_resolves( $key, $subs ) ) ) {
			return true;
		}
		if ( '' === $name || 0 !== strpos( $key, $name . '_' ) ) {
			continue;
		}
		$rest = (string) substr( $key, strlen( $name ) + 1 );
		if ( 'group' === $type && aafm_acf_block_key_resolves( $rest, $subs ) ) {
			return true;
		}
		if ( ( 'repeater' === $type || 'flexible_content' === $type ) && 1 === preg_match( '/^[0-9]+_(.+)$/s', $rest, $row ) ) {
			if ( 'flexible_content' === $type ) {
				$subs = array();
				foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
					$subs = array_merge( $subs, (array) $layout['sub_fields'] );
				}
			}
			if ( aafm_acf_block_key_resolves( $row[1], $subs ) ) {
				return true;
			}
		}
	}
	return false;
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
 * @param string $block  Block name (for example core/heading).
 * @param string $code   Machine code for the warning type.
 * @param string $detail The code's detail: the offending class name, the removed element name,
 *                       the unknown ACF keys or the dropped blockGap values ('' otherwise).
 * @return array{block:string,code:string,message:string}
 */
function aafm_block_warning( string $block, string $code, string $detail ): array {
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
				$detail
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
		case 'removed_element':
			$message = sprintf(
				/* translators: %s: HTML element name, style or script. */
				__( 'A <%s> element was removed together with its contents. Stored content cannot carry styles or scripts; put styling in block attributes instead.', 'agent-abilities-for-mcp' ),
				$detail
			);
			break;
		case 'unregistered_block':
			$message = sprintf(
				/* translators: %s: block name. */
				__( 'No block type named %s is registered on this site, so it will not render as that block. Check the name with aafm-list-block-types.', 'agent-abilities-for-mcp' ),
				$block
			);
			break;
		case 'unknown_acf_field':
			$valid   = wp_list_pluck( (array) aafm_acf_block_fields( $block ), 'name' );
			$message = sprintf(
				/* translators: 1: block name, 2: comma-separated data keys, 3: comma-separated field names. */
				__( 'The %1$s block data has keys that match none of its ACF fields, so ACF ignores them: %2$s. Its field names are: %3$s. See aafm-get-block-type.', 'agent-abilities-for-mcp' ),
				$block,
				$detail,
				implode( ', ', array_slice( $valid, 0, 20 ) )
			);
			break;
		case 'block_gap_dropped':
			$message = sprintf(
				/* translators: 1: block name, 2: blockGap value(s). */
				__( 'WordPress ignores the blockGap value %2$s on the %1$s block, because it contains (, \\, &, =, } or /*. Use a length such as 24px or a spacing preset such as var:preset|spacing|40.', 'agent-abilities-for-mcp' ),
				$block,
				$detail
			);
			break;
		case 'invalid_block_attributes':
			$message = sprintf(
				/* translators: %s: block name. */
				__( 'The %s block has delimiter attributes that are not valid JSON, so WordPress ignores all of them. Fix the JSON (for example a trailing comma) and write the block again.', 'agent-abilities-for-mcp' ),
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
 * The warning codes strict mode refuses on: the editor-invalid-content codes the guard had before
 * the informational ones (unregistered_block, unknown_acf_field, block_gap_dropped,
 * invalid_block_attributes, removed_element) were added. Those never refuse, so a strict site does
 * not start refusing writes it accepted before.
 *
 * @return list<string>
 */
function aafm_block_guard_strict_codes(): array {
	return array(
		'text_color_class_without_attr',
		'background_class_without_attr',
		'border_color_class_without_attr',
		'custom_font_size_class_without_attr',
		'font_size_class_without_attr',
		'inline_style_without_attrs',
		'color_attr_value_dropped',
		'background_attr_value_dropped',
		'font_size_attr_value_dropped',
	);
}

/**
 * Run the guard against final content and, in strict mode, return a blocking error.
 *
 * The single chokepoint every write executor calls. It returns the scan warnings in every case.
 * Strict mode refuses only warnings whose codes are listed by aafm_block_guard_strict_codes();
 * informational warnings never block a write. On such a refusal it ALSO returns a WP_Error the
 * caller must return before writing. The caller attaches the warnings to its success response.
 *
 * @param string $content Final, post-sanitization block markup.
 * @return array{warnings:list<array{block:string,code:string,message:string}>,error:WP_Error|null}
 */
function aafm_block_guard_evaluate( string $content ): array {
	$warnings = aafm_scan_block_content( $content );
	$error    = null;

	// Strict mode refuses only on the editor-invalid codes; the informational ones never refuse.
	$refusing = array_filter(
		$warnings,
		static fn( array $warning ): bool => in_array( $warning['code'], aafm_block_guard_strict_codes(), true )
	);
	if ( ! empty( $refusing ) && aafm_block_guard_is_strict() ) {
		$blocks = implode( ', ', array_values( array_unique( wp_list_pluck( $refusing, 'block' ) ) ) );
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

/**
 * Sanitize agent-written block markup: wp_kses_post(), minus its damage to plain attribute text.
 *
 * The wp_kses_post() pass entity-encodes &, < and > inside block attribute JSON for every user, so
 * a value such as "Saturday & Sunday" or an embed URL with a query string is stored changed. Core
 * does that on save only for users without unfiltered_html; for a user with it, the block editor
 * stores the attribute text raw. This helper keeps wp_kses_post() as the sanitizer and, for a user with
 * unfiltered_html only, puts back each attribute string that cannot open an HTML tag and that kses
 * only entity-encoded, exactly as it was sent. Any other value keeps the kses result, and
 * innerHTML is never restored.
 * When nothing is restored, the content is wp_kses_post() of the input byte for byte, unless kses
 * of the raw input parses differently from kses of the normalized input (a raw "<" in the comment
 * JSON broke or merged the comment): then it is the normalized kses tree, serialized. A user
 * without unfiltered_html always gets wp_kses_post() of the input byte for byte. Both apart from
 * the <style>/<script> removal below.
 *
 * <style> and <script> elements are removed with their contents first, since kses would strip the
 * tags and leave the CSS or script text in the page. Both that removal and a block whose delimiter
 * JSON does not parse come back as informational warnings, which never refuse a write.
 *
 * @param string $raw Block markup as the agent sent it.
 * @return array{content:string,warnings:list<array{block:string,code:string,message:string}>}
 */
function aafm_kses_block_markup( string $raw ): array {
	$warnings = array();
	$stripped = aafm_strip_raw_text_elements( $raw, $warnings );
	$clean    = wp_kses_post( $stripped );
	$parsed   = parse_blocks( $stripped );

	// The block parser decides whether this is block markup, not a "<!-- wp:" literal.
	$has_blocks = aafm_flag_invalid_block_attributes( $parsed, $warnings );
	if ( ! $has_blocks || ! current_user_can( 'unfiltered_html' ) ) {
		return array(
			'content'  => $clean,
			'warnings' => $warnings,
		);
	}

	// Serialize first so a raw "<" in the comment JSON is escaped before kses can merge two keys.
	$norm = serialize_blocks( $parsed );
	$kept = parse_blocks( wp_kses_post( $norm ) );
	$tree = aafm_restore_block_attrs( parse_blocks( $norm ), $kept );

	if ( null !== $tree && $tree !== $kept ) {
		// @phpstan-ignore-next-line argument.type (the walk rewrites attrs values only, so every entry keeps the parse_blocks() block shape)
		$clean = serialize_blocks( $tree );
	} elseif ( null !== $tree && parse_blocks( $clean ) !== $kept ) {
		// kses of the raw input read a "<" in the comment JSON as a tag and broke or merged the comment.
		$clean = serialize_blocks( $kept );
	}
	return array(
		'content'  => $clean,
		'warnings' => $warnings,
	);
}

/**
 * Empty every <style> and <script> element, using the HTML tokenizer rather than a regex.
 *
 * A "<style>" inside a quoted attribute or inside an HTML comment (a block delimiter) is not an
 * element and is left alone. An unclosed element stops the tokenizer and is left for kses, as
 * before. kses then drops the emptied tags, since neither is an allowed post tag.
 *
 * @param string                                               $raw      Markup.
 * @param list<array{block:string,code:string,message:string}> $warnings Accumulator, by reference: one removed_element row per element name.
 * @return string The markup, byte for byte when nothing was emptied.
 */
function aafm_strip_raw_text_elements( string $raw, array &$warnings ): string {
	$processor = new WP_HTML_Tag_Processor( $raw );
	$removed   = array();
	while ( $processor->next_tag() ) {
		$tag = $processor->get_tag();
		if ( ( 'STYLE' === $tag || 'SCRIPT' === $tag ) && $processor->set_modifiable_text( '' ) ) {
			$removed[ $tag ] = true;
		}
	}
	if ( array() === $removed ) {
		return $raw;
	}
	foreach ( array_keys( $removed ) as $tag ) {
		$warnings[] = aafm_block_warning( '', 'removed_element', strtolower( (string) $tag ) );
	}
	return $processor->get_updated_html();
}

/**
 * Add an invalid_block_attributes warning for every named block whose delimiter JSON did not parse.
 *
 * The block parser leaves attrs null when json_decode() fails, and the attributes are then lost.
 *
 * @param array<int|string,array<string,mixed>>                $blocks   Parsed blocks.
 * @param list<array{block:string,code:string,message:string}> $warnings Accumulator, by reference.
 * @return bool Whether the tree holds any named block at any depth.
 */
function aafm_flag_invalid_block_attributes( array $blocks, array &$warnings ): bool {
	$named = false;
	foreach ( $blocks as $block ) {
		if ( isset( $block['blockName'] ) ) {
			$named = true;
			if ( null === $block['attrs'] ) {
				$warnings[] = aafm_block_warning( (string) $block['blockName'], 'invalid_block_attributes', '' );
			}
		}
		if ( ! empty( $block['innerBlocks'] ) && aafm_flag_invalid_block_attributes( $block['innerBlocks'], $warnings ) ) {
			$named = true;
		}
	}
	return $named;
}

/**
 * Walk the as-sent tree and the kses tree together, restoring tag-free attribute strings.
 *
 * @param array<int|string,array<string,mixed>> $sent Parsed tree of the normalized markup.
 * @param array<int|string,array<string,mixed>> $kept Parsed tree of its wp_kses_post() output.
 * @return array<int|string,array<string,mixed>>|null The kses tree with values restored, or null
 *                                                    when the two trees differ in shape.
 */
function aafm_restore_block_attrs( array $sent, array $kept ): ?array {
	if ( count( $sent ) !== count( $kept ) ) {
		return null;
	}
	foreach ( $kept as $i => $block ) {
		$from = $sent[ $i ] ?? null;
		if ( ! is_array( $from ) || $from['blockName'] !== $block['blockName'] ) {
			return null;
		}
		if ( is_array( $from['attrs'] ) && is_array( $block['attrs'] ) ) {
			$kept[ $i ]['attrs'] = aafm_restore_attr_values( $from['attrs'], $block['attrs'] );
		}
		$inner = aafm_restore_block_attrs( (array) $from['innerBlocks'], (array) $block['innerBlocks'] );
		if ( null === $inner ) {
			return null;
		}
		$kept[ $i ]['innerBlocks'] = $inner;
	}
	return $kept;
}

/**
 * Restore one attribute value (recursively through arrays, by identical key path).
 *
 * A string comes back as sent only when it cannot open an HTML tag (no "<" followed by a letter,
 * "/", "!" or "?") and kses changed it by entity encoding alone, so decoding the kses value gives
 * the sent value back. A value kses emptied or rewrote for any other reason (a template-part
 * tagName that is not an allowed element, for one) keeps the kses side, as do keys present in
 * only one tree.
 *
 * @param mixed $sent Value as sent.
 * @param mixed $kept Value after kses.
 * @return mixed
 */
function aafm_restore_attr_values( $sent, $kept ) {
	if ( is_string( $sent ) && is_string( $kept ) ) {
		if ( 1 !== preg_match( '#<[a-zA-Z/!?]#', $sent ) && html_entity_decode( $kept, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) === $sent ) {
			return $sent;
		}
		return $kept;
	}
	if ( is_array( $sent ) && is_array( $kept ) ) {
		foreach ( $kept as $key => $value ) {
			if ( array_key_exists( $key, $sent ) ) {
				$kept[ $key ] = aafm_restore_attr_values( $sent[ $key ], $value );
			}
		}
	}
	return $kept;
}
