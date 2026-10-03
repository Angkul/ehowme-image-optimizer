<?php
defined( 'ABSPATH' ) || exit;

/**
 * Deletes the AVIF/WebP copies whenever WordPress deletes a source file
 * (attachment deleted, image edited, sizes regenerated).
 */
class EHIO_Cleanup {

	public static function init() {
		add_filter( 'wp_delete_file', array( __CLASS__, 'on_delete_file' ) );
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
