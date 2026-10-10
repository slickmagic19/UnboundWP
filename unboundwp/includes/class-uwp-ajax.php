<?php
/**
 * All admin-ajax endpoints. Every endpoint does a small bounded tick and
 * returns JSON progress - the browser loops until done. No request ever
 * tries to export/import a whole site at once (that's what prevents
 * timeouts and "file too large" errors).
 *
 * @package UnboundWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UWP_Ajax {

	/**
	 * Actions that continue long-running jobs. These MUST work without a
	 * WordPress login: restoring a database replaces the user tables and
	 * kills the operator's session mid-run by design. They authenticate
	 * purely by per-job secret (see check_job_request) - the same pattern
	 * as All-in-One WP Migration's nopriv endpoints + secret_key.
	 */
	const NOPRIV_ACTIONS = array(
		'uwp_export_tick',
		'uwp_import_tick',
		'uwp_backup_verify_tick',
		'uwp_job_status',
	);

	/**
	 * Register AJAX actions.
	 */
	public static function register() {
		$actions = array(
			'uwp_export_start',
			'uwp_export_tick',
			'uwp_export_cancel',
			'uwp_upload_chunk',
			'uwp_import_start',
			'uwp_import_tick',
			'uwp_import_cancel',
			'uwp_backup_delete',
			'uwp_backup_download',
			'uwp_backup_verify_start',
			'uwp_backup_verify_tick',
			'uwp_job_status',
			'uwp_diag',
			'uwp_log_download',
		);
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, 'dispatch' ) );
			if ( in_array( $action, self::NOPRIV_ACTIONS, true ) ) {
				// Logged-out requests never reach wp_ajax_* handlers at
				// all (admin-ajax answers bare `0`), so without this the
				// job secret could never be checked after a session died.
				add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, 'dispatch' ) );
			}
		}
	}

	/**
	 * Route to the right handler.
	 */
	public static function dispatch() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : ''; // phpcs:ignore
		// Log fatals for the long-running tick actions - without this a
		// dying tick looks identical to a network glitch in the browser.
		// The state key pins the fatal to the job so the next tick can
		// surface the real cause in the UI.
		$fatal_keys = array(
			'uwp_export_tick'        => UWP_Export::STATE_OPTION,
			'uwp_import_tick'        => UWP_Import::STATE_OPTION,
			'uwp_backup_verify_tick' => UWP_Verify::STATE_OPTION,
			'uwp_upload_chunk'       => null,
			'uwp_job_status'         => null,
		);
		if ( isset( $fatal_keys[ $action ] ) ) {
			UWP_Helpers::capture_fatals( $action, $fatal_keys[ $action ] );
		}
		try {
			switch ( $action ) {
				case 'uwp_export_start':
					UWP_Helpers::check_request();
					UWP_Export::cancel(); // fresh start.
					$export_password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore
					$state = UWP_Export::start( is_string( $export_password ) ? $export_password : '' );
					wp_send_json_success(
						array(
							'message' => __( 'Export started.', 'unboundwp' ),
							'job_id'  => $state['job_id'],
							'secret'  => $state['secret'],
						)
					);
					break;

				case 'uwp_export_tick':
					// Secret-authed: survives login/session expiry mid-run.
					UWP_Helpers::check_job_request( UWP_Export::STATE_OPTION );
					$result = UWP_Export::tick();
					wp_send_json_success( $result );
					break;

				case 'uwp_export_cancel':
					UWP_Helpers::check_job_or_admin( UWP_Export::STATE_OPTION );
					UWP_Export::cancel();
					wp_send_json_success( array( 'message' => __( 'Export cancelled.', 'unboundwp' ) ) );
					break;

				case 'uwp_upload_chunk':
					self::upload_chunk();
					break;

				case 'uwp_import_start':
					UWP_Helpers::check_request();
					$archive  = isset( $_POST['archive'] ) ? sanitize_file_name( wp_unslash( $_POST['archive'] ) ) : ''; // phpcs:ignore
					$password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore
					if ( '' === $archive ) {
						throw new Exception( __( 'No archive selected.', 'unboundwp' ) );
					}
					// Only allow files inside our own dirs (no path traversal).
					$path = self::resolve_archive( $archive );
					UWP_Import::cancel();
					$state = UWP_Import::start( $path, $password, UWP_Import::restore_options_from_request() );
					wp_send_json_success(
						array(
							'message' => __( 'Import started.', 'unboundwp' ),
							'job_id'  => $state['job_id'],
							'secret'  => $state['secret'],
						)
					);
					break;

				case 'uwp_import_tick':
					// Secret-authed: survives the database (and session)
					// replacement mid-restore.
					UWP_Helpers::check_job_request( UWP_Import::STATE_OPTION );
					$result = UWP_Import::tick();
					wp_send_json_success( $result );
					break;

				case 'uwp_import_cancel':
					UWP_Helpers::check_job_or_admin( UWP_Import::STATE_OPTION );
					UWP_Import::cancel();
					wp_send_json_success( array( 'message' => __( 'Import cancelled.', 'unboundwp' ) ) );
					break;

				case 'uwp_backup_delete':
					UWP_Helpers::check_request();
					$name = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : ''; // phpcs:ignore
					$path = trailingslashit( uwp_backups_dir() ) . $name;
					if ( ! UWP_Helpers::is_supported_archive( $name ) || ! is_file( $path ) ) {
						throw new Exception( __( 'Backup not found.', 'unboundwp' ) );
					}
					unlink( $path ); // phpcs:ignore
					wp_send_json_success( array( 'message' => __( 'Backup deleted.', 'unboundwp' ) ) );
					break;

				case 'uwp_backup_download':
					self::download();
					break;

			case 'uwp_log_download':
					self::download_log();
					break;

				case 'uwp_diag':
					// Login-gated diagnostics battery (read-only probes).
					UWP_Helpers::check_request();
					wp_send_json_success( UWP_Diag::run() );
					break;

				case 'uwp_backup_verify_start':
					UWP_Helpers::check_request();
					$name = isset( $_POST['file'] ) ? sanitize_file_name( wp_unslash( $_POST['file'] ) ) : ''; // phpcs:ignore
					$path = trailingslashit( uwp_backups_dir() ) . $name;
					if ( ! UWP_Helpers::is_supported_archive( $name ) || ! is_file( $path ) ) {
						throw new Exception( __( 'Backup not found.', 'unboundwp' ) );
					}
					$vstate = UWP_Verify::start( $path );
					wp_send_json_success(
						array(
							'message' => __( 'Verification started.', 'unboundwp' ),
							'secret'  => $vstate['secret'],
						)
					);
					break;

				case 'uwp_backup_verify_tick':
					UWP_Helpers::check_job_request( UWP_Verify::STATE_OPTION );
					$result = UWP_Verify::tick();
					wp_send_json_success( $result );
					break;

				case 'uwp_job_status':
					// Read-only progress poll. Accepts the job secret (for
					// logged-out polling after a session died) or the normal
					// admin login. Also delivers completion receipts left by
					// loopback-driven ticks that finished with no browser.
					$job = isset( $_POST['job'] ) ? sanitize_key( $_POST['job'] ) : ''; // phpcs:ignore
					$key = ( 'export' === $job ) ? UWP_Export::STATE_OPTION : UWP_Import::STATE_OPTION;

					$done = UWP_Helpers::job_take_done( $key );
					if ( null !== $done ) {
						$response = array(
							'active'  => false,
							'done'    => true,
							'message' => isset( $done['message'] ) ? $done['message'] : __( 'Complete.', 'unboundwp' ),
							'file'    => isset( $done['file'] ) ? $done['file'] : '',
							'size'    => isset( $done['size'] ) ? $done['size'] : 0,
							'nonce'   => wp_create_nonce( 'uwp_nonce' ),
						);
						if ( '' !== $response['file'] ) {
							$response['download_url'] = admin_url(
								'admin-ajax.php?action=uwp_backup_download&file=' . rawurlencode( $response['file'] ) . '&nonce=' . $response['nonce']
							);
						}
						wp_send_json_success( $response );
					}

					$secret = isset( $_REQUEST['job_secret'] ) ? (string) $_REQUEST['job_secret'] : ''; // phpcs:ignore
					$state  = null;
					if ( '' !== $secret ) {
						$candidate = UWP_Helpers::job_load( $key );
						if ( is_array( $candidate ) && isset( $candidate['secret'] ) && hash_equals( (string) $candidate['secret'], $secret ) ) {
							$state = $candidate;
						}
					}
					if ( null === $state ) {
						if ( ! UWP_Helpers::has_login_cookie() ) {
							UWP_Helpers::json_403( __( 'You are not allowed to do this. Log in and try again.', 'unboundwp' ) );
						}
						UWP_Helpers::check_request();
						$state = ( 'export' === $job ) ? UWP_Export::load() : UWP_Import::load();
					}
					if ( null === $state ) {
						wp_send_json_success( array( 'active' => false ) );
					}
					$describe = ( 'export' === $job ) ? UWP_Export::describe() : UWP_Import::describe();
					wp_send_json_success(
						array(
							'active'  => true,
							'done'    => false,
							'step'    => isset( $state['step'] ) ? $state['step'] : '',
							'created' => isset( $state['created'] ) ? (int) $state['created'] : 0,
							'percent' => null !== $describe ? $describe['percent'] : 0,
							'message' => null !== $describe ? $describe['message'] : __( 'Working…', 'unboundwp' ),
							'nonce'   => wp_create_nonce( 'uwp_nonce' ),
							// Hand over the job secret so the reloaded page
							// can tick without depending on the session.
							'secret'  => isset( $state['secret'] ) ? $state['secret'] : '',
						)
					);
					break;

				default:
					wp_send_json_error( array( 'message' => __( 'Unknown action.', 'unboundwp' ) ), 400 );
			}
		} catch ( Throwable $e ) {
			// Throwable (not just Exception): TypeErrors and friends become
			// a readable JSON error instead of a dropped connection. True
			// memory exhaustion still hard-dies and is caught by capture_fatals().
			// Include file:line - without it a message like "count(): ...
			// null given" is unlocatable (that exact gap cost a debug round).
			$where = basename( (string) $e->getFile() ) . ':' . (int) $e->getLine();
			UWP_Helpers::debug_log( sprintf( 'ERROR in %s (%s): %s', $action, $where, $e->getMessage() ) );
			$error = array( 'message' => $e->getMessage() . ' (' . $where . ')' );
			// Attach the log tail so the UI can show the story leading up
			// to this failure - no more evidence-free errors.
			$tail = UWP_Helpers::read_log_tail( 15 );
			if ( '' !== $tail ) {
				$error['log_tail'] = $tail;
			}
			// Attach the newest job's FULL log (never truncated): with it,
			// any failure can be fixed from the payload alone. The Logs
			// admin page offers the same files for viewing/downloading.
			if ( class_exists( 'UWP_Logger' ) ) {
				$full = UWP_Logger::latest_job_log();
				if ( '' !== $full ) {
					$error['log_full'] = $full;
					$error['logs_url'] = admin_url( 'admin.php?page=unboundwp-logs' );
				}
			}
			wp_send_json_error( $error );
		}
	}

	/**
	 * Resolve a backup/upload file name to an absolute path inside our dirs.
	 *
	 * @param string $name File name (no directories).
	 * @return string Absolute path.
	 * @throws Exception When not found.
	 */
	protected static function resolve_archive( $name ) {
		$name = sanitize_file_name( basename( $name ) );
		if ( ! UWP_Helpers::is_supported_archive( $name ) ) {
			throw new Exception( __( 'Unsupported file type.', 'unboundwp' ) );
		}
		$candidates = array(
			trailingslashit( uwp_backups_dir() ) . $name,
			trailingslashit( uwp_temp_dir() ) . 'uploads/' . $name,
		);
		foreach ( $candidates as $path ) {
			if ( is_file( $path ) ) {
				return $path;
			}
		}
		throw new Exception( __( 'Archive not found.', 'unboundwp' ) );
	}

	/**
	 * Receive one upload slice and merge it into the assembling file.
	 *
	 * Resumable, idempotent protocol - the browser sends:
	 *   file_name, offset (byte position this slice starts at),
	 *   total_size, nonce + the slice.
	 *
	 * The server compares the slice offset against the bytes it already
	 * has and always answers with its true `received` count, which the
	 * browser obeys. That makes every failure mode self-healing:
	 *   - duplicate delivery (retry after a timeout that actually landed)
	 *     → bytes already present, skipped, no corruption;
	 *   - missed slice → server reports where it is, browser rewinds;
	 *   - partial write → only the missing tail is appended;
	 *   - temp wiped mid-upload → browser restarts cleanly from zero.
	 *
	 * Because slices are small (2 MB, shrinking on strict hosts),
	 * PHP's upload_max_filesize / post_max_size never matter - a 20 GB
	 * backup uploads just as well as a 2 MB one.
	 */
	protected static function upload_chunk() {
		UWP_Helpers::check_request();

		$file_name  = isset( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( $_POST['file_name'] ) ) : ''; // phpcs:ignore
		$offset     = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$total_size = isset( $_POST['total_size'] ) ? max( 0, (int) $_POST['total_size'] ) : 0;

		if ( '' === $file_name || ! UWP_Helpers::is_supported_archive( $file_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Only .uwp and .wpress files can be uploaded.', 'unboundwp' ) ) );
		}
		if ( $total_size <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Empty file. Please choose a real backup.', 'unboundwp' ) ) );
		}
		if ( empty( $_FILES['slice'] ) || ! isset( $_FILES['slice']['error'] ) ) { // phpcs:ignore
			wp_send_json_error(
				array(
					'message'   => __( 'Upload slice missing. Please try again.', 'unboundwp' ),
					'retryable' => true,
				)
			);
		}

		$err = (int) $_FILES['slice']['error']; // phpcs:ignore
		if ( UPLOAD_ERR_INI_SIZE === $err || UPLOAD_ERR_FORM_SIZE === $err ) {
			// Host rejects slices this big - ask the browser for smaller ones.
			wp_send_json_error(
				array(
					'message' => __( 'Server rejected this piece size. Retrying with smaller pieces…', 'unboundwp' ),
					'shrink'  => true,
				)
			);
		}
		if ( UPLOAD_ERR_PARTIAL === $err ) {
			// Truncated in transit - harmless, just resend it.
			wp_send_json_error(
				array(
					'message'   => __( 'A piece arrived truncated. Resending…', 'unboundwp' ),
					'retryable' => true,
				)
			);
		}
		if ( UPLOAD_ERR_OK !== $err || ! is_uploaded_file( $_FILES['slice']['tmp_name'] ) ) { // phpcs:ignore
			wp_send_json_error( array( 'message' => __( 'Upload slice missing. Please try again.', 'unboundwp' ) ) );
		}
		$slice_len = (int) $_FILES['slice']['size']; // phpcs:ignore
		if ( $slice_len <= 0 ) {
			wp_send_json_error(
				array(
					'message'   => __( 'An empty piece arrived. Resending…', 'unboundwp' ),
					'retryable' => true,
				)
			);
		}

		$dest_dir = trailingslashit( uwp_temp_dir() ) . 'uploads/';
		if ( ! file_exists( $dest_dir ) ) {
			wp_mkdir_p( $dest_dir );
		}
		$dest = $dest_dir . $file_name;

		$in = @fopen( $_FILES['slice']['tmp_name'], 'rb' ); // phpcs:ignore
		if ( false === $in ) {
			wp_send_json_error(
				array(
					'message'   => __( 'Cannot read upload piece. Resending…', 'unboundwp' ),
					'retryable' => true,
				)
			);
		}

		$merged = self::merge_slice( $dest, $in, $offset, $slice_len );
		fclose( $in );
		if ( isset( $merged['error'] ) ) {
			wp_send_json_error(
				array(
					'message'   => $merged['error'],
					'retryable' => true,
				)
			);
		}
		if ( ! empty( $merged['gap'] ) ) {
			// We missed something earlier - report where we are so the
			// browser rewinds and resends from there.
			wp_send_json_success(
				array(
					'done'     => false,
					'received' => $merged['received'],
					'message'  => __( 'Uploading…', 'unboundwp' ),
				)
			);
		}
		// Duplicate delivery (a retry that already landed) is skipped inside
		// merge_slice and lands here with the true byte count - the browser
		// simply advances past it.

		self::upload_respond( $dest, $file_name, $total_size );
	}

	/**
	 * Merge one slice into the assembling file, idempotently.
	 *
	 * Pure file logic (no request handling) so it stays easy to test:
	 * returns the true received byte count plus what happened.
	 *
	 * @param string   $dest Absolute assembling file.
	 * @param resource $in Open readable handle of the slice.
	 * @param int      $offset Byte position this slice starts at.
	 * @param int      $slice_len Slice length in bytes.
	 * @return array array('received' => int, 'gap' => bool, 'duplicate' => bool, 'error' => string?)
	 */
	public static function merge_slice( $dest, $in, $offset, $slice_len ) {
		if ( 0 === $offset ) {
			// Start of (re)upload: fresh file.
			$out = @fopen( $dest, 'wb' ); // phpcs:ignore
			if ( false === $out ) {
				return array(
					'received'  => 0,
					'gap'       => false,
					'duplicate' => false,
					'error'     => __( 'Cannot write to temp directory.', 'unboundwp' ),
				);
			}
			$skip = 0;
		} else {
			$out = @fopen( $dest, 'c+b' ); // phpcs:ignore (create if gone, never truncate)
			if ( false === $out ) {
				return array(
					'received'  => 0,
					'gap'       => false,
					'duplicate' => false,
					'error'     => __( 'Cannot write to temp directory.', 'unboundwp' ),
				);
			}
			$stat = fstat( $out );
			$have = $stat ? (int) $stat['size'] : 0;
			if ( $have < $offset ) {
				fclose( $out );
				return array(
					'received'  => $have,
					'gap'       => true,
					'duplicate' => false,
				);
			}
			if ( $have >= $offset + $slice_len ) {
				fclose( $out );
				return array(
					'received'  => $have,
					'gap'       => false,
					'duplicate' => true,
				);
			}
			// Partial overlap: append only the missing tail.
			fseek( $out, 0, SEEK_END );
			$skip = $have - $offset;
			if ( $skip > 0 ) {
				fseek( $in, $skip );
			}
		}

		while ( ! feof( $in ) ) {
			$data = fread( $in, UWP_CHUNK_BYTES ); // phpcs:ignore
			if ( false === $data || '' === $data ) {
				break;
			}
			if ( false === @fwrite( $out, $data ) ) { // phpcs:ignore
				$stat = fstat( $out );
				fclose( $out );
				return array(
					'received'  => $stat ? (int) $stat['size'] : 0,
					'gap'       => false,
					'duplicate' => false,
					'error'     => __( 'Could not save upload piece (disk full?).', 'unboundwp' ),
				);
			}
		}
		$stat = fstat( $out );
		fclose( $out );
		return array(
			'received'  => $stat ? (int) $stat['size'] : 0,
			'gap'       => false,
			'duplicate' => false,
		);
	}

	/**
	 * Answer an upload slice: report true progress, or finish when whole.
	 *
	 * The browser always advances to `received`, so gaps, duplicates and
	 * partial writes converge instead of failing the upload.
	 *
	 * @param string $dest Absolute assembling file.
	 * @param string $file_name Original file name.
	 * @param int    $total_size Expected final size.
	 */
	protected static function upload_respond( $dest, $file_name, $total_size ) {
		clearstatcache( true, $dest );
		$received = is_file( $dest ) ? filesize( $dest ) : 0;

		if ( $received > $total_size ) {
			// Shouldn't happen, but never hand a corrupt file to the importer.
			@unlink( $dest ); // phpcs:ignore
			wp_send_json_error(
				array(
					'message' => __( 'Upload got out of sync. Restarting it cleanly…', 'unboundwp' ),
					'restart' => true,
				)
			);
		}

		if ( $received >= $total_size ) {
			// Quick sanity: file must start with a plausible header, not HTML.
			if ( ! self::looks_like_archive( $dest ) ) {
				@unlink( $dest ); // phpcs:ignore
				wp_send_json_error( array( 'message' => __( 'This does not look like a backup file.', 'unboundwp' ) ) );
			}
			wp_send_json_success(
				array(
					'done'     => true,
					'received' => $received,
					'file'     => $file_name,
					'size'     => $received,
					'message'  => __( 'Upload complete.', 'unboundwp' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'done'     => false,
				'received' => $received,
				'message'  => __( 'Uploading…', 'unboundwp' ),
			)
		);
	}

	/**
	 * Peek at the first header to reject obvious non-archives early.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	protected static function looks_like_archive( $path ) {
		$h = @fopen( $path, 'rb' ); // phpcs:ignore
		if ( false === $h ) {
			return false;
		}
		$block = fread( $h, UWP_Archive::HEADER_SIZE ); // phpcs:ignore
		fclose( $h );
		if ( strlen( $block ) < UWP_Archive::HEADER_SIZE ) {
			return false;
		}
		$entry = UWP_Archive::parse_header( $block );
		return null !== $entry && '' !== $entry['basename'];
	}

	/**
	 * Stream a backup to the browser (chunked readfile - no memory limit issues).
	 */
	protected static function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'unboundwp' ), 403 );
		}
		$nonce = isset( $_GET['nonce'] ) ? sanitize_key( wp_unslash( $_GET['nonce'] ) ) : ''; // phpcs:ignore
		if ( ! wp_verify_nonce( $nonce, 'uwp_nonce' ) ) {
			wp_die( esc_html__( 'Session expired. Please refresh and try again.', 'unboundwp' ), 403 );
		}
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : ''; // phpcs:ignore
		$path = trailingslashit( uwp_backups_dir() ) . $file;
		if ( ! UWP_Helpers::is_supported_archive( $file ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Backup not found.', 'unboundwp' ), 404 );
		}

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		$h = @fopen( $path, 'rb' ); // phpcs:ignore
		if ( false === $h ) {
			wp_die( esc_html__( 'Cannot read backup.', 'unboundwp' ), 500 );
		}
		while ( ! feof( $h ) ) {
			$data = fread( $h, UWP_CHUNK_BYTES ); // phpcs:ignore
			if ( false === $data || '' === $data ) {
				break;
			}
			echo $data; // phpcs:ignore
			if ( ob_get_level() > 0 ) {
				@ob_flush(); // phpcs:ignore
			}
			flush();
		}
		fclose( $h );
		exit;
	}

	/**
	 * Stream a full log file to the browser (never truncated).
	 */
	protected static function download_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'unboundwp' ), 403 );
		}
		$nonce = isset( $_GET['nonce'] ) ? sanitize_key( wp_unslash( $_GET['nonce'] ) ) : ''; // phpcs:ignore
		if ( ! wp_verify_nonce( $nonce, 'uwp_nonce' ) ) {
			wp_die( esc_html__( 'Session expired. Please refresh and try again.', 'unboundwp' ), 403 );
		}
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : ''; // phpcs:ignore
		if ( ! class_exists( 'UWP_Logger' ) || false === UWP_Logger::safe_name( $file ) ) {
			wp_die( esc_html__( 'Log not found.', 'unboundwp' ), 404 );
		}
		$path = trailingslashit( uwp_temp_dir() ) . $file;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Log not found.', 'unboundwp' ), 404 );
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		$h = @fopen( $path, 'rb' ); // phpcs:ignore
		if ( false === $h ) {
			wp_die( esc_html__( 'Cannot read log.', 'unboundwp' ), 500 );
		}
		while ( ! feof( $h ) ) {
			$data = fread( $h, UWP_CHUNK_BYTES ); // phpcs:ignore
			if ( false === $data || '' === $data ) {
				break;
			}
			echo $data; // phpcs:ignore
			if ( ob_get_level() > 0 ) {
				@ob_flush(); // phpcs:ignore
			}
			flush();
		}
		fclose( $h );
		exit;
	}
}
