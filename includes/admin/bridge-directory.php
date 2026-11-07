<?php
/**
 * "Other plugins" admin section + its AJAX save.
 *
 * Discovers WordPress Abilities registered by OTHER plugins (via aafm_discover_foreign_abilities())
 * and lets the operator opt each one in. An opted-in slug is stored in aafm_enabled_bridged_abilities
 * (separate from the native aafm_enabled_abilities) and registered as a governed aafm-bridge/* wrapper.
 *
 * The markup mirrors includes/admin/integrations.php EXACTLY (same card / switch / badge / filter
 * classes) but posts to its OWN action (aafm_save_bridged_abilities) with its own field name
 * (bridged_abilities[]) and its own checked source (aafm_get_stored_bridged_abilities_raw()), so it can
 * never write into the native option. The only new classes are a .aafm-bridge scoping wrapper and an
 * .is-destructive row modifier.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Render the "Other plugins" section.
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

	$groups = aafm_merge_bridge_groups_by_known_plugin( aafm_discover_foreign_abilities() );
	// The RAW stored list, never the floored one. Read-only mode subtracts writes from what
	// registers, not from what the operator chose, so the switches here have to keep showing that
	// choice - a floored read would render every enabled write unticked and the next save would
	// make that lie permanent.
	$enabled = aafm_get_stored_bridged_abilities_raw();

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

	aafm_render_bridge_review_card( $groups, $enabled );

	echo '<div class="aafm-integrations-disclaimer">';
	echo wp_kses(
		aafm_get_notice_html(
			'warning',
			__( 'These abilities are written by other plugins, so what they read and change is up to them. Unlike this plugin\'s own abilities, a bridged ability\'s output is not filtered or redacted here: whatever the third-party ability returns is what the agent sees. It can still only touch what the connected account\'s WordPress role allows, and every call is recorded in the activity log. Turn on only what you trust the connected agent to run.', 'agent-abilities-for-mcp' )
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

	$unavailable = aafm_bridge_group_slugs( $orphans );
	foreach ( $groups as $ns => $group ) {
		aafm_render_bridge_group( (string) $ns, $group, $enabled, false, count( $unavailable[ (string) $ns ] ?? array() ) );
	}

	if ( ! empty( $orphans ) ) {
		aafm_render_bridge_orphan_group( $orphans );
	}

	echo '<div class="aafm-savebar"><button type="submit" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save changes', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-save-status" aria-live="polite"></span></div>';
	echo '</form>';

	echo '</div>'; // .aafm-bridge
}

/**
 * The "New tools since your last review" card. Absent when nothing is new.
 *
 * It holds no ability checkbox, so a Save on this tab cannot enable anything it lists.
 *
 * @param array<string,array<string,mixed>> $groups  Merged discovery groups, keyed by group key.
 * @param array<int,string>                 $enabled The stored enable list the tab's switches show.
 * @return void
 */
