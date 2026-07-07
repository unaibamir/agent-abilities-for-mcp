<?php
/**
 * "Abilities from other plugins" admin section + its AJAX save.
 *
 * Discovers WordPress Abilities registered by OTHER plugins (via aafm_discover_foreign_abilities())
 * and lets the operator opt each one in. An opted-in slug is stored in aafm_enabled_bridged_abilities
 * (separate from the native aafm_enabled_abilities) and registered as a governed aafm-bridge/* wrapper.
 *
 * The markup mirrors includes/admin/integrations.php EXACTLY (same card / switch / badge / filter
 * classes) but posts to its OWN action (aafm_save_bridged_abilities) with its own field name
 * (bridged_abilities[]) and its own checked source (aafm_get_enabled_bridged_abilities()), so it can
 * never write into the native option. The only new classes are a .aafm-bridge scoping wrapper and an
 * .is-destructive row modifier.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Render the "Abilities from other plugins" section.
 *
 * @return void
 */
function aafm_render_bridge_directory(): void {
	echo '<div class="aafm-bridge aafm-integrations">';

	echo '<p class="aafm-page-lede">' . esc_html__( 'Other plugins can register their own WordPress Abilities. Any you turn on here becomes a governed MCP tool, wrapped in the same audit log, rate limit, and permission checks as everything else on this site. Each one stays off until you switch it on.', 'agent-abilities-for-mcp' ) . '</p>';

	// WP < 6.9 has no Abilities registry to read.
	if ( ! function_exists( 'wp_get_abilities' ) ) {
		echo wp_kses(
			aafm_get_notice_html(
				'warning',
				__( 'This needs the WordPress Abilities API, added in WordPress 6.9. Update WordPress to discover abilities from other plugins.', 'agent-abilities-for-mcp' )
			),
			aafm_admin_allowed_html()
		);
		echo '</div>';
		return;
	}

	$groups  = aafm_discover_foreign_abilities();
	$enabled = aafm_get_enabled_bridged_abilities();

	// Enabled slugs that are no longer discoverable (their host plugin is inactive) still need a
	// home, so union them in as a disabled "Unavailable" group rather than dropping them silently.
	$discovered_slugs = array();
	foreach ( $groups as $group ) {
		foreach ( $group['abilities'] as $ability ) {
			$discovered_slugs[] = (string) $ability['slug'];
		}
	}
	$orphans = array_values( array_diff( $enabled, $discovered_slugs ) );

	if ( empty( $groups ) && empty( $orphans ) ) {
		echo wp_kses(
			aafm_get_notice_html(
				'info',
				__( 'No abilities from other plugins yet. Activate a plugin that registers WordPress Abilities and they will show up here.', 'agent-abilities-for-mcp' )
			),
			aafm_admin_allowed_html()
		);
		echo '</div>';
		return;
	}

	echo '<div class="aafm-integrations-disclaimer">';
	echo wp_kses(
		aafm_get_notice_html(
			'warning',
			__( 'These abilities are written by other plugins, so what they read and change is up to them. A bridged ability can still only touch what the connected account\'s WordPress role allows, and every call is recorded in the activity log. Turn on only what you trust the connected agent to run.', 'agent-abilities-for-mcp' )
		),
		aafm_admin_allowed_html()
	);
	echo '</div>';

	aafm_render_bridge_collision_notice();

	// One global search + risk filter across every group. Bound by admin.js #bindBridgeFilter,
	// which iterates rows across all groups and auto-opens groups that contain a match.
	aafm_render_bridge_filter();

	echo '<form id="aafm-bridge-form" class="aafm-integrations-cards">';
	wp_nonce_field( 'aafm_admin', 'aafm_nonce' );

	foreach ( $groups as $ns => $group ) {
		aafm_render_bridge_group( (string) $ns, $group, $enabled, false );
	}

	if ( ! empty( $orphans ) ) {
		aafm_render_bridge_orphan_group( $orphans );
	}

	echo '<div class="aafm-savebar"><button type="submit" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save changes', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-save-status" aria-live="polite"></span></div>';
	echo '</form>';

	echo '</div>'; // .aafm-bridge
}

/**
 * Warn inline when two enabled foreign slugs normalized to the same wrapper name.
 *
 * The registration pass records any losing slug in aafm_bridge_collisions() and skips it so the
 * winner's wrapper is not overwritten. Surfacing it here keeps the clash from vanishing: the
 * operator can see which enabled ability did not become a tool, and why.
 *
 * @return void
 */
