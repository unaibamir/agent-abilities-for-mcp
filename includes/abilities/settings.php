<?php
/**
 * Site-settings read + write abilities.
 *
 * The update-site-settings ability is the single most dangerous write in the catalog: getting it
 * wrong could change siteurl/home/admin_email and lock out or take over the whole site.
 * It is contained four ways: (1) a fixed allowlist (aafm_allowed_site_settings) that excludes
 * every takeover-class key and is re-stripped after any filter, (2) a closed input schema,
 * (3) a fail-closed execute that re-checks every submitted key against the allowlist and
 * rejects the WHOLE call the moment one is not on it - it never falls back to a raw
 * update_option(), and (4) a non-scalar value (array/object) is refused outright before any
 * write, so a structure can never be stored. The two integer settings are clamped into their
 * legal ranges server-side so a 0 or a 99 can never be persisted.
 *
 * Both abilities gate on manage_options - site settings are administrator data, so the
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
		'description'  => __( 'Read a small allowlist of site settings: name, tagline, timezone, date and time formats, week start, posts per page, and the static homepage (show_on_front, page_on_front, page_for_posts). Requires the manage-options capability. Never the site URL or admin email.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'site',
		'args_builder' => 'aafm_args_get_site_settings',
	);
	$registry['aafm/update-site-settings'] = array(
		'label'        => __( 'Update site settings', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Write a small allowlist of site settings, passed as a settings object whose keys are the setting names and values the new values. Accepted keys: blogname (site name), blogdescription (tagline), timezone_string, date_format, time_format, start_of_week (0-6), posts_per_page (1-100), and the static homepage: show_on_front (posts or page), page_on_front and page_for_posts (a published page id, or 0 for none). String values are sanitized; the two integer settings are clamped into their legal ranges. Any unrecognized key rejects the entire call with nothing written. The homepage keys are checked together before anything is written (the front page and posts page must differ, and show_on_front page needs a front page), then written in the order page_on_front, page_for_posts, show_on_front; if one does not land, the call returns an error listing the keys that landed, were unchanged and failed. It can never change the site URL, admin email, default role, or open registration. Requires manage-options. Off by default.', 'agent-abilities-for-mcp' ),
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
		'label'               => aafm_ability_label( 'aafm/get-site-settings' ),
		'description'         => aafm_ability_description( 'aafm/get-site-settings' ),
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
				'idempotent'  => true,
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
	// aafm_allowed_site_settings() is filterable (helpers.php:583-592). A site that hooks the
	// filter down to an empty list - e.g. add_filter( 'aafm_allowed_site_settings',
	// '__return_empty_array' ), the natural way to stop exposing settings without disabling
	// the ability - makes $out empty. An empty PHP array encodes as [], not {}, and the
	// adapter passes the return value verbatim into structuredContent, which would violate the
	// declared object schema. Cast so the map still JSON-encodes to {} in that case.
	return array( 'settings' => array() === $out ? (object) $out : $out );
}

/**
 * Args for aafm/update-site-settings.
 *
 * The schema is closed at the top level (additionalProperties:false) and the nested
 * settings object is validated server-side against the allowlist. The ability is
 * annotated destructive:true - a settings change is permanent and site-wide.
 *
 * @return array<string,mixed>
 */