function aafm_render_bridge_review_card( array $groups, array $enabled ): void {
	$new = aafm_bridge_new_tools();
	if ( array() === $new ) {
		return;
	}

	$body = '<p class="aafm-bridge-review-status" role="status"></p>'
		. '<p class="aafm-bridge-review-all"><button type="button" class="aafm-btn aafm-btn-secondary aafm-bridge-mark-reviewed" data-bridge-plugin="' . esc_attr( aafm_bridge_review_all_key() ) . '">' . esc_html__( 'Mark all as reviewed', 'agent-abilities-for-mcp' ) . '</button></p>';

	foreach ( $groups as $key => $group ) {
		$rows = array();
		foreach ( (array) ( $group['abilities'] ?? array() ) as $row ) {
			if ( in_array( (string) $row['slug'], $new, true ) ) {
				$rows[] = $row;
			}
		}
		if ( array() === $rows ) {
			continue;
		}

		$label = aafm_bridge_display_label( (string) ( $group['label'] ?? $key ) );
		$attrs = sprintf( 'data-bridge-plugin="%1$s" data-bridge-name="%2$s"', esc_attr( (string) $key ), esc_attr( $label ) );

		$body .= '<div class="aafm-bridge-review-block" data-bridge-plugin="' . esc_attr( (string) $key ) . '">';
		$body .= '<h3>' . esc_html( $label ) . ' <span class="aafm-count-badge">' . esc_html(
			sprintf(
				/* translators: %d: how many new tools this plugin added. */
				_n( '%d new tool', '%d new tools', count( $rows ), 'agent-abilities-for-mcp' ),
				count( $rows )
			)
		) . '</span></h3>';

		$body .= '<ul class="aafm-bridge-review-tools">';
		foreach ( $rows as $row ) {
			$risk_label = aafm_bridge_risk_label( $row );
			$notice     = aafm_bridge_personal_data_row_notice( aafm_bridge_personal_data_kind( (string) $row['slug'] ) );

			$body .= '<li><span class="aafm-badge aafm-badge-' . esc_attr( (string) $row['risk'] ) . '">' . esc_html( $risk_label['label'] ) . '</span> ';
			if ( in_array( (string) $row['slug'], $enabled, true ) ) {
				$row_lock = aafm_ability_lock_reason( (string) $row['slug'] );
				if ( null === $row_lock ) {
					$body .= '<span class="aafm-pill aafm-pill-success">' . esc_html__( 'On', 'agent-abilities-for-mcp' ) . '</span> ';
				} elseif ( 'high_risk' === $row_lock ) {
					$body .= '<span class="aafm-pill aafm-pill-neutral">' . esc_html__( 'Saved, the high-risk lock blocks it', 'agent-abilities-for-mcp' ) . '</span> ';
				} else {
					$body .= '<span class="aafm-pill aafm-pill-neutral">' . esc_html__( 'Saved, read-only mode blocks it', 'agent-abilities-for-mcp' ) . '</span> ';
				}
			}
			$body .= '<code>' . esc_html( (string) $row['slug'] ) . '</code>';
			if ( '' !== $risk_label['note'] ) {
				$body .= '<p class="aafm-ability-hint aafm-muted">' . esc_html( $risk_label['note'] ) . '</p>';
			}
			if ( '' !== $notice ) {
				$body .= aafm_get_notice_html( 'info', $notice, array( 'inline' => true ) );
			}
			$body .= '</li>';
		}
		$body .= '</ul>';

		$body .= '<p class="aafm-bridge-review-actions">'
			. '<button type="button" class="aafm-btn aafm-btn-secondary aafm-bridge-starter" ' . $attrs . '>' . esc_html__( 'Allow only read tools', 'agent-abilities-for-mcp' ) . '</button> '
			. '<button type="button" class="aafm-btn aafm-btn-secondary aafm-bridge-mark-reviewed" ' . $attrs . '>' . esc_html__( 'Mark as reviewed', 'agent-abilities-for-mcp' ) . '</button>'
			. '</p></div>';
	}

	aafm_render_section(
		array(
			'class'       => 'aafm-bridge-review',
			'title'       => __( 'New tools since your last review', 'agent-abilities-for-mcp' ),
			'icon'        => 'bell',
			'description' => __( 'An update or a newly activated plugin added these tools. Tools not marked On stay off until you switch them on, which needs a Save. Allow only read tools applies as soon as you confirm.', 'agent-abilities-for-mcp' ),
			'body'        => $body,
		)
	);
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
	$collisions = aafm_bridge_collisions();
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
	echo '<div class="aafm-integration-filter aafm-bridge-filter" id="aafm-bridge-filter">';
	aafm_render_filter_controls( 'aafm-bridge-search' );
	echo '</div>';
}

/**
 * Known third-party namespaces this plugin recognizes as belonging to the same product, each
 * carrying a CANONICAL, UNTRANSLATED id alongside its display label. Keyed by the exact
 * namespace slug (the part of the ability name before the first "/").
 *
 * The 'label' is display data ONLY - it is real product branding, real WordPress i18n, and can
 * legitimately be translated by a locale's .mo file or filtered by a 'gettext' plugin. The 'id'
 * exists precisely so nothing that identifies WHICH plugin this is ever depends on that: it is a
 * plain lowercase-hyphen literal that never passes through __() or the 'gettext' filter, so two
 * unrelated products can never end up sharing an id just because their labels happen to
 * translate to the same localized string on some site
 * (the prior fix keyed the merge on the translated label itself, which closed the
 * raw-namespace and sanitize_title aliasing cases but not this one).
 *
 * This list is necessarily incomplete - the Abilities API has no way to ask "what is your
 * plugin's display name" for an arbitrary namespace - so an unlisted plugin still falls back to
 * Title Case in aafm_bridge_display_label() and is never merge-eligible. Extend this map as
 * specific known-bad namespaces are reported; every namespace for the same product must share
 * the same 'id'.
 *
 * @return array<string,array{id:string,label:string}>
 */
