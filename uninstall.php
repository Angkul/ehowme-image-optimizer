<?php
/**
 * Removes settings, queue meta and the .htaccess blocks.
 * Converted files in wp-content/uploads-optimized are left in place; delete that folder by hand if you no longer need it.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-ehio-settings.php';
require_once __DIR__ . '/includes/class-ehio-paths.php';
require_once __DIR__ . '/includes/class-ehio-htaccess.php';
require_once __DIR__ . '/includes/class-ehio-files.php';

EHIO_Htaccess::remove();
EHIO_Files::uninstall();

delete_option( 'ehio_settings' );
delete_option( 'ehio_last_seen' );
delete_option( 'ehio_connection' );
delete_option( 'ehio_rules_version' );
delete_option( 'ehio_import' );
delete_transient( 'ehio_import_found' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_ehio\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
