<?php
/**
 * Playbooks: step-by-step instructions the site owner writes for agents.
 *
 * The library is one option row, autoload off, written only from wp-admin (the Playbooks tab).
 * No ability, REST route or post type reaches it, so an agent can never change the instructions it
 * is given. Agents read Active playbooks through aafm/list-playbooks and aafm/get-playbook.
 *
 * Reads come from the database row, not the object cache, so a stale persistent-cache copy never
 * serves a deleted or edited playbook. A row that cannot be read is a failure: nothing is served
 * and every write refuses, so a read error can never be mistaken for an empty library and
 * overwrite it.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const AAFM_PLAYBOOKS_OPTION       = 'aafm_playbooks';
const AAFM_PLAYBOOKS_MAX          = 50;
const AAFM_PLAYBOOK_TITLE_MAX     = 80;
const AAFM_PLAYBOOK_DESC_MAX      = 200;
const AAFM_PLAYBOOK_BODY_MAX      = 8000;
const AAFM_PLAYBOOK_SLUG_MAX      = 64;
const AAFM_PLAYBOOK_ABILITIES_MAX = 50;
const AAFM_PLAYBOOK_LIBRARY_URL   = 'https://agentabilitieswp.com/playbooks/';

/**
 * Clean a playbook body: the text exactly as typed, with LF line endings and without invalid UTF-8,
 * control characters or invisible and bidirectional override characters. Markup-like text is kept
 * as text: the body only ever leaves as JSON or through an escaped attribute, never as HTML.
 *
 * @param string $raw Raw text.
 * @return string
 */
function aafm_clean_playbook_body( string $raw ): string {
	$text = str_replace( aafm_unsafe_text_characters(), '', wp_check_invalid_utf8( $raw ) );

	return trim( (string) preg_replace( '/\r\n|\r/', "\n", $text ) );
}

/**
 * Clean one stored entry on read. A hand-written row is held to the same rules as a save; text
 * that is too long is cut rather than refused, an entry with no usable slug, title or body is
 * dropped.
 *
 * @param mixed $entry Stored entry.
 * @return array{slug:string,title:string,description:string,body:string,active:bool,abilities:list<string>,updated_gmt:string}|null
 */
function aafm_clean_stored_playbook( $entry ): ?array {
	if ( ! is_array( $entry ) ) {
		return null;
	}
	$slug = isset( $entry['slug'] ) && is_string( $entry['slug'] ) ? $entry['slug'] : '';
	if ( ! preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) ) {
		return null;
	}
	$title = isset( $entry['title'] ) && is_string( $entry['title'] ) ? mb_substr( aafm_sanitize_plain_text( $entry['title'] ), 0, AAFM_PLAYBOOK_TITLE_MAX ) : '';
	$body  = isset( $entry['body'] ) && is_string( $entry['body'] ) ? mb_substr( aafm_clean_playbook_body( $entry['body'] ), 0, AAFM_PLAYBOOK_BODY_MAX ) : '';
	if ( '' === trim( $title ) || '' === $body ) {
		return null;
	}
	$description = isset( $entry['description'] ) && is_string( $entry['description'] ) ? mb_substr( aafm_sanitize_plain_text( $entry['description'] ), 0, AAFM_PLAYBOOK_DESC_MAX ) : '';
	$updated     = isset( $entry['updated_gmt'] ) && is_string( $entry['updated_gmt'] ) && preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $entry['updated_gmt'] ) ? $entry['updated_gmt'] : '';

	return array(
		'slug'        => $slug,
		'title'       => trim( $title ),
		'description' => trim( $description ),
		'body'        => $body,
		'active'      => ! empty( $entry['active'] ),
		'abilities'   => aafm_clean_stored_playbook_abilities( $entry['abilities'] ?? null ),
		'updated_gmt' => $updated,
	);
}

/**
 * Read the library from the database row.
 *
 * @return array{ok:bool,list:list<array{slug:string,title:string,description:string,body:string,active:bool,updated_gmt:string}>}
 *         ok is false when the row could not be read; an absent row is ok with an empty list.
 */
