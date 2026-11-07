<?php
/**
 * Page-builder content-ownership detection.
 *
 * A write to post_content/post_excerpt made through this plugin's own content abilities can go wrong
 * three ways on a post owned by a page builder (Elementor, Divi, Beaver Builder, Avada, Visual
 * Composer, Oxygen, Breakdance, Brizy, Bricks or WPBakery). It can be silently ignored: Elementor, Divi and Beaver Builder do not use post_content
 * as their rendering source, so the builder's own stored data (a separate meta key, or an external
 * asset file) is what actually renders, and the agent's edit appears to succeed but has no visible
 * effect. It can corrupt markup: Avada renders from Fusion shortcodes in post_content, so a generic
 * write can break that shortcode tree. And it can be overwritten later: Visual Composer also writes
 * rendered output into post_content, so a visible change is lost the next time someone saves in the
 * builder. WPBakery keeps its layout as shortcodes in post_content itself, so a generic write can
 * break it the same way. This is a REFUSAL guard, not a builder integration: it never edits builder-owned data, it
 * only stops a write from landing where it will be ignored, corrupted or overwritten.
 *
 * Avada/Fusion Builder's marker WAS confirmed (2026-09-05) against a real installed copy (Avada
 * 7.16.1 theme, Fusion Builder 3.16.1 plugin): `fusion_builder_status` post meta set to 'active'
 * (inc/class-fusion-builder.php:1736, read back at :1751/:2723/:2732 to decide whether a page is
 * builder-owned) and `fusion_builder_converted` set to 'yes' for content migrated to the modern
 * builder (same file, same read sites). 228-avada-guard-design.md, written without a live install,
 * had assumed no such key existed and scoped its own guard to post_content alone - this is the
 * "new information" it said would require a follow-up, not a gap in that design. Both keys are
 * added below so the GENERIC guard also refuses a write on an Avada-owned post, exactly like
 * the other builders; aafm/avada-replace-text (includes/abilities/avada.php) is the one
 * exception that allows a text-only edit, via its own shortcode-tree signature check rather than
 * this blanket refusal.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AAFM_BUILDER_OWNERSHIP_UNKNOWN' ) ) {
	define( 'AAFM_BUILDER_OWNERSHIP_UNKNOWN', 'unknown' );
}

/**
 * Whether a post's content is owned by a detected foreign page builder.
 *
 * A builder is only listed once its ownership marker has been confirmed against real source or a
 * real installed copy. Shipping a guessed meta key would be worse than shipping nothing: a wrong
 * key refuses ordinary writes on uninvolved posts, or, if it never matches, gives operators false
 * confidence that the guard covers a builder it does not. The map is filterable via
 * aafm_page_builder_markers, so an operator can add a marker without a code change.
 *
 * By default this is the guard every write consults. It reads every marker's stored rows in one
 * query and also reads each marker through core, so a marker counts when either view holds it,
 * including one only a read filter or a registered default supplies, and a stored row a read
 * filter hides. A failed read, a marker row stored under another spelling that the column's
 * collation matches, or markers of two different builders answer
 * AAFM_BUILDER_OWNERSHIP_UNKNOWN, which every write treats as owned. With $pure_read true it reads
 * through core only and the first marker in map order wins, for a flag a read reports and no write
 * decides from.
 *
 * @param int  $post_id   Post id.
 * @param bool $pure_read Read through core only, never answering unknown.
 * @return string|false The detected builder's short name, AAFM_BUILDER_OWNERSHIP_UNKNOWN, or false
 *                      when none is detected.
 */
