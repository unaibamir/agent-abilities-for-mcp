<?php
/**
 * Media abilities: the get-media read (Phase 3) plus the guarded writes
 * set-featured-image and the hardened base64 upload-media (Phase 4).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_media_definitions' );

/**
 * Contribute media ability definitions to the registry (reads).
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_media_definitions( array $registry ): array {
	$registry['aafm/get-media']          = array(
		'label'        => __( 'Get media', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List media library items (URL, alt, mime, dimensions). Response includes total (the full match count).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'media',
		'args_builder' => 'aafm_args_get_media',
	);
	$registry['aafm/get-media-item']     = array(
		'label'        => __( 'Get media item', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Read one media item by id: caption, description, date, filesize, parent, and all image sizes.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'media',
		'args_builder' => 'aafm_args_get_media_item',
	);
	$registry['aafm/count-media']        = array(
		'label'        => __( 'Count media', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Count media library items, total and broken down by mime type.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'media',
		'args_builder' => 'aafm_args_count_media',
	);
	$registry['aafm/set-featured-image'] = array(
		'label'        => __( 'Set featured image', 'agent-abilities-for-mcp' ),
		'description'  => __( "Set a post's featured image to an existing image attachment ID.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'media',
		'args_builder' => 'aafm_args_set_featured_image',
	);
	$registry['aafm/upload-media']       = array(
		'label'        => __( 'Upload media', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Upload an image from base64 data (jpg, png, gif, webp; SVG rejected).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'media',
		'args_builder' => 'aafm_args_upload_media',
	);
	$registry['aafm/update-media']       = array(
		'label'        => __( 'Update media', 'agent-abilities-for-mcp' ),
		'description'  => __( "Update an attachment's title, alt text, caption, or description.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'media',
		'args_builder' => 'aafm_args_update_media',
	);
	$registry['aafm/delete-media']       = array(
		'label'        => __( 'Delete media', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently delete an attachment - the file and library entry are removed and cannot be recovered.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'media',
		'args_builder' => 'aafm_args_delete_media',
	);
	return $registry;
}

/**
 * Args for aafm/get-media.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_media(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-media' ),
		'description'         => aafm_ability_description( 'aafm/get-media' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'search'   => array(
						'type'        => 'string',
						'description' => __( 'Free-text search term matched against the media item\'s title, using WordPress\'s normal search matching.', 'agent-abilities-for-mcp' ),
					),
					'per_page' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => AAFM_LIST_PER_PAGE_MAX,
						'description' => __( 'Number of items per page, clamped to the 1-50 range regardless of the value requested. Defaults to 10 when omitted.', 'agent-abilities-for-mcp' ),
					),
					'page'     => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => AAFM_LIST_PAGE_MAX,
						'description' => __( '1-based page number for pagination. Defaults to 1.', 'agent-abilities-for-mcp' ),
					),
				),
				aafm_lang_schema_fragment()
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'media'    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'total'    => array( 'type' => 'integer' ),
				'language' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The WPML language the list was scoped to ("all" for every language), or null when WPML is inactive.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_get_media',
		'permission_callback' => 'aafm_perm_get_media',
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
 * Permission for the media inventory read: upload_files or edit_posts.
 *
 * A bare subscriber (no upload/edit capability) is denied, and the denial is
 * audited by the registration wrapper before any media metadata is read.
 *
 * @return bool
 */
function aafm_perm_get_media(): bool {
	return current_user_can( 'upload_files' ) || current_user_can( 'edit_posts' );
}

/**
 * The author id media reads should scope to, or null for the unscoped full library.
 *
 * A caller holding edit_others_posts sees the whole library, unchanged from before.
 * Anyone else who cleared the aafm_perm_get_media() floor (upload_files or
 * edit_posts) sees only the attachments they uploaded themselves. Applies to the
 * list read, the single-object read, and the count read alike - scoping the list
 * without the count would recreate the count-versus-list contradiction 1.3.2 was
 * built to remove.
 *
 * @return int|null
 */
function aafm_media_scope_author_id(): ?int {
	return current_user_can( 'edit_others_posts' ) ? null : get_current_user_id();
}

