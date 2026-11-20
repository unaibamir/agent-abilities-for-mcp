<?php
/**
 * UpdraftPlus readers, the backup set check and the gate window walk.
 *
 * Loaded on first use, never from the bootstrap. Everything here reads: the backup history, the job
 * rows, and the archive files in the backup directory. Nothing here writes an option, a transient or
 * a file, and nothing here starts, resumes or changes a backup. No UpdraftPlus method is called
 * except UpdraftPlus_Backup_History::get_history(), which is a read.
 *
 * Callers wrap these functions in aafm_siteops_vendor_call(), so a vendor error ends as a closed
 * gate or an empty answer, never as a fatal error.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * How many bytes one window walk may hash inside a tool call or a permission check, in total.
 *
 * @return int Bytes, from 0 to 33554432.
 */
function aafm_site_updraft_inline_max_bytes(): int {
	/**
	 * Filters the total number of bytes one backup check may hash on the spot.
	 *
	 * Larger database archives are checked by the hourly background check instead. The value is
	 * clamped to the range 0 to 33554432 (32 MB).
	 *
	 * @param int $bytes Default 8388608 (8 MB).
	 */
	$bytes = apply_filters( 'aafm_backup_gate_inline_checksum_max_bytes', 8388608 );
	$bytes = is_numeric( $bytes ) ? (int) $bytes : 8388608;

	return max( 0, min( 33554432, $bytes ) );
}

/**
 * The local backup directory, or null when it cannot be resolved without a vendor write.
 *
 * UpdraftPlus's own resolver creates the directory and writes files into it, so it is never called.
 * A stored value holding a dot segment is refused, and the vendor's legacy "relative to the
 * WordPress folder" form is not followed: on such an install no local set verifies.
 *
 * @return string|null
 */
function aafm_site_updraft_dir(): ?string {
	$stored = get_option( 'updraft_dir' );
	$dir    = is_string( $stored ) ? untrailingslashit( $stored ) : '';

	if ( 1 === preg_match( '#^wp-content/(.*)$#', $dir, $match ) && ABSPATH . 'wp-content' === WP_CONTENT_DIR ) {
		$dir = WP_CONTENT_DIR . '/' . $match[1];
	}
	if ( '' === $dir ) {
		$dir = WP_CONTENT_DIR . '/updraft';
	}
	if ( '/' !== $dir[0] && '\\' !== $dir[0] && 1 !== preg_match( '/^[a-zA-Z]:/', $dir ) ) {
		if ( 1 === preg_match( '#(^|/)\.\.?(/|$)#', $dir ) ) {
			return null;
		}
		$dir = trailingslashit( WP_CONTENT_DIR ) . $dir;
	}

	return is_dir( $dir ) ? $dir : null;
}

/**
 * UpdraftPlus's backup history, newest first.
 *
 * @return array<int,array<string,mixed>> Start time (UTC) => entry. Empty when it cannot be read.
 */
function aafm_site_updraft_history(): array {
	if ( ! class_exists( 'UpdraftPlus_Backup_History' ) || ! method_exists( 'UpdraftPlus_Backup_History', 'get_history' ) ) { // @phpstan-ignore-line function.alreadyNarrowedType (the analysis stub declares the method; real UpdraftPlus may not)
		return array();
	}
	$history = UpdraftPlus_Backup_History::get_history();
	if ( ! is_array( $history ) ) {
		return array();
	}

	$out = array();
	foreach ( $history as $timestamp => $entry ) {
		if ( is_array( $entry ) && ( is_int( $timestamp ) || ctype_digit( (string) $timestamp ) ) ) {
			$out[ (int) $timestamp ] = $entry;
		}
	}
	krsort( $out, SORT_NUMERIC );

	return $out;
}

/**
 * Whether a history key is inside the gate window: the last 24 hours, with five minutes of slack
 * for a clock that runs ahead.
 *
 * @param int $timestamp History key.
 * @return bool
 */
function aafm_site_updraft_in_window( int $timestamp ): bool {
	$now = time();

	return $timestamp >= $now - DAY_IN_SECONDS && $timestamp <= $now + 300;
}

/**
 * The remote storage methods an entry names. Empty for a set kept only on this server.
 *
 * @param array<string,mixed> $entry History entry.
 * @return list<string>
 */
