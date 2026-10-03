<?php
defined( 'ABSPATH' ) || exit;

/**
 * Delivery: the page keeps requesting photo.jpg; the server answers with the
 * AVIF or WebP copy when the browser's Accept header allows it and the copy exists.
 *
 * Apache and LiteSpeed (Hostinger) read the .htaccess rules written here.
 * nginx-only hosts need the snippet from nginx_snippet() in the server config.
 */
class EHIO_Htaccess {

	const MARKER     = 'eHowMe Image Optimizer';
	const OLD_MARKER = 'EHOWME Image Optimizer'; // Before 1.0.0; removed on write.

	private static function load_wp_helpers() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
	}

	private static function escape_path( $path ) {
		return str_replace( ' ', '\ ', $path );
	}

	/**
	 * Headers for every image the rules may answer for. Unless the CDN keeps one copy
	 * per Accept header, the answer must not be stored by shared caches at all:
	 * browsers still cache it (private), Cloudflare Free / LiteSpeed Cache do not.
	 */
	private static function header_lines() {
		$lines = array( '    Header append Vary Accept' );
		if ( empty( EHIO_Settings::get()['cdn_vary'] ) ) {
			// "set" (not "always set") replaces the Cache-Control that mod_expires or the host adds.
			$lines[] = '    Header set Cache-Control "private, max-age=604800"';
			$lines[] = '    Header set X-LiteSpeed-Cache-Control "no-cache"';
		}
		return $lines;
	}

	/** Rules for wp-content/uploads/.htaccess. */
	public static function upload_rules() {
		$formats = EHIO_Settings::get()['formats'];
		if ( ! $formats ) {
			return array();
		}
		$abs   = self::escape_path( EHIO_Paths::optimized_root_dir() );
		$url   = self::escape_path( (string) wp_parse_url( EHIO_Paths::optimized_root_url(), PHP_URL_PATH ) );
		$lines = array( '<IfModule mod_rewrite.c>', '  RewriteEngine On' );
		foreach ( $formats as $format ) {
			// WebP sources only have an AVIF copy.
			$sources = $format === 'avif' ? 'jpe?g|png|webp' : 'jpe?g|png';
			$mime    = EHIO_Settings::MIME[ $format ];
			$lines[] = '  RewriteCond %{QUERY_STRING} !(^|&)ehio=src(&|$)'; // The worker fetching an original.
			$lines[] = '  RewriteCond %{HTTP_ACCEPT} ' . $mime;
			$lines[] = '  RewriteCond ' . $abs . '/$1.$2.' . $format . ' -f';
			$lines[] = '  RewriteRule ^(.+)\.(' . $sources . ')$ ' . $url . '/$1.$2.' . $format . ' [NC,T=' . $mime . ',L]';
		}
		$lines[] = '</IfModule>';
		$lines[] = '<IfModule mod_headers.c>';
		$lines[] = '  <FilesMatch "\.(?i:jpe?g|png|webp)$">';
		$lines   = array_merge( $lines, self::header_lines() );
		$lines[] = '  </FilesMatch>';
		$lines[] = '</IfModule>';
		return $lines;
	}

	/** Rules for wp-content/uploads-optimized/.htaccess. */
	public static function optimized_rules() {
		return array_merge(
			array(
				'<IfModule mod_mime.c>',
				'  AddType image/avif .avif',
				'  AddType image/webp .webp',
				'</IfModule>',
				'<IfModule mod_headers.c>',
				'  <FilesMatch "\\.(avif|webp)$">',
			),
			self::header_lines(),
			array(
				'  </FilesMatch>',
				'</IfModule>',
				'<IfModule mod_expires.c>',
				'  ExpiresActive On',
				'  ExpiresByType image/avif "access plus 1 year"',
				'  ExpiresByType image/webp "access plus 1 year"',
				'</IfModule>',
			)
		);
	}

	/**
	 * Rules for wp-content/.htaccess: images in themes, plugins... (when those folders
	 * are enabled), served from uploads-optimized/@<folder>/.
	 */
	public static function content_rules() {
		$formats = EHIO_Settings::get()['formats'];
		$roots   = array_values( array_diff( EHIO_Files::enabled_dirs(), array( 'uploads' ) ) );
		if ( ! $formats || ! $roots ) {
			return array();
		}
		$abs   = self::escape_path( EHIO_Paths::optimized_root_dir() );
		$url   = self::escape_path( (string) wp_parse_url( EHIO_Paths::optimized_root_url(), PHP_URL_PATH ) );
		$group = implode( '|', array_map( 'preg_quote', $roots ) );
		$lines = array( '<IfModule mod_rewrite.c>', '  RewriteEngine On' );
		foreach ( $formats as $format ) {
			$sources = $format === 'avif' ? 'jpe?g|png|webp' : 'jpe?g|png';
			$mime    = EHIO_Settings::MIME[ $format ];
			$lines[] = '  RewriteCond %{QUERY_STRING} !(^|&)ehio=src(&|$)';
			$lines[] = '  RewriteCond %{HTTP_ACCEPT} ' . $mime;
			$lines[] = '  RewriteCond ' . $abs . '/@$1/$2.$3.' . $format . ' -f';
			$lines[] = '  RewriteRule ^(' . $group . ')/(.+)\.(' . $sources . ')$ ' . $url . '/@$1/$2.$3.' . $format . ' [NC,T=' . $mime . ',L]';
		}
		$lines[] = '</IfModule>';
		$lines[] = '<IfModule mod_headers.c>';
		$lines[] = '  <FilesMatch "\.(?i:jpe?g|png|webp)$">';
		$lines   = array_merge( $lines, self::header_lines() );
		$lines[] = '  </FilesMatch>';
		$lines[] = '</IfModule>';
		return $lines;
	}

	public static function content_htaccess_file() {
		return wp_normalize_path( WP_CONTENT_DIR ) . '/.htaccess';
	}

	public static function upload_htaccess_file() {
		return EHIO_Paths::root_dir() . '/.htaccess';
	}

	public static function optimized_htaccess_file() {
		return EHIO_Paths::optimized_root_dir() . '/.htaccess';
	}

	/** Writes both rule blocks. Returns false if either file is not writable. */
	public static function write() {
		self::load_wp_helpers();
		$opt_root = EHIO_Paths::optimized_root_dir();
		if ( ! wp_mkdir_p( $opt_root ) ) {
			return false;
		}
		if ( ! file_exists( $opt_root . '/index.php' ) ) {
			file_put_contents( $opt_root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		self::remove_old_marker();
		$ok_upload    = insert_with_markers( self::upload_htaccess_file(), self::MARKER, self::upload_rules() );
		$ok_optimized = insert_with_markers( self::optimized_htaccess_file(), self::MARKER, self::optimized_rules() );
		$content      = self::content_rules();
		$ok_content   = true;
		if ( $content || file_exists( self::content_htaccess_file() ) ) {
			$ok_content = insert_with_markers( self::content_htaccess_file(), self::MARKER, $content );
		}
		return $ok_upload && $ok_optimized && $ok_content;
	}

	private static function remove_old_marker() {
		foreach ( array( self::upload_htaccess_file(), self::optimized_htaccess_file() ) as $file ) {
			if ( is_readable( $file ) && strpos( (string) file_get_contents( $file ), '# BEGIN ' . self::OLD_MARKER ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				insert_with_markers( $file, self::OLD_MARKER, array() );
				// insert_with_markers leaves the empty BEGIN/END pair; drop it.
				$text = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$text = preg_replace( '/\n?# BEGIN ' . preg_quote( self::OLD_MARKER, '/' ) . '.*?# END ' . preg_quote( self::OLD_MARKER, '/' ) . '\n?/s', "\n", $text );
				file_put_contents( $file, ltrim( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	public static function remove() {
		self::load_wp_helpers();
		foreach ( array( self::upload_htaccess_file(), self::optimized_htaccess_file(), self::content_htaccess_file() ) as $file ) {
			if ( file_exists( $file ) ) {
				insert_with_markers( $file, self::MARKER, array() );
			}
		}
		self::remove_old_marker();
	}

	/** Whether the uploads .htaccess currently contains our rewrite block. */
	public static function is_installed() {
		$file = self::upload_htaccess_file();
		if ( ! is_readable( $file ) ) {
			return false;
		}
		$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return strpos( $content, '# BEGIN ' . self::MARKER ) !== false && strpos( $content, 'RewriteRule' ) !== false;
	}

	/** False when folders outside uploads are enabled but wp-content/.htaccess lacks their rules. */
	public static function content_rules_ok() {
		if ( ! self::content_rules() ) {
			return true;
		}
		$file = self::content_htaccess_file();
		return is_readable( $file ) && strpos( (string) file_get_contents( $file ), '/@' ) !== false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public static function looks_like_nginx() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		return strpos( $software, 'nginx' ) !== false;
	}

	public static function nginx_snippet() {
		$uploads   = untrailingslashit( (string) wp_parse_url( EHIO_Paths::root_url(), PHP_URL_PATH ) );
		$optimized = untrailingslashit( (string) wp_parse_url( EHIO_Paths::optimized_root_url(), PHP_URL_PATH ) );
		$formats   = EHIO_Settings::get()['formats'];

		$maps  = '';
		$tries = '';
		foreach ( $formats as $format ) {
			$maps  .= 'map $http_accept $ehio_' . $format . " {\n    default \"\";\n    \"~*" . EHIO_Settings::MIME[ $format ] . '" ".' . $format . "\";\n}\n";
			$tries .= $optimized . '/$ehio_path$ehio_' . $format . ' ';
		}

		// A named capture is required: evaluating a regex map resets $1.
		$out = "# 1) http {} block\n" . $maps
			. "\n# 2) server {} block (before other location blocks for images)\n"
			. 'location ~* ^' . $uploads . "/(?<ehio_path>.+\\.(?:jpe?g|png))$ {\n"
			. "    add_header Vary Accept;\n"
			. '    try_files ' . $tries . "\$uri =404;\n"
			. "}\n";
		if ( in_array( 'avif', $formats, true ) ) {
			// WebP uploads only have an AVIF copy.
			$out .= 'location ~* ^' . $uploads . "/(?<ehio_path>.+\\.webp)$ {\n"
				. "    add_header Vary Accept;\n"
				. '    try_files ' . $optimized . "/\$ehio_path\$ehio_avif \$uri =404;\n"
				. "}\n";
		}
		return $out . "\n# 3) mime.types must contain: image/avif avif;  image/webp webp;\n";
	}
}