function aafm_bridge_known_plugins(): array {
	return array(
		'wordpress-seo'          => array(
			'id'    => 'yoast-seo',
			'label' => __( 'Yoast SEO', 'agent-abilities-for-mcp' ),
		),
		'yoast-seo'              => array(
			'id'    => 'yoast-seo',
			'label' => __( 'Yoast SEO', 'agent-abilities-for-mcp' ),
		),
		'rank-math'              => array(
			'id'    => 'rank-math',
			'label' => __( 'Rank Math', 'agent-abilities-for-mcp' ),
		),
		'all-in-one-seo-pack'    => array(
			'id'    => 'aioseo',
			'label' => __( 'All in One SEO', 'agent-abilities-for-mcp' ),
		),
		'aioseo-posts'           => array(
			'id'    => 'aioseo',
			'label' => __( 'All in One SEO', 'agent-abilities-for-mcp' ),
		),
		'aioseo-settings'        => array(
			'id'    => 'aioseo',
			'label' => __( 'All in One SEO', 'agent-abilities-for-mcp' ),
		),
		'aioseo-notifications'   => array(
			'id'    => 'aioseo',
			'label' => __( 'All in One SEO', 'agent-abilities-for-mcp' ),
		),
		'aioseo-robots'          => array(
			'id'    => 'aioseo',
			'label' => __( 'All in One SEO', 'agent-abilities-for-mcp' ),
		),
		'aioseo-audit'           => array(
			'id'    => 'aioseo',
			'label' => __( 'All in One SEO', 'agent-abilities-for-mcp' ),
		),
		'advanced-custom-fields' => array(
			'id'    => 'acf',
			'label' => __( 'ACF', 'agent-abilities-for-mcp' ),
		),
		'secure-custom-fields'   => array(
			'id'    => 'acf',
			'label' => __( 'ACF', 'agent-abilities-for-mcp' ),
		),
		'wp-mail-smtp'           => array(
			'id'    => 'wp-mail-smtp',
			'label' => __( 'WP Mail SMTP', 'agent-abilities-for-mcp' ),
		),
		'wpmailsmtp'             => array(
			'id'    => 'wp-mail-smtp',
			'label' => __( 'WP Mail SMTP', 'agent-abilities-for-mcp' ),
		),
	);
}

/**
 * Display-label view of aafm_bridge_known_plugins(): namespace => translated label.
 *
 * Kept as its own helper because aafm_bridge_display_label() (and anything else that only needs
 * a label to SHOW) has no business also handling ids - only aafm_bridge_known_plugin_ids() and the
 * merge in aafm_merge_bridge_groups_by_known_plugin() care about those.
 *
 * @return array<string,string>
 */
function aafm_bridge_known_plugin_labels(): array {
	return wp_list_pluck( aafm_bridge_known_plugins(), 'label' );
}

/**
 * Canonical-id view of aafm_bridge_known_plugins(): namespace => untranslated plugin id.
 *
 * This is the map aafm_merge_bridge_groups_by_known_plugin() folds groups on. Never __()'d, never
 * gettext-filtered - see aafm_bridge_known_plugins()'s docblock for why that is the whole point.
 *
 * @return array<string,string>
 */
function aafm_bridge_known_plugin_ids(): array {
	return wp_list_pluck( aafm_bridge_known_plugins(), 'id' );
}

/**
 * The prefix a synthetic bridge merge-group key is built with, provably disjoint from every real
 * ability namespace.
 *
 * WordPress core's own Abilities registry only accepts a namespace matching /^[a-z0-9-]+$/
 * (wp-includes/abilities-api/class-wp-abilities-registry.php, enforced on every
 * wp_register_ability() call), so a genuine namespace can never contain a colon or an uppercase
 * character. Both appear in this prefix, which makes a collision with a real raw-namespace key
 * structurally impossible rather than merely unlikely - unlike the prior
 * scheme's 'aafm-known-' prefix, itself built entirely from the same [a-z0-9-] alphabet a real
 * namespace uses, which a real foreign namespace could and did alias.
 *
 * @return string
 */
function aafm_bridge_merge_group_key_prefix(): string {
	return 'AAFM-KNOWN:';
}

/**
 * Build the merge-group array key for a known plugin.
 *
 * Keyed on the CANONICAL, UNTRANSLATED id string (a value of aafm_bridge_known_plugin_ids()),
 * never the translated display label and never a sanitize_title() of either. Two prior schemes
 * both leaked translation/normalization into identity and both aliased real products together:
 * sanitize_title($label) collapsed whitespace/hyphen/case differences between two DIFFERENT
 * labels onto the same slug, and keying on the label string itself (still __()'d) let two
 * DIFFERENT known plugins collapse onto the same key whenever their English labels happened to
 * translate - or get 'gettext'-filtered - to the same localized string.
 * An id is a plain literal that is never translated or filtered, so only
 * two namespaces this map itself declares to be the same product ever share a key.
 *
 * @param string $id Canonical known-plugin id (a value of aafm_bridge_known_plugin_ids()).
 * @return string
 */
function aafm_bridge_merge_group_key( string $id ): string {
	return aafm_bridge_merge_group_key_prefix() . $id;
}