function aafm_site_updraft_services( array $entry ): array {
	$raw = $entry['service'] ?? array();
	$raw = is_array( $raw ) ? $raw : array( $raw );
	$out = array();
	foreach ( $raw as $service ) {
		if ( is_string( $service ) && '' !== $service && 'none' !== $service ) {
			$out[] = $service;
		}
	}

	return $out;
}

/**
 * What identifies an archive file as the same file it was when it was checked.
 *
 * @param string $nonce Backup nonce.
 * @param string $file  Absolute path.
 * @return string nonce|size|mtime|ctime|inode, or '' when the file is gone.
 */
function aafm_site_updraft_fingerprint( string $nonce, string $file ): string {
	clearstatcache( true, $file );
	if ( ! is_file( $file ) ) {
		return '';
	}

	return $nonce . '|' . (int) filesize( $file ) . '|' . (int) filemtime( $file ) . '|' . (int) filectime( $file ) . '|' . (int) fileinode( $file );
}

/**
 * The jobstatus of one backup job row.
 *
 * @param string $nonce Backup nonce.
 * @return string|null|false The status ('' when the row has none), null when no row exists, false
 *                           when the read failed.
 */
function aafm_site_updraft_job_status( string $nonce ) {
	global $wpdb;

	$row = aafm_wpdb_row( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'updraft_jobdata_' . $nonce ) );
	if ( ! $row['ok'] ) {
		return false;
	}
	if ( null === $row['value'] ) {
		return null;
	}
	$data = maybe_unserialize( $row['value']['option_value'] ?? '' );
	if ( ! is_array( $data ) || ! is_scalar( $data['jobstatus'] ?? null ) ) {
		return '';
	}

	return (string) $data['jobstatus'];
}

/**
 * The stage name the tools report for a jobstatus.
 *
 * @param string $status UpdraftPlus jobstatus.
 * @return string
 */
function aafm_site_updraft_stage( string $status ): string {
	$map = array(
		'begun'             => 'begun',
		'filescreating'     => 'files',
		'filescreated'      => 'files',
		'dbcreating'        => 'database',
		'dbcreated'         => 'database',
		'clouduploading'    => 'uploading',
		'pruning'           => 'pruning',
		'resumingforerrors' => 'resuming',
	);

	return $map[ $status ] ?? 'other';
}

/**
 * UpdraftPlus backup jobs that have not finished.
 *
 * @return list<array{state:string,stage:string,started_at:int}>|null Null when the read failed.
 */
function aafm_site_updraft_jobs(): ?array {
	global $wpdb;

	$rows = aafm_wpdb_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s LIMIT 50', $wpdb->options, $wpdb->esc_like( 'updraft_jobdata_' ) . '%' ) );
	if ( ! $rows['ok'] ) {
		return null;
	}

	// One pass over the cron array: the resume event of each job, and each start that has not run yet.
	$resume = array();
	$starts = array();
	$cron   = _get_cron_array();
	foreach ( is_array( $cron ) ? $cron : array() as $due => $hooks ) {
		if ( ! is_array( $hooks ) ) {
			continue;
		}
		foreach ( $hooks as $hook => $events ) {
			if ( ! is_array( $events ) ) {
				continue;
			}
			foreach ( $events as $event ) {
				$args = is_array( $event ) && is_array( $event['args'] ?? null ) ? array_values( $event['args'] ) : array();
				if ( 'updraft_backup_resume' === $hook && is_string( $args[1] ?? null ) ) {
					$resume[ $args[1] ] = max( (int) ( $resume[ $args[1] ] ?? 0 ), (int) $due );
				} elseif ( in_array( $hook, array( 'updraft_backupnow_backup_database', 'updraft_backupnow_backup_all' ), true ) && is_array( $args[0] ?? null ) && is_string( $args[0]['use_nonce'] ?? null ) ) {
					$starts[ $args[0]['use_nonce'] ] = (int) $due;
				}
			}
		}
	}

	$now      = time();
	$jobs     = array();
	$with_row = array();
	foreach ( (array) $rows['value'] as $row ) {
		$nonce = substr( (string) ( $row['option_name'] ?? '' ), strlen( 'updraft_jobdata_' ) );
		$data  = maybe_unserialize( $row['option_value'] ?? '' );
		$data  = is_array( $data ) ? $data : array();

		$with_row[ $nonce ] = true;
		$status             = is_scalar( $data['jobstatus'] ?? null ) ? (string) $data['jobstatus'] : '';
		if ( 'finished' === $status ) {
			continue;
		}

		$runs = is_array( $data['runs_started'] ?? null ) ? array_filter( $data['runs_started'], 'is_numeric' ) : array();
		$last = array() === $runs ? 0.0 : (float) max( $runs );
		if ( isset( $resume[ $nonce ] ) ) {
			// A resumption is scheduled. More than fifteen minutes overdue means WP-Cron is not running.
			$state = ( $now - $resume[ $nonce ] ) > 900 ? 'stalled' : 'running';
		} else {
			// No resumption. Two minutes covers the gap while a run schedules the next one; after that it is dead.
			$state = ( $now - $last ) <= 120 ? 'running' : 'failed';
		}

		$jobs[] = array(
			'state'      => $state,
			'stage'      => aafm_site_updraft_stage( $status ),
			'started_at' => is_numeric( $data['backup_time'] ?? null ) ? (int) $data['backup_time'] : 0,
		);
	}

	foreach ( array_keys( $starts ) as $nonce ) {
		if ( ! isset( $with_row[ $nonce ] ) ) {
			$jobs[] = array(
				'state'      => 'queued',
				'stage'      => '',
				'started_at' => 0,
			);
		}
	}

	return $jobs;
}

