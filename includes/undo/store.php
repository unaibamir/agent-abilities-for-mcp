<?php
/**
 * The change history store: the aafm_changes table, its insert and update, retention, the caps
 * that keep it small, and the purges that remove what should not outlive its object or its user.
 *
 * Every statement that carries before-values runs with wpdb errors suppressed, because
 * wpdb::print_error() would otherwise write the whole failing statement, values included, to the
 * PHP error log. Values are JSON only and never leave the table except through a restore.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AAFM_UNDO_RETENTION_DAYS' ) ) {
	// Rows older than this are expired and removed; no filter, no setting (a longer window gets its own ruling).
	define( 'AAFM_UNDO_RETENTION_DAYS', 30 );
	// An agent may undo only its own connection's changes from this window.
	define( 'AAFM_UNDO_AGENT_WINDOW', DAY_IN_SECONDS );
	// One change keeps at most this many bytes of before-values.
	define( 'AAFM_UNDO_MAX_CHANGE_BYTES', 2 * 1024 * 1024 );
	// The whole site keeps at most this many bytes of before-values.
	define( 'AAFM_UNDO_MAX_SITE_BYTES', 256 * 1024 * 1024 );
	// The table keeps at most this many rows.
	define( 'AAFM_UNDO_MAX_ROWS', 20000 );
	// An agent connection may call undo-change this often per hour.
	define( 'AAFM_UNDO_AGENT_LIMIT', 30 );
	// A pending or undoing row younger than this is a call still in flight.
	define( 'AAFM_UNDO_PENDING_GRACE', 5 * MINUTE_IN_SECONDS );
}

/**
 * The columns of the change table and the format of each. The id and created_at are handled apart.
 *
 * @return array<string,string> Column to wpdb format.
 */
function aafm_undo_column_formats(): array {
	return array(
		'log_row_id'            => '%d',
		'group_id'              => '%s',
		'ability'               => '%s',
		'source'                => '%s',
		'principal_user_id'     => '%d',
		'client_id'             => '%s',
		'object_type'           => '%s',
		'object_id'             => '%d',
		'undo_op'               => '%s',
		'status'                => '%s',
		'reason'                => '%s',
		'fields'                => '%s',
		'perm_input'            => '%s',
		'before_values'         => '%s',
		'before_sha256'         => '%s',
		'after_hashes'          => '%s',
		'reverts_change_id'     => '%d',
		'reverted_by_change_id' => '%d',
		'snapshot_bytes'        => '%d',
		'created_at'            => '%s',
	);
}

/**
 * Insert one change row.
 *
 * The created_at column is always written explicitly in UTC, the way the activity log does. snapshot_bytes
 * defaults to the length of before_values. Errors are suppressed so a failing statement never
 * prints its values.
 *
 * @param array<string,mixed> $row Column values; unknown keys are ignored.
 * @return int The new row id, or 0 when the insert failed.
 */
function aafm_undo_insert( array $row ): int {
	global $wpdb;

	if ( ! isset( $row['created_at'] ) ) {
		$row['created_at'] = current_time( 'mysql', true );
	}
	if ( ! isset( $row['snapshot_bytes'] ) && isset( $row['before_values'] ) && is_string( $row['before_values'] ) ) {
		$row['snapshot_bytes'] = strlen( $row['before_values'] );
	}

	$data    = array();
	$formats = array();
	foreach ( aafm_undo_column_formats() as $column => $format ) {
		if ( array_key_exists( $column, $row ) ) {
			$data[ $column ] = $row[ $column ];
			$formats[]       = $format;
		}
	}

	$suppressed = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$inserted = $wpdb->insert( aafm_changes_table(), $data, $formats );
	$id       = $inserted ? (int) $wpdb->insert_id : 0;
	$wpdb->suppress_errors( $suppressed );

	return $id;
}

/**
 * Update one change row, optionally only while it holds one of the given statuses.
 *
 * @param int                 $id           Row id.
 * @param array<string,mixed> $data         Column to new value; null writes SQL NULL; unknown keys are ignored.
 * @param array<int,string>   $where_status Statuses the row must currently hold; empty means any.
 * @return int Affected rows, or -1 when the statement failed.
 */
