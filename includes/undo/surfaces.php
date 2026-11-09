<?php
/**
 * What the change history covers: which tools record a change and the fields each one touches,
 * which tools say they cannot be undone and why, and the one closed list of reason codes.
 *
 * Field ids: post:{column}, meta:{key}, terms:{taxonomy}, term:{name|description|parent},
 * aioseo:{column}, and fp:object (the whole-object fingerprint of a created post).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The seven post columns a post-level change records.
 *
 * @return string[] Field ids.
 */
function aafm_undo_post_field_ids(): array {
	return array( 'post:post_title', 'post:post_content', 'post:post_excerpt', 'post:post_name', 'post:post_status', 'post:post_date', 'post:post_date_gmt' );
}

/**
 * Field ids for the meta keys of an input map that pass the owning validator, so a hard-blocked key
 * never reaches the table.
 *
 * @param mixed  $map       The tool's meta input, key to value.
 * @param string $validator Validator function, aafm_validate_meta_key or aafm_validate_term_meta_key.
 * @return string[]
 */
function aafm_undo_meta_field_ids( $map, string $validator = 'aafm_validate_meta_key' ): array {
	$ids = array();
	foreach ( is_array( $map ) ? array_keys( $map ) : array() as $key ) {
		$key = (string) $key;
		if ( ! is_wp_error( 'aafm_validate_term_meta_key' === $validator ? aafm_validate_term_meta_key( $key ) : aafm_validate_meta_key( $key ) ) ) {
			$ids[] = 'meta:' . $key;
		}
	}
	return $ids;
}

/**
 * Fields touched by update-post, update-page and update-cpt-item.
 *
 * @param array<string,mixed> $input Tool input.
 * @return string[]
 */
function aafm_undo_fields_post_write( array $input ): array {
	$ids = aafm_undo_post_field_ids();
	foreach ( is_array( $input['terms'] ?? null ) ? array_keys( $input['terms'] ) : array() as $taxonomy ) {
		$ids[] = 'terms:' . sanitize_key( (string) $taxonomy );
	}
	if ( isset( $input['featured_media'] ) ) {
		$ids[] = 'meta:_thumbnail_id';
	}
	return array_values( array_unique( array_merge( $ids, aafm_undo_meta_field_ids( $input['meta'] ?? null ) ) ) );
}

/**
 * Surface factory for the families with a fixed field list.
 *
 * @param string              $family  Family name.
 * @param string              $object_type 'post', 'term' or ''.
 * @param string              $id      Input key holding the object id ('' for a create).
 * @param string[]            $handles Input keys the surface understands.
 * @param callable            $fields  fields( array $input ): string[].
 * @param array<string,mixed> $extra   Further keys: taxonomy, undo_op, inverse, inverse_perm, post_type, perm.
 * @return array<string,mixed>
 */
function aafm_undo_surface( string $family, string $object_type, string $id, array $handles, callable $fields, array $extra = array() ): array {
	return array_merge(
		array(
			'family'  => $family,
			'object'  => $object_type,
			'id'      => $id,
			'perm'    => '' !== $id ? array( $id ) : array(),
			'handles' => $handles,
			'fields'  => $fields,
		),
		$extra
	);
}