/**
 * The database archive of one entry, located and size-checked. No hashing, no write.
 *
 * @param array<string,mixed> $entry History entry.
 * @return array{state:string,file:?string,size:int,sha1:?string,fingerprint:?string} state is
 *         'bad', 'not_local' or 'present'. The other keys are set for 'present'.
 */
function aafm_site_updraft_db_archive( array $entry ): array {
	$none = array(
		'state'       => 'bad',
		'file'        => null,
		'size'        => 0,
		'sha1'        => null,
		'fingerprint' => null,
	);

	$nonce = $entry['nonce'] ?? null;
	if ( ! is_string( $nonce ) || 1 !== preg_match( '/^[0-9a-f]{12}$/', $nonce ) ) {
		return $none;
	}
	// A set UpdraftPlus found by a rescan or an upload is marked native false: it did not make it here.
	if ( isset( $entry['native'] ) && ! $entry['native'] ) {
		return $none;
	}
	// A migration set sent from another site.
	if ( in_array( 'remotesend', aafm_site_updraft_services( $entry ), true ) ) {
		return $none;
	}
	// The entry must still name its database file. After retention pruned it, the size and the
	// checksum stay in the entry and the name does not.
	$db = $entry['db'] ?? null;
	if ( ! is_string( $db ) || '' === $db || basename( $db ) !== $db ) {
		return $none;
	}
	$size = $entry['db-size'] ?? null;
	if ( ! is_int( $size ) || $size < 1 ) {
		return $none;
	}
	// An encrypted archive is the .crypt file on disk and has its own recorded checksum.
	$key  = '.crypt' === substr( $db, -6 ) ? 'db0.crypt' : 'db0';
	$sums = $entry['checksums'] ?? null;
	$sha1 = is_array( $sums ) && is_array( $sums['sha1'] ?? null ) ? ( $sums['sha1'][ $key ] ?? null ) : null;
	if ( ! is_string( $sha1 ) || 1 !== preg_match( '/^[0-9a-f]{40}$/', $sha1 ) ) {
		return $none;
	}
	// The job must be over: no row, or a row that says finished. A failed read is not "over".
	$status = aafm_site_updraft_job_status( $nonce );
	if ( null !== $status && 'finished' !== $status ) {
		return $none;
	}

	$dir  = aafm_site_updraft_dir();
	$file = null === $dir ? null : aafm_siteops_confined_path( $dir, $db );
	if ( null !== $file ) {
		clearstatcache( true, $file );
	}
	if ( null === $file || ! is_file( $file ) ) {
		$none['state'] = 'not_local';
		return $none;
	}
	if ( filesize( $file ) !== $size ) {
		return $none;
	}

	return array(
		'state'       => 'present',
		'file'        => $file,
		'size'        => $size,
		'sha1'        => $sha1,
		'fingerprint' => aafm_site_updraft_fingerprint( $nonce, $file ),
	);
}

