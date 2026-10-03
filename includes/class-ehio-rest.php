<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API used by the worker. Every route requires a valid HMAC signature.
 *
 * GET  /ehio/v1/queue?limit=5     claim jobs, returns files to convert + settings
 * POST /ehio/v1/result            upload one converted file (fields id, rel, format + file)
 * POST /ehio/v1/complete          finish a job (fields id, status=done|failed, message, saved)
 * POST /ehio/v1/results           1.0.4+: several copies in one request (manifest + f0..fN),
 *                                 optionally finishing the job too. Cuts PHP requests per image
 *                                 from ~13 to 1, which keeps shared hosting within its limits.
 * GET  /ehio/v1/status            queue counts, for the worker dashboard
 */
class EHIO_Rest {

	const NS        = 'ehio/v1';
	const MAX_BYTES = 52428800; // 50 MB per converted file.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'no_cache_early' ), 10, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_cache_headers' ), 10, 3 );
	}

	private static function is_ours( WP_REST_Request $request ) {
		return strpos( $request->get_route(), '/' . self::NS . '/' ) === 0;
	}

	/**
	 * Page caches must never store these answers: a cached "queue empty" reply would
	 * stop the worker for good, and every reply belongs to one signed request.
	 */
	public static function no_cache_early( $result, $server, $request ) {
		if ( self::is_ours( $request ) ) {
			do_action( 'litespeed_control_set_nocache', 'eHowMe Image Optimizer API' ); // LiteSpeed Cache.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // WP Rocket, W3TC, WP Super Cache and others.
			}
		}
		return $result;
	}

	public static function no_cache_headers( $response, $server, $request ) {
		if ( self::is_ours( $request ) && $response instanceof WP_HTTP_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
			$response->header( 'CDN-Cache-Control', 'no-store' );
		}
		return $response;
	}

	public static function register() {
		$auth = array( 'EHIO_Auth', 'verify' );

		register_rest_route(
			self::NS,
			'/queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'queue' ),
				'permission_callback' => $auth,
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 5,
						'minimum' => 1,
						'maximum' => 20,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/result',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'result' ),
				'permission_callback' => $auth,
				'args'                => array(
					'id'     => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^f?[0-9]+$',
					),
					'rel'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'format' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => EHIO_Settings::FORMATS,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/results',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'results' ),
				'permission_callback' => $auth,
				'args'                => array(
					'id'       => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^f?[0-9]+$',
					),
					'manifest' => array(
						'type'     => 'string',
						'required' => true,
					),
					'status'   => array(
						'type' => 'string',
						'enum' => array( 'done', 'failed' ),
					),
					'message'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'saved'    => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'complete' ),
				'permission_callback' => $auth,
				'args'                => array(
					'id'      => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^f?[0-9]+$',
					),
					'status'  => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'done', 'failed' ),
					),
					'message' => array(
						'type'    => 'string',
						'default' => '',
					),
					'saved'   => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => $auth,
			)
		);
	}

	/** Workers before 1.0.0 parse job ids as integers, so they only get Media Library jobs. */
	private static function worker_handles_files( WP_REST_Request $request ) {
		if ( ! preg_match( '#ehio-worker/(\d+\.\d+\.\d+)#', (string) $request->get_header( 'user_agent' ), $m ) ) {
			return false;
		}
		return version_compare( $m[1], '1.0.0', '>=' );
	}

	public static function queue( WP_REST_Request $request ) {
		$settings = EHIO_Settings::get();
		$limit    = (int) $request['limit'];
		$items    = array();
		foreach ( EHIO_Queue::claim( $limit ) as $id ) {
			$items[] = array(
				'id'    => (string) $id,
				'files' => array_values( EHIO_Paths::attachment_files( $id ) ),
			);
		}
		if ( count( $items ) < $limit && self::worker_handles_files( $request ) ) {
			$items = array_merge( $items, EHIO_Files::claim( $limit - count( $items ) ) );
		}
		return rest_ensure_response(
			array(
				'site_id'  => $settings['site_id'],
				'formats'  => $settings['formats'],
				'quality'  => array(
					'webp' => EHIO_Settings::quality( 'webp' ),
					'avif' => EHIO_Settings::quality( 'avif' ),
				),
				'max_size' => array(
					'width'  => (int) $settings['max_width'],
					'height' => (int) $settings['max_height'],
				),
				'lease'    => EHIO_Queue::LEASE_SECONDS,
				// Lets 1.0.2+ workers send all copies of an image in one request.
				'api'      => 2,
				'limits'   => array(
					'max_files' => max( 1, (int) ini_get( 'max_file_uploads' ) ?: 20 ),
					'max_bytes' => (int) wp_max_upload_size(),
				),
				'items'    => $items,
			)
		);
	}

	public static function result( WP_REST_Request $request ) {
		$rel    = (string) $request['rel'];
		$format = (string) $request['format'];
		$target = self::target( (string) $request['id'], $rel, $format );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$upload = $request->get_file_params()['file'] ?? null;
		if ( ! $upload || ! empty( $upload['error'] ) || empty( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) ) {
			return new WP_Error( 'ehio_no_file', 'No uploaded file.', array( 'status' => 400 ) );
		}
		$stored = self::store( $upload['tmp_name'], $target['dest'], $target['bytes'], $format );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		return new WP_REST_Response(
			array(
				'saved' => $rel . '.' . $format,
				'bytes' => $stored,
			),
			201
		);
	}

	/**
	 * Several copies of one job in a single request. The signed "manifest" lists
	 * rel, format and sha256 for files f0..fN, so every file is covered by the HMAC.
	 */
	public static function results( WP_REST_Request $request ) {
		$id       = (string) $request['id'];
		$manifest = json_decode( (string) $request['manifest'], true );
		if ( ! is_array( $manifest ) || count( $manifest ) > 200 ) {
			return new WP_Error( 'ehio_bad_manifest', 'Manifest is not a list.', array( 'status' => 400 ) );
		}
		$uploads = $request->get_file_params();
		$out     = array();
		foreach ( array_values( $manifest ) as $i => $entry ) {
			$rel    = isset( $entry['rel'] ) ? (string) $entry['rel'] : '';
			$format = isset( $entry['format'] ) ? (string) $entry['format'] : '';
			$upload = $uploads[ 'f' . $i ] ?? null;
			$result = array(
				'rel'    => $rel,
				'format' => $format,
			);
			if ( ! in_array( $format, EHIO_Settings::FORMATS, true ) ) {
				$result['state'] = 'bad_format';
			} elseif ( ! $upload || ! empty( $upload['error'] ) || empty( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) ) {
				$result['state'] = 'missing';
			} elseif ( ! hash_equals( strtolower( (string) ( $entry['sha256'] ?? '' ) ), hash_file( 'sha256', $upload['tmp_name'] ) ) ) {
				$result['state'] = 'bad_hash';
			} else {
				$target = self::target( $id, $rel, $format );
				$stored = is_wp_error( $target ) ? $target : self::store( $upload['tmp_name'], $target['dest'], $target['bytes'], $format );
				if ( is_wp_error( $stored ) ) {
					$result['state'] = $stored->get_error_code() === 'ehio_not_smaller' || $stored->get_error_code() === 'ehio_format_not_wanted' ? 'not_smaller' : $stored->get_error_code();
					if ( $stored->get_error_code() === 'ehio_excluded' || $stored->get_error_code() === 'ehio_not_found' ) {
						return $stored; // The whole job is gone; nothing else to store.
					}
				} else {
					$result['state'] = 'saved';
					$result['bytes'] = $stored;
				}
			}
			$out[] = $result;
		}
		$response = array(
			'id'      => $id,
			'results' => $out,
		);
		if ( $request['status'] ) {
			$response['state'] = self::finish_job( $id, (string) $request['status'], (string) $request['message'], (int) $request['saved'] );
		}
		return rest_ensure_response( $response );
	}

	/** Destination and source size for one copy of a job ("123" or "f45"), or WP_Error. */
	private static function target( $id, $rel, $format ) {
		if ( strpos( $id, 'f' ) === 0 ) {
			return EHIO_Files::result_target( (int) substr( $id, 1 ), $rel, $format );
		}
		$id = (int) $id;
		if ( get_post_type( $id ) !== 'attachment' ) {
			return new WP_Error( 'ehio_not_found', 'Attachment not found.', array( 'status' => 404 ) );
		}
		if ( EHIO_Queue::is_excluded( $id ) ) {
			return new WP_Error( 'ehio_excluded', 'This image is excluded from optimization.', array( 'status' => 409 ) );
		}
		$files = EHIO_Paths::attachment_files( $id );
		if ( ! isset( $files[ $rel ] ) ) {
			return new WP_Error( 'ehio_bad_rel', 'File does not belong to this attachment.', array( 'status' => 400 ) );
		}
		if ( ! in_array( $format, $files[ $rel ]['formats'], true ) ) {
			// 422 like "not smaller": older workers treat it as "keep the original".
			return new WP_Error( 'ehio_format_not_wanted', 'This format is not produced for this file.', array( 'status' => 422 ) );
		}
		return array(
			'dest'  => EHIO_Paths::output_path( $rel, $format ),
			'bytes' => $files[ $rel ]['bytes'],
		);
	}

	/** Validates an uploaded copy and moves it into the optimized folder. Returns its size or WP_Error. */
	private static function store( $tmp_name, $dest, $source_bytes, $format ) {
		$size = (int) filesize( $tmp_name );
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return new WP_Error( 'ehio_bad_size', 'Uploaded file is empty or too large.', array( 'status' => 413 ) );
		}
		if ( $size >= $source_bytes ) {
			return new WP_Error( 'ehio_not_smaller', 'Converted file is not smaller than the original.', array( 'status' => 422 ) );
		}
		if ( self::detect_format( $tmp_name ) !== $format ) {
			return new WP_Error( 'ehio_bad_type', 'File content does not match the declared format.', array( 'status' => 415 ) );
		}
		if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
			return new WP_Error( 'ehio_mkdir', 'Output folder is not writable.', array( 'status' => 500 ) );
		}
		$root = wp_normalize_path( (string) realpath( EHIO_Paths::optimized_root_dir() ) );
		$dir  = wp_normalize_path( (string) realpath( dirname( $dest ) ) );
		if ( $root === '' || strpos( $dir . '/', $root . '/' ) !== 0 ) {
			return new WP_Error( 'ehio_bad_path', 'Output path escapes the optimized folder.', array( 'status' => 400 ) );
		}
		$tmp = $dest . '.part';
		if ( ! move_uploaded_file( $tmp_name, $tmp ) || ! rename( $tmp, $dest ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'ehio_write', 'Could not write the output file.', array( 'status' => 500 ) );
		}
		@chmod( $dest, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $size;
	}

	/** Shared by /complete and /results. */
	private static function finish_job( $id, $status, $message, $saved ) {
		$message = sanitize_text_field( substr( $message, 0, 300 ) );
		if ( strpos( $id, 'f' ) === 0 ) {
			return EHIO_Files::complete( (int) substr( $id, 1 ), $status, $message );
		}
		if ( get_post_type( (int) $id ) !== 'attachment' ) {
			return 'gone';
		}
		return EHIO_Queue::complete( (int) $id, $status, $message, $saved );
	}

	public static function complete( WP_REST_Request $request ) {
		$id = (string) $request['id'];
		if ( strpos( $id, 'f' ) !== 0 && get_post_type( (int) $id ) !== 'attachment' ) {
			return new WP_Error( 'ehio_not_found', 'Attachment not found.', array( 'status' => 404 ) );
		}
		$state = self::finish_job( $id, (string) $request['status'], (string) $request['message'], (int) $request['saved'] );
		return rest_ensure_response( array( 'id' => strpos( $id, 'f' ) === 0 ? $id : (int) $id, 'state' => $state ) );
	}

	public static function status() {
		return rest_ensure_response(
			array(
				'site_id' => EHIO_Settings::get()['site_id'],
				'version' => EHIO_VERSION,
				'counts'  => self::combined_counts(),
			)
		);
	}

	/** Media Library and folder jobs together, for the worker dashboard. */
	public static function combined_counts() {
		$counts = EHIO_Queue::stats();
		$files  = EHIO_Files::stats();
		foreach ( array( 'pending', 'processing', 'done', 'failed', 'total' ) as $key ) {
			$counts[ $key ] += $files[ $key ];
		}
		return $counts;
	}

	/** Identifies WebP/AVIF by magic bytes instead of trusting the client. */
	public static function detect_format( $path ) {
		$head = (string) file_get_contents( $path, false, null, 0, 16 );
		if ( strlen( $head ) >= 12 && substr( $head, 0, 4 ) === 'RIFF' && substr( $head, 8, 4 ) === 'WEBP' ) {
			return 'webp';
		}
		if ( strlen( $head ) >= 12 && substr( $head, 4, 4 ) === 'ftyp' && in_array( substr( $head, 8, 4 ), array( 'avif', 'avis' ), true ) ) {
			return 'avif';
		}
		return '';
	}
}
