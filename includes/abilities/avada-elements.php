<?php
/**
 * Avada element tools: Avada Builder's element map through the vendor seam, the kind of every setting, the encoder
 * that turns typed input into the form Avada Builder 3.16.1's editor keeps (the inverse of aafm_avada_decode_atts()
 * in avada.php), the one body-text choke point, and the abilities that read the element catalog and change elements.
 *
 * Design 325 sections 4.7 and 9, plan 326 Session 10. Vendor code is reached only through aafm_builder_vendor()
 * (Ruling B14). Every setting that can carry script needs unfiltered_html, checked before anything is encoded. The
 * forms written here follow what Avada's own editor keeps on its next save (scratchpad/1113/s10-editor-rewrites.md):
 * it drops an attribute that holds &quot; and writes the apostrophe and the backtick as characters.
 *
 * Registers ONLY while Avada is active (aafm_integration_active('avada')).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_avada_elements_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_avada_elements_full_definitions' );

/**
 * Contribute the Avada element ability definitions, only while Avada is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_avada_elements_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'avada' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_avada_elements_registry_definitions() );
}

/**
 * Contribute the Avada element ability definitions to the host-independent full registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_avada_elements_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_avada_elements_registry_definitions() );
}

/**
 * The Avada element ability rows.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_avada_elements_registry_definitions(): array {
	return array(
		'aafm/avada-list-elements' => array(
			'label'        => __( 'List Avada elements', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists the elements Avada Builder has on this site, from Avada\'s own element map: tag, name, whether it holds other elements, and the one child element a parent element such as tabs takes. With tag it returns one element with its settings, each under the name Avada stores it (a padding setting is one per side): kind, label, default, the allowed values of a choice (at most 100), whether avada-update-element can write it, and needs, which is unfiltered_html for settings that can carry script or raw HTML. Typography and dynamic data settings are listed but cannot be written. Settings Avada adds only inside its own editor, such as the tablet and phone variants, are not listed. Requires the edit_posts capability; Avada\'s Role Manager settings are not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_list_elements',
		),
	);
}

/**
 * The map refusal: Avada Builder's element map is missing or empty in this request.
 *
 * @return WP_Error
 */
