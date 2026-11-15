<?php
/**
 * WPBakery Page Builder integration (plugin folder js_composer, formerly Visual Composer): a read
 * ability returning a page's raw shortcode markup and an element outline, and a guarded write
 * ability that replaces text between shortcode tags without changing any element or attribute.
 *
 * Registers ONLY while WPBakery Page Builder is active (aafm_integration_active('wpbakery')).
 * WPBakery keeps its whole layout as shortcodes in post_content, so both abilities gate on the
 * ordinary edit access check every other content ability uses (aafm_perm_avada_post_object(),
 * which is builder neutral). WPBakery's own Role Manager is not consulted.
 *
 * Vendor facts were read from WPBakery Page Builder 9.1 (plugin folder js_composer); see design
 * doc 322 section 4.2. Add-ons map their own tags under any name, so the tag list each call
 * guards is scanned from the content itself (aafm_wpbakery_shortcode_tags()), not taken from a
 * prefix or from WPBakery's element map.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_wpbakery_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_wpbakery_full_definitions' );

/**
 * Contribute WPBakery ability definitions to the registry, only while WPBakery is active.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wpbakery_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'wpbakery' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_wpbakery_registry_definitions() );
}

/**
 * Contribute WPBakery ability definitions to the host-independent full registry (used by the
 * Integrations tab and the manifest, whether or not WPBakery is active here).
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_wpbakery_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_wpbakery_registry_definitions() );
}

/**
 * The WPBakery ability rows, shared by the guarded and full registries.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_wpbakery_registry_definitions(): array {
	return array(
		'aafm/wpbakery-get-page-content' => array(
			'label'        => __( 'Get WPBakery page content', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads the raw post content of a WPBakery page, unchanged. Shortcodes come back exactly as stored, never rendered or stripped, and attribute values keep the form WPBakery stores them in, including its escaped brackets and quotes and base64 encoded values such as raw HTML, so search for text as it is stored. Also returns is_wpbakery_owned, true when WPBakery owns the page or the post is a Grid Builder template. With include_structure it also returns an outline of the shortcode elements (tag, nesting depth, attributes as stored and decoded the way WPBakery reads them, the inner text of an element with no child element, decoded for raw HTML and raw JS, and a fingerprint per element) plus a page fingerprint, to find the text block that holds the text before calling wpbakery-replace-text. The outline stops at 2,000 elements. With include_page_settings it also returns the page custom CSS and the editor status, and the page custom JS only to users who may post unfiltered HTML. Requires edit access to the post; WPBakery\'s Role Manager settings are not applied. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_get_page_content',
		),
		'aafm/wpbakery-replace-text'     => array(
			'label'        => __( 'Replace text in a WPBakery page', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Replaces literal text within a WPBakery page while requiring the shortcode structure and every attribute value to stay the same before and after (quoting style and whitespace inside a tag are not part of this guarantee). Text that WPBakery keeps in an element\'s settings, such as a heading or a button label, cannot be changed here and is refused, as is any replacement that would add, remove or alter an element. Only a user who may post unfiltered HTML can put square brackets, percent-encoded brackets or data-* attributes in body text, and even then text that would add an element is refused. If saving the page as this user would remove or change elements, or change other content on the page, the call is refused and nothing is saved. Not undoable from this plugin\'s change history; WordPress keeps a revision when revisions are on. Requires edit access to the post; WPBakery\'s Role Manager settings are not applied. Only while WPBakery Page Builder is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'wpbakery',
			'args_builder' => 'aafm_args_wpbakery_replace_text',
		),
	);
}

/**
 * Args for aafm/wpbakery-get-page-content.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_get_page_content(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-get-page-content' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-get-page-content' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post or page to read. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
				),
				'include_structure'     => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'When true, also returns structure: the shortcode elements in document order with their nesting depth, attributes, decoded attributes, inner text and fingerprint, plus structure_status and page_fingerprint. Use it to find the element that holds the text you want to change before calling wpbakery-replace-text.', 'agent-abilities-for-mcp' ),
				),
				'include_page_settings' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'When true, also returns page_settings: the page custom CSS, the editor status and, for a user who may post unfiltered HTML, the page custom JS for the header and the footer.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'           => array( 'type' => 'integer' ),
				'content'           => array( 'type' => 'string' ),
				'is_wpbakery_owned' => array( 'type' => 'boolean' ),
				'structure_status'  => array(
					'type'        => 'string',
					'enum'        => array( 'ok', 'unparseable', 'truncated' ),
					'description' => __( 'Only with include_structure. ok: the outline is complete. unparseable: the shortcodes could not be read safely (wpbakery-replace-text refuses this page too) and no structure is returned. truncated: only the first 2,000 elements are listed.', 'agent-abilities-for-mcp' ),
				),
				'structure'         => array(
					'type'        => 'array',
					'description' => __( 'Only with include_structure, when structure_status is ok or truncated. Attribute values are shown as WPBakery stores them.', 'agent-abilities-for-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'index'        => array( 'type' => 'integer' ),
							'depth'        => array( 'type' => 'integer' ),
							'tag'          => array( 'type' => 'string' ),
							'self_closing' => array( 'type' => 'boolean' ),
							'has_closer'   => array( 'type' => 'boolean' ),
							'atts'         => array( 'type' => 'object' ),
							'fingerprint'  => array(
								'type'        => 'string',
								'description' => __( 'First 16 hex characters of the SHA-256 of the element\'s stored source, children included. A later element write names the element by index, tag and this fingerprint.', 'agent-abilities-for-mcp' ),
							),
							'atts_decoded' => array(
								'type'        => 'object',
								'description' => __( 'The attributes as WPBakery reads them: escaped quotes and brackets restored, a link split into url, title, target and rel, a param group as rows, and a #E-8_ value decoded.', 'agent-abilities-for-mcp' ),
							),
							'text'         => array(
								'type'        => 'string',
								'description' => __( 'Only on an element with no child element: its inner content as stored. For raw HTML and raw JS elements, the decoded code; absent when the stored value does not decode.', 'agent-abilities-for-mcp' ),
							),
						),
					),
				),
				'page_fingerprint'  => array(
					'type'        => 'string',
					'description' => __( 'Only with include_structure: first 16 hex characters of the SHA-256 of the whole stored content.', 'agent-abilities-for-mcp' ),
				),
				'page_settings'     => array(
					'type'        => 'object',
					'description' => __( 'Only with include_page_settings. custom_js_header and custom_js_footer are present only for a user who may post unfiltered HTML.', 'agent-abilities-for-mcp' ),
					'properties'  => array(
						'custom_css'       => array( 'type' => 'string' ),
						'editor_status'    => array( 'type' => 'string' ),
						'custom_js_header' => array( 'type' => 'string' ),
						'custom_js_footer' => array( 'type' => 'string' ),
					),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_wpbakery_get_page_content',
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
 * Execute aafm/wpbakery-get-page-content: raw post_content, unrendered, an ownership flag
 * decided from the content as the guard decides it, on request the outline the writes guard (with
 * fingerprints, decoded attributes and leaf text, design WB-1) and the page fingerprint, and on
 * request the page settings metas (WB-9 read side), read inside aafm_with_checked_reads() so a
 * failed meta read is an error. Page custom JS goes only to a user with unfiltered_html (Ruling Q14).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_wpbakery_get_page_content( array $input ) {
	$id   = absint( $input['post_id'] ?? 0 );
	$post = aafm_exact_object( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// Read first, before the ownership check loads this post's meta outside the checked scope.
	$settings = null;
	if ( ! empty( $input['include_page_settings'] ) ) {
		$settings = aafm_with_checked_reads(
			static function () use ( $id ): array {
				$keys = array(
					'custom_css'    => '_wpb_post_custom_css',
					'editor_status' => '_wpb_vc_js_status',
				);
				if ( current_user_can( 'unfiltered_html' ) ) {
					$keys['custom_js_header'] = '_wpb_post_custom_js_header';
					$keys['custom_js_footer'] = '_wpb_post_custom_js_footer';
				}
				$settings = array();
				foreach ( $keys as $field => $key ) {
					$value              = aafm_meta_get( 'post', $id, $key, true );
					$settings[ $field ] = is_scalar( $value ) ? (string) $value : '';
				}
				return $settings;
			},
			aafm_generic_error()
		);
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
	}

	$content = (string) $post->post_content;
	$out     = array(
		'post_id'           => $id,
		'content'           => $content,
		'is_wpbakery_owned' => aafm_builder_ownership_allows( 'wpbakery', aafm_post_has_foreign_builder_ownership( $id, true ), $post ),
	);
	if ( ! empty( $input['include_structure'] ) ) {
		$tags                    = aafm_wpbakery_shortcode_tags( $content );
		$out                     = array_merge(
			$out,
			null === $tags ? array( 'structure_status' => 'unparseable' ) : aafm_wpbakery_structure( $content, $tags )
		);
		$out['page_fingerprint'] = aafm_builder_page_fingerprint( $content );
	}
	if ( null !== $settings ) {
		$out['page_settings'] = $settings;
	}
	return $out;
}

/**
 * The include_structure outline: aafm_avada_structure()'s items with each one's fingerprint, decoded
 * attributes and, on an element with no child element, its text. The offsets come from
 * aafm_builder_elements() with the same tag list; an outline that does not line up with it is
 * reported unparseable rather than with fingerprints of the wrong element.
 *
 * @param string        $content Content.
 * @param array<string> $tags    Tags scanned from the content.
 * @return array<string,mixed>
 */
