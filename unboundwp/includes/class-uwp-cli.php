<?php
/**
 * Optional WP-CLI commands.
 *
 *   wp unboundwp export [--password=<password>]
 *   wp unboundwp import <file> [--password=<password>]
 *   wp unboundwp verify <file>
 *   wp unboundwp list
 *
 * Long jobs run as a tick loop right here, so no browser or HTTP
 * round-trip is involved. Every command is safe to re-run: export/import
 * resume from their persisted state instead of starting blind.
 *
 * @package UnboundWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UWP_CLI {

	/**
	 * Register commands when WP-CLI is present.
	 */
	public static function register() {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		WP_CLI::add_command( 'unboundwp', array( __CLASS__, 'route' ) );
	}

	/**
	 * Route subcommands (kept tiny on purpose).
	 *
	 * @param array $args Positional args.
	 * @param array $assoc Named args.
	 */
	public static function route( $args, $assoc ) {
		$cmd = isset( $args[0] ) ? (string) $args[0] : 'help';
		$password = isset( $assoc['password'] ) ? (string) $assoc['password'] : '';

		switch ( $cmd ) {
			case 'export':
				try {
					UWP_Export::cancel();
					UWP_Export::start( $password );
					$result = self::run_loops( 'export' );
					WP_CLI::success( sprintf( 'Backup created: %s', isset( $result['file'] ) ? $result['file'] : '' ) );
					if ( ! empty( $result['message'] ) ) {
						WP_CLI::log( $result['message'] );
					}
				} catch ( Throwable $e ) {
					WP_CLI::error( $e->getMessage() );
				}
				break;

			case 'import':
				$file = isset( $args[1] ) ? (string) $args[1] : '';
				if ( '' === $file ) {
					WP_CLI::error( 'Usage: wp unboundwp import <file.uwp|file.wpress> [--password=<password>]' );
				}
				$file = self::resolve_file( $file );
				if ( '' === $file ) {
					WP_CLI::error( 'Backup not found (absolute path or a file name from the Backups page).' );
				}
				try {
					UWP_Import::cancel();
					UWP_Import::start( $file, $password, array() );
					$result = self::run_loops( 'import' );
					WP_CLI::success( isset( $result['message'] ) ? $result['message'] : 'Restore complete.' );
				} catch ( Throwable $e ) {
					WP_CLI::error( $e->getMessage() );
				}
				break;

			case 'verify':
				$file = isset( $args[1] ) ? (string) $args[1] : '';
				if ( '' === $file ) {
					WP_CLI::error( 'Usage: wp unboundwp verify <file.uwp|file.wpress>' );
				}
				$file = self::resolve_file( $file );
				if ( '' === $file ) {
					WP_CLI::error( 'Backup not found (absolute path or a file name from the Backups page).' );
				}
				try {
					UWP_Verify::start( $file );
					$result = self::run_loops( 'verify' );
					if ( empty( $result['healthy'] ) ) {
						WP_CLI::error( isset( $result['message'] ) ? $result['message'] : 'Verification failed.' );
					}
					WP_CLI::success( $result['message'] );
					if ( ! empty( $result['warnings'] ) ) {
						foreach ( (array) $result['warnings'] as $warning ) {
							WP_CLI::warning( $warning );
						}
					}
				} catch ( Throwable $e ) {
					WP_CLI::error( $e->getMessage() );
				}
				break;

			case 'list':
				$backups = UWP_Helpers::list_backups();
				if ( empty( $backups ) ) {
					WP_CLI::line( 'No backups yet.' );
					break;
				}
				foreach ( $backups as $b ) {
					WP_CLI::line( sprintf( '%s (%s, %s)', $b['name'], size_format( $b['size'] ), gmdate( 'Y-m-d H:i', (int) $b['mtime'] ) ) );
				}
				break;

			default:
				WP_CLI::line( 'Usage:' );
				WP_CLI::line( '  wp unboundwp export [--password=<password>]' );
				WP_CLI::line( '  wp unboundwp import <file> [--password=<password>]' );
				WP_CLI::line( '  wp unboundwp verify <file>' );
				WP_CLI::line( '  wp unboundwp list' );
				break;
		}
	}

	/**
	 * Drive a job's tick loop to completion, logging progress.
	 *
	 * @param string $kind 'export', 'import' or 'verify'.
	 * @return array Final envelope from the job.
	 * @throws Exception On a fatal job error.
	 */
	protected static function run_loops( $kind ) {
		$last_pct = -1;
		$guard    = 0;
		do {
			if ( 'export' === $kind ) {
				$result = UWP_Export::tick();
			} elseif ( 'import' === $kind ) {
				$result = UWP_Import::tick();
			} else {
				$result = UWP_Verify::tick();
			}
			$pct = isset( $result['percent'] ) ? (int) $result['percent'] : -1;
			if ( $pct >= 0 && $pct !== $last_pct ) {
				$last_pct = $pct;
				WP_CLI::log( sprintf( '[%3d%%] %s', $pct, isset( $result['message'] ) ? $result['message'] : '' ) );
			}
			// Busy means another worker holds the lock: wait briefly.
			if ( ! empty( $result['busy'] ) ) {
				sleep( 1 );
			}
			$guard++;
		} while ( empty( $result['done'] ) && $guard < 100000 );

		if ( empty( $result['done'] ) ) {
			throw new Exception( 'The job did not finish (too many iterations).' );
		}
		// No browser is waiting for a receipt: clear it so a later page
		// load does not announce a job that finished in the terminal.
		$keys = array(
			'export' => UWP_Export::STATE_OPTION,
			'import' => UWP_Import::STATE_OPTION,
			'verify' => UWP_Verify::STATE_OPTION,
		);
		if ( isset( $keys[ $kind ] ) ) {
			UWP_Helpers::job_clear_done( $keys[ $kind ] );
		}
		return $result;
	}

	/**
	 * Resolve a CLI file argument: absolute path or a name in backups.
	 *
	 * @param string $file Argument.
	 * @return string Absolute path or '' when not found.
	 */
	protected static function resolve_file( $file ) {
		if ( is_file( $file ) ) {
			return $file;
		}
		$candidate = trailingslashit( uwp_backups_dir() ) . sanitize_file_name( basename( (string) $file ) );
		if ( is_file( $candidate ) ) {
			return $candidate;
		}
		$candidate = trailingslashit( uwp_temp_dir() ) . 'uploads/' . sanitize_file_name( basename( (string) $file ) );
		if ( is_file( $candidate ) ) {
			return $candidate;
		}
		return '';
	}
}
