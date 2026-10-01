<?php
/**
 * The Google box on Reviews > Settings and the actions behind its buttons:
 * save credentials, connect, pick a location, sync now, disconnect.
 *
 * Every button posts to admin-post.php with its own nonce and requires
 * manage_options.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Reviews_Google_Admin {

	const STATE_TRANSIENT = 'scout_reviews_google_state_';
	const NOTICE          = 'scout_reviews_google_notice_';

	public static function boot(): void {
		add_action( 'scout_reviews_settings_top', array( __CLASS__, 'box' ) );

		foreach ( array( 'credentials', 'connect', 'callback', 'location', 'sync', 'disconnect' ) as $action ) {
			add_action( 'admin_post_scout_reviews_google_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}

		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu_bubble' ), 99 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'synced_hint' ), 5 );
	}

	/* ---- Settings box ---- */

	public static function box(): void {
		$g        = Scout_Reviews_Google::get();
		$settings = admin_url( 'edit.php?post_type=' . Scout_Reviews_Post_Type::TYPE . '&page=' . Scout_Reviews_Settings::PAGE );
		?>
		<div class="card" style="max-width:1100px;padding:16px 20px;margin:16px 0 24px;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Google reviews sync', 'scout-reviews' ); ?></h2>

			<?php if ( ! Scout_Reviews_Google::has_credentials() ) : ?>
				<p><?php esc_html_e( 'Step 1: paste the OAuth client ID and secret from Google Cloud (APIs & Services > Credentials). Add this exact address as an "Authorized redirect URI" on that client:', 'scout-reviews' ); ?></p>
				<p><code style="user-select:all;"><?php echo esc_html( Scout_Reviews_Google::redirect_uri() ); ?></code></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="scout_reviews_google_credentials" />
					<?php wp_nonce_field( 'scout_reviews_google_credentials' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="scout-g-id"><?php esc_html_e( 'Client ID', 'scout-reviews' ); ?></label></th>
							<td><input type="text" id="scout-g-id" class="large-text" name="client_id" autocomplete="off" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="scout-g-secret"><?php esc_html_e( 'Client secret', 'scout-reviews' ); ?></label></th>
							<td><input type="password" id="scout-g-secret" class="regular-text" name="client_secret" autocomplete="new-password" /></td>
						</tr>
					</table>
					<p class="description"><?php esc_html_e( 'More secure option: put SCOUT_REVIEWS_GOOGLE_CLIENT_ID and SCOUT_REVIEWS_GOOGLE_CLIENT_SECRET in wp-config.php instead, and this form disappears.', 'scout-reviews' ); ?></p>
					<?php submit_button( __( 'Save credentials', 'scout-reviews' ), 'secondary' ); ?>
				</form>

			<?php elseif ( ! Scout_Reviews_Google::is_connected() ) : ?>
				<p><?php esc_html_e( 'Step 2: sign in with the Google account that owns or manages your Business Profile.', 'scout-reviews' ); ?></p>
				<?php self::button( 'connect', __( 'Connect Google', 'scout-reviews' ), 'primary' ); ?>
				<?php if ( ! Scout_Reviews_Google::credentials_in_config() ) : ?>
					<?php self::button( 'disconnect', __( 'Clear credentials', 'scout-reviews' ), 'link', array( 'forget' => '1' ) ); ?>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Redirect URI on the Google client:', 'scout-reviews' ); ?> <code style="user-select:all;"><?php echo esc_html( Scout_Reviews_Google::redirect_uri() ); ?></code></p>

			<?php elseif ( ! Scout_Reviews_Google::is_ready() ) : ?>
				<?php self::location_picker(); ?>

			<?php else : ?>
				<p>
					<?php
					/* translators: %s: business name on Google. */
					echo wp_kses_post( sprintf( __( 'Connected to <strong>%s</strong>. Reviews sync once a day. New ones arrive as drafts so you can look before they go live.', 'scout-reviews' ), esc_html( $g['location_title'] ) ) );
					?>
				</p>
				<p>
					<?php
					if ( $g['last_sync'] ) {
						/* translators: %s: time since the last sync, e.g. "2 hours". */
						echo esc_html( sprintf( __( 'Last sync: %s ago.', 'scout-reviews' ), human_time_diff( (int) $g['last_sync'] ) ) ) . ' ';
						$r = $g['last_result'] ?? array();
						if ( ! $g['last_error'] && $r ) {
							/* translators: 1: new reviews, 2: reviews removed on Google, 3: star-only reviews skipped. */
							echo esc_html( sprintf( __( '%1$d new, %2$d removed on Google, %3$d star-only reviews skipped.', 'scout-reviews' ), (int) $r['new'], (int) $r['removed'], (int) $r['skipped'] ) );
						}
					} else {
						esc_html_e( 'Not synced yet.', 'scout-reviews' );
					}
					?>
				</p>
				<?php if ( $g['last_error'] ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $g['last_error'] ); ?></p></div>
				<?php endif; ?>
				<p>
					<?php self::button( 'sync', __( 'Sync now', 'scout-reviews' ), 'primary' ); ?>
					<?php self::button( 'location', __( 'Change location', 'scout-reviews' ), 'secondary', array( 'reset' => '1' ) ); ?>
					<?php self::button( 'disconnect', __( 'Disconnect', 'scout-reviews' ), 'link' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $g['last_error'] && ! Scout_Reviews_Google::is_ready() ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $g['last_error'] ); ?></p></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function location_picker(): void {
		$locations = Scout_Reviews_Google::locations();
		if ( is_wp_error( $locations ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $locations->get_error_message() ) . '</p></div>';
			self::button( 'disconnect', __( 'Disconnect and start over', 'scout-reviews' ), 'secondary' );
			return;
		}
		if ( ! $locations ) {
			echo '<p>' . esc_html__( 'This Google account does not manage any Business Profiles. Disconnect and sign in with the account that does.', 'scout-reviews' ) . '</p>';
			self::button( 'disconnect', __( 'Disconnect', 'scout-reviews' ), 'secondary' );
			return;
		}
		?>
		<p><?php esc_html_e( 'Step 3: pick the business to pull reviews from.', 'scout-reviews' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="scout_reviews_google_location" />
			<?php wp_nonce_field( 'scout_reviews_google_location' ); ?>
			<fieldset>
				<?php foreach ( $locations as $i => $loc ) : ?>
					<label style="display:block;margin:6px 0;">
						<input type="radio" name="pick" value="<?php echo esc_attr( $loc['account'] . '|' . $loc['location'] . '|' . $loc['title'] ); ?>" <?php checked( 0, $i ); ?> />
						<strong><?php echo esc_html( $loc['title'] ); ?></strong>
						<?php if ( $loc['address'] ) : ?>
							<span class="description"><?php echo esc_html( $loc['address'] ); ?></span>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<?php submit_button( __( 'Use this business and sync', 'scout-reviews' ) ); ?>
		</form>
		<?php
	}

	private static function button( string $action, string $label, string $style, array $extra = array() ): void {
		$class = 'link' === $style ? 'button-link' : 'button button-' . $style;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( 'scout_reviews_google_' . $action ); ?>" />
			<?php foreach ( $extra as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>" />
			<?php endforeach; ?>
			<?php wp_nonce_field( 'scout_reviews_google_' . $action ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/* ---- Handlers ---- */

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'scout-reviews' ), 403 );
		}
		check_admin_referer( 'scout_reviews_google_' . $action );
	}

	private static function back( string $message = '', string $type = 'success' ): void {
		if ( '' !== $message ) {
			set_transient( self::NOTICE . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . Scout_Reviews_Post_Type::TYPE . '&page=' . Scout_Reviews_Settings::PAGE ) );
		exit;
	}

	public static function handle_credentials(): void {
		self::guard( 'credentials' );
		$id     = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );
		$secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
		if ( '' === $id || '' === $secret ) {
			self::back( __( 'Enter both the client ID and the client secret.', 'scout-reviews' ), 'error' );
		}
		Scout_Reviews_Google::update( array( 'client_id' => $id, 'client_secret' => $secret, 'last_error' => '' ) );
		self::back( __( 'Credentials saved. Now click Connect Google.', 'scout-reviews' ) );
	}

	public static function handle_connect(): void {
		self::guard( 'connect' );
		if ( ! Scout_Reviews_Google::has_credentials() ) {
			self::back( __( 'Save the client ID and secret first.', 'scout-reviews' ), 'error' );
		}
		$state = wp_generate_password( 32, false );
		set_transient( self::STATE_TRANSIENT . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		wp_redirect( Scout_Reviews_Google::auth_url( $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- Google's sign-in page.
		exit;
	}

	/**
	 * Google sends the person back here after sign-in. No nonce is possible on
	 * an inbound redirect, so the random state stored at connect time does
	 * that job.
	 */
	public static function handle_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'scout-reviews' ), 403 );
		}
		$key      = self::STATE_TRANSIENT . get_current_user_id();
		$expected = (string) get_transient( $key );
		delete_transient( $key );

		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- state checked below.
		if ( '' === $expected || ! hash_equals( $expected, $state ) ) {
			self::back( __( 'That sign-in link expired. Click Connect Google again.', 'scout-reviews' ), 'error' );
		}
		if ( ! empty( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::back( __( 'Google sign-in was cancelled.', 'scout-reviews' ), 'error' );
		}

		$result = Scout_Reviews_Google::exchange_code( sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_wp_error( $result ) ) {
			self::back( $result->get_error_message(), 'error' );
		}
		self::back( __( 'Google is connected. Pick your business below.', 'scout-reviews' ) );
	}

	public static function handle_location(): void {
		self::guard( 'location' );

		if ( ! empty( $_POST['reset'] ) ) {
			Scout_Reviews_Google::update( array( 'account' => '', 'location' => '', 'location_title' => '' ) );
			Scout_Reviews_Google_Sync::unschedule();
			self::back();
		}

		$parts = explode( '|', sanitize_text_field( wp_unslash( $_POST['pick'] ?? '' ) ), 3 );
		if ( 3 !== count( $parts ) || ! preg_match( '#^accounts/[\w-]+$#', $parts[0] ) || ! preg_match( '#^locations/[\w-]+$#', $parts[1] ) ) {
			self::back( __( 'Pick a business from the list.', 'scout-reviews' ), 'error' );
		}
		Scout_Reviews_Google::update( array( 'account' => $parts[0], 'location' => $parts[1], 'location_title' => $parts[2] ) );
		Scout_Reviews_Google_Sync::schedule();

		self::report( Scout_Reviews_Google_Sync::run() );
	}

	public static function handle_sync(): void {
		self::guard( 'sync' );
		self::report( Scout_Reviews_Google_Sync::run() );
	}

	public static function handle_disconnect(): void {
		self::guard( 'disconnect' );
		Scout_Reviews_Google::disconnect();
		Scout_Reviews_Google_Sync::unschedule();
		if ( ! empty( $_POST['forget'] ) ) {
			Scout_Reviews_Google::update( array( 'client_id' => '', 'client_secret' => '' ) );
		}
		self::back( __( 'Google is disconnected. Reviews already imported stay as they are.', 'scout-reviews' ) );
	}

	/**
	 * @param array|WP_Error $result
	 */
	private static function report( $result ): void {
		if ( is_wp_error( $result ) ) {
			self::back( $result->get_error_message(), 'error' );
		}
		self::back(
			sprintf(
				/* translators: 1: new reviews, 2: overall rating, 3: total review count. */
				__( 'Sync done. %1$d new reviews are waiting as drafts. Google shows %2$s stars from %3$d reviews.', 'scout-reviews' ),
				(int) $result['new'],
				number_format_i18n( (float) $result['rating'], 1 ),
				(int) $result['count']
			)
		);
	}

	/* ---- Notices ---- */

	public static function notices(): void {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
				esc_html( $notice['message'] )
			);
		}

		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'edit_posts' ) || ! in_array( $screen->id, array( 'dashboard', 'edit-' . Scout_Reviews_Post_Type::TYPE ), true ) ) {
			return;
		}
		$new = Scout_Reviews_Google_Sync::new_count();
		if ( $new ) {
			$link = admin_url( 'edit.php?post_type=' . Scout_Reviews_Post_Type::TYPE . '&post_status=draft' );
			printf(
				'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
				/* translators: %d: number of new reviews. */
				esc_html( sprintf( _n( '%d new Google review is waiting for a look.', '%d new Google reviews are waiting for a look.', $new, 'scout-reviews' ), $new ) ),
				esc_url( $link ),
				esc_html__( 'See them', 'scout-reviews' )
			);
		}
	}

	/**
	 * Count of new reviews on the Reviews menu item, like the Comments bubble.
	 */
	public static function menu_bubble(): void {
		global $menu;
		$new = Scout_Reviews_Google_Sync::new_count();
		if ( ! $new || ! is_array( $menu ) ) {
			return;
		}
		$slug = 'edit.php?post_type=' . Scout_Reviews_Post_Type::TYPE;
		foreach ( $menu as $i => $item ) {
			if ( isset( $item[2] ) && $slug === $item[2] ) {
				$menu[ $i ][0] .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $new . '</span></span>'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				break;
			}
		}
	}

	public static function synced_hint( WP_Post $post ): void {
		if ( Scout_Reviews_Post_Type::TYPE !== $post->post_type || ! Scout_Reviews_Google_Sync::is_synced( $post->ID ) ) {
			return;
		}
		$removed = get_post_meta( $post->ID, Scout_Reviews_Google_Sync::META_REMOVED, true );
		echo '<div class="notice notice-info inline" style="margin:12px 0 0;"><p>';
		if ( $removed ) {
			esc_html_e( 'This review was removed from Google, so it was taken off the site. It stays here for your records.', 'scout-reviews' );
		} else {
			esc_html_e( 'Synced from Google. The name, text, stars, and date come from Google and update on every sync, so edits to them will not stick. You choose whether it shows (Publish or Draft), the reviewer detail, Featured, and the order.', 'scout-reviews' );
		}
		echo '</p></div>';
	}
}
