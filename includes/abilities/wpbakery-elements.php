<?php
/**
 * WPBakery Page Builder element tools: the element map through the vendor seam, the kind of every setting,
 * the encoder that turns typed input into the form WPBakery 9.1 stores (the inverse of the decoder in
 * wpbakery.php), and the abilities that read the element catalog and change one element.
 *
 * Design 325 sections 4.2, 4.7 and 9, plan 326 Session 9a. Vendor code is reached only through
 * aafm_builder_vendor() (Ruling B14). A setting's param type comes only from the seam row
 * wpbakery_param_type, the row the decoder reads; the map entry supplies labels, defaults, value lists and
 * limits. Every setting that can carry script needs unfiltered_html, checked before anything is encoded.
 *
 * Registers ONLY while WPBakery Page Builder is active (aafm_integration_active('wpbakery')).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_wpbakery_elements_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wpbakery_elements_full_definitions' );

/**
 * Contribute the WPBakery element ability definitions, only while WPBakery is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wpbakery_elements_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'wpbakery' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_wpbakery_elements_registry_definitions() );
}

/**
 * Contribute the WPBakery element ability definitions to the host-independent full registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wpbakery_elements_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wpbakery_elements_registry_definitions() );
}

/**
 * The WPBakery element ability rows.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wpbakery_elements_registry_definitions(): array {
	return array(
		'aafm/wpbakery-list-elements'  => array(
			'label'        => __( 'List WPBakery elements', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists the elements WPBakery Page Builder has on this site, add-ons included, from WPBakery\'s own element map: tag, name, category, whether it holds other elements, the elements it allows inside it and the ones it may sit in, and whether it is deprecated. With tag it returns one element with its settings: name, kind, label, default, the allowed values of a choice (at most 100), whether wpbakery-update-element can write it, and needs, which is unfiltered_html for Design Options and for settings that can carry script or raw HTML. With context grid it lists the Grid Builder\'s elements too. An element an add-on maps only inside wp-admin is not listed. Requires the edit_posts capability; WPBakery\'s Role Manager settings are not applied. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_list_elements',
		),
		'aafm/wpbakery-update-element' => array(
			'label'        => __( 'Update a WPBakery element', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Changes the settings or the inner text of one element on a WPBakery page or Grid Builder template, named by its index and by the tag and fingerprint wpbakery-get-page-content returned with include_structure. set_atts sets settings, each value in the form wpbakery-list-elements gives as its kind (a link as url, title, target and rel; an image as an attachment id; Design Options as fields such as margin_top, never as CSS); remove_atts returns settings to their default; text replaces the inner content of an element that has a content setting and no child elements. Only a user who may post unfiltered HTML can put square brackets, percent-encoded brackets or data-* attributes in body text, and even then text that would add an element is refused. Only the element\'s opening tag and, for text, its inner content change; every other byte of the page stays as it was. Refused: a stale index or fingerprint, a setting the element does not have or that cannot be written, a value its kind does not accept, already encoded values, and any change that would add or remove an element. Design Options and settings that can carry script or raw HTML need a user who may post unfiltered HTML. A save that would change other content for this user is refused, and nothing is saved. When a revision is kept, restore_revision_id brings the page back through restore-revision; the response also returns the element\'s new fingerprint and the page fingerprint. The outline stops at 2,000 elements. Not undoable from this plugin\'s change history. Requires edit access to the post; WPBakery\'s Role Manager settings are not applied, though its own save filter still runs. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_update_element',
		),
		'aafm/wpbakery-insert-element' => array(
			'label'        => __( 'Insert a WPBakery element', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Adds an element with its children to a WPBakery page or Grid Builder template, before or after an element from the outline of wpbakery-get-page-content or as its first or last child; element takes tag, atts in the input form of each kind from wpbakery-list-elements, text and children. WPBakery\'s own nesting rules apply: the top level takes rows and sections, a row takes columns, a column takes content elements or an inner row. At most 50 elements, nested at most 6 deep. On an empty post with no builder, leave out the anchor to start the page; this one check follows WPBakery\'s own setting for which post types its editor is on for. Raw HTML, raw JS, maps, custom onclick settings and Design Options need a user who may post unfiltered HTML, and only such a user can put square brackets, percent-encoded brackets or data-* attributes in body text. Requires page_fingerprint from the last read or write and returns the new one; a changed page is refused and nothing is saved. Other indexes may move, so read the outline again before the next structural change. When a revision is kept, restore_revision_id brings the page back through restore-revision. The page\'s generated CSS is rebuilt. Not undoable from this plugin\'s change history. Requires edit access to the post; WPBakery\'s Role Manager settings are not applied, though its own save filter still runs. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_insert_element',
		),
		'aafm/wpbakery-move-element'   => array(
			'label'        => __( 'Move a WPBakery element', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Moves one element, with everything inside it, on a WPBakery page or Grid Builder template to a new place named against another element from the outline of wpbakery-get-page-content: before or after it, or as its first or last child. The element\'s stored text moves byte for byte; nothing in it is rewritten. WPBakery\'s own nesting rules apply to the new place, and a move into the element\'s own children or one that leaves a row with no column is refused. Requires page_fingerprint from the last read or write and returns the new one; a changed page is refused and nothing is saved. A move to the place the element already has saves nothing and returns moved false. Other indexes may move, so read the outline again before the next structural change. When a revision is kept, restore_revision_id brings the page back through restore-revision. The page\'s generated CSS is rebuilt. Not undoable from this plugin\'s change history. Requires edit access to the post; WPBakery\'s Role Manager settings are not applied, though its own save filter still runs. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_move_element',
		),
		'aafm/wpbakery-remove-element' => array(
			'label'        => __( 'Remove a WPBakery element', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Removes one element, with everything inside it, from a WPBakery page or Grid Builder template, named by its index and by the tag and fingerprint from the outline of wpbakery-get-page-content. The fingerprint covers the whole element, so one that gained content since the read is refused. Refused when the post keeps no revisions (a Grid Builder template keeps none) or the site keeps only one, for the page\'s last row or section, and when a row would be left with no column. Requires page_fingerprint from the last read or write and returns the new one; a changed page is refused and nothing is saved. Other indexes may move, so read the outline again before the next structural change. restore_revision_id brings the page back through restore-revision. The page\'s generated CSS is rebuilt. Not undoable from this plugin\'s change history. Requires edit access to the post; WPBakery\'s Role Manager settings are not applied, though its own save filter still runs. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_remove_element',
		),
	);
}

/**
 * The map refusal: WPBakery's map is missing or empty in this request.
 *
 * @return WP_Error
 */
