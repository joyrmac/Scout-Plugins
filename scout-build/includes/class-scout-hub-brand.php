<?php
/**
 * Scout Brand: the white-label layer every site gets with Scout Build, so no
 * site needs a separate branding plugin (Branda Pro and the like).
 *
 * Five parts, all on by default, all filled from the site's own facts:
 *
 * 1. Login screen: the client's logo and brand color, the logo links to the
 *    site, and the page title is the site name.
 * 2. Admin bar: no WordPress logo menu, a friendly greeting, and the update
 *    counter only for operators (a client seeing "33 updates" is noise).
 * 3. Admin footer: who builds and looks after the site, with a link to ask
 *    for a change; the WordPress version only for operators.
 * 4. Admin menu: menus the site hides from non-operators, by slug.
 * 5. Admin message: one short note at the top of the Dashboard.
 *
 * Settings come from the theme's scout-setup.json "brand" section first, then
 * the scout_brand option (for sites without a setup file), then sensible
 * defaults: the Customizer logo, the Site Icon, the site name.
 *
 *   "brand": {
 *     "logo":        "assets/img/logo.svg",   // theme path or https:// address
 *     "logo_width":  220,                      // pixels on the login screen
 *     "color":       "#1A2E5A",                // buttons and links
 *     "background":  "#F6F8FB",                // login page background
 *     "text":        "#1B2150",                // login page text
 *     "message":     "Need a change? Use Request a change in the top bar.",
 *     "hide_menus":  [ "tools.php", "edit-comments.php" ],
 *     "off":         [ "admin_bar" ]           // any of: login, admin_bar, footer, menu, message
 *   }
 *
 * A site turns the whole layer off in wp-config.php:
 *   define( 'SCOUT_BRAND_OFF', true );
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Brand {

	const OPTION  = 'scout_brand';
	const PARTS   = array( 'login', 'admin_bar', 'footer', 'menu', 'message' );
	const DISMISS = 'scout_brand_message_dismissed';

	/** @var array|null Resolved settings, cached per request. */
	private static $settings = null;

	public static function boot(): void {
		if ( defined( 'SCOUT_BRAND_OFF' ) && SCOUT_BRAND_OFF ) {
			return;
		}
		if ( self::on( 'login' ) ) {
			add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_style' ) );
			add_filter( 'login_headerurl', array( __CLASS__, 'login_url' ) );
			add_filter( 'login_headertext', array( __CLASS__, 'login_text' ) );
			add_filter( 'login_title', array( __CLASS__, 'login_title' ), 10, 2 );
		}
		if ( self::on( 'admin_bar' ) ) {
			add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 999 );
		}
		if ( self::on( 'footer' ) ) {
			add_filter( 'admin_footer_text', array( __CLASS__, 'footer_left' ), 99 );
			add_filter( 'update_footer', array( __CLASS__, 'footer_right' ), 99 );
		}
		if ( self::on( 'menu' ) ) {
			add_action( 'admin_menu', array( __CLASS__, 'hide_menus' ), 9999 );
		}
		if ( self::on( 'message' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'message' ) );
			add_action( 'wp_ajax_scout_brand_dismiss', array( __CLASS__, 'dismiss' ) );
		}
	}

	/* ------------------------------------------------------------ settings */

	/** @return array<string, mixed> */
	public static function settings(): array {
		if ( null !== self::$settings ) {
			return self::$settings;
		}
		$file   = class_exists( 'Scout_Hub_Setup' ) ? Scout_Hub_Setup::data() : array();
		$file   = isset( $file['brand'] ) && is_array( $file['brand'] ) ? $file['brand'] : array();
		$option = get_option( self::OPTION, array() );
		$option = is_array( $option ) ? $option : array();
		$s      = array_merge( $option, $file );

		self::$settings = array(
			'logo'       => (string) ( $s['logo'] ?? '' ),
			'logo_width' => max( 80, min( 400, (int) ( $s['logo_width'] ?? 220 ) ) ),
			'color'      => self::hex( $s['color'] ?? '' ),
			'background' => self::hex( $s['background'] ?? '' ),
			'text'       => self::hex( $s['text'] ?? '' ),
			'message'    => (string) ( $s['message'] ?? '' ),
			'hide_menus' => array_values( array_filter( array_map( 'strval', (array) ( $s['hide_menus'] ?? array() ) ) ) ),
			'off'        => array_values( array_intersect( self::PARTS, (array) ( $s['off'] ?? array() ) ) ),
		);
		/**
		 * Filter the resolved brand settings.
		 *
		 * @param array $settings Logo, colors, message, hidden menus, parts turned off.
		 */
		self::$settings = (array) apply_filters( 'scout_brand_settings', self::$settings );
		return self::$settings;
	}

	public static function on( string $part ): bool {
		return ! in_array( $part, self::settings()['off'], true );
	}

	private static function hex( $value ): string {
		$value = trim( (string) $value );
		return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ? $value : '';
	}

	/** The logo for the login screen: brand setting, then Customizer logo, then Site Icon. */
	public static function logo_url(): string {
		$logo = self::settings()['logo'];
		if ( '' !== $logo ) {
			return 0 === strpos( $logo, 'https://' ) ? $logo : get_theme_file_uri( ltrim( $logo, '/' ) );
		}
		$custom = (int) get_theme_mod( 'custom_logo' );
		if ( $custom ) {
			$src = wp_get_attachment_image_url( $custom, 'medium' );
			if ( $src ) {
				return $src;
			}
		}
		return (string) get_site_icon_url( 192 );
	}

	/* --------------------------------------------------------------- login */

	public static function login_style(): void {
		$s     = self::settings();
		$logo  = self::logo_url();
		$color = $s['color'];
		$css   = '';
		if ( '' !== $logo ) {
			$css .= sprintf(
				'#login h1 a,.login h1 a{background-image:url(%1$s);background-size:contain;background-position:center;width:%2$dpx;max-width:100%%;height:96px;margin-bottom:24px}',
				esc_url( $logo ),
				$s['logo_width']
			);
		} else {
			// No logo anywhere: show the site name as text instead of the WordPress mark.
			$css .= '#login h1 a,.login h1 a{background-image:none;text-indent:0;width:auto;height:auto;font-size:24px;font-weight:600;line-height:1.3;color:inherit}';
		}
		if ( '' !== $s['background'] ) {
			$css .= sprintf( 'body.login{background:%s}', $s['background'] );
		}
		if ( '' !== $s['text'] ) {
			$css .= sprintf( 'body.login,#login #nav a,#login #backtoblog a{color:%s}', $s['text'] );
		}
		if ( '' !== $color ) {
			$css .= sprintf(
				'.login .button-primary{background:%1$s;border-color:%1$s;box-shadow:none}.login .button-primary:hover,.login .button-primary:focus{background:%1$s;border-color:%1$s;filter:brightness(.88)}.login input:focus{border-color:%1$s;box-shadow:0 0 0 1px %1$s}.login a:focus{box-shadow:0 0 0 2px %1$s}.login .button.wp-hide-pw .dashicons{color:%1$s}',
				$color
			);
		}
		echo '<style id="scout-brand-login">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from validated hex values and esc_url().
	}

	public static function login_url(): string {
		return home_url( '/' );
	}

	public static function login_text(): string {
		return get_bloginfo( 'name' );
	}

	public static function login_title( string $title, string $label ): string {
		return $label . ' ‹ ' . get_bloginfo( 'name' );
	}

	/* ----------------------------------------------------------- admin bar */

	public static function admin_bar( WP_Admin_Bar $bar ): void {
		$bar->remove_node( 'wp-logo' );

		$account = $bar->get_node( 'my-account' );
		if ( $account && is_string( $account->title ) ) {
			$user = wp_get_current_user();
			/* translators: %s: the user's display name */
			$hello = sprintf( __( 'Hi, %s', 'scout-build' ), '<span class="display-name">' . esc_html( $user->display_name ) . '</span>' );
			$title = preg_replace( '/^.*?<span class="display-name">.*?<\/span>/s', $hello, $account->title, 1 );
			if ( is_string( $title ) && $title !== $account->title ) {
				$bar->add_node( array( 'id' => 'my-account', 'title' => $title ) );
			}
		}

		if ( class_exists( 'Scout_Hub_Access' ) && ! Scout_Hub_Access::is_operator() ) {
			$bar->remove_node( 'updates' );
		}
	}

	/* -------------------------------------------------------------- footer */

	public static function footer_left(): string {
		$request = class_exists( 'Scout_Hub_Admin' ) ? Scout_Hub_Admin::url( 'requests' ) : '';
		$ask     = $request ? sprintf( ' <a href="%s">%s</a>', esc_url( $request ), esc_html__( 'Request a change', 'scout-build' ) ) : '';
		if ( class_exists( 'Scout_Hub_Admin' ) && Scout_Hub_Admin::branded() ) {
			return '<span id="footer-thankyou">' . esc_html__( 'Built and looked after by Scout Media.', 'scout-build' ) . $ask . '</span>';
		}
		return '<span id="footer-thankyou">' . esc_html__( 'Need something changed?', 'scout-build' ) . $ask . '</span>';
	}

	public static function footer_right( $text ): string {
		if ( class_exists( 'Scout_Hub_Access' ) && Scout_Hub_Access::is_operator() ) {
			return (string) $text;
		}
		return '';
	}

	/* ---------------------------------------------------------------- menu */

	public static function hide_menus(): void {
		if ( class_exists( 'Scout_Hub_Access' ) && Scout_Hub_Access::is_operator() ) {
			return;
		}
		foreach ( self::settings()['hide_menus'] as $slug ) {
			if ( false !== strpos( $slug, '>' ) ) {
				// "parent.php>child.php" hides one submenu item.
				list( $parent, $child ) = array_map( 'trim', explode( '>', $slug, 2 ) );
				remove_submenu_page( $parent, $child );
			} else {
				remove_menu_page( $slug );
			}
		}
	}

	/* ------------------------------------------------------------- message */

	public static function message(): void {
		$text = trim( self::settings()['message'] );
		if ( '' === $text ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'dashboard' !== $screen->id ) {
			return;
		}
		$hash = md5( $text );
		if ( get_user_meta( get_current_user_id(), self::DISMISS, true ) === $hash ) {
			return;
		}
		printf(
			'<div class="notice notice-info is-dismissible scout-brand-message" data-hash="%1$s"><p>%2$s</p></div>',
			esc_attr( $hash ),
			wp_kses( $text, array( 'a' => array( 'href' => true ), 'strong' => array(), 'em' => array() ) )
		);
		?>
		<script>
		document.addEventListener( 'click', function ( e ) {
			var box = e.target.closest( '.scout-brand-message .notice-dismiss' ) && e.target.closest( '.scout-brand-message' );
			if ( ! box ) { return; }
			var body = new URLSearchParams( { action: 'scout_brand_dismiss', hash: box.dataset.hash, _ajax_nonce: <?php echo wp_json_encode( wp_create_nonce( 'scout_brand_dismiss' ) ); ?> } );
			fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } );
		} );
		</script>
		<?php
	}

	public static function dismiss(): void {
		check_ajax_referer( 'scout_brand_dismiss' );
		$hash = isset( $_POST['hash'] ) ? sanitize_key( wp_unslash( $_POST['hash'] ) ) : '';
		if ( $hash ) {
			update_user_meta( get_current_user_id(), self::DISMISS, $hash );
		}
		wp_send_json_success();
	}
}
