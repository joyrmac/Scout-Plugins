<?php
/**
 * Batch sweep of the existing media library (Stage E).
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimizes images already in the library, in the background.
 *
 * Admin-triggered and confirmation-gated. A WP-Cron event processes images in
 * small, time-boxed batches and reschedules itself until the library is done,
 * so it never times out. Each image runs through the same processor as new
 * uploads (original backed up, then optimized), and existing dimensions are
 * left untouched (no resize), so live srcset/metadata are never altered.
 * Idempotent: already-processed attachments are skipped.
 */
final class Scout_Optimize_Batch {

	/**
	 * Cron hook that runs a batch.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'scout_optimize_run_batch';

	/**
	 * Option flag set while a sweep is running.
	 *
	 * @var string
	 */
	const RUNNING_OPTION = 'scout_optimize_sweep_running';

	/**
	 * Attachment meta key holding the optimization summary.
	 *
	 * @var string
	 */
	const META_KEY = '_scout_optimize';

	/**
	 * MIME types the sweep processes.
	 *
	 * @var string[]
	 */
	const SUPPORTED = array( 'image/jpeg', 'image/png', 'image/gif' );

	/**
	 * Seconds to spend processing per cron run before rescheduling.
	 *
	 * @var int
	 */
	const RUN_SECONDS = 20;

	/**
	 * Wire hooks: admin page, start/stop handlers, and the cron worker.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
		add_action( 'admin_post_scout_optimize_sweep_start', array( $this, 'handle_start' ) );
		add_action( 'admin_post_scout_optimize_sweep_stop', array( $this, 'handle_stop' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_batch' ) );
	}

	/**
	 * Register the Scout-gated sweep admin page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		// Inside the Scout menu this screen opens from a button on Image optimizer, so
		// the sidebar keeps one entry per feature. Under Tools it stays listed.
		$parent = Scout_Optimize::parent();
		add_submenu_page(
			'tools.php' === $parent ? $parent : '',
			__( 'Shrink older photos', 'scout-optimize' ),
			__( 'Shrink older photos', 'scout-optimize' ),
			SCOUT_OPTIMIZE_CAP,
			'scout-optimize-sweep',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle the "Start sweep" form.
	 *
	 * @return void
	 */
	public function handle_start(): void {
		if ( ! current_user_can( SCOUT_OPTIMIZE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'scout-optimize' ) );
		}
		check_admin_referer( 'scout_optimize_sweep' );

