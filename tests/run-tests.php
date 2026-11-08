<?php
/**
 * Standalone test harness for UnboundWP's pure logic.
 *
 * WordPress is NOT loaded: the handful of WP functions used by the
 * classes under test are stubbed below. This keeps the suite runnable on
 * any PHP binary (php tests/run-tests.php) and fast enough to run before
 * every release.
 *
 * @package UnboundWP
 */

error_reporting( E_ALL );

define( 'ABSPATH', '/srv/site/' );
define( 'WP_CONTENT_DIR', '/srv/site/wp-content' );
define( 'WP_PLUGIN_DIR', '/srv/site/wp-content/plugins' );
define( 'UWP_VERSION', '1.1.0-test' );
define( 'UWP_PLUGIN_BASENAME', 'unboundwp/unboundwp.php' );
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { // phpcs:ignore
		return $text;
	}
}
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $value ) { // phpcs:ignore
		return addslashes( (string) $value );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) { // phpcs:ignore
		return json_encode( $data, $flags ); // phpcs:ignore
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) { // phpcs:ignore
		return is_dir( $dir ) || @mkdir( $dir, 0777, true ); // phpcs:ignore
	}
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) { // phpcs:ignore
		$path = str_replace( '\\', '/', (string) $path );
		$path = preg_replace( '|(?<=.)/+|', '/', $path );
		return $path;
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) { // phpcs:ignore
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) { // phpcs:ignore
		return number_format( (float) $bytes ) . ' B';
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $name ) { // phpcs:ignore
		return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $name );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) { // phpcs:ignore
		return $value;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { // phpcs:ignore
		return false;
	}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() { // phpcs:ignore
		return array( 'basedir' => '/srv/site/wp-content/uploads' );
	}
}

// Minimal $wpdb stand-in for the prefix-aware helpers.
class UWP_Test_WPDB {
	public $prefix = 'wp_';
	public $last_error = '';
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $query, 1 );
		}
		return $query;
	}
	public function get_results( $query, $mode = null ) { // phpcs:ignore
		if ( false !== stripos( $query, 'SHOW COLLATION' ) ) {
			// The minimum set the charset tests need; utf8mb3 is absent on
			// purpose (MySQL 8 behaviour).
			return array(
				array( 'Collation' => 'utf8mb4_unicode_ci', 'Charset' => 'utf8mb4' ),
				array( 'Collation' => 'utf8mb4_general_ci', 'Charset' => 'utf8mb4' ),
				array( 'Collation' => 'utf8_unicode_ci', 'Charset' => 'utf8' ),
				array( 'Collation' => 'utf8_general_ci', 'Charset' => 'utf8' ),
				array( 'Collation' => 'latin1_swedish_ci', 'Charset' => 'latin1' ),
			);
		}
		return array();
	}
	public function query( $query ) { return 0; } // phpcs:ignore
	public function get_row( $query, $mode = null ) { return array(); } // phpcs:ignore
	public function get_var( $query ) { return null; } // phpcs:ignore
	public function esc_like( $text ) { return addcslashes( $text, '_%\\' ); }
}
$GLOBALS['wpdb'] = new UWP_Test_WPDB();

require_once __DIR__ . '/../unboundwp/includes/class-uwp-helpers.php';
require_once __DIR__ . '/../unboundwp/includes/class-uwp-logger.php';
require_once __DIR__ . '/../unboundwp/includes/class-uwp-archive.php';
require_once __DIR__ . '/../unboundwp/includes/class-uwp-database.php';
require_once __DIR__ . '/../unboundwp/includes/class-uwp-export.php';
require_once __DIR__ . '/../unboundwp/includes/class-uwp-import.php';

$GLOBALS['uwp_tests'] = array( 'pass' => 0, 'fail' => 0, 'failures' => array() );

