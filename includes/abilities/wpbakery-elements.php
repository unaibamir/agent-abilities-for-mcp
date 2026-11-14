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
			'description'  => __( 'Changes the settings or the inner text of one element on a WPBakery page or Grid Builder template, named by its index and by the tag and fingerprint wpbakery-get-page-content returned with include_structure. set_atts sets settings, each value in the form wpbakery-list-elements gives as its kind (a link as url, title, target and rel; an image as an attachment id; Design Options as fields such as margin_top, never as CSS); remove_atts returns settings to their default; text replaces the inner content of an element that has a content setting and no child elements. Only the element\'s opening tag and, for text, its inner content change; every other byte of the page stays as it was. Refused: a stale index or fingerprint, a setting the element does not have or that cannot be written, a value its kind does not accept, already encoded values, and any change that would add or remove an element. Design Options and settings that can carry script or raw HTML need a user who may post unfiltered HTML. A save that would change other content for this user is refused, and nothing is saved. When a revision is kept, restore_revision_id brings the page back through restore-revision; the response also returns the element\'s new fingerprint and the page fingerprint. The outline stops at 2,000 elements. Not undoable from this plugin\'s change history. Requires edit access to the post; WPBakery\'s Role Manager settings are not applied, though its own save filter still runs. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_update_element',
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
		return is_array( $settings ) ? $settings : aafm_builder_error( 'aafm_builder_unknown_element' );
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
	$name  = isset( $param['param_name'] ) && is_string( $param['param_name'] ) ? $param['param_name'] : '';
	$type  = isset( $param['type'] ) && is_string( $param['type'] ) ? $param['type'] : '';
	$base  = aafm_wpbakery_type_kind( $type, $name );
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
			// Entities decoded to the form WPBakery's editor saves, so a shortcode spelled with them is refused by the
			// structure check rather than created by the editor on its next save.
			return is_string( $value ) ? wp_kses_post( aafm_wpbakery_decode_entities( $value ) ) : null;
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
	$value = aafm_wpbakery_decode_entities( $value );
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
		$url = is_string( $value ) ? aafm_wpbakery_link_url( $value ) : null;
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
		'font_family' => static fn( string $v ): bool => 1 === preg_match( '/^[^;{}<(\\\\\r\n]+$/', $v ),
		'font_style'  => static fn( string $v ): bool => 1 === preg_match( '/^[^;{}<(\\\\\r\n]+$/', $v ),
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
	);
}
