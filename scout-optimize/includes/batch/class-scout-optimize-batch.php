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
		add_action( 'admin_menu', array( $this, 'register_page' ) );
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
		add_management_page(
			__( 'Scout Optimize Sweep', 'scout-optimize' ),
			__( 'Scout Optimize Sweep', 'scout-optimize' ),
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
		wp_safe_redirect( admin_url( 'tools.php?page=scout-optimize-sweep' ) );
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
		<div class="wrap">
			<h1><?php esc_html_e( 'Scout Optimize Sweep', 'scout-optimize' ); ?></h1>
			<p style="max-width:760px;">
				<?php esc_html_e( 'Optimize images already in the media library. This runs in the background in small batches, backing up each original before optimizing. New uploads are handled automatically; this is for the existing library.', 'scout-optimize' ); ?>
			</p>

			<table class="widefat striped" style="max-width:420px;">
				<tbody>
					<tr><td><?php esc_html_e( 'Images total', 'scout-optimize' ); ?></td><td><strong><?php echo esc_html( (string) $stats['total'] ); ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Optimized', 'scout-optimize' ); ?></td><td><strong><?php echo esc_html( (string) $stats['optimized'] ); ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Remaining', 'scout-optimize' ); ?></td><td><strong><?php echo esc_html( (string) $stats['remaining'] ); ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Progress', 'scout-optimize' ); ?></td><td><strong><?php echo esc_html( $percent . '%' ); ?></strong></td></tr>
				</tbody>
			</table>

			<p style="margin-top:16px;">
				<?php if ( $running ) : ?>
					<em><?php esc_html_e( 'A sweep is running in the background. Reload this page to see progress.', 'scout-optimize' ); ?></em>
					<br /><br />
					<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline;">
						<?php wp_nonce_field( 'scout_optimize_sweep' ); ?>
						<input type="hidden" name="action" value="scout_optimize_sweep_stop" />
						<button type="submit" class="button"><?php esc_html_e( 'Stop sweep', 'scout-optimize' ); ?></button>
					</form>
				<?php elseif ( $stats['remaining'] > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline;" onsubmit="return confirm('This backs up every original (roughly doubling image storage) and optimizes the library in the background. Continue?');">
						<?php wp_nonce_field( 'scout_optimize_sweep' ); ?>
						<input type="hidden" name="action" value="scout_optimize_sweep_start" />
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Start sweep', 'scout-optimize' ); ?></button>
					</form>
				<?php else : ?>
					<em><?php esc_html_e( 'Every image has been optimized.', 'scout-optimize' ); ?></em>
				<?php endif; ?>
			</p>

			<p style="color:#666;max-width:760px;">
				<?php esc_html_e( 'Each original is preserved in the originals folder before optimizing, so the sweep is reversible per image. Confirm your host storage headroom before running it on a large library.', 'scout-optimize' ); ?>
			</p>
		</div>
		<?php
	}
}
