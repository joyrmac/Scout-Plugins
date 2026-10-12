<?php
/**
 * Core bootstrap for Scout Optimize.
 *
 * The optimization engine, originals store, upload pipeline, WebP/AVIF
 * delivery, and the batch sweep of the existing library are all loaded. The
 * pipeline and delivery register their hooks but are gated by the
 * optimize_uploads and rewrite_picture settings, both default OFF. The batch
 * sweep is admin-triggered and confirmation-gated. Activating this build with
 * default settings still changes nothing on a site.
 *
 * @package Scout_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Engine and originals store.
require_once SCOUT_OPTIMIZE_DIR . 'includes/engine/interface-scout-optimize-engine.php';
require_once SCOUT_OPTIMIZE_DIR . 'includes/engine/class-scout-optimize-request.php';
require_once SCOUT_OPTIMIZE_DIR . 'includes/engine/class-scout-optimize-result.php';
require_once SCOUT_OPTIMIZE_DIR . 'includes/engine/class-scout-optimize-presets.php';
require_once SCOUT_OPTIMIZE_DIR . 'includes/engine/class-scout-optimize-imagick-engine.php';
require_once SCOUT_OPTIMIZE_DIR . 'includes/engine/class-scout-optimize-null-engine.php';
require_once SCOUT_OPTIMIZE_DIR . 'includes/engine/class-scout-optimize-engine-factory.php';
require_once SCOUT_OPTIMIZE_DIR . 'includes/originals/class-scout-optimize-originals.php';

// Upload pipeline (Stage C). Hook registered below; gated by the OFF-by-default flag.
require_once SCOUT_OPTIMIZE_DIR . 'includes/pipeline/class-scout-optimize-attachment-processor.php';
require_once SCOUT_OPTIMIZE_DIR . 'includes/pipeline/class-scout-optimize-pipeline.php';

// WebP delivery (Stage D). Hooks registered below; gated by the OFF-by-default flag.
require_once SCOUT_OPTIMIZE_DIR . 'includes/delivery/class-scout-optimize-picture-builder.php';
require_once SCOUT_OPTIMIZE_DIR . 'includes/delivery/class-scout-optimize-delivery.php';

// Batch sweep of the existing library (Stage E). Admin-triggered; cron worker.
require_once SCOUT_OPTIMIZE_DIR . 'includes/batch/class-scout-optimize-batch.php';

/**
 * Main plugin class (singleton).
 */
final class Scout_Optimize {

	/**
	 * Option key holding the installed plugin version, used for idempotent
	 * activation and future upgrade routines.
	 */
	const VERSION_OPTION = 'scout_optimize_version';

	/**
	 * Option key holding plugin settings (preset, toggles). Populated with
	 * safe defaults on activation; managed by the Stage F settings page.
	 */
	const SETTINGS_OPTION = 'scout_optimize_settings';

	/**
	 * Singleton instance.
	 *
	 * @var Scout_Optimize|null
	 */
	private static $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return Scout_Optimize
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Wire hooks. Kept intentionally empty of features in Stage A.
	 *
	 * @return void
	 */
	private function init() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_post_scout_optimize_save', array( $this, 'handle_save_settings' ) );

		// Register the upload pipeline. Its callback is a no-op until the
		// optimize_uploads setting is turned on for the site.
		( new Scout_Optimize_Pipeline() )->register();

		// Register WebP delivery. Its filters are no-ops until the
		// rewrite_picture setting is turned on for the site.
		( new Scout_Optimize_Delivery() )->register();

