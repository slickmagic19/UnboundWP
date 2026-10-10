<?php
/**
 * Small shared helpers. Kept dependency-free and easy to read.
 *
 * @package UnboundWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Fallbacks so this file also works standalone in tests / external tools.
if ( ! defined( 'UWP_EXTENSION' ) ) {
	define( 'UWP_EXTENSION', 'uwp' );
}
if ( ! defined( 'UWP_WPRESS_EXTENSION' ) ) {
	define( 'UWP_WPRESS_EXTENSION', 'wpress' );
}
if ( ! defined( 'UWP_CHUNK_BYTES' ) ) {
	define( 'UWP_CHUNK_BYTES', 512000 );
}
if ( ! defined( 'UWP_AJAX_TIME_BUDGET' ) ) {
	define( 'UWP_AJAX_TIME_BUDGET', 8 );
}

class UWP_Helpers {

	/**
	 * Give the current request the best chance to finish its small chunk:
	 * unlimited time (within reason), no abort, no output buffering.
	 * Each AJAX tick still does only a few seconds of work - this is just
	 * a safety net, the real timeout protection is chunking.
	 */
	public static function prepare_chunk_request() {
		@set_time_limit( 0 ); // phpcs:ignore
		@ignore_user_abort( true ); // phpcs:ignore
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore
		}
	}

	/**
	 * Check the current user may migrate, and verify the nonce.
	 *
	 * @param string $nonce_action Nonce action name.
	 */
	public static function check_request( $nonce_action = 'uwp_nonce' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to do this.', 'unboundwp' ) ),
				403
			);
		}
		check_ajax_referer( $nonce_action, 'nonce' );
	}

	/**
	 * Authorize a long-running job tick, the way All-in-One WP Migration
	 * does it: by job secret, not by login session.
	 *
	 * Replacing the database wipes wp_usermeta mid-restore, which kills
	 * the admin's own login cookie. Any tick gated on that cookie dies
	 * with it. A per-job random secret (created at job start, stored in
	 * the state file, handed to the browser once) keeps the run going
	 * with no login at all - exactly like Ai1WM's secret_key.
	 *
	 * Falls back to the normal admin check when no secret is sent, so
	 * freshly loaded pages (which have a nonce but no secret yet) work.
	 *
	 * @param string $state_key Job state key, e.g. UWP_Import::STATE_OPTION.
	 * @return array|null Current job state (null when none exists).
	 */
	public static function check_job_request( $state_key ) {
		$secret = isset( $_REQUEST['job_secret'] ) ? (string) $_REQUEST['job_secret'] : ''; // phpcs:ignore
		if ( '' !== $secret ) {
			$state = self::job_load( $state_key );
			if ( is_array( $state ) && isset( $state['secret'] ) && is_string( $state['secret'] ) && hash_equals( $state['secret'], $secret ) ) {
				return $state;
			}
			wp_send_json_error(
				array( 'message' => __( 'Invalid job token. Reload the page and resume with your login.', 'unboundwp' ) ),
				403
			);
		}
		if ( ! self::has_login_cookie() ) {
			// Definitely a guest: answer 403 directly WITHOUT touching
			// auth APIs (current_user_can and friends can fatal on sites
			// whose auth filters assume a user exists).
			self::json_403( __( 'You are not allowed to do this. Log in and try again.', 'unboundwp' ) );
		}
		self::check_request();
		return self::job_load( $state_key );
	}

	/**
	 * Authorize a management action on a job (cancel): job secret OR admin
	 * login. Unlike ticks, an invalid secret falls back to login instead of
	 * failing - cancelling must always be possible for the site owner, even
	 * with a stale tab and a dead session.
	 *
	 * @param string $state_key Job state key.
	 * @return array|null Current job state (null when none exists).
	 */
	public static function check_job_or_admin( $state_key ) {
		$secret = isset( $_REQUEST['job_secret'] ) ? (string) $_REQUEST['job_secret'] : ''; // phpcs:ignore
		if ( '' !== $secret ) {
			$state = self::job_load( $state_key );
			if ( is_array( $state ) && isset( $state['secret'] ) && is_string( $state['secret'] ) && hash_equals( $state['secret'], $secret ) ) {
				return $state;
			}
		}
		self::check_request();
		return self::job_load( $state_key );
	}

	/**
	 * Whether the request carries a WordPress login cookie (of any age).
	 *
	 * Pure header inspection - deliberately touches NO auth APIs, so it is
	 * safe for logged-out requests even when site code mishandles guests.
	 *
	 * @return bool
	 */
	public static function has_login_cookie() {
		if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
			return false;
		}
		foreach ( $_COOKIE as $name => $value ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			if ( false !== strpos( $name, 'wordpress_logged_in_' ) || false !== strpos( $name, 'wordpress_sec_' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Minimal 403 JSON responder that touches no auth APIs.
	 *
	 * Used for definitely-guest requests (no secret, no login cookie):
	 * current_user_can() and friends can fatal on sites whose auth
	 * filters assume a user exists. Never returns.
	 *
	 * @param string $message Error message.
	 */
	public static function json_403( $message ) {
		status_header( 403 );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode(
			array(
				'success' => false,
				'data'    => array( 'message' => $message ),
			)
		);
		die();
	}
	public static function new_job_secret() {
		return bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Effective PHP memory limit in bytes (0 = unlimited/unknown).
	 *
	 * @return int
	 */
	public static function memory_limit_bytes() {
		$raw = ini_get( 'memory_limit' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return 0;
		}
		$raw = trim( $raw );
		if ( '-1' === $raw ) {
			return 0;
		}
		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (int) $raw;
		switch ( $unit ) {
			case 'g':
				$value *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$value *= 1024 * 1024;
				break;
			case 'k':
				$value *= 1024;
				break;
		}
		return max( 0, $value );
	}

	/**
	 * True when PHP memory is running hot (>70% of the limit).
	 *
	 * Loops check this and break to a tick boundary instead of dying to
	 * an out-of-memory fatal halfway through a batch. Pure function of
	 * runtime state - safe to call anywhere hot.
	 *
	 * @return bool
	 */
	public static function memory_hot() {
		$limit = self::memory_limit_bytes();
		if ( $limit <= 0 ) {
			return false;
		}
		return memory_get_usage( true ) > (int) ( $limit * 0.7 );
	}

	/**
	 * Non-blocking exclusive lock for a job (flock).
	 *
	 * The browser AND server-side loopback chains can both trigger ticks;
	 * the lock guarantees only one worker runs at a time. A second worker
	 * gets false and must just report progress, never do work. The OS
	 * releases the lock if PHP dies, so fatals can never wedge the job.
	 *
	 * @param string $key Job key.
	 * @return resource|false Lock handle (falsy when busy). Keep it open
	 *                        while working; fclose() to release.
	 */
	public static function job_lock( $key ) {
		$path = trailingslashit( uwp_temp_dir() ) . 'lock-' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $key ) . '.lock';
		$dir  = dirname( $path );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$handle = @fopen( $path, 'c+b' ); // phpcs:ignore
		if ( false === $handle ) {
			return false;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return false;
		}
		return $handle;
	}

	/**
	 * Fire a non-blocking loopback so the NEXT work slice runs
	 * server-to-server (Ai1WM's chaining pattern).
	 *
	 * Why: the browser connection may be flaky (stale keep-alive, mDNS,
	 * proxies) while the server can still reach itself fine. Chaining
	 * means a run completes even if every later browser request dies,
	 * because the browser only needs to poll lightweight status. Best-effort:
	 * returns false when loopbacks are blocked, and the browser simply
	 * keeps driving ticks itself.
	 *
	 * @param string $action admin-ajax action, e.g. 'uwp_import_tick'.
	 * @param string $secret Job secret for authentication (no cookies here).
	 * @return bool True when the loopback was handed off.
	 */
	public static function chain_self( $action, $secret ) {
		if ( ! function_exists( 'wp_remote_post' ) || '' === $secret ) {
			return false;
		}
		$url = admin_url( 'admin-ajax.php' );
		// Low connect timeout so blocked loopbacks fail fast instead of
		// stalling the worker that fired them.
		$result = wp_remote_post(
			$url,
			array(
				'method'   => 'POST',
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array(),
				'body'     => array(
					'action'     => $action,
					'job_secret' => $secret,
					'nonce'      => '',
				),
				'cookies'  => array(),
			)
		);
		if ( is_wp_error( $result ) ) {
			UWP_Helpers::debug_log( sprintf( 'chain %s failed: %s', $action, $result->get_error_message() ) );
			return false;
		}
		return true;
	}

	/**
	 * Completion receipt path (reported once via status, then consumed).
	 *
	 * Ticks driven by loopback chains finish with no browser waiting, so
	 * completion is recorded here for the next status poll to pick up.
	 *
	 * @param string $key Job key.
	 * @return string
	 */
	public static function job_done_path( $key ) {
		return trailingslashit( uwp_temp_dir() ) . 'done-' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $key ) . '.json';
	}

	/**
	 * Record job completion for later status pickup.
	 *
	 * @param string $key Job key.
	 * @param array  $report Summary (message, file, size...).
	 */
	public static function job_write_done( $key, $report ) {
		$report['when'] = time();
		@file_put_contents( self::job_done_path( $key ), wp_json_encode( $report ), LOCK_EX ); // phpcs:ignore
	}

	/**
	 * Take (read + delete) a completion receipt, if present.
	 *
	 * @param string $key Job key.
	 * @return array|null
	 */
	public static function job_take_done( $key ) {
		$path = self::job_done_path( $key );
		if ( ! is_file( $path ) ) {
			return null;
		}
		$raw  = file_get_contents( $path ); // phpcs:ignore
		@unlink( $path ); // phpcs:ignore
		$data = $raw ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Clear a completion receipt (cancel / fresh start).
	 *
	 * @param string $key Job key.
	 */
	public static function job_clear_done( $key ) {
		@unlink( self::job_done_path( $key ) ); // phpcs:ignore
	}

	/**
	 * Current time budget helper: have we spent too long on this tick?
	 *
	 * @param float $started Microtime when the tick started.
	 * @return bool True when we should stop and let the browser call us again.
	 */
	public static function out_of_time( $started ) {
		return ( microtime( true ) - $started ) >= UWP_AJAX_TIME_BUDGET;
	}

	/**
	 * Null-safe count(): returns 0 for null / non-countable instead of
	 * throwing a TypeError on PHP 8+. Use everywhere a persisted job-state
	 * value is counted - resumed or forward-compat states may hold null.
	 *
	 * @param mixed $value Anything.
	 * @return int
	 */
	public static function safe_count( $value ) {
		return is_countable( $value ) ? count( $value ) : 0;
	}

	/**
	 * Free bytes on the filesystem holding $path, or false when unknowable.
	 *
	 * Pure stat call - safe anywhere, including shutdown/error contexts.
	 *
	 * @param string $path Any path on the target filesystem.
	 * @return int|false Free bytes or false.
	 */
	public static function disk_free_bytes( $path ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return false;
		}
		$free = @disk_free_space( $path ); // phpcs:ignore
		if ( false === $free ) {
			return false;
		}
		return (int) $free;
	}

	/**
	 * Human-readable bytes, e.g. "2.4 MB".
	 *
	 * @param int $bytes Byte count.
	 * @return string
	 */
	public static function format_bytes( $bytes ) {
		$bytes = max( 0, (int) $bytes );
		if ( $bytes < 1024 ) {
			/* translators: %d = bytes */
			return sprintf( __( '%d B', 'unboundwp' ), $bytes );
		}
		$units = array( 'KB', 'MB', 'GB', 'TB' );
		$exp   = (int) floor( log( $bytes ) / log( 1024 ) );
		$exp   = min( $exp, count( $units ) );
		$value = $bytes / pow( 1024, $exp );
		return sprintf( '%s %s', number_format_i18n( $value, 1 ), $units[ $exp - 1 ] );
	}

	/**
	 * Safe backup filename: {site}-{Y-m-d-His}-{rand}.uwp
	 *
	 * @return string
	 */
	public static function backup_filename() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_file_name( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : 'site'; // phpcs:ignore
		$host = preg_replace( '/[^a-z0-9-]+/i', '-', strtolower( $host ) );
		$host = trim( $host, '-' );
		if ( '' === $host ) {
			$host = 'site';
		}
		return sprintf(
			'%s-%s-%s.uwp',
			$host,
			gmdate( 'Y-m-d-His' ),
			substr( wp_generate_password( 8, false, false ), 0, 6 )
		);
	}

	/**
	 * Is this an archive we can import? (.uwp or .wpress, case-insensitive)
	 *
	 * @param string $filename File name or path.
	 * @return bool
	 */
	public static function is_supported_archive( $filename ) {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		return in_array( $ext, array( UWP_EXTENSION, UWP_WPRESS_EXTENSION ), true );
	}

	/**
	 * Block path-traversal: archive entry must stay inside $root.
	 *
	 * @param string $root Absolute root with trailing slash.
	 * @param string $relative Archive-relative path with forward slashes.
	 * @return string|false Absolute destination or false when unsafe.
	 */
	public static function safe_join( $root, $relative ) {
		$relative = str_replace( '\\', '/', $relative );
		$relative = ltrim( $relative, '/' );

		$parts = array();
		foreach ( explode( '/', $relative ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return false;
			}
			$parts[] = $part;
		}

		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$full = $root . '/' . implode( '/', $parts );

		// Real check: normalized full path must start with root.
		if ( 0 !== strpos( $full, $root . '/' ) && $full !== $root ) {
			return false;
		}
		return $full;
	}

	/**
	 * File-based job state. Export/import/verify progress lives in small
	 * JSON files under the temp dir - deliberately NOT in wp_options,
	 * because restoring a database replaces the options table and would
	 * otherwise wipe a running job's state mid-restore.
	 */

	/**
	 * Absolute path of a job state file.
	 *
	 * @param string $key Job key, e.g. 'uwp_import_state'.
	 * @return string
	 */
	public static function job_file( $key ) {
		$key = preg_replace( '/[^a-z0-9_-]/i', '', (string) $key );
		return trailingslashit( uwp_temp_dir() ) . 'state-' . $key . '.json';
	}

	/**
	 * Persist job state atomically.
	 *
	 * @param string $key Job key.
	 * @param array  $state Job state.
	 */
	public static function job_save( $key, $state ) {
		$file = self::job_file( $key );
		$dir  = dirname( $file );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		file_put_contents( $file, wp_json_encode( $state ), LOCK_EX ); // phpcs:ignore
	}

	/**
	 * Load job state.
	 *
	 * @param string $key Job key.
	 * @return array|null
	 */
	public static function job_load( $key ) {
		$file = self::job_file( $key );
		if ( ! is_file( $file ) ) {
			return null;
		}
		$raw  = file_get_contents( $file ); // phpcs:ignore
		$data = $raw ? json_decode( $raw, true ) : null;
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Delete job state.
	 *
	 * @param string $key Job key.
	 */
	public static function job_clear( $key ) {
		@unlink( self::job_file( $key ) ); // phpcs:ignore
	}

	/**
	 * Append one line to the shared debug log.
	 *
	 * When a tick dies (PHP fatal, OOM kill, server timeout) the browser
	 * only sees "connection lost" - this log is what turns the next such
	 * failure from a mystery into a one-line root cause. Kept small with
	 * a single rotated generation.
	 *
	 * Per-job mirroring: lines shaped like `import <job-id>: ...` (and the
	 * export/verify equivalents) are ALSO appended to that job's own full
	 * log file - one shared story plus one complete file per job, with no
	 * extra work at any of the dozens of call sites.
	 *
	 * @param string $message What happened.
	 */
	public static function debug_log( $message ) {
		$dir = uwp_temp_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$file = trailingslashit( $dir ) . 'uwp-debug.log';
		if ( is_file( $file ) && filesize( $file ) > 512000 ) {
			@rename( $file, $file . '.1' ); // phpcs:ignore
		}
		$line = gmdate( 'Y-m-d H:i:s' ) . ' ' . $message . "\n";
		@file_put_contents( $file, $line, FILE_APPEND ); // phpcs:ignore
		// Mirror to the job's own log when the line carries a job id.
		if ( class_exists( 'UWP_Logger' )
			&& preg_match( '/^(import|export|verify)\s+([A-Za-z0-9_-]{4,80}):/', $message, $m ) ) {
			UWP_Logger::append( $m[2], $line );
		}
	}

	/**
	 * Last N lines of the debug log for attaching to error responses.
	 *
	 * Pure file read - safe to call from error handlers.
	 *
	 * @param int $lines How many lines.
	 * @return string Possibly empty.
	 */
	public static function read_log_tail( $lines = 15 ) {
		$file = trailingslashit( uwp_temp_dir() ) . 'uwp-debug.log';
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return '';
		}
		$raw = file_get_contents( $file ); // phpcs:ignore
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}
		$all = explode( "\n", rtrim( $raw ) );
		return implode( "\n", array_slice( $all, max( 0, count( $all ) - (int) $lines ) ) );
	}

	/**
	 * Register a shutdown handler that logs PHP fatals.
	 *
	 * JSON endpoints die silently on fatal errors (the browser just sees
	 * the connection drop). This records the actual error for post-mortem
	 * AND pins it to a tiny sidecar file, so the next tick picks it up and
	 * shows the real cause in the UI instead of "something went wrong".
	 * The sidecar (not the full state) is deliberate: after an
	 * out-of-memory death there may not be enough headroom left to
	 * re-encode a whole job state, but a 200-byte write succeeds.
	 *
	 * @param string      $context Short label, e.g. 'import-tick'.
	 * @param string|null $state_key Job state key to pin the fatal to.
	 */
	public static function capture_fatals( $context, $state_key = null ) {
		register_shutdown_function(
			function () use ( $context, $state_key ) {
				$err = error_get_last();
				if ( ! is_array( $err ) ) {
					return;
				}
				$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
				if ( ! in_array( $err['type'], $fatal, true ) ) {
					return;
				}
				$message = sprintf(
					'FATAL in %s: %s in %s on line %d (peak memory %d bytes)',
					$context,
					$err['message'],
					$err['file'],
					$err['line'],
					memory_get_peak_usage( true )
				);
				UWP_Helpers::debug_log( $message );
				if ( null !== $state_key ) {
					UWP_Helpers::job_write_fatal( $state_key, $message );
				}
			}
		);
	}

	/**
	 * Sidecar path for a pinned fatal.
	 *
	 * @param string $key Job key.
	 * @return string
	 */
	public static function job_fatal_path( $key ) {
		return trailingslashit( uwp_temp_dir() ) . 'fatal-' . preg_replace( '/[^a-z0-9_-]/i', '', (string) $key ) . '.txt';
	}

	/**
	 * Pin a fatal message (tiny write, safe under memory pressure).
	 *
	 * @param string $key Job key.
	 * @param string $message Fatal message.
	 */
	public static function job_write_fatal( $key, $message ) {
		$dir = uwp_temp_dir();
		if ( ! file_exists( $dir ) ) {
			return;
		}
		@file_put_contents( self::job_fatal_path( $key ), substr( (string) $message, 0, 2000 ), LOCK_EX ); // phpcs:ignore
	}

	/**
	 * Take (read + delete) a pinned fatal, if present.
	 *
	 * @param string $key Job key.
	 * @return string|null
	 */
	public static function job_take_fatal( $key ) {
		$path = self::job_fatal_path( $key );
		if ( ! is_file( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path ); // phpcs:ignore
		@unlink( $path ); // phpcs:ignore
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}
		return trim( $raw );
	}

	/**
	 * Remove a stale UnboundWP maintenance marker, if no restore is running.
	 *
	 * Background: WordPress answers EVERYTHING (including admin-ajax) with
	 * HTTP 503 while ANY fresh .maintenance file exists, so a restore must
	 * never create one. This only cleans up markers left by older versions,
	 * and never touches core updates' files.
	 */
	public static function maintenance_sweep() {
		if ( null !== self::job_load( 'uwp_import_state' ) ) {
			return; // a restore is (or may be) running - leave it alone.
		}
		$file = ABSPATH . '.maintenance';
		if ( ! is_file( $file ) ) {
			return;
		}
		$raw = file_get_contents( $file ); // phpcs:ignore
		if ( false !== $raw && false !== strpos( $raw, 'UnboundWP' ) ) {
			@unlink( $file ); // phpcs:ignore
		}
	}

	/**
	 * WordPress core files allowed at the archive root (besides wp-admin/
	 * and wp-includes/ trees). wp-config.php is NEVER included: the live
	 * one always wins. Everything else at ABSPATH root is left out.
	 *
	 * @return array Basenames.
	 */
	public static function core_root_files() {
		return array(
			'index.php',
			'license.txt',
			'readme.html',
			'wp-activate.php',
			'wp-blog-header.php',
			'wp-comments-post.php',
			'wp-config-sample.php',
			'wp-cron.php',
			'wp-links-opml.php',
			'wp-load.php',
			'wp-login.php',
			'wp-mail.php',
			'wp-settings.php',
			'wp-signup.php',
			'wp-trackback.php',
			'xmlrpc.php',
		);
	}

	/**
	 * True only for regular files that are safe to open and read.
	 *
	 * is_file() alone is NOT enough: FIFOs (named pipes), sockets and
	 * devices also pass it, and opening one for reading BLOCKS FOREVER
	 * waiting for a writer that never comes - hanging the worker until
	 * the server kills it. filetype() distinguishes them cheaply.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_regular_file( $path ) {
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return false;
		}
		clearstatcache( true, $path );
		return 'file' === @filetype( $path ); // phpcs:ignore
	}

	/**
	 * Recursively delete a directory (used for staging cleanup).
	 *
	 * @param string $dir Absolute path.
	 */
	public static function rrmdir( $dir ) {
		if ( ! file_exists( $dir ) ) {
			return;
		}
		if ( ! is_dir( $dir ) ) {
			@unlink( $dir ); // phpcs:ignore
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			self::rrmdir( $dir . DIRECTORY_SEPARATOR . $item );
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	/**
	 * Move a work directory aside for background cleanup (instant).
	 *
	 * Deleting thousands of staged files synchronously inside cancel /
	 * finalize requests can exceed server time limits - the "can't even
	 * discard" failure. A rename is atomic, so cleanup requests return
	 * immediately and empty_trash() grinds through leftovers on later
	 * requests, a few seconds at a time.
	 *
	 * @param string $dir Absolute directory to discard.
	 */
	public static function trash_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		// random_bytes (PHP 7+, no WordPress needed) so this works in any
		// context, including uninstall and shutdown handlers.
		try {
			$suffix = bin2hex( random_bytes( 3 ) );
		} catch ( Exception $e ) {
			$suffix = substr( md5( uniqid( '', true ) ), 0, 6 );
		}
		$trash = trailingslashit( uwp_temp_dir() ) . 'trash-' . basename( rtrim( str_replace( '\\', '/', $dir ), '/' ) ) . '-' . $suffix;
		if ( ! @rename( $dir, $trash ) ) { // phpcs:ignore
			UWP_Helpers::debug_log( sprintf( 'trash: could not move %s aside', $dir ) );
		}
	}

	/**
	 * Delete trashed directories, bounded by a time budget.
	 *
	 * Unfinished work resumes on the next call (deleted files stay
	 * deleted; the rest is re-walked). Safe to run on every init.
	 *
	 * @param float $budget_seconds Max seconds to spend.
	 */
	public static function empty_trash( $budget_seconds = 3 ) {
		$tmp = uwp_temp_dir();
		if ( ! is_dir( $tmp ) ) {
			return;
		}
		$deadline = microtime( true ) + max( 1, (float) $budget_seconds );
		$handle   = @opendir( $tmp ); // phpcs:ignore
		if ( false === $handle ) {
			return;
		}
		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( 0 !== strpos( $entry, 'trash-' ) ) {
				continue;
			}
			self::delete_budgeted( $tmp . DIRECTORY_SEPARATOR . $entry, $deadline );
			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
		closedir( $handle );
	}

	/**
	 * Delete a tree, stopping at the deadline (resumable next call).
	 *
	 * @param string $dir Absolute directory.
	 * @param float  $deadline Microtime deadline.
	 * @return bool True when fully gone.
	 */
	public static function delete_budgeted( $dir, $deadline ) {
		if ( ! file_exists( $dir ) ) {
			return true;
		}
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return (bool) @unlink( $dir ); // phpcs:ignore
		}
		// Depth-first so directories are empty when removed.
		$stack = array( $dir );
		$order = array();
		while ( ! empty( $stack ) ) {
			$current = array_pop( $stack );
			$order[] = $current;
			$handle  = @opendir( $current ); // phpcs:ignore
			if ( false === $handle ) {
				continue;
			}
			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$full = $current . DIRECTORY_SEPARATOR . $entry;
				if ( is_dir( $full ) && ! is_link( $full ) ) {
					$stack[] = $full;
				} else {
					@unlink( $full ); // phpcs:ignore
				}
				if ( microtime( true ) > $deadline ) {
					break 2;
				}
			}
			closedir( $handle );
		}
		// Remove collected directories deepest-first.
		foreach ( array_reverse( $order ) as $d ) {
			@rmdir( $d ); // phpcs:ignore
			if ( microtime( true ) > $deadline ) {
				break;
			}
		}
		clearstatcache();
		return ! file_exists( $dir );
	}

	/**
	 * List backup files (newest first).
	 *
	 * @return array Each item: name, path, size, mtime.
	 */
	public static function list_backups() {
		$dir   = uwp_backups_dir();
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}
		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return $files;
		}
		foreach ( $entries as $entry ) {
			if ( ! self::is_supported_archive( $entry ) ) {
				continue;
			}
			$path = $dir . $entry;
			if ( ! is_file( $path ) ) {
				continue;
			}
			$files[] = array(
				'name'  => $entry,
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
}