/**
 * The tools whose changes are recorded, keyed by ability name.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_undo_surfaces(): array {
	$post_cols = static fn(): array => aafm_undo_post_field_ids();
	$write     = array( 'title', 'content', 'excerpt', 'status', 'slug', 'featured_media', 'terms', 'meta' );
	$create    = static function ( string $inverse, string $inverse_perm, $post_type ): array {
		return array(
			'undo_op'      => 'trash',
			'inverse'      => $inverse,
			'inverse_perm' => $inverse_perm,
			'post_type'    => $post_type,
		);
	};
	$seo       = static function ( array $handles, callable $fields ): array {
		return aafm_undo_surface( 'meta', 'post', 'post_id', $handles, $fields );
	};
	$seo_keys  = static function ( string $callback ): callable {
		return static fn(): array => function_exists( $callback ) ? array_map( static fn( string $key ): string => 'meta:' . $key, $callback() ) : array();
	};

	$surfaces = array(
		'aafm/update-post'            => aafm_undo_surface( 'post_write', 'post', 'post_id', array_merge( $write, array( 'post_id' ) ), 'aafm_undo_fields_post_write' ),
		'aafm/update-page'            => aafm_undo_surface( 'post_write', 'post', 'page_id', array_merge( $write, array( 'page_id' ) ), 'aafm_undo_fields_post_write' ),
		'aafm/update-cpt-item'        => aafm_undo_surface( 'post_write', 'post', 'post_id', array_merge( $write, array( 'post_id' ) ), 'aafm_undo_fields_post_write' ),
		'aafm/replace-in-post'        => aafm_undo_surface( 'post_content', 'post', 'post_id', array( 'post_id', 'search', 'replace' ), $post_cols ),
		'aafm/replace-block'          => aafm_undo_surface( 'post_content', 'post', 'post_id', array( 'post_id', 'path', 'markup', 'expect_block' ), $post_cols ),
		'aafm/create-draft'           => aafm_undo_surface( 'post_create', 'post', '', $write, 'aafm_undo_fields_none', $create( 'aafm/trash-post', 'post_id', 'post' ) ),
		'aafm/create-post'            => aafm_undo_surface( 'post_create', 'post', '', $write, 'aafm_undo_fields_none', $create( 'aafm/trash-post', 'post_id', 'post' ) ),
		'aafm/create-page'            => aafm_undo_surface( 'post_create', 'post', '', $write, 'aafm_undo_fields_none', $create( 'aafm/trash-page', 'page_id', 'page' ) ),
		'aafm/create-cpt-item'        => aafm_undo_surface( 'post_create', 'post', '', array_merge( $write, array( 'post_type' ) ), 'aafm_undo_fields_none', $create( 'aafm/trash-post', 'post_id', '@input' ) ),
		'aafm/trash-post'             => aafm_undo_surface( 'post_trash', 'post', 'post_id', array( 'post_id' ), 'aafm_undo_fields_trash', array( 'undo_op' => 'untrash' ) ),
		'aafm/trash-page'             => aafm_undo_surface( 'post_trash', 'post', 'page_id', array( 'page_id' ), 'aafm_undo_fields_trash', array( 'undo_op' => 'untrash' ) ),
		'aafm/delete-block'           => aafm_undo_surface( 'post_trash', 'post', 'block_id', array( 'block_id' ), 'aafm_undo_fields_trash', array( 'undo_op' => 'untrash' ) ),
		'aafm/add-post-terms'         => aafm_undo_surface(
			'post_terms',
			'post',
			'post_id',
			array( 'post_id', 'taxonomy', 'term_ids' ),
			static fn( array $input ): array => array( 'terms:' . sanitize_key( (string) ( $input['taxonomy'] ?? '' ) ) ),
			array( 'perm' => array( 'post_id', 'taxonomy' ) )
		),
		'aafm/update-term'            => aafm_undo_surface(
			'term_fields',
			'term',
			'term_id',
			array( 'taxonomy', 'term_id', 'name', 'description', 'parent' ),
			static fn(): array => array( 'term:name', 'term:description', 'term:parent' ),
			array(
				'taxonomy' => 'taxonomy',
				'perm'     => array( 'term_id', 'taxonomy' ),
			)
		),
		'aafm/update-post-meta'       => aafm_undo_surface( 'meta', 'post', 'post_id', array( 'post_id', 'meta_key', 'value' ), 'aafm_undo_fields_post_meta_key', array( 'perm' => array( 'post_id', 'meta_key' ) ) ),
		'aafm/delete-post-meta'       => aafm_undo_surface( 'meta', 'post', 'post_id', array( 'post_id', 'meta_key' ), 'aafm_undo_fields_post_meta_key', array( 'perm' => array( 'post_id', 'meta_key' ) ) ),
		'aafm/update-term-meta'       => aafm_undo_surface(
			'meta',
			'term',
			'term_id',
			array( 'taxonomy', 'term_id', 'meta_key', 'value' ),
			'aafm_undo_fields_term_meta_key',
			array(
				'taxonomy' => 'taxonomy',
				'perm'     => array( 'term_id', 'taxonomy', 'meta_key' ),
			)
		),
		'aafm/delete-term-meta'       => aafm_undo_surface(
			'meta',
			'term',
			'term_id',
			array( 'taxonomy', 'term_id', 'meta_key' ),
			'aafm_undo_fields_term_meta_key',
			array(
				'taxonomy' => 'taxonomy',
				'perm'     => array( 'term_id', 'taxonomy', 'meta_key' ),
			)
		),
		'aafm/set-featured-image'     => $seo( array( 'post_id', 'attachment_id' ), static fn(): array => array( 'meta:_thumbnail_id' ) ),
		'aafm/yoast-update-post'      => $seo( array( 'post_id', 'title', 'description', 'focus_keyword', 'canonical', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image', 'robots_noindex', 'robots_nofollow', 'robots_adv' ), $seo_keys( 'aafm_yoast_meta_keys' ) ),
		'aafm/rankmath-update-post'   => $seo( array( 'post_id', 'title', 'description', 'focus_keyword', 'canonical', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image', 'robots' ), $seo_keys( 'aafm_rankmath_meta_keys' ) ),
		'aafm/rankmath-update-schema' => $seo(
			array( 'post_id', 'type', 'schema' ),
			static function ( array $input ): array {
				$type = function_exists( 'aafm_rankmath_validate_schema_type' ) ? aafm_rankmath_validate_schema_type( (string) ( $input['type'] ?? '' ) ) : '';
				return '' === $type ? array() : array( 'meta:rank_math_schema_' . $type );
			}
		),
		'aafm/slim-seo-update-post'   => $seo( array( 'post_id', 'title', 'description', 'facebook_image', 'twitter_image', 'canonical', 'noindex' ), static fn(): array => array( 'meta:slim_seo' ) ),
		'aafm/aioseo-update-post'     => aafm_undo_surface(
			'aioseo',
			'post',
			'post_id',
			array( 'post_id', 'title', 'description', 'canonical', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image', 'robots_noindex', 'robots_nofollow' ),
			static fn(): array => array_map( static fn( string $column ): string => 'aioseo:' . $column, array_values( aafm_undo_aioseo_columns() ) )
		),
		'aafm/replace-sitewide'       => aafm_undo_surface( 'sitewide', '', '', array( 'search', 'replace', 'post_type', 'status', 'dry_run' ), 'aafm_undo_fields_none' ),
	);

	return $surfaces;
}

/**
 * A surface that names no fields up front (a create, or a call that finds its objects as it runs).
 *
 * @return string[]
 */
