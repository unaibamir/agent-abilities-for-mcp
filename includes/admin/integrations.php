<?php
/**
 * Integrations tab: per-plugin SEO (Yoast / Rank Math / All in One SEO) / ACF / WooCommerce cards with a detected status, the security
 * disclaimer, and the per-ability toggles that register only when a host plugin is active.
 *
 * Reuses the shared admin design system (aafm-card / aafm-btn / inline-SVG aafm-icon) and
 * stores enabled abilities in the same aafm_enabled_abilities option as the Abilities tab,
 * saved through the same aafm_save_abilities AJAX action. Integration abilities are bucketed
 * by their registry `subject` (one of the integration slugs), so this tab needs no new option.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The integrations shown on the tab: slug => display label and the inline-SVG icon key.
 *
 * Each SEO plugin is its own card with its own ability set, since the per-plugin sets are
 * independent (a site can run more than one). The plugins list backs the installed-but-inactive
 * probe for that specific plugin.
 *
 * @return array<string,array{label:string,icon:string,plugins:array<int,string>}>
 */
function aafm_integration_cards(): array {
	return array(
		'yoast'       => array(
			'label'   => __( 'Yoast SEO', 'agent-abilities-for-mcp' ),
			'icon'    => 'abilities',
			'plugins' => array( 'wordpress-seo/wp-seo.php' ),
		),
		'rankmath'    => array(
			'label'   => __( 'Rank Math', 'agent-abilities-for-mcp' ),
			'icon'    => 'abilities',
			'plugins' => array( 'seo-by-rank-math/rank-math.php' ),
		),
		'aioseo'      => array(
			'label'   => __( 'All in One SEO', 'agent-abilities-for-mcp' ),
			'icon'    => 'abilities',
			'plugins' => array( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ),
		),
		'acf'         => array(
			'label'   => __( 'ACF', 'agent-abilities-for-mcp' ),
			'icon'    => 'groups',
			'plugins' => array( 'advanced-custom-fields/acf.php', 'advanced-custom-fields-pro/acf.php', 'secure-custom-fields/secure-custom-fields.php' ),
		),
		'woocommerce' => array(
			'label'   => __( 'WooCommerce', 'agent-abilities-for-mcp' ),
			'icon'    => 'groups',
			'plugins' => array( 'woocommerce/woocommerce.php' ),
		),
	);
}

/**
 * The detected status of an integration on this site.
 *
 * 'active'             — host plugin active (aafm_integration_active() true).
 * 'installed_inactive' — a candidate host plugin file is present but not active.
 * 'not_installed'      — no candidate host plugin file is present.
 *
 * @param string $slug Integration slug.
 * @return string One of 'active' | 'installed_inactive' | 'not_installed'.
 */
function aafm_integration_status( string $slug ): string {
	if ( aafm_integration_active( $slug ) ) {
		return 'active';
	}

	$cards = aafm_integration_cards();
	$files = $cards[ $slug ]['plugins'] ?? array();
	foreach ( $files as $file ) {
		if ( aafm_integration_plugin_file_exists( $file ) ) {
			return 'installed_inactive';
		}
	}
	return 'not_installed';
}

/**
 * Whether a plugin file is present in the plugins directory (installed-but-inactive probe).
 *
 * Read-only existence check against WP_PLUGIN_DIR; never loads or activates the plugin.
 *
 * @param string $plugin_file Plugin file relative to the plugins directory.
 * @return bool
 */
function aafm_integration_plugin_file_exists( string $plugin_file ): bool {
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		return false;
	}
	return file_exists( WP_PLUGIN_DIR . '/' . $plugin_file );
}

/**
 * Render the Integrations tab: the disclaimer header, then one card per integration.
 *
 * @return void
 */