function uwp_assert( $condition, $label ) {
	if ( $condition ) {
		$GLOBALS['uwp_tests']['pass']++;
		echo "  ok   {$label}\n";
		return;
	}
	$GLOBALS['uwp_tests']['fail']++;
	$GLOBALS['uwp_tests']['failures'][] = $label;
	echo "  FAIL {$label}\n";
}

function uwp_assert_same( $expected, $actual, $label ) {
	$show = function ( $value ) {
		if ( is_string( $value ) && strlen( $value ) > 80 ) {
			return "'" . substr( $value, 0, 60 ) . '… (' . strlen( $value ) . ' bytes)';
		}
		return var_export( $value, true );
	};
	uwp_assert( $expected === $actual, $label . ' (expected ' . $show( $expected ) . ', got ' . $show( $actual ) . ')' );
}

function uwp_tmp_dir() {
	// Workspace-local temp keeps the suite runnable inside sandboxes that
	// forbid writes to the system temp directory.
	$base = __DIR__ . '/.tmp';
	if ( ! is_dir( $base ) ) {
		mkdir( $base, 0777, true );
	}
	$dir = $base . '/run-' . getmypid() . '-' . mt_rand( 1000, 9999 );
	mkdir( $dir, 0777, true );
	return $dir;
}

echo "Archive format\n";
$tmp  = uwp_tmp_dir();
$src  = $tmp . '/payload.bin';
$arc  = $tmp . '/archive.uwp';
$out  = $tmp . '/extracted.bin';
$payload = str_repeat( "Some binary-ish content \x00\x01\x02 and text; with a semicolon\n", 5000 );
file_put_contents( $src, $payload );
touch( $src, 1700000000 );
$h = UWP_Archive::open_for_write( $arc );
UWP_Archive::append_file( $h, $src, 'uploads/2024', 'payload.bin' );
UWP_Archive::finish( $h );
$scan = UWP_Archive::scan( $arc );
uwp_assert_same( 1, $scan['files'], 'scan counts one entry' );
uwp_assert_same( strlen( $payload ), $scan['bytes'], 'scan counts entry bytes' );
$h2 = fopen( $arc, 'rb' );
$entry = UWP_Archive::read_header( $h2 );
uwp_assert( is_array( $entry ), 'header parses' );
uwp_assert_same( 'uploads/2024/payload.bin', $entry['name'], 'header keeps prefix/name' );
uwp_assert_same( strlen( $payload ), (int) $entry['size'], 'header size matches' );
uwp_assert_same( strtolower( hash( 'crc32b', $payload ) ), $entry['crc'], 'CRC backpatched (single-pass writer)' );
rewind( $h2 );
UWP_Archive::read_header( $h2 );
UWP_Archive::extract_entry( $h2, $entry, $out );
fclose( $h2 );
uwp_assert_same( $payload, file_get_contents( $out ), 'extracted bytes identical' );
uwp_assert_same( 1700000000, (int) filemtime( $out ), 'mtime restored' );
UWP_Helpers::rrmdir( $tmp );

echo "Legacy 4096-byte prefix headers\n";
$legacy_prefix = str_pad( 'old/legacy/dir', 4096, "\0" );
$legacy_block  = str_pad( 'file.txt', 255, "\0" ) . str_pad( '12', 14, "\0" ) . str_pad( '0', 12, "\0" ) . $legacy_prefix;
$parsed = UWP_Archive::parse_header( $legacy_block );
uwp_assert( is_array( $parsed ), 'legacy header parses' );
uwp_assert_same( 'old/legacy/dir/file.txt', $parsed['name'], 'legacy prefix reattached' );
uwp_assert_same( '', $parsed['crc'], 'legacy entry carries no CRC' );

