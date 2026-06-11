<?php
/**
 * Shared validation, redaction, pagination, and error helpers.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Validate a post type against the public/queryable allow-list.
 *
 * @param string $type Requested post type.
 * @return string|WP_Error Sanitized type, or error if not allowed.
 */
function aafm_validate_post_type( string $type ) {
	$type    = sanitize_key( $type );
	$allowed = get_post_types( array( 'public' => true ), 'names' );
	if ( ! in_array( $type, $allowed, true ) ) {
		return new WP_Error( 'aafm_invalid_post_type', __( 'Unsupported post type.', 'agent-abilities-for-mcp' ) );
	}
	return $type;
}

/**
 * Validate a taxonomy against the public allow-list.
 *
 * @param string $taxonomy Requested taxonomy.
 * @return string|WP_Error Sanitized taxonomy, or error if not allowed.
 */
function aafm_validate_taxonomy( string $taxonomy ) {
	$taxonomy = sanitize_key( $taxonomy );
	$allowed  = get_taxonomies( array( 'public' => true ), 'names' );
	if ( ! in_array( $taxonomy, $allowed, true ) ) {
		return new WP_Error( 'aafm_invalid_taxonomy', __( 'Unsupported taxonomy.', 'agent-abilities-for-mcp' ) );
	}
	return $taxonomy;
}

/**
 * Validate a post status against a strict allow-list.
 *
 * Blocks 'any' and prevents a non-privileged caller from widening visibility to
 * private/draft/etc.
 *
 * @param string $status           Requested status.
 * @param bool   $can_read_private Whether the caller may read non-public statuses.
 * @return string|WP_Error Sanitized status, or error if not allowed.
 */
function aafm_validate_post_status( string $status, bool $can_read_private ) {
	$status = sanitize_key( $status );

	// Public statuses come from core (covers custom public statuses), not a hardcoded list.
	$public_statuses  = array_values( get_post_stati( array( 'public' => true ) ) );
	$private_statuses = array( 'draft', 'pending', 'future', 'private' );

	if ( in_array( $status, $public_statuses, true ) ) {
		return $status;
	}
	if ( in_array( $status, $private_statuses, true ) && $can_read_private ) {
		return $status;
	}
	// 'any', 'trash', 'auto-draft', 'inherit', and unknown values are always rejected —
	// this is the no-`status=any`-widening guard.
	return new WP_Error( 'aafm_invalid_status', __( 'Unsupported or unauthorized post status.', 'agent-abilities-for-mcp' ) );
}

/**
 * Reduce a post to a safe, public-facing shape.
 *
 * @param WP_Post $post Post object.
 * @return array<string,mixed>
 */
function aafm_redact_post( WP_Post $post ): array {
	return array(
		'id'           => (int) $post->ID,
		'title'        => get_the_title( $post ),
		'status'       => $post->post_status,
		'type'         => $post->post_type,
		'slug'         => $post->post_name,
		'excerpt'      => has_excerpt( $post ) ? get_the_excerpt( $post ) : '',
		'link'         => (string) get_permalink( $post ),
		'author_id'    => (int) $post->post_author,
		'date_gmt'     => $post->post_date_gmt,
		'modified_gmt' => $post->post_modified_gmt,
	);
}

/**
 * Reduce a user to id, display name, roles, and post count — never PII.
 *
 * @param WP_User|false $user User object.
 * @return array<string,mixed>
 */
function aafm_redact_user( $user ): array {
	if ( ! $user instanceof WP_User ) {
		return array();
	}
	return array(
		'id'           => (int) $user->ID,
		'display_name' => $user->display_name,
		'roles'        => array_values( $user->roles ),
		'post_count'   => (int) count_user_posts( $user->ID ),
	);
}

/**
 * Reduce a comment to a safe shape — no email, no IP.
 *
 * @param WP_Comment|null $comment Comment object.
 * @return array<string,mixed>
 */
function aafm_redact_comment( $comment ): array {
	if ( ! $comment instanceof WP_Comment ) {
		return array();
	}
	return array(
		'id'          => (int) $comment->comment_ID,
		'post_id'     => (int) $comment->comment_post_ID,
		'author_name' => $comment->comment_author,
		'content'     => $comment->comment_content,
		'status'      => wp_get_comment_status( $comment ),
		'date_gmt'    => $comment->comment_date_gmt,
		'parent'      => (int) $comment->comment_parent,
	);
}

/**
 * Reduce a term to a safe, public-facing shape.
 *
 * @param WP_Term $term Term object.
 * @return array<string,mixed>
 */
function aafm_redact_term( WP_Term $term ): array {
	return array(
		'id'          => (int) $term->term_id,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'taxonomy'    => $term->taxonomy,
		'parent'      => (int) $term->parent,
		'count'       => (int) $term->count,
		'description' => $term->description,
	);
}

/**
 * Reduce an attachment to a safe inventory shape (public URL/alt/mime/dims only).
 *
 * @param WP_Post $attachment Attachment post.
 * @return array<string,mixed>
 */
function aafm_redact_media( WP_Post $attachment ): array {
	$meta = wp_get_attachment_metadata( $attachment->ID );
	return array(
		'id'        => (int) $attachment->ID,
		'title'     => get_the_title( $attachment ),
		'mime_type' => $attachment->post_mime_type,
		'url'       => (string) wp_get_attachment_url( $attachment->ID ),
		'alt'       => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
		'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : null,
	);
}

/**
 * Bound page/per_page arguments.
 *
 * @param array<string,mixed> $input Raw input.
 * @param int                 $max   Maximum allowed per_page.
 * @return array{per_page:int,page:int}
 */
function aafm_paginate_args( array $input, int $max = 50 ): array {
	$per_page = isset( $input['per_page'] ) ? (int) $input['per_page'] : 10;
	$page     = isset( $input['page'] ) ? (int) $input['page'] : 1;
	return array(
		'per_page' => min( $max, max( 1, $per_page ) ),
		'page'     => max( 1, $page ),
	);
}

/**
 * A single generic error returned to callers — never leaks internal detail.
 *
 * @return WP_Error
 */
function aafm_generic_error(): WP_Error {
	return new WP_Error( 'aafm_error', __( 'The request could not be completed.', 'agent-abilities-for-mcp' ) );
}

/**
 * Whether WordPress will move trashed content to the Trash instead of deleting it.
 *
 * Core's wp_trash_post()/wp_trash_comment() force a permanent, unrecoverable delete
 * when EMPTY_TRASH_DAYS is 0 or falsy. The trash abilities advertise "recoverable,
 * never permanently deleted", so they consult this before trashing and refuse when
 * the Trash is off rather than silently destroy content.
 *
 * @return bool True when the Trash is enabled (content is recoverable).
 */
function aafm_trash_is_enabled(): bool {
	$enabled = defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS;

	/**
	 * Filters whether the plugin treats the Trash as enabled.
	 *
	 * @param bool $enabled True when EMPTY_TRASH_DAYS is truthy.
	 */
	return (bool) apply_filters( 'aafm_trash_is_enabled', $enabled );
}

/**
 * The error returned when a trash ability is asked to act on a Trash-disabled site.
 *
 * @return WP_Error
 */
function aafm_trash_disabled_error(): WP_Error {
	return new WP_Error(
		'aafm_trash_disabled',
		__( 'Trash is disabled on this site, so this content cannot be moved to the Trash. Refusing to permanently delete it.', 'agent-abilities-for-mcp' )
	);
}
