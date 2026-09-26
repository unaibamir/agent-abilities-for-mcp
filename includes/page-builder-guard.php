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
	$markers = aafm_page_builder_markers();

	if ( $pure_read ) {
		foreach ( $markers as $meta_key => $builder ) {
			$value = aafm_meta_get( 'post', $post_id, (string) $meta_key, true );
			if ( '' === $value || false === $value || null === $value || '0' === $value || 'off' === $value ) {
				continue; // Present-but-falsy (e.g. Divi toggled off) is not current ownership.
			}
			return (string) $builder;
		}
		return false;
	}

	$stored = aafm_meta_rows( 'post', $post_id, array_map( 'strval', array_keys( $markers ) ) );
	if ( ! $stored['ok'] ) {
		return AAFM_BUILDER_OWNERSHIP_UNKNOWN;
	}
	foreach ( $stored['by_key'] as $rows ) {
		if ( $rows['aliased'] > 0 ) {
			return AAFM_BUILDER_OWNERSHIP_UNKNOWN;
		}
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

	return $owner;
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
	return apply_filters(
		'aafm_page_builder_markers',
		array(
			'_elementor_data'          => 'elementor',
			'et_pb_use_builder'        => 'divi',
			'_fl_builder_data'         => 'beaver-builder',
			'fusion_builder_status'    => 'avada',
			'fusion_builder_converted' => 'avada',
			'vcv-pageContent'          => 'visual-composer',
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
