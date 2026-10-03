<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manage the eHowMe Image Optimizer queue.
 */
class EHIO_CLI {

	/**
	 * Show queue counts and when the worker last connected.
	 *
	 * ## EXAMPLES
	 *     wp ehio status
	 */
	public function status() {
		$stats     = EHIO_Queue::stats();
		$last_seen = (int) get_option( EHIO_Settings::LAST_SEEN, 0 );
		$rows      = array();
		foreach ( $stats as $key => $value ) {
			$rows[] = array(
				'status' => $key,
				'count'  => $value,
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'count' ) );
		$conn = EHIO_Connection::get();
		WP_CLI::log( 'Access token: ' . ( EHIO_Settings::has_token() ? EHIO_Settings::masked_token() : 'none' ) );
		WP_CLI::log( 'Connection: ' . EHIO_Connection::LABELS[ $conn['status'] ] . ( $conn['message'] ? ' - ' . $conn['message'] : '' ) );
		WP_CLI::log( 'Worker last contact: ' . ( $last_seen ? gmdate( 'Y-m-d H:i:s', $last_seen ) . ' UTC' : 'never' ) );
		WP_CLI::log( 'Rewrite rules installed: ' . ( EHIO_Htaccess::is_installed() ? 'yes' : 'no' ) );
	}

	/**
	 * Add images to the queue.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs to convert again now (also lifts an exclusion).
	 *
	 * [--all]
	 * : Bulk optimization: queue Media Library images not converted yet and scan the folders enabled in settings. Excluded images are skipped.
	 *
	 * [--force]
	 * : With --all, re-queue the whole library, including images already done
	 * (use after a migration that did not copy wp-content/uploads-optimized).
	 *
	 * ## EXAMPLES
	 *     wp ehio queue --all
	 *     wp ehio queue --all --force
	 *     wp ehio queue 123 456
	 */
	public function queue( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['all'] ) ) {
			$result = EHIO_Queue::bulk_start( ! empty( $assoc_args['force'] ) );
			WP_CLI::success( "{$result['library']} Media Library images and {$result['files']} folder images queued." );
			return;
		} else {
			if ( ! $args ) {
				WP_CLI::error( 'Pass attachment IDs or --all.' );
			}
			$count = 0;
			foreach ( $args as $id ) {
				if ( EHIO_Queue::reoptimize( (int) $id ) ) {
					$count++;
				} else {
					WP_CLI::warning( "Attachment {$id} has no JPEG/PNG/WebP files; skipped." );
				}
			}
		}
		if ( $count ) {
			EHIO_Notifier::ping();
		}
		WP_CLI::success( "{$count} images queued." );
	}

	/**
	 * Move the copies made by Converter for Media (wp-content/uploads-webpc) into this
	 * plugin's folder so they do not have to be converted again. Deactivate Converter
	 * for Media first; delete it afterwards.
	 *
	 * ## EXAMPLES
	 *     wp ehio import
	 */
	public function import() {
		if ( EHIO_Import::source_plugin_active() ) {
			WP_CLI::error( 'Deactivate Converter for Media first (wp plugin deactivate webp-converter-for-media). Do not delete it yet.' );
		}
		do {
			$r = EHIO_Import::run( 60 );
			WP_CLI::log( sprintf( '%s: %d moved, %d skipped, %d images marked optimized, %d queued', $r['phase'], $r['moved'], $r['skipped'], $r['marked'], $r['queued'] ) );
		} while ( ! $r['done'] );
		WP_CLI::success( 'Import finished. You can delete Converter for Media now.' );
	}

	/**
	 * Stop converting images and serve their originals (e.g. a favicon or a logo).
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Attachment IDs.
	 *
	 * ## EXAMPLES
	 *     wp ehio exclude 123 456
	 */
	public function exclude( $args ) {
		foreach ( $args as $id ) {
			EHIO_Queue::exclude( (int) $id );
		}
		WP_CLI::success( count( $args ) . ' images excluded; their converted copies were deleted.' );
	}

	/**
	 * Stop bulk optimization: take waiting images out of the queue.
	 * Images being converted right now finish.
	 *
	 * ## EXAMPLES
	 *     wp ehio stop
	 */
	public function stop() {
		WP_CLI::success( EHIO_Queue::stop_bulk() . ' images taken out of the queue.' );
	}

	/**
	 * Send failed images back to the queue.
	 *
	 * ## EXAMPLES
	 *     wp ehio retry
	 */
	public function retry() {
		$count = EHIO_Queue::retry_failed();
		if ( $count ) {
			EHIO_Notifier::ping();
		}
		WP_CLI::success( "{$count} failed images re-queued." );
	}

	/**
	 * Write (or rewrite) the .htaccess delivery rules.
	 *
	 * ## EXAMPLES
	 *     wp ehio htaccess
	 */
	public function htaccess() {
		if ( EHIO_Htaccess::write() ) {
			WP_CLI::success( 'Rules written to ' . EHIO_Htaccess::upload_htaccess_file() );
		} else {
			WP_CLI::error( 'Could not write .htaccess files (permissions?).' );
		}
	}

	/**
	 * Connect this site with an access token from the worker dashboard.
	 *
	 * ## OPTIONS
	 *
	 * <token>
	 * : The ehio_… token.
	 *
	 * ## EXAMPLES
	 *     wp ehio connect ehio_eyJ2Ijox...
	 */
	public function connect( $args ) {
		$result = EHIO_Settings::set_token( $args[0] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$this->check();
	}

	/**
	 * Ask the worker whether this site's token is valid.
	 *
	 * ## EXAMPLES
	 *     wp ehio check
	 */
	public function check() {
		$state = EHIO_Connection::check();
		$line  = EHIO_Connection::LABELS[ $state['status'] ] . ': ' . $state['message'];
		if ( $state['status'] === 'active' ) {
			EHIO_Htaccess::write();
			EHIO_Notifier::ping();
			WP_CLI::success( $line );
		} else {
			WP_CLI::warning( $line );
		}
	}

	/**
	 * Remove the access token from this site.
	 *
	 * ## EXAMPLES
	 *     wp ehio disconnect
	 */
	public function disconnect() {
		EHIO_Settings::clear_token();
		WP_CLI::success( 'Token removed. New uploads will not be converted until a token is added.' );
	}

	/**
	 * Set output options.
	 *
	 * ## OPTIONS
	 *
	 * [--formats=<list>]
	 * : Comma-separated: avif,webp
	 *
	 * [--quality-webp=<n>]
	 * : WebP quality 1-100.
	 *
	 * [--quality-avif=<n>]
	 * : AVIF quality 1-100.
	 *
	 * ## EXAMPLES
	 *     wp ehio config --formats=avif,webp --quality-avif=50
	 */
	public function config( $args, $assoc_args ) {
		$current = EHIO_Settings::get();
		EHIO_Settings::update(
			EHIO_Settings::sanitize_form(
				array(
					'formats'      => isset( $assoc_args['formats'] ) ? explode( ',', $assoc_args['formats'] ) : $current['formats'],
					'quality_webp' => $assoc_args['quality-webp'] ?? $current['quality_webp'],
					'quality_avif' => $assoc_args['quality-avif'] ?? $current['quality_avif'],
				)
			)
		);
		EHIO_Htaccess::write();
		$s = EHIO_Settings::get();
		WP_CLI::success( sprintf( 'formats=%s webp=%d avif=%d', implode( ',', $s['formats'] ), $s['quality_webp'], $s['quality_avif'] ) );
	}
}