echo "SQL statement splitter\n";
$cases = array(
	array( "SELECT 1;\nSELECT 2;", 'SELECT 1;', 'simple statement' ),
	array( "INSERT INTO `t` VALUES ('a;b');", "INSERT INTO `t` VALUES ('a;b');", 'semicolon inside a string' ),
	array( "INSERT INTO `t` VALUES ('line1;\nline2');\nSELECT 3;", "INSERT INTO `t` VALUES ('line1;\nline2');", 'newline + semicolon inside a string' ),
	array( "INSERT INTO `t` VALUES ('it''s; fine');", "INSERT INTO `t` VALUES ('it''s; fine');", 'doubled quotes' ),
	array( "INSERT INTO `t` VALUES ('a\\';b');", "INSERT INTO `t` VALUES ('a\\';b');", 'backslash escaped quote' ),
	array( "-- comment ; only\nSELECT 4;", "-- comment ; only\nSELECT 4;", 'line comment is kept but cannot terminate' ),
	array( "/* block ; comment */ SELECT 5;", "/* block ; comment */ SELECT 5;", 'block comment cannot terminate' ),
	array( "SELECT `weird;column` FROM t;", "SELECT `weird;column` FROM t;", 'backticked identifier' ),
);
foreach ( $cases as $case ) {
	$next = UWP_Database::next_statement( $case[0], ';' );
	uwp_assert_same( $case[1], $next['sql'], $case[2] );
}
$next = UWP_Database::next_statement( 'SELECT 1', ';' );
uwp_assert_same( null, $next['sql'], 'incomplete statement asks for more input' );
$next = UWP_Database::next_statement( "CREATE PROCEDURE p() BEGIN SELECT 1; END$$\nDELIMITER ;\n", '$$' );
uwp_assert_same( "CREATE PROCEDURE p() BEGIN SELECT 1; END$$", $next['sql'], 'custom DELIMITER respected' );
$next = UWP_Database::next_statement( "SELECT 'a\\'; b';", ';' );
uwp_assert_same( "SELECT 'a\\'; b';", $next['sql'], 'escaped quote keeps the ; inside the string' );
$next = UWP_Database::next_statement( "SELECT 'a\\'; b'", ';' );
uwp_assert_same( null, $next['sql'], 'escaped quote without a final terminator is incomplete' );

echo "Prefix remapping\n";
$sql = "INSERT INTO `wp_options` (`option_id`,`option_name`,`option_value`) VALUES (1,'wp_user_roles','a'),(2,'siteurl','http://x'),(3,'wp_rocket_settings','b');";
$new = UWP_Database::rewrite_statement_prefix( $sql, 'wp_', 'zz_' );
uwp_assert( false !== strpos( $new, 'INSERT INTO `zz_options`' ), 'INSERT table name remapped' );
uwp_assert( false !== strpos( $new, "'zz_user_roles'" ), 'first tuple key remapped' );
uwp_assert( false !== strpos( $new, "'siteurl'" ), 'unprefixed key untouched' );
uwp_assert( false !== strpos( $new, "'wp_rocket_settings'" ), 'reserved key untouched' );
$ddl = 'CREATE TABLE `wp_posts` (`ID` bigint);';
uwp_assert_same( 'CREATE TABLE `zz_posts` (`ID` bigint);', UWP_Database::rewrite_statement_prefix( $ddl, 'wp_', 'zz_' ), 'DDL table name remapped' );

echo "Serialization-safe replacement\n";
$serialized = serialize( array( 'url' => 'http://old.test/a', 'nested' => array( 'x' => 'http://old.test/b' ) ) );
$replaced   = UWP_Database::replace_string( $serialized, 'http://old.test', 'https://new.test' );
$back       = @unserialize( $replaced ); // phpcs:ignore
uwp_assert( is_array( $back ), 'serialized payload stays unserializable' );
uwp_assert_same( 'https://new.test/a', $back['url'], 'serialized string replaced' );
uwp_assert_same( 'https://new.test/b', $back['nested']['x'], 'nested serialized string replaced' );

