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
	// One neutral glyph for every card: the integration "plug" icon. Each card already carries
	// its own name, status pill, and ability count, so the icon only needs to read as "an
	// integration" - not classify it. Deliberately NOT the 'abilities'/'bolt' glyph, which means
	// "enabled" elsewhere in this UI; reusing it here would imply a state the icon doesn't track.
	return array(
		'yoast'       => array(
			'label'   => __( 'Yoast SEO', 'agent-abilities-for-mcp' ),
			'icon'    => 'integrations',
			'plugins' => array( 'wordpress-seo/wp-seo.php' ),
		),
		'rankmath'    => array(
			'label'   => __( 'Rank Math', 'agent-abilities-for-mcp' ),
			'icon'    => 'integrations',
			'plugins' => array( 'seo-by-rank-math/rank-math.php' ),
		),
		'aioseo'      => array(
			'label'   => __( 'All in One SEO', 'agent-abilities-for-mcp' ),
			'icon'    => 'integrations',
			'plugins' => array( 'all-in-one-seo-pack/all_in_one_seo_pack.php' ),
		),
		'acf'         => array(
			'label'   => __( 'ACF', 'agent-abilities-for-mcp' ),
			'icon'    => 'integrations',
			'plugins' => array( 'advanced-custom-fields/acf.php', 'advanced-custom-fields-pro/acf.php', 'secure-custom-fields/secure-custom-fields.php' ),
		),
		'woocommerce' => array(
			'label'   => __( 'WooCommerce', 'agent-abilities-for-mcp' ),
			'icon'    => 'integrations',
			'plugins' => array( 'woocommerce/woocommerce.php' ),
		),
	);
}

