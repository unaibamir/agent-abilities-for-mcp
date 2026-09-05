<?php
/**
 * Page-builder content-ownership detection.
 *
 * A post whose content is owned by a page builder that does not use post_content as its
 * rendering source (Elementor, Divi, Beaver Builder) will silently ignore a write to
 * post_content/post_excerpt made through this plugin's own content abilities - the builder's own
 * stored data (a separate meta key, or an external asset file) is what actually renders, so the
 * agent's edit appears to succeed but has no visible effect. This is a REFUSAL guard, not a
 * builder integration: it never edits builder-owned data, it only stops a write from landing
 * somewhere it will be silently ignored.
 *
 * OptimizePress is one of the four builders locked by the release spec, but it is a paid,
 * non-wordpress.org plugin whose post-meta ownership marker could not be verified against any
 * public source (no wordpress.org SVN checkout, no public developer docs found, no plugin copy
 * available in this repo) - see 228-plan-1-7-4-features.md Amendment 22. This is recorded as an
 * open scope gap below, not shipped as a guessed marker.
 *
 * Avada/Fusion Builder's marker WAS confirmed (2026-09-05) against a real installed copy (Avada
 * 7.16.1 theme, Fusion Builder 3.16.1 plugin): `fusion_builder_status` post meta set to 'active'
 * (inc/class-fusion-builder.php:1736, read back at :1751/:2723/:2732 to decide whether a page is
 * builder-owned) and `fusion_builder_converted` set to 'yes' for content migrated to the modern
 * builder (same file, same read sites). 228-avada-guard-design.md, written without a live install,
 * had assumed no such key existed and scoped its own guard to post_content alone - this is the
 * "new information" it said would require a follow-up, not a gap in that design. Both keys are
 * added below so the GENERIC guard also refuses a write on an Avada-owned post, exactly like
 * Elementor/Divi/Beaver Builder; aafm/avada-replace-text (includes/abilities/avada.php) is the one
 * exception that allows a text-only edit, via its own shortcode-tree signature check rather than
 * this blanket refusal.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Whether a post's content is owned by a detected foreign page builder.
 *
 * OptimizePress is NOT included: no public source (wordpress.org SVN, developer docs, or a
 * plugin copy) was available to verify its actual ownership marker, and shipping a guessed meta
 * key would be worse than shipping nothing - a wrong key would refuse ordinary writes on
 * uninvolved posts, or (if it happens to never match) give operators false confidence the guard
 * covers a builder it does not. Filterable via aafm_page_builder_markers so the operator, or a
 * future patch once a real marker is confirmed, can add it without a code change.
 *
 * @param int $post_id Post id.
 * @return string|false The detected builder's short name, or false when none is detected.
 */
function aafm_post_has_foreign_builder_ownership( int $post_id ) {
	foreach ( aafm_page_builder_markers() as $meta_key => $builder ) {
		$value = get_post_meta( $post_id, (string) $meta_key, true );
		if ( '' === $value || false === $value || null === $value || '0' === $value || 'off' === $value ) {
			continue; // Present-but-falsy (e.g. Divi toggled off) is not current ownership.
		}
		return (string) $builder;
	}

	return false;
}

/**
 * The page-builder ownership marker map: meta key => detected builder's short name.
 *
 * Extracted to its own function so aafm_hard_blocked_meta_key() (includes/helpers.php) can hard-
 * block every configured marker key from the generic post-meta abilities - Codex final round 7
 * HIGH: `et_pb_use_builder`/`fusion_builder_status`/`fusion_builder_converted` are NOT `_`-prefixed
 * so is_protected_meta() never protects them, and neither did the built-in hard-block list, so an
 * operator who exposes `*` (or one of these keys by name) via the meta allowlist let the agent
 * clear the marker with update-post-meta/delete-post-meta and then have aafm_exec_update_post()'s
 * ownership check pass on the very next call, writing through the guard entirely.
 * `_elementor_data`/`_fl_builder_data` were already covered by is_protected_meta()'s leading-
 * underscore rule; blocking the whole map here rather than only the two gap keys keeps this
 * automatically correct for any marker added later through the aafm_page_builder_markers filter,
 * including a future OptimizePress marker once one is confirmed.
 *
 * @return array<string,string> Meta key => builder short name.
 */
function aafm_page_builder_markers(): array {
	return apply_filters(
		'aafm_page_builder_markers',
		array(
			'_elementor_data'          => 'elementor',
			'et_pb_use_builder'        => 'divi',
			'_fl_builder_data'         => 'beaver-builder',
			'fusion_builder_status'    => 'avada',
			'fusion_builder_converted' => 'avada',
		)
	);
}

/**
 * The shared refusal error every content-write call site returns identically when the target
 * post is owned by a foreign page builder.
 *
 * Wording covers BOTH outcomes an unguarded write could have, not only "no visible effect":
 * Avada/Fusion Builder is the one covered builder that DOES render from post_content (Fusion
 * shortcodes), so an unguarded generic write there would alter or corrupt the shortcode tree
 * rather than silently do nothing - Codex final round 7 LOW.
 *
 * @param string $builder The detected builder's short name (from aafm_post_has_foreign_builder_ownership()).
 * @return WP_Error
 */
function aafm_page_builder_owned_error( string $builder ): WP_Error {
	$label = ucwords( str_replace( '-', ' ', $builder ) );
	return new WP_Error(
		'aafm_page_builder_owned',
		sprintf(
			/* translators: 1: detected page builder name, e.g. Elementor. 2: same name, repeated for readability. 3: same name, repeated for readability. */
			__( 'This content is owned by %1$s. Writing to it through this ability would either have no visible effect or corrupt %2$s\'s own stored markup, because %3$s renders from its own stored data rather than (or in addition to) the post content field. Edit it there directly.', 'agent-abilities-for-mcp' ),
			$label,
			$label,
			$label
		),
		array( 'status' => 409 )
	);
}