/**
 * Execute aafm/get-media.
 *
 * Lists attachments in the media library, redacted to a safe inventory shape:
 * id, title, mime type, the public URL, alt text, and dimensions. The absolute
 * server file path (get_attached_file / _wp_attached_file) and any uploader PII
 * are never returned. Scoped to the caller's own uploads per
 * aafm_media_scope_author_id().
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_media( array $input ) {
	$paging    = aafm_paginate_args( $input, AAFM_LIST_PER_PAGE_MAX );
	$author_id = aafm_media_scope_author_id();

	$lang = aafm_resolve_lang( $input );
	if ( is_wp_error( $lang ) ) {
		return $lang;
	}
	$query = aafm_with_language(
		$lang,
		static function () use ( $input, $paging, $author_id ): WP_Query {
			$args = array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				's'                => isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
				'posts_per_page'   => $paging['per_page'],
				'paged'            => $paging['page'],
				'no_found_rows'    => false,
				'suppress_filters' => false,
			);
			if ( null !== $author_id ) {
				$args['author'] = $author_id;
			}
			return new WP_Query( $args );
		}
	);

	// WP_Query->posts is typed array<int,int|WP_Post>; keep only WP_Post before redacting.
	$attachments = array_filter(
		$query->posts,
		static fn( $post ): bool => $post instanceof WP_Post
	);

	return array(
		'media'    => array_values( array_map( 'aafm_redact_media', $attachments ) ),
		// Full match count for the search filter so an agent can page through the library.
		'total'    => (int) $query->found_posts,
		'language' => $lang,
	);
}

/**
 * Args for aafm/get-media-item - one attachment by id, rich shape.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_media_item(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/get-media-item' ),
		'description'         => aafm_ability_description( 'aafm/get-media-item' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'attachment_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'ID of the attachment to read. An unknown ID, or one that is not an attachment, returns a generic error.', 'agent-abilities-for-mcp' ),
					),
				),
				aafm_lang_schema_fragment()
			),
			'required'             => array( 'attachment_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'media' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_media_item',
		'permission_callback' => 'aafm_perm_get_media',
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
 * Execute aafm/get-media-item - rich shape by attachment id.
 *
 * The id must resolve to a real attachment; anything else returns a generic error.
 * The absolute server file path and uploader PII are never returned.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_media_item( array $input ) {
	$att_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	if ( $att_id ) {
		$lang = aafm_resolve_lang( $input );
		if ( is_wp_error( $lang ) ) {
			return $lang;
		}
		$att_id = ( is_string( $lang ) && 'all' !== $lang )
			? aafm_wpml_translated_id( $att_id, 'attachment', $lang )
			: $att_id;
	}
	$attachment = $att_id ? get_post( $att_id ) : null;
	if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
		return aafm_generic_error();
	}

	// Scoped to the caller's own uploads, same as the list read. The SAME generic
	// error as an unknown id, deliberately: a distinct message here would make the
	// scoping an existence oracle telling the caller which attachment ids exist.
	$author_id = aafm_media_scope_author_id();
	if ( null !== $author_id && (int) $attachment->post_author !== $author_id ) {
		return aafm_generic_error();
	}

	return array( 'media' => aafm_media_item_payload( $attachment ) );
}

/**
 * Args for aafm/count-media - total plus per-mime breakdown, optional mime filter.
 *
 * @return array<string,mixed>
 */
