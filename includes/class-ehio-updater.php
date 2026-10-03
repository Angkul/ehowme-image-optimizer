<?php
defined( 'ABSPATH' ) || exit;

/**
 * Updates from GitHub Releases (Plugin Update Checker by Yahnis Elsts, lib/).
 *
 * Every tag vX.Y.Z pushed to the repository builds ehowme-image-optimizer.zip
 * (.github/workflows/release.yml) and attaches it to the release; WordPress then
 * offers the update on Dashboard > Updates and Plugins like any other plugin.
 * Only that zip is installed, never GitHub's source archive.
 */
class EHIO_Updater {

	const REPOSITORY = 'https://github.com/Angkul/ehowme-image-optimizer/';
	const SLUG       = 'ehowme-image-optimizer';

	public static function init() {
		$library = EHIO_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $library ) ) {
			return;
		}
		require_once $library;

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( self::REPOSITORY, EHIO_FILE, self::SLUG );
		$checker->getVcsApi()->enableReleaseAssets(
			'/^ehowme-image-optimizer\.zip$/i',
			\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
		);

		add_filter( 'puc_request_info_result-' . self::SLUG, array( __CLASS__, 'info' ) );
		// Keep the Plugins row to "Version | By eHowMe | Bulk optimization".
		// Dashboard > Updates still checks GitHub right away.
		add_filter( 'puc_view_details_link-' . self::SLUG, '__return_empty_string' );
		add_filter( 'puc_manual_check_link-' . self::SLUG, '__return_empty_string' );
	}

	/** Icon on the update screens, and no "not tested with your WordPress version" warning. */
	public static function info( $info ) {
		if ( $info ) {
			$base        = plugins_url( 'assets/', EHIO_FILE );
			$info->icons = array(
				'1x' => $base . 'icon-128.png?v=' . EHIO_VERSION,
				'2x' => $base . 'icon-256x256.png?v=' . EHIO_VERSION,
			);
			$info->tested = get_bloginfo( 'version' );
		}
		return $info;
	}
}
