<?php
/**
 * Admin menus, pages and assets.
 *
 * @package UnboundWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UWP_Admin {

	/**
	 * Register menu + assets.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'restore_notice' ) );
		// Late kill: heartbeat's auth-check popup fires on any failed poll,
		// which during long restores is expected noise. Our pages don't use
		// heartbeat for anything, so remove it there (priority 100 beats
		// anything re-adding it). Everywhere else is untouched.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'kill_heartbeat' ), 100 );
	}

	/**
	 * Remove heartbeat on UnboundWP screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function kill_heartbeat( $hook ) {
		if ( false === strpos( $hook, 'unboundwp' ) ) {
			return;
		}
		wp_dequeue_script( 'heartbeat' );
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Add the UnboundWP menu.
	 */
	public static function menu() {
		add_menu_page(
			__( 'UnboundWP', 'unboundwp' ),
			__( 'UnboundWP', 'unboundwp' ),
			'manage_options',
			'unboundwp',
			array( __CLASS__, 'render_export' ),
			'dashicons-cloud-saved',
			75
		);
		add_submenu_page(
			'unboundwp',
			__( 'Export', 'unboundwp' ),
			__( 'Export', 'unboundwp' ),
			'manage_options',
			'unboundwp',
			array( __CLASS__, 'render_export' )
		);
		add_submenu_page(
			'unboundwp',
			__( 'Import', 'unboundwp' ),
			__( 'Import', 'unboundwp' ),
			'manage_options',
			'unboundwp-import',
			array( __CLASS__, 'render_import' )
		);
		add_submenu_page(
			'unboundwp',
			__( 'Backups', 'unboundwp' ),
			__( 'Backups', 'unboundwp' ),
			'manage_options',
			'unboundwp-backups',
			array( __CLASS__, 'render_backups' )
		);
		add_submenu_page(
			'unboundwp',
			__( 'Logs', 'unboundwp' ),
			__( 'Logs', 'unboundwp' ),
			'manage_options',
			'unboundwp-logs',
			array( __CLASS__, 'render_logs' )
		);
		add_submenu_page(
			'unboundwp',
			__( 'About', 'unboundwp' ),
			__( 'About', 'unboundwp' ),
			'manage_options',
			'unboundwp-about',
			array( __CLASS__, 'render_about' )
		);
	}

	/**
	 * Tab navigation shared by every UnboundWP page.
	 *
	 * @param string $current Active tab slug: export, import, backups, logs, about.
	 */
	public static function tabs( $current ) {
		$tabs = array(
			'export'  => array( __( 'Export', 'unboundwp' ), 'admin.php?page=unboundwp' ),
			'import'  => array( __( 'Import', 'unboundwp' ), 'admin.php?page=unboundwp-import' ),
			'backups' => array( __( 'Backups', 'unboundwp' ), 'admin.php?page=unboundwp-backups' ),
			'logs'    => array( __( 'Logs', 'unboundwp' ), 'admin.php?page=unboundwp-logs' ),
			'about'   => array( __( 'About', 'unboundwp' ), 'admin.php?page=unboundwp-about' ),
		);
		echo '<h2 class="nav-tab-wrapper uwp-tabs">';
		foreach ( $tabs as $slug => $tab ) {
			$class = ( $slug === $current ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf(
				'<a class="%s" href="%s">%s</a>',
				esc_attr( $class ),
				esc_url( admin_url( $tab[1] ) ),
				esc_html( $tab[0] )
			);
		}
		echo '</h2>';
	}

	/**
	 * Load CSS/JS only on our own pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'unboundwp' ) ) {
			return;
		}
		// No heartbeat here: its auth-check popup ("session expired") fires
		// on any failed poll, which during long restores is expected noise
		// (the run authenticates by job secret, not by session). Removing
		// it also frees a PHP worker per poll while jobs run. Normal
		// heartbeat resumes on every other admin page.
		wp_deregister_script( 'heartbeat' );
		wp_enqueue_style(
			'uwp-admin',
			UWP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			UWP_VERSION
		);

		$data = array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'uwp_nonce' ),
			'chunkSize' => UWP_UPLOAD_CHUNK,
			'homeUrl'   => home_url( '/' ),
			'logsUrl'   => admin_url( 'admin.php?page=unboundwp-logs' ),
			'i18n'      => array(
				'starting'       => __( 'Starting…', 'unboundwp' ),
				'resuming'       => __( 'Resuming…', 'unboundwp' ),
				'uploading'      => __( 'Uploading…', 'unboundwp' ),
				'retrying'       => __( 'Retrying…', 'unboundwp' ),
				'failed'         => __( 'Something went wrong. Please try again.', 'unboundwp' ),
				'gaveUp'         => __( 'Still no response from the server. Your progress is saved, so use Resume to keep trying. If this repeats, the server log (unboundwp-temp/uwp-debug.log in uploads) names the failing step.', 'unboundwp' ),
				'serverError'    => __( 'The server errored during this step. Your progress is saved, so use Resume to keep trying.', 'unboundwp' ),
				'sessionExpired' => __( 'Your login session expired. Log in again (or refresh this page), then use Resume to continue where it left off. No progress is lost.', 'unboundwp' ),
				'confirm'     => __( 'This will overwrite your site with the backup. Continue?', 'unboundwp' ),
				'confirmDel'  => __( 'Delete this backup permanently?', 'unboundwp' ),
				'viewSite'    => __( 'View site', 'unboundwp' ),
				'newRestore'  => __( 'Start new restore', 'unboundwp' ),
				'openLogs'    => __( 'Open full logs', 'unboundwp' ),
				'restoreNotes' => __( 'What changed (notes)', 'unboundwp' ),
			),
		);

		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, 'unboundwp-import' ) ) {
			wp_enqueue_script( 'uwp-import', UWP_PLUGIN_URL . 'assets/js/import.js', array(), UWP_VERSION, true );
			wp_localize_script( 'uwp-import', 'UWP', $data );
		} else {
			wp_enqueue_script( 'uwp-export', UWP_PLUGIN_URL . 'assets/js/export.js', array(), UWP_VERSION, true );
			wp_localize_script( 'uwp-export', 'UWP', $data );
		}
	}

	/**
	 * Persistent restore-completion notice (our screens only).
	 *
	 * Shows the last restore's message plus next steps until dismissed;
	 * dismissed via POST (same pattern as the Logs page, no redirects).
	 */
	public static function restore_notice() {
		if ( ! function_exists( 'get_current_screen' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['uwp_dismiss_restore'] ) ) {
			check_admin_referer( 'uwp_dismiss_restore' );
			delete_option( 'uwp_last_restore' );
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'unboundwp' ) ) {
			return;
		}
		$report = get_option( 'uwp_last_restore', null );
		if ( ! is_array( $report ) || empty( $report['message'] ) ) {
			return;
		}
		$when = isset( $report['time'] ) ? (int) $report['time'] : 0;
		?>
		<div class="notice notice-success is-dismissible uwp-restore-notice">
			<p><strong><?php esc_html_e( 'UnboundWP restore finished.', 'unboundwp' ); ?></strong>
			<?php esc_html_e( 'Your site is back. Notes and details are on the Import page result and in the full logs.', 'unboundwp' ); ?></p>
			<p>
				<a class="button button-small button-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View site', 'unboundwp' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>"><?php esc_html_e( 'Check permalinks', 'unboundwp' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=unboundwp-logs' ) ); ?>"><?php esc_html_e( 'Open full logs', 'unboundwp' ); ?></a>
				<?php if ( $when > 0 ) : ?>
				<span class="description"><?php echo esc_html( sprintf( __( 'Finished %s.', 'unboundwp' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $when ) ) ); ?></span>
				<?php endif; ?>
			</p>
			<form method="post" style="margin:0 0 8px">
				<?php wp_nonce_field( 'uwp_dismiss_restore' ); ?>
				<button type="submit" name="uwp_dismiss_restore" value="1" class="button button-small"><?php esc_html_e( 'Dismiss', 'unboundwp' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the About page.
	 */
	public static function render_about() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'unboundwp' ) );
		}
		$mu_version = defined( 'UWP_MU_LOADER_VERSION' ) ? UWP_MU_LOADER_VERSION : __( 'unknown', 'unboundwp' );
		include UWP_PLUGIN_DIR . 'templates/admin-about.php';
	}

	/**
	 * Render the Export page.
	 */
	public static function render_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'unboundwp' ) );
		}
		include UWP_PLUGIN_DIR . 'templates/admin-export.php';
	}

	/**
	 * Render the Import page.
	 */
	public static function render_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'unboundwp' ) );
		}
		$backups = UWP_Helpers::list_backups();
		include UWP_PLUGIN_DIR . 'templates/admin-import.php';
	}

	/**
	 * Render the Backups page.
	 */
	public static function render_backups() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'unboundwp' ) );
		}
		$backups = UWP_Helpers::list_backups();
		include UWP_PLUGIN_DIR . 'templates/admin-backups.php';
	}

	/**
	 * Render the Logs page (full, untruncated per-job + shared logs).
	 */
	public static function render_logs() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'unboundwp' ) );
		}
		$notice = '';
		if ( isset( $_POST['uwp_log_delete'] ) ) {
			check_admin_referer( 'uwp_logs' );
			$name = isset( $_POST['log'] ) ? sanitize_file_name( wp_unslash( $_POST['log'] ) ) : '';
			if ( class_exists( 'UWP_Logger' ) && UWP_Logger::delete( $name ) ) {
				$notice = sprintf( __( 'Deleted %s.', 'unboundwp' ), $name );
			} else {
				$notice = __( 'Could not delete that log.', 'unboundwp' );
			}
		}
		$logs = class_exists( 'UWP_Logger' ) ? UWP_Logger::list_logs() : array();
		$view = isset( $_GET['log'] ) ? sanitize_file_name( wp_unslash( $_GET['log'] ) ) : '';
		$view_content = '';
		if ( '' !== $view && class_exists( 'UWP_Logger' ) ) {
			$safe = UWP_Logger::safe_name( $view );
			if ( false !== $safe ) {
				$path = trailingslashit( uwp_temp_dir() ) . $safe;
				if ( is_file( $path ) && is_readable( $path ) ) {
					$raw = file_get_contents( $path ); // phpcs:ignore - full content by design, never truncated.
					$view_content = is_string( $raw ) ? $raw : '';
				}
			}
		}
		$download_nonce = wp_create_nonce( 'uwp_nonce' );
		include UWP_PLUGIN_DIR . 'templates/admin-logs.php';
	}
}