echo "URL variant pairs\n";
$pairs = UWP_Database::build_replacements( 'http://old.test', 'http://old.test', 'https://new.test', 'https://new.test', 'http://old.test/wp-content/uploads', 'https://new.test/wp-content/uploads', '/srv/old', '/srv/new', '/srv/old/wp-content', '/srv/new/wp-content' );
$flat = array();
foreach ( $pairs as $p ) {
	$flat[ $p[0] ] = $p[1];
}
uwp_assert( isset( $flat['http://old.test'] ) && 'https://new.test' === $flat['http://old.test'], 'http source canonicalizes to the https destination' );
uwp_assert( isset( $flat['https://old.test'] ) && 'https://new.test' === $flat['https://old.test'], 'https source maps to destination' );
uwp_assert( isset( $flat['http://www.old.test'] ) && 'https://new.test' === $flat['http://www.old.test'], 'www variant maps to the bare destination' );
uwp_assert( isset( $flat['//old.test'] ) && '//new.test' === $flat['//old.test'], 'schemeless pair stays schemeless' );
uwp_assert( isset( $flat['http://new.test'] ) && 'https://new.test' === $flat['http://new.test'], 'destination http URLs normalize to https' );
uwp_assert( isset( $flat['http%3A%2F%2Fold.test'] ), 'urlencoded variant present' );
uwp_assert( isset( $flat['/srv/old/'] ) && '/srv/new/' === $flat['/srv/old/'], 'server path pair mapped' );
$lengths = array_map( 'strlen', array_keys( $flat ) );
$sorted  = $lengths;
rsort( $sorted );
uwp_assert_same( $sorted, $lengths, 'pairs sorted longest-first' );

echo "Path safety\n";
uwp_assert_same( '/root/a/b.txt', UWP_Helpers::safe_join( '/root/', 'a/b.txt' ), 'safe join inside root' );
uwp_assert_same( false, UWP_Helpers::safe_join( '/root/', '../evil.txt' ), 'traversal blocked' );
uwp_assert_same( false, UWP_Helpers::safe_join( '/root/', 'a/../../evil.txt' ), 'nested traversal blocked' );

echo "Archive path mapping\n";
uwp_assert_same( 'uploads/a.jpg', UWP_Export::archive_name( '/srv/site/wp-content/uploads/a.jpg' ), 'wp-content stored bare' );
uwp_assert_same( 'wp-admin/admin.php', UWP_Export::archive_name( '/srv/site/wp-admin/admin.php' ), 'core stored ABSPATH-relative' );
uwp_assert_same( 'index.php', UWP_Export::archive_name( '/srv/site/index.php' ), 'root file included' );
uwp_assert_same( null, UWP_Export::archive_name( '/srv/site/wp-content/index.php' ), 'wp-content twin of root file excluded' );
uwp_assert_same( null, UWP_Export::archive_name( '/srv/site/wp-config.php' ), 'wp-config never archived' );

uwp_assert_same( '/srv/site/wp-content/plugins/foo/bar.php', UWP_Import::map_to_live( 'plugins/foo/bar.php' ), 'Ai1WM bare path maps to wp-content' );
uwp_assert_same( '/srv/site/wp-content/themes/x/style.css', UWP_Import::map_to_live( 'wp-content/themes/x/style.css' ), 'prefixed path maps to ABSPATH' );
uwp_assert_same( '/srv/site/wp-config.php.restored.php', UWP_Import::map_to_live( 'wp-config.php' ), 'wp-config restored beside live' );
uwp_assert_same( null, UWP_Import::map_to_live( 'plugins/unboundwp/unboundwp.php' ), 'own plugin never overwritten' );
uwp_assert_same( null, UWP_Import::map_to_live( 'mu-plugins/unboundwp-loader.php' ), 'resurrection loader never overwritten' );
uwp_assert_same( null, UWP_Import::map_to_live( 'stray-root-file' ), 'unknown root file skipped' );
uwp_assert_same( '/srv/site/wp-content/cache/x.txt', UWP_Import::map_to_live( 'cache/x.txt' ), 'unknown subfolder falls under wp-content' );