function aafm_undo_update( int $id, array $data, array $where_status = array() ): int {
	global $wpdb;

	$formats = aafm_undo_column_formats();
	$parts   = array();
	$args    = array( aafm_changes_table() );
	foreach ( $data as $column => $value ) {
		if ( ! isset( $formats[ $column ] ) ) {
			continue;
		}
		if ( null === $value ) {
			$parts[] = '%i = NULL';
			$args[]  = $column;
			continue;
		}
		$parts[] = '%i = ' . $formats[ $column ];
		$args[]  = $column;
		$args[]  = $value;
	}
	if ( array() === $parts ) {
		return 0;
	}

	$sql    = 'UPDATE %i SET ' . implode( ', ', $parts ) . ' WHERE id = %d';
	$args[] = $id;
	if ( array() !== $where_status ) {
		$sql .= ' AND status IN (' . implode( ', ', array_fill( 0, count( $where_status ), '%s' ) ) . ')';
		foreach ( $where_status as $status ) {
			$args[] = $status;
		}
	}

	$suppressed = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$result = $wpdb->query( $wpdb->prepare( $sql, $args ) );
	$wpdb->suppress_errors( $suppressed );

	return false === $result ? -1 : (int) $result;
}

/**
 * Delete one change row (a call that changed nothing leaves no row).
 *
 * @param int $id Row id.
 * @return void
 */
function aafm_undo_delete( int $id ): void {
	global $wpdb;
	$suppressed = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', aafm_changes_table(), $id ) );
	$wpdb->suppress_errors( $suppressed );
}

/**
 * Read one change row. The only row getter: an unreadable table and an absent row are different answers.
 *
 * @param int $id Row id.
 * @return array{ok:bool,row:?array<string,mixed>}
 */
function aafm_undo_get_view( int $id ): array {
	global $wpdb;
	$view = aafm_wpdb_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', aafm_changes_table(), $id ) );
	return array(
		'ok'  => $view['ok'],
		'row' => $view['ok'] ? $view['value'] : null,
	);
}

/**
 * Whether change capture is switched off.
 *
 * Off is the stored row, absent means on, and an unreadable row reads as off: a change is then
 * reported as not undoable, never recorded wrongly. Memoized per request; the memo is dropped when
 * the option changes and by the $forget seam the tests use.
 *
 * @param bool $forget Drop the memo and return false.
 * @return bool
 */
function aafm_undo_capture_off( bool $forget = false ): bool {
	static $memo = null;

	if ( $forget ) {
		$memo = null;
		return false;
	}
	if ( null === $memo ) {
		$memo = aafm_stored_switch_on( 'aafm_undo_capture_off' );
	}
	return $memo;
}

/**
 * Drop the capture-switch memo when its option is added, updated or deleted.
 *
 * @return void
 */
function aafm_undo_forget_capture_memo(): void {
	aafm_undo_capture_off( true );
}
add_action( 'add_option_aafm_undo_capture_off', 'aafm_undo_forget_capture_memo' );
add_action( 'update_option_aafm_undo_capture_off', 'aafm_undo_forget_capture_memo' );
add_action( 'delete_option_aafm_undo_capture_off', 'aafm_undo_forget_capture_memo' );
add_action(
	'deleted_option',
	static function ( $option ): void {
		if ( 'aafm_undo_capture_off' === $option ) {
			aafm_undo_capture_off( true );
		}
	}
);

/**
 * A UTC timestamp string for "now minus $seconds", the form created_at is stored in.
 *
 * @param int $seconds Age in seconds.
 * @return string
 */
function aafm_undo_cutoff( int $seconds ): string {
	return gmdate( 'Y-m-d H:i:s', time() - $seconds );
}

/**
 * Whether a row is past the retention window. Age is part of state: a row counts as expired here
 * whether or not cron has removed it. An unreadable timestamp counts as aged.
 *
 * @param array<string,mixed> $row A change row.
 * @return bool
 */
function aafm_undo_is_aged( array $row ): bool {
	$created = isset( $row['created_at'] ) ? strtotime( (string) $row['created_at'] . ' UTC' ) : false;
	return false === $created || $created < time() - AAFM_UNDO_RETENTION_DAYS * DAY_IN_SECONDS;
}

