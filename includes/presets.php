<?php
/**
 * Access presets: named narrowings a connection can carry. Each is a rule over the registry (risk,
 * subject, read classification), so a new ability is classified without a list edit. A preset only
 * ever narrows what the allowlist and the site-wide switches already allow; Custom adds no limit.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The four presets, in display order, which is also the rank order (narrowest first).
 *
 * @return array<string,array{label:string,summary:string}>
 */
function aafm_access_presets(): array {
	return array(
		'read_only'      => array(
			'label'   => __( 'Read only', 'agent-abilities-for-mcp' ),
			'summary' => __( 'Can look at your content, never change it.', 'agent-abilities-for-mcp' ),
		),
		'content_editor' => array(
			'label'   => __( 'Content editor', 'agent-abilities-for-mcp' ),
			'summary' => __( 'Posts, pages, media and categories or tags. No deletes, users, settings or comments.', 'agent-abilities-for-mcp' ),
		),
		'shop_manager'   => array(
			'label'   => __( 'Shop manager', 'agent-abilities-for-mcp' ),
			'summary' => __( 'Everything in Content editor plus WooCommerce, except deletes and the nine money and authority tools.', 'agent-abilities-for-mcp' ),
		),
		'custom'         => array(
			'label'   => __( 'Custom', 'agent-abilities-for-mcp' ),
			'summary' => __( 'Everything you have switched on, limited only by the approving user\'s role.', 'agent-abilities-for-mcp' ),
		),
	);
}

/**
 * Normalize a stored or submitted preset.
 *
 * @param mixed  $value    Raw value.
 * @param string $fallback Returned for anything that is not a known preset.
 * @return string '' for Custom, a preset slug, or $fallback.
 */
function aafm_preset_normalize( $value, string $fallback = '' ): string {
	if ( ! is_string( $value ) ) {
		return $fallback;
	}
	if ( '' === $value || 'custom' === $value ) {
		return '';
	}
	return isset( aafm_access_presets()[ $value ] ) ? $value : $fallback;
}

/**
 * How wide a preset is: Read only 1, Content editor 2, Shop manager 3, Custom 4, unknown 0.
 *
 * @param string $preset Preset slug, '' for Custom.
 * @return int
 */
function aafm_preset_rank( string $preset ): int {
	$ranks = array(
		'read_only'      => 1,
		'content_editor' => 2,
		'shop_manager'   => 3,
		''               => 4,
		'custom'         => 4,
	);
	return $ranks[ $preset ] ?? 0;
}

/**
 * The preset an approver may actually give: an owner keeps what they picked; anyone else gets no
 * wider than the site default.
 *
 * @param string $preset   Requested preset.
 * @param bool   $by_owner Whether the approver can manage_options.
 * @return string
 */
function aafm_preset_clamp( string $preset, bool $by_owner ): string {
	$default = aafm_default_preset();
	return ! $by_owner && aafm_preset_rank( $preset ) > aafm_preset_rank( $default ) ? $default : $preset;
}

/**
 * Whether a preset lets a connection reach an ability. Fails closed for unknown slugs and names.
 *
 * @param string $preset       Preset slug, '' for Custom.
 * @param string $ability_name Native, bridged or foreign ability name.
 * @return bool
 */
function aafm_preset_permits( string $preset, string $ability_name ): bool {
	if ( '' === $preset ) {
		return true; // Custom: the allowlist and the site-wide switches already decide.
	}
	if ( 'read_only' === $preset ) {
		return aafm_ability_is_read( $ability_name ); // Native, bridged and unknown names, all fail closed.
	}
	$def = aafm_get_abilities_registry_full()[ $ability_name ] ?? null;
	if ( null === $def || ! in_array( $preset, array( 'content_editor', 'shop_manager' ), true ) ) {
		return false; // Bridged names, unknown names and unknown slugs: only Custom reaches them.
	}
	// Permanent deletes and the nine money and authority tools are only ever reached through Custom.
	if ( 'destructive' === (string) ( $def['risk'] ?? '' ) || aafm_ability_is_high_risk( $ability_name ) ) {
		return false;
	}
	$subject = (string) ( $def['subject'] ?? '' );
	if ( 'shop_manager' === $preset && 'woocommerce' === $subject ) {
		return true;
	}
	// replace-sitewide rewrites text across the whole site, so it stays out. The two site reads let an
	// agent target custom post types and taxonomies.
	return ( in_array( $subject, array( 'content', 'media', 'taxonomies' ), true ) && 'aafm/replace-sitewide' !== $ability_name )
		|| in_array( $ability_name, array( 'aafm/get-post-types', 'aafm/get-taxonomies' ), true );
}

/**
 * How many enabled tools a preset reaches, for the "up to these" wording.
 *
 * @param string $preset Preset slug, '' for Custom.
 * @return array{allowed:int,enabled:int}
 */
function aafm_preset_count( string $preset ): array {
	$native  = aafm_get_enabled_abilities();
	$bridged = aafm_get_enabled_bridged_abilities();
	$permits = static fn( string $name ): bool => aafm_preset_permits( $preset, $name );
	return array(
		'allowed' => count( array_filter( $native, $permits ) ) + count( array_filter( $bridged, $permits ) ),
		'enabled' => count( $native ) + count( $bridged ),
	);
}

/**
 * The site default for new connections. Absent means Custom; an unreadable or malformed row means
 * Read only, the narrowest ceiling.
 *
 * @return string
 */
function aafm_default_preset(): string {
	$row = aafm_policy_row( 'aafm_oauth_default_preset' );
	if ( ! $row['ok'] ) {
		return 'read_only';
	}
	if ( ! $row['found'] ) {
		return '';
	}
	return aafm_preset_normalize( $row['value'], 'read_only' );
}