/**
 * The sha1 of an archive file. Announces the bytes it is about to read.
 *
 * @param string $file Absolute path.
 * @param int    $size File size in bytes.
 * @return string
 */
function aafm_site_updraft_sha1_file( string $file, int $size ): string {
	/**
	 * Fires when a backup archive is hashed.
	 *
	 * @param int    $size  Bytes read.
	 * @param string $where 'inline' for a tool call or permission check, 'verifier' for the background check.
	 */
	do_action( 'aafm_site_backup_hashed', $size, 'inline' );

	return (string) sha1_file( $file );
}

/**
 * The database check for one entry, with where the answer came from.
 *
 * @param int                 $timestamp     The entry's history key.
 * @param array<string,mixed> $entry         History entry.
 * @param array<mixed>        $verdicts      aafm_backup_verify_read(), read once by the caller.
 * @param bool                $accept_remote The owner accepts sets kept only off-site.
 * @param bool                $accept_size   The owner accepts a large archive on its size alone.
 * @param int                 $inline_budget Bytes this caller may still hash; reduced by what is hashed here.
 * @return array{check:string,checked_at:?int} checked_at is set only when a stored verdict decided.
 */
function aafm_site_updraft_set_check_detail( int $timestamp, array $entry, array $verdicts, bool $accept_remote, bool $accept_size, int &$inline_budget ): array {
	$answer = static function ( string $check, ?int $checked_at = null ): array {
		return array(
			'check'      => $check,
			'checked_at' => $checked_at,
		);
	};

	$archive = aafm_site_updraft_db_archive( $entry );
	if ( 'bad' === $archive['state'] ) {
		return $answer( 'failed' );
	}
	if ( 'not_local' === $archive['state'] ) {
		// No file here. With a storage method named, the set may live off-site; that cannot be checked.
		return $answer( array() !== aafm_site_updraft_services( $entry ) && $accept_remote ? 'trusted_unchecked' : 'failed' );
	}

	$stored = $verdicts[ (string) $entry['nonce'] ] ?? null;
	if ( is_array( $stored ) && is_string( $stored['fingerprint'] ?? null ) && $stored['fingerprint'] === $archive['fingerprint'] ) {
		$checked_at = isset( $stored['checked_at'] ) ? (int) $stored['checked_at'] : null;
		$verdict    = (string) ( $stored['verdict'] ?? '' );
		if ( 'verified' === $verdict ) {
			return $answer( 'verified', $checked_at );
		}
		if ( 'failed' === $verdict ) {
			return $answer( 'failed', $checked_at );
		}
		return $answer( $accept_size ? 'size_only' : 'too_large' );
	}

	// Nothing outside the gate window is hashed by anyone.
	if ( ! aafm_site_updraft_in_window( $timestamp ) ) {
		return $answer( 'not_checked' );
	}

	if ( $archive['size'] <= $inline_budget ) {
		$inline_budget -= $archive['size'];
		$matches        = hash_equals( (string) $archive['sha1'], aafm_site_updraft_sha1_file( (string) $archive['file'], $archive['size'] ) );
		return $answer( $matches ? 'verified' : 'failed' );
	}

	// Waiting for the background check.
	return $answer( $accept_size ? 'size_only' : 'pending' );
}

/**
 * The database check for one history entry. Reads the stored verdicts; never writes.
 *
 * @param int                 $timestamp     The entry's history key.
 * @param array<string,mixed> $entry         History entry.
 * @param array<mixed>        $verdicts      aafm_backup_verify_read(), read once by the caller.
 * @param bool                $accept_remote The owner accepts sets kept only off-site.
 * @param bool                $accept_size   The owner accepts a large archive on its size alone.
 * @param int                 $inline_budget Bytes this caller may still hash. Pass 0 to forbid hashing.
 * @return string 'verified', 'pending', 'too_large', 'size_only', 'trusted_unchecked', 'not_checked' or 'failed'.
 */
function aafm_site_updraft_set_check( int $timestamp, array $entry, array $verdicts, bool $accept_remote, bool $accept_size, int &$inline_budget ): string {
	return aafm_site_updraft_set_check_detail( $timestamp, $entry, $verdicts, $accept_remote, $accept_size, $inline_budget )['check'];
}

/**
 * Whether the entry's database file is on this server, whatever its size.
 *
 * @param array<string,mixed> $entry History entry.
 * @return bool
 */