function aafm_args_count_media(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/count-media' ),
		'description'         => aafm_ability_description( 'aafm/count-media' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array_merge(
				array(
					'mime_type' => array(
						'type'        => 'string',
						'description' => __( 'Exact MIME type to filter the breakdown to, for example image/jpeg. Omit to return the total and breakdown across every MIME type.', 'agent-abilities-for-mcp' ),
					),
				),
				aafm_lang_schema_fragment()
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'total'    => array( 'type' => 'integer' ),
				'by_mime'  => array( 'type' => 'object' ),
				'language' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The WPML language the list was scoped to ("all" for every language), or null when WPML is inactive.', 'agent-abilities-for-mcp' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_count_media',
		'permission_callback' => 'aafm_perm_get_media',
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
 * Execute aafm/count-media - wp_count_attachments() returns counts keyed by mime
 * type; sum for the total. An optional mime_type narrows the breakdown to one type.
 *
 * That single language-blind SQL GROUP BY disagreed with the language-scoped
 * aafm/get-media list whenever WPML Media Translation is active. When WPML is on,
 * the same mime-keyed counts are instead built from an attachment WP_Query run
 * inside the resolved language scope; when WPML is off this delegates to
 * wp_count_attachments() exactly as before, so non-multilingual sites are
 * byte-for-byte unchanged.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_count_media( array $input ) {
	$filter = isset( $input['mime_type'] ) ? sanitize_text_field( (string) $input['mime_type'] ) : '';
	$lang   = aafm_resolve_lang( $input );
	if ( is_wp_error( $lang ) ) {
		return $lang;
	}
	$author_id = aafm_media_scope_author_id();

	if ( aafm_wpml_active() ) {
		$counts = aafm_with_language(
			$lang,
			static function () use ( $author_id ): array {
				return aafm_query_attachment_counts_by_mime( $author_id );
			}
		);
	} elseif ( null !== $author_id ) {
		// wp_count_attachments() cannot be author-scoped, so a scoped caller goes
		// through the same query-based counter the WPML branch uses.
		$counts = aafm_query_attachment_counts_by_mime( $author_id );
	} else {
		$counts = (array) wp_count_attachments();
	}

	$by_mime = array();
	$total   = 0;
	foreach ( $counts as $mime => $n ) {
		// wp_count_attachments() adds a 'trash' key alongside the mime-type rows -
		// it is a status bucket (how many attachments are trashed), not a mime
		// type, so it must not be reported as one or summed into the total. The
		// WPML-scoped query never includes trashed attachments in the first place
		// (post_status => 'inherit'), so this guard only matters for the
		// wp_count_attachments() branch.
		if ( 'trash' === $mime ) {
			continue;
		}
		$n = (int) $n;
		if ( '' !== $filter && $filter !== $mime ) {
			continue;
		}
		$by_mime[ (string) $mime ] = $n;
		$total                    += $n;
	}

	return array(
		'total'    => $total,
		// Cast so an empty breakdown JSON-encodes to "{}" (object) per the schema,
		// instead of "[]" (a JSON array). A populated assoc array is unaffected.
		'by_mime'  => (object) $by_mime,
		'language' => $lang,
	);
}

/**
 * Count non-trashed attachments grouped by their exact post_mime_type, mirroring
 * wp_count_attachments()'s key shape but via WP_Query so WPML's language filters
 * apply. Called from inside aafm_with_language() and directly - see aafm_exec_count_media().
 *
 * @param int|null $author_id Restrict to this author's attachments, or null for the
 *                            unscoped full library (aafm_media_scope_author_id()).
 * @return array<string,int> Mime type => count.
 */
function aafm_query_attachment_counts_by_mime( ?int $author_id = null ): array {
	$args = array(
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'posts_per_page'         => -1,
		'no_found_rows'          => true,
		'suppress_filters'       => false,
		// The loop reads only post_mime_type, a wp_posts column already on the hydrated
		// WP_Post. Leaving these at WP_Query's defaults (both true) would fire a large
		// postmeta and term IN-scan per invocation that nothing here ever reads, which on a
		// big library is the dominant cost. suppress_filters stays false so WPML's language
		// filter still applies and the tally is byte-identical.
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);
	if ( null !== $author_id ) {
		$args['author'] = $author_id;
	}
	$query = new WP_Query( $args );

	$counts = array();
	foreach ( $query->posts as $post ) {
		if ( ! $post instanceof WP_Post ) {
			continue;
		}
		$mime            = (string) $post->post_mime_type;
		$counts[ $mime ] = ( $counts[ $mime ] ?? 0 ) + 1;
	}
	return $counts;
}

/**
 * Args for aafm/set-featured-image.
 *
 * Sets a post's thumbnail to an EXISTING attachment id only - never a URL, so
 * there is no fetch path and no SSRF surface. The closed input schema rejects
 * any field other than the two ids.
 *
 * @return array<string,mixed>
 */
function aafm_args_set_featured_image(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/set-featured-image' ),
		'description'         => aafm_ability_description( 'aafm/set-featured-image' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'       => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the post whose featured image will be set. The caller must be able to edit this post.', 'agent-abilities-for-mcp' ),
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of an existing image attachment to use as the featured image. Must already exist and genuinely be an image; a non-image or missing attachment is rejected.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id', 'attachment_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'set' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_set_featured_image',
		'permission_callback' => 'aafm_perm_set_featured_image',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/set-featured-image: routes the TARGET post through the shared
 * write chokepoint.
 *
 * Setting a thumbnail is a `_thumbnail_id` write that fires save_post and bumps
 * modified_gmt, so it is gated exactly like update-post/trash-post: the target type
 * must clear the eligibility floor AND the default-deny allowlist AND be
 * map_meta_cap===true, then the per-object edit cap is checked. A bare
 * current_user_can('edit_post') is NOT enough - on a non-mapped type it degrades to a
 * singular primitive that can fail open. The denial is audited by the registration
 * wrapper before any change is attempted.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_set_featured_image( array $input ): bool {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = $post_id ? get_post( $post_id ) : null;
	return $post instanceof WP_Post && aafm_can_edit_post_object( $post );
}

/**
 * Execute aafm/set-featured-image - by existing IMAGE attachment id only.
 *
 * The attachment id must resolve to an attachment that is genuinely an image
 * (wp_attachment_is_image), so a PDF/zip/other attachment id can't be smuggled
 * in as a thumbnail. Never fetches a URL.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_set_featured_image( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$att_id  = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;

	// Defense-in-depth: re-confirm the target exists AND its type is writable through the
	// chokepoint, so a non-allowlisted / non-mapped type is refused even if the permission
	// callback were ever bypassed.
	$target = $post_id ? get_post( $post_id ) : null;
	if ( ! $target instanceof WP_Post || ! aafm_can_edit_post_object( $target ) ) {
		return aafm_generic_error();
	}

	// The id must be a real attachment AND a real image - not a PDF or a plain post.
	if ( $att_id <= 0 || 'attachment' !== get_post_type( $att_id ) || ! wp_attachment_is_image( $att_id ) ) {
		return aafm_generic_error();
	}

	if ( ! set_post_thumbnail( $post_id, $att_id ) ) {
		return aafm_generic_error();
	}

	return array( 'set' => true );
}

/**
 * Args for aafm/upload-media.
 *
 * @return array<string,mixed>
 */
function aafm_args_upload_media(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/upload-media' ),
		'description'         => aafm_ability_description( 'aafm/upload-media' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'filename'    => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Filename to base the stored file\'s name on. Only the basename is kept and sanitized, and the extension is replaced with the one matching the file\'s actual detected type, not the extension supplied here.', 'agent-abilities-for-mcp' ),
				),
				'data_base64' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( 'Base64-encoded file bytes. The decoded content must be a real jpg, png, gif, or webp image, verified from its bytes rather than the filename or a claimed MIME type; SVG and any other type is rejected, and the decoded size must fit within the site\'s maximum upload size.', 'agent-abilities-for-mcp' ),
				),
				'alt'         => array(
					'type'        => 'string',
					'description' => __( 'Alt text to set on the uploaded image.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'filename', 'data_base64' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array( 'type' => 'integer' ),
				'media'         => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_upload_media',
		'permission_callback' => 'aafm_perm_upload_media',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/upload-media: upload_files.
 *
 * A caller without upload_files is denied (and audited by the wrapper) before
 * any bytes are decoded or written.
 *
 * @return bool
 */
function aafm_perm_upload_media(): bool {
	return current_user_can( 'upload_files' );
}

/**
 * The allow-list of safe upload MIME types mapped to canonical extensions.
 *
 * Intentionally narrow: raster images only. SVG (script-capable XML), HTML, PHP,
 * and every other type are absent and therefore rejected.
 *
 * @return array<string,string>
 */
function aafm_upload_allowlist(): array {
	return array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	);
}

/**
 * Whether the PHP fileinfo extension is available to byte-sniff an upload.
 * Filterable so the missing-extension path can be exercised in tests without
 * actually removing the extension from the runtime.
 *
 * @return bool
 */
function aafm_fileinfo_available(): bool {
	return (bool) apply_filters( 'aafm_fileinfo_available', extension_loaded( 'fileinfo' ) );
}

/**
 * Whether decoding this upload will be paid for out of PHP's own memory_limit.
 *
 * This decides whether the pixel cap below applies at all, and it matters more than it looks.
 * GD decodes into PHP's heap, so a large image really does come out of memory_limit and a
 * memory-derived bound models it. ImageMagick does not: it allocates through its own resource
 * limits and spills to its disk cache, so PHP's peak stays small no matter how big the image is.
 * That was measured, not assumed - a fully valid 30000x30000 payload fired in isolation used a
 * 2 MB PHP peak while ImageMagick's stock `area` policy (256 MP) refused the 900 MP decode by
 * itself.
 *
 * So applying a PHP-memory bound on an ImageMagick host would refuse images that host handles
 * perfectly well. A 48 MP phone photo on a 256M site is the concrete case: ImageMagick takes it,
 * a PHP-memory cap would not. Refusing a legitimate upload is its own defect, so the cap stays
 * out of the way where its cost model does not hold.
 *
 * The test is a proxy for WordPress's own editor choice, not that choice itself.
 * wp_get_image_editor() would answer exactly, but it loads the image to do so, which is the very
 * work this guard exists to avoid; _wp_image_editor_choose() answers without loading but is
 * private API.
 *
 * Both halves of the proxy are deliberate, and they are the two things core's own
 * WP_Image_Editor_Imagick::test() cares about. class_exists('Imagick') is what core actually
 * checks, so checking it here keeps the two aligned. extension_loaded('imagick') is checked as
 * well because the editor classes are not autoloaded - core requires them inside
 * _wp_image_editor_choose(), so on a request that has not reached an image editor yet the class
 * may not be in memory even though the extension is. Either one being true is treated as
 * ImageMagick being available.
 *
 * KNOWN IMPRECISION, stated rather than hidden: a site that filters wp_image_editors to force GD
 * while ImageMagick is present gets no cap. Every divergence here resolves the same way, toward
 * not capping, so the imprecision fails OPEN. That is the correct direction for this to be wrong,
 * since the cost of a false refusal is a legitimate upload rejected and the cost of a missed cap
 * is the behaviour that shipped in 1.6.3 anyway.
 *
 * @return bool True when GD will pay for the decode out of PHP memory.
 */
function aafm_upload_decode_costs_php_memory(): bool {
	$imagick_available = extension_loaded( 'imagick' ) || class_exists( 'Imagick' );

	return (bool) apply_filters( 'aafm_upload_decode_costs_php_memory', ! $imagick_available );
}

/**
 * Derive how many pixels this request can afford to decode, from a memory budget.
 *
 * Kept pure - it takes the two numbers and returns the bound - so both branches can be tested
 * without touching the runtime's real memory limit.
 *
 * THE DERIVATION, so nobody has to reverse-engineer it from the arithmetic:
 *
 * GD holds a decoded truecolor image as one packed integer per pixel, four bytes, regardless of
 * how small the compressed file was. That is the whole decompression-bomb mechanism: a 187 KB PNG
 * can declare 8000x8000 and cost 256 MB the moment it is decoded. Four is the floor of the real
 * cost rather than a padded estimate, and it is deliberately the permissive end of the 4-to-5
 * range, because the failure mode of a too-small number here is refusing somebody's real photo.
 *
 * The budget is what WordPress will actually have in hand during image work, not the current
 * memory_limit. Core raises the limit before it decodes anything (wp_raise_memory_limit('image'),
 * called from both image editors), so the ceiling is the larger of memory_limit and
 * WP_MAX_MEMORY_LIMIT, after the image_memory_limit filter. Anything already allocated is
 * subtracted, because that memory is not available to the decode either.
 *
 * WHAT THIS DOES NOT DO, stated precisely, because a guard is not a proof and a caveat that
 * overclaims is worse than no caveat at all.
 *
 * Passing this check does NOT mean the decode will fit. Four bytes per pixel is the floor of the
 * cost, not the whole of it: a real GD decode also pays for scanline buffers and the row-pointer
 * table, an image editor may hold a source and a destination bitmap at the same time, and nothing
 * here models the sub-size generation that follows. So an image just under the ceiling can still
 * exhaust memory. This excludes the clearly impossible; it does not certify the possible, and the
 * upload path is not memory-safe because this exists.
 *
 * What it does do is convert a decode that was going to exhaust PHP's memory and die without
 * explanation into a refusal that names the limit. The asymmetry is deliberate. Because four is
 * the permissive end of the range, an image this refuses would have failed on any reading of the
 * cost, so the refusals are sound even though the acceptances are not a guarantee.
 *
 * It is also not a rate limit, and it does not stop a determined caller from tying up a worker.
 *
 * @param int $budget_bytes Total memory the decode may use, or -1 for unlimited.
 * @param int $in_use_bytes Memory already allocated to this request.
 * @return int Maximum pixel count, or 0 for "no bound could be determined; do not refuse".
 */
function aafm_derive_max_pixels( int $budget_bytes, int $in_use_bytes ): int {
	// Four bytes per pixel: one packed truecolor integer, GD's in-memory representation.
	$bytes_per_pixel = 4;

	// -1 is PHP's "unlimited", and a zero or negative budget means the value could not be read.
	// Neither yields a bound, and the instruction when there is no bound is to allow, not guess.
	//
	// Mutation testing showed this guard alone is NOT load-bearing: with it removed the subtraction
	// below goes negative and the next guard catches the same cases. It stays because it states the
	// intent at the point the caller cares about, and because "allow" must not depend on an
	// arithmetic accident. Removing BOTH does change the answer (the function starts returning a
	// negative pixel count), which is what the no-bound rows in UploadPixelCapTest pin.
	if ( $budget_bytes <= 0 ) {
		return 0;
	}

	$available = $budget_bytes - max( 0, $in_use_bytes );

	// Already at or over budget. A determinate answer, but "refuse everything" is a far worse
	// failure than "refuse nothing", so this also declines to bound.
	if ( $available <= 0 ) {
		return 0;
	}

	return intdiv( $available, $bytes_per_pixel );
}

/**
 * The pixel ceiling for this request, gathering the environment around aafm_derive_max_pixels().
 *
 * Returns 0 to mean "no ceiling" - unlimited memory, an unreadable limit, or a host whose decoder
 * does not spend PHP memory in the first place.
 *
 * $derived reports the ceiling as it stood BEFORE the aafm_upload_max_pixels filter, so a caller
 * can tell a memory-derived ceiling from one a site owner set. The refusal message needs that
 * distinction: it explains the ceiling as a memory bound, and that explanation is only true when
 * the derivation is what produced the number. Reported through this parameter rather than a second
 * function because a second call would re-read memory_get_usage(), so the two answers could
 * disagree by a chunk and the message would pick its wording on an accident. One call, one
 * measurement, exact.
 *
 * @param int|null $derived Receives the ceiling the memory derivation produced; 0 when none was.
 * @param-out int  $derived
 * @return int Maximum pixel count, or 0 for no cap.
 */
function aafm_upload_max_pixels( &$derived = null ): int {
	$budget     = -1;
	$max_pixels = 0;

	// The derivation only runs where its cost model holds. The filter below runs either way, so a
	// site owner can still impose a ceiling on a host this declines to derive one for - an
	// override that could only ever relax the guard would be half a filter.
	if ( aafm_upload_decode_costs_php_memory() ) {
		$current = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$wp_max  = defined( 'WP_MAX_MEMORY_LIMIT' ) ? wp_convert_hr_to_bytes( (string) WP_MAX_MEMORY_LIMIT ) : 0;

		// Either one being unlimited makes the effective ceiling unlimited, because core raises to
		// the larger of the two. Mirrors wp_raise_memory_limit()'s own -1 handling.
		$budget = ( -1 === $current || -1 === $wp_max ) ? -1 : max( $current, $wp_max );

		/** This filter is documented in wp-includes/functions.php */
		$budget = wp_convert_hr_to_bytes( (string) apply_filters( 'image_memory_limit', $budget ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core owns this hook; we apply it so the estimate matches the limit wp_raise_memory_limit('image') will actually install.

		$max_pixels = aafm_derive_max_pixels( (int) $budget, memory_get_usage( true ) );
	}

	$derived = $max_pixels;

	/**
	 * Filters the maximum pixel count aafm/upload-media will decode in one request.
	 *
	 * The default is derived from the memory the host can actually spare, so it scales with the
	 * site instead of guessing for it. Return 0 to remove the ceiling entirely, or a pixel count
	 * to set your own - useful on a site that handles genuinely large photography, or one whose
	 * decoder does not spend PHP memory the way this estimate assumes. It runs even where no
	 * ceiling could be derived, so it can impose one as well as relax one.
	 *
	 * @param int $max_pixels   Derived ceiling in pixels; 0 means no ceiling.
	 * @param int $budget_bytes The memory budget the ceiling was derived from; -1 for unlimited.
	 */
	return max( 0, (int) apply_filters( 'aafm_upload_max_pixels', $max_pixels, (int) $budget ) );
}

/**
 * Execute aafm/upload-media - base64 only, byte-sniffed, allow-listed, SVG
 * rejected, size-capped, filename sanitized, WordPress owns the path.
 *
 * Hardening (§6.2):
 * - NO URL fetch path exists, so the SSRF class is eliminated outright; the only
 *   input is inline base64 bytes the caller supplies.
 * - The real MIME is derived from the DECODED BYTES (finfo), never the supplied
 *   filename, extension, or any client mime - and must be on the raster-image
 *   allow-list. SVG and executable payloads fail this gate and no file is written.
 * - fileinfo availability is guarded (aafm_fileinfo_available) before finfo is
 *   ever instantiated, so a host without the extension errors instead of
 *   fataling on a missing class.
 * - The filename is sanitized (sanitize_file_name, then aafm_sanitize_plain_text for
 *   the control and bidi characters core keeps) and rebuilt with the canonical
 *   extension for the real type; traversal segments cannot survive. The same
 *   sanitized base becomes the attachment title, so neither can be used to spoof
 *   how a name renders in the media library.
 * - wp_upload_bits() writes the bytes inside the uploads dir under WordPress's
 *   control - never a raw file_put_contents to a caller-chosen path.
 * - A second wp_check_filetype_and_ext() check on the written file guards against
 *   ext<->contents disagreement; a mismatch deletes the file and errors.
 * - The decoded size is capped at wp_max_upload_size() before any write.
 * - The PIXEL count is capped too, read from the header before any pixel is decoded. The byte cap
 *   cannot see this, because compression is the whole point of a decompression bomb: a small file
 *   may declare dimensions that cost hundreds of megabytes to decode. The ceiling is derived from
 *   the host's own memory rather than a fixed number, and is inert where the decode does not spend
 *   PHP memory - see aafm_upload_max_pixels() and aafm_derive_max_pixels() for the derivation and
 *   for what it does and does not protect against.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_upload_media( array $input ) {
	$payload = isset( $input['data_base64'] ) ? (string) $input['data_base64'] : '';
	// Decoding the caller-supplied upload payload - the bytes are sniffed against a
	// strict image allow-list below, never executed; the strict flag rejects junk.
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$decoded = base64_decode( $payload, true );
	if ( false === $decoded || '' === $decoded ) {
		return new WP_Error( 'aafm_bad_base64', __( 'Invalid base64 payload.', 'agent-abilities-for-mcp' ) );
	}

	// Size cap from WordPress, enforced before anything is written.
	if ( strlen( $decoded ) > (int) wp_max_upload_size() ) {
		return new WP_Error( 'aafm_too_large', __( 'File exceeds the maximum upload size.', 'agent-abilities-for-mcp' ) );
	}

	if ( ! aafm_fileinfo_available() ) {
		return new WP_Error( 'aafm_fileinfo_missing', __( 'The server is missing the PHP fileinfo extension, required to verify upload contents.', 'agent-abilities-for-mcp' ) );
	}

	// Derive the true MIME from the decoded BYTES, never the supplied name/extension.
	$finfo     = new finfo( FILEINFO_MIME_TYPE );
	$real_mime = $finfo->buffer( $decoded );

	$allow = aafm_upload_allowlist();
	if ( ! is_string( $real_mime ) || ! isset( $allow[ $real_mime ] ) ) {
		return new WP_Error( 'aafm_disallowed_type', __( 'Unsupported or unsafe file type.', 'agent-abilities-for-mcp' ) );
	}

	// Pixel ceiling, checked from the HEADER before any pixel is decoded and before any byte is
	// written. The byte cap above does not cover this: compression is exactly what a decompression
	// bomb exploits, so a file well inside wp_max_upload_size() can still declare dimensions that
	// cost hundreds of megabytes to decode. getimagesizefromstring() reads only the header.
	//
	// Unreadable or zero dimensions do NOT refuse. A file that got this far already passed the
	// byte sniff, so the honest reading is that this check cannot answer, not that the file is
	// hostile, and the rule when there is no bound is to allow.
	$derived_pixels = 0;
	$max_pixels     = aafm_upload_max_pixels( $derived_pixels );
	if ( $max_pixels > 0 ) {
		$dimensions = @getimagesizefromstring( $decoded ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed header is answered by the false return, not by a notice reaching the caller.
		$width      = is_array( $dimensions ) ? (int) $dimensions[0] : 0;
		$height     = is_array( $dimensions ) ? (int) $dimensions[1] : 0;

		// The positivity test is belt-and-braces, not the thing that makes an unreadable header
		// safe: an unreadable header gives 0, and 0 is never greater than a positive ceiling, so
		// removing it changes no outcome (confirmed by mutation). It is kept so the intent is
		// legible and so a hypothetical pair of negative dimensions cannot multiply into a
		// positive product.
		if ( $width > 0 && $height > 0 && ( $width * $height ) > $max_pixels ) {
			// Explain the ceiling as a memory bound only where the memory derivation is what
			// produced it. A filter can name any number, on any host, including one where the
			// derivation declined to run at all - so back-computing size_format( $max_pixels * 4 )
			// from a filtered ceiling reports an amount of memory that was never the reason for
			// the refusal and need not exist on the machine. Both branches still name the limit
			// and how to move it, because refusing a legitimate upload without explaining it is
			// its own defect.
			if ( $derived_pixels > 0 && $derived_pixels === $max_pixels ) {
				return new WP_Error(
					'aafm_too_many_pixels',
					sprintf(
						/* translators: 1: image width in pixels, 2: image height in pixels, 3: the image's megapixel count, 4: the site's megapixel ceiling, 5: formatted memory size, for example "256 MB". */
						__( 'This image is %1$d by %2$d pixels (%3$s megapixels), and this site can decode at most %4$s megapixels in one request. The ceiling comes from the %5$s of memory available for image work, at four bytes per pixel for the uncompressed bitmap a decoder builds, so a larger image would run the site out of memory rather than finish. Resize the image before uploading, raise the memory available to PHP, or raise the aafm_upload_max_pixels filter.', 'agent-abilities-for-mcp' ),
						$width,
						$height,
						number_format_i18n( ( $width * $height ) / 1000000, 1 ),
						number_format_i18n( $max_pixels / 1000000, 1 ),
						size_format( $max_pixels * 4 )
					)
				);
			}

			return new WP_Error(
				'aafm_too_many_pixels',
				sprintf(
					/* translators: 1: image width in pixels, 2: image height in pixels, 3: the image's megapixel count, 4: the site's megapixel ceiling. */
					__( 'This image is %1$d by %2$d pixels (%3$s megapixels), and this site can decode at most %4$s megapixels in one request. The aafm_upload_max_pixels filter sets that ceiling. Resize the image before uploading, or change the filter.', 'agent-abilities-for-mcp' ),
					$width,
					$height,
					number_format_i18n( ( $width * $height ) / 1000000, 1 ),
					number_format_i18n( $max_pixels / 1000000, 1 )
				)
			);
		}
	}

	// Build a safe filename with the canonical extension for the real type. The
	// supplied name is reduced to its basename and sanitized, so '../' segments
	// and the client-declared extension cannot influence where the file lands.
	//
	// sanitize_file_name() alone is not enough for what gets STORED. It handles the
	// path- and shell-hostile punctuation, but it keeps every C0 control except tab,
	// LF and CR, and it keeps the whole Trojan Source bidi set - so a name carrying
	// U+202E lands intact. aafm_sanitize_plain_text() runs on top and has the last
	// word, which is the same rule aafm-update-media already applies to a title on
	// this very object; without it the two abilities disagree about the same field.
	// Ordering is deliberate: the plain-text strip runs last so nothing can
	// reintroduce a bidi override after it, and the path guarantee does not weaken,
	// because wp_upload_bits() -> wp_unique_filename() re-applies sanitize_file_name()
	// to the name that actually reaches disk.
	//
	// $base feeds BOTH consumers - the stored file name below and post_title at the
	// wp_insert_attachment() call - so one strip covers both. The corpus test pins
	// each of them separately, so decoupling them later fails loudly.
	$base      = aafm_sanitize_plain_text( sanitize_file_name( wp_basename( (string) ( $input['filename'] ?? '' ), '.' . pathinfo( (string) ( $input['filename'] ?? '' ), PATHINFO_EXTENSION ) ) ) );
	$base      = '' !== $base ? $base : 'upload';
	$safe_name = $base . '.' . $allow[ $real_mime ];

	// Let WordPress own the path; no fopen, no traversal, confined to uploads dir.
	$upload = wp_upload_bits( $safe_name, null, $decoded );
	if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
		return aafm_generic_error();
	}

	// Second-line check on the written file (extension <-> contents agreement).
	$check = wp_check_filetype_and_ext( $upload['file'], $safe_name );
	if ( empty( $check['type'] ) || ! isset( $allow[ $check['type'] ] ) ) {
		wp_delete_file( $upload['file'] );
		return new WP_Error( 'aafm_type_mismatch', __( 'File contents did not match an allowed type.', 'agent-abilities-for-mcp' ) );
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $real_mime,
			'post_title'     => $base,
			'post_status'    => 'inherit',
		),
		$upload['file'],
		0,
		true
	);
	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $upload['file'] );
		return aafm_generic_error();
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

	if ( isset( $input['alt'] ) ) {
		// update_post_meta() unslashes the value, so a backslash in the alt text is stripped unless
		// it is slashed first, exactly like the sibling meta writers.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( aafm_sanitize_plain_text( (string) $input['alt'] ) ) );
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// Return the redacted media shape - public URL only, never an absolute path.
	return array(
		'attachment_id' => (int) $attachment_id,
		'media'         => aafm_redact_media( $attachment ),
	);
}