function aafm_post_has_foreign_builder_ownership( int $post_id, bool $pure_read = false ) {
	global $wpdb;

	$markers = aafm_page_builder_markers();
	if ( array() === $markers ) {
		return false; // A site that empties the map has switched the guard off.
	}
	$bricks = aafm_page_builder_bricks_keys();

	// WPBakery has no marker: its layout is the shortcodes in post_content. A post that does not
	// exist has no owner; a lookup that failed on a database error cannot be decided.
	$post = aafm_exact_object( 'post', $post_id );
	if ( ! $post instanceof WP_Post && ! $pure_read && '' !== (string) $wpdb->last_error ) {
		return AAFM_BUILDER_OWNERSHIP_UNKNOWN;
	}
	$wpbakery = $post instanceof WP_Post && 1 === preg_match( aafm_page_builder_content_pattern(), (string) $post->post_content );

	if ( $pure_read ) {
		foreach ( $markers as $meta_key => $builder ) {
			$value = aafm_meta_get( 'post', $post_id, (string) $meta_key, true );
			if ( '' === $value || false === $value || null === $value || '0' === $value || 'off' === $value ) {
				continue; // Present-but-falsy (e.g. Divi toggled off) is not current ownership.
			}
			return (string) $builder;
		}
		$views = array();
		foreach ( $bricks as $key ) {
			$views[ $key ] = array( aafm_meta_get( 'post', $post_id, $key, true ) );
		}
		if ( aafm_page_builder_bricks_owned( $views[ $bricks[0] ], array_merge( $views[ $bricks[1] ], $views[ $bricks[2] ], $views[ $bricks[3] ] ) ) ) {
			return 'bricks';
		}
		return $wpbakery ? 'wpbakery' : false;
	}

	$stored = aafm_meta_rows( 'post', $post_id, array_merge( array_map( 'strval', array_keys( $markers ) ), $bricks ) );
	if ( ! $stored['ok'] ) {
		return AAFM_BUILDER_OWNERSHIP_UNKNOWN;
	}
	foreach ( $stored['by_key'] as $rows ) {
		if ( $rows['aliased'] > 0 ) {
			return AAFM_BUILDER_OWNERSHIP_UNKNOWN;
		}
	}

	// Bricks and WPBakery count as one more owner each, so two builders still answer unknown.
	$views = array();
	foreach ( $bricks as $key ) {
		$views[ $key ] = array( $stored['by_key'][ $key ]['value'] ?? null, aafm_meta_get( 'post', $post_id, $key, true ) );
	}
	$owners = array();
	if ( aafm_page_builder_bricks_owned( $views[ $bricks[0] ], array_merge( $views[ $bricks[1] ], $views[ $bricks[2] ], $views[ $bricks[3] ] ) ) ) {
		$owners[] = 'bricks';
	}
	if ( $wpbakery ) {
		$owners[] = 'wpbakery';
	}

	$owner = false;
	foreach ( $markers as $meta_key => $builder ) {
		$meta_key = (string) $meta_key;
		$on       = false;
		foreach ( array( $stored['by_key'][ $meta_key ]['value'] ?? null, aafm_meta_get( 'post', $post_id, $meta_key, true ) ) as $value ) {
			if ( ! ( '' === $value || false === $value || null === $value || '0' === $value || 'off' === $value ) ) {
				$on = true; // Either view holding a value that is not off counts.
			}
		}
		if ( ! $on ) {
			continue;
		}
		if ( false !== $owner && (string) $builder !== $owner ) {
			return AAFM_BUILDER_OWNERSHIP_UNKNOWN;
		}
		$owner = (string) $builder;
	}

	foreach ( $owners as $builder ) {
		if ( false !== $owner && $builder !== $owner ) {
			return AAFM_BUILDER_OWNERSHIP_UNKNOWN;
		}
		$owner = $builder;
	}

	return $owner;
}

/**
 * The WPBakery layout pattern: a root row, section or container shortcode in post_content.
 *
 * Matched as text, never through has_shortcode(), because WPBakery's tags may not be registered in
 * an ability request. `\b` stops at the underscore of [vc_row_inner], and an inner row always sits
 * inside a root this matches. The `_wpb_vc_js_status` meta is never read: it can say 'false' while
 * the shortcodes still render.
 *
 * @return string
 */
function aafm_page_builder_content_pattern(): string {
	return '/\[vc_(row|section|flexbox_container|grid_container)\b/';
}

/**
 * The Bricks keys: the editor mode first, then the three stored areas.
 *
 * Bricks keeps its data after a page is switched back to Render with WordPress, so the mode vetoes
 * it. Read in the same query as the markers, and hard-blocked from the meta tools beside them, since
 * an agent clearing the mode would pass the guard.
 *
 * @return string[]
 */
function aafm_page_builder_bricks_keys(): array {
	return array( '_bricks_editor_mode', '_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2' );
}

/**
 * Whether Bricks owns a post, from every view of its mode and area values.
 *
 * False when every mode seen is the Render with WordPress value. Otherwise true when any area holds
 * a non-empty array.
 *
 * @param array<int,mixed> $modes Every view's editor mode value.
 * @param array<int,mixed> $areas Every view's area values.
 * @return bool
 */
function aafm_page_builder_bricks_owned( array $modes, array $areas ): bool {
	$seen = array_values( array_filter( $modes, static fn( $mode ): bool => '' !== (string) ( is_scalar( $mode ) ? $mode : '' ) ) );
	if ( array() !== $seen && array() === array_diff( array_map( 'strval', $seen ), array( 'wordpress' ) ) ) {
		return false;
	}
	foreach ( $areas as $area ) {
		$area = maybe_unserialize( $area );
		if ( is_array( $area ) && array() !== $area ) {
			return true;
		}
	}
	return false;
}

