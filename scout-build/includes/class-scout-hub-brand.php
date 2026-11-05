<?php
/**
 * Scout Brand: the white-label layer for the WordPress admin, so a site can
 * drop a separate branding plugin (Branda Pro and the like).
 *
 * Opt-in (Joy, 2026-11-05). It does nothing on a site until that site turns
 * it on, so a client's admin looks exactly as before (Site Standard rule 20):
 *
 *   - the theme's scout-setup.json has a "brand" section (even an empty one), or
 *   - the scout_brand option is saved with "on": true (sites with no setup file).
 *
 * "on": false in the setup file's section turns it off again, and
 * define( 'SCOUT_BRAND_OFF', true ); in wp-config.php turns it all off.
 *
 * Five parts, each one can be listed in "off":
 *
 * 1. login:     the client's logo and brand color, the logo links to the site,
 *               and the tab title is the site name.
 * 2. admin_bar: no WordPress logo menu, "Hi, Name", and the updates counter
 *               only for operators (clients don't see updates on the dashboard either).
 * 3. footer:    who builds and looks after the site, with Request a change; the
 *               WordPress version only for operators. Branded sites name Scout
 *               Media; white-label sites (SCOUT_HUB_BRAND false) say "your web
 *               team" and name nobody (rule 26).
 * 4. menu:      menus listed in hide_menus are hidden from everyone but operators.
 * 5. message:   one short note at the top of the Dashboard, until each person dismisses it.
 *
 *   "brand": {
 *     "logo":        "assets/img/logo.svg",   // theme path or https:// address
 *     "logo_width":  220,                      // pixels on the login screen
 *     "color":       "#1A2E5A",                // buttons and links
 *     "background":  "#F6F8FB",                // login page background
 *     "text":        "#1B2150",                // login page text
 *     "message":     "Need a change? Use Request a change in the top bar.",
 *     "hide_menus":  [ "tools.php", "options-general.php>options-privacy.php" ],
 *     "off":         [ "admin_bar" ]           // any of: login, admin_bar, footer, menu, message
 *   }
 *
 * The setup file wins over the option, key by key.
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

	/** Names for the parts, for the status line on Updates. */
	const PART_NAMES = array(
		'login'     => 'Login screen',
		'admin_bar' => 'Admin bar',
		'footer'    => 'Footer',
		'menu'      => 'Hidden menus',
		'message'   => 'Dashboard message',
	);

	/** @var array|null Resolved settings, kept for the request. */
	private static $settings = null;

	/** @var string|null Where the brand came from, kept for the request. */
	private static $source = null;

	public static function boot(): void {
		// The setup file lives in the theme, so read it once the theme is known.
		add_action( 'after_setup_theme', array( __CLASS__, 'hook' ), 20 );
	}

	/** Add the hooks for each part that is on. Nothing at all without brand config. */
	public static function hook(): void {
		if ( ! self::active() ) {
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

	/**
	 * Where this site's brand comes from: "setup" (the theme's setup file),
	 * "option" (the scout_brand option), "config_off" (SCOUT_BRAND_OFF), or ""
	 * when the site never turned it on.
	 */
	public static function source(): string {
		if ( null !== self::$source ) {
			return self::$source;
		}
		if ( defined( 'SCOUT_BRAND_OFF' ) && SCOUT_BRAND_OFF ) {
			return self::$source = 'config_off';
		}
		$file = self::file_section();
		if ( null !== $file ) {
			// "on": false in the file turns it off, whatever the option says.
			return self::$source = ( false === ( $file['on'] ?? true ) ? '' : 'setup' );
		}
		$option = get_option( self::OPTION, null );
		if ( is_array( $option ) && ! empty( $option['on'] ) ) {
			return self::$source = 'option';
		}
		return self::$source = '';
	}

	public static function active(): bool {
		return in_array( self::source(), array( 'setup', 'option' ), true );
	}

	/** The setup file's "brand" section, or null when the file has none. */
	private static function file_section(): ?array {
		$data = class_exists( 'Scout_Hub_Setup' ) ? Scout_Hub_Setup::data() : array();
		return isset( $data['brand'] ) && is_array( $data['brand'] ) ? $data['brand'] : null;
	}

	/** @return array<string, mixed> */
	public static function settings(): array {
		if ( null !== self::$settings ) {
			return self::$settings;
		}
		$option = get_option( self::OPTION, array() );
		$option = is_array( $option ) ? $option : array();
		$s      = array_merge( $option, (array) self::file_section() );

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
		 * Filter the brand settings once they are read.
		 *
		 * @param array $settings Logo, colors, message, hidden menus, parts turned off.
		 */
		self::$settings = (array) apply_filters( 'scout_brand_settings', self::$settings );
		return self::$settings;
	}

	/** Forget what was read, so the next call reads the setup file and option again. */
	public static function reset(): void {
		self::$settings = null;
		self::$source   = null;
	}

	public static function on( string $part ): bool {
		return self::active() && ! in_array( $part, (array) self::settings()['off'], true );
	}

	private static function hex( $value ): string {
		$value = trim( (string) $value );
		return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ? $value : '';
	}

	/** Branded sites name Scout; white-label sites (SCOUT_HUB_BRAND false) name nobody. */
	public static function branded(): bool {
		if ( class_exists( 'Scout_Admin_UI' ) && method_exists( 'Scout_Admin_UI', 'branded' ) ) {
			return (bool) Scout_Admin_UI::branded();
		}
		if ( class_exists( 'Scout_Hub_Admin' ) ) {
			return Scout_Hub_Admin::branded();
		}
		return ! defined( 'SCOUT_HUB_BRAND' ) || (bool) SCOUT_HUB_BRAND;
	}

	private static function is_operator(): bool {
		return class_exists( 'Scout_Hub_Access' ) && Scout_Hub_Access::is_operator();
	}

	/**
	 * One line for operators on Scout tools, Updates: is the white-label layer on, and from where.
	 */
	public static function status_text(): string {
		switch ( self::source() ) {
			case 'setup':
				$text = __( 'White-label: on, from the setup file.', 'scout-build' );
				break;
			case 'option':
				$text = __( 'White-label: on, from the scout_brand setting.', 'scout-build' );
				break;
			case 'config_off':
				return __( 'White-label: off in wp-config.php (SCOUT_BRAND_OFF).', 'scout-build' );
			default:
				return __( 'White-label: off. A "brand" section in the setup file turns it on.', 'scout-build' );
		}
		$off = array_map( static fn( $p ) => self::PART_NAMES[ $p ] ?? $p, (array) self::settings()['off'] );
		if ( $off ) {
			/* translators: %s: parts, e.g. "Admin bar, Footer" */
			$text .= ' ' . sprintf( __( 'Turned off: %s.', 'scout-build' ), implode( ', ', $off ) );
		}
		return $text;
	}

	/** The login logo: the brand's logo, then the Customizer logo, then the Site Icon. */
	public static function logo_url(): string {
		$logo = (string) self::settings()['logo'];
		if ( '' !== $logo ) {
			return 0 === strpos( $logo, 'https://' ) ? $logo : (string) get_theme_file_uri( ltrim( str_replace( '..', '', $logo ), '/' ) );
		}
		$custom = (int) get_theme_mod( 'custom_logo' );
		if ( $custom ) {
			$src = wp_get_attachment_image_url( $custom, 'medium' );
			if ( $src ) {
				return (string) $src;
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
			// No logo anywhere: the site name shows as text instead of the WordPress mark.
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
		echo '<style id="scout-brand-login">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from checked hex values and esc_url().
	}

	public static function login_url(): string {
		return home_url( '/' );
	}

	public static function login_text(): string {
		return (string) get_bloginfo( 'name' );
	}

	public static function login_title( $title, $label = '' ): string {
		return (string) $label . ' ‹ ' . get_bloginfo( 'name' );
	}

	/* ----------------------------------------------------------- admin bar */

	public static function admin_bar( $bar ): void {
		$bar->remove_node( 'wp-logo' );

		$account = $bar->get_node( 'my-account' );
		if ( $account && is_string( $account->title ) ) {
			$user = wp_get_current_user();
			/* translators: %s: the person's display name */
			$hello = sprintf( __( 'Hi, %s', 'scout-build' ), '<span class="display-name">' . esc_html( $user->display_name ) . '</span>' );
			$title = preg_replace( '/^.*?<span class="display-name">.*?<\/span>/s', $hello, $account->title, 1 );
			if ( is_string( $title ) && $title !== $account->title ) {
				$bar->add_node( array( 'id' => 'my-account', 'title' => $title ) );
			}
		}

		if ( ! self::is_operator() ) {
			$bar->remove_node( 'updates' );
		}
	}

	/* -------------------------------------------------------------- footer */

	public static function footer_left( $text = '' ): string {
		$branded = self::branded();
		// Scout's own screens already end with the Scout sign-off, so it isn't said twice.
		if ( $branded && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && ( false !== strpos( (string) $screen->id, 'site-build' ) ) ) {
				return '';
			}
		}
		$request = class_exists( 'Scout_Hub_Admin' ) ? Scout_Hub_Admin::url( 'requests' ) : '';
		$ask     = '' !== $request ? sprintf( ' <a href="%s">%s</a>', esc_url( $request ), esc_html__( 'Request a change', 'scout-build' ) ) : '';
		$line    = $branded
			? __( 'Built and looked after by Scout Media.', 'scout-build' )
			: __( 'Built and looked after by your web team.', 'scout-build' );
		return '<span id="footer-thankyou">' . esc_html( $line ) . $ask . '</span>';
	}

	public static function footer_right( $text = '' ): string {
		return self::is_operator() ? (string) $text : '';
	}

	/* ---------------------------------------------------------------- menu */

	public static function hide_menus(): void {
		if ( self::is_operator() ) {
			return;
		}
		foreach ( (array) self::settings()['hide_menus'] as $slug ) {
			if ( false !== strpos( $slug, '>' ) ) {
				// "parent.php>child.php" hides one submenu item.
				list( $parent, $child ) = array_map( 'trim', explode( '>', $slug, 2 ) );
				remove_submenu_page( $parent, $child );
			} else {
				remove_menu_page( trim( $slug ) );
			}
		}
	}

	/* ------------------------------------------------------------- message */

	public static function message(): void {
		$text = trim( (string) self::settings()['message'] );
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
