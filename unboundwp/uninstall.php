<?php
/**
 * Uninstall cleanup.
 *
 * Removes: job state, temp working files, the must-use resurrection loader
 * and our own options. KEEPS: finished backups in
 * wp-content/uploads/unboundwp-backups/ - they are the user's data, and
 * deleting them on uninstall would be destructive.
 *
 * @package UnboundWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'uwp_export_state' );
delete_option( 'uwp_import_state' );
delete_option( 'uwp_verify_state' );
delete_option( 'uwp_last_restore' );

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

// Remove temp work (uploads/unboundwp-temp) if it exists. The old code
// checked function_exists( 'WP_Filesystem' ) BEFORE loading file.php, so
// this cleanup never actually ran; use the filesystem API properly.
$uploads = wp_upload_dir();
if ( ! empty( $uploads['basedir'] ) ) {
	$temp = trailingslashit( $uploads['basedir'] ) . 'unboundwp-temp';
	if ( file_exists( $temp ) ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $temp, true, 'd' );
		}
	}
}
