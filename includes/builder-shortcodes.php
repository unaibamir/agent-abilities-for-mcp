<?php
/**
 * The shared page builder engine for WPBakery Page Builder and Avada Builder: the element outline
 * with byte offsets, the nested tree, fingerprints, addressing, the shortcode serializer, the
 * builders' own post types and the ownership rule, the one seam to vendor code, the expected
 * outline after a change, insert anchors, and the guarded save every element write goes through.
 *
 * Design 325 sections 4.1 to 4.5, plan 326 Session 7. The outline is built with the same regex and
 * quote check as aafm_fusion_shortcode_walk() (includes/abilities/avada.php), and its tuples equal
 * that walker's on every stored page fixture; the walker and the structure check stay in avada.php.
 *
 * The ownership check (aafm_post_has_foreign_builder_ownership()) is never called here: each write
 * executor calls it in its own body (Ruling R-B1) and passes the answer to
 * aafm_builder_ownership_allows().
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The outline stops here, as aafm_avada_structure() does; an element past it cannot be addressed.
 */
const AAFM_BUILDER_OUTLINE_CAP = 2000;

/**
 * A WP_Error for one of the engine's shared codes, with its HTTP status.
 *
 * @param string              $code  Error code.
 * @param array<string,mixed> $extra Extra error data merged after the status.
 * @return WP_Error
 */
