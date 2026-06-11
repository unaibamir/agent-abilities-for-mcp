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
		'description'  => __( 'List media library items (URL, alt, mime, dimensions).', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'args_builder' => 'aafm_args_get_media',
	);
	$registry['aafm/set-featured-image'] = array(
		'label'        => __( 'Set featured image', 'agent-abilities-for-mcp' ),
		'description'  => __( "Set a post's featured image to an existing image attachment ID.", 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'args_builder' => 'aafm_args_set_featured_image',
	);
	$registry['aafm/upload-media']       = array(
		'label'        => __( 'Upload media', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Upload an image from base64 data (jpg, png, gif, webp; SVG rejected).', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'args_builder' => 'aafm_args_upload_media',
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
		'label'               => __( 'Get media', 'agent-abilities-for-mcp' ),
		'description'         => __( 'List media library items (URL, alt, mime, dimensions).', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'search'   => array(
					'type' => 'string',
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
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
			),
		),
		'execute_callback'    => 'aafm_exec_get_media',
		'permission_callback' => 'aafm_perm_get_media',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
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
 * Execute aafm/get-media.
 *
 * Lists attachments in the media library, redacted to a safe inventory shape:
 * id, title, mime type, the public URL, alt text, and dimensions. The absolute
 * server file path (get_attached_file / _wp_attached_file) and any uploader PII
 * are never returned.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_get_media( array $input ): array {
	$paging = aafm_paginate_args( $input, 50 );
	$query  = new WP_Query(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			's'              => isset( $input['search'] ) ? sanitize_text_field( (string) $input['search'] ) : '',
			'posts_per_page' => $paging['per_page'],
			'paged'          => $paging['page'],
		)
	);

	// WP_Query->posts is typed array<int,int|WP_Post>; keep only WP_Post before redacting.
	$attachments = array_filter(
		$query->posts,
		static fn( $post ): bool => $post instanceof WP_Post
	);

	return array( 'media' => array_values( array_map( 'aafm_redact_media', $attachments ) ) );
}

/**
 * Args for aafm/set-featured-image.
 *
 * Sets a post's thumbnail to an EXISTING attachment id only — never a URL, so
 * there is no fetch path and no SSRF surface. The closed input schema rejects
 * any field other than the two ids.
 *
 * @return array<string,mixed>
 */
function aafm_args_set_featured_image(): array {
	return array(
		'label'               => __( 'Set featured image', 'agent-abilities-for-mcp' ),
		'description'         => __( "Set a post's featured image to an existing image attachment ID.", 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
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
 * Permission for aafm/set-featured-image: per-object edit_post on the TARGET post.
 *
 * A caller who cannot edit the specific post cannot change its thumbnail. The
 * denial is audited by the registration wrapper before any change is attempted.
 *
 * @param array<string,mixed> $input Input.
 * @return bool
 */
function aafm_perm_set_featured_image( array $input ): bool {
	$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
	return $post_id > 0 && current_user_can( 'edit_post', $post_id );
}

/**
 * Execute aafm/set-featured-image — by existing IMAGE attachment id only.
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

	if ( $post_id <= 0 || ! get_post( $post_id ) instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// The id must be a real attachment AND a real image — not a PDF or a plain post.
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
		'label'               => __( 'Upload media', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Upload an image from base64 data (jpg, png, gif, webp; SVG rejected).', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
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
 * Execute aafm/upload-media — base64 only, byte-sniffed, allow-listed, SVG
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
function aafm_exec_upload_media( array $input ) {
	$payload = isset( $input['data_base64'] ) ? (string) $input['data_base64'] : '';
	// Decoding the caller-supplied upload payload — the bytes are sniffed against a
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

	// Derive the true MIME from the decoded BYTES, never the supplied name/extension.
	$finfo     = new finfo( FILEINFO_MIME_TYPE );
	$real_mime = $finfo->buffer( $decoded );

	$allow = aafm_upload_allowlist();
	if ( ! is_string( $real_mime ) || ! isset( $allow[ $real_mime ] ) ) {
		return new WP_Error( 'aafm_disallowed_type', __( 'Unsupported or unsafe file type.', 'agent-abilities-for-mcp' ) );
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
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $input['alt'] ) );
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment instanceof WP_Post ) {
		return aafm_generic_error();
	}

	// Return the redacted media shape — public URL only, never an absolute path.
	return array(
		'attachment_id' => (int) $attachment_id,
		'media'         => aafm_redact_media( $attachment ),
	);
}
