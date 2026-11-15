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
		'aafm/avada-list-elements'  => array(
			'label'        => __( 'List Avada elements', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists the elements Avada Builder has on this site, from Avada\'s own element map: tag, name, whether it holds other elements, and the one child element a parent element such as tabs takes. With tag it returns one element with its settings, each under the name Avada stores it (a padding setting is one per side): kind, label, default, the allowed values of a choice (at most 100), whether avada-update-element can write it, and needs, which is unfiltered_html for settings that can carry script or raw HTML. Typography and dynamic data settings are listed but cannot be written. The tablet, phone and hover variants Avada\'s builder adds are listed as settings of their own. Requires the edit_posts capability; Avada\'s Role Manager settings are not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_list_elements',
		),
		'aafm/avada-update-element' => array(
			'label'        => __( 'Update an Avada element', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Changes the settings or the inner text of one element on an Avada page, layout section, library item, form or off canvas panel, named by its index and by the tag and fingerprint avada-get-page-content returned with include_structure. set_atts sets settings by the names avada-list-elements gives, each value in the input form of its kind (an image as an attachment id under image_id, which writes the id and the URL together; a padding side such as padding_top as a CSS length); remove_atts takes settings off the element so Avada uses their default; text replaces the inner content of an element with no child elements. A double quote, a square bracket, two spaces in a row or a non-breaking space in a text setting is refused, because Avada\'s builder drops or changes it on its next save. Only a user who may post unfiltered HTML can put square brackets, percent-encoded brackets or data-* attributes in body text. Refused: a stale index or fingerprint, an element that shows a global library item (edit the item itself), a setting driven by dynamic data, a setting the element does not have or that cannot be written (typography and dynamic data cannot), a value its kind does not accept, and any change that would add or remove an element. Code blocks and settings that can carry script, such as link_attributes and custom attributes, need a user who may post unfiltered HTML. A save that would change other content for this user is refused, and nothing is saved. When a revision is kept, restore_revision_id brings the page back through restore-revision; the response also returns the element\'s new fingerprint and the page fingerprint. The outline stops at 2,000 elements. Not undoable from this plugin\'s change history. Requires edit access to the post, and for a layout section a user who can manage site options; Avada\'s Role Manager settings are not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_update_element',
		),
		'aafm/avada-insert-element' => array(
			'label'        => __( 'Insert an Avada element', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Adds one element, with its children, to an Avada page, layout section, library item, form or off canvas panel: before or after an element named by its index and fingerprint from avada-get-page-content with include_structure, or as that element\'s first or last child. element takes tag, atts (setting names and input forms from avada-list-elements), text and children. Every setting the element has is written with its default, as Avada\'s builder writes a new element; empty ones are left out when Avada\'s remove empty attributes setting is on. A container takes rows, a row takes columns, a column takes elements or nested columns, a nested row takes nested columns, and a parent element such as tabs takes only its own child element; a column needs type, its width, such as 1_2. Refused: an element type Avada\'s element map does not list, a place inside an element that shows a global library item, and a setting or value avada-update-element would refuse. A double quote, a square bracket, two spaces in a row or a non-breaking space in a text setting is refused. Code blocks and settings that can carry script need a user who may post unfiltered HTML, and so do square brackets, percent-encoded brackets and data-* attributes in body text. page_fingerprint must be the one from the last read or write; the response returns the new one with the new element\'s index and fingerprint. An empty post takes an insert with no anchor and is then marked as an Avada page. At most 50 elements, nested at most 6 deep. A save that would change other content for this user is refused, and nothing is saved. When a revision is kept, restore_revision_id brings the page back through restore-revision. Not undoable from this plugin\'s change history. Requires edit access to the post, and for a layout section a user who can manage site options; Avada\'s Role Manager settings are not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_insert_element',
		),
		'aafm/avada-move-element'   => array(
			'label'        => __( 'Move an Avada element', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Moves one element, with its children, to a new place on the same Avada page, layout section, library item, form or off canvas panel: before or after another element, or as its first or last child. The element and the anchor are each named by index and fingerprint from avada-get-page-content with include_structure. The element\'s stored text moves byte for byte; nothing is rewritten. A move to a new parent follows Avada\'s nesting (a container takes rows, a row takes columns, a column takes elements or nested columns, a parent element such as tabs takes only its own child element), and a reorder under the same parent is always allowed. Refused: a move into the element itself or one of its children, an element type Avada\'s element map does not list, a place inside an element that shows a global library item, and a move that would leave a container with no row or a row with no column. A move to the place the element already has saves nothing and returns moved false. page_fingerprint must be the one from the last read or write; the response returns the new one with the element\'s new index and fingerprint. A save that would change other content for this user is refused, and nothing is saved. When a revision is kept, restore_revision_id brings the page back through restore-revision. Not undoable from this plugin\'s change history. Requires edit access to the post, and for a layout section a user who can manage site options; Avada\'s Role Manager settings are not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_move_element',
		),
		'aafm/avada-remove-element' => array(
			'label'        => __( 'Remove an Avada element', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Removes one element, with its children, from an Avada page, layout section or library item, named by its index and fingerprint from avada-get-page-content with include_structure. Every other byte of the page stays as it was. Refused before anything is saved when no revision could bring the element back: a form or an off canvas panel (they keep no revisions), revisions switched off, or a site that keeps only 1 revision per post. Also refused: the page\'s last top level container, or a library item\'s only top level element, an element inside an element that shows a global library item, and a removal that would leave a container with no row or a row with no column. page_fingerprint must be the one from the last read or write; the response returns the new one and the number of elements removed. A save that would change other content for this user is refused, and nothing is saved. restore_revision_id brings the page back through restore-revision. Not undoable from this plugin\'s change history. Requires edit access to the post, and for a layout section a user who can manage site options; Avada\'s Role Manager settings are not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_remove_element',
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
 * Text with every entity decoded until it stops changing, at most five passes (so a double-encoded &nbsp; is seen),
 * the same bound the attribute rule uses.
 *
 * @param string $value Text.
 * @return string
 */
function aafm_avada_decoded_text( string $value ): string {
	for ( $i = 0; $i < 5; $i++ ) {
		$next = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $next === $value ) {
			break;
		}
		$value = $next;
	}
	return $value;
}

/**
 * Attribute text in the form Avada's editor keeps: every entity decoded first (repeatedly, so a double-encoded quote is
 * seen), then refused when it holds a double quote (the editor drops the whole attribute on its next save), a square
 * bracket, a control character or, for one line, a line break; then &, < and > are written as entities and the
 * apostrophe and the backtick as characters, which is what the editor stores after its own save. Nothing else is
 * removed or collapsed: a value is stored as sent or refused, never stored altered (CX-BR1-2), so text such as
 * "Press <Ctrl> + S" or "100%25" keeps every character.
 *
 * @param string $text     Text.
 * @param bool   $one_line Whether line breaks are refused.
 * @return string|null Null when refused.
 */
function aafm_avada_attribute_text( string $text, bool $one_line = true ): ?string {
	$text     = aafm_avada_decoded_text( $text );
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
	// Body text returned above, so a content kind here is a textarea stored as an attribute: the same rule applies.
	$attribute = ( 'text' === $kind || 'content' === $kind ) && is_string( $value );
	if ( $attribute && 1 === preg_match( '/\x{00A0}|\x{200B}/u', aafm_avada_decoded_text( $value ) ) ) {
		// BR-5: WordPress reads either one as a plain space in an attribute, and Avada's editor stores a plain space on
		// a later save, whether it was sent as the character or as &nbsp; (smoke site, fix pass 2).
		return new WP_Error(
			'aafm_avada_nonbreaking_space',
			/* translators: %s: the setting name. */
			sprintf( __( 'The value of %s has a non-breaking or zero-width space, which Avada\'s editor turns into an ordinary space when the page is saved. Use an ordinary space. Nothing was changed.', 'agent-abilities-for-mcp' ), $name ),
			array(
				'status'  => 400,
				'setting' => $name,
			)
		);
	}
	if ( $attribute && false !== strpos( aafm_avada_decoded_text( $value ), '  ' ) ) {
		// Avada's editor collapses a run of spaces in an attribute on its next save, so the value would not stay as sent.
		return new WP_Error(
			'aafm_avada_repeated_spaces',
			/* translators: %s: the setting name. */
			sprintf( __( 'The value of %s has two or more spaces in a row, which Avada\'s editor turns into one space the next time the page is saved. Use single spaces. Nothing was changed.', 'agent-abilities-for-mcp' ), $name ),
			array(
				'status'  => 400,
				'setting' => $name,
			)
		);
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
		if ( $id < 1 ) {
			return null;
		}
		$attachment = aafm_exact_object( 'post', $id );
		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $id ) ) {
			return null;
		}
		$url = (string) wp_get_attachment_url( $id );
		if ( '' === $url ) {
			return null;
		}
		$url = aafm_avada_attribute_text( $url );
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
			return aafm_avada_attribute_text( wp_kses_post( $text ), false );
		case 'link':
			$url = aafm_wpbakery_link_url( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			return null === $url ? null : aafm_avada_attribute_text( $url );
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
	return 'text' === $base || 'content' === $base ? aafm_avada_attribute_text( $text ) : null;
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

/**
 * The reversibility note of an engine result: reversible_reason only when the engine set it (there is no restore point:
 * revisions are off, or the save pruned it).
 *
 * @param array<string,mixed> $result aafm_builder_commit() result.
 * @return array<string,string>
 */
function aafm_avada_reversible_reason( array $result ): array {
	return isset( $result['reversible_reason'] ) && is_string( $result['reversible_reason'] ) ? array( 'reversible_reason' => $result['reversible_reason'] ) : array();
}

/**
 * The write output schema every Avada element write shares, with its own fields added.
 *
 * @param array<string,mixed> $extra Extra properties.
 * @return array<string,mixed>
 */
function aafm_avada_write_output_schema( array $extra ): array {
	return array(
		'type'       => 'object',
		'properties' => array_merge(
			array( 'post' => array( 'type' => 'object' ) ),
			$extra,
			array(
				'page_fingerprint'    => array( 'type' => 'string' ),
				'restore_revision_id' => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Pass to restore-revision to put the page back as it was. Null when nothing was saved or no revision is kept.', 'agent-abilities-for-mcp' ),
				),
				'reversible'          => array( 'type' => 'boolean' ),
				'reversible_reason'   => array(
					'type'        => 'string',
					'description' => __( 'Present only when there is no restore point: the site or the content type keeps no revisions, WordPress saved none before the change, or the one saved was deleted during the save by the revision limit or another plugin. Says why there is no way back.', 'agent-abilities-for-mcp' ),
				),
			)
		),
	);
}

/**
 * The element-address input fields: index and expect.
 *
 * @return array<string,mixed>
 */
function aafm_avada_address_schema(): array {
	return array(
		'index'  => array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => __( 'The element\'s index in the outline from avada-get-page-content with include_structure.', 'agent-abilities-for-mcp' ),
		),
		'expect' => array(
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
	);
}

/**
 * Args for aafm/avada-update-element.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_update_element(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-update-element' ),
		'description'         => aafm_ability_description( 'aafm/avada-update-element' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'post_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of the page, post, layout section, library item, form or off canvas panel.', 'agent-abilities-for-mcp' ),
					),
				),
				aafm_avada_address_schema(),
				array(
					'set_atts'    => array(
						'type'        => 'object',
						'description' => __( 'Setting name to value, each name and value in the form avada-list-elements gives.', 'agent-abilities-for-mcp' ),
					),
					'remove_atts' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Setting names to take off the element, so Avada uses their default.', 'agent-abilities-for-mcp' ),
					),
					'text'        => array(
						'type'        => 'string',
						'description' => __( 'New inner content for an element with no child elements, such as a text block, a title or a button\'s text. HTML is filtered as in a post; a code block\'s code needs unfiltered HTML.', 'agent-abilities-for-mcp' ),
					),
				)
			),
			'required'             => array( 'post_id', 'index', 'expect' ),
			'additionalProperties' => false,
		),
		'output_schema'       => aafm_avada_write_output_schema(
			array(
				'index'       => array( 'type' => 'integer' ),
				'fingerprint' => array( 'type' => 'string' ),
				'changed'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Settings whose stored value changed, and text when the inner content did. Empty when nothing was saved.', 'agent-abilities-for-mcp' ),
				),
			)
		),
		'execute_callback'    => 'aafm_exec_avada_update_element',
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
 * The refusal for a post that is not an Avada page.
 *
 * @return WP_Error
 */
function aafm_avada_not_owned_error(): WP_Error {
	return new WP_Error(
		'aafm_not_avada_owned',
		__( 'This post is not an Avada page. Edit a page built with another builder in that builder. Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 409 )
	);
}

/**
 * The settings an element's dynamic data drives (A3: base64 JSON keyed by setting name), or null when the element
 * carries dynamic data that cannot be read, which then blocks every change.
 *
 * @param array<string,mixed> $atts Stored attributes.
 * @return array<string>|null
 */
function aafm_avada_dynamic_settings( array $atts ): ?array {
	$raw = isset( $atts['dynamic_params'] ) && is_string( $atts['dynamic_params'] ) ? trim( $atts['dynamic_params'] ) : '';
	if ( '' === $raw ) {
		return array();
	}
	$data = json_decode( aafm_avada_decode_if_needed( $raw ), true );
	return is_array( $data ) ? array_map( 'strval', array_keys( $data ) ) : null;
}

/**
 * The refusal for a setting dynamic data drives.
 *
 * @param string $name Setting.
 * @return WP_Error
 */
function aafm_avada_dynamic_error( string $name ): WP_Error {
	return new WP_Error(
		'aafm_avada_dynamic_setting',
		/* translators: %s: the setting name. */
		sprintf( __( 'Dynamic data drives the setting %s on this element, so a stored value would be ignored when the page is shown. Change it in Avada\'s builder. Nothing was changed.', 'agent-abilities-for-mcp' ), $name ),
		array(
			'status'  => 400,
			'setting' => $name,
		)
	);
}

/**
 * Execute aafm/avada-update-element (design AV-3 with AV-11 and the raw kinds of AV-14).
 *
 * Resolves the element by index and fingerprint with the tags the read used, refuses an element that shows a global
 * library item, looks its settings up in Avada's map, applies the script gate to every touched setting before any value
 * is encoded, rebuilds only the opening tag and, for text or an image, the inner content, and hands the result to the
 * one guarded save (aafm_builder_commit()) with no CSS step: Avada resets a page's compiled CSS on every save (A11).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_update_element( array $input ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( ! aafm_builder_ownership_allows( 'avada', aafm_post_has_foreign_builder_ownership( $id ), $post ) ) {
		return aafm_avada_not_owned_error();
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
	$tags    = aafm_fusion_shortcode_tags();
	$element = aafm_builder_resolve( $content, $tags, (int) ( $input['index'] ?? -1 ), is_array( $input['expect'] ?? null ) ? $input['expect'] : array() );
	if ( is_wp_error( $element ) ) {
		return $element;
	}
	$tag    = (string) $element['tag'];
	$global = aafm_avada_global_id( $tag, (array) $element['atts'] );
	if ( null !== $global ) {
		return new WP_Error(
			'aafm_avada_global_element',
			/* translators: %1$d: the library item's post id. */
			sprintf( __( 'This element shows the global library item %1$d, which every page that shows it shares. Edit item %1$d itself instead. Nothing was changed.', 'agent-abilities-for-mcp' ), $global ),
			array(
				'status'  => 409,
				'item_id' => $global,
			)
		);
	}
	$entry = aafm_avada_map( $tag );
	if ( is_wp_error( $entry ) ) {
		return $entry;
	}
	$settings  = aafm_avada_settings( $entry );
	$inner_par = aafm_avada_content_param( $entry );
	$dynamic   = aafm_avada_dynamic_settings( (array) $element['atts'] );

	// Every touched setting is checked, and gated, before any value is encoded.
	$touched = array();
	foreach ( array_merge( array_map( 'strval', array_keys( $set ) ), array_map( 'strval', $remove ) ) as $name ) {
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $name ) || ! isset( $settings[ $name ] ) ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $name );
		}
		$kind = aafm_avada_param_kind( $tag, $settings[ $name ] );
		if ( ! $kind['writable'] ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $name );
		}
		// Dynamic data is keyed by the param's own name, so a side of a dimension or image_id (element_content) is
		// checked under that name too.
		if ( null === $dynamic || array() !== array_intersect( array( $name, (string) ( $settings[ $name ]['param_name'] ?? '' ) ), $dynamic ) ) {
			return aafm_avada_dynamic_error( $name );
		}
		$gate = aafm_avada_script_gate( $kind['kind'] );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$touched[ $name ] = $kind['kind'];
	}
	$has_children = in_array( (int) $element['index'], array_column( (array) aafm_builder_elements( $content, $tags ), 'parent' ), true );
	$text_kind    = null;
	if ( $has_text ) {
		$kind = null !== $inner_par && ! $has_children && 'upload' !== ( $inner_par['type'] ?? null ) ? aafm_avada_param_kind( $tag, $inner_par ) : null;
		if ( null === $kind || ! $kind['writable'] ) {
			return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', 'text' );
		}
		if ( null === $dynamic || in_array( 'element_content', $dynamic, true ) ) {
			return aafm_avada_dynamic_error( 'element_content' );
		}
		$gate = aafm_avada_script_gate( $kind['kind'] );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$text_kind = $kind['kind'];
	}

	// The opening tag, and its attribute text between the tag name and the closing bracket.
	$open      = substr( $content, (int) $element['start'], (int) $element['open_end'] - (int) $element['start'] );
	$tail      = $element['self_closing'] ? (string) preg_replace( '/^.*?(\s*\/\]|\])$/s', '$1', $open ) : ']';
	$attr_text = substr( $open, strlen( $tag ) + 1, strlen( $open ) - strlen( $tag ) - 1 - strlen( $tail ) );
	$spans     = aafm_wpbakery_attribute_spans( $attr_text );
	$inner     = substr( $content, (int) $element['open_end'], (int) $element['inner_end'] - (int) $element['open_end'] );
	$new_inner = $inner;

	$values = array();
	foreach ( $set as $name => $value ) {
		$name    = (string) $name;
		$encoded = aafm_avada_encode_value( $touched[ $name ], $value, $settings[ $name ] );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		foreach ( is_array( $encoded ) ? $encoded : array( $name => $encoded ) as $attr => $stored ) {
			if ( '' === $attr ) {
				$new_inner = $stored;
				continue;
			}
			$values[ $attr ] = $stored;
		}
	}
	if ( $has_text && null !== $text_kind ) {
		$encoded = aafm_avada_encode_value( $text_kind, $input['text'], (array) $inner_par );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}
		$new_inner = is_string( $encoded ) ? $encoded : $new_inner;
	}

	$changed  = array();
	$edits    = array();
	$appended = '';
	foreach ( $values as $name => $encoded ) {
		$name = (string) $name;
		if ( isset( $spans[ $name ] ) && $spans[ $name ]['value'] === $encoded ) {
			continue;
		}
		$changed[] = $name;
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
	if ( $new_inner !== $inner ) {
		if ( ! $element['has_closer'] || $element['self_closing'] || aafm_wpbakery_text_closes_a_tag( $new_inner, array_values( array_unique( array_merge( $tags, (array) aafm_wpbakery_shortcode_tags( $content, $new_inner ) ) ) ) ) ) {
			return aafm_builder_error( 'aafm_builder_structure_changed' );
		}
		$changed[] = $has_text ? 'text' : 'image_id';
		$changed   = array_values( array_unique( $changed ) );
	}

	$source = '[' . $tag . $new_attr_text . $tail . $new_inner . substr( $content, (int) $element['inner_end'], (int) $element['end'] - (int) $element['inner_end'] );
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

	$result = aafm_builder_commit( $post, $content, $new, $tags, $expected, null, 'avada' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$stored   = (string) $result['post']->post_content;
	$elements = aafm_builder_elements( $stored, $tags );
	$after    = $elements[ (int) $element['index'] ] ?? null;
	return array(
		'post'                => aafm_redact_post( $result['post'] ),
		'index'               => (int) $element['index'],
		'fingerprint'         => null === $after ? '' : aafm_builder_fingerprint( $stored, $after ),
		'page_fingerprint'    => $result['page_fingerprint'],
		'changed'             => $result['unchanged'] ? array() : $changed,
		'restore_revision_id' => $result['restore_revision_id'],
		'reversible'          => $result['reversible'],
	) + aafm_avada_reversible_reason( $result );
}

/**
 * The refusal for an insert over its limits: 50 elements and 6 levels per call, as for WPBakery.
 *
 * @return WP_Error
 */
function aafm_avada_insert_limit_error(): WP_Error {
	return new WP_Error(
		'aafm_builder_insert_limit',
		__( 'One insert may add at most 50 elements, nested at most 6 deep. Split the change into smaller inserts. Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 400 )
	);
}

/**
 * Whether a tag is a child element: one a parent element names as its element_child (A6), or one Avada maps as a
 * multi element child. Such an element goes only inside that parent.
 *
 * @param string                            $tag Tag.
 * @param array<string,array<string,mixed>> $map Map entries keyed by tag.
 * @return bool
 */
function aafm_avada_is_child_element( string $tag, array $map ): bool {
	if ( 'multi_element_child' === ( $map[ $tag ]['multi'] ?? null ) ) {
		return true;
	}
	foreach ( $map as $entry ) {
		if ( 'multi_element_parent' === ( $entry['multi'] ?? null ) && ( $entry['element_child'] ?? null ) === $tag ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether Avada's builder lets $child_tag sit inside $parent_tag (design AV-5). The top level ('') takes a container, a
 * container takes rows, a row takes columns, an inner row takes inner columns, a column takes content elements or an
 * inner row, an inner column takes content elements, and a parent element takes only the child element it names (A6).
 * The child's own rule counts too: a child element such as a tab goes only inside its parent element. Anything else,
 * including a parent the map does not know, is refused.
 *
 * Demo #11: a flex container (type="flex") also takes a column directly, as Avada Studio ships its sections and Avada's
 * builder accepts them. Only existing content (a library copy or a move) passes $flex_parent; a new element still gets a row.
 *
 * @param string                            $parent_tag  Parent tag, '' for the top level.
 * @param string                            $child_tag   Child tag.
 * @param array<string,array<string,mixed>> $map         Map entries keyed by tag.
 * @param bool                              $flex_parent Whether the parent is a flex container holding existing content.
 * @return true|WP_Error aafm_builder_invalid_nesting (400) naming parent and child.
 */
function aafm_avada_nesting_allows( string $parent_tag, string $child_tag, array $map, bool $flex_parent = false ) {
	$layout = aafm_avada_layout_tags();
	$fixed  = array(
		''                         => 'fusion_builder_container',
		'fusion_builder_container' => 'fusion_builder_row',
		'fusion_builder_row'       => 'fusion_builder_column',
		'fusion_builder_row_inner' => 'fusion_builder_column_inner',
	);
	$child  = aafm_avada_is_child_element( $child_tag, $map );
	if ( isset( $fixed[ $parent_tag ] ) ) {
		$ok = $fixed[ $parent_tag ] === $child_tag || ( $flex_parent && 'fusion_builder_container' === $parent_tag && 'fusion_builder_column' === $child_tag );
	} elseif ( 'fusion_builder_column' === $parent_tag || 'fusion_builder_column_inner' === $parent_tag ) {
		$ok = ( ! in_array( $child_tag, $layout, true ) && ! $child && isset( $map[ $child_tag ] ) )
			|| ( 'fusion_builder_column' === $parent_tag && 'fusion_builder_row_inner' === $child_tag );
	} else {
		$entry = $map[ $parent_tag ] ?? array();
		$ok    = 'multi_element_parent' === ( $entry['multi'] ?? null ) && ( $entry['element_child'] ?? null ) === $child_tag;
	}
	if ( $ok ) {
		return true;
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
 * Whether an element is a flex container: a fusion_builder_container whose stored type is flex.
 *
 * @param array<string,mixed> $element Outline element or tree node (tag and atts).
 * @return bool
 */
function aafm_avada_is_flex_container( array $element ): bool {
	return 'fusion_builder_container' === ( $element['tag'] ?? null ) && 'flex' === ( ( (array) ( $element['atts'] ?? array() ) )['type'] ?? null );
}

/**
 * Checks nesting through a tree: each node inside its parent, then its own children inside it.
 *
 * @param array<int,array<string,mixed>>    $nodes        Nodes.
 * @param string                            $parent_tag   Parent tag, '' for the top level.
 * @param array<string,array<string,mixed>> $map          Map entries keyed by tag.
 * @param bool                              $existing     Whether the nodes are existing content (a library copy), for
 *                                                         which a flex container may hold a column directly.
 * @param bool                              $flex_parent  Whether the parent is a flex container (with $existing).
 * @return true|WP_Error
 */
function aafm_avada_tree_nesting( array $nodes, string $parent_tag, array $map, bool $existing = false, bool $flex_parent = false ) {
	foreach ( $nodes as $node ) {
		$ok = aafm_avada_nesting_allows( $parent_tag, (string) $node['tag'], $map, $existing && $flex_parent );
		if ( true === $ok ) {
			$ok = aafm_avada_tree_nesting( array_values( (array) ( $node['children'] ?? array() ) ), (string) $node['tag'], $map, $existing, aafm_avada_is_flex_container( $node ) );
		}
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
	}
	return true;
}

/**
 * A column width as the editor stores it in a column's type (A27): a fraction such as 1_2, or a percentage.
 *
 * @param mixed $value Input.
 * @return string|null Null when refused.
 */
function aafm_avada_column_width( $value ): ?string {
	if ( ! is_string( $value ) ) {
		return null;
	}
	if ( 1 === preg_match( '/^([1-9][0-9]?)_([1-9][0-9]?)$/', $value, $m ) ) {
		return (int) $m[1] <= (int) $m[2] ? $value : null;
	}
	return 1 === preg_match( '/^(100|[1-9]?[0-9](\.[0-9]{1,2})?)%$/', $value ) && (float) $value > 0 ? $value : null;
}

/**
 * The editor's first and last flags for columns, worked out as its legacy column spacing pass does (app.js
 * legacyColumnSpacing()): columns, in page order, are grouped while their widths add up to at most 1 (the running sum
 * carries across rows; a new row starts a new group), and the first and last column of each group are flagged.
 *
 * @param list<array{tag:string,width:string}> $items Rows and columns in page order.
 * @return list<array{first:string,last:string}> One per column item, in order.
 */
function aafm_avada_column_flags( array $items ): array {
	$ids    = array(
		'fusion_builder_column'       => 0,
		'fusion_builder_column_inner' => 0,
	);
	$sums   = array(
		'fusion_builder_column'       => 0.0,
		'fusion_builder_column_inner' => 0.0,
	);
	$rows   = array(
		'fusion_builder_row'       => 'fusion_builder_column',
		'fusion_builder_row_inner' => 'fusion_builder_column_inner',
	);
	$groups = array();
	$order  = array();
	foreach ( $items as $item ) {
		if ( isset( $rows[ $item['tag'] ] ) ) {
			++$ids[ $rows[ $item['tag'] ] ];
			continue;
		}
		$kind = $item['tag'];
		$w    = $item['width'];
		if ( 1 === preg_match( '/^(\d+)_(\d+)$/', $w, $m ) && 0 < (int) $m[2] ) {
			$width = (int) $m[1] / (int) $m[2];
		} else {
			$width = (float) $w > 1 ? (float) $w / 100 : (float) $w;
		}
		$sums[ $kind ] += $width;
		if ( $sums[ $kind ] > 1 ) {
			++$ids[ $kind ];
			$sums[ $kind ] = $width;
		}
		$key              = $kind . ':' . $ids[ $kind ];
		$groups[ $key ][] = count( $order );
		$order[]          = $key;
	}
	$out = array();
	foreach ( $order as $i => $key ) {
		$out[] = array(
			'first' => $groups[ $key ][0] === $i ? 'true' : 'false',
			'last'  => end( $groups[ $key ] ) === $i ? 'true' : 'false',
		);
	}
	return $out;
}

/**
 * The value the editor writes for a setting the caller did not set (design 4.7, Ruling B4): the setting's own default,
 * else its value when that is not a choice list; a typography sub-value is empty. Null for a setting the editor never
 * writes (remove_from_atts, other than the sides and sub-values that stand in for it) and for a default that could not
 * sit inside a double-quoted shortcode attribute.
 *
 * @param string              $name  Stored name.
 * @param array<string,mixed> $param Param from aafm_avada_settings().
 * @return string|null
 */
function aafm_avada_default_value( string $name, array $param ): ?string {
	$type = is_string( $param['type'] ?? null ) ? $param['type'] : '';
	if ( 'image_id' === $name || ( ! empty( $param['remove_from_atts'] ) && 'size' !== aafm_avada_type_kind( $type ) && 'typography' !== $type ) ) {
		return null;
	}
	if ( 'typography' === $type ) {
		return '';
	}
	$default = $param['default'] ?? null;
	if ( ! is_scalar( $default ) ) {
		$default = is_scalar( $param['value'] ?? null ) ? $param['value'] : '';
	}
	$default = (string) $default;
	return false === strpbrk( $default, "\"[]\\\r\n" ) ? $default : null;
}

/**
 * Serializes untrusted nodes the way Avada's editor writes a new element (design AV-5, Ruling B4, probe B and C in
 * s10b-report.md): every setting of the element in map order with its default, the caller's values in their place
 * through the one encoder, inner text through the body-text choke point, every script kind gated before anything is
 * encoded. A column leads with its width as type and layout and ends with the first, last and border_position the
 * editor adds; a row carries nothing; an inner row only its non-empty settings; empty settings are dropped when the
 * site's remove_empty_attributes is on. The tag form follows A1.
 *
 * @param array<int,array<string,mixed>>        $nodes        Nodes.
 * @param array<string,array<string,mixed>>     $map          Map entries keyed by tag.
 * @param array<string>                         $tags         Shortcode names, for the closer check.
 * @param list<array{first:string,last:string}> $flags    Column flags in page order, consumed.
 * @param bool                                  $remove_empty Whether the site drops empty attributes.
 * @return string|WP_Error
 */
function aafm_avada_serialize_nodes( array $nodes, array $map, array $tags, array &$flags, bool $remove_empty ) {
	$out = '';
	foreach ( $nodes as $node ) {
		$tag       = (string) $node['tag'];
		$entry     = $map[ $tag ];
		$settings  = aafm_avada_settings( $entry );
		$inner_par = aafm_avada_content_param( $entry );
		$children  = array_values( (array) ( $node['children'] ?? array() ) );
		$set       = (array) ( $node['atts'] ?? array() );
		$column    = in_array( $tag, array( 'fusion_builder_column', 'fusion_builder_column_inner' ), true );
		$width     = null;
		if ( $column ) {
			$width = aafm_avada_column_width( $set['type'] ?? null );
			if ( null === $width ) {
				return new WP_Error(
					'aafm_avada_column_needs_width',
					__( 'A column needs type set to its width, a fraction such as 1_2 or a percentage such as 25%. Nothing was changed.', 'agent-abilities-for-mcp' ),
					array( 'status' => 400 )
				);
			}
			unset( $set['type'] );
		}

		// Every setting is checked, and gated, before any value is encoded.
		$kinds = array();
		foreach ( array_map( 'strval', array_keys( $set ) ) as $name ) {
			$kind = 1 === preg_match( '/^[a-z0-9_]+$/', $name ) && isset( $settings[ $name ] ) ? aafm_avada_param_kind( $tag, $settings[ $name ] ) : null;
			if ( null === $kind || ! $kind['writable'] ) {
				return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', $name );
			}
			$gate = aafm_avada_script_gate( $kind['kind'] );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			$kinds[ $name ] = $kind['kind'];
		}
		$text_kind = null;
		if ( isset( $node['text'] ) ) {
			$kind = null !== $inner_par && array() === $children && 'upload' !== ( $inner_par['type'] ?? null ) ? aafm_avada_param_kind( $tag, $inner_par ) : null;
			if ( null === $kind || ! $kind['writable'] ) {
				return aafm_wpbakery_setting_error( 'aafm_builder_attribute_not_writable', 'text' );
			}
			$gate = aafm_avada_script_gate( $kind['kind'] );
			if ( is_wp_error( $gate ) ) {
				return $gate;
			}
			$text_kind = $kind['kind'];
		}

		$atts = null === $width ? array() : array(
			'type'   => $width,
			'layout' => $width,
		);
		foreach ( $settings as $name => $param ) {
			$default = $column && in_array( $name, array( 'type', 'layout' ), true ) ? null : aafm_avada_default_value( (string) $name, $param );
			if ( null !== $default ) {
				$atts[ (string) $name ] = $default;
			}
		}
		$inner = '';
		foreach ( $set as $name => $value ) {
			$encoded = aafm_avada_encode_value( $kinds[ (string) $name ], $value, $settings[ (string) $name ] );
			if ( is_wp_error( $encoded ) ) {
				return $encoded;
			}
			foreach ( is_array( $encoded ) ? $encoded : array( (string) $name => $encoded ) as $attr => $stored ) {
				if ( '' === (string) $attr ) {
					$inner = (string) $stored;
				} else {
					$atts[ (string) $attr ] = (string) $stored;
				}
			}
		}
		if ( $column ) {
			$flag = array_shift( $flags ) ?? array(
				'first' => 'true',
				'last'  => 'true',
			);
			unset( $atts['first'], $atts['last'] );
			$atts['first'] = $flag['first'];
			$atts['last']  = $flag['last'];
			if ( ! isset( $atts['border_position'] ) ) {
				$atts['border_position'] = 'all';
			}
		}
		if ( $remove_empty || 'fusion_builder_row_inner' === $tag ) {
			$atts = array_filter(
				$atts,
				static function ( string $value ): bool {
					return '' !== $value;
				}
			);
		}
		// A value that would leave its quotes changes the outline; the commit would refuse it, so refuse it here by name.
		foreach ( $atts as $name => $value ) {
			$parsed = shortcode_parse_atts( $name . '="' . $value . '"' );
			if ( ! is_array( $parsed ) || array( $name => $value ) !== $parsed ) {
				return aafm_wpbakery_setting_error( 'aafm_builder_invalid_value', (string) $name );
			}
		}

		if ( null !== $text_kind ) {
			$encoded = aafm_avada_encode_value( $text_kind, $node['text'], (array) $inner_par );
			if ( is_wp_error( $encoded ) ) {
				return $encoded;
			}
			$inner = is_string( $encoded ) ? $encoded : $inner;
		} elseif ( array() !== $children ) {
			$inner = aafm_avada_serialize_nodes( $children, $map, $tags, $flags, $remove_empty );
			if ( is_wp_error( $inner ) ) {
				return $inner;
			}
		}
		if ( array() === $children && aafm_wpbakery_text_closes_a_tag( $inner, array_values( array_unique( array_merge( $tags, (array) aafm_wpbakery_shortcode_tags( $inner ) ) ) ) ) ) {
			return aafm_builder_error( 'aafm_builder_structure_changed' );
		}
		$void = 'awb_dd' === $tag || ( '' === $inner && ! in_array( $tag, aafm_avada_layout_tags(), true ) && ! in_array( $tag, array( 'fusion_text', 'fusion_code' ), true ) );
		$out .= aafm_builder_serialize( $tag, $atts, $void ? null : $inner, $void ? 'avada_void' : 'avada_closed' );
	}
	return $out;
}

/**
 * The refusal for an insert that names no anchor on a post that has content.
 *
 * @return WP_Error
 */
function aafm_avada_anchor_required_error(): WP_Error {
	return new WP_Error(
		'aafm_avada_anchor_required',
		__( 'Give anchor_index, anchor_expect and placement together. Only an empty post takes an insert without them. Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 400 )
	);
}

/**
 * Whether the element at $index sits inside an element that shows a global library item (Ruling R-S8b-5): a change
 * there would be a change to this page's copy of the item, which Avada's builder keeps in step with the item itself.
 *
 * @param list<array<string,mixed>> $elements Outline.
 * @param int|null                  $index    Element, or null for the top level.
 * @return int|null The item's post id, or null.
 */
function aafm_avada_global_ancestor( array $elements, ?int $index ): ?int {
	while ( null !== $index && isset( $elements[ $index ] ) ) {
		$global = aafm_avada_global_id( (string) $elements[ $index ]['tag'], (array) $elements[ $index ]['atts'] );
		if ( null !== $global ) {
			return $global;
		}
		$index = null === $elements[ $index ]['parent'] ? null : (int) $elements[ $index ]['parent'];
	}
	return null;
}

/**
 * The refusal for a change inside an element that shows a global library item.
 *
 * @param int $item_id Library item post id.
 * @return WP_Error
 */
function aafm_avada_inside_global_error( int $item_id ): WP_Error {
	return new WP_Error(
		'aafm_avada_global_element',
		/* translators: %1$d: the library item's post id. */
		sprintf( __( 'That place is inside the global library item %1$d, which every page that shows it shares. Change item %1$d itself instead. Nothing was changed.', 'agent-abilities-for-mcp' ), $item_id ),
		array(
			'status'  => 409,
			'item_id' => $item_id,
		)
	);
}

/**
 * The trusted-path text check (Ruling R-S11-1 applied to Avada): for a user who may not post unfiltered HTML, stored
 * text that carries a script kind is refused, and so is every run of text outside the shortcodes (a leaf's inner text,
 * the text between children, the text outside the roots) that the body-text choke point refuses or would change.
 *
 * @param string        $text Stored shortcode text.
 * @param array<string> $tags Tags to read.
 * @return true|WP_Error
 */
function aafm_avada_trusted_text_allowed( string $text, array $tags ) {
	if ( current_user_can( 'unfiltered_html' ) ) {
		return true;
	}
	if ( aafm_avada_content_has_script( $text ) ) {
		return aafm_builder_error( 'aafm_builder_needs_unfiltered_html' );
	}
	$elements = aafm_builder_elements( $text, $tags );
	if ( null === $elements ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	// Every run of text is checked: a leaf's inner text, the text between a container's children, and, through the
	// whole text as one more container, the text before, between and after the roots, which Avada prints too.
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
			$clean = aafm_avada_body_text( $segment );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			if ( $clean !== $segment ) {
				return new WP_Error(
					'aafm_avada_stored_text_needs_unfiltered_html',
					__( 'This stored content holds HTML that would be filtered out for this user, so it needs a user who may post unfiltered HTML. Nothing was changed.', 'agent-abilities-for-mcp' ),
					array( 'status' => 400 )
				);
			}
		}
	}
	return true;
}

/**
 * The shared Avada insert path (design AV-5, handover 2 for S12 and S13). Checks the limits, every tag against the map
 * (a tag the map cannot resolve or no registered shortcode renders, fusion_global included, is refused before any
 * nesting check; only a trusted root may be a [fusion_global id] reference, Ruling R-S12-1), the nesting, the place
 * (never inside an element that shows a global library item), gates script content, serializes (untrusted) or splices
 * the stored sources (trusted), and saves through aafm_builder_commit() with no CSS step: Avada resets a page's
 * compiled CSS on every save (A11). The caller has already loaded the post, called the ownership check in its own body,
 * applied aafm_builder_ownership_allows() and run aafm_builder_page_check().
 *
 * With $trusted_vendor_text the nodes come from aafm_builder_tree() and each root carries source, spliced in byte for
 * byte; tag and children are read only for the limits, the map and nesting. For a user who may not post unfiltered HTML
 * the stored text must pass aafm_avada_trusted_text_allowed(). Without it each atts value is the input form of its kind
 * and goes through the one encoder, and text through the body-text choke point (aafm_avada_serialize_nodes()).
 *
 * $anchor is array{index:?int,expect:?array,placement:?string}; all null only for an empty post (Ruling Q7). The
 * returned post is not redacted: every executor redacts it.
 *
 * @param WP_Post                        $post                Post.
 * @param array<int,array<string,mixed>> $tree                Root nodes.
 * @param array<string,mixed>            $anchor              Anchor.
 * @param bool                           $trusted_vendor_text Whether the nodes are stored vendor text.
 * @return array{post:WP_Post,index:int,fingerprint:string,page_fingerprint:string,inserted:int,restore_revision_id:?int,reversible:bool,reversible_reason?:string}|WP_Error
 */
function aafm_avada_insert_tree( WP_Post $post, array $tree, array $anchor, bool $trusted_vendor_text ) {
	$tree    = array_values( $tree );
	$content = (string) $post->post_content;
	if ( $trusted_vendor_text ) {
		foreach ( $tree as $i => $root ) {
			if ( ! is_array( $root ) || ! is_string( $root['source'] ?? null ) ) {
				return aafm_builder_error( 'aafm_builder_invalid_value' );
			}
			if ( 'fusion_global' !== ( $root['tag'] ?? null ) ) {
				continue;
			}
			// Ruling R-S12-1: a global library reference, [fusion_global id], carrying only its id. It is in neither the
			// map nor the nesting rules, so the limits, the map and nesting check it as the item it shows (place_as, the
			// item's own root tag); the stored bytes are the reference. Never reachable from the untrusted branch.
			$atts = $root['atts'] ?? null;
			if ( ! is_array( $atts ) || array( 'id' ) !== array_keys( $atts ) || 1 !== preg_match( '/^[1-9][0-9]*$/', (string) $atts['id'] )
				|| array() !== ( $root['children'] ?? array() ) || ! is_string( $root['place_as'] ?? null ) || 'fusion_global' === $root['place_as'] ) {
				return aafm_builder_error( 'aafm_builder_invalid_value' );
			}
			$tree[ $i ]['tag'] = $root['place_as'];
		}
	}
	$seen  = array();
	$stats = aafm_wpbakery_tree_stats( $tree, 1, $seen );
	if ( is_wp_error( $stats ) ) {
		return $stats;
	}
	if ( 0 === $stats['count'] ) {
		return aafm_builder_error( 'aafm_builder_invalid_value' );
	}
	if ( $stats['count'] > 50 || $stats['depth'] > 6 ) {
		return aafm_avada_insert_limit_error();
	}

	// The tags the page reads use, so the returned index and fingerprint match the next outline.
	$tags     = aafm_fusion_shortcode_tags();
	$elements = aafm_builder_elements( $content, $tags );
	if ( null === $elements ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$index     = $anchor['index'] ?? null;
	$expect    = $anchor['expect'] ?? null;
	$placement = $anchor['placement'] ?? null;
	if ( null === $index && null === $expect && null === $placement ) {
		if ( '' !== trim( $content ) ) {
			return aafm_avada_anchor_required_error();
		}
		$point = array(
			'offset' => strlen( $content ),
			'at'     => count( $elements ),
			'depth'  => 0,
			'parent' => null,
		);
	} else {
		if ( ! is_int( $index ) || ! is_array( $expect ) || ! is_string( $placement ) ) {
			return aafm_avada_anchor_required_error();
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
	$global = aafm_avada_global_ancestor( $elements, $point['parent'] );
	if ( null !== $global ) {
		return aafm_avada_inside_global_error( $global );
	}

	$map = aafm_avada_map();
	if ( is_wp_error( $map ) ) {
		return $map;
	}
	// A tag the map lists but no registered shortcode renders would be invisible to the outline: refused as well.
	foreach ( array_unique( $seen ) as $tag ) {
		if ( ! isset( $map[ $tag ] ) || ! in_array( $tag, $tags, true ) ) {
			return aafm_builder_error( 'aafm_builder_unknown_element' );
		}
	}
	$parent_tag = null === $point['parent'] ? '' : (string) $elements[ $point['parent'] ]['tag'];
	$nesting    = aafm_avada_tree_nesting( $tree, $parent_tag, $map, $trusted_vendor_text, null !== $point['parent'] && aafm_avada_is_flex_container( $elements[ $point['parent'] ] ) );
	if ( is_wp_error( $nesting ) ) {
		return $nesting;
	}

	if ( $trusted_vendor_text ) {
		$text  = implode( '', array_column( $tree, 'source' ) );
		$allow = aafm_avada_trusted_text_allowed( $text, $tags );
		if ( is_wp_error( $allow ) ) {
			return $allow;
		}
	} else {
		// The editor's first and last column flags for the page as it will be, so its next save keeps them.
		$items = array();
		$flat  = static function ( array $nodes ) use ( &$flat, &$items ): void {
			foreach ( $nodes as $node ) {
				$items[] = array(
					'tag'   => (string) $node['tag'],
					'width' => is_string( $node['atts']['type'] ?? null ) ? $node['atts']['type'] : '',
				);
				$flat( array_values( (array) ( $node['children'] ?? array() ) ) );
			}
		};
		foreach ( $elements as $i => $element ) {
			if ( $i === $point['at'] ) {
				$flat( $tree );
			}
			$items[] = array(
				'tag'   => (string) $element['tag'],
				'width' => is_string( $element['atts']['type'] ?? null ) ? $element['atts']['type'] : '',
			);
		}
		if ( $point['at'] >= count( $elements ) ) {
			$flat( $tree );
		}
		$layout = array( 'fusion_builder_row', 'fusion_builder_row_inner', 'fusion_builder_column', 'fusion_builder_column_inner' );
		$items  = array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $layout ): bool {
					return in_array( $item['tag'], $layout, true );
				}
			)
		);
		$all    = aafm_avada_column_flags( $items );
		$before = 0;
		foreach ( array_slice( $elements, 0, $point['at'] ) as $element ) {
			$before += in_array( $element['tag'], array( 'fusion_builder_column', 'fusion_builder_column_inner' ), true ) ? 1 : 0;
		}
		$flags    = array_slice( $all, $before );
		$settings = get_option( 'fusion_builder_settings', array() );
		$text     = aafm_avada_serialize_nodes( $tree, $map, $tags, $flags, is_array( $settings ) && 'on' === ( $settings['remove_empty_attributes'] ?? 'off' ) );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
	}
	// A global reference, placed or nested in copied text, shows its item: that item gets the chosen item's checks.
	$references = aafm_builder_references_allowed( $text, $post );
	if ( is_wp_error( $references ) ) {
		return $references;
	}
	$tuples = aafm_builder_walk( $text, $tags );
	if ( null === $tuples || count( $tuples ) !== $stats['count'] ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}

	$new  = substr( $content, 0, $point['offset'] ) . $text . substr( $content, $point['offset'] );
	$walk = aafm_builder_walk( $content, $tags );
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
	$result   = aafm_builder_commit( $post, $content, $new, $tags, $expected, null, 'avada' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$stored = (string) $result['post']->post_content;
	$after  = aafm_builder_elements( $stored, $tags );
	$root   = $after[ $point['at'] ] ?? null;
	$out    = array(
		'post'                => $result['post'],
		'index'               => (int) $point['at'],
		'fingerprint'         => null === $root ? '' : aafm_builder_fingerprint( $stored, $root ),
		'page_fingerprint'    => $result['page_fingerprint'],
		'inserted'            => (int) $stats['count'],
		'restore_revision_id' => $result['restore_revision_id'],
		'reversible'          => $result['reversible'],
	);
	if ( isset( $result['reversible_reason'] ) ) {
		$out['reversible_reason'] = $result['reversible_reason'];
	}
	return $out;
}

/**
 * The input schema parts every Avada structural write shares: post_id and page_fingerprint, the element's own address,
 * and the anchor.
 *
 * @param bool $address Whether the element's own index and expect are included.
 * @param bool $anchor  Whether anchor_index, anchor_expect and placement are included.
 * @return array<string,array<string,mixed>>
 */
function aafm_avada_structural_schema( bool $address, bool $anchor ): array {
	$out = array(
		'post_id'          => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => __( 'ID of the page, post, layout section, library item, form or off canvas panel.', 'agent-abilities-for-mcp' ),
		),
		'page_fingerprint' => array(
			'type'        => 'string',
			'pattern'     => '^[0-9a-f]{16}$',
			'description' => __( 'The page fingerprint from the last avada-get-page-content read or the last write\'s response. A page that changed since is refused.', 'agent-abilities-for-mcp' ),
		),
	);
	if ( $address ) {
		$out += aafm_avada_address_schema();
	}
	if ( $anchor ) {
		$address_fields       = aafm_avada_address_schema();
		$out['anchor_index']  = array( 'description' => __( 'Index of the element the new place is named against, from the same outline.', 'agent-abilities-for-mcp' ) ) + $address_fields['index'];
		$out['anchor_expect'] = array( 'description' => __( 'The tag and fingerprint the outline returned for anchor_index.', 'agent-abilities-for-mcp' ) ) + $address_fields['expect'];
		$out['placement']     = array(
			'type'        => 'string',
			'enum'        => array( 'before', 'after', 'inside_start', 'inside_end' ),
			'description' => __( 'Where, relative to the anchor element: before or after it, or as its first or last child.', 'agent-abilities-for-mcp' ),
		);
	}
	return $out;
}

/**
 * Args for aafm/avada-insert-element.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_insert_element(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-insert-element' ),
		'description'         => aafm_ability_description( 'aafm/avada-insert-element' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_avada_structural_schema( false, true ) + array(
				'element' => array(
					'type'                 => 'object',
					'properties'           => array(
						'tag'      => array( 'type' => 'string' ),
						'atts'     => array(
							'type'        => 'object',
							'description' => __( 'Setting name to value, each in the input form of its kind from avada-list-elements. A column needs type, its width: a fraction such as 1_2 or a percentage.', 'agent-abilities-for-mcp' ),
						),
						'text'     => array(
							'type'        => 'string',
							'description' => __( 'Inner content for an element with no children, such as a text block, a title or a button\'s text.', 'agent-abilities-for-mcp' ),
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
		'output_schema'       => aafm_avada_write_output_schema(
			array(
				'index'       => array( 'type' => 'integer' ),
				'fingerprint' => array( 'type' => 'string' ),
				'inserted'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of elements added, children included.', 'agent-abilities-for-mcp' ),
				),
			)
		),
		'execute_callback'    => 'aafm_exec_avada_insert_element',
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
 * Execute aafm/avada-insert-element (design AV-5): a thin wrapper around aafm_avada_insert_tree() with typed input, so
 * every value goes through the one encoder and every text through the body-text choke point. An insert that starts a
 * page on an empty post then sets the fusion_builder_status marker Avada's own save writes (A9, Ruling Q7), only after
 * the content is saved; a marker that does not take is reported with the saved content and the revision that restores
 * it, never as plain success.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_insert_element( array $input ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( ! aafm_builder_ownership_allows( 'avada', aafm_post_has_foreign_builder_ownership( $id ), $post, true ) ) {
		return aafm_avada_not_owned_error();
	}
	$check = aafm_builder_page_check( (string) $post->post_content, (string) ( $input['page_fingerprint'] ?? '' ) );
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	$starts = '' === trim( (string) $post->post_content ) && ! in_array( $post->post_type, aafm_builder_own_post_types( 'avada' ), true );
	$out    = aafm_avada_insert_tree(
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
	if ( $starts ) {
		$marker = aafm_meta_set( 'post', $id, 'fusion_builder_status', 'active', $post->post_type );
		$status = is_wp_error( $marker ) ? AAFM_WRITE_REFUSED : (string) ( $marker['status'] ?? AAFM_WRITE_REFUSED );
		if ( ! in_array( $status, array( AAFM_WRITE_WRITTEN, AAFM_WRITE_UNCHANGED ), true ) ) {
			$writer = aafm_meta_write_error( $status, 'write', 'post', $id, 'fusion_builder_status' );
			return new WP_Error(
				'aafm_avada_marker_unconfirmed',
				__( 'The element was saved, but the marker that makes Avada open this page in its builder could not be confirmed, so the page may open in the default editor. Set the page to use Avada\'s builder there, or restore the page with restore_revision_id.', 'agent-abilities-for-mcp' ),
				array(
					'status'              => 500,
					'write_status'        => $status,
					'post_id'             => $id,
					'page_fingerprint'    => $out['page_fingerprint'],
					'restore_revision_id' => $out['restore_revision_id'],
				) + $writer->get_error_data()
			);
		}
	}
	$out['post'] = aafm_redact_post( $out['post'] );
	return $out;
}

/**
 * Whether taking the element at $index out of its parent would leave a container with no row, or a row or nested row
 * with no column.
 *
 * @param list<array<string,mixed>> $elements Outline.
 * @param int                       $index    Element.
 * @return bool
 */
function aafm_avada_empties_a_layout_parent( array $elements, int $index ): bool {
	$parent = $elements[ $index ]['parent'];
	if ( null === $parent || ! in_array( $elements[ $parent ]['tag'], array( 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_row_inner' ), true ) ) {
		return false;
	}
	return 1 === count( array_keys( array_column( $elements, 'parent' ), $parent, true ) );
}

/**
 * The refusal for a change that would leave a container, row or nested row empty.
 *
 * @return WP_Error
 */
function aafm_avada_layout_needs_child_error(): WP_Error {
	return new WP_Error(
		'aafm_avada_layout_needs_child',
		__( 'A container must keep at least one row (a flex container that holds its columns directly, at least one column), and a row at least one column, so this change would break the layout. Move or remove the whole container or row instead. Nothing was changed.', 'agent-abilities-for-mcp' ),
		array( 'status' => 400 )
	);
}

/**
 * Args for aafm/avada-move-element.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_move_element(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-move-element' ),
		'description'         => aafm_ability_description( 'aafm/avada-move-element' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_avada_structural_schema( true, true ),
			'required'             => array( 'post_id', 'page_fingerprint', 'index', 'expect', 'anchor_index', 'anchor_expect', 'placement' ),
			'additionalProperties' => false,
		),
		'output_schema'       => aafm_avada_write_output_schema(
			array(
				'index'       => array( 'type' => 'integer' ),
				'fingerprint' => array( 'type' => 'string' ),
				'moved'       => array(
					'type'        => 'boolean',
					'description' => __( 'False when the element was already in that place and nothing was saved.', 'agent-abilities-for-mcp' ),
				),
			)
		),
		'execute_callback'    => 'aafm_exec_avada_move_element',
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
 * Execute aafm/avada-move-element (design AV-6). Cuts the element's stored bytes, children included, and splices the
 * same bytes in at the anchor; nothing is re-serialized or encoded. The moved tag must be in Avada's map; a move to a
 * new parent follows the nesting rules, a reorder under the same parent needs none. A move to the place it already has
 * saves nothing and reports moved false (Ruling R3-PR2-8).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_move_element( array $input ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( ! aafm_builder_ownership_allows( 'avada', aafm_post_has_foreign_builder_ownership( $id ), $post ) ) {
		return aafm_avada_not_owned_error();
	}
	$content = (string) $post->post_content;
	$check   = aafm_builder_page_check( $content, (string) ( $input['page_fingerprint'] ?? '' ) );
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	$tags     = aafm_fusion_shortcode_tags();
	$elements = aafm_builder_elements( $content, $tags );
	if ( null === $elements ) {
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
	foreach ( array( $element['parent'], $point['parent'] ) as $parent ) {
		$global = aafm_avada_global_ancestor( $elements, null === $parent ? null : (int) $parent );
		if ( null !== $global ) {
			return aafm_avada_inside_global_error( $global );
		}
	}
	$map = aafm_avada_map();
	if ( is_wp_error( $map ) ) {
		return $map;
	}
	$tag = (string) $element['tag'];
	if ( ! isset( $map[ $tag ] ) ) {
		return aafm_builder_error( 'aafm_builder_unknown_element' );
	}
	if ( $point['parent'] !== $element['parent'] ) {
		$parent_tag = null === $point['parent'] ? '' : (string) $elements[ $point['parent'] ]['tag'];
		$nesting    = aafm_avada_nesting_allows( $parent_tag, $tag, $map, null !== $point['parent'] && aafm_avada_is_flex_container( $elements[ $point['parent'] ] ) );
		if ( is_wp_error( $nesting ) ) {
			return $nesting;
		}
		if ( aafm_avada_empties_a_layout_parent( $elements, $index ) ) {
			return aafm_avada_layout_needs_child_error();
		}
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
	$result   = aafm_builder_commit( $post, $content, $new, $tags, $expected, null, 'avada' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$stored    = (string) $result['post']->post_content;
	$new_index = $point['at'] > $index ? $point['at'] - $size : $point['at'];
	$after     = aafm_builder_elements( $stored, $tags );
	$moved     = $after[ $new_index ] ?? null;
	return array(
		'post'                => aafm_redact_post( $result['post'] ),
		'index'               => $new_index,
		'fingerprint'         => null === $moved ? '' : aafm_builder_fingerprint( $stored, $moved ),
		'page_fingerprint'    => $result['page_fingerprint'],
		'moved'               => ! $result['unchanged'],
		'restore_revision_id' => $result['restore_revision_id'],
		'reversible'          => $result['reversible'],
	) + aafm_avada_reversible_reason( $result );
}

/**
 * Args for aafm/avada-remove-element.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_remove_element(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-remove-element' ),
		'description'         => aafm_ability_description( 'aafm/avada-remove-element' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => aafm_avada_structural_schema( true, false ),
			'required'             => array( 'post_id', 'page_fingerprint', 'index', 'expect' ),
			'additionalProperties' => false,
		),
		'output_schema'       => aafm_avada_write_output_schema(
			array(
				'removed' => array(
					'type'        => 'integer',
					'description' => __( 'Number of elements removed, children included.', 'agent-abilities-for-mcp' ),
				),
			)
		),
		'execute_callback'    => 'aafm_exec_avada_remove_element',
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
 * Execute aafm/avada-remove-element (design AV-7). Removes the element's stored bytes, children included. Refused
 * before anything is written when no revision can bring it back (a type without revisions, such as a form or an off
 * canvas panel (A8), revisions off, or a site that keeps only one), for the page's last top level container or its
 * only top level element, inside an element that shows a global library item, and when a container or row would be
 * left empty.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_remove_element( array $input ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}
	if ( ! aafm_builder_ownership_allows( 'avada', aafm_post_has_foreign_builder_ownership( $id ), $post ) ) {
		return aafm_avada_not_owned_error();
	}
	$content = (string) $post->post_content;
	$check   = aafm_builder_page_check( $content, (string) ( $input['page_fingerprint'] ?? '' ) );
	if ( is_wp_error( $check ) ) {
		return $check;
	}
	if ( ! post_type_supports( $post->post_type, 'revisions' ) || ! wp_revisions_enabled( $post ) ) {
		return aafm_builder_error( 'aafm_builder_remove_irreversible' );
	}
	// Core prunes the oldest revisions inside the save; with one kept, the restore point would be deleted at once.
	if ( 1 === wp_revisions_to_keep( $post ) ) {
		return new WP_Error(
			'aafm_builder_remove_irreversible',
			__( 'This site keeps only 1 revision per post (WP_POST_REVISIONS or the wp_revisions_to_keep filter), so WordPress would delete the revision that brings the removed element back during the same save. Raise the limit to 2 or more, or remove the element in Avada\'s builder. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	$tags     = aafm_fusion_shortcode_tags();
	$elements = aafm_builder_elements( $content, $tags );
	if ( null === $elements ) {
		return aafm_builder_error( 'aafm_builder_unparseable' );
	}
	$element = aafm_builder_resolve( $content, $tags, (int) ( $input['index'] ?? -1 ), is_array( $input['expect'] ?? null ) ? $input['expect'] : array() );
	if ( is_wp_error( $element ) ) {
		return $element;
	}
	$index  = (int) $element['index'];
	$global = aafm_avada_global_ancestor( $elements, null === $element['parent'] ? null : (int) $element['parent'] );
	if ( null !== $global ) {
		return aafm_avada_inside_global_error( $global );
	}
	if ( null === $element['parent'] ) {
		$roots = array();
		foreach ( $elements as $other ) {
			if ( null === $other['parent'] && (int) $other['index'] !== $index ) {
				$roots[] = (string) $other['tag'];
			}
		}
		// The page keeps reading as an Avada page only while a top level container, or for a library item its own top
		// level element, is left.
		if ( array() === $roots || ( 'fusion_builder_container' === $element['tag'] && ! in_array( 'fusion_builder_container', $roots, true ) ) ) {
			return aafm_builder_error( 'aafm_builder_remove_last_root' );
		}
	}
	if ( aafm_avada_empties_a_layout_parent( $elements, $index ) ) {
		return aafm_avada_layout_needs_child_error();
	}

	$new  = substr( $content, 0, (int) $element['start'] ) . substr( $content, (int) $element['end'] );
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
	$result   = aafm_builder_commit( $post, $content, $new, $tags, $expected, null, 'avada' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return array(
		'post'                => aafm_redact_post( $result['post'] ),
		'removed'             => aafm_builder_subtree_size( $elements, $index ),
		'page_fingerprint'    => $result['page_fingerprint'],
		'restore_revision_id' => $result['restore_revision_id'],
		'reversible'          => $result['reversible'],
	) + aafm_avada_reversible_reason( $result );
}
