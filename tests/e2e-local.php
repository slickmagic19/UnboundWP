<?php
/**
 * End-to-end test against a real WordPress install (Local by Flywheel).
 *
 * Boots WordPress from E2E_SITE (env var or the default Local path),
 * loads UnboundWP and drives the real job pipelines:
 *
 *   php tests/e2e-local.php export
 *   php tests/e2e-local.php verify [archive]
 *   php tests/e2e-local.php import [archive]
 *   php tests/e2e-local.php all
 *
 * This exercises exactly what the admin-ajax endpoints call, minus HTTP.
 *
 * @package UnboundWP
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
set_time_limit( 0 );

$site = getenv( 'E2E_SITE' );
if ( ! $site ) {
	$site = 'C:/Users/Achi/Local Sites/thriftywedsites/app/public';
}
$site = rtrim( str_replace( '\\', '/', $site ), '/' );

if ( ! is_file( $site . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress not found at {$site}\n" );
	exit( 1 );
}

$_SERVER['HTTP_HOST']       = 'thriftywedsites.local';
$_SERVER['SERVER_NAME']     = 'thriftywedsites.local';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'UnboundWP E2E';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';

define( 'WP_USE_THEMES', false );
// Pretend to be WP-CLI: the job helpers then drive the work themselves
// instead of firing loopback HTTP requests at the same site.
if ( ! defined( 'WP_CLI' ) ) {
	define( 'WP_CLI', true );
}
// Plugins that ship a WP-CLI command (e.g. All-in-One WP Migration)
// include it when the constant is set; give them the base classes so
// the harness does not die before UnboundWP loads.
if ( ! class_exists( 'WP_CLI_Command' ) ) {
	class WP_CLI_Command {} // phpcs:ignore
}
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI { // phpcs:ignore
		public static function __callStatic( $name, $args ) {}
		public static function add_command( $name, $callable ) {}
		public static function error( $message ) {
			throw new Exception( (string) $message );
		}
	}
}
require $site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once $site . '/wp-content/plugins/unboundwp/unboundwp.php';

uwp_ensure_directories();
uwp_ensure_mu_loader();

$cmd = isset( $argv[1] ) ? (string) $argv[1] : 'all';
$password = getenv( 'E2E_PASSWORD' ) ? (string) getenv( 'E2E_PASSWORD' ) : '';

/**
 * Print a progress line only when the step/message changes.
 */
function e2e_progress( $last, $result ) {
	$key = ( isset( $result['step'] ) ? $result['step'] : '' ) . '|' . ( isset( $result['percent'] ) ? $result['percent'] : '' );
	if ( $key === $last ) {
		return $last;
	}
	printf(
		"  [%3s%%] %-11s %s\n",
		isset( $result['percent'] ) ? (string) $result['percent'] : '?',
		isset( $result['step'] ) ? $result['step'] : '',
		isset( $result['message'] ) ? $result['message'] : ''
	);
	return $key;
}

/**
 * Drive one job to completion.
 */
function e2e_run( $kind, $max_ticks = 20000 ) {
	$last  = '';
	$ticks = 0;
	do {
		if ( 'export' === $kind ) {
			$result = UWP_Export::tick();
		} elseif ( 'import' === $kind ) {
			$result = UWP_Import::tick();
		} else {
			$result = UWP_Verify::tick();
		}
		$last = e2e_progress( $last, $result );
		if ( ! empty( $result['busy'] ) ) {
			usleep( 200000 );
		}
		$ticks++;
	} while ( empty( $result['done'] ) && $ticks < $max_ticks );
	if ( empty( $result['done'] ) ) {
		throw new Exception( "{$kind}: did not finish after {$ticks} ticks" );
	}
	return $result;
}

function e2e_latest_backup() {
	$list = UWP_Helpers::list_backups();
	return empty( $list ) ? '' : $list[0]['path'];
}