		update_option( self::RUNNING_OPTION, time() );
		$this->schedule_next();
		$this->redirect();
	}

	/**
	 * Handle the "Stop sweep" form.
	 *
	 * @return void
	 */
	public function handle_stop(): void {
		if ( ! current_user_can( SCOUT_OPTIMIZE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'scout-optimize' ) );
		}
		check_admin_referer( 'scout_optimize_sweep' );

		delete_option( self::RUNNING_OPTION );
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		$this->redirect();
	}

	/**
	 * Cron worker: process images in a time-boxed batch, then reschedule.
	 *
	 * @return void
	 */
	public function run_batch(): void {
		if ( ! get_option( self::RUNNING_OPTION ) ) {
			return;
		}

		$deadline = time() + self::RUN_SECONDS;
		while ( time() < $deadline ) {
			$ids = $this->unprocessed_ids( 1 );
			if ( empty( $ids ) ) {
				delete_option( self::RUNNING_OPTION ); // Library is fully swept.
				return;
			}
			$this->process_attachment( (int) $ids[0] );
		}

		$this->schedule_next();
	}

	/**
	 * Optimize one existing attachment, recording the summary.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return void
	 */
	private function process_attachment( int $attachment_id ): void {
		if ( get_post_meta( $attachment_id, self::META_KEY, true ) ) {
			return;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::SUPPORTED, true ) ) {
			update_post_meta( $attachment_id, self::META_KEY, array( 'status' => 'skipped_unsupported' ) );
			return;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			update_post_meta( $attachment_id, self::META_KEY, array( 'status' => 'skipped_no_metadata' ) );
			return;
		}

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$base_dir      = trailingslashit( $uploads['basedir'] );
		$original_path = $base_dir . $meta['file'];
		$subdir        = trailingslashit( dirname( $meta['file'] ) );

		$size_paths = array();
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$size_paths[] = $base_dir . $subdir . $size['file'];
				}
			}
		}

		$settings      = get_option( 'scout_optimize_settings', array() );
		$preset        = isset( $settings['preset'] ) ? (string) $settings['preset'] : 'balanced';
		$generate_webp = ! empty( $settings['generate_webp'] );
		$generate_avif = ! empty( $settings['generate_avif'] );

		$originals = new Scout_Optimize_Originals(
			$uploads['basedir'],
			$uploads['basedir'] . '/scout-optimize-originals'
		);
		// Existing images keep their dimensions (null resize ceiling), so stored
		// metadata and srcset are never altered.
		$processor = new Scout_Optimize_Attachment_Processor(
			Scout_Optimize_Engine_Factory::make(),
			$originals,
			$preset,
			null,
			null
		);

		$summary           = $processor->process( $original_path, $size_paths, $mime, $generate_webp, $generate_avif );
		$summary['source'] = 'batch';
		update_post_meta( $attachment_id, self::META_KEY, $summary );
	}

	/**
	 * Query the next unprocessed image attachment IDs.
	 *
	 * @param int $limit How many IDs to return.
	 * @return int[]
	 */
	private function unprocessed_ids( int $limit ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::SUPPORTED,
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Background sweep over the library, not a front-end request.
				'meta_query'     => array(
					array(
						'key'     => self::META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Count images and how many are already optimized.
	 *
	 * @return array{total:int,optimized:int,remaining:int}
	 */
	private function stats(): array {
		$total = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::SUPPORTED,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		$remaining = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::SUPPORTED,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin stats query, not a front-end request.
				'meta_query'     => array(
					array(
						'key'     => self::META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$total_count     = (int) $total->found_posts;
		$remaining_count = (int) $remaining->found_posts;

		return array(
			'total'     => $total_count,
			'optimized' => max( 0, $total_count - $remaining_count ),
			'remaining' => $remaining_count,
		);
	}

	/**
	 * Schedule the next batch run if one is not already queued.
	 *
	 * @return void
	 */
	private function schedule_next(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/**
	 * Redirect back to the sweep page after a form action.
	 *
	 * @return void
	 */
	private function redirect(): void {
		wp_safe_redirect( Scout_Optimize::url( 'scout-optimize-sweep' ) );
		exit;
	}

	/**
	 * Render the sweep admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( SCOUT_OPTIMIZE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'scout-optimize' ) );
		}

		$stats   = $this->stats();
		$running = (bool) get_option( self::RUNNING_OPTION );
		$percent = $stats['total'] > 0 ? (int) round( ( $stats['optimized'] / $stats['total'] ) * 100 ) : 0;
		$action  = admin_url( 'admin-post.php' );
		?>
		<?php Scout_Admin_UI::open( __( 'Shrink older photos', 'scout-optimize' ), __( 'Shrinks photos uploaded before the optimizer was on.', 'scout-optimize' ), __( 'Images', 'scout-optimize' ), 'scout-optimize' ); ?>
			<?php
			// One status line and a meter instead of a row of numbers (DESIGN 2 #5).
			if ( $running ) {
				$state    = 'good';
				$headline = __( 'Running in the background.', 'scout-optimize' );
				$go       = '<button type="submit" form="scout-optimize-stop" class="button">' . esc_html__( 'Stop', 'scout-optimize' ) . '</button>';
			} elseif ( $stats['remaining'] > 0 ) {
				$state = 'off';
				/* translators: %d: images not optimized yet. */
				$headline = sprintf( _n( '%d image to shrink.', '%d images to shrink.', $stats['remaining'], 'scout-optimize' ), $stats['remaining'] );
				$go       = '<button type="submit" form="scout-optimize-start" class="button button-primary">' . esc_html__( 'Start', 'scout-optimize' ) . '</button>';
			} else {
				$state    = 'good';
				$headline = __( 'Every image has been optimized.', 'scout-optimize' );
				$go       = '';
			}
			/* translators: 1: optimized images, 2: all images, 3: percent done. */
			$detail = sprintf( __( '%1$d of %2$d done (%3$d%%).', 'scout-optimize' ), $stats['optimized'], $stats['total'], $percent ) . ( $running ? ' ' . __( 'Reload to see progress.', 'scout-optimize' ) : '' );
			if ( method_exists( 'Scout_Admin_UI', 'status' ) ) {
				Scout_Admin_UI::status( $state, $headline, $detail, $go );
			} else {
				echo '<section class="sh-box"><p class="sh-status is-' . esc_attr( $state ) . '"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html( $headline ) . '</strong> <span>' . esc_html( $detail ) . '</span>' . ( '' !== $go ? ' <span class="sh-status-go">' . $go . '</span>' : '' ) . '</p></section>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $go is built from escaped parts above.
			}
			?>
			<form id="scout-optimize-stop" method="post" action="<?php echo esc_url( $action ); ?>" hidden>
				<?php wp_nonce_field( 'scout_optimize_sweep' ); ?>
				<input type="hidden" name="action" value="scout_optimize_sweep_stop" />
			</form>
			<form id="scout-optimize-start" method="post" action="<?php echo esc_url( $action ); ?>" hidden onsubmit="return confirm('This backs up every original (roughly doubling image storage) and optimizes the library in the background. Continue?');">
				<?php wp_nonce_field( 'scout_optimize_sweep' ); ?>
				<input type="hidden" name="action" value="scout_optimize_sweep_start" />
			</form>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Your media library', 'scout-optimize' ); ?></h2>
				<div class="sh-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $percent; ?>" aria-label="<?php esc_attr_e( 'Images optimized', 'scout-optimize' ); ?>"><span style="width:<?php echo (int) $percent; ?>%"></span></div>
				<p><?php esc_html_e( 'It runs in small batches and backs up each original first, so any image can be put back.', 'scout-optimize' ); ?></p>
				<p class="sh-note"><?php esc_html_e( 'On a large library, check hosting storage first.', 'scout-optimize' ); ?> <a href="<?php echo esc_url( Scout_Optimize::url() ); ?>"><?php esc_html_e( 'Image optimizer', 'scout-optimize' ); ?> &rsaquo;</a></p>
			</section>
		<?php Scout_Admin_UI::close(); ?>
		<?php
	}
}
