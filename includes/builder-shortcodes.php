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

/**
 * The closed list of a builder's own post types (design 4.3). The permission check, the ownership
 * rule and the restore rule all read this one list. templatera is not here until S14 (Ruling B13).
 *
 * @param string $builder avada or wpbakery.
 * @return array<string>
 */
function aafm_builder_own_post_types( string $builder ): array {
	$types = array(
		'avada'    => array( 'fusion_tb_section', 'fusion_template', 'fusion_element', 'fusion_form', 'awb_off_canvas' ),
		'wpbakery' => array( 'vc_grid_item' ),
	);
	return $types[ $builder ] ?? array();
}

/**
 * The one statement of the design 4.3 ownership rule. True when the guard answers this builder; or
 * the guard answers false and the post is one of the builder's own types; or, for an insert, the guard
 * answers false and the post is empty.
 *
 * @param string       $builder      avada or wpbakery.
 * @param string|false $guard_answer aafm_post_has_foreign_builder_ownership() as the executor read it.
 * @param WP_Post      $post         Post.
 * @param bool         $empty_insert Whether an insert may start a builder page on an empty post.
 * @return bool
 */
function aafm_builder_ownership_allows( string $builder, $guard_answer, WP_Post $post, bool $empty_insert = false ): bool {
	if ( $guard_answer === $builder ) {
		return true;
	}
	if ( false !== $guard_answer ) {
		return false;
	}
	return in_array( $post->post_type, aafm_builder_own_post_types( $builder ), true )
		|| ( $empty_insert && '' === trim( (string) $post->post_content ) );
}

/**
 * The seam's rows. A row's target is a function name, a class and static method pair, or a closure,
 * never a bare class name; requires names a function that must exist for the row to be present.
 * Each row is added in the session that first calls it (Ruling R3-P4).
 *
 * @return array<string,array{target:string|array{0:string,1:string}|Closure,requires:?string}>
 */
function aafm_builder_vendor_rows(): array {
	return array(
		'wpbakery_css_rebuild' => array(
			'target'   => static function ( int $id, string $type ): void {
				$vc = call_user_func( 'wpbakery' ); // @phpstan-ignore-line argument.type (WPBakery's own accessor; this row is present only when the function exists, see requires)
				if ( ! is_object( $vc ) || ! method_exists( $vc, 'buildShortcodesCss' ) ) {
					throw new RuntimeException( 'WPBakery CSS rebuild is not available.' );
				}
				$vc->buildShortcodesCss( $id, $type );
			},
			'requires' => 'wpbakery',
		),
	);
}

/**
 * The one way a tool reaches vendor code (Ruling B14). Tests force an entry present or absent through
 * the aafm_builder_vendor filter, never by defining a vendor symbol.
 *
 * @param string $entry Entry name.
 * @return callable|null
 */
function aafm_builder_vendor( string $entry ): ?callable {
	$rows     = aafm_builder_vendor_rows();
	$row      = $rows[ $entry ] ?? null;
	$callable = null;
	if ( null !== $row && ( null === $row['requires'] || function_exists( $row['requires'] ) ) && is_callable( $row['target'] ) ) {
		$callable = $row['target'];
	}
	/**
	 * Filters the callable a builder vendor entry resolves to; null means absent.
	 *
	 * @param callable|null $callable Resolved entry.
	 * @param string        $entry    Entry name.
	 */
	$callable = apply_filters( 'aafm_builder_vendor', $callable, $entry );
	return is_callable( $callable ) ? $callable : null;
}

/**
 * The five walker keys of a tuple, in the walker's own order, so outlines compare with ===.
 *
 * @param array<string,mixed> $tuple Tuple or element.
 * @return array{tag:mixed,self_closing:mixed,has_closer:mixed,atts:mixed,depth:mixed}
 */
function aafm_builder_tuple( array $tuple ): array {
	return array(
		'tag'          => $tuple['tag'] ?? null,
		'self_closing' => $tuple['self_closing'] ?? null,
		'has_closer'   => $tuple['has_closer'] ?? null,
		'atts'         => $tuple['atts'] ?? null,
		'depth'        => $tuple['depth'] ?? null,
	);
}