/**
 * Delete one bounded batch of rows past the retention window, at most once per request.
 *
 * Called from the caps pass, the Changes tab render and the owner handlers, so values age out
 * without cron and without new captures. Nothing is added to the agent read path.
 *
 * @param bool $forget Reset the once-per-request memo and return.
 * @return void
 */
function aafm_undo_age_batch( bool $forget = false ): void {
	static $ran = false;

	if ( $forget ) {
		$ran = false;
		return;
	}
	if ( $ran ) {
		return;
	}
	$ran = true;

	global $wpdb;
	$suppressed = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s ORDER BY id ASC LIMIT 500', aafm_changes_table(), aafm_undo_cutoff( AAFM_UNDO_RETENTION_DAYS * DAY_IN_SECONDS ) ) );
	$wpdb->suppress_errors( $suppressed );
}

/**
 * Keep the table inside its caps: age out old rows, then expire the oldest values past the byte
 * cap (the rows stay), then evict the oldest rows past the row cap. Runs at most once per request,
 * from the decorator-level finish of a write that stored values, from a recorded revert and from prune.
 *
 * A failed read stops a loop, so an unreadable count never deletes. In-flight rows (pending or
 * undoing, younger than the grace) are skipped, and terminal statuses keep their meaning.
 *
 * @param bool $forget Reset the once-per-request memo and return.
 * @return void
 */
