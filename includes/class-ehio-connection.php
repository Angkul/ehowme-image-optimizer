<?php
defined( 'ABSPATH' ) || exit;

/**
 * Asks the worker whether this site's token is valid (POST /api/connect, signed).
 * The worker answers for the token: active, disabled (cut off in the dashboard),
 * invalid_token (rotated or deleted), unknown_site, or wrong_site (domain mismatch).
 */
class EHIO_Connection {

	const OPTION      = 'ehio_connection';
	const RECHECK_AGE = 600; // seconds before the admin page re-checks on its own.

	const LABELS = array(
		'active'        => 'Connected',
		'disabled'      => 'Disabled by the worker',
		'invalid_token' => 'Token no longer valid',
		'unknown_site'  => 'Site not registered',
		'wrong_site'    => 'Token belongs to another site',
		'unreachable'   => 'Worker unreachable',
		'not_connected' => 'Not connected',
	);

	public static function get() {
		$state = get_option( self::OPTION, array() );
		if ( ! EHIO_Settings::has_token() ) {
			return array(
				'status'  => 'not_connected',
				'message' => 'Paste the access token from the worker dashboard.',
				'checked' => 0,
			);
		}
		return is_array( $state ) && ! empty( $state['status'] ) ? $state : array(
			'status'  => 'unreachable',
			'message' => 'Not checked yet.',
			'checked' => 0,
		);
	}

	/** Connected, but the worker owner paused this site in the worker dashboard. */
	public static function is_paused() {
		$state = self::get();
		return $state['status'] === 'active' && ! empty( $state['paused'] );
	}

	public static function is_stale() {
		$state = self::get();
		return EHIO_Settings::has_token() && ( time() - (int) $state['checked'] ) > self::RECHECK_AGE;
	}

	/** Signed POST /api/connect to a worker address. */
	private static function request( $worker_url ) {
		$settings = EHIO_Settings::get();
		$params   = array(
			'site'    => $settings['site_id'],
			'url'     => home_url(),
			'version' => EHIO_VERSION,
		);
		return wp_remote_post(
			$worker_url . '/api/connect',
			array(
				'timeout' => 10,
				'headers' => EHIO_Auth::client_headers( $settings['secret'], 'POST', '/api/connect', $params ),
				'body'    => $params,
			)
		);
	}

	/**
	 * The worker moved to a new address (e.g. img.angkul.com -> img.ehowme.com) and says so.
	 * Switch only when the new address answers a signed check with "active": that proves it
	 * is the same worker holding this site's secret.
	 */
	private static function follow( $worker_url ) {
		$new     = untrailingslashit( esc_url_raw( (string) $worker_url, array( 'https' ) ) );
		$current = EHIO_Settings::get()['worker_url'];
		if ( $new === '' || $new === $current || wp_parse_url( $new, PHP_URL_PATH ) ) {
			return;
		}
		$response = self::request( $new );
		$body     = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) === 200 && is_array( $body ) && ( $body['status'] ?? '' ) === 'active' ) {
			EHIO_Settings::move_worker( $new );
		}
	}

	public static function check() {
		$settings = EHIO_Settings::get();
		if ( $settings['secret'] === '' ) {
			return self::get();
		}

		$response = self::request( $settings['worker_url'] );

		if ( is_wp_error( $response ) ) {
			$state = array(
				'status'  => 'unreachable',
				// Keep the worker address out of the admin screen.
				'message' => 'The worker could not be reached: ' . str_ireplace( (string) wp_parse_url( $settings['worker_url'], PHP_URL_HOST ), 'worker', $response->get_error_message() ),
			);
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$info = is_array( $body ) ? ( $body['detail'] ?? $body ) : array();
			if ( $code === 200 && ( $info['status'] ?? '' ) === 'active' ) {
				$state = array(
					'status'  => 'active',
					'message' => 'Images are converted by your eHowMe worker.',
					// Paused in the worker dashboard: still connected, but no new jobs are fetched.
					'paused'  => ! empty( $info['paused'] ),
				);
				if ( ! empty( $info['worker_url'] ) ) {
					self::follow( $info['worker_url'] );
				}
			} elseif ( is_array( $info ) && isset( $info['status'], self::LABELS[ $info['status'] ] ) ) {
				$messages = array(
					'disabled'      => 'This site was disabled in the worker dashboard. New images are not converted; already converted images are still served.',
					'invalid_token' => 'The token was replaced or removed in the worker dashboard. Paste the new token.',
					'unknown_site'  => 'The worker does not know this site. Ask for a new token.',
					'wrong_site'    => 'This token was issued for ' . ( $info['expected'] ?? 'another site' ) . ', not ' . home_url() . '.',
				);
				$state    = array(
					'status'  => $info['status'],
					'message' => $messages[ $info['status'] ] ?? '',
				);
			} else {
				$state = array(
					'status'  => 'unreachable',
					'message' => sprintf( 'Unexpected answer from the worker (HTTP %d).', $code ),
				);
			}
		}
		$state['checked'] = time();
		update_option( self::OPTION, $state, false );
		return $state;
	}
}