/**
 * The number of tuples in the subtree that starts at $index (the tuple itself included).
 *
 * @param list<array<string,mixed>> $walk  Walker tuples or elements.
 * @param int                       $index Root of the subtree.
 * @return int
 */
function aafm_builder_subtree_size( array $walk, int $index ): int {
	$size  = 1;
	$count = count( $walk );
	while ( $index + $size < $count && (int) $walk[ $index + $size ]['depth'] > (int) $walk[ $index ]['depth'] ) {
		++$size;
	}
	return $size;
}

/**
 * The outline a page must have after one change, worked out from the outline before it.
 *
 * @param list<array<string,mixed>> $walk   Walker tuples before the change.
 * @param array<string,mixed>       $change op update (index, atts), insert (at, depth, tuples with
 *                                         depths relative to 0), move (index, at, depth: the subtree
 *                                         is placed before the original tuple at `at`) or remove (index).
 * @return list<array<string,mixed>>
 */
function aafm_builder_expected_walk( array $walk, array $change ): array {
	$walk  = array_values( array_map( 'aafm_builder_tuple', $walk ) );
	$op    = (string) ( $change['op'] ?? '' );
	$index = (int) ( $change['index'] ?? 0 );

	if ( 'update' === $op ) {
		$walk[ $index ]['atts'] = (array) $change['atts'];
		return $walk;
	}
	if ( 'insert' === $op ) {
		$new = array();
		foreach ( (array) $change['tuples'] as $tuple ) {
			$tuple          = aafm_builder_tuple( (array) $tuple );
			$tuple['depth'] = (int) $tuple['depth'] + (int) $change['depth'];
			$new[]          = $tuple;
		}
		array_splice( $walk, (int) $change['at'], 0, $new );
		return $walk;
	}
	$size = aafm_builder_subtree_size( $walk, $index );
	if ( 'remove' === $op ) {
		array_splice( $walk, $index, $size );
		return $walk;
	}
	// move.
	$subtree = array_slice( $walk, $index, $size );
	$shift   = (int) $change['depth'] - (int) $walk[ $index ]['depth'];
	foreach ( $subtree as $i => $tuple ) {
		$subtree[ $i ]['depth'] = (int) $tuple['depth'] + $shift;
	}
	$at  = (int) $change['at'];
	$out = array();
	foreach ( $walk as $i => $tuple ) {
		if ( $i === $at ) {
			$out = array_merge( $out, $subtree );
		}
		if ( $i < $index || $i >= $index + $size ) {
			$out[] = $tuple;
		}
	}
	if ( $at >= count( $walk ) ) {
		$out = array_merge( $out, $subtree );
	}
	return $out;
}

/**
 * Where a new element goes relative to an anchor element.
 *
 * @param list<array<string,mixed>> $elements  Outline from aafm_builder_elements().
 * @param array<string,mixed>       $anchor    The anchor element.
 * @param string                    $placement before, after, inside_start or inside_end.
 * @return array{offset:int,at:int,depth:int,parent:?int}|WP_Error
 */
function aafm_builder_anchor_point( array $elements, array $anchor, string $placement ) {
	$index = (int) $anchor['index'];
	$after = $index + aafm_builder_subtree_size( $elements, $index );
	if ( 'before' === $placement || 'after' === $placement ) {
		return array(
			'offset' => (int) ( 'before' === $placement ? $anchor['start'] : $anchor['end'] ),
			'at'     => 'before' === $placement ? $index : $after,
			'depth'  => (int) $anchor['depth'],
			'parent' => null === $anchor['parent'] ? null : (int) $anchor['parent'],
		);
	}
	if ( ( 'inside_start' !== $placement && 'inside_end' !== $placement ) || $anchor['self_closing'] || ! $anchor['has_closer'] ) {
		return aafm_builder_error( 'aafm_builder_invalid_nesting' );
	}
	return array(
		'offset' => (int) ( 'inside_start' === $placement ? $anchor['open_end'] : $anchor['inner_end'] ),
		'at'     => 'inside_start' === $placement ? $index + 1 : $after,
		'depth'  => (int) $anchor['depth'] + 1,
		'parent' => $index,
	);
}

/**
 * The walker's tuples for content, normalised for === comparison; null when it cannot be parsed.
 *
 * @param string             $content Content.
 * @param array<string>|null $tags    Tags.
 * @return list<array<string,mixed>>|null
 */
