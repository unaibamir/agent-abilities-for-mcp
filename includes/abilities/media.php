<?php
/**
 * Media abilities: the get-media read (Phase 3) plus the guarded writes
 * set-featured-image and the hardened base64 upload-media (Phase 4).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'oversio_abilities_registry', 'oversio_register_media_definitions' );

/**
 * Contribute media ability definitions to the registry (reads).
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function oversio_register_media_definitions( array $registry ): array {
	$registry['oversio/get-media']          = array(
		'label'        => __( 'Get media', 'oversio-agent-abilities' ),
		'description'  => __( 'List media library items (URL, alt, mime, dimensions). Response includes total (the full match count).', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'media',
		'args_builder' => 'oversio_args_get_media',
	);
	$registry['oversio/get-media-item']     = array(
		'label'        => __( 'Get media item', 'oversio-agent-abilities' ),
		'description'  => __( 'Read one media item by id: caption, description, date, filesize, parent, and all image sizes.', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'media',
		'args_builder' => 'oversio_args_get_media_item',
	);
	$registry['oversio/count-media']        = array(
		'label'        => __( 'Count media', 'oversio-agent-abilities' ),
		'description'  => __( 'Count media library items, total and broken down by mime type.', 'oversio-agent-abilities' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'media',
		'args_builder' => 'oversio_args_count_media',
	);
	$registry['oversio/set-featured-image'] = array(
		'label'        => __( 'Set featured image', 'oversio-agent-abilities' ),
		'description'  => __( "Set a post's featured image to an existing image attachment ID.", 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'media',
		'args_builder' => 'oversio_args_set_featured_image',
	);
	$registry['oversio/upload-media']       = array(
		'label'        => __( 'Upload media', 'oversio-agent-abilities' ),
		'description'  => __( 'Upload an image from base64 data (jpg, png, gif, webp; SVG rejected).', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'media',
		'args_builder' => 'oversio_args_upload_media',
	);
	$registry['oversio/update-media']       = array(
		'label'        => __( 'Update media', 'oversio-agent-abilities' ),
		'description'  => __( "Update an attachment's title, alt text, caption, or description.", 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'media',
		'args_builder' => 'oversio_args_update_media',
	);
	$registry['oversio/delete-media']       = array(
		'label'        => __( 'Delete media', 'oversio-agent-abilities' ),
		'description'  => __( 'Permanently delete an attachment — the file and library entry are removed and cannot be recovered.', 'oversio-agent-abilities' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'media',
		'args_builder' => 'oversio_args_delete_media',
	);
	return $registry;
}

/**
 * Args for oversio/get-media.
 *
 * @return array<string,mixed>
 */
