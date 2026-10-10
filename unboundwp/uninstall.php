<?php
/**
 * Uninstall cleanup: remove temp work and saved job state.
 * Finished backups are KEPT (they are the user's data).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'uwp_export_state' );
delete_option( 'uwp_import_state' );

// Remove the must-use resurrection loader (mu-plugins are not removed
// automatically when a plugin is deleted).
if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
	$mu = trailingslashit( WPMU_PLUGIN_DIR ) . 'unboundwp-loader.php';
	if ( is_file( $mu ) ) {
		$raw = file_get_contents( $mu ); // phpcs:ignore
		// Only remove our own file, never a stranger's.
		if ( false !== $raw && false !== strpos( $raw, 'UnboundWP resurrection loader' ) ) {
			@unlink( $mu ); // phpcs:ignore
		}
	}
}

// Remove temp dir (uploads/unboundwp-temp) if it exists.
$uploads = wp_upload_dir();
$temp    = trailingslashit( $uploads['basedir'] ) . 'unboundwp-temp/';
if ( function_exists( 'WP_Filesystem' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->rmrf( $temp );
	}
}