function aafm_wpbakery_map_unavailable(): WP_Error {
	return new WP_Error(
		'aafm_wpbakery_map_unavailable',
		__( 'WPBakery\'s element map is not available in this request, so its elements cannot be read or written here. Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 409 )
	);
}

/**
 * WPBakery's element map, through the vendor seam. With context grid the Grid Builder's mapper runs first,
 * because WPBakery maps those elements only in a grid editor request (W18, Ruling B6). Each element is resolved
 * on its own (WPBMap::getShortCode(), which getAllShortCodes() also calls per element), so one entry that
 * throws or does not resolve is skipped and counted instead of failing the whole map.
 *
 * @param string      $context page or grid.
 * @param string|null $tag     One element, or null for all.
 * @param int         $skipped Entries skipped, added to.
 * @return array<string,mixed>|WP_Error All elements keyed by tag, one element's settings, or a refusal:
 *                                      aafm_wpbakery_map_unavailable (409), or aafm_builder_unknown_element
 *                                      (400) for a tag the map does not have.
 */
function aafm_wpbakery_map( string $context = 'page', ?string $tag = null, int &$skipped = 0 ) {
	if ( 'grid' === $context ) {
		$mapper = aafm_builder_vendor( 'wpbakery_grid_mapper' );
		if ( null === $mapper ) {
			return aafm_wpbakery_map_unavailable();
		}
		try {
			$mapper();
		} catch ( Throwable $e ) {
			return aafm_wpbakery_map_unavailable();
		}
	}
	$one = aafm_builder_vendor( 'wpbakery_map_one' );
	if ( null === $one ) {
		return aafm_wpbakery_map_unavailable();
	}
	if ( null !== $tag ) {
		try {
			$settings = $one( $tag );
		} catch ( Throwable $e ) {
			return aafm_wpbakery_map_unavailable();
		}
		if ( is_array( $settings ) ) {
			return $settings;
		}
		if ( in_array( $tag, array( 'vc_flexbox_container', 'vc_flexbox_container_item', 'vc_grid_container', 'vc_grid_container_item' ), true ) ) {
			// Demo #6a: WPBakery 9.1 ships these four tags without registering them, so say so rather than "not known".
			return new WP_Error(
				'aafm_builder_unknown_element',
				__( 'WPBakery does not register its flexbox and grid containers on this site, so elements cannot be added to them or moved into them. Elements already inside can be edited, reordered or removed. Nothing was changed.', 'agent-abilities-for-mcp' ),
				array( 'status' => 400 )
			);
		}
		return aafm_builder_error( 'aafm_builder_unknown_element' );
	}
	$all = aafm_builder_vendor( 'wpbakery_map_all' );
	if ( null === $all ) {
		return aafm_wpbakery_map_unavailable();
	}
	try {
		$entries = $all();
	} catch ( Throwable $e ) {
		return aafm_wpbakery_map_unavailable();
	}
	if ( ! is_array( $entries ) || array() === $entries ) {
		return aafm_wpbakery_map_unavailable();
	}
	$map = array();
	foreach ( array_keys( $entries ) as $name ) {
		try {
			$settings = $one( (string) $name );
		} catch ( Throwable $e ) {
			++$skipped;
			continue;
		}
		if ( ! is_array( $settings ) ) {
			++$skipped;
			continue;
		}
		$map[ (string) $name ] = $settings;
	}
	return array() === $map ? aafm_wpbakery_map_unavailable() : $map;
}

/**
 * WPBakery's custom HTML elements (W12): the fixed baseline, always gated, plus whatever an add-on adds through
 * WPBakery's own filter at call time. The filter can only add (Ruling R-S9a-3).
 *
 * @return array<string>
 */
function aafm_wpbakery_custom_html_elements(): array {
	$baseline = array( 'vc_raw_html', 'vc_raw_js', 'vc_gmaps' );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPBakery's own filter (Vc_Post_Admin::get_elements_with_custom_html()), read so an add-on's script element is gated too.
	$filtered = apply_filters( 'wpb_custom_html_elements', $baseline );
	$added    = is_array( $filtered ) ? array_filter( $filtered, 'is_string' ) : array();
	return array_values( array_unique( array_merge( $baseline, $added ) ) );
}

/**
 * The kind a param type is written as (design 4.7, WPBakery table), before the script name rule. '' for a type
 * the encoder does not know.
 *
 * @param string $type Param type from the map.
 * @param string $name Setting name.
 * @return string
 */
function aafm_wpbakery_type_kind( string $type, string $name ): string {
	if ( 'content' === $name ) {
		return in_array( $type, array( 'textarea_ace', 'textarea_raw_html' ), true ) ? 'raw' : 'content';
	}
	if ( 'el_class' === $name && 'textfield' === $type ) {
		return 'token';
	}
	$kinds = array(
		'textfield'              => 'text',
		'textarea'               => 'text',
		'el_id'                  => 'text',
		'textarea_safe'          => 'safe_text',
		'exploded_textarea_safe' => 'safe_text',
		'exploded_textarea'      => 'list',
		'vc_link'                => 'link',
		'link'                   => 'link',
		'href'                   => 'link',
		'attach_image'           => 'image',
		'attach_images'          => 'image',
		'dropdown'               => 'choice',
		'button_group'           => 'choice',
		'checkbox'               => 'choice',
		'toggle'                 => 'choice',
		'animation_style'        => 'choice',
		'colorpicker'            => 'colour',
		'number'                 => 'number',
		'range'                  => 'number',
		'iconpicker'             => 'token',
		'param_group'            => 'group',
		'font_container'         => 'group',
		'google_fonts'           => 'group',
		'css_editor'             => 'design',
		'textarea_ace'           => 'raw',
		'textarea_raw_html'      => 'raw',
	);
	return $kinds[ $type ] ?? '';
}

/**
 * A setting's param type, from the seam row the decoder also reads (one source, s8a-review-r3 note 2).
 *
 * @param string $tag  Tag.
 * @param string $name Setting name.
 * @return string|null Null when the map does not know it.
 */
function aafm_wpbakery_param_type( string $tag, string $name ): ?string {
	$row = aafm_builder_vendor( 'wpbakery_param_type' );
	if ( null === $row || '' === $name ) {
		return null;
	}
	$type = $row( $tag, $name );
	return is_string( $type ) ? $type : null;
}

/**
 * The kind, whether the update tool can write it, and the capability it needs. The type comes from the seam row;
 * then the name rule wins (design section 9, Ruling B1): every setting of an element on the custom HTML list and
 * every setting named like WPBakery's custom onclick is raw.
 *
 * @param string              $tag   Tag.
 * @param array<string,mixed> $param The map's param (labels, values, limits; its own type is not read).
 * @return array{kind:string,writable:bool,needs:string}
 */
function aafm_wpbakery_param_kind( string $tag, array $param ): array {
	$name     = isset( $param['param_name'] ) && is_string( $param['param_name'] ) ? $param['param_name'] : '';
	$type     = aafm_wpbakery_param_type( $tag, $name );
	$base     = null === $type ? '' : aafm_wpbakery_type_kind( $type, $name );
	$writable = '' !== $base && 1 === preg_match( '/^[a-z0-9_]+$/', $name );
	if ( $writable && 'choice' === $base ) {
		$param['type'] = $type;
		$writable      = array() !== aafm_wpbakery_choice_values( $param );
	}
	$scripted = in_array( $tag, aafm_wpbakery_custom_html_elements(), true ) || 1 === preg_match( '/(^|_)custom_onclick(_code)?$/', $name );
	$kind     = $scripted ? 'raw' : ( '' === $base ? 'none' : $base );
	return array(
		'kind'     => $kind,
		'writable' => $writable,
		'needs'    => in_array( $kind, array( 'design', 'raw' ), true ) ? 'unfiltered_html' : '',
	);
}

/**
 * The values a choice setting allows, from the map: a dropdown's values, a button group's keys, a toggle's
 * checked value or empty.
 *
 * @param array<string,mixed> $param Param with its type.
 * @return array<string>
 */
function aafm_wpbakery_choice_values( array $param ): array {
	$type  = (string) ( $param['type'] ?? '' );
	$value = $param['value'] ?? null;
	if ( 'toggle' === $type ) {
		$checked = is_array( $value ) ? (string) current( $value ) : ( is_scalar( $value ) ? (string) $value : 'true' );
		return array_values( array_unique( array( '' === $checked ? 'true' : $checked, '' ) ) );
	}
	if ( ! is_array( $value ) ) {
		return array();
	}
	$out = array();
	foreach ( $value as $key => $option ) {
		if ( 'button_group' === $type ) {
			$out[] = (string) $key;
		} elseif ( is_scalar( $option ) ) {
			$out[] = (string) $option;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * The script gate (design section 9): design and raw kinds need unfiltered_html, checked before any value is
 * encoded so a refused call never builds the script.
 *
 * @param string $kind Kind from aafm_wpbakery_param_kind().
 * @return true|WP_Error
 */
function aafm_wpbakery_script_gate( string $kind ) {
	if ( in_array( $kind, array( 'design', 'raw' ), true ) && ! current_user_can( 'unfiltered_html' ) ) {
		return aafm_builder_error( 'aafm_builder_needs_unfiltered_html' );
	}
	return true;
}

/**
 * A refusal that names the setting, so the agent sees which one in the error message.
 *
 * @param string $code aafm_builder_invalid_value or aafm_builder_attribute_not_writable.
 * @param string $name Setting.
 * @return WP_Error
 */
function aafm_wpbakery_setting_error( string $code, string $name ): WP_Error {
	$error = aafm_builder_error( $code, array( 'setting' => $name ) );
	return new WP_Error(
		$code,
		/* translators: 1: the refusal, 2: the setting name. */
		sprintf( __( '%1$s Setting: %2$s.', 'agent-abilities-for-mcp' ), $error->get_error_message(), $name ),
		$error->get_error_data()
	);
}

/**
 * URL-encodes like the editor's encodeURIComponent(): rawurlencode() with ! * ' ( ) left as they are.
 *
 * @param string $value Value.
 * @return string
 */
function aafm_wpbakery_uri_encode( string $value ): string {
	return strtr(
		rawurlencode( $value ),
		array(
			'%21' => '!',
			'%2A' => '*',
			'%27' => "'",
			'%28' => '(',
			'%29' => ')',
		)
	);
}

/**
 * W2: the escape WPBakery applies to every attribute value, reversed by the decoder.
 *
 * @param string $value Value.
 * @return string
 */
function aafm_wpbakery_escape_attribute( string $value ): string {
	return str_replace( array( '"', '[', ']' ), array( '``', '`{`', '`}`' ), $value );
}

/**
 * A scalar text input as a string: a number is accepted as its string form, anything else is refused.
 *
 * @param mixed $value Input.
 * @return string|null
 */
function aafm_wpbakery_scalar_text( $value ): ?string {
	if ( is_string( $value ) ) {
		return $value;
	}
	if ( is_int( $value ) || is_float( $value ) ) {
		return (string) $value;
	}
	return null;
}

/**
 * A link URL: http, https, mailto or tel, a site-relative path or an #anchor, accepted only as written.
 *
 * @param string $url URL.
 * @return string|null Null when refused; '' for empty.
 */
function aafm_wpbakery_link_url( string $url ): ?string {
	$url = trim( $url );
	if ( '' === $url ) {
		return '';
	}
	if ( '#' === $url[0] ) {
		return 1 === preg_match( '/^#[A-Za-z0-9_\-:.]*$/', $url ) ? $url : null;
	}
	if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
		return esc_url_raw( $url ) === $url ? $url : null;
	}
	if ( 1 !== preg_match( '/^(https?|mailto|tel):/i', $url ) ) {
		return null;
	}
	$clean = esc_url_raw( $url, array( 'http', 'https', 'mailto', 'tel' ) );
	return '' !== $clean && $clean === $url ? $url : null;
}

/**
 * A CSS length: a number and a unit, the units WPBakery's Design Options accept.
 *
 * @param mixed $value Value.
 * @return bool
 */
function aafm_wpbakery_is_length( $value ): bool {
	return is_string( $value ) && 1 === preg_match( '/^-?\d*\.?\d+(px|%|in|cm|mm|em|rem|ex|pt|pc|vw|vh|vmin|vmax)$/', $value );
}

/**
 * A colour: hex, or rgb() or rgba() with numeric parts.
 *
 * @param mixed $value Value.
 * @return bool
 */
function aafm_wpbakery_is_colour( $value ): bool {
	return is_string( $value ) && (
		1 === preg_match( '/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value )
		|| 1 === preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $value )
	);
}

/**
 * The stored attribute text for a typed value (design 4.7), or a refusal naming the setting. The encoder is the
 * inverse of aafm_wpbakery_decode_atts() and is checked against the bytes WPBakery's editor stored. Every text
 * value goes through wp_kses_normalize_entities() before the vendor escape (Ruling B9). A value already in an
 * encoded form is never accepted (design section 9). The caller runs aafm_wpbakery_script_gate() first.
 *
 * @param string              $kind  Kind from aafm_wpbakery_param_kind().
 * @param mixed               $value The input form of the kind.
 * @param array<string,mixed> $param The map's param, with type set from the param type row.
 * @return string|WP_Error
 */
function aafm_wpbakery_encode_value( string $kind, $value, array $param ) {
	$name = isset( $param['param_name'] ) && is_string( $param['param_name'] ) ? $param['param_name'] : '';
	$type = isset( $param['type'] ) && is_string( $param['type'] ) ? $param['type'] : '';
	$base = aafm_wpbakery_type_kind( $type, $name );
	if ( 'content' === $kind ) {
		// Body text goes through the shared choke point, whose refusal is returned as it is.
		return is_string( $value ) ? aafm_wpbakery_body_text( $value ) : aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $name );
	}
	$clean = 'raw' === $kind ? aafm_wpbakery_encode_raw( $base, $value, $param ) : aafm_wpbakery_encode_clean( $kind, $value, $param );
	if ( null === $clean ) {
		return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $name );
	}
	return $clean;
}

/**
 * A raw-kind value: W7 (base64 of the URL-encoded value) for a raw HTML type, the W17 form for a safe text
 * setting with tags kept, the plain escaped text for a text setting (an onclick code), and the base kind's own
 * form for anything else on a listed element. Only reached behind the gate.
 *
 * @param string              $base  Kind of the type before the name rule.
 * @param mixed               $value Input.
 * @param array<string,mixed> $param Param.
 * @return string|null
 */
function aafm_wpbakery_encode_raw( string $base, $value, array $param ): ?string {
	if ( 'raw' === $base ) {
		return is_string( $value ) ? base64_encode( aafm_wpbakery_uri_encode( $value ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WPBakery's own raw element form (W7), behind unfiltered_html.
	}
	if ( 'safe_text' === $base || 'text' === $base ) {
		$text = aafm_wpbakery_scalar_text( $value );
		$text = null === $text ? null : aafm_wpbakery_decode_entities( $text );
		if ( null === $text || 0 === strpos( $text, '#E-8_' ) || ( 'text' === $base && 1 === preg_match( '/[\r\n]/', $text ) ) ) {
			return null;
		}
		return 'safe_text' === $base ? aafm_wpbakery_safe_form( $text ) : aafm_wpbakery_escape_attribute( aafm_wpbakery_editor_entities( wp_kses_normalize_entities( $text ) ) );
	}
	return '' === $base ? null : aafm_wpbakery_encode_clean( $base, $value, $param );
}

/**
 * W17: #E-8_ plus base64 of the URL-encoded value when it holds a double quote or http, else the value escaped
 * as typed (with its entities normalised, since kses reads that form).
 *
 * @param string $text Value.
 * @return string
 */
function aafm_wpbakery_safe_form( string $text ): string {
	if ( false !== strpos( $text, '"' ) || false !== strpos( $text, 'http' ) ) {
		return '#E-8_' . base64_encode( aafm_wpbakery_uri_encode( $text ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WPBakery's own safe text form (W17), built by the plugin from checked input.
	}
	return aafm_wpbakery_escape_attribute( aafm_wpbakery_editor_entities( wp_kses_normalize_entities( $text ) ) );
}

/**
 * A non-raw value in its stored form (escaped where the kind stores plain text), or null when refused.
 *
 * @param string              $kind  Kind.
 * @param mixed               $value Input.
 * @param array<string,mixed> $param Param with type.
 * @return string|null
 */
function aafm_wpbakery_encode_clean( string $kind, $value, array $param ): ?string {
	$type = (string) ( $param['type'] ?? '' );
	switch ( $kind ) {
		case 'text':
			$text = aafm_wpbakery_plain_text( $value, 'textarea' === $type );
			return null === $text ? null : aafm_wpbakery_escape_attribute( $text );
		case 'safe_text':
			return aafm_wpbakery_encode_safe_text( $value, 'exploded_textarea_safe' === $type );
		case 'list':
			if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
				return null;
			}
			$items = array();
			foreach ( $value as $item ) {
				$text = aafm_wpbakery_plain_text( $item );
				if ( null === $text || false !== strpos( $text, ',' ) ) {
					return null;
				}
				$items[] = $text;
			}
			return aafm_wpbakery_escape_attribute( implode( ',', $items ) );
		case 'content':
			// Reached only from aafm_wpbakery_encode_value(), which sends body text to the shared choke point first.
			$body = is_string( $value ) ? aafm_wpbakery_body_text( $value ) : null;
			return is_string( $body ) ? $body : null;
		case 'link':
			return aafm_wpbakery_encode_link( $value, 'href' === $type );
		case 'image':
			return aafm_wpbakery_encode_image( $value, 'attach_images' === $type );
		case 'choice':
			$choice = aafm_wpbakery_encode_choice( $value, $param );
			return null === $choice ? null : aafm_wpbakery_escape_attribute( $choice );
		case 'colour':
			return aafm_wpbakery_is_colour( $value ) ? (string) $value : null;
		case 'number':
			return aafm_wpbakery_encode_number( $value, $param );
		case 'token':
			return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9 _-]*$/', $value ) ? $value : null;
		case 'group':
			return aafm_wpbakery_encode_group( $value, $param );
		case 'design':
			return aafm_wpbakery_encode_design( $value );
	}
	return null;
}

/**
 * Plain text: the plugin's stored-text sanitizer, then entity normalisation (Ruling B9). One line, unless the setting
 * is WPBakery's textarea type, whose editor stores a line break as CRLF inside the attribute and keeps it on its next
 * save (smoke probe, S9a fix round 1). An encoded #E-8_ value is refused.
 *
 * @param mixed $value     Input.
 * @param bool  $multiline Whether line breaks are kept.
 * @return string|null
 */
function aafm_wpbakery_plain_text( $value, bool $multiline = false ): ?string {
	$text = aafm_wpbakery_scalar_text( $value );
	// Decoded first, so the line break refusal and the sanitizer's control strip see what the editor will save.
	$text = null === $text ? null : aafm_wpbakery_decode_entities( $text );
	if ( null === $text || ( ! $multiline && 1 === preg_match( '/[\r\n]/', $text ) ) || 0 === strpos( $text, '#E-8_' ) ) {
		return null;
	}
	if ( ! $multiline ) {
		return aafm_wpbakery_editor_entities( wp_kses_normalize_entities( aafm_sanitize_plain_text( $text ) ) );
	}
	// The editor's form post stores each line break as CRLF.
	$clean = aafm_sanitize_multiline_text( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
	return str_replace( "\n", "\r\n", aafm_wpbakery_editor_entities( wp_kses_normalize_entities( $clean ) ) );
}

/**
 * Attribute text in the form WPBakery 9.1's editor saves it. The editor reads the page through TinyMCE, which decodes
 * every entity and re-encodes only & < and > (smoke probes S9-5a and S9a fix round 1). Any other entity in stored
 * attribute text changes on the editor's next save, and a quote or bracket entity ends the attribute or the tag. So
 * every entity is decoded and only & < and > are encoded again; W2 then escapes a double quote or a bracket. Core's
 * sanitize_text_field() produces quote entities for text after a lone <.
 *
 * @param string $text Text with entities normalised.
 * @return string
 */
function aafm_wpbakery_editor_entities( string $text ): string {
	return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * Safe text (Ruling B5): tags stripped, a value that looks like a URL must pass the link check, then the W17
 * form. The exploded type takes a list of strings or line breaks and joins them with commas, as the editor does.
 *
 * @param mixed $value    Input.
 * @param bool  $exploded Whether the type is exploded_textarea_safe.
 * @return string|null
 */
function aafm_wpbakery_encode_safe_text( $value, bool $exploded ): ?string {
	if ( $exploded && is_array( $value ) ) {
		if ( array_values( $value ) !== $value || array() !== array_filter( $value, static fn( $item ): bool => ! is_string( $item ) ) ) {
			return null;
		}
		$value = implode( ',', $value );
	}
	if ( ! is_string( $value ) ) {
		return null;
	}
	// Decoded first, so the encoded-value check, the scheme check and the #E-8_ choice see what the editor saves.
	// A control character spelled as an entity is a real one after the decode, so the unsafe ones are stripped here.
	$value = str_replace( aafm_unsafe_text_characters(), '', aafm_wpbakery_decode_entities( $value ) );
	if ( 0 === strpos( $value, '#E-8_' ) ) {
		return null;
	}
	$text = wp_strip_all_tags( $exploded ? (string) preg_replace( '/\r\n|\r|\n/', ',', $value ) : $value );
	foreach ( $exploded ? explode( ',', $text ) : array( $text ) as $part ) {
		$part = trim( $part );
		if ( 1 === preg_match( '/^([a-z][a-z0-9+.\-]*:|\/\/)/i', $part ) && null === aafm_wpbakery_link_url( $part ) ) {
			return null;
		}
	}
	return aafm_wpbakery_safe_form( $text );
}

/**
 * A link (W3): url, title, target and rel, only the non-empty parts, trimmed, each encoded like
 * encodeURIComponent(). The href type takes a bare URL.
 *
 * @param mixed $value Input.
 * @param bool  $href  Whether the type is href.
 * @return string|null
 */
function aafm_wpbakery_encode_link( $value, bool $href ): ?string {
	if ( $href ) {
		// Stored bare, so decoded first (the editor's next save would decode an entity) and any bracket percent-encoded.
		$url = is_string( $value ) ? aafm_wpbakery_link_url( str_replace( array( '[', ']' ), array( '%5B', '%5D' ), aafm_wpbakery_decode_entities( $value ) ) ) : null;
		return null === $url ? null : aafm_wpbakery_escape_attribute( $url );
	}
	if ( ! is_array( $value ) || array() !== array_diff( array_keys( $value ), array( 'url', 'title', 'target', 'rel' ) ) ) {
		return null;
	}
	$parts = array();
	foreach ( array( 'url', 'title', 'target', 'rel' ) as $key ) {
		$part = $value[ $key ] ?? '';
		if ( ! is_string( $part ) ) {
			return null;
		}
		$part = trim( $part );
		if ( 'url' === $key ) {
			$part = aafm_wpbakery_link_url( $part );
		} elseif ( 'title' === $key ) {
			$part = 1 === preg_match( '/[\r\n]/', $part ) ? null : wp_kses_normalize_entities( aafm_sanitize_plain_text( $part ) );
		} elseif ( 'target' === $key ) {
			$part = in_array( $part, array( '', '_self', '_blank' ), true ) ? $part : null;
		} else {
			$tokens = '' === $part ? array() : (array) preg_split( '/\s+/', $part );
			$part   = array() === array_diff( $tokens, array( 'nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc', 'external' ) ) ? $part : null;
		}
		if ( null === $part ) {
			return null;
		}
		if ( '' !== $part ) {
			$parts[] = $key . ':' . aafm_wpbakery_uri_encode( $part );
		}
	}
	return implode( '|', $parts );
}

/**
 * An image id, or a list of ids for attach_images; each must be an existing image attachment.
 *
 * @param mixed $value Input.
 * @param bool  $many  Whether the type takes several.
 * @return string|null
 */
function aafm_wpbakery_encode_image( $value, bool $many ): ?string {
	$ids = $many ? $value : array( $value );
	if ( ! is_array( $ids ) || array() === $ids || array_values( $ids ) !== $ids ) {
		return null;
	}
	foreach ( $ids as $id ) {
		if ( ! is_int( $id ) || $id < 1 ) {
			return null;
		}
		$attachment = aafm_exact_object( 'post', $id );
		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $id ) ) {
			return null;
		}
	}
	return implode( ',', $ids );
}

/**
 * A choice: one of the values the map lists (a checkbox also takes a list, joined with commas), unescaped.
 *
 * @param mixed               $value Input.
 * @param array<string,mixed> $param Param with type.
 * @return string|null
 */
function aafm_wpbakery_encode_choice( $value, array $param ): ?string {
	$allowed = aafm_wpbakery_choice_values( $param );
	$values  = 'checkbox' === ( $param['type'] ?? '' ) && is_array( $value ) ? $value : array( $value );
	$out     = array();
	foreach ( $values as $one ) {
		$one = is_int( $one ) ? (string) $one : $one;
		if ( ! is_string( $one ) || ! in_array( $one, $allowed, true ) ) {
			return null;
		}
		$out[] = $one;
	}
	return array() === $out ? null : implode( ',', $out );
}

/**
 * A number inside the map's minimum and maximum (settings min and max, or min and max on the param).
 *
 * @param mixed               $value Input; a numeric string is accepted, as a decoded param group row carries one.
 * @param array<string,mixed> $param Param.
 * @return string|null
 */
function aafm_wpbakery_encode_number( $value, array $param ): ?string {
	if ( is_string( $value ) && 1 === preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
		$value = false === strpos( $value, '.' ) ? (int) $value : (float) $value;
	}
	if ( ! is_int( $value ) && ! is_float( $value ) ) {
		return null;
	}
	$settings = is_array( $param['settings'] ?? null ) ? $param['settings'] : array();
	$min      = $settings['min'] ?? $param['min'] ?? null;
	$max      = $settings['max'] ?? $param['max'] ?? null;
	if ( ( is_numeric( $min ) && $value < (float) $min ) || ( is_numeric( $max ) && $value > (float) $max ) ) {
		return null;
	}
	return is_int( $value ) ? (string) $value : rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' );
}

/**
 * A group: param_group rows (W6, each field through its own kind, the whole list as URL-encoded JSON), or the
 * pair form of font_container and google_fonts (W3).
 *
 * @param mixed               $value Input.
 * @param array<string,mixed> $param Param with type.
 * @return string|null
 */
function aafm_wpbakery_encode_group( $value, array $param ): ?string {
	$type = (string) ( $param['type'] ?? '' );
	if ( 'font_container' === $type || 'google_fonts' === $type ) {
		return aafm_wpbakery_encode_pairs( $value, $type, $param );
	}
	if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
		return null;
	}
	$fields = array();
	foreach ( (array) ( $param['params'] ?? array() ) as $field ) {
		if ( is_array( $field ) && isset( $field['param_name'], $field['type'] ) && is_string( $field['param_name'] ) && is_string( $field['type'] ) ) {
			$fields[ $field['param_name'] ] = $field;
		}
	}
	$rows = array();
	foreach ( $value as $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$encoded = array();
		foreach ( $row as $name => $field_value ) {
			$field = $fields[ (string) $name ] ?? null;
			$kind  = null === $field ? '' : aafm_wpbakery_type_kind( (string) $field['type'], (string) $name );
			if ( ! in_array( $kind, array( 'text', 'number', 'choice', 'colour', 'token', 'image' ), true ) ) {
				return null;
			}
			// Inside URL-encoded JSON the W2 escape is not applied.
			if ( 'text' === $kind ) {
				$one = aafm_wpbakery_plain_text( $field_value );
			} elseif ( 'choice' === $kind ) {
				$one = aafm_wpbakery_encode_choice( $field_value, $field );
			} else {
				$one = aafm_wpbakery_encode_clean( $kind, $field_value, $field );
			}
			if ( null === $one ) {
				return null;
			}
			$encoded[ (string) $name ] = $one;
		}
		$rows[] = array() === $encoded ? new stdClass() : $encoded;
	}
	$json = wp_json_encode( $rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	return false === $json ? null : aafm_wpbakery_uri_encode( $json );
}

/**
 * The font_container (tag, text_align, font_size, line_height, color) or google_fonts (font_family, font_style) in
 * the key:value pair form, keys in the map's field order.
 *
 * @param mixed               $value Input object.
 * @param string              $type  font_container or google_fonts.
 * @param array<string,mixed> $param Param.
 * @return string|null
 */
function aafm_wpbakery_encode_pairs( $value, string $type, array $param ): ?string {
	if ( ! is_array( $value ) ) {
		return null;
	}
	$keys = 'google_fonts' === $type ? array( 'font_family', 'font_style' ) : array( 'tag', 'text_align', 'font_size', 'line_height', 'color' );
	if ( 'font_container' === $type && is_array( $param['settings']['fields'] ?? null ) ) {
		$order = array_values( array_intersect( array_map( 'strval', array_keys( $param['settings']['fields'] ) ), $keys ) );
		$keys  = array_values( array_unique( array_merge( $order, $keys ) ) );
	}
	if ( array() !== array_diff( array_keys( $value ), $keys ) ) {
		return null;
	}
	$size  = '/^\d+(\.\d+)?(px|em|rem|%|pt|vw|vh)?$/';
	$rules = array(
		'tag'         => static fn( string $v ): bool => in_array( $v, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div' ), true ),
		'text_align'  => static fn( string $v ): bool => in_array( $v, array( 'left', 'right', 'center', 'justify' ), true ),
		'font_size'   => static fn( string $v ): bool => 1 === preg_match( $size, $v ),
		'line_height' => static fn( string $v ): bool => 1 === preg_match( $size, $v ),
		'color'       => 'aafm_wpbakery_is_colour',
		// No & either: an entity, even one without its semicolon, would decode to a refused character in an attribute.
		'font_family' => static fn( string $v ): bool => 1 === preg_match( '/^[^;{}<(&\\\\\r\n]+$/', $v ),
		'font_style'  => static fn( string $v ): bool => 1 === preg_match( '/^[^;{}<(&\\\\\r\n]+$/', $v ),
	);
	$parts = array();
	foreach ( $keys as $key ) {
		if ( ! array_key_exists( $key, $value ) ) {
			continue;
		}
		$one = aafm_wpbakery_scalar_text( $value[ $key ] );
		if ( null === $one || ( '' !== $one && ! $rules[ $key ]( $one ) ) ) {
			return null;
		}
		if ( '' !== $one ) {
			$parts[] = $key . ':' . aafm_wpbakery_uri_encode( $one );
		}
	}
	return implode( '|', $parts );
}

/**
 * Design Options (Ruling Q4, W10): typed fields turned into one rule .vc_custom_<13 digits>{...}, declarations in
 * the order WPBakery's own CSS editor writes them. No selector, url() or brace ever comes from the caller; a
 * background image is an attachment id the plugin resolves.
 *
 * @param mixed $value Input object of fields.
 * @return string|null '' for no fields.
 */
function aafm_wpbakery_encode_design( $value ): ?string {
	if ( ! is_array( $value ) ) {
		return null;
	}
	$sides   = array( 'top', 'right', 'bottom', 'left' );
	$lengths = array();
	foreach ( array( 'margin', 'border', 'padding' ) as $layer ) {
		foreach ( $sides as $side ) {
			$lengths[] = 'border' === $layer ? 'border_' . $side . '_width' : $layer . '_' . $side;
		}
	}
	$known = array_merge( $lengths, array( 'border_color', 'border_style', 'border_radius', 'background_color', 'background_image', 'background_style' ) );
	if ( array() !== array_diff( array_keys( $value ), $known ) ) {
		return null;
	}
	foreach ( array_merge( $lengths, array( 'border_radius' ) ) as $key ) {
		if ( isset( $value[ $key ] ) && '' !== $value[ $key ] && ! aafm_wpbakery_is_length( $value[ $key ] ) ) {
			return null;
		}
	}
	foreach ( array( 'border_color', 'background_color' ) as $key ) {
		if ( isset( $value[ $key ] ) && '' !== $value[ $key ] && ! aafm_wpbakery_is_colour( $value[ $key ] ) ) {
			return null;
		}
	}
	$style = (string) ( $value['border_style'] ?? '' );
	$bg    = (string) ( $value['background_style'] ?? '' );
	if ( ! in_array( $style, array( '', 'solid', 'dotted', 'dashed', 'none', 'hidden', 'double', 'groove', 'ridge', 'inset', 'outset', 'initial', 'inherit' ), true )
		|| ! in_array( $bg, array( '', 'cover', 'contain', 'no-repeat', 'repeat' ), true ) ) {
		return null;
	}
	$src = '';
	if ( isset( $value['background_image'] ) && '' !== $value['background_image'] ) {
		if ( null === aafm_wpbakery_encode_image( $value['background_image'], false ) ) {
			return null;
		}
		$url = wp_get_attachment_image_url( (int) $value['background_image'], 'full' );
		if ( ! is_string( $url ) || 1 === preg_match( '/[\s"\'()\\\\{};]/', $url ) ) {
			return null;
		}
		$src = $url . '?id=' . (int) $value['background_image'];
	}

	$attrs = array();
	foreach ( array( 'margin', 'border', 'padding' ) as $layer ) {
		foreach ( $sides as $side ) {
			$key = 'border' === $layer ? 'border_' . $side . '_width' : $layer . '_' . $side;
			if ( '' !== (string) ( $value[ $key ] ?? '' ) ) {
				$attrs[ 'border' === $layer ? 'border-' . $side . '-width' : $layer . '-' . $side ] = (string) $value[ $key ];
			}
		}
	}
	$colour = (string) ( $value['background_color'] ?? '' );
	if ( '' !== $colour && '' !== $src ) {
		$attrs['background'] = $colour . ' url(' . $src . ')';
	} elseif ( '' !== $colour ) {
		$attrs['background-color'] = $colour;
	} elseif ( '' !== $src ) {
		$attrs['background-image'] = 'url(' . $src . ')';
	}
	if ( '' !== $src && false !== strpos( $bg, 'repeat' ) ) {
		$attrs['background-position'] = '0 0';
		$attrs['background-repeat']   = $bg;
	} elseif ( '' !== $src && in_array( $bg, array( 'cover', 'contain' ), true ) ) {
		$attrs['background-position'] = 'center';
		$attrs['background-repeat']   = 'no-repeat';
		$attrs['background-size']     = $bg;
	}
	if ( 0 === strpos( $colour, 'rgba' ) ) {
		$attrs['*background-color'] = (string) preg_replace( '/^rgba\((\d+),(\d+),(\d+),[^)]+\)$/', 'rgb($1,$2,$3)', (string) preg_replace( '/\s+/', '', $colour ) );
	}
	foreach ( array( 'left', 'right', 'top', 'bottom' ) as $side ) {
		if ( isset( $attrs[ 'border-' . $side . '-width' ] ) && '' !== $style ) {
			$attrs[ 'border-' . $side . '-style' ] = $style;
		}
	}
	if ( '' !== (string) ( $value['border_color'] ?? '' ) ) {
		$attrs['border-color'] = (string) $value['border_color'];
	}
	if ( '' !== (string) ( $value['border_radius'] ?? '' ) ) {
		$attrs['border-radius'] = (string) $value['border_radius'];
	}
	if ( array() === $attrs ) {
		return '';
	}
	$rule = '';
	foreach ( $attrs as $property => $declaration ) {
		$rule .= $property . ': ' . $declaration . ' !important;';
	}
	return '.vc_custom_' . (string) (int) floor( microtime( true ) * 1000 ) . '{' . $rule . '}';
}

/**
 * Args for aafm/wpbakery-list-elements.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_list_elements(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-list-elements' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-list-elements' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'context' => array(
						'type'        => 'string',
						'enum'        => array( 'page', 'grid' ),
						'default'     => 'page',
						'description' => __( 'page for the elements of a page (the default), grid to include the Grid Builder\'s elements for a grid template.', 'agent-abilities-for-mcp' ),
					),
					'tag'     => array(
						'type'        => 'string',
						'description' => __( 'One element\'s shortcode tag, such as vc_custom_heading. Returns that element with its settings.', 'agent-abilities-for-mcp' ),
					),
					'search'  => array(
						'type'        => 'string',
						'description' => __( 'Only elements whose name or tag contains this text.', 'agent-abilities-for-mcp' ),
					),
				),
				aafm_pagination_schema_props(
					50,
					__( 'Number of elements per page, clamped to the 1-50 range. Defaults to 20 when omitted.', 'agent-abilities-for-mcp' ),
					__( '1-based page number for pagination. Defaults to 1.', 'agent-abilities-for-mcp' )
				)
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'elements'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'tag'              => array( 'type' => 'string' ),
							'name'             => array( 'type' => 'string' ),
							'category'         => array( 'type' => 'string' ),
							'is_container'     => array( 'type' => 'boolean' ),
							'allowed_children' => array(
								'type'        => 'object',
								'description' => __( 'From the map: only, the elements allowed inside, and except, the ones refused. Both empty when the map sets no rule.', 'agent-abilities-for-mcp' ),
							),
							'allowed_parents'  => array(
								'type'        => 'object',
								'description' => __( 'From the map: only, the elements it may sit in, and except, the ones it may not. Both empty when the map sets no rule.', 'agent-abilities-for-mcp' ),
							),
							'deprecated'       => array( 'type' => 'boolean' ),
							'settings'         => array(
								'type'        => 'array',
								'description' => __( 'Only with tag. kind is how wpbakery-update-element takes the value; writable says whether it can; needs is unfiltered_html for Design Options and for settings that can carry script or raw HTML.', 'agent-abilities-for-mcp' ),
							),
						),
					),
				),
				'total'      => array( 'type' => 'integer' ),
				'skipped'    => array(
					'type'        => 'integer',
					'description' => __( 'Map entries and settings left out because the map could not resolve them or they had no setting name.', 'agent-abilities-for-mcp' ),
				),
				'map_source' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_wpbakery_list_elements',
		'permission_callback' => 'aafm_perm_blocks_floor',
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
 * A string from the element map, through the plugin's stored-text sanitizer so a label cannot carry markup.
 *
 * @param mixed $value Map value.
 * @return string
 */
function aafm_wpbakery_map_text( $value ): string {
	return is_scalar( $value ) ? aafm_sanitize_plain_text( (string) $value ) : '';
}

/**
 * A map nesting rule (as_parent or as_child): only and except lists of tags.
 *
 * @param mixed $rule Map value.
 * @return array{only:list<string>,except:list<string>}
 */
function aafm_wpbakery_nesting_rule( $rule ): array {
	$out = array(
		'only'   => array(),
		'except' => array(),
	);
	foreach ( array( 'only', 'except' ) as $key ) {
		if ( is_array( $rule ) && isset( $rule[ $key ] ) && is_string( $rule[ $key ] ) ) {
			$out[ $key ] = array_values( array_filter( array_map( 'aafm_wpbakery_map_text', explode( ',', $rule[ $key ] ) ), static fn( string $tag ): bool => '' !== $tag ) );
		}
	}
	return $out;
}

/**
 * One catalog row, with settings on request. A param that is not an array or has no setting name is skipped and
 * counted.
 *
 * @param string              $tag           Tag.
 * @param array<string,mixed> $entry         Map entry.
 * @param bool                $with_settings Whether to add settings.
 * @param int                 $skipped       Skip counter, added to.
 * @return array<string,mixed>
 */
function aafm_wpbakery_catalog_row( string $tag, array $entry, bool $with_settings, int &$skipped ): array {
	$category = $entry['category'] ?? '';
	$row      = array(
		'tag'              => $tag,
		'name'             => aafm_wpbakery_map_text( $entry['name'] ?? $tag ),
		'category'         => is_array( $category ) ? implode( ', ', array_filter( array_map( 'aafm_wpbakery_map_text', $category ), static fn( string $part ): bool => '' !== $part ) ) : aafm_wpbakery_map_text( $category ),
		'is_container'     => ! empty( $entry['is_container'] ) || ! empty( $entry['as_parent'] ),
		'allowed_children' => aafm_wpbakery_nesting_rule( $entry['as_parent'] ?? null ),
		'allowed_parents'  => aafm_wpbakery_nesting_rule( $entry['as_child'] ?? null ),
		'deprecated'       => ! empty( $entry['deprecated'] ),
	);
	if ( ! $with_settings ) {
		return $row;
	}
	$row['settings'] = array();
	foreach ( (array) ( $entry['params'] ?? array() ) as $param ) {
		if ( ! is_array( $param ) || ! isset( $param['param_name'] ) || ! is_string( $param['param_name'] ) || '' === $param['param_name'] ) {
			++$skipped;
			continue;
		}
		$kind    = aafm_wpbakery_param_kind( $tag, $param );
		$type    = aafm_wpbakery_param_type( $tag, $param['param_name'] );
		$allowed = array();
		if ( null !== $type && 'choice' === aafm_wpbakery_type_kind( $type, $param['param_name'] ) ) {
			$allowed = array_slice( array_map( 'aafm_wpbakery_map_text', aafm_wpbakery_choice_values( array_merge( $param, array( 'type' => $type ) ) ) ), 0, 100 );
		}
		$default = $param['std'] ?? null;
		if ( ! is_scalar( $default ) ) {
			$default = array() !== $allowed ? $allowed[0] : ( is_scalar( $param['value'] ?? null ) ? $param['value'] : '' );
		}
		$row['settings'][] = array(
			'name'           => $param['param_name'],
			'kind'           => $kind['kind'],
			'label'          => aafm_wpbakery_map_text( $param['heading'] ?? '' ),
			'default'        => aafm_wpbakery_map_text( $default ),
			'allowed_values' => $allowed,
			'writable'       => $kind['writable'],
			'needs'          => $kind['needs'],
		);
	}
	return $row;
}

/**
 * Execute aafm/wpbakery-list-elements (design WB-3).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wpbakery_list_elements( array $input ) {
	$context = 'grid' === ( $input['context'] ?? 'page' ) ? 'grid' : 'page';
	$skipped = 0;
	if ( isset( $input['tag'] ) && '' !== (string) $input['tag'] ) {
		$tag   = (string) $input['tag'];
		$entry = aafm_wpbakery_map( $context, $tag );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$row = aafm_wpbakery_catalog_row( $tag, $entry, true, $skipped );
		return array(
			'elements'   => array( $row ),
			'total'      => 1,
			'skipped'    => $skipped,
			'map_source' => 'wpbakery',
		);
	}

	$map = aafm_wpbakery_map( $context, null, $skipped );
	if ( is_wp_error( $map ) ) {
		return $map;
	}
	$search = isset( $input['search'] ) ? strtolower( aafm_sanitize_plain_text( (string) $input['search'] ) ) : '';
	$rows   = array();
	foreach ( $map as $tag => $entry ) {
		$row = aafm_wpbakery_catalog_row( (string) $tag, $entry, false, $skipped );
		if ( '' !== $search && false === strpos( strtolower( $row['tag'] ), $search ) && false === strpos( strtolower( $row['name'] ), $search ) ) {
			continue;
		}
		$rows[] = $row;
	}
	$paging = aafm_paginate_args( $input + array( 'per_page' => 20 ), 50 );
	return array(
		'elements'   => array_slice( $rows, ( $paging['page'] - 1 ) * $paging['per_page'], $paging['per_page'] ),
		'total'      => count( $rows ),
		'skipped'    => $skipped,
		'map_source' => 'wpbakery',
	);
}

/**
 * Whether two Design Options rules carry the same declarations, whatever their vc_custom class.
 *
 * @param string $stored  Stored attribute value.
 * @param string $encoded New rule from the encoder.
 * @return bool
 */
function aafm_wpbakery_same_design( string $stored, string $encoded ): bool {
	$pattern = '/^\.vc_custom_\d+(\{[^{}]*\})$/';
	return 1 === preg_match( $pattern, $stored, $a ) && 1 === preg_match( $pattern, $encoded, $b ) && $a[1] === $b[1];
}

/**
 * Args for aafm/wpbakery-update-element.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_update_element(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-update-element' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-update-element' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the page, post or Grid Builder template.', 'agent-abilities-for-mcp' ),
				),
				'index'       => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'The element\'s index in the outline from wpbakery-get-page-content with include_structure.', 'agent-abilities-for-mcp' ),
				),
				'expect'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'tag'         => array( 'type' => 'string' ),
						'fingerprint' => array(
							'type'    => 'string',
							'pattern' => '^[0-9a-f]{16}$',
						),
					),
					'required'             => array( 'tag', 'fingerprint' ),
					'additionalProperties' => false,
					'description'          => __( 'The tag and fingerprint the outline returned for that index. A page that changed since is refused.', 'agent-abilities-for-mcp' ),
				),
				'set_atts'    => array(
					'type'        => 'object',
					'description' => __( 'Setting name to value, each value in the input form of its kind from wpbakery-list-elements.', 'agent-abilities-for-mcp' ),
				),
				'remove_atts' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Setting names to remove, which returns them to the element\'s default.', 'agent-abilities-for-mcp' ),
				),
				'text'        => array(
					'type'        => 'string',
					'description' => __( 'New inner content for an element with a content setting and no child elements, such as a text block. HTML is filtered as in a post; the code of a raw HTML or raw JS element needs unfiltered HTML.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id', 'index', 'expect' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post'                => array( 'type' => 'object' ),
				'index'               => array( 'type' => 'integer' ),
				'fingerprint'         => array( 'type' => 'string' ),
				'page_fingerprint'    => array( 'type' => 'string' ),
				'changed'             => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Settings whose stored value changed, and text when the inner content did. Empty when nothing was saved.', 'agent-abilities-for-mcp' ),
				),
				'restore_revision_id' => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Pass to restore-revision to put the page back as it was. Null when nothing was saved or no revision is kept.', 'agent-abilities-for-mcp' ),
				),
				'reversible'          => array( 'type' => 'boolean' ),
				'reversible_reason'   => array(
					'type'        => 'string',
					'description' => __( 'Present only when there is no restore point: the site or the content type keeps no revisions, WordPress saved none before the change, or the one saved was deleted during the save by the revision limit or another plugin. Says why there is no way back.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wpbakery_update_element',
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
 * The attributes of an opening tag with where each sits, so a change rewrites only that attribute and keeps the
 * stored text, order and quoting of every other one.
 *
 * @param string $attribute_text The text between the tag name and the closing bracket.
 * @return array<string,array{core_start:int,core_length:int,whole_length:int,value:string}> Keyed by lowercase name.
 */
function aafm_wpbakery_attribute_spans( string $attribute_text ): array {
	$spans = array();
	if ( false === preg_match_all( get_shortcode_atts_regex(), $attribute_text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return $spans;
	}
	foreach ( $matches as $m ) {
		foreach ( array(
			1 => 2,
			3 => 4,
			5 => 6,
		) as $name_group => $value_group ) {
			if ( isset( $m[ $name_group ] ) && '' !== $m[ $name_group ][0] && -1 !== $m[ $name_group ][1] ) {
				$whole                                       = (string) $m[0][0];
				$spans[ strtolower( $m[ $name_group ][0] ) ] = array(
					'core_start'   => (int) $m[0][1],
					'core_length'  => strlen( rtrim( $whole ) ),
					'whole_length' => strlen( $whole ),
					'value'        => (string) $m[ $value_group ][0],
				);
				break;
			}
		}
	}
	return $spans;
}

/**
 * Execute aafm/wpbakery-update-element (design WB-2 with WB-11, WB-12 and the write side of WB-13).
 *
 * Resolves the element by index and fingerprint with the tag list the read used, looks its settings up in the
 * element map (the Grid Builder's mapper first on a grid template), applies the script gate to every touched
 * setting before any value is encoded, rebuilds only the opening tag and, for text, the inner content, and hands
 * the result to the one guarded save (aafm_builder_commit()), which asks for a CSS rebuild only when a Design
 * Options value changed. A stored closer, or its absence, is kept (Ruling R-S9a-0).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wpbakery_update_element( array $input ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( ! aafm_builder_ownership_allows( 'wpbakery', aafm_post_has_foreign_builder_ownership( $id ), $post ) ) {
		return new WP_Error(
			'aafm_not_wpbakery_owned',
			__( 'This post is not a WPBakery page. Edit a page built with another builder in that builder. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}

	$set      = is_array( $input['set_atts'] ?? null ) ? $input['set_atts'] : array();
	$remove   = is_array( $input['remove_atts'] ?? null ) ? array_values( $input['remove_atts'] ) : array();
	$has_text = array_key_exists( 'text', $input );
	if ( array() === $set && array() === $remove && ! $has_text ) {
		return new WP_Error(
			'aafm_builder_nothing_to_change',
			__( 'Give at least one of set_atts, remove_atts or text. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}

	$content = (string) $post->post_content;
	$tags    = aafm_wpbakery_shortcode_tags( $content );
	if ( null === $tags ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$element = aafm_builder_resolve( $content, $tags, (int) ( $input['index'] ?? -1 ), is_array( $input['expect'] ?? null ) ? $input['expect'] : array() );
	if ( is_wp_error( $element ) ) {
		return $element;
	}
	$tag   = (string) $element['tag'];
	$entry = aafm_wpbakery_map( 'vc_grid_item' === $post->post_type ? 'grid' : 'page', $tag );
	if ( is_wp_error( $entry ) ) {
		return $entry;
	}
	$params = array();
	foreach ( (array) ( $entry['params'] ?? array() ) as $param ) {
		if ( is_array( $param ) && isset( $param['param_name'] ) && is_string( $param['param_name'] ) ) {
			$params[ $param['param_name'] ] = $param;
		}
	}

	// Every touched setting is checked, and gated, before any value is encoded.
	$touched = array();
	foreach ( array_merge( array_map( 'strval', array_keys( $set ) ), array_map( 'strval', $remove ) ) as $name ) {
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $name ) || 'content' === $name || ! isset( $params[ $name ] ) ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $name );
		}
		$kind = aafm_wpbakery_param_kind( $tag, $params[ $name ] );
		if ( ! $kind['writable'] ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $name );
		}
		$gate = aafm_wpbakery_script_gate( $kind['kind'] );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$touched[ $name ] = $kind['kind'];
	}
	$has_children = in_array( (int) $element['index'], array_column( (array) aafm_builder_elements( $content, $tags ), 'parent' ), true );
	if ( $has_text ) {
		$kind = isset( $params['content'] ) && ! $has_children ? aafm_wpbakery_param_kind( $tag, $params['content'] ) : null;
		if ( null === $kind || ! $kind['writable'] ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', 'text' );
		}
		$gate = aafm_wpbakery_script_gate( $kind['kind'] );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
	}

	// The opening tag, and its attribute text between the tag name and the closing bracket.
	$open      = substr( $content, (int) $element['start'], (int) $element['open_end'] - (int) $element['start'] );
	$tail      = $element['self_closing'] ? (string) preg_replace( '/^.*?(\s*\/\]|\])$/s', '$1', $open ) : ']';
	$attr_text = substr( $open, strlen( $tag ) + 1, strlen( $open ) - strlen( $tag ) - 1 - strlen( $tail ) );
	$spans     = aafm_wpbakery_attribute_spans( $attr_text );

	$changed       = array();
	$design_change = false;
	$edits         = array();
	$appended      = '';
	foreach ( $set as $name => $value ) {
		$name          = (string) $name;
		$param         = $params[ $name ];
		$param['type'] = aafm_wpbakery_param_type( $tag, $name );
		$encoded       = aafm_wpbakery_encode_value( $touched[ $name ], $value, $param );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		// Design Options mint a new class each time; the same declarations under the stored class are no change.
		if ( isset( $spans[ $name ] ) && 'css_editor' === $param['type'] && aafm_wpbakery_same_design( $spans[ $name ]['value'], $encoded ) ) {
			continue;
		}
		if ( isset( $spans[ $name ] ) && $spans[ $name ]['value'] === $encoded ) {
			continue;
		}
		$changed[]     = $name;
		$design_change = $design_change || 'css_editor' === $param['type'];
		if ( isset( $spans[ $name ] ) ) {
			$edits[ $spans[ $name ]['core_start'] ] = array( $spans[ $name ]['core_length'], $name . '="' . $encoded . '"' );
		} else {
			$appended .= ' ' . $name . '="' . $encoded . '"';
		}
	}
	foreach ( $remove as $name ) {
		$name = (string) $name;
		if ( ! isset( $spans[ $name ] ) || isset( $edits[ $spans[ $name ]['core_start'] ] ) ) {
			continue;
		}
		$changed[]                              = $name;
		$design_change                          = $design_change || 'css_editor' === aafm_wpbakery_param_type( $tag, $name );
		$edits[ $spans[ $name ]['core_start'] ] = array( $spans[ $name ]['whole_length'], '' );
	}
	krsort( $edits );
	$new_attr_text = $attr_text;
	foreach ( $edits as $start => list( $length, $replacement ) ) {
		$new_attr_text = substr_replace( $new_attr_text, $replacement, $start, $length );
	}
	if ( '' !== $appended || array() !== $edits ) {
		$trailing      = (string) preg_replace( '/^.*?(\s*)$/s', '$1', $attr_text );
		$new_attr_text = rtrim( $new_attr_text ) . $appended . $trailing;
	}

	$inner = substr( $content, (int) $element['open_end'], (int) $element['inner_end'] - (int) $element['open_end'] );
	if ( $has_text ) {
		$param         = $params['content'];
		$param['type'] = aafm_wpbakery_param_type( $tag, 'content' );
		$new_inner     = aafm_wpbakery_encode_value( aafm_wpbakery_param_kind( $tag, $param )['kind'], $input['text'], $param );
		if ( is_wp_error( $new_inner ) ) {
			return $new_inner;
		}
		if ( $new_inner !== $inner ) {
			if ( ! $element['has_closer'] || $element['self_closing'] || aafm_wpbakery_text_closes_a_tag( $new_inner, (array) aafm_wpbakery_shortcode_tags( $content, $new_inner ) ) ) {
				return aafm_builder_error( 'aafm_builder_structure_changed' );
			}
			$changed[] = 'text';
			$inner     = $new_inner;
		}
	}

	if ( array() !== $changed ) {
		// A changed Templatera id shows another template: it gets the checks of a template chosen directly.
		$references = aafm_builder_references_allowed( '[' . $tag . $new_attr_text . ']', $post );
		if ( is_wp_error( $references ) ) {
			return $references;
		}
	}
	$source = '[' . $tag . $new_attr_text . $tail . $inner . substr( $content, (int) $element['inner_end'], (int) $element['end'] - (int) $element['inner_end'] );
	$new    = substr( $content, 0, (int) $element['start'] ) . $source . substr( $content, (int) $element['end'] );
	$walk   = aafm_builder_walk( $content, $tags );
	if ( null === $walk ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$atts     = shortcode_parse_atts( $new_attr_text );
	$expected = aafm_builder_expected_walk(
		$walk,
		array(
			'op'    => 'update',
			'index' => (int) $element['index'],
			'atts'  => is_array( $atts ) ? $atts : array(),
		)
	);

	$result = aafm_builder_commit( $post, $content, $new, $tags, $expected, $design_change ? 'aafm_wpbakery_rebuild_css' : null, 'wpbakery' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$stored   = (string) $result['post']->post_content;
	$elements = aafm_builder_elements( $stored, (array) aafm_wpbakery_shortcode_tags( $stored ) );
	$after    = $elements[ (int) $element['index'] ] ?? null;
	return array(
		'post'                => aafm_redact_post( $result['post'] ),
		'index'               => (int) $element['index'],
		'fingerprint'         => null === $after ? '' : aafm_builder_fingerprint( $stored, $after ),
		'page_fingerprint'    => $result['page_fingerprint'],
		'changed'             => $result['unchanged'] ? array() : $changed,
		'restore_revision_id' => $result['restore_revision_id'],
		'reversible'          => $result['reversible'],
	) + aafm_wpbakery_reversible_reason( $result );
}

/**
 * Whether WPBakery lets $child_tag sit inside $parent_tag (design WB-4). The fixed rules come first: the top level
 * ('') takes a row or a section, a section takes rows, a row takes columns, a column takes content elements or an
 * inner row, an inner row takes inner columns, an inner column takes content elements. Any other parent follows its
 * map entry's as_parent; a container with no as_parent takes content elements; anything else is refused. An inner row
 * also goes where allowed_container_element names vc_row. Below the top level, the child's own
 * as_child rule must allow the parent too.
 *
 * @param string                            $parent_tag Parent tag, '' for the top level.
 * @param string                            $child_tag  Child tag.
 * @param array<string,array<string,mixed>> $map        Map entries keyed by tag (those known; a missing parent is refused).
 * @return true|WP_Error aafm_builder_invalid_nesting (400) naming parent and child.
 */
function aafm_wpbakery_nesting_allows( string $parent_tag, string $child_tag, array $map ) {
	$layout = array( 'vc_section', 'vc_row', 'vc_column', 'vc_row_inner', 'vc_column_inner' );
	$fixed  = array(
		''             => array( 'vc_row', 'vc_section' ),
		'vc_section'   => array( 'vc_row' ),
		'vc_row'       => array( 'vc_column' ),
		'vc_row_inner' => array( 'vc_column_inner' ),
	);
	// A container WPBakery maps with content_element false (a column, a tab section, a flexbox or grid item) is never
	// free content: it goes only where a fixed rule or its parent's as_parent list names it. A leaf with that flag (the
	// deprecated vc_gmaps, vc_facebook and vc_cta_button) is still content; the flag only hides it from Add Element.
	$free = false !== ( $map[ $child_tag ]['content_element'] ?? true ) || empty( $map[ $child_tag ]['is_container'] );
	if ( isset( $fixed[ $parent_tag ] ) ) {
		$ok = in_array( $child_tag, $fixed[ $parent_tag ], true );
	} elseif ( 'vc_column' === $parent_tag || 'vc_column_inner' === $parent_tag ) {
		$ok = ( ! in_array( $child_tag, $layout, true ) && $free ) || ( 'vc_column' === $parent_tag && 'vc_row_inner' === $child_tag );
	} else {
		$entry = $map[ $parent_tag ] ?? array();
		$raw   = $entry['as_parent'] ?? null;
		$rule  = aafm_wpbakery_nesting_rule( $raw );
		// A layout child is refused, except an inner row where the container's allowed_container_element names vc_row.
		$layout_ok = ! in_array( $child_tag, $layout, true ) || ( 'vc_row_inner' === $child_tag && 'vc_row' === ( $entry['allowed_container_element'] ?? null ) );
		if ( ! isset( $entry['as_parent'] ) ) {
			// A container WPBakery maps with no as_parent (tab, accordion and toggle sections, flexbox and grid
			// items) takes content elements, as its editor lets it.
			$ok = ! empty( $entry['is_container'] ) && $layout_ok && ( $free || 'vc_row_inner' === $child_tag );
		} else {
			$ok = is_array( $raw ) && ( array() !== $rule['only'] || array() !== $rule['except'] )
				&& $layout_ok
				&& ( array() === $rule['only'] || in_array( $child_tag, $rule['only'], true ) )
				&& ! in_array( $child_tag, $rule['except'], true )
				&& ( $free || in_array( $child_tag, $rule['only'], true ) || 'vc_row_inner' === $child_tag );
		}
	}
	if ( $ok && '' !== $parent_tag ) {
		$rule = aafm_wpbakery_nesting_rule( $map[ $child_tag ]['as_child'] ?? null );
		$ok   = ( array() === $rule['only'] || in_array( $parent_tag, $rule['only'], true ) ) && ! in_array( $parent_tag, $rule['except'], true );
	}
	if ( $ok ) {
		return true;
	}
	$fixed_parents = array( '', 'vc_section', 'vc_row', 'vc_column', 'vc_row_inner', 'vc_column_inner' );
	if ( ! in_array( $parent_tag, $fixed_parents, true ) && ! isset( $map[ $parent_tag ] ) ) {
		return new WP_Error(
			'aafm_builder_invalid_nesting',
			/* translators: 1: the parent tag, 2: the child tag. */
			sprintf( __( 'WPBakery\'s element map on this site does not list %1$s, so its nesting rule cannot be read and nothing can be placed inside it with this tool. Use WPBakery\'s editor for this container. Nothing was changed. Child: %2$s.', 'agent-abilities-for-mcp' ), $parent_tag, $child_tag ),
			array(
				'status' => 400,
				'parent' => $parent_tag,
				'child'  => $child_tag,
			)
		);
	}
	$error = aafm_builder_error(
		'aafm_builder_invalid_nesting',
		array(
			'parent' => $parent_tag,
			'child'  => $child_tag,
		)
	);
	return new WP_Error(
		'aafm_builder_invalid_nesting',
		/* translators: 1: the refusal, 2: the parent tag or "top level", 3: the child tag. */
		sprintf( __( '%1$s Parent: %2$s. Child: %3$s.', 'agent-abilities-for-mcp' ), $error->get_error_message(), '' === $parent_tag ? __( 'top level', 'agent-abilities-for-mcp' ) : $parent_tag, $child_tag ),
		$error->get_error_data()
	);
}

/**
 * Whether stored WPBakery shortcode text holds anything on the design section 9 script list: an element on the
 * custom HTML list, a custom onclick setting, a Design Options value, or any setting or content whose kind is raw.
 * Text that cannot be parsed counts as holding one. S11 and S14 call this (handover 2).
 *
 * @param string $content Stored shortcode text.
 * @return bool
 */
function aafm_wpbakery_content_has_script( string $content ): bool {
	$tags = aafm_wpbakery_shortcode_tags( $content );
	if ( null === $tags ) {
		return true;
	}
	$elements = aafm_builder_elements( $content, $tags );
	if ( null === $elements ) {
		return true;
	}
	$parents = array_column( $elements, 'parent' );
	foreach ( $elements as $element ) {
		$tag  = (string) $element['tag'];
		$name = array_map( 'strval', array_keys( (array) $element['atts'] ) );
		if ( ! in_array( (int) $element['index'], $parents, true ) && (int) $element['inner_end'] > (int) $element['open_end'] ) {
			$name[] = 'content';
		}
		foreach ( $name as $setting ) {
			if ( 'css' === $setting || in_array( aafm_wpbakery_param_kind( $tag, array( 'param_name' => $setting ) )['kind'], array( 'design', 'raw' ), true ) ) {
				return true;
			}
		}
		if ( in_array( $tag, aafm_wpbakery_custom_html_elements(), true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The refusal for an insert over its limits: depth 6 and 50 elements per call.
 *
 * @return WP_Error
 */
function aafm_wpbakery_insert_limit_error(): WP_Error {
	return new WP_Error(
		'aafm_builder_insert_limit',
		__( 'One insert may add at most 50 elements, nested at most 6 deep. Split the change into smaller inserts. Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 400 )
	);
}

/**
 * The refusal for a change that would leave a row or inner row with no column.
 *
 * @return WP_Error
 */
function aafm_wpbakery_row_needs_column_error(): WP_Error {
	return new WP_Error(
		'aafm_builder_row_needs_column',
		__( 'A row must keep at least one column, so this change would break the layout. Remove or move the whole row instead. Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 400 )
	);
}

/**
 * Count, depth and tags of a tree of nodes, each a well-formed array{tag,atts?,text?,children?}.
 *
 * @param array<int,mixed> $nodes Nodes.
 * @param int              $depth Depth of these nodes, 1 for the roots.
 * @param array<string>    $tags  Tags seen, added to.
 * @return array{count:int,depth:int}|WP_Error aafm_builder_invalid_value for a malformed node.
 */
function aafm_wpbakery_tree_stats( array $nodes, int $depth, array &$tags ) {
	$count = 0;
	$max   = 0;
	foreach ( $nodes as $node ) {
		if ( ! is_array( $node ) || ! is_string( $node['tag'] ?? null ) || 1 !== preg_match( '/^[A-Za-z0-9_\-]+$/', $node['tag'] )
			|| ! is_array( $node['atts'] ?? array() ) || ! is_array( $node['children'] ?? array() )
			|| ( isset( $node['text'] ) && ! is_string( $node['text'] ) ) ) {
			return aafm_builder_error( 'aafm_builder_invalid_value' );
		}
		$tags[] = $node['tag'];
		$inner  = aafm_wpbakery_tree_stats( array_values( (array) ( $node['children'] ?? array() ) ), $depth + 1, $tags );
		if ( is_wp_error( $inner ) ) {
			return $inner;
		}
		$count += 1 + $inner['count'];
		$max    = max( $max, $depth, $inner['depth'] );
	}
	return array(
		'count' => $count,
		'depth' => $max,
	);
}

/**
 * Checks nesting through a tree: each node inside its parent, then its own children inside it.
 *
 * @param array<int,array<string,mixed>>    $nodes      Nodes.
 * @param string                            $parent_tag Parent tag, '' for the top level.
 * @param array<string,array<string,mixed>> $map        Map entries keyed by tag.
 * @return true|WP_Error
 */
function aafm_wpbakery_tree_nesting( array $nodes, string $parent_tag, array $map ) {
	foreach ( $nodes as $node ) {
		$ok = aafm_wpbakery_nesting_allows( $parent_tag, (string) $node['tag'], $map );
		if ( true === $ok ) {
			$ok = aafm_wpbakery_tree_nesting( array_values( (array) ( $node['children'] ?? array() ) ), (string) $node['tag'], $map );
		}
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
	}
	return true;
}

/**
 * Serializes untrusted nodes through the encoder (attribute values) and the body text choke point (text, inside
 * aafm_wpbakery_encode_value()), gating every script kind before it encodes, and predicts each node's outline tuple
 * from the values it wrote. A node gets a closer when the map makes it a container or gives it a content param
 * (Ruling R-S9a-0).
 *
 * @param array<int,array<string,mixed>>    $nodes   Nodes.
 * @param array<string,array<string,mixed>> $map     Map entries keyed by tag.
 * @param array<string>                     $tags    Shortcode names on the page, for the closer check.
 * @param int                               $depth   Depth of these nodes relative to the insert, 0 for the roots.
 * @param list<array<string,mixed>>         $tuples  Predicted tuples, appended to.
 * @return string|WP_Error
 */
function aafm_wpbakery_serialize_nodes( array $nodes, array $map, array $tags, int $depth, array &$tuples ) {
	$out = '';
	foreach ( $nodes as $node ) {
		$tag      = (string) $node['tag'];
		$entry    = $map[ $tag ];
		$children = array_values( (array) ( $node['children'] ?? array() ) );
		$params   = array();
		foreach ( (array) ( $entry['params'] ?? array() ) as $param ) {
			if ( is_array( $param ) && isset( $param['param_name'] ) && is_string( $param['param_name'] ) ) {
				$params[ $param['param_name'] ] = $param;
			}
		}
		$set   = (array) ( $node['atts'] ?? array() );
		$kinds = array();
		foreach ( array_map( 'strval', array_keys( $set ) ) as $name ) {
			if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $name ) || 'content' === $name || ! isset( $params[ $name ] ) ) {
				return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $name );
			}
			$kind = aafm_wpbakery_param_kind( $tag, $params[ $name ] );
			if ( ! $kind['writable'] ) {
				return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $name );
			}
			$gate = aafm_wpbakery_script_gate( $kind['kind'] );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			$kinds[ $name ] = $kind['kind'];
		}
		$text_kind = null;
		if ( isset( $node['text'] ) ) {
			$text_kind = isset( $params['content'] ) && array() === $children ? aafm_wpbakery_param_kind( $tag, $params['content'] ) : null;
			if ( null === $text_kind || ! $text_kind['writable'] ) {
				return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', 'text' );
			}
			$gate = aafm_wpbakery_script_gate( $text_kind['kind'] );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
		}

		$atts = array();
		foreach ( $set as $name => $value ) {
			$name          = (string) $name;
			$param         = $params[ $name ];
			$param['type'] = aafm_wpbakery_param_type( $tag, $name );
			$encoded       = aafm_wpbakery_encode_value( $kinds[ $name ], $value, $param );
			if ( is_wp_error( $encoded ) ) {
				return $encoded;
			}
			$atts[ $name ] = $encoded;
		}
		// The prediction: each value as shortcode_parse_atts() reads it alone, so a value that leaves its quotes
		// changes the outline and the commit refuses it.
		$predicted = array();
		foreach ( $atts as $name => $encoded ) {
			$parsed = shortcode_parse_atts( $name . '="' . $encoded . '"' );
			if ( ! is_array( $parsed ) || array( $name ) !== array_map( 'strval', array_keys( $parsed ) ) ) {
				return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $name );
			}
			$predicted[ $name ] = $parsed[ $name ];
		}

		$container = ! empty( $entry['is_container'] ) || isset( $entry['as_parent'] );
		$inner     = $container || isset( $params['content'] ) ? '' : null;
		$tuples[]  = array(
			'tag'          => $tag,
			'self_closing' => false,
			'has_closer'   => null !== $inner,
			'atts'         => $predicted,
			'depth'        => $depth,
		);
		if ( null !== $text_kind ) {
			$param         = $params['content'];
			$param['type'] = aafm_wpbakery_param_type( $tag, 'content' );
			$inner         = aafm_wpbakery_encode_value( $text_kind['kind'], $node['text'], $param );
			if ( is_wp_error( $inner ) ) {
				return $inner;
			}
			if ( aafm_wpbakery_text_closes_a_tag( $inner, array_merge( $tags, (array) aafm_wpbakery_shortcode_tags( $inner ) ) ) ) {
				return aafm_builder_error( 'aafm_builder_structure_changed' );
			}
		} elseif ( array() !== $children ) {
			$inner = aafm_wpbakery_serialize_nodes( $children, $map, $tags, $depth + 1, $tuples );
			if ( is_wp_error( $inner ) ) {
				return $inner;
			}
		}
		$out .= aafm_builder_serialize( $tag, $atts, $inner, 'wpbakery' );
	}
	return $out;
}

/**
 * SEC-S9b-2 (Ruling R-S11-1), for a user without unfiltered_html: every run of body text in stored shortcode text
 * (an element's inner content outside its child elements, and the text outside every root) goes through aafm_wpbakery_body_text(), and is refused
 * when kses would remove a tag, an attribute, a style rule or text from it. Core's save runs kses for this user and
 * the save replay compares only the outline, so a strip would otherwise be saved as a silent partial write.
 *
 * @param string        $text Stored shortcode text.
 * @param array<string> $tags Shortcode names in it.
 * @return true|WP_Error
 */
function aafm_wpbakery_stored_text_gate( string $text, array $tags ) {
	$elements = aafm_builder_elements( $text, $tags );
	if ( null === $elements ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	// The whole text is one more container, so the text before, between and after the roots, which Templatera
	// prints with the rest, is checked too.
	$containers = array_merge(
		array(
			array(
				'index'     => null,
				'open_end'  => 0,
				'inner_end' => strlen( $text ),
			),
		),
		$elements
	);
	foreach ( $containers as $element ) {
		$from     = (int) $element['open_end'];
		$segments = array();
		foreach ( $elements as $child ) {
			if ( $child['parent'] === $element['index'] ) {
				$segments[] = substr( $text, $from, (int) $child['start'] - $from );
				$from       = (int) $child['end'];
			}
		}
		$segments[] = substr( $text, $from, max( 0, (int) $element['inner_end'] - $from ) );
		foreach ( $segments as $segment ) {
			if ( '' === trim( $segment ) ) {
				continue;
			}
			$clean = aafm_wpbakery_body_text( $segment );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			if ( aafm_wpbakery_html_shape( aafm_wpbakery_decode_entities( $segment, true ) ) !== aafm_wpbakery_html_shape( $clean ) ) {
				return new WP_Error(
					'aafm_wpbakery_text_needs_unfiltered_html',
					__( 'Text in this template holds HTML that WordPress removes for a user who may not post unfiltered HTML, such as a script, an event attribute or a link protocol it does not allow. Remove it from the template, or ask such a user to insert it. Nothing was changed.', 'agent-abilities-for-mcp' ),
					array( 'status' => 403 )
				);
			}
		}
	}
	return true;
}

/**
 * The parts of an HTML fragment kses can remove: each tag and closer with its attributes and decoded values (a
 * style value as its list of rules, since kses rewrites the spacing and the last semicolon of a rule it keeps),
 * then the text without tags.
 *
 * @param string $html Fragment.
 * @return list<string>
 */
function aafm_wpbakery_html_shape( string $html ): array {
	$shape = array();
	$tags  = new WP_HTML_Tag_Processor( $html );
	while ( $tags->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
		$row   = ( $tags->is_tag_closer() ? '/' : '' ) . $tags->get_tag();
		$names = (array) $tags->get_attribute_names_with_prefix( '' );
		sort( $names );
		foreach ( $names as $name ) {
			$value = $tags->get_attribute( $name );
			$value = true === $value ? '' : (string) $value;
			if ( 'style' === $name ) {
				$value = implode( ';', array_filter( array_map( static fn( string $rule ): string => (string) preg_replace( '/\s*:\s*/', ':', trim( $rule ) ), explode( ';', $value ) ), static fn( string $rule ): bool => '' !== $rule ) );
			}
			$row .= ' ' . $name . '=' . $value;
		}
		$shape[] = $row;
	}
	$shape[] = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return $shape;
}

/**
 * The shared insert path (design WB-4, Ruling B3, handover 2). Checks the limits and every tag against the map,
 * checks nesting, gates script content, serializes (untrusted) or splices the stored sources (trusted), and saves
 * through aafm_builder_commit() with a CSS rebuild. The caller has already made the guard call, applied
 * aafm_builder_ownership_allows() and run aafm_builder_page_check().
 *
 * With $trusted_vendor_text the nodes come from aafm_builder_tree() and each root carries source, spliced in byte
 * for byte; tag and children are read only for the limits, the map, nesting and the script gate. Without it each
 * atts value is the input form of its kind and goes through the encoder, and text through the body text choke point.
 *
 * $anchor is array{index:?int,expect:?array,placement:?string}; all null for an empty post, which also needs empty
 * content and a type WPBakery's editor is on for (Ruling Q7, B15).
 *
 * @param WP_Post                        $post                Post.
 * @param array<int,array<string,mixed>> $tree                Root nodes.
 * @param array<string,mixed>            $anchor              Anchor.
 * @param bool                           $trusted_vendor_text Whether the nodes are stored vendor text.
 * @return array{post:WP_Post,index:int,fingerprint:string,page_fingerprint:string,inserted:int,restore_revision_id:?int,reversible:bool}|WP_Error
 */
function aafm_wpbakery_insert_tree( WP_Post $post, array $tree, array $anchor, bool $trusted_vendor_text ) {
	$tree    = array_values( $tree );
	$content = (string) $post->post_content;
	$seen    = array();
	$stats   = aafm_wpbakery_tree_stats( $tree, 1, $seen );
	if ( is_wp_error( $stats ) ) {
		return $stats;
	}
	if ( 0 === $stats['count'] ) {
		return aafm_builder_error( 'aafm_builder_invalid_value' );
	}
	if ( $stats['count'] > 50 || $stats['depth'] > 6 ) {
		return aafm_wpbakery_insert_limit_error();
	}
	if ( $trusted_vendor_text ) {
		foreach ( $tree as $root ) {
			if ( ! is_string( $root['source'] ?? null ) ) {
				return aafm_builder_error( 'aafm_builder_invalid_value' );
			}
		}
	}

	$tags = aafm_wpbakery_shortcode_tags( $content );
	if ( null === $tags ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$elements = aafm_builder_elements( $content, $tags );
	if ( null === $elements ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$index     = $anchor['index'] ?? null;
	$expect    = $anchor['expect'] ?? null;
	$placement = $anchor['placement'] ?? null;
	if ( null === $index && null === $expect && null === $placement ) {
		if ( '' !== trim( $content ) ) {
			return new WP_Error(
				'aafm_wpbakery_anchor_required',
				__( 'Give anchor_index, anchor_expect and placement together. Only an empty post takes an insert without them. Nothing was changed.', 'agent-abilities-for-mcp' ),
				array( 'status' => 400 )
			);
		}
		$types = aafm_builder_vendor( 'wpbakery_editor_post_types' );
		try {
			$on = null !== $types && in_array( $post->post_type, (array) $types(), true );
		} catch ( Throwable $e ) {
			$on = false;
		}
		if ( ! $on ) {
			return new WP_Error(
				'aafm_wpbakery_editor_off',
				__( 'WPBakery\'s editor is not switched on for this post type for this user, so a WPBakery page cannot be started here. Nothing was changed.', 'agent-abilities-for-mcp' ),
				array( 'status' => 409 )
			);
		}
		$point = array(
			'offset' => strlen( $content ),
			'at'     => count( $elements ),
			'depth'  => 0,
			'parent' => null,
		);
	} else {
		if ( ! is_int( $index ) || ! is_array( $expect ) || ! is_string( $placement ) ) {
			return new WP_Error(
				'aafm_wpbakery_anchor_required',
				__( 'Give anchor_index, anchor_expect and placement together. Only an empty post takes an insert without them. Nothing was changed.', 'agent-abilities-for-mcp' ),
				array( 'status' => 400 )
			);
		}
		$anchor_element = aafm_builder_resolve( $content, $tags, $index, $expect );
		if ( is_wp_error( $anchor_element ) ) {
			return $anchor_element;
		}
		$point = aafm_builder_anchor_point( $elements, $anchor_element, $placement );
		if ( is_wp_error( $point ) ) {
			return $point;
		}
	}
	$parent_tag = null === $point['parent'] ? '' : (string) $elements[ $point['parent'] ]['tag'];

	// Every tag must be in the map; the parent is looked up too, and a parent the map does not know is left out, so
	// only a fixed rule can place anything inside it.
	$context = 'vc_grid_item' === $post->post_type ? 'grid' : 'page';
	$map     = array();
	foreach ( array_unique( $seen ) as $tag ) {
		$entry = aafm_wpbakery_map( $context, $tag );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$map[ $tag ] = $entry;
	}
	if ( '' !== $parent_tag && ! isset( $map[ $parent_tag ] ) ) {
		$entry = aafm_wpbakery_map( $context, $parent_tag );
		if ( is_array( $entry ) ) {
			$map[ $parent_tag ] = $entry;
		}
	}
	$nesting = aafm_wpbakery_tree_nesting( $tree, $parent_tag, $map );
	if ( is_wp_error( $nesting ) ) {
		return $nesting;
	}

	if ( $trusted_vendor_text ) {
		$text = implode( '', array_column( $tree, 'source' ) );
		// Ruling B11: stored text that carries a script kind needs unfiltered_html, as typed input would.
		if ( ! current_user_can( 'unfiltered_html' ) && aafm_wpbakery_content_has_script( $text ) ) {
			return aafm_builder_error( 'aafm_builder_needs_unfiltered_html' );
		}
		$source_tags = aafm_wpbakery_shortcode_tags( $text );
		// SEC-S9b-2 (Ruling R-S11-1): stored template text is not administrator text, since WPBakery lets any user
		// with template access save one, so its body text gets the checks typed text gets.
		if ( ! current_user_can( 'unfiltered_html' ) && null !== $source_tags ) {
			$gate = aafm_wpbakery_stored_text_gate( $text, $source_tags );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
		}
		$tuples = null === $source_tags ? null : aafm_builder_walk( $text, $source_tags );
		if ( null === $tuples || count( $tuples ) !== $stats['count'] ) {
			return aafm_builder_error( 'aafm_builder_unparseable' );
		}
	} else {
		$tuples = array();
		$text   = aafm_wpbakery_serialize_nodes( $tree, $map, $tags, 0, $tuples );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
	}
	// A Templatera element, copied, typed or nested, shows its template: that template gets the chosen item's checks.
	$references = aafm_builder_references_allowed( $text, $post );
	if ( is_wp_error( $references ) ) {
		return $references;
	}

	$new  = substr( $content, 0, $point['offset'] ) . $text . substr( $content, $point['offset'] );
	$walk = aafm_builder_walk( $content, (array) aafm_wpbakery_shortcode_tags( $content, $text ) );
	if ( null === $walk ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$expected = aafm_builder_expected_walk(
		$walk,
		array(
			'op'     => 'insert',
			'at'     => $point['at'],
			'depth'  => $point['depth'],
			'tuples' => $tuples,
		)
	);
	$result   = aafm_builder_commit( $post, $content, $new, $tags, $expected, 'aafm_wpbakery_rebuild_css', 'wpbakery' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$stored = (string) $result['post']->post_content;
	$after  = aafm_builder_elements( $stored, (array) aafm_wpbakery_shortcode_tags( $stored ) );
	$root   = $after[ $point['at'] ] ?? null;
	return array(
		'post'                => $result['post'],
		'index'               => (int) $point['at'],
		'fingerprint'         => null === $root ? '' : aafm_builder_fingerprint( $stored, $root ),
		'page_fingerprint'    => $result['page_fingerprint'],
		'inserted'            => (int) $stats['count'],
		'restore_revision_id' => $result['restore_revision_id'],
		'reversible'          => $result['reversible'],
	) + aafm_wpbakery_reversible_reason( $result );
}

/**
 * The opening every structural write shares: the post, the guard call and ownership rule in the executor's own body
 * (Ruling R-B1), and the page fingerprint check (Ruling R3-P2).
 *
 * @param array<string,mixed> $input        Validated input.
 * @param bool                $empty_insert Whether an insert may start a page on an empty post.
 * @return WP_Post|WP_Error
 */
function aafm_wpbakery_structural_post( array $input, bool $empty_insert ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( ! aafm_builder_ownership_allows( 'wpbakery', aafm_post_has_foreign_builder_ownership( $id ), $post, $empty_insert ) ) {
		return new WP_Error(
			'aafm_not_wpbakery_owned',
			__( 'This post is not a WPBakery page. Edit a page built with another builder in that builder. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	$check = aafm_builder_page_check( (string) $post->post_content, (string) ( $input['page_fingerprint'] ?? '' ) );
	return is_wp_error( $check ) ? $check : $post;
}

/**
 * The post_id and page_fingerprint parts alone, for an insert, which names its place with the anchor_ trio (BR-4).
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wpbakery_insert_post_schema(): array {
	return array_intersect_key( aafm_wpbakery_structural_schema( '' ), array_flip( array( 'post_id', 'page_fingerprint' ) ) );
}

/**
 * The input schema parts every structural write shares.
 *
 * @param string $prefix '' for the element itself, 'anchor_' for the anchor.
 * @return array<string,array<string,mixed>>
 */
function aafm_wpbakery_structural_schema( string $prefix ): array {
	$out = array();
	if ( '' === $prefix ) {
		$out['post_id']          = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'ID of the page, post or Grid Builder template.', 'agent-abilities-for-mcp' ),
		);
		$out['page_fingerprint'] = array(
			'type'        => 'string',
			'pattern'     => '^[0-9a-f]{16}$',
			'description' => __( 'The page fingerprint from the last wpbakery-get-page-content read or the last write\'s response. A page that changed since is refused.', 'agent-abilities-for-mcp' ),
		);
	}
	$out[ $prefix . 'index' ]  = array(
		'type'        => 'integer',
		'minimum'     => 0,
		'description' => '' === $prefix
			? __( 'The element\'s index in the outline from wpbakery-get-page-content with include_structure.', 'agent-abilities-for-mcp' )
			: __( 'Index of the element the new place is named against, from the same outline.', 'agent-abilities-for-mcp' ),
	);
	$out[ $prefix . 'expect' ] = array(
		'type'                 => 'object',
		'properties'           => array(
			'tag'         => array( 'type' => 'string' ),
			'fingerprint' => array(
				'type'    => 'string',
				'pattern' => '^[0-9a-f]{16}$',
			),
		),
		'required'             => array( 'tag', 'fingerprint' ),
		'additionalProperties' => false,
		'description'          => __( 'The tag and fingerprint the outline returned for that index.', 'agent-abilities-for-mcp' ),
	);
	if ( 'anchor_' === $prefix ) {
		$out['placement'] = array(
			'type'        => 'string',
			'enum'        => array( 'before', 'after', 'inside_start', 'inside_end' ),
			'description' => __( 'Where, relative to the anchor element: before or after it, or as its first or last child.', 'agent-abilities-for-mcp' ),
		);
	}
	return $out;
}

/**
 * Args for aafm/wpbakery-insert-element.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_insert_element(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-insert-element' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-insert-element' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_wpbakery_insert_post_schema() + aafm_wpbakery_structural_schema( 'anchor_' ) + array(
				'element' => array(
					'type'                 => 'object',
					'properties'           => array(
						'tag'      => array( 'type' => 'string' ),
						'atts'     => array(
							'type'        => 'object',
							'description' => __( 'Setting name to value, each in the input form of its kind from wpbakery-list-elements.', 'agent-abilities-for-mcp' ),
						),
						'text'     => array(
							'type'        => 'string',
							'description' => __( 'Inner content for an element with a content setting and no children, such as a text block.', 'agent-abilities-for-mcp' ),
						),
						'children' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object' ),
							'description' => __( 'Child elements, each with the same keys: tag, atts, text, children.', 'agent-abilities-for-mcp' ),
						),
					),
					'required'             => array( 'tag' ),
					'additionalProperties' => false,
					'description'          => __( 'The element to add, with its children. At most 50 elements, nested at most 6 deep.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id', 'page_fingerprint', 'element' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post'                => array( 'type' => 'object' ),
				'index'               => array( 'type' => 'integer' ),
				'fingerprint'         => array( 'type' => 'string' ),
				'page_fingerprint'    => array( 'type' => 'string' ),
				'inserted'            => array( 'type' => 'integer' ),
				'restore_revision_id' => array( 'type' => array( 'integer', 'null' ) ),
				'reversible'          => array( 'type' => 'boolean' ),
				'reversible_reason'   => array(
					'type'        => 'string',
					'description' => __( 'Present only when there is no restore point: the site or the content type keeps no revisions, WordPress saved none before the change, or the one saved was deleted during the save by the revision limit or another plugin. Says why there is no way back.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wpbakery_insert_element',
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
 * Execute aafm/wpbakery-insert-element (design WB-4): a thin wrapper around aafm_wpbakery_insert_tree() with typed
 * input, so every value goes through the attribute encoder and every text through the body text choke point.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wpbakery_insert_element( array $input ) {
	$post = aafm_wpbakery_structural_post( $input, true );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$out = aafm_wpbakery_insert_tree(
		$post,
		array( is_array( $input['element'] ?? null ) ? $input['element'] : array() ),
		array(
			'index'     => isset( $input['anchor_index'] ) ? (int) $input['anchor_index'] : null,
			'expect'    => is_array( $input['anchor_expect'] ?? null ) ? $input['anchor_expect'] : null,
			'placement' => isset( $input['placement'] ) ? (string) $input['placement'] : null,
		),
		false
	);
	if ( is_wp_error( $out ) ) {
		return $out;
	}
	$out['post'] = aafm_redact_post( $out['post'] );
	return $out;
}

/**
 * Whether taking the element at $index out of its parent would leave a row or inner row with no column.
 *
 * @param list<array<string,mixed>> $elements Outline.
 * @param int                       $index    Element.
 * @return bool
 */
function aafm_wpbakery_empties_a_row( array $elements, int $index ): bool {
	$parent = $elements[ $index ]['parent'];
	if ( null === $parent || ! in_array( $elements[ $parent ]['tag'], array( 'vc_row', 'vc_row_inner' ), true ) ) {
		return false;
	}
	return 1 === count( array_keys( array_column( $elements, 'parent' ), $parent, true ) );
}

/**
 * Args for aafm/wpbakery-move-element.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_move_element(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-move-element' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-move-element' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_wpbakery_structural_schema( '' ) + aafm_wpbakery_structural_schema( 'anchor_' ),
			'required'             => array( 'post_id', 'page_fingerprint', 'index', 'expect', 'anchor_index', 'anchor_expect', 'placement' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post'                => array( 'type' => 'object' ),
				'index'               => array( 'type' => 'integer' ),
				'fingerprint'         => array( 'type' => 'string' ),
				'page_fingerprint'    => array( 'type' => 'string' ),
				'moved'               => array( 'type' => 'boolean' ),
				'restore_revision_id' => array( 'type' => array( 'integer', 'null' ) ),
				'reversible'          => array( 'type' => 'boolean' ),
				'reversible_reason'   => array(
					'type'        => 'string',
					'description' => __( 'Present only when there is no restore point: the site or the content type keeps no revisions, WordPress saved none before the change, or the one saved was deleted during the save by the revision limit or another plugin. Says why there is no way back.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wpbakery_move_element',
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
 * Execute aafm/wpbakery-move-element (design WB-5). Cuts the element's stored bytes, children included, and splices
 * the same bytes in at the anchor; nothing is re-serialized or encoded. A move to the place it already has saves
 * nothing and reports moved false (Ruling R3-PR2-8).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wpbakery_move_element( array $input ) {
	$post = aafm_wpbakery_structural_post( $input, false );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	$content  = (string) $post->post_content;
	$tags     = aafm_wpbakery_shortcode_tags( $content );
	$elements = null === $tags ? null : aafm_builder_elements( $content, $tags );
	if ( null === $tags || null === $elements ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$element = aafm_builder_resolve( $content, $tags, (int) ( $input['index'] ?? -1 ), is_array( $input['expect'] ?? null ) ? $input['expect'] : array() );
	if ( is_wp_error( $element ) ) {
		return $element;
	}
	$anchor = aafm_builder_resolve( $content, $tags, (int) ( $input['anchor_index'] ?? -1 ), is_array( $input['anchor_expect'] ?? null ) ? $input['anchor_expect'] : array() );
	if ( is_wp_error( $anchor ) ) {
		return $anchor;
	}
	$index     = (int) $element['index'];
	$size      = aafm_builder_subtree_size( $elements, $index );
	$placement = (string) ( $input['placement'] ?? '' );
	$inside    = (int) $anchor['index'] > $index && (int) $anchor['index'] < $index + $size;
	if ( $inside || ( (int) $anchor['index'] === $index && 0 === strpos( $placement, 'inside_' ) ) ) {
		return aafm_builder_error( 'aafm_builder_invalid_move' );
	}
	$point = aafm_builder_anchor_point( $elements, $anchor, $placement );
	if ( is_wp_error( $point ) ) {
		return $point;
	}
	$tag        = (string) $element['tag'];
	$parent_tag = null === $point['parent'] ? '' : (string) $elements[ $point['parent'] ]['tag'];
	$context    = 'vc_grid_item' === $post->post_type ? 'grid' : 'page';
	$map        = array();
	// Demo #6b: a reorder under the same parent reads no placement rule, so it needs no map entry (the unregistered
	// flexbox and grid items reorder as the readme says).
	$names = $point['parent'] === $element['parent'] ? array() : array_unique( array_filter( array( $tag, $parent_tag ) ) );
	foreach ( $names as $name ) {
		$entry = aafm_wpbakery_map( $context, $name );
		if ( is_array( $entry ) ) {
			$map[ $name ] = $entry;
		} elseif ( $name === $tag || 'aafm_builder_unknown_element' !== $entry->get_error_code() ) {
			// The moved element must be in the map, so its own placement rule can be read; an unknown parent is left
			// to the nesting check, which refuses it with the reason.
			return $entry;
		}
	}
	// A reorder keeps the element under the parent it already has, so only a move to a new parent is checked.
	$nesting = $point['parent'] === $element['parent'] ? true : aafm_wpbakery_nesting_allows( $parent_tag, $tag, $map );
	if ( is_wp_error( $nesting ) ) {
		return $nesting;
	}
	if ( $point['parent'] !== $element['parent'] && aafm_wpbakery_empties_a_row( $elements, $index ) ) {
		return aafm_wpbakery_row_needs_column_error();
	}

	$start  = (int) $element['start'];
	$length = (int) $element['end'] - $start;
	$source = substr( $content, $start, $length );
	$cut    = substr( $content, 0, $start ) . substr( $content, $start + $length );
	$offset = $point['offset'] > $start ? $point['offset'] - $length : $point['offset'];
	$new    = substr( $cut, 0, $offset ) . $source . substr( $cut, $offset );
	$walk   = aafm_builder_walk( $content, $tags );
	if ( null === $walk ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$expected = aafm_builder_expected_walk(
		$walk,
		array(
			'op'    => 'move',
			'index' => $index,
			'at'    => $point['at'],
			'depth' => $point['depth'],
		)
	);
	$result   = aafm_builder_commit( $post, $content, $new, $tags, $expected, 'aafm_wpbakery_rebuild_css', 'wpbakery' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$stored    = (string) $result['post']->post_content;
	$new_index = $point['at'] > $index ? $point['at'] - $size : $point['at'];
	$after     = aafm_builder_elements( $stored, (array) aafm_wpbakery_shortcode_tags( $stored ) );
	$moved     = $after[ $new_index ] ?? null;
	return array(
		'post'                => aafm_redact_post( $result['post'] ),
		'index'               => $new_index,
		'fingerprint'         => null === $moved ? '' : aafm_builder_fingerprint( $stored, $moved ),
		'page_fingerprint'    => $result['page_fingerprint'],
		'moved'               => ! $result['unchanged'],
		'restore_revision_id' => $result['restore_revision_id'],
		'reversible'          => $result['reversible'],
	) + aafm_wpbakery_reversible_reason( $result );
}

/**
 * Args for aafm/wpbakery-remove-element.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_remove_element(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-remove-element' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-remove-element' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_wpbakery_structural_schema( '' ),
			'required'             => array( 'post_id', 'page_fingerprint', 'index', 'expect' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post'                => array( 'type' => 'object' ),
				'removed'             => array( 'type' => 'integer' ),
				'page_fingerprint'    => array( 'type' => 'string' ),
				'restore_revision_id' => array( 'type' => array( 'integer', 'null' ) ),
				'reversible_reason'   => array(
					'type'        => 'string',
					'description' => __( 'Present only when there is no restore point: the site or the content type keeps no revisions, WordPress saved none before the change, or the one saved was deleted during the save by the revision limit or another plugin. Says why there is no way back.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wpbakery_remove_element',
		'permission_callback' => 'aafm_perm_avada_post_object',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Execute aafm/wpbakery-remove-element (design WB-6). Removes the element's stored bytes, children included. Refused
 * when no revision can be kept (Ruling Q5; a grid template keeps none, W16), for the page's last root row or section
 * (Ruling B8), and when a row would be left with no column.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wpbakery_remove_element( array $input ) {
	$post = aafm_wpbakery_structural_post( $input, false );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	if ( ! post_type_supports( $post->post_type, 'revisions' ) || ! wp_revisions_enabled( $post ) ) {
		return aafm_builder_error( 'aafm_builder_remove_irreversible' );
	}
	// Core prunes the oldest revisions inside the save; with one kept, the restore point would be deleted at once.
	if ( 1 === wp_revisions_to_keep( $post ) ) {
		return new WP_Error(
			'aafm_builder_remove_irreversible',
			__( 'This site keeps only 1 revision per post (WP_POST_REVISIONS or the wp_revisions_to_keep filter), so WordPress would delete the revision that brings the removed element back during the same save. Raise the limit to 2 or more, or remove the element in WPBakery\'s editor. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	$content  = (string) $post->post_content;
	$tags     = aafm_wpbakery_shortcode_tags( $content );
	$elements = null === $tags ? null : aafm_builder_elements( $content, $tags );
	if ( null === $tags || null === $elements ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$element = aafm_builder_resolve( $content, $tags, (int) ( $input['index'] ?? -1 ), is_array( $input['expect'] ?? null ) ? $input['expect'] : array() );
	if ( is_wp_error( $element ) ) {
		return $element;
	}
	$index = (int) $element['index'];
	if ( aafm_wpbakery_empties_a_row( $elements, $index ) ) {
		return aafm_wpbakery_row_needs_column_error();
	}

	$new = substr( $content, 0, (int) $element['start'] ) . substr( $content, (int) $element['end'] );
	// Ruling B8, decided on the result the way the ownership guard reads it: every root kind it knows counts.
	if ( 1 !== preg_match( aafm_page_builder_content_pattern(), $new ) ) {
		return aafm_builder_error( 'aafm_builder_remove_last_root' );
	}
	$walk = aafm_builder_walk( $content, $tags );
	if ( null === $walk ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$expected = aafm_builder_expected_walk(
		$walk,
		array(
			'op'    => 'remove',
			'index' => $index,
		)
	);
	$result   = aafm_builder_commit( $post, $content, $new, $tags, $expected, 'aafm_wpbakery_rebuild_css', 'wpbakery' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return array(
		'post'                => aafm_redact_post( $result['post'] ),
		'removed'             => aafm_builder_subtree_size( $elements, $index ),
		'page_fingerprint'    => $result['page_fingerprint'],
		'restore_revision_id' => $result['restore_revision_id'],
	) + aafm_wpbakery_reversible_reason( $result );
}

/**
 * The engine's reversible_reason, when the site's revision limit deleted the restore point, as an output fragment.
 *
 * @param array<string,mixed> $result aafm_builder_commit() result.
 * @return array<string,string>
 */
function aafm_wpbakery_reversible_reason( array $result ): array {
	return isset( $result['reversible_reason'] ) ? array( 'reversible_reason' => (string) $result['reversible_reason'] ) : array();
}