function aafm_undo_fields_none(): array {
	return array();
}

/**
 * Fields of a trash tool: the status the post leaves and the slug core may rewrite.
 *
 * @return string[]
 */
function aafm_undo_fields_trash(): array {
	return array( 'post:post_status', 'post:post_name' );
}

/**
 * The one meta key of update-post-meta and delete-post-meta, when it passes the post meta validator.
 *
 * @param array<string,mixed> $input Tool input.
 * @return string[]
 */
function aafm_undo_fields_post_meta_key( array $input ): array {
	return aafm_undo_meta_field_ids( array( (string) ( $input['meta_key'] ?? '' ) => true ) );
}

/**
 * The one meta key of update-term-meta and delete-term-meta, when it passes the term meta validator.
 *
 * @param array<string,mixed> $input Tool input.
 * @return string[]
 */
function aafm_undo_fields_term_meta_key( array $input ): array {
	return aafm_undo_meta_field_ids( array( (string) ( $input['meta_key'] ?? '' ) => true ), 'aafm_validate_term_meta_key' );
}

/**
 * Why a native writing tool cannot be undone: the named codes, every other writer being not_covered.
 *
 * @return array<string,string> Ability name to reason code.
 */
function aafm_undo_named_refusals(): array {
	$map = array();
	$set = array(
		'permanent_delete' => array( 'delete-post', 'delete-page', 'delete-comment', 'delete-media', 'delete-user', 'delete-revision', 'delete-menu', 'delete-menu-item', 'wc-delete-product', 'wc-delete-product-variation' ),
		'external_effect'  => array( 'wc-create-order', 'wc-update-order', 'wc-update-order-status', 'wc-create-order-note', 'wc-create-order-refund', 'wc-update-payment-gateway', 'moderate-comment' ),
		'account_data'     => array( 'create-user', 'update-user', 'update-user-meta', 'delete-user-meta', 'acf-update-user-fields', 'wc-create-customer', 'wc-update-customer', 'update-site-settings' ),
		'file_created'     => array( 'upload-media', 'upload-media-from-url' ),
		'no_trash'         => array( 'create-term' ),
	);
	foreach ( $set as $reason => $names ) {
		foreach ( $names as $name ) {
			$map[ 'aafm/' . $name ] = $reason;
		}
	}
	return $map;
}

