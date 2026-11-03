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

const AAFM_PLAYBOOKS_OPTION   = 'aafm_playbooks';
const AAFM_PLAYBOOKS_MAX      = 50;
const AAFM_PLAYBOOK_TITLE_MAX = 80;
const AAFM_PLAYBOOK_DESC_MAX  = 200;
const AAFM_PLAYBOOK_BODY_MAX  = 8000;
const AAFM_PLAYBOOK_SLUG_MAX  = 64;

/**
 * Clean a playbook body: plain multi-line text, LF line endings, no markup.
 *
 * @param string $raw Raw text.
 * @return string
 */
function aafm_clean_playbook_body( string $raw ): string {
	$text = aafm_sanitize_multiline_text( $raw );

	return trim( (string) preg_replace( '/\r\n|\r/', "\n", $text ) );
}

/**
 * Clean one stored entry on read. A hand-written row is held to the same rules as a save; text
 * that is too long is cut rather than refused, an entry with no usable slug, title or body is
 * dropped.
 *
 * @param mixed $entry Stored entry.
 * @return array{slug:string,title:string,description:string,body:string,active:bool,updated_gmt:string}|null
 */
function aafm_clean_stored_playbook( $entry ): ?array {
	if ( ! is_array( $entry ) ) {
		return null;
	}
	$slug = isset( $entry['slug'] ) && is_string( $entry['slug'] ) ? $entry['slug'] : '';
	if ( ! preg_match( '/^[a-z0-9-]{1,64}$/', $slug ) ) {
		return null;
	}
	$title = isset( $entry['title'] ) && is_string( $entry['title'] ) ? mb_substr( sanitize_text_field( $entry['title'] ), 0, AAFM_PLAYBOOK_TITLE_MAX ) : '';
	$body  = isset( $entry['body'] ) && is_string( $entry['body'] ) ? mb_substr( aafm_clean_playbook_body( $entry['body'] ), 0, AAFM_PLAYBOOK_BODY_MAX ) : '';
	if ( '' === trim( $title ) || '' === $body ) {
		return null;
	}
	$description = isset( $entry['description'] ) && is_string( $entry['description'] ) ? mb_substr( sanitize_text_field( $entry['description'] ), 0, AAFM_PLAYBOOK_DESC_MAX ) : '';
	$updated     = isset( $entry['updated_gmt'] ) && is_string( $entry['updated_gmt'] ) && preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $entry['updated_gmt'] ) ? $entry['updated_gmt'] : '';

	return array(
		'slug'        => $slug,
		'title'       => trim( $title ),
		'description' => trim( $description ),
		'body'        => $body,
		'active'      => ! empty( $entry['active'] ),
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
	if ( $views['db_error'] ) {
		return array(
			'ok'   => false,
			'list' => array(),
		);
	}
	$list = array();
	$seen = array();
	if ( $views['db_found'] && is_array( $views['db_value'] ) ) {
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
 * @return array{title:string,description:string,body:string,active:bool}|WP_Error
 */
function aafm_clean_playbook_input( array $raw ) {
	$title       = trim( sanitize_text_field( is_string( $raw['title'] ?? null ) ? $raw['title'] : '' ) );
	$description = trim( sanitize_text_field( is_string( $raw['description'] ?? null ) ? $raw['description'] : '' ) );
	$body        = aafm_clean_playbook_body( is_string( $raw['body'] ?? null ) ? $raw['body'] : '' );

	if ( '' === $title || mb_strlen( $title ) > AAFM_PLAYBOOK_TITLE_MAX ) {
		/* translators: %d: the longest title allowed, in characters. */
		return new WP_Error( 'aafm_playbook_invalid', sprintf( __( 'Give the playbook a title of 1 to %d characters.', 'agent-abilities-for-mcp' ), AAFM_PLAYBOOK_TITLE_MAX ) );
	}
	if ( mb_strlen( $description ) > AAFM_PLAYBOOK_DESC_MAX ) {
		/* translators: %d: the longest description allowed, in characters. */
		return new WP_Error( 'aafm_playbook_invalid', sprintf( __( 'Keep the description to %d characters or fewer.', 'agent-abilities-for-mcp' ), AAFM_PLAYBOOK_DESC_MAX ) );
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
	);
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
	$clean = aafm_clean_playbook_input( $raw );
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}
	$action = '' !== $log_action ? $log_action : ( '' === $slug ? 'added' : 'edited' );

	// ponytail: last save wins when two administrators save at once; add a per-entry revision check if users report lost edits.
	$read = aafm_playbooks_read();
	if ( ! $read['ok'] ) {
		aafm_log_playbook_change( $action, $slug, false );
		return aafm_playbook_not_saved_error();
	}
	$list = $read['list'];

	if ( '' === $slug ) {
		if ( count( $list ) >= AAFM_PLAYBOOKS_MAX ) {
			/* translators: %d: the most playbooks a site can keep. */
			return new WP_Error( 'aafm_playbook_limit', sprintf( __( 'This site already has %d playbooks, the most it can keep. Delete one first.', 'agent-abilities-for-mcp' ), AAFM_PLAYBOOKS_MAX ) );
		}
		$slug  = aafm_playbook_new_slug( $clean['title'], array_column( $list, 'slug' ) );
		$index = count( $list );
	} else {
		$index = array_search( $slug, array_column( $list, 'slug' ), true );
		if ( false === $index ) {
			return aafm_playbook_not_found_error( $slug );
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
		return aafm_playbook_not_found_error( $slug );
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
 * The refusal for a slug with no playbook.
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