function aafm_playbooks_read(): array {
	$views = aafm_read_option_views( AAFM_PLAYBOOKS_OPTION );
	// A row that cannot be read, or one that is present but not a list, is a failed read: nothing is
	// served and writes refuse, so a corrupt row is never mistaken for an empty library.
	if ( $views['db_error'] || ( $views['db_found'] && ! is_array( $views['db_value'] ) ) ) {
		return array(
			'ok'   => false,
			'list' => array(),
		);
	}
	$list = array();
	$seen = array();
	if ( $views['db_found'] ) {
		foreach ( $views['db_value'] as $entry ) {
			$clean = aafm_clean_stored_playbook( $entry );
			if ( null === $clean || isset( $seen[ $clean['slug'] ] ) ) {
				continue;
			}
			$seen[ $clean['slug'] ] = true;
			$list[]                 = $clean;
		}
	}

	return array(
		'ok'   => true,
		'list' => $list,
	);
}

/**
 * Every stored playbook, Active or not, in stored order. Empty when the row cannot be read.
 *
 * @return list<array{slug:string,title:string,description:string,body:string,active:bool,updated_gmt:string}>
 */
function aafm_playbooks(): array {
	// ponytail: one extra query per read; reads happen only on the Playbooks tab, the two abilities and initialize.
	return aafm_playbooks_read()['list'];
}

/**
 * One playbook by slug.
 *
 * @param string $slug Slug.
 * @return array{slug:string,title:string,description:string,body:string,active:bool,updated_gmt:string}|null
 */
function aafm_playbook( string $slug ): ?array {
	foreach ( aafm_playbooks() as $playbook ) {
		if ( $playbook['slug'] === $slug ) {
			return $playbook;
		}
	}
	return null;
}

/**
 * Clean and check the fields an owner submits.
 *
 * @param array<string,mixed> $raw Fields title, description, body, active.
 * @return array{title:string,description:string,body:string,active:bool,abilities:list<string>}|WP_Error
 */
function aafm_clean_playbook_input( array $raw ) {
	$title       = trim( aafm_sanitize_plain_text( is_string( $raw['title'] ?? null ) ? $raw['title'] : '' ) );
	$description = trim( aafm_sanitize_plain_text( is_string( $raw['description'] ?? null ) ? $raw['description'] : '' ) );
	$body        = aafm_clean_playbook_body( is_string( $raw['body'] ?? null ) ? $raw['body'] : '' );

	if ( '' === $title || mb_strlen( $title ) > AAFM_PLAYBOOK_TITLE_MAX ) {
		/* translators: %d: the longest title allowed, in characters. */
		return new WP_Error( 'aafm_playbook_invalid', sprintf( __( 'Give the playbook a title of 1 to %d characters.', 'agent-abilities-for-mcp' ), AAFM_PLAYBOOK_TITLE_MAX ) );
	}
	if ( mb_strlen( $description ) > AAFM_PLAYBOOK_DESC_MAX ) {
		/* translators: %d: the longest description allowed, in characters. */
		return new WP_Error( 'aafm_playbook_invalid', sprintf( __( 'Keep the description to %d characters or fewer.', 'agent-abilities-for-mcp' ), AAFM_PLAYBOOK_DESC_MAX ) );
	}
	$abilities = aafm_clean_playbook_abilities( $raw['abilities'] ?? array() );
	if ( is_wp_error( $abilities ) ) {
		return $abilities;
	}
	if ( '' === $body || mb_strlen( $body ) > AAFM_PLAYBOOK_BODY_MAX ) {
		/* translators: %s: the longest body allowed, in characters, for example 8,000. */
		return new WP_Error( 'aafm_playbook_invalid', sprintf( __( 'Write the steps in 1 to %s characters.', 'agent-abilities-for-mcp' ), number_format_i18n( AAFM_PLAYBOOK_BODY_MAX ) ) );
	}

	return array(
		'title'       => $title,
		'description' => $description,
		'body'        => $body,
		'active'      => ! empty( $raw['active'] ) && 'false' !== $raw['active'],
		'abilities'   => $abilities,
	);
}