/**
 * Args for aafm/update-media.
 *
 * Closed schema. title/alt/caption/description are all optional; the executor
 * requires at least one. Per-object edit_post is the execute-time gate.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_media(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/update-media' ),
		'description'         => aafm_ability_description( 'aafm/update-media' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the attachment to update.', 'agent-abilities-for-mcp' ),
				),
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'New attachment title, sanitized as plain text. At least one of title, alt, caption, or description must be given.', 'agent-abilities-for-mcp' ),
				),
				'alt'           => array(
					'type'        => 'string',
					'description' => __( 'New alt text for the image, sanitized as plain text.', 'agent-abilities-for-mcp' ),
				),
				'caption'       => array(
					'type'        => 'string',
					'description' => __( 'New caption, sanitized as plain text with no HTML.', 'agent-abilities-for-mcp' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'New description. Basic HTML is allowed and sanitized.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'attachment_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'media' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_update_media',
		'permission_callback' => 'aafm_perm_update_media',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/update-media: DIRECT per-object edit_post on the attachment.
 *
 * Attachments are a _builtin post type, so they are NOT eligible for the CPT
 * chokepoint (aafm_can_edit_post_object / aafm_validate_post_type require
 * public && !_builtin and fail-closed for ALL attachments). map_meta_cap resolves
 * edit_post correctly for attachments, so we check it directly - after confirming
 * the id is a real attachment. The denial is audited by the registration wrapper.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_update_media( array $input ): bool {
	$att_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	if ( $att_id <= 0 || 'attachment' !== get_post_type( $att_id ) ) {
		return false;
	}
	return current_user_can( 'edit_post', $att_id );
}

/**
 * Execute aafm/update-media - title/alt/caption/description, at least one required.
 *
 * Re-confirms the target is a real attachment the caller can edit (defense in depth),
 * sanitizes each field, and returns the rich media shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_media( array $input ) {
	$att_id     = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	$attachment = $att_id ? get_post( $att_id ) : null;
	if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type
		|| ! current_user_can( 'edit_post', $att_id ) ) {
		return aafm_generic_error();
	}

	$has_title       = array_key_exists( 'title', $input );
	$has_alt         = array_key_exists( 'alt', $input );
	$has_caption     = array_key_exists( 'caption', $input );
	$has_description = array_key_exists( 'description', $input );

	if ( ! $has_title && ! $has_alt && ! $has_caption && ! $has_description ) {
		return new WP_Error( 'aafm_nothing_to_update', __( 'Provide at least one field to update.', 'agent-abilities-for-mcp' ) );
	}

	$postarr = array( 'ID' => $att_id );
	if ( $has_title ) {
		$postarr['post_title'] = aafm_sanitize_plain_text( (string) $input['title'] );
	}
	if ( $has_caption ) {
		$postarr['post_excerpt'] = aafm_sanitize_multiline_text( (string) $input['caption'] );
	}
	if ( $has_description ) {
		$postarr['post_content'] = wp_kses_post( (string) $input['description'] );
	}

	if ( count( $postarr ) > 1 ) {
		// wp_update_post() unslashes its input, so slash here to keep literal
		// backslashes in title/caption/description from being stripped on save.
		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			return aafm_generic_error();
		}
	}

	if ( $has_alt ) {
		// update_post_meta() unslashes its value, so slash here too (matches
		// aafm_exec_update_post_meta) to preserve literal backslashes in alt text.
		update_post_meta( $att_id, '_wp_attachment_image_alt', wp_slash( aafm_sanitize_plain_text( (string) $input['alt'] ) ) );
	}

	$fresh = get_post( $att_id );
	if ( ! $fresh instanceof WP_Post ) {
		return aafm_generic_error();
	}

	return array( 'media' => aafm_media_item_payload( $fresh ) );
}

/**
 * Args for aafm/delete-media.
 *
 * Destructive: wp_delete_attachment( $id, true ) permanently removes the file and
 * the DB record; not recoverable. Closed schema; per-object delete_post is the gate.
 *
 * @return array<string,mixed>
 */
