<?php
/**
 * Chunked, resumable site importer / 1-click restorer.
 *
 * Pipeline (each step runs across many small AJAX ticks):
 *   1. validate  - archive exists, readable, has package.json + database.sql
 *   2. extract   - stream entries to a staging dir (decodes gzip/encrypted .wpress)
 *   3. database  - stream database.sql into MySQL in ~1 MB ticks
 *   4. replace   - serialization-safe old-URL -> new-URL replacement, batched
 *   5. files     - copy staged wp-content over the live site, batched
 *   6. core      - atomic core swap (rename, or resumable copy fallback)
 *   7. reactivate - backup plugins back a few per tick, crash-isolated
 *   8. finalize  - theme fallback, flush caches, cleanup
 *
 * Timeouts are impossible by construction: no tick does more than
 * ~8 seconds of work, uploads arrive in 2 MB slices, and every step
 * persists its offset so it can resume after any interruption.
 *
 * @package UnboundWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UWP_Import {

	const STATE_OPTION = 'uwp_import_state';

	/**
	 * Start an import from a finished backup or an uploaded archive.
	 *
	 * @param string $archive_path Absolute .uwp/.wpress path.
	 * @param string $password Optional password for encrypted Ai1WM files.
	 * @param array  $options Optional restore options (see
	 *                        restore_options_from_request()).
	 * @return array Job state.
	 */
	public static function start( $archive_path, $password = '', $options = array() ) {
		if ( function_exists( 'uwp_ensure_directories' ) ) {
			uwp_ensure_directories();
		}

		if ( ! is_file( $archive_path ) || ! is_readable( $archive_path ) ) {
			throw new Exception( __( 'Archive not found or not readable.', 'unboundwp' ) );
		}
		if ( ! UWP_Helpers::is_supported_archive( $archive_path ) ) {
			throw new Exception( __( 'Only .uwp and .wpress files can be imported.', 'unboundwp' ) );
		}

		$job_id   = 'import-' . gmdate( 'Ymd-His' ) . '-' . substr( wp_generate_password( 8, false, false ), 0, 6 );
		$work_dir = trailingslashit( uwp_temp_dir() ) . $job_id . '/';
		$stage    = $work_dir . 'stage/';
		wp_mkdir_p( $stage );

		$state = array(
			'job_id'       => $job_id,
			// Per-job bearer secret: ticks authenticate with this instead
			// of the login session (Ai1WM's secret_key approach), so the
			// run survives the database (and session) replacement.
			'secret'       => UWP_Helpers::new_job_secret(),
			'work_dir'     => $work_dir,
			'stage_dir'    => $stage,
			'archive'      => $archive_path,
			'password'     => (string) $password,
			'step'         => 'validate',
			'created'      => time(),
			// Validate/scan.
			'package'      => null,
			'file_count'   => 0,
			'total_bytes'  => 0,
			// Extract.
			'extract_offset' => 0, // byte offset of next header in archive.
			'extracted'      => 0,
			// Database.
			'sql_path'     => '',
			'db_state'     => array(),
			// Replace.
			'live'         => array(),
			'pairs'        => array(),
			'replace_state' => array(),
			// Files.
			'copy_list'    => array(),
			'copy_index'   => 0,
			// Login session snapshot (restored after the DB import so the
			// restore doesn't log you out mid-run - see snapshot_session()).
			'session'      => null,
			'session_ok'   => false,
			'done'         => false,
			// Restore options (operator-chosen, all default off = classic
			// full restore; persisted so resume honors the same choices).
			'keep_core'    => ! empty( $options['keep_core'] ),
			'skip_plugins' => ! empty( $options['skip_plugins'] ),
		);
		self::snapshot_session( $state );
		self::save( $state );
		UWP_Helpers::job_clear_done( self::STATE_OPTION ); // no stale completion.
		UWP_Helpers::maintenance_sweep(); // clear any pre-1.0.9 marker.
		if ( function_exists( 'delete_option' ) ) {
			delete_option( 'uwp_last_restore' ); // fresh run, fresh notice.
		}
		if ( class_exists( 'UWP_Logger' ) ) {
			UWP_Logger::prune();
		}
		UWP_Helpers::debug_log(
			sprintf(
				'import started: job %s, archive %s (%d bytes), session %s',
				$job_id,
				basename( $archive_path ),
				filesize( $archive_path ),
				null !== $state['session'] ? 'snapshotted' : 'none (re-login will be needed)'
			)
		);
		return $state;
	}

	/**
	 * Restore options from the start request (checkboxes, all optional).
	 *
	 * Pure function of $_POST - easy to test. Missing keys mean defaults
	 * (off), so older callers and resumed jobs behave exactly as before.
	 *
	 * @return array array('keep_core' => bool, 'skip_plugins' => bool).
	 */
	public static function restore_options_from_request() {
		$keep = isset( $_POST['keep_core'] ) ? (string) wp_unslash( $_POST['keep_core'] ) : ''; // phpcs:ignore
		$skip = isset( $_POST['skip_plugins'] ) ? (string) wp_unslash( $_POST['skip_plugins'] ) : ''; // phpcs:ignore
		return array(
			'keep_core'    => '1' === $keep,
			'skip_plugins' => '1' === $skip,
		);
	}

	/**
	 * Load current import state.
	 *
	 * @return array|null
	 */
	public static function load() {
		$state = UWP_Helpers::job_load( self::STATE_OPTION );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Persist import state.
	 *
	 * @param array $state Job state.
	 */
	public static function save( $state ) {
		UWP_Helpers::job_save( self::STATE_OPTION, $state );
	}

	/**
	 * Cancel and clean up.
	 */
	public static function cancel() {
		$state = UWP_Helpers::job_load( self::STATE_OPTION );
		if ( is_array( $state ) && ! empty( $state['work_dir'] ) ) {
			// Instant move-aside; actual deletion happens budgeted in the
			// background so cancel itself can never time out.
			UWP_Helpers::trash_dir( $state['work_dir'] );
			UWP_Helpers::empty_trash( 2 );
		}
		UWP_Helpers::job_clear( self::STATE_OPTION );
		UWP_Helpers::job_clear_done( self::STATE_OPTION );
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
			case 'validate':
				$pct = 5;
				$msg = __( 'Verifying backup…', 'unboundwp' );
				break;
			case 'extract':
				$total = isset( $state['file_count'] ) ? max( 1, (int) $state['file_count'] ) : 1;
				$done  = isset( $state['extracted'] ) ? (int) $state['extracted'] : 0;
				$pct   = 8 + (int) ( 42 * min( 1, $done / $total ) );
				/* translators: %d = files extracted so far */
				$msg = sprintf( __( 'Extracting files… (%d)', 'unboundwp' ), $done );
				break;
			case 'database':
				$size = isset( $state['sql_path'] ) && is_file( $state['sql_path'] ) ? filesize( $state['sql_path'] ) : 1;
				$off  = isset( $state['db_state']['offset'] ) ? (int) $state['db_state']['offset'] : 0;
				$pct  = 50 + (int) ( 22 * min( 1, $off / max( 1, $size ) ) );
				$msg  = __( 'Importing database…', 'unboundwp' );
				break;
			case 'replace':
				$pct = 73;
				$msg = __( 'Updating URLs…', 'unboundwp' );
				break;
			case 'files_scan':
				$pct = 75;
				$msg = __( 'Cataloguing files…', 'unboundwp' );
				break;
			case 'files':
				$total = isset( $state['copy_list'] ) && is_array( $state['copy_list'] ) ? UWP_Helpers::safe_count( $state['copy_list'] ) : 0;
				$done  = isset( $state['copy_index'] ) ? (int) $state['copy_index'] : 0;
				$ctotal = isset( $state['core_list'] ) && is_array( $state['core_list'] ) ? UWP_Helpers::safe_count( $state['core_list'] ) : 0;
				$cdone  = isset( $state['core_index'] ) ? (int) $state['core_index'] : 0;
				$all    = $total + $ctotal;
				$units  = $done + $cdone;
				$pct   = 76 + (int) ( 18 * ( $all > 0 ? $units / $all : 1 ) );
				/* translators: 1: copied, 2: total */
				$msg = sprintf( __( 'Copying files… (%1$d/%2$d)', 'unboundwp' ), $units, $all );
				break;
			case 'core':
				$pct = 95;
				$msg = __( 'Swapping WordPress core…', 'unboundwp' );
				break;
			case 'reactivate':
				$pct = 96;
				$msg = __( 'Reactivating plugins…', 'unboundwp' );
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
	 * Remember the current login before the database is replaced.
	 *
	 * Importing database.sql overwrites wp_usermeta - including the
	 * session_tokens row that keeps YOU logged in. Without this snapshot
	 * every restore would kill its own auth cookie mid-run and all later
	 * steps would fail with "session expired".
	 *
	 * @param array $state Job state (by reference).
	 */
	protected static function snapshot_session( &$state ) {
		$state['session']    = null;
		$state['session_ok'] = false;
		if ( ! function_exists( 'wp_get_session_token' ) || ! function_exists( 'get_current_user_id' ) ) {
			return;
		}
		$user_id = get_current_user_id();
		$token   = wp_get_session_token();
		if ( $user_id && $token ) {
			$state['session'] = array(
				'user_id' => (int) $user_id,
				'token'   => $token,
			);
		}
	}

	/**
	 * Re-register the snapshotted login in the freshly imported database.
	 *
	 * The auth cookie in the browser is still cryptographically valid
	 * (keys live in wp-config.php, untouched) - it only needs its
	 * session_tokens row back, with a fresh expiration.
	 *
	 * @param array $state Job state (by reference).
	 * @return bool True when the session was preserved.
	 */
	protected static function restore_session( &$state ) {
		$state['session_ok'] = false;
		if ( empty( $state['session']['token'] ) || empty( $state['session']['user_id'] ) ) {
			return false;
		}
		if ( ! function_exists( 'get_userdata' ) || ! function_exists( 'update_user_meta' ) ) {
			return false;
		}
		$user_id = (int) $state['session']['user_id'];
		if ( ! get_userdata( $user_id ) ) {
			// Cross-site restore where this account doesn't exist in the
			// backup - nothing to preserve; the user must log in again.
			return false;
		}
		// The auth cookie also names a username: on cross-site restores it
		// may belong to an account the backup never had. The token row
		// alone can't save such a session - report honestly so the UI
		// says "log in again" instead of pretending all is well.
		if ( function_exists( 'wp_parse_auth_cookie' ) && function_exists( 'get_user_by' ) ) {
			$cookie = wp_parse_auth_cookie( '', 'logged_in' );
			if ( ! empty( $cookie['username'] ) && ! get_user_by( 'login', $cookie['username'] ) ) {
				return false;
			}
		}
		$sessions = get_user_meta( $user_id, 'session_tokens', true );
		if ( ! is_array( $sessions ) ) {
			$sessions = array();
		}
		$sessions[ hash( 'sha256', $state['session']['token'] ) ] = array(
			'expiration' => time() + 14 * DAY_IN_SECONDS,
			'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '127.0.0.1', // phpcs:ignore
			'ua'         => isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '', // phpcs:ignore
			'login'      => time(),
		);
		update_user_meta( $user_id, 'session_tokens', $sessions );
		$state['session_ok'] = true;
		return true;
	}

	/**
	 * Do one bounded chunk of work.
	 *
	 * Browser ticks and server-side loopback ticks share a lock, so only
	 * one worker ever runs: the loser just reports progress. After each
	 * slice the worker chains the next one server-to-server, so the run
	 * completes even when the browser connection is unreliable.
	 *
	 * @return array Progress envelope.
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
			$result['chained'] = UWP_Helpers::chain_self( 'uwp_import_tick', $secret );
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
			throw new Exception( __( 'No import in progress.', 'unboundwp' ) );
		}
		$pinned = UWP_Helpers::job_take_fatal( self::STATE_OPTION );
		if ( null !== $pinned ) {
			// A previous tick died hard (pinned by the shutdown handler).
			// If it died mid-plugin-activation, attribute the crash to that
			// plugin: force-deactivate it, record it, and CONTINUE with the
			// rest instead of dying on every resume (one killer plugin must
			// never veto the whole restore or whitescreen the site).
			if ( self::handle_pinned_reactivation_fatal( $state, $pinned ) ) {
				self::save( $state );
			} else {
				// Log at ERROR level in the job's own full log, then surface
				// the real cause instead of another generic failure.
				if ( class_exists( 'UWP_Logger' ) ) {
					UWP_Logger::job( $state['job_id'], 'ERROR', sprintf( 'previous step crashed: %s', $pinned ) );
				}
				// Surface the real cause instead of another generic failure.
				throw new Exception( sprintf( __( 'The previous step crashed: %s', 'unboundwp' ), $pinned ) );
			}
		}
		UWP_Helpers::debug_log(
			sprintf(
				'import %s: tick step=%s extracted=%d db_offset=%d copied=%d mem=%d',
				$state['job_id'],
				$state['step'],
				isset( $state['extracted'] ) ? (int) $state['extracted'] : 0,
				isset( $state['db_state']['offset'] ) ? (int) $state['db_state']['offset'] : 0,
				isset( $state['copy_index'] ) ? (int) $state['copy_index'] : 0,
				memory_get_usage( true )
			)
		);
		if ( ! is_file( $state['archive'] ) ) {
			throw new Exception( __( 'Archive file disappeared. Please upload it again.', 'unboundwp' ) );
		}

		// --- Step 1: validate ------------------------------------------------
		if ( 'validate' === $state['step'] ) {
			self::validate_tick( $state );
			self::save( $state );
			$state['step'] = 'extract';
			self::save( $state );
			$message = __( 'Backup verified. Extracting files…', 'unboundwp' );
			$opts_note = array();
			if ( ! empty( $state['keep_core'] ) ) {
				$opts_note[] = __( 'keeping destination WordPress core', 'unboundwp' );
			}
			if ( ! empty( $state['skip_plugins'] ) ) {
				$opts_note[] = __( 'leaving plugins inactive', 'unboundwp' );
			}
			if ( ! empty( $opts_note ) ) {
				$message .= ' ' . sprintf( __( '(Options: %s.)', 'unboundwp' ), implode( ', ', $opts_note ) );
			}
			if ( ! empty( $state['warnings'] ) ) {
				$message .= ' ' . __( 'Note:', 'unboundwp' ) . ' ' . $state['warnings'][0];
			}
			return self::progress( $state, $message, 8 );
		}

		// --- Step 2: extract (streamed, resumable by byte offset) ------------
		if ( 'extract' === $state['step'] ) {
			$done = self::extract_tick( $state, $started );
			self::save( $state );
			$pct = $state['total_bytes'] > 0 ? (int) ( 8 + 40 * min( 1, $state['extract_offset'] / max( 1, $state['total_bytes'] + $state['file_count'] * UWP_Archive::HEADER_SIZE ) ) ) : 20;
			if ( $done ) {
				// Locate database.sql inside staging (Ai1WM vs UnboundWP paths differ).
				$state['sql_path'] = self::find_sql( $state['stage_dir'] );
				if ( ! $state['sql_path'] ) {
					throw new Exception( __( 'database.sql was not found in this backup.', 'unboundwp' ) );
				}
			// Snapshot the LIVE site identity for the replace step. This must
			// happen before the database is imported (afterwards these
			// options belong to the backup, not to this site).
			$uploads      = wp_upload_dir();
			$state['live'] = array(
				'site'    => get_option( 'siteurl' ),
				'home'    => get_option( 'home' ),
				'uploads' => isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '',
				'base'    => ABSPATH,
				'content' => WP_CONTENT_DIR,
			);
			$state['step'] = 'database';
				self::save( $state );
				return self::progress( $state, __( 'Files extracted. Importing database…', 'unboundwp' ), 50 );
			}
			return self::progress( $state, sprintf( __( 'Extracting files… (%d/%d)', 'unboundwp' ), $state['extracted'], $state['file_count'] ), $pct );
		}

		// --- Step 3: database -------------------------------------------------
		if ( 'database' === $state['step'] ) {
			// Resolve the dump→live prefix map once: statements are remapped
			// in the data (reference behavior), so the restore lands on the
			// live tables whatever prefix the source used. No wp-config
			// changes, no host-specific failure modes.
			if ( ! isset( $state['db_state']['pfx_init'] ) ) {
				global $wpdb;
				$live_pfx = ( isset( $wpdb ) && isset( $wpdb->prefix ) ) ? (string) $wpdb->prefix : '';
				$dump_pfx = self::package_table_prefix( $state );
				if ( '' === $dump_pfx && isset( $state['sql_path'] ) ) {
					$dump_pfx = self::infer_dump_prefix( $state['sql_path'] );
				}
				$state['db_state']['from_prefix'] = $dump_pfx;
				$state['db_state']['to_prefix']   = $live_pfx;
				$state['db_state']['pfx_init']    = true;
				if ( '' !== $dump_pfx && '' !== $live_pfx && $dump_pfx !== $live_pfx ) {
					UWP_Helpers::debug_log( sprintf( 'import %s: remapping table prefix "%s" -> "%s" in data', $state['job_id'], $dump_pfx, $live_pfx ) );
				}
				self::save( $state );
			}
			$db_finished = false;
			while ( ! UWP_Helpers::out_of_time( $started ) ) {
				$finished = UWP_Database::import_tick( $state['sql_path'], $state['db_state'] );
				// Persist after EVERY call, not just at tick end: each call
				// commits its own transaction, so a connection that dies now
				// loses at most the in-flight call - and MySQL rolls that
				// one back on disconnect. Retries therefore never replay
				// committed statements (no duplicate rows on InnoDB).
				self::save( $state );
				if ( $finished ) {
					$db_finished = true;
					break;
				}
			}
			$sql_size = (int) filesize( $state['sql_path'] );
			$off      = isset( $state['db_state']['offset'] ) ? (int) $state['db_state']['offset'] : 0;
			if ( $db_finished || $off >= $sql_size ) {
				// Boundary heal for jobs that imported under 1.3.6 (dump
				// table names applied verbatim): if dump-named tables exist
				// but live-named ones do not, switch wp-config so the site
				// reads the restored tables. Normal 1.3.9+ runs remap in
				// the data above and never need this.
				self::legacy_prefix_heal( $state );
				// The options + usermeta tables were just replaced - bring
				// our own login session back before anything else runs.
				// Flush caches first so we read the NEW tables, not stale
				// cached copies of the pre-import ones.
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
				$kept = self::restore_session( $state );
				UWP_Helpers::debug_log(
					sprintf(
						'import %s: database done (%d bytes SQL), session %s',
						$state['job_id'],
						$sql_size,
						$kept ? 'preserved' : 'NOT preserved (re-login will be needed)'
					)
				);
				if ( function_exists( 'wp_cache_flush' ) ) {
					wp_cache_flush();
				}
				// Quarantine third-party plugins while the files land: the
				// dump neutralizes active_plugins, but a foreign backup
				// (e.g. Ai1WM-made) may name plugins whose half-copied files
				// would fatal every following request. Keep only ourselves
				// (our ajax handlers must stay registered); the full list
				// is reactivated at Done, when files and DB are consistent.
				update_option( 'active_plugins', array( UWP_PLUGIN_BASENAME ) );
				UWP_Helpers::debug_log(
					sprintf( 'import %s: plugins quarantined', $state['job_id'] )
				);
				// Build the full old→new replacement list now: backup URLs
				// and paths from package.json, live values snapshotted
				// before the import (the live options are already gone).
				$state['pairs'] = self::build_pairs( $state );
				if ( ! is_array( $state['pairs'] ) ) {
					$state['pairs'] = array();
				}
				UWP_Helpers::debug_log(
					sprintf(
						'import %s: built %d replacement pairs',
						$state['job_id'],
						UWP_Helpers::safe_count( $state['pairs'] )
					)
				);
				$state['step'] = 'replace';
				self::save( $state );
				return self::progress( $state, __( 'Database imported. Updating URLs…', 'unboundwp' ), 72 );
			}
			$pct      = (int) ( 50 + 22 * min( 1, $off / max( 1, $sql_size ) ) );
			return self::progress( $state, __( 'Importing database…', 'unboundwp' ), $pct );
		}

		// --- Step 4: URL replacement ------------------------------------------
		// One pass over every table with the full old→new variant list
		// (schemes, www, encodings, uploads, emails, paths) built at the
		// end of the database step.
		if ( 'replace' === $state['step'] ) {
			// Self-heal: a resumed/interrupted state may hold null here
			// (count(null) is a fatal TypeError on PHP 8+).
			if ( ! isset( $state['pairs'] ) || ! is_array( $state['pairs'] ) || empty( $state['pairs'] ) ) {
				$state['step'] = 'files_scan';
				self::save( $state );
				return self::progress( $state, __( 'URLs already match. Copying files…', 'unboundwp' ), 76 );
			}
			if ( ! isset( $state['replace_state'] ) || ! is_array( $state['replace_state'] ) ) {
				$state['replace_state'] = array();
			}
			while ( ! UWP_Helpers::out_of_time( $started ) ) {
				$finished = UWP_Database::replace_tick( $state['pairs'], $state['replace_state'], 200 );
				// Same idempotence rule as the database step: persist after
				// every batch so a dead connection can only repeat the
				// in-flight rows (URL replacement is naturally repeatable).
				self::save( $state );
				if ( $finished ) {
					break;
				}
			}
			$tables = isset( $state['replace_state']['tables'] ) && is_array( $state['replace_state']['tables'] ) ? UWP_Helpers::safe_count( $state['replace_state']['tables'] ) : 1;
			$idx    = isset( $state['replace_state']['table_index'] ) ? (int) $state['replace_state']['table_index'] : 0;
			if ( $idx >= $tables ) {
				$state['step']       = 'files_scan';
				$state['copy_list']  = array();
				$state['copy_index'] = 0;
				self::save( $state );
				UWP_Helpers::debug_log( sprintf( 'import %s: urls updated (%d pairs)', $state['job_id'], UWP_Helpers::safe_count( $state['pairs'] ) ) );
				return self::progress( $state, __( 'URLs updated. Copying files…', 'unboundwp' ), 76 );
			}
			return self::progress( $state, __( 'Updating URLs…', 'unboundwp' ), 73 );
		}

		// --- Step 5: catalogue staged files (resumable directory walk) --------
		// Builds copy_list a few hundred entries per tick so even enormous
		// media libraries can't blow a single request's time budget.
		if ( 'files_scan' === $state['step'] ) {
			if ( ! isset( $state['scan_stack'] ) ) {
				$state['scan_stack'] = array( $state['stage_dir'] );
				$state['copy_list']  = array();
				$state['core_list']  = array();
			}
			while ( ! empty( $state['scan_stack'] ) && ! UWP_Helpers::out_of_time( $started ) ) {
				$dir = array_pop( $state['scan_stack'] );
				if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
					continue;
				}
				// Streamed, never scandir() (monster dirs), with re-queue +
				// assoc dedupe so an interrupted directory resumes safely.
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
					if ( is_link( $full ) ) {
						continue; // never follow symlinks out of staging.
					}
					if ( is_dir( $full ) ) {
						$state['scan_stack'][] = $full;
						continue;
					}
					// Regular files only (see is_regular_file: FIFOs and
					// friends would hang or corrupt the copy).
					if ( ! UWP_Helpers::is_regular_file( $full ) ) {
						continue;
					}
					$rel = self::stage_relative( $state['stage_dir'], $full );
					if ( null === $rel ) {
						continue;
					}
					if ( in_array( basename( $rel ), self::skip_basenames(), true ) ) {
						continue; // control files, not site content.
					}
					if ( self::is_restore_blocked( $rel ) ) {
						continue; // environment files belong to this server.
					}
					if ( self::is_core_rel( $rel ) ) {
						// Core travels separately: staged for the atomic
						// rename-swap at the end, never copied live here
						// unless the operator chose to keep the destination
						// core, in which case staged core is simply left out.
						if ( ! empty( $state['keep_core'] ) ) {
							continue;
						}
						$state['core_list'][ $rel ] = array(
							'from' => $full,
							'rel'  => $rel,
						);
						continue;
					}
					$dest = self::map_to_live( $rel );
					if ( null !== $dest ) {
						$state['copy_list'][ $rel ] = array(
							'from' => $full,
							'to'   => $dest,
						);
					}
					if ( UWP_Helpers::out_of_time( $started ) ) {
						$finished_dir = false;
						break;
					}
				}
				closedir( $handle );
				if ( ! $finished_dir ) {
					$state['scan_stack'][] = $dir;
					break;
				}
			}
			self::save( $state );
			if ( ! empty( $state['scan_stack'] ) ) {
				return self::progress(
					$state,
					sprintf(
						/* translators: %d = files catalogued so far */
						__( 'Cataloguing files… (%d found)', 'unboundwp' ),
						UWP_Helpers::safe_count( isset( $state['copy_list'] ) ? $state['copy_list'] : array() )
					),
					75
				);
			}
			unset( $state['scan_stack'] );
			$state['copy_list']   = array_values( isset( $state['copy_list'] ) && is_array( $state['copy_list'] ) ? $state['copy_list'] : array() );
			$state['core_list']   = array_values( isset( $state['core_list'] ) && is_array( $state['core_list'] ) ? $state['core_list'] : array() );
			$state['copy_index'] = 0;
			$state['core_index'] = 0;
			$state['step']        = 'files';
			self::save( $state );
			UWP_Helpers::debug_log( sprintf( 'import %s: catalogued %d files (%d core) to copy', $state['job_id'], UWP_Helpers::safe_count( $state['copy_list'] ), UWP_Helpers::safe_count( $state['core_list'] ) ) );
			// Disk guard BEFORE any live writes: running out mid-copy is
			// exactly the half-written-tree class. Resumable: free space
			// and Resume - the catalogue above is saved.
			$need = max( 104857600, isset( $state['total_bytes'] ) ? (int) $state['total_bytes'] : 0 );
			$free = UWP_Helpers::disk_free_bytes( ABSPATH );
			if ( false !== $free && $free < $need ) {
				throw new Exception( sprintf( __( 'Not enough disk space to copy files: %s free, need about %s. Free space and Resume. Progress is saved.', 'unboundwp' ), size_format( $free ), size_format( $need ) ) );
			}
			return self::progress( $state, __( 'URLs updated. Copying files…', 'unboundwp' ), 76 );
		}

		// --- Step 6: copy staged files over the live site --------------------
		// Live content goes straight to its destination; core files go to
		// a staging area for the atomic swap in the next step (never live
		// file-by-file - a half-swapped core would fatal every request).
		if ( 'files' === $state['step'] ) {
			if ( ! isset( $state['copy_list'] ) || ! is_array( $state['copy_list'] ) ) {
				$state['copy_list'] = array();
			}
			if ( ! isset( $state['copy_index'] ) ) {
				$state['copy_index'] = 0;
			}
			$total     = UWP_Helpers::safe_count( $state['copy_list'] );
			$core_total = isset( $state['core_list'] ) && is_array( $state['core_list'] ) ? UWP_Helpers::safe_count( $state['core_list'] ) : 0;
			$deadline  = $started + UWP_AJAX_TIME_BUDGET;
			while ( $state['copy_index'] < $total && ! UWP_Helpers::out_of_time( $started ) ) {
				$batch = 0;
				while ( $state['copy_index'] < $total && $batch < 200 && ! UWP_Helpers::out_of_time( $started ) ) {
					$item = $state['copy_list'][ $state['copy_index'] ];
					// A single giant file must not overrun the tick either:
					// copy_one() aborts mid-file past the deadline, deletes
					// the partial copy, and reports it so the next tick
					// retries that file from scratch. Never corrupt, just
					// an extra tick for freak files. Skips (unreadable
					// source / unwritable dest) are RECORDED, not silent:
					// a half-restored theme with no report is how sites
					// come back whitescreened with no explanation.
					$why = null;
					if ( ! self::copy_one( $item['from'], $item['to'], $deadline, $why ) ) {
						break 2;
					}
					if ( null !== $why ) {
						self::record_skipped_file( $state, self::short_path( $item['to'] ), $why );
					}
					$state['copy_index']++;
					$batch++;
				}
			}
			if ( $state['copy_index'] >= $total && ! isset( $state['core_list'] ) ) {
				$state['core_list'] = array();
			}
			if ( $state['copy_index'] >= $total ) {
				if ( ! isset( $state['core_index'] ) ) {
					$state['core_index'] = 0;
				}
				$stage_core = trailingslashit( $state['work_dir'] ) . 'corestage/';
				while ( $state['core_index'] < $core_total && ! UWP_Helpers::out_of_time( $started ) ) {
					$item = $state['core_list'][ $state['core_index'] ];
					$dest = UWP_Helpers::safe_join( $stage_core, $item['rel'] );
					if ( false === $dest ) {
						$state['core_index']++;
						continue;
					}
					$dir = dirname( $dest );
					if ( ! file_exists( $dir ) ) {
						wp_mkdir_p( $dir );
					}
					$why = null;
					if ( ! self::copy_one( $item['from'], $dest, $deadline, $why ) ) {
						break;
					}
					if ( null !== $why ) {
						self::record_skipped_file( $state, 'corestage/' . ( isset( $item['rel'] ) ? $item['rel'] : basename( $dest ) ), $why );
					}
					$state['core_index']++;
				}
			}
			self::save( $state );
			$done_units  = $state['copy_index'] + ( isset( $state['core_index'] ) ? (int) $state['core_index'] : 0 );
			$all_units   = $total + $core_total;
			$pct = 76 + (int) ( 18 * ( $all_units > 0 ? $done_units / $all_units : 1 ) );
			if ( $state['copy_index'] >= $total && ( isset( $state['core_index'] ) ? (int) $state['core_index'] : 0 ) >= $core_total ) {
				$state['step'] = 'core';
				self::save( $state );
				UWP_Helpers::debug_log( sprintf( 'import %s: copied %d files (%d core staged)', $state['job_id'], $total, $core_total ) );
				return self::progress(
					$state,
					! empty( $state['keep_core'] )
						? __( 'Files copied. Core kept as-is…', 'unboundwp' )
						: __( 'Files copied. Swapping WordPress core…', 'unboundwp' ),
					94
				);
			}
			return self::progress( $state, sprintf( __( 'Copying files… (%d/%d)', 'unboundwp' ), $done_units, $all_units ), min( 93, $pct ) );
		}

		// --- Step 7: atomic core swap ------------------------------------------
		// wp-admin + wp-includes switch trees by rename (milliseconds, no
		// mixed-tree window); root files copy individually (tiny). Fully
		// resumable: every state is recoverable, see swap_core_tree().
		// When renames are blocked (locked/hardened FS), identical-version
		// trees are left in place and version changes fall back to a
		// resumable direct copy - the restore completes either way.
		if ( 'core' === $state['step'] ) {
			if ( ! empty( $state['keep_core'] ) ) {
				// Operator chose to keep the destination core: no swap, no
				// staging, no root-file copies - the live tree stays exactly
				// as it was. Still verified below before anything runs on it.
				self::core_note( $state, __( 'WordPress core left untouched by option (keeping the destination version).', 'unboundwp' ) );
				UWP_Helpers::debug_log( sprintf( 'import %s: core replacement skipped by option', $state['job_id'] ) );
				$state['core_done'] = array( 'wp-admin', 'wp-includes', 'root' );
				self::save( $state );
			} else {
				$swapped = self::swap_core_tree( ABSPATH, trailingslashit( $state['work_dir'] ) . 'corestage/', $state, $started );
				self::save( $state );
				if ( $swapped['pending'] ) {
					$msg = isset( $state['core_fb'] ) && is_array( $state['core_fb'] )
						? __( 'Copying WordPress core… (instant swap blocked, direct copy)', 'unboundwp' )
						: __( 'Swapping WordPress core…', 'unboundwp' );
					return self::progress( $state, $msg, 95 );
				}
				if ( '' !== $swapped['summary'] ) {
					UWP_Helpers::debug_log( sprintf( 'import %s: %s', $state['job_id'], $swapped['summary'] ) );
				}
			}
			// Core must be bootable before anything is reactivated: a
			// half-written core (blocked swap + skipped files) would
			// whitescreen every page. Fail LOUDLY here - resumable - rather
			// than completing into a dead site.
			self::verify_live_core( $state );
			self::reactivation_init( $state );
			$state['step'] = 'reactivate';
			self::save( $state );
			return self::progress( $state, __( 'Core ready. Reactivating plugins…', 'unboundwp' ), 96 );
		}

		// --- Step 8: crash-isolated plugin reactivation ------------------------
		// The backup's plugins come back a few per tick with the cursor
		// persisted after every plugin. A plugin that kills its tick is
		// attributed on the next tick (pinned fatal), force-deactivated,
		// recorded as failed, and SKIPPED - one bad plugin can neither
		// stall the restore nor whitescreen the finished site.
		if ( 'reactivate' === $state['step'] ) {
			$more = self::reactivate_tick( $state, $started );
			self::save( $state );
			if ( $more ) {
				$report = isset( $state['reactivation'] ) && is_array( $state['reactivation'] ) ? $state['reactivation'] : array();
				$done_n = UWP_Helpers::safe_count( isset( $report['activated'] ) ? $report['activated'] : array() )
					+ UWP_Helpers::safe_count( isset( $report['skipped'] ) ? $report['skipped'] : array() )
					+ UWP_Helpers::safe_count( isset( $report['failed'] ) ? $report['failed'] : array() );
				$total_n = UWP_Helpers::safe_count( isset( $state['react_list'] ) ? $state['react_list'] : array() );
				return self::progress(
					$state,
					$total_n > 0
						/* translators: 1: done, 2: total */
						? sprintf( __( 'Reactivating plugins… (%1$d/%2$d)', 'unboundwp' ), min( $done_n, $total_n ), $total_n )
						: __( 'Reactivating plugins…', 'unboundwp' ),
					96
				);
			}
			$state['step'] = 'finalize';
			self::save( $state );
		}

		// --- Step 9: finalize --------------------------------------------------
		if ( 'finalize' === $state['step'] ) {
			// Forward-compat: jobs that reached finalize under <=1.3.4 never
			// ran the crash-isolated reactivate step - send them through it
			// first so the backup's plugins are not left quarantined.
			if ( empty( $state['react_done'] ) ) {
				$state['step'] = 'reactivate';
				self::save( $state );
				return self::progress( $state, __( 'Reactivating plugins…', 'unboundwp' ), 96 );
			}
			// Belt and braces: make sure our own login survived the restore.
			self::restore_session( $state );
			self::finalize( $state );

			$message = __( 'Restore complete. Your site is back.', 'unboundwp' );
			if ( ! empty( $state['session'] ) && empty( $state['session_ok'] ) ) {
				$message = __( 'Restore complete. Please log in again with the restored site’s user account.', 'unboundwp' );
			}
			if ( ! empty( $state['reactivation']['skipped'] ) || ! empty( $state['reactivation']['failed'] ) ) {
				$notes = array_merge(
					(array) $state['reactivation']['skipped'],
					(array) $state['reactivation']['failed']
				);
				$message .= ' ' . sprintf(
					/* translators: %s = comma-separated plugin list */
					__( 'Left inactive for safety, review under Plugins: %s.', 'unboundwp' ),
					implode( ', ', array_map( 'basename', $notes ) )
				);
			}
			if ( ! empty( $state['warnings'] ) ) {
				$message .= ' ' . __( 'Note:', 'unboundwp' ) . ' ' . implode( ' ', $state['warnings'] );
			}

			UWP_Helpers::debug_log( sprintf( 'import %s: restore complete', $state['job_id'] ) );
			UWP_Helpers::job_write_done(
				self::STATE_OPTION,
				array(
					'message' => $message,
					'job_id'  => $state['job_id'],
				)
			);
			// Persistent completion notice (the "popup"): survives reloads
			// so the operator sees what happened plus next steps even if
			// they navigated away mid-run. Dismissed explicitly, or replaced
			// by the next restore.
			if ( function_exists( 'update_option' ) ) {
				update_option(
					'uwp_last_restore',
					array(
						'message' => $message,
						'job_id'  => $state['job_id'],
						'time'    => time(),
					)
				);
			}
			UWP_Helpers::trash_dir( $state['work_dir'] );
			UWP_Helpers::empty_trash( 2 );
			UWP_Helpers::job_clear( self::STATE_OPTION );

			return array(
				'done'    => true,
				'step'    => 'done',
				'percent' => 100,
				'message' => $message,
				'nonce'   => wp_create_nonce( 'uwp_nonce' ),
			);
		}

		throw new Exception( __( 'Unknown import step.', 'unboundwp' ) );
	}

	// ------------------------------------------------------------------
	// Steps
	// ------------------------------------------------------------------

	/**
	 * Validate the archive and read its metadata.
	 *
	 * @param array $state Job state (by reference).
	 * @throws Exception When the archive is unusable.
	 */
	protected static function validate_tick( &$state ) {
		$scan = UWP_Archive::scan( $state['archive'] );
		if ( 0 === $scan['files'] ) {
			throw new Exception( __( 'This archive is empty or corrupt.', 'unboundwp' ) );
		}
		$state['file_count']  = $scan['files'];
		$state['total_bytes'] = $scan['bytes'];

		$raw = UWP_Archive::read_small_entry( $state['archive'], 'package.json' );
		$pkg = $raw ? json_decode( $raw, true ) : null;
		$state['package'] = is_array( $pkg ) ? $pkg : array();

		// Surface encryption/compression requirements early with a clear message.
		if ( ! empty( $state['package']['Encrypted'] ) && '' === $state['password'] ) {
			throw new Exception( __( 'This backup is password-protected. Please enter its password and try again.', 'unboundwp' ) );
		}
		// Verify the password against the package signature BEFORE touching
		// any file data: a wrong password fails here in milliseconds instead
		// of extracting hundreds of megabytes first.
		if ( ! empty( $state['package']['Encrypted'] ) && '' !== $state['password'] ) {
			if ( ! UWP_Archive::password_validates( $state['package'], $state['password'] ) ) {
				throw new Exception( __( 'Incorrect backup password. Please check it and try again.', 'unboundwp' ) );
			}
		}
		if ( ! empty( $state['package']['Compression']['Enabled'] ) ) {
			$type = isset( $state['package']['Compression']['Type'] ) ? $state['package']['Compression']['Type'] : 'gzip';
			if ( 'gzip' === $type && ! function_exists( 'gzuncompress' ) && ! function_exists( 'gzinflate' ) ) {
				throw new Exception( __( 'This backup uses gzip compression but PHP zlib is missing.', 'unboundwp' ) );
			}
			if ( 'bzip2' === $type && ! function_exists( 'bzdecompress' ) ) {
				throw new Exception( __( 'This backup uses bzip2 but PHP bz2 support is missing.', 'unboundwp' ) );
			}
		}

		// Version compatibility guard (non-blocking): an old core on a new
		// PHP is the classic restore crasher. Warn loudly, keep going - the
		// operator explicitly chose this backup.
		$state['warnings'] = self::compat_warnings( $state['package'] );

		// Table-prefix handling: statements are remapped dump → live in the
		// data during import (reference behavior - no wp-config changes, no
		// host-specific failure modes), so a mismatch is handled, not fatal.
		// Surfaced first among warnings: it explains the whole restore.
		global $wpdb;
		$live_prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		$dump_prefix = isset( $state['package']['Database']['Prefix'] ) ? (string) $state['package']['Database']['Prefix'] : '';
		if ( '' !== $live_prefix && '' !== $dump_prefix && $live_prefix !== $dump_prefix ) {
			array_unshift(
				$state['warnings'],
				sprintf(
					/* translators: 1: backup prefix, 2: live prefix */
					__( 'Table prefix differs: backup uses "%1$s" but this install uses "%2$s". Table and key names will be remapped to "%2$s" automatically during import, so the restored data takes effect.', 'unboundwp' ),
					$dump_prefix,
					$live_prefix
				)
			);
		}
		foreach ( $state['warnings'] as $warning ) {
			UWP_Helpers::debug_log( sprintf( 'import %s: WARNING: %s', $state['job_id'], $warning ) );
		}
	}

	/**
	 * Check the backup's WordPress/PHP versions against this server.
	 * Pure function of its inputs - easy to test.
	 *
	 * @param array $package Decoded package.json (may be empty).
	 * @return array Warning strings (empty when all clear).
	 */
	public static function compat_warnings( $package ) {
		$warnings = array();
		$wp_ver   = isset( $package['WordPress']['Version'] ) ? (string) $package['WordPress']['Version'] : '';
		if ( '' !== $wp_ver ) {
			$major = (int) $wp_ver;
			if ( $major > 0 && $major < 6 && version_compare( PHP_VERSION, '8.0', '>=' ) ) {
				$warnings[] = sprintf(
					/* translators: 1: backup WP version, 2: server PHP version */
					__( 'Backup runs WordPress %1$s, which predates PHP 8. This server runs PHP %2$s. The restore will proceed, but very old cores can be unstable here.', 'unboundwp' ),
					$wp_ver,
					PHP_VERSION
				);
			}
		}
		return $warnings;
	}

	/**
	 * Extract a bounded number of entries per tick.
	 *
	 * @param array $state Job state (by reference).
	 * @param float $started Tick start.
	 * @return bool True when extraction is complete.
	 */
	protected static function extract_tick( &$state, $started ) {
		$handle = @fopen( $state['archive'], 'rb' ); // phpcs:ignore
		if ( false === $handle ) {
			throw new Exception( __( 'Cannot open archive for extraction.', 'unboundwp' ) );
		}
		if ( $state['extract_offset'] > 0 ) {
			fseek( $handle, (int) $state['extract_offset'] );
		}

		$options = self::decoder_options( $state );
		$finished = false;
		$since_save = 0;

		while ( ! UWP_Helpers::out_of_time( $started ) ) {
			$pos = ftell( $handle );
			if ( false === $pos ) {
				break;
			}
			if ( feof( $handle ) ) {
				$finished = true;
				break;
			}
			$entry = UWP_Archive::read_header( $handle );
			if ( null === $entry ) {
				// EOF block.
				$finished = true;
				$state['extract_offset'] = ftell( $handle );
				break;
			}
			$dest = UWP_Helpers::safe_join( $state['stage_dir'], $entry['name'] );
			if ( false === $dest ) {
				throw new Exception( sprintf( __( 'Archive contains an unsafe path: %s', 'unboundwp' ), $entry['name'] ) );
			}
			// First-wins for ARCHIVE-ROOT rels: a poisoned archive can carry
			// the same root rel twice (field case: a stub index.php AFTER
			// the real front controller, silently overwriting it in staging
			// and bricking the restored homepage with zero evidence).
			// Later copies extract aside (identical stream handling) and are
			// reported loudly instead of landing.
			$rel_key = str_replace( '\\', '/', $entry['name'] );
			if ( false === strpos( $rel_key, '/' ) ) {
				if ( ! isset( $state['root_seen'] ) || ! is_array( $state['root_seen'] ) ) {
					$state['root_seen'] = array();
				}
				if ( isset( $state['root_seen'][ $rel_key ] ) ) {
					$tmp = trailingslashit( $state['work_dir'] ) . 'dup-' . md5( $rel_key ) . '.tmp';
					UWP_Archive::extract_entry( $handle, $entry, $tmp, $options );
					@unlink( $tmp ); // phpcs:ignore - drained, never staged.
					$state['extracted']++;
					$state['extract_offset'] = ftell( $handle );
					self::core_note(
						$state,
						sprintf(
							/* translators: 1: archive path, 2: bytes */
							__( 'Archive contains "%1$s" twice (%2$d-byte second copy). Kept the first, skipped the duplicate.', 'unboundwp' ),
							$rel_key,
							isset( $entry['size'] ) ? (int) $entry['size'] : 0
						)
					);
					UWP_Helpers::debug_log( sprintf( 'import %s: duplicate archive entry "%s" skipped (first kept)', $state['job_id'], $rel_key ) );
					if ( ++$since_save >= 100 ) {
						$since_save = 0;
						self::save( $state );
					}
					continue;
				}
				$state['root_seen'][ $rel_key ] = true;
			}
			$dir = dirname( $dest );
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			UWP_Archive::extract_entry( $handle, $entry, $dest, $options );
			$state['extracted']++;
			$state['extract_offset'] = ftell( $handle );
			// Extraction overwrites the same files, so replaying is safe,
			// still, checkpoint regularly so a dead tick loses little.
			if ( ++$since_save >= 100 ) {
				$since_save = 0;
				self::save( $state );
			}
		}

		fclose( $handle );
		return $finished;
	}

	/**
	 * Decoder options from package.json + user password.
	 *
	 * @param array $state Job state.
	 * @return array
	 */
	protected static function decoder_options( $state ) {
		$compression = null;
		if ( ! empty( $state['package']['Compression']['Enabled'] ) ) {
			$compression = isset( $state['package']['Compression']['Type'] ) ? $state['package']['Compression']['Type'] : 'gzip';
		}
		return array(
			'password'    => '' !== $state['password'] ? $state['password'] : null,
			'compression' => $compression,
		);
	}

	/**
	 * Build old→new replacement pairs: backup identity from package.json,
	 * live identity from the pre-import snapshot.
	 *
	 * @param array $state Job state.
	 * @return array Pairs for UWP_Database::build_replacements().
	 */
	protected static function build_pairs( $state ) {
		$pkg  = ( isset( $state['package'] ) && is_array( $state['package'] ) ) ? $state['package'] : array();
		$live = ( isset( $state['live'] ) && is_array( $state['live'] ) ) ? $state['live'] : array();

		$old_site = isset( $pkg['SiteURL'] ) ? (string) $pkg['SiteURL'] : '';
		$old_home = isset( $pkg['HomeURL'] ) ? (string) $pkg['HomeURL'] : '';
		if ( '' === $old_site ) {
			$old_site = $old_home;
		}

		$old_uploads = '';
		if ( ! empty( $pkg['UploadsURL'] ) ) {
			$old_uploads = (string) $pkg['UploadsURL'];
		} elseif ( ! empty( $pkg['WordPress']['UploadsURL'] ) ) {
			$old_uploads = (string) $pkg['WordPress']['UploadsURL'];
		} elseif ( '' !== $old_home ) {
			$old_uploads = rtrim( $old_home, '/' ) . '/wp-content/uploads';
		}

		$old_base = '';
		if ( ! empty( $pkg['ABSPATH'] ) ) {
			$old_base = (string) $pkg['ABSPATH'];
		} elseif ( ! empty( $pkg['WordPress']['Absolute'] ) ) {
			$old_base = (string) $pkg['WordPress']['Absolute'];
		}
		$old_content = '';
		if ( ! empty( $pkg['ContentDir'] ) ) {
			$old_content = (string) $pkg['ContentDir'];
		} elseif ( ! empty( $pkg['WordPress']['Content'] ) ) {
			$old_content = (string) $pkg['WordPress']['Content'];
		}

		$new_site    = isset( $live['site'] ) ? (string) $live['site'] : get_option( 'siteurl' );
		$new_home    = isset( $live['home'] ) ? (string) $live['home'] : get_option( 'home' );
		$new_uploads = isset( $live['uploads'] ) ? (string) $live['uploads'] : '';
		$new_base    = isset( $live['base'] ) ? (string) $live['base'] : ABSPATH;
		$new_content = isset( $live['content'] ) ? (string) $live['content'] : WP_CONTENT_DIR;

		return UWP_Database::build_replacements(
			$old_site,
			$old_home,
			$new_site,
			$new_home,
			$old_uploads,
			$new_uploads,
			$old_base,
			$new_base,
			$old_content,
			$new_content
		);
	}

	/**
	 * Read $wp_version from a version.php file (best-effort, for logging).
	 *
	 * @param string $file Absolute path.
	 * @return string Version or ''.
	 */
	protected static function read_core_version( $file ) {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return '';
		}
		$contents = file_get_contents( $file ); // phpcs:ignore
		if ( ! is_string( $contents ) || ! preg_match( '/\\$wp_version\s*=\s*\'([^\']+)\'/', $contents, $m ) ) {
			return '';
		}
		return $m[1];
	}

	/**
	 * Table prefix recorded by the backup (our own exports store it in
	 * package.json under Database.Prefix).
	 *
	 * @param array $state Job state.
	 * @return string Prefix or '' when unknown/invalid.
	 */
	protected static function package_table_prefix( $state ) {
		$pkg = ( isset( $state['package'] ) && is_array( $state['package'] ) ) ? $state['package'] : array();
		$p   = isset( $pkg['Database']['Prefix'] ) ? (string) $pkg['Database']['Prefix'] : '';
		return preg_match( '/^[A-Za-z0-9_]+$/', $p ) ? $p : '';
	}

	/**
	 * Core table suffixes used to infer a dump's prefix from its SQL.
	 *
	 * @return array Suffixes, longest-first so e.g. term_relationships wins.
	 */
	protected static function core_table_suffixes() {
		return array(
			'term_relationships',
			'term_taxonomy',
			'commentmeta',
			'postmeta',
			'termmeta',
			'usermeta',
			'options',
			'posts',
			'users',
			'terms',
			'comments',
			'links',
			'sitemeta',
			'site',
			'blogs',
			'blogmeta',
			'signups',
		);
	}

	/**
	 * Infer the dump's table prefix from the first CREATE TABLE in database.sql.
	 *
	 * Pure function of file content - easy to test. Used when the backup
	 * records no prefix (foreign files).
	 *
	 * @param string $sql_path Absolute database.sql path.
	 * @return string Prefix or '' when it cannot be determined.
	 */
	public static function infer_dump_prefix( $sql_path ) {
		if ( ! is_string( $sql_path ) || '' === $sql_path || ! is_file( $sql_path ) || ! is_readable( $sql_path ) ) {
			return '';
		}
		$handle = @fopen( $sql_path, 'rb' ); // phpcs:ignore
		if ( false === $handle ) {
			return '';
		}
		$head = fread( $handle, 131072 ); // phpcs:ignore - first 128 KB holds the schema.
		fclose( $handle );
		if ( ! is_string( $head ) || '' === $head ) {
			return '';
		}
		if ( ! preg_match_all( '/CREATE TABLE\s+`([^`]+)`/i', $head, $m ) || empty( $m[1] ) ) {
			return '';
		}
		foreach ( $m[1] as $table ) {
			foreach ( self::core_table_suffixes() as $suffix ) {
				if ( $suffix === $table ) {
					return '';
				}
				if ( substr( $table, -strlen( $suffix ) ) === $suffix ) {
					$prefix = substr( $table, 0, strlen( $table ) - strlen( $suffix ) );
					if ( preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
						return $prefix;
					}
				}
			}
		}
		return '';
	}

	/**
	 * Rewrite a wp-config.php text's $table_prefix assignment.
	 *
	 * Pure string function - easy to test. Normalizes to single quotes.
	 *
	 * @param string $text wp-config.php contents.
	 * @param string $new_prefix Validated new prefix.
	 * @return string|null New text, or null when the assignment is missing
	 *                     (unconventional config - needs a human).
	 */
	public static function rewrite_wp_config_prefix( $text, $new_prefix ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return null;
		}
		if ( ! is_string( $new_prefix ) || ! preg_match( '/^[A-Za-z0-9_]+$/', $new_prefix ) ) {
			return null;
		}
		if ( ! preg_match( '/^[ \t]*\$table_prefix\s*=\s*([\'"])(.*?)\\1\s*;/m', $text, $m ) ) {
			return null;
		}
		if ( $m[2] === $new_prefix ) {
			return $text; // already correct.
		}
		$new_text = preg_replace(
			'/^([ \t]*\$table_prefix\s*=\s*)([\'"])(.*?)\\2(\s*;)/m',
			'$1\'' . $new_prefix . '\'$4',
			$text,
			1
		);
		return is_string( $new_text ) ? $new_text : null;
	}

	/**
	 * Boundary heal for restores whose database landed under 1.3.6 (dump
	 * table names applied verbatim): when dump-named tables exist but the
	 * live-named ones do not, fall back to the 1.3.6 wp-config switch so
	 * the site reads the restored tables. No-op for every other job
	 * (1.3.9+ remaps prefixes in the data during import).
	 *
	 * @param array $state Job state (by reference; gains warnings).
	 */
	protected static function legacy_prefix_heal( &$state ) {
		global $wpdb;
		$live = ( isset( $wpdb ) && isset( $wpdb->prefix ) ) ? (string) $wpdb->prefix : '';
		$dump = self::package_table_prefix( $state );
		if ( '' === $dump && isset( $state['sql_path'] ) && is_string( $state['sql_path'] ) ) {
			$dump = self::infer_dump_prefix( $state['sql_path'] );
		}
		if ( '' === $dump || '' === $live || $dump === $live || ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return;
		}
		// Dump-named options present while live-named options missing means
		// the statements went in verbatim (pre-remap code) - switch over.
		$have_dump = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $dump . 'options' ) );
		$have_live = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $live . 'options' ) );
		if ( ! empty( $have_dump ) && empty( $have_live ) ) {
			UWP_Helpers::debug_log( sprintf( 'import %s: legacy boundary - dump-named tables found, switching wp-config', $state['job_id'] ) );
			self::maybe_switch_table_prefix( $state );
		}
	}

	/**
	 * Switch this install to the dump's table prefix (runtime + wp-config.php).
	 *
	 * The dump replays its own table names, so without this a prefix
	 * mismatch restores BESIDE the live tables and WordPress keeps
	 * showing stale pre-restore data (wrong content, wrong permalinks).
	 * Runs right after the database lands, before session restore,
	 * quarantine, replace and finalize - every later step then hits the
	 * RESTORED tables. wp-config.php is backed up to wp-config.php.uwp-bak
	 * first. Throws (resumable) when the switch is required but impossible.
	 *
	 * @param array $state Job state (by reference; gains warnings).
	 * @throws Exception When a required switch cannot be completed.
	 */
	protected static function maybe_switch_table_prefix( &$state ) {
		global $wpdb;
		$live = ( isset( $wpdb ) && isset( $wpdb->prefix ) ) ? (string) $wpdb->prefix : '';
		$dump = self::package_table_prefix( $state );
		if ( '' === $dump && isset( $state['sql_path'] ) && is_string( $state['sql_path'] ) ) {
			$dump = self::infer_dump_prefix( $state['sql_path'] );
			if ( '' !== $dump ) {
				UWP_Helpers::debug_log( sprintf( 'import %s: inferred dump table prefix "%s"', $state['job_id'], $dump ) );
			}
		}
		if ( '' === $dump || '' === $live || $dump === $live ) {
			if ( '' !== $dump ) {
				UWP_Helpers::debug_log( sprintf( 'import %s: table prefix "%s" already in effect', $state['job_id'], $live ) );
			} else {
				UWP_Helpers::debug_log( sprintf( 'import %s: WARNING: dump table prefix unknown; restored tables may sit beside live ones', $state['job_id'] ) );
				self::core_note( $state, __( 'Backup records no table prefix; if the source used a custom prefix, check that the restored content is in effect.', 'unboundwp' ) );
			}
			return;
		}
		// Runtime switch so every later step reads the RESTORED tables.
		if ( isset( $wpdb ) && method_exists( $wpdb, 'set_prefix' ) ) {
			$err = $wpdb->set_prefix( $dump );
			if ( is_wp_error( $err ) ) {
				throw new Exception( sprintf( __( 'Cannot switch to the backup table prefix "%s": %s', 'unboundwp' ), $dump, $err->get_error_message() ) );
			}
		} elseif ( isset( $wpdb ) ) {
			$wpdb->prefix = $dump;
		}
		// Persist for all future requests and the site owner.
		$cfg = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/wp-config.php';
		if ( ! is_file( $cfg ) ) {
			throw new Exception( sprintf( __( 'Table prefix differs (backup "%1$s" vs this install "%2$s") but wp-config.php was not found. Set $table_prefix manually to "%1$s".', 'unboundwp' ), $dump, $live ) );
		}
		$raw      = @file_get_contents( $cfg ); // phpcs:ignore
		$new_text = is_string( $raw ) ? self::rewrite_wp_config_prefix( $raw, $dump ) : null;
		if ( null === $new_text ) {
			throw new Exception( sprintf( __( 'Table prefix differs (backup "%1$s" vs this install "%2$s") and wp-config.php sets $table_prefix unconventionally. Set it to "%1$s" manually (wp-config.php was left untouched).', 'unboundwp' ), $dump, $live ) );
		}
		if ( ! is_writable( $cfg ) ) {
			throw new Exception( sprintf( __( 'Table prefix differs (backup "%1$s" vs this install "%2$s") but wp-config.php is not writable. Make it writable and Resume.', 'unboundwp' ), $dump, $live ) );
		}
		$bak = $cfg . '.uwp-bak';
		if ( ! is_file( $bak ) ) {
			@copy( $cfg, $bak ); // phpcs:ignore
		}
		if ( false === @file_put_contents( $cfg, $new_text, LOCK_EX ) ) { // phpcs:ignore
			throw new Exception( sprintf( __( 'Could not write the new table prefix "%1$s" to wp-config.php. Make it writable and Resume.', 'unboundwp' ), $dump ) );
		}
		UWP_Helpers::debug_log( sprintf( 'import %s: table prefix switched "%s" -> "%s" (wp-config.php backed up)', $state['job_id'], $live, $dump ) );
		self::core_note( $state, sprintf( __( 'Table prefix switched from "%1$s" to "%2$s" so the restored data takes effect (wp-config.php.uwp-bak kept).', 'unboundwp' ), $live, $dump ) );
	}

	/**
	 * Atomically swap staged core trees into place (rename-based).
	 *
	 * Why renames: copying thousands of files live leaves a mixed-version
	 * tree that fatals every request bootstrapping inside the window. Two
	 * renames switch versions in milliseconds.
	 *
	 * Crash recovery (every state is re-runnable):
	 * - died after moving live aside: live missing, staging intact → redo.
	 * - died after moving staging live: staging gone → just clean leftovers.
	 * - died during leftover cleanup: leftovers cleaned next pass.
	 * - nothing staged (old backup / Ai1WM file): silent no-op.
	 *
	 * Hostile filesystems (1.3.4): on some servers rename() of a core tree
	 * is persistently blocked (Windows indexer/AV locks, hardened
	 * permissions) - previously a fatal, unresumable dead end ("Cannot
	 * replace wp-admin"). Now: renames are retried, an identical-version
	 * swap whose rename fails is skipped safely (live tree already correct),
	 * and a required swap whose rename fails falls back to a resumable
	 * direct copy. The OS reason is attached to any remaining fatal so the
	 * message names the cause instead of guessing at permissions.
	 *
	 * @param string $abs Absolute ABSPATH (forward slashes tolerated).
	 * @param string $stage_core Absolute staging core dir.
	 * @param array|null $state Job state (by reference; required for the
	 *                          copy fallback and skip markers - without it
	 *                          a blocked rename still throws, as before).
	 * @param float  $started Tick start microtime (0 = no time budget).
	 * @return array array('pending' => bool, 'summary' => string).
	 * @throws Exception When a required rename fails and no fallback is
	 *                   possible - resumable.
	 */
	public static function swap_core_tree( $abs, $stage_core, &$state = null, $started = 0 ) {
		$abs        = rtrim( str_replace( '\\', '/', $abs ), '/' );
		$stage_core = rtrim( str_replace( '\\', '/', $stage_core ), '/' );
		$pending    = false;
		$old_ver    = self::read_core_version( $abs . '/wp-includes/version.php' );
		$new_ver    = self::read_core_version( $stage_core . '/wp-includes/version.php' );
		$same_ver   = '' !== $old_ver && $old_ver === $new_ver;

		if ( is_array( $state ) && ! isset( $state['core_done'] ) ) {
			$state['core_done'] = array();
		}
		$done = ( is_array( $state ) && isset( $state['core_done'] ) && is_array( $state['core_done'] ) )
			? $state['core_done']
			: array();

		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			if ( in_array( $dir, $done, true ) ) {
				continue; // handled on an earlier tick.
			}
			$src  = $stage_core . '/' . $dir;
			$live = $abs . '/' . $dir;
			$bak  = $abs . '/' . $dir . '.uwp-bak';

			if ( ! is_dir( $src ) ) {
				// Nothing staged: swapped earlier, or backup has no core.
				if ( is_dir( $bak ) && ! file_exists( $live ) ) {
					// Impossible via our own flow, but recover anyway:
					// the backup-named tree is the only copy left.
					@rename( $bak, $live ); // phpcs:ignore
				} elseif ( is_dir( $bak ) ) {
					UWP_Helpers::rrmdir( $bak );
					if ( is_dir( $bak ) ) {
						$pending = true;
					}
				} elseif ( file_exists( $bak ) ) {
					@unlink( $bak ); // phpcs:ignore - stray file, not a tree.
				}
				$done[] = $dir;
				continue;
			}

			// A fallback copy already in flight for this dir resumes where
			// it left off; anything else starts fresh below.
			if ( is_array( $state ) && isset( $state['core_fb'] ) && is_array( $state['core_fb'] )
				&& isset( $state['core_fb']['dir'] ) && $state['core_fb']['dir'] !== $dir ) {
				unset( $state['core_fb'] ); // stale entry for another dir.
			}

			$live_exists = file_exists( $live );

			if ( $live_exists ) {
				if ( is_dir( $bak ) ) {
					// Leftover from an older interrupted cleanup - clear it
					// first, otherwise this rename cannot land.
					UWP_Helpers::rrmdir( $bak );
					if ( is_dir( $bak ) ) {
						$pending = true;
						continue;
					}
				} elseif ( file_exists( $bak ) ) {
					// A stray FILE at the backup path (never ours, but seen
					// in the wild) also blocks the rename - remove it.
					@unlink( $bak ); // phpcs:ignore
					if ( file_exists( $bak ) ) {
						throw new Exception(
							sprintf(
								/* translators: 1: directory, 2: OS reason */
								__( 'Cannot replace %1$s (a file blocks the backup path; %2$s).', 'unboundwp' ),
								$dir,
								self::last_os_error()
							)
						);
					}
				}

				if ( self::rename_with_retries( $live, $bak ) ) {
					if ( self::rename_with_retries( $src, $live ) ) {
						if ( is_dir( $bak ) ) {
							UWP_Helpers::rrmdir( $bak );
							if ( is_dir( $bak ) ) {
								$pending = true; // cleanup continues next tick.
							}
						} elseif ( file_exists( $bak ) ) {
							@unlink( $bak ); // phpcs:ignore
						}
						$done[] = $dir;
						continue;
					}
					// Staging would not move live: roll back before anything else.
					$rolled_back = self::rename_with_retries( $bak, $live );
					if ( $rolled_back && $same_ver ) {
						// Live tree restored untouched and it already runs
						// the backup's version - safe to leave in place.
						self::core_note( $state, sprintf( __( 'WordPress core %s unchanged: identical version already live, instant swap unavailable so files were left in place.', 'unboundwp' ), $dir ) );
						$done[] = $dir;
						continue;
					}
					if ( $rolled_back && is_array( $state ) ) {
						// Live is safe again; copy the new tree over it.
						if ( self::core_fallback_tick( $state, $dir, $src, $live, $started ) ) {
							$pending = true;
							continue;
						}
						$done[] = $dir;
						continue;
					}
					throw new Exception(
						sprintf(
							/* translators: 1: directory, 2: OS reason */
							__( 'Cannot install new %1$s (check file permissions and disk space; %2$s).', 'unboundwp' ),
							$dir,
							self::last_os_error()
						)
					);
				}

				// Live tree could not be moved aside (locked/hardened FS).
				if ( $same_ver ) {
					// The live tree already runs this exact version - the
					// swap is a no-op, so a blocked rename must not fail
					// the restore (this is the Windows AV/indexer case).
					// (core_note mirrors into the job log with the version.)
					self::core_note( $state, sprintf( __( 'WordPress core %s unchanged: identical version already live and the folder is locked, so it was left in place.', 'unboundwp' ), $dir ) );
					$done[] = $dir;
					continue;
				}
				if ( is_array( $state ) ) {
					// Version change required: copy over the live tree.
					if ( class_exists( 'UWP_Logger' ) && isset( $state['job_id'] ) ) {
						UWP_Logger::job( $state['job_id'], 'WARNING', sprintf( 'core swap: %s rename blocked (%s) - direct-copy fallback', $dir, self::last_os_error() ) );
					} else {
						UWP_Helpers::debug_log( sprintf( 'core swap: %s rename blocked (%s) - direct-copy fallback', $dir, self::last_os_error() ) );
					}
					if ( self::core_fallback_tick( $state, $dir, $src, $live, $started ) ) {
						$pending = true;
						continue;
					}
					$done[] = $dir;
					continue;
				}
				throw new Exception(
					sprintf(
						/* translators: 1: directory, 2: OS reason */
						__( 'Cannot replace %1$s (check file permissions; %2$s).', 'unboundwp' ),
						$dir,
						self::last_os_error()
					)
				);
			}

			// Live tree missing (interrupted pass moved it aside, or a
			// broken core): never delete $bak here - while $live is gone
			// the backup-named tree may be the only good copy left.
			if ( file_exists( $bak ) && ! is_dir( $bak ) ) {
				@unlink( $bak ); // phpcs:ignore - stray file.
			}
			if ( self::rename_with_retries( $src, $live ) ) {
				if ( is_dir( $bak ) ) {
					UWP_Helpers::rrmdir( $bak );
					if ( is_dir( $bak ) ) {
						$pending = true;
					}
				}
				$done[] = $dir;
				continue;
			}
			if ( is_array( $state ) ) {
				// Move impossible - copy the staged tree into place.
				if ( self::core_fallback_tick( $state, $dir, $src, $live, $started ) ) {
					$pending = true;
					continue;
				}
				if ( is_dir( $bak ) ) {
					UWP_Helpers::rrmdir( $bak );
					if ( is_dir( $bak ) ) {
						$pending = true;
					}
				}
				$done[] = $dir;
				continue;
			}
			throw new Exception(
				sprintf(
					/* translators: 1: directory, 2: OS reason */
					__( 'Cannot install new %1$s (check file permissions and disk space; %2$s).', 'unboundwp' ),
					$dir,
					self::last_os_error()
				)
			);
		}

		// Root files are tiny: copy individually (each copy is near-atomic;
		// the running process already loaded its own copies).
		if ( ! in_array( 'root', $done, true ) ) {
			$root_skipped = self::copy_core_root_files( $stage_core, $abs );
			if ( is_array( $state ) && ! empty( $root_skipped ) ) {
				foreach ( $root_skipped as $rs ) {
					self::record_skipped_file( $state, $rs, 'unwritable-dest' );
				}
				self::core_note( $state, sprintf( __( 'Left %d WordPress root file(s) in place (not writable): %s.', 'unboundwp' ), count( $root_skipped ), implode( ', ', $root_skipped ) ) );
			}
			$done[] = 'root';
		}

		if ( is_array( $state ) ) {
			$state['core_done'] = array_values( array_unique( $done ) );
			unset( $state['core_fb'] );
		}

		$summary = '';
		if ( '' !== $new_ver && $new_ver !== $old_ver ) {
			$summary = sprintf( 'WordPress core %s -> %s', '' !== $old_ver ? $old_ver : '?', $new_ver );
		} elseif ( '' !== $new_ver ) {
			$summary = sprintf( 'WordPress core unchanged (%s)', $new_ver );
		}
		return array(
			'pending' => $pending,
			'summary' => $summary,
		);
	}

	/**
	 * Rename with a few quick retries.
	 *
	 * Windows indexer/AV holds on to new files briefly; the first rename
	 * right after staging often fails while a retry 300 ms later sails
	 * through. A throw is forever - a retry is 300 ms.
	 *
	 * @param string $from Source path.
	 * @param string $to Destination path.
	 * @param int    $tries Attempts.
	 * @return bool True when the rename landed.
	 */
	protected static function rename_with_retries( $from, $to, $tries = 3 ) {
		for ( $i = 0; $i < $tries; $i++ ) {
			if ( @rename( $from, $to ) ) { // phpcs:ignore
				return true;
			}
			if ( $i + 1 < $tries ) {
				usleep( 300000 );
			}
		}
		return false;
	}

	/**
	 * Last PHP/OS error text for attaching to fatal messages.
	 *
	 * @return string e.g. "rename(...): Permission denied" or "unknown error".
	 */
	protected static function last_os_error() {
		$e = error_get_last();
		if ( is_array( $e ) && isset( $e['message'] ) && is_string( $e['message'] ) && '' !== $e['message'] ) {
			$msg = $e['message'];
			// Strip absolute paths (support message, not a path leak).
			$msg = preg_replace( '/[A-Za-z]:[\\\\\\/][^\\s:;\'"]+/', '[path]', $msg );
			$msg = str_replace( '\\', '/', (string) $msg );
			return substr( $msg, 0, 160 );
		}
		return __( 'unknown error', 'unboundwp' );
	}

	/**
	 * Record a human-readable core note for the completion message.
	 *
	 * @param array|null $state Job state (by reference; may be null).
	 * @param string     $note Note text.
	 */
	protected static function core_note( &$state, $note ) {
		if ( ! is_array( $state ) ) {
			return;
		}
		if ( ! isset( $state['warnings'] ) || ! is_array( $state['warnings'] ) ) {
			$state['warnings'] = array();
		}
		$state['warnings'][] = $note;
		// Job-prefixed so the line mirrors into the job's own full log.
		$jid = isset( $state['job_id'] ) ? (string) $state['job_id'] : '';
		UWP_Helpers::debug_log( ( '' !== $jid ? 'import ' . $jid . ': ' : 'core swap: ' ) . $note );
	}

	/**
	 * One budgeted slice of the direct-copy fallback for a core tree.
	 *
	 * Builds the file list a few hundred entries at a time, then copies
	 * with per-file deadline aborts (partial copies are deleted and
	 * retried next tick - never corrupt). Unwritable files are chmodded
	 * once, then honestly recorded as skipped instead of stalling.
	 *
	 * @param array  $state Job state (by reference; persists list + index).
	 * @param string $dir Core dir name ('wp-admin' or 'wp-includes').
	 * @param string $src Staged source dir (normalized, no trailing slash).
	 * @param string $live Live destination dir (normalized, no trailing slash).
	 * @param float  $started Tick start microtime (0 = no time budget).
	 * @return bool True when more work remains (pending).
	 */
	protected static function core_fallback_tick( &$state, $dir, $src, $live, $started ) {
		if ( ! isset( $state['core_fb'] ) || ! is_array( $state['core_fb'] )
			|| ! isset( $state['core_fb']['dir'] ) || $state['core_fb']['dir'] !== $dir ) {
			$state['core_fb'] = array(
				'dir'     => $dir,
				'stack'   => array( $src ),
				'list'    => array(),
				'index'   => 0,
				'skipped' => array(),
			);
		}
		$fb       = &$state['core_fb'];
		$deadline = $started > 0 ? $started + UWP_AJAX_TIME_BUDGET : 0;

		// Phase 1: catalogue (resumable directory walk).
		while ( ! empty( $fb['stack'] ) ) {
			if ( $started > 0 && UWP_Helpers::out_of_time( $started ) ) {
				return true;
			}
			$d = array_pop( $fb['stack'] );
			if ( ! is_dir( $d ) || ! is_readable( $d ) ) {
				continue;
			}
			$handle = @opendir( $d ); // phpcs:ignore
			if ( false === $handle ) {
				continue;
			}
			$finished_dir = true;
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$full = $d . DIRECTORY_SEPARATOR . $entry;
				if ( is_link( $full ) ) {
					continue;
				}
				if ( is_dir( $full ) ) {
					$fb['stack'][] = $full;
					continue;
				}
				if ( ! UWP_Helpers::is_regular_file( $full ) ) {
					continue;
				}
				$rel = self::stage_relative( $src . '/', $full );
				if ( null === $rel ) {
					continue;
				}
				$fb['list'][] = $rel;
				if ( $started > 0 && UWP_Helpers::out_of_time( $started ) ) {
					$finished_dir = false;
					break;
				}
			}
			closedir( $handle );
			if ( ! $finished_dir ) {
				$fb['stack'][] = $d;
				return true;
			}
		}

		// Phase 2: copy over the live tree.
		$total = UWP_Helpers::safe_count( $fb['list'] );
		while ( (int) $fb['index'] < $total ) {
			if ( $started > 0 && UWP_Helpers::out_of_time( $started ) ) {
				return true;
			}
			$rel  = $fb['list'][ (int) $fb['index'] ];
			$from = $src . '/' . $rel;
			$to   = $live . '/' . $rel;
			$parent = dirname( $to );
			if ( ! file_exists( $parent ) ) {
				wp_mkdir_p( $parent );
			}
			if ( file_exists( $to ) && ! is_writable( $to ) ) {
				@chmod( $to, 0644 ); // phpcs:ignore - clears Windows read-only.
			}
			if ( ( file_exists( $to ) && ! is_writable( $to ) ) || ! is_readable( $from ) ) {
				$fb['skipped'][] = $dir . '/' . $rel;
				$fb['index'] = (int) $fb['index'] + 1;
				continue;
			}
			// Atomic: the live file is either old bytes or new bytes.
			// A hard death mid-copy can no longer truncate it.
			if ( ! self::copy_one_atomic( $from, $to, $deadline ) ) {
				return true; // deadline: temp deleted, retry file next tick.
			}
			if ( ! file_exists( $to ) ) {
				$fb['skipped'][] = $dir . '/' . $rel;
			}
			$fb['index'] = (int) $fb['index'] + 1;
		}

		$skipped = isset( $fb['skipped'] ) && is_array( $fb['skipped'] ) ? $fb['skipped'] : array();
		if ( ! empty( $skipped ) ) {
			$shown = array_slice( $skipped, 0, 5 );
			self::core_note(
				$state,
				sprintf(
					/* translators: 1: count, 2: directory, 3: sample files */
					__( 'Direct-copied %1$d %2$s file(s) (%3$d not writable, e.g. %4$s).', 'unboundwp' ),
					$total,
					$dir,
					count( $skipped ),
					implode( ', ', $shown )
				)
			);
		} else {
			UWP_Helpers::debug_log( sprintf( 'core swap: direct-copied %d %s files over live tree', $total, $dir ) );
		}
		unset( $state['core_fb'] );
		return false;
	}

	/**
	 * Copy staged core root files beside ABSPATH (tiny, atomic each).
	 *
	 * @param string $stage_core Absolute staging core dir.
	 * @param string $abs Absolute ABSPATH.
	 * @return array Rel names that could not be written.
	 */
	protected static function copy_core_root_files( $stage_core, $abs ) {
		$skipped = array();
		$entries = @scandir( $stage_core ); // phpcs:ignore
		if ( ! is_array( $entries ) ) {
			return $skipped;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $stage_core . '/' . $entry;
			if ( is_dir( $full ) || ! in_array( $entry, UWP_Helpers::core_root_files(), true ) ) {
				continue;
			}
			$to = $abs . '/' . $entry;
			if ( file_exists( $to ) && ! is_writable( $to ) ) {
				@chmod( $to, 0644 ); // phpcs:ignore
			}
			// Atomic: a hard death mid-copy must never leave a truncated
			// live root file (silent blank homepage, no error anywhere).
			self::copy_one_atomic( $full, $to );
			if ( ! file_exists( $to ) ) {
				$skipped[] = $entry;
			}
		}
		return $skipped;
	}

	/**
	 * Find database.sql anywhere inside staging (top-level or nested).
	 *
	 * @param string $stage Staging dir.
	 * @return string|false Absolute path or false.
	 */
	protected static function find_sql( $stage ) {
		$direct = $stage . 'database.sql';
		if ( is_file( $direct ) ) {
			return $direct;
		}
		if ( is_file( $stage . '/database.sql' ) ) {
			return $stage . '/database.sql';
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $stage, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $it as $file ) {
			if ( 'database.sql' === $file->getFilename() ) {
				return $file->getPathname();
			}
		}
		return false;
	}

	/**
	 * Archive control files that live in staging but are never copied live.
	 *
	 * @return array Basenames.
	 */
	protected static function skip_basenames() {
		return array( 'package.json', 'multisite.json', 'blogs.json', 'filemap.list', 'database.sql' );
	}

	/**
	 * Is this a WordPress core path (staged for the atomic swap, never
	 * copied live file-by-file)?
	 *
	 * @param string $rel Archive-relative path with "/" separators.
	 * @return bool
	 */
	public static function is_core_rel( $rel ) {
		if ( 0 === strpos( $rel, 'wp-admin/' ) || 0 === strpos( $rel, 'wp-includes/' ) ) {
			return true;
		}
		if ( false === strpos( $rel, '/' ) && in_array( $rel, UWP_Helpers::core_root_files(), true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Environment-specific files that must never overwrite the live site:
	 * drop-ins (caching, custom DB drivers) and hosting mu-plugins belong
	 * to the DESTINATION server, not the backup. Mirrors Ai1WM's exclusions.
	 *
	 * @param string $rel Archive-relative path with "/" separators.
	 * @return bool True when the file must be skipped on restore.
	 */
	public static function is_restore_blocked( $rel ) {
		$base = basename( str_replace( '\\', '/', $rel ) );
		// Core drop-ins (honor _get_dropins() when available).
		static $dropins = null;
		if ( null === $dropins ) {
			$dropins = array( 'advanced-cache.php', 'db.php', 'db-error.php', 'object-cache.php', 'sunrise.php', 'memcached.php', 'redis-cache.php' );
			if ( function_exists( '_get_dropins' ) ) {
				$dropins = array_unique( array_merge( $dropins, array_keys( _get_dropins() ) ) );
			}
		}
		if ( in_array( $base, $dropins, true ) ) {
			return true;
		}
		// Well-known hosting mu-plugins (Endurance, GoDaddy, WP Engine…).
		$host_plugins = array(
			'endurance-page-cache.php',
			'endurance-php-edge.php',
			'endurance-browser-cache.php',
			'gd-system-plugin.php',
			'wpengine-common.php',
			'wpe-wp-sign-on-plugin.php',
			'wp-cerber.php',
		);
		if ( 0 === strpos( $rel, 'mu-plugins/' ) && in_array( $base, $host_plugins, true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Staging-absolute path to archive-relative path ("/" separators).
	 *
	 * @param string $stage Staging dir.
	 * @param string $abs Absolute path inside staging.
	 * @return string|null Relative path or null when outside staging.
	 */
	protected static function stage_relative( $stage, $abs ) {
		$root = rtrim( str_replace( '\\', '/', $stage ), '/' ) . '/';
		$path = str_replace( '\\', '/', $abs );
		if ( 0 !== strpos( $path, $root ) ) {
			return null;
		}
		return ltrim( substr( $path, strlen( $root ) ), '/' );
	}

	/**
	 * Map an archive-relative path to its live destination.
	 *
	 * Handles both UnboundWP archives (ABSPATH-relative, e.g.
	 * "wp-content/uploads/x.jpg") and Ai1WM variants (bare "uploads/...",
	 * "plugins/...", "themes/...", "blogs.dir/...").
	 *
	 * @param string $rel Archive-relative path with "/" separators.
	 * @return string|null Absolute destination or null to skip.
	 */
	public static function map_to_live( $rel ) {
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );

		// Never allow upward traversal, even though staged paths come from
		// our own safe extraction - defense in depth.
		if ( false !== strpos( $rel, '..' ) ) {
			return null;
		}

		// Dangerous leftovers - never restore over these (matched anywhere
		// in the path, since archives may or may not prefix wp-content/).
		if ( false !== strpos( $rel, 'unboundwp-temp' ) || false !== strpos( $rel, 'unboundwp-backups' ) ) {
			return null;
		}
		// Never overwrite our own running code mid-restore: swapping the
		// plugin's files under itself (plus opcache staleness) is asking
		// for half-loaded classes. Reinstall the plugin afterwards if the
		// backup contains a different copy. (Both archive layouts covered.)
		if ( 0 === strpos( $rel, 'wp-content/plugins/unboundwp/' ) || 'wp-content/plugins/unboundwp' === $rel
			|| 0 === strpos( $rel, 'plugins/unboundwp/' ) || 'plugins/unboundwp' === $rel ) {
			return null;
		}

		// Normal case: path already starts at a known ABSPATH root.
		$candidates = array( 'wp-content/', 'wp-includes/', 'wp-admin/' );
		foreach ( $candidates as $root ) {
			if ( 0 === strpos( $rel, $root ) ) {
				return wp_normalize_path( ABSPATH . $rel );
			}
		}
		// Ai1WM-style bare roots.
		$bare = array(
			'uploads/'  => 'wp-content/uploads/',
			'plugins/'  => 'wp-content/plugins/',
			'themes/'   => 'wp-content/themes/',
			'mu-plugins/' => 'wp-content/mu-plugins/',
			'languages/'  => 'wp-content/languages/',
			'blogs.dir/'  => 'wp-content/blogs.dir/',
		);
		foreach ( $bare as $from => $to ) {
			if ( 0 === strpos( $rel, $from ) ) {
				return wp_normalize_path( ABSPATH . $to . substr( $rel, strlen( $from ) ) );
			}
		}
		// wp-config.php: restore beside ABSPATH but never clobber the live
		// wp-config (write as wp-config.restored.php instead).
		$base = basename( $rel );
		if ( in_array( $base, array( 'wp-config.php' ), true ) ) {
			return wp_normalize_path( ABSPATH . $base . '.restored.php' );
		}
		// WordPress core root files land beside ABSPATH (wp-config.php can
		// never reach here: it is not in the allowlist).
		if ( false === strpos( $rel, '/' ) && in_array( $rel, UWP_Helpers::core_root_files(), true ) ) {
			return wp_normalize_path( ABSPATH . $rel );
		}
		// Anything else at archive root that we don't recognise: skip.
		if ( false === strpos( $rel, '/' ) ) {
			return null;
		}
		// Fallback: content we don't explicitly know. Archives store files
		// relative to wp-content (Ai1WM layout), so unknown subfolders
		// (upgrade/, cache/, custom dirs…) belong under WP_CONTENT_DIR,
		// never beside ABSPATH where they could shadow core.
		return wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . $rel );
	}

	/**
	 * Copy one file, creating directories and preserving mtime when possible.
	 *
	 * @param string $from Source.
	 * @param string $to Destination.
	 * @param float  $deadline Optional microtime deadline: aborting past it
	 *                         deletes the partial copy and returns false so
	 *                         the next tick retries the file from scratch.
	 * @param string|null $why Optional out-param: set to 'unreadable-source',
	 *                          'unwritable-dest' or 'deadline' when the file
	 *                          was skipped/aborted (null on clean copy).
	 * @return bool True when the file was fully copied (or intentionally skipped).
	 */
	protected static function copy_one( $from, $to, $deadline = 0, &$why = null ) {
		$why = null; // definitive on every return: callers may reuse the variable.
		// Never follow symlinks out of staging.
		if ( is_link( $from ) ) {
			return true; // nothing to do - count it as handled.
		}
		$dir = dirname( $to );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Stream copy (handles multi-GB videos without memory spikes).
		$in = @fopen( $from, 'rb' ); // phpcs:ignore
		if ( false === $in ) {
			$why = 'unreadable-source';
			return true; // skip, don't stall the restore (caller records it).
		}
		$out = @fopen( $to, 'wb' ); // phpcs:ignore
		if ( false === $out ) {
			fclose( $in );
			$why = 'unwritable-dest';
			return true; // skip, don't stall the restore (caller records it).
		}
		while ( ! feof( $in ) ) {
			$data = fread( $in, UWP_CHUNK_BYTES ); // phpcs:ignore
			if ( false === $data || '' === $data ) {
				break;
			}
			fwrite( $out, $data ); // phpcs:ignore
			if ( $deadline > 0 && microtime( true ) > $deadline ) {
				fclose( $in );
				fclose( $out );
				@unlink( $to ); // phpcs:ignore - partial copy, retry whole file next tick.
				$why = 'deadline';
				return false;
			}
		}
		fclose( $in );
		fclose( $out );
		@touch( $to, filemtime( $from ) ); // phpcs:ignore
		return true;
	}

	/**
	 * Copy one SMALL critical file atomically (temp + rename).
	 *
	 * copy_one() streams straight into the destination: a hard death
	 * mid-copy (timeout kill, OOM) leaves a truncated live file - for a
	 * root file like index.php that is a silent blank homepage with HTTP
	 * 200 and no error anywhere (field case). Temp+rename makes the live
	 * path switch instant: it is either the old bytes or the new bytes,
	 * never a prefix. Partial temp files are deleted on abort.
	 *
	 * @param string $from Source.
	 * @param string $to Destination.
	 * @param float  $deadline Optional microtime deadline (0 = none).
	 * @param string|null $why Optional out-param (same vocabulary as copy_one).
	 * @return bool True when the destination now holds the full copy.
	 */
	protected static function copy_one_atomic( $from, $to, $deadline = 0, &$why = null ) {
		$why = null;
		if ( is_link( $from ) ) {
			return true;
		}
		$dir = dirname( $to );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_file( $from ) || ! is_readable( $from ) ) {
			$why = 'unreadable-source';
			return true;
		}
		try {
			$suffix = bin2hex( random_bytes( 4 ) );
		} catch ( Exception $e ) {
			$suffix = uniqid();
		}
		$tmp = $to . '.uwp-tmp-' . $suffix;
		$ok  = self::copy_one( $from, $tmp, $deadline, $why );
		if ( ! $ok ) {
			@unlink( $tmp ); // phpcs:ignore - deadline abort, nothing touched live.
			return false;
		}
		if ( null !== $why ) {
			@unlink( $tmp ); // phpcs:ignore - skipped, keep live file as-is.
			return true;
		}
		if ( ! is_file( $tmp ) ) {
			$why = 'unwritable-dest';
			return true;
		}
		if ( file_exists( $to ) && ! is_writable( $to ) ) {
			@chmod( $to, 0644 ); // phpcs:ignore - clears Windows read-only.
		}
		if ( ! @rename( $tmp, $to ) ) { // phpcs:ignore
			// Rename-over-open fails on Windows when the destination is
			// held open (locks, AV): fall back to a direct overwrite so
			// the bytes still land. Atomicity is best-effort here; a
			// recorded landing beats a pristine skip for core files.
			$why2  = null;
			$retry = self::copy_one( $tmp, $to, $deadline, $why2 );
			@unlink( $tmp ); // phpcs:ignore - temp served its purpose either way.
			if ( ! $retry ) {
				$why = 'deadline';
				return false; // retry whole file next tick, index unadvanced.
			}
			if ( null === $why2 && is_file( $to ) ) {
				$why = null;
				@touch( $to, filemtime( $from ) ); // phpcs:ignore
				return true;
			}
			$why = 'unwritable-dest';
			return true;
		}
		@touch( $to, filemtime( $from ) ); // phpcs:ignore
		return true;
	}

	/**
	 * Record a file that could not be written during the restore.
	 *
	 * Skips used to be silent - a half-restored theme with no report is
	 * how sites come back whitescreened with no explanation. Counts are
	 * unbounded; stored samples are capped.
	 *
	 * @param array  $state Job state (by reference).
	 * @param string $path Short readable path (see short_path()).
	 * @param string $why 'unreadable-source' or 'unwritable-dest'.
	 */
	protected static function record_skipped_file( &$state, $path, $why ) {
		if ( ! isset( $state['skipped_file_count'] ) ) {
			$state['skipped_file_count'] = 0;
		}
		$state['skipped_file_count'] = (int) $state['skipped_file_count'] + 1;
		if ( ! isset( $state['skipped_files'] ) || ! is_array( $state['skipped_files'] ) ) {
			$state['skipped_files'] = array();
		}
		if ( UWP_Helpers::safe_count( $state['skipped_files'] ) < 20 ) {
			$state['skipped_files'][] = $why . ': ' . $path;
		}
		UWP_Helpers::debug_log( sprintf( 'import %s: skipped file (%s) %s', $state['job_id'], $why, $path ) );
	}

	/**
	 * Turn recorded file skips into one completion-message warning.
	 *
	 * @param array $state Job state (by reference).
	 */
	protected static function summarize_skipped_files( &$state ) {
		$n = isset( $state['skipped_file_count'] ) ? (int) $state['skipped_file_count'] : 0;
		if ( $n <= 0 ) {
			return;
		}
		$samples = ( isset( $state['skipped_files'] ) && is_array( $state['skipped_files'] ) )
			? array_slice( $state['skipped_files'], 0, 5 )
			: array();
		self::core_note(
			$state,
			sprintf(
				/* translators: 1: count, 2: sample list */
				__( '%1$d file(s) could not be written during restore (e.g. %2$s). Check permissions and missing theme/plugin files.', 'unboundwp' ),
				$n,
				! empty( $samples ) ? implode( ', ', $samples ) : __( 'see log', 'unboundwp' )
			)
		);
	}

	/**
	 * Shorten an absolute path for human-readable reports.
	 *
	 * @param string $abs Absolute path.
	 * @return string Shortened path.
	 */
	public static function short_path( $abs ) {
		$p = str_replace( '\\', '/', (string) $abs );
		foreach ( array(
			array( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', '[content]/' ),
			array( defined( 'ABSPATH' ) ? ABSPATH : '', '[site]/' ),
		) as $pair ) {
			$root = rtrim( str_replace( '\\', '/', (string) $pair[0] ), '/' );
			if ( '' !== $root && 0 === strpos( $p, $root . '/' ) ) {
				return $pair[1] . substr( $p, strlen( $root ) + 1 );
			}
		}
		return basename( $p );
	}

	/**
	 * Flat recursive file list (relative paths), capped for safety.
	 *
	 * @param string $dir Absolute directory.
	 * @param int    $cap Maximum entries.
	 * @return array rel path => true.
	 */
	protected static function list_files_flat( $dir, $cap = 5000 ) {
		$out   = array();
		$root  = rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
		$stack = array( $root );
		while ( ! empty( $stack ) && UWP_Helpers::safe_count( $out ) < $cap ) {
			$d = array_pop( $stack );
			$h = @opendir( $d ); // phpcs:ignore
			if ( false === $h ) {
				continue;
			}
			while ( false !== ( $entry = readdir( $h ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$full = $d . '/' . $entry;
				if ( is_link( $full ) ) {
					continue;
				}
				if ( is_dir( $full ) ) {
					$stack[] = $full;
					continue;
				}
				if ( ! UWP_Helpers::is_regular_file( $full ) ) {
					continue;
				}
				$out[ substr( $full, strlen( $root ) + 1 ) ] = true;
				if ( UWP_Helpers::safe_count( $out ) >= $cap ) {
					break;
				}
			}
			closedir( $h );
		}
		return $out;
	}

	/**
	 * Verify the active theme(s) landed completely: staged vs live file lists.
	 *
	 * A half-copied theme (unwritable files, truncated archive) boots the
	 * admin fine but whitescreens the frontend - the exact signature seen
	 * in the field. This names the missing files instead of guessing.
	 *
	 * @param array $state Job state (by reference; gains warnings).
	 */
	protected static function verify_theme_files( &$state ) {
		if ( ! function_exists( 'get_option' ) || ! defined( 'WP_CONTENT_DIR' ) ) {
			return;
		}
		$stage = ( isset( $state['stage_dir'] ) && is_string( $state['stage_dir'] ) )
			? rtrim( str_replace( '\\', '/', $state['stage_dir'] ), '/' )
			: '';
		if ( '' === $stage || ! is_dir( $stage ) ) {
			return;
		}
		$sheets = array_unique(
			array_filter(
				array(
					(string) get_option( 'template', '' ),
					(string) get_option( 'stylesheet', '' ),
				)
			)
		);
		if ( empty( $sheets ) ) {
			return;
		}
		foreach ( $sheets as $sheet ) {
			$sheet = ltrim( str_replace( '\\', '/', $sheet ), '/' );
			if ( '' === $sheet || false !== strpos( $sheet, '..' ) ) {
				continue;
			}
			// Staged layout differs by backup origin (ours vs Ai1WM bare).
			$staged_candidates = array(
				$stage . '/wp-content/themes/' . $sheet,
				$stage . '/themes/' . $sheet,
			);
			$staged = null;
			foreach ( $staged_candidates as $cand ) {
				if ( is_dir( $cand ) ) {
					$staged = $cand;
					break;
				}
			}
			if ( null === $staged ) {
				continue; // theme did not come from this backup - nothing to compare.
			}
			$live = rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/themes/' . $sheet;
			if ( ! is_dir( $live ) ) {
				self::core_note( $state, sprintf( __( 'Theme "%s" is missing on the restored site. Reinstall it.', 'unboundwp' ), $sheet ) );
				continue;
			}
			$want = self::list_files_flat( $staged );
			$have = self::list_files_flat( $live );
			if ( empty( $want ) ) {
				continue;
			}
			$missing = array_diff_key( $want, $have );
			if ( ! empty( $missing ) || UWP_Helpers::safe_count( $have ) < UWP_Helpers::safe_count( $want ) ) {
				$sample = array_slice( array_keys( $missing ), 0, 5 );
				self::core_note(
					$state,
					sprintf(
						/* translators: 1: theme, 2: have count, 3: want count, 4: sample files */
						__( 'Theme "%1$s" may be incomplete: %2$d of %3$d files present%4$s. Recopy or reinstall the theme.', 'unboundwp' ),
						$sheet,
						UWP_Helpers::safe_count( $have ),
						UWP_Helpers::safe_count( $want ),
						! empty( $sample ) ? sprintf( __( ' (missing e.g. %s)', 'unboundwp' ), implode( ', ', $sample ) ) : ''
					)
				);
			} else {
				UWP_Helpers::debug_log( sprintf( 'import %s: theme %s complete (%d files)', $state['job_id'], $sheet, UWP_Helpers::safe_count( $have ) ) );
			}
		}
	}

	/**
	 * Final cleanup after a successful restore.
	 *
	 * Files and database are consistent again here. The theme is restored
	 * (with a default-theme fallback so the site can never be left
	 * themeless); plugins were already reactivated crash-isolated in the
	 * reactivate step.
	 *
	 * @param array $state Job state.
	 */
	protected static function finalize( &$state ) {
		self::restore_theme( $state );
		// Routing self-heal BEFORE verification: a tagless live structure
		// (mid-export source edit, or third-party mangling) is restored
		// from the manifest's sane value, exactly like pressing Save on
		// Settings → Permalinks. Verification then sees the healed value.
		self::apply_manifest_permalink( $state );
		// Prove the 1:1 before declaring victory: routing, theme, identity
		// and row counts are re-checked against the backup's manifest, and
		// the homepage is fetched once. Diffs become warnings (shown in the
		// completion message), never silent.
		self::verify_fidelity( $state );
		self::smoke_test_homepage( $state );
		self::verify_theme_files( $state );
		self::summarize_skipped_files( $state );
		// Builder caches reference old markup - drop the regenerable ones.
		if ( function_exists( 'delete_post_meta_by_key' ) ) {
			delete_post_meta_by_key( '_elementor_css' );
			delete_post_meta_by_key( '_elementor_element_cache' );
			delete_post_meta_by_key( '_elementor_page_assets' );
		}
		if ( function_exists( 'delete_option' ) ) {
			delete_option( '_elementor_global_css' );
			delete_option( '_elementor_assets_data' );
			delete_option( 'elementor-custom-breakpoints-files' );
		}
		// Refresh rewrite rules + caches so the restored site works immediately.
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		// Make sure the new URLs stuck even if replace step was skipped.
		update_option( 'siteurl', get_option( 'siteurl' ) );
		update_option( 'home', get_option( 'home' ) );
	}

	/**
	 * Plugins that must stay off when the site is not served over HTTPS
	 * (or that hide the login screen): activating them could lock the
	 * operator out of the just-restored site. Slugs are matched loosely
	 * against the plugin basename. Mirrors Ai1WM's safety lists.
	 *
	 * @return array Slug fragments.
	 */
	protected static function risky_plugin_slugs() {
		return array(
			'really-simple-ssl',
			'wordpress-https',
			'wp-force-ssl',
			'force-https',
			'wps-hide-login',
			'rename-wp-login',
			'lockdown-wp-admin',
			'hide-my-wp',
			'hide-my-wordpress',
			'wp-simple-firewall',
		);
	}

	/**
	 * Whether a plugin basename matches the risky list (SSL-forcers and
	 * login-hiders that could lock out the operator on plain HTTP).
	 *
	 * @param string $plugin Plugin basename, e.g. foo/bar.php.
	 * @return bool
	 */
	public static function is_risky_plugin( $plugin ) {
		foreach ( self::risky_plugin_slugs() as $slug ) {
			if ( false !== stripos( (string) $plugin, $slug ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Restore the theme at Done, when files and database are consistent.
	 *
	 * Never leaves the site themeless: if the backup's theme is missing,
	 * fall back to a default installed theme (an empty or dangling
	 * stylesheet is a known blank-screen vector).
	 *
	 * @param array $state Job state (by reference; gains theme report).
	 */
	protected static function restore_theme( &$state ) {
		if ( ! isset( $state['reactivation'] ) || ! is_array( $state['reactivation'] ) ) {
			$state['reactivation'] = array(
				'theme'     => '',
				'activated' => array(),
				'skipped'   => array(),
				'failed'    => array(),
			);
		}
		$pkg = ( isset( $state['package'] ) && is_array( $state['package'] ) ) ? $state['package'] : array();

		$applied = '';
		if ( ! empty( $pkg['ActiveTheme']['Stylesheet'] ) ) {
			$stylesheet = (string) $pkg['ActiveTheme']['Stylesheet'];
			$template   = ! empty( $pkg['ActiveTheme']['Template'] ) ? (string) $pkg['ActiveTheme']['Template'] : $stylesheet;
			if ( function_exists( 'wp_get_theme' ) ) {
				$theme = wp_get_theme( $stylesheet );
				if ( $theme && $theme->exists() ) {
					update_option( 'template', $template );
					update_option( 'stylesheet', $stylesheet );
					$applied = $stylesheet;
				} else {
					UWP_Helpers::debug_log( sprintf( 'import %s: theme %s missing from backup, will fall back', $state['job_id'], $stylesheet ) );
				}
			}
		}
		if ( '' === $applied ) {
			$fb = self::default_fallback_theme();
			if ( null !== $fb ) {
				update_option( 'template', $fb[0] );
				update_option( 'stylesheet', $fb[1] );
				$applied = $fb[1];
				self::core_note( $state, sprintf( __( 'Active theme was missing, fell back to %s.', 'unboundwp' ), $fb[1] ) );
			}
		}
		$state['reactivation']['theme'] = $applied;
	}

	/**
	 * Pick a guaranteed-installed theme: current-if-valid, else the
	 * bundled default, else the first installed theme.
	 *
	 * @return array|null array(template, stylesheet) or null when none exists.
	 */
	protected static function default_fallback_theme() {
		if ( ! function_exists( 'wp_get_theme' ) || ! function_exists( 'wp_get_themes' ) || ! function_exists( 'get_option' ) ) {
			return null;
		}
		$current = (string) get_option( 'stylesheet', '' );
		if ( '' !== $current ) {
			$t = wp_get_theme( $current );
			if ( $t && $t->exists() ) {
				$tpl = (string) get_option( 'template', '' );
				return array( '' !== $tpl ? $tpl : $current, $current );
			}
		}
		if ( defined( 'WP_DEFAULT_THEME' ) ) {
			$slug = (string) WP_DEFAULT_THEME;
			if ( '' !== $slug ) {
				$t = wp_get_theme( $slug );
				if ( $t && $t->exists() ) {
					$tpl = method_exists( $t, 'get_template' ) ? (string) $t->get_template() : $slug;
					return array( '' !== $tpl ? $tpl : $slug, $slug );
				}
			}
		}
		$all = wp_get_themes();
		if ( is_array( $all ) ) {
			foreach ( $all as $slug => $theme ) {
				if ( $theme && $theme->exists() ) {
					$tpl = method_exists( $theme, 'get_template' ) ? (string) $theme->get_template() : (string) $slug;
					return array( '' !== $tpl ? $tpl : (string) $slug, (string) $slug );
				}
			}
		}
		return null;
	}

	/**
	 * Restore the permalink structure from the backup manifest when the
	 * live value is tagless garbage.
	 *
	 * The manifest records get_option() (what the source showed and used);
	 * the dump holds raw rows. When they disagree because the source was
	 * edited mid-export - or a third party mangled the row - the manifest
	 * is the source of truth. Applied ONLY when the manifest value is a
	 * sane tagged structure and the live one is not: this is byte-for-byte
	 * what pressing Save on Settings → Permalinks does (update_option +
	 * flush), so it cannot crash the site. Anything else is reported, not
	 * overridden - 1:1 is preserved for legitimate custom structures.
	 *
	 * @param array $state Job state (by reference; gains warnings).
	 */
	protected static function apply_manifest_permalink( &$state ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$pkg = ( isset( $state['package'] ) && is_array( $state['package'] ) ) ? $state['package'] : array();
		$exp = ( isset( $pkg['Fidelity'] ) && is_array( $pkg['Fidelity'] ) && isset( $pkg['Fidelity']['permalink_structure'] ) )
			? (string) $pkg['Fidelity']['permalink_structure']
			: '';
		$live = (string) get_option( 'permalink_structure', '' );
		if ( '' === $exp || $exp === $live ) {
			return; // nothing known, or already in effect.
		}
		if ( ! preg_match( '/%[a-z_]+%/', $exp ) ) {
			return; // manifest itself tagless: never apply garbage.
		}
		if ( preg_match( '/%[a-z_]+%/', $live ) ) {
			return; // live sane but different (operator/third party): report, don't override.
		}
		update_option( 'permalink_structure', $exp );
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
		}
		self::core_note(
			$state,
			sprintf(
				/* translators: %s = permalink structure */
				__( 'Permalink structure restored to the backup value ("%s") and rewrite rules flushed. Pretty URLs work again.', 'unboundwp' ),
				$exp
			)
		);
	}

	/**
	 * Compare the restored site against the backup's fidelity manifest.
	 *
	 * Anything that defines "same site" yet drifted (routing, theme,
	 * identity, row counts) is reported as a warning - which the
	 * completion message surfaces - instead of completing silently into a
	 * site that merely LOOKS restored. Never throws: a diff is evidence,
	 * not a failure.
	 *
	 * @param array $state Job state (by reference; gains warnings).
	 */
	protected static function verify_fidelity( &$state ) {
		$pkg = ( isset( $state['package'] ) && is_array( $state['package'] ) ) ? $state['package'] : array();
		$exp = ( isset( $pkg['Fidelity'] ) && is_array( $pkg['Fidelity'] ) ) ? $pkg['Fidelity'] : null;
		if ( null === $exp ) {
			UWP_Helpers::debug_log( sprintf( 'import %s: no fidelity manifest in backup, skipping 1:1 check', $state['job_id'] ) );
			return;
		}
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		$diffs = array();
		$check = function ( $label, $expected, $actual ) use ( &$diffs ) {
			if ( (string) $expected !== (string) $actual ) {
				$diffs[] = sprintf( '%s: backup had "%s" but restored site has "%s"', $label, $expected, $actual );
			}
		};
		// Routing must be byte-identical (we never rewrite it).
		$check( 'permalink_structure', isset( $exp['permalink_structure'] ) ? $exp['permalink_structure'] : '', get_option( 'permalink_structure', '' ) );
		// A structure with no valid %tags% breaks every pretty URL. The
		// importer reproduces source data verbatim (1:1), so a tagless
		// value means the SOURCE holds it (or a third party wrote it post
		// restore) - either way the human fix is the same Settings page.
		$live_perm = (string) get_option( 'permalink_structure', '' );
		$exp_perm  = isset( $exp['permalink_structure'] ) ? (string) $exp['permalink_structure'] : '';
		if ( '' !== $live_perm && ! preg_match( '/%[a-z_]+%/', $live_perm ) ) {
			$diffs[] = sprintf( 'restored permalink_structure "%s" has no valid tags - post/page URLs will not resolve until Post name (or another tagged structure) is saved under Settings → Permalinks', $live_perm );
		}
		if ( '' !== $exp_perm && $exp_perm !== $live_perm && ! preg_match( '/%[a-z_]+%/', $exp_perm ) ) {
			$diffs[] = sprintf( 'backup manifest permalink_structure "%s" has no valid tags - fix it on the source (Post name → Save) and re-export for clean routing', $exp_perm );
		}
		// Theme must be what we applied (package theme or recorded fallback).
		$applied_theme = ( isset( $state['reactivation'] ) && is_array( $state['reactivation'] ) && isset( $state['reactivation']['theme'] ) )
			? (string) $state['reactivation']['theme']
			: '';
		$live_stylesheet = (string) get_option( 'stylesheet', '' );
		if ( '' !== $applied_theme && $applied_theme !== $live_stylesheet ) {
			$diffs[] = sprintf( 'stylesheet: applied "%s" but live value is "%s"', $applied_theme, $live_stylesheet );
		}
		// Identity must equal the pre-import live snapshot (new domain).
		$live = ( isset( $state['live'] ) && is_array( $state['live'] ) ) ? $state['live'] : array();
		if ( isset( $live['site'] ) ) {
			$check( 'siteurl', $live['site'], get_option( 'siteurl' ) );
		}
		if ( isset( $live['home'] ) ) {
			$check( 'home', $live['home'], get_option( 'home' ) );
		}
		// Row counts must match exactly (we import every row; transients
		// live in options, not in these tables).
		global $wpdb;
		if ( isset( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
			foreach ( array( 'users' => 'users', 'posts' => 'posts' ) as $key => $prop ) {
				if ( ! isset( $exp[ $key ] ) || (int) $exp[ $key ] < 0 || ! isset( $wpdb->$prop ) ) {
					continue;
				}
				$table = $wpdb->$prop;
				$have  = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore
				if ( null !== $have && (int) $have !== (int) $exp[ $key ] ) {
					$diffs[] = sprintf( '%s table: backup had %d rows but restored table has %d', $key, (int) $exp[ $key ], (int) $have );
				}
			}
		}
		foreach ( $diffs as $diff ) {
			self::core_note( $state, sprintf( __( 'Fidelity check: %s.', 'unboundwp' ), $diff ) );
		}
		if ( empty( $diffs ) ) {
			UWP_Helpers::debug_log( sprintf( 'import %s: fidelity check clean (routing, theme, identity, counts match)', $state['job_id'] ) );
		}
	}

	/**
	 * Best-effort homepage smoke test after the restore.
	 *
	 * Fetches the homepage server-side and reports HTTP errors or an
	 * empty body (the white-screen signature) as warnings. Never throws
	 * and never blocks completion: on hosts without loopback it simply
	 * records that the check was skipped.
	 *
	 * @param array $state Job state (by reference; gains warnings).
	 */
	protected static function smoke_test_homepage( &$state ) {
		if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'home_url' ) ) {
			return;
		}
		$url = home_url( '/' );
		$r   = wp_remote_get(
			$url,
			array(
				'timeout'   => 8,
				'blocking'  => true,
				'cookies'   => array(),
				'sslverify' => false,
			)
		);
		if ( is_wp_error( $r ) ) {
			UWP_Helpers::debug_log( sprintf( 'import %s: homepage check skipped (no loopback: %s)', $state['job_id'], $r->get_error_message() ) );
			return;
		}
		$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $r ) : 200;
		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $r ) : 'x';
		if ( $code >= 500 ) {
			self::core_note( $state, sprintf( __( 'Homepage check: the restored homepage returns HTTP %d. Open Site Health and Plugins/Themes on the restored site.', 'unboundwp' ), $code ) );
			return;
		}
		$text = function_exists( 'wp_strip_all_tags' )
			? trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $body ) ) )
			: trim( $body );
		if ( strlen( $text ) < 200 ) {
			self::core_note( $state, __( 'Homepage check: the restored homepage body looks empty (possible white screen). Check the active theme and recently reactivated plugins.', 'unboundwp' ) );
			return;
		}
		UWP_Helpers::debug_log( sprintf( 'import %s: homepage check OK (HTTP %d, %d bytes text)', $state['job_id'], $code, strlen( $text ) ) );
	}

	/**
	 * Verify the live core can boot before anything is reactivated.
	 *
	 * A half-written core (blocked swap + skipped files) whitescreens
	 * every page; failing LOUDLY here keeps the job resumable instead of
	 * completing into a dead site. Existence alone is NOT enough: the
	 * field case was a 28-byte silence stub sitting at ABSPATH/index.php
	 * (every file present, homepage 200 + empty) while this check passed.
	 * The front controller must therefore contain its bootstrap marker.
	 *
	 * @param array $state Job state.
	 * @throws Exception Naming the missing/unreadable/bogus core files.
	 */
	protected static function verify_live_core( &$state ) {
		$abs = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
		$missing = array();
		foreach ( array( '/wp-includes/version.php', '/wp-settings.php', '/wp-admin/admin.php', '/wp-load.php', '/index.php' ) as $rel ) {
			if ( ! is_file( $abs . $rel ) || ! is_readable( $abs . $rel ) ) {
				$missing[] = ltrim( $rel, '/' );
			}
		}
		$ver = self::read_core_version( $abs . '/wp-includes/version.php' );
		if ( '' === $ver && ! in_array( 'wp-includes/version.php', $missing, true ) ) {
			$missing[] = 'wp-includes/version.php (unreadable version)';
		}
		// The front controller must boot WordPress, not sit there: a
		// silence stub or foreign index.php passes every existence check
		// yet serves a blank homepage with HTTP 200 and no error anywhere.
		if ( ! in_array( 'index.php', $missing, true ) && ! self::looks_like_wp_bootstrap( $abs . '/index.php' ) ) {
			$missing[] = 'index.php (not a WordPress front controller, homepage cannot boot)';
		}
		$missing = array_values( array_unique( $missing ) );
		if ( ! empty( $missing ) ) {
			throw new Exception(
				sprintf(
					/* translators: %s = comma-separated file list */
					__( 'Restore incomplete: WordPress core files are missing or unreadable after the file step: %s. Fix permissions and Resume. No data was lost.', 'unboundwp' ),
					implode( ', ', $missing )
				)
			);
		}
		UWP_Helpers::debug_log( sprintf( 'import %s: live core verified (%s)', $state['job_id'], $ver ) );
	}

	/**
	 * Whether a file looks like the WordPress front controller.
	 *
	 * Pure content check (small read): must require wp-blog-header.
	 *
	 * @param string $file Absolute path.
	 * @return bool
	 */
	public static function looks_like_wp_bootstrap( $file ) {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return false;
		}
		$head = @file_get_contents( $file, false, null, 0, 2048 ); // phpcs:ignore
		return is_string( $head ) && false !== strpos( $head, 'wp-blog-header' );
	}

	/**
	 * Initialize the reactivation work list + report (idempotent).
	 *
	 * @param array $state Job state (by reference).
	 */
	protected static function reactivation_init( &$state ) {
		if ( ! isset( $state['reactivation'] ) || ! is_array( $state['reactivation'] ) ) {
			$state['reactivation'] = array(
				'theme'     => '',
				'activated' => array(),
				'skipped'   => array(),
				'failed'    => array(),
			);
		}
		foreach ( array( 'activated', 'skipped', 'failed' ) as $k ) {
			if ( ! isset( $state['reactivation'][ $k ] ) || ! is_array( $state['reactivation'][ $k ] ) ) {
				$state['reactivation'][ $k ] = array();
			}
		}
		if ( isset( $state['react_list'] ) && is_array( $state['react_list'] ) ) {
			if ( ! isset( $state['react_index'] ) ) {
				$state['react_index'] = 0;
			}
			if ( ! array_key_exists( 'react_current', $state ) ) {
				$state['react_current'] = null;
			}
			return;
		}
		$pkg = ( isset( $state['package'] ) && is_array( $state['package'] ) ) ? $state['package'] : array();
		$plugins = array();
		if ( ! empty( $pkg['ActivePlugins'] ) && is_array( $pkg['ActivePlugins'] ) ) {
			$plugins = array_values( $pkg['ActivePlugins'] );
		}
		if ( empty( $plugins ) && function_exists( 'get_option' ) ) {
			// Foreign backup without our package format: fall back to the
			// imported list (Ai1WM dumps blank it, so usually empty here).
			$opt = get_option( 'active_plugins', array() );
			if ( is_array( $opt ) ) {
				$plugins = array_values( $opt );
			}
		}
		$clean = array();
		foreach ( $plugins as $plugin ) {
			$plugin = ltrim( str_replace( '\\', '/', (string) $plugin ), '/' );
			if ( '' === $plugin || $plugin === UWP_PLUGIN_BASENAME || in_array( $plugin, $clean, true ) ) {
				continue;
			}
			$clean[] = $plugin;
		}
		$state['react_list']    = $clean;
		$state['react_index']   = 0;
		$state['react_current'] = null;
	}

	/**
	 * Reactivate a budgeted slice of the plugin list.
	 *
	 * The cursor is persisted after EVERY plugin, and the in-flight plugin
	 * is marked before attempting it - so a tick killed by a bad plugin
	 * resumes by skipping exactly that plugin (see
	 * handle_pinned_reactivation_fatal()).
	 *
	 * @param array $state Job state (by reference).
	 * @param float $started Tick start microtime (0 = no time budget).
	 * @return bool True when more plugins remain.
	 */
	protected static function reactivate_tick( &$state, $started ) {
		self::reactivation_init( $state );
		$list = is_array( $state['react_list'] ) ? $state['react_list'] : array();
		if ( ! empty( $state['skip_plugins'] ) ) {
			// Operator chose manual review: every plugin stays inactive.
			// Recorded as skipped (not failed) so the completion message
			// reads honestly and nothing is ever half-activated.
			foreach ( $list as $plugin ) {
				$plugin = ltrim( str_replace( '\\', '/', (string) $plugin ), '/' );
				if ( '' === $plugin || in_array( $plugin, $state['reactivation']['skipped'], true ) ) {
					continue;
				}
				$state['reactivation']['skipped'][] = $plugin;
			}
			$state['react_index']   = UWP_Helpers::safe_count( $list );
			$state['react_current'] = null;
			$state['react_done']    = true;
			self::core_note(
				$state,
				sprintf(
					/* translators: %d = number of plugins */
					__( 'Plugin reactivation skipped by option: %d plugin(s) left inactive for manual review under Plugins.', 'unboundwp' ),
					UWP_Helpers::safe_count( $state['reactivation']['skipped'] )
				)
			);
			UWP_Helpers::debug_log( sprintf( 'import %s: plugin reactivation skipped by option (%d left inactive)', $state['job_id'], UWP_Helpers::safe_count( $list ) ) );
			return false;
		}
		if ( ! isset( $state['react_index'] ) ) {
			$state['react_index'] = 0;
		}
		$secure = function_exists( 'is_ssl' ) ? is_ssl() : false;
		while ( (int) $state['react_index'] < UWP_Helpers::safe_count( $list ) ) {
			if ( $started > 0 && UWP_Helpers::out_of_time( $started ) ) {
				return true;
			}
			$plugin = isset( $list[ (int) $state['react_index'] ] ) ? (string) $list[ (int) $state['react_index'] ] : '';
			// Mark in-flight and persist BEFORE the dangerous include, so a
			// hard fatal attributes to exactly this plugin on next tick.
			$state['react_current'] = $plugin;
			self::save( $state );
			$outcome = self::activate_one_plugin( $state, $plugin, $secure );
			$state['react_current'] = null;
			$report = &$state['reactivation'];
			if ( 'activated' === $outcome && ! in_array( $plugin, $report['activated'], true ) ) {
				$report['activated'][] = $plugin;
			} elseif ( 'skipped' === $outcome && ! in_array( $plugin, $report['skipped'], true ) ) {
				$report['skipped'][] = $plugin;
			} elseif ( 'failed' === $outcome && ! in_array( $plugin, $report['failed'], true ) ) {
				$report['failed'][] = $plugin;
			}
			unset( $report );
			$state['react_index'] = (int) $state['react_index'] + 1;
			self::save( $state );
		}
		$state['react_current'] = null;
		$state['react_done']    = true;
		$report = $state['reactivation'];
		UWP_Helpers::debug_log(
			sprintf(
				'import %s: reactivation done: activated=%d skipped=%d failed=%d',
				$state['job_id'],
				UWP_Helpers::safe_count( $report['activated'] ),
				UWP_Helpers::safe_count( $report['skipped'] ),
				UWP_Helpers::safe_count( $report['failed'] )
			)
		);
		return false;
	}

	/**
	 * Attempt one plugin; catchable failures are recorded, never thrown.
	 *
	 * Uncatchable fatals (parse/compile errors in the plugin file) kill
	 * the tick - the persisted in-flight marker attributes them on the
	 * next tick, which force-deactivates and skips the killer.
	 *
	 * @param array  $state Job state (by reference).
	 * @param string $plugin Plugin basename, e.g. foo/bar.php.
	 * @param bool   $secure Whether the site is served over HTTPS.
	 * @return string|null 'activated', 'skipped', 'failed', or null when ignored.
	 */
	protected static function activate_one_plugin( &$state, $plugin, $secure ) {
		$plugin = ltrim( str_replace( '\\', '/', (string) $plugin ), '/' );
		if ( '' === $plugin || $plugin === UWP_PLUGIN_BASENAME ) {
			return null;
		}
		if ( ! $secure && self::is_risky_plugin( $plugin ) ) {
			return 'skipped';
		}
		if ( ! function_exists( 'activate_plugin' ) || ! function_exists( 'is_plugin_active' ) ) {
			self::fail_current_plugin( $state, $plugin, __( 'plugin API unavailable', 'unboundwp' ) );
			return 'failed';
		}
		if ( is_plugin_active( $plugin ) ) {
			return 'activated';
		}
		// Cheap pre-checks (no full plugin scan): missing/unreadable main
		// file or an illegal path fails here instead of inside activation.
		if ( function_exists( 'validate_file' ) && 0 !== validate_file( $plugin ) ) {
			self::fail_current_plugin( $state, $plugin, __( 'illegal plugin path', 'unboundwp' ) );
			return 'failed';
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$main = rtrim( WP_PLUGIN_DIR, '/\\' ) . '/' . $plugin;
			if ( ! is_file( $main ) || ! is_readable( $main ) ) {
				self::fail_current_plugin( $state, $plugin, __( 'plugin file missing or unreadable', 'unboundwp' ) );
				return 'failed';
			}
		}
		try {
			$result = activate_plugin( $plugin, '', false, true );
			if ( is_wp_error( $result ) ) {
				throw new Exception( $result->get_error_message() );
			}
			return 'activated';
		} catch ( Throwable $e ) {
			self::fail_current_plugin( $state, $plugin, $e->getMessage() );
			return 'failed';
		}
	}

	/**
	 * Record a plugin failure and guarantee it ends INACTIVE.
	 *
	 * Activation may have recorded the plugin before dying, which would
	 * whitescreen every page - so the killer is always force-deactivated.
	 *
	 * @param array  $state Job state (by reference).
	 * @param string $plugin Plugin basename.
	 * @param string $reason Human-readable reason.
	 */
	protected static function fail_current_plugin( &$state, $plugin, $reason ) {
		UWP_Helpers::debug_log( sprintf( 'import %s: plugin %s not reactivated: %s', $state['job_id'], $plugin, $reason ) );
		if ( ! isset( $state['reactivation'] ) || ! is_array( $state['reactivation'] ) ) {
			$state['reactivation'] = array(
				'theme'     => '',
				'activated' => array(),
				'skipped'   => array(),
				'failed'    => array(),
			);
		}
		if ( ! isset( $state['reactivation']['failed'] ) || ! is_array( $state['reactivation']['failed'] ) ) {
			$state['reactivation']['failed'] = array();
		}
		if ( ! in_array( $plugin, $state['reactivation']['failed'], true ) ) {
			$state['reactivation']['failed'][] = $plugin;
		}
		if ( function_exists( 'deactivate_plugins' ) ) {
			try {
				deactivate_plugins( $plugin, true );
			} catch ( Throwable $e ) { // phpcs:ignore - best effort only.
			}
		}
	}

	/**
	 * Attribute a pinned hard fatal to the in-flight plugin, if any.
	 *
	 * @param array  $state Job state (by reference).
	 * @param string $pinned Pinned fatal text.
	 * @return bool True when the fatal was attributed and the run may continue.
	 */
	protected static function handle_pinned_reactivation_fatal( &$state, $pinned ) {
		if ( ! is_array( $state ) || ! isset( $state['step'] ) || 'reactivate' !== $state['step'] ) {
			return false;
		}
		if ( empty( $state['react_current'] ) || ! is_string( $state['react_current'] ) ) {
			return false;
		}
		$plugin = $state['react_current'];
		self::reactivation_init( $state );
		self::fail_current_plugin(
			$state,
			$plugin,
			sprintf(
				/* translators: %s = crash reason */
				__( 'crashed the server during activation (hard fatal, left inactive): %s', 'unboundwp' ),
				$pinned
			)
		);
		// Advance past the killer so resume never retries it.
		$state['react_index']   = (int) ( isset( $state['react_index'] ) ? $state['react_index'] : 0 ) + 1;
		$state['react_current'] = null;
		return true;
	}

	/**
	 * Progress envelope.
	 *
	 * @param array  $state Job state.
	 * @param string $message Message.
	 * @param int    $percent Percent.
	 * @return array
	 */
	protected static function progress( $state, $message, $percent ) {
		return array(
			'done'    => false,
			'step'    => $state['step'],
			'percent' => max( 0, min( 99, (int) $percent ) ),
			'message' => $message,
			// Fresh nonce every tick: long restores can outlive the nonce
			// that was printed when the admin page loaded.
			'nonce'   => wp_create_nonce( 'uwp_nonce' ),
		);
	}
}
