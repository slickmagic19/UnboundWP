<?php
/**
 * Read-only inspection of an archive's package.json + structure.
 *
 * Usage: php tests/peek-archive.php <archive.uwp>
 *
 * @package UnboundWP
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/srv/site/' );
define( 'WP_CONTENT_DIR', '/srv/site/wp-content' );
define( 'WP_PLUGIN_DIR', '/srv/site/wp-content/plugins' );
function __( $t, $d = null ) { return $t; } // phpcs:ignore
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } // phpcs:ignore
function wp_mkdir_p( $d ) { return is_dir( $d ) || @mkdir( $d, 0777, true ); } // phpcs:ignore
function wp_normalize_path( $p ) { return preg_replace( '|(?<=.)/+|', '/', str_replace( '\\', '/', $p ) ); } // phpcs:ignore
function trailingslashit( $p ) { return rtrim( $p, '/\\' ) . '/'; } // phpcs:ignore
function size_format( $b, $d = 0 ) { return $b . ' B'; } // phpcs:ignore
function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._-]/', '', $n ); } // phpcs:ignore
function apply_filters( $h, $v ) { return $v; } // phpcs:ignore
function is_wp_error( $t ) { return false; } // phpcs:ignore
function wp_upload_dir() { return array( 'basedir' => '/srv/site/wp-content/uploads' ); } // phpcs:ignore

class W {
	public $prefix = 'wp_';
	public $last_error = '';
}
$GLOBALS['wpdb'] = new W();

require __DIR__ . '/../unboundwp/includes/class-uwp-helpers.php';
require __DIR__ . '/../unboundwp/includes/class-uwp-logger.php';
require __DIR__ . '/../unboundwp/includes/class-uwp-archive.php';

$file = isset( $argv[1] ) ? $argv[1] : '';
if ( '' === $file || ! is_file( $file ) ) {
	fwrite( STDERR, "Usage: php tests/peek-archive.php <archive>\n" );
	exit( 1 );
}

echo 'size: ' . size_format( filesize( $file ) ) . "\n";

$raw = UWP_Archive::read_small_entry( $file, 'package.json' );
$pkg = $raw ? json_decode( $raw, true ) : null;
echo 'package.json: ' . ( is_array( $pkg ) ? 'yes' : 'no' ) . "\n";
if ( is_array( $pkg ) ) {
	foreach ( array( 'SiteURL', 'HomeURL', 'ABSPATH', 'ContentDir', 'Encrypted', 'Compression', 'FileCount', 'Multisite', 'Created' ) as $k ) {
		echo $k . ': ' . json_encode( isset( $pkg[ $k ] ) ? $pkg[ $k ] : null ) . "\n";
	}
	echo 'Plugin: ' . json_encode( isset( $pkg['Plugin'] ) ? $pkg['Plugin'] : null ) . "\n";
	echo 'Database: ' . json_encode( isset( $pkg['Database'] ) ? $pkg['Database'] : null ) . "\n";
	echo 'WordPress: ' . json_encode( isset( $pkg['WordPress'] ) ? $pkg['WordPress'] : null ) . "\n";
	echo 'ActivePlugins: ' . json_encode( isset( $pkg['ActivePlugins'] ) ? $pkg['ActivePlugins'] : null ) . "\n";
	echo 'ActiveTheme: ' . json_encode( isset( $pkg['ActiveTheme'] ) ? $pkg['ActiveTheme'] : null ) . "\n";
	echo 'Fidelity: ' . json_encode( isset( $pkg['Fidelity'] ) ? $pkg['Fidelity'] : null ) . "\n";
}

$scan = UWP_Archive::scan( $file );
echo 'scan: files=' . $scan['files'] . ' bytes=' . $scan['bytes'] . "\n";
