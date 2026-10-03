<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings stored in one option.
 *
 * Connection details (secret, worker URL, site ID) come from the Access Token issued by
 * the worker dashboard. Without a token the plugin's API refuses every request, so the
 * site cannot use the service.
 */
class EHIO_Settings {

	const OPTION    = 'ehio_settings';
	const LAST_SEEN = 'ehio_last_seen';
	const FORMATS   = array( 'avif', 'webp' ); // Delivery preference order.
	const MIME      = array(
		'avif' => 'image/avif',
		'webp' => 'image/webp',
	);

	/** Quality per format for each conversion strategy ("custom" uses quality_avif / quality_webp). */
	const STRATEGIES = array(
		'smallest' => array(
			'avif' => 40,
			'webp' => 70,
		),
		'balanced' => array(
			'avif' => 50,
			'webp' => 80,
		),
		'quality'  => array(
			'avif' => 65,
			'webp' => 88,
		),
	);

	/** Folders under wp-content that can be converted besides the Media Library. */
	const EXTRA_DIRS = array( 'uploads', 'themes', 'plugins', 'gallery', 'cache' );

	public static function defaults() {
		return array(
			'token'        => '',
			'secret'       => '',
			'worker_url'   => '',
			'site_id'      => '',
			'formats'      => array( 'avif', 'webp' ),
			'strategy'     => 'balanced',
			'quality_webp' => 80,
			'quality_avif' => 50,
			'max_width'    => 0,
			'max_height'   => 0,
			'auto_convert' => true,
			// Extra folders scanned by Bulk optimization ('uploads' = files in uploads
			// that are not Media Library items, e.g. Elementor thumbnails).
			'dirs'         => array(),
			// Off: images are sent with Cache-Control: private so CDNs that ignore
			// Vary: Accept (Cloudflare Free) never cache one format for everyone.
			'cdn_vary'     => false,
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$settings            = array_merge( self::defaults(), $saved );
		$settings['formats'] = self::clean_formats( $settings['formats'] );
		return $settings;
	}

	public static function update( array $changes ) {
		update_option( self::OPTION, array_merge( self::get(), $changes ), false );
	}

	/** Quality the worker should use for a format under the current strategy. */
	public static function quality( $format ) {
		$s = self::get();
		if ( isset( self::STRATEGIES[ $s['strategy'] ][ $format ] ) ) {
			return self::STRATEGIES[ $s['strategy'] ][ $format ];
		}
		return (int) $s[ 'quality_' . $format ];
	}

	public static function has_token() {
		$s = self::get();
		return $s['token'] !== '' && $s['secret'] !== '';
	}

	/**
	 * Decodes "ehio_<base64url(JSON {v,w,s,k})>".
	 *
	 * @return array{worker_url:string,site_id:string,secret:string}|WP_Error
	 */
	public static function parse_token( $token ) {
		$token = trim( (string) $token );
		if ( strpos( $token, 'ehio_' ) !== 0 ) {
			return new WP_Error( 'ehio_token_format', 'This is not an Image Optimizer access token (it should start with ehio_).' );
		}
		$body = strtr( substr( $token, 5 ), '-_', '+/' );
		$json = base64_decode( $body . str_repeat( '=', ( 4 - strlen( $body ) % 4 ) % 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || empty( $data['w'] ) || empty( $data['s'] ) || empty( $data['k'] ) ) {
			return new WP_Error( 'ehio_token_broken', 'The token is incomplete. Copy it again from the worker dashboard.' );
		}
		$worker_url = untrailingslashit( esc_url_raw( (string) $data['w'], array( 'http', 'https' ) ) );
		if ( $worker_url === '' || ! preg_match( '/^[a-f0-9]{64}$/', (string) $data['k'] ) ) {
			return new WP_Error( 'ehio_token_broken', 'The token is damaged. Copy it again from the worker dashboard.' );
		}
		return array(
			'worker_url' => $worker_url,
			'site_id'    => substr( preg_replace( '/[^a-z0-9.\-]/', '', strtolower( (string) $data['s'] ) ), 0, 100 ),
			'secret'     => (string) $data['k'],
		);
	}

	/** Stores a token. Returns WP_Error if it cannot be decoded. */
	public static function set_token( $token ) {
		$parsed = self::parse_token( $token );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		self::update( array_merge( $parsed, array( 'token' => trim( (string) $token ) ) ) );
		delete_option( EHIO_Connection::OPTION );
		return true;
	}

	/**
	 * The stored token with only its first and last 3 characters visible,
	 * e.g. "ehio_eyJ••••••••In0". Empty when no token is stored.
	 */
	public static function masked_token() {
		$token = self::get()['token'];
		if ( $token === '' ) {
			return '';
		}
		$body = substr( $token, 5 ); // After "ehio_".
		if ( strlen( $body ) <= 6 ) {
			return 'ehio_' . str_repeat( '•', 8 );
		}
		return 'ehio_' . substr( $body, 0, 3 ) . str_repeat( '•', 8 ) . substr( $body, -3 );
	}

	/** New worker address, same site and secret: the stored token is rebuilt to match. */
	public static function move_worker( $worker_url ) {
		$s       = self::get();
		$payload = wp_json_encode(
			array(
				'v' => 1,
				'w' => $worker_url,
				's' => $s['site_id'],
				'k' => $s['secret'],
			),
			JSON_UNESCAPED_SLASHES
		);
		self::update(
			array(
				'worker_url' => $worker_url,
				'token'      => 'ehio_' . rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			)
		);
	}

	public static function clear_token() {
		self::update(
			array(
				'token'      => '',
				'secret'     => '',
				'worker_url' => '',
				'site_id'    => '',
			)
		);
		delete_option( EHIO_Connection::OPTION );
	}

	/** Keeps only known formats, in delivery preference order. */
	public static function clean_formats( $formats ) {
		$formats = is_array( $formats ) ? $formats : array();
		return array_values( array_intersect( self::FORMATS, $formats ) );
	}

	/** Validates the settings form. */
	public static function sanitize_form( array $input ) {
		$strategy = isset( $input['strategy'] ) ? sanitize_key( $input['strategy'] ) : 'balanced';
		if ( $strategy !== 'custom' && ! isset( self::STRATEGIES[ $strategy ] ) ) {
			$strategy = 'balanced';
		}
		$dirs = array_values( array_intersect( self::EXTRA_DIRS, isset( $input['dirs'] ) ? (array) $input['dirs'] : array() ) );
		return array(
			'formats'      => self::clean_formats( isset( $input['formats'] ) ? (array) $input['formats'] : array() ),
			'strategy'     => $strategy,
			'quality_webp' => min( 100, max( 1, (int) ( $input['quality_webp'] ?? 80 ) ) ),
			'quality_avif' => min( 100, max( 1, (int) ( $input['quality_avif'] ?? 50 ) ) ),
			'max_width'    => min( 20000, max( 0, (int) ( $input['max_width'] ?? 0 ) ) ),
			'max_height'   => min( 20000, max( 0, (int) ( $input['max_height'] ?? 0 ) ) ),
			'auto_convert' => ! empty( $input['auto_convert'] ),
			'dirs'         => $dirs,
		);
	}

	/** Validates the Advanced Settings tab. */
	public static function sanitize_advanced( array $input ) {
		return array(
			'cdn_vary' => ! empty( $input['cdn_vary'] ),
		);
	}
}