function aafm_site_updraft_db_on_disk( array $entry ): bool {
	$db = $entry['db'] ?? null;
	if ( ! is_string( $db ) || '' === $db || basename( $db ) !== $db ) {
		return false;
	}
	$dir  = aafm_site_updraft_dir();
	$file = null === $dir ? null : aafm_siteops_confined_path( $dir, $db );
	if ( null === $file ) {
		return false;
	}
	clearstatcache( true, $file );

	return is_file( $file );
}

/**
 * The size check of an entry's file archives. Sizes only; file archives are never hashed.
 *
 * @param array<string,mixed> $entry History entry.
 * @return string 'not_included', 'size_ok', 'failed' or 'not_local'.
 */
function aafm_site_updraft_files_check( array $entry ): string {
	$dir     = aafm_site_updraft_dir();
	$listed  = 0;
	$present = 0;
	$all_ok  = true;

	foreach ( array( 'plugins', 'themes', 'uploads', 'others', 'mu-plugins', 'wpcore', 'more' ) as $entity ) {
		$files = $entry[ $entity ] ?? null;
		if ( ! is_array( $files ) || array() === $files ) {
			continue;
		}
		foreach ( $files as $index => $name ) {
			++$listed;
			$index = (int) $index;
			$want  = $entry[ 0 === $index ? $entity . '-size' : $entity . $index . '-size' ] ?? null;
			if ( ! is_string( $name ) || '' === $name || basename( $name ) !== $name || ! is_int( $want ) ) {
				$all_ok = false;
				continue;
			}
			$file = null === $dir ? null : aafm_siteops_confined_path( $dir, $name );
			if ( null !== $file ) {
				clearstatcache( true, $file );
			}
			if ( null === $file || ! is_file( $file ) ) {
				$all_ok = false;
				continue;
			}
			++$present;
			if ( filesize( $file ) !== $want ) {
				$all_ok = false;
			}
		}
	}

	if ( 0 === $listed ) {
		return 'not_included';
	}
	if ( null === $dir || ( 0 === $present && array() !== aafm_site_updraft_services( $entry ) ) ) {
		return 'not_local';
	}

	return $all_ok ? 'size_ok' : 'failed';
}

/**
 * The fixed safe fields of one entry, given its database check.
 *
 * @param int                                 $timestamp The entry's history key.
 * @param array<string,mixed>                 $entry     History entry.
 * @param array{check:string,checked_at:?int} $detail    aafm_site_updraft_set_check_detail().
 * @return array{started_at:int,scope:string,entities:list<string>,total_bytes:int,storage:string,database_check:string,files_check:string,checked_at:?int}
 */
function aafm_site_updraft_summary_from( int $timestamp, array $entry, array $detail ): array {
	$entities = array();
	foreach ( array( 'db', 'plugins', 'themes', 'uploads', 'others', 'mu-plugins', 'wpcore', 'more' ) as $entity ) {
		if ( array_key_exists( $entity, $entry ) ) {
			$entities[] = $entity;
		}
	}
	$has_files = array() !== array_intersect( array( 'plugins', 'themes', 'uploads', 'others' ), $entities );

	$total = 0;
	foreach ( $entry as $key => $value ) {
		if ( is_string( $key ) && '-size' === substr( $key, -5 ) && is_int( $value ) && $value > 0 ) {
			$total += $value;
		}
	}

	$remote = array() !== aafm_site_updraft_services( $entry );
	if ( aafm_site_updraft_db_on_disk( $entry ) ) {
		$storage = $remote ? 'local_and_remote' : 'local';
	} else {
		$storage = 'remote';
	}

	return array(
		'started_at'     => $timestamp,
		'scope'          => in_array( 'db', $entities, true ) && $has_files ? 'full' : 'database',
		'entities'       => $entities,
		'total_bytes'    => $total,
		'storage'        => $storage,
		'database_check' => $detail['check'],
		'files_check'    => aafm_site_updraft_files_check( $entry ),
		'checked_at'     => $detail['checked_at'],
	);
}

