<?php
/**
 * Scout Build -> Visits and cookies: the Tag Manager container, the consent choice and
 * the cookie notice.
 *
 * @package Scout_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Analytics_Admin {

	/**
	 * One status line at the top of the screen (admin kit 1.10.0). An older kit
	 * copy may be the one loaded mid-update, so fall back to the same markup.
	 *
	 * @param string $go Button markup, escaped by the caller.
	 */
	private static function ui_status( string $state, string $headline, string $detail = '', string $go = '' ): void {
		if ( method_exists( 'Scout_Admin_UI', 'status' ) ) {
			Scout_Admin_UI::status( $state, $headline, $detail, $go );
			return;
		}
		$state = in_array( $state, array( 'good', 'warn', 'bad', 'off' ), true ) ? $state : 'good';
		echo '<section class="sh-box"><p class="sh-status is-' . esc_attr( $state ) . '"><span class="sh-dot" aria-hidden="true"></span><strong>' . esc_html( $headline ) . '</strong>';
		if ( '' !== $detail ) {
			echo ' <span>' . esc_html( $detail ) . '</span>';
		}
		if ( '' !== $go ) {
			echo ' <span class="sh-status-go">' . $go . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller.
		}
		echo '</p></section>';
	}

	/** A closed "More options" box (admin kit 1.10.0), with the same fallback. */
	private static function ui_fold_open( string $title ): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_open' ) ) {
			Scout_Admin_UI::fold_open( $title );
			return;
		}
		echo '<details class="sh-box sh-fold"><summary class="sh-title">' . esc_html( $title ) . '</summary>';
	}

	private static function ui_fold_close(): void {
		if ( method_exists( 'Scout_Admin_UI', 'fold_close' ) ) {
			Scout_Admin_UI::fold_close();
			return;
		}
		echo '</details>';
	}

	const SLUG = 'scout-analytics';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SCOUT_ANALYTICS_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/** Nest under the site's Build section; fall back to Settings. */
	public static function parent(): string {
		global $admin_page_hooks;
		foreach ( array( 'scout-build', 'recon-build', 'site-build', 'scout' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return 'options-general.php';
	}

	public static function url(): string {
		return 'options-general.php' === self::parent() ? admin_url( 'options-general.php?page=' . self::SLUG ) : admin_url( 'admin.php?page=' . self::SLUG );
	}

	public static function menu(): void {
		add_submenu_page( self::parent(), __( 'Visits & cookies', 'scout-analytics' ), __( 'Visits & cookies', 'scout-analytics' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::SLUG ) ) {
			Scout_Admin_UI::enqueue( SCOUT_ANALYTICS_URL . 'assets/scout-admin.css' );
		}
	}

	public static function action_link( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'scout-analytics' ) . '</a>' );
		return $links;
	}

	public static function register(): void {
		register_setting( 'scout_analytics_group', Scout_Analytics::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$in  = (array) $in;
		$gtm = strtoupper( preg_replace( '/\s+/', '', (string) ( $in['gtm'] ?? '' ) ) );
		$ga4 = strtoupper( preg_replace( '/\s+/', '', (string) ( $in['ga4'] ?? '' ) ) );
		if ( '' !== $gtm && ! Scout_Analytics::valid_gtm( $gtm ) ) {
			add_settings_error( Scout_Analytics::OPTION, 'gtm', __( 'That Google tracking code does not look right. A Tag Manager code starts with GTM-.', 'scout-analytics' ) );
			$gtm = Scout_Analytics::settings()['gtm'];
		}
		if ( '' !== $ga4 && ! Scout_Analytics::valid_ga4( $ga4 ) ) {
			add_settings_error( Scout_Analytics::OPTION, 'ga4', __( 'That Google tracking code does not look right. A GA4 code starts with G-.', 'scout-analytics' ) );
			$ga4 = Scout_Analytics::settings()['ga4'];
		}
		return array(
			'gtm'          => $gtm,
			'ga4'          => $ga4,
			'mode'         => 'optin' === ( $in['mode'] ?? '' ) ? 'optin' : 'optout',
			'ads'          => ! empty( $in['ads'] ) ? 1 : 0,
			'skip_editors' => ! empty( $in['skip_editors'] ) ? 1 : 0,
			'late'         => ! empty( $in['late'] ) ? 1 : 0,
			'notice'       => sanitize_textarea_field( (string) ( $in['notice'] ?? '' ) ),
			'privacy_page' => absint( $in['privacy_page'] ?? 0 ),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = Scout_Analytics::settings();
		$o   = Scout_Analytics::OPTION;
		$tag = Scout_Analytics::tag();
		Scout_Admin_UI::open(
			__( 'Visits & cookies', 'scout-analytics' ),
			__( 'Counts visits with Google Tag Manager and shows visitors a small cookie notice.', 'scout-analytics' ),
			__( 'Visits & cookies', 'scout-analytics' )
		);
		settings_errors( $o );
		if ( 'gtm' === $tag ) {
			$headline = __( 'On.', 'scout-analytics' );
			/* translators: %s: the Tag Manager container ID. */
			$detail = sprintf( __( 'Google Tag Manager %s loads on the live site.', 'scout-analytics' ), $s['gtm'] );
		} elseif ( 'ga4' === $tag ) {
			$headline = __( 'On.', 'scout-analytics' );
			/* translators: %s: the GA4 measurement ID. */
			$detail = sprintf( __( 'GA4 %s loads on the live site, without Tag Manager.', 'scout-analytics' ), $s['ga4'] );
		} else {
			$headline = __( 'Not set up.', 'scout-analytics' );
			$detail   = __( 'Add the Tag Manager code below to start counting.', 'scout-analytics' );
		}
		// Headline plus one sentence (DESIGN 2a #2). The consent choice shows below.
		self::ui_status( $tag ? 'good' : 'warn', $headline, $detail );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'scout_analytics_group' ); ?>
			<section class="sh-box">
				<h2 class="sh-title"><?php esc_html_e( 'Tracking & privacy', 'scout-analytics' ); ?></h2>
				<p class="sh-note"><?php esc_html_e( 'Google consent mode is set before any tag runs, and nothing loads on staging or local copies.', 'scout-analytics' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sa-gtm"><?php esc_html_e( 'Tag Manager code', 'scout-analytics' ); ?></label></th>
						<td><input id="sa-gtm" type="text" class="regular-text code" placeholder="GTM-XXXXXXX" name="<?php echo esc_attr( $o ); ?>[gtm]" value="<?php echo esc_attr( $s['gtm'] ); ?>">
							<p class="description"><?php esc_html_e( 'Starts with GTM-. Put GA4 and any other tags inside the container.', 'scout-analytics' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Before a visitor chooses', 'scout-analytics' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[mode]" value="optout" <?php checked( 'optout', $s['mode'] ); ?>> <?php esc_html_e( 'Count the visit, with a "No thanks" button (our US standard)', 'scout-analytics' ); ?></label><br>
							<label><input type="radio" name="<?php echo esc_attr( $o ); ?>[mode]" value="optin" <?php checked( 'optin', $s['mode'] ); ?>> <?php esc_html_e( 'Count nothing until they say yes', 'scout-analytics' ); ?></label>
							<p class="description"><?php esc_html_e( 'Browsers that send Global Privacy Control count as a no.', 'scout-analytics' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="sa-notice"><?php esc_html_e( 'Cookie notice text', 'scout-analytics' ); ?></label></th>
						<td><textarea id="sa-notice" class="large-text" rows="2" name="<?php echo esc_attr( $o ); ?>[notice]" placeholder="<?php echo esc_attr( Scout_Analytics::default_notice( $s['mode'] ) ); ?>"><?php echo esc_textarea( $s['notice'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Leave blank for the standard wording shown.', 'scout-analytics' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="sa-privacy"><?php esc_html_e( 'Privacy policy page', 'scout-analytics' ); ?></label></th>
						<td><?php wp_dropdown_pages( array( 'name' => esc_attr( $o ) . '[privacy_page]', 'id' => 'sa-privacy', 'selected' => (int) $s['privacy_page'], 'show_option_none' => esc_html__( 'None', 'scout-analytics' ), 'option_none_value' => '0' ) ); ?>
							<p class="description"><?php esc_html_e( 'Linked from the notice. Add [scout_privacy_choices] to it so visitors can change their answer.', 'scout-analytics' ); ?></p></td>
					</tr>
				</table>
			</section>

			<?php self::ui_fold_open( __( 'More options', 'scout-analytics' ) ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sa-ga4"><?php esc_html_e( 'GA4 code (no Tag Manager)', 'scout-analytics' ); ?></label></th>
						<td><input id="sa-ga4" type="text" class="regular-text code" placeholder="G-XXXXXXXXXX" name="<?php echo esc_attr( $o ); ?>[ga4]" value="<?php echo esc_attr( $s['ga4'] ); ?>">
							<p class="description"><?php esc_html_e( 'Starts with G-. Ignored while a Tag Manager code is set.', 'scout-analytics' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Ad signals', 'scout-analytics' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[ads]" value="1" <?php checked( $s['ads'] ); ?>> <?php esc_html_e( 'This site runs paid ads, so ad measurement follows the same choice', 'scout-analytics' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off: ad cookies stay off for everyone.', 'scout-analytics' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'People editing the site', 'scout-analytics' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[skip_editors]" value="1" <?php checked( $s['skip_editors'] ); ?>> <?php esc_html_e( 'Do not count visits from anyone logged in who can edit', 'scout-analytics' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page speed', 'scout-analytics' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[late]" value="1" <?php checked( $s['late'] ); ?>> <?php esc_html_e( 'Load Tag Manager after the page shows (recommended)', 'scout-analytics' ); ?></label>
						<p class="description"><?php esc_html_e( 'Pages show faster on phones, but a visitor who leaves in the first second or two isn’t counted.', 'scout-analytics' ); ?></p></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Events other Scout features send', 'scout-analytics' ); ?></h3>
				<p><?php esc_html_e( 'The digital business cards and the link page report their views and taps here. In Tag Manager, one Custom Event trigger catches them all: event name ^(card|links)_ with "Use regex matching" ticked, feeding a GA4 Event tag whose event name is {{Event}}.', 'scout-analytics' ); ?></p>
				<p><code>card_view</code> <code>card_save_contact</code> <code>card_call</code> <code>card_email</code> <code>card_book</code> <code>card_links_click</code> <code>links_click</code> <code>links_post_click</code> <code>links_card_click</code></p>
				<p class="description"><?php esc_html_e( 'scout_consent_update fires when a visitor answers the cookie notice. It is for Tag Manager only; leave it out of GA4.', 'scout-analytics' ); ?></p>
				<p class="description"><?php esc_html_e( 'Each event carries link_label, and link_url when it is a link. In Tag Manager, add both as Data Layer Variables and send them as event parameters.', 'scout-analytics' ); ?></p>
			<?php self::ui_fold_close(); ?>
			<?php submit_button( __( 'Save changes', 'scout-analytics' ) ); ?>
		</form>
		<?php
		Scout_Admin_UI::close();
	}
}
