<?php
defined( 'ABSPATH' ) || exit;

/**
 * Images outside the Media Library: theme and plugin images, and files in uploads
 * that are not attachments (Elementor thumbnails, plugin-generated images...).
 *
 * They live in their own table and reach the worker as items with ids "f<row id>".
 * Copies of uploads files sit next to attachment copies (uploads-optimized/<rel>);
 * copies of other folders under uploads-optimized/@<folder>/<rel>.
 */
class EHIO_Files {

	const DB_VERSION = '1';
	const SCAN_LIMIT = 30000; // Files per folder, a guard against huge plugin folders.
	const SKIP_DIRS  = array( 'node_modules', '.git', 'vendor', 'cache-busting' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ehio_files';
	}

	public static function install() {
		global $wpdb;
		if ( get_option( 'ehio_files_db' ) === self::DB_VERSION && self::table_exists() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			root varchar(32) NOT NULL,
			rel varchar(700) NOT NULL,
			path_hash char(32) NOT NULL,
			bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			sig varchar(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			lease int(10) unsigned NOT NULL DEFAULT 0,
			claimed int(10) unsigned NOT NULL DEFAULT 0,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			message varchar(300) NOT NULL DEFAULT '',
			out_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash),
			KEY status (status)
			) $charset;"
		);
		update_option( 'ehio_files_db', self::DB_VERSION, false );
	}

	private static function table_exists() {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) === self::table();
	}

	public static function uninstall() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( 'ehio_files_db' );
		delete_option( 'ehio_scan' );
	}

	// ------------------------------------------------------------------ folders

	/** Folders that exist on this site, with labels. */
	public static function available_dirs() {
		$dirs = array(
			'uploads' => 'Other images in uploads',
			'themes'  => 'Themes',
			'plugins' => 'Plugins',
			'gallery' => 'Gallery (NextGEN)',
			'cache'   => 'Cache folder',
		);
		foreach ( array_keys( $dirs ) as $root ) {
			if ( ! is_dir( self::root_dir( $root ) ) ) {
				unset( $dirs[ $root ] );
			}
		}
		return $dirs;
	}

	public static function root_dir( $root ) {
		if ( $root === 'uploads' ) {
			return EHIO_Paths::basedir();
		}
		return wp_normalize_path( WP_CONTENT_DIR . '/' . $root );
	}

	public static function root_url( $root ) {
		if ( $root === 'uploads' ) {
			return untrailingslashit( wp_upload_dir( null, false )['baseurl'] );
		}
		return untrailingslashit( content_url( $root ) );
	}

	public static function output_path( $root, $rel, $format ) {
		if ( $root === 'uploads' ) {
			return EHIO_Paths::output_path( $rel, $format );
		}
		return EHIO_Paths::optimized_root_dir() . '/@' . $root . '/' . $rel . '.' . $format;
	}

	public static function enabled_dirs() {
		return array_values( array_intersect( EHIO_Settings::get()['dirs'], array_keys( self::available_dirs() ) ) );
	}

	// --------------------------------------------------------------------- scan

	/** Uploads-relative paths of every Media Library file, so the uploads scan can skip them. */
	public static function library_paths() {
		global $wpdb;
		$paths  = array();
		$offset = 0;
		do {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' ORDER BY meta_id LIMIT %d, 500",
					$offset
				)
			);
			foreach ( $rows as $raw ) {
				$meta = maybe_unserialize( $raw );
				if ( empty( $meta['file'] ) || ! is_string( $meta['file'] ) ) {
					continue;
				}
				$dir                  = dirname( $meta['file'] );
				$dir                  = ( $dir === '.' ) ? '' : $dir . '/';
				$paths[ $meta['file'] ] = true;
				if ( ! empty( $meta['original_image'] ) ) {
					$paths[ $dir . $meta['original_image'] ] = true;
				}
				foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$paths[ $dir . $size['file'] ] = true;
					}
				}
			}
			$offset += 500;
		} while ( count( $rows ) === 500 );
		return $paths;
	}

	/** Image files under a folder, as rel => [bytes, mtime]. */
	private static function walk( $root ) {
		$base  = self::root_dir( $root );
		$found = array();
		if ( ! is_dir( $base ) ) {
			return $found;
		}
		$skip = array_flip( self::SKIP_DIRS );
		$it   = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				function ( $file ) use ( $skip, $root ) {
					if ( $file->isDir() ) {
						$name = $file->getFilename();
						// Multisite subsites have their own Media Library and scan.
						if ( $root === 'uploads' && $name === 'sites' && $file->getPath() === EHIO_Paths::basedir() && is_multisite() ) {
							return false;
						}
						return ! isset( $skip[ $name ] ) && $name[0] !== '.';
					}
					return true;
				}
			)
		);
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || ! EHIO_Paths::is_source_extension( $file->getFilename() ) ) {
				continue;
			}
			$size = $file->getSize();
			if ( $size <= 0 || $size > EHIO_Rest::MAX_BYTES ) {
				continue;
			}
			$rel = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $base ) ), '/' );
			if ( $rel === '' || strpos( $rel, '..' ) !== false ) {
				continue;
			}
			$found[ $rel ] = array( $size, $file->getMTime() );
			if ( count( $found ) >= self::SCAN_LIMIT ) {
				break;
			}
		}
		return $found;
	}

	/**
	 * Syncs the table with the enabled folders. New or changed files (and every file
	 * with $force) become pending; vanished files and disabled folders are removed
	 * together with their copies.
	 *
	 * @return array{queued:int, counts:array<string,int>}
	 */
	public static function scan( $force = false ) {
		global $wpdb;
		self::install();
		$table   = self::table();
		$enabled = self::enabled_dirs();
		$queued  = 0;
		$counts  = array();

		// Folders that were switched off: forget them and delete their copies.
		$known = $wpdb->get_col( "SELECT DISTINCT root FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array_diff( $known, $enabled ) as $root ) {
			self::delete_root( $root );
		}

		$library = in_array( 'uploads', $enabled, true ) ? self::library_paths() : array();
		foreach ( $enabled as $root ) {
			$files = self::walk( $root );
			if ( $root === 'uploads' ) {
				$files = array_diff_key( $files, $library );
			}
			$counts[ $root ] = count( $files );

			$existing = array();
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, rel, sig, status FROM {$table} WHERE root = %s", $root ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$existing[ $row->rel ] = $row;
			}
			foreach ( $files as $rel => $info ) {
				$sig = $info[0] . ':' . $info[1];
				if ( ! isset( $existing[ $rel ] ) ) {
					$wpdb->insert(
						$table,
						array(
							'root'      => $root,
							'rel'       => $rel,
							'path_hash' => md5( $root . '|' . $rel ),
							'bytes'     => $info[0],
							'sig'       => $sig,
							'status'    => 'pending',
						)
					);
					$queued++;
					continue;
				}
				$row = $existing[ $rel ];
				unset( $existing[ $rel ] );
				if ( $force || $row->sig !== $sig || $row->status === 'idle' ) {
					$wpdb->update(
						$table,
						array(
							'bytes'    => $info[0],
							'sig'      => $sig,
							'status'   => 'pending',
							'lease'    => 0,
							'attempts' => 0,
							'message'  => '',
						),
						array( 'id' => $row->id )
					);
					$queued++;
				}
			}
			foreach ( $existing as $rel => $row ) { // Files that are gone.
				self::delete_outputs( $root, $rel );
				$wpdb->delete( $table, array( 'id' => $row->id ) );
			}
		}
		update_option(
			'ehio_scan',
			array(
				'time'   => time(),
				'counts' => $counts,
			),
			false
		);
		return array(
			'queued' => $queued,
			'counts' => $counts,
		);
	}

	public static function delete_root( $root ) {
		global $wpdb;
		$table = self::table();
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT rel FROM {$table} WHERE root = %s", $root ) ) as $rel ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			self::delete_outputs( $root, $rel );
		}
		$wpdb->delete( $table, array( 'root' => $root ) );
	}

	private static function delete_outputs( $root, $rel ) {
		foreach ( EHIO_Settings::FORMATS as $format ) {
			$path = self::output_path( $root, $rel, $format );
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	// ------------------------------------------------------------- worker jobs

	/** Claims up to $limit pending files. @return array[] queue items */
	public static function claim( $limit ) {
		global $wpdb;
		if ( $limit <= 0 || ! self::table_exists() ) {
			return array();
		}
		$table = self::table();
		$now   = time();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' OR ( status = 'processing' AND lease < %d ) ORDER BY id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$limit
			)
		);
		$items = array();
		foreach ( $rows as $row ) {
			$attempts = (int) $row->attempts + ( $row->status === 'processing' ? 1 : 0 );
			if ( $attempts >= EHIO_Queue::MAX_ATTEMPTS ) {
				$wpdb->update( $table, array( 'status' => 'failed', 'message' => 'Worker lease expired too many times.' ), array( 'id' => $row->id ) );
				continue;
			}
			$abs = self::root_dir( $row->root ) . '/' . $row->rel;
			if ( ! is_file( $abs ) || ! in_array( $row->root, self::enabled_dirs(), true ) ) {
				$wpdb->delete( $table, array( 'id' => $row->id ) );
				continue;
			}
			// Compare-and-set so two workers never take the same file.
			$won = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'processing', lease = %d, claimed = %d, attempts = %d WHERE id = %d AND status = %s AND lease = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$now + EHIO_Queue::LEASE_SECONDS,
					$now,
					$attempts,
					$row->id,
					$row->status,
					$row->lease
				)
			);
			if ( ! $won ) {
				continue;
			}
			$items[] = array(
				'id'    => 'f' . $row->id,
				'files' => array(
					array(
						'rel'     => $row->rel,
						'url'     => self::root_url( $row->root ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $row->rel ) ) ),
						'bytes'   => (int) filesize( $abs ),
						'formats' => EHIO_Paths::target_formats( $row->rel ),
					),
				),
			);
		}
		return $items;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Where an uploaded copy for this job goes, or WP_Error. */
	public static function result_target( $id, $rel, $format ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'ehio_not_found', 'File job not found.', array( 'status' => 404 ) );
		}
		if ( $row->rel !== $rel ) {
			return new WP_Error( 'ehio_bad_rel', 'File does not belong to this job.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $format, EHIO_Paths::target_formats( $rel ), true ) ) {
			return new WP_Error( 'ehio_format_not_wanted', 'This format is not produced for this file.', array( 'status' => 422 ) );
		}
		$abs = self::root_dir( $row->root ) . '/' . $row->rel;
		return array(
			'dest'  => self::output_path( $row->root, $row->rel, $format ),
			'bytes' => is_file( $abs ) ? (int) filesize( $abs ) : 0,
		);
	}

	public static function complete( $id, $status, $message ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return 'gone';
		}
		$table = self::table();
		if ( $status === 'done' ) {
			$best = (int) $row->bytes;
			foreach ( EHIO_Settings::FORMATS as $format ) {
				$path = self::output_path( $row->root, $row->rel, $format );
				if ( ! is_file( $path ) ) {
					continue;
				}
				if ( filemtime( $path ) < (int) $row->claimed || ! in_array( $format, EHIO_Paths::target_formats( $row->rel ), true ) ) {
					wp_delete_file( $path );
					continue;
				}
				$best = min( $best, (int) filesize( $path ) );
			}
			$wpdb->update(
				$table,
				array(
					'status'    => 'done',
					'message'   => $message,
					'lease'     => 0,
					'out_bytes' => $best,
				),
				array( 'id' => $id )
			);
			return 'done';
		}
		$attempts = (int) $row->attempts + 1;
		$state    = $attempts >= EHIO_Queue::MAX_ATTEMPTS ? 'failed' : 'pending';
		$wpdb->update(
			$table,
			array(
				'status'   => $state,
				'attempts' => $attempts,
				'message'  => $message,
				'lease'    => 0,
			),
			array( 'id' => $id )
		);
		return $state;
	}

	/**
	 * Stop button: pending files become 'idle' (counted as not optimized yet), or
	 * 'done' again when they were optimized before. scan() queues idle files again.
	 */
	public static function stop_pending() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		$table = self::table();
		$back  = (int) $wpdb->query( "UPDATE {$table} SET status = 'done', lease = 0 WHERE status = 'pending' AND out_bytes > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$idle  = (int) $wpdb->query( "UPDATE {$table} SET status = 'idle', lease = 0, attempts = 0 WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $back + $idle;
	}

	public static function retry_failed() {
		global $wpdb;
		if ( ! self::table_exists() ) {
			return 0;
		}
		return (int) $wpdb->query( 'UPDATE ' . self::table() . " SET status = 'pending', attempts = 0, lease = 0 WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Counts per folder and status, plus byte totals of finished files. */
	public static function stats() {
		global $wpdb;
		$out = array(
			'roots'     => array(),
			'pending'   => 0,
			'processing'=> 0,
			'done'      => 0,
			'failed'    => 0,
			'idle'      => 0, // Taken out of the queue with Stop.
			'total'     => 0,
			'orig'      => 0,
			'out'       => 0,
		);
		if ( ! self::table_exists() ) {
			return $out;
		}
		$table = self::table();
		foreach ( $wpdb->get_results( "SELECT root, status, COUNT(*) AS n, SUM(bytes) AS b, SUM(out_bytes) AS o FROM {$table} GROUP BY root, status" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n = (int) $r->n;
			if ( ! isset( $out['roots'][ $r->root ] ) ) {
				$out['roots'][ $r->root ] = array(
					'total' => 0,
					'done'  => 0,
				);
			}
			$out['roots'][ $r->root ]['total'] += $n;
			$out['total']                      += $n;
			if ( isset( $out[ $r->status ] ) ) {
				$out[ $r->status ] += $n;
			}
			if ( $r->status === 'done' ) {
				$out['roots'][ $r->root ]['done'] += $n;
				$out['orig']                      += (int) $r->b;
				$out['out']                       += (int) $r->o;
			}
		}
		return $out;
	}
}