function aafm_wpbakery_structure( string $content, array $tags ): array {
	$out = aafm_avada_structure( $content, $tags );
	if ( ! isset( $out['structure'] ) ) {
		return $out;
	}
	$elements = aafm_builder_elements( $content, $tags );
	if ( null === $elements ) {
		return array( 'structure_status' => 'unparseable' );
	}
	$parents = array_flip( array_filter( array_column( $elements, 'parent' ), 'is_int' ) );
	foreach ( $out['structure'] as $i => $item ) {
		$element = $elements[ $item['index'] ] ?? null;
		if ( null === $element || $element['tag'] !== $item['tag'] ) {
			return array( 'structure_status' => 'unparseable' );
		}
		$item['fingerprint']  = aafm_builder_fingerprint( $content, $element );
		$item['atts_decoded'] = (object) aafm_wpbakery_decode_atts( $element['tag'], $element['atts'] );
		if ( ! isset( $parents[ $element['index'] ] ) ) {
			$inner = substr( $content, $element['open_end'], $element['inner_end'] - $element['open_end'] );
			$text  = in_array( $element['tag'], array( 'vc_raw_html', 'vc_raw_js' ), true ) ? aafm_wpbakery_decode_raw( $inner ) : $inner;
			if ( null !== $text ) {
				$item['text'] = $text;
			}
		}
		$out['structure'][ $i ] = $item;
	}
	return $out;
}

