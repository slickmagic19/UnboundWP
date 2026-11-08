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
				// Views must be recreated as views: DROP TABLE against a
				// view errors out and leaves the stale definition behind.
				$is_view = (bool) preg_match( '/^\s*CREATE\b[\s\S]*?\bVIEW\b/i', (string) $create[1] );
				self::fwrite_all( $out, ( $is_view ? "DROP VIEW IF EXISTS `{$table}`;\n" : "DROP TABLE IF EXISTS `{$table}`;\n" ) );
				self::fwrite_all( $out, $create[1] . ";\n" );
			} else {
				UWP_Helpers::debug_log( sprintf( 'dump: WARNING: SHOW CREATE TABLE failed for %s (%s)', $table, (string) $wpdb->last_error ) );
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
	 * - Streams the file in 64 KB chunks and executes each complete
	 *   statement - memory stays flat no matter how big the dump is.
	 * - Resumes by exact byte offset, persisted between ticks.
	 * - Runs inside START TRANSACTION … COMMIT per call, so a dying tick
	 *   rolls back instead of leaving half-written tables.
	 * - Skips volatile junk (transients, WooCommerce / importer sessions)
	 *   instead of restoring stale cache rows.
	 * - Honors max_allowed_packet: oversized statements are skipped, never
	 *   fatal.
	 * - Timeout-checked after every statement (adaptive budget), EXCEPT for
	 *   atomic statements (DROP/CREATE and wp_options writes) which always
	 *   run to completion - the options table can never be left half-done.
	 *
	 * Statement parsing is quote/comment/delimiter aware (see
	 * next_statement()): multi-line statements, ';' inside values and
	 * `DELIMITER $$` routine dumps all import correctly. The old
	 * "line ending with ;" heuristic silently cut rows whose text
	 * contained a newline.
	 *
	 * State: offset (byte position in the SQL file), delimiter.
	 *
	 * @param string $sql_path Absolute database.sql path.
	 * @param array  $state Import state (by reference).
	 * @return bool True when the whole file has been imported.
	 * @throws Exception On unrecoverable database errors.
	 */
	public static function import_tick( $sql_path, &$state ) {
		global $wpdb;

		if ( ! is_string( $sql_path ) || '' === $sql_path || ! is_file( $sql_path ) ) {
			throw new Exception( __( 'database.sql is missing from the backup.', 'unboundwp' ) );
		}
		$size = (int) filesize( $sql_path );
		if ( ! isset( $state['offset'] ) || ! is_numeric( $state['offset'] ) ) {
			$state['offset'] = 0;
		}
		$state['offset'] = max( 0, (int) $state['offset'] );
		if ( $state['offset'] >= $size ) {
			return true;
		}

		$started      = microtime( true );
		$deadline     = $started + UWP_Helpers::time_budget();
		$max_packet   = self::max_allowed_packet();
		$options_mark = 'INSERT INTO `' . $wpdb->prefix . 'options`';
		$delimiter    = ( isset( $state['delimiter'] ) && is_string( $state['delimiter'] ) && '' !== $state['delimiter'] )
			? $state['delimiter']
			: ';';

		$handle = @fopen( $sql_path, 'rb' ); // phpcs:ignore
		if ( false === $handle ) {
			throw new Exception( __( 'Cannot open database.sql for import.', 'unboundwp' ) );
		}
		if ( 0 !== fseek( $handle, (int) $state['offset'] ) ) {
			fclose( $handle );
			throw new Exception( __( 'Cannot seek inside database.sql.', 'unboundwp' ) );
		}

		// Per-request session settings, like Ai1WM.
		$wpdb->query( "SET SESSION sql_mode = ''" ); // phpcs:ignore
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore (no-op on MyISAM).

		$buffer    = '';                       // bytes read but not consumed yet
		$read_pos  = (int) $state['offset'];   // file position after the buffer
		$eof       = false;
		$running   = true;
		$last_err  = '';
		$skipped   = 0;

		while ( $running ) {
			if ( microtime( true ) >= $deadline || UWP_Helpers::memory_hot() ) {
				break;
			}

			// MySQL-client directive: switch the statement terminator.
			if ( preg_match( '/^\s*DELIMITER\s+(\S+)[^\n]*\n/i', $buffer, $dm ) ) {
				$delimiter = $dm[1];
				$buffer    = substr( $buffer, strlen( $dm[0] ) );
				$state['delimiter'] = $delimiter;
				$state['offset']    = $read_pos - strlen( $buffer );
				continue;
			}

			$next = self::next_statement( $buffer, $delimiter );
			if ( null === $next['sql'] ) {
				if ( $eof ) {
					// Trailing bytes with no terminator: a truncated dump.
					// Executing a partial statement would only error out,
					// so report it and finish - every complete statement
					// before it has already landed.
					if ( '' !== trim( $buffer ) ) {
						UWP_Helpers::debug_log( sprintf( 'import: dump ends with an unterminated statement (%d bytes), skipped', strlen( trim( $buffer ) ) ) );
						$state['unterminated_tail'] = true;
					}
					$state['offset'] = $size;
					$buffer = '';
					break;
				}
				$chunk = fread( $handle, 65536 ); // phpcs:ignore
				if ( false === $chunk || '' === $chunk ) {
					$eof = true;
					continue;
				}
				$buffer   .= $chunk;
				$read_pos += strlen( $chunk );

				// A single statement bigger than max_allowed_packet can
				// never execute on this server. Drop it (and resync on a
				// line boundary) instead of growing memory forever.
				if ( strlen( $buffer ) > $max_packet ) {
					$cut = strpos( $buffer, $delimiter );
					if ( false === $cut ) {
						$nl  = strrpos( $buffer, "\n" );
						$cut = ( false === $nl ) ? strlen( $buffer ) - 1 : $nl;
					}
					$buffer = substr( $buffer, $cut + strlen( $delimiter ) );
					$state['offset'] = $read_pos - strlen( $buffer );
					$skipped++;
					UWP_Helpers::debug_log( sprintf( 'import: skipped an oversized statement (> %d bytes)', $max_packet ) );
				}
				continue;
			}

			$sql    = $next['sql'];
			$buffer = $next['rest'];

			$trimmed = trim( (string) $sql );
			if ( '' === $trimmed || self::is_comment_only( $trimmed ) ) {
				$state['offset'] = $read_pos - strlen( $buffer );
				continue;
			}
			if ( strlen( $sql ) > $max_packet ) {
				// Too big for this server - skip, never die.
				UWP_Helpers::debug_log( sprintf( 'import: skipped oversized statement (%d bytes)', strlen( $sql ) ) );
				$state['offset'] = $read_pos - strlen( $buffer );
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

			// Volatile rows are detected AFTER the remap (the check keys on
			// the live table name) and only for options INSERTs.
			if ( self::is_skippable_insert( $sql ) ) {
				$state['offset'] = $read_pos - strlen( $buffer );
				continue;
			}

			// Dumps from another server can carry collations/charsets this
			// one has never heard of (classic: MariaDB 11's
			// utf8mb3_uca1400_ai_ci imported into MySQL 8). Normalize them
			// before the statement runs, or every such table fails to exist.
			if ( self::is_schema_statement( $sql ) ) {
				$sql = self::normalize_ddl_collations( $sql, $state );
			}

			// phpcs:ignore -- SQL comes from the backup itself.
			// Hide wpdb's HTML "WordPress database error" output while the
			// statement runs: a printed error would corrupt the JSON answer
			// of the AJAX tick. last_error is still readable for our own
			// reporting, and suppression is restored immediately.
			$prev_suppress = $wpdb->suppress_errors( true );
			try {
				$result = $wpdb->query( $sql ); // phpcs:ignore
			} finally {
				$wpdb->suppress_errors( $prev_suppress );
			}
			if ( false === $result && '' !== $wpdb->last_error ) {
				$db_error = (string) $wpdb->last_error;

				// A schema statement that fails is NEVER "keep going": the
				// dump already dropped the old table, so continuing would
				// finish with a missing table and call it a success. Stop
				// with the real reason instead (resumable).
				if ( self::is_schema_statement( $sql ) ) {
					throw new Exception(
						sprintf(
							/* translators: 1: server error, 2: statement excerpt */
							__( 'The database server rejected a schema statement: %1$s This usually means the backup was made with database features this server lacks (a charset, collation or table engine). The restore stopped here; completed steps are saved, so fix the server (or restore on a compatible one) and press Resume. Statement: %2$s', 'unboundwp' ),
							$db_error,
							substr( preg_replace( '/\s+/', ' ', $sql ), 0, 200 )
						)
					);
				}

				// Duplicate-key convergence: a concurrent writer (or a
				// replayed batch after a hard death) may have put a row
				// with the same unique key first - most often fresh
				// widget defaults landing mid-import. Failing would keep
				// the STALE row and silently lose the dump's data, so
				// retry once as REPLACE INTO: the dump's row wins, whatever
				// the conflicting key is. Verbatim values, no parsing.
				if ( false !== stripos( $db_error, 'Duplicate entry' )
					&& 0 === stripos( ltrim( $sql ), 'INSERT INTO' ) ) {
					$retry_sql = preg_replace( '/^INSERT INTO/i', 'REPLACE INTO', ltrim( $sql ), 1 );
					$retry     = ( null !== $retry_sql ) ? $wpdb->query( $retry_sql ) : false; // phpcs:ignore
					if ( false === $retry && '' !== $wpdb->last_error ) {
						self::maybe_throw_db_error( $wpdb->last_error );
						self::record_db_error( $state, (string) $wpdb->last_error, $sql );
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
					self::maybe_throw_db_error( $db_error );
					// Row-level failures (a value too long, an old clause)
					// are recorded and reported at the end instead of being
					// silently swallowed.
					self::record_db_error( $state, $db_error, $sql );
				}
			}

			if ( ! isset( $state['statements'] ) ) {
				$state['statements'] = 0;
			}
			$state['statements'] = (int) $state['statements'] + 1;
			$state['offset']     = $read_pos - strlen( $buffer );

			// Time check after every statement - except atomic ones, which
			// must complete so tables are never left half-written.
			if ( ! self::is_atomic_statement( $sql, $options_mark ) ) {
				if ( microtime( true ) > $deadline || UWP_Helpers::memory_hot() ) {
					$running = false;
					break;
				}
			}
		}

		if ( '' !== $last_err && ! isset( $state['last_error'] ) ) {
			$state['last_error'] = substr( $last_err, 0, 300 );
		}
		if ( $skipped > 0 ) {
			$state['oversized_skipped'] = ( isset( $state['oversized_skipped'] ) ? (int) $state['oversized_skipped'] : 0 ) + $skipped;
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore
		$done = $eof && '' === trim( $buffer );
		fclose( $handle );
		if ( $done && ! isset( $state['offset'] ) ) {
			$state['offset'] = $size;
		}
		return $done;
	}

	/**
	 * Pull one complete statement out of a buffer.
	 *
	 * Quote, comment and delimiter aware. Returns sql = null when the
	 * buffer does not contain a complete statement yet (caller reads
	 * more), so a statement may span any number of reads.
	 *
	 * Handles: 'single', "double", `backtick` (with '' / "" / `` doubling
	 * and backslash escapes), -- and # line comments, block comments
	 * (including executable /*! … *\/ which stay part of the statement),
	 * and arbitrary DELIMITER strings.
	 *
	 * @param string $buffer Pending bytes.
	 * @param string $delimiter Statement terminator.
	 * @return array{sql:string|null,rest:string}
	 */
	public static function next_statement( $buffer, $delimiter = ';' ) {
		$buffer = (string) $buffer;
		$delim   = ( is_string( $delimiter ) && '' !== $delimiter ) ? $delimiter : ';';
		$dlen    = strlen( $delim );
		$len     = strlen( $buffer );
		$state   = 'code'; // code | sq | dq | bt | line | block.
		$start   = 0;

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $buffer[ $i ];

			if ( 'line' === $state ) {
				if ( "\n" === $ch ) {
					$state = 'code';
				}
				continue;
			}
			if ( 'block' === $state ) {
				if ( '*' === $ch && $i + 1 < $len && '/' === $buffer[ $i + 1 ] ) {
					$state = 'code';
					$i++;
				}
				continue;
			}
			if ( 'sq' === $state || 'dq' === $state ) {
				$quote = ( 'sq' === $state ) ? "'" : '"';
				if ( '\\' === $ch ) {
					$i++; // escape consumes the next byte (whatever it is).
					continue;
				}
				if ( $ch === $quote ) {
					if ( $i + 1 < $len && $buffer[ $i + 1 ] === $quote ) {
						$i++; // doubled quote = literal quote, string continues.
					} else {
						$state = 'code';
					}
				}
				continue;
			}
			if ( 'bt' === $state ) {
				if ( '`' === $ch ) {
					if ( $i + 1 < $len && '`' === $buffer[ $i + 1 ] ) {
						$i++;
					} else {
						$state = 'code';
					}
				}
				continue;
			}

			// Normal code.
			if ( "'" === $ch ) {
				$state = 'sq';
				continue;
			}
			if ( '"' === $ch ) {
				$state = 'dq';
				continue;
			}
			if ( '`' === $ch ) {
				$state = 'bt';
				continue;
			}
			if ( '#' === $ch ) {
				$state = 'line';
				continue;
			}
			if ( '-' === $ch && $i + 1 < $len && '-' === $buffer[ $i + 1 ]
				&& ( $i + 2 >= $len || false !== strpos( " \t\r\n", $buffer[ $i + 2 ] ) ) ) {
				$state = 'line';
				$i++;
				continue;
			}
			if ( '/' === $ch && $i + 1 < $len && '*' === $buffer[ $i + 1 ] ) {
				$state = 'block';
				$i++;
				continue;
			}
			if ( $dlen > 0 && $ch === $delim[0] && substr( $buffer, $i, $dlen ) === $delim ) {
				return array(
					'sql'  => substr( $buffer, $start, $i - $start + $dlen ),
					'rest' => substr( $buffer, $i + $dlen ),
				);
			}
		}

		return array( 'sql' => null, 'rest' => $buffer );
	}

	/**
	 * Whether a statement holds only comments/whitespace (nothing to run).
	 *
	 * @param string $sql Trimmed statement.
	 * @return bool
	 */
	protected static function is_comment_only( $sql ) {
		$probe = (string) $sql;
		// `/*!` is executable MySQL code - never treat it as a comment.
		while ( preg_match( '/^\s*(?:--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|\/\*(?!!)[\s\S]*?\*\/)/', $probe, $m ) ) {
			$probe = substr( $probe, strlen( $m[0] ) );
			if ( '' === trim( $probe ) ) {
				return true;
			}
		}
		return '' === trim( $probe );
	}

	/**
	 * Whether a statement changes schema (and therefore must never be
	 * silently skipped when it fails).
	 *
	 * @param string $sql Statement.
	 * @return bool
	 */
	public static function is_schema_statement( $sql ) {
		return (bool) preg_match( '/^\s*(DROP|CREATE|ALTER|RENAME|TRUNCATE)\b/i', (string) $sql );
	}

	/**
	 * Collations this database server knows, keyed by lowercase name.
	 *
	 * Cached per request. An empty array means "could not ask", in which
	 * case nothing is rewritten (never guess when unsure).
	 *
	 * @return array collation => charset
	 */
	protected static function supported_collations() {
		static $set = null;
		if ( null !== $set ) {
			return $set;
		}
		global $wpdb;
		$set  = array();
		$rows = $wpdb->get_results( 'SHOW COLLATION', ARRAY_A ); // phpcs:ignore
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( empty( $row['Collation'] ) ) {
					continue;
				}
				$set[ strtolower( (string) $row['Collation'] ) ] = isset( $row['Charset'] ) ? strtolower( (string) $row['Charset'] ) : '';
			}
		}
		return $set;
	}

	/**
	 * A collation this server supports, for the same character set.
	 *
	 * @param string $collation Original (unsupported) collation name.
	 * @param array  $supported Supported collation map.
	 * @return string|null Replacement, or null to drop the COLLATE clause.
	 */
	protected static function fallback_collation( $collation, $supported ) {
		$charset = (string) $collation;
		$pos     = strpos( $charset, '_' );
		if ( false !== $pos ) {
			$charset = substr( $charset, 0, $pos );
		}
		$charsets = array();
		foreach ( $supported as $cs ) {
			if ( '' !== $cs ) {
				$charsets[ $cs ] = true;
			}
		}
		// utf8mb3 and utf8 are the same thing under two names; use the one
		// this server actually exposes.
		$map = array( 'utf8mb3' => 'utf8', 'utf8' => 'utf8mb3' );
		if ( ! isset( $charsets[ $charset ] ) && isset( $map[ $charset ], $charsets[ $map[ $charset ] ] ) ) {
			$charset = $map[ $charset ];
		}
		if ( ! isset( $charsets[ $charset ] ) ) {
			return null; // unknown charset: let the charset default decide.
		}
		$candidates = array(
			$charset . '_unicode_ci',
			$charset . '_general_ci',
			$charset . '_general_mysql500_ci',
			$charset . '_bin',
		);
		foreach ( $candidates as $candidate ) {
			if ( isset( $supported[ $candidate ] ) ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Replace charsets/collations this server does not support.
	 *
	 * Backups move between database servers: a MariaDB 11 site dumps
	 * `utf8mb3_uca1400_ai_ci` (and sometimes `utf8mb3`), which MySQL 8
	 * rejects outright - every such CREATE TABLE would fail. Map them to
	 * the closest supported equivalent (same character set) or drop the
	 * clause and let the table default apply.
	 *
	 * @param string $sql    DDL statement.
	 * @param array  $state  Import db_state (by reference; counts fixes).
	 * @return string Possibly-rewritten statement.
	 */
	public static function normalize_ddl_collations( $sql, &$state = null ) {
		$supported = self::supported_collations();
		if ( empty( $supported ) ) {
			return $sql;
		}
		$charsets = array();
		foreach ( $supported as $cs ) {
			if ( '' !== $cs ) {
				$charsets[ $cs ] = true;
			}
		}
		$fixes = 0;

		// CHARSET=utf8mb3 / CHARACTER SET utf8mb3 (table and column level).
		$sql = preg_replace_callback(
			'/\b(CHARSET|CHARACTER)(\s+SET)?(\s*=\s*|\s+)([A-Za-z0-9_]+)/i',
			function ( $m ) use ( $charsets, &$fixes ) {
				$cs = strtolower( $m[4] );
				if ( isset( $charsets[ $cs ] ) ) {
					return $m[0];
				}
				$map = array( 'utf8mb3' => 'utf8', 'utf8' => 'utf8mb3' );
				if ( isset( $map[ $cs ] ) && isset( $charsets[ $map[ $cs ] ] ) ) {
					$fixes++;
					return $m[1] . ( '' !== $m[2] ? $m[2] : '' ) . $m[3] . $map[ $cs ];
				}
				return $m[0];
			},
			$sql
		);

		// COLLATE=utf8mb3_uca1400_ai_ci / COLLATE utf8mb4_... (both forms).
		$sql = preg_replace_callback(
			'/\bCOLLATE(\s*=\s*|\s+)([A-Za-z0-9_]+)/i',
			function ( $m ) use ( $supported, &$fixes ) {
				$col = strtolower( $m[2] );
				if ( isset( $supported[ $col ] ) ) {
					return $m[0];
				}
				$fallback = self::fallback_collation( $col, $supported );
				$fixes++;
				if ( null === $fallback ) {
					return ''; // drop it: the charset default is valid.
				}
				return 'COLLATE' . $m[1] . $fallback;
			},
			$sql
		);

		if ( $fixes > 0 && is_array( $state ) ) {
			$state['collation_fixes'] = ( isset( $state['collation_fixes'] ) ? (int) $state['collation_fixes'] : 0 ) + $fixes;
			if ( (int) $state['collation_fixes'] === $fixes ) { // first time only.
				UWP_Helpers::debug_log( sprintf( 'import: normalized %d unsupported charset/collation clause(s) for this database server', $fixes ) );
			}
		}
		return $sql;
	}

	/**
	 * Record a row-level database failure (bounded, human-readable).
	 *
	 * @param array  $state Import db_state (by reference).
	 * @param string $error Server error text.
	 * @param string $sql   Statement that failed.
	 */
	protected static function record_db_error( &$state, $error, $sql ) {
		if ( ! is_array( $state ) ) {
			return;
		}
		if ( ! isset( $state['db_errors'] ) || ! is_array( $state['db_errors'] ) ) {
			$state['db_errors'] = array();
		}
		$state['db_error_count'] = ( isset( $state['db_error_count'] ) ? (int) $state['db_error_count'] : 0 ) + 1;
		if ( UWP_Helpers::safe_count( $state['db_errors'] ) < 5 ) {
			$state['db_errors'][] = substr( (string) $error, 0, 200 ) . ' [' . substr( preg_replace( '/\s+/', ' ', trim( (string) $sql ) ), 0, 90 ) . ']';
		}
		if ( (int) $state['db_error_count'] <= 5 ) {
			UWP_Helpers::debug_log( sprintf( 'import: database statement failed (%d): %s', (int) $state['db_error_count'], (string) $error ) );
		}
	}

	/**
	 * Skip volatile rows that must never be restored: transients and
	 * session options (WooCommerce, WP All Import). Restoring them only
	 * brings back stale cache locks and dead sessions.
	 *
	 * Deliberately scoped to INSERTs into the options table: the old
	 * whole-statement match also skipped posts whose CONTENT happened to
	 * contain "_transient_" - silent data loss.
	 *
	 * @param string $sql Complete statement.
	 * @return bool
	 */
	protected static function is_skippable_insert( $sql ) {
		global $wpdb;
		$prefix = ( isset( $wpdb->prefix ) && is_string( $wpdb->prefix ) ) ? $wpdb->prefix : 'wp_';
		if ( ! preg_match( '/^\s*INSERT\s+INTO\s+`?' . preg_quote( $prefix . 'options', '/' ) . '`?\s*\(/i', (string) $sql ) ) {
			return false;
		}
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
	 *
	 * Every VALUES tuple is handled, so extended-insert dumps
	 * (`INSERT … VALUES (...),(...),(...)`, what mysqldump writes by
	 * default) restore their prefixed option/meta keys correctly too.
	 * Unparseable statements are returned unchanged.
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
		$values_pos = stripos( $sql, 'VALUES', $close );
		if ( false === $values_pos ) {
			return $sql;
		}

		// Walk every tuple; collect literal edits, then apply them from the
		// end so earlier offsets stay valid.
		$len   = strlen( $sql );
		$pos   = $values_pos;
		$edits = array();
		while ( false !== ( $vopen = strpos( $sql, '(', $pos ) ) ) {
			$vals = self::split_sql_tuple( $sql, $vopen );
			if ( null === $vals || UWP_Helpers::safe_count( $vals['items'] ) !== UWP_Helpers::safe_count( $cols ) ) {
				break;
			}
			if ( isset( $vals['items'][ $idx ] ) ) {
				$new_literal = self::remap_key_literal( $vals['items'][ $idx ], $from, $to );
				if ( null !== $new_literal ) {
					$edits[] = array( (int) $vals['starts'][ $idx ], (int) $vals['ends'][ $idx ], $new_literal );
				}
			}
			// Continue only when another tuple follows this one.
			$pos = (int) $vals['end'] + 1;
			while ( $pos < $len && false !== strpos( " \t\r\n", $sql[ $pos ] ) ) {
				$pos++;
			}
			if ( $pos >= $len || ',' !== $sql[ $pos ] ) {
				break;
			}
			$pos++;
		}

		if ( empty( $edits ) ) {
			return $sql;
		}
		for ( $i = count( $edits ) - 1; $i >= 0; $i-- ) {
			list( $s, $e, $lit ) = $edits[ $i ];
			$sql = substr( $sql, 0, $s ) . $lit . substr( $sql, $e );
		}
		return $sql;
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
		$fatal = array( 'tablespace', 'access denied', 'no space left', 'disk full', 'read-only', 'read only' );
		foreach ( $fatal as $needle ) {
			if ( false !== stripos( (string) $last_error, $needle ) ) {
				throw new Exception( sprintf( __( 'Database error during restore: %s', 'unboundwp' ), $last_error ) );
			}
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
			$state['offset'] = '';
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
			$state['offset']      = '';
			return (int) $state['table_index'] >= UWP_Helpers::safe_count( $tables );
		}
		// Each table starts at "before the first key" (''): works for
		// integer and string primary keys alike, including jobs resumed
		// from versions that stored a numeric 0 here.
		if ( ! isset( $state['current_table'] ) || $state['current_table'] !== $table ) {
			$state['current_table'] = $table;
			$state['offset']        = '';
		}

		// Shrink batches when memory runs hot: a few small rows beat one
		// fatal batch. Rows already fetched are still processed below.
		if ( UWP_Helpers::memory_hot() ) {
			$rows_per_tick = min( $rows_per_tick, 25 );
		}

		// Primary key for stable paging (fall back to OFFSET when none).
		// Keyset paging is type-aware: a table whose single-column primary
		// key is a string (slug, code, UUID…) must be compared as a string,
		// otherwise `pk > 0` silently matches nothing and its rows would
		// never have URLs replaced. Empty offset = "start of table" and
		// works for both types (`pk > ''`).
		$pk = self::primary_key( $table );

		if ( $pk ) {
			$last = $state['offset'];
			$ph   = ( is_int( $last ) || ( is_string( $last ) && ctype_digit( $last ) ) ) ? '%d' : '%s';
			// phpcs:ignore -- internal identifiers.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` > {$ph} ORDER BY `{$pk}` ASC LIMIT %d", $last, $rows_per_tick ), ARRAY_A );
		} else {
			// phpcs:ignore -- internal identifiers.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $rows_per_tick, (int) $state['offset'] ), ARRAY_A );
		}

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			$state['table_index'] = (int) $state['table_index'] + 1;
			$state['offset'] = '';
			return (int) $state['table_index'] >= UWP_Helpers::safe_count( $tables );
		}

		$n = 0;
		$last_pk = null;
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
				$last_pk = $pkval;
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

		if ( $pk ) {
			if ( null !== $last_pk ) {
				$state['offset'] = $last_pk; // keeps string keys as strings.
			}
		} else {
			$state['offset'] = (int) $state['offset'] + $row_count;
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

		// URL pairs: site, home and uploads.
		//
		// Every scheme (http, https, schemeless) and www/non-www variant of
		// the OLD url maps onto the DESTINATION's canonical scheme+host with
		// the same path. The previous positional mapping kept the source
		// scheme, so an http -> https migration left every internal link on
		// http://new-domain (redirect chains, mixed content). The new host is
		// the single source of truth, which is also what Ai1WM users expect.
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
			// Both sides must be absolute URLs with hosts; otherwise there is
			// no meaningful scheme/host to canonicalize.
			$old_parts = parse_url( $old );
			$new_parts = parse_url( $new );
			if ( ! is_array( $old_parts ) || ! is_array( $new_parts )
				|| empty( $old_parts['host'] ) || empty( $new_parts['host'] ) ) {
				continue;
			}
			$old_rest = '';
			if ( isset( $old_parts['path'] ) ) {
				$old_rest .= $old_parts['path'];
			}
			if ( isset( $old_parts['query'] ) ) {
				$old_rest .= '?' . $old_parts['query'];
			}
			$old_rest = rtrim( $old_rest, '/' );

			$new_rest = '';
			if ( isset( $new_parts['path'] ) ) {
				$new_rest .= $new_parts['path'];
			}
			if ( isset( $new_parts['query'] ) ) {
				$new_rest .= '?' . $new_parts['query'];
			}
			$new_rest = rtrim( $new_rest, '/' );

			$new_scheme = isset( $new_parts['scheme'] ) ? strtolower( (string) $new_parts['scheme'] ) : 'https';
			if ( ! in_array( $new_scheme, array( 'http', 'https' ), true ) ) {
				$new_scheme = 'https';
			}
			$new_host = $new_parts['host'];
			$new_hosts = self::host_variants( $new_host );
			$old_hosts = self::host_variants( $old_parts['host'] );

			// Every old variant -> canonical destination URL.
			$canonical = $new_scheme . '://' . $new_host . $new_rest;
			foreach ( $old_hosts as $oh ) {
				foreach ( array( 'http', 'https', '' ) as $scheme ) {
					if ( '' === $scheme ) {
						$ov = '//' . $oh . $old_rest;
						$nv = '//' . $new_host . $new_rest; // schemeless stays schemeless.
					} else {
						$ov = $scheme . '://' . $oh . $old_rest;
						$nv = $canonical; // both schemes normalize to the destination.
					}
					$add( $ov, $nv );
					$add( urlencode( $ov ), urlencode( $nv ) );
					$add( rawurlencode( $ov ), rawurlencode( $nv ) );
					$add( addcslashes( $ov, '/' ), addcslashes( $nv, '/' ) );
				}
			}
			// Values that already name the destination host but use another
			// scheme (or www form) are normalized to the canonical URL too.
			foreach ( $new_hosts as $nh ) {
				foreach ( array( 'http', 'https' ) as $scheme ) {
					$ov = $scheme . '://' . $nh . $new_rest;
					if ( $ov !== $canonical ) {
						$add( $ov, $canonical );
					}
				}
				$add( '//' . $nh . $new_rest, '//' . $new_host . $new_rest );
			}

			// Quoted bare hosts (JSON attributes etc.), never bare hosts
			// (a bare replace would corrupt e.g. myold.com).
			$old_host_lc = strtolower( (string) $old_parts['host'] );
			$new_host_lc = strtolower( $new_host );
			if ( '' !== $old_host_lc && '' !== $new_host_lc && $old_host_lc !== $new_host_lc ) {
				foreach ( array( '="', "='", '://' ) as $prefix ) {
					$add( $prefix . $old_host_lc, $prefix . $new_host_lc );
				}
				$add( '@' . $old_host_lc, '@' . $new_host_lc ); // emails.
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
	 * The www / non-www forms of one host, original first.
	 *
	 * @param string $host Host name.
	 * @return array Unique host strings.
	 */
	protected static function host_variants( $host ) {
		$host = (string) $host;
		if ( '' === $host ) {
			return array();
		}
		$bare = preg_replace( '/^www\./i', '', $host );
		$www  = 'www.' . $bare;
		return array_values( array_unique( array( $host, $www, $bare ) ) );
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