/**
 * Fold discovered foreign-ability groups whose namespaces resolve to the SAME known plugin into
 * a single group, before the render loop draws a card per group.
 *
 * Discovery groups strictly by raw namespace. A plugin that registers
 * its abilities under several namespaces - AIOSEO ships aioseo-posts, aioseo-settings,
 * aioseo-notifications, aioseo-robots and aioseo-audit - therefore produced one card PER
 * namespace, and every one of those cards resolved to the same "All in One SEO" heading via
 * aafm_bridge_display_label(): five identically-titled cards, each listing only a slice of the
 * plugin's abilities, instead of one card for the plugin (1.7.2 bug #8; the earlier fix only
 * relabeled the namespaces, it never merged the groups that render from them).
 *
 * Only a namespace with an entry in aafm_bridge_known_plugin_ids() is eligible: that map is the
 * plugin's own record of "these namespaces are the same product," so folding on it can never
 * merge two genuinely unrelated plugins just because a generic Title Case transform happens to
 * collide. An unlisted namespace is returned untouched, keeping its own single-namespace group.
 *
 * A merged group's own 'label' field is kept as the FIRST member namespace encountered, not the
 * already-resolved brand name. aafm_render_bridge_group() resolves 'label' through
 * aafm_bridge_display_label() exactly once, same as any other group; since every namespace
 * folded into this group is a known-plugin key by construction, resolving any one of them
 * reaches the same brand name. Storing the resolved name here instead would feed it back through
 * that resolver a second time - and since the resolved brand is no longer itself a known-
 * namespace key, it would fall through to the generic Title Case transform and get re-cased
 * wrong (e.g. "All in One SEO" -> "All In One SEO").
 *
 * The synthetic key a merged group is stored under (see
 * aafm_bridge_merge_group_key() below) must be provably disjoint from every real raw-namespace
 * key in this same array - see that function's own docblock for how. It is also keyed on the
 * canonical untranslated id, never the translated label - see aafm_bridge_merge_group_key()'s
 * own docblock for the residual finding that closed.
 *
 * @param array<string,array{label:string,abilities:array<int,array<string,mixed>>}> $groups Discovery groups keyed by namespace.
 * @return array<string,array{label:string,abilities:array<int,array<string,mixed>>}>
 */
function aafm_merge_bridge_groups_by_known_plugin( array $groups ): array {
	$ids    = aafm_bridge_known_plugin_ids();
	$merged = array();

	foreach ( $groups as $ns => $group ) {
		$ns = (string) $ns;

		if ( ! isset( $ids[ $ns ] ) ) {
			$merged[ $ns ] = $group;
			continue;
		}

		$key = aafm_bridge_merge_group_key( $ids[ $ns ] );

		if ( ! isset( $merged[ $key ] ) ) {
			$merged[ $key ] = array(
				'label'     => $ns, // Resolved once by the renderer - see the docblock above.
				'abilities' => array(),
			);
		}

		$merged[ $key ]['abilities'] = array_merge( $merged[ $key ]['abilities'], $group['abilities'] ?? array() );
	}

	// Re-sort the merged card's rows the same way aafm_discover_foreign_abilities() sorts a
	// single-namespace group: alphabetically by label, ties breaking toward original order. Left
	// untouched, a card assembled from several source groups would show each source namespace's
	// own alphabetical run back to back instead of one alphabetized list.
	$prefix = aafm_bridge_merge_group_key_prefix();
	foreach ( $merged as $key => &$group ) {
		if ( 0 !== strncmp( (string) $key, $prefix, strlen( $prefix ) ) ) {
			continue;
		}
		$paired = array_map(
			static function ( array $item, int $index ): array {
				return array( $item, $index );
			},
			$group['abilities'],
			array_keys( $group['abilities'] )
		);
		usort(
			$paired,
			static function ( array $a, array $b ): int {
				$cmp = strcasecmp( (string) $a[0]['label'], (string) $b[0]['label'] );
				return 0 !== $cmp ? $cmp : $a[1] <=> $b[1];
			}
		);
		$group['abilities'] = array_column( $paired, 0 );
	}
	unset( $group );

	return $merged;
}

/**
 * Turn a source namespace slug into a readable group heading.
 *
 * The namespace comes off the ability slug lowercased and hyphenated (e.g. "events-manager").
 * aafm_bridge_provider_label() is tried first; a generic Title Case transform ("events-manager" ->
 * "Events Manager") is the last resort, and only for a heading, because a heading is only a label.
 *
 * @param string $ns Source namespace.
 * @return string
 */
function aafm_bridge_display_label( string $ns ): string {
	$provider = aafm_bridge_provider_label( $ns );
	if ( '' !== $provider ) {
		return $provider;
	}

	$words = str_replace( array( '-', '_' ), ' ', $ns );
	$words = trim( (string) preg_replace( '/\s+/', ' ', $words ) );
	return '' === $words ? $ns : ucwords( $words );
}

