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
 * Clean a playbook body: the text exactly as typed (indentation and trailing lines included), with LF
 * line endings and without invalid UTF-8, control characters or invisible and bidirectional override
 * characters. Markup-like text is kept as text: the body only ever leaves as JSON or through an
 * escaped attribute, never as HTML.
 *
 * @param string $raw Raw text.
 * @return string
 */
function aafm_clean_playbook_body( string $raw ): string {
	$text = str_replace( aafm_unsafe_text_characters(), '', wp_check_invalid_utf8( $raw ) );

	return (string) preg_replace( '/\r\n|\r/', "\n", $text );
}

/**
 * Clean one stored entry on read. A hand-written row is held to the same rules as a save; text
 * that is too long is cut rather than refused, an entry with no usable slug, title or body is
 * dropped.
 *
 * @param mixed $entry Stored entry.
 * @return array{slug:string,title:string,description:string,body:string,active:bool,abilities:list<string>,starter:string,updated_gmt:string}|null
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
	if ( '' === trim( $title ) || '' === trim( $body ) ) {
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
		'starter'     => isset( $entry['starter'] ) && is_string( $entry['starter'] ) && preg_match( '/^[a-z0-9_-]{1,64}$/', $entry['starter'] ) ? $entry['starter'] : '',
		'updated_gmt' => $updated,
	);
}

/**
 * Read the library from the database row.
 *
 * @return array{ok:bool,list:list<array{slug:string,title:string,description:string,body:string,active:bool,abilities:list<string>,starter:string,updated_gmt:string}>}
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
 * @return list<array{slug:string,title:string,description:string,body:string,active:bool,abilities:list<string>,starter:string,updated_gmt:string}>
 */
function aafm_playbooks(): array {
	// ponytail: one extra query per read; reads happen only on the Playbooks tab, the two abilities and initialize.
	return aafm_playbooks_read()['list'];
}

/**
 * One playbook by slug.
 *
 * @param string $slug Slug.
 * @return array{slug:string,title:string,description:string,body:string,active:bool,abilities:list<string>,starter:string,updated_gmt:string}|null
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
	if ( '' === trim( $body ) || mb_strlen( $body ) > AAFM_PLAYBOOK_BODY_MAX ) {
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
 * @return array{slug:string,title:string,description:string,body:string,active:bool,abilities:list<string>,starter:string,updated_gmt:string}|WP_Error
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

	// The starter a playbook came from is set once, when it is added, and kept on every edit.
	$new_starter = is_string( $raw['starter'] ?? null ) && preg_match( '/^[a-z0-9_-]{1,64}$/', $raw['starter'] ) ? $raw['starter'] : '';

	if ( '' === $slug ) {
		if ( '' !== $new_starter && in_array( $new_starter, array_column( $list, 'starter' ), true ) ) {
			aafm_log_playbook_change( $action, '', false );
			return new WP_Error( 'aafm_playbook_starter_added', __( 'That starter is already in your playbooks.', 'agent-abilities-for-mcp' ) );
		}
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

	$starter        = isset( $list[ $index ] ) ? $list[ $index ]['starter'] : $new_starter;
	$entry          = array_merge(
		array( 'slug' => $slug ),
		$clean,
		array(
			'starter'     => $starter,
			'updated_gmt' => current_time( 'mysql', true ),
		)
	);
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
 * five built-in starters are restored after the filter, so a filter cannot remove or replace them.
 * An added starter's text must pass the same cleaning a save does or it is dropped; its abilities
 * list is cleaned on its own, so a bad list becomes empty without losing the starter. `requires`
 * lists plugin markers ('' or 'woocommerce') and is display-only: it is never stored.
 *
 * The steps name tools by their registry label ("the Get post tool"); `abilities` lists the names.
 *
 * @return array<string,array{title:string,description:string,body:string,abilities:list<string>,requires:list<string>}>
 */
