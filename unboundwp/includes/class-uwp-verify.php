<?php
/**
 * Read-only backup verifier.
 *
 * Answers "is this backup actually good?" without restoring anything:
 * walks the archive header by header, streams every entry's bytes and
 * checks them against the per-file CRC32 checksum stored in the header.
 *
 * Like export/import it works in small resumable ticks (one AJAX call per
 * few seconds of work), so even multi-GB backups verify without timeouts.
 *
 * Notes:
 * - Legacy .wpress entries carry no checksum - those are checked for
 *   readability/structure (truncation) but reported as "no checksum".
 * - Compressed/encrypted Ai1WM archives: the stored bytes can't be
 *   checksum-verified the same way, so per-file CRC comparison is skipped
 *   for those and the report says so. Structure is still fully checked.
 *
 * @package UnboundWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UWP_Verify {

	const STATE_OPTION = 'uwp_verify_state';
	const MAX_ERRORS   = 25;

	/**
	 * Start verifying an archive.
	 *
	 * @param string $archive_path Absolute .uwp/.wpress path.
	 * @return array Initial state (also includes package metadata).
	 * @throws Exception When the archive can't be used.
	 */
	public static function start( $archive_path ) {
		if ( ! is_file( $archive_path ) || ! is_readable( $archive_path ) ) {
			throw new Exception( __( 'Backup not found or not readable.', 'unboundwp' ) );
		}
		if ( ! UWP_Helpers::is_supported_archive( $archive_path ) ) {
			throw new Exception( __( 'Only .uwp and .wpress files can be verified.', 'unboundwp' ) );
		}

		// Fresh start - drop any previous verification state.
		UWP_Helpers::job_clear( self::STATE_OPTION );

		$raw     = UWP_Archive::read_small_entry( $archive_path, 'package.json' );
		$package = $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $package ) ) {
			$package = array();
		}

		$state = array(
			'job_id'     => 'verify-' . gmdate( 'Ymd-His' ) . '-' . substr( UWP_Helpers::new_job_secret(), 0, 6 ),
			'archive'    => $archive_path,
			'secret'     => UWP_Helpers::new_job_secret(),
			'size'       => filesize( $archive_path ),
			'offset'     => 0,
			'checked'    => 0,
			'with_crc'   => 0,
			'no_crc'     => 0,
			'bytes'      => 0,
			'errors'     => array(),
			'saw_eof'    => false,
			'saw_pkg'    => false,
			'saw_db'     => false,
			'package'    => $package,
			'encoded'    => ! empty( $package['Encrypted'] ) || ! empty( $package['Compression']['Enabled'] ),
			'done'       => false,
		);
		self::save( $state );
		if ( class_exists( 'UWP_Logger' ) ) {
			UWP_Logger::prune();
		}
		UWP_Helpers::debug_log( sprintf( 'verify %s: started on %s', $state['job_id'], basename( $archive_path ) ) );
		return $state;
	}

	/**
	 * Load current verification state.
	 *
	 * @return array|null
	 */
	public static function load() {
		$state = UWP_Helpers::job_load( self::STATE_OPTION );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Persist verification state (never autoloaded).
	 *
	 * @param array $state Job state.
	 */
	public static function save( $state ) {
		UWP_Helpers::job_save( self::STATE_OPTION, $state );
	}

	/**
	 * Do one bounded chunk of verification.
	 *
	 * @return array Progress envelope; when done includes the full report.
	 * @throws Exception On fatal failure.
	 */
	public static function tick() {
		UWP_Helpers::prepare_chunk_request();

		$lock = UWP_Helpers::job_lock( self::STATE_OPTION );
		if ( false === $lock ) {
			return array(
				'done'    => false,
				'busy'    => true,
				'chained' => false,
				'percent' => -1,
				'message' => __( 'Another worker is already on it…', 'unboundwp' ),
				'nonce'   => wp_create_nonce( 'uwp_nonce' ),
			);
		}
		try {
			$result = self::tick_locked();
		} finally {
			fclose( $lock );
		}

		if ( empty( $result['done'] ) ) {
			$state            = self::load();
			$secret           = ( is_array( $state ) && isset( $state['secret'] ) ) ? (string) $state['secret'] : '';
			$result['chained'] = UWP_Helpers::chain_self( 'uwp_backup_verify_tick', $secret );
		}
		return $result;
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
			throw new Exception( __( 'No verification in progress.', 'unboundwp' ) );
		}
		$pinned = UWP_Helpers::job_take_fatal( self::STATE_OPTION );
		if ( null !== $pinned ) {
			throw new Exception( sprintf( __( 'The previous step crashed: %s', 'unboundwp' ), $pinned ) );
		}
		if ( ! is_file( $state['archive'] ) ) {
			throw new Exception( __( 'Backup file disappeared.', 'unboundwp' ) );
		}
		UWP_Helpers::debug_log(
			sprintf(
				'verify %s: tick checked=%d offset=%d mem=%d',
				isset( $state['job_id'] ) ? $state['job_id'] : '?',
				isset( $state['checked'] ) ? (int) $state['checked'] : 0,
				isset( $state['offset'] ) ? (int) $state['offset'] : 0,
				memory_get_usage( true )
			)
		);

		$handle = @fopen( $state['archive'], 'rb' ); // phpcs:ignore
		if ( false === $handle ) {
			throw new Exception( __( 'Cannot open backup for verification.', 'unboundwp' ) );
		}
		fseek( $handle, (int) $state['offset'] );

		$finished = false;
		while ( ! UWP_Helpers::out_of_time( $started ) ) {
			$pos = ftell( $handle );
			if ( false === $pos || $pos >= $state['size'] || feof( $handle ) ) {
				$finished = true;
				break;
			}

			$block = @fread( $handle, UWP_Archive::HEADER_SIZE ); // phpcs:ignore
			if ( false === $block || '' === $block ) {
				$finished = true; // ended exactly on a boundary without EOF block.
				break;
			}
			if ( strlen( $block ) < UWP_Archive::HEADER_SIZE ) {
				self::add_error( $state, __( 'Archive ends mid-header: file is truncated.', 'unboundwp' ) );
				$finished = true;
				break;
			}

			$entry = UWP_Archive::parse_header( $block );
			if ( null === $entry ) {
				$state['saw_eof'] = true;
				$state['offset']  = ftell( $handle );
				$finished         = true;
				break;
			}

			if ( 'package.json' === $entry['basename'] ) {
				$state['saw_pkg'] = true;
			}
			if ( 'database.sql' === $entry['basename'] ) {
				$state['saw_db'] = true;
			}

			$ok = self::check_entry_data( $handle, $state, $entry );
			$state['offset'] = ftell( $handle );
			if ( ! $ok ) {
				$finished = true; // truncation - nothing more to read.
				break;
			}
		}

		fclose( $handle );
		self::save( $state );

		if ( $finished ) {
			return self::report( $state );
		}

		return array(
			'done'    => false,
			'percent' => $state['size'] > 0 ? (int) ( 100 * min( 1, $state['offset'] / $state['size'] ) ) : 100,
			'message' => sprintf(
				/* translators: %d = files checked so far */
				__( 'Verifying… (%d files checked)', 'unboundwp' ),
				$state['checked']
			),
			'nonce'   => wp_create_nonce( 'uwp_nonce' ),
		);
	}

	/**
	 * Stream one entry's data, hashing it when a checksum is available.
	 *
	 * @param resource $handle Open archive handle positioned at data start.
	 * @param array    $state Job state (by reference).
	 * @param array    $entry Parsed header.
	 * @return bool False when the data is truncated (stop the whole run).
	 */
	protected static function check_entry_data( $handle, &$state, $entry ) {
		$left = (int) $entry['size'];
		if ( $left < 0 ) {
			self::add_error( $state, sprintf( __( 'Entry %s has an invalid size.', 'unboundwp' ), $entry['name'] ) );
			return true; // keep going - header was intact.
		}

		$check_crc = '' !== $entry['crc'] && ! $state['encoded'];
		$ctx       = $check_crc ? hash_init( 'crc32b' ) : null;

		while ( $left > 0 ) {
			$want = min( UWP_Archive::CHUNK, $left );
			$data = @fread( $handle, $want ); // phpcs:ignore
			if ( false === $data || '' === $data ) {
				self::add_error( $state, sprintf( __( 'Entry %s is truncated: file ends early.', 'unboundwp' ), $entry['name'] ) );
				return false;
			}
			if ( $ctx ) {
				hash_update( $ctx, $data );
			}
			$state['bytes'] += strlen( $data );
			$left           -= strlen( $data );
		}

		if ( $ctx ) {
			$actual = strtolower( hash_final( $ctx ) );
			if ( $actual !== strtolower( $entry['crc'] ) ) {
				self::add_error(
					$state,
					sprintf(
						/* translators: 1: file name, 2: expected checksum, 3: actual checksum */
						__( 'Checksum mismatch in %1$s (expected %2$s, got %3$s).', 'unboundwp' ),
						$entry['name'],
						strtolower( $entry['crc'] ),
						$actual
					)
				);
			} else {
				$state['with_crc']++;
			}
		} else {
			$state['no_crc']++;
		}
		$state['checked']++;
		return true;
	}

	/**
	 * Record an error, keeping the stored list bounded.
	 *
	 * @param array  $state Job state (by reference).
	 * @param string $message Error message.
	 */
	protected static function add_error( &$state, $message ) {
		if ( ! isset( $state['errors'] ) || ! is_array( $state['errors'] ) ) {
			$state['errors'] = array();
		}
		if ( UWP_Helpers::safe_count( $state['errors'] ) < self::MAX_ERRORS ) {
			$state['errors'][] = $message;
		}
	}

	/**
	 * Build the final verification report.
	 *
	 * @param array $state Job state.
	 * @return array Report envelope (done = true).
	 */
	protected static function report( $state ) {
		$warnings = array();

		if ( ! $state['saw_eof'] ) {
			$warnings[] = __( 'Archive is missing its end-of-file marker.', 'unboundwp' );
		}
		if ( ! $state['saw_pkg'] ) {
			$warnings[] = __( 'package.json was not found in this backup.', 'unboundwp' );
		}
		if ( ! $state['saw_db'] ) {
			$warnings[] = __( 'database.sql was not found in this backup.', 'unboundwp' );
		}
		if ( $state['encoded'] ) {
			$warnings[] = __( 'Compressed/encrypted backup: file contents checked for readability, per-file checksums skipped.', 'unboundwp' );
		} elseif ( $state['no_crc'] > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d = number of files */
				__( '%d file(s) carry no checksum (legacy format): checked for readability only.', 'unboundwp' ),
				$state['no_crc']
			);
		}

		$healthy = empty( $state['errors'] ) && $state['saw_eof'] && $state['saw_db'] && $state['checked'] > 0;

		if ( $healthy ) {
			if ( $state['with_crc'] > 0 && 0 === $state['no_crc'] && ! $state['encoded'] ) {
				$message = sprintf(
					/* translators: 1: files checked, 2: data size, 3: archive file size */
					__( 'Backup verified: %1$d files, %2$s of data in a %3$s archive. All checksums OK.', 'unboundwp' ),
					$state['checked'],
					size_format( $state['bytes'] ),
					size_format( $state['size'] )
				);
			} else {
				$message = sprintf(
					/* translators: 1: files checked, 2: data size, 3: archive file size */
					__( 'Backup verified: %1$d files, %2$s of data in a %3$s archive. Structure OK (see notes).', 'unboundwp' ),
					$state['checked'],
					size_format( $state['bytes'] ),
					size_format( $state['size'] )
				);
			}
		} elseif ( empty( $state['errors'] ) ) {
			$message = __( 'Verification finished with warnings (see details).', 'unboundwp' );
		} else {
			$message = sprintf(
				/* translators: %d = number of problems */
				__( 'Backup FAILED verification with %d problem(s). Do not rely on this file.', 'unboundwp' ),
				UWP_Helpers::safe_count( isset( $state['errors'] ) ? $state['errors'] : array() )
			);
		}

		// Keep state for debugging, but the run is over.
		$state['done'] = true;
		self::save( $state );
		UWP_Helpers::debug_log(
			sprintf(
				'verify %s: complete healthy=%s checked=%d errors=%d',
				isset( $state['job_id'] ) ? $state['job_id'] : '?',
				$healthy ? 'yes' : 'no',
				$state['checked'],
				UWP_Helpers::safe_count( $state['errors'] )
			)
		);

		return array(
			'done'     => true,
			'percent'  => 100,
			'healthy'  => $healthy,
			'message'  => $message,
			'nonce'    => wp_create_nonce( 'uwp_nonce' ),
			'files'    => $state['checked'],
			'bytes'    => $state['bytes'],
			'with_crc' => $state['with_crc'],
			'errors'   => $state['errors'],
			'warnings' => $warnings,
			'meta'     => array(
				'site'    => isset( $state['package']['SiteURL'] ) ? $state['package']['SiteURL'] : null,
				'created' => isset( $state['package']['Created'] ) ? $state['package']['Created'] : null,
				'wp'      => isset( $state['package']['WordPress']['Version'] ) ? $state['package']['WordPress']['Version'] : null,
			),
		);
	}
}
