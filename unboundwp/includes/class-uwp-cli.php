<?php
/**
 * Optional WP-CLI commands: wp unboundwp export / import / list.
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
		$cmd = isset( $args[0] ) ? $args[0] : 'help';
		switch ( $cmd ) {
			case 'export':
				UWP_Export::cancel();
				UWP_Export::start();
				do {
					$r = UWP_Export::tick();
					WP_CLI::log( sprintf( '[%d%%] %s', $r['percent'], $r['message'] ) );
				} while ( empty( $r['done'] ) );
				WP_CLI::success( sprintf( 'Backup: %s', $r['file'] ) );
				break;

			case 'list':
				foreach ( UWP_Helpers::list_backups() as $b ) {
					WP_CLI::line( sprintf( '%s (%s)', $b['name'], size_format( $b['size'] ) ) );
				}
				break;

			default:
				WP_CLI::line( 'Usage: wp unboundwp export | wp unboundwp list' );
				break;
		}
	}
}
