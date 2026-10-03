<?php
defined( 'ABSPATH' ) || exit;

/**
 * HMAC-SHA256 request signing shared with the worker.
 *
 * String to sign (newline separated):
 *   METHOD
 *   route              e.g. /ehio/v1/queue
 *   timestamp          unix seconds, must be within WINDOW of server time
 *   params             k=v pairs sorted by key, joined with &, raw (not URL-encoded)
 *   body sha256 hex    of the uploaded file, or of an empty string
 *
 * Headers: X-EHIO-Timestamp, X-EHIO-Signature (lowercase hex).
 * The worker's Authorization header stays free for HTTP basic auth on staging sites.
 */
class EHIO_Auth {

	const WINDOW = 300;

	public static function canonical( $method, $route, $timestamp, array $params, $body_sha ) {
		unset( $params['rest_route'] ); // Present when pretty permalinks are off.
		ksort( $params, SORT_STRING );
		$pairs = array();
		foreach ( $params as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$pairs[] = $key . '=' . $value;
			}
		}
		return strtoupper( $method ) . "\n" . $route . "\n" . $timestamp . "\n" . implode( '&', $pairs ) . "\n" . $body_sha;
	}

	public static function sign( $secret, $string_to_sign ) {
		return hash_hmac( 'sha256', $string_to_sign, $secret );
	}

	/** Headers for a request from this site to the worker (form body, no file). */
	public static function client_headers( $secret, $method, $route, array $params ) {
		$timestamp = (string) time();
		return array(
			'X-EHIO-Timestamp' => $timestamp,
			'X-EHIO-Signature' => self::sign( $secret, self::canonical( $method, $route, $timestamp, $params, hash( 'sha256', '' ) ) ),
		);
	}

	/** REST permission callback. */
	public static function verify( WP_REST_Request $request ) {
		$secret = EHIO_Settings::get()['secret'];
		if ( ! EHIO_Settings::has_token() ) {
			return new WP_Error( 'ehio_not_connected', 'This site has no access token.', array( 'status' => 503 ) );
		}

		$timestamp = (string) $request->get_header( 'x_ehio_timestamp' );
		$signature = strtolower( (string) $request->get_header( 'x_ehio_signature' ) );
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::WINDOW ) {
			return new WP_Error( 'ehio_bad_timestamp', 'Missing or stale timestamp (check the worker clock).', array( 'status' => 401 ) );
		}

		$body_sha = hash( 'sha256', '' );
		$files    = $request->get_file_params();
		if ( ! empty( $files['file']['tmp_name'] ) && is_uploaded_file( $files['file']['tmp_name'] ) ) {
			$body_sha = hash_file( 'sha256', $files['file']['tmp_name'] );
		}

		$params   = array_merge( $request->get_query_params(), $request->get_body_params() );
		$expected = self::sign( $secret, self::canonical( $request->get_method(), $request->get_route(), $timestamp, $params, $body_sha ) );

		if ( $signature === '' || ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'ehio_bad_signature', 'Invalid signature.', array( 'status' => 401 ) );
		}

		update_option( EHIO_Settings::LAST_SEEN, time(), false );
		return true;
	}
}
