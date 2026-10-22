<?php
/**
 * Scout Mail: the site's email (form notices, password resets, Scout checks)
 * goes out through a real Google mailbox instead of the host's mail server.
 *
 * Managed hosts like WP Engine send from their own servers, which fail the
 * sender domain's SPF, so the mail lands in junk or vanishes. Here the site
 * signs in to Google's mail server (smtp.gmail.com) with one Gmail or Google
 * Workspace address and a Google app password, and sends as that address.
 * Whatever "From" a form set (a client's own address, say) becomes the
 * Reply-To, so replies still reach the right person.
 *
 * One screen: address, app password, sender name, Send test. The password is
 * stored encrypted with the site's own salts, never shown again, and can live
 * in wp-config.php instead:  define( 'SCOUT_MAIL_PASSWORD', 'abcd efgh ijkl mnop' );
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_Mail {

	const PAGE   = 'scout-mail';
	const OPTION = 'scout_mail';
	const SAVE   = 'scout_mail_save';
	const TEST   = 'scout_mail_test';
	const HOST   = 'smtp.gmail.com';
	const PORT   = 587;

	/** @var string The last wp_mail error during a test send. */
	private static $error = '';

	public static function boot(): void {
		add_action( 'phpmailer_init', array( __CLASS__, 'mailer' ), 1000 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_' . self::SAVE, array( __CLASS__, 'save' ) );
		add_action( 'admin_post_' . self::TEST, array( __CLASS__, 'test' ) );
		add_filter( 'scout_admin_nav_hint', array( __CLASS__, 'nav_hint' ), 10, 2 );
		add_filter( 'gform_pre_send_email', array( __CLASS__, 'brand_form' ), 20, 4 );
	}

	/** @return array{email:string, name:string, password:string, accent:string, last:array} */
	private static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'email'    => (string) ( $s['email'] ?? '' ),
			'name'     => (string) ( $s['name'] ?? '' ),
			'password' => (string) ( $s['password'] ?? '' ),
			'accent'   => (string) ( $s['accent'] ?? '' ),
			'last'     => is_array( $s['last'] ?? null ) ? $s['last'] : array(),
		);
	}

	private static function password(): string {
		if ( defined( 'SCOUT_MAIL_PASSWORD' ) && '' !== (string) SCOUT_MAIL_PASSWORD ) {
			return (string) SCOUT_MAIL_PASSWORD;
		}
		return self::decrypt( self::settings()['password'] );
	}

	/** Set up: an address and a password are both saved. */
	public static function ready(): bool {
		return is_email( self::settings()['email'] ) && '' !== self::password();
	}

	/* ---------------------------------------------------------------- */

	/** Route every wp_mail through Google once the screen is filled in. */
	public static function mailer( $phpmailer ): void {
		if ( ! self::ready() ) {
			return;
		}
		$s    = self::settings();
		$from = $phpmailer->From;
		$name = $phpmailer->FromName;

		$phpmailer->isSMTP();
		$phpmailer->Host       = (string) apply_filters( 'scout_mail_host', self::HOST );
		$phpmailer->Port       = (int) apply_filters( 'scout_mail_port', self::PORT );
		$phpmailer->SMTPSecure = 'tls';
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Username   = $s['email'];
		$phpmailer->Password   = self::password();

		// Google only sends as the signed-in address. A different From the
		// email asked for (a form's "send as the business owner") becomes the
		// Reply-To, unless the email already set one.
		if ( $from && strtolower( $from ) !== strtolower( $s['email'] ) && is_email( $from ) && ! $phpmailer->getReplyToAddresses() && ! self::is_default_from( $from ) ) {
			$phpmailer->addReplyTo( $from, $name );
		}
		$phpmailer->setFrom( $s['email'], '' !== $s['name'] ? $s['name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), false );
		$phpmailer->Sender = $s['email'];
	}

	/** WordPress's own made-up wordpress@domain sender is not worth replying to. */
	private static function is_default_from( string $from ): bool {
		return 0 === strpos( strtolower( $from ), 'wordpress@' );
	}

	/* ---------------------------------------------------------------- */

	public static function menu(): void {
		add_submenu_page( Scout_Hub_Admin::parent(), __( 'Email', 'scout-build' ), __( 'Email', 'scout-build' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::PAGE ) ) {
			Scout_Admin_UI::enqueue( SCOUT_HUB_URL . 'assets/scout-admin.css' );
		}
	}

	public static function nav_hint( string $hint, string $slug ): string {
		if ( self::PAGE === $slug ) {
			return self::ready() ? __( 'On', 'scout-build' ) : __( 'Not set up', 'scout-build' );
		}
		return $hint;
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/** One line for Site health. */
	public static function check(): array {
		$s = self::settings();
		if ( ! self::ready() ) {
			return array(
				'label'     => __( 'Site email', 'scout-build' ),
				'value'     => __( 'Host mail', 'scout-build' ),
				'state'     => 'warn',
				'note'      => __( 'Form emails go out through the host, which often lands them in junk. Set up Email to send through Google.', 'scout-build' ),
				'link'      => self::url(),
				'link_text' => __( 'Set up Email', 'scout-build' ),
			);
		}
		$last = $s['last'];
		$bad  = isset( $last['ok'] ) && ! $last['ok'];
		return array(
			'label'     => __( 'Site email', 'scout-build' ),
			'value'     => $bad ? __( 'Last test failed', 'scout-build' ) : __( 'Through Google', 'scout-build' ),
			'state'     => $bad ? 'bad' : ( empty( $last ) ? 'warn' : 'good' ),
			/* translators: %s: email address */
			'note'      => empty( $last ) ? sprintf( __( 'Sends as %s. Send a test to confirm it arrives.', 'scout-build' ), $s['email'] ) : sprintf( __( 'Sends as %s.', 'scout-build' ), $s['email'] ),
			'link'      => self::url(),
			'link_text' => __( 'Email settings', 'scout-build' ),
		);
	}

	/* ---------------------------------------------------------------- */

	public static function save(): void {
		check_admin_referer( self::SAVE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only site admins can change the site email.', 'scout-build' ), 403 );
		}
		$s     = self::settings();
		$email = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );
		$pass  = preg_replace( '/\s+/', '', wp_unslash( (string) ( $_POST['password'] ?? '' ) ) );
		$clear = ! empty( $_POST['clear'] );

		if ( $clear ) {
			delete_option( self::OPTION );
			self::notice( __( 'Email turned off. The site sends through the host again.', 'scout-build' ), 'success' );
			self::back();
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			self::notice( __( 'That email address does not look right.', 'scout-build' ), 'error' );
			self::back();
		}
		$s['email'] = $email;
		$s['name']  = sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) );
		$accent      = sanitize_hex_color( wp_unslash( (string) ( $_POST['accent'] ?? '' ) ) );
		// Only keep a color someone changed; the detected one stays automatic.
		$s['accent'] = ( $accent && strtolower( $accent ) !== strtolower( self::detected_accent() ) ) ? $accent : '';
		if ( '' !== $pass ) {
			$s['password'] = self::encrypt( $pass );
			$s['last']     = array();
		}
		update_option( self::OPTION, $s, false );
		self::notice( self::ready() ? __( 'Saved. Send a test to make sure it arrives.', 'scout-build' ) : __( 'Saved. Add the app password to switch it on.', 'scout-build' ), 'success' );
		self::back();
	}

	public static function test(): void {
		check_admin_referer( self::TEST );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only site admins can send a test.', 'scout-build' ), 403 );
		}
		$to = sanitize_email( wp_unslash( (string) ( $_POST['to'] ?? '' ) ) );
		if ( ! is_email( $to ) ) {
			self::notice( __( 'Enter the address the test should go to.', 'scout-build' ), 'error' );
			self::back();
		}
		add_action(
			'wp_mail_failed',
			static function ( $error ) {
				self::$error = is_wp_error( $error ) ? $error->get_error_message() : '';
			}
		);
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$html = static fn() => 'text/html';
		add_filter( 'wp_mail_content_type', $html );
		/* translators: %s: site name */
		$ok = wp_mail( $to, sprintf( __( 'Your website can send email: %s', 'scout-build' ), $site ), self::test_body( $site ) );
		remove_filter( 'wp_mail_content_type', $html );

		$s         = self::settings();
		$s['last'] = array( 'ok' => (bool) $ok, 'to' => $to, 'at' => time(), 'error' => $ok ? '' : self::$error );
		update_option( self::OPTION, $s, false );

		if ( $ok ) {
			/* translators: %s: email address */
			self::notice( sprintf( __( 'Sent to %s. Check that inbox (and junk, the first time).', 'scout-build' ), $to ), 'success' );
		} else {
			self::notice( __( 'Google did not accept it: ', 'scout-build' ) . self::explain( self::$error ), 'error' );
		}
		self::back();
	}

	/**
	 * The test email: a small card in the site's own colors, with its icon,
	 * that says plainly what just worked. Inline styles only, so it renders
	 * the same in Gmail, Apple Mail and Outlook.
	 */
	private static function test_body( string $site ): string {
		$s    = self::settings();
		$url  = home_url( '/' );
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		/* translators: 1: site address, 2: sending address */
		$text = sprintf( __( 'This came from %1$s through Google, sent as %2$s. Form messages, booking notes and password resets will arrive the same way, in the inbox instead of junk.', 'scout-build' ), $host, $s['email'] );
		return self::card(
			$site,
			__( 'Your website can send email.', 'scout-build' ),
			'<p style="margin:0;text-align:center">' . esc_html( $text ) . '</p>',
			array( array( __( 'Visit the site', 'scout-build' ), $url ) ),
			Scout_Hub_Admin::branded() ? __( 'Sent from the Scout Email screen. Built and looked after by Scout Media & Consulting.', 'scout-build' ) : __( 'Sent from the Email screen.', 'scout-build' )
		);
	}

	/**
	 * One email card in the site's own color with its icon: the test email and
	 * every form message use it. Inline styles only, so it renders the same in
	 * Gmail, Apple Mail and Outlook.
	 *
	 * @param string $eyebrow Small line above the title (the site name).
	 * @param string $title   The headline.
	 * @param string $body    Body HTML, already escaped by the caller.
	 * @param array  $buttons Each [ label, url ]; the first is filled, the rest outlined.
	 * @param string $footer  Plain text for the footer line.
	 */
	private static function card( string $eyebrow, string $title, string $body, array $buttons, string $footer ): string {
		$accent = self::accent();
		$ink    = self::ink( $accent );
		$icon   = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 160 ) : '';
		ob_start();
		?>