		// Register the batch sweep (admin page, handlers, cron worker).
		( new Scout_Optimize_Batch() )->register();
	}

	/**
	 * Activation routine. Idempotent by design (per the suite's self-healing
	 * setup pattern): safe to run on every activation and on version bumps.
	 *
	 * NEVER performs destructive operations. Originals-preservation is a
	 * vision-level invariant (vision doc §3.4).
	 *
	 * @return void
	 */
	public static function activate() {
		// Grant the gate capability to administrators. Stage F may narrow this.
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( SCOUT_OPTIMIZE_CAP ) ) {
			$role->add_cap( SCOUT_OPTIMIZE_CAP );
		}

		// Seed settings with safe defaults only if absent (idempotent).
		if ( false === get_option( self::SETTINGS_OPTION ) ) {
			add_option(
				self::SETTINGS_OPTION,
				array(
					'preset'           => 'balanced', // lossy | balanced | lossless.
					'optimize_uploads' => false,      // Off until enabled per site.
					'generate_webp'    => false,      // Off until enabled per site.
					'generate_avif'    => false,      // Off until enabled per site.
					'rewrite_picture'  => false,      // Off until enabled per site.
					'max_dimension'    => 1920,       // Full-size longest-edge cap in pixels.
				)
			);
		}

		update_option( self::VERSION_OPTION, SCOUT_OPTIMIZE_VERSION );
	}

	/**
	 * Deactivation routine. Removes nothing: settings, stats, and (above all)
	 * original image files are preserved. Cleanup of options happens only in
	 * uninstall.php; image originals are never removed by any lifecycle hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Stop and unschedule any running batch sweep. No media is touched.
		$timestamp = wp_next_scheduled( 'scout_optimize_run_batch' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'scout_optimize_run_batch' );
		}
		delete_option( 'scout_optimize_sweep_running' );
	}

	/**
	 * Register the Scout-gated admin page.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_management_page(
			__( 'Scout Optimize', 'scout-optimize' ),
			__( 'Scout Optimize', 'scout-optimize' ),
			SCOUT_OPTIMIZE_CAP,
			'scout-optimize',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Current settings merged over the seeded defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'preset'           => 'balanced',
			'optimize_uploads' => false,
			'generate_webp'    => false,
			'generate_avif'    => false,
			'rewrite_picture'  => false,
			'max_dimension'    => 1920,
		);
		$saved    = get_option( self::SETTINGS_OPTION, array() );
		return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
	}

	/**
	 * Persist settings from the admin form. Capability- and nonce-gated.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( SCOUT_OPTIMIZE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'scout-optimize' ) );
		}
		check_admin_referer( 'scout_optimize_settings' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_admin_referer.
		$preset = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : 'balanced';
		$max    = isset( $_POST['max_dimension'] ) ? absint( wp_unslash( $_POST['max_dimension'] ) ) : 1920;
		if ( $max > 0 ) {
			$max = max( 320, min( 8000, $max ) );
		}

		$settings = array(
			'preset'           => Scout_Optimize_Presets::normalize( $preset ),
			'optimize_uploads' => ! empty( $_POST['optimize_uploads'] ),
			'generate_webp'    => ! empty( $_POST['generate_webp'] ),
			'generate_avif'    => ! empty( $_POST['generate_avif'] ),
			'rewrite_picture'  => ! empty( $_POST['rewrite_picture'] ),
			'max_dimension'    => $max,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( self::SETTINGS_OPTION, $settings );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'tools.php?page=scout-optimize' ) ) );
		exit;
	}

	/**
	 * Report the server's image capabilities so a tester knows what will work.
	 *
	 * @return array{imagick:bool,webp:bool,avif:bool}
	 */
	private static function capabilities() {
		$imagick = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$formats = $imagick ? array_map( 'strtoupper', Imagick::queryFormats() ) : array();
		return array(
			'imagick' => $imagick,
			'webp'    => in_array( 'WEBP', $formats, true ),
			'avif'    => in_array( 'AVIF', $formats, true ),
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( SCOUT_OPTIMIZE_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'scout-optimize' ) );
		}

		$s    = self::settings();
		$caps = self::capabilities();
		$yes  = __( 'yes', 'scout-optimize' );
		$no   = __( 'no', 'scout-optimize' );

		$toggles = array(
			'optimize_uploads' => __( 'Optimize new uploads (compress, cap dimensions, back up the original first)', 'scout-optimize' ),
			'generate_webp'    => __( 'Generate WebP copies', 'scout-optimize' ),
			'generate_avif'    => __( 'Generate AVIF copies (smaller than WebP; needs server AVIF support)', 'scout-optimize' ),
			'rewrite_picture'  => __( 'Serve the smaller copies to visitors (wrap images in <picture>)', 'scout-optimize' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Scout Optimize', 'scout-optimize' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag after a nonce-checked save/redirect. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'scout-optimize' ); ?></p></div>
			<?php endif; ?>

			<p style="max-width:760px;">
				<?php esc_html_e( 'Turn optimization on for this site. Everything is off until you enable it here. Originals are always backed up before any image is touched, so every change is reversible.', 'scout-optimize' ); ?>
			</p>

			<h2><?php esc_html_e( 'This server', 'scout-optimize' ); ?></h2>
			<table class="widefat striped" style="max-width:420px;">
				<tbody>
					<tr><td><?php esc_html_e( 'Imagick image engine', 'scout-optimize' ); ?></td><td><strong><?php echo esc_html( $caps['imagick'] ? $yes : $no ); ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Can create WebP', 'scout-optimize' ); ?></td><td><strong><?php echo esc_html( $caps['webp'] ? $yes : $no ); ?></strong></td></tr>
					<tr><td><?php esc_html_e( 'Can create AVIF', 'scout-optimize' ); ?></td><td><strong><?php echo esc_html( $caps['avif'] ? $yes : $no ); ?></strong></td></tr>
				</tbody>
			</table>
			<?php if ( ! $caps['avif'] ) : ?>
				<p style="color:#8a6d3b;max-width:760px;"><?php esc_html_e( 'This server cannot create AVIF, so AVIF copies are skipped even if the option below is on. WebP still works.', 'scout-optimize' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'scout_optimize_settings' ); ?>
				<input type="hidden" name="action" value="scout_optimize_save" />

				<h2><?php esc_html_e( 'Quality preset', 'scout-optimize' ); ?></h2>
				<select name="preset">
					<?php
					$presets = array(
						'lossy'    => __( 'Lossy (smallest files)', 'scout-optimize' ),
						'balanced' => __( 'Balanced (recommended)', 'scout-optimize' ),
						'lossless' => __( 'Lossless (largest, highest quality)', 'scout-optimize' ),
					);
					foreach ( $presets as $key => $label ) :
						?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['preset'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<h2><?php esc_html_e( 'What to do', 'scout-optimize' ); ?></h2>
				<fieldset>
					<?php foreach ( $toggles as $key => $label ) : ?>
						<p>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?> />
								<?php echo esc_html( $label ); ?>
							</label>
						</p>
					<?php endforeach; ?>
				</fieldset>

				<h2><?php esc_html_e( 'Maximum image width/height (pixels)', 'scout-optimize' ); ?></h2>
				<p>
					<input type="number" name="max_dimension" min="0" max="8000" step="10" value="<?php echo esc_attr( (string) $s['max_dimension'] ); ?>" />
					<span class="description"><?php esc_html_e( 'New uploads larger than this are scaled down. 0 turns the cap off. Applies to new uploads only.', 'scout-optimize' ); ?></span>
				</p>

				<?php submit_button( __( 'Save settings', 'scout-optimize' ) ); ?>
			</form>

			<p style="max-width:760px;">
				<?php
				printf(
					/* translators: %s: URL of the sweep tool. */
					wp_kses_post( __( 'To optimize images already in the library, use <a href="%s">Scout Optimize Sweep</a>. New uploads are handled automatically once the options above are on.', 'scout-optimize' ) ),
					esc_url( admin_url( 'tools.php?page=scout-optimize-sweep' ) )
				);
				?>
			</p>
			<p style="color:#666;"><?php echo esc_html( sprintf( /* translators: %s: version. */ __( 'Scout Optimize %s', 'scout-optimize' ), SCOUT_OPTIMIZE_VERSION ) ); ?></p>
		</div>
		<?php
	}
}
