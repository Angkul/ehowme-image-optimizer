<?php
defined( 'ABSPATH' ) || exit;

/**
 * Deletes the AVIF/WebP copies whenever WordPress deletes a source file
 * (attachment deleted, image edited, sizes regenerated).
 */
class EHIO_Cleanup {

	const PRUNE_HOOK   = 'ehio_prune_avif';
	const PRUNE_OPTION = 'ehio_avif_pruned'; // Set once every existing copy has been checked.
	const PRUNE_BUDGET = 20;                 // Seconds per cron run on shared hosting.

	public static function init() {
		add_filter( 'wp_delete_file', array( __CLASS__, 'on_delete_file' ) );
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune_cron' ) );
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

	/** Queues the one-time check of copies converted before 1.0.9 (called on upgrade). */
	public static function schedule_prune() {
		if ( get_option( self::PRUNE_OPTION ) || wp_next_scheduled( self::PRUNE_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 30, self::PRUNE_HOOK );
	}

	public static function prune_cron() {
		$result = self::prune( self::PRUNE_BUDGET );
		if ( $result['complete'] ) {
			update_option( self::PRUNE_OPTION, time(), false );
		} else {
			wp_schedule_single_event( time() + 60, self::PRUNE_HOOK ); // Out of time: carry on next minute.
		}
	}

	/**
	 * Deletes every AVIF copy that is not smaller than its WebP sibling, in the whole optimized
	 * folder (Media Library and other folders). Safe to stop and run again: removed files are gone,
	 * the rest are skipped quickly. With WebP turned off nothing is removed (the WebP copies left
	 * on disk are no longer served, so the AVIF is the best copy).
	 *
	 * @return array{checked:int,removed:int,bytes:int,complete:bool}
	 */
	public static function prune( $budget = 0 ) {
		$out  = array(
			'checked'  => 0,
			'removed'  => 0,
			'bytes'    => 0,
			'complete' => true,
		);
		$root = EHIO_Paths::optimized_root_dir();
		if ( ! self::webp_enabled() || ! is_dir( $root ) ) {
			return $out;
		}
		$start = microtime( true );
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $files as $file ) {
			if ( $budget && microtime( true ) - $start > $budget ) {
				$out['complete'] = false;
				break;
			}
			$path = $file->getPathname();
			if ( substr( $path, -5 ) !== '.avif' ) {
				continue;
			}
			$out['checked']++;
			$size = (int) $file->getSize();
			if ( self::drop_heavier_avif( $path, substr( $path, 0, -5 ) . '.webp' ) ) {
				$out['removed']++;
				$out['bytes'] += $size;
			}
		}
		return $out;
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