<!doctype html><html><body style="margin:0;padding:0;background:#F5F5F7"><!-- scout-card -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F5F5F7;padding:32px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border-radius:18px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1D1D1F">
<tr><td style="height:8px;background:<?php echo esc_attr( $accent ); ?>"></td></tr>
<tr><td style="padding:32px 32px 8px;text-align:center">
<?php if ( $icon ) : ?><img src="<?php echo esc_url( $icon ); ?>" width="72" height="72" alt="" style="display:block;margin:0 auto 18px;border-radius:50%"><?php endif; ?>
<p style="margin:0 0 6px;font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#86868B"><?php echo esc_html( $eyebrow ); ?></p>
<h1 style="margin:0;font-size:24px;line-height:1.25;font-weight:600;letter-spacing:-.02em"><?php echo esc_html( $title ); ?></h1>
</td></tr>
<tr><td style="padding:16px 32px 8px;font-size:16px;line-height:1.6;color:#3A3A3C"><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller. ?></td></tr>
<?php if ( $buttons ) : ?>
<tr><td style="padding:20px 32px 32px;text-align:center">
<?php
foreach ( array_values( $buttons ) as $i => $b ) {
	$style = 0 === $i
		? 'background:' . $accent . ';color:' . $ink . ';border:2px solid ' . $accent
		: 'background:#FFFFFF;color:#1D1D1F;border:2px solid ' . $accent;
	echo '<a href="' . esc_url( (string) $b[1], array( 'http', 'https', 'tel', 'sms', 'mailto' ) ) . '" style="display:inline-block;margin:4px;' . esc_attr( $style ) . ';text-decoration:none;font-weight:600;font-size:15px;padding:10px 22px;border-radius:980px">' . esc_html( (string) $b[0] ) . '</a>';
}
?>
</td></tr>
<?php endif; ?>
<tr><td style="padding:16px 32px;border-top:1px solid #EDEDF0;font-size:12px;color:#86868B;text-align:center"><?php echo esc_html( $footer ); ?></td></tr>
</table></td></tr></table></body></html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Text color for a button filled with $hex: white when it reads at 4.5:1
	 * (WCAG AA), else near-black. Carolina blue and other light colors get dark text.
	 */
	private static function ink( string $hex ): string {
		$lum = 0.0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $w ) {
			$c    = hexdec( substr( ltrim( $hex, '#' ), $i * 2, 2 ) ) / 255;
			$lum += $w * ( $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4 );
		}
		return 1.05 / ( $lum + 0.05 ) >= 4.5 ? '#FFFFFF' : '#1D1D1F';
	}

	/**
	 * Gravity Forms messages arrive in the same card as the test email, in the
	 * site's color with its icon, with Call and Text buttons for the first phone
	 * number in the message and Email back for the first email address (business
	 * notifications only, never a confirmation sent to the customer). Only HTML
	 * notifications, only while Scout Email is set up, and never twice. Off
	 * switch: scout_mail_brand_forms.
	 *
	 * @param array  $email        to, subject, message, headers, attachments, abort_email.
	 * @param string $format       html or text.
	 * @param array  $notification The notification settings.
	 * @param array  $entry        The entry.
	 */
	public static function brand_form( $email, $format = 'html', $notification = array(), $entry = array() ) {
		if ( ! is_array( $email ) || 'html' !== $format || ! self::ready() || ! apply_filters( 'scout_mail_brand_forms', true, $notification, $entry ) ) {
			return $email;
		}
		$message = (string) ( $email['message'] ?? '' );
		if ( '' === trim( $message ) || false !== strpos( $message, '<!-- scout-card -->' ) ) {
			return $email;
		}
		// Gravity Forms may already wrap the message in <html><body>: keep only what is inside.
		if ( preg_match( '#<body[^>]*>(.*)</body>#is', $message, $inner ) ) {
			$message = $inner[1];
		}
		$message = preg_replace( '#</?(html|head|body)[^>]*>|<head>.*?</head>#is', '', $message );
		// Call, Text and Email back are for the business. A message going to the
		// person who filled in the form (a confirmation) gets no contact buttons.
		$to_customer = 'field' === ( $notification['toType'] ?? '' );
		$buttons     = array();
		if ( ! $to_customer && ( preg_match( '#href=["\']tel:([+0-9().\s-]{7,})["\']#i', $message, $m ) || preg_match( '#(\(?\d{3}\)?[\s.-]?\d{3}[\s.-]\d{4})#', wp_strip_all_tags( $message ), $m ) ) ) {
			$phone  = trim( $m[1] );
			$digits = preg_replace( '/[^0-9+]/', '', $m[1] );
			if ( strlen( ltrim( $digits, '+' ) ) >= 10 ) {
				$buttons[] = array( __( 'Call', 'scout-build' ), 'tel:' . $digits );
				$buttons[] = array( __( 'Text', 'scout-build' ), 'sms:' . $digits );
			}
		}
		if ( ! $to_customer && preg_match( '#href=["\']mailto:([^"\'?]+)#i', $message, $m ) && is_email( rawurldecode( $m[1] ) ) ) {
			$buttons[] = array( __( 'Email back', 'scout-build' ), 'mailto:' . rawurldecode( $m[1] ) );
		}
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$footer = Scout_Hub_Admin::branded()
			/* translators: %s: site address */
			? sprintf( __( 'Sent from a form on %s. Website by Scout Media & Consulting.', 'scout-build' ), (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) )
			/* translators: %s: site address */
			: sprintf( __( 'Sent from a form on %s.', 'scout-build' ), (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$title = wp_specialchars_decode( (string) ( $email['subject'] ?? '' ), ENT_QUOTES );
		// The number already has its Call button, and mail apps turn a number in
		// the headline into a big link, so the headline drops it.
		if ( isset( $phone ) && '' !== $phone ) {
			$title = trim( preg_replace( '/\s*[(\[]?\s*' . preg_quote( $phone, '/' ) . '\s*[)\]]?/', '', $title ), " \t-:|," ) ?: $title;
		}
		$email['message'] = self::card( $site, $title, wp_kses_post( $message ), $buttons, $footer );
		return $email;
	}

	/**
	 * Colors to click on the Email screen: the block theme's own palette
	 * (grays, near-white and near-black left out), the Cards and Reviews
	 * colors, and Scout's cerulean. Hex code => name.
	 *
	 * @return array<string,string>
	 */
	private static function swatches(): array {
		$out = array();
		$add = static function ( $hex, $name ) use ( &$out ) {
			$hex = strtoupper( (string) $hex );
			if ( ! preg_match( '/^#[0-9A-F]{6}$/', $hex ) || isset( $out[ $hex ] ) ) {
				return;
			}
			$rgb = array_map( 'hexdec', str_split( substr( $hex, 1 ), 2 ) );
			$max = max( $rgb );
			$min = min( $rgb );
			if ( $max - $min < 24 || $max < 40 || $min > 235 ) {
				return; // Grays, near-black and near-white make poor buttons.
			}
			$out[ $hex ] = (string) $name;
		};
		$add( self::detected_accent(), __( 'Detected', 'scout-build' ) );
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palettes = (array) wp_get_global_settings( array( 'color', 'palette' ) );
			foreach ( array( 'custom', 'theme' ) as $origin ) {
				foreach ( (array) ( $palettes[ $origin ] ?? array() ) as $c ) {
					$add( $c['color'] ?? '', $c['name'] ?? ( $c['slug'] ?? '' ) );
				}
			}
		}
		foreach ( array( 'scout_cards_settings', 'scout_reviews_brand', 'scout_reviews_settings' ) as $opt ) {
			$o = get_option( $opt );
			if ( is_array( $o ) ) {
				$add( $o['accent'] ?? '', __( 'Brand', 'scout-build' ) );
			}
		}
		$add( '#0A78C2', 'Scout' );
		return array_slice( $out, 0, 16, true );
	}

	/** The email color: the one picked on this screen, else the detected brand color. */
	private static function accent(): string {
		$picked = self::settings()['accent'];
		return preg_match( '/^#[0-9a-fA-F]{6}$/', $picked ) ? $picked : self::detected_accent();
	}

	/**
	 * The site's brand color: the scout_brand_accent filter, Scout Cards, Scout
	 * Reviews, a block theme's accent/primary/brand color, then Scout's own
	 * darker cerulean.
	 */
	private static function detected_accent(): string {
		$hex      = '/^#[0-9a-fA-F]{6}$/';
		$filtered = (string) apply_filters( 'scout_brand_accent', '' );
		if ( preg_match( $hex, $filtered ) ) {
			return $filtered;
		}
		foreach ( array( 'scout_cards_settings', 'scout_reviews_brand', 'scout_reviews_settings' ) as $opt ) {
			$o = get_option( $opt );
			if ( is_array( $o ) && ! empty( $o['accent'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $o['accent'] ) ) {
				return (string) $o['accent'];
			}
		}
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$palette = (array) wp_get_global_settings( array( 'color', 'palette', 'theme' ) );
			foreach ( array( 'accent', 'primary', 'brand' ) as $want ) {
				foreach ( $palette as $c ) {
					if ( ( $c['slug'] ?? '' ) === $want && preg_match( $hex, (string) ( $c['color'] ?? '' ) ) ) {
						return (string) $c['color'];
					}
				}
			}
		}
		return '#0A78C2';
	}

	/** Turn Google's error text into the fix. */
	private static function explain( string $error ): string {
		if ( preg_match( '/authenticat|password|535|534|Username and Password/i', $error ) ) {
			return __( 'the address or app password is wrong. Make a new app password for this address and paste it in.', 'scout-build' );
		}
		if ( preg_match( '/connect|timed out|could not/i', $error ) ) {
			return __( 'the site could not reach Google\'s mail server. Ask the host whether port 587 is open.', 'scout-build' );
		}
		return '' !== $error ? $error : __( 'no reason given.', 'scout-build' );
	}

	private static function notice( string $message, string $type ): void {
		set_transient( 'scout_mail_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), MINUTE_IN_SECONDS );
	}

	private static function back(): void {
		wp_safe_redirect( self::url() );
		exit;
	}

	/* ---------------------------------------------------------------- */

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$ready = self::ready();
		$in_config = defined( 'SCOUT_MAIL_PASSWORD' ) && '' !== (string) SCOUT_MAIL_PASSWORD;
		Scout_Admin_UI::open( __( 'Email', 'scout-build' ), __( 'Send the site\'s email (form notices, password resets) through Google, so it reaches the inbox instead of junk.', 'scout-build' ) );

		$key    = 'scout_mail_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), esc_html( $notice['message'] ) );
		}
		if ( function_exists( 'wp_mail_smtp' ) || defined( 'WPMS_PLUGIN_VER' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP Mail SMTP is also installed. Scout Email takes over once it is set up here, so you can delete WP Mail SMTP.', 'scout-build' ) . '</p></div>';
		}
		$last = $s['last'];
		?>
		<style>
			.sm-steps{margin:0; padding-left:20px; display:grid; gap:6px}
			.sm-row{display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px}
			.sm-row label{display:grid; gap:6px; font-weight:600}
			.sm-row input{width:100%}
			.sm-color{display:grid; gap:6px; align-content:start}
			.sm-color > label{font-weight:600}
			.sm-color-row{display:flex; gap:8px; align-items:center}
			.sm-chip{flex:0 0 36px; height:36px; border-radius:8px; border:1px solid #dcdcde}
			.sm-swatches{display:flex; flex-wrap:wrap; gap:6px}
			.sm-swatch{width:28px; height:28px; padding:0; border-radius:50%; border:2px solid #fff; box-shadow:0 0 0 1px #c3c4c7; cursor:pointer}
			.sm-swatch:hover,.sm-swatch:focus{box-shadow:0 0 0 2px #0A78C2; outline:none}
			.sm-actions{display:flex; flex-wrap:wrap; gap:10px; align-items:end; margin-top:14px}
			.sm-actions label{display:grid; gap:6px; font-weight:600; flex:1 1 240px}
		</style>
		<div class="sh-grid sh-grid-2">
			<section class="sh-box sh-check is-<?php echo esc_attr( $ready ? ( isset( $last['ok'] ) && ! $last['ok'] ? 'bad' : 'good' ) : 'warn' ); ?>">
				<p class="sh-label"><?php esc_html_e( 'Status', 'scout-build' ); ?></p>
				<p class="sh-value"><span class="sh-dot" aria-hidden="true"></span><?php echo esc_html( $ready ? __( 'Sending through Google', 'scout-build' ) : __( 'Not set up: the host sends', 'scout-build' ) ); ?></p>
				<p class="sh-note">
					<?php
					if ( ! empty( $last['at'] ) ) {
						/* translators: 1: result, 2: address, 3: date */
						echo esc_html( sprintf( __( 'Last test %1$s to %2$s, %3$s.', 'scout-build' ), $last['ok'] ? __( 'sent', 'scout-build' ) : __( 'failed', 'scout-build' ), $last['to'], wp_date( 'M j, g:i a', (int) $last['at'] ) ) );
					} else {
						esc_html_e( 'No test sent yet.', 'scout-build' );
					}
					?>
				</p>
			</section>
			<section class="sh-box">
				<p class="sh-label"><?php esc_html_e( 'Set it up once', 'scout-build' ); ?></p>
				<ol class="sm-steps">
					<li><?php esc_html_e( 'Sign in to Google as the address the site should send from.', 'scout-build' ); ?></li>
					<li><?php echo wp_kses( __( 'Open <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a>, name it after this site, and copy the 16 letters.', 'scout-build' ), array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ); ?></li>
					<li><?php esc_html_e( 'Paste them below, Save, then Send test.', 'scout-build' ); ?></li>
				</ol>
			</section>
		</div>

		<section class="sh-box" style="margin-top:14px">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>">
				<?php wp_nonce_field( self::SAVE ); ?>
				<div class="sm-row">
					<label><?php esc_html_e( 'Send from (Gmail or Google Workspace)', 'scout-build' ); ?>
						<input type="email" name="email" value="<?php echo esc_attr( $s['email'] ); ?>" placeholder="name@gmail.com" autocomplete="off">
					</label>
					<label><?php esc_html_e( 'App password', 'scout-build' ); ?>
						<input type="password" name="password" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $in_config ? __( 'Set in wp-config.php', 'scout-build' ) : ( '' !== $s['password'] ? __( 'Saved. Paste a new one to replace it.', 'scout-build' ) : 'abcd efgh ijkl mnop' ) ); ?>" <?php disabled( $in_config ); ?>>
					</label>
					<label><?php esc_html_e( 'Sender name', 'scout-build' ); ?>
						<input type="text" name="name" value="<?php echo esc_attr( $s['name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>">
					</label>
					<div class="sm-color">
						<label for="sm-accent"><?php esc_html_e( 'Email color', 'scout-build' ); ?></label>
						<span class="sm-color-row">
							<span class="sm-chip" id="sm-chip" style="background:<?php echo esc_attr( self::accent() ); ?>" aria-hidden="true"></span>
							<input type="text" id="sm-accent" name="accent" value="<?php echo esc_attr( self::accent() ); ?>" maxlength="7" pattern="#[0-9a-fA-F]{6}" placeholder="#A04E10" spellcheck="false">
						</span>
						<span class="sm-swatches">
							<?php foreach ( self::swatches() as $hex => $name ) : ?>
								<button type="button" class="sm-swatch" data-c="<?php echo esc_attr( $hex ); ?>" style="background:<?php echo esc_attr( $hex ); ?>" title="<?php echo esc_attr( $name . ' ' . $hex ); ?>" aria-label="<?php echo esc_attr( $name . ' ' . $hex ); ?>"></button>
							<?php endforeach; ?>
						</span>
						<span class="sh-note"><?php esc_html_e( 'Click one of the site\'s colors, or type a hex code.', 'scout-build' ); ?></span>
					</div>
				</div>
				<script>
				( function () {
					var input = document.getElementById( 'sm-accent' ), chip = document.getElementById( 'sm-chip' );
					function show() { if ( /^#[0-9a-fA-F]{6}$/.test( input.value ) ) { chip.style.background = input.value; } }
					input.addEventListener( 'input', show );
					document.querySelectorAll( '.sm-swatch' ).forEach( function ( b ) {
						b.addEventListener( 'click', function () { input.value = b.getAttribute( 'data-c' ); show(); } );
					} );
				} )();
				</script>
				<p class="sh-note" style="margin-top:10px"><?php esc_html_e( 'Every email the site sends goes out as this address. When a form asks to send as someone else, that address becomes the reply-to, so replies still reach them.', 'scout-build' ); ?></p>
				<div class="sm-actions">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'scout-build' ); ?></button>
					<?php if ( '' !== $s['email'] || '' !== $s['password'] ) : ?>
						<button type="submit" name="clear" value="1" class="button" onclick="return confirm('<?php echo esc_js( __( 'Turn off Email and go back to the host\'s mail?', 'scout-build' ) ); ?>');"><?php esc_html_e( 'Turn off', 'scout-build' ); ?></button>
					<?php endif; ?>
				</div>
			</form>
		</section>

		<section class="sh-box" style="margin-top:14px">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="sm-actions" style="margin-top:0">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST ); ?>">
				<?php wp_nonce_field( self::TEST ); ?>
				<label><?php esc_html_e( 'Send a test to', 'scout-build' ); ?>
					<input type="email" name="to" value="<?php echo esc_attr( (string) ( $last['to'] ?? wp_get_current_user()->user_email ) ); ?>">
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Send test', 'scout-build' ); ?></button>
			</form>
			<?php if ( ! empty( $last['error'] ) ) : ?>
				<p class="sh-note" style="margin-top:10px"><?php echo esc_html( __( 'Google said: ', 'scout-build' ) . $last['error'] ); ?></p>
			<?php endif; ?>
		</section>
		<?php
		Scout_Admin_UI::close();
	}

	/* ---------------------------------------------------------------- */

	private static function key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . 'scout-mail', true );
	}

	private static function encrypt( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'p:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv = random_bytes( 16 );
		return 'e:' . base64_encode( $iv . openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'p:' ) ) {
			return $raw;
		}
		if ( ! function_exists( 'openssl_decrypt' ) || strlen( $raw ) < 17 ) {
			return '';
		}
		$out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $out ? '' : $out;
	}
}
