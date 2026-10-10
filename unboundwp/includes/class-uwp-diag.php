<?php
/**
 * One-click diagnostics: probe every subsystem a job tick depends on.
 *
 * Motivation: when ticks die with an empty "critical error", guessing
 * wastes days. Each probe runs isolated (own try/catch, bounded work), so
 * one broken subsystem cannot hide the rest - the report names it.
 *
 * All probes are read-only except a 1 KB temp-file roundtrip and one
 * loopback HTTP call to our own status endpoint (which also proves the
 * server can reach itself, the chaining prerequisite).
 *
 * @package UnboundWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UWP_Diag {

	/**
	 * Run all probes. Never throws: every probe is individually guarded,
	 * and the whole run is time-boxed.
	 *
	 * @return array Report: array('lines' => array(array('name','ok','detail'))).
	 */
	public static function run() {
		$started = microtime( true );
		$budget  = 25; // seconds for the whole battery, max.
		$lines   = array();

		$probes = array(
			'php'       => 'PHP runtime',
			'functions' => 'Required functions',
			'temp'      => 'Temp/backups directories',
			'state'     => 'Job state save/load',
			'lock'      => 'Job locking',
			'scan'      => 'Directory streaming sample',
			'database'  => 'Database basics',
			'uploads'   => 'Uploads directory',
			'loopback'  => 'Server self-reachability (loopback)',
			'guestajax' => 'Logged-out ajax variants',
			'fatallines' => 'Recent fatal log lines',
			'guestauth' => 'Logged-out auth safety',
			'nonce'     => 'Nonce creation',
			'muloader'  => 'MU resurrection loader',
			'jobs'      => 'Jobs on disk',
		);

		foreach ( $probes as $key => $label ) {
			if ( ( microtime( true ) - $started ) > $budget ) {
				$lines[] = array(
					'name'   => $label,
					'ok'     => false,
					'detail' => 'skipped: diagnostics time budget exceeded',
				);
				continue;
			}
			$t0 = microtime( true );
			try {
				$result = call_user_func( array( __CLASS__, 'probe_' . $key ) );
			} catch ( Throwable $e ) {
				$result = array( false, 'threw ' . get_class( $e ) . ': ' . $e->getMessage() );
			}
			$ms      = (int) ( ( microtime( true ) - $t0 ) * 1000 );
			// Severity: probes may return an explicit 'warn' level as a
			// third item (degraded-but-working or resolved history). `ok`
			// stays boolean for backward compatibility; UI prefers `level`.
			$ok    = (bool) $result[0];
			$level = ( isset( $result[2] ) && 'warn' === $result[2] ) ? 'warn' : ( $ok ? 'ok' : 'fail' );
			$lines[] = array(
				'name'   => $label,
				'ok'     => $ok,
				'level'  => $level,
				'detail' => $result[1] . ' (' . $ms . ' ms)',
			);
		}

		return array( 'lines' => $lines );
	}

	protected static function probe_php() {
		return array(
			true,
			sprintf(
				'PHP %s, SAPI %s, memory_limit %s, max_execution_time %s',
				PHP_VERSION,
				php_sapi_name(),
				ini_get( 'memory_limit' ),
				ini_get( 'max_execution_time' )
			),
		);
	}

	protected static function probe_functions() {
		$need = array( 'flock', 'random_bytes', 'hash_init', 'gzuncompress', 'gzinflate', 'openssl_decrypt', 'wp_remote_post' );
		$missing = array();
		foreach ( $need as $fn ) {
			if ( ! function_exists( $fn ) ) {
				$missing[] = $fn;
			}
		}
		if ( $missing ) {
			return array( false, 'missing: ' . implode( ', ', $missing ) );
		}
		return array( true, 'flock, random_bytes, hash, zlib, openssl, HTTP API present' );
	}

	protected static function probe_temp() {
		uwp_ensure_directories();
		$dir = uwp_temp_dir();
		if ( ! is_dir( $dir ) ) {
			return array( false, 'temp dir missing and could not be created: ' . $dir );
		}
		$probe = $dir . 'diag-' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false === @file_put_contents( $probe, 'ok' ) ) { // phpcs:ignore
			return array( false, 'temp dir not writable: ' . $dir );
		}
		$read = file_get_contents( $probe ); // phpcs:ignore
		@unlink( $probe ); // phpcs:ignore
		if ( 'ok' !== $read ) {
			return array( false, 'temp roundtrip mismatch' );
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore
		return array( true, 'writable; free space ' . ( false === $free ? 'unknown' : size_format( $free ) ) );
	}

	protected static function probe_state() {
		UWP_Helpers::job_save( 'uwp_diag_probe', array( 'a' => 1 ) );
		$back = UWP_Helpers::job_load( 'uwp_diag_probe' );
		UWP_Helpers::job_clear( 'uwp_diag_probe' );
		if ( ! is_array( $back ) || 1 !== $back['a'] ) {
			return array( false, 'state save/load roundtrip failed' );
		}
		return array( true, 'save/load/clear roundtrip ok' );
	}

	protected static function probe_lock() {
		$h1 = UWP_Helpers::job_lock( 'uwp_diag_probe' );
		if ( false === $h1 ) {
			return array( false, 'cannot acquire fresh lock' );
		}
		$h2 = UWP_Helpers::job_lock( 'uwp_diag_probe' );
		if ( false !== $h2 ) {
			fclose( $h2 );
			fclose( $h1 );
			return array( false, 'lock is not exclusive!' );
		}
		fclose( $h1 );
		$h3 = UWP_Helpers::job_lock( 'uwp_diag_probe' );
		if ( false === $h3 ) {
			return array( false, 'lock not released after close' );
		}
		fclose( $h3 );
		return array( true, 'exclusive + releasable' );
	}

	protected static function probe_scan() {
		$roots = array( WP_CONTENT_DIR );
		if ( is_dir( ABSPATH . 'wp-admin' ) ) {
			$roots[] = ABSPATH . 'wp-admin';
		}
		if ( is_dir( ABSPATH . 'wp-includes' ) ) {
			$roots[] = ABSPATH . 'wp-includes';
		}
		$counts = array();
		foreach ( $roots as $root ) {
			$n = 0;
			$h = @opendir( $root ); // phpcs:ignore
			if ( false === $h ) {
				return array( false, 'cannot open ' . $root );
			}
			while ( false !== ( $e = readdir( $h ) ) ) {
				if ( '.' === $e || '..' === $e ) {
					continue;
				}
				if ( ++$n >= 200 ) {
					break;
				}
			}
			closedir( $h );
			$counts[] = basename( rtrim( str_replace( '\\', '/', $root ), '/' ) ) . ':' . $n;
		}
		return array( true, 'streaming reads ok (' . implode( ', ', $counts ) . ' sampled)' );
	}

	protected static function probe_database() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return array( false, '$wpdb unavailable' );
		}
		$tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
		if ( ! is_array( $tables ) ) {
			return array( false, 'SHOW TABLES failed: ' . $wpdb->last_error );
		}
		$one = $wpdb->get_var( 'SELECT 1' );
		if ( '1' !== (string) $one && 1 !== $one ) {
			return array( false, 'SELECT 1 failed: ' . $wpdb->last_error );
		}
		$packet = $wpdb->get_row( "SHOW VARIABLES LIKE 'max_allowed_packet'", ARRAY_N );
		$packet = ( is_array( $packet ) && isset( $packet[1] ) ) ? (int) $packet[1] : 0;
		return array(
			true,
			sprintf(
				'%d tables, roundtrip ok, max_allowed_packet %s, db v%s',
				UWP_Helpers::safe_count( $tables ),
				$packet > 0 ? size_format( $packet ) : 'unknown',
				isset( $wpdb->db_version ) ? $wpdb->db_version : '?'
			),
		);
	}

	protected static function probe_uploads() {
		$u = wp_upload_dir();
		if ( ! is_array( $u ) || empty( $u['basedir'] ) ) {
			return array( false, 'wp_upload_dir broken' );
		}
		if ( ! empty( $u['error'] ) ) {
			return array( false, (string) $u['error'] );
		}
		return array( true, $u['basedir'] );
	}

	protected static function probe_loopback() {
		$url = admin_url( 'admin-ajax.php' );
		$r   = wp_remote_post(
			$url,
			array(
				'method'   => 'POST',
				'timeout'  => 8,
				'blocking' => true,
				'body'     => array(
					'action' => 'uwp_job_status',
					'job'    => 'export',
					'nonce'  => '',
				),
				'cookies'  => array(),
			)
		);
		if ( is_wp_error( $r ) ) {
			return array( false, 'loopback failed: ' . $r->get_error_message() . '. Server chaining is unavailable; browser must drive (exports/imports still work).' );
		}
		$code = wp_remote_retrieve_response_code( $r );
		$body = (string) wp_remote_retrieve_body( $r );
		// NOTE: HTTP 403 IS healthy here. This probe sends no login cookie
		// and no job secret, so the endpoint correctly answers 403 JSON
		// ("not allowed") - that proves the server reaches itself and our
		// handler runs. Only transport deaths, bare `0` (nopriv missing),
		// or 5xx mean real breakage. (Pre-1.3.3 this probe wrongly required
		// HTTP 200 and always FAILED on healthy sites.)
		if ( 403 === (int) $code && false !== strpos( $body, 'not allowed' ) ) {
			return array( true, 'server reaches itself (HTTP 403 guest-guard, ' . strlen( $body ) . ' bytes JSON)' );
		}
		if ( 200 !== (int) $code ) {
			if ( '0' === trim( $body ) ) {
				return array( false, 'loopback HTTP ' . $code . ': bare 0. Job endpoints unreachable when logged out (MU loader missing?)' );
			}
			return array( false, 'loopback HTTP ' . $code . ': ' . substr( trim( wp_strip_all_tags( $body ) ), 0, 120 ) );
		}
		return array( true, 'server reaches itself (HTTP 200, ' . strlen( $body ) . ' bytes)' );
	}

		/**
	 * Bisect logged-out admin-ajax: ours (secret-less status) vs core
	 * heartbeat vs unknown action. If core paths fail too, the breakage is
	 * beneath our code (server or security layer, not the plugin).
	 */
	protected static function probe_guestajax() {
		$url     = admin_url( 'admin-ajax.php' );
		$results = array();
		$cases   = array(
			'ours'    => array( 'action' => 'uwp_job_status', 'job' => 'export' ),
			'core'    => array( 'action' => 'heartbeat', 'data' => array() ),
			'missing' => array( 'action' => 'uwp_no_such_action_xyz' ),
		);
		foreach ( $cases as $name => $body ) {
			$r = wp_remote_post(
				$url,
				array(
					'method'   => 'POST',
					'timeout'  => 5,
					'blocking' => true,
					'body'     => $body,
					'cookies'  => array(),
				)
			);
			if ( is_wp_error( $r ) ) {
				$results[] = $name . '=transport-fail(' . $r->get_error_message() . ')';
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			$body_text = trim( wp_strip_all_tags( (string) wp_remote_retrieve_body( $r ) ) );
			$results[] = $name . '=HTTP' . $code . ':' . substr( preg_replace( '/\s+/', ' ', $body_text ), 0, 80 );
		}
		$joined = implode( ' | ', $results );
		$ours_healthy = false !== strpos( $joined, 'ours=HTTP200' ) || false !== strpos( $joined, 'ours=HTTP403' );
		if ( $ours_healthy ) {
			return array( true, $joined );
		}
		$verdict = self::guestajax_verdict( $results );
		if ( 'warn' === $verdict ) {
			// Server answers core paths but not ours (typical on LocalWP:
			// mDNS/timeouts). Degraded, not broken: browser-driven runs
			// still work, only auto-chaining is off.
			return array(
				false,
				$joined . '. Server answers core ajax but not ours (typical on LocalWP); browser-driven runs still work, only auto-chaining is off',
				'warn'
			);
		}
		return array(
			false,
			$joined . '. Server cannot reach itself at all; browser-driven runs still work, only auto-chaining is off',
		);
	}

	/**
	 * Severity for the guest-ajax bisect (pure function of probe outcomes).
	 *
	 * @param array $results Per-case "name=result" strings.
	 * @return string 'ok', 'warn' or 'fail'.
	 */
	public static function guestajax_verdict( $results ) {
		$joined = implode( ' | ', (array) $results );
		if ( false !== strpos( $joined, 'ours=HTTP200' ) || false !== strpos( $joined, 'ours=HTTP403' ) ) {
			return 'ok';
		}
		if ( false !== strpos( $joined, 'core=HTTP200' ) ) {
			return 'warn';
		}
		return 'fail';
	}

	/**
	 * Surface recent fatal/error lines from the debug log, if any.
	 * Only entries from the last 24 hours FAIL - older lines are reported
	 * as historical context (a stale "Cannot redeclare" from before an
	 * update must not permanently redden diagnostics after the fix).
	 */
	protected static function probe_fatallines() {
		$tail = UWP_Helpers::read_log_tail( 200 );
		if ( '' === $tail ) {
			return array( true, 'no log yet; nothing has died loudly' );
		}
		$now  = time();
		$fresh = array();
		$fresh_ts = array();
		$old   = 0;
		$last_complete = '';
		foreach ( explode( "\n", $tail ) as $line ) {
			$ts = '';
			if ( preg_match( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', trim( $line ), $m ) ) {
				$ts = $m[1];
			}
			// Completion marker: a later 'restore complete' means earlier
			// fatals were survived (the job finished anyway).
			if ( '' !== $ts && false !== stripos( $line, 'restore complete' ) ) {
				if ( '' === $last_complete || $ts > $last_complete ) {
					$last_complete = $ts;
				}
			}
			if ( ! preg_match( '/\b(FATAL|ERROR|critical|Allowed memory|Maximum execution|timeout)\b/i', $line )
				&& false === stripos( $line, 'FATAL' )
				&& false === stripos( $line, 'critical' ) ) {
				continue;
			}
			// Leading "Y-m-d H:i:s" timestamp? Age it.
			$age_ok = false;
			$is_old = false;
			if ( '' !== $ts ) {
				$tts = strtotime( $ts . ' UTC' );
				if ( $tts ) {
					$age_ok = true;
					if ( ( $now - $tts ) > DAY_IN_SECONDS ) {
						$old++;
						continue;
					}
				}
			}
			// Truncate: full Windows paths + repeated lines made this probe
			// unreadable (see 1.3.2 screenshot). Keep the signal, cut noise.
			$short = trim( $line );
			$short = str_replace( '\\', '/', $short );
			if ( strlen( $short ) > 220 ) {
				$short = substr( $short, 0, 220 ) . '…';
			}
			$fresh[] = $short;
			$fresh_ts[] = $ts;
			unset( $age_ok, $is_old );
		}
		$fresh = array_slice( array_unique( $fresh ), -3 );
		if ( ! $fresh ) {
			return array( true, $old > 0 ? 'log present, no fatal lines in the last 24h (' . $old . ' older ignored)' : 'log present, no fatal lines' );
		}
		if ( 'warn' === self::fatallines_verdict( $fresh_ts, $last_complete ) ) {
			return array( false, 'PAST FAILURES (all followed by a completed restore; resolved history, kept for reference): ' . implode( ' // ', $fresh ), 'warn' );
		}
		return array( false, 'RECENT FAILURES (last 24h): ' . implode( ' // ', $fresh ) );
	}

	/**
	 * Severity for recent fatals (pure function of timestamps).
	 *
	 * Warn when every fresh fatal predates a completed restore (the run
	 * survived them - history, not a live breakage). Anything else fails:
	 * missing timestamps fail safe, and a fatal newer than the last
	 * completion means something is currently wrong.
	 *
	 * @param array  $fresh_ts Timestamp strings ('Y-m-d H:i:s') of fresh fatal lines.
	 * @param string $last_complete_ts Newest 'restore complete' timestamp, or ''.
	 * @return string 'warn' or 'fail'.
	 */
	public static function fatallines_verdict( $fresh_ts, $last_complete_ts ) {
		if ( '' === (string) $last_complete_ts ) {
			return 'fail';
		}
		foreach ( (array) $fresh_ts as $ts ) {
			if ( '' === (string) $ts || $ts >= $last_complete_ts ) {
				return 'fail';
			}
		}
		return 'warn';
	}

	protected static function probe_guestauth() {

		if ( ! function_exists( 'wp_set_current_user' ) || ! function_exists( 'wp_get_current_user' ) ) {
			return array( false, 'user switching unavailable' );
		}
		$previous = wp_get_current_user();
		$previous_id = ( $previous && isset( $previous->ID ) ) ? (int) $previous->ID : 0;
		try {
			wp_set_current_user( 0 );
			$can = current_user_can( 'manage_options' );
		} catch ( Throwable $e ) {
			wp_set_current_user( $previous_id );
			return array( false, 'auth check threw for guests: ' . get_class( $e ) . ': ' . $e->getMessage() );
		}
		wp_set_current_user( $previous_id );
		if ( $can ) {
			return array( false, 'guest unexpectedly has admin capabilities' );
		}
		return array( true, 'guest capability check safe (false, no error)' );
	}

	protected static function probe_nonce() {
		$n = wp_create_nonce( 'uwp_nonce' );
		if ( ! is_string( $n ) || '' === $n ) {
			return array( false, 'empty nonce' );
		}
		return array( true, 'ok' );
	}

	protected static function probe_muloader() {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
			return array( false, 'WPMU_PLUGIN_DIR undefined' );
		}
		$p = trailingslashit( WPMU_PLUGIN_DIR ) . 'unboundwp-loader.php';
		if ( ! is_file( $p ) ) {
			return array( false, 'loader missing (reinstall or visit any admin page to self-heal)' );
		}
		$raw = @file_get_contents( $p ); // phpcs:ignore
		$ver = '';
		if ( is_string( $raw ) && preg_match( "/UWP_MU_LOADER_VERSION',\s*'([^']+)'/", $raw, $m ) ) {
			$ver = $m[1];
		}
		// The template ships a version marker since 1.3.3; an installed copy
		// without one (or with an older one) is stale - flag it so the
		// "Cannot redeclare" class of bootstrap fatal gets a name, not a mystery.
		if ( '' === $ver ) {
			return array( false, 'installed but STALE (no version marker, pre-1.3.3). Visit any admin page to self-heal, size ' . size_format( (int) filesize( $p ) ) );
		}
		if ( defined( 'UWP_VERSION' ) && version_compare( $ver, UWP_VERSION, '<' ) ) {
			return array( false, 'installed v' . $ver . ' but plugin is v' . UWP_VERSION . '. Stale, visit any admin page to self-heal' );
		}
		return array( true, 'installed v' . $ver . ', ' . size_format( (int) filesize( $p ) ) );
	}

	protected static function probe_jobs() {
		$tmp = uwp_temp_dir();
		if ( ! is_dir( $tmp ) ) {
			return array( true, 'no temp dir, no jobs' );
		}
		$found = array();
		$h     = @opendir( $tmp ); // phpcs:ignore
		if ( false !== $h ) {
			while ( false !== ( $e = readdir( $h ) ) ) {
				if ( 0 === strpos( $e, 'state-' ) && '.json' === substr( $e, -5 ) ) {
					$found[] = $e;
				}
			}
			closedir( $h );
		}
		if ( ! $found ) {
			return array( true, 'no stuck jobs' );
		}
		return array( true, 'stuck job files: ' . implode( ', ', $found ) );
	}
}
