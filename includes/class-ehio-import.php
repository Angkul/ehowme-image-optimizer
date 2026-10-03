<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-time import of the copies made by Converter for Media (wp-content/uploads-webpc),
 * so a site switching plugins does not have to convert its library again.
 *
 * Converter for Media names copies like we do (photo.jpg.avif), only under another folder:
 *   uploads-webpc/uploads/2024/01/photo.jpg.avif -> uploads-optimized/2024/01/photo.jpg.avif
 *   uploads-webpc/themes/x/bg.jpg.webp           -> uploads-optimized/@themes/x/bg.jpg.webp
 * Files are moved (rename), so no extra disk space is used. "<copy>.deleted" markers mean
 * Converter for Media found that copy larger than the original; those stay skipped.
 *
 * Runs in time-boxed steps: move files, then mark Media Library images.
 */
class EHIO_Import {

	const STATE  = 'ehio_import';
	const CACHE  = 'ehio_import_found';
	const PLUGIN = 'webp-converter-for-media/webp-converter-for-media.php';
	const ROOTS  = array( 'uploads', 'themes', 'plugins', 'gallery', 'cache' );

	public static function source_dir() {
		return wp_normalize_path( WP_CONTENT_DIR . '/uploads-webpc' );
	}

	public static function source_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( self::PLUGIN );
	}

	public static function state() {
		$state = get_option( self::STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	/** Copies waiting in uploads-webpc (cached for 10 minutes; 0 when there is nothing). */
	public static function found( $refresh = false ) {
		if ( ! is_dir( self::source_dir() ) ) {
			return array(
				'files' => 0,
				'bytes' => 0,
			);
		}
		$cached = get_transient( self::CACHE );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$files = 0;
		$bytes = 0;
		foreach ( self::iterate() as $file ) {
			$files++;
			$bytes += $file->getSize();
			if ( $files >= 500000 ) {
				break;
			}
		}
		$found = array(
			'files' => $files,
			'bytes' => $bytes,
		);
		set_transient( self::CACHE, $found, 10 * MINUTE_IN_SECONDS );
		return $found;
	}

	/** AVIF/WebP copies under uploads-webpc (no .deleted markers). */
	private static function iterate() {
		$base = self::source_dir();
		if ( ! is_dir( $base ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && preg_match( '/\.(avif|webp)$/i', $file->getFilename() ) ) {
				yield $file;
			}
		}
	}

	/**
	 * Does as much as fits in $seconds. Call again until it returns done = true.
	 *
	 * @return array{done:bool, phase:string, moved:int, skipped:int, failed:int, marked:int, queued:int}
	 */
	public static function run( $seconds = 20 ) {
		$deadline = microtime( true ) + $seconds;
		$state    = self::state();
		if ( empty( $state['phase'] ) || $state['phase'] === 'done' ) {
			$state = array(
				'phase'   => 'move',
				'started' => time(),
				'moved'   => 0,
				'skipped' => 0,
				'failed'  => 0,
				'marked'  => 0,
				'queued'  => 0,
				'last_id' => 0,
				'roots'   => array(),
			);
		}

		if ( $state['phase'] === 'move' ) {
			$finished = self::move_files( $state, $deadline );
			if ( $finished ) {
				$state['phase'] = 'mark';
			}
		}
		if ( $state['phase'] === 'mark' && microtime( true ) < $deadline ) {
			if ( self::mark_library( $state, $deadline ) ) {
				$state['phase']    = 'done';
				$state['finished'] = time();
				self::finish( $state );
			}
		}
		update_option( self::STATE, $state, false );
		delete_transient( self::CACHE );
		return array(
			'done'    => $state['phase'] === 'done',
			'phase'   => $state['phase'],
			'moved'   => (int) $state['moved'],
			'skipped' => (int) $state['skipped'],
			'failed'  => (int) ( $state['failed'] ?? 0 ),
			'marked'  => (int) $state['marked'],
			'queued'  => (int) $state['queued'],
		);
	}

	/** Phase 1: move copies into uploads-optimized. Returns true when nothing is left. */
	private static function move_files( array &$state, $deadline ) {
		global $wpdb;
		EHIO_Files::install();
		$base     = self::source_dir();
		$enabled  = EHIO_Settings::get()['formats'];
		$library  = null;
		$finished = true;

		foreach ( self::iterate() as $file ) {
			if ( microtime( true ) > $deadline ) {
				$finished = false;
				break;
			}
			$path = wp_normalize_path( $file->getPathname() );
			$sub  = ltrim( substr( $path, strlen( $base ) ), '/' );          // uploads/2024/01/photo.jpg.avif
			$root = strtok( $sub, '/' );
			$rest = substr( $sub, strlen( $root ) + 1 );                      // 2024/01/photo.jpg.avif
			$fmt  = strtolower( pathinfo( $rest, PATHINFO_EXTENSION ) );
			$rel  = substr( $rest, 0, -strlen( $fmt ) - 1 );                  // 2024/01/photo.jpg
			$orig = wp_normalize_path( WP_CONTENT_DIR . '/' . $root . '/' . $rel );

			$ok = in_array( $root, self::ROOTS, true ) && $rel !== '' && strpos( $rel, '..' ) === false
				&& in_array( $fmt, $enabled, true ) && EHIO_Paths::is_source_extension( $rel )
				&& in_array( $fmt, EHIO_Paths::target_formats( $rel ), true ) && is_file( $orig )
				&& $file->getSize() > 0 && $file->getSize() < filesize( $orig );
			if ( ! $ok ) {
				$state['skipped']++;
				continue;
			}

			if ( $root === 'uploads' ) {
				// Our layout keeps the multisite offset (sites/2/...) inside uploads-optimized too.
				$dest = EHIO_Paths::optimized_root_dir() . '/' . $rel . '.' . $fmt;
			} else {
				$dest = EHIO_Paths::optimized_root_dir() . '/@' . $root . '/' . $rel . '.' . $fmt;
			}
			if ( is_file( $dest ) ) {
				$state['skipped']++; // Ours is newer; leave theirs for Converter for Media's uninstall.
				continue;
			}
			if ( ! wp_mkdir_p( dirname( $dest ) ) || ! self::move( $path, $dest ) ) {
				$state['failed'] = (int) ( $state['failed'] ?? 0 ) + 1;
				continue;
			}
			$state['moved']++;

			// Images outside the Media Library become finished rows of the folder table.
			if ( $root !== 'uploads' || self::outside_library( $rel, $library ) ) {
				$table_rel = $root === 'uploads' ? self::site_rel( $rel ) : $rel;
				if ( $table_rel !== null ) {
					self::record_file( $root, $table_rel, $orig );
					$state['roots'][ $root ] = true;
				}
			}
		}
		return $finished;
	}

	/** rename(), or copy + delete when rename is not allowed (other owner, other disk). */
	private static function move( $from, $to ) {
		if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return true;
		}
		if ( ! @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		@unlink( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- if this fails the leftover goes with Converter for Media's uninstall.
		return true;
	}

	/** uploads-relative path for the current site, or null for another subsite's file. */
	private static function site_rel( $rel ) {
		$offset = ltrim( EHIO_Paths::site_offset(), '/' );
		if ( $offset === '' ) {
			return ( is_multisite() && strpos( $rel, 'sites/' ) === 0 ) ? null : $rel;
		}
		return strpos( $rel, $offset . '/' ) === 0 ? substr( $rel, strlen( $offset ) + 1 ) : null;
	}

	private static function outside_library( $rel, &$library ) {
		if ( $library === null ) {
			$library = EHIO_Files::library_paths();
		}
		$site_rel = self::site_rel( $rel );
		return $site_rel !== null && ! isset( $library[ $site_rel ] );
	}

	private static function record_file( $root, $rel, $orig ) {
		global $wpdb;
		$table = EHIO_Files::table();
		$hash  = md5( $root . '|' . $rel );
		$size  = (int) filesize( $orig );
		$best  = $size;
		foreach ( EHIO_Settings::FORMATS as $format ) {
			$copy = EHIO_Files::output_path( $root, $rel, $format );
			if ( is_file( $copy ) ) {
				$best = min( $best, (int) filesize( $copy ) );
			}
		}
		$row  = array(
			'root'      => $root,
			'rel'       => $rel,
			'path_hash' => $hash,
			'bytes'     => $size,
			'sig'       => $size . ':' . filemtime( $orig ),
			'status'    => 'done',
			'message'   => 'Imported from Converter for Media',
			'out_bytes' => $best,
		);
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE path_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $table, $row );
		}
	}

	/**
	 * Phase 2: Media Library images whose main file now has every format (or a
	 * ".deleted" marker saying that format was larger) are marked optimized; images
	 * with only some copies are queued so the worker fills the gaps.
	 */
	private static function mark_library( array &$state, $deadline ) {
		global $wpdb;
		$enabled = EHIO_Settings::get()['formats'];
		while ( microtime( true ) < $deadline ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp') AND ID > %d ORDER BY ID LIMIT 100",
					(int) $state['last_id']
				)
			);
			if ( ! $ids ) {
				return true;
			}
			foreach ( array_map( 'intval', $ids ) as $id ) {
				$state['last_id'] = $id;
				$status           = (string) get_post_meta( $id, EHIO_Queue::STATUS, true );
				if ( in_array( $status, array( 'done', 'excluded', 'processing' ), true ) ) {
					continue;
				}
				$files = EHIO_Paths::attachment_files( $id );
				if ( ! $files ) {
					continue;
				}
				$meta     = wp_get_attachment_metadata( $id );
				$main_rel = $meta['file'];
				$main     = $files[ $main_rel ] ?? reset( $files );
				$any      = false;
				$complete = true;
				foreach ( $files as $file ) {
					foreach ( $file['formats'] as $format ) {
						if ( is_file( EHIO_Paths::output_path( $file['rel'], $format ) ) ) {
							$any = true;
						}
					}
				}
				foreach ( array_intersect( $enabled, $main['formats'] ) as $format ) {
					$has    = is_file( EHIO_Paths::output_path( $main['rel'], $format ) );
					$larger = is_file( self::source_dir() . '/uploads' . EHIO_Paths::site_offset() . '/' . $main['rel'] . '.' . $format . '.deleted' );
					if ( ! $has && ! $larger ) {
						$complete = false;
					}
				}
				if ( $any && $complete ) {
					update_post_meta( $id, EHIO_Queue::STATUS, 'done' );
					update_post_meta( $id, EHIO_Queue::SIG, EHIO_Queue::signature( $id ) );
					update_post_meta( $id, EHIO_Queue::MESSAGE, 'Imported from Converter for Media' );
					update_post_meta( $id, EHIO_Queue::SAVED, 0 );
					EHIO_Queue::record_bytes( $id );
					$state['marked']++;
				} elseif ( $any && EHIO_Queue::maybe_queue( $id, true ) ) {
					$state['queued']++;
				}
			}
		}
		return false;
	}

	/** Turns on the folders that received files, rewrites the rules and wakes the worker. */
	private static function finish( array $state ) {
		$roots = array_keys( (array) $state['roots'] );
		if ( $roots ) {
			$dirs = EHIO_Settings::get()['dirs'];
			EHIO_Settings::update( array( 'dirs' => array_values( array_unique( array_merge( $dirs, $roots ) ) ) ) );
		}
		EHIO_Htaccess::write();
		if ( $state['queued'] ) {
			EHIO_Notifier::ping();
		}
	}
}