/**
 * The page-builder ownership marker map: meta key => detected builder's short name.
 *
 * Extracted to its own function so aafm_hard_blocked_meta_key() (includes/helpers.php) can hard-
 * block every configured marker key from the generic post-meta abilities
 * HIGH: `et_pb_use_builder`/`fusion_builder_status`/`fusion_builder_converted` are NOT `_`-prefixed
 * so is_protected_meta() never protects them, and neither did the built-in hard-block list, so an
 * operator who exposes `*` (or one of these keys by name) via the meta allowlist let the agent
 * clear the marker with update-post-meta/delete-post-meta and then have aafm_exec_update_post()'s
 * ownership check pass on the very next call, writing through the guard entirely.
 * `_elementor_data`/`_fl_builder_data` were already covered by is_protected_meta()'s leading-
 * underscore rule; blocking the whole map here rather than only the two gap keys keeps this
 * automatically correct for any marker added later through the aafm_page_builder_markers filter.
 *
 * `vcv-pageContent`'s marker was confirmed against the real plugin zip from wordpress.org (Visual
 * Composer Website Builder, free edition, slug `visualcomposer`, stable 45.16.2): read at
 * Helpers/PostType.php:67, written at Modules/Editors/DataAjax/Controller.php:399, non-empty JSON
 * means the builder owns the post. This builder is a hybrid: it also writes rendered output into
 * post_content on every editor save, so an unguarded write here would not simply be invisible -
 * it desyncs from vcv-pageContent and is reverted the next time someone opens the builder and
 * saves. The guard still refuses the write; the failure mode is just "the change disappears
 * later" rather than "the change never appears".
 *
 * @return array<string,string> Meta key => builder short name.
 */
function aafm_page_builder_markers(): array {
	return apply_filters( 'aafm_page_builder_markers', aafm_page_builder_default_markers() );
}

/**
 * The markers this plugin ships, before any site filter. The one array both the guard and the
 * builder names in the ability descriptions read, so the text cannot drift from what is enforced.
 *
 * @return array<string,string> Meta key => builder short name.
 */
function aafm_page_builder_default_markers(): array {
	return array(
		'_elementor_data'          => 'elementor',
		'et_pb_use_builder'        => 'divi',
		'_fl_builder_data'         => 'beaver-builder',
		'fusion_builder_status'    => 'avada',
		'fusion_builder_converted' => 'avada',
		'vcv-pageContent'          => 'visual-composer',
		'_ct_builder_json'         => 'oxygen',
		'_ct_builder_shortcodes'   => 'oxygen',
		'ct_builder_json'          => 'oxygen',
		'ct_builder_shortcodes'    => 'oxygen',
		'_oxygen_data'             => 'oxygen',
		'_breakdance_data'         => 'breakdance',
		'breakdance_data'          => 'breakdance',
		'brizy_enabled'            => 'brizy',
	);
}

/**
 * The display name of a builder short name ("beaver-builder" becomes "Beaver Builder").
 *
 * @param string $slug Builder short name from the marker map.
 * @return string
 */
function aafm_page_builder_label( string $slug ): string {
	if ( 'wpbakery' === $slug ) {
		return 'WPBakery';
	}
	return ucwords( str_replace( '-', ' ', $slug ) );
}

/**
 * The builders the shipped guard covers as a sentence list, for ability descriptions. Built from the
 * unfiltered defaults on purpose: a site filter that adds a marker must not change the registry text
 * that is catalogued and translated for every site.
 *
 * @return string For example "Elementor, Divi, Beaver Builder, and WPBakery".
 */
function aafm_page_builder_names(): string {
	$labels = array();
	foreach ( aafm_page_builder_slugs() as $slug ) {
		$labels[] = aafm_page_builder_label( $slug );
	}
	return wp_sprintf_l( '%l', $labels );
}

/**
 * Every builder short name the shipped guard covers: the marker builders, then Bricks and WPBakery,
 * which are detected without a flat marker.
 *
 * @return string[]
 */
function aafm_page_builder_slugs(): array {
	return array_merge( array_map( 'strval', array_unique( array_values( aafm_page_builder_default_markers() ) ) ), array( 'bricks', 'wpbakery' ) );
}

/**
 * The shared refusal error every content-write call site returns identically when the target
 * post is owned by a foreign page builder.
 *
 * Wording covers BOTH outcomes an unguarded write could have, not only "no visible effect":
 * Avada/Fusion Builder is the one covered builder that DOES render from post_content (Fusion
 * shortcodes), so an unguarded generic write there would alter or corrupt the shortcode tree
 * rather than silently do nothing.
 *
 * @param string $builder The detected builder's short name, or AAFM_BUILDER_OWNERSHIP_UNKNOWN (from
 *                        aafm_post_has_foreign_builder_ownership()).
 * @return WP_Error
 */
function aafm_page_builder_owned_error( string $builder ): WP_Error {
	if ( AAFM_BUILDER_OWNERSHIP_UNKNOWN === $builder ) {
		return new WP_Error(
			'aafm_page_builder_owned',
			__( 'This content may belong to a page builder, and the plugin could not tell which one, so it refused the write. Edit the content in the page builder directly, or try again.', 'agent-abilities-for-mcp' ),
			array( 'status' => 409 )
		);
	}

	$label = aafm_page_builder_label( $builder );
	return new WP_Error(
		'aafm_page_builder_owned',
		sprintf(
			/* translators: 1: detected page builder name, e.g. Elementor. 2: same name, repeated for readability. 3: same name, repeated for readability. */
			__( 'This content belongs to %1$s. Writing to it through this ability could have no visible effect or damage %2$s\'s layout, because the builder controls how this content is stored and shown. Edit it in %3$s directly.', 'agent-abilities-for-mcp' ),
			$label,
			$label,
			$label
		),
		array( 'status' => 409 )
	);
}