/**
 * The abilities list kept on a stored entry: well-formed names only, de-duplicated, at most
 * AAFM_PLAYBOOK_ABILITIES_MAX. No registry check, so a pick for an inactive integration survives and
 * is reported unavailable.
 *
 * @param mixed $stored Stored value.
 * @return list<string>
 */
function aafm_clean_stored_playbook_abilities( $stored ): array {
	if ( ! is_array( $stored ) ) {
		return array();
	}
	$out = array();
	foreach ( $stored as $name ) {
		if ( is_string( $name ) && preg_match( '#^aafm(-bridge)?/[a-z0-9-]{1,100}$#', $name ) && ! in_array( $name, $out, true ) ) {
			$out[] = $name;
		}
	}
	return array_slice( $out, 0, AAFM_PLAYBOOK_ABILITIES_MAX );
}

/**
 * Clean the abilities an owner picked: a list, or a JSON string of a list (the AJAX shape). Names
 * that are not abilities of this plugin, or enabled bridged abilities, are dropped.
 *
 * @param mixed $raw Picked names.
 * @return list<string>|WP_Error
 */
function aafm_clean_playbook_abilities( $raw ) {
	if ( is_string( $raw ) ) {
		$raw = json_decode( $raw, true );
	}
	if ( ! is_array( $raw ) ) {
		return array();
	}
	$known = array_merge( array_keys( aafm_get_abilities_registry_full() ), aafm_get_enabled_bridged_abilities() );
	$out   = array();
	foreach ( $raw as $name ) {
		if ( is_string( $name ) && in_array( $name, $known, true ) && ! in_array( $name, $out, true ) ) {
			$out[] = $name;
		}
	}
	if ( count( $out ) > AAFM_PLAYBOOK_ABILITIES_MAX ) {
		/* translators: %d: the most abilities one playbook can list. */
		return new WP_Error( 'aafm_playbook_invalid', sprintf( __( 'Choose up to %d abilities for one playbook.', 'agent-abilities-for-mcp' ), AAFM_PLAYBOOK_ABILITIES_MAX ) );
	}
	return $out;
}

/**
 * A new, unique slug for a title: ASCII only, at most AAFM_PLAYBOOK_SLUG_MAX characters.
 *
 * @param string   $title Clean title.
 * @param string[] $taken Slugs already in use.
 * @return string
 */
function aafm_playbook_new_slug( string $title, array $taken ): string {
	$base = sanitize_title( remove_accents( $title ) );
	$base = (string) preg_replace( '/%[0-9a-f]{2}/i', '', $base );
	$base = (string) preg_replace( '/[^a-z0-9-]/', '', $base );
	$base = trim( (string) preg_replace( '/-+/', '-', $base ), '-' );
	if ( '' === $base ) {
		$base = 'playbook';
	}
	$slug = rtrim( substr( $base, 0, AAFM_PLAYBOOK_SLUG_MAX ), '-' );
	$n    = 2;
	while ( in_array( $slug, $taken, true ) ) {
		$suffix = '-' . $n;
		++$n;
		$slug = rtrim( substr( $base, 0, AAFM_PLAYBOOK_SLUG_MAX - strlen( $suffix ) ), '-' ) . $suffix;
	}
	return $slug;
}

/**
 * Create (empty $slug) or update a playbook. Re-reads the row right before writing and applies the
 * one change to that fresh list by slug.
 *
 * @param array<string,mixed> $raw        Fields title, description, body, active.
 * @param string              $slug       Slug to update, '' to create.
 * @param string              $log_action Activity Log action, '' for added or edited.
 * @return array{slug:string,title:string,description:string,body:string,active:bool,updated_gmt:string}|WP_Error
 */