/**
 * One history entry, reduced to the fixed safe fields. No file name, nonce or storage detail.
 *
 * @param int                 $timestamp     The entry's history key.
 * @param array<string,mixed> $entry         History entry.
 * @param array<mixed>        $verdicts      aafm_backup_verify_read(), read once by the caller.
 * @param bool                $accept_remote The owner accepts sets kept only off-site.
 * @param bool                $accept_size   The owner accepts a large archive on its size alone.
 * @param int                 $inline_budget Bytes this caller may still hash. Pass 0 to forbid hashing.
 * @return array{started_at:int,scope:string,entities:list<string>,total_bytes:int,storage:string,database_check:string,files_check:string,checked_at:?int}
 */
function aafm_site_updraft_set_summary( int $timestamp, array $entry, array $verdicts, bool $accept_remote, bool $accept_size, int &$inline_budget ): array {
	return aafm_site_updraft_summary_from( $timestamp, $entry, aafm_site_updraft_set_check_detail( $timestamp, $entry, $verdicts, $accept_remote, $accept_size, $inline_budget ) );
}

/**
 * One walk over the gate window, newest first, at most 20 sets.
 *
 * A failed set is skipped. A pending or too-large set is noted and the walk goes on, so an older
 * verified backup inside the window still counts while a newer one waits.
 *
 * @param array<mixed> $verdicts      aafm_backup_verify_read(), read once by the caller.
 * @param bool         $accept_remote The owner accepts sets kept only off-site.
 * @param bool         $accept_size   The owner accepts a large archive on its size alone.
 * @return array{good:?array<string,mixed>,waiting:string} good is the summary of the newest good
 *         set. waiting is '' or, when no good set was found, 'pending' or 'too_large'.
 */
function aafm_site_updraft_window_walk( array $verdicts, bool $accept_remote, bool $accept_size ): array {
	$budget  = aafm_site_updraft_inline_max_bytes();
	$waiting = '';
	$seen    = 0;

	foreach ( aafm_site_updraft_history() as $timestamp => $entry ) {
		if ( $timestamp < time() - DAY_IN_SECONDS ) {
			break; // Newest first: everything after this is older still.
		}
		if ( ! aafm_site_updraft_in_window( $timestamp ) ) {
			continue;
		}
		if ( ++$seen > 20 ) {
			break;
		}

		$detail = aafm_site_updraft_set_check_detail( $timestamp, $entry, $verdicts, $accept_remote, $accept_size, $budget );
		if ( 'failed' === $detail['check'] ) {
			continue;
		}
		if ( 'pending' === $detail['check'] ) {
			$waiting = 'pending';
			continue;
		}
		if ( 'too_large' === $detail['check'] ) {
			$waiting = '' === $waiting ? 'too_large' : $waiting;
			continue;
		}

		return array(
			'good'    => aafm_site_updraft_summary_from( $timestamp, $entry, $detail ),
			'waiting' => '',
		);
	}

	return array(
		'good'    => null,
		'waiting' => $waiting,
	);
}

/**
 * The seconds one verification run may spend hashing.
 *
 * Lowered to the host's execution limit minus ten seconds, never below five, so a run can store
 * its answer before PHP stops it. With no limit set (WP-CLI reports 0) it is $seconds.
 *
 * @param int $seconds Requested bound.
 * @return int
 */
function aafm_backup_verify_bound( int $seconds ): int {
	$limit = (int) ini_get( 'max_execution_time' );

	return $limit > 0 ? min( $seconds, max( 5, $limit - 10 ) ) : $seconds;
}

/**
 * Hash the database archives in the gate window that have no usable verdict, and store the verdicts.
 *
 * The only writer of the aafm_backup_verify option. Called by the hourly event and by "Verify now".
 * Each run rewrites the option to hold exactly the verdicts of the sets now in the window.
 *
 * @param int  $seconds         Time bound for hashing.
 * @param bool $retry_too_large Hash again an archive an earlier run could not finish.
 * @return string 'ran', 'nothing_to_do', 'not_read' (UpdraftPlus's history could not be read) or 'not_stored'.
 */
