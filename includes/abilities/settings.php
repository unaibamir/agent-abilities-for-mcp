<?php
/**
 * Site-settings read + write abilities.
 *
 * The update-site-settings ability is the single most dangerous write in the catalog: getting it
 * wrong could change siteurl/home/admin_email and lock out or take over the whole site.
 * It is contained four ways: (1) a fixed allowlist (aafm_allowed_site_settings) that excludes
 * every takeover-class key and is re-stripped after any filter, (2) a closed input schema,
 * (3) a fail-closed execute that re-checks every submitted key against the allowlist and
 * rejects the WHOLE call the moment one is not on it — it never falls back to a raw
 * update_option(), and (4) a non-scalar value (array/object) is refused outright before any
 * write, so a structure can never be stored. The two integer settings are clamped into their
 * legal ranges server-side so a 0 or a 99 can never be persisted.
 *
 * Both abilities gate on manage_options — site settings are administrator data, so the
 * read is held to the same bar WordPress puts on the Settings screen.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_settings_definitions' );

/**
 * Contribute the site-settings definitions to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_settings_definitions( array $registry ): array {
	$registry['aafm/get-site-settings']    = array(
		'label'        => __( 'Get site settings', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Read a small allowlist of site settings: name, tagline, timezone, date and time formats, week start, and posts per page. Requires the manage-options capability. Never the site URL or admin email.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_site_settings',
	);
	$registry['aafm/update-site-settings'] = array(
		'label'        => __( 'Update site settings', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Write a small allowlist of site settings only (name, tagline, timezone, formats, posts per page). It can never change the site URL, admin email, default role, or open registration. Requires manage-options. Off by default.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_update_site_settings',
	);
	return $registry;
}

/**
 * Permission for the site-settings abilities: manage_options.
 *
 * The same capability WordPress gates the Settings screen behind. A caller without it is
 * denied (and audited) before any option is read or written. The check is object-
 * independent, so discovery can fall through to this callback with no server.php case.
 *
 * @return bool
 */
function aafm_perm_manage_options(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Args for aafm/get-site-settings.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_site_settings(): array {
	return array(
		'label'               => __( 'Get site settings', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Read a small allowlist of site settings. Requires the manage-options capability. Never the site URL or admin email.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'settings' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_site_settings',
		'permission_callback' => 'aafm_perm_manage_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/get-site-settings.
 *
 * Returns each allowlisted setting's current value as a key/value map. Only the fixed
 * allowlist is ever read, so the takeover-class keys can never appear in the output.
 *
 * @return array<string,mixed>
 */
function aafm_exec_get_site_settings(): array {
	$out = array();
	foreach ( aafm_allowed_site_settings() as $key ) {
		$out[ $key ] = get_option( $key );
	}
	// The allowlist is never empty, so the map always has keys and stays a JSON object.
	return array( 'settings' => $out );
}

/**
 * Args for aafm/update-site-settings.
 *
 * The schema is closed at the top level (additionalProperties:false) and the nested
 * settings object is validated server-side against the allowlist. The ability is
 * annotated destructive:true — a settings change is permanent and site-wide.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_site_settings(): array {
	return array(
		'label'               => __( 'Update site settings', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Write a small allowlist of site settings only. It can never change the site URL, admin email, default role, or open registration. Requires manage-options.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'settings' => array(
					'type' => 'object',
				),
			),
			'required'             => array( 'settings' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'settings' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_update_site_settings',
		'permission_callback' => 'aafm_perm_manage_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Execute aafm/update-site-settings.
 *
 * Fail-closed: every submitted key is checked against the allowlist BEFORE any write, and
 * a single non-allowlisted key rejects the whole call — there is no partial apply and no
 * raw update_option() of an arbitrary key. A non-scalar value (array/object) is likewise
 * refused outright before any write, so a structure can never be stored. Values are sanitized
 * per type; the two integers are clamped into their legal ranges server-side (posts_per_page
 * floored to >=1 and capped at 100 so a 0 cannot break WP_Query; start_of_week clamped to 0..6).
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_site_settings( array $input ) {
	$settings = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : array();
	if ( array() === $settings ) {
		return aafm_generic_error();
	}

	$allow = aafm_allowed_site_settings();
	// Fail closed: any key not on the allowlist rejects the whole call before a single write.
	foreach ( array_keys( $settings ) as $key ) {
		if ( ! in_array( (string) $key, $allow, true ) ) {
			return aafm_generic_error();
		}
	}

	// Scalar-only: a non-scalar value (array/object) is refused outright, before any write,
	// so the agent can never store a structure and the execute never hits an Array-to-string.
	foreach ( $settings as $value ) {
		if ( ! is_scalar( $value ) ) {
			return aafm_generic_error();
		}
	}

	$updated = array();
	foreach ( $settings as $key => $value ) {
		$key = (string) $key;
		update_option( $key, aafm_sanitize_site_setting( $key, $value ) );
		// Read the value back so the agent sees ground truth after the clamp/sanitize.
		$updated[ $key ] = get_option( $key );
	}

	return array( 'settings' => $updated );
}

/**
 * Sanitize and clamp one allowlisted site-setting value by its type.
 *
 * Note absint() is deliberately NOT used for the integer bounds: it returns the ABSOLUTE value,
 * so absint('-3') is 3, not 0 — it would silently flip a negative into a live limit. The
 * floor/cap form (min(max, max(floor, (int) $raw))) clamps correctly. The string settings
 * run through sanitize_text_field.
 *
 * @param string $key   An allowlisted settings key (the caller has already proven this).
 * @param mixed  $value Raw submitted value.
 * @return int|string Clamped/sanitized value ready for update_option.
 */
function aafm_sanitize_site_setting( string $key, $value ) {
	if ( 'posts_per_page' === $key ) {
		// Floor to >=1 (a 0 breaks WP_Query) and cap at 100 to avoid an unbounded query.
		return min( 100, max( 1, (int) $value ) );
	}
	if ( 'start_of_week' === $key ) {
		// WordPress stores 0 (Sunday) .. 6 (Saturday); clamp so a 99 can never be persisted.
		return min( 6, max( 0, (int) $value ) );
	}
	return sanitize_text_field( (string) $value );
}
