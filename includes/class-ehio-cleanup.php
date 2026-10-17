<?php
defined( 'ABSPATH' ) || exit;

/**
 * Deletes the AVIF/WebP copies whenever WordPress deletes a source file
 * (attachment deleted, image edited, sizes regenerated).
 */
class EHIO_Cleanup {

	const PRUNE_HOOK   = 'ehio_prune_avif';
	const PRUNE_OPTION = 'ehio_avif_pruned'; // Set once every existing copy has been checked.
	const PRUNE_BUDGET = 20;                 // Seconds per run at most (see budget()).
	const PRUNE_CURSOR = 'ehio_avif_prune_at'; // Last folder finished by a run that ran out of time.
	const PRUNE_LOCK   = 'ehio_avif_prune_lock';
	const SMALL_HOOK   = 'ehio_prune_small';
	const SMALL_CURSOR = 'ehio_small_prune_at'; // Last attachment ID done by the small-sizes cleanup.

	public static function init() {
		add_filter( 'wp_delete_file', array( __CLASS__, 'on_delete_file' ) );
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune_cron' ) );
		add_action( self::SMALL_HOOK, array( __CLASS__, 'small_cron' ) );
		// Also on sites nobody logs in to after the update (the upgrade hook runs in wp-admin only).
		add_action( 'init', array( __CLASS__, 'schedule_prune' ) );
	}

	public static function webp_enabled() {
		return in_array( 'webp', EHIO_Settings::get()['formats'], true );
	}