function aafm_backup_verify_run( int $seconds = 20, bool $retry_too_large = false ): string {
	if ( ! aafm_stored_switch_on( 'aafm_backup_gate' ) || is_multisite() || ! aafm_site_updraft_loaded() ) {
		return 'nothing_to_do';
	}

	$deadline = microtime( true ) + aafm_backup_verify_bound( $seconds );
	$stored   = aafm_backup_verify_read();

	$call = aafm_siteops_vendor_call(
		static function () use ( $stored, $retry_too_large ): array {
			$window = array();
			$todo   = array();
			$seen   = 0;
			foreach ( aafm_site_updraft_history() as $timestamp => $entry ) {
				if ( ! aafm_site_updraft_in_window( $timestamp ) ) {
					continue;
				}
				if ( ++$seen > 20 ) {
					break;
				}
				$archive = aafm_site_updraft_db_archive( $entry );
				$nonce   = $entry['nonce'] ?? null;
				if ( ! is_string( $nonce ) || 1 !== preg_match( '/^[0-9a-f]{12}$/', $nonce ) ) {
					continue;
				}
				$window[] = $nonce;
				if ( 'present' !== $archive['state'] ) {
					continue;
				}
				$have = $stored[ $nonce ] ?? null;
				if ( ! is_array( $have ) || $have['fingerprint'] !== $archive['fingerprint'] || ( $retry_too_large && 'too_large' === $have['verdict'] ) ) {
					$todo[] = array(
						'nonce'       => $nonce,
						'file'        => (string) $archive['file'],
						'size'        => $archive['size'],
						'sha1'        => (string) $archive['sha1'],
						'fingerprint' => (string) $archive['fingerprint'],
					);
				}
			}
			return array(
				'window' => $window,
				'todo'   => $todo,
			);
		}
	);
	if ( ! $call['ok'] || ! is_array( $call['value'] ) ) {
		return 'not_read';
	}

	$new     = array();
	$started = 0;
	foreach ( $call['value']['todo'] as $item ) {
		// The first archive is always started; a later one only while there is time left.
		if ( $started > 0 && microtime( true ) >= $deadline ) {
			break;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads a local backup archive in chunks so the time bound can be tested between them; WP_Filesystem has no streaming read.
		$handle = fopen( $item['file'], 'rb' );
		if ( false === $handle ) {
			continue;
		}
		$was_first = 0 === $started;
		++$started;

		$context  = hash_init( 'sha1' );
		$finished = false;
		while ( microtime( true ) < $deadline ) {
			if ( 0 === hash_update_stream( $context, $handle, 1048576 ) ) {
				$finished = true;
				break;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the handle opened above.
		fclose( $handle );

		if ( $finished ) {
			/** This action is documented in includes/siteops/backup-updraft.php */
			do_action( 'aafm_site_backup_hashed', $item['size'], 'verifier' );
			// A file that changed while it was read gets no verdict; the next run reads it again.
			if ( aafm_site_updraft_fingerprint( $item['nonce'], $item['file'] ) === $item['fingerprint'] ) {
				$new[ $item['nonce'] ] = array(
					'fingerprint' => $item['fingerprint'],
					'verdict'     => hash_equals( $item['sha1'], hash_final( $context ) ) ? 'verified' : 'failed',
					'checked_at'  => time(),
				);
			}
			continue;
		}

		// Out of time. An archive that had the whole bound to itself is too large to verify here;
		// a later one is simply tried first on the next run.
		if ( $was_first ) {
			$new[ $item['nonce'] ] = array(
				'fingerprint' => $item['fingerprint'],
				'verdict'     => 'too_large',
				'checked_at'  => time(),
			);
		}
		break;
	}

	// Keep exactly the verdicts of the sets now in the window: new ones over what is stored. The
	// row kept for this request is dropped first, so a verdict another run stored meanwhile is read.
	aafm_policy_forget_row( 'aafm_backup_verify' );
	$fresh  = aafm_backup_verify_read();
	$result = array();
	foreach ( $call['value']['window'] as $nonce ) {
		if ( isset( $new[ $nonce ] ) ) {
			$result[ $nonce ] = $new[ $nonce ];
		} elseif ( isset( $fresh[ $nonce ] ) ) {
			$result[ $nonce ] = $fresh[ $nonce ];
		}
	}
	ksort( $result );
	ksort( $fresh );
	if ( $result === $fresh ) {
		return array() === $new ? 'nothing_to_do' : 'ran';
	}

	if ( ! aafm_update_option_verified( 'aafm_backup_verify', $result ) ) {
		aafm_log_ability_persist_failure( 'aafm_backup_verify', __( 'The backup check results', 'agent-abilities-for-mcp' ) );
		return 'not_stored';
	}

	return 'ran';
}