function aafm_render_bridge_collision_notice(): void {
	$collisions = function_exists( 'aafm_bridge_collisions' ) ? aafm_bridge_collisions() : array();
	if ( empty( $collisions ) ) {
		return;
	}

	$lines = array();
	foreach ( $collisions as $loser => $info ) {
		$lines[] = sprintf(
			/* translators: 1: skipped ability slug, 2: winning ability slug, 3: shared MCP tool name. */
			__( '"%1$s" was skipped because it maps to the same tool name as "%2$s" (%3$s).', 'agent-abilities-for-mcp' ),
			(string) $loser,
			(string) ( $info['winner'] ?? '' ),
			(string) ( $info['wrapper'] ?? '' )
		);
	}

	$message = __( 'Some enabled abilities share a normalized tool name and could not all be bridged:', 'agent-abilities-for-mcp' )
		. ' ' . implode( ' ', $lines );

	echo wp_kses(
		aafm_get_notice_html( 'warning', $message ),
		aafm_admin_allowed_html()
	);
}

/**
 * The global search + All / Read Only / Write filter for the bridge directory.
 *
 * Reuses the integrations filter markup exactly (.aafm-integration-filter, .aafm-filter-risk,
 * .aafm-filter-btn with aria-pressed) but carries a bridge id so #bindBridgeFilter binds it as a
 * single global filter across every group. Destructive rows also satisfy the "Write" filter.
 *
 * @return void
 */
function aafm_render_bridge_filter(): void {
	$input_id = 'aafm-bridge-search';

	echo '<div class="aafm-integration-filter aafm-bridge-filter" id="aafm-bridge-filter">';

	printf(
		'<label class="screen-reader-text" for="%1$s">%2$s</label>',
		esc_attr( $input_id ),
		esc_html__( 'Search abilities', 'agent-abilities-for-mcp' )
	);
	printf(
		'<input type="search" id="%1$s" class="aafm-integration-search" placeholder="%2$s" autocomplete="off">',
		esc_attr( $input_id ),
		esc_attr__( 'Search abilities…', 'agent-abilities-for-mcp' )
	);

	echo '<div class="aafm-filter-risk" role="group" aria-label="' . esc_attr__( 'Filter by risk', 'agent-abilities-for-mcp' ) . '">';
	$risks = array(
		'all'   => __( 'All', 'agent-abilities-for-mcp' ),
		'read'  => __( 'Read Only', 'agent-abilities-for-mcp' ),
		'write' => __( 'Write', 'agent-abilities-for-mcp' ),
	);
	foreach ( $risks as $value => $label ) {
		printf(
			'<button type="button" class="aafm-filter-btn%1$s" data-filter-risk="%2$s" aria-pressed="%3$s">%4$s</button>',
			'all' === $value ? ' is-active' : '',
			esc_attr( $value ),
			'all' === $value ? 'true' : 'false',
			esc_html( $label )
		);
	}
	echo '</div>';

	echo '</div>';
}

/**
 * Turn a source namespace slug into a readable group heading.
 *
 * The namespace comes off the ability slug lowercased and hyphenated (e.g. "events-manager").
 * Title-case each word for display: "events-manager" -> "Events Manager". We cannot recover a
 * plugin's exact brand casing (e.g. "WooCommerce") from a lowercase slug, so Title Case is the
 * honest generic transform.
 *
 * @param string $ns Source namespace.
 * @return string
 */
function aafm_bridge_display_label( string $ns ): string {
	$words = str_replace( array( '-', '_' ), ' ', $ns );
	$words = trim( (string) preg_replace( '/\s+/', ' ', $words ) );
	return '' === $words ? $ns : ucwords( $words );
}

/**
 * Render one source-plugin group (a collapsed accordion card) with its ability rows.
 *
 * @param string              $ns       Source namespace (the group key).
 * @param array<string,mixed> $group    Group data from aafm_discover_foreign_abilities().
 * @param array<int,string>   $enabled  Enabled foreign slugs.
 * @param bool                $disabled True to render every row disabled (the orphan group).
 * @return void
 */
