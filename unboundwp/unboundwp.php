<?php
/**
 * Plugin Name:       UnboundWP - Free Migration & Backup
 * Plugin URI:        https://github.com/slickmagic19/UnboundWP
 * Description:       Free and open-source site export, import, backup and 1-click restore. No size limits, no paywalls. Reads and writes .uwp files (fully .wpress compatible).
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            UnboundWP Contributors
 * Author URI:        https://github.com/slickmagic19/UnboundWP
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       unboundwp
 * Domain Path:       /languages
 *
 * UnboundWP is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constants (guarded: the MU loader may have defined a few of these first
// when it ran earlier in the same request - redefining warns and, with
// strict error display, corrupts ajax JSON responses).
// ---------------------------------------------------------------------------

defined( 'UWP_VERSION' ) || define( 'UWP_VERSION', '1.0.1' );
defined( 'UWP_PLUGIN_FILE' ) || define( 'UWP_PLUGIN_FILE', __FILE__ );
defined( 'UWP_PLUGIN_DIR' ) || define( 'UWP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'UWP_PLUGIN_URL' ) || define( 'UWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
defined( 'UWP_PLUGIN_BASENAME' ) || define( 'UWP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Archive format constants. .uwp IS the .wpress format (4377-byte headers)
// so the two extensions are interchangeable - rename works both ways.
defined( 'UWP_EXTENSION' ) || define( 'UWP_EXTENSION', 'uwp' );
defined( 'UWP_WPRESS_EXTENSION' ) || define( 'UWP_WPRESS_EXTENSION', 'wpress' );
defined( 'UWP_HEADER_SIZE' ) || define( 'UWP_HEADER_SIZE', 4377 );
defined( 'UWP_CHUNK_BYTES' ) || define( 'UWP_CHUNK_BYTES', 512000 ); // 500 KB streaming chunks (never load whole files).
defined( 'UWP_AJAX_TIME_BUDGET' ) || define( 'UWP_AJAX_TIME_BUDGET', 8 ); // seconds of work per AJAX tick (timeout-safe).
defined( 'UWP_UPLOAD_CHUNK' ) || define( 'UWP_UPLOAD_CHUNK', 2097152 ); // 2 MB browser upload slices (no size limit).

// ---------------------------------------------------------------------------
// Includes (small, single-purpose, human-readable classes)
// ---------------------------------------------------------------------------
// class_exists guards: the MU resurrection loader may have already loaded
// these same files earlier in this request via a differently-resolved path
// (symlinks, case differences on Windows). require_once alone dedupes only
// identical paths; the guards make double-loading impossible regardless.

if ( ! class_exists( 'UWP_Helpers' ) ) {
	require_once UWP_PLUGIN_DIR . 'includes/class-uwp-helpers.php'; // phpcs:ignore
}
if ( ! class_exists( 'UWP_Logger' ) ) {
	require_once UWP_PLUGIN_DIR . 'includes/class-uwp-logger.php'; // phpcs:ignore
}
if ( ! class_exists( 'UWP_Archive' ) ) {
	require_once UWP_PLUGIN_DIR . 'includes/class-uwp-archive.php'; // phpcs:ignore
}
if ( ! class_exists( 'UWP_Database' ) ) {
	require_once UWP_PLUGIN_DIR . 'includes/class-uwp-database.php'; // phpcs:ignore
}
if ( ! class_exists( 'UWP_Export' ) ) {
	require_once UWP_PLUGIN_DIR . 'includes/class-uwp-export.php'; // phpcs:ignore
}
if ( ! class_exists( 'UWP_Import' ) ) {
	require_once UWP_PLUGIN_DIR . 'includes/class-uwp-import.php'; // phpcs:ignore
}
if ( ! class_exists( 'UWP_Verify' ) ) {
	require_once UWP_PLUGIN_DIR . 'includes/class-uwp-verify.php'; // phpcs:ignore
}
if ( ! class_exists( 'UWP_Diag' ) ) {
	require_once UWP_PLUGIN_DIR . 'includes/class-uwp-diag.php'; // phpcs:ignore
}
if ( ! class_exists( 'UWP_Ajax' ) ) {
	require_once UWP_PLUGIN_DIR . 'includes/class-uwp-ajax.php'; // phpcs:ignore
}
if ( ! class_exists( 'UWP_Admin' ) ) {
	require_once UWP_PLUGIN_DIR . 'includes/class-uwp-admin.php'; // phpcs:ignore
}
if ( ! class_exists( 'UWP_CLI' ) ) {
	require_once UWP_PLUGIN_DIR . 'includes/class-uwp-cli.php'; // phpcs:ignore
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

/**
 * Get the directory where finished backups live.
 *
 * @return string Absolute path with trailing slash.
 */