function e2e_snapshot() {
	global $wpdb;
	return array(
		'siteurl'       => get_option( 'siteurl' ),
		'home'          => get_option( 'home' ),
		'posts'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->posts}`" ), // phpcs:ignore
		'users'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->users}`" ), // phpcs:ignore
		'options'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->options}`" ), // phpcs:ignore
		'stylesheet'    => get_option( 'stylesheet' ),
		'permalinks'    => get_option( 'permalink_structure' ),
		'site_title'    => get_option( 'blogname' ),
		'front_index'   => (int) filesize( ABSPATH . 'index.php' ),
		'theme_style'   => (int) @filesize( WP_CONTENT_DIR . '/themes/' . get_option( 'stylesheet' ) . '/style.css' ), // phpcs:ignore
	);
}

$exit = 0;
try {
	if ( 'all' === $cmd || 'export' === $cmd ) {
		echo "EXPORT\n";
		$before = e2e_snapshot();
		UWP_Export::cancel();
		$state = UWP_Export::start( $password );
		$done  = e2e_run( 'export' );
		printf( "  done: %s (%s)\n", $done['file'], size_format( $done['size'] ) );
		$archive = trailingslashit( uwp_backups_dir() ) . $done['file'];
		printf( "  snapshot: posts=%d users=%d options=%d theme=%s\n", $before['posts'], $before['users'], $before['options'], $before['stylesheet'] );
		file_put_contents( __DIR__ . '/e2e-last-archive.txt', $archive );
	}

	if ( 'all' === $cmd || 'verify' === $cmd ) {
		echo "VERIFY\n";
		$archive = isset( $argv[2] ) ? $argv[2] : trim( (string) @file_get_contents( __DIR__ . '/e2e-last-archive.txt' ) ); // phpcs:ignore
		if ( '' === $archive ) {
			$archive = e2e_latest_backup();
		}
		$state = UWP_Verify::start( $archive );
		$done  = e2e_run( 'verify' );
		printf( "  %s\n", $done['message'] );
		printf( "  files=%d bytes=%s crc-checked=%d warnings=%d errors=%d\n", $done['files'], size_format( $done['bytes'] ), $done['with_crc'], count( $done['warnings'] ), count( $done['errors'] ) );
		foreach ( array_merge( (array) $done['warnings'], (array) $done['errors'] ) as $line ) {
			echo '  - ' . $line . "\n";
		}
		if ( empty( $done['healthy'] ) ) {
			throw new Exception( 'verification reported an unhealthy archive' );
		}
	}

	if ( 'all' === $cmd || 'import' === $cmd ) {
		echo "IMPORT\n";
		$archive = isset( $argv[2] ) ? $argv[2] : trim( (string) @file_get_contents( __DIR__ . '/e2e-last-archive.txt' ) ); // phpcs:ignore
		if ( '' === $archive ) {
			$archive = e2e_latest_backup();
		}
		$before = e2e_snapshot();
		UWP_Import::cancel();
		$import_opts = array(
			'keep_core'    => (bool) getenv( 'E2E_KEEP_CORE' ),
			'skip_plugins' => (bool) getenv( 'E2E_SKIP_PLUGINS' ),
			'skip_replace' => (bool) getenv( 'E2E_SKIP_REPLACE' ),
		);
		$state = UWP_Import::start( $archive, $password, $import_opts );
		$done  = e2e_run( 'import' );
		echo '  ' . $done['message'] . "\n";

		// Reload options/caches the way a fresh request would.
		wp_cache_flush();
		clean_post_cache( 0 );

		$after = e2e_snapshot();
		echo "  BEFORE: " . json_encode( $before ) . "\n";
		echo "  AFTER : " . json_encode( $after ) . "\n";
		if ( getenv( 'E2E_CROSS_SITE' ) ) {
			// Migrating a DIFFERENT site: counts and identity legitimately
			// change. Check that the restored data is coherent instead.
			echo "  cross-site mode: skipping same-site drift comparison\n";
			if ( (int) $after['posts'] <= 0 || (int) $after['users'] <= 0 ) {
				throw new Exception( 'restored site has no posts/users' );
			}
			$live = untrailingslashit( home_url() );
			if ( untrailingslashit( (string) get_option( 'home' ) ) !== $live || untrailingslashit( (string) get_option( 'siteurl' ) ) !== $live ) {
				throw new Exception( "siteurl/home were not rewritten to {$live} (got " . get_option( 'siteurl' ) . ' / ' . get_option( 'home' ) . ')' );
			}
		} else {
			foreach ( array( 'siteurl', 'home', 'posts', 'users', 'options', 'blogname' ) as $key ) {
				if ( ! array_key_exists( $key, $after ) ) {
					continue;
				}
				if ( 'options' === $key ) {
					continue; // option counts legitimately change (transients dropped).
				}
				if ( (string) $before[ $key ] !== (string) $after[ $key ] ) {
					throw new Exception( "restore drift in {$key}: '{$before[$key]}' -> '{$after[$key]}'" );
				}
			}
		}
		if ( ! is_file( ABSPATH . 'wp-includes/version.php' ) || ! is_file( ABSPATH . 'wp-admin/admin.php' ) ) {
			throw new Exception( 'core files missing after restore' );
		}
		$head = (string) file_get_contents( ABSPATH . 'index.php', false, null, 0, 2048 );
		if ( false === strpos( $head, 'wp-blog-header' ) ) {
			throw new Exception( 'index.php is not the WordPress front controller' );
		}
		if ( ! is_plugin_active( UWP_PLUGIN_BASENAME ) ) {
			throw new Exception( 'UnboundWP is not active after the restore' );
		}
		if ( is_file( WP_CONTENT_DIR . '/themes/' . $after['stylesheet'] . '/style.css' ) === false ) {
			throw new Exception( 'active theme files missing after restore' );
		}

		$r    = wp_remote_get( home_url( '/' ), array( 'timeout' => 20, 'sslverify' => false ) );
		if ( is_wp_error( $r ) ) {
			echo '  homepage fetch skipped: ' . $r->get_error_message() . "\n";
		} else {
			$code = (int) wp_remote_retrieve_response_code( $r );
			$body = (string) wp_remote_retrieve_body( $r );
			printf( "  homepage: HTTP %d, %d bytes\n", $code, strlen( $body ) );
			if ( $code >= 400 || strlen( $body ) < 500 ) {
				throw new Exception( "homepage looks broken (HTTP {$code}, " . strlen( $body ) . ' bytes)' );
			}
		}
	}

	echo "E2E OK\n";
} catch ( Throwable $e ) {
	$exit = 1;
	echo 'E2E FAILED: ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
	$log = trailingslashit( uwp_temp_dir() ) . 'uwp-debug.log';
	if ( is_file( $log ) ) {
		echo "--- debug log tail ---\n";
		echo implode( "\n", array_slice( explode( "\n", (string) file_get_contents( $log ) ), -25 ) ) . "\n"; // phpcs:ignore
	}
}
exit( $exit );
