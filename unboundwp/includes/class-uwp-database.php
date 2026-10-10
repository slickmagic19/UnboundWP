<?php
/**
 * Database export, import and URL replacement.
 *
 * Design goals:
 *  - No mysqldump dependency (works on every host).
 *  - Chunked: dump/import a few thousand rows per AJAX tick.
 *  - Serialization-safe search/replace (WordPress options, meta, widgets...).
 *  - Import streams database.sql - never loads it fully into memory.
 *
 * @package UnboundWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UWP_Database {

	/**
	 * Get all table names in this WordPress database.
	 *
	 * @return array
	 */
	public static function all_tables() {
		global $wpdb;
		$tables = array();
		// Plain SHOW TABLES: works on every MySQL/MariaDB version, no
		// fragile WHERE clause. Views are harmless to include.
		// phpcs:ignore -- no user input.
		$rows = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
		foreach ( (array) $rows as $row ) {
			if ( isset( $row[0] ) ) {
				$tables[] = $row[0];
			}
		}
		return $tables;
	}

	/**
	 * Neutralize a full options row before it is written to the dump.
	 *
	 * A restored site boots with half-replaced plugin/theme files; if the
	 * database simultaneously activates them, every following request can
	 * fatal on version-skewed code. So the dump records them empty (like
	 * All-in-One WP Migration) and the true lists travel in package.json
	 * for reactivation at Done, when files and database are consistent.
	 *
	 * @param string $table Table being dumped.
	 * @param array  $row Full row (must include option_name when options).
	 * @return array Possibly-neutralized row.
	 */
	public static function neutralize_options_row( $table, $row ) {
		global $wpdb;
		$prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
		if ( $table !== $prefix . 'options' || ! isset( $row['option_name'] ) ) {
			return $row;
		}
		if ( 'active_plugins' === $row['option_name'] ) {
			$row['option_value'] = 'a:0:{}';
		} elseif ( 'template' === $row['option_name'] || 'stylesheet' === $row['option_name'] ) {
			$row['option_value'] = '';
		}
		return $row;
	}

	/**
	 * Dump one chunk of one table to an open SQL file handle.
	 *
	 * State array (persisted between ticks):
	 *   table_index, table, offset, wrote_create
	 *
	 * Small batches (250 rows): a single call must never run long enough
	 * to hit server time limits, no matter how fat the rows are. Fetched
	 * in 50-row pages with time/memory checks between pages, so even one
	 * heavy table stays bounded.
	 *
	 * @param resource $out Open SQL file handle (append mode).
	 * @param array    $state Export progress state (by reference).
	 * @param int      $rows_per_tick Rows per tick.
	 * @param float    $deadline Optional microtime deadline (0 = none).
	 * @return bool True when ALL tables are done.
	 */
	public static function dump_tick( $out, &$state, $rows_per_tick = 250, $deadline = 0 ) {
		global $wpdb;

		if ( ! isset( $state['tables'] ) || ! is_array( $state['tables'] ) || ! isset( $state['v'] ) ) {
			// v2 state: keyset pagination (see below). Older jobs restart
			// the dump from scratch - redoing work beats mixing offset and
			// keyset cursors, which would silently skip or duplicate rows.
			// is_array guard: a resumed/corrupt state may hold null here,
			// and count(null) is a fatal TypeError on PHP 8+.
			$state['tables']      = self::all_tables();
			$state['table_index'] = 0;
			$state['offset']      = 0;
			$state['wrote_create'] = false;
			$state['v']            = 2;
			unset( $state['dump_mode'], $state['dump_pk'] );
		}

		$tables = is_array( $state['tables'] ) ? $state['tables'] : array();
		if ( $state['table_index'] >= UWP_Helpers::safe_count( $tables ) ) {
			return true;
		}

		$table = $tables[ $state['table_index'] ];

		// 1) CREATE TABLE once per table.
		if ( ! $state['wrote_create'] ) {
			// phpcs:ignore -- internal table name from SHOW TABLES.
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
			if ( ! empty( $create[1] ) ) {
				self::fwrite_all( $out, "DROP TABLE IF EXISTS `{$table}`;\n" );
				self::fwrite_all( $out, $create[1] . ";\n" );
			}
			$state['wrote_create'] = true;
			// Decide pagination once per table: keyset on a single-column
			// primary key (stable speed at ANY offset - OFFSET scans and
			// discards every skipped row, degrading until the server kills
			// late batches on huge tables), OFFSET fallback otherwise.
			$pk = self::primary_key( $table );
			$state['dump_mode'] = null === $pk ? 'off' : 'key';
			$state['dump_pk']   = $pk;
			if ( 'key' === $state['dump_mode'] ) {
				// Empty-string start: `pk > ''` matches every real key,
				// numeric or string (unlike `pk > 0`, which drops strings).
				$state['offset'] = '';
			}
			// Stage marker: if the next death lands here, the log names the
			// exact table instead of leaving another mystery.
			UWP_Helpers::debug_log( sprintf( 'dump: table %s (%s mode)', $table, $state['dump_mode'] ) );
		}

		// 2) Copy rows in small pages so time/memory stay bounded even for
		// tables with megabyte-sized rows.
		$remaining = (int) $rows_per_tick;
		while ( $remaining > 0 ) {
			$page = min( 50, $remaining );
			if ( isset( $state['dump_mode'] ) && 'key' === $state['dump_mode'] && ! empty( $state['dump_pk'] ) ) {
				$pk   = $state['dump_pk'];
				$last = $state['offset'];
				// phpcs:ignore -- internal identifiers; values cast below.
				$ph   = ( is_int( $last ) || ( is_string( $last ) && ctype_digit( $last ) ) ) ? '%d' : '%s';
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` > {$ph} ORDER BY `{$pk}` ASC LIMIT %d", $last, $page ), ARRAY_A );
			} else {
				// phpcs:ignore -- internal identifiers, integers cast below.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $page, (int) $state['offset'] ), ARRAY_A );
			}

			if ( empty( $rows ) ) {
				// Table done - move to next.
				$state['table_index']++;
				$state['offset']       = 0;
				$state['wrote_create'] = false;
				unset( $state['dump_mode'], $state['dump_pk'] );
				return $state['table_index'] >= UWP_Helpers::safe_count( $tables );
			}

			if ( ! is_array( $rows ) ) {
				$rows = array();
			}
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$row    = self::neutralize_options_row( $table, $row );
				$values = array();
				foreach ( $row as $value ) {
					if ( is_null( $value ) ) {
						$values[] = 'NULL';
					} else {
						$values[] = "'" . esc_sql( $value ) . "'";
					}
				}
				$cols = array_map(
					function ( $c ) {
						return '`' . str_replace( '`', '``', $c ) . '`';
					},
					array_keys( $row )
				);
				self::fwrite_all( $out, 'INSERT INTO `' . $table . '` (' . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', $values ) . ");\n" );
			}

			if ( isset( $state['dump_mode'] ) && 'key' === $state['dump_mode'] && ! empty( $state['dump_pk'] ) ) {
				$last_row = end( $rows );
				if ( is_array( $last_row ) && array_key_exists( $state['dump_pk'], $last_row ) ) {
					$state['offset'] = $last_row[ $state['dump_pk'] ];
				} else {
					// PK vanished from the row shape mid-table: fall back to
					// OFFSET for the rest of this table (duplicates in the
					// dump are harmless - the importer skips failing ones).
					$state['dump_mode'] = 'off';
					$state['offset']    = 0;
					UWP_Helpers::debug_log( sprintf( 'dump: table %s lost PK shape, falling back to OFFSET', $table ) );
				}
			} else {
				$state['offset'] += UWP_Helpers::safe_count( $rows );
			}
			$remaining -= UWP_Helpers::safe_count( $rows );

			// A short page means the table is exhausted; loop back to the
			// empty check above instead of querying pointlessly.
			if ( UWP_Helpers::safe_count( $rows ) < $page ) {
				continue;
			}
			if ( ( $deadline > 0 && microtime( true ) > $deadline ) || UWP_Helpers::memory_hot() ) {
				break;
			}
		}
		return false;
	}

	/**
	 * Import database.sql the way All-in-One WP Migration does it.
	 *
	 * - Streams the file line by line (fgets) and executes each complete
	 *   statement - memory stays flat no matter how big the dump is.
	 * - Resumes by exact byte offset (ftell), persisted between ticks.
	 * - Runs inside START TRANSACTION … COMMIT per call, so a dying tick
	 *   rolls back instead of leaving half-written tables.
	 * - Skips volatile junk (transients, WooCommerce / importer sessions)
	 *   instead of restoring stale cache rows.
	 * - Honors max_allowed_packet: oversized statements are skipped, never
	 *   fatal.
	 * - Timeout-checked after every statement (10s budget), EXCEPT for
	 *   atomic statements (DROP/CREATE and wp_options writes) which always
	 *   run to completion - the options table can never be left half-done.
	 *
	 * State: offset (byte position in the SQL file).
	 *
	 * @param string $sql_path Absolute database.sql path.
	 * @param array  $state Import state (by reference).
	 * @return bool True when the whole file has been imported.
	 */
	public static function import_tick( $sql_path, &$state ) {
		global $wpdb;

		$size = filesize( $sql_path );
		if ( ! isset( $state['offset'] ) ) {
			$state['offset'] = 0;
		}
		if ( $state['offset'] >= $size ) {
			return true;
		}

		$started      = microtime( true );
		$time_budget  = 10; // seconds of DB work per call (Ai1WM parity).
		$max_packet   = self::max_allowed_packet();
		$options_mark = 'INSERT INTO `' . $wpdb->prefix . 'options`';

		$handle = @fopen( $sql_path, 'rb' ); // phpcs:ignore
		if ( false === $handle ) {
			throw new Exception( __( 'Cannot open database.sql for import.', 'unboundwp' ) );
		}
		fseek( $handle, (int) $state['offset'] );

		// Per-request session settings, like Ai1WM.
		$wpdb->query( "SET SESSION sql_mode = ''" ); // phpcs:ignore
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore (no-op on MyISAM).

		$query     = null;
		$completed = true;
		$in_block  = false; // inside a multi-line /* … */ comment.

		while ( ( $line = fgets( $handle ) ) !== false ) {
			// Strip comment-only lines BEFORE accumulating, so a bare
			// comment line can never glue itself to the next statement
			// (which would silently swallow e.g. a DROP TABLE).
			// Executable MySQL conditionals (/*!…*/) are kept.
			if ( $in_block ) {
				if ( false !== strpos( $line, '*/' ) ) {
					$in_block = false;
				}
				$state['offset'] = ftell( $handle );
				continue;
			}
			$trimmed = ltrim( $line );
			if ( '' === $trimmed ) {
				$state['offset'] = ftell( $handle );
				continue;
			}
			if ( 0 === strpos( $trimmed, '--' ) || '#' === $trimmed[0] ) {
				$state['offset'] = ftell( $handle );
				continue;
			}
			if ( 0 === strpos( $trimmed, '/*' ) && 0 !== strpos( $trimmed, '/*!' ) ) {
				if ( false === strpos( $trimmed, '*/' ) ) {
					$in_block = true;
				}
				$state['offset'] = ftell( $handle );
				continue;
			}

			$query .= $line;

			// End of statement: semicolon at end of line. Our own dumper
			// and Ai1WM dumps both write one statement per line.
			if ( ! preg_match( '/;\s*$/S', $query ) ) {
				continue;
			}
			$sql   = trim( $query );
			$query = null;

			if ( '' === $sql || 0 === strpos( $sql, '--' ) || 0 === strpos( $sql, '/*' ) ) {
				$state['offset'] = ftell( $handle );
				continue;
			}
			if ( strlen( $sql ) > $max_packet ) {
				// Too big for this server - skip, never die.
				UWP_Helpers::debug_log( sprintf( 'import: skipped oversized statement (%d bytes)', strlen( $sql ) ) );
				$state['offset'] = ftell( $handle );
				continue;
			}
			if ( self::is_skippable_insert( $sql ) ) {
				$state['offset'] = ftell( $handle );
				continue;
			}

			// Table-prefix remap (dump → live), done in the data: statement
			// identifiers plus option_name/meta_key values only - never row
			// DATA, so serialized lengths stay valid by construction.
			$from_pfx = isset( $state['from_prefix'] ) ? (string) $state['from_prefix'] : '';
			$to_pfx   = isset( $state['to_prefix'] ) ? (string) $state['to_prefix'] : '';
			if ( '' !== $from_pfx && '' !== $to_pfx && $from_pfx !== $to_pfx ) {
				$sql = self::rewrite_statement_prefix( $sql, $from_pfx, $to_pfx );
			}

			// phpcs:ignore -- SQL comes from the backup itself.
			$result = $wpdb->query( $sql );
			if ( false === $result && '' !== $wpdb->last_error ) {
				// Duplicate-key convergence: a concurrent writer (or a
				// replayed batch after a hard death) may have put a row
				// with the same unique key first - most often fresh
				// widget defaults landing mid-import. Failing would keep
				// the STALE row and silently lose the dump's data, so
				// retry once as REPLACE INTO: the dump's row wins, whatever
				// the conflicting key is. Verbatim values, no parsing.
				if ( false !== stripos( $wpdb->last_error, 'Duplicate entry' )
					&& 0 === stripos( ltrim( $sql ), 'INSERT INTO' ) ) {
					$retry_sql = preg_replace( '/^INSERT INTO/i', 'REPLACE INTO', ltrim( $sql ), 1 );
					$retry     = ( null !== $retry_sql ) ? $wpdb->query( $retry_sql ) : false; // phpcs:ignore
					if ( false === $retry && '' !== $wpdb->last_error ) {
						self::maybe_throw_db_error( $wpdb->last_error );
					} elseif ( false !== $retry ) {
						if ( ! isset( $state['dupe_fixes'] ) ) {
							$state['dupe_fixes'] = 0;
						}
						$state['dupe_fixes'] = (int) $state['dupe_fixes'] + 1;
						if ( (int) $state['dupe_fixes'] <= 5 ) {
							UWP_Helpers::debug_log( sprintf( 'import: duplicate-key converged via REPLACE (%d so far): %.120s', (int) $state['dupe_fixes'], $sql ) );
						}
					}
				} else {
					self::maybe_throw_db_error( $wpdb->last_error );
				}
				// Other failures (e.g. version-specific clauses): keep going.
			}

			$state['offset'] = ftell( $handle );

			// Time check after every statement - except atomic ones, which
			// must complete so tables are never left half-written. Memory
			// is checked too: breaking to a tick boundary beats dying to
			// an out-of-memory fatal mid-batch (the COMMIT below still runs).
			if ( ! self::is_atomic_statement( $sql, $options_mark ) ) {
				if ( ( microtime( true ) - $started ) > $time_budget || UWP_Helpers::memory_hot() ) {
					$completed = false;
					break;
				}
			}
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore
		$eof = feof( $handle );
		fclose( $handle );
		return $completed && $eof;
	}

	/**
	 * Skip volatile rows that must never be restored: transients and
	 * session tables (WooCommerce, WP All Import). Restoring them only
	 * brings back stale cache locks and dead sessions.
	 *
	 * @param string $sql Complete statement.
	 * @return bool
	 */
	protected static function is_skippable_insert( $sql ) {
		return false !== strpos( $sql, "'_transient_" )
			|| false !== strpos( $sql, "'_site_transient_" )
			|| false !== strpos( $sql, "'_wc_session_" )
			|| false !== strpos( $sql, "'_wpallimport_session_" );
	}

	/**
	 * Statements that must run to completion even past the time budget:
	 * schema changes plus wp_options writes (a half-written options table
	 * breaks the whole site).
	 *
	 * @param string $sql Complete statement.
	 * @param string $options_mark INSERT prefix for the options table.
	 * @return bool
	 */
	protected static function is_atomic_statement( $sql, $options_mark ) {
		if ( 0 === strpos( $sql, 'DROP TABLE' ) || 0 === strpos( $sql, 'CREATE TABLE' )
			|| 0 === strpos( $sql, 'DROP VIEW' ) || 0 === strpos( $sql, 'CREATE VIEW' )
			|| 0 === strpos( $sql, 'START TRANSACTION' ) || 0 === strpos( $sql, 'COMMIT' ) ) {
			return true;
		}
		return 0 === stripos( $sql, $options_mark );
	}

	/**
	 * This server's max_allowed_packet, cached per request.
	 *
	 * @return int Bytes.
	 */
	protected static function max_allowed_packet() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$cached = 16 * 1024 * 1024; // sane default when undetectable.
		global $wpdb;
		if ( isset( $wpdb ) ) {
			$row = $wpdb->get_row( "SHOW VARIABLES LIKE 'max_allowed_packet'", ARRAY_N ); // phpcs:ignore
			if ( is_array( $row ) && isset( $row[1] ) && (int) $row[1] > 0 ) {
				$cached = (int) $row[1];
			}
		}
		return $cached;
	}

	/**
	 * option_name / meta_key values that must NEVER be prefix-mapped, even
	 * when they start with the dump prefix (they are hardcoded `wp_` names,
	 * not prefix-derived). Mirrors the reference implementation's list.
	 *
	 * @return array Full key names.
	 */
	public static function reserved_key_names() {
		return array(
			'wp_force_deactivated_plugins',
			'wp_page_for_privacy_policy',
			'wp_rocket_settings',
			'wp_rocket_dismiss_imagify_notice',
			'wp_rocket_no_licence',
			'wp_rocket_rocketcdn_old_url',
			'wp_rocket_hide_deactivation_form',
		);
	}

	/**
	 * Rewrite one SQL statement's table prefix (dump → live).
	 *
	 * Scope is deliberately narrow so row DATA can never be touched:
	 * - DDL (DROP/CREATE/VIEW/LOCK): every `` `old `` occurrence is an
	 *   identifier (backticks never quote data), including FOREIGN KEY
	 *   references and view bodies.
	 * - INSERT: only the leading table name (anchored) plus - for the
	 *   options/usermeta tables - the single option_name/meta_key value,
	 *   parsed positionally (never regexed inside blobs, so serialized
	 *   lengths cannot break).
	 * Anything unparseable is returned unchanged (safe degradation).
	 *
	 * @param string $sql Complete statement.
	 * @param string $from Dump prefix.
	 * @param string $to Live prefix.
	 * @return string Possibly-rewritten statement.
	 */
	public static function rewrite_statement_prefix( $sql, $from, $to ) {
		if ( ! is_string( $sql ) || '' === $sql || ! is_string( $from ) || '' === $from
			|| ! is_string( $to ) || '' === $to || $from === $to ) {
			return $sql;
		}
		// DDL: backticked occurrences only.
		if ( preg_match( '/^\s*(DROP\s+TABLE|CREATE\s+TABLE|DROP\s+VIEW|CREATE(?:\s+\S+)*\s+VIEW|LOCK\s+TABLES|UNLOCK\s+TABLES|CREATE\s+TRIGGER|DROP\s+TRIGGER)\b/i', $sql ) ) {
			return str_replace( '`' . $from, '`' . $to, $sql );
		}
		// INSERT: anchored leading table name.
		if ( preg_match( '/^(\s*INSERT\s+INTO\s+`?)' . preg_quote( $from, '/' ) . '(?=[A-Za-z0-9_]*`?\s*\()/i', $sql, $m ) ) {
			$sql = $m[1] . $to . substr( $sql, strlen( $m[0] ) );
			return self::rewrite_insert_keys( $sql, $from, $to );
		}
		return $sql;
	}

	/**
	 * Rewrite the option_name / meta_key value inside an options/usermeta
	 * INSERT (already mapped to live table names). Positional parse of the
	 * column list finds the key column; only that literal is remapped.
	 * Multi-tuple or unparseable statements are returned unchanged.
	 *
	 * @param string $sql Complete INSERT statement (live table names).
	 * @param string $from Dump prefix.
	 * @param string $to Live prefix.
	 * @return string Possibly-rewritten statement.
	 */
	public static function rewrite_insert_keys( $sql, $from, $to ) {
		if ( ! preg_match( '/^(\s*INSERT\s+INTO\s+`?)(' . preg_quote( $to, '/' ) . '[A-Za-z0-9_]*)(`?\s*\()/i', $sql, $m ) ) {
			return $sql;
		}
		$table = $m[2];
		$key_col = false;
		if ( substr( $table, -strlen( 'options' ) ) === 'options' ) {
			$key_col = 'option_name';
		} elseif ( substr( $table, -strlen( 'usermeta' ) ) === 'usermeta' ) {
			$key_col = 'meta_key';
		} else {
			return $sql;
		}
		// Column list: first top-level (...) group (backticked, no nesting).
		$open = strpos( $sql, '(', strlen( $m[0] ) - strlen( $m[3] ) );
		if ( false === $open ) {
			return $sql;
		}
		$close = strpos( $sql, ')', $open );
		if ( false === $close ) {
			return $sql;
		}
		$cols = array_map( 'trim', explode( ',', substr( $sql, $open + 1, $close - $open - 1 ) ) );
		$idx  = -1;
		foreach ( $cols as $i => $col ) {
			if ( trim( $col, '` ' ) === $key_col ) {
				$idx = $i;
				break;
			}
		}
		if ( $idx < 0 ) {
			return $sql;
		}
		// VALUES group must be single-tuple; anything else: leave untouched.
		$values_pos = stripos( $sql, 'VALUES', $close );
		if ( false === $values_pos ) {
			return $sql;
		}
		$vopen = strpos( $sql, '(', $values_pos );
		if ( false === $vopen ) {
			return $sql;
		}
		$vals = self::split_sql_tuple( $sql, $vopen );
		if ( null === $vals || UWP_Helpers::safe_count( $vals['items'] ) !== UWP_Helpers::safe_count( $cols ) ) {
			return $sql;
		}
		// Ensure no second tuple follows (multi-row INSERT): anything but
		// whitespace/semicolon after the tuple means hands off.
		$tail = trim( substr( $sql, $vals['end'] + 1 ), " \t\n\r;" );
		if ( '' !== $tail ) {
			return $sql;
		}
		if ( ! isset( $vals['items'][ $idx ] ) ) {
			return $sql;
		}
		$new_literal = self::remap_key_literal( $vals['items'][ $idx ], $from, $to );
		if ( null === $new_literal ) {
			return $sql;
		}
		return substr( $sql, 0, $vals['starts'][ $idx ] ) . $new_literal . substr( $sql, $vals['ends'][ $idx ] );
	}

	/**
	 * Remap one SQL string literal's leading dump prefix (quote-aware).
	 *
	 * @param string $literal Literal including quotes, e.g. `'wp_capabilities'`.
	 * @param string $from Dump prefix.
	 * @param string $to Live prefix.
	 * @return string|null New literal, or null when it must stay untouched
	 *                     (not a plain string, reserved name, no prefix).
	 */
	public static function remap_key_literal( $literal, $from, $to ) {
		if ( strlen( $literal ) < 2 || '\'' !== $literal[0] || '\'' !== substr( $literal, -1 ) ) {
			return null;
		}
		$inner = substr( $literal, 1, -1 );
		if ( 0 !== strpos( $inner, $from ) ) {
			return null;
		}
		// Unescape to test the real key name (handles \' and \\).
		$key = stripcslashes( $inner );
		if ( in_array( $key, self::reserved_key_names(), true ) ) {
			return null;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $key ) ) {
			return null;
		}
		// Validated keys hold no quotes/backslashes, so plain quoting is exact.
		return "'" . $to . substr( $key, strlen( $from ) ) . "'";
	}

	/**
	 * Split one top-level (...) tuple into items, quote/escape aware.
	 *
	 * Handles backslash escapes and ''-doubled quotes. Returns null when
	 * the tuple does not close cleanly.
	 *
	 * @param string $sql Full statement.
	 * @param int    $open Offset of the opening paren.
	 * @return array|null array(items, starts, ends, end) or null.
	 */
	public static function split_sql_tuple( $sql, $open ) {
		$len    = strlen( $sql );
		$items  = array();
		$starts = array();
		$ends   = array();
		$depth  = 0;
		$in_str = false;
		$cur    = '';
		$cstart = $open + 1;
		for ( $i = $open; $i < $len; $i++ ) {
			$ch = $sql[ $i ];
			if ( $in_str ) {
				$cur .= $ch;
				if ( '\\' === $ch && $i + 1 < $len ) {
					$cur .= $sql[ $i + 1 ];
					$i++;
				} elseif ( '\'' === $ch ) {
					if ( $i + 1 < $len && '\'' === $sql[ $i + 1 ] ) {
						$cur .= '\'';
						$i++;
					} else {
						$in_str = false;
					}
				}
				continue;
			}
			if ( '\'' === $ch ) {
				$in_str = true;
				$cur   .= $ch;
				continue;
			}
			if ( '(' === $ch ) {
				$depth++;
				if ( $depth > 1 ) {
					$cur .= $ch;
				}
				continue;
			}
			if ( ')' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					$items[]  = trim( $cur );
					$starts[] = $cstart;
					$ends[]   = $i;
					return array(
						'items'  => $items,
						'starts' => $starts,
						'ends'   => $ends,
						'end'    => $i,
					);
				}
				$cur .= $ch;
				continue;
			}
			if ( ',' === $ch && 1 === $depth ) {
				$items[]  = trim( $cur );
				$starts[] = $cstart;
				$ends[]   = $i;
				$cur      = '';
				$cstart   = $i + 1;
				continue;
			}
			$cur .= $ch;
		}
		return null;
	}

	/**
	 * Write all bytes or throw (disk-full and stream errors become a clear
	 * message instead of a silently truncated dump).
	 *
	 * @param resource $handle Open file handle.
	 * @param string   $data Bytes to write.
	 * @throws Exception
	 */
	protected static function fwrite_all( $handle, $data ) {
		$len = strlen( $data );
		$off = 0;
		while ( $off < $len ) {
			$w = @fwrite( $handle, substr( $data, $off ) ); // phpcs:ignore
			if ( false === $w || 0 === $w ) {
				throw new Exception( __( 'Cannot write database dump (disk full?). Free space and resume.', 'unboundwp' ) );
			}
			$off += $w;
		}
	}

	/**
	 * Turn unrecoverable database errors into a clear message instead of a
	 * silent skip: missing tablespace/privileges will fail every retry, so
	 * say so immediately.
	 *
	 * @param string $last_error $wpdb->last_error text.
	 * @throws Exception
	 */
	protected static function maybe_throw_db_error( $last_error ) {
		if ( false !== stripos( $last_error, 'tablespace' ) || false !== stripos( $last_error, 'access denied' ) ) {
			throw new Exception( sprintf( __( 'Database error during restore: %s', 'unboundwp' ), $last_error ) );
		}
	}


	// ------------------------------------------------------------------
	// Search / replace (serialization-safe)
	// ------------------------------------------------------------------

	/**
	 * Apply replacement pairs across every table, in batches.
	 * Handles PHP-serialized strings so widgets/options don't corrupt.
	 *
	 * State: table_index, offset, needles (cached).
	 *
	 * @param array $pairs Replacement pairs from build_replacements().
	 * @param array $state Progress state (by reference).
	 * @param int   $rows_per_tick Rows per tick.
	 * @return bool True when finished.
	 */
	public static function replace_tick( $pairs, &$state, $rows_per_tick = 200 ) {
		global $wpdb;

		if ( empty( $pairs ) || ! is_array( $pairs ) ) {
			return true;
		}
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		if ( ! isset( $state['tables'] ) || ! is_array( $state['tables'] ) ) {
			$state['tables']      = self::all_tables();
			$state['table_index'] = 0;
			$state['offset']      = 0;
		}
		if ( ! isset( $state['table_index'] ) ) {
			$state['table_index'] = 0;
		}
		if ( ! isset( $state['offset'] ) ) {
			$state['offset'] = 0;
		}
		if ( ! isset( $state['needles'] ) || ! is_array( $state['needles'] ) ) {
			$state['needles'] = self::needles_from_pairs( $pairs );
		}
		$tables = is_array( $state['tables'] ) ? $state['tables'] : array();
		if ( (int) $state['table_index'] >= UWP_Helpers::safe_count( $tables ) ) {
			return true;
		}

		$table = isset( $tables[ (int) $state['table_index'] ] ) ? $tables[ (int) $state['table_index'] ] : null;
		if ( ! is_string( $table ) || '' === $table ) {
			$state['table_index'] = (int) $state['table_index'] + 1;
			$state['offset']      = 0;
			return (int) $state['table_index'] >= UWP_Helpers::safe_count( $tables );
		}

		// Shrink batches when memory runs hot: a few small rows beat one
		// fatal batch. Rows already fetched are still processed below.
		if ( UWP_Helpers::memory_hot() ) {
			$rows_per_tick = min( $rows_per_tick, 25 );
		}

		// Primary key for stable paging (fall back to OFFSET when none).
		$pk = self::primary_key( $table );

		if ( $pk ) {
			// phpcs:ignore -- internal identifiers.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` > %d ORDER BY `{$pk}` ASC LIMIT %d", (int) $state['offset'], $rows_per_tick ), ARRAY_A );
		} else {
			// phpcs:ignore -- internal identifiers.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $rows_per_tick, (int) $state['offset'] ), ARRAY_A );
		}

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			$state['table_index'] = (int) $state['table_index'] + 1;
			$state['offset'] = 0;
			return (int) $state['table_index'] >= UWP_Helpers::safe_count( $tables );
		}

		$n = 0;
		$needles = is_array( $state['needles'] ) ? $state['needles'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$dirty = false;
			$pkval = $pk && isset( $row[ $pk ] ) ? $row[ $pk ] : null;

			// Transient cache rows are regenerable: skip them entirely
			// instead of paying to unserialize megabytes of stale cache
			// (a classic out-of-memory source on real-world databases).
			// Routing-structure rows (permalink_structure) are never
			// rewritten either: they are domain-independent by design.
			if ( ! self::is_transient_option_row( $table, $row ) && ! self::is_protected_option_row( $table, $row ) ) {
				foreach ( $row as $col => $value ) {
					if ( ! is_string( $value ) || ! self::contains_any( $value, $needles ) ) {
						continue;
					}
					$new = self::replace_with_pairs( $value, $pairs );
					if ( $new !== $value ) {
						$row[ $col ] = $new;
						$dirty       = true;
					}
				}
			}

			if ( $dirty ) {
				if ( null !== $pkval ) {
					$wpdb->update( $table, $row, array( $pk => $pkval ) ); // phpcs:ignore
				} else {
					// No PK: LIKE-scoped REPLACE per column, only for pairs
					// actually present in this row's values.
					self::replace_row_no_pk( $table, $row, $pairs );
				}
			}

			if ( null !== $pkval ) {
				$state['offset'] = max( (int) $state['offset'], (int) $pkval );
			}

			// Release cycles as we go so giant rows can't pile up.
			if ( 0 === ( ++$n % 25 ) ) {
				unset( $row );
				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
			}
		}
		// Count BEFORE releasing: count() on the unset variable below is a
		// fatal TypeError on PHP 8 (this exact crash reached production).
		$row_count = UWP_Helpers::safe_count( $rows );
		unset( $rows );

		if ( ! $pk ) {
			$state['offset'] += $row_count;
		}
		return false;
	}

	/**
	 * Whether a row is a regenerable transient cache entry.
	 *
	 * Pure function of its inputs (prefix falls back to wp_). Skipped on
	 * import (never restored) and in replacement (never worth the memory).
	 *
	 * @param string $table Table name.
	 * @param array  $row Row values.
	 * @return bool
	 */
	public static function is_transient_option_row( $table, $row ) {
		global $wpdb;
		$prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
		if ( $table !== $prefix . 'options' || ! isset( $row['option_name'] ) || ! is_string( $row['option_name'] ) ) {
			return false;
		}
		return 0 === strpos( $row['option_name'], '_transient_' ) || 0 === strpos( $row['option_name'], '_site_transient_' );
	}

	/**
	 * Whether a row holds URL-routing structure that must never be rewritten.
	 *
	 * permalink_structure is domain-independent (tags only): no replacement
	 * pair can legitimately match it, and any rewrite risks breaking every
	 * URL on the site. Skipped defensively in replacement.
	 *
	 * @param string $table Table name.
	 * @param array  $row Row values.
	 * @return bool
	 */
	public static function is_protected_option_row( $table, $row ) {
		global $wpdb;
		$prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
		if ( $table !== $prefix . 'options' || ! isset( $row['option_name'] ) || ! is_string( $row['option_name'] ) ) {
			return false;
		}
		return 'permalink_structure' === $row['option_name'];
	}

	/**
	 * Find the single-column primary key of a table, if any.
	 *
	 * @param string $table Table name.
	 * @return string|null
	 */
	protected static function primary_key( $table ) {
		global $wpdb;
		// phpcs:ignore -- internal table name.
		$keys = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
		if ( ! is_array( $keys ) || UWP_Helpers::safe_count( $keys ) !== 1 ) {
			return null;
		}
		return isset( $keys[0]['Column_name'] ) ? $keys[0]['Column_name'] : null;
	}

	/**
	 * Fallback replace for tables without a primary key.
	 *
	 * @param string $table Table name.
	 * @param array  $row Current row values (already replaced).
	 * @param array  $pairs Replacement pairs.
	 */
	protected static function replace_row_no_pk( $table, $row, $pairs ) {
		global $wpdb;
		if ( ! is_array( $row ) || ! is_array( $pairs ) ) {
			return;
		}
		foreach ( $row as $col => $value ) {
			if ( ! is_string( $value ) || ! is_string( $col ) ) {
				continue;
			}
			foreach ( $pairs as $pair ) {
				if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) || ! is_string( $pair[0] ) || '' === $pair[0] ) {
					continue;
				}
				if ( false === strpos( $value, $pair[0] ) ) {
					continue;
				}
				// phpcs:ignore -- internal identifiers; values escaped below.
				$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET `{$col}` = REPLACE(`{$col}`, %s, %s) WHERE `{$col}` LIKE %s", $pair[0], $pair[1], '%' . $wpdb->esc_like( $pair[0] ) . '%' ) );
			}
		}
	}

	/**
	 * Replace inside a string, fixing serialized lengths.
	 *
	 * @param string $data Haystack (may be serialized).
	 * @param string $from Needle.
	 * @param string $to Replacement.
	 * @return string
	 */
	public static function replace_string( $data, $from, $to ) {
		if ( false === strpos( $data, $from ) ) {
			return $data;
		}
		// Fast path: not serialized.
		if ( ! self::looks_serialized( $data ) ) {
			return str_replace( $from, $to, $data );
		}
		// Serialized: unserialize, recurse, re-serialize (lengths fixed).
		$un = @unserialize( $data ); // phpcs:ignore
		if ( false === $un && 'b:0;' !== $data ) {
			return str_replace( $from, $to, $data );
		}
		$fixed = self::replace_recursive( $un, $from, $to );
		return serialize( $fixed ); // phpcs:ignore
	}

	/**
	 * Recurse into arrays/objects/strings.
	 *
	 * @param mixed  $data Anything.
	 * @param string $from Old.
	 * @param string $to New.
	 * @return mixed
	 */
	public static function replace_recursive( $data, $from, $to ) {
		if ( is_string( $data ) ) {
			return self::replace_string( $data, $from, $to );
		}
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$new_k = is_string( $k ) ? self::replace_string( $k, $from, $to ) : $k;
				$out[ $new_k ] = self::replace_recursive( $v, $from, $to );
			}
			return $out;
		}
		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = self::replace_recursive( $v, $from, $to );
			}
			return $data;
		}
		return $data;
	}

	/**
	 * Cheap check before paying for unserialize().
	 *
	 * @param string $data String.
	 * @return bool
	 */
	protected static function looks_serialized( $data ) {
		$data = trim( (string) $data );
		if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
			return false;
		}
		return in_array( $data[0], array( 'a', 's', 'O', 'b', 'i', 'd' ), true );
	}

	// ------------------------------------------------------------------
	// Replacement pairs (Ai1WM-style URL variant coverage)
	// ------------------------------------------------------------------

	/**
	 * Build the full old→new replacement list, like the original plugin.
	 *
	 * A bare SiteURL swap misses most real content: page builders and
	 * widgets store http/https/schemeless, www/non-www, URL-encoded and
	 * JSON-escaped variants, plus uploads URLs, emails and server paths.
	 * Every variant maps to its new-site counterpart here.
	 *
	 * @param string $old_site Old Site URL.
	 * @param string $old_home Old Home URL.
	 * @param string $new_site New Site URL.
	 * @param string $new_home New Home URL.
	 * @param string $old_uploads Old uploads base URL (optional).
	 * @param string $new_uploads New uploads base URL (optional).
	 * @param string $old_base Old ABSPATH (optional).
	 * @param string $new_base New ABSPATH (optional).
	 * @param string $old_content Old content dir (optional).
	 * @param string $new_content New content dir (optional).
	 * @return array List of array($old, $new), longest-old-first.
	 */
	public static function build_replacements( $old_site, $old_home, $new_site, $new_home, $old_uploads = '', $new_uploads = '', $old_base = '', $new_base = '', $old_content = '', $new_content = '' ) {
		$pairs = array();
		$add   = function ( $old, $new ) use ( &$pairs ) {
			if ( '' === (string) $old || $old === $new ) {
				return;
			}
			$pairs[ (string) $old ] = (string) $new;
		};

		// URL pairs: site, home and uploads, each with www/scheme variants.
		$url_sets = array(
			array( $old_site, $new_site ),
			array( $old_home, $new_home ),
			array( $old_uploads, $new_uploads ),
		);
		foreach ( $url_sets as $set ) {
			list( $old, $new ) = $set;
			$old = trim( (string) $old );
			$new = trim( (string) $new );
			if ( '' === $old || '' === $new || $old === $new ) {
				continue;
			}
			// Both sides must be absolute URLs with hosts; otherwise the
			// positional variant mapping cannot align (e.g. a bare path
			// would map onto the wrong new variant).
			$old_host = parse_url( $old, PHP_URL_HOST );
			$new_host = parse_url( $new, PHP_URL_HOST );
			if ( empty( $old_host ) || empty( $new_host ) ) {
				continue;
			}
			$old_variants = self::url_variants( $old );
			$new_variants = self::url_variants( $new );
			// Positional mapping: plain→plain, http→http, … (same count by
			// construction when both parse as absolute URLs).
			$count = min( UWP_Helpers::safe_count( $old_variants ), UWP_Helpers::safe_count( $new_variants ) );
			for ( $i = 0; $i < $count; $i++ ) {
				$ov = $old_variants[ $i ];
				$nv = $new_variants[ $i ];
				$add( $ov, $nv );
				$add( urlencode( $ov ), urlencode( $nv ) );
				$add( rawurlencode( $ov ), rawurlencode( $nv ) );
				$add( addcslashes( $ov, '/' ), addcslashes( $nv, '/' ) );
			}
			// Quoted bare hosts (JSON attributes etc.), never bare hosts
			// (a bare replace would corrupt e.g. myold.com).
			$old_host = strtolower( (string) parse_url( $old, PHP_URL_HOST ) );
			$new_host = strtolower( (string) parse_url( $new, PHP_URL_HOST ) );
			if ( '' !== $old_host && '' !== $new_host && $old_host !== $new_host ) {
				foreach ( array( '="', "='", '://' ) as $prefix ) {
					$add( $prefix . $old_host, $prefix . $new_host );
				}
				$add( '@' . $old_host, '@' . $new_host ); // emails.
			}
		}

		// Server paths (only when source and target really differ).
		$path_sets = array(
			array( $old_base, $new_base ),
			array( $old_content, $new_content ),
		);
		foreach ( $path_sets as $set ) {
			list( $old, $new ) = $set;
			$old = str_replace( '\\', '/', trim( (string) $old ) );
			$new = str_replace( '\\', '/', trim( (string) $new ) );
			if ( '' === $old || '' === $new || $old === $new ) {
				continue;
			}
			$add( rtrim( $old, '/' ) . '/', rtrim( $new, '/' ) . '/' );
			$add( rtrim( $old, '/' ), rtrim( $new, '/' ) );
		}

		// Longest-old-first so `https://old.com/sub` wins over `https://old.com`.
		uksort(
			$pairs,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$out = array();
		foreach ( $pairs as $o => $n ) {
			$out[] = array( $o, $n );
		}
		return $out;
	}

	/**
	 * Scheme × www variants of one absolute URL, in fixed canonical order.
	 *
	 * The order must NEVER depend on the input (no scheme-first): old and
	 * new URLs usually have different schemes, and positional mapping only
	 * works when variant[i] means the same thing for both sides.
	 *
	 * @param string $url Absolute URL.
	 * @return array Variant strings (identical positions for any input).
	 */
	protected static function url_variants( $url ) {
		$url   = rtrim( trim( (string) $url ), '/' );
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return array( $url );
		}
		$host = $parts['host'];
		$rest = '';
		if ( isset( $parts['path'] ) ) {
			$rest .= $parts['path'];
		}
		if ( isset( $parts['query'] ) ) {
			$rest .= '?' . $parts['query'];
		}
		$rest = rtrim( $rest, '/' );
		$www  = 'www.' . preg_replace( '/^www\./i', '', $host );
		$bare = preg_replace( '/^www\./i', '', $host );

		$hosts = array_unique( array( $host, $www, $bare ) );
		$out   = array();
		foreach ( array( 'http', 'https', '' ) as $s ) {
			$prefix = '' === $s ? '//' : $s . '://';
			foreach ( $hosts as $h ) {
				$out[] = $prefix . $h . $rest;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Short distinctive needles for the fast prefilter (hosts of all olds).
	 *
	 * @param array $pairs Replacement pairs.
	 * @return array Unique host-ish strings.
	 */
	protected static function needles_from_pairs( $pairs ) {
		$needles = array();
		if ( ! is_array( $pairs ) ) {
			return array();
		}
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair[0] ) || ! is_string( $pair[0] ) || '' === $pair[0] ) {
				continue;
			}
			$old  = $pair[0];
			$host = parse_url( $old, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$needles[ strtolower( $host ) ] = true;
			} elseif ( false === strpos( $old, '://' ) && false === strpos( $old, '/' ) && strlen( $old ) > 3 ) {
				$needles[ strtolower( ltrim( $old, '@="\'(' ) ) ] = true;
			}
		}
		return array_keys( $needles );
	}

	/**
	 * Apply all pairs to a string (single serialized round-trip at most).
	 *
	 * @param string $data Haystack (may be serialized).
	 * @param array  $pairs Replacement pairs.
	 * @return string
	 */
	public static function replace_with_pairs( $data, $pairs ) {
		if ( ! is_string( $data ) || '' === $data || ! is_array( $pairs ) || empty( $pairs ) ) {
			return $data;
		}
		if ( ! self::contains_any( $data, self::needles_from_pairs( $pairs ) ) ) {
			return $data;
		}
		if ( ! self::looks_serialized( $data ) ) {
			foreach ( $pairs as $pair ) {
				if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) || ! is_string( $pair[0] ) || '' === $pair[0] ) {
					continue;
				}
				if ( false !== strpos( $data, $pair[0] ) ) {
					$data = str_replace( $pair[0], $pair[1], $data );
				}
			}
			return $data;
		}
		$un = @unserialize( $data ); // phpcs:ignore
		if ( false === $un && 'b:0;' !== $data ) {
			foreach ( $pairs as $pair ) {
				if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) || ! is_string( $pair[0] ) || '' === $pair[0] ) {
					continue;
				}
				if ( false !== strpos( $data, $pair[0] ) ) {
					$data = str_replace( $pair[0], $pair[1], $data );
				}
			}
			return $data;
		}
		return serialize( self::replace_pairs_recursive( $un, $pairs ) ); // phpcs:ignore
	}

	/**
	 * Recurse into arrays/objects/strings applying every pair.
	 *
	 * @param mixed $data Anything.
	 * @param array $pairs Replacement pairs.
	 * @return mixed
	 */
	public static function replace_pairs_recursive( $data, $pairs ) {
		if ( is_string( $data ) ) {
			return self::replace_with_pairs( $data, $pairs );
		}
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$new_k       = is_string( $k ) ? self::replace_with_pairs( $k, $pairs ) : $k;
				$out[ $new_k ] = self::replace_pairs_recursive( $v, $pairs );
			}
			return $out;
		}
		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = self::replace_pairs_recursive( $v, $pairs );
			}
			return $data;
		}
		return $data;
	}

	/**
	 * True when $haystack contains any of $needles (case-insensitive).
	 *
	 * This is only a skip optimization - admitting extra values is always
	 * safe because the actual replacement stays case-sensitive and a
	 * no-change result is never written back.
	 *
	 * @param string $haystack Haystack.
	 * @param array  $needles Needles.
	 * @return bool
	 */
	protected static function contains_any( $haystack, $needles ) {
		if ( ! is_string( $haystack ) || '' === $haystack || ! is_array( $needles ) || empty( $needles ) ) {
			return false;
		}
		foreach ( $needles as $needle ) {
			if ( ! is_string( $needle ) || '' === $needle ) {
				continue;
			}
			if ( false !== stripos( $haystack, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