/**
 * Render one source-plugin group (a collapsed accordion card) with its ability rows.
 *
 * @param string              $ns          Source namespace (the group key).
 * @param array<string,mixed> $group       Group data from aafm_discover_foreign_abilities().
 * @param array<int,string>   $enabled     Enabled foreign slugs.
 * @param bool                $disabled    True to render every row disabled (the orphan group).
 * @param int                 $unavailable This group's stored slugs that are not registered right
 *                                         now. Their rows sit in the Unavailable group, so the
 *                                         starter's confirm reads the count from this card.
 * @return void
 */
function aafm_render_bridge_group( string $ns, array $group, array $enabled, bool $disabled, int $unavailable = 0 ): void {
	$rows  = isset( $group['abilities'] ) && is_array( $group['abilities'] ) ? $group['abilities'] : array();
	$label = aafm_bridge_display_label( (string) ( $group['label'] ?? $ns ) );

	// Tallied the same way aafm_integration_manifest() tallies a native integration's rows, so the
	// header goes through aafm_integration_count_breakdown() below instead of a second read/write
	// string. This tab is the one place the high-risk floor does NOT reach - bridged abilities are
	// explicitly out of scope for it (see the Settings copy) - so folding destructive into write
	// here would understate reach on exactly the screen where the operator has the least other
	// protection and is relying most on what the header tells them.
	$read        = 0;
	$write       = 0;
	$destructive = 0;
	foreach ( $rows as $row ) {
		switch ( (string) ( $row['risk'] ?? 'write' ) ) {
			case 'read':
				++$read;
				break;
			case 'destructive':
				++$destructive;
				break;
			default:
				++$write;
		}
	}
	$counts = array(
		'total'       => count( $rows ),
		'read'        => $read,
		'write'       => $write,
		'destructive' => $destructive,
	);

	printf(
		'<details class="aafm-card aafm-integration-card aafm-integration-%1$s%2$s" data-bridge-plugin="%3$s"%4$s>',
		esc_attr( sanitize_html_class( $ns ) ),
		$disabled ? ' is-disabled' : '',
		esc_attr( $ns ),
		$unavailable > 0 ? ' data-bridge-unavailable="' . esc_attr( (string) $unavailable ) . '"' : ''
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
				/* translators: 1: total abilities, 2: read/write/destructive breakdown, e.g. "27 read, 23 write, 2 destructive". */
				_n( '%1$d ability · %2$s', '%1$d abilities · %2$s', count( $rows ), 'agent-abilities-for-mcp' ),
				count( $rows ),
				aafm_integration_count_breakdown( $counts )
			)
		)
	);
	echo '</span>';
	echo '</summary>';

	echo '<div class="aafm-integration-body">';

	// The orphan group has no plugin to name: that is what makes it an orphan group.
	if ( ! $disabled ) {
		echo '<p class="aafm-card-desc">' . esc_html( aafm_bridge_source_line( (string) ( $group['label'] ?? $ns ) ) ) . '</p>';
	}

	$flagged = $disabled ? '' : aafm_bridge_group_personal_data_kind( $rows );
	if ( '' !== $flagged ) {
		echo wp_kses( aafm_get_notice_html( 'info', aafm_bridge_personal_data_notice( $flagged ) ), aafm_admin_allowed_html() );
	}

	// Per-group bulk controls: flip every ability in this plugin at once. Not on the orphan
	// group (its rows are disabled). Bulk enable is a deliberate action, so it turns on
	// destructive abilities too without the per-row confirm; the DESTRUCTIVE badge still warns.
	if ( ! $disabled && $rows ) {
		echo '<div class="aafm-bridge-bulk">';
		echo '<button type="button" class="aafm-btn aafm-btn-secondary" data-bridge-bulk="enable">' . esc_html__( 'Enable all', 'agent-abilities-for-mcp' ) . '</button> ';
		echo '<button type="button" class="aafm-btn aafm-btn-secondary" data-bridge-bulk="disable">' . esc_html__( 'Disable all', 'agent-abilities-for-mcp' ) . '</button> ';
		// Read-only mode covers bridged abilities, so the friction it creates lands here too: on a
		// plugin that annotates many abilities readonly, the operator would otherwise tick each one
		// by hand. Same control as the other two tabs, scoped by the group card this sits in.
		echo '<button type="button" class="aafm-btn aafm-btn-secondary aafm-enable-reads">' . esc_html__( 'Enable all reads', 'agent-abilities-for-mcp' ) . '</button> ';
		// Unlike the three above, this one saves as soon as it is confirmed. See #bindBridgeStarter.
		printf(
			'<button type="button" class="aafm-btn aafm-btn-secondary aafm-bridge-starter" data-bridge-plugin="%1$s" data-bridge-name="%2$s">%3$s</button>',
			esc_attr( $ns ),
			esc_attr( $label ),
			esc_html__( 'Allow only read tools', 'agent-abilities-for-mcp' )
		);
		echo '</div>';
		if ( 0 === $read ) {
			echo '<p class="aafm-card-desc">' . esc_html__( 'This plugin labels none of its tools as read-only, so Allow only read tools turns all of them off.', 'agent-abilities-for-mcp' ) . '</p>';
		}
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
 * The first personal-data kind among a group's rows.
 *
 * @param array<int,array<string,mixed>> $rows Group rows; each has a slug.
 * @return string '' when no row is flagged.
 */
function aafm_bridge_group_personal_data_kind( array $rows ): string {
	foreach ( $rows as $row ) {
		$kind = aafm_bridge_personal_data_kind( (string) ( $row['slug'] ?? '' ) );
		if ( '' !== $kind ) {
			return $kind;
		}
	}
	return '';
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
 * Best-effort read of a foreign ability's raw permission callback, for display only.
 *
 * The bridge adds no capability floor of its own: a bridged tool's effective gate is the
 * transport's signed-in check plus THIS ability's own permission_callback, which lives in the
 * source plugin. The Abilities API keeps that callback protected with no public getter, so this
 * reflects the property purely to SHOW the operator what gates the ability (a wide-open
 * __return_true or a bare is_user_logged_in is exactly the weak check worth seeing). It is never
 * invoked. Any reflection failure returns null so the caller falls back to an honest note.
 *
 * @param object $ability The foreign WP_Ability.
 * @return mixed The raw callback, or null when it cannot be read.
 */
function aafm_bridge_reflect_permission_callback( $ability ) {
	if ( ! is_object( $ability ) ) {
		return null;
	}
	try {
		$prop = new \ReflectionProperty( $ability, 'permission_callback' );
		$prop->setAccessible( true );
		return $prop->getValue( $ability );
	} catch ( \Throwable $e ) {
		unset( $e );
		return null;
	}
}

/**
 * A readable label for a foreign ability's permission callback, or '' when it is not readable.
 *
 * Resolves the LIVE foreign ability by slug and reflects its callback (see
 * aafm_bridge_reflect_permission_callback()). A string callback (function name) or an
 * array [class, method] callback yields a readable label; a closure or anything unreadable
 * yields '' so the renderer shows the honest "determined by the plugin" note instead.
 *
 * @param string $slug The foreign ability slug.
 * @return string A human-readable callback reference, or '' when none can be shown.
 */
function aafm_bridge_permission_label( string $slug ): string {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return '';
	}
	$ability = wp_get_ability( $slug );
	if ( ! $ability instanceof \WP_Ability ) {
		return '';
	}

	$callback = aafm_bridge_reflect_permission_callback( $ability );

	if ( is_string( $callback ) && '' !== $callback ) {
		return $callback;
	}
	if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
		$class  = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
		$method = (string) $callback[1];
		if ( '' !== $class && '' !== $method ) {
			return $class . '::' . $method;
		}
	}

	return ''; // Closure or otherwise not readable.
}