function aafm_builder_error( string $code, array $extra = array() ): WP_Error {
	$codes = array(
		'aafm_builder_unparseable'            => array( 409, __( 'The page content could not be read as builder elements, so nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_element_mismatch'       => array( 409, __( 'No element matches that index, tag and fingerprint. The page may have changed, or the index is past the 2,000 element outline. Read the outline again. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_page_changed'           => array( 409, __( 'The page changed since the page fingerprint was read. Read the outline again and retry with the new fingerprint. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_structure_changed'      => array( 400, __( 'The change would alter the page layout beyond the element that was named, for example by adding a shortcode inside text. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_attribute_not_writable' => array( 400, __( 'That element setting cannot be written by this tool. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_invalid_value'          => array( 400, __( 'A setting value has the wrong type or form. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_needs_unfiltered_html'  => array( 403, __( 'That setting can carry script or raw HTML, so it needs a user who may post unfiltered HTML. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_unknown_element'        => array( 400, __( 'That element type is not known to the page builder on this site. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_invalid_nesting'        => array( 400, __( 'An element cannot be placed there: it cannot hold child elements, or the builder does not allow that nesting. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_invalid_move'           => array( 400, __( 'An element cannot be moved into itself or into one of its own children. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_remove_irreversible'    => array( 409, __( 'Revisions are off for this post, so a removed element could not be brought back. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_remove_last_root'       => array( 409, __( 'The last top level element of a page cannot be removed. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
		'aafm_builder_unknown_template'       => array( 404, __( 'No template with that id was found. Nothing was changed.', 'agent-abilities-for-mcp' ) ),
	);
	$row   = $codes[ $code ] ?? array( 400, __( 'The request could not be completed. Nothing was changed.', 'agent-abilities-for-mcp' ) );
	return new WP_Error( $code, $row[1], array_merge( array( 'status' => $row[0] ), $extra ) );
}

/**
 * The element outline with byte offsets, in outline order (each element before its children).
 *
 * Each element: index, depth, parent (index or null), tag, atts (shortcode_parse_atts()), self_closing,
 * has_closer, and four byte offsets into $content: start (first byte of the opening tag), open_end (the
 * byte after the opening tag's closing bracket), inner_end (where the closing tag starts; open_end when
 * there is none) and end (the byte after the element). The tag, self_closing, has_closer, atts and depth
 * of each element, in order, equal aafm_fusion_shortcode_walk( $content, 0, $tags ).
 *
 * @param string        $content Content.
 * @param array<string> $tags    Shortcode tags to read. An empty list reads nothing.
 * @return list<array<string,mixed>>|null Null when the content cannot be parsed.
 */
function aafm_builder_elements( string $content, array $tags ): ?array {
	if ( array() === $tags ) {
		return array();
	}
	$out = array();
	return aafm_builder_elements_level( $content, 0, strlen( $content ), 0, null, '/' . get_shortcode_regex( array_values( $tags ) ) . '/', $out ) ? $out : null;
}

/**
 * One nesting level of aafm_builder_elements(): scans $content between $from and $to.
 *
 * @param string                    $content Whole content.
 * @param int                       $from    First byte of this level.
 * @param int                       $to      Byte after this level.
 * @param int                       $depth   Depth.
 * @param int|null                  $parent_index Parent index.
 * @param string                    $pattern Shortcode regex.
 * @param list<array<string,mixed>> $out     Outline, appended to.
 * @return bool False when the level cannot be parsed.
 */
function aafm_builder_elements_level( string $content, int $from, int $to, int $depth, ?int $parent_index, string $pattern, array &$out ): bool {
	$level = substr( $content, $from, $to - $from );
	if ( false === preg_match_all( $pattern, $level, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return false;
	}
	foreach ( $matches as $m ) {
		$attribute_str = (string) $m[3][0];
		if ( ! aafm_fusion_attribute_quotes_balanced( $attribute_str ) ) {
			return false;
		}
		$tag          = (string) $m[2][0];
		$self_closing = '/' === ( $m[4][0] ?? '' );
		$has_inner    = isset( $m[5] ) && -1 !== $m[5][1];
		$whole        = (string) $m[0][0];
		$start        = $from + (int) $m[0][1] + strlen( (string) $m[1][0] );
		$end          = $from + (int) $m[0][1] + strlen( $whole ) - strlen( (string) ( $m[6][0] ?? '' ) );
		$open_end     = $has_inner ? $from + (int) $m[5][1] : $end;
		$inner_end    = $has_inner ? $open_end + strlen( (string) $m[5][0] ) : $open_end;
		$atts         = shortcode_parse_atts( $attribute_str );
		$index        = count( $out );

		$out[] = array(
			'index'        => $index,
			'depth'        => $depth,
			'parent'       => $parent_index,
			'tag'          => $tag,
			'atts'         => is_array( $atts ) ? $atts : array(),
			'self_closing' => $self_closing,
			'has_closer'   => $self_closing || (bool) preg_match( '/\[\/' . preg_quote( $tag, '/' ) . '\]$/', $whole ),
			'start'        => $start,
			'open_end'     => $open_end,
			'inner_end'    => $inner_end,
			'end'          => $end,
		);

		if ( ! $self_closing && $inner_end > $open_end && ! aafm_builder_elements_level( $content, $open_end, $inner_end, $depth + 1, $index, $pattern, $out ) ) {
			return false;
		}
	}
	return true;
}

/**
 * The first 16 hex characters of the SHA-256 of an element's stored source, children included.
 *
 * @param string              $content Content.
 * @param array<string,mixed> $element Element from aafm_builder_elements().
 * @return string
 */
function aafm_builder_fingerprint( string $content, array $element ): string {
	return substr( hash( 'sha256', substr( $content, (int) $element['start'], (int) $element['end'] - (int) $element['start'] ) ), 0, 16 );
}

/**
 * The first 16 hex characters of the SHA-256 of the whole content (Ruling R3-P2).
 *
 * @param string $content Content.
 * @return string
 */
function aafm_builder_page_fingerprint( string $content ): string {
	return substr( hash( 'sha256', $content ), 0, 16 );
}

/**
 * Whether the page is the one the caller last read.
 *
 * @param string $content Stored content.
 * @param string $expect  Page fingerprint from the last read or write.
 * @return true|WP_Error
 */
function aafm_builder_page_check( string $content, string $expect ) {
	return hash_equals( aafm_builder_page_fingerprint( $content ), $expect ) ? true : aafm_builder_error( 'aafm_builder_page_changed' );
}

/**
 * The elements as nested nodes: tag, atts, text (inner content, set only on a node with no child
 * element), children, and source (the node's exact stored bytes, children included).
 *
 * @param string        $content Content.
 * @param array<string> $tags    Shortcode tags to read.
 * @return list<array<string,mixed>>|null Null when the content cannot be parsed.
 */
function aafm_builder_tree( string $content, array $tags ): ?array {
	$elements = aafm_builder_elements( $content, $tags );
	if ( null === $elements ) {
		return null;
	}
	$children = array();
	foreach ( $elements as $e ) {
		$children[ $e['parent'] ?? -1 ][] = $e['index'];
	}
	$roots = array();
	foreach ( $children[-1] ?? array() as $index ) {
		$roots[] = aafm_builder_tree_node( $content, $elements, $children, $index );
	}
	return $roots;
}

/**
 * One node of aafm_builder_tree(), with its children.
 *
 * @param string                    $content  Content.
 * @param list<array<string,mixed>> $elements Outline.
 * @param array<int,list<int>>      $children Child indexes per parent index.
 * @param int                       $index    Node index.
 * @return array<string,mixed>
 */
function aafm_builder_tree_node( string $content, array $elements, array $children, int $index ): array {
	$e    = $elements[ $index ];
	$kids = array();
	foreach ( $children[ $index ] ?? array() as $child ) {
		$kids[] = aafm_builder_tree_node( $content, $elements, $children, $child );
	}
	return array(
		'tag'      => $e['tag'],
		'atts'     => $e['atts'],
		'text'     => array() === $kids ? substr( $content, $e['open_end'], $e['inner_end'] - $e['open_end'] ) : null,
		'children' => $kids,
		'source'   => substr( $content, $e['start'], $e['end'] - $e['start'] ),
	);
}

/**
 * The element at an outline index, checked against the tag and fingerprint the caller read.
 *
 * @param string              $content Content.
 * @param array<string>       $tags    Shortcode tags the read used.
 * @param int                 $index   Outline index.
 * @param array<string,mixed> $expect  tag and fingerprint.
 * @return array<string,mixed>|WP_Error
 */
function aafm_builder_resolve( string $content, array $tags, int $index, array $expect ) {
	$elements = aafm_builder_elements( $content, $tags );
	if ( null === $elements ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	if ( $index < 0 || $index >= AAFM_BUILDER_OUTLINE_CAP || ! isset( $elements[ $index ] ) ) {
		return aafm_builder_error( 'aafm_builder_element_mismatch' );
	}
	$element = $elements[ $index ];
	if ( ( $expect['tag'] ?? null ) !== $element['tag']
		|| ! is_string( $expect['fingerprint'] ?? null )
		|| ! hash_equals( aafm_builder_fingerprint( $content, $element ), $expect['fingerprint'] ) ) {
		return aafm_builder_error( 'aafm_builder_element_mismatch' );
	}
	return $element;
}

/**
 * Shortcode text for one element in the vendor's own form. Values are written double quoted and
 * must already be encoded by the caller.
 *
 * @param string               $tag   Tag.
 * @param array<string,string> $atts  Attributes, encoded.
 * @param string|null          $inner Inner content, or null for none.
 * @param string               $form  wpbakery (always a closing tag), avada_closed (always a closing
 *                                    tag) or avada_void ([tag ... /]).
 * @return string
 */
function aafm_builder_serialize( string $tag, array $atts, ?string $inner, string $form ): string {
	if ( 'wpbakery' !== $form ) {
		// A1, Ruling B4: Avada drops empty attributes only when its own setting says so, read now.
		$settings = get_option( 'fusion_builder_settings', array() );
		if ( is_array( $settings ) && 'on' === ( $settings['remove_empty_attributes'] ?? 'off' ) ) {
			$atts = array_filter(
				$atts,
				static function ( $value ): bool {
					return '' !== (string) $value;
				}
			);
		}
	}
	$open = '[' . $tag;
	foreach ( $atts as $name => $value ) {
		$open .= ' ' . $name . '="' . $value . '"';
	}
	if ( 'avada_void' === $form ) {
		return $open . ' /]';
	}
	return $open . ']' . (string) $inner . '[/' . $tag . ']';
}