function aafm_builder_walk( string $content, ?array $tags ): ?array {
	$walk = aafm_fusion_shortcode_walk( $content, 0, $tags );
	return null === $walk ? null : array_values( array_map( 'aafm_builder_tuple', $walk ) );
}

/**
 * Steps 3 and 4 of the guard chain, the one copy the commit, avada-replace-text (AV-1) and
 * restore-revision (X-1) all call. Step 3: saving the stored page as this user must leave it as it is.
 * Step 4: the new content, replayed through the same save, must keep the expected outline (for
 * WPBakery with the replayed string's tags merged in). With $exact, the replay must also equal the new
 * content byte for byte (a restore must not lose an embed that is not an element).
 *
 * @param string                    $builder  avada or wpbakery; picks the code prefix.
 * @param int                       $id       Post id the replay sanitizes for.
 * @param string                    $old      Stored content.
 * @param string                    $new      Content about to be saved.
 * @param array<string>             $tags     Tags the outline uses.
 * @param list<array<string,mixed>> $expected Outline the new content must have.
 * @param bool                      $exact    Whether the replay must equal $new byte for byte.
 * @return true|WP_Error
 */
function aafm_builder_replay_check( string $builder, int $id, string $old, string $new, array $tags, array $expected, bool $exact = false ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.newFound -- $new is the contracted parameter name (plan 326 S7-2).
	$prefix = 'wpbakery' === $builder ? 'wpbakery' : 'avada';
	if ( aafm_post_field_canonical_replay( 'post_content', $old, $id ) !== $old ) {
		return new WP_Error(
			'aafm_' . $prefix . '_save_would_change_page',
			__( 'Saving this page as the current user would change content outside the requested change, for example by removing embedded HTML or elements this user is not allowed to keep. Ask a user with fuller rights to make the edit, or make it in the page builder. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	$replayed = aafm_post_field_canonical_replay( 'post_content', $new, $id );
	$all_tags = $tags;
	if ( 'wpbakery' === $builder ) {
		$merged   = aafm_wpbakery_shortcode_tags( $old, $new, $replayed );
		$all_tags = null === $merged ? null : array_values( array_unique( array_merge( $tags, $merged ) ) );
	}
	$walk = null === $all_tags ? null : aafm_builder_walk( $replayed, $all_tags );
	if ( null === $walk || array_values( array_map( 'aafm_builder_tuple', $expected ) ) !== $walk ) {
		return new WP_Error(
			'aafm_' . $prefix . '_save_would_change_layout',
			__( 'Saving the change as the current user would remove or change page builder elements, so it was not saved. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	if ( $exact && $replayed !== $new ) {
		return new WP_Error(
			'aafm_' . $prefix . '_save_would_change_page',
			__( 'Saving this content as the current user would change it, for example by removing embedded HTML this user is not allowed to keep. Ask a user with fuller rights to make the change. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	return true;
}

/**
 * The guarded save every element write goes through (design 4.4, plan 326 steps 0 to 9).
 *
 * @param WP_Post                   $post     Post, as the executor loaded it.
 * @param string                    $old      Stored content the change was worked out from.
 * @param string                    $new      New content.
 * @param array<string>             $tags     Tags the outline uses. For wpbakery the commit scans the
 *                                            tags of $old and $new itself (Ruling R3-PR2-2).
 * @param list<array<string,mixed>> $expected Outline $new must have.
 * @param callable|null             $after    callable( int $post_id, bool $probe ): bool, or null.
 * @param string                    $builder  avada or wpbakery.
 * @return array{post:WP_Post,restore_revision_id:?int,reversible:bool,unchanged:bool,page_fingerprint:string}|WP_Error
 */
function aafm_builder_commit( WP_Post $post, string $old, string $new, array $tags, array $expected, ?callable $after, string $builder ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.newFound -- $new is the contracted parameter name (plan 326 S7-2).
	$id         = (int) $post->ID;
	$prefix     = 'wpbakery' === $builder ? 'wpbakery' : 'avada';
	$reversible = post_type_supports( $post->post_type, 'revisions' ) && wp_revisions_enabled( $post );

	// Step 0 (Ruling R3-PR2-8): nothing to save.
	if ( $new === $old ) {
		return array(
			'post'                => $post,
			'restore_revision_id' => null,
			'reversible'          => $reversible,
			'unchanged'           => true,
			'page_fingerprint'    => aafm_builder_page_fingerprint( $old ),
		);
	}

	// Steps 1 and 2.
	if ( 'wpbakery' === $builder ) {
		$scanned = aafm_wpbakery_shortcode_tags( $old, $new );
		if ( null === $scanned ) {
			return aafm_builder_error( 'aafm_builder_unparseable' );
		}
		$tags = $scanned;
	}
	$old_walk = aafm_builder_walk( $old, $tags );
	$new_walk = aafm_builder_walk( $new, $tags );
	if ( null === $old_walk || null === $new_walk ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$expected = array_values( array_map( 'aafm_builder_tuple', $expected ) );
	if ( $expected !== $new_walk ) {
		return aafm_builder_error( 'aafm_builder_structure_changed' );
	}

	// Steps 3 and 4.
	$replay = aafm_builder_replay_check( $builder, $id, $old, $new, $tags, $expected );
	if ( is_wp_error( $replay ) ) {
		return $replay;
	}

	// The post and its metadata are loaded fresh in a checked read before anything is written, as every
	// content write does, so a failed load refuses instead of writing over a post that was not read.
	if ( ! aafm_exact_object_chain( 'post', $id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// Step 6 runs before step 5 so a refusal does not leave a revision behind.
	if ( null !== $after && ! $after( $id, true ) ) {
		return new WP_Error(
			'aafm_' . $prefix . '_css_rebuild_unavailable',
			__( 'The page builder cannot rebuild this page\'s generated CSS right now, so the change was not saved. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}

	// Step 5: the state before the write as a revision.
	$restore_revision_id = null;
	if ( $reversible ) {
		$saved = wp_save_post_revision( $id );
		if ( is_int( $saved ) && $saved > 0 ) {
			$restore_revision_id = $saved;
		} else {
			// Core saved nothing because its newest real revision already equals the stored content.
			// Find that revision the way wp_save_post_revision() does: newest first, autosaves skipped.
			foreach ( wp_get_post_revisions( $id ) as $revision ) {
				if ( ! $revision instanceof WP_Post || wp_is_post_autosave( $revision ) ) {
					continue;
				}
				if ( $revision->post_content === $old ) {
					$restore_revision_id = (int) $revision->ID;
				}
				break;
			}
		}
		$reversible = null !== $restore_revision_id;
	}

	// Step 7.
	$updated = wp_update_post(
		wp_slash(
			array(
				'ID'           => $id,
				'post_content' => $new,
			)
		),
		true
	);
	if ( is_wp_error( $updated ) || ! $updated ) {
		return aafm_generic_error();
	}

	// Step 8.
	if ( ! aafm_post_field_confirm_logged( $id, 'post_content', $new, $old ) ) {
		return new WP_Error(
			'aafm_' . $prefix . '_write_unconfirmed',
			__( 'The change could not be confirmed as saved. Read the page to check its current state.', 'agent-abilities-for-mcp' ),
			array( 'restore_revision_id' => $restore_revision_id )
		);
	}

	// Step 9.
	if ( null !== $after && ! $after( $id, false ) ) {
		return new WP_Error(
			'aafm_' . $prefix . '_css_stale',
			null === $restore_revision_id
				? __( 'The content was saved, but the page builder could not rebuild the page\'s generated CSS, so the page may look out of date until it is saved again in the builder.', 'agent-abilities-for-mcp' )
				/* translators: %d: revision id. */
				: sprintf( __( 'The content was saved, but the page builder could not rebuild the page\'s generated CSS, so the page may look out of date until it is saved again in the builder. Revision %d restores the content as it was before.', 'agent-abilities-for-mcp' ), $restore_revision_id ),
			array( 'restore_revision_id' => $restore_revision_id )
		);
	}

	$fresh = aafm_exact_object( 'post', $id );
	if ( ! $fresh instanceof WP_Post ) {
		return aafm_generic_error();
	}
	return array(
		'post'                => $fresh,
		'restore_revision_id' => $restore_revision_id,
		'reversible'          => $reversible,
		'unchanged'           => false,
		'page_fingerprint'    => aafm_builder_page_fingerprint( (string) $fresh->post_content ),
	);
}