/**
 * Render the effective-permission line for a bridge ability row (display only).
 *
 * The bridge never overrides the source plugin's own permission_callback, so the operator can't
 * otherwise see what really gates a bridged tool. Surface it: the readable callback when we can
 * reflect one, or an honest "permission determined by <plugin>" note when we can't (a closure, or
 * an orphan whose host plugin is inactive). Read-only; changes no dispatch behavior.
 *
 * @param string $slug     The foreign ability slug.
 * @param bool   $disabled True for orphan/unavailable rows (host plugin inactive).
 * @return void
 */
function aafm_render_bridge_permission_line( string $slug, bool $disabled ): void {
	$label = $disabled ? '' : aafm_bridge_permission_label( $slug );

	if ( '' !== $label ) {
		printf(
			'<p class="aafm-ability-hint aafm-muted">%1$s <code>%2$s</code></p>',
			esc_html__( 'Permission check:', 'agent-abilities-for-mcp' ),
			esc_html( $label )
		);
		return;
	}

	$pos          = strpos( $slug, '/' );
	$plugin_label = aafm_bridge_display_label( false !== $pos ? substr( $slug, 0, $pos ) : $slug );
	printf(
		'<p class="aafm-ability-hint aafm-muted">%s</p>',
		esc_html(
			sprintf(
				/* translators: %s: source plugin display name. */
				__( 'Permission determined by %s', 'agent-abilities-for-mcp' ),
				$plugin_label
			)
		)
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
	$risk_label  = aafm_bridge_risk_label( $ability );

	$lock_reason = aafm_ability_lock_reason( $slug );

	// A locked row has no switch, so this is how the starter's confirm counts a stored tool it would turn off.
	$high_risk = aafm_ability_is_high_risk( $slug );
	printf(
		'<div class="aafm-ability-row%1$s" data-risk="%2$s"%3$s%4$s%5$s>',
		$destructive ? ' is-destructive' : '',
		esc_attr( $risk ),
		null !== $lock_reason && in_array( $slug, $enabled, true ) ? ' data-aafm-stored-on="1"' : '',
		$disabled ? ' aria-disabled="true"' : '',
		$high_risk ? ' data-high-risk="1"' : ''
	);

	// Read-only mode reaches bridged abilities too, classified by the foreign ability's own
	// annotation, and so does the high-risk lock for a held foreign slug (the built-in held slugs
	// and any the aafm_high_risk_abilities filter names). A locked row renders the lock glyph and
	// NO <input>, exactly like the native renderer, so the group's "Enable all" cannot sweep it
	// back in. Pinned by ReadOnlyUiTest::test_a_held_bridge_row_renders_the_lock_and_no_switch().
	if ( null !== $lock_reason ) {
		printf(
			'<span class="aafm-ability-locked" role="img" aria-label="%1$s">%2$s</span>',
			esc_attr__( 'Locked', 'agent-abilities-for-mcp' ),
			wp_kses( aafm_icon( 'lock' ), aafm_svg_allowed_html() )
		);
	} else {
		printf(
			'<label class="aafm-switch"><input type="checkbox" name="bridged_abilities[]" value="%1$s" aria-labelledby="%2$s"%3$s%4$s%5$s><span class="aafm-switch-track"></span></label>',
			esc_attr( $slug ),
			esc_attr( $title_id ),
			$disabled ? '' : checked( in_array( $slug, $enabled, true ), true, false ),
			$disabled ? ' disabled' : '',
			$destructive ? ' data-destructive="1"' : ''
		);
	}

	echo '<div class="aafm-ability-main"><div class="aafm-ability-title">';
	printf(
		'<h4 id="%1$s">%2$s</h4><span class="aafm-badge aafm-badge-%3$s">%4$s</span>',
		esc_attr( $title_id ),
		esc_html( (string) ( $ability['label'] ?? $slug ) ),
		esc_attr( $risk ),
		esc_html( $risk_label['label'] )
	);
	// The badge marks what the tool can do, so it stays once the category is unlocked.
	if ( $high_risk ) {
		echo ' <span class="aafm-badge aafm-badge-high-risk">' . esc_html__( 'high-risk', 'agent-abilities-for-mcp' ) . '</span>';
	}
	echo '</div>';

	printf( '<p class="aafm-ability-hint">%s</p>', esc_html( $hint ) );

	$data_notice = $disabled ? '' : aafm_bridge_personal_data_row_notice( aafm_bridge_personal_data_kind( $slug ) );
	if ( '' !== $data_notice ) {
		echo wp_kses( aafm_get_notice_html( 'info', $data_notice, array( 'inline' => true ) ), aafm_admin_allowed_html() );
	}

	// An unavailable row has no annotations to read, so it says nothing about what the plugin labelled.
	if ( ! $disabled && '' !== $risk_label['note'] ) {
		printf( '<p class="aafm-ability-hint aafm-muted">%s</p>', esc_html( $risk_label['note'] ) );
	}

	// The resulting MCP tool name as a muted monospace line (not a second badge).
	printf(
		'<p class="aafm-ability-hint aafm-muted"><code>%s</code></p>',
		esc_html( $tool_name )
	);

	// The effective permission gate, so the operator can judge exposure before enabling.
	aafm_render_bridge_permission_line( $slug, $disabled );

	if ( null !== $lock_reason ) {
		echo '<p class="aafm-ability-locked-note">' . esc_html( aafm_ability_lock_note( $lock_reason ) ) . '</p>';
	}

	// Destructive confirm: an inline reveal, never a JS modal. admin.js shows it when the switch
	// is flipped on; "Enable anyway" keeps it on, "Cancel" flips it back off. A locked row has no
	// switch to flip, so it never renders one.
	if ( $destructive && ! $disabled && null === $lock_reason ) {
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

	$stored = aafm_get_stored_bridged_abilities_raw();

	// A locked row (read-only mode, or a held tool under the high-risk lock) has no checkbox, so a
	// posted one did not come from the screen. Refuse a locked slug that is not already stored, the
	// same rule aafm_set_enabled_abilities() applies to native names: an existing choice is carried
	// forward, a fresh one is not accepted from a form that never offered it. The refused slugs are
	// kept by reason so each refusal can be audited below, exactly as the native path audits its own.
	$refused = array(
		'read_only' => array(),
		'high_risk' => array(),
	);
	$kept    = array();
	foreach ( $allowed as $slug ) {
		$reason = aafm_ability_lock_reason( $slug );
		if ( null !== $reason && ! in_array( $slug, $stored, true ) ) {
			$refused[ $reason ][] = $slug;
			continue;
		}
		$kept[] = $slug;
	}
	$allowed = $kept;

	// Enabled slugs whose host plugin is currently inactive are not in $available and their
	// disabled checkboxes are never posted, so an unrelated save would silently drop them and
	// reactivating the host would not restore the bridge. Union those orphans back in so an
	// enabled-but-unavailable ability survives every save until it is explicitly turned off.
	//
	// A slug a lock has hidden is the same story: its checkbox never rendered, so its absence from
	// the POST is the lock speaking, not the operator turning it off. Preserve it, or the first
	// bridge save while locked discards a choice that was never restated and lifting the lock hands
	// back a blank slate.
	$orphans   = array_diff( $stored, $available );
	$lock_kept = array_values( array_filter( $stored, static fn( string $slug ): bool => null !== aafm_ability_lock_reason( $slug ) ) );
	$enabled   = array_values( array_unique( array_merge( $allowed, $orphans, $lock_kept ) ) );
	// Verified, not a bare update_option(): see aafm_update_option_verified()'s docblock for why a
	// persistent object cache can otherwise make the write silently no-op.
	$enabled_persisted = aafm_update_option_verified( 'aafm_enabled_bridged_abilities', $enabled );

	// The bridge tab changes ability exposure outside the main save path, so it carries the
	// same audit contract - one ability_enabled/ability_disabled row per changed slug, and one
	// ability_enable_blocked row per refused fresh write, with the lock that refused it. The refusal
	// rows are logged regardless of what happens below - they describe what the floor refused to
	// even attempt, not whether the write that follows persisted.
	foreach ( $refused as $reason => $slugs ) {
		if ( ! empty( $slugs ) ) {
			aafm_log_blocked_ability_enables( $slugs, $reason );
		}
	}

	// The success-style diff must not be logged ahead of knowing whether the write actually took:
	// a stale persistent object cache can make $enabled_persisted false while $enabled still lists
	// every slug the operator asked for, and logging the diff first would leave
	// ability_enabled/ability_disabled rows on record for a change the database never accepted.
	if ( $enabled_persisted ) {
		aafm_log_ability_toggle_diff( $stored, $enabled );
	} else {
		aafm_log_ability_persist_failure( 'aafm_enabled_bridged_abilities', __( 'The bridged abilities selection', 'agent-abilities-for-mcp' ) );
		wp_send_json_error( array( 'message' => aafm_switch_not_persisted_message( __( 'The bridged abilities selection', 'agent-abilities-for-mcp' ) ) ) );
	}

	wp_send_json_success(
		array(
			'enabled' => $enabled,
			'count'   => count( $enabled ),
		)
	);
}

/**
 * AJAX: set one plugin's tools to its reads. Nonce + manage_options gated; the group key is checked
 * against the computed groups inside aafm_bridge_apply_read_only_starter().
 *
 * @return void
 */
function aafm_ajax_bridge_read_only_starter(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$key    = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
	$result = aafm_bridge_apply_read_only_starter( (string) $key );
	if ( ! $result['ok'] ) {
		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	wp_send_json_success( $result );
}

/**
 * AJAX: mark one plugin's tools, or every new tool, as reviewed. Enables nothing. Nonce +
 * manage_options gated; the group key is checked against the computed groups.
 *
 * @return void
 */
function aafm_ajax_bridge_mark_reviewed(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
	$key    = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
	$groups = aafm_bridge_group_slugs( aafm_bridge_foreign_slugs() );
	if ( aafm_bridge_review_all_key() === $key ) {
		$slugs = aafm_bridge_foreign_slugs();
	} elseif ( isset( $groups[ $key ] ) ) {
		$slugs = $groups[ $key ];
	} else {
		wp_send_json_error( array( 'message' => __( 'That plugin has no tools registered right now.', 'agent-abilities-for-mcp' ) ) );
	}

	if ( ! aafm_bridge_mark_reviewed( $slugs ) ) {
		aafm_bridge_review_log_failure();
		wp_send_json_error( array( 'message' => aafm_switch_not_persisted_message( __( 'The reviewed tools list', 'agent-abilities-for-mcp' ) ) ) );
	}

	wp_send_json_success(
		array(
			'new'   => aafm_bridge_new_tools(),
			'count' => count( aafm_bridge_new_tools() ),
		)
	);
}