function aafm_args_delete_media(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/delete-media' ),
		'description'         => aafm_ability_description( 'aafm/delete-media' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of the attachment to permanently delete. Both the file and the library entry are removed; there is no undo.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'attachment_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted'       => array( 'type' => 'boolean' ),
				'attachment_id' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'aafm_exec_delete_media',
		'permission_callback' => 'aafm_perm_delete_media',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for aafm/delete-media: DIRECT per-object delete_post on the attachment.
 *
 * Same _builtin rationale as update-media: the CPT chokepoint fails-closed for all
 * attachments, so we check the per-object delete cap directly after confirming the
 * id is a real attachment. The denial is audited by the registration wrapper.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_delete_media( array $input ): bool {
	$att_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	if ( $att_id <= 0 || 'attachment' !== get_post_type( $att_id ) ) {
		return false;
	}
	return current_user_can( 'delete_post', $att_id );
}

/**
 * Execute aafm/delete-media - permanent delete via wp_delete_attachment( $id, true ).
 *
 * Re-confirms the target is a real attachment the caller can delete (defense in
 * depth). wp_delete_attachment returns WP_Post on success, or false|null on failure;
 * anything other than a WP_Post is treated as failure.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_delete_media( array $input ) {
	$att_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	if ( $att_id <= 0 || 'attachment' !== get_post_type( $att_id ) || ! current_user_can( 'delete_post', $att_id ) ) {
		return aafm_generic_error();
	}

	$result = wp_delete_attachment( $att_id, true );
	if ( ! $result instanceof WP_Post ) {
		return aafm_generic_error();
	}

	return array(
		'deleted'       => true,
		'attachment_id' => $att_id,
	);
}