function aafm_render_bridge_group( string $ns, array $group, array $enabled, bool $disabled ): void {
	$rows  = isset( $group['abilities'] ) && is_array( $group['abilities'] ) ? $group['abilities'] : array();
	$label = aafm_bridge_display_label( (string) ( $group['label'] ?? $ns ) );

	$read  = 0;
	$write = 0;
	foreach ( $rows as $row ) {
		if ( 'read' === (string) ( $row['risk'] ?? '' ) ) {
			++$read;
		} else {
			++$write; // Destructive counts as a write.
		}
	}

	printf(
		'<details class="aafm-card aafm-integration-card aafm-integration-%1$s%2$s">',
		esc_attr( sanitize_html_class( $ns ) ),
		$disabled ? ' is-disabled' : ''
	);

	// One neutral glyph for every group (the integrations "plug"); no per-plugin logos/brand colors.
	echo '<summary class="aafm-card-head">';
	echo '<span class="icon">';
	echo wp_kses( aafm_icon( 'integrations' ), aafm_svg_allowed_html() );
	echo '</span>';
	echo '<h2>' . esc_html( $label ) . '</h2>';

	echo '<span class="abilities-count">';
	printf(
		'<p class="aafm-integration-count">%s</p>',
		esc_html(
			sprintf(
				/* translators: 1: total abilities, 2: read count, 3: write count. */
				_n( '%1$d ability · %2$d read, %3$d write', '%1$d abilities · %2$d read, %3$d write', count( $rows ), 'agent-abilities-for-mcp' ),
				count( $rows ),
				$read,
				$write
			)
		)
	);
	echo '</span>';
	echo '</summary>';

	echo '<div class="aafm-integration-body">';

	// Per-group bulk controls: flip every ability in this plugin at once. Not on the orphan
	// group (its rows are disabled). Bulk enable is a deliberate action, so it turns on
	// destructive abilities too without the per-row confirm; the DESTRUCTIVE badge still warns.
	if ( ! $disabled && $rows ) {
		echo '<div class="aafm-bridge-bulk">';
		echo '<button type="button" class="aafm-btn aafm-btn-secondary" data-bridge-bulk="enable">' . esc_html__( 'Enable all', 'agent-abilities-for-mcp' ) . '</button> ';
		echo '<button type="button" class="aafm-btn aafm-btn-secondary" data-bridge-bulk="disable">' . esc_html__( 'Disable all', 'agent-abilities-for-mcp' ) . '</button>';
		echo '</div>';
	}

	echo '<div class="aafm-card aafm-ability-list">';
	foreach ( $rows as $row ) {
		aafm_render_bridge_ability_row( $row, $enabled, $disabled );
	}
	echo '</div>';
	echo '</div>';

	echo '</details>';
}

/**
 * Render the "Unavailable" group for enabled slugs whose host plugin is no longer active.
 *
 * These rows reuse the inactive-integration treatment (is-disabled + disabled checkbox +
 * aria-disabled) so the enabled-but-missing state renders somewhere instead of vanishing.
 *
 * @param array<int,string> $orphans Enabled foreign slugs that are no longer discoverable.
 * @return void
 */
function aafm_render_bridge_orphan_group( array $orphans ): void {
	$rows = array();
	foreach ( $orphans as $slug ) {
		$rows[] = array(
			'slug'        => $slug,
			'label'       => $slug,
			'description' => __( 'Unavailable - the plugin that provides this ability is not active.', 'agent-abilities-for-mcp' ),
			'risk'        => 'write',
			'readonly'    => false,
			'destructive' => false,
			'tool_name'   => aafm_mcp_tool_name( aafm_bridge_tool_name( $slug ) ),
		);
	}
	aafm_render_bridge_group(
		'unavailable',
		array(
			'label'     => __( 'Unavailable (plugin inactive)', 'agent-abilities-for-mcp' ),
			'abilities' => $rows,
		),
		array(),
		true
	);
}

/**
 * Render a single bridge ability toggle row.
 *
 * Bridge-specific by design (see MF-1): it emits name="bridged_abilities[]", derives its checked
 * state from the enabled bridged list, and its save posts to aafm_save_bridged_abilities - so it can
 * never write a foreign slug into the native aafm_enabled_abilities option. Every CSS class is
 * identical to the integrations row; only the field name, checked source, destructive confirm, and
 * the resulting-tool-name line differ.
 *
 * @param array<string,mixed> $ability  Ability display record.
 * @param array<int,string>   $enabled  Enabled foreign slugs.
 * @param bool                $disabled True when the row is read-only (orphan/unavailable).
 * @return void
 */
