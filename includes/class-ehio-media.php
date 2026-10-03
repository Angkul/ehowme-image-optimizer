<?php
defined( 'ABSPATH' ) || exit;

/**
 * Per-image controls in the Media Library:
 *   - list view: an "Image Optimizer" column
 *   - grid view / edit screen: a block in the attachment details
 *   - bulk actions: re-optimize, exclude
 *
 * "Exclude" deletes the converted copies and keeps serving the original
 * (for a favicon, a logo, or any image that should stay byte-for-byte).
 */
class EHIO_Media {

	const ACTION = 'ehio_media';

	public static function init() {
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'add_field' ), 20, 2 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_action' ) );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ) );
		add_action( 'admin_head-upload.php', array( __CLASS__, 'styles' ) );
		add_action( 'admin_head-post.php', array( __CLASS__, 'styles' ) );
		add_action( 'admin_head-post-new.php', array( __CLASS__, 'styles' ) );
	}

	// ------------------------------------------------------------------ list view

	public static function add_column( $columns ) {
		if ( current_user_can( 'upload_files' ) ) {
			$columns['ehio'] = 'Image Optimizer';
		}
		return $columns;
	}

	public static function render_column( $column, $attachment_id ) {
		if ( $column === 'ehio' ) {
			echo self::panel( (int) $attachment_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
	}

	// ------------------------------------------------------------- status filter

	/** Filter values for upload.php?ehio_status=…, as linked from the bulk card. */
	const FILTERS = array(
		'queue'    => 'In queue',
		'none'     => 'Not optimized yet',
		'done'     => 'Optimized',
		'failed'   => 'Failed',
		'excluded' => 'Excluded',
	);

	public static function current_filter() {
		$value = isset( $_GET['ehio_status'] ) ? sanitize_key( wp_unslash( $_GET['ehio_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return isset( self::FILTERS[ $value ] ) ? $value : '';
	}

	/** Media Library list view, filtered to one optimization status. */
	public static function filter_url( $status ) {
		return add_query_arg(
			array(
				'mode'        => 'list',
				'ehio_status' => $status,
			),
			admin_url( 'upload.php' )
		);
	}

	public static function filter_query( $query ) {
		global $pagenow;
		$status = self::current_filter();
		if ( ! is_admin() || $pagenow !== 'upload.php' || ! $query->is_main_query() || $status === '' ) {
			return;
		}
		$query->set( 'post_mime_type', EHIO_Paths::SOURCE_MIMES );
		$meta = (array) $query->get( 'meta_query' );
		if ( $status === 'none' ) {
			$meta[] = array(
				'key'     => EHIO_Queue::STATUS,
				'compare' => 'NOT EXISTS',
			);
		} else {
			$meta[] = array(
				'key'     => EHIO_Queue::STATUS,
				'value'   => $status === 'queue' ? array( 'pending', 'processing' ) : array( $status ),
				'compare' => 'IN',
			);
		}
		$query->set( 'meta_query', $meta );
	}

	public static function filter_dropdown( $post_type ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'upload' || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$current = self::current_filter();
		echo '<label for="ehio-status-filter" class="screen-reader-text">Filter by optimization status</label>';
		echo '<select name="ehio_status" id="ehio-status-filter"><option value="">All optimization states</option>';
		foreach ( self::FILTERS as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/** Above the filtered list: what is shown, and a way back. */
	private static function filter_notice() {
		$status = self::current_filter();
		if ( $status === '' ) {
			return;
		}
		$folder = '';
		$files  = EHIO_Files::stats();
		$map    = array(
			'queue'  => $files['pending'] + $files['processing'],
			'failed' => $files['failed'],
			'done'   => $files['done'],
			'none'   => $files['idle'],
		);
		if ( ! empty( $map[ $status ] ) ) {
			$folder = ' ' . sprintf(
				_n( '%s more image from other folders (themes, plugins, page-builder files) is in this state but is not listed here.', '%s more images from other folders (themes, plugins, page-builder files) are in this state but are not listed here.', $map[ $status ], 'ehowme-image-optimizer' ),
				number_format_i18n( $map[ $status ] )
			);
		}
		$phrases = array(
			'queue'    => 'waiting in the queue',
			'none'     => 'not optimized yet',
			'done'     => 'already optimized',
			'failed'   => 'that failed to convert',
			'excluded' => 'excluded from optimization',
		);
		printf(
			'<div class="notice notice-info ehio-filter-note"><p>Showing Media Library images <strong>%1$s</strong>.%2$s <a href="%3$s">Show all images</a> · <a href="%4$s">Back to Image Optimizer</a></p></div>',
			esc_html( $phrases[ $status ] ),
			esc_html( $folder ),
			esc_url( remove_query_arg( array( 'ehio_status', 'paged' ) ) ),
			esc_url( admin_url( 'upload.php?page=ehio#ehio-bulk' ) )
		);
	}

	// --------------------------------------------------- grid view / edit screen

	public static function add_field( $fields, $post ) {
		if ( ! in_array( get_post_mime_type( $post ), EHIO_Paths::SOURCE_MIMES, true ) || ! current_user_can( 'upload_files' ) ) {
			return $fields;
		}
		$fields['ehio'] = array(
			'label' => 'Image Optimizer',
			'input' => 'html',
			'html'  => self::panel( (int) $post->ID ),
		);
		return $fields;
	}

	// ---------------------------------------------------------------------- panel

	/** Status, sizes and actions for one attachment. */
	public static function panel( $attachment_id ) {
		if ( ! in_array( get_post_mime_type( $attachment_id ), EHIO_Paths::SOURCE_MIMES, true ) ) {
			return '<span class="ehio-muted">Not converted (only JPEG, PNG and WebP)</span>';
		}

		$status  = (string) get_post_meta( $attachment_id, EHIO_Queue::STATUS, true );
		$message = (string) get_post_meta( $attachment_id, EHIO_Queue::MESSAGE, true );
		$report  = self::report( $attachment_id );

		$labels = array(
			''           => array( 'Not optimized yet', 'idle' ),
			'pending'    => array( 'In queue', 'wait' ),
			'processing' => array( 'Converting…', 'wait' ),
			'done'       => $report['files_with_copies'] ? array( 'Optimized', 'ok' ) : array( 'Original kept', 'idle' ),
			'failed'     => array( 'Failed', 'err' ),
			'excluded'   => array( 'Excluded', 'off' ),
		);
		list( $label, $tone ) = $labels[ $status ] ?? $labels[''];

		$html = '<div class="ehio-panel"><span class="ehio-badge ehio-' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';

		if ( $status !== 'excluded' && $report['files_with_copies'] ) {
			$parts = array();
			foreach ( $report['formats'] as $format => $t ) {
				if ( $t['orig'] > 0 ) {
					$parts[] = self::label( $format ) . ' ' . self::pct( $t['orig'], $t['out'] );
				}
			}
			$html .= '<div class="ehio-line">' . esc_html( implode( ' · ', $parts ) ) . ' <span class="ehio-muted">(' . esc_html( self::files_label( $report['files_with_copies'], $report['files'] ) ) . ')</span></div>';
			$html .= self::details( $report );
		}

		if ( $message !== '' && ( $status !== 'done' || ! $report['files_with_copies'] ) ) {
			$html .= '<div class="ehio-line ehio-muted">' . esc_html( $message ) . '</div>';
		}

		if ( current_user_can( 'edit_post', $attachment_id ) ) {
			$html .= '<div class="ehio-actions">';
			$html .= '<a class="button button-small" href="' . esc_url( self::action_url( 'reoptimize', $attachment_id ) ) . '">'
				. ( $status === 'excluded' ? 'Include and optimize' : 'Re-optimize now' ) . '</a>';
			if ( $status !== 'excluded' ) {
				$html .= ' <a class="button button-small" href="' . esc_url( self::action_url( 'exclude', $attachment_id ) ) . '" title="Delete the AVIF/WebP copies and always serve the original">Exclude</a>';
			}
			$html .= '</div>';
		}
		return $html . '</div>';
	}

	/** Original vs converted sizes for every file of the attachment, read from disk. */
	private static function report( $attachment_id ) {
		$report = array(
			'files'             => 0,
			'files_with_copies' => 0,
			'rows'              => array(),
			'formats'           => array(),
		);
		foreach ( EHIO_Paths::attachment_files( $attachment_id ) as $file ) {
			$report['files']++;
			$row = array(
				'name'   => wp_basename( $file['rel'] ),
				'bytes'  => $file['bytes'],
				'copies' => array(),
			);
			foreach ( EHIO_Settings::FORMATS as $format ) {
				$path = EHIO_Paths::output_path( $file['rel'], $format );
				if ( ! is_file( $path ) ) {
					continue;
				}
				$size                     = (int) filesize( $path );
				$row['copies'][ $format ] = $size;
				if ( ! isset( $report['formats'][ $format ] ) ) {
					$report['formats'][ $format ] = array(
						'orig' => 0,
						'out'  => 0,
					);
				}
				$report['formats'][ $format ]['orig'] += $file['bytes'];
				$report['formats'][ $format ]['out']  += $size;
			}
			if ( $row['copies'] ) {
				$report['files_with_copies']++;
			}
			$report['rows'][] = $row;
		}
		return $report;
	}

	private static function details( $report ) {
		$items = '';
		foreach ( $report['rows'] as $row ) {
			$copies = array();
			foreach ( $row['copies'] as $format => $size ) {
				$copies[] = self::label( $format ) . ' ' . size_format( $size, 1 ) . ' (' . self::pct( $row['bytes'], $size ) . ')';
			}
			$items .= '<li><span class="ehio-file">' . esc_html( $row['name'] ) . '</span> '
				. esc_html( size_format( $row['bytes'], 1 ) ) . ' → '
				. esc_html( $copies ? implode( ', ', $copies ) : 'original kept (copies were not smaller)' ) . '</li>';
		}
		return '<details class="ehio-details"><summary>Sizes</summary><ul>' . $items . '</ul></details>';
	}

	private static function label( $format ) {
		return $format === 'webp' ? 'WebP' : strtoupper( $format );
	}

	private static function pct( $orig, $out ) {
		if ( $orig <= 0 ) {
			return '';
		}
		return '−' . min( 99, max( 0, (int) round( ( 1 - $out / $orig ) * 100 ) ) ) . '%';
	}

	private static function files_label( $converted, $total ) {
		return $converted === $total
			? sprintf( _n( '%d file', '%d files', $total, 'ehowme-image-optimizer' ), $total )
			: sprintf( '%d of %d files', $converted, $total );
	}

	// -------------------------------------------------------------------- actions

	private static function action_url( $do, $attachment_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'do'     => $do,
					'id'     => $attachment_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $do . '_' . $attachment_id
		);
	}

	public static function handle_action() {
		$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		check_admin_referer( self::ACTION . '_' . $do . '_' . $id );
		if ( ! $id || get_post_type( $id ) !== 'attachment' || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'You cannot change this image.', 403 );
		}
		$count = self::apply( $do, array( $id ) );
		$back  = wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' );
		wp_safe_redirect( add_query_arg( array( 'ehio_media' => $do, 'ehio_count' => $count ), $back ) );
		exit;
	}

	/** Runs an action on several attachments. Returns how many were changed. */
	private static function apply( $do, array $ids ) {
		$count = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! current_user_can( 'edit_post', $id ) || ! in_array( get_post_mime_type( $id ), EHIO_Paths::SOURCE_MIMES, true ) ) {
				continue;
			}
			if ( $do === 'exclude' ) {
				EHIO_Queue::exclude( $id );
				$count++;
			} elseif ( $do === 'reoptimize' && EHIO_Queue::reoptimize( $id ) ) {
				$count++;
			}
		}
		if ( $do === 'reoptimize' && $count ) {
			EHIO_Notifier::ping();
		}
		return $count;
	}

	public static function bulk_actions( $actions ) {
		if ( current_user_can( 'upload_files' ) ) {
			$actions['ehio_reoptimize'] = 'Image Optimizer: re-optimize now';
			$actions['ehio_exclude']    = 'Image Optimizer: exclude (serve original)';
		}
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( 'ehio_reoptimize', 'ehio_exclude' ), true ) ) {
			return $redirect;
		}
		$do    = substr( $action, 5 );
		$count = self::apply( $do, (array) $ids );
		return add_query_arg( array( 'ehio_media' => $do, 'ehio_count' => $count ), $redirect );
	}

	public static function notices() {
		$screen = get_current_screen();
		if ( $screen && $screen->id === 'upload' ) {
			self::filter_notice();
		}
		if ( empty( $_GET['ehio_media'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$do    = sanitize_key( wp_unslash( $_GET['ehio_media'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$count = isset( $_GET['ehio_count'] ) ? (int) $_GET['ehio_count'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $do === 'exclude' ) {
			$text = sprintf( _n( '%d image excluded. Its original is served from now on.', '%d images excluded. Their originals are served from now on.', $count, 'ehowme-image-optimizer' ), $count );
		} elseif ( $do === 'reoptimize' ) {
			$text = $count
				? sprintf( _n( '%d image sent to the worker for conversion.', '%d images sent to the worker for conversion.', $count, 'ehowme-image-optimizer' ), $count )
				: 'Nothing to convert: the selected files are not JPEG, PNG or WebP.';
		} else {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	public static function styles() {
		?>
		<style>
			.column-ehio { width: 260px; }
			.ehio-panel { font-size: 12px; line-height: 1.5; }
			.ehio-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; font-weight: 500; }
			.ehio-ok { background: #e6f4ea; color: #1e6b36; }
			.ehio-wait { background: #fcf3e3; color: #8a5300; }
			.ehio-err { background: #fcebea; color: #b32d2e; }
			.ehio-off, .ehio-idle { background: #f0f0f1; color: #50575e; }
			.ehio-line { margin-top: 4px; }
			.ehio-muted { color: #646970; }
			.ehio-details { margin-top: 4px; }
			.ehio-details summary { cursor: pointer; color: #2271b1; }
			.ehio-details ul { margin: 4px 0 0 0; }
			.ehio-details li { margin: 0 0 2px; }
			.ehio-file { color: #1d2327; word-break: break-all; }
			.ehio-actions { margin-top: 6px; display: flex; gap: 4px; flex-wrap: wrap; }
			.compat-field-ehio .ehio-panel { padding-top: 6px; }
		</style>
		<?php
	}
}