function aafm_undo_enforce_caps( bool $forget = false ): void {
	static $ran = false;

	if ( $forget ) {
		$ran = false;
		return;
	}
	if ( $ran ) {
		return;
	}
	$ran = true;

	aafm_undo_age_batch();

	global $wpdb;
	$table      = aafm_changes_table();
	$in_flight  = aafm_undo_cutoff( AAFM_UNDO_PENDING_GRACE );
	$suppressed = $wpdb->suppress_errors( true );

	// Byte cap: drop the oldest values, keep the rows. Each pass either zeroes at least one row's
	// bytes or affects nothing, so the loop ends.
	for ( $pass = 0; $pass < 200; $pass++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sum = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COALESCE(SUM(snapshot_bytes), 0) FROM %i', $table ) );
		if ( ! $sum['ok'] || (int) $sum['value'] <= AAFM_UNDO_MAX_SITE_BYTES ) {
			break;
		}
		// Expire no more than the excess needs: one change holds at most AAFM_UNDO_MAX_CHANGE_BYTES,
		// so this many rows is enough or the next pass takes a few more.
		$rows = max( 1, min( 500, (int) ceil( ( (int) $sum['value'] - AAFM_UNDO_MAX_SITE_BYTES ) / AAFM_UNDO_MAX_CHANGE_BYTES ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE %i SET before_values = NULL, snapshot_bytes = 0, status = IF( status = 'ready', 'expired', status ) WHERE before_values IS NOT NULL AND snapshot_bytes > 0 AND NOT ( status IN ('pending', 'undoing') AND created_at > %s ) ORDER BY id ASC LIMIT %d", $table, $in_flight, $rows ) );
		if ( ! is_int( $affected ) || $affected < 1 ) {
			break;
		}
	}

	// Row cap: evict valueless rows first, then the oldest.
	for ( $pass = 0; $pass < 100; $pass++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		if ( ! $count['ok'] || (int) $count['value'] <= AAFM_UNDO_MAX_ROWS ) {
			break;
		}
		$excess = min( 500, (int) $count['value'] - AAFM_UNDO_MAX_ROWS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE NOT ( status IN ('pending', 'undoing') AND created_at > %s ) ORDER BY ( before_values IS NULL ) DESC, id ASC LIMIT %d", $table, $in_flight, $excess ) );
		if ( ! is_int( $affected ) || $affected < 1 ) {
			break;
		}
	}

	$wpdb->suppress_errors( $suppressed );
}

/**
 * The daily prune: delete rows past the 30 day window in bounded batches, then enforce the caps.
 * Independent of the activity-log retention setting (a log kept forever does not keep before-values).
 *
 * @return void
 */
function aafm_undo_prune(): void {
	global $wpdb;
	$table  = aafm_changes_table();
	$cutoff = aafm_undo_cutoff( AAFM_UNDO_RETENTION_DAYS * DAY_IN_SECONDS );

	$suppressed = $wpdb->suppress_errors( true );
	for ( $batch = 0; $batch < 200; $batch++ ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s ORDER BY id ASC LIMIT 5000', $table, $cutoff ) );
		if ( ! is_int( $deleted ) || $deleted < 5000 ) {
			break;
		}
	}
	$wpdb->suppress_errors( $suppressed );

	aafm_undo_enforce_caps();
}

/**
 * Empty the change table. True also when the table is absent, so a reset never reports failure for
 * a table the installer could not create.
 *
 * @return bool True when the table is confirmed empty or absent.
 */
function aafm_undo_clear(): bool {
	global $wpdb;
	$table = aafm_changes_table();
	if ( ! aafm_activity_log_table_present( $table ) ) {
		return true;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $table ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$remaining = aafm_wpdb_scalar( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	return $remaining['ok'] && 0 === (int) $remaining['value'];
}

/**
 * Null the values of every row of a permanently deleted object. A row that was still undoable
 * becomes expired; a terminal status keeps its meaning.
 *
 * @param string $object_type 'post' or 'term'.
 * @param int    $object_id   The object id.
 * @return void
 */
function aafm_undo_purge_object( string $object_type, int $object_id ): void {
	global $wpdb;
	$suppressed = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "UPDATE %i SET before_values = NULL, snapshot_bytes = 0, status = IF( status IN ('ready', 'pending'), 'expired', status ) WHERE object_type = %s AND object_id = %d", aafm_changes_table(), $object_type, $object_id ) );
	$wpdb->suppress_errors( $suppressed );
}

/**
 * A post was permanently deleted: its restore values are gone with it.
 *
 * @param int $post_id Post id.
 * @return void
 */
function aafm_undo_on_deleted_post( $post_id ): void {
	aafm_undo_purge_object( 'post', (int) $post_id );
}
add_action( 'deleted_post', 'aafm_undo_on_deleted_post' );

/**
 * A term was deleted: its restore values are gone with it.
 *
 * @param int $term_id Term id.
 * @return void
 */
function aafm_undo_on_delete_term( $term_id ): void {
	aafm_undo_purge_object( 'term', (int) $term_id );
}
add_action( 'delete_term', 'aafm_undo_on_delete_term' );

/**
 * Delete every row one user made on the current site.
 *
 * @param int $user_id User id.
 * @return void
 */
function aafm_undo_purge_user( int $user_id ): void {
	global $wpdb;
	$suppressed = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE principal_user_id = %d', aafm_changes_table(), $user_id ) );
	$wpdb->suppress_errors( $suppressed );
}

/**
 * A user was deleted on this site.
 *
 * @param int $user_id User id.
 * @return void
 */
function aafm_undo_on_deleted_user( $user_id ): void {
	aafm_undo_purge_user( (int) $user_id );
}
add_action( 'deleted_user', 'aafm_undo_on_deleted_user' );

/**
 * A network user delete: purge that user's rows on every site they belonged to. Fires before the
 * memberships are removed. A site the plugin never ran on has no table and is skipped.
 *
 * @param int $user_id User id.
 * @return void
 */
function aafm_undo_on_network_user_delete( $user_id ): void {
	$user_id = (int) $user_id;
	foreach ( get_blogs_of_user( $user_id, true ) as $blog ) {
		switch_to_blog( (int) ( ( (array) $blog )['userblog_id'] ?? 0 ) );
		try {
			if ( aafm_activity_log_table_present( aafm_changes_table() ) ) {
				aafm_undo_purge_user( $user_id );
			}
		} finally {
			restore_current_blog();
		}
	}
}
add_action( 'wpmu_delete_user', 'aafm_undo_on_network_user_delete' );

/**
 * The rows the Changes tab shows, newest first, a replace-sitewide group counting as one row.
 *
 * Never selects the before-values: a row carries only whether values are still held and whether they
 * still hash to what was stored, so no value can reach the page. A pending row younger than the grace
 * period is a call still in flight and is left out.
 *
 * @param int $page     Page number, from 1.
 * @param int $per_page Rows per page.
 * @return array{ok: bool, rows: list<array<string,mixed>>, total: int}
 */
function aafm_undo_list_for_tab( int $page, int $per_page ): array {
	global $wpdb;

	$empty   = array(
		'ok'    => false,
		'rows'  => array(),
		'total' => 0,
	);
	$table   = aafm_changes_table();
	$grace   = aafm_undo_cutoff( AAFM_UNDO_PENDING_GRACE );
	$visible = $wpdb->prepare( "NOT ( status = 'pending' AND created_at >= %s )", $grace );
	// A sitewide call's rows share one key; every other row is its own.
	$unit = "IF( ability = 'aafm/replace-sitewide' AND group_id <> '', group_id, CONCAT( 'r', id ) )";

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $visible is a prepared fragment, $unit a constant, the table a %i.
	$count = aafm_wpdb_scalar( $wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT 1 FROM %i WHERE {$visible} GROUP BY {$unit} ) AS u", $table ) );
	if ( ! $count['ok'] ) {
		return $empty;
	}
	$leads = aafm_wpdb_results( $wpdb->prepare( "SELECT MAX( id ) AS lead_id FROM %i WHERE {$visible} GROUP BY {$unit} ORDER BY lead_id DESC LIMIT %d OFFSET %d", $table, $per_page, max( 0, $page - 1 ) * $per_page ) );
	// phpcs:enable
	if ( ! $leads['ok'] ) {
		return $empty;
	}
	$ids = array_map( static fn( array $row ): int => (int) $row['lead_id'], (array) $leads['value'] );
	if ( array() === $ids ) {
		return array(
			'ok'    => true,
			'rows'  => array(),
			'total' => (int) $count['value'],
		);
	}

	$columns = 'id, log_row_id, group_id, ability, source, principal_user_id, client_id, object_type, object_id, undo_op, status, reason, fields, perm_input, after_hashes, before_sha256, reverts_change_id, reverted_by_change_id, snapshot_bytes, created_at';
	$marks   = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the column list is a constant and the marks are generated from the id list.
	$sql  = "SELECT {$columns}, IF( before_values IS NULL, NULL, '1' ) AS before_values, ( SHA2( before_values, 256 ) = before_sha256 ) AS hash_ok FROM %i WHERE ( id IN ( {$marks} ) OR group_id IN ( SELECT group_id FROM %i WHERE id IN ( {$marks} ) AND group_id <> '' AND ability = 'aafm/replace-sitewide' ) ) AND {$visible} ORDER BY id DESC";
	$rows = aafm_wpdb_results( $wpdb->prepare( $sql, array_merge( array( $table ), $ids, array( $table ), $ids ) ) );
	// phpcs:enable
	if ( ! $rows['ok'] ) {
		return $empty;
	}
	return array(
		'ok'    => true,
		'rows'  => array_values( (array) $rows['value'] ),
		'total' => (int) $count['value'],
	);
}

/**
 * The ids and tools of every row in one replace-sitewide group, newest first.
 *
 * @param string $group_id The 32 character group id.
 * @return array{ok: bool, rows: list<array<string,mixed>>}
 */
function aafm_undo_group_members( string $group_id ): array {
	global $wpdb;
	$rows = aafm_wpdb_results( $wpdb->prepare( 'SELECT id, ability FROM %i WHERE group_id = %s ORDER BY id DESC LIMIT 200', aafm_changes_table(), $group_id ) );
	return array(
		'ok'   => $rows['ok'],
		'rows' => $rows['ok'] ? array_values( (array) $rows['value'] ) : array(),
	);
}

/**
 * The field names of a row, without the family prefix on post and term columns.
 *
 * @param array<string,mixed> $row A change row.
 * @return string[]
 */
function aafm_undo_field_names( array $row ): array {
	$fields = array_values( array_filter( (array) json_decode( (string) ( $row['fields'] ?? '[]' ), true ), 'is_string' ) );
	return array_map(
		static function ( string $field ): string {
			foreach ( array( 'post:', 'term:', 'aioseo:' ) as $prefix ) {
				if ( 0 === strpos( $field, $prefix ) ) {
					return substr( $field, strlen( $prefix ) );
				}
			}
			return $field;
		},
		$fields
	);
}