/**
 * The tools that say they cannot be undone, computed from the registry: every writing ability that
 * is neither covered nor self-recording. Ability name to reason code.
 *
 * @return array<string,string>
 */
function aafm_undo_refusals(): array {
	$named    = aafm_undo_named_refusals();
	$surfaces = aafm_undo_surfaces();
	$self     = aafm_undo_self_recording();
	$refusals = array();
	foreach ( aafm_get_abilities_registry_full() as $name => $definition ) {
		if ( 'read' === ( $definition['risk'] ?? '' ) || isset( $surfaces[ $name ] ) || in_array( $name, $self, true ) ) {
			continue;
		}
		$refusals[ (string) $name ] = $named[ $name ] ?? 'not_covered';
	}
	return $refusals;
}

/**
 * The tools that write their own history entry: they are neither covered nor refused.
 *
 * @return string[]
 */
function aafm_undo_self_recording(): array {
	return array( 'aafm/undo-change' );
}

/**
 * The closed list of reason codes with their messages.
 *
 * @return array<string,string>
 */
function aafm_undo_reasons(): array {
	return array(
		'not_covered'      => __( 'This tool does not record its changes, so they cannot be undone.', 'agent-abilities-for-mcp' ),
		'permanent_delete' => __( 'This permanently deletes the item, so it cannot be undone.', 'agent-abilities-for-mcp' ),
		'external_effect'  => __( 'This tool has effects outside the site content, so it cannot be undone.', 'agent-abilities-for-mcp' ),
		'account_data'     => __( 'This changes account or site settings data, which is not recorded.', 'agent-abilities-for-mcp' ),
		'file_created'     => __( 'This created a file, which is not recorded.', 'agent-abilities-for-mcp' ),
		'no_trash'         => __( 'Terms have no Trash, so creating one cannot be undone here.', 'agent-abilities-for-mcp' ),
		'no_change'        => __( 'The call changed nothing, so there is nothing to undo.', 'agent-abilities-for-mcp' ),
		'history_off'      => __( 'Change history is switched off.', 'agent-abilities-for-mcp' ),
		'capture_failed'   => __( 'This change could not be recorded.', 'agent-abilities-for-mcp' ),
		'too_large'        => __( 'This change is too large to keep for undo.', 'agent-abilities-for-mcp' ),
		'not_utf8'         => __( 'This change holds text that cannot be stored safely for undo.', 'agent-abilities-for-mcp' ),
		'holds_object'     => __( 'This change holds a stored PHP object, which cannot be restored exactly.', 'agent-abilities-for-mcp' ),
		'not_exact'        => __( 'This change cannot be restored exactly.', 'agent-abilities-for-mcp' ),
		'secret_shaped'    => __( 'This key looks like a secret, so no copy of its value is kept.', 'agent-abilities-for-mcp' ),
		'no_vendor_row'    => __( 'The SEO plugin had no stored row for this post, so there is nothing to restore.', 'agent-abilities-for-mcp' ),
		'did_not_finish'   => __( 'This change did not finish being recorded.', 'agent-abilities-for-mcp' ),
		'changed_since'    => __( 'The item changed after this edit, so undoing it could overwrite newer work.', 'agent-abilities-for-mcp' ),
		'expired'          => __( 'This change is older than 30 days and is no longer kept.', 'agent-abilities-for-mcp' ),
		'meta_filtered'    => __( 'A plugin filters this field, so it cannot be restored exactly.', 'agent-abilities-for-mcp' ),
		'not_logged'       => __( 'The undo attempt could not be logged, so nothing was changed.', 'agent-abilities-for-mcp' ),
		'interrupted'      => __( 'Another undo of this change is running or did not finish.', 'agent-abilities-for-mcp' ),
		'already_reverted' => __( 'This change was already reverted.', 'agent-abilities-for-mcp' ),
		'window_passed'    => __( 'Agents can only undo changes from the last 24 hours.', 'agent-abilities-for-mcp' ),
		'needs_owner'      => __( 'Only a site owner can undo this change, from the Changes tab.', 'agent-abilities-for-mcp' ),
		'builder_owned'    => __( 'A page builder now owns this post, so the change cannot be restored.', 'agent-abilities-for-mcp' ),
		'dependency_gone'  => __( 'Something this change depends on no longer exists.', 'agent-abilities-for-mcp' ),
		'trash_off'        => __( 'The Trash is switched off, so this cannot be undone.', 'agent-abilities-for-mcp' ),
		'not_in_trash'     => __( 'The item is no longer in the Trash.', 'agent-abilities-for-mcp' ),
		'would_change'     => __( 'WordPress would store the old value differently now, so a restore would not be exact. Use Revisions instead.', 'agent-abilities-for-mcp' ),
		'status_coerced'   => __( 'Force draft would change the status this undo restores.', 'agent-abilities-for-mcp' ),
		'not_allowed'      => __( 'This undo is not allowed.', 'agent-abilities-for-mcp' ),
		'read_failed'      => __( 'The stored change could not be read.', 'agent-abilities-for-mcp' ),
		'rate_limited'     => __( 'Too many undo calls. Try again later.', 'agent-abilities-for-mcp' ),
	);
}

/**
 * The message for a reason code, or the generic one for a code this list does not know.
 *
 * @param string $reason Reason code.
 * @return string
 */
function aafm_undo_reason_message( string $reason ): string {
	$reasons = aafm_undo_reasons();
	return $reasons[ $reason ] ?? $reasons['not_allowed'];
}

/**
 * The output schema of the `undo` key every native write response carries.
 *
 * @return array<string,mixed>
 */
function aafm_undo_response_schema(): array {
	return array(
		'type'                 => 'object',
		'description'          => __( 'Whether this change can be undone, with the change ids, or the reason it cannot. When only some posts of a find and replace were recorded, undoable is false with a reason and change_ids still lists the ones that can be undone.', 'agent-abilities-for-mcp' ),
		'properties'           => array(
			'undoable'   => array( 'type' => 'boolean' ),
			'change_ids' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'integer' ),
			),
			'reason'     => array( 'type' => 'string' ),
		),
		'required'             => array( 'undoable', 'change_ids' ),
		'additionalProperties' => false,
	);
}