function aafm_avada_map_unavailable(): WP_Error {
	return new WP_Error(
		'aafm_avada_map_unavailable',
		__( 'Avada Builder\'s element map is not available in this request (it needs Avada Builder 3.15.6 or later), so its elements cannot be read or written here. Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 409 )
	);
}

/**
 * Avada Builder's element map, through the vendor seam: the loader runs first (avada_map_loader, which builds the map
 * once per request), then the map is read (avada_map). Entries that are not arrays or carry no name are empty
 * placeholders and are skipped.
 *
 * @param string|null $tag One element, or null for all.
 * @return array<string,array<string,mixed>>|array<string,mixed>|WP_Error All entries keyed by tag, one entry, or a
 *                                                                        refusal: aafm_avada_map_unavailable (409),
 *                                                                        aafm_builder_unknown_element (400).
 */
function aafm_avada_map( ?string $tag = null ) {
	$loader = aafm_builder_vendor( 'avada_map_loader' );
	$reader = aafm_builder_vendor( 'avada_map' );
	if ( null === $loader || null === $reader ) {
		return aafm_avada_map_unavailable();
	}
	try {
		$loader();
		$raw = $reader();
	} catch ( Throwable $e ) {
		return aafm_avada_map_unavailable();
	}
	$map = array();
	foreach ( is_array( $raw ) ? $raw : array() as $key => $entry ) {
		if ( is_string( $key ) && 1 === preg_match( '/^[a-z0-9_]+$/', $key ) && is_array( $entry ) && array() !== $entry && isset( $entry['name'] ) ) {
			$map[ $key ] = $entry;
		}
	}
	if ( array() === $map ) {
		return aafm_avada_map_unavailable();
	}
	if ( null === $tag ) {
		return $map;
	}
	return $map[ $tag ] ?? aafm_builder_error( 'aafm_builder_unknown_element' );
}

/**
 * The tags that are part of the fixed layout grid rather than content elements.
 *
 * @return array<string>
 */
function aafm_avada_layout_tags(): array {
	return array( 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_column', 'fusion_builder_row_inner', 'fusion_builder_column_inner' );
}

/**
 * Whether a setting name is on the raw row's name list (Ruling B1, A17): link_attributes, attributes, or any name
 * ending in _attributes, html_attributes included.
 *
 * @param string $name Setting name.
 * @return bool
 */
function aafm_avada_is_raw_name( string $name ): bool {
	return 'attributes' === $name || '_attributes' === substr( $name, -11 );
}

/**
 * The kind of a map type before the name rules (design 4.7, Avada table).
 *
 * @param string $type Map type.
 * @return string
 */
function aafm_avada_type_kind( string $type ): string {
	$kinds = array(
		'textfield'           => 'text',
		'text'                => 'text',
		'tinymce'             => 'content',
		'textarea'            => 'content',
		'link_selector'       => 'link',
		'upload'              => 'image',
		'radio_button_set'    => 'choice',
		'select'              => 'choice',
		'multiple_select'     => 'choice',
		'checkbox_button_set' => 'choice',
		'switch'              => 'choice',
		'colorpickeralpha'    => 'colour',
		'colorpicker'         => 'colour',
		'range'               => 'number',
		'dimension'           => 'size',
		'spacing'             => 'size',
		'border_radius'       => 'size',
		'iconpicker'          => 'token',
		'class'               => 'token',
		'date_time_picker'    => 'date',
		'raw_textarea'        => 'raw',
		'raw_text'            => 'raw',
		'code'                => 'raw',
		'repeater'            => 'raw',
		'typography'          => 'typography',
	);
	return $kinds[ $type ] ?? 'unknown';
}

/**
 * The kind of one setting (design 4.7 and section 9): type first, then the name rules, which win. The content of
 * fusion_code and every name on the raw name list are raw. dynamic_params, a free-text setting named like an event
 * handler, a hidden setting, typography and any type the encoder does not know are not writable.
 *
 * @param string              $tag   Tag.
 * @param array<string,mixed> $param The map's param.
 * @return array{kind:string,writable:bool,needs:string}
 */
function aafm_avada_param_kind( string $tag, array $param ): array {
	$name = isset( $param['param_name'] ) && is_string( $param['param_name'] ) ? $param['param_name'] : '';
	$type = isset( $param['type'] ) && is_string( $param['type'] ) ? $param['type'] : '';
	$kind = aafm_avada_type_kind( $type );
	if ( 'id' === $name ) {
		$kind = 'token';
	}
	if ( aafm_avada_is_raw_name( $name ) || ( 'fusion_code' === $tag && 'element_content' === $name ) ) {
		$kind = 'raw';
	}
	if ( 'dynamic_params' === $name ) {
		$kind = 'dynamic';
	}
	$writable = ! in_array( $kind, array( 'typography', 'dynamic', 'unknown' ), true ) && empty( $param['hidden'] );
	if ( 'raw' !== $kind && 1 === preg_match( '/^on[a-z]+$/', $name ) ) {
		$writable = false;
	}
	return array(
		'kind'     => $kind,
		'writable' => $writable,
		'needs'    => 'raw' === $kind ? 'unfiltered_html' : '',
	);
}

/**
 * Every setting of an element under the name Avada stores it, each mapped to its param: a dimension, spacing or border
 * radius param with sides is one setting per side; a typography param's sub-values come from the element's
 * subparam_map (A28); the inner content (element_content) is not a setting. Params with no setting name are skipped and
 * counted.
 *
 * @param array<string,mixed> $entry   Map entry.
 * @param int                 $skipped Skip counter, added to.
 * @return array<string,array<string,mixed>> Stored name => param (a side carries its default in value).
 */
function aafm_avada_settings( array $entry, int &$skipped = 0 ): array {
	$out = array();
	foreach ( (array) ( $entry['params'] ?? array() ) as $param ) {
		if ( ! is_array( $param ) || ! isset( $param['param_name'] ) || ! is_string( $param['param_name'] ) || 1 !== preg_match( '/^[a-z0-9_]+$/', $param['param_name'] ) ) {
			++$skipped;
			continue;
		}
		$name = $param['param_name'];
		if ( 'element_content' === $name ) {
			continue;
		}
		$type = is_string( $param['type'] ?? null ) ? $param['type'] : '';
		if ( 'size' === aafm_avada_type_kind( $type ) && is_array( $param['value'] ?? null ) ) {
			foreach ( $param['value'] as $side => $default ) {
				if ( is_string( $side ) && 1 === preg_match( '/^[a-z0-9_]+$/', $side ) ) {
					$out[ $side ] = array_merge(
						$param,
						array(
							'value'   => is_scalar( $default ) ? (string) $default : '',
							'default' => is_scalar( $default ) ? (string) $default : '',
						)
					);
				}
			}
			continue;
		}
		if ( 'typography' === $type ) {
			foreach ( (array) ( $entry['subparam_map'] ?? array() ) as $sub => $owner ) {
				if ( $owner === $name && is_string( $sub ) && 1 === preg_match( '/^[a-z0-9_]+$/', $sub ) ) {
					$out[ $sub ] = $param;
				}
			}
			continue;
		}
		$out[ $name ] = $param;
	}
	// An image element's picture is its inner URL with the id beside it (A4): one setting, image_id, of the image kind.
	$content = aafm_avada_content_param( $entry );
	if ( null !== $content && 'upload' === ( $content['type'] ?? null ) ) {
		$out['image_id'] = $content;
	}
	return $out;
}

/**
 * The inner content param of an element (element_content), or null.
 *
 * @param array<string,mixed> $entry Map entry.
 * @return array<string,mixed>|null
 */
function aafm_avada_content_param( array $entry ): ?array {
	foreach ( (array) ( $entry['params'] ?? array() ) as $param ) {
		if ( is_array( $param ) && 'element_content' === ( $param['param_name'] ?? null ) ) {
			return $param;
		}
	}
	return null;
}

/**
 * The stored values a choice setting accepts: the keys of its value list (A26).
 *
 * @param array<string,mixed> $param Param.
 * @return list<string>
 */
function aafm_avada_choice_values( array $param ): array {
	$out = array();
	foreach ( (array) ( $param['value'] ?? array() ) as $key => $label ) {
		if ( is_scalar( $label ) || is_array( $label ) ) {
			$out[] = (string) $key;
		}
	}
	return $out;
}

/**
 * The script gate (design section 9): the raw kind needs unfiltered_html, checked before any value is encoded so a
 * refused call never builds the script.
 *
 * @param string $kind Kind from aafm_avada_param_kind().
 * @return true|WP_Error
 */
function aafm_avada_script_gate( string $kind ) {
	if ( 'raw' === $kind && ! current_user_can( 'unfiltered_html' ) ) {
		return aafm_builder_error( 'aafm_builder_needs_unfiltered_html' );
	}
	return true;
}

/**
 * Body text for an Avada page: text stored between shortcode tags (an element's inner content, a button's text). The
 * one choke point every Avada write of such text goes through. Entities are decoded first to the form the editor saves
 * (quote entities kept, so an attribute in the HTML keeps its text; aafm_wpbakery_decode_entities(), the same decoder
 * WPBakery's choke point uses), then the text goes through wp_kses_post(). For a user who may not post unfiltered HTML
 * it is refused, never stripped, when it holds a square bracket (Avada's editor turns &#91; into a real one on its next
 * save, scratchpad/1113/s10-editor-rewrites.md), a percent-encoded bracket, a data-* attribute or an unfinished tag,
 * checked on the text as sent and on the form kses would store. The last two are kept by Avada's editor and are refused
 * to stay at least as strict as WPBakery's rule (aafm_wpbakery_body_text()).
 *
 * @param string $text Text from the caller.
 * @return string|WP_Error
 */
function aafm_avada_body_text( string $text ) {
	$decoded = aafm_wpbakery_decode_entities( $text, true );
	$clean   = wp_kses_post( $decoded );
	if ( current_user_can( 'unfiltered_html' ) ) {
		return $clean;
	}
	if ( false !== strpbrk( $decoded . $clean, '[]' ) ) {
		return new WP_Error(
			'aafm_avada_brackets_need_unfiltered_html',
			__( 'Square brackets in this text need a user who may post unfiltered HTML, because Avada can turn them into an element the next time the page is saved in its builder. Remove the brackets, or ask such a user to make the edit. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}
	if ( 1 === preg_match( '/%5[bd]/i', $decoded . "\n" . $clean ) ) {
		return new WP_Error(
			'aafm_avada_encoded_brackets_need_unfiltered_html',
			__( 'A percent-encoded square bracket in this text needs a user who may post unfiltered HTML. Remove it, or ask such a user to make the edit. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}
	foreach ( array( $decoded, $clean ) as $html ) {
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag() ) {
			if ( array() !== (array) $tags->get_attribute_names_with_prefix( 'data-' ) ) {
				return new WP_Error(
					'aafm_avada_data_attributes_need_unfiltered_html',
					__( 'A data-* attribute in this text needs a user who may post unfiltered HTML. Remove the attribute, or ask such a user to make the edit. Nothing was changed.', 'agent-abilities-for-mcp' ),
					array( 'status' => 400 )
				);
			}
		}
		if ( $tags->paused_at_incomplete_token() ) {
			return new WP_Error(
				'aafm_avada_incomplete_html_needs_unfiltered_html',
				__( 'This text ends inside an unfinished HTML tag, which needs a user who may post unfiltered HTML because the tag cannot be checked. Finish or remove the tag, or ask such a user to make the edit. Nothing was changed.', 'agent-abilities-for-mcp' ),
				array( 'status' => 400 )
			);
		}
	}
	return $clean;
}

/**
 * Attribute text in the form Avada's editor keeps: every entity decoded first (repeatedly, so a double-encoded quote is
 * seen), then refused when it holds a double quote (the editor drops the whole attribute on its next save), a square
 * bracket, a control character or, for one line, a line break; then &, < and > are written as entities and the
 * apostrophe and the backtick as characters, which is what the editor stores after its own save.
 *
 * @param string $text      Text.
 * @param bool   $one_line  Whether line breaks are refused.
 * @param bool   $sanitize  Whether sanitize_text_field() (or, multi-line, sanitize_textarea_field()) runs first.
 * @return string|null Null when refused.
 */
function aafm_avada_attribute_text( string $text, bool $one_line = true, bool $sanitize = true ): ?string {
	for ( $i = 0; $i < 5; $i++ ) {
		$next = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $next === $text ) {
			break;
		}
		$text = $next;
	}
	if ( $sanitize ) {
		// The sanitizer writes a lone < as &lt;; read it back as the character, escaped once below.
		$text = html_entity_decode( $one_line ? sanitize_text_field( $text ) : sanitize_textarea_field( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	$controls = $one_line ? '/[\x00-\x1f\x7f]/' : '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/';
	if ( false !== strpbrk( $text, '"[]' ) || 1 === preg_match( $controls, $text ) ) {
		return null;
	}
	return strtr(
		$text,
		array(
			'&' => '&amp;',
			'<' => '&lt;',
			'>' => '&gt;',
		)
	);
}

/**
 * Avada's settings dialog escape, Underscore's _.escape() (A2), for a raw text field that is escaped before it is
 * encoded (aafm_avada_decode_atts() reverses both).
 *
 * @param string $text Text.
 * @return string
 */
function aafm_avada_dialog_escape( string $text ): string {
	return strtr(
		$text,
		array(
			'&' => '&amp;',
			'<' => '&lt;',
			'>' => '&gt;',
			'"' => '&quot;',
			"'" => '&#x27;',
			'`' => '&#x60;',
		)
	);
}

/**
 * The stored form for a typed value (design 4.7), or a refusal naming the setting. The inverse of
 * aafm_avada_decode_atts(). The caller runs aafm_avada_script_gate() first. The image kind returns two values: the id
 * attribute ("id|full", A4) and the URL, keyed '' when it is the element's inner content.
 *
 * @param string              $kind  Kind from aafm_avada_param_kind().
 * @param mixed               $value The input form of the kind.
 * @param array<string,mixed> $param The map's param.
 * @return string|array<string,string>|WP_Error
 */
function aafm_avada_encode_value( string $kind, $value, array $param ) {
	$name = isset( $param['param_name'] ) && is_string( $param['param_name'] ) ? $param['param_name'] : '';
	if ( 'element_content' === $name && ( 'content' === $kind || 'text' === $kind ) ) {
		// Body text goes through the shared choke point, whose refusal is returned as it is.
		$text = aafm_wpbakery_scalar_text( $value );
		return null === $text ? aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $name ) : aafm_avada_body_text( $text );
	}
	$out = aafm_avada_encode_clean( $kind, $value, $param );
	return null === $out ? aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', $name ) : $out;
}

/**
 * The encoder for every kind but body text (aafm_avada_encode_value()).
 *
 * @param string              $kind  Kind.
 * @param mixed               $value Input.
 * @param array<string,mixed> $param Param.
 * @return string|array<string,string>|null Null when refused.
 */
function aafm_avada_encode_clean( string $kind, $value, array $param ) {
	$name = is_string( $param['param_name'] ?? null ) ? $param['param_name'] : '';
	$type = is_string( $param['type'] ?? null ) ? $param['type'] : '';
	if ( 'raw' === $kind ) {
		return aafm_avada_encode_raw( $type, $name, $value, $param );
	}
	if ( 'choice' === $kind ) {
		$allowed = aafm_avada_choice_values( $param );
		$many    = in_array( $type, array( 'multiple_select', 'checkbox_button_set' ), true );
		$values  = $many && is_array( $value ) ? array_values( $value ) : array( $value );
		if ( ( ! $many && is_array( $value ) ) || array() === $values ) {
			return null;
		}
		foreach ( $values as $one ) {
			if ( ! is_string( $one ) || ! in_array( $one, $allowed, true ) ) {
				return null;
			}
		}
		return implode( ',', $values );
	}
	if ( 'image' === $kind ) {
		$id = is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/', $value ) ) ? (int) $value : 0;
		if ( $id < 1 || ! wp_attachment_is_image( $id ) ) {
			return null;
		}
		$url = (string) wp_get_attachment_url( $id );
		if ( '' === $url ) {
			return null;
		}
		$url = aafm_avada_attribute_text( $url, true, false );
		if ( null === $url ) {
			return null;
		}
		return 'element_content' === $name
			? array(
				'image_id' => $id . '|full',
				''         => $url,
			)
			: array(
				$name . '_id' => $id . '|full',
				$name         => $url,
			);
	}
	$text = aafm_wpbakery_scalar_text( $value );
	if ( null === $text ) {
		return null;
	}
	switch ( $kind ) {
		case 'text':
			return aafm_avada_attribute_text( $text );
		case 'content':
			// A textarea or editor setting stored as an attribute: kses first, then the attribute rule.
			return aafm_avada_attribute_text( wp_kses_post( $text ), false, false );
		case 'link':
			$url = aafm_wpbakery_link_url( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			return null === $url ? null : aafm_avada_attribute_text( $url, true, false );
		case 'colour':
			return '' === $text || aafm_wpbakery_is_colour( $text )
				|| 1 === preg_match( '/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $text )
				|| 1 === preg_match( '/^var\(--awb-[a-z0-9-]+\)$/', $text ) ? $text : null;
		case 'number':
			if ( '' === $text ) {
				return '';
			}
			if ( 1 !== preg_match( '/^-?\d+(\.\d+)?$/', $text ) ) {
				return null;
			}
			$min = is_numeric( $param['min'] ?? null ) ? (float) $param['min'] : null;
			$max = is_numeric( $param['max'] ?? null ) ? (float) $param['max'] : null;
			return ( null !== $min && (float) $text < $min ) || ( null !== $max && (float) $text > $max ) ? null : $text;
		case 'size':
			return '' === $text || 1 === preg_match( '/^-?\d*\.?\d+(px|em|rem|%|vw|vh)$/', $text ) ? $text : null;
		case 'token':
			$pattern = 'id' === $name ? '/^[A-Za-z0-9_-]*$/' : '/^([A-Za-z0-9_-]+( [A-Za-z0-9_-]+)*)?$/';
			return 1 === preg_match( $pattern, $text ) ? $text : null;
		case 'date':
			if ( '' === $text ) {
				return '';
			}
			$date = date_create( $text, wp_timezone() );
			return false === $date ? null : $date->format( 'Y-m-d H:i:s' );
	}
	return null;
}

/**
 * A raw-kind value (A3, Ruling B1), only reached behind the gate: a repeater is base64 JSON of its rows, a raw text
 * area base64, a raw text field base64 of its dialog-escaped text, a code block base64 only while the site option
 * avada_disable_encoding is 1 (read now), and a setting raw by its name keeps its type's own form.
 *
 * @param string              $type  Map type.
 * @param string              $name  Setting name.
 * @param mixed               $value Input.
 * @param array<string,mixed> $param Param.
 * @return string|null
 */
function aafm_avada_encode_raw( string $type, string $name, $value, array $param ): ?string {
	if ( 'repeater' === $type ) {
		if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
			return null;
		}
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) || ( array() !== $row && array_values( $row ) === $row ) ) {
				return null;
			}
			foreach ( $row as $key => $cell ) {
				if ( ! is_string( $key ) || ! is_scalar( $cell ) ) {
					return null;
				}
			}
		}
		return base64_encode( (string) wp_json_encode( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Avada's own repeater form (A3, A18), behind unfiltered_html.
	}
	$text = aafm_wpbakery_scalar_text( $value );
	if ( null === $text ) {
		return null;
	}
	if ( 'raw_textarea' === $type ) {
		return base64_encode( $text ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Avada's own raw text area form (A3), behind unfiltered_html.
	}
	if ( 'raw_text' === $type ) {
		return base64_encode( aafm_avada_dialog_escape( $text ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Avada's own raw text form (A2, A3), behind unfiltered_html.
	}
	if ( 'code' === $type ) {
		return '1' === (string) get_option( 'avada_disable_encoding', '' ) ? base64_encode( $text ) : $text; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Avada's own code block form (A3), behind unfiltered_html.
	}
	$base = aafm_avada_type_kind( $type );
	if ( 'choice' === $base ) {
		$out = aafm_avada_encode_clean( 'choice', $value, $param );
		return is_string( $out ) ? $out : null;
	}
	// link_attributes and other text settings raw by name: plain attribute text, no stripping of what was written.
	return 'text' === $base || 'content' === $base ? aafm_avada_attribute_text( $text, true, false ) : null;
}

/**
 * Whether content holds anything on section 9's Avada script list: a code block, a setting raw by its name, a raw
 * text, raw text area, repeater or JSON setting Avada's renderers decode (aafm_avada_encoded_settings()), or a setting
 * named like an event handler, each only when it holds a value (an empty repeater is "W10="). A library item insert,
 * copied or by global reference, calls this before it writes (Ruling B11, S12).
 *
 * @param string $content Content.
 * @return bool
 */
function aafm_avada_content_has_script( string $content ): bool {
	// Every name in the content is read, not only the registered Fusion tags, so an element whose shortcode is not
	// registered in this request is still inspected.
	$names    = aafm_wpbakery_shortcode_tags( $content );
	$elements = null === $names ? null : aafm_builder_elements( $content, array_values( array_unique( array_merge( aafm_fusion_shortcode_tags(), $names ) ) ) );
	if ( null === $elements ) {
		return true;
	}
	$encoded = aafm_avada_encoded_settings();
	foreach ( $elements as $element ) {
		if ( 'fusion_code' === $element['tag'] ) {
			return true;
		}
		foreach ( (array) $element['atts'] as $name => $value ) {
			$name  = (string) $name;
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' === $value || 'W10=' === $value ) {
				continue;
			}
			$listed = $encoded[ $element['tag'] ][ $name ] ?? $encoded['*'][ $name ] ?? 'json';
			if ( aafm_avada_is_raw_name( $name ) || 'json' !== $listed || 1 === preg_match( '/^on[a-z]+$/', $name ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Args for aafm/avada-list-elements.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_list_elements(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-list-elements' ),
		'description'         => aafm_ability_description( 'aafm/avada-list-elements' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'tag'    => array(
						'type'        => 'string',
						'description' => __( 'One element\'s shortcode tag, such as fusion_button. Returns that element with its settings.', 'agent-abilities-for-mcp' ),
					),
					'search' => array(
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
							'tag'          => array( 'type' => 'string' ),
							'name'         => array( 'type' => 'string' ),
							'is_container' => array( 'type' => 'boolean' ),
							'child_tag'    => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'The one element a parent element such as tabs takes as its children, or null.', 'agent-abilities-for-mcp' ),
							),
							'settings'     => array(
								'type'        => 'array',
								'description' => __( 'Only with tag. kind is how avada-update-element takes the value; writable says whether it can; needs is unfiltered_html for settings that can carry script or raw HTML.', 'agent-abilities-for-mcp' ),
							),
						),
					),
				),
				'total'      => array( 'type' => 'integer' ),
				'skipped'    => array(
					'type'        => 'integer',
					'description' => __( 'Settings left out because the map gave them no setting name.', 'agent-abilities-for-mcp' ),
				),
				'map_source' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_list_elements',
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
 * One catalog row, with settings on request. Only the setting's own default or value is read; the element-level
 * defaults and extras Avada merges in for its live editor never are (A20).
 *
 * @param string              $tag           Tag.
 * @param array<string,mixed> $entry         Map entry.
 * @param bool                $with_settings Whether to add settings.
 * @param int                 $skipped       Skip counter, added to.
 * @return array<string,mixed>
 */
function aafm_avada_catalog_row( string $tag, array $entry, bool $with_settings, int &$skipped ): array {
	$parent = 'multi_element_parent' === ( $entry['multi'] ?? null ) && is_string( $entry['element_child'] ?? null ) ? aafm_wpbakery_map_text( $entry['element_child'] ) : '';
	$row    = array(
		'tag'          => $tag,
		'name'         => aafm_wpbakery_map_text( $entry['name'] ?? $tag ),
		'is_container' => in_array( $tag, aafm_avada_layout_tags(), true ) || '' !== $parent,
		'child_tag'    => '' === $parent ? null : $parent,
	);
	if ( ! $with_settings ) {
		return $row;
	}
	$row['settings'] = array();
	foreach ( aafm_avada_settings( $entry, $skipped ) as $name => $param ) {
		$kind    = aafm_avada_param_kind( $tag, $param );
		$allowed = 'choice' === aafm_avada_type_kind( is_string( $param['type'] ?? null ) ? $param['type'] : '' )
			? array_slice( array_map( 'aafm_wpbakery_map_text', aafm_avada_choice_values( $param ) ), 0, 100 )
			: array();
		$default = $param['default'] ?? null;
		if ( ! is_scalar( $default ) ) {
			$default = is_scalar( $param['value'] ?? null ) ? $param['value'] : '';
		}
		$row['settings'][] = array(
			'name'           => $name,
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
 * Execute aafm/avada-list-elements (design AV-4).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_list_elements( array $input ) {
	$skipped = 0;
	if ( isset( $input['tag'] ) && '' !== (string) $input['tag'] ) {
		$tag   = (string) $input['tag'];
		$entry = aafm_avada_map( $tag );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$row = aafm_avada_catalog_row( $tag, $entry, true, $skipped );
		return array(
			'elements'   => array( $row ),
			'total'      => 1,
			'skipped'    => $skipped,
			'map_source' => 'avada',
		);
	}

	$map = aafm_avada_map();
	if ( is_wp_error( $map ) ) {
		return $map;
	}
	$search = isset( $input['search'] ) ? strtolower( aafm_sanitize_plain_text( (string) $input['search'] ) ) : '';
	$rows   = array();
	foreach ( $map as $tag => $entry ) {
		$row = aafm_avada_catalog_row( (string) $tag, $entry, false, $skipped );
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
		'map_source' => 'avada',
	);
}
