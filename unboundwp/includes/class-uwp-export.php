<?php
/**
 * Chunked, resumable site exporter.
 *
 * Why chunked? A big site cannot be exported in one HTTP request,
 * PHP would hit max_execution_time and the browser/proxy would hit a
 * gateway timeout. Instead the browser calls tick() repeatedly; each
 * tick does ~8 seconds of work, saves its state to disk, and returns
 * progress. Refresh, close the tab, come back - export resumes.
 *
 * Archive layout (inside .uwp - same as .wpress):
 *   package.json          metadata (URLs, versions, prefix, file count)
 *   database.sql          full database dump (built incrementally)
 *   wp-content/...        every file under wp-content, same relative path
 *
 * @package UnboundWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UWP_Export {

	const STATE_OPTION = 'uwp_export_state';

	/**
	 * Start a new export job.
	 *
	 * @param string $password Optional encryption password (empty = plain backup).
	 * @return array Job state.
	 * @throws Exception When a password is set but openssl is missing.
	 */
	public static function start( $password = '' ) {
		if ( function_exists( 'uwp_ensure_directories' ) ) {
			uwp_ensure_directories();
		}

		$job_id   = 'export-' . gmdate( 'Ymd-His' ) . '-' . substr( wp_generate_password( 8, false, false ), 0, 6 );
		$work_dir = trailingslashit( uwp_temp_dir() ) . $job_id . '/';
		wp_mkdir_p( $work_dir );

		$state = array(
			'job_id'      => $job_id,
			// Per-job bearer secret: ticks authenticate with this instead
			// of the login session (Ai1WM's secret_key approach), so long
			// runs survive session expiry. Stored server-side only.
			'secret'      => UWP_Helpers::new_job_secret(),
			'work_dir'    => $work_dir,
			'step'        => 'scan',
			'created'     => time(),
			// Scan state: wp-content (bare layout) plus WordPress core trees
			// (ABSPATH-relative), so restores reproduce the exact version.
			'dirs'        => array( WP_CONTENT_DIR, ABSPATH . 'wp-admin', ABSPATH . 'wp-includes' ),
			// File list lives on DISK (files.list, one path per line), never
			// in the state array: 100k+ paths would blow PHP memory every
			// time the state is encoded, and lose nothing on resume.
			'file_list'   => $work_dir . 'files.list',
			'file_total'  => 0,
			'files'       => array(), // legacy bridge only (pre-1.2.5 jobs).
			'scan_done'   => false,
			'skipped_rows' => 0, // poison batches skipped after repeat crashes.
			'skipped_files' => 0, // unreadable files skipped during archiving.
			// Database state.
			'db_state'    => array(),
			'db_done'     => false,
			'sql_path'    => $work_dir . 'database.sql',
			// Archive state.
			'archive_tmp' => $work_dir . 'archive.tmp.uwp',
			'file_index'  => 0,
			'pkg_written' => false,
			'db_archived' => false,
			'final_name'  => UWP_Helpers::backup_filename(),
			'done'         => false,
			// Optional archive password. Lives in the server-side state
			// file only (never in URLs); cleared with the job. Entries are
			// encrypted per-chunk (reference framing); config files stay
			// plain so imports can verify the password up front.
			'password'     => (string) $password,
		);

		// Fresh SQL file.
		file_put_contents( $state['sql_path'], '' ); // phpcs:ignore

		// Seed allowlisted ABSPATH root files (core files live outside the
		// scanned trees; wp-config.php is never in the allowlist).
		foreach ( UWP_Helpers::core_root_files() as $root_file ) {
			$full = ABSPATH . $root_file;
			if ( is_file( $full ) && is_readable( $full ) ) {
				$state['files'][] = $full;
			}
		}

		self::save( $state );
		UWP_Helpers::job_clear_done( self::STATE_OPTION ); // no stale completion.
		if ( class_exists( 'UWP_Logger' ) ) {
			UWP_Logger::prune();
		}
		if ( '' !== $state['password'] && ! UWP_Archive::crypto_available() ) {
			throw new Exception( __( 'Password encryption needs PHP openssl, which is missing on this server.', 'unboundwp' ) );
		}
		// Disk guard BEFORE doing work: a backup needs headroom for the SQL
		// dump, file list and archive assembly next to the live site.
		$free = UWP_Helpers::disk_free_bytes( uwp_backups_dir() );
		if ( false !== $free && $free < 104857600 ) {
			throw new Exception( sprintf( __( 'Not enough disk space for a backup: %s free. Free space and try again.', 'unboundwp' ), size_format( $free ) ) );
		}
		UWP_Helpers::debug_log( sprintf( 'export started: job %s', $job_id ) );
		return $state;
	}

	/**
	 * Load current export state.
	 *
	 * @return array|null
	 */
	public static function load() {
		$state = UWP_Helpers::job_load( self::STATE_OPTION );
		if ( ! is_array( $state ) ) {
			return null;
		}
		if ( empty( $state['work_dir'] ) || ! is_dir( $state['work_dir'] ) ) {
			return null;
		}
		return $state;
	}

	/**
	 * Persist export state.
	 *
	 * @param array $state Job state.
	 */
	public static function save( $state ) {
		UWP_Helpers::job_save( self::STATE_OPTION, $state );
	}

	/**
	 * Cancel and clean up the current job.
	 */
	public static function cancel() {
		$state = UWP_Helpers::job_load( self::STATE_OPTION );
		if ( is_array( $state ) && ! empty( $state['work_dir'] ) ) {
			UWP_Helpers::trash_dir( $state['work_dir'] );
			UWP_Helpers::empty_trash( 2 );
		}
		UWP_Helpers::job_clear( self::STATE_OPTION );
		UWP_Helpers::job_clear_done( self::STATE_OPTION );
	}

	/**
	 * Consume a pinned fatal: decide between surfacing it and skipping.
	 *
	 * Returns true when the same unit already killed the previous tick too
	 * (proven poison): skips it and lets work continue. Covers every step:
	 * a poison directory (scan), file (archive) or row batch (database) can
	 * otherwise loop forever, and each skip is logged and counted.
	 * Returns false when the failure should be shown to the operator.
	 * Pure state-machine logic - easy to test.
	 *
	 * @param array $state Job state (by reference).
	 * @return bool True = skip ahead and continue; false = throw to caller.
	 */
	public static function consume_pinned_fatal( &$state ) {
	$cursor = self::fatal_cursor( $state );
	if ( null === $cursor || ! isset( $state['last_fatal'] ) || $state['last_fatal'] !== $cursor ) {
		$state['last_fatal'] = $cursor;
		return false;
	}
	$step = isset( $state['step'] ) ? $state['step'] : '';
	$state['last_fatal'] = null;
	if ( 'scan' === $step ) {
		$skipped = array_pop( $state['dirs'] );
		$state['skipped_dirs'] = isset( $state['skipped_dirs'] ) ? $state['skipped_dirs'] : array();
		if ( null !== $skipped ) {
			$state['skipped_dirs'][] = $skipped;
		}
		return true;
	}
	if ( 'archive' === $step ) {
		$list = @fopen( self::file_list_path( $state ), 'rb' ); // phpcs:ignore
		if ( false !== $list ) {
			fseek( $list, isset( $state['list_offset'] ) ? (int) $state['list_offset'] : 0 );
			if ( false !== fgets( $list ) ) {
				$state['list_offset'] = ftell( $list );
			}
			fclose( $list );
		}
		$state['file_index']    = ( isset( $state['file_index'] ) ? (int) $state['file_index'] : 0 ) + 1;
		$state['skipped_files'] = ( isset( $state['skipped_files'] ) ? (int) $state['skipped_files'] : 0 ) + 1;
		return true;
	}
	if ( 'database' === $step ) {
		if ( ! isset( $state['db_state'] ) || ! is_array( $state['db_state'] ) ) {
			$state['db_state'] = array();
		}
		$db = &$state['db_state'];
		if ( isset( $db['dump_mode'] ) && 'key' === $db['dump_mode'] ) {
			$db['dump_mode'] = 'off';
			unset( $db['dump_pk'] );
			$db['offset'] = 0;
			return true;
		}
		$db['offset'] = ( isset( $db['offset'] ) ? (int) $db['offset'] : 0 ) + 250;
		$state['skipped_rows'] = ( isset( $state['skipped_rows'] ) ? (int) $state['skipped_rows'] : 0 ) + 250;
		return true;
	}
	return false;
}

	/**
	 * Position identifying where a fatal struck, for poison-unit detection.
	 * Covers scan (top directory), archive (file index) and database
	 * (table cursor). Null for steps with nothing to skip past.
	 *
	 * @param array $state Job state.
	 * @return string|null
	 */
	protected static function fatal_cursor( $state ) {
		if ( ! isset( $state['step'] ) ) {
			return null;
		}
		if ( 'scan' === $state['step'] ) {
			$dirs = ( isset( $state['dirs'] ) && is_array( $state['dirs'] ) ) ? $state['dirs'] : array();
			if ( empty( $dirs ) ) {
				return null;
			}
			return 'scan:' . end( $dirs );
		}
		if ( 'archive' === $state['step'] ) {
			return 'archive:' . ( isset( $state['file_index'] ) ? (int) $state['file_index'] : 0 );
		}
		if ( 'database' !== $state['step'] ) {
			return null;
		}
		$db     = isset( $state['db_state'] ) && is_array( $state['db_state'] ) ? $state['db_state'] : array();
		$tables = isset( $db['tables'] ) && is_array( $db['tables'] ) ? $db['tables'] : array();
		$idx    = isset( $db['table_index'] ) ? (int) $db['table_index'] : 0;
		$table  = isset( $tables[ $idx ] ) ? $tables[ $idx ] : '?';
		$off    = isset( $db['offset'] ) ? (string) $db['offset'] : '0';
		return $idx . ':' . $table . ':' . $off;
	}

	/**
	 * Lightweight progress snapshot for status polling (does no work).
	 *
	 * @return array|null Null when no job exists.
	 */
	public static function describe() {
		$state = self::load();
		if ( null === $state ) {
			return null;
		}
		$step = isset( $state['step'] ) ? $state['step'] : '';
		switch ( $step ) {
			case 'scan':
				$pct = 5;
				$msg = __( 'Scanning files…', 'unboundwp' );
				break;
			case 'database':
				$tables = isset( $state['db_state']['tables'] ) && is_array( $state['db_state']['tables'] ) ? UWP_Helpers::safe_count( $state['db_state']['tables'] ) : 1;
				$idx    = isset( $state['db_state']['table_index'] ) ? (int) $state['db_state']['table_index'] : 0;
				$pct    = 5 + (int) ( 35 * min( 1, $idx / max( 1, $tables ) ) );
				$msg    = __( 'Exporting database…', 'unboundwp' );
				break;
			case 'archive':
				$total = isset( $state['file_total'] ) ? (int) $state['file_total'] : UWP_Helpers::safe_count( isset( $state['files'] ) ? $state['files'] : array() );
				$done  = isset( $state['file_index'] ) ? (int) $state['file_index'] : 0;
				$pct   = 40 + (int) ( 55 * ( $total > 0 ? min( 1, $done / $total ) : 1 ) );
				/* translators: %d = files archived so far */
				$msg = sprintf( __( 'Archiving files… (%d)', 'unboundwp' ), $done );
				break;
			default:
				$pct = 97;
				$msg = __( 'Finalizing…', 'unboundwp' );
				break;
		}
		return array(
			'step'    => $step,
			'percent' => $pct,
			'message' => $msg,
		);
	}

	/**
	 * Do one bounded chunk of work. Called repeatedly via AJAX.
	 *
	 * Lock-shared with loopback ticks: only one worker runs at a time,
	 * and each slice chains the next one server-to-server.
	 *
	 * @return array Progress: done, step, percent, message, file(optional).
	 * @throws Exception On fatal failure.
	 */
	public static function tick() {
		UWP_Helpers::prepare_chunk_request();

		$lock = UWP_Helpers::job_lock( self::STATE_OPTION );
		if ( false === $lock ) {
			return self::busy();
		}
		try {
			$result = self::tick_locked();
		} finally {
			fclose( $lock );
		}

		if ( empty( $result['done'] ) ) {
			$state            = self::load();
			$secret           = ( is_array( $state ) && isset( $state['secret'] ) ) ? (string) $state['secret'] : '';
			$result['chained'] = UWP_Helpers::chain_self( 'uwp_export_tick', $secret );
		}
		return $result;
	}

	/**
	 * "Another worker is on it" envelope - progress only, no work.
	 *
	 * @return array
	 */
	protected static function busy() {
		$state = self::load();
		return array(
			'done'    => false,
			'busy'    => true,
			'chained' => false,
			'step'    => ( is_array( $state ) && isset( $state['step'] ) ) ? $state['step'] : '',
			'percent' => -1, // keep the current bar.
			'message' => __( 'Another worker is already on it…', 'unboundwp' ),
			'nonce'   => wp_create_nonce( 'uwp_nonce' ),
		);
	}

	/**
	 * The actual work slice (runs under the job lock).
	 *
	 * @return array Progress envelope.
	 * @throws Exception On fatal failure.
	 */
	protected static function tick_locked() {
		$started = microtime( true );

		$state = self::load();
		if ( null === $state ) {
			throw new Exception( __( 'No export in progress. Please start a new export.', 'unboundwp' ) );
		}
		$pinned = UWP_Helpers::job_take_fatal( self::STATE_OPTION );
		if ( null !== $pinned ) {
			// Self-healing for poison batches: if the previous tick died
			// twice in a row at the exact same database cursor, skip one
			// batch ahead instead of dying there forever. Loudly logged and
			// counted; a backup missing those rows beats no backup at all.
			if ( self::consume_pinned_fatal( $state ) ) {
				UWP_Helpers::debug_log( sprintf( 'export %s: skipping poison batch (see previous fatal)', $state['job_id'] ) );
				self::save( $state );
				// Fall through to normal work (no throw this time).
			} else {
				self::save( $state );
				throw new Exception( sprintf( __( 'The previous step crashed: %s', 'unboundwp' ), $pinned ) );
			}
		}
		UWP_Helpers::debug_log(
			sprintf(
				'export %s: tick step=%s files=%d archived=%d mem=%d',
				$state['job_id'],
				$state['step'],
				isset( $state['file_total'] ) ? (int) $state['file_total'] : 0,
				isset( $state['file_index'] ) ? (int) $state['file_index'] : 0,
				memory_get_usage( true )
			)
		);

		// Step 1: enumerate wp-content (chunked directory walk).
		if ( 'scan' === $state['step'] ) {
			self::scan_tick( $state, $started );
			self::save( $state );
			if ( $state['scan_done'] ) {
				$state['step'] = 'database';
				self::save( $state );
			}
			return self::progress( $state, __( 'Scanning files…', 'unboundwp' ), 5 );
		}

		// Step 2: dump the database incrementally.
		if ( 'database' === $state['step'] ) {
			$out = fopen( $state['sql_path'], 'ab' ); // phpcs:ignore
			if ( false === $out ) {
				throw new Exception( __( 'Cannot write database dump.', 'unboundwp' ) );
			}
			while ( ! UWP_Helpers::out_of_time( $started ) && ! UWP_Helpers::memory_hot() ) {
				$finished = UWP_Database::dump_tick( $out, $state['db_state'], 250, $started + UWP_AJAX_TIME_BUDGET );
				if ( $finished ) {
					break;
				}
			}
			fclose( $out );
			self::save( $state );

			$table_count = isset( $state['db_state']['tables'] ) && is_array( $state['db_state']['tables'] ) ? UWP_Helpers::safe_count( $state['db_state']['tables'] ) : 0;
			$table_index = isset( $state['db_state']['table_index'] ) ? (int) $state['db_state']['table_index'] : 0;

			// dump_tick is done when its table cursor runs past the end,
			// including the edge case of a database with zero tables.
			if ( isset( $state['db_state']['tables'] ) && $table_index >= $table_count ) {
				$state['db_done'] = true;
			}
			if ( ! empty( $state['db_done'] ) ) {
				$state['step'] = 'archive';
				self::save( $state );
			}
			$pct = $table_count > 0 ? (int) ( 5 + 35 * min( 1, $table_index / max( 1, $table_count ) ) ) : 10;
			return self::progress( $state, __( 'Exporting database…', 'unboundwp' ), $pct );
		}

		// Step 3: stream everything into the archive.
		if ( 'archive' === $state['step'] ) {
			$archive = UWP_Archive::open_for_append( $state['archive_tmp'] );

			// 3a) package.json first (so imports can read metadata fast).
			if ( ! $state['pkg_written'] ) {
				UWP_Archive::append_string( $archive, '.', 'package.json', self::package_json( $state ) );
				// Minimal blogs.json (single-site entry) so All-in-One WP
				// Migration can also read our archives - its importer
				// requires the file to exist.
				UWP_Archive::append_string( $archive, '.', 'blogs.json', self::blogs_json( get_option( 'siteurl' ), get_option( 'home' ) ) );
				// Keep the handle open for the rest of this tick.
				$state['pkg_written'] = true;
			}

			// 3b) database.sql next.
			if ( ! $state['db_archived'] && ! UWP_Helpers::out_of_time( $started ) ) {
				UWP_Archive::append_file( $archive, $state['sql_path'], '.', 'database.sql', isset( $state['password'] ) ? $state['password'] : null );
				$state['db_archived'] = true;
			}

		// 3c) site files, a bounded batch per tick, streamed from files.list
		// (never the whole list in memory).
		$total = isset( $state['file_total'] ) ? (int) $state['file_total'] : 0;
		if ( $total <= 0 && ! empty( $state['files'] ) ) {
			// Legacy bridge (pre-1.2.5 resumed job): spill + position.
			self::migrate_legacy_list( $state );
			$total = (int) $state['file_total'];
		}
		if ( ! isset( $state['file_index'] ) ) {
			$state['file_index'] = 0;
		}
		if ( ! isset( $state['list_offset'] ) ) {
			$state['list_offset'] = 0;
		}
		if ( ! isset( $state['skipped_files'] ) ) {
			$state['skipped_files'] = 0;
		}
		$list = @fopen( self::file_list_path( $state ), 'rb' ); // phpcs:ignore
		if ( false === $list ) {
			if ( $total > 0 ) {
				throw new Exception( __( 'Export file list is missing. Please start a new export.', 'unboundwp' ) );
			}
		} else {
			// Position at the first unconsumed line. The persisted byte
			// offset is exact; only legacy resumed jobs (freshly migrated
			// list, unknown byte position) skip ahead by line count.
			if ( (int) $state['list_offset'] > 0 ) {
				fseek( $list, (int) $state['list_offset'] );
			} else {
				$skip = (int) $state['file_index'];
				while ( $skip > 0 && ! feof( $list ) ) {
					if ( false === fgets( $list ) ) {
						break;
					}
					$skip--;
				}
			}
			while ( $state['file_index'] < $total && ! UWP_Helpers::out_of_time( $started ) ) {
				// Bound by count AND bytes so one giant video can't blow the budget.
				$batch_bytes = 0;
				$batch_max   = 20 * 1024 * 1024; // ~20 MB per tick.
				$batch_files = 0;
				while ( $state['file_index'] < $total && $batch_files < 200 && $batch_bytes < $batch_max ) {
					$line = fgets( $list ); // phpcs:ignore
					if ( false === $line ) {
						break; // truncated list: stop rather than spin.
					}
					$abs = trim( $line );
					$state['file_index']++;
					$batch_files++;
					if ( '' === $abs ) {
						continue;
					}
					// Heartbeat marker every 1000 files: a death between two
					// markers implicates that exact file range in the log.
					if ( 0 === ( $state['file_index'] % 1000 ) ) {
						UWP_Helpers::debug_log( sprintf( 'archive %s: file %d/%d (%s)', $state['job_id'], $state['file_index'], $total, $abs ) );
					}
					if ( UWP_Helpers::is_regular_file( $abs ) && is_readable( $abs ) ) {
						$rel = self::archive_name( $abs );
						if ( null !== $rel ) {
							// Root-level rels share one namespace: a second
							// entry for the same rel would silently overwrite
							// the first on extraction (field case: a stub
							// index.php over the real front controller and a
							// blank homepage with zero evidence). Keep the
							// first, skip loudly. (Non-root rels derive 1:1
							// from absolute paths, so only roots are tracked
							// - the state array must stay small.)
							if ( false === strpos( $rel, '/' ) ) {
								if ( ! isset( $state['root_emitted'] ) || ! is_array( $state['root_emitted'] ) ) {
									$state['root_emitted'] = array();
								}
								if ( isset( $state['root_emitted'][ $rel ] ) ) {
									$now = is_file( $abs ) ? filesize( $abs ) : -1;
									UWP_Helpers::debug_log( sprintf( 'export %s: WARNING: duplicate archive rel "%s" (%d then %d bytes) from %s - keeping first, skipping', $state['job_id'], $rel, $state['root_emitted'][ $rel ], $now, $abs ) );
									continue;
								}
								$state['root_emitted'][ $rel ] = is_file( $abs ) ? filesize( $abs ) : -1;
							}
							$dir_part  = dirname( $rel );
							$base_part = basename( $rel );
							try {
								UWP_Archive::append_file( $archive, $abs, '.' === $dir_part ? '.' : $dir_part, $base_part, isset( $state['password'] ) ? $state['password'] : null );
							} catch ( Exception $e ) {
								// One unreadable file must never kill a whole
								// export: count it, log it, keep going.
								$state['skipped_files']++;
								if ( $state['skipped_files'] <= 5 ) {
									UWP_Helpers::debug_log( sprintf( 'export %s: skipped unreadable file %s (%s)', $state['job_id'], $abs, $e->getMessage() ) );
								}
								continue;
							}
							$batch_bytes += filesize( $abs );
						}
					}
					if ( UWP_Helpers::out_of_time( $started ) ) {
						break;
					}
				}
			}
			$state['list_offset'] = ftell( $list );
			fclose( $list );
		}
		fclose( $archive );
		self::save( $state );

		$pct = 40 + (int) ( 55 * ( $total > 0 ? $state['file_index'] / $total : 1 ) );
		if ( $state['file_index'] >= $total ) {
			$state['step'] = 'finalize';
			self::save( $state );
		}
		return self::progress( $state, __( 'Archiving files…', 'unboundwp' ), min( 95, $pct ) );
	}

		// Step 4: finalize - EOF, move to backups, cleanup.
		if ( 'finalize' === $state['step'] ) {
			$archive = UWP_Archive::open_for_append( $state['archive_tmp'] );
			UWP_Archive::finish( $archive ); // finish() appends EOF and closes.

			$dest = trailingslashit( uwp_backups_dir() ) . $state['final_name'];
			if ( ! @rename( $state['archive_tmp'], $dest ) ) { // phpcs:ignore
				// Cross-filesystem fallback: stream copy.
				self::copy_big( $state['archive_tmp'], $dest );
				@unlink( $state['archive_tmp'] ); // phpcs:ignore
			}

			UWP_Helpers::trash_dir( $state['work_dir'] );
			UWP_Helpers::empty_trash( 2 );
			UWP_Helpers::job_clear( self::STATE_OPTION );

			$file_name = basename( $dest );

			$done_message = __( 'Export complete.', 'unboundwp' );
			if ( isset( $state['password'] ) && '' !== (string) $state['password'] ) {
				$done_message .= ' ' . __( 'This backup is password-encrypted: imports will ask for the password.', 'unboundwp' );
			}
			$skipped_rows  = isset( $state['skipped_rows'] ) ? (int) $state['skipped_rows'] : 0;
			$skipped_files = isset( $state['skipped_files'] ) ? (int) $state['skipped_files'] : 0;
			$skipped_dirs  = isset( $state['skipped_dirs'] ) && is_array( $state['skipped_dirs'] ) ? UWP_Helpers::safe_count( $state['skipped_dirs'] ) : 0;
			if ( $skipped_rows > 0 || $skipped_files > 0 || $skipped_dirs > 0 ) {
				$done_message .= ' ' . sprintf(
					/* translators: 1: rows, 2: files, 3: directories */
					__( 'Skipped %1$d database rows, %2$d files and %3$d directories after repeated crashes (see log). Review the backup before relying on it.', 'unboundwp' ),
					$skipped_rows,
					$skipped_files,
					$skipped_dirs
				);
			}
			// Source-damage guard: if THIS site's front controller is not a
			// WordPress bootstrap, every restore will carry a blank homepage.
			// Say so now, at the origin, instead of debugging the target.
			if ( ! self::front_controller_ok() ) {
				$done_message .= ' ' . __( 'Warning: the ABSPATH index.php here is not the WordPress front controller. Restores will show a blank homepage until it is repaired. See log.', 'unboundwp' );
				UWP_Helpers::debug_log( sprintf( 'export %s: WARNING: ABSPATH index.php is not a WP bootstrap', $state['job_id'] ) );
			}

			UWP_Helpers::job_write_done(
				self::STATE_OPTION,
				array(
					'message' => $done_message,
					'job_id'  => $state['job_id'],
					'file'    => $file_name,
					'size'    => filesize( $dest ),
				)
			);

			return array(
				'done'    => true,
				'step'    => 'done',
				'percent' => 100,
				'message' => $done_message,
				'file'    => $file_name,
				'size'    => filesize( $dest ),
				'nonce'   => wp_create_nonce( 'uwp_nonce' ),
				// Direct download link, like the original plugin shows after export.
				'download_url' => admin_url(
					'admin-ajax.php?action=uwp_backup_download&file=' . rawurlencode( $file_name ) . '&nonce=' . wp_create_nonce( 'uwp_nonce' )
				),
			);
		}

		throw new Exception( __( 'Unknown export step.', 'unboundwp' ) );
	}

	/**
	 * One bounded directory-scan tick.
	 *
	 * Found paths stream straight into files.list on disk (buffered
	 * appends) - the state array only keeps counts, so memory stays flat
	 * no matter how many files the site has.
	 *
	 * @param array $state Job state (by reference).
	 * @param float $started Tick start time.
	 */
	protected static function scan_tick( &$state, $started ) {
		// One-time bridge for pre-1.2.5 jobs whose file list lives in the
		// state array: spill it to disk, then continue in list-file mode.
		// Handles both shapes (numeric list and assoc set).
		if ( ! empty( $state['files'] ) ) {
			$paths = array();
			foreach ( $state['files'] as $k => $v ) {
				if ( is_string( $v ) && '' !== $v ) {
					$paths[] = $v;
				} elseif ( is_string( $k ) ) {
					$paths[] = $k;
				}
			}
			self::append_file_list( $state, $paths );
			$state['files'] = array();
			self::save( $state );
		}
		$buf = array();
		$skip = array(
			// Never archive our own temp work or finished backups inside uploads.
			'unboundwp-temp',
			'unboundwp-backups',
			'ai1wm-backups',
			// Cache / backup noise that bloats archives and breaks restores.
			'cache',
			'wflogs',
		);

		while ( ! empty( $state['dirs'] ) && ! UWP_Helpers::out_of_time( $started ) ) {
			$dir = array_pop( $state['dirs'] );
			if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
				continue;
			}
			// Stream entries one by one (opendir/readdir), NEVER scandir():
			// a single cache/session dir with hundreds of thousands of
			// files would otherwise blow PHP memory in one call.
			$handle = @opendir( $dir ); // phpcs:ignore
			if ( false === $handle ) {
				continue;
			}
			$finished_dir = true;
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$full = $dir . DIRECTORY_SEPARATOR . $entry;
				// Skip symlinks that escape the scanned roots (safety).
				if ( is_link( $full ) ) {
					continue;
				}
				if ( is_dir( $full ) ) {
					$base = basename( $full );
					if ( in_array( strtolower( $base ), $skip, true ) ) {
						// Still allow the top-level uploads dir itself, just skip
						// known-noisy subfolders by name match at any depth except
						// exact backup/temp dirs handled above. Keep it simple:
						// skip directories literally named as below.
						if ( in_array( $base, array( 'unboundwp-temp', 'unboundwp-backups', 'ai1wm-backups' ), true ) ) {
							continue;
						}
					}
					$state['dirs'][] = $full;
				} elseif ( UWP_Helpers::is_regular_file( $full ) ) {
					// Dedupe against recently appended paths: a re-queued
					// directory replays its head next tick. The window covers
					// typical replays; anything beyond it may archive twice
					// (same bytes, so restores just overwrite identically).
					// Same-name collisions across different sources are
					// caught separately by the root-rel gate in the archive
					// step, which keeps the first copy and warns loudly.
					if ( ! isset( $state['recent'] ) || ! is_array( $state['recent'] ) ) {
						$state['recent'] = array();
					}
					if ( ! isset( $state['recent'][ $full ] ) ) {
						$buf[] = $full;
						$state['recent'][ $full ] = true;
						if ( UWP_Helpers::safe_count( $state['recent'] ) > 1000 ) {
							$state['recent'] = array_slice( $state['recent'], -500, null, true );
						}
					}
					if ( UWP_Helpers::safe_count( $buf ) >= 500 ) {
						self::append_file_list( $state, $buf );
						$buf = array();
					}
				}
				if ( UWP_Helpers::out_of_time( $started ) ) {
					$finished_dir = false;
					break;
				}
			}
			closedir( $handle );
			if ( UWP_Helpers::safe_count( $buf ) >= 500 ) {
				self::append_file_list( $state, $buf );
				$buf = array();
			}
			if ( ! $finished_dir ) {
				$state['dirs'][] = $dir; // resume this directory next tick.
				break;
			}
		}
		if ( UWP_Helpers::safe_count( $buf ) > 0 ) {
			self::append_file_list( $state, $buf );
		}

		if ( empty( $state['dirs'] ) ) {
			$state['scan_done'] = true;
		}
	}

	/**
	 * Absolute path of the on-disk file list, ensuring a default for very
	 * old resumed jobs that predate list-file mode.
	 *
	 * @param array $state Job state (by reference).
	 * @return string Absolute path.
	 * @throws Exception When no work dir exists to anchor it.
	 */
	protected static function file_list_path( &$state ) {
		if ( empty( $state['file_list'] ) ) {
			if ( empty( $state['work_dir'] ) ) {
				throw new Exception( __( 'Export state is corrupt. Please start a new export.', 'unboundwp' ) );
			}
			$state['file_list'] = trailingslashit( $state['work_dir'] ) . 'files.list';
		}
		if ( ! isset( $state['file_total'] ) ) {
			$state['file_total'] = 0;
		}
		return $state['file_list'];
	}

	/**
	 * Append paths to the on-disk file list (buffered by callers).
	 *
	 * @param array $state Job state (by reference; file_total updated).
	 * @param array $paths Absolute paths.
	 */
	protected static function append_file_list( &$state, $paths ) {
		if ( empty( $paths ) ) {
			return;
		}
		$handle = @fopen( self::file_list_path( $state ), 'ab' ); // phpcs:ignore
		if ( false === $handle ) {
			throw new Exception( __( 'Cannot write export file list.', 'unboundwp' ) );
		}
		foreach ( $paths as $path ) {
			fwrite( $handle, $path . "\n" ); // phpcs:ignore
		}
		fclose( $handle );
		if ( ! isset( $state['file_total'] ) ) {
			$state['file_total'] = 0;
		}
		if ( ! is_array( $paths ) ) {
			$paths = array();
		}
		$state['file_total'] = (int) $state['file_total'] + UWP_Helpers::safe_count( $paths );
	}

	/**
	 * One-time migration for pre-1.2.5 resumed jobs whose file list lives
	 * in the state array (numeric list or assoc set - both handled).
	 *
	 * @param array $state Job state (by reference).
	 */
	protected static function migrate_legacy_list( &$state ) {
		if ( empty( $state['files'] ) || ! is_array( $state['files'] ) ) {
			$state['files'] = array();
			return;
		}
		$paths = array();
		foreach ( $state['files'] as $k => $v ) {
			if ( is_string( $v ) && '' !== $v ) {
				$paths[] = $v;
			} elseif ( is_string( $k ) ) {
				$paths[] = $k;
			}
		}
		$state['files'] = array();
		self::append_file_list( $state, $paths );
		self::save( $state );
	}

	/**
	 * Map an absolute path to its archive-relative name.
	 *
	 * Two layouts, both unambiguous on restore:
	 * - Inside wp-content: bare, like All-in-One WP Migration
	 *   (uploads/2024/x.jpg, plugins/akismet/a.php) - readable by any
	 *   .wpress tool, not just UnboundWP.
	 * - WordPress core: ABSPATH-relative (wp-admin/…, wp-includes/…,
	 *   plus allowlisted root files) so restores can match the backup's
	 *   exact version (upgrade or downgrade).
	 * Anything else (wp-config.php, server dotfiles, outside roots) is
	 * left out by returning null.
	 *
	 * Twin-exclusion (field case, 1.4.1): a bare root-level name that is
	 * ALSO an allowlisted ABSPATH root file (in practice wp-content's own
	 * silence-stub index.php vs the real front controller) must never
	 * share the rel - extraction is positional, so the second entry would
	 * silently overwrite the first and brick the restored homepage with
	 * zero evidence. The ABSPATH copy always wins; the content twin is
	 * left out (it is regenerable boilerplate).
	 *
	 * @param string $abs Absolute path.
	 * @return string|null Relative name or null when not archivable.
	 */
	public static function archive_name( $abs ) {
		$path    = wp_normalize_path( $abs );
		$content = rtrim( wp_normalize_path( WP_CONTENT_DIR ), '/' ) . '/';
		if ( 0 === strpos( $path, $content ) ) {
			$rel = ltrim( substr( $path, strlen( $content ) ), '/' );
			if ( false === strpos( $rel, '/' ) && in_array( $rel, UWP_Helpers::core_root_files(), true ) ) {
				return null; // content twin of an ABSPATH root file - see above.
			}
			return $rel;
		}
		$base = rtrim( wp_normalize_path( ABSPATH ), '/' ) . '/';
		if ( 0 !== strpos( $path, $base ) ) {
			return null;
		}
		$rel = ltrim( substr( $path, strlen( $base ) ), '/' );
		if ( 0 === strpos( $rel, 'wp-admin/' ) || 0 === strpos( $rel, 'wp-includes/' ) ) {
			return $rel;
		}
		if ( false === strpos( $rel, '/' ) && in_array( $rel, UWP_Helpers::core_root_files(), true ) ) {
			return $rel;
		}
		return null;
	}

	/**
	 * Build a minimal single-site blogs.json (Ai1WM-compatible shape).
	 *
	 * Pure function of its inputs so it stays easy to test.
	 *
	 * @param string $site_url Site URL at export time.
	 * @param string $home_url Home URL at export time.
	 * @return string JSON.
	 */
	public static function blogs_json( $site_url, $home_url ) {
		$blogs = array(
			array(
				'Old' => array(
					'BlogID'  => 1,
					'SiteURL' => (string) $site_url,
					'HomeURL' => (string) $home_url,
				),
				'New' => array(
					'BlogID'  => 1,
					'SiteURL' => (string) $site_url,
					'HomeURL' => (string) $home_url,
				),
			),
		);
		return wp_json_encode( $blogs, JSON_PRETTY_PRINT );
	}

	/**
	 * Build package.json metadata.
	 *
	 * @param array $state Job state.
	 * @return string JSON.
	 */
	protected static function package_json( $state ) {
		global $wpdb, $wp_version;
		$uploads = wp_upload_dir();
		$password = isset( $state['password'] ) ? (string) $state['password'] : '';
		$data = array(
			'Plugin'         => array(
				'Name'    => 'UnboundWP',
				'Version' => UWP_VERSION,
				'Format'  => 'wpress-compatible',
			),
			'SiteURL'        => get_option( 'siteurl' ),
			'HomeURL'        => get_option( 'home' ),
			'UploadsURL'     => isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '',
			'ABSPATH'        => ABSPATH,
			'ContentDir'     => WP_CONTENT_DIR,
			'WordPress'      => array( 'Version' => $wp_version ),
			'PHP'            => array( 'Version' => PHP_VERSION ),
			'Database'       => array( 'Prefix' => $wpdb->prefix ),
			'Created'        => gmdate( 'c' ),
			'FileCount'      => isset( $state['file_total'] ) ? (int) $state['file_total'] : UWP_Helpers::safe_count( isset( $state['files'] ) ? $state['files'] : array() ),
			'Core'           => true, // wp-admin + wp-includes + root files included.
			'Encrypted'      => '' !== $password,
			'EncryptedSignature' => '' !== $password ? UWP_Archive::encrypt_signature( $password ) : '',
			'Compression'    => array( 'Enabled' => false ),
			'Multisite'      => is_multisite(),
			'ActivePlugins'  => get_option( 'active_plugins', array() ),
			'ActiveTheme'    => array(
				'Template'   => get_option( 'template' ),
				'Stylesheet' => get_option( 'stylesheet' ),
			),
			// 1:1 fidelity manifest: the importer re-checks these after the
			// restore and reports any drift (rather than completing silently
			// into a site that merely LOOKS restored).
			'Fidelity'       => self::fidelity_snapshot(),
		);
		return wp_json_encode( $data, JSON_PRETTY_PRINT );
	}

	/**
	 * Snapshot the handful of values that define "same site": routing,
	 * identity (adjusted for the new domain on import), and row counts.
	 *
	 * @return array Scalar manifest (cheap: a few options + COUNT queries).
	 */
	public static function fidelity_snapshot() {
		global $wpdb;
		$users = -1;
		$posts = -1;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'get_var' ) && isset( $wpdb->users, $wpdb->posts ) ) {
			$u = $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->users}`" ); // phpcs:ignore
			$p = $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->posts}`" ); // phpcs:ignore
			$users = null === $u ? -1 : (int) $u;
			$posts = null === $p ? -1 : (int) $p;
		}
		return array(
			'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
			'template'            => (string) get_option( 'template', '' ),
			'stylesheet'          => (string) get_option( 'stylesheet', '' ),
			'users'               => $users,
			'posts'               => $posts,
		);
	}

	/**
	 * Whether this site's ABSPATH index.php is the WordPress bootstrap.
	 *
	 * Pure check of file content (first 2 KB must require wp-blog-header).
	 *
	 * @return bool
	 */
	public static function front_controller_ok() {
		$index = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/index.php';
		if ( ! is_file( $index ) || ! is_readable( $index ) ) {
			return false;
		}
		$head = @file_get_contents( $index, false, null, 0, 2048 ); // phpcs:ignore
		return is_string( $head ) && false !== strpos( $head, 'wp-blog-header' );
	}

	/**
	 * Progress envelope for the browser.
	 *
	 * @param array  $state Job state.
	 * @param string $message Human message.
	 * @param int    $percent 0-100.
	 * @return array
	 */
	protected static function progress( $state, $message, $percent ) {
		return array(
			'done'    => false,
			'step'    => $state['step'],
			'percent' => max( 0, min( 99, (int) $percent ) ),
			'message' => $message,
			'files'   => UWP_Helpers::safe_count( isset( $state['files'] ) ? $state['files'] : array() ),
			// Fresh nonce every tick: long exports can outlive the nonce
			// that was printed when the admin page loaded.
			'nonce'   => wp_create_nonce( 'uwp_nonce' ),
		);
	}

	/**
	 * Stream-copy a big file (rename fallback across filesystems).
	 *
	 * @param string $from Source.
	 * @param string $to Destination.
	 * @throws Exception On failure.
	 */
	protected static function copy_big( $from, $to ) {
		$in = @fopen( $from, 'rb' ); // phpcs:ignore
		if ( false === $in ) {
			throw new Exception( __( 'Cannot finalize backup.', 'unboundwp' ) );
		}
		$out = @fopen( $to, 'wb' ); // phpcs:ignore
		if ( false === $out ) {
			fclose( $in );
			throw new Exception( __( 'Cannot write to backups directory.', 'unboundwp' ) );
		}
		while ( ! feof( $in ) ) {
			$data = fread( $in, UWP_CHUNK_BYTES ); // phpcs:ignore
			if ( false === $data || '' === $data ) {
				break;
			}
			fwrite( $out, $data ); // phpcs:ignore
		}
		fclose( $in );
		fclose( $out );
	}
}