function aafm_save_playbook( array $raw, string $slug = '', string $log_action = '' ) {
	$action = '' !== $log_action ? $log_action : ( '' === $slug ? 'added' : 'edited' );
	$clean  = aafm_clean_playbook_input( $raw );
	if ( is_wp_error( $clean ) ) {
		aafm_log_playbook_change( $action, $slug, false );
		return $clean;
	}

	// ponytail: last save wins when two administrators save at once; add a per-entry revision check if users report lost edits.
	$read = aafm_playbooks_read();
	if ( ! $read['ok'] ) {
		aafm_log_playbook_change( $action, $slug, false );
		return aafm_playbook_not_saved_error();
	}
	$list = $read['list'];

	if ( '' === $slug ) {
		if ( count( $list ) >= AAFM_PLAYBOOKS_MAX ) {
			aafm_log_playbook_change( $action, '', false );
			/* translators: %d: the most playbooks a site can keep. */
			return new WP_Error( 'aafm_playbook_limit', sprintf( __( 'This site already has %d playbooks, the most it can keep. Delete one first.', 'agent-abilities-for-mcp' ), AAFM_PLAYBOOKS_MAX ) );
		}
		$slug  = aafm_playbook_new_slug( $clean['title'], array_column( $list, 'slug' ) );
		$index = count( $list );
	} else {
		$index = array_search( $slug, array_column( $list, 'slug' ), true );
		if ( false === $index ) {
			aafm_log_playbook_change( $action, $slug, false );
			return aafm_playbook_admin_not_found_error();
		}
	}

	$entry          = array_merge( array( 'slug' => $slug ), $clean, array( 'updated_gmt' => current_time( 'mysql', true ) ) );
	$list[ $index ] = $entry;

	if ( ! aafm_update_option_verified( AAFM_PLAYBOOKS_OPTION, array_values( $list ), false ) ) {
		aafm_log_playbook_change( $action, $slug, false );
		return aafm_playbook_not_saved_error();
	}
	aafm_log_playbook_change( $action, $slug, true );

	return $entry;
}

/**
 * Delete a playbook. Deleting the last one deletes the row.
 *
 * @param string $slug Slug.
 * @return true|WP_Error
 */
function aafm_delete_playbook( string $slug ) {
	$read = aafm_playbooks_read();
	if ( ! $read['ok'] ) {
		aafm_log_playbook_change( 'deleted', $slug, false );
		return aafm_playbook_not_saved_error();
	}
	$list  = $read['list'];
	$index = array_search( $slug, array_column( $list, 'slug' ), true );
	if ( false === $index ) {
		aafm_log_playbook_change( 'deleted', $slug, false );
		return aafm_playbook_admin_not_found_error();
	}
	unset( $list[ $index ] );

	$ok = array() === $list
		? aafm_delete_option_cache_safe( AAFM_PLAYBOOKS_OPTION )
		: aafm_update_option_verified( AAFM_PLAYBOOKS_OPTION, array_values( $list ), false );
	aafm_log_playbook_change( 'deleted', $slug, $ok );

	return $ok ? true : aafm_playbook_not_saved_error();
}

/**
 * The refusal for a save or delete that did not reach the database.
 *
 * @return WP_Error
 */
function aafm_playbook_not_saved_error(): WP_Error {
	return new WP_Error( 'aafm_playbook_not_saved', __( 'The playbook could not be saved. Reload the page and try again.', 'agent-abilities-for-mcp' ) );
}

/**
 * The refusal the Playbooks tab shows when the playbook it edits or deletes is gone.
 *
 * @return WP_Error
 */
function aafm_playbook_admin_not_found_error(): WP_Error {
	return new WP_Error( 'aafm_playbook_not_found', __( 'That playbook no longer exists. Reload the page to see the current list.', 'agent-abilities-for-mcp' ) );
}

/**
 * The refusal an agent gets for a slug with no Active playbook.
 *
 * @param string $slug Slug as given.
 * @return WP_Error
 */
function aafm_playbook_not_found_error( string $slug ): WP_Error {
	/* translators: %s: the playbook slug. */
	return new WP_Error( 'aafm_playbook_not_found', sprintf( __( 'No active playbook is called "%s".', 'agent-abilities-for-mcp' ), substr( sanitize_title( $slug ), 0, AAFM_PLAYBOOK_SLUG_MAX ) ) );
}

/**
 * Record a change to the library in the Activity Log. Names the slug, never the title or body.
 *
 * @param string $action One of added, edited, deleted, added_starter.
 * @param string $slug   Slug.
 * @param bool   $ok     Whether the change was stored.
 * @return void
 */
