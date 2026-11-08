<?php
/**
 * List archive entries whose path matches a substring (read-only).
 *
 * Usage: php tests/list-entries.php <archive> <substring>
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

$file = isset( $argv[1] ) ? $argv[1] : '';
$needle = isset( $argv[2] ) ? $argv[2] : '';
if ( '' === $file || '' === $needle ) {
	fwrite( STDERR, "Usage: php tests/list-entries.php <archive> <substring>\n" );
	exit( 1 );
}

$h = fopen( $file, 'rb' );
$found = 0;
while ( ! feof( $h ) ) {
	$entry = UWP_Archive::read_header( $h );
	if ( null === $entry ) {
		break;
	}
	if ( false !== stripos( $entry['name'], $needle ) ) {
		$found++;
		echo 'name      : ' . json_encode( $entry['name'] ) . "\n";
		echo 'basename  : ' . json_encode( $entry['basename'] ) . "\n";
		echo 'prefix    : ' . json_encode( $entry['prefix'] ) . "\n";
		echo 'size      : ' . $entry['size'] . "\n";
		echo 'crc       : ' . $entry['crc'] . "\n";
		echo 'name_hex  : ' . bin2hex( $entry['basename'] ) . "\n---\n";
	}
	UWP_Archive::skip_data( $h, $entry['size'] );
}
fclose( $h );
echo "matches: {$found}\n";
