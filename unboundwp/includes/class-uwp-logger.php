<?php
/**
 * Full-fidelity job logging.
 *
 * Every job (export/import/verify) gets its own complete log file:
 * nothing is truncated, nothing is sampled - lifecycle, decisions,
 * warnings, skips and errors all land here in order. The shared
 * uwp-debug.log keeps the cross-job story (unchanged behavior); per-job
 * files are what you download when something needs fixing.
 *
 * Retention: each job log rotates at 5 MB (one .1 generation) and only
 * the 10 newest job logs are kept. The Logs admin page renders every
 * file IN FULL (view + download + delete).
 *
 * @package UnboundWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UWP_Logger {

	const MAX_BYTES = 5242880; // 5 MB per job log, then rotate once.
	const MAX_FILES = 10; // newest job logs kept.

	/**
	 * Sanitize a job id for use in a file name.
	 *
	 * @param string $job_id Job id.
	 * @return string Safe slug (fallback 'general').
	 */
	public static function job_slug( $job_id ) {
		$slug = preg_replace( '/[^a-z0-9_-]/i', '', (string) $job_id );
		return ( '' !== $slug ) ? substr( $slug, 0, 80 ) : 'general';
	}

	/**
	 * Absolute path of a job's log file.
	 *
	 * @param string $job_id Job id.
	 * @return string Absolute path.
	 */
	public static function log_path( $job_id ) {
		return trailingslashit( uwp_temp_dir() ) . 'uwp-log-' . self::job_slug( $job_id ) . '.log';
	}

	/**
	 * Append one line to a job's log AND mirror it to the shared log.
	 *
	 * Single call site for per-job lines: the shared story stays identical
	 * (same text as before), while the job file keeps the complete record.
	 *
	 * @param string $job_id Job id (or '' for general).
	 * @param string $level INFO, WARNING or ERROR.
	 * @param string $message Line text (already contains context).
	 */
	public static function job( $job_id, $level, $message ) {
		$level = in_array( $level, array( 'INFO', 'WARNING', 'ERROR' ), true ) ? $level : 'INFO';
		$line  = gmdate( 'Y-m-d H:i:s' ) . ' [' . $level . '] ' . (string) $message . "\n";
		$dir   = uwp_temp_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$path = self::log_path( $job_id );
		if ( is_file( $path ) && filesize( $path ) > self::MAX_BYTES ) {
			@rename( $path, $path . '.1' ); // phpcs:ignore - single rotated generation.
		}
		@file_put_contents( $path, $line, FILE_APPEND ); // phpcs:ignore
		// Mirror keeps the shared cross-job log complete too.
		UWP_Helpers::debug_log( '[' . $level . '] ' . (string) $message );
	}

	/**
	 * Append a ready-made line to a job's log file (no shared mirror).
	 *
	 * Used by the debug_log() auto-mirror (which already wrote the shared
	 * line) - separate from job() precisely to avoid mirror recursion.
	 *
	 * @param string $job_id Job id.
	 * @param string $line Complete line including timestamp + newline.
	 */
	public static function append( $job_id, $line ) {
		$dir = uwp_temp_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$path = self::log_path( $job_id );
		if ( is_file( $path ) && filesize( $path ) > self::MAX_BYTES ) {
			@rename( $path, $path . '.1' ); // phpcs:ignore
		}
		@file_put_contents( $path, $line, FILE_APPEND ); // phpcs:ignore
	}

	/**
	 * Read a job's log IN FULL. Never truncated by design.
	 *
	 * @param string $job_id Job id.
	 * @return string Full content (possibly '').
	 */
	public static function read( $job_id ) {
		$path = self::log_path( $job_id );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		$raw = file_get_contents( $path ); // phpcs:ignore
		return is_string( $raw ) ? $raw : '';
	}

	/**
	 * Newest job log's FULL content (for attaching to error responses).
	 *
	 * @return string Full content or '' when no job log exists.
	 */
	public static function latest_job_log() {
		$best      = '';
		$best_time = 0;
		foreach ( self::list_logs() as $item ) {
			if ( empty( $item['job'] ) || 0 !== strpos( $item['name'], 'uwp-log-' ) ) {
				continue;
			}
			if ( $item['mtime'] >= $best_time ) {
				$best_time = $item['mtime'];
				$best      = $item['name'];
			}
		}
		if ( '' === $best ) {
			return '';
		}
		if ( ! preg_match( '/^uwp-log-([A-Za-z0-9_-]+)\.log$/', $best, $m ) ) {
			return '';
		}
		return self::read( $m[1] );
	}

	/**
	 * List all log files (job logs + shared logs), newest first.
	 *
	 * @return array Each item: name, job (or ''), path, size, mtime.
	 */
	public static function list_logs() {
		$dir   = uwp_temp_dir();
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}
		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return $files;
		}
		foreach ( $entries as $entry ) {
			$job = '';
			if ( 0 === strpos( $entry, 'uwp-log-' ) && '.log' === substr( $entry, -4 ) ) {
				if ( ! preg_match( '/^uwp-log-([A-Za-z0-9_-]+)(\.1)?\.log$/', $entry, $m ) ) {
					continue;
				}
				$job = $m[1];
			} elseif ( 'uwp-debug.log' !== $entry && 'uwp-debug.log.1' !== $entry ) {
				continue;
			}
			$path = $dir . $entry;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$files[] = array(
				'name'  => $entry,
				'job'   => $job,
				'path'  => $path,
				'size'  => filesize( $path ),
				'mtime' => filemtime( $path ),
			);
		}
		usort(
			$files,
			function ( $a, $b ) {
				return $b['mtime'] - $a['mtime'];
			}
		);
		return $files;
	}

	/**
	 * Validate a viewer-supplied log file name (no paths, only our logs).
	 *
	 * @param string $name File name from the request.
	 * @return string|false Safe name or false.
	 */
	public static function safe_name( $name ) {
		$name = basename( (string) $name );
		if ( preg_match( '/^uwp-log-[A-Za-z0-9_-]+(\.1)?\.log$/', $name ) ) {
			return $name;
		}
		if ( 'uwp-debug.log' === $name || 'uwp-debug.log.1' === $name ) {
			return $name;
		}
		return false;
	}

	/**
	 * Delete one log file by viewer-supplied name.
	 *
	 * @param string $name File name from the request.
	 * @return bool True when deleted.
	 */
	public static function delete( $name ) {
		$safe = self::safe_name( $name );
		if ( false === $safe ) {
			return false;
		}
		$path = trailingslashit( uwp_temp_dir() ) . $safe;
		return is_file( $path ) ? (bool) @unlink( $path ) : false; // phpcs:ignore
	}

	/**
	 * Keep only the newest MAX_FILES job logs (shared logs untouched).
	 */
	public static function prune() {
		$kept  = 0;
		$tmp   = uwp_temp_dir();
		foreach ( self::list_logs() as $item ) {
			if ( '' === $item['job'] || '.1.log' === substr( $item['name'], -6 ) ) {
				continue;
			}
			$kept++;
			if ( $kept > self::MAX_FILES ) {
				@unlink( $item['path'] ); // phpcs:ignore
				@unlink( $item['path'] . '.1' ); // phpcs:ignore
			}
		}
	}
}
