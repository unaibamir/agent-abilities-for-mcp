<?php
/**
 * What the owner has reviewed of other plugins' tools. Nothing here can enable a tool: the review
 * state and aafm_enabled_bridged_abilities are separate on purpose.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Every foreign slug registered right now, without the schema work discovery does.
 *
 * @return list<string>
 */
function aafm_bridge_foreign_slugs(): array {
	if ( ! class_exists( 'WP_Abilities_Registry' ) ) {
		return array();
	}
	$registry = \WP_Abilities_Registry::get_instance();
	if ( null === $registry ) {
		return array();
	}

	$slugs = array();
	foreach ( array_keys( $registry->get_all_registered() ) as $slug ) {
		$slug = (string) $slug;
		$ns   = aafm_bridge_namespace_of( $slug );
		if ( 'aafm' !== $ns && AAFM_BRIDGE_NAMESPACE !== $ns && 'mcp-adapter' !== $ns ) {
			$slugs[] = $slug;
		}
	}
	return $slugs;
}

/**
 * The review state, from the database row: a stale cache copy that says "reviewed" would hide a new
 * tool, and the row is cheap, because detection only runs on an admin page load.
 *
 * With an unreadable row the lists are empty, so the screen shows more rather than less, and ok is
 * false so nothing writes from that view.
 *
 * @return array{reviewed:list<string>,notified:list<string>,baselined:bool,ok:bool}
 */
function aafm_bridge_review_state(): array {
	$row   = aafm_option_row( 'aafm_bridge_review' );
	$value = $row['found'] && is_array( $row['value'] ) ? $row['value'] : array();
	$list  = static function ( string $key ) use ( $value ): array {
		$items = isset( $value[ $key ] ) && is_array( $value[ $key ] ) ? $value[ $key ] : array();
		return array_values( array_unique( array_filter( $items, 'is_string' ) ) );
	};

	return array(
		'reviewed'  => $list( 'reviewed' ),
		'notified'  => $list( 'notified' ),
		'baselined' => $row['found'] || ! $row['ok'],
		'ok'        => $row['ok'],
	);
}

/**
 * Store the review state.
 *
 * @param array<int,string> $reviewed Reviewed slugs.
 * @param array<int,string> $notified Announced slugs.
 * @return bool Whether the write took.
 */
function aafm_bridge_review_store( array $reviewed, array $notified ): bool {
	return aafm_update_option_verified(
		'aafm_bridge_review',
		array(
			'v'        => 1,
			'reviewed' => array_values( $reviewed ),
			'notified' => array_values( $notified ),
		),
		false
	);
}

/**
 * Registered foreign tools the owner has not reviewed.
 *
 * @return list<string> Empty until the first run has recorded what was already there.
 */
function aafm_bridge_new_tools(): array {
	$state = aafm_bridge_review_state();
	if ( ! $state['baselined'] ) {
		return array();
	}
	return array_values( array_diff( aafm_bridge_foreign_slugs(), $state['reviewed'] ) );
}

/**
 * Group slugs the way the directory groups them, through the same known-plugin merge.
 *
 * @param array<int,string> $slugs Foreign slugs.
 * @return array<string,list<string>> Group key => slugs.
 */
function aafm_bridge_group_slugs( array $slugs ): array {
	$groups = array();
	foreach ( $slugs as $slug ) {
		$ns                           = aafm_bridge_namespace_of( $slug );
		$groups[ $ns ]['label']       = $slug;
		$groups[ $ns ]['abilities'][] = array(
			'slug'  => $slug,
			'label' => $slug,
		);
	}

	$out = array();
	foreach ( aafm_merge_bridge_groups_by_known_plugin( $groups ) as $key => $group ) {
		$out[ (string) $key ] = array_column( $group['abilities'], 'slug' );
	}
	return $out;
}

/**
 * Record slugs as reviewed. Enables nothing.
 *
 * With no row yet the whole registered set counts as reviewed too, or the first mark would turn
 * everything else into "new".
 *
 * @param array<int,string> $slugs Slugs to mark.
 * @return bool Whether the state now says so. False when the row cannot be read or written.
 */
function aafm_bridge_mark_reviewed( array $slugs ): bool {
	$state = aafm_bridge_review_state();
	if ( ! $state['ok'] ) {
		return false;
	}

	$reviewed = array_merge( $state['reviewed'], $slugs );
	if ( ! $state['baselined'] ) {
		$reviewed = array_merge( $reviewed, aafm_bridge_foreign_slugs() );
	}
	return aafm_bridge_review_store( array_values( array_unique( $reviewed ) ), array_values( array_diff( $state['notified'], $slugs ) ) );
}

/**
 * Log a review-state write that did not take. Bounded: this can recur on every admin page load.
 *
 * @return void
 */
function aafm_bridge_review_log_failure(): void {
	if ( aafm_denial_log_within_cap( 'brv' ) ) {
		aafm_log_ability_persist_failure( 'aafm_bridge_review', __( 'The reviewed tools list', 'agent-abilities-for-mcp' ) );
	}
}

/**
 * Detect tools other plugins added since the owner last looked. Runs on admin_init, never throws.
 *
 * The first run records everything already listed, silently, so nothing is "new" after an upgrade.
 *
 * ponytail: runs on the next wp-admin page load, so an auto-update or a WP-CLI update is announced
 * when someone next opens wp-admin. A cron or per-request scan is not worth the cost.
 *
 * @return void
 */
function aafm_bridge_review_sync(): void {
	if ( wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	try {
		$state = aafm_bridge_review_state();
		if ( ! $state['ok'] ) {
			return;
		}
		if ( ! $state['baselined'] ) {
			if ( ! aafm_bridge_review_store( aafm_bridge_foreign_slugs(), array() ) ) {
				aafm_bridge_review_log_failure();
			}
			return;
		}

		// Only slugs never announced count, so reviewing some tools does not re-announce the rest, and
		// a plugin deactivated and reactivated before review does not announce its tools twice. The
		// list is stored before the event goes out: a write that keeps failing must not send one on
		// every page load.
		$fresh = array_values( array_diff( array_diff( aafm_bridge_foreign_slugs(), $state['reviewed'] ), $state['notified'] ) );
		if ( array() === $fresh ) {
			return;
		}
		if ( ! aafm_bridge_review_store( $state['reviewed'], array_merge( $state['notified'], $fresh ) ) ) {
			aafm_bridge_review_log_failure();
			return;
		}
		aafm_notify_event( 'new_tools', 'detected', array( 'count' => count( $fresh ) ) );
	} catch ( \Throwable $e ) {
		unset( $e ); // Bookkeeping for a notice must never break the admin page that raised it.
	}
}
