<?php
defined( 'ABSPATH' ) || exit;

/**
 * Media > Image Optimizer: bulk optimization, settings and the worker connection.
 */
class EHIO_Admin {

	const SLUG = 'ehio';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ehio_action', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_ehio_progress', array( __CLASS__, 'ajax_progress' ) );
		add_action( 'admin_notices', array( __CLASS__, 'global_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EHIO_FILE ), array( __CLASS__, 'links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 20, 2 );
	}

	public static function menu() {
		add_media_page( 'eHowMe Image Optimizer', 'Image Optimizer', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">Settings</a>' );
		return $links;
	}

	/** Plugins row: "Version | By eHowMe | Bulk optimization" only. */
	public static function row_meta( $meta, $file ) {
		if ( $file === plugin_basename( EHIO_FILE ) ) {
			// WordPress adds "View details" once the GitHub updater registers the slug.
			$meta   = array_values(
				array_filter(
					$meta,
					function ( $link ) {
						return strpos( $link, 'tab=plugin-information' ) === false;
					}
				)
			);
			$meta[] = '<a href="' . esc_url( self::page_url() ) . '#ehio-bulk">Bulk optimization</a>';
		}
		return $meta;
	}

	private static function page_url() {
		return admin_url( 'upload.php?page=' . self::SLUG );
	}

	private static function action_url( $do ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=ehio_action&do=' . $do ), 'ehio_' . $do );
	}

	/** Reminds admins on other screens that the plugin does nothing until connected. */
	public static function global_notice() {
		$screen = get_current_screen();
		if ( ! current_user_can( 'manage_options' ) || ( $screen && $screen->id === 'media_page_' . self::SLUG ) ) {
			return;
		}
		if ( ! EHIO_Settings::has_token() ) {
			printf(
				'<div class="notice notice-warning"><p>eHowMe Image Optimizer is not connected, so images are not converted. <a href="%s">Add the access token</a>.</p></div>',
				esc_url( self::page_url() )
			);
		}
	}

	// ------------------------------------------------------------------ actions

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		check_admin_referer( 'ehio_' . $do );

		$notice = '';
		$anchor = '';
		$page   = '';
		switch ( $do ) {
			case 'connect':
				$token  = isset( $_POST['ehio_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ehio_token'] ) ) : '';
				$result = EHIO_Settings::set_token( $token );
				if ( is_wp_error( $result ) ) {
					$notice = 'err:' . $result->get_error_message();
					break;
				}
				$state  = EHIO_Connection::check();
				$notice = $state['status'] === 'active' ? 'connected' : 'checked';
				if ( $state['status'] === 'active' ) {
					EHIO_Htaccess::write();
					EHIO_Notifier::ping();
				}
				break;
			case 'check':
				$notice = EHIO_Connection::check()['status'] === 'active' ? 'connected' : 'checked';
				break;
			case 'disconnect':
				EHIO_Settings::clear_token();
				$notice = 'disconnected';
				break;
			case 'save':
				$input  = isset( $_POST['ehio'] ) && is_array( $_POST['ehio'] ) ? wp_unslash( $_POST['ehio'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in sanitize_form().
				$tab    = isset( $_POST['tab'] ) && $_POST['tab'] === 'advanced' ? 'advanced' : 'general';
				$before = EHIO_Files::enabled_dirs();
				EHIO_Settings::update( $tab === 'advanced' ? EHIO_Settings::sanitize_advanced( $input ) : EHIO_Settings::sanitize_form( $input ) );
				foreach ( array_diff( $before, EHIO_Files::enabled_dirs() ) as $root ) {
					EHIO_Files::delete_root( $root ); // Folder switched off: its copies go too.
				}
				$notice = EHIO_Htaccess::write() ? 'saved' : 'saved_htaccess_failed';
				$anchor = $tab === 'advanced' ? '' : '#ehio-settings';
				$page   = $tab === 'advanced' ? 'advanced' : '';
				break;
			case 'bulk':
				$result = EHIO_Queue::bulk_start( ! empty( $_POST['force'] ) );
				$notice = 'bulk:' . ( $result['library'] + $result['files'] );
				$anchor = '#ehio-bulk';
				break;
			case 'import':
				if ( EHIO_Import::source_plugin_active() ) {
					$notice = 'err:Deactivate Converter for Media first (do not delete it yet), then import.';
					break;
				}
				$r      = EHIO_Import::run( 20 );
				$notice = $r['done'] ? 'import_done' : 'importing';
				$anchor = '#ehio-import';
				break;
			case 'stop':
				$notice = 'stopped:' . EHIO_Queue::stop_bulk();
				$anchor = '#ehio-bulk';
				break;
			case 'retry_failed':
				$notice = 'retried:' . EHIO_Queue::retry_failed();
				EHIO_Notifier::ping();
				$anchor = '#ehio-bulk';
				break;
			case 'htaccess':
				$notice = EHIO_Htaccess::write() ? 'htaccess_ok' : 'htaccess_failed';
				$page   = ! empty( $_GET['tab'] ) && $_GET['tab'] === 'advanced' ? 'advanced' : '';
				break;
		}
		$args = array( 'ehio_notice' => rawurlencode( $notice ) );
		if ( $page ) {
			$args['tab'] = $page;
		}
		wp_safe_redirect( add_query_arg( $args, self::page_url() ) . $anchor );
		exit;
	}

	private static function notice( $code ) {
		if ( strpos( $code, 'err:' ) === 0 ) {
			return array( 'error', substr( $code, 4 ) );
		}
		if ( strpos( $code, 'bulk:' ) === 0 ) {
			$n = (int) substr( $code, 5 );
			return $n
				? array( 'success', sprintf( _n( '%d image sent to the worker. You can leave this page; conversion continues in the background.', '%d images sent to the worker. You can leave this page; conversion continues in the background.', $n, 'ehowme-image-optimizer' ), $n ) )
				: array( 'info', 'Everything is already optimized. Turn on "Convert optimized images again" to redo them with the current settings.' );
		}
		if ( $code === 'import_done' ) {
			$st = EHIO_Import::state();
			return array(
				'success',
				sprintf(
					'Imported %1$s copies from Converter for Media: %2$s images marked optimized, %3$s queued to fill missing formats.%4$s You can delete Converter for Media now.',
					number_format_i18n( (int) ( $st['moved'] ?? 0 ) ),
					number_format_i18n( (int) ( $st['marked'] ?? 0 ) ),
					number_format_i18n( (int) ( $st['queued'] ?? 0 ) ),
					! empty( $st['failed'] ) ? ' ' . sprintf( '%s files could not be moved (folder permissions); those images are converted again.', number_format_i18n( (int) $st['failed'] ) ) : ''
				),
			);
		}
		if ( $code === 'importing' ) {
			return array( 'info', 'Importing… this page continues automatically.' );
		}
		if ( strpos( $code, 'stopped:' ) === 0 ) {
			$n = (int) substr( $code, 8 );
			return $n
				? array( 'success', sprintf( _n( 'Stopped. %d image was taken out of the queue. Images already being converted finish; press Start bulk optimization to continue later.', 'Stopped. %d images were taken out of the queue. Images already being converted finish; press Start bulk optimization to continue later.', $n, 'ehowme-image-optimizer' ), $n ) )
				: array( 'info', 'Nothing was waiting in the queue.' );
		}
		if ( strpos( $code, 'retried:' ) === 0 ) {
			return array( 'success', sprintf( '%d failed images sent back to the queue.', (int) substr( $code, 8 ) ) );
		}
		$texts = array(
			'connected'             => array( 'success', 'Connected. New uploads are converted automatically.' ),
			'checked'               => array( 'warning', 'Connection checked. See the status on the right.' ),
			'disconnected'          => array( 'success', 'Disconnected. The token was removed from this site.' ),
			'saved'                 => array( 'success', 'Settings saved.' ),
			'saved_htaccess_failed' => array( 'warning', 'Settings saved, but the .htaccess files could not be written. Check folder permissions.' ),
			'htaccess_ok'           => array( 'success', 'Delivery rules written.' ),
			'htaccess_failed'       => array( 'error', 'Could not write the .htaccess files. Check folder permissions.' ),
		);
		return $texts[ $code ] ?? null;
	}

	// ----------------------------------------------------------------- progress

	/** Everything the bulk card shows, also returned by the AJAX poll. */
	public static function progress() {
		$lib   = EHIO_Queue::stats();
		$files = EHIO_Files::stats();
		$bytes = EHIO_Queue::byte_totals();

		$total     = max( 0, $lib['total'] - $lib['excluded'] ) + $files['total'];
		$done      = $lib['done'] + $files['done'];
		$queue     = $lib['pending'] + $lib['processing'] + $files['pending'] + $files['processing'];
		$failed    = $lib['failed'] + $files['failed'];
		$orig      = $bytes['orig'] + $files['orig'];
		$delivered = $bytes['out'] + $files['out'];

		$sources = array(
			array(
				'key'   => 'library',
				'label' => 'Media Library',
				'total' => max( 0, $lib['total'] - $lib['excluded'] ),
				'done'  => $lib['done'],
			),
		);
		$labels = EHIO_Files::available_dirs();
		$scan   = get_option( 'ehio_scan', array() );
		foreach ( EHIO_Files::enabled_dirs() as $root ) {
			$sources[] = array(
				'key'     => $root,
				'label'   => $labels[ $root ],
				'total'   => $files['roots'][ $root ]['total'] ?? 0,
				'done'    => $files['roots'][ $root ]['done'] ?? 0,
				'scanned' => isset( $scan['counts'][ $root ] ),
			);
		}

		return array(
			'total'     => $total,
			'done'      => $done,
			'percent'   => $total ? (int) floor( $done * 100 / $total ) : 0,
			'queue'     => $queue,
			'remaining' => max( 0, $total - $done - $failed - $queue ),
			'failed'    => $failed,
			'excluded'  => $lib['excluded'],
			'orig'      => $orig,
			'delivered' => $delivered,
			'saved_pct' => $orig ? (int) round( ( 1 - $delivered / $orig ) * 100 ) : 0,
			'saved_h'   => size_format( max( 0, $orig - $delivered ), 1 ),
			'orig_h'    => size_format( $orig, 1 ),
			'deliv_h'   => size_format( $delivered, 1 ),
			'sources'   => $sources,
		);
	}

	public static function ajax_progress() {
		check_ajax_referer( 'ehio_progress' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		EHIO_Queue::backfill_bytes( 20 );
		wp_send_json_success( self::progress() );
	}

	// ------------------------------------------------------------------- render

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( EHIO_Connection::is_stale() ) {
			EHIO_Connection::check();
		}
		EHIO_Queue::backfill_bytes( 60 );
		$s         = EHIO_Settings::get();
		$conn      = EHIO_Connection::get();
		$p         = self::progress();
		$connected = $conn['status'] === 'active';
		$notice    = isset( $_GET['ehio_notice'] ) ? self::notice( sanitize_text_field( wp_unslash( $_GET['ehio_notice'] ) ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		$tab       = isset( $_GET['tab'] ) && $_GET['tab'] === 'advanced' ? 'advanced' : 'general'; // phpcs:ignore WordPress.Security.NonceVerification

		self::styles();
		?>
		<div class="wrap ehio">
			<header class="ehio-head">
				<div class="ehio-brand">
					<img class="ehio-mark" src="<?php echo esc_url( plugins_url( 'assets/icon-128.png', EHIO_FILE ) ); ?>" width="56" height="56" alt="">
					<div>
						<h1 class="ehio-title">eHowMe Image Optimizer</h1>
						<div class="ehio-sub">by <a href="https://www.ehowme.com/" target="_blank" rel="noopener">eHowMe</a> · v<?php echo esc_html( EHIO_VERSION ); ?></div>
					</div>
				</div>
				<?php if ( EHIO_Connection::is_paused() ) : ?>
					<span class="ehio-pill is-warn"><i></i>Paused by the worker</span>
				<?php else : ?>
					<span class="ehio-pill <?php echo esc_attr( self::tone( $conn['status'] ) ); ?>"><i></i><?php echo esc_html( EHIO_Connection::LABELS[ $conn['status'] ] ?? $conn['status'] ); ?></span>
				<?php endif; ?>
			</header>
			<hr class="wp-header-end">

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<div class="ehio-grid">
				<div class="ehio-main">
					<nav class="ehio-tabs" aria-label="Settings sections">
						<a href="<?php echo esc_url( self::page_url() ); ?>" class="<?php echo $tab === 'general' ? 'is-active' : ''; ?>" <?php echo $tab === 'general' ? 'aria-current="page"' : ''; ?>>General Settings</a>
						<a href="<?php echo esc_url( add_query_arg( 'tab', 'advanced', self::page_url() ) ); ?>" class="<?php echo $tab === 'advanced' ? 'is-active' : ''; ?>" <?php echo $tab === 'advanced' ? 'aria-current="page"' : ''; ?>>Advanced Settings</a>
					</nav>
					<?php if ( $tab === 'advanced' ) : ?>
						<?php self::render_advanced( $s ); ?>
					<?php else : ?>
						<?php self::render_import(); ?>
						<?php self::render_stuck_warning( $p, $connected ); ?>
						<?php self::render_settings( $s, $p ); ?>
						<?php self::render_bulk( $p, $connected ); ?>
					<?php endif; ?>
				</div>
				<aside class="ehio-side">
					<?php self::render_connection( $s, $conn ); ?>
					<?php self::render_delivery_problems(); ?>
					<section class="ehio-card ehio-tips">
						<h2>Per-image control</h2>
						<p>In <a href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>">Media Library (list view)</a> each image shows its savings, with <strong>Re-optimize now</strong> and <strong>Exclude</strong> for logos, favicons or images that must stay untouched.</p>
					</section>
				</aside>
			</div>
		</div>
		<?php
		if ( $tab === 'general' ) {
			self::script( $p );
		}
	}

	private static function tone( $status ) {
		if ( $status === 'active' ) {
			return 'is-ok';
		}
		return in_array( $status, array( 'unreachable', 'not_connected' ), true ) ? 'is-warn' : 'is-err';
	}

	/** Offer to move Converter for Media copies over; shows progress and the result. */
	private static function render_import() {
		if ( ! is_dir( EHIO_Import::source_dir() ) ) {
			return;
		}
		$st        = EHIO_Import::state();
		$phase     = $st['phase'] ?? '';
		$running   = in_array( $phase, array( 'move', 'mark' ), true );
		$found     = EHIO_Import::found( $running );
		$installed = is_dir( WP_PLUGIN_DIR . '/webp-converter-for-media' );
		$active    = EHIO_Import::source_plugin_active();

		if ( $phase === 'done' ) {
			if ( ! $installed ) {
				return;
			}
			?>
			<section class="ehio-card ehio-import is-done" id="ehio-import">
				<div class="ehio-import-icon"><span class="dashicons dashicons-yes-alt"></span></div>
				<div>
					<h2>Converter for Media files imported</h2>
					<p><?php echo esc_html( sprintf( '%1$s copies moved · %2$s images marked optimized · %3$s queued for missing formats.', number_format_i18n( (int) $st['moved'] ), number_format_i18n( (int) $st['marked'] ), number_format_i18n( (int) $st['queued'] ) ) ); ?></p>
					<p class="ehio-hint">You can now <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">delete Converter for Media</a>. Files left in <code>uploads-webpc</code> (<?php echo esc_html( number_format_i18n( $found['files'] ) ); ?>) are not needed and go with it.</p>
				</div>
			</section>
			<?php
			return;
		}
		if ( ! $running && ! $found['files'] ) {
			return;
		}
		?>
		<section class="ehio-card ehio-import" id="ehio-import">
			<div class="ehio-import-icon"><span class="dashicons dashicons-migrate"></span></div>
			<div class="ehio-import-body">
				<h2><?php echo $running ? 'Importing from Converter for Media…' : 'Import from Converter for Media'; ?></h2>
				<?php if ( $running ) : ?>
					<p><?php echo esc_html( sprintf( '%1$s copies moved so far%2$s. Keep this page open.', number_format_i18n( (int) $st['moved'] ), $phase === 'mark' ? ', now marking Media Library images' : '' ) ); ?></p>
				<?php else : ?>
					<p><?php echo esc_html( sprintf( 'Found %1$s AVIF/WebP copies (%2$s) made by Converter for Media. Move them here so these images do not need converting again.', number_format_i18n( $found['files'] ), size_format( $found['bytes'], 1 ) ) ); ?></p>
					<ol class="ehio-steps">
						<li class="<?php echo $active ? 'is-todo' : 'is-ok'; ?>"><?php if ( $active ) : ?><a href="<?php echo esc_url( admin_url( 'plugins.php?plugin_status=active' ) ); ?>">Deactivate Converter for Media</a> — do <strong>not</strong> delete it yet, deleting removes these files.<?php else : ?>Converter for Media is deactivated.<?php endif; ?></li>
						<li>Import: the copies are moved (no extra disk space). Images with every format are marked optimized; the rest are queued.</li>
						<li>Delete Converter for Media.</li>
					</ol>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ehio="importform">
					<input type="hidden" name="action" value="ehio_action">
					<input type="hidden" name="do" value="import">
					<?php wp_nonce_field( 'ehio_import' ); ?>
					<button type="submit" class="button button-primary" <?php disabled( $active ); ?>><?php echo $running ? 'Continue import' : 'Import ' . esc_html( number_format_i18n( $found['files'] ) ) . ' files'; ?></button>
				</form>
			</div>
		</section>
		<?php if ( $running ) : ?>
			<script>setTimeout(function () { var f = document.querySelector('[data-ehio="importform"]'); if (f) { f.submit(); } }, 800);</script>
		<?php endif; ?>
		<?php
	}

	private static function render_bulk( $p, $connected ) {
		$r    = 54;
		$circ = 2 * M_PI * $r;
		?>
		<section class="ehio-card ehio-bulk" id="ehio-bulk">
			<div class="ehio-card-head">
				<div>
					<h2>Bulk optimization</h2>
					<p>Converts every image that is not optimized yet. You can close this page: the worker keeps going.</p>
				</div>
				<span class="ehio-live<?php echo EHIO_Connection::is_paused() ? ' is-paused' : ''; ?>" data-ehio="live" <?php echo $p['queue'] ? '' : 'hidden'; ?>><i></i><?php echo EHIO_Connection::is_paused() ? 'Paused by the worker' : 'Converting'; ?></span>
			</div>

			<div class="ehio-bulk-body">
				<div class="ehio-ring" style="--circ:<?php echo esc_attr( round( $circ, 2 ) ); ?>">
					<svg viewBox="0 0 132 132" aria-hidden="true">
						<circle cx="66" cy="66" r="<?php echo (int) $r; ?>" class="track"/>
						<circle cx="66" cy="66" r="<?php echo (int) $r; ?>" class="bar" data-ehio="ring"
							stroke-dasharray="<?php echo esc_attr( round( $circ, 2 ) ); ?>"
							stroke-dashoffset="<?php echo esc_attr( round( $circ * ( 1 - $p['percent'] / 100 ), 2 ) ); ?>"/>
					</svg>
					<div class="ehio-ring-label"><strong data-ehio="percent"><?php echo (int) $p['percent']; ?>%</strong><span>optimized</span></div>
				</div>

				<div class="ehio-bulk-stats">
					<p class="ehio-count"><a href="<?php echo esc_url( EHIO_Media::filter_url( 'done' ) ); ?>" title="Show optimized images in the Media Library"><strong data-ehio="done"><?php echo esc_html( number_format_i18n( $p['done'] ) ); ?></strong></a> of <span data-ehio="total"><?php echo esc_html( number_format_i18n( $p['total'] ) ); ?></span> images optimized</p>
					<ul class="ehio-chips">
						<li><a href="<?php echo esc_url( EHIO_Media::filter_url( 'queue' ) ); ?>"><b class="dot q"></b>In queue <strong data-ehio="queue"><?php echo (int) $p['queue']; ?></strong></a></li>
						<li><a href="<?php echo esc_url( EHIO_Media::filter_url( 'none' ) ); ?>"><b class="dot r"></b>Not optimized yet <strong data-ehio="remaining"><?php echo (int) $p['remaining']; ?></strong></a></li>
						<li><a href="<?php echo esc_url( EHIO_Media::filter_url( 'failed' ) ); ?>"><b class="dot f"></b>Failed <strong data-ehio="failed"><?php echo (int) $p['failed']; ?></strong></a><a class="ehio-retry" href="<?php echo esc_url( self::action_url( 'retry_failed' ) ); ?>" data-ehio="retry" <?php echo $p['failed'] ? '' : 'hidden'; ?>>Retry</a></li>
						<li><a href="<?php echo esc_url( EHIO_Media::filter_url( 'excluded' ) ); ?>"><b class="dot x"></b>Excluded <strong data-ehio="excluded"><?php echo (int) $p['excluded']; ?></strong></a></li>
					</ul>
					<p class="ehio-hint">Click a count to see those images in the Media Library.</p>
					<div class="ehio-savings" data-ehio="savings" <?php echo $p['orig'] ? '' : 'hidden'; ?>>
						<div class="ehio-savings-head"><span>Saved <strong data-ehio="saved"><?php echo esc_html( $p['saved_h'] ); ?></strong></span><span class="ehio-badge" data-ehio="savedpct">−<?php echo (int) $p['saved_pct']; ?>%</span></div>
						<div class="ehio-bars">
							<div class="row"><span>Originals</span><div class="b"><i style="width:100%"></i></div><em data-ehio="orig"><?php echo esc_html( $p['orig_h'] ); ?></em></div>
							<div class="row"><span>Delivered</span><div class="b"><i class="opt" data-ehio="delivbar" style="width:<?php echo esc_attr( max( 1, 100 - $p['saved_pct'] ) ); ?>%"></i></div><em data-ehio="deliv"><?php echo esc_html( $p['deliv_h'] ); ?></em></div>
						</div>
						<p class="ehio-hint">Delivered = what an AVIF-capable browser downloads for the optimized images.</p>
					</div>
				</div>
			</div>

			<div class="ehio-sources">
				<div class="ehio-sources-head">Images that can be optimized <a href="#ehio-folders">Choose folders</a></div>
				<ul data-ehio="sources">
					<?php foreach ( $p['sources'] as $src ) : ?>
						<?php self::source_row( $src ); ?>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="ehio-bulk-foot">
				<label class="ehio-switch"><input type="checkbox" name="force" value="1" form="ehio-bulk-form"><span></span>Convert optimized images again <em>(after changing quality or size)</em></label>
				<div class="ehio-bulk-buttons">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ehio="stop" <?php echo $p['queue'] ? '' : 'hidden'; ?>>
						<input type="hidden" name="action" value="ehio_action">
						<input type="hidden" name="do" value="stop">
						<?php wp_nonce_field( 'ehio_stop' ); ?>
						<button type="submit" class="button button-hero ehio-stop" title="Take the waiting images out of the queue. Images being converted right now finish."><span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>Stop</button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ehio-bulk-form">
						<input type="hidden" name="action" value="ehio_action">
						<input type="hidden" name="do" value="bulk">
						<?php wp_nonce_field( 'ehio_bulk' ); ?>
						<button type="submit" class="button button-primary button-hero ehio-start" <?php disabled( ! $connected ); ?>>Start bulk optimization</button>
					</form>
				</div>
			</div>
			<?php if ( ! $connected ) : ?>
				<p class="ehio-hint ehio-right">Connect to the worker first (see Connection).</p>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function source_row( $src ) {
		$pct  = $src['total'] ? floor( $src['done'] * 100 / $src['total'] ) : 0;
		$icon = $src['key'] === 'library' ? 'dashicons-format-image' : 'dashicons-category';
		?>
		<li>
			<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
			<span class="name"><?php echo esc_html( $src['label'] ); ?></span>
			<?php if ( $src['key'] !== 'library' && empty( $src['scanned'] ) ) : ?>
				<span class="meta">scanned when you start bulk optimization</span>
			<?php else : ?>
				<span class="meta"><?php echo esc_html( number_format_i18n( $src['done'] ) . ' / ' . number_format_i18n( $src['total'] ) ); ?></span>
				<span class="mini"><i style="width:<?php echo (int) $pct; ?>%"></i></span>
			<?php endif; ?>
		</li>
		<?php
	}

	private static function render_settings( $s, $p ) {
		$dirs   = EHIO_Files::available_dirs();
		$scan   = get_option( 'ehio_scan', array() );
		$descs  = array(
			'uploads' => 'Files in uploads that are not Media Library items, e.g. Elementor and page-builder thumbnails.',
			'themes'  => 'Images shipped with your themes (backgrounds, icons).',
			'plugins' => 'Images shipped with plugins. Usually small; enable if PageSpeed lists them.',
			'gallery' => 'NextGEN Gallery images.',
			'cache'   => 'Images that other plugins write into wp-content/cache.',
		);
		$strategies = array(
			'smallest' => array( 'Smallest files', 'Most savings, slight softness on detailed photos.' ),
			'balanced' => array( 'Balanced', 'Recommended. Hard to tell apart from the original.' ),
			'quality'  => array( 'High quality', 'For photography and product close-ups.' ),
			'custom'   => array( 'Custom', 'Set the quality per format yourself.' ),
		);
		?>
		<form class="ehio-card ehio-settings" id="ehio-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ehio_action">
			<input type="hidden" name="do" value="save">
			<input type="hidden" name="tab" value="general">
			<?php wp_nonce_field( 'ehio_save' ); ?>
			<div class="ehio-card-head"><div><h2>General Settings</h2><p>Changes apply to images converted from now on. Use bulk optimization with "Convert optimized images again" to update existing ones.</p></div></div>

			<div class="ehio-field">
				<div class="ehio-label"><h3>Image formats</h3><p>Each browser gets the first format it supports. The original stays as the fallback.</p></div>
				<div class="ehio-control ehio-tiles">
					<?php
					$formats = array(
						'avif' => array( 'AVIF', 'Smallest files. Chrome, Edge, Firefox, Safari 16+.' ),
						'webp' => array( 'WebP', 'For browsers without AVIF. Supported almost everywhere.' ),
					);
					foreach ( $formats as $key => $f ) :
						?>
						<label class="ehio-tile fmt-<?php echo esc_attr( $key ); ?>">
							<input type="checkbox" name="ehio[formats][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['formats'], true ) ); ?>>
							<span class="ehio-tile-body"><strong><?php echo esc_html( $f[0] ); ?></strong><span><?php echo esc_html( $f[1] ); ?></span></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="ehio-field">
				<div class="ehio-label"><h3>Conversion strategy</h3><p>How hard to compress. Copies that end up larger than the original are never used.</p></div>
				<div class="ehio-control">
					<div class="ehio-segments" role="radiogroup">
						<?php foreach ( $strategies as $key => $st ) : ?>
							<label class="ehio-seg">
								<input type="radio" name="ehio[strategy]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $s['strategy'], $key ); ?>>
								<span>
									<strong><?php echo esc_html( $st[0] ); ?></strong>
									<em><?php echo esc_html( $st[1] ); ?></em>
									<?php if ( isset( EHIO_Settings::STRATEGIES[ $key ] ) ) : ?>
										<small><b class="a">AVIF <?php echo (int) EHIO_Settings::STRATEGIES[ $key ]['avif']; ?></b> <b class="w">WebP <?php echo (int) EHIO_Settings::STRATEGIES[ $key ]['webp']; ?></b></small>
									<?php endif; ?>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
					<div class="ehio-custom" data-ehio="custom" <?php echo $s['strategy'] === 'custom' ? '' : 'hidden'; ?>>
						<label><span class="a">AVIF quality</span><input type="range" min="20" max="90" name="ehio[quality_avif]" value="<?php echo (int) $s['quality_avif']; ?>" oninput="this.nextElementSibling.value=this.value"><output><?php echo (int) $s['quality_avif']; ?></output></label>
						<label><span class="w">WebP quality</span><input type="range" min="40" max="100" name="ehio[quality_webp]" value="<?php echo (int) $s['quality_webp']; ?>" oninput="this.nextElementSibling.value=this.value"><output><?php echo (int) $s['quality_webp']; ?></output></label>
						<p class="ehio-hint">AVIF looks like WebP at a lower number: AVIF 50 ≈ WebP 80.</p>
					</div>
				</div>
			</div>

			<div class="ehio-field" id="ehio-folders">
				<div class="ehio-label"><h3>Folders</h3><p>Where to look for images during bulk optimization.</p></div>
				<div class="ehio-control ehio-checks">
					<label class="is-fixed"><input type="checkbox" checked disabled><span><strong>Media Library</strong><em>Always included. New uploads are converted automatically.</em></span></label>
					<?php foreach ( $dirs as $root => $label ) : ?>
						<label><input type="checkbox" name="ehio[dirs][]" value="<?php echo esc_attr( $root ); ?>" <?php checked( in_array( $root, $s['dirs'], true ) ); ?>>
							<span><strong><?php echo esc_html( $label ); ?></strong><code>wp-content/<?php echo esc_html( $root ); ?></code>
							<em><?php echo esc_html( $descs[ $root ] ?? '' ); ?><?php echo isset( $scan['counts'][ $root ] ) ? ' ' . esc_html( sprintf( _n( '%s image found in the last scan.', '%s images found in the last scan.', (int) $scan['counts'][ $root ], 'ehowme-image-optimizer' ), number_format_i18n( $scan['counts'][ $root ] ) ) ) : ''; ?></em></span></label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="ehio-field">
				<div class="ehio-label"><h3>Maximum image size</h3><p>Larger images are scaled down in the AVIF/WebP copies, keeping the aspect ratio. The original file is not touched.</p></div>
				<div class="ehio-control">
					<div class="ehio-dims">
						<label><span>Width</span><input type="number" min="0" step="1" name="ehio[max_width]" value="<?php echo $s['max_width'] ? (int) $s['max_width'] : ''; ?>" placeholder="No limit"><i>px</i></label>
						<b>×</b>
						<label><span>Height</span><input type="number" min="0" step="1" name="ehio[max_height]" value="<?php echo $s['max_height'] ? (int) $s['max_height'] : ''; ?>" placeholder="No limit"><i>px</i></label>
					</div>
					<p class="ehio-hint">1920 px wide covers full-width banners on most screens. Leave empty for no limit.</p>
				</div>
			</div>

			<div class="ehio-field">
				<div class="ehio-label"><h3>New images</h3><p>What happens when an image is uploaded, edited or its sizes are regenerated.</p></div>
				<div class="ehio-control">
					<label class="ehio-switch"><input type="checkbox" name="ehio[auto_convert]" value="1" <?php checked( ! empty( $s['auto_convert'] ) ); ?>><span></span>Convert automatically right after upload</label>
					<p class="ehio-hint">When off, new images wait until the next bulk optimization.</p>
				</div>
			</div>


			<div class="ehio-card-foot"><button type="submit" class="button button-primary">Save settings</button></div>
		</form>
		<?php
	}

	/** Images wait but the worker has not fetched work for a while: likely a firewall block. */
	private static function render_stuck_warning( $p, $connected ) {
		$last_seen = (int) get_option( EHIO_Settings::LAST_SEEN, 0 );
		if ( $connected && $p['queue'] && EHIO_Connection::is_paused() ) {
			?>
			<section class="ehio-card ehio-stuck">
				<span class="dashicons dashicons-controls-pause"></span>
				<div>
					<h2>The worker is paused for this site</h2>
					<p><?php echo esc_html( sprintf( _n( '%s image is waiting. It is converted once the site is resumed in the worker dashboard.', '%s images are waiting. They are converted once the site is resumed in the worker dashboard.', $p['queue'], 'ehowme-image-optimizer' ), number_format_i18n( $p['queue'] ) ) ); ?></p>
				</div>
			</section>
			<?php
			return;
		}
		if ( ! $connected || ! $p['queue'] || ( $last_seen && time() - $last_seen < 15 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		?>
		<section class="ehio-card ehio-stuck">
			<span class="dashicons dashicons-warning"></span>
			<div>
				<h2>The worker has not fetched work <?php echo $last_seen ? esc_html( 'for ' . human_time_diff( $last_seen ) ) : 'yet'; ?></h2>
				<p><?php echo esc_html( sprintf( '%s images are waiting. The worker may be blocked by Cloudflare, a security plugin or the host, or it may be offline.', number_format_i18n( $p['queue'] ) ) ); ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'advanced', self::page_url() ) . '#ehio-firewall' ); ?>">How to let it through</a></p>
			</div>
		</section>
		<?php
	}

	private static function render_advanced( $s ) {
		$installed = EHIO_Htaccess::is_installed() && EHIO_Htaccess::content_rules_ok();
		?>
		<form class="ehio-card ehio-settings" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ehio_action">
			<input type="hidden" name="do" value="save">
			<input type="hidden" name="tab" value="advanced">
			<?php wp_nonce_field( 'ehio_save' ); ?>
			<div class="ehio-card-head"><div><h2>Advanced Settings</h2><p>Only change these if your CDN or server needs it.</p></div></div>

			<div class="ehio-field">
				<div class="ehio-label"><h3>CDN caching</h3><p>How images are cached by a CDN in front of the site (Cloudflare, BunnyCDN…).</p></div>
				<div class="ehio-control">
					<label class="ehio-switch"><input type="checkbox" name="ehio[cdn_vary]" value="1" <?php checked( ! empty( $s['cdn_vary'] ) ); ?>><span></span>My CDN keeps a separate copy per browser format</label>
					<p class="ehio-hint">Turn on only for Cloudflare Pro or higher with "Vary for Images", or BunnyCDN. Leave off for Cloudflare Free and most hosts: images are then sent with <code>Cache-Control: private</code>, so the CDN passes them through and every browser gets the right format. Browsers still cache images for 7 days.</p>
				</div>
			</div>

			<div class="ehio-field" id="ehio-firewall">
				<div class="ehio-label"><h3>Firewall &amp; bot protection</h3><p>The worker talks to this site from a server, not a browser. Bot protection may block it.</p></div>
				<div class="ehio-control ehio-fw">
					<p>The worker calls <code>/wp-json/ehio/</code> and downloads originals with <code>?ehio=src</code>. Every API request is signed, and unsigned requests are rejected, so letting these two paths through your firewall is safe.</p>
					<h4>Cloudflare</h4>
					<ol>
						<li>Security → WAF → Custom rules → <strong>Create rule</strong>, name it "eHowMe Image Optimizer".</li>
						<li>Choose <strong>Edit expression</strong> and paste:
							<textarea readonly rows="2" class="widefat code" onclick="this.select()">(starts_with(http.request.uri.path, "/wp-json/ehio/")) or (http.request.uri.query contains "rest_route=/ehio/") or (http.request.uri.query contains "ehio=src")</textarea></li>
						<li>Action <strong>Skip</strong>, tick every option it offers (remaining custom rules, rate limiting, managed rules, and Super Bot Fight Mode on paid plans), then <strong>Deploy</strong>.</li>
						<li>Free plan: <strong>Bot Fight Mode</strong> (Security → Bots) cannot be skipped by a rule. Keep it off on sites that use this plugin.</li>
					</ol>
					<h4>Security plugins and the host</h4>
					<ul>
						<li>Wordfence: Firewall → Allowlisted URLs → add <code>/wp-json/ehio/</code>.</li>
						<li>Plugins that disable or restrict the REST API must allow the <code>ehio/v1</code> namespace.</li>
						<li>If the host still answers 403 or 429, ask them to allow the worker's IP address (hPanel → Security / IP Manager on Hostinger).</li>
					</ul>
					<p class="ehio-hint">Signs of a block: the worker dashboard shows HTTP 403, 429 or "Blocked by Cloudflare", or "Last job fetch" in Connection stops moving while images wait in the queue.</p>
				</div>
			</div>

			<div class="ehio-field">
				<div class="ehio-label"><h3>Delivery rules</h3><p>The rewrite rules that send AVIF/WebP instead of the original.</p></div>
				<div class="ehio-control">
					<p class="ehio-state <?php echo $installed ? 'is-ok' : 'is-warn'; ?>"><i></i><?php echo $installed ? 'Installed in .htaccess' : 'Not installed'; ?></p>
					<p class="ehio-hint">Written automatically on save. Rewrite them after moving the site or if another plugin overwrote <code>.htaccess</code>.</p>
					<p><a class="button" href="<?php echo esc_url( add_query_arg( 'tab', 'advanced', self::action_url( 'htaccess' ) ) ); ?>">Rewrite delivery rules</a></p>
					<details class="ehio-nginx">
						<summary>nginx configuration (servers that ignore .htaccess)</summary>
						<textarea readonly rows="12" class="widefat code"><?php echo esc_textarea( EHIO_Htaccess::nginx_snippet() ); ?></textarea>
					</details>
				</div>
			</div>

			<div class="ehio-card-foot"><button type="submit" class="button button-primary">Save settings</button></div>
		</form>
		<?php
	}

	private static function render_connection( $s, $conn ) {
		$has       = EHIO_Settings::has_token();
		$last_seen = (int) get_option( EHIO_Settings::LAST_SEEN, 0 );
		?>
		<section class="ehio-card ehio-conn">
			<h2>Connection</h2>
			<?php
			// Messages saved by older versions may still contain the worker address.
			$message = $conn['status'] === 'active' ? 'Images are converted by your eHowMe worker.' : (string) $conn['message'];
			if ( EHIO_Connection::is_paused() ) {
				$message = 'Paused in the worker dashboard: no new images are fetched until it is resumed there.';
			}
			$host    = $s['worker_url'] ? (string) wp_parse_url( $s['worker_url'], PHP_URL_HOST ) : '';
			if ( $host !== '' ) {
				$message = str_ireplace( array( $s['worker_url'], $host ), 'worker', $message );
			}
			?>
			<?php if ( $message ) : ?>
				<p class="ehio-hint"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>
			<?php if ( $has ) : ?>
				<dl>
					<dt>Site ID</dt><dd><?php echo esc_html( $s['site_id'] ); ?></dd>
					<dt>Access token</dt><dd><code><?php echo esc_html( EHIO_Settings::masked_token() ); ?></code></dd>
					<dt>Last job fetch</dt><dd><?php echo $last_seen ? esc_html( human_time_diff( $last_seen ) . ' ago' ) : 'never'; ?></dd>
				</dl>
				<p class="ehio-actions">
					<a class="button" href="<?php echo esc_url( self::action_url( 'check' ) ); ?>">Check connection</a>
					<a class="button ehio-danger" href="<?php echo esc_url( self::action_url( 'disconnect' ) ); ?>" onclick="return confirm('Remove the token from this site? New uploads will not be converted until a token is added again. Converted images stay in place.');">Disconnect</a>
				</p>
			<?php endif; ?>
			<details <?php echo $has ? '' : 'open'; ?> class="ehio-token">
				<summary><?php echo $has ? 'Replace access token' : 'Add access token'; ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ehio_action">
					<input type="hidden" name="do" value="connect">
					<?php wp_nonce_field( 'ehio_connect' ); ?>
					<input name="ehio_token" type="password" class="widefat code" autocomplete="off" placeholder="ehio_…" required>
					<button class="button button-primary" type="submit">Connect</button>
					<p class="ehio-hint">Issued in the worker dashboard (Add site). A token only works on the site it was issued for.</p>
				</form>
			</details>
		</section>
		<?php
	}

	/** Shown only when delivery cannot work as is. */
	private static function render_delivery_problems() {
		$nginx     = EHIO_Htaccess::looks_like_nginx();
		$installed = EHIO_Htaccess::is_installed() && EHIO_Htaccess::content_rules_ok();
		if ( $installed && ! $nginx ) {
			return;
		}
		?>
		<section class="ehio-card ehio-problem">
			<h2>Delivery needs attention</h2>
			<?php if ( $nginx ) : ?>
				<p>This server runs nginx, which ignores <code>.htaccess</code>. Ask your host to add this to the site's nginx config:</p>
				<textarea readonly rows="10" class="widefat code"><?php echo esc_textarea( EHIO_Htaccess::nginx_snippet() ); ?></textarea>
			<?php else : ?>
				<p>The delivery rules could not be written to <code><?php echo esc_html( EHIO_Htaccess::is_installed() ? EHIO_Htaccess::content_htaccess_file() : EHIO_Htaccess::upload_htaccess_file() ); ?></code>, so browsers still get the originals. Check that WordPress can write to that file.</p>
				<p><a class="button button-primary" href="<?php echo esc_url( self::action_url( 'htaccess' ) ); ?>">Write the rules</a></p>
			<?php endif; ?>
		</section>
		<?php
	}

	// ------------------------------------------------------------ styles/script

	private static function styles() {
		?>
		<style>
		.ehio{--ink:#151620;--slate:#5B6B84;--muted:#7D879A;--line:#E3E8EF;--soft:#F0F6FC;--brand:#2254C5;--brand-soft:#E6EDFA;--webp:#F7912A;--webp-soft:#FEF0E1;--ok:#1E7B4F;--ok-soft:#E3F3EA;--warn:#9A5B00;--warn-soft:#FDF1DC;--err:#B42318;--err-soft:#FBE5E2;color:var(--ink);max-width:none;margin-right:20px}
		.ehio *{box-sizing:border-box}
		.ehio-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:12px 0 18px;flex-wrap:wrap}
		.ehio-brand{display:flex;align-items:center;gap:12px}
		.ehio-mark{width:56px;height:56px;flex:none;border-radius:14px}
		.ehio .ehio-title{font-size:22px;line-height:1.2;font-weight:600;margin:0;padding:0;color:var(--ink)}
		.ehio-sub{color:var(--muted);font-size:13px}
		.ehio-sub a{color:var(--slate)}
		.ehio-pill,.ehio-state{display:inline-flex;align-items:center;gap:8px;font-weight:500;border-radius:99px;padding:5px 12px;font-size:13px;margin:0}
		.ehio-pill i,.ehio-state i{width:8px;height:8px;border-radius:50%;background:currentColor}
		.is-ok{background:var(--ok-soft);color:var(--ok)}.is-warn{background:var(--warn-soft);color:var(--warn)}.is-err{background:var(--err-soft);color:var(--err)}
		.ehio-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:24px;align-items:start}
		.ehio-main,.ehio-side{display:grid;gap:20px}
		.ehio-side{margin-top:51px}
		.ehio-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:22px 24px}
		.ehio-card h2{font-size:16px;margin:0 0 4px;color:var(--ink)}
		.ehio-card-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}
		.ehio-card-head p{margin:0;color:var(--slate);max-width:62ch}
		.ehio-hint{color:var(--muted);font-size:12.5px;margin:6px 0 0}
		.ehio-right{text-align:right}
		.ehio-live{display:inline-flex;align-items:center;gap:8px;color:var(--brand);background:var(--brand-soft);border-radius:99px;padding:4px 12px;font-weight:500;white-space:nowrap}
		.ehio-live i{width:8px;height:8px;border-radius:50%;background:var(--brand);animation:ehio-pulse 1.4s ease-in-out infinite}
		@keyframes ehio-pulse{50%{opacity:.25}}
		.ehio-live[hidden]{display:none}
		.ehio-live.is-paused{color:var(--warn);background:var(--warn-soft)}.ehio-live.is-paused i{background:var(--warn);animation:none}
		.ehio-bulk-body{display:grid;grid-template-columns:160px minmax(0,1fr);gap:28px;align-items:center}
		.ehio-ring{position:relative;width:160px;height:160px}
		.ehio-ring svg{width:100%;height:100%;transform:rotate(-90deg)}
		.ehio-ring circle{fill:none;stroke-width:12}
		.ehio-ring .track{stroke:var(--brand-soft)}
		.ehio-ring .bar{stroke:var(--brand);stroke-linecap:round;transition:stroke-dashoffset .8s ease}
		.ehio-ring-label{position:absolute;inset:0;display:grid;place-content:center;text-align:center}
		.ehio-ring-label strong{font-size:32px;line-height:1;font-weight:650;letter-spacing:-.02em}
		.ehio-ring-label span{color:var(--muted);font-size:12px;margin-top:4px}
		.ehio-count{font-size:15px;margin:0 0 12px;color:var(--slate)}
		.ehio-count strong{color:var(--ink);font-size:20px}
		.ehio-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px;padding:0;list-style:none}
		.ehio-chips li{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:8px;margin:0;color:var(--slate);overflow:hidden;transition:border-color .15s}
		.ehio-chips li:hover{border-color:#B9C6DA}
		.ehio-chips li>a:first-child{display:inline-flex;align-items:center;gap:7px;padding:6px 10px;color:inherit;text-decoration:none}
		.ehio-chips li>a:first-child:hover{background:var(--soft);color:var(--ink)}
		.ehio-chips li>a:first-child:focus{box-shadow:none;outline:2px solid var(--brand);outline-offset:-2px}
		.ehio-chips strong{color:var(--ink)}
		.ehio-chips .ehio-retry{padding:6px 10px 6px 8px;border-left:1px solid var(--line);font-weight:500}
		.ehio-chips .ehio-retry[hidden]{display:none}
		.ehio-count a{text-decoration:none}
		.ehio-count a:hover strong{color:var(--brand)}
		.ehio-bulk-stats>.ehio-hint{margin:-8px 0 14px}
		.dot{width:8px;height:8px;border-radius:50%;display:inline-block}.dot.q{background:var(--brand)}.dot.r{background:#C3CBD8}.dot.f{background:var(--err)}.dot.x{background:var(--muted)}
		.ehio-savings{background:var(--soft);border-radius:10px;padding:14px 16px}
		.ehio-savings[hidden]{display:none}
		.ehio-savings-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;color:var(--slate)}
		.ehio-savings-head strong{color:var(--ink);font-size:16px}
		.ehio-badge{background:var(--ok-soft);color:var(--ok);font-weight:600;border-radius:6px;padding:2px 8px}
		.ehio-bars .row{display:grid;grid-template-columns:70px minmax(0,1fr) 72px;gap:10px;align-items:center;margin:4px 0;font-size:12.5px;color:var(--slate)}
		.ehio-bars .b{height:8px;background:#fff;border-radius:4px;overflow:hidden}
		.ehio-bars i{display:block;height:100%;background:#C3CBD8;border-radius:4px}
		.ehio-bars i.opt{background:var(--brand);transition:width .8s ease}
		.ehio-bars em{font-style:normal;text-align:right;color:var(--ink)}
		.ehio-sources{margin-top:22px;border:1px solid var(--line);border-radius:10px;overflow:hidden}
		.ehio-sources-head{display:flex;justify-content:space-between;background:var(--soft);padding:10px 14px;font-weight:600;font-size:13px}
		.ehio-sources-head a{font-weight:400}
		.ehio-sources ul{margin:0;padding:0;list-style:none}
		.ehio-sources li{display:grid;grid-template-columns:22px minmax(0,1fr) auto 120px;gap:10px;align-items:center;padding:10px 14px;margin:0;border-top:1px solid var(--line)}
		.ehio-sources li .dashicons{color:var(--slate);font-size:18px}
		.ehio-sources .meta{color:var(--muted);font-variant-numeric:tabular-nums}
		.ehio-sources .mini{height:6px;background:var(--brand-soft);border-radius:3px;overflow:hidden}
		.ehio-sources .mini i{display:block;height:100%;background:var(--brand)}
		.ehio-sources li .meta:last-child{grid-column:3/5;text-align:right}
		.ehio-bulk-foot{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-top:22px;flex-wrap:wrap}
		.ehio .button-hero.ehio-start{font-size:14px;min-height:42px;padding:0 22px;line-height:40px}
		.ehio-bulk-buttons{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
		.ehio-bulk-buttons form{margin:0}
		.ehio-bulk-buttons form[hidden]{display:none}
		.ehio .button-hero.ehio-stop{font-size:14px;min-height:42px;padding:0 18px;line-height:40px;color:var(--err);border-color:#E9B4AE;background:#fff;display:inline-flex;align-items:center;gap:6px}
		.ehio .button-hero.ehio-stop:hover{background:var(--err-soft);border-color:var(--err);color:var(--err)}
		.ehio .button-hero.ehio-stop:focus{box-shadow:0 0 0 2px #fff,0 0 0 4px var(--err)}
		.ehio-stop .dashicons{font-size:18px;width:18px;height:18px}
		.ehio-switch{display:inline-flex;align-items:center;gap:10px;cursor:pointer;color:var(--ink)}
		.ehio-switch em{color:var(--muted);font-style:normal}
		.ehio-switch input{position:absolute;opacity:0;width:1px;height:1px}
		.ehio-switch span{width:36px;height:20px;border-radius:99px;background:#C3CBD8;position:relative;flex:none;transition:background .2s}
		.ehio-switch span::after{content:"";position:absolute;top:3px;left:3px;width:14px;height:14px;border-radius:50%;background:#fff;transition:transform .2s}
		.ehio-switch input:checked+span{background:var(--brand)}
		.ehio-switch input:checked+span::after{transform:translateX(16px)}
		.ehio-switch input:focus-visible+span{outline:2px solid var(--brand);outline-offset:2px}
		.ehio-field{display:grid;grid-template-columns:230px minmax(0,1fr);gap:24px;padding:20px 0;border-top:1px solid var(--line)}
		.ehio-label h3{font-size:14px;margin:0 0 4px}
		.ehio-label p{margin:0;color:var(--muted);font-size:12.5px}
		.ehio-tiles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
		.ehio-tile{position:relative;cursor:pointer}
		.ehio-tile input{position:absolute;top:14px;right:14px}
		.ehio-tile-body{display:block;border:1.5px solid var(--line);border-radius:10px;padding:14px 16px;height:100%}
		.ehio-tile-body strong{display:block;font-size:15px;margin-bottom:4px}
		.ehio-tile-body span{color:var(--slate);font-size:12.5px}
		.fmt-avif strong{color:var(--brand)}.fmt-webp strong{color:#C76A0E}
		.fmt-avif input:checked+.ehio-tile-body{border-color:var(--brand);background:var(--brand-soft)}
		.fmt-webp input:checked+.ehio-tile-body{border-color:var(--webp);background:var(--webp-soft)}
		.ehio-segments{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
		.ehio-seg{cursor:pointer}
		.ehio-seg input{position:absolute;opacity:0}
		.ehio-seg>span{display:flex;flex-direction:column;gap:4px;height:100%;border:1.5px solid var(--line);border-radius:10px;padding:12px}
		.ehio-seg strong{font-size:13.5px}
		.ehio-seg em{font-style:normal;color:var(--slate);font-size:12px;line-height:1.4}
		.ehio-seg small{margin-top:auto;display:flex;gap:6px;flex-wrap:wrap}
		.ehio-seg small b,.ehio-custom span.a,.ehio-custom span.w{font-weight:500;font-size:11px;border-radius:4px;padding:1px 6px}
		b.a,span.a{background:var(--brand-soft);color:var(--brand)}b.w,span.w{background:var(--webp-soft);color:#A65A0B}
		.ehio-seg input:checked+span{border-color:var(--brand);box-shadow:inset 0 0 0 1px var(--brand)}
		.ehio-seg input:focus-visible+span{outline:2px solid var(--brand);outline-offset:2px}
		.ehio-custom{margin-top:14px;display:grid;gap:10px;max-width:520px}
		.ehio-custom[hidden]{display:none}
		.ehio-custom label{display:grid;grid-template-columns:110px minmax(0,1fr) 36px;align-items:center;gap:12px}
		.ehio-custom span.a,.ehio-custom span.w{font-size:12px;padding:3px 8px;justify-self:start}
		.ehio-custom input[type=range]{accent-color:var(--brand)}
		.ehio-custom output{font-variant-numeric:tabular-nums;font-weight:600;text-align:right}
		.ehio-checks{display:grid;gap:10px}
		.ehio-checks label{display:flex;gap:10px;align-items:flex-start;cursor:pointer}
		.ehio-checks label input{margin-top:2px}
		.ehio-checks span{display:flex;flex-wrap:wrap;gap:2px 8px;align-items:baseline}
		.ehio-checks code{font-size:11px;background:var(--soft);color:var(--slate)}
		.ehio-checks em{flex-basis:100%;font-style:normal;color:var(--muted);font-size:12.5px}
		.ehio-checks .is-fixed{cursor:default}
		.ehio-dims{display:flex;align-items:flex-end;gap:12px}
		.ehio-dims label{display:grid;gap:4px;font-size:12px;color:var(--slate);position:relative}
		.ehio-dims input{width:140px;padding-right:30px}
		.ehio-dims i{position:absolute;right:10px;bottom:7px;font-style:normal;color:var(--muted)}
		.ehio-dims b{padding-bottom:7px;color:var(--muted)}
		.ehio-tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin-bottom:-8px}
		.ehio-tabs a{padding:10px 18px;border:1px solid transparent;border-bottom:0;border-radius:10px 10px 0 0;text-decoration:none;color:var(--slate);font-weight:500;font-size:14px;margin-bottom:-1px}
		.ehio-tabs a:hover{color:var(--brand)}
		.ehio-tabs a.is-active{background:#fff;border-color:var(--line);color:var(--ink)}
		.ehio-tabs a:focus{box-shadow:none;outline:2px solid var(--brand);outline-offset:-2px}
		.ehio-nginx{margin-top:12px}
		.ehio-fw p{margin:0 0 10px;color:var(--slate)}
		.ehio-fw h4{margin:14px 0 6px;font-size:13px}
		.ehio-fw ol,.ehio-fw ul{margin:0 0 8px 18px;color:var(--slate)}
		.ehio-fw ul{list-style:disc}
		.ehio-fw li{margin:4px 0}
		.ehio-fw textarea{margin-top:6px;font-size:12px;resize:none}
		.ehio-stuck{border-color:#F0C27B;background:#FFFBF2;display:flex;gap:12px;align-items:flex-start}
		.ehio-stuck .dashicons{color:var(--warn);margin-top:2px}
		.ehio-stuck p{margin:2px 0 0;color:var(--slate)}
		.ehio-nginx summary{cursor:pointer;color:var(--brand)}
		.ehio-nginx textarea{margin-top:8px;font-size:12px}
		.ehio-card-foot{border-top:1px solid var(--line);padding-top:18px;display:flex;justify-content:flex-end}
		.ehio-conn dl{display:grid;grid-template-columns:auto minmax(0,1fr);gap:6px 12px;margin:14px 0;font-size:12.5px}
		.ehio-conn dt{color:var(--muted)}.ehio-conn dd{margin:0;overflow-wrap:anywhere}
		.ehio-conn code{font-size:11.5px}
		.ehio-actions{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px}
		.ehio .ehio-danger{color:var(--err);border-color:#E9B4AE}
		.ehio-token{border-top:1px solid var(--line);padding-top:12px}
		.ehio-token summary{cursor:pointer;color:var(--brand);font-weight:500}
		.ehio-token form{display:grid;gap:8px;margin-top:10px}
		.ehio-token .button{justify-self:start}
		.ehio-problem{border-color:#F0C27B;background:#FFFBF2}
		.ehio-import{display:grid;grid-template-columns:44px minmax(0,1fr);gap:16px;border-color:#BFD0F2;background:linear-gradient(180deg,#F5F8FE,#fff)}
		.ehio-import.is-done{border-color:#BFE3CD;background:#F6FBF8}
		.ehio-import-icon{width:44px;height:44px;border-radius:10px;background:var(--brand-soft);display:grid;place-items:center;color:var(--brand)}
		.ehio-import.is-done .ehio-import-icon{background:var(--ok-soft);color:var(--ok)}
		.ehio-import-icon .dashicons{font-size:24px;width:24px;height:24px}
		.ehio-import p{margin:4px 0 0;color:var(--slate)}
		.ehio-steps{margin:12px 0 16px 18px;color:var(--slate)}
		.ehio-steps li{margin:4px 0;padding-left:4px}
		.ehio-steps li.is-ok::marker{color:var(--ok)}
		.ehio-steps li.is-todo{color:var(--ink)}
		.ehio-tips p{margin:6px 0 0;color:var(--slate)}
		@media (max-width:1100px){.ehio-grid{grid-template-columns:1fr}.ehio-side{margin-top:0}.ehio-segments{grid-template-columns:repeat(2,minmax(0,1fr))}}
		@media (max-width:782px){.ehio-field{grid-template-columns:1fr;gap:12px}.ehio-bulk-body{grid-template-columns:1fr;justify-items:center}.ehio-bulk-stats{width:100%}.ehio-tiles{grid-template-columns:1fr}.ehio-sources li{grid-template-columns:22px minmax(0,1fr) auto}.ehio-sources .mini{display:none}.ehio-card{padding:18px}}
		@media (prefers-reduced-motion:reduce){.ehio *{transition:none!important;animation:none!important}}
		</style>
		<?php
	}

	private static function script( $p ) {
		?>
		<script>
		(function () {
			var root = document.querySelector('.ehio');
			if (!root) { return; }
			var q = function (k) { return root.querySelector('[data-ehio="' + k + '"]'); };
			var fmt = function (n) { return Number(n).toLocaleString(); };

			// Show the custom quality sliders only for the Custom strategy.
			root.querySelectorAll('input[name="ehio[strategy]"]').forEach(function (r) {
				r.addEventListener('change', function () { q('custom').hidden = r.value !== 'custom' || !r.checked; });
			});

			var circ = parseFloat(q('ring').getAttribute('stroke-dasharray'));
			function paint(d) {
				q('ring').setAttribute('stroke-dashoffset', (circ * (1 - d.percent / 100)).toFixed(2));
				q('percent').textContent = d.percent + '%';
				q('done').textContent = fmt(d.done);
				q('total').textContent = fmt(d.total);
				q('queue').textContent = d.queue;
				q('remaining').textContent = d.remaining;
				q('failed').textContent = d.failed;
				q('excluded').textContent = d.excluded;
				q('retry').hidden = !d.failed;
				q('live').hidden = !d.queue;
				q('stop').hidden = !d.queue;
				q('savings').hidden = !d.orig;
				q('saved').textContent = d.saved_h;
				q('savedpct').textContent = '−' + d.saved_pct + '%';
				q('orig').textContent = d.orig_h;
				q('deliv').textContent = d.deliv_h;
				q('delivbar').style.width = Math.max(1, 100 - d.saved_pct) + '%';
				d.sources.forEach(function (s, i) {
					var li = q('sources').children[i];
					if (!li) { return; }
					var meta = li.querySelector('.meta'), bar = li.querySelector('.mini i');
					if (bar) {
						meta.textContent = fmt(s.done) + ' / ' + fmt(s.total);
						bar.style.width = (s.total ? Math.floor(s.done * 100 / s.total) : 0) + '%';
					}
				});
			}

			var busy = <?php echo $p['queue'] ? 'true' : 'false'; ?>, idleChecks = 0;
			function poll() {
				var body = new URLSearchParams({ action: 'ehio_progress', _ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'ehio_progress' ) ); ?>' });
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
					.then(function (r) { return r.json(); })
					.then(function (r) {
						if (!r || !r.success) { return; }
						paint(r.data);
						busy = r.data.queue > 0;
						idleChecks = busy ? 0 : idleChecks + 1;
					})
					.catch(function () {})
					.finally(function () {
						// Fast while converting; after the queue empties, check a few more times, then stop.
						if (busy || idleChecks < 3) { setTimeout(poll, busy ? 4000 : 8000); }
					});
			}
			if (busy) { setTimeout(poll, 4000); }
		})();
		</script>
		<?php
	}
}