function oversio_args_get_media(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/get-media' ),
		'description'         => oversio_ability_description( 'oversio/get-media' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'search'   => array(
					'type' => 'string',
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => OVERSIO_LIST_PER_PAGE_MAX,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => OVERSIO_LIST_PAGE_MAX,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'media' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'total' => array( 'type' => 'integer' ),
			),
		),
		'execute_callback'    => 'oversio_exec_get_media',
		'permission_callback' => 'oversio_perm_get_media',
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
function oversio_perm_get_media(): bool {
	return current_user_can( 'upload_files' ) || current_user_can( 'edit_posts' );
}

/**
 * Execute oversio/get-media.
 *
 * Lists attachments in the media library, redacted to a safe inventory shape:
 * id, title, mime type, the public URL, alt text, and dimensions. The absolute
 * server file path (get_attached_file / _wp_attached_file) and any uploader PII
 * are never returned.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function oversio_exec_get_media( array $input ): array {
	$paging = oversio_paginate_args( $input, OVERSIO_LIST_PER_PAGE_MAX );
	$query  = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
			'posts_per_page' => $paging['per_page'],
			'paged'          => $paging['page'],
			'no_found_rows'  => false,
		)
	);

	// WP_Query->posts is typed array<int,int|WP_Post>; keep only WP_Post before redacting.
	$attachments = array_filter(
		$query->posts,
		static fn( $post ): bool => $post instanceof WP_Post
	);

	return array(
		'media' => array_values( array_map( 'oversio_redact_media', $attachments ) ),
		// Full match count for the search filter so an agent can page through the library.
		'total' => (int) $query->found_posts,
	);
}

/**
 * Args for oversio/get-media-item — one attachment by id, rich shape.
 *
 * @return array<string,mixed>
 */
function oversio_args_get_media_item(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/get-media-item' ),
		'description'         => oversio_ability_description( 'oversio/get-media-item' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
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
		'execute_callback'    => 'oversio_exec_get_media_item',
		'permission_callback' => 'oversio_perm_get_media',
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
 * Execute oversio/get-media-item — rich shape by attachment id.
 *
 * The id must resolve to a real attachment; anything else returns a generic error.
 * The absolute server file path and uploader PII are never returned.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_get_media_item( array $input ) {
	$att_id     = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	$attachment = $att_id ? get_post( $att_id ) : null;
	if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
		return oversio_generic_error();
	}

	return array( 'media' => oversio_media_item_payload( $attachment ) );
}

/**
 * Args for oversio/count-media — total plus per-mime breakdown, optional mime filter.
 *
 * @return array<string,mixed>
 */
function oversio_args_count_media(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/count-media' ),
		'description'         => oversio_ability_description( 'oversio/count-media' ),
		'category'            => 'oversio-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'mime_type' => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'total'   => array( 'type' => 'integer' ),
				'by_mime' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'oversio_exec_count_media',
		'permission_callback' => 'oversio_perm_get_media',
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
 * Execute oversio/count-media — wp_count_attachments() returns counts keyed by mime
 * type; sum for the total. An optional mime_type narrows the breakdown to one type.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function oversio_exec_count_media( array $input ): array {
	$counts = (array) wp_count_attachments();
	$filter = isset( $input['mime_type'] ) ? sanitize_text_field( (string) $input['mime_type'] ) : '';

	$by_mime = array();
	$total   = 0;
	foreach ( $counts as $mime => $n ) {
		$n = (int) $n;
		if ( '' !== $filter && $filter !== $mime ) {
			continue;
		}
		$by_mime[ (string) $mime ] = $n;
		$total                    += $n;
	}

	return array(
		'total'   => $total,
		// Cast so an empty breakdown JSON-encodes to "{}" (object) per the schema,
		// instead of "[]" (a JSON array). A populated assoc array is unaffected.
		'by_mime' => (object) $by_mime,
	);
}

/**
 * Args for oversio/set-featured-image.
 *
 * Sets a post's thumbnail to an EXISTING attachment id only — never a URL, so
 * there is no fetch path and no SSRF surface. The closed input schema rejects
 * any field other than the two ids.
 *
 * @return array<string,mixed>
 */
function oversio_args_set_featured_image(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/set-featured-image' ),
		'description'         => oversio_ability_description( 'oversio/set-featured-image' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'attachment_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
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
		'execute_callback'    => 'oversio_exec_set_featured_image',
		'permission_callback' => 'oversio_perm_set_featured_image',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for oversio/set-featured-image: routes the TARGET post through the shared
 * write chokepoint.
 *
 * Setting a thumbnail is a `_thumbnail_id` write that fires save_post and bumps
 * modified_gmt, so it is gated exactly like update-post/trash-post: the target type
 * must clear the eligibility floor AND the default-deny allowlist AND be
 * map_meta_cap===true, then the per-object edit cap is checked. A bare
 * current_user_can('edit_post') is NOT enough — on a non-mapped type it degrades to a
 * singular primitive that can fail open. The denial is audited by the registration
 * wrapper before any change is attempted.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function oversio_perm_set_featured_image( array $input ): bool {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$post    = $post_id ? get_post( $post_id ) : null;
	return $post instanceof WP_Post && oversio_can_edit_post_object( $post );
}

/**
 * Execute oversio/set-featured-image — by existing IMAGE attachment id only.
 *
 * The attachment id must resolve to an attachment that is genuinely an image
 * (wp_attachment_is_image), so a PDF/zip/other attachment id can't be smuggled
 * in as a thumbnail. Never fetches a URL.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_set_featured_image( array $input ) {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	$att_id  = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;

	// Defense-in-depth: re-confirm the target exists AND its type is writable through the
	// chokepoint, so a non-allowlisted / non-mapped type is refused even if the permission
	// callback were ever bypassed.
	$target = $post_id ? get_post( $post_id ) : null;
	if ( ! $target instanceof WP_Post || ! oversio_can_edit_post_object( $target ) ) {
		return oversio_generic_error();
	}

	// The id must be a real attachment AND a real image — not a PDF or a plain post.
	if ( $att_id <= 0 || 'attachment' !== get_post_type( $att_id ) || ! wp_attachment_is_image( $att_id ) ) {
		return oversio_generic_error();
	}

	if ( ! set_post_thumbnail( $post_id, $att_id ) ) {
		return oversio_generic_error();
	}

	return array( 'set' => true );
}

/**
 * Args for oversio/upload-media.
 *
 * @return array<string,mixed>
 */
function oversio_args_upload_media(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/upload-media' ),
		'description'         => oversio_ability_description( 'oversio/upload-media' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'filename'    => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'data_base64' => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'alt'         => array(
					'type' => 'string',
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
		'execute_callback'    => 'oversio_exec_upload_media',
		'permission_callback' => 'oversio_perm_upload_media',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for oversio/upload-media: upload_files.
 *
 * A caller without upload_files is denied (and audited by the wrapper) before
 * any bytes are decoded or written.
 *
 * @return bool
 */
function oversio_perm_upload_media(): bool {
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
function oversio_upload_allowlist(): array {
	return array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	);
}

/**
 * Execute oversio/upload-media — base64 only, byte-sniffed, allow-listed, SVG
 * rejected, size-capped, filename sanitized, WordPress owns the path.
 *
 * Hardening (§6.2):
 * - NO URL fetch path exists, so the SSRF class is eliminated outright; the only
 *   input is inline base64 bytes the caller supplies.
 * - The real MIME is derived from the DECODED BYTES (finfo), never the supplied
 *   filename, extension, or any client mime — and must be on the raster-image
 *   allow-list. SVG and executable payloads fail this gate and no file is written.
 * - The filename is sanitized (sanitize_file_name) and rebuilt with the canonical
 *   extension for the real type; traversal segments cannot survive.
 * - wp_upload_bits() writes the bytes inside the uploads dir under WordPress's
 *   control — never a raw file_put_contents to a caller-chosen path.
 * - A second wp_check_filetype_and_ext() check on the written file guards against
 *   ext<->contents disagreement; a mismatch deletes the file and errors.
 * - The decoded size is capped at wp_max_upload_size() before any write.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_upload_media( array $input ) {
	$payload = isset( $input['data_base64'] ) ? (string) $input['data_base64'] : '';
	// Decoding the caller-supplied upload payload — the bytes are sniffed against a
	// strict image allow-list below, never executed; the strict flag rejects junk.
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$decoded = base64_decode( $payload, true );
	if ( false === $decoded || '' === $decoded ) {
		return new WP_Error( 'oversio_bad_base64', __( 'Invalid base64 payload.', 'oversio-agent-abilities' ) );
	}

	// Size cap from WordPress, enforced before anything is written.
	if ( strlen( $decoded ) > (int) wp_max_upload_size() ) {
		return new WP_Error( 'oversio_too_large', __( 'File exceeds the maximum upload size.', 'oversio-agent-abilities' ) );
	}

	// Derive the true MIME from the decoded BYTES, never the supplied name/extension.
	$finfo     = new finfo( FILEINFO_MIME_TYPE );
	$real_mime = $finfo->buffer( $decoded );

	$allow = oversio_upload_allowlist();
	if ( ! is_string( $real_mime ) || ! isset( $allow[ $real_mime ] ) ) {
		return new WP_Error( 'oversio_disallowed_type', __( 'Unsupported or unsafe file type.', 'oversio-agent-abilities' ) );
	}

	// Build a safe filename with the canonical extension for the real type. The
	// supplied name is reduced to its basename and sanitized, so '../' segments
	// and the client-declared extension cannot influence where the file lands.
	$base      = sanitize_file_name( wp_basename( (string) ( $input['filename'] ?? '' ), '.' . pathinfo( (string) ( $input['filename'] ?? '' ), PATHINFO_EXTENSION ) ) );
	$base      = '' !== $base ? $base : 'upload';
	$safe_name = $base . '.' . $allow[ $real_mime ];

	// Let WordPress own the path; no fopen, no traversal, confined to uploads dir.
	$upload = wp_upload_bits( $safe_name, null, $decoded );
	if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
		return oversio_generic_error();
	}

	// Second-line check on the written file (extension <-> contents agreement).
	$check = wp_check_filetype_and_ext( $upload['file'], $safe_name );
	if ( empty( $check['type'] ) || ! isset( $allow[ $check['type'] ] ) ) {
		wp_delete_file( $upload['file'] );
		return new WP_Error( 'oversio_type_mismatch', __( 'File contents did not match an allowed type.', 'oversio-agent-abilities' ) );
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
		return oversio_generic_error();
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

	if ( isset( $input['alt'] ) ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment instanceof WP_Post ) {
		return oversio_generic_error();
	}

	// Return the redacted media shape — public URL only, never an absolute path.
	return array(
		'attachment_id' => (int) $attachment_id,
		'media'         => oversio_redact_media( $attachment ),
	);
}

/**
 * Args for oversio/update-media.
 *
 * Closed schema. title/alt/caption/description are all optional; the executor
 * requires at least one. Per-object edit_post is the execute-time gate.
 *
 * @return array<string,mixed>
 */
function oversio_args_update_media(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/update-media' ),
		'description'         => oversio_ability_description( 'oversio/update-media' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'title'         => array( 'type' => 'string' ),
				'alt'           => array( 'type' => 'string' ),
				'caption'       => array( 'type' => 'string' ),
				'description'   => array( 'type' => 'string' ),
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
		'execute_callback'    => 'oversio_exec_update_media',
		'permission_callback' => 'oversio_perm_update_media',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for oversio/update-media: DIRECT per-object edit_post on the attachment.
 *
 * Attachments are a _builtin post type, so they are NOT eligible for the CPT
 * chokepoint (oversio_can_edit_post_object / oversio_validate_post_type require
 * public && !_builtin and fail-closed for ALL attachments). map_meta_cap resolves
 * edit_post correctly for attachments, so we check it directly — after confirming
 * the id is a real attachment. The denial is audited by the registration wrapper.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function oversio_perm_update_media( array $input ): bool {
	$att_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	if ( $att_id <= 0 || 'attachment' !== get_post_type( $att_id ) ) {
		return false;
	}
	return current_user_can( 'edit_post', $att_id );
}

/**
 * Execute oversio/update-media — title/alt/caption/description, at least one required.
 *
 * Re-confirms the target is a real attachment the caller can edit (defense in depth),
 * sanitizes each field, and returns the rich media shape.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_update_media( array $input ) {
	$att_id     = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	$attachment = $att_id ? get_post( $att_id ) : null;
	if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type
		|| ! current_user_can( 'edit_post', $att_id ) ) {
		return oversio_generic_error();
	}

	$has_title       = array_key_exists( 'title', $input );
	$has_alt         = array_key_exists( 'alt', $input );
	$has_caption     = array_key_exists( 'caption', $input );
	$has_description = array_key_exists( 'description', $input );

	if ( ! $has_title && ! $has_alt && ! $has_caption && ! $has_description ) {
		return new WP_Error( 'oversio_nothing_to_update', __( 'Provide at least one field to update.', 'oversio-agent-abilities' ) );
	}

	$postarr = array( 'ID' => $att_id );
	if ( $has_title ) {
		$postarr['post_title'] = sanitize_text_field( (string) $input['title'] );
	}
	if ( $has_caption ) {
		$postarr['post_excerpt'] = sanitize_textarea_field( (string) $input['caption'] );
	}
	if ( $has_description ) {
		$postarr['post_content'] = wp_kses_post( (string) $input['description'] );
	}

	if ( count( $postarr ) > 1 ) {
		// wp_update_post() unslashes its input, so slash here to keep literal
		// backslashes in title/caption/description from being stripped on save.
		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			return oversio_generic_error();
		}
	}

	if ( $has_alt ) {
		// update_post_meta() unslashes its value, so slash here too (matches
		// oversio_exec_update_post_meta) to preserve literal backslashes in alt text.
		update_post_meta( $att_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( (string) $input['alt'] ) ) );
	}

	$fresh = get_post( $att_id );
	if ( ! $fresh instanceof WP_Post ) {
		return oversio_generic_error();
	}

	return array( 'media' => oversio_media_item_payload( $fresh ) );
}

/**
 * Args for oversio/delete-media.
 *
 * Destructive: wp_delete_attachment( $id, true ) permanently removes the file and
 * the DB record; not recoverable. Closed schema; per-object delete_post is the gate.
 *
 * @return array<string,mixed>
 */
function oversio_args_delete_media(): array {
	return array(
		'label'               => oversio_ability_label( 'oversio/delete-media' ),
		'description'         => oversio_ability_description( 'oversio/delete-media' ),
		'category'            => 'oversio-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
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
		'execute_callback'    => 'oversio_exec_delete_media',
		'permission_callback' => 'oversio_perm_delete_media',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for oversio/delete-media: DIRECT per-object delete_post on the attachment.
 *
 * Same _builtin rationale as update-media: the CPT chokepoint fails-closed for all
 * attachments, so we check the per-object delete cap directly after confirming the
 * id is a real attachment. The denial is audited by the registration wrapper.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function oversio_perm_delete_media( array $input ): bool {
	$att_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	if ( $att_id <= 0 || 'attachment' !== get_post_type( $att_id ) ) {
		return false;
	}
	return current_user_can( 'delete_post', $att_id );
}

/**
 * Execute oversio/delete-media — permanent delete via wp_delete_attachment( $id, true ).
 *
 * Re-confirms the target is a real attachment the caller can delete (defense in
 * depth). wp_delete_attachment returns WP_Post on success, or false|null on failure;
 * anything other than a WP_Post is treated as failure.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function oversio_exec_delete_media( array $input ) {
	$att_id = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
	if ( $att_id <= 0 || 'attachment' !== get_post_type( $att_id ) || ! current_user_can( 'delete_post', $att_id ) ) {
		return oversio_generic_error();
	}

	$result = wp_delete_attachment( $att_id, true );
	if ( ! $result instanceof WP_Post ) {
		return oversio_generic_error();
	}

	return array(
		'deleted'       => true,
		'attachment_id' => $att_id,
	);
}