function aafm_playbook_starters(): array {
	$built_in = array(
		'publish-checklist'     => array(
			'title'       => __( 'Publish checklist', 'agent-abilities-for-mcp' ),
			'description' => __( 'Check one draft before it goes live and list what is missing or worth fixing. Changes nothing unless you say yes. Never publishes.', 'agent-abilities-for-mcp' ),
			'body'        => __( "Use this when the user asks you to check a draft before it is published.\n\n1. Find the draft. If the user named it or gave a link or ID, read it with the Get post tool. If not, list recent drafts with the Get posts tool (status draft) and ask which one to check. Do not guess.\n2. Title. Say so if it is longer than about 60 characters, is vague, or does not match what the post is about.\n3. Content. Look for placeholder text (such as \"lorem ipsum\", \"TODO\" or \"[insert\"), empty headings, very short sections and repeated paragraphs. Quote the exact spot for each problem.\n4. Excerpt. Say so if it is empty, and suggest one of one or two sentences. Do not save it.\n5. Featured image. Say so if there is none. If the user asks you to add one, search the media library with the Get media tool, show up to three options, and set the one the user picks with the Set featured image tool.\n6. Categories and tags. Read the site's categories and tags with the Get terms tool. Flag a post that is only in Uncategorized or has no tags, and suggest existing terms that fit. Add them with the Add post terms tool only after the user says yes to those exact terms. Do not create new terms.\n7. Reply with a short checklist. Mark each item OK or Needs work, with one line on what to fix.\n\nRules:\n- Leave the post as a draft. Never publish, schedule or delete it, even if everything looks fine.\n- Change nothing until the user says yes to that exact change.\n- If a tool is missing or returns an error, say which check you could not do and finish the others.", 'agent-abilities-for-mcp' ),
			'abilities'   => array( 'aafm/get-posts', 'aafm/get-post', 'aafm/get-terms', 'aafm/get-media', 'aafm/set-featured-image', 'aafm/add-post-terms' ),
			'requires'    => array(),
		),
		'weekly-content-report' => array(
			'title'       => __( 'Weekly content report', 'agent-abilities-for-mcp' ),
			'description' => __( 'Posts published, drafts started and comments waiting in the last 7 days, as one short table. Read-only.', 'agent-abilities-for-mcp' ),
			'body'        => __( "Use this when the user asks what happened on the site this week. Only read. Change nothing.\n\n1. Date range. Use the last 7 days up to today. If you do not know today's date, ask the user before you start.\n2. Published posts. List published posts with the Get posts tool. They come newest first by publish date. Keep the ones dated inside the range and stop paging once you reach an older one.\n3. New drafts. Do the same with status draft and keep the drafts created inside the range. A draft created earlier and only edited this week does not show up this way. Say so in one line under the table.\n4. Comments waiting. Page through the Get pending comments tool and keep the comments dated inside the range. The tool also returns a total for the whole queue. Report it as \"waiting in total\".\n5. If paging stops before you reach 7 days back, or a tool is off or fails, say exactly what is missing. Never fill a gap with a guess.\n6. Reply with one table with three rows: Published, New drafts, Comments waiting. Give a count for each, plus the titles (for comments, the post each one is on).\n\nIf the user also asks for totals, use the Count posts tool and label those numbers \"all time\", not \"this week\".", 'agent-abilities-for-mcp' ),
			'abilities'   => array( 'aafm/get-posts', 'aafm/get-pending-comments', 'aafm/count-posts' ),
			'requires'    => array(),
		),
		'content-health-check'  => array(
			'title'       => __( 'Content health check', 'agent-abilities-for-mcp' ),
			'description' => __( 'Scan recent posts, images and terms for common gaps: no featured image, no category, missing alt text, unused tags. Reports only.', 'agent-abilities-for-mcp' ),
			'body'        => __( "Use this when the user asks for a health check of the site's content. Only read. Change nothing.\n\n1. Scope. Check the 100 most recent published posts unless the user asks for a different number. Use the Count posts tool to get the total number of published posts, so you can tell the user how much you covered.\n2. Posts. Page through the Get posts tool (status publish, 50 per page). For each post, note any of these:\n- no featured image\n- only in Uncategorized, or no category at all\n- no tags\n- no excerpt\n- a title longer than about 60 characters\n3. Images. Page through the Get media tool for up to 100 of the newest items. Note every image with empty alt text. Skip files that are not images.\n4. Categories and tags. Read them with the Get terms tool. Note terms used by 0 posts, and pairs that look like duplicates (for example \"Recipe\" and \"Recipes\").\n5. Reply with one short table per problem, with the item's title, ID and link. Put the problem that affects the most items first. End with the three fixes you would make first.\n6. If a tool is off, fails or hits a paging limit, say which part you could not check. Do not guess.\n\nNever edit, delete or merge anything during this check. If the user wants a fix, they can ask for it separately after reading the report.", 'agent-abilities-for-mcp' ),
			'abilities'   => array( 'aafm/count-posts', 'aafm/get-posts', 'aafm/get-media', 'aafm/get-terms' ),
			'requires'    => array(),
		),
		'draft-a-post'          => array(
			'title'       => __( 'Draft a new post', 'agent-abilities-for-mcp' ),
			'description' => __( "Write a blog post from the user's brief and save it as a draft for review. Never publishes or schedules.", 'agent-abilities-for-mcp' ),
			'body'        => __( "Use this when the user asks you to write a new blog post.\n\n1. Brief. Make sure you know the topic, who the post is for, and roughly how long it should be. If any of these is missing, ask for all of them in one message. Do not start writing on a guess.\n2. Site. Read the site name and tagline with the Get site info tool so the tone fits the site.\n3. Repeats. Search the site for the topic with the Search content tool. If a close match exists, tell the user and ask whether to go ahead or stop.\n4. Write the post: a clear title of about 60 characters or fewer, a short intro, a heading for each section, and an excerpt of one or two sentences. Use plain paragraphs and headings with no inline styles.\n5. Facts. Do not invent numbers, prices, quotes, dates, or claims about real people or businesses. Where a fact is needed and you do not have it, write [CHECK: what is needed] in the text.\n6. Category. Read the categories with the Get terms tool and pick one or two existing ones that fit. Do not create new categories.\n7. Save the post with the Create draft tool, with the status set to draft. Then give the user the title, the link to the draft and a list of every [CHECK] note.\n\nRules:\n- Only ever save as a draft. Never publish or schedule, and never change any other post. If the user asks you to publish, tell them to review the draft and publish it themselves in WordPress.\n- If saving fails or the tool is off, paste the full post into the chat so nothing is lost, and say why it was not saved.", 'agent-abilities-for-mcp' ),
			'abilities'   => array( 'aafm/get-site-info', 'aafm/search-content', 'aafm/get-terms', 'aafm/create-draft' ),
			'requires'    => array(),
		),
		'shop-tidy-up'          => array(
			'title'       => __( 'Shop tidy-up', 'agent-abilities-for-mcp' ),
			'description' => __( 'Find WooCommerce products with no price, no image, no stock or stuck in draft, and suggest fixes. Changes nothing.', 'agent-abilities-for-mcp' ),
			'body'        => __( "Use this when the user asks you to tidy up the shop. Only read and suggest. Change nothing.\n\n1. Size. Get the product counts with the Count WooCommerce products tool so you know how many products there are.\n2. List. Page through the List WooCommerce products tool with status \"any\", 100 per page. From each row, note products that have:\n- no price\n- stock status \"out of stock\"\n- status draft or pending\n3. Images. The list does not show images. Read up to 50 products with the Get WooCommerce product tool, published ones first, and note any with no main image. If there are more than 50, say how many you did not check.\n4. Variable products. A variable product can show no price in the list. Read it with the Get WooCommerce product tool before you report a missing price.\n5. Reply with one table per problem: product name, ID, status and what to fix. Add a one-line suggested fix for each product.\n6. If a tool is off, fails or stops paging early, say what you could not check. Do not guess.\n\nNever change, publish, hide or delete a product. If the user wants a fix made, they can ask for that exact change separately.", 'agent-abilities-for-mcp' ),
			'abilities'   => array( 'aafm/wc-count-products', 'aafm/wc-list-products', 'aafm/wc-get-product' ),
			'requires'    => array( 'woocommerce' ),
		),
	);

	$filtered = apply_filters( 'aafm_playbook_starters', $built_in );
	$out      = $built_in;
	if ( is_array( $filtered ) ) {
		foreach ( $filtered as $id => $starter ) {
			if ( ! is_string( $id ) || isset( $built_in[ $id ] ) || ! is_array( $starter ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/', $id ) ) {
				continue;
			}
			$clean = aafm_clean_playbook_input( array_diff_key( $starter, array( 'abilities' => true ) ) );
			if ( is_wp_error( $clean ) ) {
				continue;
			}
			$abilities  = aafm_clean_playbook_abilities( $starter['abilities'] ?? array() );
			$requires   = isset( $starter['requires'] ) && is_array( $starter['requires'] ) ? $starter['requires'] : array();
			$out[ $id ] = array(
				'title'       => $clean['title'],
				'description' => $clean['description'],
				'body'        => $clean['body'],
				'abilities'   => is_wp_error( $abilities ) ? array() : $abilities,
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
 * @return array{slug:string,title:string,description:string,body:string,active:bool,abilities:list<string>,starter:string,updated_gmt:string}|WP_Error
 */
function aafm_add_starter_playbook( string $starter_id ) {
	$starters = aafm_playbook_starters();
	if ( ! isset( $starters[ $starter_id ] ) ) {
		aafm_log_playbook_change( 'added_starter', '', false );
		return new WP_Error( 'aafm_playbook_starter_unknown', __( 'That starter playbook does not exist.', 'agent-abilities-for-mcp' ) );
	}
	$starter = $starters[ $starter_id ];
	return aafm_save_playbook(
		array(
			'title'       => $starter['title'],
			'description' => $starter['description'],
			'body'        => $starter['body'],
			'active'      => false,
			'abilities'   => $starter['abilities'],
			'starter'     => $starter_id,
		),
		'',
		'added_starter'
	);
}