/**
 * Attribute values as WPBakery 9.1 reads them: W2's escaped quote and brackets restored on every value,
 * as prepareAtts() does; a link setting split into url, title, target and rel as vc_build_link() does
 * (W3); a URL-encoded JSON list decoded to rows as vc_param_group_parse_atts() does (W6); a #E-8_ value
 * decoded as vc_value_from_safe() does (W17). Whether a value is a link comes from WPBakery's element
 * map (param type vc_link or link) when the map knows the setting, and from a strict shape rule only
 * when it does not. The S9a encoder is the inverse.
 *
 * @param string              $tag  Shortcode tag.
 * @param array<string,mixed> $atts Stored attributes.
 * @return array<string,mixed>
 */
function aafm_wpbakery_decode_atts( string $tag, array $atts ): array {
	$param_type = aafm_builder_vendor( 'wpbakery_param_type' );
	$decoded    = array();
	foreach ( $atts as $name => $value ) {
		if ( ! is_string( $value ) ) {
			$decoded[ $name ] = $value;
			continue;
		}
		$type             = null === $param_type ? null : $param_type( $tag, (string) $name );
		$decoded[ $name ] = aafm_wpbakery_decode_value( $value, is_string( $type ) ? $type : null );
	}
	return $decoded;
}

/**
 * One attribute value, decoded (see aafm_wpbakery_decode_atts()).
 *
 * @param string      $value Stored value.
 * @param string|null $type  The setting's param type from the element map, or null when the map does not know it.
 * @return string|array<mixed>
 */