/**
 * The detected status of an integration on this site.
 *
 * 'active'             - host plugin active (aafm_integration_active() true).
 * 'installed_inactive' - a candidate host plugin file is present but not active.
 * 'not_installed'      - no candidate host plugin file is present.
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
	echo wp_kses(
		aafm_get_notice_html(
			'warning',
			__( 'These integrations let an agent read and write real data on your site, including personal data through WooCommerce and ACF: customer names, emails, addresses, order details. What an agent can actually touch is still bounded by the WordPress role of the account it connects as, and by the abilities you switch on below. Everything it does is recorded in the activity log. Turn on only what you trust the connected agent to handle.', 'agent-abilities-for-mcp' )
		),
		aafm_admin_allowed_html()
	);
	echo '</div>';

	// One outer form for every per-ability toggle across all integration cards (never a
	// nested form). The save handler binds to the same aafm_save_abilities AJAX action as
	// the Abilities tab; the stored value is a flat list of enabled ability names.
	echo '<form id="aafm-integrations-form" class="aafm-integrations-cards">';
	wp_nonce_field( 'aafm_admin', 'aafm_nonce' );

	// This form saves through the same aafm_save_abilities action as the Abilities tab, but it
	// only renders integration toggles. Rather than carrying off-tab abilities forward as
	// client-side hidden inputs (which a stale tab or a tamper could drop or flip), it declares
	// the subjects it OWNS via aafm_scope[]. The server preserves every persisted ability outside
	// that scope from the stored option and only updates the in-scope ones - see
	// aafm_resolve_scoped_enabled_input().
	$integration_subjects = array_keys( aafm_integration_cards() );
	foreach ( $integration_subjects as $scope_subject ) {
		printf(
			'<input type="hidden" name="aafm_scope[]" value="%s">',
			esc_attr( $scope_subject )
		);
	}

	$descriptor = aafm_integration_ability_manifest();

	foreach ( aafm_integration_cards() as $slug => $card ) {
		$status   = aafm_integration_status( $slug );
		$disabled = ( 'active' !== $status );

		// Ability rows always come from the static descriptor, so an inactive host still shows the
		// full list. When the host is active the live registry holds the same set, so prefer the
		// live rows (they carry the real risk/group/description); otherwise fall back to the
		// descriptor's static rows, which are rendered disabled below.
		$rows = $disabled
			? ( $descriptor[ $slug ] ?? array() )
			: ( $by_subject[ $slug ] ?? ( $descriptor[ $slug ] ?? array() ) );

		// Each card is a native <details> accordion (collapsed by default - no open attribute), so
		// the whole section is the toggle. The card classes ride on the <details> so the existing
		// .aafm-integration-{slug} hooks and .is-disabled muting still apply.
		printf(
			'<details class="aafm-card aafm-integration-card aafm-integration-%1$s%2$s">',
			esc_attr( $slug ),
			$disabled ? ' is-disabled' : ''
		);

		$counts = aafm_integration_manifest()[ $slug ] ?? null;

		// The ENABLED count is computed from the saved option, exactly like the Abilities tab:
		// count how many of this integration's ability names the operator has turned on. Count
		// against the same manifest set that backs the total/read/write tallies ($descriptor),
		// so the enabled figure can never exceed the denominator shown beside it.
		if ( null !== $counts ) {
			$enabled_in_card = 0;
			foreach ( $descriptor[ $slug ] ?? array() as $manifest_row ) {
				if ( in_array( (string) $manifest_row['name'], $enabled, true ) ) {
					++$enabled_in_card;
				}
			}
			$counts['enabled'] = $enabled_in_card;
		}

		// The <summary> IS the card head: icon + label + status pill + count. A real <summary>
		// toggles on click and on Enter/Space natively, so the accordion stays keyboard-accessible.
		echo '<summary class="aafm-card-head">';
		echo '<span class="icon">';
		echo wp_kses( aafm_icon( $card['icon'] ), aafm_svg_allowed_html() );
		echo '</span>';
		echo '<h2>' . esc_html( $card['label'] ) . '</h2>';
		echo wp_kses( aafm_integration_status_pill( $status ), aafm_admin_allowed_html() );

		echo '<span class="abilities-count">';
		if ( null !== $counts ) {
			printf(
				'<p class="aafm-integration-count">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: enabled abilities, 2: total abilities, 3: read count, 4: write count. */
						__( '%1$d / %2$d · %3$d read, %4$d write', 'agent-abilities-for-mcp' ),
						(int) $counts['enabled'],
						(int) $counts['total'],
						(int) $counts['read'],
						(int) $counts['write']
					)
				)
			);
		}
		echo '</span>';

		echo '</summary>';

		// Accordion content: the status note, the per-card filter, then the ability list directly.
		echo '<div class="aafm-integration-body">';

		echo '<p class="aafm-integration-note">' . esc_html( aafm_integration_status_note( $slug, $status ) ) . '</p>';

		aafm_render_integration_filter( $slug );

		aafm_render_integration_abilities( $slug, $rows, $enabled, $disclosures, $disabled );

		echo '</div>';

		echo '</details>';
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
 * Render the per-card search + read/write filter for one integration card.
 *
 * A search box plus an All / Read Only / Write toggle group, modelled on the reference MCP
 * client's tool filter. The controls are scoped to this card by data-card and drive admin.js's
 * per-card filter, which toggles row visibility only - the buttons are type="button" and the
 * search input is not an aafm_abilities[] field, so neither interferes with the form submit.
 *
 * @param string $slug Integration slug.
 * @return void
 */