	/**
	 * Browsers that accept AVIF are sent the AVIF copy before the WebP one, so an AVIF that is
	 * not smaller than its WebP sibling only makes the page heavier. Deletes it; returns true if so.
	 */
	public static function drop_heavier_avif( $avif, $webp ) {
		if ( ! is_file( $avif ) || ! is_file( $webp ) ) {
			return false;
		}
		clearstatcache( true, $avif );
		clearstatcache( true, $webp );
		if ( (int) filesize( $avif ) < (int) filesize( $webp ) ) {
			return false;
		}
		return @unlink( $avif ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** Queues the one-time check of copies converted before 1.0.9 (called on upgrade and on init). */
	public static function schedule_prune() {
		if ( get_option( self::PRUNE_OPTION ) || wp_next_scheduled( self::PRUNE_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 30, self::PRUNE_HOOK );
	}

	public static function prune_cron() {
		$result = self::prune( self::budget() );
		if ( $result['locked'] ) {
			wp_schedule_single_event( time() + 120, self::PRUNE_HOOK );
		} elseif ( $result['complete'] ) {
			// Autoloaded: schedule_prune() reads it on every request.
			update_option( self::PRUNE_OPTION, time(), true );
		} else {
			wp_schedule_single_event( time() + 60, self::PRUNE_HOOK ); // Out of time: carry on next minute.
		}
	}

	/** Seconds one run may take: 20, or less when PHP's own time limit is shorter. */
	public static function budget() {
		$limit = (int) ini_get( 'max_execution_time' );
		return $limit > 0 ? max( 3, min( self::PRUNE_BUDGET, $limit - 10 ) ) : self::PRUNE_BUDGET;
	}

	/**
	 * Deletes every AVIF copy that is not smaller than its WebP sibling, in the whole optimized
	 * folder (Media Library and other folders). With WebP turned off nothing is removed (the
	 * WebP copies left on disk are no longer served, so the AVIF is the best copy).
	 *
	 * Works folder by folder in name order and remembers the last finished folder, so a run cut
	 * short by $budget carries on where it stopped instead of starting over: a big site finishes
	 * in a few runs and the job then stops for good. Only one run at a time.
	 *
	 * @return array{checked:int,removed:int,bytes:int,complete:bool,locked:bool}
	 */
	public static function prune( $budget = 0 ) {
		$out  = array(
			'checked'  => 0,
			'removed'  => 0,
			'bytes'    => 0,
			'complete' => true,
			'locked'   => false,
		);
		$root = EHIO_Paths::optimized_root_dir();
		if ( ! self::webp_enabled() || ! is_dir( $root ) ) {
			delete_option( self::PRUNE_CURSOR );
			return $out;
		}
		if ( get_transient( self::PRUNE_LOCK ) ) {
			$out['complete'] = false;
			$out['locked']   = true;
			return $out;
		}
		set_transient( self::PRUNE_LOCK, 1, 120 );

		$start  = microtime( true );
		$cursor = (string) get_option( self::PRUNE_CURSOR, '' );
		$dirs   = self::folders( $root );
		sort( $dirs, SORT_STRING );
		$done = 0;

		foreach ( $dirs as $dir ) {
			if ( $cursor !== '' && strcmp( $dir, $cursor ) <= 0 ) {
				continue; // Finished in an earlier run.
			}
			// Always finish at least one folder per run, so every run moves forward.
			if ( $budget && $done && microtime( true ) - $start > $budget ) {
				$out['complete'] = false;
				break;
			}
			$done++;
			foreach ( (array) glob( $dir . '/*.avif', GLOB_NOSORT ) as $avif ) {
				if ( ! is_file( $avif ) ) {
					continue;
				}
				$out['checked']++;
				$size = (int) filesize( $avif );
				if ( self::drop_heavier_avif( $avif, substr( $avif, 0, -5 ) . '.webp' ) ) {
					$out['removed']++;
					$out['bytes'] += $size;
				}
			}
			update_option( self::PRUNE_CURSOR, $dir, false );
		}
		if ( $out['complete'] ) {
			delete_option( self::PRUNE_CURSOR ); // The next run (button, CLI) starts from the top.
		}
		delete_transient( self::PRUNE_LOCK );
		return $out;
	}

	/**
	 * Every folder under $root (itself included). Lists folders only, so a library with
	 * hundreds of thousands of files costs a few hundred directory reads, not one stat per file.
	 */
	private static function folders( $root ) {
		$out   = array();
		$stack = array( $root );
		while ( $stack ) {
			$dir   = array_pop( $stack );
			$out[] = $dir;
			foreach ( (array) glob( $dir . '/*', GLOB_ONLYDIR | GLOB_NOSORT ) as $sub ) {
				if ( $sub && ! is_link( $sub ) ) {
					$stack[] = $sub;
				}
			}
		}
		return $out;
	}

	/** After "Small sizes" was raised: remove the copies of sizes that are now skipped. */
	public static function schedule_small() {
		update_option( self::SMALL_CURSOR, 0, false );
		wp_clear_scheduled_hook( self::SMALL_HOOK );
		wp_schedule_single_event( time() + 10, self::SMALL_HOOK );
	}

	public static function small_cron() {
		if ( ! self::prune_small( self::budget() )['complete'] ) {
			wp_schedule_single_event( time() + 60, self::SMALL_HOOK );
		}
	}

	/**
	 * Deletes the AVIF/WebP copies of Media Library sizes below the "Small sizes" setting,
	 * 200 images at a time in ID order, remembering where it stopped.
	 *
	 * @return array{removed:int,complete:bool}
	 */
	public static function prune_small( $budget = 0 ) {
		global $wpdb;
		$out = array(
			'removed'  => 0,
			'complete' => true,
		);
		if ( ! (int) EHIO_Settings::get()['min_size'] ) {
			delete_option( self::SMALL_CURSOR );
			return $out;
		}
		$start = microtime( true );
		$after = (int) get_option( self::SMALL_CURSOR, 0 );
		while ( true ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp') AND ID > %d ORDER BY ID ASC LIMIT 200",
					$after
				)
			);
			if ( ! $ids ) {
				delete_option( self::SMALL_CURSOR );
				return $out;
			}
			foreach ( $ids as $id ) {
				foreach ( EHIO_Paths::attachment_files( (int) $id, true ) as $file ) {
					if ( empty( $file['small'] ) ) {
						continue;
					}
					foreach ( EHIO_Settings::FORMATS as $format ) {
						$copy = EHIO_Paths::output_path( $file['rel'], $format );
						if ( is_file( $copy ) && @unlink( $copy ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
							$out['removed']++;
						}
					}
				}
				$after = (int) $id;
			}
			update_option( self::SMALL_CURSOR, $after, false );
			if ( $budget && microtime( true ) - $start > $budget ) {
				$out['complete'] = false;
				return $out;
			}
		}
	}

	public static function on_delete_file( $file ) {
		if ( ! is_string( $file ) || ! EHIO_Paths::is_source_extension( $file ) ) {
			return $file;
		}
		$base = EHIO_Paths::basedir();
		$path = wp_normalize_path( $file );
		if ( strpos( $path, $base . '/' ) !== 0 ) {
			return $file;
		}
		$rel = substr( $path, strlen( $base ) + 1 );
		foreach ( EHIO_Settings::FORMATS as $format ) {
			$copy = EHIO_Paths::output_path( $rel, $format );
			if ( is_file( $copy ) ) {
				@unlink( $copy ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		return $file;
	}
}
