<?php
/**
 * Streaming archive reader/writer for the .uwp / .wpress format.
 *
 * Format (identical to All-in-One WP Migration, so files are interchangeable):
 *
 *   Each file = 4377-byte header + raw file bytes.
 *   Header    = basename (255, null-padded)
 *             + size     (14, decimal string, null-padded)
 *             + mtime    (12, unix timestamp string, null-padded)
 *             + prefix   (4088, directory path with "/" separators, null-padded)
 *             + crc32    (8, hex of file bytes, null-padded; empty = legacy file)
 *   Archive ends with one 4377-byte all-zero block (EOF).
 *
 * Legacy .wpress files used a 4096-byte prefix with no CRC - the reader
 * accepts those too (their last 8 "crc" bytes are just nulls).
 *
 * Newer Ai1WM files may gzip and/or encrypt each entry (see package.json).
 * The READER supports plain + gzip + openssl-encrypted entries so any
 * .wpress can be restored. The WRITER always writes plain entries (v1):
 * maximum compatibility, easiest to debug, zero extra dependencies.
 *
 * Everything streams in 512 KB chunks - memory use stays flat no matter
 * how large the site or backup is.
 *
 * @package UnboundWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UWP_Archive {

	const HEADER_SIZE = 4377;
	const NAME_SIZE   = 255;
	const SIZE_SIZE   = 14;
	const MTIME_SIZE  = 12;
	const PREFIX_SIZE = 4088; // + 8 bytes CRC = 4096 total tail.
	const CRC_SIZE    = 8;
	const CHUNK       = 512000;

	// ------------------------------------------------------------------
	// Writing
	// ------------------------------------------------------------------

	/**
	 * Open a new archive for writing (truncates if it exists).
	 *
	 * @param string $path Absolute archive path.
	 * @return resource File handle.
	 * @throws Exception When the file cannot be opened.
	 */
	public static function open_for_write( $path ) {
		$dir = dirname( $path );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$handle = @fopen( $path, 'wb' ); // phpcs:ignore
		if ( false === $handle ) {
			throw new Exception( sprintf( __( 'Cannot open archive for writing: %s', 'unboundwp' ), $path ) );
		}
		return $handle;
	}

	/**
	 * Open an existing archive for appending (resume an interrupted export).
	 *
	 * @param string $path Absolute archive path.
	 * @return resource File handle positioned at end.
	 * @throws Exception When the file cannot be opened.
	 */
	public static function open_for_append( $path ) {
		$handle = @fopen( $path, 'ab' ); // phpcs:ignore
		if ( false === $handle ) {
			throw new Exception( sprintf( __( 'Cannot open archive for appending: %s', 'unboundwp' ), $path ) );
		}
		return $handle;
	}

	/**
	 * Build a 4377-byte header block.
	 *
	 * @param string $basename File name without any directory.
	 * @param int    $size     File size in bytes.
	 * @param int    $mtime    Unix modification time.
	 * @param string $prefix   Directory path with "/" separators ("" or "." = root).
	 * @param string $crc_hex  8-char hex CRC32 of the file bytes ("" = skip).
	 * @return string 4377-byte block.
	 */
	public static function build_header( $basename, $size, $mtime, $prefix, $crc_hex = '' ) {
		$basename = substr( $basename, 0, self::NAME_SIZE );
		$prefix   = str_replace( DIRECTORY_SEPARATOR, '/', (string) $prefix );
		$prefix   = trim( $prefix, '/' );
		if ( '' === $prefix ) {
			$prefix = '.';
		}
		$prefix = substr( $prefix, 0, self::PREFIX_SIZE );

		return str_pad( $basename, self::NAME_SIZE, "\0" )
			. str_pad( (string) (int) $size, self::SIZE_SIZE, "\0" )
			. str_pad( (string) (int) $mtime, self::MTIME_SIZE, "\0" )
			. str_pad( $prefix, self::PREFIX_SIZE, "\0" )
			. str_pad( strtolower( (string) $crc_hex ), self::CRC_SIZE, "\0" );
	}

	/**
	 * Append one on-disk file to an open archive (streamed, CRC-checked).
	 *
	 * @param resource $archive   Open binary handle.
	 * @param string   $disk_path Absolute source file.
	 * @param string   $arc_prefix Directory inside the archive ("/" separated).
	 * @param string   $arc_name   Optional renamed basename (defaults to real name).
	 * @throws Exception On unreadable source or write failure.
	 */
	public static function append_file( $archive, $disk_path, $arc_prefix, $arc_name = '', $password = null ) {
		// Regular files only: opening a FIFO/socket/device for reading
		// blocks forever waiting for a writer that never comes.
		if ( ! UWP_Helpers::is_regular_file( $disk_path ) || ! is_readable( $disk_path ) ) {
			throw new Exception( sprintf( __( 'Cannot read file: %s', 'unboundwp' ), $disk_path ) );
		}

		if ( '' === $arc_name ) {
			$arc_name = basename( $disk_path );
		}

		// Config files are never encrypted (metadata must stay readable),
		// and unprotected jobs stream plain as before.
		if ( ( null === $password || '' === $password ) || self::is_config_name( $arc_name ) ) {
			return self::append_file_plain( $archive, $disk_path, $arc_prefix, $arc_name );
		}
		return self::append_file_encrypted( $archive, $disk_path, $arc_prefix, $arc_name, $password );
	}

	/**
	 * Append one file unencrypted (original path, unchanged behavior).
	 *
	 * @param resource $archive Open binary handle (append mode).
	 * @param string   $disk_path Absolute source path.
	 * @param string   $arc_prefix Directory inside the archive.
	 * @param string   $arc_name Basename inside the archive.
	 */
	protected static function append_file_plain( $archive, $disk_path, $arc_prefix, $arc_name ) {
		$size  = filesize( $disk_path );
		$mtime = filemtime( $disk_path );

		// First pass: CRC32 of the source (streamed, low memory).
		$crc  = self::crc32_file( $disk_path );
		$head = self::build_header( $arc_name, $size, $mtime, $arc_prefix, $crc );
		self::fwrite_all( $archive, $head );

		// Second pass: stream the bytes.
		$in = @fopen( $disk_path, 'rb' ); // phpcs:ignore
		if ( false === $in ) {
			throw new Exception( sprintf( __( 'Cannot open file: %s', 'unboundwp' ), $disk_path ) );
		}
		while ( ! feof( $in ) ) {
			$data = fread( $in, self::CHUNK ); // phpcs:ignore
			if ( false === $data ) {
				fclose( $in );
				throw new Exception( sprintf( __( 'Cannot read file: %s', 'unboundwp' ), $disk_path ) );
			}
			if ( '' !== $data ) {
				self::fwrite_all( $archive, $data );
			}
		}
		fclose( $in );
	}

	/**
	 * Append one file password-encrypted (reference chunk framing).
	 *
	 * Plaintext is split into CHUNK pieces; each stored chunk is IV(16) +
	 * ciphertext. The header size (total stored bytes) is unknowable
	 * upfront, so a placeholder header is written first and backpatched
	 * after streaming - the archive handle must be seekable (always true
	 * for local backup files). CRC covers the plaintext, like the
	 * reference implementation.
	 *
	 * @param resource $archive Open binary handle (append mode, seekable).
	 * @param string   $disk_path Absolute source path.
	 * @param string   $arc_prefix Directory inside the archive.
	 * @param string   $arc_name Basename inside the archive.
	 * @param string   $password Encryption password.
	 * @throws Exception On crypto failure or I/O failure.
	 */
	protected static function append_file_encrypted( $archive, $disk_path, $arc_prefix, $arc_name, $password ) {
		if ( ! self::crypto_available() ) {
			throw new Exception( __( 'Password encryption needs PHP openssl, which is missing.', 'unboundwp' ) );
		}
		$mtime = filemtime( $disk_path );
		$header_start = ftell( $archive );
		if ( false === $header_start ) {
			throw new Exception( __( 'Cannot write encrypted backup to a non-seekable destination.', 'unboundwp' ) );
		}
		// Placeholder header (size + CRC backpatched below).
		self::fwrite_all( $archive, self::build_header( $arc_name, 0, $mtime, $arc_prefix, '' ) );

		$ctx     = hash_init( 'crc32b' );
		$written = 0;
		$in      = @fopen( $disk_path, 'rb' ); // phpcs:ignore
		if ( false === $in ) {
			throw new Exception( sprintf( __( 'Cannot open file: %s', 'unboundwp' ), $disk_path ) );
		}
		while ( ! feof( $in ) ) {
			$data = fread( $in, self::CHUNK ); // phpcs:ignore
			if ( false === $data ) {
				fclose( $in );
				throw new Exception( sprintf( __( 'Cannot read file: %s', 'unboundwp' ), $disk_path ) );
			}
			if ( '' === $data ) {
				continue;
			}
			hash_update( $ctx, $data );
			$enc = self::encrypt_chunk( $data, $password );
			self::fwrite_all( $archive, $enc );
			$written += strlen( $enc );
		}
		fclose( $in );
		$crc = strtolower( hash_final( $ctx ) );

		// Backpatch size (14) then CRC (8): seek over name(255) to size,
		// write it, skip mtime(12) + prefix(4088), write CRC, return to end.
		if ( 0 !== @fseek( $archive, $header_start + 255, SEEK_SET ) ) { // phpcs:ignore
			throw new Exception( __( 'Cannot finalize encrypted backup (destination not seekable).', 'unboundwp' ) );
		}
		self::fwrite_all( $archive, str_pad( (string) $written, 14, "\0" ) ); // phpcs:ignore
		if ( 0 !== @fseek( $archive, $header_start + 255 + 14 + 12 + 4088, SEEK_SET ) ) { // phpcs:ignore
			throw new Exception( __( 'Cannot finalize encrypted backup (destination not seekable).', 'unboundwp' ) );
		}
		self::fwrite_all( $archive, str_pad( $crc, 8, "\0" ) ); // phpcs:ignore
		if ( 0 !== @fseek( $archive, $header_start + self::HEADER_SIZE + $written, SEEK_SET ) ) { // phpcs:ignore
			throw new Exception( __( 'Cannot finalize encrypted backup (destination not seekable).', 'unboundwp' ) );
		}
	}

	/**
	 * Append an in-memory string as a file (for package.json, database.sql chunks, ...).
	 *
	 * @param resource $archive Open binary handle.
	 * @param string   $arc_prefix Directory inside the archive.
	 * @param string   $arc_name Basename inside the archive.
	 * @param string   $content Raw bytes to store.
	 */
	public static function append_string( $archive, $arc_prefix, $arc_name, $content ) {
		$head = self::build_header( $arc_name, strlen( $content ), time(), $arc_prefix, hash( 'crc32b', $content ) );
		self::fwrite_all( $archive, $head );
		self::fwrite_all( $archive, $content );
	}

	/**
	 * Write the final all-zero EOF block and close.
	 *
	 * @param resource $archive Open binary handle.
	 * @param int|null $total_size Optional total stored bytes (for archive-level CRC footer).
	 * @param string   $archive_crc Optional archive CRC (v2 footer).
	 */
	public static function finish( $archive, $total_size = null, $archive_crc = '' ) {
		if ( null !== $total_size && '' !== $archive_crc ) {
			// v2-style footer: size + crc in an otherwise empty header.
			$block = str_repeat( "\0", self::NAME_SIZE )
				. str_pad( (string) (int) $total_size, self::SIZE_SIZE, "\0" )
				. str_repeat( "\0", self::MTIME_SIZE + self::PREFIX_SIZE )
				. str_pad( strtolower( $archive_crc ), self::CRC_SIZE, "\0" );
			self::fwrite_all( $archive, $block );
		} else {
			self::fwrite_all( $archive, str_repeat( "\0", self::HEADER_SIZE ) );
		}
		fclose( $archive );
	}

	// ------------------------------------------------------------------
	// Reading
	// ------------------------------------------------------------------

	/**
	 * Parse one header block.
	 *
	 * Accepts both layouts:
	 *   v2: prefix 4088 + crc 8   (what we write)
	 *   v1: prefix 4096, no crc   (legacy .wpress - last 8 bytes are nulls)
	 *
	 * @param string $block 4377 bytes.
	 * @return array|null Entry array or null when this is the EOF block.
	 */
	public static function parse_header( $block ) {
		if ( strlen( $block ) < self::HEADER_SIZE ) {
			return null;
		}
		if ( $block === str_repeat( "\0", self::HEADER_SIZE ) ) {
			return null; // v1 EOF.
		}

		$raw_name   = substr( $block, 0, self::NAME_SIZE );
		$raw_size   = substr( $block, self::NAME_SIZE, self::SIZE_SIZE );
		$raw_mtime  = substr( $block, self::NAME_SIZE + self::SIZE_SIZE, self::MTIME_SIZE );
		$raw_prefix = substr( $block, self::NAME_SIZE + self::SIZE_SIZE + self::MTIME_SIZE, self::PREFIX_SIZE );
		$raw_crc    = substr( $block, self::NAME_SIZE + self::SIZE_SIZE + self::MTIME_SIZE + self::PREFIX_SIZE, self::CRC_SIZE );

		$name   = rtrim( $raw_name, "\0" );
		$size   = (int) rtrim( $raw_size, "\0 " );
		$mtime  = (int) rtrim( $raw_mtime, "\0 " );
		$prefix = rtrim( $raw_prefix, "\0" );
		$crc    = strtolower( rtrim( $raw_crc, "\0 " ) );

		// v2 EOF footer looks like: empty name + hex crc + numeric size.
		if ( '' === $name && '' !== $crc && preg_match( '/^[0-9a-f]{8}$/', $crc ) ) {
			// Distinguish "real EOF footer" from a corrupt entry: prefix must be empty.
			if ( '' === trim( $prefix, "\0 " ) ) {
				return null;
			}
		}

		// Truly empty header = EOF (also covers legacy files whose tail is nulls).
		if ( '' === $name && 0 === $size && '' === trim( $prefix ) && '' === $crc ) {
			return null;
		}

		// Legacy fallback: if the "crc" slot is not hex, it was really the
		// tail of a 4096-byte prefix. Re-attach it.
		if ( '' !== $crc && ! preg_match( '/^[0-9a-f]{8}$/', $crc ) ) {
			$prefix .= $raw_crc;
			$prefix  = rtrim( $prefix, "\0" );
			$crc     = '';
		} elseif ( preg_match( '/^[0-9a-f]{8}$/', $crc ) ) {
			// Looks like a real checksum - keep it.
		} else {
			$crc = '';
		}

		$prefix = str_replace( '\\', '/', $prefix );
		$prefix = trim( $prefix, '/' );
		if ( '' === $prefix ) {
			$prefix = '.';
		}

		$full = ( '.' === $prefix ) ? $name : $prefix . '/' . $name;

		return array(
			'name'   => $full, // archive-relative path with "/" separators.
			'basename' => $name,
			'prefix' => $prefix,
			'size'   => $size,
			'mtime'  => $mtime,
			'crc'    => $crc,
		);
	}

	/**
	 * Read one header from an open handle.
	 *
	 * @param resource $handle Open binary handle.
	 * @return array|null Entry or null on EOF / short read at end.
	 * @throws Exception On unreadable handle.
	 */
	public static function read_header( $handle ) {
		$block = @fread( $handle, self::HEADER_SIZE ); // phpcs:ignore
		if ( false === $block || '' === $block ) {
			return null;
		}
		if ( strlen( $block ) < self::HEADER_SIZE ) {
			return null; // truncated tail = treat as EOF.
		}
		return self::parse_header( $block );
	}

	/**
	 * Skip over an entry's data bytes (streaming seek).
	 *
	 * @param resource $handle Open binary handle.
	 * @param int      $size Bytes to skip.
	 */
	public static function skip_data( $handle, $size ) {
		$size = (int) $size;
		if ( $size <= 0 ) {
			return;
		}
		// Prefer seeking (fast); fall back to reading (needed for non-seekable streams).
		if ( 0 === @fseek( $handle, $size, SEEK_CUR ) ) { // phpcs:ignore
			return;
		}
		$left = $size;
		while ( $left > 0 && ! feof( $handle ) ) {
			$want = min( self::CHUNK, $left );
			$data = fread( $handle, $want ); // phpcs:ignore
			if ( false === $data || '' === $data ) {
				break;
			}
			$left -= strlen( $data );
		}
	}

	/**
	 * Stream one entry's raw bytes to an open output handle.
	 * Verifies per-file CRC when the header carries one.
	 *
	 * NOTE: for gzip/encrypted Ai1WM entries the caller should use
	 * extract_entry() which decodes; this raw copy is for plain entries
	 * and for the fast path.
	 *
	 * @param resource $in Open archive handle (positioned at data start).
	 * @param resource $out Open output handle.
	 * @param array    $entry Parsed header from parse_header().
	 * @throws Exception On CRC mismatch or I/O failure.
	 */
	public static function copy_data( $in, $out, $entry ) {
		$left = (int) $entry['size'];
		$ctx  = ( '' !== $entry['crc'] ) ? hash_init( 'crc32b' ) : null;

		while ( $left > 0 ) {
			$want = min( self::CHUNK, $left );
			$data = @fread( $in, $want ); // phpcs:ignore
			if ( false === $data || '' === $data ) {
				throw new Exception( sprintf( __( 'Archive is truncated (entry %s).', 'unboundwp' ), $entry['name'] ) );
			}
			if ( $ctx ) {
				hash_update( $ctx, $data );
			}
			self::fwrite_all( $out, $data );
			$left -= strlen( $data );
		}

		if ( $ctx ) {
			$actual = strtolower( hash_final( $ctx ) );
			if ( $actual !== strtolower( $entry['crc'] ) ) {
				throw new Exception( sprintf( __( 'Checksum mismatch for %s (expected %s, got %s).', 'unboundwp' ), $entry['name'], $entry['crc'], $actual ) );
			}
		}
	}

	/**
	 * Extract one entry to disk, decoding gzip / openssl layers when needed.
	 *
	 * Ai1WM encoding (from the open-source explorer + archiver code):
	 *   - plain: entry bytes are the file bytes.
	 *   - gzip: entry bytes = sequence of [4-byte BE length + gzipped chunk].
	 *   - encrypted: each stored chunk is openssl_encrypt(aes-256-cbc) with
	 *     key = first 16 bytes of SHA1(password) + 16 zero bytes, IV prepended.
	 *   - config files (package.json, multisite.json) are always plain.
	 *
	 * @param resource $in Open archive handle (positioned at data start).
	 * @param array    $entry Parsed header.
	 * @param string   $dest Absolute destination path.
	 * @param array    $options Optional: array('password' => ?, 'compression' => ?).
	 * @throws Exception On decode failure.
	 */
	public static function extract_entry( $in, $entry, $dest, $options = array() ) {
		$password    = isset( $options['password'] ) ? $options['password'] : null;
		$compression = isset( $options['compression'] ) ? $options['compression'] : null;
		$is_config   = self::is_config_name( isset( $entry['basename'] ) ? $entry['basename'] : '' );

		$dir = dirname( $dest );
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$out = @fopen( $dest, 'wb' ); // phpcs:ignore
		if ( false === $out ) {
			throw new Exception( sprintf( __( 'Cannot write file: %s', 'unboundwp' ), $dest ) );
		}

		try {
			if ( $is_config || ( ! $password && ! $compression ) ) {
				// Fast path: plain bytes.
				self::copy_data( $in, $out, $entry );
			} else {
				self::extract_encoded( $in, $out, $entry, $password, $compression );
			}
		} catch ( Exception $e ) {
			fclose( $out );
			throw $e;
		}
		fclose( $out );

		if ( $entry['mtime'] > 0 ) {
			@touch( $dest, $entry['mtime'] ); // phpcs:ignore
		}
	}

	/**
	 * Decode a password-encrypted entry (reference chunk framing).
	 *
	 * Reads IV + ciphertext blobs sized by the framing contract; memory
	 * stays flat no matter how large the file is. A wrong password fails
	 * on the first blob with a clear message (never a mid-file mystery).
	 *
	 * @param resource $in Open archive handle (positioned at data start).
	 * @param resource $out Open output handle.
	 * @param array    $entry Parsed header.
	 * @param string   $password Decryption password.
	 * @throws Exception On truncation, decryption failure, or corruption.
	 */
	protected static function extract_encrypted( $in, $out, $entry, $password ) {
		$remaining = (int) $entry['size'];
		$data_start = ftell( $in );
		$decrypt_failed = false;
		try {
			while ( $remaining > 0 ) {
				$want = min( $remaining, self::CHUNK );
				if ( $remaining > self::CHUNK ) {
					$want = min( $want + 32, $remaining );
				}
				$blob = self::fread_all( $in, $want );
				$remaining -= strlen( $blob );
				try {
					$plain = self::php_openssl_decrypt( $blob, $password );
				} catch ( Exception $e ) {
					$decrypt_failed = true;
					throw new Exception( sprintf( __( 'Cannot decrypt entry %s. Wrong password or corrupt backup.', 'unboundwp' ), $entry['name'] ) );
				}
				self::fwrite_all( $out, $plain );
			}
		} catch ( Exception $e ) {
			// Legacy whole-entry blobs (single encrypt() over the file):
			// retry once when small enough to hold in memory.
			if ( $decrypt_failed && (int) $entry['size'] <= 8 * 1024 * 1024 && false !== $data_start ) {
				fseek( $in, (int) $data_start );
				try {
					$raw   = self::fread_all( $in, (int) $entry['size'] );
					$plain = self::php_openssl_decrypt( $raw, $password );
					fseek( $out, 0 );
					ftruncate( $out, 0 );
					self::fwrite_all( $out, $plain );
					return;
				} catch ( Exception $e2 ) { // phpcs:ignore - fall through to the chunked error below.
				}
			}
			throw $e;
		}
	}

	/**
	 * Decode a gzip/encrypted entry (chunked framing).
	 *
	 * @param resource $in Open archive handle.
	 * @param resource $out Open output handle.
	 * @param array    $entry Parsed header.
	 * @param string|null $password Decryption password or null.
	 * @param string|null $compression "gzip", "bzip2" or null.
	 */
	protected static function extract_encoded( $in, $out, $entry, $password, $compression ) {
		$remaining = (int) $entry['size'];

		// Encrypted-only entries use the reference chunk framing: plaintext
		// split into CHUNK pieces, each stored as IV(16) + ciphertext. A
		// full chunk occupies CHUNK + 32 bytes on the wire; the final chunk
		// is whatever remains. Wrong passwords fail on the first chunk.
		if ( $password && ! $compression ) {
			self::extract_encrypted( $in, $out, $entry, $password );
			return;
		}

		while ( $remaining > 0 ) {
			$len_bytes = self::fread_all( $in, min( 4, $remaining ) );
			$remaining -= strlen( $len_bytes );
			if ( 4 !== strlen( $len_bytes ) ) {
				throw new Exception( sprintf( __( 'Archive is truncated (entry %s).', 'unboundwp' ), $entry['name'] ) );
			}
			$chunk_len = unpack( 'N', $len_bytes );
			$chunk_len = $chunk_len[1];
			if ( $chunk_len <= 0 || $chunk_len > $remaining ) {
				throw new Exception( sprintf( __( 'Archive is corrupt (entry %s).', 'unboundwp' ), $entry['name'] ) );
			}
			$stored = self::fread_all( $in, $chunk_len );
			$remaining -= $chunk_len;

			if ( $password ) {
				$stored = self::php_openssl_decrypt( $stored, $password );
			}
			if ( 'gzip' === $compression ) {
				$decoded = @gzuncompress( $stored ); // phpcs:ignore
				if ( false === $decoded ) {
					// Try raw inflate (Ai1WM variants differ).
					$decoded = @gzinflate( $stored ); // phpcs:ignore
				}
				if ( false === $decoded ) {
					throw new Exception( sprintf( __( 'Cannot decompress entry %s.', 'unboundwp' ), $entry['name'] ) );
				}
				$stored = $decoded;
			} elseif ( 'bzip2' === $compression ) {
				if ( ! function_exists( 'bzdecompress' ) ) {
					throw new Exception( __( 'This backup needs bzip2 but PHP bz2 support is missing.', 'unboundwp' ) );
				}
				$stored = bzdecompress( $stored ); // phpcs:ignore
			}
			self::fwrite_all( $out, $stored );
		}
	}

	/**
	 * Decrypt one Ai1WM openssl blob (IV prepended, aes-256-cbc).
	 *
	 * Key derivation matches the open-source explorer:
	 * key = SHA1(password)[0..16] + 16 zero bytes.
	 *
	 * @param string $blob Raw stored bytes.
	 * @param string $password Password.
	 * @return string Decrypted bytes.
	 * @throws Exception On failure.
	 */
	public static function php_openssl_decrypt( $blob, $password ) {
		if ( strlen( $blob ) < 16 ) {
			throw new Exception( __( 'Encrypted data is too short.', 'unboundwp' ) );
		}
		$iv         = substr( $blob, 0, 16 );
		$ciphertext = substr( $blob, 16 );
		$digest     = sha1( (string) $password, true );
		$key        = substr( $digest, 0, 16 ) . str_repeat( "\0", 16 );

		$plain = @openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv ); // phpcs:ignore
		if ( false === $plain ) {
			throw new Exception( __( 'Wrong password or corrupt encrypted data.', 'unboundwp' ) );
		}
		return $plain;
	}

	/**
	 * Password-based encryption, byte-compatible with the reference
	 * implementation (Ai1WM 7.x): AES-256-CBC, per-chunk random IV,
	 * key = first 16 bytes of SHA1(password) zero-padded to 32.
	 *
	 * Framing contract both sides honor (writer + reader): plaintext is
	 * split into CHUNK-sized pieces; each stored chunk is IV(16) +
	 * ciphertext. A full plaintext chunk therefore occupies CHUNK + 32
	 * bytes on the wire (16 IV + 16 PKCS#7 pad block); the final chunk is
	 * whatever remains. Header size = total stored bytes; CRC = plaintext.
	 * Config files (package.json, multisite.json, blogs.json, filemap)
	 * are never encrypted, so metadata stays readable.
	 */
	const CIPHER = 'AES-256-CBC';

	const SIGN_TEXT = '"How long do you want these messages to remain secret? I want them to remain secret for as long as men are capable of evil." - Neal Stephenson';

	/**
	 * Whether password encryption can run on this host.
	 *
	 * @return bool
	 */
	public static function crypto_available() {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
			return false;
		}
		if ( ! function_exists( 'openssl_cipher_iv_length' ) ) {
			return false;
		}
		return 16 === openssl_cipher_iv_length( self::CIPHER ); // phpcs:ignore
	}

	/**
	 * Derive the 32-byte key from a password (reference-compatible).
	 *
	 * @param string $password Password.
	 * @return string|false 32-byte key, or false for empty passwords.
	 */
	public static function derive_key( $password ) {
		if ( ! is_string( $password ) || '' === $password ) {
			return false;
		}
		return substr( sha1( $password, true ), 0, 16 ) . str_repeat( "\0", 16 );
	}

	/**
	 * Encrypt one plaintext chunk (IV prepended).
	 *
	 * @param string $plain Plaintext chunk.
	 * @param string $password Password.
	 * @return string Stored bytes (IV + ciphertext).
	 * @throws Exception When encryption is unavailable or fails.
	 */
	public static function encrypt_chunk( $plain, $password ) {
		$key = self::derive_key( $password );
		if ( false === $key || ! self::crypto_available() ) {
			throw new Exception( __( 'Password encryption is unavailable on this server (PHP openssl missing).', 'unboundwp' ) );
		}
		$iv = openssl_random_pseudo_bytes( 16 ); // phpcs:ignore
		if ( false === $iv ) {
			throw new Exception( __( 'Could not generate encryption randomness.', 'unboundwp' ) );
		}
		$enc = @openssl_encrypt( (string) $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv ); // phpcs:ignore
		if ( false === $enc ) {
			throw new Exception( __( 'Could not encrypt backup data.', 'unboundwp' ) );
		}
		return $iv . $enc;
	}

	/**
	 * Encrypt the well-known signature (password check stored in package.json).
	 *
	 * @param string $password Password.
	 * @return string Base64 signature blob.
	 * @throws Exception When encryption is unavailable or fails.
	 */
	public static function encrypt_signature( $password ) {
		return base64_encode( self::encrypt_chunk( self::SIGN_TEXT, $password ) ); // phpcs:ignore
	}

	/**
	 * Whether the given password unlocks a package (signature check).
	 *
	 * @param array  $package Decoded package.json (may be empty).
	 * @param string $password Password to test.
	 * @return bool True when the signature decrypts to the sign text.
	 */
	public static function password_validates( $package, $password ) {
		if ( ! is_array( $package ) || empty( $package['Encrypted'] ) || empty( $package['EncryptedSignature'] ) ) {
			return false;
		}
		if ( ! is_string( $password ) || '' === $password ) {
			return false;
		}
		$raw = base64_decode( (string) $package['EncryptedSignature'], true ); // phpcs:ignore
		if ( false === $raw ) {
			return false;
		}
		try {
			return self::php_openssl_decrypt( $raw, $password ) === self::SIGN_TEXT;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Config files are always plain (never encrypted), so imports can read
	 * metadata and verify the password before touching any file data.
	 *
	 * @param string $basename Entry basename.
	 * @return bool
	 */
	public static function is_config_name( $basename ) {
		return in_array( (string) $basename, array( 'package.json', 'multisite.json', 'blogs.json', 'filemap.list' ), true );
	}

	/**
	 * Read one entry fully into memory (only for small config files!).
	 *
	 * @param string $archive_path Absolute archive path.
	 * @param string $wanted Archive-relative name, e.g. "package.json".
	 * @param array  $options Decoder options (password/compression).
	 * @return string|null Contents or null when not found.
	 */
	public static function read_small_entry( $archive_path, $wanted, $options = array() ) {
		$handle = @fopen( $archive_path, 'rb' ); // phpcs:ignore
		if ( false === $handle ) {
			return null;
		}
		$result = null;
		while ( ! feof( $handle ) ) {
			$entry = self::read_header( $handle );
			if ( null === $entry ) {
				break;
			}
			if ( $entry['name'] === $wanted || $entry['basename'] === $wanted ) {
				// Safety cap: never slurp huge files here.
				if ( $entry['size'] > 10 * 1024 * 1024 ) {
					fclose( $handle );
					return null;
				}
				$tmp = fopen( 'php://temp', 'r+b' ); // phpcs:ignore
				try {
					// package.json is always plain - decode off.
					self::copy_data( $handle, $tmp, $entry );
				} catch ( Exception $e ) {
					fclose( $tmp );
					fclose( $handle );
					return null;
				}
				rewind( $tmp );
				$result = stream_get_contents( $tmp );
				fclose( $tmp );
				break;
			} else {
				self::skip_data( $handle, $entry['size'] );
			}
		}
		fclose( $handle );
		return $result;
	}

	/**
	 * Count entries + total bytes without extracting (for progress bars).
	 *
	 * @param string $archive_path Absolute path.
	 * @return array array('files' => int, 'bytes' => int)
	 */
	public static function scan( $archive_path ) {
		$handle = @fopen( $archive_path, 'rb' ); // phpcs:ignore
		if ( false === $handle ) {
			throw new Exception( __( 'Cannot open archive.', 'unboundwp' ) );
		}
		$files = 0;
		$bytes = 0;
		while ( ! feof( $handle ) ) {
			$entry = self::read_header( $handle );
			if ( null === $entry ) {
				break;
			}
			$files++;
			$bytes += $entry['size'];
			self::skip_data( $handle, $entry['size'] );
		}
		fclose( $handle );
		return array(
			'files' => $files,
			'bytes' => $bytes,
		);
	}

	// ------------------------------------------------------------------
	// Low-level I/O
	// ------------------------------------------------------------------

	/**
	 * Write all bytes (handles short writes).
	 *
	 * @param resource $handle Open handle.
	 * @param string   $data Bytes.
	 * @throws Exception On failure.
	 */
	public static function fwrite_all( $handle, $data ) {
		$len = strlen( $data );
		$off = 0;
		while ( $off < $len ) {
			$w = @fwrite( $handle, substr( $data, $off ) ); // phpcs:ignore
			if ( false === $w || 0 === $w ) {
				throw new Exception( __( 'Cannot write to archive (disk full?).', 'unboundwp' ) );
			}
			$off += $w;
		}
	}

	/**
	 * Read exactly $length bytes (chunked).
	 *
	 * @param resource $handle Open handle.
	 * @param int      $length Bytes wanted.
	 * @return string Bytes read (may be shorter only at EOF).
	 */
	public static function fread_all( $handle, $length ) {
		$length = (int) $length;
		$out    = '';
		while ( strlen( $out ) < $length && ! feof( $handle ) ) {
			$want = min( self::CHUNK, $length - strlen( $out ) );
			$data = @fread( $handle, $want ); // phpcs:ignore
			if ( false === $data || '' === $data ) {
				break;
			}
			$out .= $data;
		}
		return $out;
	}

	/**
	 * CRC32 (hex) of a file, streamed.
	 *
	 * @param string $path Absolute path.
	 * @return string 8-char hex.
	 */
	public static function crc32_file( $path ) {
		$ctx = hash_init( 'crc32b' );
		$in  = @fopen( $path, 'rb' ); // phpcs:ignore
		if ( false === $in ) {
			return '00000000';
		}
		while ( ! feof( $in ) ) {
			$data = fread( $in, self::CHUNK ); // phpcs:ignore
			if ( false === $data || '' === $data ) {
				break;
			}
			hash_update( $ctx, $data );
		}
		fclose( $in );
		return strtolower( hash_final( $ctx ) );
	}
}