function aafm_wpbakery_decode_value( string $value, ?string $type = null ) {
	$escapes = array( '`{`', '`}`', '``' );
	$plain   = array( '[', ']', '"' );
	$value   = str_replace( $escapes, $plain, $value );
	if ( 0 === strpos( $value, '#E-8_' ) ) {
		$raw = base64_decode( substr( $value, 5 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading WPBakery's own #E-8_ form back (W17), never executed.
		return false === $raw ? $value : str_replace( $escapes, $plain, rawurldecode( $raw ) );
	}
	if ( null === $type ? aafm_wpbakery_looks_like_link( $value ) : in_array( $type, array( 'vc_link', 'link' ), true ) ) {
		return aafm_wpbakery_parse_link( $value );
	}
	if ( 0 === stripos( $value, '%5B' ) ) {
		$rows = json_decode( urldecode( $value ), true );
		if ( is_array( $rows ) && array_values( $rows ) === $rows && array() === array_filter( $rows, static fn( $row ): bool => ! is_array( $row ) ) ) {
			return $rows;
		}
	}
	return $value;
}

/**
 * A link value read as vc_build_link() reads it (W3, vc_parse_multi_attribute()): the four parts default
 * to empty, pairs split on the pipe, each pair split on its first colon, the value URL-decoded and trimmed.
 * Only those four keys are kept, the shape the S9a encoder writes back.
 *
 * @param string $value Stored value, W2 already reversed.
 * @return array<string,string>
 */
function aafm_wpbakery_parse_link( string $value ): array {
	$link = array(
		'url'    => '',
		'title'  => '',
		'target' => '',
		'rel'    => '',
	);
	foreach ( explode( '|', $value ) as $pair ) {
		$parts = explode( ':', $pair, 2 );
		if ( isset( $parts[1] ) && array_key_exists( $parts[0], $link ) ) {
			$link[ $parts[0] ] = trim( rawurldecode( $parts[1] ) );
		}
	}
	return $link;
}

/**
 * The fallback when the element map does not know a setting: a link only when every pair names one of
 * WPBakery's four link keys and every part is in the URL-encoded form WPBakery stores (W3; the editor's
 * encodeURIComponent() leaves ! ' ( ) * as they are), so plain
 * text such as "url:https://example.com" or "title: our services" stays text. Empty segments, as in the
 * legacy url:%23||| form, are skipped the way vc_parse_multi_attribute() skips them.
 *
 * @param string $value Stored value, W2 already reversed.
 * @return bool
 */
function aafm_wpbakery_looks_like_link( string $value ): bool {
	return 1 === preg_match( '/^(url|title|target|rel):[A-Za-z0-9._~!*\'()%-]*(\|((url|title|target|rel):[A-Za-z0-9._~!*\'()%-]*)?)*$/', $value );
}

/**
 * The code a raw HTML or raw JS element holds, as WPBakery's template decodes it (W7): tags stripped,
 * base64, then URL decoding.
 *
 * @param string $inner Stored inner content.
 * @return string|null Null when the stored value is not base64.
 */
function aafm_wpbakery_decode_raw( string $inner ): ?string {
	$raw = base64_decode( wp_strip_all_tags( $inner ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading WPBakery's own raw element form back (W7), never executed.
	return false === $raw ? null : rawurldecode( $raw );
}

/**
 * The UTF-8 bytes of one Unicode code point, in plain PHP so no mbstring is needed (mb_chr() is polyfilled only by
 * WordPress 7.1). Null for 0, a negative value, a surrogate (U+D800 to U+DFFF) or anything above U+10FFFF.
 *
 * @param int $code Code point.
 * @return string|null
 */
function aafm_utf8_chr( int $code ): ?string {
	if ( $code <= 0 || $code > 0x10FFFF || ( $code >= 0xD800 && $code <= 0xDFFF ) ) {
		return null;
	}
	if ( $code < 0x80 ) {
		return chr( $code );
	}
	if ( $code < 0x800 ) {
		return chr( 0xC0 | ( $code >> 6 ) ) . chr( 0x80 | ( $code & 0x3F ) );
	}
	if ( $code < 0x10000 ) {
		return chr( 0xE0 | ( $code >> 12 ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
	}
	return chr( 0xF0 | ( $code >> 18 ) ) . chr( 0x80 | ( ( $code >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $code >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $code & 0x3F ) );
}

/**
 * Text in the form WPBakery's backend editor saves it: every entity decoded except &amp;, &lt; and &gt;. The editor
 * reads the page through TinyMCE, which decodes every entity in text and encodes only & < and > again (smoke probes,
 * S9a fix rounds 1 and 2), so an entity that spells a bracket, a slash or a letter of a shortcode name becomes the
 * real character on its next save. Decoding it first lets every check see what the editor will produce. An entity
 * that decodes to & < or > is written as &amp;, &lt; or &gt;, and an unknown entity is left as it is. With
 * $keep_quotes, a quote entity stays one entity (&quot; or &apos;), for HTML whose attribute values may hold one.
 *
 * @param string $text        Text.
 * @param bool   $keep_quotes Whether quote entities stay encoded.
 * @return string
 */
function aafm_wpbakery_decode_entities( string $text, bool $keep_quotes = false ): string {
	return (string) preg_replace_callback(
		'/&(#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i',
		static function ( array $m ) use ( $keep_quotes ): string {
			if ( in_array( strtolower( $m[1] ), array( 'amp', 'lt', 'gt' ), true ) ) {
				return $m[0];
			}
			$char = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $char === $m[0] && '#' === $m[1][0] ) {
				// A numeric reference HTML5 leaves alone (a control such as &#13;) is still decoded by code point, as
				// the editor does.
				$code = 'x' === strtolower( $m[1][1] ?? '' ) ? (int) hexdec( substr( $m[1], 2 ) ) : (int) substr( $m[1], 1 );
				$char = aafm_utf8_chr( $code ) ?? $m[0];
			}
			$keep = array(
				'&' => '&amp;',
				'<' => '&lt;',
				'>' => '&gt;',
			);
			if ( $keep_quotes ) {
				$keep['"'] = '&quot;';
				$keep["'"] = '&apos;';
			}
			return $char === $m[0] ? $m[0] : strtr( $char, $keep );
		},
		$text
	);
}

/**
 * Body text for a WPBakery page: text stored between shortcode tags rather than in an escaped attribute (an
 * element's inner content, a replace-text replacement). The one choke point every such write goes through.
 * Entities are decoded to the form WPBakery's editor saves (quote entities kept, so an attribute holding one keeps
 * its text), then, for a user who may not post unfiltered HTML, the text may hold no square bracket at all: the
 * editor rewrites body text on its next save in ways a check cannot all predict, and with no bracket no shortcode
 * can come of it. The same user may not send a percent-encoded bracket (%5B, %5D) or any data-* attribute either:
 * WordPress's TinyMCE plugins replace an element carrying data-wp-media, data-wp-preserve, data-wpview-text or
 * data-wpview-marker with the URL-decoded attribute value on the editor's save, so the whole class is refused rather
 * than stripped. For a user who may, the structure and closer checks that follow still apply. Then wp_kses_post().
 *
 * @param string $text Text from the caller.
 * @return string|WP_Error
 */
function aafm_wpbakery_body_text( string $text ) {
	$decoded = aafm_wpbakery_decode_entities( $text, true );
	$clean   = wp_kses_post( $decoded );
	if ( ! current_user_can( 'unfiltered_html' ) ) {
		if ( false !== strpbrk( $decoded, '[]' ) ) {
			return new WP_Error(
				'aafm_wpbakery_brackets_need_unfiltered_html',
				__( 'Square brackets in this text need a user who may post unfiltered HTML, because WPBakery can turn them into an element the next time the page is saved in its editor. Remove the brackets, or ask such a user to make the edit. Nothing was changed.', 'agent-abilities-for-mcp' ),
				array( 'status' => 400 )
			);
		}
		// The stored form is checked too: kses can join a bracket's parts by removing a tag between them.
		if ( 1 === preg_match( '/%5[bd]/i', $decoded . "\n" . $clean ) ) {
			return new WP_Error(
				'aafm_wpbakery_encoded_brackets_need_unfiltered_html',
				__( 'A percent-encoded square bracket in this text needs a user who may post unfiltered HTML, because WordPress\'s editor can decode it into an element the next time the page is saved in WPBakery. Remove it, or ask such a user to make the edit. Nothing was changed.', 'agent-abilities-for-mcp' ),
				array( 'status' => 400 )
			);
		}
		// Both the text as sent and the form kses would store are parsed, so neither can carry one past the check.
		foreach ( array( $decoded, $clean ) as $html ) {
			$tags = new WP_HTML_Tag_Processor( $html );
			while ( $tags->next_tag() ) {
				if ( array() !== (array) $tags->get_attribute_names_with_prefix( 'data-' ) ) {
					return new WP_Error(
						'aafm_wpbakery_data_attributes_need_unfiltered_html',
						__( 'A data-* attribute in this text needs a user who may post unfiltered HTML, because WordPress\'s editor can turn one into page text the next time the page is saved in WPBakery. Remove the attribute, or ask such a user to make the edit. Nothing was changed.', 'agent-abilities-for-mcp' ),
						array( 'status' => 400 )
					);
				}
			}
			// A parser that stopped on an unfinished tag has not read every attribute, so refuse what it could not see.
			if ( $tags->paused_at_incomplete_token() ) {
				return new WP_Error(
					'aafm_wpbakery_incomplete_html_needs_unfiltered_html',
					__( 'This text ends inside an unfinished HTML tag, which needs a user who may post unfiltered HTML because the tag cannot be checked. Finish or remove the tag, or ask such a user to make the edit. Nothing was changed.', 'agent-abilities-for-mcp' ),
					array( 'status' => 400 )
				);
			}
		}
	}
	return $clean;
}

/**
 * Whether inserted text holds the closing tag of a shortcode on the page. A closer inside an element's text ends
 * that element early without adding one, so the outline check alone does not see it.
 *
 * @param string        $text Inserted text, entities already decoded.
 * @param array<string> $tags Shortcode names on the page.
 * @return bool
 */
function aafm_wpbakery_text_closes_a_tag( string $text, array $tags ): bool {
	foreach ( $tags as $tag ) {
		if ( false !== strpos( $text, '[/' . $tag . ']' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Every distinct shortcode name that appears in the given strings, merged.
 *
 * The scan accepts every name get_shortcode_regex() could match: opening tags, closing tags
 * ([/tag]) and the escaped [[tag]] form. A name is the run of characters add_shortcode() allows
 * (anything but <>&/[]= whitespace and control characters); the leading word part of that run is
 * added too, because get_shortcode_regex() ends a name at any character outside [\w-], so
 * [acme.box] also renders as the shortcode acme. A bracketed word in body text, such as [sic],
 * becomes a name as well; that can only make a later structure check refuse, never pass.
 *
 * @param string ...$contents Strings to scan.
 * @return array<int,string>|null Distinct names, or null above 300 names (treated as unparseable).
 */
function aafm_wpbakery_shortcode_tags( string ...$contents ): ?array {
	$names = array();
	foreach ( $contents as $content ) {
		if ( false === strpos( $content, '[' ) ) {
			continue;
		}
		if ( false === preg_match_all( '/\[\/?([^<>&\/\[\]\x00-\x20=]+)/', $content, $matches ) ) {
			return null;
		}
		foreach ( $matches[1] as $name ) {
			$names[ $name ] = true;
			if ( 1 === preg_match( '/^[\w-]+/', $name, $word ) ) {
				$names[ $word[0] ] = true;
			}
		}
		if ( count( $names ) > 300 ) {
			return null;
		}
	}
	return array_map( 'strval', array_keys( $names ) );
}

/**
 * Args for aafm/wpbakery-replace-text.
 *
 * @return array<string,mixed>
 */
function aafm_args_wpbakery_replace_text(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/wpbakery-replace-text' ),
		'description'         => aafm_ability_description( 'aafm/wpbakery-replace-text' ),
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
		'execute_callback'    => 'aafm_exec_wpbakery_replace_text',
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
 * Execute aafm/wpbakery-replace-text (design doc 322 section 4.5, steps 1 to 11 in order).
 *
 * Only text between shortcode tags can change: every element, attribute value and closing tag
 * must be the same before and after, and the tag list is scanned from the old content, the new
 * content and the save replay, so a replacement that introduces any shortcode is refused too.
 * Two checks run the save pipeline core applies for the calling user (content_save_pre, which is
 * where kses and WPBakery 9.1's own element removal run): the stored page must survive that save
 * unchanged (step 7), and the new content must keep its structure through it (step 8).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array{post:array<string,mixed>,replacements:int}|WP_Error
 */
function aafm_exec_wpbakery_replace_text( array $input ) {
	$id   = absint( $input['post_id'] );
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// The write form answers unknown for two builders or a failed read, so only a page WPBakery
	// alone owns, or a Grid Builder template the guard cannot see (vc_grid_item), gets past here.
	if ( ! aafm_builder_ownership_allows( 'wpbakery', aafm_post_has_foreign_builder_ownership( $id ), $post ) ) {
		return new WP_Error(
			'aafm_not_wpbakery_owned',
			__( 'This post is not a WPBakery page. Use replace-in-post for an ordinary post, and edit a page built with another builder in that builder.', 'agent-abilities-for-mcp' ),
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

	// Body text through the one choke point: entities decoded to the editor's form, brackets refused for a user
	// without unfiltered_html, then kses; a shortcode spelled with entities reaches the structure check below.
	$inserted = aafm_wpbakery_body_text( $replace );
	if ( is_wp_error( $inserted ) ) {
		return $inserted;
	}
	$new = str_replace( $search, $inserted, $content );

	$tags = aafm_wpbakery_shortcode_tags( $content, $new );
	if ( null === $tags ) {
		return new WP_Error(
			'aafm_wpbakery_unparseable',
			__( 'This page uses more distinct shortcode names than can be checked safely, so its text cannot be replaced here. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}

	if ( ! aafm_fusion_shortcode_structure_preserved( $content, $new, $tags ) || aafm_wpbakery_text_closes_a_tag( $inserted, $tags ) ) {
		return new WP_Error(
			'aafm_replace_inside_shortcode',
			__( 'That replacement would change a WPBakery element rather than the text between elements. WPBakery keeps heading text, button labels and other element settings inside the element itself, so change that text in WPBakery. Choose a search term that appears only in plain text, such as a text block. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}

	if ( aafm_post_field_canonical_replay( 'post_content', $content, $id ) !== $content ) {
		return new WP_Error(
			'aafm_wpbakery_save_would_change_page',
			__( 'Saving this page as the current user would change content outside the requested text, for example by removing embedded HTML or WPBakery elements this user is not allowed to keep. Ask a user with fuller rights to make the edit, or make it in WPBakery. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}

	$replayed = aafm_post_field_canonical_replay( 'post_content', $new, $id );
	$all_tags = aafm_wpbakery_shortcode_tags( $content, $new, $replayed );
	if ( null === $all_tags || ! aafm_fusion_shortcode_structure_preserved( $content, $replayed, $all_tags ) ) {
		return new WP_Error(
			'aafm_wpbakery_save_would_change_layout',
			__( 'Saving the new text would remove or change WPBakery elements on this page, so the replacement was not saved. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
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

	$fresh = aafm_exact_object( 'post', $id );
	if ( ! $fresh instanceof WP_Post || ! aafm_post_field_confirm_logged( $id, 'post_content', $new, $content ) ) {
		return new WP_Error(
			'aafm_wpbakery_write_unconfirmed',
			__( 'The replacement could not be confirmed as saved.', 'agent-abilities-for-mcp' )
		);
	}

	return array(
		'post'         => aafm_redact_post( $fresh ),
		'replacements' => $replacements,
	);
}

/**
 * Rebuild a page's WPBakery generated CSS the way WPBakery's own save does (W9): the default then the
 * custom kind, through the vendor seam. Runs after a structural or Design Options write and after a
 * revision restore (design 325 section 4.5).
 *
 * @param int  $id    Post id.
 * @param bool $probe When true, only report whether the rebuild is available; nothing runs.
 * @return bool False when the entry is missing or the rebuild throws.
 */
function aafm_wpbakery_rebuild_css( int $id, bool $probe = false ): bool {
	$rebuild = aafm_builder_vendor( 'wpbakery_css_rebuild' );
	if ( null === $rebuild || $probe ) {
		return null !== $rebuild;
	}
	try {
		$rebuild( $id, 'default' );
		$rebuild( $id, 'custom' );
	} catch ( Throwable $e ) {
		return false;
	}
	return true;
}