function aafm_log_playbook_change( string $action, string $slug, bool $ok ): void {
	$labels = array(
		/* translators: %s: the playbook slug. */
		'added'         => __( 'Playbook added: %s', 'agent-abilities-for-mcp' ),
		/* translators: %s: the playbook slug. */
		'edited'        => __( 'Playbook edited: %s', 'agent-abilities-for-mcp' ),
		/* translators: %s: the playbook slug. */
		'deleted'       => __( 'Playbook deleted: %s', 'agent-abilities-for-mcp' ),
		/* translators: %s: the playbook slug. */
		'added_starter' => __( 'Starter playbook added: %s', 'agent-abilities-for-mcp' ),
	);
	$label  = $labels[ $action ] ?? $labels['edited'];
	$user   = wp_get_current_user();
	aafm_log_activity(
		array(
			'ability'           => 'aafm/playbooks',
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
			'status'            => $ok ? 'success' : 'error',
			'event_type'        => 'setting_changed',
			'detail'            => sprintf( $label, '' !== $slug ? $slug : '-' ),
		)
	);
}

/**
 * The playbook index appended to the server instructions on initialize: '' unless at least one
 * playbook is Active, both playbook abilities are enabled and the current principal can discover
 * both. Otherwise a heading plus one "- slug: description" line per Active playbook, cut at 600
 * characters on a line boundary with a closing "and N more" line.
 *
 * @return string
 */
function aafm_playbook_index(): string {
	$active = array_values(
		array_filter(
			aafm_playbooks(),
			static function ( array $playbook ): bool {
				return $playbook['active'];
			}
		)
	);
	if ( array() === $active ) {
		return '';
	}
	$enabled = aafm_get_enabled_abilities();
	foreach ( array( 'aafm/list-playbooks', 'aafm/get-playbook' ) as $name ) {
		if ( ! in_array( $name, $enabled, true ) || ! aafm_user_can_discover_ability( $name ) ) {
			return '';
		}
	}

	$lines = array( __( 'Playbooks the site owner wrote for this site. When a task matches one, load it with get-playbook and follow it:', 'agent-abilities-for-mcp' ) );
	$total = count( $active );
	$more  = static function ( int $left ): string {
		/* translators: %d: how many playbooks are not listed. */
		return sprintf( __( '- and %d more: call list-playbooks.', 'agent-abilities-for-mcp' ), $left );
	};
	foreach ( $active as $i => $playbook ) {
		$line      = '- ' . $playbook['slug'] . ': ' . ( '' !== $playbook['description'] ? $playbook['description'] : $playbook['title'] );
		$left      = $total - $i - 1;
		$candidate = array_merge( $lines, array( $line ), $left > 0 ? array( $more( $left ) ) : array() );
		if ( mb_strlen( implode( "\n", $candidate ) ) > 600 ) {
			$lines[] = $more( $total - $i );
			return implode( "\n", $lines );
		}
		$lines[] = $line;
	}
	return implode( "\n", $lines );
}

/**
 * The starter playbooks the Playbooks tab offers, keyed by starter id.
 *
 * Filter `aafm_playbook_starters` may ADD starters; the free plugin never hides or locks one. The
 * three built-in starters are restored after the filter, so a filter cannot remove or replace them.
 * Added entries must pass the same cleaning a save does or they are dropped. `requires` lists plugin
 * markers ('' or 'woocommerce') and is display-only: it is never stored with a playbook.
 *
 * @return array<string,array{title:string,description:string,body:string,requires:list<string>}>
 */