function aafm_render_integrations_tab(): void {
	$registry    = aafm_get_abilities_registry();
	$enabled     = aafm_get_enabled_abilities();
	$disclosures = aafm_ability_disclosures();

	// Bucket integration abilities by their subject (the integration slug).
	$by_subject = array();
	foreach ( $registry as $name => $meta ) {
		$subject                  = (string) ( $meta['subject'] ?? '' );
		$by_subject[ $subject ][] = array( 'name' => (string) $name ) + $meta;
	}

	echo '<div class="aafm-integrations">';

	// Intro lede + the security disclaimer (humanized copy).
	echo '<p class="aafm-page-lede">' . esc_html__( 'Connect AI agents to the plugins you already run. An integration\'s abilities show up here only while its plugin is active, and each one stays off until you turn it on.', 'agent-abilities-for-mcp' ) . '</p>';

	echo '<div class="aafm-integrations-disclaimer">';
	echo aafm_get_notice_html( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper.
		'warning',
		__( 'These integrations let an agent read and write real data on your site, including personal data through WooCommerce and ACF: customer names, emails, addresses, order details. What an agent can actually touch is still bounded by the WordPress role of the account it connects as, and by the abilities you switch on below. Everything it does is recorded in the activity log. Turn on only what you trust the connected agent to handle.', 'agent-abilities-for-mcp' )
	);
	echo '</div>';

	// One outer form for every per-ability toggle across all integration cards (never a
	// nested form). The save handler binds to the same aafm_save_abilities AJAX action as
	// the Abilities tab; the stored value is a flat list of enabled ability names.
	echo '<form id="aafm-integrations-form" class="aafm-integrations-cards">';
	wp_nonce_field( 'aafm_admin', 'aafm_nonce' );

	// This form saves through the same aafm_save_abilities action as the Abilities tab,
	// which REPLACES the whole enabled list with the names it receives. The Integrations
	// form only renders integration toggles, so carry every already-enabled ability that
	// is NOT one of these three integrations as a hidden input — otherwise saving here
	// would silently disable all the core abilities enabled on the Abilities tab.
	$integration_subjects = array_keys( aafm_integration_cards() );
	foreach ( $enabled as $enabled_name ) {
		$subject = (string) ( $registry[ $enabled_name ]['subject'] ?? '' );
		if ( in_array( $subject, $integration_subjects, true ) ) {
			continue; // Rendered as a real toggle below.
		}
		printf(
			'<input type="hidden" name="aafm_abilities[]" value="%s">',
			esc_attr( $enabled_name )
		);
	}

	foreach ( aafm_integration_cards() as $slug => $card ) {
		$status   = aafm_integration_status( $slug );
		$rows     = $by_subject[ $slug ] ?? array();
		$disabled = ( 'active' !== $status );

		printf(
			'<section class="aafm-card aafm-integration-card aafm-integration-%1$s%2$s">',
			esc_attr( $slug ),
			$disabled ? ' is-disabled' : ''
		);

		// Card head: icon + label + a status pill.
		echo '<div class="aafm-card-head">';
		echo '<span class="icon">';
		echo aafm_icon( $card['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal SVG.
		echo '</span>';
		echo '<h2>' . esc_html( $card['label'] ) . '</h2>';
		echo aafm_integration_status_pill( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in the helper.
		echo '</div>';

		// Status note.
		echo '<p class="aafm-integration-note">' . esc_html( aafm_integration_status_note( $slug, $status ) ) . '</p>';

		if ( 'active' === $status ) {
			aafm_render_integration_abilities( $slug, $rows, $enabled, $disclosures );
		} else {
			// Inactive host: there are no live abilities to toggle, so show the manifest count
			// ("0 / N · X read, Y write") so the operator sees what activating the plugin unlocks.
			$counts = aafm_integration_manifest()[ $slug ] ?? null;
			if ( null !== $counts ) {
				printf(
					'<p class="aafm-integration-count">%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: total abilities, 2: read count, 3: write count. */
							__( '0 / %1$d · %2$d read, %3$d write', 'agent-abilities-for-mcp' ),
							(int) $counts['total'],
							(int) $counts['read'],
							(int) $counts['write']
						)
					)
				);
			}
		}

		echo '</section>';
	}

	echo '<div class="aafm-savebar"><button type="submit" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save changes', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-save-status" aria-live="polite"></span></div>';
	echo '</form>';

	echo '</div>'; // .aafm-integrations
}

/**
 * The status pill markup for an integration card head.
 *
 * @param string $status One of 'active' | 'installed_inactive' | 'not_installed'.
 * @return string Escaped HTML.
 */
function aafm_integration_status_pill( string $status ): string {
	$map                   = array(
		'active'             => array( 'aafm-pill-success', __( 'Active', 'agent-abilities-for-mcp' ) ),
		'installed_inactive' => array( 'aafm-pill-warn', __( 'Inactive', 'agent-abilities-for-mcp' ) ),
		'not_installed'      => array( 'aafm-pill-neutral', __( 'Not installed', 'agent-abilities-for-mcp' ) ),
	);
	list( $class, $label ) = $map[ $status ] ?? $map['not_installed'];
	return sprintf(
		'<span class="aafm-pill %1$s">%2$s</span>',
		esc_attr( $class ),
		esc_html( $label )
	);
}

/**
 * The plain-language status note shown under an integration card head.
 *
 * @param string $slug   Integration slug.
 * @param string $status Detected status.
 * @return string Plain text (escaped by the caller).
 */
function aafm_integration_status_note( string $slug, string $status ): string {
	$cards = aafm_integration_cards();
	$label = $cards[ $slug ]['label'] ?? $slug;

	switch ( $status ) {
		case 'active':
			return __( 'Active. Turn on the abilities you want this agent to use.', 'agent-abilities-for-mcp' );
		case 'installed_inactive':
			return sprintf(
				/* translators: %s: the integration plugin name, e.g. WooCommerce. */
				__( 'Installed but not active. Activate %s to use these abilities.', 'agent-abilities-for-mcp' ),
				$label
			);
		case 'not_installed':
		default:
			return __( 'Not installed. Install and activate the plugin to expose its abilities here.', 'agent-abilities-for-mcp' );
	}
}

/**
 * Render the per-ability toggles for one active integration, with a group enable/disable-all
 * control that confirms before bulk-enabling a PII/destructive group.
 *
 * The toggle markup mirrors the Abilities tab exactly so the shared save handler binds to the
 * same name="aafm_abilities[]" inputs. The bulk control is a type="button" (never a nested
 * form), per the Wave-0 nested-form lesson. The ability list is wrapped in a <details> element
 * so it can be collapsed without hiding the checkboxes from the form (collapse is CSS-only;
 * inputs inside a closed <details> still submit normally).
 *
 * @param string                         $slug        Integration slug.
 * @param array<int,array<string,mixed>> $rows        This integration's ability rows.
 * @param array<int,string>              $enabled     Enabled ability names.
 * @param array<string,string>           $disclosures Disclosure map.
 * @return void
 */
function aafm_render_integration_abilities( string $slug, array $rows, array $enabled, array $disclosures ): void {
	if ( empty( $rows ) ) {
		echo '<p class="aafm-integration-empty description">' . esc_html__( 'No abilities are available for this integration yet.', 'agent-abilities-for-mcp' ) . '</p>';
		return;
	}

	// Enabled-over-total count for the collapsible summary.
	$group_enabled = 0;
	$has_sensitive = false;
	foreach ( $rows as $row ) {
		if ( in_array( (string) $row['name'], $enabled, true ) ) {
			++$group_enabled;
		}
		if ( 'destructive' === (string) ( $row['risk'] ?? '' ) ) {
			$has_sensitive = true;
		}
	}

	// Collapsible abilities section: <details open> replaces the old static group-head div.
	// The <summary> carries the "Abilities X/Y" label; the body holds the toggle + ability list.
	printf(
		'<details class="aafm-abilities-details" open><summary>%1$s <span class="aafm-count-badge">%2$s / %3$s</span></summary>',
		esc_html__( 'Abilities', 'agent-abilities-for-mcp' ),
		esc_html( (string) $group_enabled ),
		esc_html( (string) count( $rows ) )
	);

	// Group enable/disable-all control (a type="button", never a nested form). The
	// data-has-sensitive flag tells the JS to window.confirm() before bulk-enabling a group
	// that can read or change personal data.
	printf(
		'<p class="aafm-section-toggle"><button type="button" class="aafm-btn aafm-btn-secondary aafm-integration-toggle-all" data-subject="%1$s"%2$s>%3$s</button></p>',
		esc_attr( $slug ),
		$has_sensitive ? ' data-has-sensitive="1"' : '',
		esc_html__( 'Enable all / Disable all', 'agent-abilities-for-mcp' )
	);

	// Each per-plugin card renders a flat ability list.
	echo '<div class="aafm-card aafm-ability-list">';
	foreach ( $rows as $ability ) {
		aafm_render_integration_ability_row( $ability, $enabled, $disclosures );
	}
	echo '</div>';

	echo '</details>';
}

/**
 * Render a single ability row inside an integration card.
 *
 * Extracted so both the flat list and the SEO sub-section loops share identical markup.
 *
 * @param array<string,mixed>  $ability     Ability data row.
 * @param array<int,string>    $enabled     Enabled ability names.
 * @param array<string,string> $disclosures Disclosure map.
 * @return void
 */
function aafm_render_integration_ability_row( array $ability, array $enabled, array $disclosures ): void {
	$name = (string) $ability['name'];
	$risk = (string) ( $ability['risk'] ?? 'read' );
	$hint = (string) ( $disclosures[ $name ] ?? ( $ability['description'] ?? '' ) );

	echo '<div class="aafm-ability-row">';
	printf(
		'<label class="aafm-switch"><input type="checkbox" name="aafm_abilities[]" value="%1$s" %2$s><span class="aafm-switch-track"></span></label>',
		esc_attr( $name ),
		checked( in_array( $name, $enabled, true ), true, false )
	);

	echo '<div class="aafm-ability-main"><div class="aafm-ability-title">';
	printf(
		'<h4>%1$s</h4><span class="aafm-badge aafm-badge-%2$s">%2$s</span>',
		esc_html( (string) ( $ability['label'] ?? $name ) ),
		esc_attr( $risk )
	);
	if ( 'read' === $risk ) {
		echo ' <span class="aafm-badge aafm-badge-readonly aafm-readonly-badge">' . esc_html__( 'read-only', 'agent-abilities-for-mcp' ) . '</span>';
	}
	printf(
		'</div><p class="aafm-ability-hint">%1$s</p></div></div>',
		esc_html( $hint )
	);
}