echo "Export skip rules\n";
uwp_assert( UWP_Export::skip_directory( '/srv/site/wp-content/cache' ), 'wp-content/cache skipped' );
uwp_assert( ! UWP_Export::skip_directory( '/srv/site/wp-content/plugins/foo/cache' ), 'plugin cache kept' );
uwp_assert( UWP_Export::skip_directory( '/srv/site/wp-content/uploads/unboundwp-temp' ), 'own temp skipped' );
uwp_assert( UWP_Export::skip_directory( '/srv/site/wp-content/plugins/x/.git' ), 'VCS metadata skipped' );
uwp_assert( ! UWP_Export::skip_directory( '/srv/site/wp-content/uploads' ), 'uploads kept' );

echo "Volatile-row scope\n";
$wpdb = $GLOBALS['wpdb'];
$method = new ReflectionMethod( 'UWP_Database', 'is_skippable_insert' );
$method->setAccessible( true );
uwp_assert( true === $method->invoke( null, "INSERT INTO `wp_options` (`option_name`) VALUES ('_transient_x');" ), 'transient option skipped' );
uwp_assert( false === $method->invoke( null, "INSERT INTO `wp_posts` (`post_content`) VALUES ('has _transient_ text');" ), 'post content mentioning it is NOT skipped' );
uwp_assert( false === $method->invoke( null, "INSERT INTO `wp_2_options` (`option_name`) VALUES ('_transient_x');" ), 'other blog table not skipped' );

echo "Compat warnings\n";
$warnings = UWP_Import::compat_warnings( array( 'WordPress' => array( 'Version' => '5.4' ) ) );
uwp_assert( ! empty( $warnings ), 'old WP on PHP 8 warns' );
uwp_assert( empty( UWP_Import::compat_warnings( array( 'WordPress' => array( 'Version' => '6.8' ) ) ) ), 'current WP does not warn' );

echo "Cross-server schema normalisation\n";
$ddl = "CREATE TABLE `wp_yoast_seo_links` (\n  `url` varchar(255) COLLATE utf8mb3_uca1400_ai_ci DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_uca1400_ai_ci;";
$norm = UWP_Database::normalize_ddl_collations( $ddl );
uwp_assert( false === strpos( $norm, 'uca1400' ), 'MariaDB-only uca1400 collation is removed' );
uwp_assert( false !== strpos( $norm, 'CHARSET=utf8' ), 'utf8mb3 charset mapped to utf8' );
uwp_assert( false !== strpos( $norm, 'COLLATE=utf8_unicode_ci' ), 'unsupported collation mapped to a supported utf8 one' );
$ddl_ok = 'CREATE TABLE `t` (`a` int) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
uwp_assert_same( $ddl_ok, UWP_Database::normalize_ddl_collations( $ddl_ok ), 'supported charset/collation left untouched' );
$ddl_mb4 = 'CREATE TABLE `t2` (`a` text) COLLATE=utf8mb4_uca1400_ai_ci;';
$norm4   = UWP_Database::normalize_ddl_collations( $ddl_mb4 );
uwp_assert( false !== strpos( $norm4, 'utf8mb4_unicode_ci' ), 'utf8mb4 uca1400 mapped to utf8mb4_unicode_ci' );
uwp_assert( UWP_Database::is_schema_statement( 'CREATE TABLE x (a int);' ), 'CREATE detected as schema statement' );
uwp_assert( UWP_Database::is_schema_statement( '  ALTER TABLE x ADD b int;' ), 'ALTER detected as schema statement' );
uwp_assert( ! UWP_Database::is_schema_statement( 'INSERT INTO x VALUES (1);' ), 'INSERT is not a schema statement' );

