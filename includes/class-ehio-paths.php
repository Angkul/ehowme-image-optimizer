<?php
defined( 'ABSPATH' ) || exit;

/**
 * Source and output locations.
 *
 * Converted files mirror the uploads tree in a sibling folder:
 *   wp-content/uploads/2026/06/photo.jpg
 *   wp-content/uploads-optimized/2026/06/photo.jpg.avif
 *   wp-content/uploads-optimized/2026/06/photo.jpg.webp
 *   wp-content/uploads-optimized/2026/06/banner.webp.avif   (WebP sources get AVIF only)
 *
 * On multisite the per-site offset (/sites/2) is kept, so one rewrite
 * block in the uploads root serves every site.
 */
class EHIO_Paths {

	const SOURCE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'webp' );
	const SOURCE_MIMES      = array( 'image/jpeg', 'image/png', 'image/webp' );

	private static function upload_dir() {
		return wp_upload_dir( null, false );
	}

	/** Absolute uploads basedir of the current site, normalized, no trailing slash. */
	public static function basedir() {
		return wp_normalize_path( untrailingslashit( self::upload_dir()['basedir'] ) );
	}

	/** Uploads root shared by all sites (wp-content/uploads), or the basedir for custom layouts. */
	public static function root_dir() {
		$base = self::basedir();
		$root = wp_normalize_path( WP_CONTENT_DIR . '/uploads' );
		return ( strpos( $base . '/', $root . '/' ) === 0 ) ? $root : $base;
	}

	public static function optimized_root_dir() {
		return self::root_dir() . '-optimized';
	}

	/** '' for single site, '/sites/2' for a multisite subsite. */
	public static function site_offset() {
		return (string) substr( self::basedir(), strlen( self::root_dir() ) );
	}

	public static function root_url() {
		$base   = untrailingslashit( self::upload_dir()['baseurl'] );
		$offset = self::site_offset();
		if ( $offset !== '' && substr( $base, -strlen( $offset ) ) === $offset ) {
			return substr( $base, 0, -strlen( $offset ) );
		}
		return $base;
	}

	public static function optimized_root_url() {
		return self::root_url() . '-optimized';
	}

	/** Output file for an uploads-relative path, e.g. 2026/06/photo.jpg + avif. */
	public static function output_path( $rel, $format ) {
		return self::optimized_root_dir() . self::site_offset() . '/' . $rel . '.' . $format;
	}

	/** Deletes every converted copy of an attachment, e.g. when it is excluded. */
	public static function delete_outputs( $attachment_id ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $meta['file'] ) || ! is_string( $meta['file'] ) ) {
			return;
		}
		$dir   = dirname( $meta['file'] );
		$dir   = ( $dir === '.' || $dir === '' ) ? '' : $dir . '/';
		$names = array( wp_basename( $meta['file'] ) );
		if ( ! empty( $meta['original_image'] ) ) {
			$names[] = wp_basename( $meta['original_image'] );
		}
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$names[] = wp_basename( $size['file'] );
			}
		}
		foreach ( array_unique( $names ) as $name ) {
			if ( strpos( $dir . $name, '..' ) !== false ) {
				continue;
			}
			foreach ( EHIO_Settings::FORMATS as $format ) {
				$path = self::output_path( $dir . $name, $format );
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}
	}

	public static function is_source_extension( $path ) {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), self::SOURCE_EXTENSIONS, true );
	}

	/**
	 * Formats to produce for one source file: JPEG/PNG get every enabled format,
	 * a WebP source only gets AVIF (a WebP copy of a WebP gains nothing).
	 *
	 * @return string[]
	 */
	public static function target_formats( $rel ) {
		$enabled = EHIO_Settings::get()['formats'];
		if ( strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) ) === 'webp' ) {
			return array_values( array_intersect( $enabled, array( 'avif' ) ) );
		}
		return $enabled;
	}

	/**
	 * Every JPEG/PNG/WebP file that belongs to an attachment (full, original_image, sizes),
	 * keyed by uploads-relative path. Only files that exist on disk are returned.
	 *
	 * @return array<string, array{rel:string,url:string,bytes:int,formats:string[]}>
	 */
	public static function attachment_files( $attachment_id ) {
		if ( ! in_array( get_post_mime_type( $attachment_id ), self::SOURCE_MIMES, true ) ) {
			return array();
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $meta['file'] ) || ! is_string( $meta['file'] ) ) {
			return array();
		}

		$dir   = dirname( $meta['file'] );
		$dir   = ( $dir === '.' || $dir === '' ) ? '' : $dir . '/';
		$names = array( wp_basename( $meta['file'] ) );
		if ( ! empty( $meta['original_image'] ) ) {
			$names[] = wp_basename( $meta['original_image'] );
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$names[] = wp_basename( $size['file'] );
				}
			}
		}

		$baseurl = untrailingslashit( self::upload_dir()['baseurl'] );
		$files   = array();
		foreach ( array_unique( $names ) as $name ) {
			$rel = $dir . $name;
			if ( strpos( $rel, '..' ) !== false || ! self::is_source_extension( $rel ) ) {
				continue;
			}
			$abs = self::basedir() . '/' . $rel;
			if ( ! is_file( $abs ) ) {
				continue;
			}
			$formats = self::target_formats( $rel );
			if ( ! $formats ) {
				continue;
			}
			$files[ $rel ] = array(
				'rel'     => $rel,
				'url'     => $baseurl . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $rel ) ) ),
				'bytes'   => (int) filesize( $abs ),
				'formats' => $formats,
			);
		}
		return $files;
	}
}
