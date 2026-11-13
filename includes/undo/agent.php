<?php
/**
 * What an agent connection may undo: its own changes from the last 24 hours, and only what the forward
 * tool would still let it write today.
 *
 * Ownership of the row is decided before any of this runs (aafm_undo_apply()). Everything here is a
 * refusal reason, never a value: a value that fails a rule gives needs_owner and the owner reverts it
 * from the Changes tab.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The tools whose recorded meta keys are fixed by the tool itself, so no allow-list applies to them.
 *
 * @return string[]
 */
function aafm_undo_fixed_meta_tools(): array {
	return array( 'aafm/set-featured-image', 'aafm/yoast-update-post', 'aafm/rankmath-update-post', 'aafm/rankmath-update-schema', 'aafm/slim-seo-update-post' );
}

/**
 * Whether every string inside a value equals its own plain-text sanitization.
 *
 * @param mixed $value A decoded value.
 * @return bool
 */
function aafm_undo_leaves_are_plain( $value ): bool {
	if ( is_string( $value ) ) {
		return aafm_sanitize_plain_text( $value ) === $value;
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $leaf ) {
			if ( ! aafm_undo_leaves_are_plain( $leaf ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * Whether a fixed SEO meta value that holds a URL passes the URL rule its forward tool runs.
 *
 * Yoast and Rank Math keep one URL per meta key; Slim SEO keeps its URLs as leaves of one array. A key that is not a
 * URL field passes; a URL field that holds anything but a string does not.
 *
 * @param string $key   Meta key.
 * @param mixed  $value The decoded value of one row.
 * @return bool
 */
function aafm_undo_seo_urls_pass( string $key, $value ): bool {
	$url_keys = array();
	foreach ( array( 'yoast', 'rankmath' ) as $plugin ) {
		if ( function_exists( "aafm_{$plugin}_url_fields" ) && function_exists( "aafm_{$plugin}_fields" ) ) {
			$fields = call_user_func( "aafm_{$plugin}_fields" );
			foreach ( call_user_func( "aafm_{$plugin}_url_fields" ) as $field ) {
				if ( isset( $fields[ $field ] ) ) {
					$url_keys[ (string) $fields[ $field ] ] = (string) $field;
				}
			}
		}
	}
	// A URL field holds a string; anything else a legacy row stored there cannot be restored by an agent.
	$passes = static fn( $url, string $field ): bool => is_string( $url ) && ( '' === $url || aafm_checked_url( $url, $field ) === $url );
	if ( isset( $url_keys[ $key ] ) ) {
		return $passes( $value, $url_keys[ $key ] );
	}
	if ( 'slim_seo' === $key && is_array( $value ) && function_exists( 'aafm_slim_seo_url_fields' ) ) {
		foreach ( aafm_slim_seo_url_fields() as $field ) {
			if ( isset( $value[ $field ] ) && ! $passes( $value[ $field ], (string) $field ) ) {
				return false;
			}
		}
	}
	return true;
}

/**
 * Whether the forward tool's own sanitizer would store this value as it stands, for one recorded field.
 *
 * @param string              $field    Field id.
 * @param mixed               $value    The before-value that would be written.
 * @param array<string,mixed> $row      The change row.
 * @param array<string,mixed> $decoded  The decoded row.
 * @return bool
 */
function aafm_undo_value_passes_forward_rules( string $field, $value, array $row, array $decoded ): bool {
	$forward = (string) $row['ability'];
	switch ( $field ) {
		case 'post:post_title':
			return is_string( $value ) && aafm_sanitize_plain_text( $value ) === $value && aafm_title_within_limit( $value );
		case 'post:post_excerpt':
		case 'term:name':
			return is_string( $value ) && aafm_sanitize_plain_text( $value ) === $value;
		case 'post:post_content':
			if ( ! is_string( $value ) ) {
				return false;
			}
			$clean = aafm_kses_block_markup( $value );
			if ( $clean['content'] !== $value ) {
				return false;
			}
			$guard = aafm_block_guard_evaluate( $value );
			return empty( $guard['error'] );
		case 'term:description':
			return is_string( $value ) && wp_kses_post( $value ) === $value;
	}

	if ( 0 === strpos( $field, 'meta:' ) ) {
		$key       = substr( $field, 5 );
		$rows      = array_map( 'strval', (array) $value );
		$post_obj  = 'post' === $row['object_type'] ? aafm_exact_object( 'post', (int) $row['object_id'] ) : null;
		$post_type = $post_obj instanceof WP_Post ? $post_obj->post_type : 'post';
		if ( '_wp_page_template' === $key && 'aafm/update-page' === $forward ) {
			// The template is checked against the theme in aafm_undo_page_template_restorable().
			return aafm_undo_page_template_restorable( $row, $decoded );
		}
		if ( '_thumbnail_id' === $key ) {
			foreach ( $rows as $raw ) {
				if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $raw ) ) {
					return false;
				}
			}
			return true;
		}
		foreach ( $rows as $raw ) {
			$inspect = aafm_meta_raw_inspect( $raw );
			if ( null !== $inspect['reason'] ) {
				return false;
			}
			$decoded_value = $inspect['value'];
			if ( in_array( $forward, aafm_undo_fixed_meta_tools(), true ) ) {
				if ( ! aafm_undo_leaves_are_plain( $decoded_value ) || ! aafm_undo_seo_urls_pass( $key, $decoded_value ) ) {
					return false;
				}
			} elseif ( 'term' === $row['object_type'] ) {
				if ( ! is_scalar( $decoded_value ) || aafm_sanitize_term_meta_value( $key, $decoded_value, (string) $decoded['taxonomy'] ) !== $decoded_value ) {
					return false;
				}
			} elseif ( ! is_scalar( $decoded_value ) || aafm_sanitize_meta_value( $key, $decoded_value, $post_type ) !== $decoded_value ) {
				return false;
			}
		}
		return true;
	}

	if ( 0 === strpos( $field, 'aioseo:' ) ) {
		$column = substr( $field, 7 );
		if ( null === $value ) {
			return true;
		}
		if ( in_array( $column, array( 'robots_default', 'robots_noindex', 'robots_nofollow', 'twitter_use_og' ), true ) ) {
			return in_array( (string) $value, array( '0', '1' ), true );
		}
		if ( in_array( $column, array( 'canonical_url', 'og_image_custom_url', 'twitter_image_custom_url' ), true ) ) {
			return is_string( $value ) && ( '' === $value || aafm_checked_url( $value, $column ) === $value );
		}
		return is_string( $value ) && aafm_sanitize_plain_text( $value ) === $value;
	}

	return true;
}

/**
 * The refusals only an agent meets: the 24 hour window, an undo of an undo, the forward tool's own
 * switches and permissions today, the sanitizers the forward tool would run, and Force draft.
 *
 * @param array<string,mixed> $row A change row.
 * @return string|null Reason code, or null when the agent may go on.
 */
function aafm_undo_agent_refusal( array $row ): ?string {
	if ( strtotime( (string) $row['created_at'] . ' UTC' ) < time() - AAFM_UNDO_AGENT_WINDOW ) {
		return 'window_passed';
	}
	if ( (int) $row['reverts_change_id'] > 0 ) {
		return 'needs_owner';
	}

	$surfaces = aafm_undo_surfaces();
	$forward  = (string) $row['ability'];
	if ( ! isset( $surfaces[ $forward ] ) ) {
		return 'not_allowed';
	}
	$surface   = $surfaces[ $forward ];
	$decoded   = aafm_undo_decode_row( $row );
	$object_id = (int) $row['object_id'];
	$tool      = $forward;
	$perm      = $decoded['perm'];
	$create    = 'trash' === $row['undo_op'] && isset( $surface['inverse'] );
	if ( $create ) {
		// A create is undone by trashing it, so the trash tool is the one the connection must hold.
		$tool = (string) $surface['inverse'];
		$perm = array( (string) $surface['inverse_perm'] => $object_id );
	}
	$denied = $create ? 'needs_owner' : 'not_allowed';

	$uid = get_current_user_id();
	if ( ! aafm_is_ability_enabled( $tool ) || ! aafm_ability_allowed_for_principal( $tool, $uid, aafm_oauth_current_client_id() ) || ! aafm_user_can_call_ability( $tool, $perm ) ) {
		return $denied;
	}
	if ( 'aafm/replace-sitewide' === $forward && ! aafm_perm_replace_in_post( array( 'post_id' => $object_id ) ) ) {
		return 'not_allowed';
	}

	if ( 'post' === $row['object_type'] ) {
		$post = aafm_exact_object( 'post', $object_id );
		if ( ! $post instanceof WP_Post ) {
			return 'dependency_gone';
		}
		if ( ! aafm_post_tool_reach_allowed( $post, 'restore' === $row['undo_op'] ? 'update' : 'trash' ) ) {
			return $denied;
		}
	}

	foreach ( $decoded['fields'] as $field ) {
		// The page template key is protected, so the meta validator refuses it. update-page writes it
		// itself, so its undo may put it back, but only on a page this connection may still edit and
		// only to a template the theme still offers.
		if ( 'meta:_wp_page_template' === $field && 'aafm/update-page' === $forward ) {
			if ( ! aafm_undo_page_template_restorable( $row, $decoded ) ) {
				return 'not_allowed';
			}
			continue;
		}
		if ( 0 === strpos( $field, 'meta:' ) && ! in_array( $forward, aafm_undo_fixed_meta_tools(), true ) && '_thumbnail_id' !== substr( $field, 5 ) ) {
			$key   = substr( $field, 5 );
			$check = 'term' === $row['object_type'] ? aafm_validate_term_meta_key( $key ) : aafm_validate_meta_key( $key );
			if ( is_wp_error( $check ) ) {
				return 'not_allowed';
			}
		}
		if ( 0 === strpos( $field, 'terms:' ) ) {
			$taxonomy = substr( $field, 6 );
			$ids      = array_map( 'intval', (array) ( $decoded['before'][ $field ] ?? array() ) );
			if ( array() !== $ids ) {
				if ( ! is_array( aafm_validate_term_ids_for_taxonomy( $taxonomy, $ids ) ) ) {
					return 'not_allowed';
				}
			} else {
				$object = is_wp_error( aafm_validate_taxonomy( $taxonomy ) ) ? null : get_taxonomy( $taxonomy );
				if ( ! $object instanceof WP_Taxonomy || ! current_user_can( $object->cap->assign_terms ) ) {
					return 'not_allowed';
				}
			}
		}
	}

	// Only what the row will write is checked: a create writes nothing but the trash.
	foreach ( 'restore' === $row['undo_op'] ? $decoded['before'] : array() as $field => $value ) {
		if ( ! aafm_undo_value_passes_forward_rules( (string) $field, $value, $row, $decoded ) ) {
			return 'needs_owner';
		}
	}

	return aafm_undo_agent_status_refusal( $row, $decoded );
}

/**
 * Whether an agent may put back an update-page change's recorded page template: the object is a
 * page the caller may edit, and every recorded value is a template the theme still offers.
 *
 * @param array<string,mixed> $row     The change row.
 * @param array<string,mixed> $decoded The decoded row.
 * @return bool
 */
function aafm_undo_page_template_restorable( array $row, array $decoded ): bool {
	$id   = (int) $row['object_id'];
	$page = 'post' === $row['object_type'] ? aafm_exact_object_chain( 'post', $id ) : null;
	if ( ! $page instanceof WP_Post || 'page' !== $page->post_type || ! aafm_user_can_checked( 'edit_post', $id ) ) {
		return false;
	}
	foreach ( (array) ( $decoded['before']['meta:_wp_page_template'] ?? array() ) as $template ) {
		if ( ! is_string( $template ) || ! aafm_page_template_available( $template, $page ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Force draft and the publish capability for a status the row would put back.
 *
 * @param array<string,mixed> $row     The change row.
 * @param array<string,mixed> $decoded The decoded row.
 * @return string|null Reason code, or null.
 */
function aafm_undo_agent_status_refusal( array $row, array $decoded ): ?string {
	$wanted = $decoded['before']['post:post_status'] ?? null;
	if ( 'post' !== $row['object_type'] || ! is_string( $wanted ) || 'trash' === $row['undo_op'] ) {
		return null;
	}
	$post = aafm_exact_object( 'post', (int) $row['object_id'] );
	if ( ! $post instanceof WP_Post ) {
		return 'dependency_gone';
	}
	$type_object = get_post_type_object( $post->post_type );
	$publish_cap = $type_object instanceof WP_Post_Type ? (string) $type_object->cap->publish_posts : 'publish_posts';
	$status      = aafm_authorize_post_status( $wanted, $publish_cap, null, 'untrash' === $row['undo_op'] ? 'trash' : $post->post_status );
	if ( is_wp_error( $status ) ) {
		return 'not_allowed';
	}
	return $status !== $wanted ? 'status_coerced' : null;
}
