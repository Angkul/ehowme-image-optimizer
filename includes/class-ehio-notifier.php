<?php
defined( 'ABSPATH' ) || exit;

/**
 * Wake-up call to the worker (through the Cloudflare Tunnel) so new uploads are
 * converted within seconds instead of at the next poll. Carries no image data; the
 * worker still pulls the job with a signed request. Skipped when the site has no token.
 */
class EHIO_Notifier {

	public static function ping() {
		$settings = EHIO_Settings::get();
		if ( $settings['worker_url'] === '' || $settings['secret'] === '' ) {
			return;
		}
		$params = array( 'site' => $settings['site_id'] );
		wp_remote_post(
			$settings['worker_url'] . '/ping',
			array(
				'blocking' => false,
				'timeout'  => 2,
				'headers'  => EHIO_Auth::client_headers( $settings['secret'], 'POST', '/ping', $params ),
				'body'     => $params,
			)
		);
	}
}
