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
 * The Avada ability rows, shared by the guarded and full registries.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_avada_registry_definitions(): array {
	return array(
		'aafm/avada-get-page-content'   => array(
			'label'        => __( 'Get Avada page content', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads the raw post content of an Avada/Fusion Builder page, unchanged. Fusion Builder shortcodes are returned exactly as stored, never rendered or stripped. Also returns is_avada_owned, true when Avada owns the content or the post is one of Avada\'s own types, and affects when an edit to this post reaches other pages (a layout section or a global library element). With include_structure it also returns an outline of the Fusion Builder elements (tag, nesting depth, attributes as stored and as Avada reads them, the inner text of an element with no child element, the library item id of a global element, and a fingerprint per element) plus a page fingerprint, to find the element that holds the text before calling avada-replace-text. The outline stops at 2,000 elements. With include_page_options it also returns the page CSS and the stored page options from a fixed list of layout, title bar, sidebar, slider, header, footer, off canvas, form appearance, steps and confirmation, SEO and template settings; form notification, submission and privacy settings and code fields are never returned. Page options are read only while the Avada theme is active, on an Avada page, one of Avada\'s own types, or a page, post, portfolio item, Avada Slider slide or Elastic Slider slide that no other builder owns. Requires edit access to the post; layout sections are open only to users who can manage site options. Avada\'s Role Manager is not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_get_page_content',
		),
		'aafm/avada-replace-text'       => array(
			'label'        => __( 'Replace text in an Avada page', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Replaces literal text within an Avada/Fusion Builder page while requiring the Fusion shortcode structure and every attribute value to stay the same before and after (quoting style and whitespace inside a tag are not part of this guarantee). A replacement that would touch a shortcode tag, its self-closing form, or an attribute is refused rather than risk breaking the layout. If saving the page as this user would remove or change an element, or change other content on it (an embed the user may not keep, for example), the call is refused and nothing is saved. Also works on Avada\'s own library items, forms, off canvas content and layout sections; layout sections are open only to users who can manage site options. A successful call returns affects when the edit reaches other pages, for a layout section or a global library element. Not undoable from this plugin\'s change history; when the site keeps revisions and the revision saved before the change survives the save, restore_revision_id names it and restore-revision brings the page back, and otherwise reversible_reason says why there is no restore point. Requires edit access to the post; Avada\'s Role Manager is not applied. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_replace_text',
		),
		'aafm/avada-list-layouts'       => array(
			'label'        => __( 'List Avada layouts', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists Avada Layouts in the order Avada checks them, with the Global Layout last. Avada checks every layout and uses the last layout whose conditions match; the Global Layout applies only when none match. Each layout has its sections (header, page title bar, content, footer) with each section\'s id, title, status and whether it exists, and its conditions exactly as Avada stores them. Conditions are not evaluated, so this does not say which layout a given page gets. A section that is not published is not used. A layout whose stored data Avada itself cannot read is marked unparseable. global_stored is false when no Global Layout was ever saved, and more than 200 layouts are cut to the first 200. Requires the manage_options capability, even where Avada\'s Role Manager opens Layouts to other roles. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_list_layouts',
		),
		'aafm/avada-get-global-options' => array(
			'label'        => __( 'Get Avada Global Options', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Reads a fixed list of Avada Global Options exactly as stored: site width, layout, the primary, palette and link colors, body and heading typography, header position and layout, logo, page title bar, footer widgets, copyright and footer text, responsive and maintenance mode. Nothing outside that list is ever returned, so API keys, passwords, custom CSS and the head and body code fields stay out; footer_text comes back as the stored HTML. A requested key in not_set has no stored value, so Avada\'s built-in default applies, which this tool does not report. option_name is the option that was read, which differs per language on a multilingual site. Requires the manage_options capability, even where Avada\'s Role Manager opens Global Options to other roles. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_get_global_options',
		),
		'aafm/avada-list-library'       => array(
			'label'        => __( 'List the Avada library', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists Avada Library items: saved elements, columns, sections and post cards, and full-page templates. Returns id, title, status, kind (the library category, template for a template, or uncategorized), is_global and modified date, plus content, the raw Fusion Builder markup, only with include_content; a global item is listed without its content unless the user can edit private posts. A global item is shared, so editing it changes every page that shows it, and its text is edited in Avada\'s Library, not on the page (avada-get-page-content with include_structure gives the global_id of such an element). Response includes total (the query-wide count of matching items; each page omits items you lack edit access to, so total can exceed the rows returned). Requires the edit_posts capability. Only while Avada is active.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'avada',
			'args_builder' => 'aafm_args_avada_list_library',
		),
	);
}

/**
 * Shared per-object gate for the Avada and WPBakery page tools and the revision tools (design 325
 * section 4.3). A post of an ordinary type needs ordinary edit access, exactly like
 * aafm/replace-in-post's aafm_perm_replace_in_post(): page builder content is not a distinct
 * capability family. A post of a builder's own type (aafm_builder_own_post_types(), not public, so
 * never on the owner's exposed list) needs a registered type with map_meta_cap and edit_post on that
 * post, the check aafm_exec_avada_list_library() makes; an Avada layout section also needs
 * manage_options, because a header or footer section changes every page (Ruling Q3), and a global
 * library item needs edit_private_posts, Avada's own default for global elements (Ruling R-S8b-4).
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_avada_post_object( array $input ): bool {
	$id   = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post = $id ? aafm_exact_object_chain( 'post', $id ) : null;
	if ( ! $post instanceof WP_Post ) {
		return false;
	}
	$own = array_merge( aafm_builder_own_post_types( 'avada' ), aafm_builder_own_post_types( 'wpbakery' ) );
	if ( ! in_array( $post->post_type, $own, true ) ) {
		return aafm_can_edit_post_object( $post );
	}
	if ( 'vc_grid_item' === $post->post_type && ! post_type_exists( 'vc_grid_item' ) && aafm_integration_active( 'wpbakery' ) ) {
		// WPBakery registers its Grid Builder type only in wp-admin; its own registration runs here (Ruling R-S8a-4).
		$register = aafm_builder_vendor( 'wpbakery_grid_post_type' );
		if ( null !== $register ) {
			$register();
		}
	}
	if ( ! aafm_type_caps( $post->post_type )['mapped']
		|| ! aafm_user_can_checked( 'edit_post', $id )
		|| ( 'fusion_tb_section' === $post->post_type && ! current_user_can( 'manage_options' ) ) ) {
		return false;
	}
	// An unreadable marker counts as global (Ruling R-S8b-4).
	if ( in_array( $post->post_type, array( 'fusion_element', 'fusion_template' ), true ) ) {
		$locked = aafm_with_checked_reads(
			static fn(): array => array( 'locked' => aafm_avada_global_item_locked( $post ) ),
			aafm_generic_error()
		);
		return ! is_wp_error( $locked ) && false === $locked['locked'];
	}
	return true;
}

/**
 * Whether the current user is kept out of a global library item: it changes every page that shows it, and Avada
 * refuses its edit screen to a user without edit_private_posts
 * (AWB_Role_Manager::check_global_element_and_backend_builder_access()). Only that fixed default is mirrored; the
 * Role Manager's per-role settings are still not consulted (Rulings R-S8b-4, R-S8b-5). Reads the marker with
 * aafm_meta_get(), so the caller holds the checked scope and refuses when it fails.
 *
 * @param WP_Post $post Post.
 * @return bool
 */
function aafm_avada_global_item_locked( WP_Post $post ): bool {
	return in_array( $post->post_type, array( 'fusion_element', 'fusion_template' ), true )
		&& ! current_user_can( 'edit_private_posts' )
		&& 'yes' === aafm_meta_get( 'post', $post->ID, '_fusion_is_global', true );
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
				'post_id'              => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post/page to read. The current user must have edit access to it.', 'agent-abilities-for-mcp' ),
				),
				'include_structure'    => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'When true, also returns structure: the Fusion Builder elements in document order with their nesting depth, attributes, decoded attributes, inner text and fingerprint, plus structure_status and page_fingerprint. Use it to find the element that holds the text you want to change before calling avada-replace-text.', 'agent-abilities-for-mcp' ),
				),
				'include_page_options' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'When true, also returns custom_css (the page CSS) and page_options (the stored Avada page options, limited to a fixed list). Needs the Avada theme active, and a post Avada owns, one of Avada\'s own types, or a page, post, portfolio item, Avada Slider slide or Elastic Slider slide that no other builder owns.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'post_id'          => array( 'type' => 'integer' ),
				'content'          => array( 'type' => 'string' ),
				'is_avada_owned'   => array( 'type' => 'boolean' ),
				'affects'          => array(
					'type'        => 'string',
					'description' => __( 'Present only when an edit here reaches more than this post: a layout section, or a global library element. Tell the owner before changing it.', 'agent-abilities-for-mcp' ),
				),
				'structure_status' => array(
					'type'        => 'string',
					'enum'        => array( 'ok', 'unparseable', 'truncated' ),
					'description' => __( 'Only with include_structure. ok: the outline is complete. unparseable: the shortcodes could not be read safely (avada-replace-text refuses this page too) and no structure is returned. truncated: only the first 2,000 elements are listed.', 'agent-abilities-for-mcp' ),
				),
				'structure'        => array(
					'type'        => 'array',
					'description' => __( 'Only with include_structure, when structure_status is ok or truncated. A global_id means the element shows a library item: its text lives in that item, not on this page.', 'agent-abilities-for-mcp' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'index'        => array( 'type' => 'integer' ),
							'depth'        => array( 'type' => 'integer' ),
							'tag'          => array( 'type' => 'string' ),
							'self_closing' => array( 'type' => 'boolean' ),
							'has_closer'   => array( 'type' => 'boolean' ),
							'atts'         => array( 'type' => 'object' ),
							'global_id'    => array( 'type' => 'integer' ),
							'fingerprint'  => array(
								'type'        => 'string',
								'description' => __( 'First 16 hex characters of the SHA-256 of the element\'s stored source, children included. A later element write names the element by index, tag and this fingerprint.', 'agent-abilities-for-mcp' ),
							),
							'atts_decoded' => array(
								'type'        => 'object',
								'description' => __( 'The attributes as Avada reads them: the entities its settings dialog writes turned back into characters, and a value Avada stores base64 encoded decoded.', 'agent-abilities-for-mcp' ),
							),
							'text'         => array(
								'type'        => 'string',
								'description' => __( 'Only on an element with no child element: its inner content as stored, decoded for a code block that Avada stores base64 encoded.', 'agent-abilities-for-mcp' ),
							),
						),
					),
				),
				'page_fingerprint' => array(
					'type'        => 'string',
					'description' => __( 'Only with include_structure: first 16 hex characters of the SHA-256 of the whole stored content.', 'agent-abilities-for-mcp' ),
				),
				'custom_css'       => array(
					'type'        => 'string',
					'description' => __( 'Only with include_page_options: the page CSS as stored.', 'agent-abilities-for-mcp' ),
				),
				'page_options'     => array(
					'type'        => 'object',
					'description' => __( 'Only with include_page_options: the stored page options whose ids are on Avada\'s fixed list of layout, title bar, sidebar, slider, header, footer, off canvas, form appearance, steps and confirmation, SEO and template settings. Form notification, submission and privacy settings and code fields are never returned. An option not stored is absent and Avada\'s default applies.', 'agent-abilities-for-mcp' ),
				),
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
	$post = aafm_exact_object( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}

	$with_options = ! empty( $input['include_page_options'] );
	if ( $with_options && ! aafm_avada_theme_active() ) {
		return new WP_Error(
			'aafm_avada_theme_inactive',
			__( 'Page options can be read only while the Avada theme is active.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	// Read first, before the ownership check loads this post's meta outside the checked scope. _fusion is
	// read directly, never through Avada's getter, which can write while it reads (A12).
	$metas = aafm_with_checked_reads(
		static function () use ( $post, $id, $with_options ): array {
			$metas   = array();
			$affects = aafm_avada_affects( $post );
			if ( null !== $affects ) {
				$metas['affects'] = $affects;
			}
			if ( $with_options ) {
				$css                   = aafm_meta_get( 'post', $id, '_fusion_builder_custom_css', true );
				$fusion                = aafm_meta_get( 'post', $id, '_fusion', true );
				$metas['custom_css']   = is_scalar( $css ) ? (string) $css : '';
				$metas['page_options'] = (object) array_intersect_key( is_array( $fusion ) ? $fusion : array(), aafm_avada_page_option_keys() );
			}
			return $metas;
		},
		aafm_generic_error()
	);
	if ( is_wp_error( $metas ) ) {
		return $metas;
	}

	$guard = aafm_post_has_foreign_builder_ownership( $id, true );
	if ( $with_options && ! aafm_avada_page_options_post_allowed( $post, $guard ) ) {
		return new WP_Error(
			'aafm_not_avada_owned',
			__( 'This post is not owned by Avada/Fusion Builder.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}

	$out = array(
		'post_id'        => $id,
		'content'        => (string) $post->post_content,
		'is_avada_owned' => aafm_builder_ownership_allows( 'avada', $guard, $post ),
	);
	if ( ! empty( $input['include_structure'] ) ) {
		$out                     = array_merge( $out, aafm_avada_structure( (string) $post->post_content ) );
		$out['page_fingerprint'] = aafm_builder_page_fingerprint( (string) $post->post_content );
	}
	return array_merge( $out, $metas );
}

/**
 * The element outline for include_structure, built from aafm_fusion_shortcode_walk() itself so it
 * sees exactly what avada-replace-text guards. The walker's tuples are left as they are (the
 * structure check compares them for equality); index and global_id are added here.
 *
 * @param string             $content Post content.
 * @param array<string>|null $tags    Shortcode tags to walk; null keeps the Fusion list. With a
 *                                    list passed, no global_id is added (it is a Fusion concept).
 * @return array<string,mixed> structure_status, and structure when the walk parsed.
 */
function aafm_avada_structure( string $content, ?array $tags = null ): array {
	$walk = aafm_fusion_shortcode_walk( $content, 0, $tags );
	if ( null === $walk ) {
		return array( 'structure_status' => 'unparseable' );
	}
	$max   = 2000;
	$items = array();
	foreach ( array_slice( $walk, 0, $max ) as $index => $element ) {
		$item      = array(
			'index'        => $index,
			'depth'        => $element['depth'],
			'tag'          => $element['tag'],
			'self_closing' => $element['self_closing'],
			'has_closer'   => $element['has_closer'],
			'atts'         => (object) $element['atts'],
		);
		$global_id = null === $tags ? aafm_avada_global_id( $element['tag'], $element['atts'] ) : null;
		if ( null !== $global_id ) {
			$item['global_id'] = $global_id;
		}
		$items[] = $item;
	}
	$out = array(
		'structure_status' => count( $walk ) > $max ? 'truncated' : 'ok',
		'structure'        => $items,
	);
	return null === $tags ? aafm_avada_outline_keys( $content, $out ) : $out;
}

/**
 * The Avada page read's outline keys (design AV-9), added only to the Fusion outline so the WPBakery read
 * that shares aafm_avada_structure() keeps its own: each item's fingerprint and its attributes decoded as
 * Avada reads them, and on an element with no child element its text. The offsets come from
 * aafm_builder_elements() with the walker's own tag list; an outline that does not line up with it item
 * for item is reported unparseable rather than with fingerprints of the wrong element.
 *
 * @param string              $content Content.
 * @param array<string,mixed> $out     aafm_avada_structure()'s result.
 * @return array<string,mixed>
 */
function aafm_avada_outline_keys( string $content, array $out ): array {
	$elements = aafm_builder_elements( $content, aafm_fusion_shortcode_tags() );
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
		$item['atts_decoded'] = (object) aafm_avada_decode_atts( $element['tag'], $element['atts'] );
		if ( ! isset( $parents[ $element['index'] ] ) ) {
			$inner        = substr( $content, $element['open_end'], $element['inner_end'] - $element['open_end'] );
			$item['text'] = in_array( $element['tag'], array( 'fusion_code', 'fusion_syntax_highlighter' ), true ) ? aafm_avada_decode_if_needed( $inner ) : $inner;
		}
		$out['structure'][ $i ] = $item;
	}
	return $out;
}

/**
 * Attribute values as Avada 7.16.1 reads them. A setting Avada's renderer base64-decodes is decoded by
 * Avada's own rule, fusion_decode_if_needed(): only when the value round-trips exactly (A3). Every other
 * value has the six entities Avada's settings dialog writes with _.escape() reversed in one pass (A2); a
 * raw text field is escaped by the dialog before it is encoded, so it gets both. Which settings are
 * encoded is fixed by where Avada's renderers decode (aafm_avada_encoded_settings()), never by the
 * value's shape. S10's encoder is the inverse.
 *
 * @param string              $tag  Shortcode tag.
 * @param array<string,mixed> $atts Stored attributes.
 * @return array<string,mixed>
 */
function aafm_avada_decode_atts( string $tag, array $atts ): array {
	$encoded  = aafm_avada_encoded_settings();
	$entities = array(
		'&amp;'  => '&',
		'&lt;'   => '<',
		'&gt;'   => '>',
		'&quot;' => '"',
		'&#x27;' => "'",
		'&#x60;' => '`',
	);
	$decoded  = array();
	foreach ( $atts as $name => $value ) {
		if ( ! is_string( $value ) ) {
			$decoded[ $name ] = $value;
			continue;
		}
		$kind             = $encoded[ $tag ][ $name ] ?? $encoded['*'][ $name ] ?? null;
		$decoded[ $name ] = null === $kind ? strtr( $value, $entities ) : aafm_avada_decode_if_needed( $value );
		if ( 'raw_text' === $kind ) {
			$decoded[ $name ] = strtr( $decoded[ $name ], $entities );
		}
	}
	return $decoded;
}

/**
 * The settings Avada's renderers base64-decode (fusion-builder 3.16.1: every fusion_decode_if_needed() and
 * base64_decode() call under shortcodes/ and inc/, mapped in scratchpad/1113/s8b-decode-sites.md; Avada Core and
 * the theme decode no element setting of their own), by tag, with '*' for the settings any element can
 * carry. raw_text marks a text input the dialog escapes before it encodes; the rest are raw_textarea,
 * repeater or JSON values stored as encoded.
 *
 * @return array<string,array<string,string>>
 */
function aafm_avada_encoded_settings(): array {
	return array(
		'*'                            => array(
			'dynamic_params'    => 'json',
			'html_attributes'   => 'repeater',
			'render_logics'     => 'json',
			'conditional_logic' => 'json',
			'logics'            => 'json',
			'motion_effects'    => 'json',
		),
		'fusion_image_hotspot_point'   => array( 'long_text' => 'raw_textarea' ),
		'fusion_tagline_box'           => array(
			'title'       => 'raw_textarea',
			'description' => 'raw_textarea',
		),
		'fusion_openstreetmap_marker'  => array( 'tooltip_content' => 'raw_textarea' ),
		'fusion_sharing'               => array( 'description' => 'raw_textarea' ),
		'fusion_map'                   => array( 'infobox_content' => 'raw_textarea' ),
		'fusion_popover'               => array( 'content' => 'raw_textarea' ),
		'fusion_video'                 => array( 'track_tags' => 'repeater' ),
		'fusion_post_cards'            => array( 'alternate_post_cards' => 'repeater' ),
		// The archives component hands its own settings to the post cards renderer (post-card-archives.php:200).
		'fusion_tb_post_card_archives' => array( 'alternate_post_cards' => 'repeater' ),
		'fusion_form_notice'           => array(
			'success' => 'raw_textarea',
			'error'   => 'raw_textarea',
		),
		'fusion_form_consent'          => array( 'description' => 'raw_textarea' ),
		'fusion_form_select'           => array( 'options' => 'json' ),
		'fusion_form_checkbox'         => array( 'options' => 'json' ),
		'fusion_form_radio'            => array( 'options' => 'json' ),
		'fusion_form_email'            => array( 'pattern' => 'raw_text' ),
		'fusion_form_phone_number'     => array( 'pattern' => 'raw_text' ),
		'fusion_form_password'         => array( 'pattern' => 'raw_text' ),
		'fusion_form_text'             => array( 'custom_pattern' => 'raw_text' ),
	);
}

/**
 * Avada's own fusion_decode_if_needed() rule (inc/lib/inc/functions.php:1365): decode with
 * base64_decode() and keep the result only when base64_encode() of it gives the value back.
 *
 * @param string $value Stored value.
 * @return string
 */
function aafm_avada_decode_if_needed( string $value ): string {
	$raw = base64_decode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading Avada's own base64 form back (A3), never executed.
	return base64_encode( $raw ) === $value ? $raw : $value; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the round-trip test Avada itself applies.
}

/**
 * What else an edit to this post changes (design AV-2): every page that uses a layout section, or every
 * page that shows a global library element (_fusion_is_global yes, read with aafm_meta_get(); the caller
 * holds the checked scope). Null for anything else.
 *
 * @param WP_Post $post Post.
 * @return string|null
 */
function aafm_avada_affects( WP_Post $post ): ?string {
	if ( 'fusion_tb_section' === $post->post_type ) {
		return __( 'every page that uses this section', 'agent-abilities-for-mcp' );
	}
	if ( in_array( $post->post_type, array( 'fusion_element', 'fusion_template' ), true ) && 'yes' === aafm_meta_get( 'post', $post->ID, '_fusion_is_global', true ) ) {
		return __( 'every page that shows this global element', 'agent-abilities-for-mcp' );
	}
	return null;
}

/**
 * Whether Avada Core is active: it defines FUSION_CORE_VERSION as it loads (fusion-core.php). The same pattern as
 * aafm_templatera_active(), for the Integrations card's add-on note.
 *
 * @return bool
 */
function aafm_avada_core_active(): bool {
	/**
	 * Filters whether Avada Core is reported active.
	 *
	 * @param bool $active Detected active state.
	 */
	return (bool) apply_filters( 'aafm_avada_core_active', defined( 'FUSION_CORE_VERSION' ) );
}

/**
 * Whether the Avada theme is active: its option name accessor is reachable through the seam.
 *
 * @return bool
 */
function aafm_avada_theme_active(): bool {
	return null !== aafm_builder_vendor( 'avada_option_name' );
}

/**
 * Whether a post's page options may be read (and, in S12, written) (Ruling B7). Page options are not
 * builder content, so the post may be one Avada owns, one of Avada's own types, or a page, post,
 * portfolio item, Avada Slider slide or Elastic Slider slide no other builder owns. A post another
 * builder owns is refused. The two slide types joined after every slide id round-tripped on a real
 * slide (Ruling R3-PR2-13, scratchpad/1113/s14-vendor/ev/slide-roundtrip.log).
 *
 * @param WP_Post      $post         Post.
 * @param string|false $guard_answer aafm_post_has_foreign_builder_ownership() as the caller read it.
 * @return bool
 */
function aafm_avada_page_options_post_allowed( WP_Post $post, $guard_answer ): bool {
	if ( 'avada' === $guard_answer ) {
		return true;
	}
	return false === $guard_answer
		&& in_array( $post->post_type, array_merge( aafm_builder_own_post_types( 'avada' ), array( 'page', 'post', 'avada_portfolio', 'slide', 'themefusion_elastic' ) ), true );
}

/**
 * The page options an agent may read (and S12's write may set): every option id of Avada 7.16.1's page
 * options tabs for pages, posts, headers, footers, the page title bar, sidebars, sliders, content,
 * portfolio items, off canvas panels, form appearance, steps and confirmation, SEO, templates and
 * library templates, plus the slide and Elastic Slider options, mapped to the type the theme gives it
 * (design AV-9, Ruling R3-P1; redirect_url is the link kind). Never a form notification, submission,
 * privacy, general or Studio setting, a code field, a notice or a button. Built by hand from
 * themes/Avada/includes/metaboxes/ (scratchpad/1113/s8b-page-option-ids.md); a fixed list, never a
 * prefix scan. The slide ids are reachable on slide and themefusion_elastic posts, which
 * aafm_avada_page_options_post_allowed() admits (Ruling R3-PR2-13).
 *
 * @return array<string,string> Option id to type.
 */
function aafm_avada_page_option_keys(): array {
	return array(
		'after_inactivity'                          => 'radio-buttonset',
		'after_x_page_views'                        => 'radio-buttonset',
		'after_x_sessions'                          => 'radio-buttonset',
		'align_content'                             => 'radio-buttonset',
		'aspect_ratio'                              => 'text',
		'author_info'                               => 'radio-buttonset',
		'auto_close_after_time'                     => 'text',
		'autoplay_video'                            => 'radio-buttonset',
		'avada_rev_styles'                          => 'radio-buttonset',
		'awb_header_bg_color'                       => 'color-alpha',
		'background_blend_mode'                     => 'select',
		'background_color'                          => 'color-alpha',
		'background_custom_size'                    => 'dimensions',
		'background_image'                          => 'upload',
		'background_position'                       => 'select',
		'background_repeat'                         => 'select',
		'background_size'                           => 'select',
		'bbpress_sidebar_position'                  => 'radio-buttonset',
		'between_steps_size'                        => 'slider',
		'bg_color'                                  => 'color-alpha',
		'bg_full'                                   => 'radio-buttonset',
		'bg_image'                                  => 'media',
		'bg_repeat'                                 => 'select',
		'blog_comments'                             => 'radio-buttonset',
		'blog_page_title_bar'                       => 'select',
		'blog_pn_nav'                               => 'radio-buttonset',
		'blog_show_page_title_bar'                  => 'select',
		'blog_sidebar_position'                     => 'radio-buttonset',
		'blog_width_100'                            => 'radio-buttonset',
		'border_color'                              => 'color-alpha',
		'border_radius'                             => 'dimensions',
		'border_width'                              => 'dimensions',
		'box_shadow'                                => 'radio-buttonset',
		'box_shadow_blur'                           => 'slider',
		'box_shadow_color'                          => 'color-alpha',
		'box_shadow_position'                       => 'dimensions',
		'box_shadow_spread'                         => 'slider',
		'button_1'                                  => 'textarea',
		'button_2'                                  => 'textarea',
		'caption'                                   => 'textarea',
		'caption_1'                                 => 'text',
		'caption_2'                                 => 'text',
		'caption_bg'                                => 'radio-buttonset',
		'caption_bg_color'                          => 'color',
		'caption_color'                             => 'color',
		'caption_font_size'                         => 'text',
		'caption_separator'                         => 'select',
		'caption_size'                              => 'radio-buttonset',
		'close_button'                              => 'radio-buttonset',
		'close_button_color'                        => 'color-alpha',
		'close_button_color_hover'                  => 'color-alpha',
		'close_button_custom_icon'                  => 'iconpicker',
		'close_button_margin'                       => 'dimensions',
		'close_button_position'                     => 'radio-buttonset',
		'close_icon_size'                           => 'slider',
		'close_on_anchor_click'                     => 'radio-buttonset',
		'close_on_esc'                              => 'radio-buttonset',
		'conditions_enabled'                        => 'radio-buttonset',
		'container_hundred_percent_animation'       => 'select',
		'container_hundred_percent_animation_speed' => 'slider',
		'container_hundred_percent_dots_navigation' => 'radio-buttonset',
		'content_alignment'                         => 'radio-buttonset',
		'content_bg_color'                          => 'color-alpha',
		'content_bg_full'                           => 'radio-buttonset',
		'content_bg_image'                          => 'media',
		'content_bg_repeat'                         => 'select',
		'content_layout'                            => 'radio-buttonset',
		'content_wrap'                              => 'radio-buttonset',
		'copy_url'                                  => 'text',
		'copy_url_text'                             => 'text',
		'css_class'                                 => 'text',
		'css_id'                                    => 'text',
		'custom_height'                             => 'text',
		'default_sidebar_pos'                       => 'radio-buttonset',
		'device'                                    => 'multiple_select',
		'display_header'                            => 'radio-buttonset',
		'displayed_menu'                            => 'select',
		'dynamic_content_preview_type'              => 'select',
		'ec_sidebar'                                => 'select',
		'ec_sidebar_2'                              => 'select',
		'ec_sidebar_bg_color'                       => 'color-alpha',
		'ec_sidebar_pos'                            => 'radio-buttonset',
		'elasticslider'                             => 'select',
		'enter_animation'                           => 'select',
		'enter_animation_direction'                 => 'select',
		'enter_animation_speed'                     => 'slider',
		'enter_animation_timing'                    => 'select',
		'events_social_sharing_box'                 => 'radio-buttonset',
		'exit_animation'                            => 'select',
		'exit_animation_direction'                  => 'select',
		'exit_animation_speed'                      => 'slider',
		'exit_animation_timing'                     => 'select',
		'exit_intent'                               => 'radio-buttonset',
		'fallback'                                  => 'media',
		'field_margin'                              => 'dimensions',
		'fimg'                                      => 'dimensions',
		'footer_100_width'                          => 'radio-buttonset',
		'footer_copyright'                          => 'radio-buttonset',
		'footer_widgets'                            => 'radio-buttonset',
		'form_bg_color'                             => 'color-alpha',
		'form_border_color'                         => 'color-alpha',
		'form_border_radius'                        => 'slider',
		'form_border_width'                         => 'dimensions',
		'form_confirmation_type'                    => 'multiple_select',
		'form_focus_border_color'                   => 'color-alpha',
		'form_font_size'                            => 'text',
		'form_input_height'                         => 'text',
		'form_label_color'                          => 'color-alpha',
		'form_placeholder_color'                    => 'color-alpha',
		'form_placeholder_focus_animation'          => 'radio-buttonset',
		'form_placeholder_focus_shrink'             => 'radio-buttonset',
		'form_text_color'                           => 'color-alpha',
		'frequency'                                 => 'select',
		'frequency_xdays'                           => 'text',
		'frequency_xtimes'                          => 'text',
		'fusion_tb_section_width_100'               => 'radio-buttonset',
		'header_100_width'                          => 'radio-buttonset',
		'header_bg_color'                           => 'color-alpha',
		'header_bg_full'                            => 'radio-buttonset',
		'header_bg_image'                           => 'media',
		'header_bg_repeat'                          => 'select',
		'header_breakpoint'                         => 'radio-buttonset',
		'header_custom_breakpoint'                  => 'slider',
		'heading'                                   => 'textarea',
		'heading_bg'                                => 'radio-buttonset',
		'heading_bg_color'                          => 'color',
		'heading_color'                             => 'color',
		'heading_font_size'                         => 'text',
		'heading_separator'                         => 'select',
		'heading_size'                              => 'radio-buttonset',
		'height'                                    => 'select',
		'hide_video_controls'                       => 'radio-buttonset',
		'horizontal_position'                       => 'radio-buttonset',
		'hundredp_padding'                          => 'text',
		'image_rollover_icons'                      => 'select',
		'inactivity_duration'                       => 'text',
		'label_font_size'                           => 'text',
		'label_position'                            => 'radio-buttonset',
		'label_typo'                                => 'typography',
		'layout'                                    => 'radio-buttonset',
		'layout_conditions'                         => 'layout_conditions',
		'link_icon_url'                             => 'text',
		'link_type'                                 => 'radio-buttonset',
		'live_footer'                               => 'radio-buttonset',
		'live_header'                               => 'radio-buttonset',
		'live_ptb'                                  => 'radio-buttonset',
		'loop_video'                                => 'radio-buttonset',
		'main_padding'                              => 'dimensions',
		'margin'                                    => 'dimensions',
		'megamenu_wrapper_max_width'                => 'slider',
		'megamenu_wrapper_width'                    => 'radio-buttonset',
		'meta_description'                          => 'textarea',
		'meta_og_image'                             => 'media',
		'mobile_header_bg_color'                    => 'color-alpha',
		'mp4'                                       => 'upload',
		'mute_video'                                => 'radio-buttonset',
		'number_of_page_views'                      => 'text',
		'number_of_sessions'                        => 'text',
		'number_of_times'                           => 'text',
		'oc_scrollbar'                              => 'radio-buttonset',
		'oc_scrollbar_background'                   => 'color-alpha',
		'oc_scrollbar_handle_color'                 => 'color-alpha',
		'off_canvas_state'                          => 'radio-buttonset',
		'ogv'                                       => 'upload',
		'on_add_to_cart'                            => 'radio-buttonset',
		'on_click'                                  => 'radio-buttonset',
		'on_click_element'                          => 'text',
		'on_page_load'                              => 'radio-buttonset',
		'on_scroll'                                 => 'radio-buttonset',
		'overlay'                                   => 'radio-buttonset',
		'overlay_background_blend_mode'             => 'select',
		'overlay_background_color'                  => 'color-alpha',
		'overlay_background_custom_size'            => 'dimensions',
		'overlay_background_image'                  => 'upload',
		'overlay_background_position'               => 'select',
		'overlay_background_repeat'                 => 'select',
		'overlay_background_size'                   => 'select',
		'overlay_close_on_click'                    => 'radio-buttonset',
		'overlay_page_scrollbar'                    => 'radio-buttonset',
		'overlay_z_index'                           => 'text',
		'padding'                                   => 'dimensions',
		'page_title_100_width'                      => 'radio-buttonset',
		'page_title_alignment'                      => 'radio-buttonset',
		'page_title_bar'                            => 'select',
		'page_title_bar_bs'                         => 'radio-buttonset',
		'page_title_bar_text'                       => 'radio-buttonset',
		'page_title_bg'                             => 'media',
		'page_title_bg_color'                       => 'color-alpha',
		'page_title_bg_full'                        => 'radio-buttonset',
		'page_title_bg_parallax'                    => 'radio-buttonset',
		'page_title_bg_retina'                      => 'media',
		'page_title_border_color'                   => 'color-alpha',
		'page_title_color'                          => 'color-alpha',
		'page_title_custom_subheader'               => 'textarea',
		'page_title_custom_text'                    => 'textarea',
		'page_title_font_size'                      => 'text',
		'page_title_height'                         => 'text',
		'page_title_line_height'                    => 'text',
		'page_title_mobile_height'                  => 'text',
		'page_title_subheader_color'                => 'color-alpha',
		'page_title_subheader_font_size'            => 'text',
		'pages_sidebar'                             => 'select',
		'pages_sidebar_2'                           => 'select',
		'portfolio_author'                          => 'radio-buttonset',
		'portfolio_comments'                        => 'radio-buttonset',
		'portfolio_featured_image_width'            => 'radio-buttonset',
		'portfolio_link_icon_target'                => 'radio-buttonset',
		'portfolio_pn_nav'                          => 'radio-buttonset',
		'portfolio_project_desc_title'              => 'radio-buttonset',
		'portfolio_project_details'                 => 'radio-buttonset',
		'portfolio_related_posts'                   => 'radio-buttonset',
		'portfolio_sidebar'                         => 'select',
		'portfolio_sidebar_2'                       => 'select',
		'portfolio_sidebar_position'                => 'radio-buttonset',
		'portfolio_social_sharing_box'              => 'radio-buttonset',
		'portfolio_width_100'                       => 'radio-buttonset',
		'position'                                  => 'radio-buttonset',
		'post_links_target'                         => 'radio-buttonset',
		'post_meta'                                 => 'radio-buttonset',
		'posts_sidebar'                             => 'select',
		'posts_sidebar_2'                           => 'select',
		'ppbress_sidebar'                           => 'select',
		'ppbress_sidebar_2'                         => 'select',
		'preview_archives'                          => 'select',
		'preview_background_color'                  => 'color-alpha',
		'preview_image'                             => 'upload',
		'preview_term'                              => 'select',
		'preview_width'                             => 'slider',
		'product_width_100'                         => 'radio-buttonset',
		'project_url'                               => 'text',
		'project_url_text'                          => 'text',
		'publish_date_after'                        => 'date_time_picker',
		'publish_date_until'                        => 'date_time_picker',
		'redirect_timeout'                          => 'slider',
		'redirect_url'                              => 'link',
		'related_posts'                             => 'radio-buttonset',
		'required_field_symbol_decoration'          => 'radio-buttonset',
		'responsive_sidebar_order'                  => 'sortable',
		'revslider'                                 => 'select',
		'sb_enter_animation'                        => 'select',
		'sb_enter_animation_speed'                  => 'slider',
		'sb_enter_animation_timing'                 => 'select',
		'sb_exit_animation'                         => 'select',
		'sb_exit_animation_speed'                   => 'slider',
		'sb_exit_animation_timing'                  => 'select',
		'sb_height'                                 => 'text',
		'scroll_direction'                          => 'radio-buttonset',
		'scroll_element'                            => 'text',
		'scroll_position'                           => 'text',
		'scroll_to'                                 => 'radio-buttonset',
		'seo_follow'                                => 'radio-buttonset',
		'seo_index'                                 => 'radio-buttonset',
		'seo_title'                                 => 'text',
		'show_close_button_after_time'              => 'text',
		'show_first_featured_image'                 => 'radio-buttonset',
		'show_wc_shop_loop'                         => 'radio-buttonset',
		'side_header_width'                         => 'slider',
		'sidebar_bg_color'                          => 'color-alpha',
		'sidebar_sticky'                            => 'select',
		'slide_link'                                => 'text',
		'slide_target'                              => 'radio-buttonset',
		'slider'                                    => 'select',
		'slider_position'                           => 'radio-buttonset',
		'slider_type'                               => 'select',
		'slider_visibility'                         => 'checkbox-buttonset',
		'social_sharing_box'                        => 'radio-buttonset',
		'special_effect'                            => 'radio-buttonset',
		'step_icon_bg'                              => 'radio-buttonset',
		'step_icon_bg_color'                        => 'color-alpha',
		'step_icon_bg_color_active'                 => 'color-alpha',
		'step_icon_bg_color_completed'              => 'color-alpha',
		'step_icon_bor_color'                       => 'color-alpha',
		'step_icon_bor_color_active'                => 'color-alpha',
		'step_icon_bor_color_completed'             => 'color-alpha',
		'step_icon_bor_type'                        => 'select',
		'step_icon_bor_width'                       => 'slider',
		'step_icon_border_radius'                   => 'dimensions',
		'step_icon_color'                           => 'color-alpha',
		'step_icon_color_active'                    => 'color-alpha',
		'step_icon_color_completed'                 => 'color-alpha',
		'step_icon_padding'                         => 'slider',
		'step_icon_size'                            => 'slider',
		'step_icon_title_gap'                       => 'text',
		'step_pb_alignment'                         => 'radio-buttonset',
		'step_pb_animated_stripes'                  => 'radio-buttonset',
		'step_pb_border_radius'                     => 'dimensions',
		'step_pb_dimension'                         => 'text',
		'step_pb_filled_border_color'               => 'color-alpha',
		'step_pb_filled_border_size'                => 'slider',
		'step_pb_filled_color'                      => 'color-alpha',
		'step_pb_percentage'                        => 'radio-buttonset',
		'step_pb_striped'                           => 'radio-buttonset',
		'step_pb_typo'                              => 'typography',
		'step_pb_typo_color'                        => 'color-alpha',
		'step_pb_unfilled_color'                    => 'color-alpha',
		'step_sep_margin'                           => 'dimensions',
		'step_type'                                 => 'radio-buttonset',
		'step_typo'                                 => 'typography',
		'steps_bg_color'                            => 'color-alpha',
		'steps_bg_color_active'                     => 'color-alpha',
		'steps_bg_color_completed'                  => 'color-alpha',
		'steps_bor_color'                           => 'color-alpha',
		'steps_bor_color_active'                    => 'color-alpha',
		'steps_bor_color_completed'                 => 'color-alpha',
		'steps_bor_type'                            => 'select',
		'steps_bor_width'                           => 'slider',
		'steps_border_radius'                       => 'dimensions',
		'steps_nav'                                 => 'select',
		'steps_number_icon'                         => 'radio-buttonset',
		'steps_padding'                             => 'dimensions',
		'steps_sep_color'                           => 'color-alpha',
		'steps_sep_color_completed'                 => 'color-alpha',
		'steps_sep_type'                            => 'select',
		'steps_sep_type_completed'                  => 'select',
		'steps_sep_width'                           => 'slider',
		'steps_spacing'                             => 'select',
		'steps_title'                               => 'radio-buttonset',
		'steps_title_color'                         => 'color-alpha',
		'steps_title_color_active'                  => 'color-alpha',
		'steps_title_color_completed'               => 'color-alpha',
		'steps_title_position'                      => 'select',
		'steps_wrapper_margin'                      => 'dimensions',
		'template_sidebar'                          => 'select',
		'template_sidebar_2'                        => 'select',
		'template_sidebar_position'                 => 'radio-buttonset',
		'time_on_page'                              => 'radio-buttonset',
		'time_on_page_duration'                     => 'text',
		'tooltip_background_color'                  => 'color-alpha',
		'tooltip_text_color'                        => 'color-alpha',
		'transition'                                => 'radio-buttonset',
		'type'                                      => 'radio-buttonset',
		'until_consent_interaction'                 => 'radio-buttonset',
		'users'                                     => 'radio-buttonset',
		'users_roles'                               => 'multiple_select',
		'valign_content'                            => 'radio-buttonset',
		'vertical_position'                         => 'radio-buttonset',
		'video'                                     => 'textarea',
		'video_bg_color'                            => 'color',
		'video_display'                             => 'select',
		'video_url'                                 => 'text',
		'vimeo_id'                                  => 'text',
		'webm'                                      => 'upload',
		'when_arriving_from'                        => 'multiple_select',
		'width'                                     => 'text',
		'woo_sidebar'                               => 'select',
		'woo_sidebar_2'                             => 'select',
		'woo_sidebar_position'                      => 'radio-buttonset',
		'wooslider'                                 => 'select',
		'youtube_id'                                => 'text',
	);
}

/**
 * The library item an element shows, the two ways Avada writes it: a [fusion_global id="12"]
 * element, or an element that carries a fusion_global="12" attribute (fusion-builder
 * shortcodes/fusion-global.php, recursively_add_global_elements()).
 *
 * @param string              $tag  Shortcode tag.
 * @param array<string,mixed> $atts Parsed attributes.
 * @return int|null The item's post id, or null when the element is not global.
 */
function aafm_avada_global_id( string $tag, array $atts ): ?int {
	$raw = 'fusion_global' === $tag ? ( $atts['id'] ?? null ) : ( $atts['fusion_global'] ?? null );
	return is_string( $raw ) && 1 === preg_match( '/^[1-9][0-9]*$/', $raw ) ? (int) $raw : null;
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
				'post'                => array( 'type' => 'object' ),
				'replacements'        => array( 'type' => 'integer' ),
				'restore_revision_id' => array(
					'type'        => 'integer',
					'description' => __( 'The revision holding the page before this change; restore-revision brings it back. Absent when there is no restore point.', 'agent-abilities-for-mcp' ),
				),
				'reversible_reason'   => array(
					'type'        => 'string',
					'description' => __( 'Present only when there is no restore point: the site or the content type keeps no revisions, WordPress saved none before the change, or the one saved was deleted during the save by the revision limit or another plugin. Says why there is no way back.', 'agent-abilities-for-mcp' ),
				),
				'affects'             => array( 'type' => 'string' ),
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
 * The Fusion shortcode tags this guard recognizes.
 *
 * A fixed 7-tag list misses every OTHER real Fusion Builder element
 * (fusion_button, fusion_alert, fusion_imageframe, the awb_* family, and dozens more) - an edit
 * inside an unrecognized shortcode is invisible to this guard entirely, since only recognized
 * tags contribute a tuple to the signature at all. Fixed by reading the REAL, complete set of
 * registered shortcodes off WordPress core's own global registry (populated by Fusion Builder's
 * own add_shortcode() calls when the theme/plugin is active) and filtering to the fusion_ and
 * awb_ prefixed families - self-updating against whatever this specific site's Fusion Builder version actually
 * registered, rather than a hand-maintained snapshot. The small hardcoded baseline stays as a
 * fallback merged in: PHPUnit never loads the real plugin, so $shortcode_tags carries none of
 * these names in tests, and a genuinely blank registry (e.g. Fusion Builder not yet fully loaded)
 * must not silently reduce this guard's own test coverage to zero.
 *
 * @return string[]
 */
function aafm_fusion_shortcode_tags(): array {
	global $shortcode_tags;

	$baseline = array(
		'fusion_builder_container',
		'fusion_builder_row',
		'fusion_builder_row_inner',
		'fusion_builder_column',
		'fusion_builder_column_inner',
		'fusion_separator',
		'fusion_text',
	);

	$discovered = array();
	foreach ( array_keys( (array) $shortcode_tags ) as $tag ) {
		if ( 0 === strpos( (string) $tag, 'fusion_' ) || 0 === strpos( (string) $tag, 'awb_' ) ) {
			$discovered[] = (string) $tag;
		}
	}

	return array_values( array_unique( array_merge( $baseline, $discovered ) ) );
}

/**
 * Whether every quote character in a shortcode's raw attribute string that actually opens a
 * quoted value also closes it - tracking which quote character is the ACTIVE delimiter, not
 * just counting '"' and "'" independently.
 *
 * A quote of the OTHER kind encountered while already inside a quoted value (title="Bob's
 * title") is ordinary content, not a second delimiter, and must not count against the balance -
 * see aafm_fusion_shortcode_walk()'s own docblock for why an unclosed delimiter (the literal-']'
 * trap) must still fail closed.
 *
 * @param string $attribute_str Raw attribute string as captured by get_shortcode_regex().
 * @return bool True when no quoted value was left open at the end of the string.
 */
function aafm_fusion_attribute_quotes_balanced( string $attribute_str ): bool {
	$active_delimiter = null;
	$length           = strlen( $attribute_str );
	for ( $i = 0; $i < $length; $i++ ) {
		$char = $attribute_str[ $i ];
		if ( null === $active_delimiter ) {
			if ( '"' === $char || "'" === $char ) {
				$active_delimiter = $char;
			}
		} elseif ( $char === $active_delimiter ) {
			$active_delimiter = null;
		}
	}
	return null === $active_delimiter;
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
 * Both closed here:
 * - A tuple now records whether a real closing tag was actually matched (`has_closer`), not just
 *   the tag/self_closing/atts/depth. Without it, `[fusion_text]Hello[/fusion_text]` and a version
 *   with the closing tag stripped produce IDENTICAL tuples - the "content+closer" span in
 *   get_shortcode_regex() is entirely OPTIONAL, so an absent closer still yields a match, just
 *   with an empty (rather than genuinely absent) inner-content capture, and nothing before this
 *   fix distinguished the two.
 * - An attribute string with an unclosed quoted value (tracked by
 *   aafm_fusion_attribute_quotes_balanced(), which follows which quote character is actually the
 *   ACTIVE delimiter rather than counting `"` and `'` independently - a plain apostrophe inside a
 *   double-quoted value, title="Bob's title", is ordinary content, not a second delimiter) means
 *   WordPress's own regex mis-parsed the match - the classic trap is a literal `]` inside a
 *   quoted attribute value, which truncates the captured attribute span mid-quote (confirmed:
 *   `content="a[1]"` captures only `content="a[1`, an unterminated quote). This function does not
 *   attempt to recover the real boundary; it fails closed (null) instead, per the design's own
 *   fail-closed rule, rather than silently exposing a "safe-looking" span that is not
 *   actually protecting the real boundary WordPress's own parser lost track of.
 *
 * @param string             $content Content to scan at this nesting level.
 * @param int                $depth   Current nesting depth (0 = top level).
 * @param array<string>|null $tags    Shortcode tags to walk; null keeps aafm_fusion_shortcode_tags().
 *                                    An empty list walks nothing (get_shortcode_regex() would fall
 *                                    back to every registered shortcode).
 * @return array<int,array{tag:string,self_closing:bool,has_closer:bool,atts:array<string,mixed>,depth:int}>|null Null means "could not be parsed" - the caller must refuse rather than assume safety.
 */
function aafm_fusion_shortcode_walk( string $content, int $depth, ?array $tags = null ): ?array {
	if ( array() === $tags ) {
		return array();
	}
	$pattern = '/' . get_shortcode_regex( null === $tags ? aafm_fusion_shortcode_tags() : $tags ) . '/';
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

		// A quote that opens but never closes means get_shortcode_regex()'s attribute-span
		// capture ran off the rails (the literal-']'-in-a-quoted-value trap) - refuse rather than
		// trust a span that does not cover what it looks like it covers.
		//
		// Counting each quote character independently (an odd count
		// of '"' OR an odd count of "'") false-positives on a perfectly ordinary attribute like
		// title="Bob's title" - one apostrophe INSIDE a double-quoted value is not a delimiter at
		// all, just a literal character, but the old count-parity check could not tell the two
		// apart. Track which quote character is actually the ACTIVE delimiter instead: a quote of
		// the other kind encountered while already inside a quoted value is ordinary content, not
		// a second delimiter.
		if ( ! aafm_fusion_attribute_quotes_balanced( $attribute_str ) ) {
			return null;
		}

		$atts = shortcode_parse_atts( $attribute_str );
		$atts = is_array( $atts ) ? $atts : array();

		$has_closer = $self_closing || (bool) preg_match( '/\[\/' . preg_quote( $tag, '/' ) . '\]$/', $match[0] );

		$signature[] = array(
			'tag'          => $tag,
			'self_closing' => $self_closing,
			'has_closer'   => $has_closer,
			'atts'         => $atts,
			'depth'        => $depth,
		);

		if ( ! $self_closing && '' !== $inner ) {
			$child = aafm_fusion_shortcode_walk( $inner, $depth + 1, $tags );
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
 * 'atts' is shortcode_parse_atts()'s output, a normalized associative array -
 * it discards the original quoting style and interior whitespace entirely, so two attribute
 * strings differing only in quote character or whitespace (or a duplicate attribute that
 * shadows an earlier one) parse to the identical array and pass this check unchanged. This
 * compares structure and attribute VALUES, not the raw bytes inside a tag - not the
 * byte-identical guarantee the disclosure used to claim.
 *
 * @param string             $before Content before the replacement.
 * @param string             $after  Content after the replacement.
 * @param array<string>|null $tags   Shortcode tags to compare; null keeps the Fusion list.
 * @return bool
 */
function aafm_fusion_shortcode_structure_preserved( string $before, string $after, ?array $tags = null ): bool {
	$original = aafm_fusion_shortcode_walk( $before, 0, $tags );
	$result   = aafm_fusion_shortcode_walk( $after, 0, $tags );

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
	$post = aafm_exact_object_chain( 'post', $id );
	if ( ! $post instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// This ability's whole reason to exist is a FINER write path for
	// Avada-owned content specifically - without this check it could edit an Elementor/Divi/
	// Beaver-Builder-owned post (which carries no Fusion shortcodes at all, so both structural
	// signatures come back empty and equal) even though the generic write path
	// (aafm_exec_replace_in_post()) explicitly refuses those same posts. Require genuine,
	// verified Avada ownership, not merely "no foreign builder marker at all".
	if ( ! aafm_builder_ownership_allows( 'avada', aafm_post_has_foreign_builder_ownership( $id ), $post ) ) {
		return new WP_Error(
			'aafm_not_avada_owned',
			__( 'This post is not owned by Avada/Fusion Builder.', 'agent-abilities-for-mcp' ),
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

	$inserted = wp_kses_post( $replace );
	$new      = str_replace( $search, $inserted, $content );

	if ( ! aafm_fusion_shortcode_structure_preserved( $content, $new ) ) {
		return new WP_Error(
			'aafm_replace_inside_shortcode',
			__( 'That replacement would change the Fusion Builder shortcode structure rather than its text. Choose a search term that appears in plain text between or inside elements, not one that would alter a shortcode tag, its self-closing form, or an attribute. Nothing was changed.', 'agent-abilities-for-mcp' ),
			array( 'status' => 400 )
		);
	}

	// AV-1: steps 3 and 4 of the builder guard chain. A user whose own save would strip something on
	// this page (an iframe in a text block, for a user without unfiltered_html) is refused here,
	// rather than having kses remove it while the confirmation below compares against what core stores.
	$tags   = aafm_fusion_shortcode_tags();
	$replay = aafm_builder_replay_check( 'avada', $id, $content, $new, $tags, array_values( (array) aafm_fusion_shortcode_walk( $content, 0, $tags ) ) );
	if ( is_wp_error( $replay ) ) {
		return $replay;
	}

	// Demo #4: keep the page before the change as a revision, the way the element tools do, so it can be undone.
	$supports = post_type_supports( $post->post_type, 'revisions' );
	$enabled  = $supports && wp_revisions_enabled( $post );
	$restore  = $enabled ? aafm_builder_restore_point( $id, $content ) : null;
	$reason   = null;
	if ( ! $supports ) {
		$reason = aafm_builder_type_no_revisions_reason();
	} elseif ( ! $enabled ) {
		$reason = aafm_builder_revisions_off_reason();
	} elseif ( null === $restore ) {
		$reason = aafm_builder_snapshot_failed_reason();
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
	// The site's revision limit can prune it during the save; never report a restore point that is gone.
	if ( null !== $restore && ! aafm_exact_object( 'post', $restore ) instanceof WP_Post ) {
		$restore = null;
		$reason  = aafm_builder_pruned_reason( $post );
	}

	$fresh = aafm_exact_object( 'post', $id );
	// $replacements was computed from the ORIGINAL content before
	// wp_update_post() ever ran, and never re-validated against what actually landed in
	// storage - a wp_insert_post_data (or similar) filter revising the content on save would
	// report a count and structure that no longer matched what was stored. Confirm the exact
	// intended content actually landed before reporting success. Compare
	// against the CANONICAL sanitize_post_field() form, not $new itself, so a legitimate
	// normalization is not mistaken for a veto.
	if ( ! $fresh instanceof WP_Post || ! aafm_post_field_confirm_logged( $id, 'post_content', $new, $content ) ) {
		return new WP_Error(
			'aafm_avada_write_unconfirmed',
			__( 'The replacement could not be confirmed as saved.', 'agent-abilities-for-mcp' )
		);
	}

	$affects = aafm_with_checked_reads(
		static fn(): array => array( 'affects' => aafm_avada_affects( $fresh ) ),
		aafm_generic_error()
	);
	if ( is_wp_error( $affects ) ) {
		return $affects;
	}
	return array_filter(
		array(
			'post'                => aafm_redact_post( $fresh ),
			'replacements'        => $replacements,
			'restore_revision_id' => $restore,
			'reversible_reason'   => $reason,
			'affects'             => $affects['affects'],
		),
		static fn( $value ): bool => null !== $value
	);
}

/**
 * Args for aafm/avada-list-layouts.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_list_layouts(): array {
	$section = array(
		'type'       => 'object',
		'properties' => array(
			'id'     => array( 'type' => 'integer' ),
			'exists' => array( 'type' => 'boolean' ),
			'title'  => array( 'type' => 'string' ),
			'status' => array( 'type' => 'string' ),
		),
	);
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-list-layouts' ),
		'description'         => aafm_ability_description( 'aafm/avada-list-layouts' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'include_conditions' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'When false, each layout is returned without its conditions.', 'agent-abilities-for-mcp' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'layouts'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'           => array(
								'type'        => array( 'integer', 'string' ),
								'description' => __( 'The layout post id, or "global" for the Global Layout.', 'agent-abilities-for-mcp' ),
							),
							'title'        => array( 'type' => 'string' ),
							'status'       => array( 'type' => 'string' ),
							'order'        => array(
								'type'        => 'integer',
								'description' => __( 'Position in the order Avada checks layouts, from 0. Avada checks them all and uses the last layout whose conditions match; the Global Layout applies only when none match.', 'agent-abilities-for-mcp' ),
							),
							'parse_status' => array(
								'type'        => 'string',
								'enum'        => array( 'ok', 'unparseable' ),
								'description' => __( 'unparseable: Avada cannot read this layout\'s stored data, so no sections or conditions are returned for it.', 'agent-abilities-for-mcp' ),
							),
							'sections'     => array(
								'type'                 => 'object',
								'description'          => __( 'Keyed by section type (header, page_title_bar, content, footer). Only when parse_status is ok.', 'agent-abilities-for-mcp' ),
								'additionalProperties' => $section,
							),
							'conditions'   => array(
								'type'        => 'array',
								'description' => __( 'The stored conditions, unchanged: mode (include or exclude), type, the value under the key named by type, and parent for a child condition. Only when parse_status is ok and include_conditions is true.', 'agent-abilities-for-mcp' ),
								'items'       => array( 'type' => 'object' ),
							),
						),
					),
				),
				'global_stored' => array( 'type' => 'boolean' ),
				'truncated'     => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_list_layouts',
		'permission_callback' => 'aafm_perm_manage_options',
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
 * Execute aafm/avada-list-layouts. Reads the layout posts, the layout order and the Global Layout
 * option the way Avada Builder's Fusion_Template_Builder does, without calling Avada (its query
 * helper caches), and decodes each one with Avada's own expression, since that decides what renders.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_list_layouts( array $input ) {
	$with_conditions = ! array_key_exists( 'include_conditions', $input ) || ! empty( $input['include_conditions'] );

	// Fusion_Template_Builder::get_registered_layouts_posts(): every status but trash and auto-draft.
	$query = new WP_Query(
		array(
			'post_type'      => 'fusion_tb_layout',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);
	$posts = (array) $query->posts;

	// Same function: ordered ids first, the rest in query order, ordered ids with no post dropped.
	$settings = get_option( 'fusion_builder_settings', array() );
	$order    = is_array( $settings ) && isset( $settings['awb_layout_order'] ) && is_string( $settings['awb_layout_order'] ) ? $settings['awb_layout_order'] : '';
	if ( '' !== $order ) {
		$by_id = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$by_id[ $post->ID ] = $post;
			}
		}
		$posts = array_replace( array_flip( explode( ',', str_replace( 'global,', '', $order ) ) ), $by_id );
	}
	$posts = array_values(
		array_filter(
			$posts,
			static function ( $post ): bool {
				return $post instanceof WP_Post;
			}
		)
	);

	$max     = 200;
	$layouts = array();
	foreach ( array_slice( $posts, 0, $max ) as $position => $post ) {
		// Fusion_Template_Builder::set_overrides() and get_conditions() decode layouts this way.
		$detail = aafm_avada_layout_detail( json_decode( str_replace( "'", "\'", wp_unslash( $post->post_content ) ), true ), $with_conditions );
		if ( null === $detail ) {
			return aafm_generic_error();
		}
		$layouts[] = array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'status' => $post->post_status,
			'order'  => $position,
		) + $detail;
	}

	$global = get_option( 'fusion_tb_layout_default' );
	if ( false !== $global ) {
		// Fusion_Template_Builder::get_default_layout() decodes the Global Layout this way.
		$detail = aafm_avada_layout_detail( is_string( $global ) ? json_decode( wp_unslash( $global ), true ) : null, $with_conditions );
		if ( null === $detail ) {
			return aafm_generic_error();
		}
		$layouts[] = array(
			'id'     => 'global',
			'title'  => __( 'Global Layout', 'agent-abilities-for-mcp' ),
			'status' => 'publish',
			'order'  => count( $layouts ),
		) + $detail;
	}

	return array(
		'layouts'       => $layouts,
		'global_stored' => false !== $global,
		'truncated'     => count( $posts ) > $max,
	);
}

/**
 * Sections and conditions of one decoded layout, or unparseable with neither when the decode did
 * not give an array (never empty ones standing in for a failed read). Null when a section's post
 * did not load and its row is not certainly absent, so "exists: false" is never a guess.
 *
 * @param mixed $data            Decoded layout data.
 * @param bool  $with_conditions Whether to include the conditions.
 * @return array<string,mixed>|null
 */
function aafm_avada_layout_detail( $data, bool $with_conditions ): ?array {
	if ( ! is_array( $data ) ) {
		return array( 'parse_status' => 'unparseable' );
	}
	$sections = array();
	$terms    = isset( $data['template_terms'] ) && is_array( $data['template_terms'] ) ? $data['template_terms'] : array();
	foreach ( $terms as $type => $section_id ) {
		if ( ! $section_id ) {
			continue; // Avada skips an empty slot.
		}
		$id      = is_numeric( $section_id ) ? absint( $section_id ) : 0;
		$section = $id > 0 ? aafm_exact_object( 'post', $id ) : null;
		if ( $id > 0 && ! $section instanceof WP_Post && ! aafm_object_absent( 'post', $id ) ) {
			return null;
		}
		$entry = array(
			'id'     => $id,
			'exists' => $section instanceof WP_Post,
		);
		if ( $section instanceof WP_Post ) {
			$entry['title']  = $section->post_title;
			$entry['status'] = $section->post_status;
		}
		$sections[ (string) $type ] = $entry;
	}
	$out = array(
		'parse_status' => 'ok',
		'sections'     => (object) $sections,
	);
	if ( $with_conditions ) {
		$out['conditions'] = isset( $data['conditions'] ) && is_array( $data['conditions'] ) ? array_values( $data['conditions'] ) : array();
	}
	return $out;
}

/**
 * The Avada Global Options this plugin may return. A fixed allowlist, never a denylist: the same
 * option holds SMTP, Turnstile, Mailchimp, HubSpot and Google Maps credentials and free head and
 * CSS code. Each key is a registered option id in themes/Avada/includes/options/*.php (7.16.1).
 *
 * @return list<string>
 */
function aafm_avada_global_option_keys(): array {
	return array( 'site_width', 'layout', 'primary_color', 'color_palette', 'link_color', 'body_typography', 'h1_typography', 'h2_typography', 'h3_typography', 'h4_typography', 'h5_typography', 'h6_typography', 'header_position', 'header_layout', 'logo', 'page_title_bar', 'footer_widgets', 'footer_copyright', 'footer_text', 'responsive', 'maintenance_mode' );
}

/**
 * Args for aafm/avada-get-global-options.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_get_global_options(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-get-global-options' ),
		'description'         => aafm_ability_description( 'aafm/avada-get-global-options' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'keys' => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => aafm_avada_global_option_keys(),
					),
					'uniqueItems' => true,
					'maxItems'    => 30,
					'description' => __( 'Only these options. Omit for the whole list.', 'agent-abilities-for-mcp' ),
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'option_name' => array( 'type' => 'string' ),
				'options'     => array(
					'type'        => 'object',
					'description' => __( 'Option key to its stored value, unchanged.', 'agent-abilities-for-mcp' ),
				),
				'not_set'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Requested keys with no stored value. Avada\'s built-in default applies to them.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_get_global_options',
		'permission_callback' => 'aafm_perm_manage_options',
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
 * Execute aafm/avada-get-global-options. Reads the stored row with get_option() and nothing else:
 * fusion_get_option() and the Fusion_Settings getters apply page and term overrides, and the post
 * meta reader can write while it reads.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_get_global_options( array $input ) {
	// Avada Builder can run on another theme; then there is no Avada option to read, and guessing
	// fusion_options would answer for a theme that is not running.
	if ( ! class_exists( 'Avada' ) || ! is_callable( array( 'Avada', 'get_option_name' ) ) ) {
		return new WP_Error(
			'aafm_avada_theme_inactive',
			__( 'The Avada theme is not active, so there are no Avada Global Options to read.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	$name   = (string) call_user_func( array( 'Avada', 'get_option_name' ) );
	$stored = '' !== $name ? get_option( $name ) : false;
	if ( false === $stored && '' !== $name ) {
		// BR-1: Avada writes the row the first time its options panel is opened.
		return new WP_Error(
			'aafm_avada_options_not_stored',
			__( 'Avada has not stored its Global Options yet. Open Avada > Options in wp-admin once, then try again.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}
	if ( ! is_array( $stored ) ) {
		return new WP_Error(
			'aafm_avada_options_unreadable',
			__( 'The Avada Global Options could not be read.', 'agent-abilities-for-mcp' ),
			array( 'status' => 500 )
		);
	}

	$allowed   = aafm_avada_global_option_keys();
	$requested = isset( $input['keys'] ) && is_array( $input['keys'] ) ? array_values( array_intersect( $allowed, $input['keys'] ) ) : $allowed;
	$options   = array();
	$not_set   = array();
	foreach ( $requested as $key ) {
		if ( array_key_exists( $key, $stored ) ) {
			$options[ $key ] = $stored[ $key ];
		} else {
			$not_set[] = $key;
		}
	}
	return array(
		'option_name' => $name,
		'options'     => (object) $options,
		'not_set'     => $not_set,
	);
}

/**
 * Args for aafm/avada-list-library.
 *
 * @return array<string,mixed>
 */
function aafm_args_avada_list_library(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/avada-list-library' ),
		'description'         => aafm_ability_description( 'aafm/avada-list-library' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				aafm_pagination_schema_props(
					100,
					__( 'Number of items per page, clamped to the 1-100 range regardless of the value requested. Defaults to 20 when omitted.', 'agent-abilities-for-mcp' ),
					__( '1-based page number for pagination. Defaults to 1.', 'agent-abilities-for-mcp' )
				),
				array(
					'kind'            => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'elements', 'columns', 'sections', 'post_cards', 'templates' ),
						'default'     => 'all',
						'description' => __( 'Only one library category, or templates for full-page templates. Defaults to all.', 'agent-abilities-for-mcp' ),
					),
					'search'          => array(
						'type'        => 'string',
						'description' => __( 'Free-text search using WordPress\'s normal post search, matched against the item\'s title and content.', 'agent-abilities-for-mcp' ),
					),
					'include_content' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'When true, each item also carries content, its raw Fusion Builder markup, unrendered. A global item is listed without its content unless the user can edit private posts.', 'agent-abilities-for-mcp' ),
					),
				)
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'items' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'           => array( 'type' => 'integer' ),
							'title'        => array( 'type' => 'string' ),
							'status'       => array( 'type' => 'string' ),
							'kind'         => array( 'type' => 'string' ),
							'is_global'    => array( 'type' => 'boolean' ),
							'modified_gmt' => array( 'type' => 'string' ),
							'content'      => array( 'type' => 'string' ),
						),
					),
				),
				'total' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_avada_list_library',
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
 * Execute aafm/avada-list-library, the way aafm_exec_list_blocks() lists reusable blocks: paged,
 * rows filtered to items the caller can edit, total as the query-wide count. Library items are
 * fusion_element posts in an element_category term and fusion_template posts; a global item has
 * _fusion_is_global = yes (Fusion_Builder_Library::create_layout()), read in a checked scope so a
 * failed meta load is an error, never "not global".
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_avada_list_library( array $input ) {
	$paging = aafm_paginate_args( $input + array( 'per_page' => 20 ), AAFM_TERMS_PER_PAGE_MAX );
	$kind   = isset( $input['kind'] ) ? (string) $input['kind'] : 'all';

	$query_args = array(
		'post_type'              => array( 'fusion_element', 'fusion_template' ),
		// pending too: create_layout() saves an item as pending for a user without publish_posts.
		'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
		'posts_per_page'         => $paging['per_page'],
		'paged'                  => $paging['page'],
		'no_found_rows'          => false,
		// Core's own priming caches empty meta when its query fails; the checked primer below does not.
		'update_post_meta_cache' => false,
	);
	if ( 'templates' === $kind ) {
		$query_args['post_type'] = 'fusion_template';
	} elseif ( in_array( $kind, array( 'elements', 'columns', 'sections', 'post_cards' ), true ) ) {
		$query_args['post_type'] = 'fusion_element';
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one term on a small post type.
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => 'element_category',
				'field'    => 'slug',
				'terms'    => $kind,
			),
		);
	}
	$search = isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '';
	if ( '' !== $search ) {
		$query_args['s'] = $search;
	}

	$query = new WP_Query( $query_args );
	aafm_prime_post_meta_checked( wp_list_pluck( (array) $query->posts, 'ID' ) );
	$visible = array();
	foreach ( (array) $query->posts as $row ) {
		if ( ! $row instanceof WP_Post ) {
			return aafm_generic_error();
		}
		$post = aafm_exact_object_chain( 'post', $row->ID );
		if ( ! $post instanceof WP_Post ) {
			return aafm_generic_error();
		}
		if ( ! aafm_user_can_checked( 'edit_post', $post->ID ) ) {
			continue;
		}
		$kind_of = 'template';
		if ( 'fusion_element' === $post->post_type ) {
			$terms = get_the_terms( $post, 'element_category' );
			if ( is_wp_error( $terms ) ) {
				return aafm_generic_error();
			}
			$kind_of = is_array( $terms ) && isset( $terms[0] ) ? $terms[0]->slug : 'uncategorized';
		}
		$visible[] = array( $post, $kind_of );
	}

	$with_content = ! empty( $input['include_content'] );
	$items        = aafm_with_checked_reads(
		static function () use ( $visible, $with_content ): array {
			$rows = array();
			foreach ( $visible as list( $post, $kind_of ) ) {
				$row = array(
					'id'           => $post->ID,
					'title'        => $post->post_title,
					'status'       => $post->post_status,
					'kind'         => $kind_of,
					'is_global'    => 'yes' === aafm_meta_get( 'post', $post->ID, '_fusion_is_global', true ),
					'modified_gmt' => $post->post_modified_gmt,
				);
				// A global item's source stays out for a user Avada keeps off its edit screen (Ruling R-S8b-5).
				if ( $with_content && ! aafm_avada_global_item_locked( $post ) ) {
					$row['content'] = $post->post_content;
				}
				$rows[] = $row;
			}
			return $rows;
		},
		aafm_generic_error()
	);
	if ( is_wp_error( $items ) ) {
		return $items;
	}

	return array(
		'items' => $items,
		'total' => (int) $query->found_posts,
	);
}
