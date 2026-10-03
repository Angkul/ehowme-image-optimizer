<?php
/**
 * Plugin Name:       eHowMe Image Optimizer
 * Description:       Serves AVIF and WebP versions of your images to browsers that support them, with the original as fallback, so pages load faster without changing any URLs. Images are converted on your own eHowMe worker server, not on this hosting account. Covers the Media Library, Elementor thumbnails, theme and plugin images, with bulk optimization, per-image control and Cloudflare-friendly delivery.
 * Version:           1.0.6
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            eHowMe
 * Author URI:        https://www.ehowme.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       ehowme-image-optimizer
 */

defined( 'ABSPATH' ) || exit;

define( 'EHIO_VERSION', '1.0.6' );
define( 'EHIO_FILE', __FILE__ );
define( 'EHIO_DIR', plugin_dir_path( __FILE__ ) );

require_once EHIO_DIR . 'includes/class-ehio-settings.php';
require_once EHIO_DIR . 'includes/class-ehio-paths.php';
require_once EHIO_DIR . 'includes/class-ehio-queue.php';
require_once EHIO_DIR . 'includes/class-ehio-files.php';
require_once EHIO_DIR . 'includes/class-ehio-import.php';
require_once EHIO_DIR . 'includes/class-ehio-auth.php';
require_once EHIO_DIR . 'includes/class-ehio-rest.php';
require_once EHIO_DIR . 'includes/class-ehio-htaccess.php';
require_once EHIO_DIR . 'includes/class-ehio-notifier.php';
require_once EHIO_DIR . 'includes/class-ehio-connection.php';
require_once EHIO_DIR . 'includes/class-ehio-cleanup.php';
require_once EHIO_DIR . 'includes/class-ehio-admin.php';
require_once EHIO_DIR . 'includes/class-ehio-media.php';
require_once EHIO_DIR . 'includes/class-ehio-updater.php';

EHIO_Updater::init();

register_activation_hook( __FILE__, 'ehio_activate' );
register_deactivation_hook( __FILE__, 'ehio_deactivate' );

function ehio_activate() {
	EHIO_Files::install();
	wp_mkdir_p( EHIO_Paths::optimized_root_dir() );
	EHIO_Htaccess::write();
	update_option( 'ehio_rules_version', EHIO_VERSION, false );
}

/** Updating by upload skips the activation hook, so rewrite the delivery rules when the version changes. */
function ehio_maybe_upgrade() {
	if ( get_option( 'ehio_rules_version' ) !== EHIO_VERSION ) {
		EHIO_Files::install();
		if ( EHIO_Htaccess::write() ) {
			update_option( 'ehio_rules_version', EHIO_VERSION, false );
		}
	}
}

function ehio_deactivate() {
	// Without the plugin the site must fall back to serving originals.
	EHIO_Htaccess::remove();
}

EHIO_Queue::init();
EHIO_Rest::init();
EHIO_Cleanup::init();

if ( is_admin() ) {
	EHIO_Admin::init();
	EHIO_Media::init();
	add_action( 'admin_init', 'ehio_maybe_upgrade' );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once EHIO_DIR . 'includes/class-ehio-cli.php';
	WP_CLI::add_command( 'ehio', 'EHIO_CLI' );
}
