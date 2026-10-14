<?php
/**
 * Scout Site Check: the form, the request inbox, and the step-by-step run.
 *
 * A visitor enters a web address, a name and an email. The browser then asks
 * this site to run the checks in four steps (basics first, then speed, AI
 * crawlers and photos side by side), so no single request runs long enough
 * for the host to cut it off. When the last step lands, the report is saved,
 * shown on the page and emailed. If the visitor closes the tab halfway, a
 * scheduled job finishes the run ten minutes later.
 *
 * @package Scout_Sitecheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Sitecheck {

	const OPTION = 'scout_sitecheck';
	const CPT    = 'scout_sitecheck';
	const NS     = 'scout-sitecheck/v1';
	const STEPS  = array( 'basics', 'speed', 'crawlers', 'photos' );
	const FINISH = 'scout_sitecheck_finish';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_type' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_shortcode( 'scout_site_check', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_assets' ) );
		add_action( self::FINISH, array( __CLASS__, 'finish_late' ) );
	}

	/** @return array<string, mixed> */
	public static function settings(): array {
		$s = (array) get_option( self::OPTION, array() );
		return array(
			'notify'     => (string) ( $s['notify'] ?? get_option( 'admin_email' ) ),
			'lead_name'  => (string) ( $s['lead_name'] ?? '' ),
			'lead_photo' => (string) ( $s['lead_photo'] ?? '' ),
			'book_url'   => (string) ( $s['book_url'] ?? '' ),
			'psi_key'    => (string) ( $s['psi_key'] ?? '' ),
			'hold'       => ! empty( $s['hold'] ),
			'page'       => (int) ( $s['page'] ?? 0 ),
		);
	}

	/** Google's speed tool key: wp-config's SCOUT_SITECHECK_PSI_KEY wins over the setting. */
	public static function psi_key(): string {
		return defined( 'SCOUT_SITECHECK_PSI_KEY' ) ? (string) SCOUT_SITECHECK_PSI_KEY : self::settings()['psi_key'];
	}

	/** Who follows up, as the report names them. Blank: "our team". */
	public static function lead_name(): string {
		$n = trim( self::settings()['lead_name'] );
		return '' !== $n ? $n : __( 'our team', 'scout-sitecheck' );
	}

	public static function book_url(): string {
		$u = self::settings()['book_url'];
		if ( '' !== $u ) {
			return $u;
		}
		return function_exists( 'scout_booking_url' ) ? (string) scout_booking_url() : home_url( '/contact/' );
	}

	public static function register_type(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => __( 'Site checks', 'scout-sitecheck' ),
					'singular_name' => __( 'Site check', 'scout-sitecheck' ),
					'edit_item'     => __( 'Site check', 'scout-sitecheck' ),
					'search_items'  => __( 'Search site checks', 'scout-sitecheck' ),
					'not_found'     => __( 'No site checks yet. Every check someone runs from the site lands here.', 'scout-sitecheck' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
			)
		);
	}

	/* ---------- the form ---------- */

	public static function maybe_assets(): void {
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, 'scout_site_check' ) ) {
			self::assets();
		}
	}

	private static function assets(): void {
		wp_enqueue_style( 'scout-sitecheck', SCOUT_SITECHECK_URL . 'assets/sitecheck.css', array(), SCOUT_SITECHECK_VERSION );
		wp_enqueue_script( 'scout-sitecheck', SCOUT_SITECHECK_URL . 'assets/sitecheck.js', array(), SCOUT_SITECHECK_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'scout-sitecheck',
			'scoutSitecheck',
			array(
				'rest'  => esc_url_raw( rest_url( self::NS ) ),
				'steps' => self::STEPS,
				'book'  => esc_url_raw( self::book_url() ),
				'lead'  => self::lead_name(),
			)
		);
	}

	/**
	 * [scout_site_check sample="yes" layout="stack"]
	 *
	 * layout="hero" puts the form and the sample report side by side, for the top of a landing page.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'sample' => 'yes', 'layout' => 'stack' ), (array) $atts, 'scout_site_check' );
		self::assets();
		$hero = 'hero' === $atts['layout'];
		ob_start();
		?>
		<div class="ssc<?php echo $hero ? ' ssc-hero' : ''; ?>" data-ssc>
			<form class="ssc-form" data-ssc-form novalidate>
				<label class="ssc-label" for="ssc-url"><?php esc_html_e( 'Your website address', 'scout-sitecheck' ); ?></label>
				<input class="ssc-input ssc-url" id="ssc-url" name="url" type="text" inputmode="url" autocomplete="url" placeholder="yourbusiness.com" required>
				<div class="ssc-row">
					<div>
						<label class="ssc-label" for="ssc-name"><?php esc_html_e( 'Your name', 'scout-sitecheck' ); ?></label>
						<input class="ssc-input" id="ssc-name" name="name" type="text" autocomplete="name" required>
					</div>
					<div>
						<label class="ssc-label" for="ssc-email"><?php esc_html_e( 'Email for the report', 'scout-sitecheck' ); ?></label>
						<input class="ssc-input" id="ssc-email" name="email" type="email" autocomplete="email" required>
					</div>
				</div>
				<p class="ssc-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
				<button class="ssc-btn" type="submit"><?php esc_html_e( 'Check my site', 'scout-sitecheck' ); ?></button>
				<p class="ssc-fine">
					<?php esc_html_e( 'It\'s free and takes about a minute. We\'ll show the results here and email you the full report.', 'scout-sitecheck' ); ?>
					<?php if ( get_privacy_policy_url() ) : ?>
						<a href="<?php echo esc_url( get_privacy_policy_url() ); ?>"><?php esc_html_e( 'How we use your details', 'scout-sitecheck' ); ?></a>
					<?php endif; ?>
				</p>
				<p class="ssc-error" data-ssc-error role="alert" hidden></p>
			</form>

			<div class="ssc-run" data-ssc-run hidden aria-live="polite">
				<p class="ssc-eyebrow" data-ssc-site></p>
				<h2 class="ssc-h" data-ssc-head><?php esc_html_e( 'Checking your site now.', 'scout-sitecheck' ); ?></h2>
				<div class="ssc-progress" aria-hidden="true"><i data-ssc-bar></i></div>
				<ul class="ssc-steps">
					<li data-step="basics"><span class="ssc-dot" aria-hidden="true"></span><?php esc_html_e( 'Security, page titles, business info and phone layout', 'scout-sitecheck' ); ?></li>
					<li data-step="speed"><span class="ssc-dot" aria-hidden="true"></span><?php esc_html_e( 'Speed on phones (Google\'s speed tool takes the longest)', 'scout-sitecheck' ); ?></li>
					<li data-step="crawlers"><span class="ssc-dot" aria-hidden="true"></span><?php esc_html_e( 'AI tools and your sitemap', 'scout-sitecheck' ); ?></li>
					<li data-step="photos"><span class="ssc-dot" aria-hidden="true"></span><?php esc_html_e( 'Photo sizes', 'scout-sitecheck' ); ?></li>
				</ul>
				<div class="ssc-result" data-ssc-result hidden></div>
			</div>

			<?php if ( 'no' !== $atts['sample'] ) : ?>
				<?php
				$rows   = Scout_Sitecheck_Report::sample();
				$passed = count( array_filter( $rows, static fn( $r ) => 'pass' === $r['status'] ) );
				?>
				<aside class="ssc-sample" data-ssc-sample aria-label="<?php esc_attr_e( 'Sample report', 'scout-sitecheck' ); ?>">
					<div class="ssc-sample-top">
						<p class="ssc-eyebrow"><?php esc_html_e( 'Sample report', 'scout-sitecheck' ); ?></p>
						<span class="ssc-site">yourbusiness.com</span>
					</div>
					<div class="ssc-score">
						<span class="ssc-n"><span data-ssc-pass><?php echo (int) $passed; ?></span><small>/<?php echo count( $rows ); ?></small><span class="screen-reader-text"> <?php esc_html_e( 'checks passed', 'scout-sitecheck' ); ?></span></span>
						<div>
							<p class="ssc-score-h" data-ssc-sample-head><?php echo esc_html( Scout_Sitecheck_Report::headline( $passed, count( $rows ) ) ); ?></p>
							<div class="ssc-seg" aria-hidden="true"><?php foreach ( $rows as $r ) : ?><i class="is-<?php echo esc_attr( $r['status'] ); ?>"></i><?php endforeach; ?></div>
						</div>
					</div>
					<?php
					echo Scout_Sitecheck_Report::rows_html( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
					?>
					<p class="ssc-sample-note"><?php esc_html_e( 'These are example results, so yours will look different.', 'scout-sitecheck' ); ?></p>
				</aside>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/* ---------- REST ---------- */

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'step' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** Count a request against a limit. True when the limit is already used up. */
	private static function over( string $key, int $max, int $ttl ): bool {
		$key = 'scout_ssc_' . md5( $key . wp_salt( 'nonce' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return true;
		}
		set_transient( $key, $n + 1, $ttl );
		return false;
	}

	/**
	 * Clean what the visitor typed into an https:// address on a public host.
	 * Returns '' when it isn't a real public website address.
	 */
	public static function normalize( string $raw ): string {
		$raw = trim( mb_substr( $raw, 0, 300 ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			$raw = 'https://' . $raw;
		}
		$parts = wp_parse_url( $raw );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host || false === strpos( $host, '.' ) || filter_var( $host, FILTER_VALIDATE_IP ) || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return '';
		}
		if ( preg_match( '/(^|\.)(localhost|local|internal|test|invalid)$/', $host ) ) {
			return '';
		}
		$url = 'https://' . $host . '/';
		return wp_http_validate_url( $url ) ? $url : '';
	}

	public static function start( WP_REST_Request $req ) {
		if ( '' !== (string) $req->get_param( 'website' ) ) { // Honeypot: people never fill it.
			return new WP_Error( 'scout_ssc_busy', __( 'Something went wrong. Please try again.', 'scout-sitecheck' ), array( 'status' => 400 ) );
		}
		$name  = sanitize_text_field( mb_substr( (string) $req->get_param( 'name' ), 0, 100 ) );
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		$url   = self::normalize( (string) $req->get_param( 'url' ) );
		if ( '' === $url ) {
			return new WP_Error( 'scout_ssc_url', __( 'That doesn\'t look like a website address. Try something like yourbusiness.com.', 'scout-sitecheck' ), array( 'status' => 400 ) );
		}
		if ( '' === $name ) {
			return new WP_Error( 'scout_ssc_name', __( 'Add your name so we know who the report is for.', 'scout-sitecheck' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'scout_ssc_email', __( 'Add your email so we can send the full report.', 'scout-sitecheck' ), array( 'status' => 400 ) );
		}
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		if ( self::over( 'ip' . $ip, 3, HOUR_IN_SECONDS ) || self::over( 'em' . strtolower( $email ), 3, DAY_IN_SECONDS ) || self::over( 'all', 40, DAY_IN_SECONDS ) ) {
			return new WP_Error( 'scout_ssc_slow', __( 'We\'ve run a lot of checks in a short time. Please try again in an hour, or email us and we\'ll run it for you.', 'scout-sitecheck' ), array( 'status' => 429 ) );
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$id   = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'private',
				'post_title'  => preg_replace( '/^www\./', '', $host ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'scout_ssc_save', __( 'Something went wrong on our end. Please try again.', 'scout-sitecheck' ), array( 'status' => 500 ) );
		}
		$token = wp_generate_password( 24, false );
		update_post_meta( $id, '_ssc_url', $url );
		update_post_meta( $id, '_ssc_name', $name );
		update_post_meta( $id, '_ssc_email', $email );
		update_post_meta( $id, '_ssc_token', $token );
		update_post_meta( $id, '_ssc_status', 'running' );
		update_post_meta( $id, '_ssc_followed', 0 );
		update_post_meta( $id, '_ssc_page', esc_url_raw( (string) $req->get_header( 'referer' ) ) );
		wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, self::FINISH, array( (int) $id ) );
		return array(
			'id'    => (int) $id,
			'token' => $token,
			'site'  => preg_replace( '/^www\./', '', $host ),
		);
	}

	public static function step( WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$step = (string) $req->get_param( 'step' );
		$post = get_post( $id );
		if ( ! $post || self::CPT !== $post->post_type || ! in_array( $step, self::STEPS, true ) || ! hash_equals( (string) get_post_meta( $id, '_ssc_token', true ), (string) $req->get_param( 'token' ) ) ) {
			return new WP_Error( 'scout_ssc_bad', __( 'This check has expired. Please start a new one.', 'scout-sitecheck' ), array( 'status' => 403 ) );
		}
		$rows = self::run_step( $id, $step );
		$out  = array(
			'step' => $step,
			'ok'   => ! isset( $rows['fatal'] ),
		);
		if ( isset( $rows['fatal'] ) ) {
			$out['message'] = $rows['fatal'];
			return $out;
		}
		$final = self::maybe_finish( $id );
		if ( $final ) {
			$final['html']   = Scout_Sitecheck_Report::rows_html( $final['rows'] );
			$final['follow'] = Scout_Sitecheck_Report::follow_up( $final['fixes'], false );
			unset( $final['rows'] );
			$out['report'] = $final;
		}
		return $out;
	}

	/** Run one step once and store it. Returns its rows, or ['fatal' => message]. */
	public static function run_step( int $id, string $step ): array {
		$done = get_post_meta( $id, '_ssc_step_' . $step, true );
		if ( is_array( $done ) ) {
			return $done;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- some hosts disable it.
		}
		$url = (string) get_post_meta( $id, '_ssc_url', true );
		if ( 'basics' !== $step ) {
			$base = (string) get_post_meta( $id, '_ssc_base', true );
			$url  = '' !== $base ? $base : $url;
		}
		$rows = Scout_Sitecheck_Checks::step( $step, $url );
		if ( isset( $rows['fatal'] ) ) {
			update_post_meta( $id, '_ssc_status', 'unreachable' );
			if ( add_post_meta( $id, '_ssc_final', 1, true ) ) {
				Scout_Sitecheck_Report::notify_team( $id, true );
			}
			return $rows;
		}
		if ( isset( $rows['base'] ) ) {
			update_post_meta( $id, '_ssc_base', (string) $rows['base'] );
			unset( $rows['base'] );
		}
		update_post_meta( $id, '_ssc_step_' . $step, $rows );
		return $rows;
	}

	/** The report once every step is in, else null. Sends it exactly once. */
	public static function maybe_finish( int $id ): ?array {
		$rows = self::rows( $id );
		if ( null === $rows ) {
			return null;
		}
		$report = self::summary( $id, $rows );
		if ( add_post_meta( $id, '_ssc_final', 1, true ) ) {
			update_post_meta( $id, '_ssc_passed', $report['passed'] );
			update_post_meta( $id, '_ssc_total', $report['total'] );
			update_post_meta( $id, '_ssc_checked', time() );
			wp_clear_scheduled_hook( self::FINISH, array( $id ) );
			if ( self::settings()['hold'] ) {
				update_post_meta( $id, '_ssc_status', 'held' );
			} else {
				update_post_meta( $id, '_ssc_status', Scout_Sitecheck_Report::send( $id ) ? 'sent' : 'not_sent' );
			}
			Scout_Sitecheck_Report::notify_team( $id );
		}
		$report['held'] = self::settings()['hold'];
		return $report;
	}

	/** Every row in report order, or null while a step is still missing. */
	public static function rows( int $id ): ?array {
		$all = array();
		foreach ( self::STEPS as $step ) {
			$rows = get_post_meta( $id, '_ssc_step_' . $step, true );
			if ( ! is_array( $rows ) ) {
				return null;
			}
			$all = array_merge( $all, $rows );
		}
		$order = array_flip( array( 'speed', 'security', 'titles', 'schema', 'phone', 'ai', 'sitemap', 'photos' ) );
		usort(
			$all,
			static function ( $a, $b ) use ( $order ) {
				return ( $order[ $a['key'] ] ?? 99 ) <=> ( $order[ $b['key'] ] ?? 99 );
			}
		);
		return $all;
	}

	/** @return array{passed:int,total:int,fixes:int,headline:string,rows:array,site:string} */
	public static function summary( int $id, array $rows ): array {
		$passed = 0;
		$total  = 0;
		foreach ( $rows as $r ) {
			if ( 'skip' === $r['status'] ) {
				continue;
			}
			++$total;
			$passed += 'pass' === $r['status'] ? 1 : 0;
		}
		return array(
			'passed'   => $passed,
			'total'    => $total,
			'fixes'    => $total - $passed,
			'headline' => Scout_Sitecheck_Report::headline( $passed, $total ),
			'rows'     => $rows,
			'site'     => get_post_field( 'post_title', $id ),
		);
	}

	/** The safety net when a visitor closes the tab before the last step. */
	public static function finish_late( int $id ): void {
		if ( get_post_meta( $id, '_ssc_final', true ) ) {
			return;
		}
		foreach ( self::STEPS as $step ) {
			$rows = self::run_step( $id, $step );
			if ( isset( $rows['fatal'] ) ) {
				return;
			}
		}
		self::maybe_finish( $id );
	}
}