function aafm_render_integration_filter( string $slug ): void {
	$input_id = 'aafm-int-search-' . $slug;

	echo '<div class="aafm-integration-filter" data-card="' . esc_attr( $slug ) . '">';

	// Visually-hidden label keeps the search input accessible without adding visible chrome.
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

	// All / Read Only / Write toggle group. "All" starts selected. Each button is type="button".
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
 * Render the per-ability toggles for one integration, with a group enable/disable-all control
 * that confirms before bulk-enabling a PII/destructive group.
 *
 * The toggle markup mirrors the Abilities tab exactly so the shared save handler binds to the
 * same name="aafm_abilities[]" inputs. The bulk control is a type="button" (never a nested form),
 * per the Wave-0 nested-form lesson. The list renders directly inside the card's accordion body -
 * the section <details> is the only collapsible now, so there is no inner sub-collapsible.
 *
 * @param string                         $slug        Integration slug.
 * @param array<int,array<string,mixed>> $rows        This integration's ability rows.
 * @param array<int,string>              $enabled     Enabled ability names.
 * @param array<string,string>           $disclosures Disclosure map.
 * @param bool                           $disabled    True when the host is inactive - rows render
 *                                                    read-only and never submit.
 * @return void
 */
function aafm_render_integration_abilities( string $slug, array $rows, array $enabled, array $disclosures, bool $disabled = false ): void {
	$has_sensitive = false;
	foreach ( $rows as $row ) {
		if ( 'destructive' === (string) ( $row['risk'] ?? '' ) ) {
			$has_sensitive = true;
			break;
		}
	}

	// Group enable/disable-all control (a type="button", never a nested form). The
	// data-has-sensitive flag tells the JS to window.confirm() before bulk-enabling a group
	// that can read or change personal data.
	printf(
		'<p class="aafm-section-toggle"><button type="button" class="aafm-btn aafm-btn-secondary aafm-integration-toggle-all" data-subject="%1$s"%2$s>%3$s</button></p>',
		esc_attr( $slug ),
		$has_sensitive ? ' data-has-sensitive="1"' : '',
		esc_html__( 'Enable all / Disable all', 'agent-abilities-for-mcp' )
	);

	// Each per-plugin card renders a flat ability list directly in the accordion body.
	echo '<div class="aafm-card aafm-ability-list">';
	foreach ( $rows as $ability ) {
		aafm_render_integration_ability_row( $ability, $enabled, $disclosures, $disabled );
	}
	echo '</div>';
}

/**
 * Render a single ability row inside an integration card.
 *
 * Extracted so both the flat list and the SEO sub-section loops share identical markup.
 *
 * The disclosure hint is resolved the same way for active and inactive rows - prefer the
 * aafm_ability_disclosures() line for this ability name, fall back to the row's own description -
 * so the descriptor never carries its own copy of the disclosure text.
 *
 * @param array<string,mixed>  $ability     Ability data row.
 * @param array<int,string>    $enabled     Enabled ability names.
 * @param array<string,string> $disclosures Disclosure map.
 * @param bool                 $disabled    True when the host is inactive - the checkbox renders
 *                                          disabled (so it never submits) and the row carries
 *                                          aria-disabled, while staying fully readable.
 * @return void
 */
function aafm_render_integration_ability_row( array $ability, array $enabled, array $disclosures, bool $disabled = false ): void {
	$name = (string) $ability['name'];
	$risk = (string) ( $ability['risk'] ?? 'read' );
	$hint = (string) ( $disclosures[ $name ] ?? ( $ability['description'] ?? '' ) );

	// Per-ability id on the title <h4>, used as the checkbox's accessible name via
	// aria-labelledby - without it a screen reader announces the bare toggle as just
	// "checkbox". sanitize_key keeps the slug DOM-safe (ability names hold a slash).
	$title_id = 'aafm-int-ability-title-' . sanitize_key( $name );

	printf(
		'<div class="aafm-ability-row" data-risk="%1$s"%2$s>',
		esc_attr( $risk ),
		$disabled ? ' aria-disabled="true"' : ''
	);
	// An inactive host has nothing enabled, so a disabled row never renders checked; it also
	// carries the disabled attribute so it stays out of the submitted aafm_abilities[] list.
	printf(
		'<label class="aafm-switch"><input type="checkbox" name="aafm_abilities[]" value="%1$s" aria-labelledby="%2$s" %3$s%4$s><span class="aafm-switch-track"></span></label>',
		esc_attr( $name ),
		esc_attr( $title_id ),
		$disabled ? '' : checked( in_array( $name, $enabled, true ), true, false ),
		$disabled ? ' disabled' : ''
	);

	echo '<div class="aafm-ability-main"><div class="aafm-ability-title">';
	printf(
		'<h4 id="%1$s">%2$s</h4><span class="aafm-badge aafm-badge-%3$s">%3$s</span>',
		esc_attr( $title_id ),
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
