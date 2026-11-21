<?php
/**
 * Process-wide host stub for UpdraftPlus (1.13.0 backup tests).
 *
 * Required once from the test bootstrap. The two vendor classes are defined only when a test calls
 * aafm_updraft_stub_activate(), because a class cannot be undefined again for the rest of the
 * process. "UpdraftPlus is not loaded" is then modelled by unsetting $GLOBALS['updraftplus'],
 * which is what aafm_site_updraft_loaded() tests first.
 *
 * The stub models only what the plugin reads: UpdraftPlus_Backup_History::get_history() returning
 * timestamp => entry, newest first (updraftplus includes/class-backup-history.php:21-37), the
 * updraft_dir option, and the updraft_jobdata_<nonce> option rows. Archive files are real files in
 * a temp directory, so size, checksum and fingerprint checks run against a real file system.
 *
 * Every file operation the backup tests need lives here, so the test classes hold none.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace {

	/**
	 * Define the vendor classes, mark UpdraftPlus as loaded, and start from an empty history and
	 * an empty temp backup directory. Safe to call from every test's set_up().
	 *
	 * @return void
	 */
	function aafm_updraft_stub_activate(): void {
		if ( ! class_exists( 'UpdraftPlus', false ) ) {
			class UpdraftPlus {}
		}
		if ( ! class_exists( 'UpdraftPlus_Backup_History', false ) ) {
			class UpdraftPlus_Backup_History {
				/**
				 * The vendor reader: timestamp => entry, newest first.
				 *
				 * @param int|false $timestamp Unused by the plugin.
				 * @return array<int,array<string,mixed>>
				 * @throws \RuntimeException When the test asked for a failing reader.
				 */
				public static function get_history( $timestamp = false ) {
					unset( $timestamp );
					++$GLOBALS['aafm_updraft_stub']['reads'];
					if ( isset( $GLOBALS['aafm_updraft_stub']['during_read'] ) && is_callable( $GLOBALS['aafm_updraft_stub']['during_read'] ) ) {
						( $GLOBALS['aafm_updraft_stub']['during_read'] )();
					}
					if ( $GLOBALS['aafm_updraft_stub']['throw'] ) {
						throw new \RuntimeException( 'stub history reader failure' );
					}
					$history = $GLOBALS['aafm_updraft_stub']['history'];
					krsort( $history );
					return $history;
				}
			}
		}

		aafm_updraft_stub_clear_files();
		$dir = sys_get_temp_dir() . '/aafm-updraft-stub-' . getmypid();
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		$GLOBALS['aafm_updraft_stub'] = array(
			'history' => array(),
			'throw'   => false,
			'reads'   => 0,
			'dir'     => $dir,
		);
		$GLOBALS['updraftplus']       = new UpdraftPlus();
		update_option( 'updraft_dir', $dir );
	}

	/**
	 * Mark UpdraftPlus as not loaded and remove the temp files. Call from tear_down().
	 *
	 * @return void
	 */
	function aafm_updraft_stub_deactivate(): void {
		aafm_updraft_stub_clear_files();
		unset( $GLOBALS['updraftplus'] );
		if ( isset( $GLOBALS['aafm_updraft_stub'] ) ) {
			$GLOBALS['aafm_updraft_stub']['history'] = array();
			$GLOBALS['aafm_updraft_stub']['throw']   = false;
		}
	}

	/**
	 * Delete every file in the temp backup directory, and the directory.
	 *
	 * @return void
	 */
	function aafm_updraft_stub_clear_files(): void {
		$dir = $GLOBALS['aafm_updraft_stub']['dir'] ?? '';
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				unlink( $dir . '/' . $name );
			}
		}
		rmdir( $dir );
	}

	/**
	 * The temp backup directory.
	 *
	 * @return string
	 */
	function aafm_updraft_stub_dir(): string {
		return (string) $GLOBALS['aafm_updraft_stub']['dir'];
	}

	/**
	 * Add a finished, local, database-only backup set with a real archive file.
	 *
	 * The entry has the shape UpdraftPlus 1.26.16 wrote for a local database backup (bench3 A2):
	 * db (file name), db-size (int), checksums.sha1.db0 (40 hex), nonce, service (empty array).
	 *
	 * @param string              $nonce       12 hex characters.
	 * @param int                 $age_seconds How long ago the backup started. Each set needs its own age.
	 * @param int                 $bytes       Archive size.
	 * @param array<string,mixed> $overrides   Entry keys to add or replace. A null value removes the key.
	 * @return string The archive's absolute path.
	 */
	function aafm_updraft_stub_add_set( string $nonce, int $age_seconds, int $bytes = 2048, array $overrides = array() ): string {
		$name = 'backup_2026-11-18-1200_Stub_' . $nonce . '-db.gz';
		$data = substr( str_repeat( sha1( $nonce ), (int) ceil( $bytes / 40 ) ), 0, $bytes );
		$file = aafm_updraft_stub_dir() . '/' . $name;
		file_put_contents( $file, $data );

		$entry = array(
			'db'        => $name,
			'db-size'   => $bytes,
			'checksums' => array(
				'sha1'   => array( 'db0' => sha1( $data ) ),
				'sha256' => array( 'db0' => hash( 'sha256', $data ) ),
			),
			'nonce'     => $nonce,
			'service'   => array(),
		);
		foreach ( $overrides as $key => $value ) {
			if ( null === $value ) {
				unset( $entry[ $key ] );
			} else {
				$entry[ $key ] = $value;
			}
		}
		$GLOBALS['aafm_updraft_stub']['history'][ time() - $age_seconds ] = $entry;

		return $file;
	}

	/**
	 * Move a set to another age, keeping its entry and file.
	 *
	 * @param string $nonce       The set's nonce.
	 * @param int    $age_seconds New age.
	 * @return void
	 */
	function aafm_updraft_stub_set_age( string $nonce, int $age_seconds ): void {
		foreach ( $GLOBALS['aafm_updraft_stub']['history'] as $timestamp => $entry ) {
			if ( ( $entry['nonce'] ?? '' ) === $nonce ) {
				unset( $GLOBALS['aafm_updraft_stub']['history'][ $timestamp ] );
				$GLOBALS['aafm_updraft_stub']['history'][ time() - $age_seconds ] = $entry;
				return;
			}
		}
	}

	/**
	 * The stored entry of one set.
	 *
	 * @param string $nonce The set's nonce.
	 * @return array<string,mixed>
	 */
	function aafm_updraft_stub_entry( string $nonce ): array {
		foreach ( $GLOBALS['aafm_updraft_stub']['history'] as $entry ) {
			if ( ( $entry['nonce'] ?? '' ) === $nonce ) {
				return $entry;
			}
		}
		return array();
	}

	/**
	 * Overwrite 16 bytes in the middle of an archive, keeping its size.
	 *
	 * @param string $file Archive path.
	 * @return void
	 */
	function aafm_updraft_stub_corrupt( string $file ): void {
		$data = (string) file_get_contents( $file );
		file_put_contents( $file, substr_replace( $data, str_repeat( 'Z', 16 ), (int) floor( strlen( $data ) / 2 ), 16 ) );
		clearstatcache( true, $file );
	}

	/**
	 * Cut one byte off the end of an archive.
	 *
	 * @param string $file Archive path.
	 * @return void
	 */
	function aafm_updraft_stub_truncate( string $file ): void {
		$data = (string) file_get_contents( $file );
		file_put_contents( $file, substr( $data, 0, -1 ) );
		clearstatcache( true, $file );
	}

	/**
	 * Delete an archive file.
	 *
	 * @param string $file Archive path.
	 * @return void
	 */
	function aafm_updraft_stub_delete( string $file ): void {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
		clearstatcache( true, $file );
	}

	/**
	 * Write a file back with given contents (to restore a deleted archive).
	 *
	 * @param string $file Archive path.
	 * @param string $data Contents.
	 * @return void
	 */
	function aafm_updraft_stub_write( string $file, string $data ): void {
		file_put_contents( $file, $data );
		clearstatcache( true, $file );
	}

	/**
	 * Read an archive's contents.
	 *
	 * @param string $file Archive path.
	 * @return string
	 */
	function aafm_updraft_stub_read( string $file ): string {
		return (string) file_get_contents( $file );
	}

	/**
	 * Give an archive a new mtime.
	 *
	 * @param string $file  Archive path.
	 * @param int    $mtime New mtime.
	 * @return void
	 */
	function aafm_updraft_stub_touch( string $file, int $mtime ): void {
		touch( $file, $mtime );
		clearstatcache( true, $file );
	}

	/**
	 * Add a file archive (plugins, themes, and so on) of a given size to the temp directory.
	 *
	 * @param string $name  File name.
	 * @param int    $bytes Size.
	 * @return void
	 */
	function aafm_updraft_stub_add_file( string $name, int $bytes ): void {
		file_put_contents( aafm_updraft_stub_dir() . '/' . $name, str_repeat( 'f', $bytes ) );
	}

	/**
	 * How many times the plugin read the history since activation.
	 *
	 * @return int
	 */
	function aafm_updraft_stub_reads(): int {
		return (int) $GLOBALS['aafm_updraft_stub']['reads'];
	}

	/**
	 * Make the history reader throw, or stop it throwing.
	 *
	 * @param bool $on Whether to throw.
	 * @return void
	 */
	function aafm_updraft_stub_throw( bool $on ): void {
		$GLOBALS['aafm_updraft_stub']['throw'] = $on;
	}

	/**
	 * Store a job row the way UpdraftPlus does on a single site: option updraft_jobdata_<nonce>.
	 *
	 * @param string              $nonce Job nonce.
	 * @param array<string,mixed> $data  Job data.
	 * @return void
	 */
	function aafm_updraft_stub_job( string $nonce, array $data ): void {
		update_option( 'updraft_jobdata_' . $nonce, $data, false );
	}
}