function aafm_args_update_site_settings(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/update-site-settings' ),
		'description'         => aafm_ability_description( 'aafm/update-site-settings' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'settings' => array(
					'type'        => 'object',
					'description' => __( 'A map of setting name to new value. Allowed keys: blogname, blogdescription, timezone_string, date_format, time_format, start_of_week, posts_per_page, show_on_front, page_on_front, page_for_posts. Any other key rejects the whole call.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'settings' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'settings'  => array( 'type' => 'object' ),
				'landed'    => array(
					'type'        => 'array',
					'description' => __( 'Present only when a homepage key was sent: the keys that were saved.', 'agent-abilities-for-mcp' ),
					'items'       => array( 'type' => 'string' ),
				),
				'unchanged' => array(
					'type'        => 'array',
					'description' => __( 'Present only when a homepage key was sent: the keys that already held the value.', 'agent-abilities-for-mcp' ),
					'items'       => array( 'type' => 'string' ),
				),
				'failed'    => array(
					'type'        => 'array',
					'description' => __( 'Present only when a homepage key was sent: the keys that were not saved. Always empty on success.', 'agent-abilities-for-mcp' ),
					'items'       => array( 'type' => 'string' ),
				),
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
 * a single non-allowlisted key rejects the whole call - there is no partial apply and no
 * raw update_option() of an arbitrary key. A non-scalar value (array/object) is likewise
 * refused outright before any write, so a structure can never be stored. Values are sanitized
 * per type; the two integers are clamped into their legal ranges server-side (posts_per_page
 * floored to >=1 and capped at 100 so a 0 cannot break WP_Query; start_of_week clamped to 0..6).
 * A value core's own sanitize_option() would silently revert (e.g. an invalid timezone_string)
 * is caught with a dry run before any write, so the call errors instead of reporting a
 * success that never actually changed the setting.
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
	// The error names the rejected key and the allowed set so the agent can correct the call.
	foreach ( array_keys( $settings ) as $key ) {
		if ( ! in_array( (string) $key, $allow, true ) ) {
			return new WP_Error(
				'aafm_setting_not_allowed',
				sprintf(
					/* translators: 1: the rejected setting key, 2: the comma-separated list of allowed setting keys. */
					__( 'The setting "%1$s" cannot be changed. Allowed settings are: %2$s.', 'agent-abilities-for-mcp' ),
					(string) $key,
					implode( ', ', $allow )
				)
			);
		}
	}

	// The static homepage keys are one state: validate them together against the stored values,
	// then move them to the end in the fixed write order, so show_on_front is written last and the
	// site never shows a static page that was not saved first.
	$homepage = array_values( array_intersect( aafm_static_homepage_keys(), array_map( 'strval', array_keys( $settings ) ) ) );
	if ( array() !== $homepage ) {
		$checked = aafm_static_homepage_checked( $settings );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$settings = array_merge( array_diff_key( $settings, $checked ), $checked );
	}

	// Scalar-only with a string-type guard: a non-scalar value (array/object) is refused outright,
	// and a boolean is refused for the string-typed settings (sending true/false for a name or
	// format is almost always a mistake and would silently store "1"/""). The two integer
	// settings DO accept a boolean, since the int clamp turns it into a sane 0/1 in range.
	$integer_keys = array( 'posts_per_page', 'start_of_week' );
	foreach ( $settings as $key => $value ) {
		if ( ! is_scalar( $value ) ) {
			return aafm_generic_error();
		}
		if ( is_bool( $value ) && ! in_array( (string) $key, $integer_keys, true ) ) {
			return new WP_Error(
				'aafm_setting_bad_type',
				sprintf(
					/* translators: %s: the setting key that received a boolean value. */
					__( 'The setting "%s" expects a text value, not true/false.', 'agent-abilities-for-mcp' ),
					(string) $key
				)
			);
		}
	}

	// Fourth pass: some allowlisted keys (timezone_string) are additionally validated by
	// WordPress's own sanitize_option() at write time. On an invalid value core does not
	// error out visibly to us - it silently REVERTS to whatever the option already held.
	// Left unchecked this ability would report success on a write that never happened.
	// Dry-run sanitize_option() against our own sanitized value before writing anything,
	// so a value core would reject fails the whole call instead - keeping the same
	// no-partial-apply guarantee as the allowlist and type checks above.
	foreach ( $settings as $key => $value ) {
		$key      = (string) $key;
		$intended = aafm_sanitize_site_setting( $key, $value );
		$current  = get_option( $key );
		if ( $intended === $current ) {
			continue; // No-op write; nothing for core to silently revert.
		}
		if ( sanitize_option( $key, $intended ) === $current ) {
			// sanitize_option() landing on the current stored value means one of two things, and they
			// need opposite handling. Core may have ESCAPED a valid value into its stored form (core
			// stores blogname "Bob's Store" as "Bob&#039;s Store"), which is a genuine no-op, or core
			// may have REJECTED an invalid value and reverted to current. Decode the stored value: if
			// it matches the intended value the caller sent, it was a valid escape, so skip it as a
			// no-op. Only a real revert, where the decoded current still differs from intended, is an
			// error.
			if ( is_string( $current ) && is_string( $intended )
				&& wp_specialchars_decode( $current, ENT_QUOTES ) === $intended ) {
				continue;
			}
			return new WP_Error(
				'aafm_setting_rejected',
				sprintf(
					/* translators: %s: the setting key WordPress rejected as invalid. */
					__( 'The value for "%s" is not valid and was not applied.', 'agent-abilities-for-mcp' ),
					$key
				)
			);
		}
	}

	// A stale persistent cache can make update_option() skip or misdirect a write, because it decides
	// from get_option()'s answer, which comes from a cache copy before the row. So before anything is
	// written, every cache copy of each key has to agree with its row (see
	// aafm_option_row_if_cache_agrees() for the rules). A refusal on any key refuses the whole request
	// before the first write. The check runs after the dry-run's reads on purpose, so a notoptions
	// entry that a failed read there leaves is refused; it must stay the last read before the first
	// write.
	foreach ( array_keys( $settings ) as $key ) {
		if ( null === aafm_option_row_if_cache_agrees( (string) $key ) ) {
			return aafm_generic_error();
		}
	}

	$updated = array();
	$report  = array(
		'landed'    => array(),
		'unchanged' => array(),
		'failed'    => array(),
	);
	foreach ( $settings as $key => $value ) {
		$key = (string) $key;
		if ( array() !== array_intersect( $report['failed'], $homepage ) && in_array( $key, $homepage, true ) ) {
			// A homepage key did not land: the homepage keys after it are not written.
			$report['failed'][] = $key;
			continue;
		}
		$intended = aafm_sanitize_site_setting( $key, $value );
		$outcome  = aafm_option_write( $key, $intended );
		if ( array() !== $homepage ) {
			// Read back from the row, never the cache or the option filters, so the report is what is stored.
			$row = aafm_option_row( $key );
			// Compared with core's canonical form, the one the row holds (blogname is stored escaped).
			$stored = $row['ok'] && $row['found'] && aafm_option_value_matches( $row['value'], sanitize_option( $key, $intended ) );
			if ( $stored && AAFM_WRITE_WRITTEN === $outcome['status'] ) {
				$report['landed'][] = $key;
			} elseif ( $stored && AAFM_WRITE_UNCHANGED === $outcome['status'] ) {
				$report['unchanged'][] = $key;
			} else {
				$report['failed'][] = $key;
			}
		}
		// Report the value the option layer gives back right after the write, rather than echoing
		// the submission. That is what makes the clamp, the sanitize, and core's own escaping of a
		// stored value visible to the agent, which is why the read-back is here.
		//
		// It is NOT a guarantee that the response equals the row just written. get_option() runs
		// the option_{$key} filters, so whatever is hooked there decides what comes back. That is
		// the whole bound, and it holds for any filtered option. A translation layer is the case
		// that turns up in practice.
		//
		// DOCUMENTED by the vendor, not inferred from our own reproduction: WPML String
		// Translation "filters theme/plugin setting options in order to return translations of
		// their values in the current language", and its own docs name a site's tagline as one of
		// the strings it handles. So on such a site this reports the current language's
		// translation. WPML also documents wpml_unfiltered_admin_string as the supported way to
		// read past its filters; this ability deliberately does not reach for it, because the
		// bound above is not one vendor's to answer for, and a read-back that special-cases a
		// vendor is a worse promise than one that admits its limit. See
		// wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference and
		// wpml.org/documentation/support/wpml-tables.
		//
		// OBSERVED ONLY, on one clone running String Translation 3.5.3 with SitePress 4.9.6:
		// blogname and blogdescription come back one write behind, and the next request reads the
		// new value. The one-write-behind sequencing is measured here, not promised by WPML.
		$updated[ $key ] = get_option( $key );
	}

	if ( array() === $homepage ) {
		return array( 'settings' => $updated );
	}
	if ( array() !== $report['failed'] ) {
		return new WP_Error(
			'aafm_settings_partial',
			sprintf(
				/* translators: 1: keys that were saved, 2: keys that already held the value, 3: keys that were not saved. "none" when a list is empty. */
				__( 'Not every setting was saved. Saved: %1$s. Already set: %2$s. Not saved: %3$s. Check the homepage settings before trying again.', 'agent-abilities-for-mcp' ),
				array() === $report['landed'] ? __( 'none', 'agent-abilities-for-mcp' ) : implode( ', ', $report['landed'] ),
				array() === $report['unchanged'] ? __( 'none', 'agent-abilities-for-mcp' ) : implode( ', ', $report['unchanged'] ),
				implode( ', ', $report['failed'] )
			),
			array_merge( array( 'status' => 500 ), $report )
		);
	}
	return array_merge( array( 'settings' => $updated ), $report );
}

/**
 * The static homepage settings, in the order they are written: show_on_front goes last.
 *
 * @return list<string>
 */
function aafm_static_homepage_keys(): array {
	return array( 'page_on_front', 'page_for_posts', 'show_on_front' );
}

/**
 * Validate the requested static homepage settings as one state, against the stored values for the
 * keys the request leaves out. A page id must be 0 or a published page; the front page and the posts
 * page must differ; and show_on_front page needs a front page.
 *
 * @param array<string,mixed> $settings The request's settings map.
 * @return array<string,int|string>|WP_Error The requested homepage keys, cleaned, in write order.
 */
function aafm_static_homepage_checked( array $settings ) {
	$clean = array();
	foreach ( aafm_static_homepage_keys() as $key ) {
		if ( ! array_key_exists( $key, $settings ) ) {
			continue;
		}
		$value = $settings[ $key ];
		if ( 'show_on_front' === $key ) {
			if ( ! in_array( $value, array( 'posts', 'page' ), true ) ) {
				return new WP_Error( 'aafm_setting_bad_type', __( 'show_on_front must be "posts" or "page", so nothing was changed.', 'agent-abilities-for-mcp' ), array( 'status' => 400 ) );
			}
			$clean[ $key ] = $value;
			continue;
		}
		if ( ! ( is_int( $value ) && $value >= 0 ) && ! ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) ) {
			return new WP_Error(
				'aafm_setting_bad_type',
				/* translators: %s: the setting key. */
				sprintf( __( 'The setting "%s" takes a page id, or 0 for none, so nothing was changed.', 'agent-abilities-for-mcp' ), $key ),
				array( 'status' => 400 )
			);
		}
		$id = (int) $value;
		if ( $id > 0 && ! aafm_homepage_page_published( $id ) ) {
			return aafm_homepage_not_published_error( $key );
		}
		$clean[ $key ] = $id;
	}

	// The stored values come from their rows, so a stale cache copy cannot decide this.
	$stored = array();
	foreach ( aafm_static_homepage_keys() as $key ) {
		$row = aafm_option_row( $key );
		if ( ! $row['ok'] ) {
			return aafm_generic_error();
		}
		$stored[ $key ] = $row['found'] && is_scalar( $row['value'] ) ? (string) $row['value'] : '';
	}
	$front = $clean['page_on_front'] ?? (int) $stored['page_on_front'];
	$posts = $clean['page_for_posts'] ?? (int) $stored['page_for_posts'];
	$show  = $clean['show_on_front'] ?? $stored['show_on_front'];
	if ( $front > 0 && $front === $posts ) {
		return new WP_Error( 'aafm_homepage_same_page', __( 'The front page and the posts page must be different pages, so nothing was changed.', 'agent-abilities-for-mcp' ), array( 'status' => 400 ) );
	}
	if ( 'page' === $show && 0 === $front && ( isset( $clean['show_on_front'] ) || isset( $clean['page_on_front'] ) ) ) {
		return new WP_Error( 'aafm_homepage_no_front_page', __( 'Showing a static front page needs page_on_front set to a published page, so nothing was changed.', 'agent-abilities-for-mcp' ), array( 'status' => 400 ) );
	}
	// Turning on a static front page that the request does not name relies on the stored one, which
	// may have been trashed or unpublished since.
	if ( isset( $clean['show_on_front'] ) && 'page' === $show && ! isset( $clean['page_on_front'] ) && ! aafm_homepage_page_published( (int) $front ) ) {
		return aafm_homepage_not_published_error( 'page_on_front' );
	}
	return $clean;
}

/**
 * Whether an id names a published page.
 *
 * @param int $id Post id.
 * @return bool
 */
function aafm_homepage_page_published( int $id ): bool {
	$page = $id > 0 ? aafm_exact_object( 'post', $id ) : null;
	return $page instanceof WP_Post && 'page' === $page->post_type && 'publish' === $page->post_status;
}

/**
 * The refusal for a homepage key that does not name a published page.
 *
 * @param string $key The setting key.
 * @return WP_Error
 */
function aafm_homepage_not_published_error( string $key ): WP_Error {
	return new WP_Error(
		'aafm_homepage_not_a_published_page',
		/* translators: %s: the setting key. */
		sprintf( __( 'The setting "%s" must name a published page, or 0 for none, so nothing was changed.', 'agent-abilities-for-mcp' ), $key ),
		array( 'status' => 400 )
	);
}

/**
 * Sanitize and clamp one allowlisted site-setting value by its type.
 *
 * Note absint() is deliberately NOT used for the integer bounds: it returns the ABSOLUTE value,
 * so absint('-3') is 3, not 0 - it would silently flip a negative into a live limit. The
 * floor/cap form (min(max, max(floor, (int) $raw))) clamps correctly. The string settings
 * run through aafm_sanitize_plain_text.
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
	if ( 'page_on_front' === $key || 'page_for_posts' === $key ) {
		return (int) $value; // Already proven a page id by aafm_static_homepage_checked().
	}
	// The site identity is the most feed-visible text on the whole site: blogname and
	// blogdescription go straight into the RSS and Atom headers, so a raw NUL here breaks every
	// feed document, not one item in it. Same defect as a post title, one level up.
	return aafm_sanitize_plain_text( (string) $value );
}