echo "Plugin safety lists\n";
uwp_assert( UWP_Import::is_host_vendor_plugin( 'hostinger/hostinger.php' ), 'hostinger is a host-panel plugin' );
uwp_assert( UWP_Import::is_host_vendor_plugin( 'wpengine/wpe.php' ), 'wpengine is a host-panel plugin' );
uwp_assert( ! UWP_Import::is_host_vendor_plugin( 'wordpress-seo/wp-seo.php' ), 'ordinary plugin is not host-panel' );
uwp_assert( ! UWP_Import::is_host_vendor_plugin( 'unboundwp/unboundwp.php' ), 'UnboundWP is not host-panel' );
uwp_assert( UWP_Import::is_risky_plugin( 'really-simple-ssl/rlrsssl.php' ), 'SSL forcer is risky' );

echo "Password-protected archives\n";
$tmp2  = uwp_tmp_dir();
$src2  = $tmp2 . '/secret.bin';
$arc2  = $tmp2 . '/secret.uwp';
$out2  = $tmp2 . '/secret.out';
// Deliberately larger than one 500 KB encryption chunk plus one byte.
$payload2 = str_repeat( "s3cr3t \x00 block ", 40000 );
file_put_contents( $src2, $payload2 );
$pass = 'correct horse battery staple';
if ( UWP_Archive::crypto_available() ) {
	$h = UWP_Archive::open_for_write( $arc2 );
	UWP_Archive::append_string( $h, '.', 'package.json', wp_json_encode( array( 'Encrypted' => true, 'EncryptedSignature' => UWP_Archive::encrypt_signature( $pass ) ) ) );
	UWP_Archive::append_file( $h, $src2, '.', 'secret.bin', $pass );
	UWP_Archive::finish( $h );

	$small = UWP_Archive::read_small_entry( $arc2, 'package.json' );
	$pkg   = $small ? json_decode( $small, true ) : null;
	uwp_assert( is_array( $pkg ) && ! empty( $pkg['Encrypted'] ), 'config file stays plain and readable without the password' );
	uwp_assert( UWP_Archive::password_validates( $pkg, $pass ), 'correct password validates against the signature' );
	uwp_assert( ! UWP_Archive::password_validates( $pkg, 'wrong' ), 'wrong password is rejected' );

	$h2     = fopen( $arc2, 'rb' );
	$pkg_e  = UWP_Archive::read_header( $h2 ); // package.json
	UWP_Archive::skip_data( $h2, $pkg_e['size'] ); // headers are positional.
	$entry  = UWP_Archive::read_header( $h2 );
	UWP_Archive::extract_entry( $h2, $entry, $out2, array( 'password' => $pass ) );
	fclose( $h2 );
	uwp_assert_same( $payload2, file_get_contents( $out2 ), 'encrypted entry round-trips byte-for-byte' );

	$h3     = fopen( $arc2, 'rb' );
	$pkg_e3 = UWP_Archive::read_header( $h3 );
	UWP_Archive::skip_data( $h3, $pkg_e3['size'] );
	$entry3 = UWP_Archive::read_header( $h3 );
	$threw  = false;
	try {
		UWP_Archive::extract_entry( $h3, $entry3, $out2 . '.bad', array( 'password' => 'wrong' ) );
	} catch ( Exception $e ) {
		$threw = true;
	}
	fclose( $h3 );
	@unlink( $out2 . '.bad' ); // phpcs:ignore
	uwp_assert( $threw, 'wrong password fails the extraction instead of writing garbage' );
} else {
	echo "  skipped (openssl not available on this PHP build)\n";
}
UWP_Helpers::rrmdir( $tmp2 );

$failed = $GLOBALS['uwp_tests']['fail'];
$total  = $GLOBALS['uwp_tests']['pass'] + $failed;
echo "\n{$GLOBALS['uwp_tests']['pass']}/{$total} assertions passed.\n";
if ( $failed > 0 ) {
	echo "FAILURES:\n";
	foreach ( $GLOBALS['uwp_tests']['failures'] as $failure ) {
		echo " - {$failure}\n";
	}
	exit( 1 );
}
echo "All tests passed.\n";
exit( 0 );
