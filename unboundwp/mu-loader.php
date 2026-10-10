<?php
/**
 * UnboundWP resurrection loader (must-use).
 *
 * Why this exists: restoring a database replaces wp_options mid-run, and
 * the backup's active_plugins list may not include UnboundWP (foreign
 * backups, pre-1.1.0 dumps). From the next request on, WordPress no longer
 * loads this plugin at all - every tick would answer bare `0` forever and
 * no resume could ever work. Must-use plugins always load, so this tiny
 * stub re-registers ONLY the job-continuation endpoints. Everything here
 * still authenticates by per-job secret (see check_job_request); it grants
 * no management powers. If the main plugin files are missing (mid-restore,
 * uninstalled), it silently does nothing.
 *
 * Installed by UnboundWP itself (activation + self-heal on init); removed
 * by uninstall.php. Safe to leave behind: without the main files it is a
 * no-op, and uninstall removes it anyway.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Catch bootstrap fatals (broken active plugin, half-restored core): these
// happen before any plugin code runs, so nothing else can log them. The
// message lands in the same debug log the admin UI points to. Deliberately
// minimal and exception-free - a shutdown logger must never itself fail.
if ( ! function_exists( 'uwp_mu_log_fatal' ) ) {
	function uwp_mu_log_fatal() {
		$err = error_get_last();
		if ( ! is_array( $err ) ) {
			return;
		}
		if ( ! in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}
		if ( ! function_exists( 'wp_upload_dir' ) || ! function_exists( 'trailingslashit' ) ) {
			return;
		}
		$u = wp_upload_dir();
		if ( ! is_array( $u ) || empty( $u['basedir'] ) ) {
			return;
		}
		// Attach whatever job progress exists, so the log shows WHERE the
		// request died (step + offsets), not just the PHP error itself.
		$context = '';
		$files   = glob( trailingslashit( $u['basedir'] ) . 'unboundwp-temp/state-*.json' );
		if ( is_array( $files ) ) {
			$bits = array();
			foreach ( array_slice( $files, 0, 3 ) as $sf ) {
				$raw = @file_get_contents( $sf ); // phpcs:ignore
				$st  = $raw ? json_decode( $raw, true ) : null;
				if ( ! is_array( $st ) ) {
					continue;
				}
				$step = isset( $st['step'] ) ? $st['step'] : '?';
				$off  = isset( $st['extract_offset'] ) ? 'ex=' . $st['extract_offset'] : ( isset( $st['db_state']['offset'] ) ? 'db=' . $st['db_state']['offset'] : ( isset( $st['copy_index'] ) ? 'cp=' . $st['copy_index'] : '' ) );
				$bits[] = $step . '(' . $off . ')';
				// Pin to the job sidecar as well: the next tick surfaces
				// this in the UI even though no tick code ran (bootstrap
				// death). Tiny write, safe under memory pressure.
				if ( preg_match( '/state-(.+)\.json$/', basename( $sf ), $m ) ) {
					$key = preg_replace( '/[^a-z0-9_-]/i', '', $m[1] );
					@file_put_contents( trailingslashit( $u['basedir'] ) . 'unboundwp-temp/fatal-' . $key . '.txt', substr( 'BOOTSTRAP FATAL: ' . $err['message'] . ' in ' . $err['file'] . ' on line ' . $err['line'], 0, 2000 ), LOCK_EX ); // phpcs:ignore
				}
			}
			if ( $bits ) {
				$context = ' [jobs: ' . implode( ',', $bits ) . ']';
			}
		}
		$line = gmdate( 'Y-m-d H:i:s' ) . ' BOOTSTRAP FATAL: ' . $err['message'] . ' in ' . $err['file'] . ' on line ' . $err['line'] . $context . "\n";
		@file_put_contents( trailingslashit( $u['basedir'] ) . 'unboundwp-temp/uwp-debug.log', $line, FILE_APPEND ); // phpcs:ignore
	}
	register_shutdown_function( 'uwp_mu_log_fatal' );
}

// Zero cost for normal requests: only our four tick/status actions proceed.
$uwp_mu_action = isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : ''; // phpcs:ignore
$uwp_mu_ticks  = array( 'uwp_export_tick', 'uwp_import_tick', 'uwp_backup_verify_tick', 'uwp_job_status' );
if ( ! in_array( $uwp_mu_action, $uwp_mu_ticks, true ) ) {
	unset( $uwp_mu_action, $uwp_mu_ticks );
	return;
}

// Fallbacks the main plugin file normally defines (absent when inactive).
// Guarded: the main file may load after us and defines the same names.
if ( ! defined( 'UWP_PLUGIN_BASENAME' ) ) {
	define( 'UWP_PLUGIN_BASENAME', 'unboundwp/unboundwp.php' );
}
if ( ! defined( 'UWP_VERSION' ) ) {
	// Read the real version from the main file header so asset URLs and
	// package.json stay correct even when only this loader runs.
	$uwp_mu_version = '0.0.0+mu';
	$uwp_mu_main    = ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins' ) . '/unboundwp/unboundwp.php';
	if ( is_file( $uwp_mu_main ) ) {
		$uwp_mu_head = @file_get_contents( $uwp_mu_main, false, null, 0, 2048 ); // phpcs:ignore
		if ( is_string( $uwp_mu_head ) && preg_match( '/Version:\s*(\S+)/', $uwp_mu_head, $uwp_mu_m ) ) {
			$uwp_mu_version = $uwp_mu_m[1];
		}
	}
	define( 'UWP_VERSION', $uwp_mu_version );
	unset( $uwp_mu_version, $uwp_mu_main, $uwp_mu_head, $uwp_mu_m );
}
if ( ! function_exists( 'uwp_backups_dir' ) ) {
	function uwp_backups_dir() {
		$u = wp_upload_dir();
		return trailingslashit( $u['basedir'] ) . 'unboundwp-backups/';
	}
}
if ( ! function_exists( 'uwp_temp_dir' ) ) {
	function uwp_temp_dir() {
		$u = wp_upload_dir();
		return trailingslashit( $u['basedir'] ) . 'unboundwp-temp/';
	}
}
// Loader version marker: bump whenever this template changes so the main
// plugin (and diagnostics) can detect a stale installed copy. A stale copy
// is what caused the historical "Cannot redeclare uwp_backups_dir()"
// bootstrap fatals - the self-healer keys on file hash, this is the belt.
if ( ! defined( 'UWP_MU_LOADER_VERSION' ) ) {
	define( 'UWP_MU_LOADER_VERSION', '1.0.0' );
}
// NOTE: no uwp_ensure_directories() stub here on purpose - with both sides
// guarded, the first definition wins, and the no-op would shadow the real
// one that creates/protects the directories. Ticks never call it.

$uwp_mu_base = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';
$uwp_mu_dir  = $uwp_mu_base . '/unboundwp';
// Map files to their classes: skip what the main plugin already loaded
// (differently-resolved paths defeat require_once dedupe on some setups).
$uwp_mu_map = array(
	'class-uwp-helpers.php'  => 'UWP_Helpers',
	'class-uwp-logger.php'   => 'UWP_Logger',
	'class-uwp-archive.php'  => 'UWP_Archive',
	'class-uwp-database.php' => 'UWP_Database',
	'class-uwp-export.php'   => 'UWP_Export',
	'class-uwp-import.php'   => 'UWP_Import',
	'class-uwp-verify.php'   => 'UWP_Verify',
	'class-uwp-ajax.php'     => 'UWP_Ajax',
);
foreach ( $uwp_mu_map as $uwp_mu_file => $uwp_mu_class ) {
	if ( class_exists( $uwp_mu_class ) ) {
		continue;
	}
	$uwp_mu_path = $uwp_mu_dir . '/includes/' . $uwp_mu_file;
	if ( is_file( $uwp_mu_path ) ) {
		require_once $uwp_mu_path;
	}
}
unset( $uwp_mu_map );

if ( class_exists( 'UWP_Ajax' ) && class_exists( 'UWP_Helpers' ) ) {
	foreach ( UWP_Ajax::NOPRIV_ACTIONS as $uwp_mu_hook ) {
		add_action( 'wp_ajax_nopriv_' . $uwp_mu_hook, array( 'UWP_Ajax', 'dispatch' ) );
	}
}

unset( $uwp_mu_action, $uwp_mu_ticks, $uwp_mu_base, $uwp_mu_dir, $uwp_mu_file, $uwp_mu_path, $uwp_mu_hook, $uwp_mu_class );