function aafm_render_bridge_ability_row( array $ability, array $enabled, bool $disabled = false ): void {
	$slug        = (string) $ability['slug'];
	$risk        = (string) ( $ability['risk'] ?? 'write' );
	$destructive = ! empty( $ability['destructive'] );
	$hint        = (string) ( $ability['description'] ?? '' );
	$tool_name   = (string) ( $ability['tool_name'] ?? aafm_mcp_tool_name( aafm_bridge_tool_name( $slug ) ) );
	$title_id    = 'aafm-bridge-ability-title-' . sanitize_key( $slug );

	printf(
		'<div class="aafm-ability-row%1$s" data-risk="%2$s"%3$s>',
		$destructive ? ' is-destructive' : '',
		esc_attr( $risk ),
		$disabled ? ' aria-disabled="true"' : ''
	);

	printf(
		'<label class="aafm-switch"><input type="checkbox" name="bridged_abilities[]" value="%1$s" aria-labelledby="%2$s"%3$s%4$s%5$s><span class="aafm-switch-track"></span></label>',
		esc_attr( $slug ),
		esc_attr( $title_id ),
		$disabled ? '' : checked( in_array( $slug, $enabled, true ), true, false ),
		$disabled ? ' disabled' : '',
		$destructive ? ' data-destructive="1"' : ''
	);

	echo '<div class="aafm-ability-main"><div class="aafm-ability-title">';
	printf(
		'<h4 id="%1$s">%2$s</h4><span class="aafm-badge aafm-badge-%3$s">%3$s</span>',
		esc_attr( $title_id ),
		esc_html( (string) ( $ability['label'] ?? $slug ) ),
		esc_attr( $risk )
	);
	echo '</div>';

	printf( '<p class="aafm-ability-hint">%s</p>', esc_html( $hint ) );

	// The resulting MCP tool name as a muted monospace line (not a second badge).
	printf(
		'<p class="aafm-ability-hint aafm-muted"><code>%s</code></p>',
		esc_html( $tool_name )
	);

	// Destructive confirm: an inline reveal, never a JS modal. admin.js shows it when the switch
	// is flipped on; "Enable anyway" keeps it on, "Cancel" flips it back off.
	if ( $destructive && ! $disabled ) {
		echo '<div class="aafm-bridge-confirm" hidden>';
		echo wp_kses(
			aafm_get_notice_html(
				'warning',
				__( 'This ability can change or delete data. Enable it only if you trust the connected agent to run it.', 'agent-abilities-for-mcp' ),
				array( 'inline' => true )
			),
			aafm_admin_allowed_html()
		);
		echo '<p class="aafm-bridge-confirm-actions">';
		echo '<button type="button" class="aafm-btn aafm-btn-secondary aafm-bridge-confirm-yes">' . esc_html__( 'Enable anyway', 'agent-abilities-for-mcp' ) . '</button> ';
		echo '<button type="button" class="aafm-btn aafm-btn-secondary aafm-bridge-confirm-no">' . esc_html__( 'Cancel', 'agent-abilities-for-mcp' ) . '</button>';
		echo '</p>';
		echo '</div>';
	}

	echo '</div></div>';
}

/**
 * AJAX: save the enabled bridged-abilities toggles.
 *
 * Nonce + manage_options gated. Submitted slugs are sanitized and allowlisted against the
 * currently-discoverable foreign abilities, so a stale, unknown, or smuggled slug can never be
 * stored. Returns the same success/error JSON shape as aafm_ajax_save_abilities().
 *
 * @return void
 */
function aafm_ajax_save_bridged_abilities(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$submitted = isset( $_POST['bridged_abilities'] ) && is_array( $_POST['bridged_abilities'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['bridged_abilities'] ) )
		: array();

	$available = array();
	foreach ( aafm_discover_foreign_abilities() as $group ) {
		foreach ( $group['abilities'] as $ability ) {
			$available[] = (string) $ability['slug'];
		}
	}

	$allowed = array_values( array_unique( array_intersect( $submitted, $available ) ) );

	// Enabled slugs whose host plugin is currently inactive are not in $available and their
	// disabled checkboxes are never posted, so an unrelated save would silently drop them and
	// reactivating the host would not restore the bridge. Union those orphans back in so an
	// enabled-but-unavailable ability survives every save until it is explicitly turned off.
	$orphans = array_diff( aafm_get_enabled_bridged_abilities(), $available );
	$enabled = array_values( array_unique( array_merge( $allowed, $orphans ) ) );
	update_option( 'aafm_enabled_bridged_abilities', $enabled );

	wp_send_json_success(
		array(
			'enabled' => $enabled,
			'count'   => count( $enabled ),
		)
	);
}
