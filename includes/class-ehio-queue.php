<?php
defined( 'ABSPATH' ) || exit;

/**
 * Job queue kept in attachment post meta.
 *
 * pending  -> waiting for the worker
 * processing (with lease) -> claimed by the worker; returns to the queue if the lease expires
 * done     -> worker finished (files saved, or nothing was smaller than the original)
 * failed   -> gave up after MAX_ATTEMPTS
 * excluded -> the owner chose to keep serving the original (never queued, copies deleted)
 */
class EHIO_Queue {

	const STATUS   = '_ehio_status';
	const LEASE    = '_ehio_lease';
	const CLAIMED  = '_ehio_claimed';
	const ATTEMPTS = '_ehio_attempts';
	const SIG      = '_ehio_sig';
	const MESSAGE  = '_ehio_message';
	const SAVED    = '_ehio_saved';
	const ORIG     = '_ehio_orig_bytes'; // Sum of the original files.
	const OUT      = '_ehio_out_bytes';  // Sum of what an AVIF-capable browser receives.

	const LEASE_SECONDS = 900;
	const MAX_ATTEMPTS  = 3;

	/** @var array<int,bool> Attachments whose metadata changed during this request. */
	private static $touched = array();

	public static function init() {
		add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'on_metadata' ), 20, 2 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 5 );
	}

	/**
	 * Metadata is written several times while sub-sizes are generated, so only
	 * remember the attachment here and decide once at the end of the request.
	 */
	public static function on_metadata( $data, $attachment_id ) {
		if ( $attachment_id ) {
			self::$touched[ (int) $attachment_id ] = true;
		}
		return $data;
	}

	public static function flush() {
		if ( ! self::$touched ) {
			return;
		}
		if ( empty( EHIO_Settings::get()['auto_convert'] ) ) {
			self::$touched = array(); // Bulk optimization picks them up later.
			return;
		}
		$ids           = array_keys( self::$touched );
		self::$touched = array();
		$queued        = 0;
		foreach ( $ids as $id ) {
			if ( self::maybe_queue( $id ) ) {
				$queued++;
			}
		}
		if ( $queued ) {
			EHIO_Notifier::ping();
		}
	}

	/** Fingerprint of the attachment's current files; changes on upload, edit or regenerate. */
	public static function signature( $attachment_id ) {
		$parts = array();
		foreach ( EHIO_Paths::attachment_files( $attachment_id ) as $file ) {
			$parts[] = $file['rel'] . ':' . $file['bytes'];
		}
		if ( ! $parts ) {
			return '';
		}
		sort( $parts );
		return md5( implode( '|', $parts ) );
	}

	public static function is_excluded( $attachment_id ) {
		return get_post_meta( $attachment_id, self::STATUS, true ) === 'excluded';
	}

	/** The cropped site icon (favicon / app icon) is left alone unless the owner includes it. */
	private static function is_site_icon( $attachment_id ) {
		return (int) get_option( 'site_icon' ) === (int) $attachment_id
			|| get_post_meta( $attachment_id, '_wp_attachment_context', true ) === 'site-icon';
	}

	/** Stops converting an image and deletes its copies, so the original is served. */
	public static function exclude( $attachment_id, $message = 'Excluded: the original is served.' ) {
		update_post_meta( $attachment_id, self::STATUS, 'excluded' );
		delete_post_meta( $attachment_id, self::ORIG );
		delete_post_meta( $attachment_id, self::OUT );
		update_post_meta( $attachment_id, self::MESSAGE, $message );
		update_post_meta( $attachment_id, self::SAVED, 0 );
		delete_post_meta( $attachment_id, self::LEASE );
		delete_post_meta( $attachment_id, self::SIG );
		EHIO_Paths::delete_outputs( $attachment_id );
	}

	/** Converts the image again now (also lifts an exclusion). Returns false if it has no convertible files. */
	public static function reoptimize( $attachment_id ) {
		if ( self::is_excluded( $attachment_id ) ) {
			delete_post_meta( $attachment_id, self::STATUS );
		}
		return self::maybe_queue( $attachment_id, true );
	}

	/** Queues the attachment if its files changed since the last time (or always with $force). */
	public static function maybe_queue( $attachment_id, $force = false ) {
		if ( self::is_excluded( $attachment_id ) ) {
			return false;
		}
		if ( get_post_meta( $attachment_id, self::STATUS, true ) === '' && self::is_site_icon( $attachment_id ) && ! $force ) {
			self::exclude( $attachment_id, 'Site icon: excluded automatically. Choose Re-optimize now to convert it anyway.' );
			return false;
		}
		$sig = self::signature( $attachment_id );
		if ( $sig === '' ) {
			return false;
		}
		if ( ! $force && get_post_meta( $attachment_id, self::SIG, true ) === $sig ) {
			return false;
		}
		update_post_meta( $attachment_id, self::SIG, $sig );
		self::set_pending( $attachment_id, true );
		return true;
	}

	public static function set_pending( $attachment_id, $reset_attempts ) {
		update_post_meta( $attachment_id, self::STATUS, 'pending' );
		delete_post_meta( $attachment_id, self::LEASE );
		delete_post_meta( $attachment_id, self::MESSAGE );
		if ( $reset_attempts ) {
			update_post_meta( $attachment_id, self::ATTEMPTS, 0 );
		}
	}

	/**
	 * Claims up to $limit jobs for the worker.
	 *
	 * @return int[] attachment IDs
	 */
	public static function claim( $limit ) {
		global $wpdb;
		$now = time();

		$candidates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.post_id FROM {$wpdb->postmeta} s
				 LEFT JOIN {$wpdb->postmeta} l ON l.post_id = s.post_id AND l.meta_key = %s
				 WHERE s.meta_key = %s
				   AND ( s.meta_value = 'pending'
				         OR ( s.meta_value = 'processing' AND ( l.meta_value IS NULL OR ( l.meta_value + 0 ) < %d ) ) )
				 ORDER BY s.post_id ASC
				 LIMIT %d",
				self::LEASE,
				self::STATUS,
				$now,
				$limit * 3
			)
		);

		$claimed = array();
		foreach ( array_map( 'intval', $candidates ) as $id ) {
			if ( count( $claimed ) >= $limit ) {
				break;
			}
			if ( get_post_type( $id ) !== 'attachment' ) {
				delete_post_meta( $id, self::STATUS );
				continue;
			}
			if ( ! EHIO_Paths::attachment_files( $id ) ) {
				self::finish( $id, 'done', 'No convertible files on disk.', 0 );
				continue;
			}

			$status = get_post_meta( $id, self::STATUS, true );
			if ( $status === 'pending' ) {
				// Compare-and-set so two workers never take the same job.
				if ( ! update_post_meta( $id, self::STATUS, 'processing', 'pending' ) ) {
					continue;
				}
			} else {
				// Expired lease: the previous worker died mid-job. Count it as an attempt.
				$old_lease = get_post_meta( $id, self::LEASE, true );
				$attempts  = (int) get_post_meta( $id, self::ATTEMPTS, true ) + 1;
				if ( $attempts >= self::MAX_ATTEMPTS ) {
					self::finish( $id, 'failed', 'Worker lease expired too many times.', 0 );
					continue;
				}
				if ( $old_lease !== '' && ! update_post_meta( $id, self::LEASE, (string) ( $now + self::LEASE_SECONDS ), $old_lease ) ) {
					continue;
				}
				update_post_meta( $id, self::ATTEMPTS, $attempts );
			}

			update_post_meta( $id, self::LEASE, (string) ( $now + self::LEASE_SECONDS ) );
			update_post_meta( $id, self::CLAIMED, (string) $now );
			$claimed[] = $id;
		}
		return $claimed;
	}

	/**
	 * Records the worker's result.
	 * On 'done', removes output files that were not refreshed in this run, so an
	 * image that no longer converts smaller never keeps serving a stale version.
	 * On 'failed', retries until MAX_ATTEMPTS.
	 */
	public static function complete( $attachment_id, $status, $message, $saved ) {
		if ( self::is_excluded( $attachment_id ) ) {
			// Excluded while the worker was busy: drop whatever it uploaded.
			EHIO_Paths::delete_outputs( $attachment_id );
			return 'excluded';
		}
		if ( $status === 'done' ) {
			$claimed = (int) get_post_meta( $attachment_id, self::CLAIMED, true );
			foreach ( EHIO_Paths::attachment_files( $attachment_id ) as $file ) {
				foreach ( EHIO_Settings::FORMATS as $format ) {
					$path = EHIO_Paths::output_path( $file['rel'], $format );
					if ( is_file( $path ) && ( filemtime( $path ) < $claimed || ! in_array( $format, $file['formats'], true ) ) ) {
						wp_delete_file( $path );
					}
				}
			}
			self::finish( $attachment_id, 'done', $message, $saved );
			self::record_bytes( $attachment_id );
			return 'done';
		}

		$attempts = (int) get_post_meta( $attachment_id, self::ATTEMPTS, true ) + 1;
		update_post_meta( $attachment_id, self::ATTEMPTS, $attempts );
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			self::finish( $attachment_id, 'failed', $message, 0 );
			return 'failed';
		}
		self::set_pending( $attachment_id, false );
		update_post_meta( $attachment_id, self::MESSAGE, $message );
		return 'pending';
	}

	/** Stores original vs delivered size so the dashboard can sum the savings. */
	public static function record_bytes( $attachment_id ) {
		$orig = 0;
		$out  = 0;
		foreach ( EHIO_Paths::attachment_files( $attachment_id ) as $file ) {
			$orig += $file['bytes'];
			$best  = $file['bytes'];
			foreach ( EHIO_Settings::FORMATS as $format ) {
				$path = EHIO_Paths::output_path( $file['rel'], $format );
				if ( is_file( $path ) ) {
					$best = min( $best, (int) filesize( $path ) );
				}
			}
			$out += $best;
		}
		update_post_meta( $attachment_id, self::ORIG, $orig );
		update_post_meta( $attachment_id, self::OUT, $out );
	}

	/** Fills in sizes for images optimized before they were recorded. Returns how many were done. */
	public static function backfill_bytes( $limit = 40 ) {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => self::STATUS,
						'value' => 'done',
					),
					array(
						'key'     => self::ORIG,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			self::record_bytes( $id );
		}
		return count( $ids );
	}

	/** Original and delivered bytes over all optimized Media Library images. */
	public static function byte_totals() {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM( CAST( o.meta_value AS UNSIGNED ) ) AS orig, SUM( CAST( d.meta_value AS UNSIGNED ) ) AS delivered
				 FROM {$wpdb->postmeta} o JOIN {$wpdb->postmeta} d ON d.post_id = o.post_id AND d.meta_key = %s
				 JOIN {$wpdb->postmeta} s ON s.post_id = o.post_id AND s.meta_key = %s AND s.meta_value = 'done'
				 WHERE o.meta_key = %s",
				self::OUT,
				self::STATUS,
				self::ORIG
			)
		);
		return array(
			'orig' => (int) ( $row->orig ?? 0 ),
			'out'  => (int) ( $row->delivered ?? 0 ),
		);
	}

	private static function finish( $attachment_id, $status, $message, $saved ) {
		update_post_meta( $attachment_id, self::STATUS, $status );
		update_post_meta( $attachment_id, self::SAVED, (int) $saved );
		update_post_meta( $attachment_id, self::MESSAGE, (string) $message );
		delete_post_meta( $attachment_id, self::LEASE );
	}

	/** Counts per status plus how many eligible images have never been queued. */
	public static function stats() {
		global $wpdb;
		$counts = array(
			'pending'    => 0,
			'processing' => 0,
			'done'       => 0,
			'failed'     => 0,
			'excluded'   => 0,
		);
		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_value AS status, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value", self::STATUS )
		);
		foreach ( $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->n;
			}
		}
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/webp')"
		);
		$counts['total']      = $total;
		$counts['not_queued'] = max( 0, $total - array_sum( array_intersect_key( $counts, array_flip( array( 'pending', 'processing', 'done', 'failed', 'excluded' ) ) ) ) );
		return $counts;
	}

	/**
	 * Queues every JPEG/PNG/WebP attachment that has no status yet (existing media library).
	 * With $everything, re-queues the whole library, e.g. after a migration that did
	 * not copy wp-content/uploads-optimized.
	 */
	public static function queue_unprocessed( $everything = false ) {
		$queued = 0;
		$page   = 1;
		do {
			$query = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => EHIO_Paths::SOURCE_MIMES,
				'fields'         => 'ids',
				'posts_per_page' => 500,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			);
			if ( ! $everything ) {
				$query['meta_query'] = array(
					array(
						'key'     => self::STATUS,
						'compare' => 'NOT EXISTS',
					),
				);
			}
			$ids = get_posts( $query );
			foreach ( $ids as $id ) {
				if ( self::is_excluded( $id ) ) {
					continue;
				}
				if ( get_post_meta( $id, self::STATUS, true ) === '' && self::is_site_icon( $id ) ) {
					self::maybe_queue( $id ); // Auto-excludes it.
					continue;
				}
				if ( self::maybe_queue( $id, true ) ) {
					$queued++;
				} else {
					// Files missing on disk: give it a status so it leaves the NOT EXISTS set.
					self::finish( $id, 'done', 'No convertible files on disk.', 0 );
				}
			}
			// In NOT EXISTS mode every item just got a status and drops out, so stay on page 1.
			if ( $everything ) {
				$page++;
			}
		} while ( count( $ids ) === 500 );
		return $queued;
	}

	/**
	 * Bulk optimization: queues Media Library images not converted yet (or all of
	 * them with $force) and syncs the enabled folders. Wakes the worker.
	 *
	 * @return array{library:int, files:int}
	 */
	public static function bulk_start( $force = false ) {
		$library = self::queue_unprocessed( $force );
		$files   = EHIO_Files::scan( $force )['queued'];
		if ( $library + $files > 0 ) {
			EHIO_Notifier::ping();
		}
		return array(
			'library' => $library,
			'files'   => $files,
		);
	}

	/**
	 * Stop button: takes every waiting image out of the queue. Images the worker is
	 * converting right now finish normally. An image that was optimized before (a
	 * "convert again" run) goes back to done, since its copies are still served;
	 * the others become "not optimized yet" and Start bulk optimization picks them up again.
	 *
	 * @return int images taken out of the queue
	 */
	public static function stop_bulk() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => self::STATUS,
				'meta_value'     => 'pending',
			)
		);
		foreach ( $ids as $id ) {
			delete_post_meta( $id, self::LEASE );
			delete_post_meta( $id, self::ATTEMPTS );
			if ( get_post_meta( $id, self::OUT, true ) !== '' ) {
				update_post_meta( $id, self::STATUS, 'done' );
			} else {
				delete_post_meta( $id, self::STATUS );
				delete_post_meta( $id, self::MESSAGE );
			}
		}
		return count( $ids ) + EHIO_Files::stop_pending();
	}

	/** Puts failed jobs back in the queue. */
	public static function retry_failed() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_key'       => self::STATUS,
				'meta_value'     => 'failed',
			)
		);
		foreach ( $ids as $id ) {
			self::set_pending( $id, true );
		}
		return count( $ids ) + EHIO_Files::retry_failed();
	}
}
