<?php
/**
 * Pre-flight scan: Windows-invalid names, very long paths, duplicates.
 *
 * Usage: php tests/scan-archive.php <archive> [stage-prefix-length]
 *
 * @package UnboundWP
 */

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

$file   = isset( $argv[1] ) ? $argv[1] : '';
$prefix = isset( $argv[2] ) ? (int) $argv[2] : 150; // typical stage dir length.
if ( '' === $file || ! is_file( $file ) ) {
	fwrite( STDERR, "Usage: php tests/scan-archive.php <archive> [stage-prefix-length]\n" );
	exit( 1 );
}

$reserved = array( 'CON', 'PRN', 'AUX', 'NUL' );
for ( $i = 1; $i <= 9; $i++ ) {
	$reserved[] = 'COM' . $i;
	$reserved[] = 'LPT' . $i;
}

$h        = fopen( $file, 'rb' );
$total    = 0;
$invalid  = array();
$long     = array();
$dupes    = array();
$seen     = array();
$maxlen   = 0;
$maxname  = '';

while ( ! feof( $h ) ) {
	$entry = UWP_Archive::read_header( $h );
	if ( null === $entry ) {
		break;
	}
	$total++;
	$name = $entry['name'];
	if ( isset( $seen[ $name ] ) ) {
		$dupes[ $name ] = true;
	}
	$seen[ $name ] = true;

	$base = $entry['basename'];
	if ( preg_match( '/[<>:"|?*\x00-\x1F]/', $base )
		|| '.' === substr( $base, -1 ) || ' ' === substr( $base, -1 )
		|| in_array( strtoupper( preg_replace( '/\..*$/', '', $base ) ), $reserved, true ) ) {
		$invalid[] = $name;
	}
	$len = strlen( $name ) + $prefix + 1;
	if ( $len > $maxlen ) {
		$maxlen = $len;
		$maxname = $name;
	}
	if ( $len >= 250 ) {
		$long[] = $len . ' ' . $name;
	}
	UWP_Archive::skip_data( $h, $entry['size'] );
}
fclose( $h );

echo "entries: {$total}\n";
echo 'windows-invalid names: ' . count( $invalid ) . "\n";
foreach ( array_slice( $invalid, 0, 30 ) as $n ) {
	echo "  ! {$n}\n";
}
echo "duplicate paths: " . count( $dupes ) . "\n";
foreach ( array_slice( array_keys( $dupes ), 0, 10 ) as $n ) {
	echo "  = {$n}\n";
}
echo "longest staged path: {$maxlen} chars\n  {$maxname}\n";
echo 'paths >= 250 chars (staged): ' . count( $long ) . "\n";
foreach ( array_slice( $long, 0, 10 ) as $n ) {
	echo "  L {$n}\n";
}