if ( ! function_exists( 'uwp_backups_dir' ) ) {
	function uwp_backups_dir() {
		$dir = wp_upload_dir();
		$path = trailingslashit( $dir['basedir'] ) . 'unboundwp-backups/';
		return $path;
	}
}

/**
 * Get the directory for in-progress temp work (staging, uploads, state).
 *
 * @return string Absolute path with trailing slash.
 */
if ( ! function_exists( 'uwp_temp_dir' ) ) {
	function uwp_temp_dir() {
		$dir  = wp_upload_dir();
		$path = trailingslashit( $dir['basedir'] ) . 'unboundwp-temp/';
		return $path;
	}
}

/**
 * Make sure our working directories exist and are protected.
 *
 * Everything here is served through PHP (never linked directly), so both
 * directories deny all web access: they hold job state files (which carry
 * job secrets), partial uploads, and debug logs. Rewritten on every load
 * so upgrades tighten existing installs too.
 */
if ( ! function_exists( 'uwp_ensure_directories' ) ) {
	function uwp_ensure_directories() {
		foreach ( array( uwp_backups_dir(), uwp_temp_dir() ) as $dir ) {
			if ( ! file_exists( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			// Prevent directory listing / direct execution.
			$index = $dir . 'index.php';
			if ( ! file_exists( $index ) ) {
				file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore
			}
			// Deny everything over HTTP. Note: only Apache honors this; on
			// nginx the files are still reachable if guessed, which is why
			// job secrets are long random tokens and never appear in URLs.
			file_put_contents( $dir . '.htaccess', "Require all denied\n" ); // phpcs:ignore
		}
		// Clean up a stale maintenance marker from older versions (which
		// wrongly created one and 503'd the site, including admin-ajax).
		if ( class_exists( 'UWP_Helpers' ) ) {
			UWP_Helpers::maintenance_sweep();
			// Grind through trashed work dirs a few seconds at a time.
			UWP_Helpers::empty_trash( 3 );
		}
	}
}
add_action( 'init', 'uwp_ensure_directories' );

/**
 * Install/update the must-use resurrection loader.
 *
 * The loader keeps job ticks working even when this plugin is inactive
 * (e.g. mid-restore, when the backup's active_plugins list took over).
 * Self-heals on every init so updates propagate; keyed on file hash so it
 * only writes when the template actually changed.
 */
if ( ! function_exists( 'uwp_ensure_mu_loader' ) ) {
	function uwp_ensure_mu_loader() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return;
		}
		$src = UWP_PLUGIN_DIR . 'mu-loader.php';
		$dst = trailingslashit( WPMU_PLUGIN_DIR ) . 'unboundwp-loader.php';
		if ( ! is_file( $src ) ) {
			return;
		}
		$want = sha1_file( $src ); // phpcs:ignore
		$have = is_file( $dst ) ? sha1_file( $dst ) : false; // phpcs:ignore
		if ( $want && $want !== $have ) {
			if ( ! file_exists( WPMU_PLUGIN_DIR ) ) {
				wp_mkdir_p( WPMU_PLUGIN_DIR );
			}
			$ok = @copy( $src, $dst ); // phpcs:ignore
			// Never fail silently: a stale MU loader is exactly what caused
			// the historical "Cannot redeclare uwp_backups_dir()" bootstrap
			// fatals (old unguarded copy + new guarded plugin). If the copy
			// failed (permissions, Windows file lock), say so in the log so
			// diagnostics can name it instead of mystifying the operator.
			if ( ! $ok && class_exists( 'UWP_Helpers' ) ) {
				UWP_Helpers::debug_log( sprintf( 'mu-loader update FAILED: could not copy to %s (check permissions)', $dst ) );
			}
		}
	}
}
add_action( 'init', 'uwp_ensure_mu_loader' );
add_action( 'admin_init', 'uwp_ensure_mu_loader' );

if ( ! function_exists( 'uwp_activate_plugin' ) ) {
	function uwp_activate_plugin() {
		uwp_ensure_directories();
		uwp_ensure_mu_loader();
	}
}
register_activation_hook( __FILE__, 'uwp_activate_plugin' );

/**
 * Load translations.
 */
if ( ! function_exists( 'uwp_load_textdomain' ) ) {
	function uwp_load_textdomain() {
		load_plugin_textdomain( 'unboundwp', false, dirname( UWP_PLUGIN_BASENAME ) . '/languages' );
	}
}
add_action( 'plugins_loaded', 'uwp_load_textdomain' );

// Register admin + ajax hooks.
UWP_Admin::register();
UWP_Ajax::register();

// WP-CLI support (optional, safe when WP-CLI is absent).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	UWP_CLI::register();
}





