function aafm_playbook_starters(): array {
	$built_in = array(
		'publish-checklist'     => array(
			'title'       => __( 'Publish checklist', 'agent-abilities-for-mcp' ),
			'description' => __( 'Check a draft before it goes out and report what is missing. Never publishes.', 'agent-abilities-for-mcp' ),
			'body'        => __( "Use this when the user asks you to check a draft before it is published.\n\n1. Read the draft with aafm/get-post.\n2. Check the title: say so if it is longer than about 60 characters or does not say what the post is about.\n3. Check the excerpt: say so if it is empty or just repeats the first paragraph.\n4. Check the featured image. If there is none, say so. Set one with aafm/set-featured-image only if the user asks you to.\n5. Check the categories and tags. Read the site's terms with aafm/get-terms and suggest ones that fit; flag a post left in Uncategorized.\n6. Leave the post as a draft. Reply with a short list of what is missing or worth fixing.\n\nNever publish or schedule the post unless the user tells you to.", 'agent-abilities-for-mcp' ),
			'requires'    => array(),
		),
		'weekly-content-report' => array(
			'title'       => __( 'Weekly content report', 'agent-abilities-for-mcp' ),
			'description' => __( 'Posts published, drafts created and comments waiting from the last 7 days, as a short table. Read-only.', 'agent-abilities-for-mcp' ),
			'body'        => __( "Use this when the user asks what happened on the site this week. This playbook only reads; change nothing.\n\n1. Page through aafm/get-posts with status publish. Results come newest first by post date. Keep posts dated within the last 7 days and stop paging once you reach an older one.\n2. Do the same with status draft for drafts created in the last 7 days. An older draft that was only edited this week is out of scope, because the list is ordered by post date, not by the date it was last changed.\n3. Page through aafm/get-pending-comments and keep the comments from the last 7 days.\n4. If a paging limit stops you before you reach 7 days back, say so plainly instead of guessing.\n5. Reply with one short table: published posts, new drafts and pending comments, with a count and the titles for each.\n\nIf the user also wants all-time totals, use aafm/count-posts and label those numbers as all-time, not weekly.", 'agent-abilities-for-mcp' ),
			'requires'    => array(),
		),
		'shop-tidy-up'          => array(
			'title'       => __( 'Shop tidy-up', 'agent-abilities-for-mcp' ),
			'description' => __( 'Find WooCommerce products with no price, no image, no stock or still in draft, and propose fixes.', 'agent-abilities-for-mcp' ),
			'body'        => __( "Use this when the user asks you to tidy up the shop. Propose changes; do not make them.\n\n1. Page through aafm/wc-list-products.\n2. List the products with no price, the ones out of stock and the ones still in draft.\n3. For products you need more detail on, such as whether there is a main image, read them with aafm/wc-get-product.\n4. Reply with one table per problem: product name, ID and what to fix.\n5. Suggest the fix for each one. Change a product with aafm/wc-update-product only after the user confirms that exact change.", 'agent-abilities-for-mcp' ),
			'requires'    => array( 'woocommerce' ),
		),
	);

	$filtered = apply_filters( 'aafm_playbook_starters', $built_in );
	$out      = $built_in;
	if ( is_array( $filtered ) ) {
		foreach ( $filtered as $id => $starter ) {
			if ( ! is_string( $id ) || isset( $built_in[ $id ] ) || ! is_array( $starter ) ) {
				continue;
			}
			$clean = aafm_clean_playbook_input( $starter );
			if ( is_wp_error( $clean ) ) {
				continue;
			}
			$requires   = isset( $starter['requires'] ) && is_array( $starter['requires'] ) ? $starter['requires'] : array();
			$out[ $id ] = array(
				'title'       => $clean['title'],
				'description' => $clean['description'],
				'body'        => $clean['body'],
				'requires'    => array_values( array_intersect( $requires, array( '', 'woocommerce' ) ) ),
			);
		}
	}
	return $out;
}

/**
 * Add a starter to the library as an ordinary playbook, switched Off.
 *
 * @param string $starter_id Starter id.
 * @return array{slug:string,title:string,description:string,body:string,active:bool,updated_gmt:string}|WP_Error
 */
function aafm_add_starter_playbook( string $starter_id ) {
	$starters = aafm_playbook_starters();
	if ( ! isset( $starters[ $starter_id ] ) ) {
		return new WP_Error( 'aafm_playbook_starter_unknown', __( 'That starter playbook does not exist.', 'agent-abilities-for-mcp' ) );
	}
	$starter = $starters[ $starter_id ];
	return aafm_save_playbook(
		array(
			'title'       => $starter['title'],
			'description' => $starter['description'],
			'body'        => $starter['body'],
			'active'      => false,
		),
		'',
		'added_starter'
	);
}
