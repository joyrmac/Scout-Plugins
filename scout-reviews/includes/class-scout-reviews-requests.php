<?php
/**
 * Review requests: one short link for every review ask, and the kit to share
 * it (QR code, printable cards, ready-to-send messages).
 *
 *   /review/          a phone page with the "leave a review" button
 *   /review/?via=qr   same, counted as a QR scan (also text, email, card, link)
 *   /review/note/     a private note form that lands in Reviews > Inbox
 *
 * Why the QR points at the site and not at Google: the printed cards outlive
 * any one link. If the review link changes, or the business adds Yelp, the
 * cards already on counters keep working because only this setting changes.
 *
 * Compliance, built in on purpose. Every client gets the same ask and the same
 * page: nothing asks whether they were happy first, and the private note link
 * sits below the review button as a quiet option, never as the alternative to
 * it (review gating, which Google's policy and FTC guidance both prohibit). No
 * incentive wording anywhere. Visits are counted as monthly totals per source;
 * no visitor data is stored.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Reviews_Requests {

	const OPTION = 'scout_reviews_requests';
	const STATS  = 'scout_reviews_request_stats';
	const ROUTES = 'scout_reviews_request_routes';
	const PAGE   = 'scout-reviews-ask';
	const VAR    = 'scout_review_go';

	/** Where a visit came from. Anything else counts as "link". */
	const VIA = array( 'qr', 'text', 'email', 'card', 'link' );

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 1 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), Scout_Reviews_Menu::PRIORITY + 2 );
			add_action( 'admin_init', array( __CLASS__, 'register' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults(): array {
		return array(
			'enabled'       => true,
			'slug'          => 'review',
			'mode'          => 'page', // page: the review page. direct: skip it and go straight to one platform.
			'primary'       => 'google',
			'notes'         => true,   // The private note form at /review/note/.
			'note_email'    => '',     // Who gets private notes. Empty: the site admin email.
			'business'      => '',
			'headline'      => __( 'Thanks for working with us.', 'scout-reviews' ),
			'ask'           => __( 'An honest review takes about a minute and helps other people find us.', 'scout-reviews' ),
			'photo'         => '',
			'card_headline' => '',
			'accent'        => '#0E8FE6',
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$s     = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		if ( '' === trim( $s['business'] ) ) {
			$s['business'] = self::short_name( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		}
		return $s;
	}

	/**
	 * Site titles often carry a tagline for Google ("Smith Law | Raleigh, NC").
	 * Cards and messages only want the name, so drop what follows a separator.
	 */
	public static function short_name( string $title ): string {
		$parts = preg_split( '/\s+[|\x{2013}\x{2014}-]\s+/u', $title, 2 );
		$name  = trim( (string) ( $parts[0] ?? '' ) );
		return '' !== $name ? $name : $title;
	}

	public static function register(): void {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$d     = self::defaults();
		$slug  = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$color = sanitize_hex_color( (string) ( $input['accent'] ?? '' ) );
		$prime = sanitize_key( (string) ( $input['primary'] ?? '' ) );
		$text  = static function ( string $key ) use ( $input, $d ): string {
			$value = sanitize_text_field( (string) ( $input[ $key ] ?? '' ) );
			return '' !== $value ? $value : $d[ $key ];
		};

		return array(
			'enabled'       => ! empty( $input['enabled'] ),
			'slug'          => '' !== $slug ? $slug : $d['slug'],
			'mode'          => ( 'direct' === ( $input['mode'] ?? '' ) ) ? 'direct' : 'page',
			'primary'       => Scout_Reviews_Sources::exists( $prime ) ? $prime : $d['primary'],
			'notes'         => ! empty( $input['notes'] ),
			'note_email'    => sanitize_email( (string) ( $input['note_email'] ?? '' ) ),
			'business'      => sanitize_text_field( (string) ( $input['business'] ?? '' ) ),
			'headline'      => $text( 'headline' ),
			'ask'           => $text( 'ask' ),
			'photo'         => esc_url_raw( trim( (string) ( $input['photo'] ?? '' ) ), array( 'https', 'http' ) ),
			'card_headline' => sanitize_text_field( (string) ( $input['card_headline'] ?? '' ) ),
			'accent'        => $color ? $color : $d['accent'],
		);
	}

	/**
	 * Platforms with a "leave a review" link, in the sources' order.
	 *
	 * @return array<string, array{label:string, url:string}>
	 */
	public static function write_links(): array {
		$out = array();
		foreach ( Scout_Reviews_Sources::all() as $slug => $info ) {
			if ( 'direct' === $slug ) {
				continue;
			}
			$url = Scout_Reviews_Settings::profile( $slug )['write_url'];
			if ( '' !== $url ) {
				$out[ $slug ] = array(
					'label' => $info['label'],
					'url'   => $url,
				);
			}
		}
		return $out;
	}

	/**
	 * The main platform: the chosen one when it has a link, otherwise the
	 * first platform that does.
	 *
	 * @return array{slug:string, label:string, url:string}|null
	 */
	public static function target(): ?array {
		$links = self::write_links();
		if ( ! $links ) {
			return null;
		}
		$slug = self::get()['primary'];
		if ( ! isset( $links[ $slug ] ) ) {
			$slug = (string) array_key_first( $links );
		}
		return array( 'slug' => $slug ) + $links[ $slug ];
	}

	/** The public short link, optionally tagged with where it was shared. */
	public static function link( string $via = '' ): string {
		$url = home_url( '/' . self::get()['slug'] . '/' );
		return '' !== $via ? add_query_arg( 'via', $via, $url ) : $url;
	}

	public static function note_link(): string {
		return home_url( '/' . self::get()['slug'] . '/note/' );
	}

	/** The printed card headline: the saved one, or "Tell Google how we did." */
	public static function card_headline( array $s ): string {
		if ( '' !== trim( $s['card_headline'] ) ) {
			return $s['card_headline'];
		}
		$target = self::target();
		return $target
			? sprintf( /* translators: %s: platform, e.g. Google */ __( 'Tell %s how we did.', 'scout-reviews' ), $target['label'] )
			: __( 'Tell us how we did.', 'scout-reviews' );
	}

	/* ------------------------------------------------------------------ */
	/* The /review/ addresses                                              */
	/* ------------------------------------------------------------------ */

	public static function rules(): void {
		$s = self::get();
		if ( $s['enabled'] ) {
			$base = preg_quote( $s['slug'], '#' );
			add_rewrite_rule( '^' . $base . '/note/?$', 'index.php?' . self::VAR . '=note', 'top' );
			add_rewrite_rule( '^' . $base . '/?$', 'index.php?' . self::VAR . '=page', 'top' );
		}
		$stamp = SCOUT_REVIEWS_VERSION . '|' . $s['slug'] . '|' . (int) $s['enabled'];
		if ( get_option( self::ROUTES ) !== $stamp ) {
			flush_rewrite_rules( false );
			update_option( self::ROUTES, $stamp, false );
		}
	}

	public static function query_vars( array $vars ): array {
		$vars[] = self::VAR;
		return $vars;
	}

	public static function dispatch(): void {
		$view = get_query_var( self::VAR );
		if ( ! $view ) {
			return;
		}
		nocache_headers(); // A cached page would skip the count and could outlive a changed link.
		header( 'X-Robots-Tag: noindex, nofollow' );
		$s = self::get();

		if ( 'note' === $view ) {
			if ( ! $s['notes'] ) {
				wp_safe_redirect( self::link() );
				exit;
			}
			Scout_Reviews_Notes::route( $s );
			exit;
		}

		$via = sanitize_key( (string) ( $_GET['via'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::count( in_array( $via, self::VIA, true ) ? $via : 'link' );

		$target = self::target();
		if ( 'direct' === $s['mode'] && $target ) {
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the review platforms are other sites by design.
			wp_redirect( $target['url'], 302, 'Scout Reviews' );
			exit;
		}
		Scout_Reviews_Notes::review_page( $s, $target, self::write_links() );
		exit;
	}

	/**
	 * Monthly totals per source. Link previews (a text app fetching the link
	 * to draw a preview) and signed-in staff are skipped, so pasting or testing
	 * the link does not count.
	 */
	private static function count( string $via ): void {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return;
		}
		$agent = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( '' === $agent || preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|discord|slack|skype|embedly|quora|pinterest|vkshare|w3c_validator/i', $agent ) ) {
			return;
		}
		$stats = get_option( self::STATS, array() );
		$stats = is_array( $stats ) ? $stats : array();
		$month = wp_date( 'Y-m' );
		$stats[ $month ][ $via ] = (int) ( $stats[ $month ][ $via ] ?? 0 ) + 1;
		krsort( $stats );
		update_option( self::STATS, array_slice( $stats, 0, 13, true ), false );
	}

	/* ------------------------------------------------------------------ */
	/* Reviews > Ask for reviews                                           */
	/* ------------------------------------------------------------------ */

	public static function menu(): void {
		add_submenu_page(
			Scout_Reviews_Menu::parent(),
			__( 'Ask for reviews', 'scout-reviews' ),
			__( 'Ask for reviews', 'scout-reviews' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) && false === strpos( $hook, Scout_Reviews_Inbox::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'scout-reviews-fonts', 'https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600&family=Geist+Mono:wght@500;600&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'scout-reviews-ask', SCOUT_REVIEWS_URL . 'assets/ask.css', array(), SCOUT_REVIEWS_VERSION );
		if ( false !== strpos( $hook, self::PAGE ) ) {
			wp_enqueue_script( 'scout-reviews-qr', SCOUT_REVIEWS_URL . 'assets/qrcode.min.js', array(), SCOUT_REVIEWS_VERSION, true );
			wp_enqueue_script( 'scout-reviews-ask', SCOUT_REVIEWS_URL . 'assets/ask.js', array( 'scout-reviews-qr' ), SCOUT_REVIEWS_VERSION, true );
		}
	}

	/** The ready-to-send messages, with the business and link filled in. */
	public static function messages( array $s, ?array $target ): array {
		$text = sprintf(
			/* translators: 1: business name, 2: review link */
			__( 'Hi [first name], thank you for choosing %1$s. If you have a minute, would you leave us an honest review? It helps other people find us. %2$s', 'scout-reviews' ),
			$s['business'],
			self::link( 'text' )
		);
		$on   = $target ? ' ' . sprintf( /* translators: %s: platform */ __( 'on %s', 'scout-reviews' ), $target['label'] ) : '';
		$body = sprintf(
			/* translators: 1: business name, 2: " on Google" or empty, 3: review link */
			__( "Hi [first name],\n\nThank you for choosing %1\$s. If you have a minute, we would be grateful for an honest review%2\$s. It takes about a minute and helps other people decide whether we are a good fit.\n\n%3\$s\n\nThank you,\n%1\$s", 'scout-reviews' ),
			$s['business'],
			$on,
			self::link( 'email' )
		);
		return array(
			'text'    => $text,
			'subject' => __( 'Would you share a quick review?', 'scout-reviews' ),
			'email'   => $body,
		);
	}

	/** The shared admin header: mono eyebrow, title, optional actions. */
	public static function header( string $section, string $title, string $actions = '' ): void {
		?>
		<header class="srk-head">
			<div>
				<p class="srk-eyebrow"><?php echo esc_html( __( 'Reviews', 'scout-reviews' ) . ' · ' . $section ); ?></p>
				<h1><?php echo esc_html( $title ); ?></h1>
			</div>
			<?php if ( $actions ) : ?>
				<div class="srk-actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts by the caller. ?></div>
			<?php endif; ?>
		</header>
		<?php
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = self::get();
		$links   = self::write_links();
		$target  = self::target();
		$msg     = self::messages( $s, $target );
		$name    = self::OPTION;
		$card_h  = self::card_headline( $s );
		$display = preg_replace( '#^https?://#', '', untrailingslashit( self::link() ) );
		$stats   = get_option( self::STATS, array() );
		$stats   = is_array( $stats ) ? array_slice( $stats, 0, 6, true ) : array();
		$settings_url = Scout_Reviews_Menu::url( Scout_Reviews_Settings::PAGE );
		$test    = '<a class="button" href="' . esc_url( self::link() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the review page', 'scout-reviews' ) . '</a>';
		?>
		<div class="wrap srk recon-theme" data-theme="light" style="--srk-accent:<?php echo esc_attr( $s['accent'] ); ?>">
			<?php self::header( __( 'Ask for reviews', 'scout-reviews' ), __( 'Ask every client the same way', 'scout-reviews' ), $test ); ?>
			<p class="srk-lede"><?php esc_html_e( 'One link for every review request. Share it by text, by email, or on a printed card with a QR code. If your review link ever changes, update it in Settings and every card and message keeps working.', 'scout-reviews' ); ?></p>

			<?php if ( ! $s['enabled'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The review page is turned off. Turn it on under Page settings below.', 'scout-reviews' ); ?></p></div>
			<?php elseif ( ! $links ) : ?>
				<div class="notice notice-error inline"><p>
					<?php esc_html_e( 'Add your "leave a review" link first. Until then the review page has no review button.', 'scout-reviews' ); ?>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open Settings', 'scout-reviews' ); ?></a>
				</p></div>
			<?php endif; ?>

			<div class="srk-grid">
				<section class="srk-box">
					<p class="srk-label"><?php esc_html_e( 'Your review link', 'scout-reviews' ); ?></p>
					<p class="srk-link"><code id="srk-main"><?php echo esc_html( self::link() ); ?></code>
						<button type="button" class="button" data-copy="#srk-main"><?php esc_html_e( 'Copy', 'scout-reviews' ); ?></button></p>
					<p class="srk-note">
						<?php
						if ( 'direct' === $s['mode'] && $target ) {
							/* translators: %s: platform */
							echo esc_html( sprintf( __( 'Goes straight to your %s review box.', 'scout-reviews' ), $target['label'] ) );
						} elseif ( $target ) {
							/* translators: %s: platform */
							echo esc_html( sprintf( __( 'Opens your review page, with a button to your %s review box.', 'scout-reviews' ), $target['label'] ) );
						}
						?>
						<?php esc_html_e( 'Your own visits are not counted while you are signed in.', 'scout-reviews' ); ?>
					</p>
					<?php if ( $s['notes'] ) : ?>
						<p class="srk-label" style="margin-top:20px"><?php esc_html_e( 'Private note form', 'scout-reviews' ); ?></p>
						<p class="srk-link"><code><?php echo esc_html( self::note_link() ); ?></code></p>
						<p class="srk-note"><?php esc_html_e( 'Linked quietly at the bottom of the review page. Notes land in your Inbox and only go on the site when the client said yes.', 'scout-reviews' ); ?></p>
					<?php endif; ?>
				</section>

				<section class="srk-box srk-qrbox">
					<div class="srk-qrcard">
						<p class="srk-qrname"><?php echo esc_html( $s['business'] ); ?></p>
						<div class="srk-qr srk-qr-lg" data-qr-url="<?php echo esc_attr( self::link( 'qr' ) ); ?>" role="img" aria-label="<?php esc_attr_e( 'QR code for your review link', 'scout-reviews' ); ?>"></div>
						<p class="srk-qrurl"><?php echo esc_html( $display ); ?></p>
					</div>
					<p class="srk-btnrow">
						<button type="button" class="button" data-qr-download="svg"><?php esc_html_e( 'Download SVG (for printers)', 'scout-reviews' ); ?></button>
						<button type="button" class="button" data-qr-download="png"><?php esc_html_e( 'Download PNG', 'scout-reviews' ); ?></button>
					</p>
				</section>
			</div>

			<section class="srk-box">
				<p class="srk-label"><?php esc_html_e( 'Printable cards', 'scout-reviews' ); ?></p>
				<h2><?php esc_html_e( 'Put the QR code where clients finish with you', 'scout-reviews' ); ?></h2>
				<p class="srk-note"><?php esc_html_e( 'Print on card stock. Each layout prints on its own letter-size page with light cut lines. The same QR code works on invoices.', 'scout-reviews' ); ?></p>
				<div class="srk-print-grid">
					<?php
					$layouts = array(
						'counter' => __( 'Counter card, 4 by 6 inches', 'scout-reviews' ),
						'cards'   => __( 'Business cards, 10 per page', 'scout-reviews' ),
						'tent'    => __( 'Table tent, fold in half', 'scout-reviews' ),
					);
					foreach ( $layouts as $key => $label ) :
						$copies = 'cards' === $key ? 10 : ( 'tent' === $key ? 2 : 1 );
						?>
						<figure class="srk-layout">
							<div class="srk-sheet-wrap srk-wrap-<?php echo esc_attr( $key ); ?>">
							<div class="srk-sheet srk-sheet-<?php echo esc_attr( $key ); ?>" data-layout="<?php echo esc_attr( $key ); ?>">
								<?php for ( $i = 0; $i < $copies; $i++ ) : ?>
									<div class="srk-card">
										<p class="srk-biz"><?php echo esc_html( $s['business'] ); ?></p>
										<p class="srk-chead"><?php echo esc_html( $card_h ); ?></p>
										<div class="srk-qr" data-qr-url="<?php echo esc_attr( self::link( 'qr' ) ); ?>"></div>
										<p class="srk-scan"><?php echo esc_html( $display ); ?></p>
									</div>
								<?php endfor; ?>
							</div>
							</div>
							<figcaption>
								<span><?php echo esc_html( $label ); ?></span>
								<button type="button" class="button button-primary" data-print="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Print', 'scout-reviews' ); ?></button>
							</figcaption>
						</figure>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="srk-box" id="srk-messages">
				<p class="srk-label"><?php esc_html_e( 'Ready-to-send messages', 'scout-reviews' ); ?></p>
				<h2><?php esc_html_e( 'Send the same message to every client', 'scout-reviews' ); ?></h2>
				<p class="srk-note"><?php esc_html_e( 'Send it right after you finish the work. Replace [first name] before sending.', 'scout-reviews' ); ?></p>
				<div class="srk-grid">
					<div>
						<label class="srk-label" for="srk-text"><?php esc_html_e( 'Text message', 'scout-reviews' ); ?></label>
						<textarea id="srk-text" rows="5" readonly><?php echo esc_textarea( $msg['text'] ); ?></textarea>
						<p class="srk-btnrow"><button type="button" class="button" data-copy="#srk-text"><?php esc_html_e( 'Copy text', 'scout-reviews' ); ?></button></p>
					</div>
					<div>
						<label class="srk-label" for="srk-subject"><?php esc_html_e( 'Email', 'scout-reviews' ); ?></label>
						<input id="srk-subject" type="text" readonly value="<?php echo esc_attr( $msg['subject'] ); ?>" />
						<textarea id="srk-email" rows="9" readonly aria-label="<?php esc_attr_e( 'Email body', 'scout-reviews' ); ?>"><?php echo esc_textarea( $msg['email'] ); ?></textarea>
						<p class="srk-btnrow"><button type="button" class="button" data-copy="#srk-subject"><?php esc_html_e( 'Copy subject', 'scout-reviews' ); ?></button>
							<button type="button" class="button" data-copy="#srk-email"><?php esc_html_e( 'Copy email', 'scout-reviews' ); ?></button></p>
					</div>
				</div>
			</section>

			<div class="srk-grid">
				<section class="srk-box">
					<p class="srk-label"><?php esc_html_e( 'Visits to your review link', 'scout-reviews' ); ?></p>
					<?php if ( ! $stats ) : ?>
						<p class="srk-note"><?php esc_html_e( 'No visits yet. Counts appear here as people use the link.', 'scout-reviews' ); ?></p>
					<?php else : ?>
						<table class="srk-stats">
							<thead><tr>
								<th><?php esc_html_e( 'Month', 'scout-reviews' ); ?></th>
								<th><?php esc_html_e( 'QR scans', 'scout-reviews' ); ?></th>
								<th><?php esc_html_e( 'Texts', 'scout-reviews' ); ?></th>
								<th><?php esc_html_e( 'Emails', 'scout-reviews' ); ?></th>
								<th><?php esc_html_e( 'Other', 'scout-reviews' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $stats as $month => $row ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( 'F Y', strtotime( $month . '-01' ) ) ); ?></td>
									<td><?php echo (int) ( $row['qr'] ?? 0 ); ?></td>
									<td><?php echo (int) ( $row['text'] ?? 0 ); ?></td>
									<td><?php echo (int) ( $row['email'] ?? 0 ); ?></td>
									<td><?php echo (int) ( $row['link'] ?? 0 ) + (int) ( $row['card'] ?? 0 ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="srk-note"><?php esc_html_e( 'These are visits, not reviews. Your platform shows how many reviews came in.', 'scout-reviews' ); ?></p>
					<?php endif; ?>
				</section>

				<section class="srk-box srk-rules">
					<p class="srk-label"><?php esc_html_e( 'Asking the right way', 'scout-reviews' ); ?></p>
					<ul>
						<li><?php esc_html_e( 'Ask every client the same way. Asking only the happy ones breaks Google\'s rules and FTC guidance.', 'scout-reviews' ); ?></li>
						<li><?php esc_html_e( 'Offer nothing in return: no discounts, gifts, or entries.', 'scout-reviews' ); ?></li>
						<li><?php esc_html_e( 'Ask soon after the work is done, while it is fresh.', 'scout-reviews' ); ?></li>
						<li><?php esc_html_e( 'Law firms: check your state bar\'s rules on asking clients for reviews before you send anything.', 'scout-reviews' ); ?></li>
					</ul>
				</section>
			</div>

			<section class="srk-box">
				<p class="srk-label"><?php esc_html_e( 'Page settings', 'scout-reviews' ); ?></p>
				<form method="post" action="options.php">
					<?php settings_fields( self::PAGE ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Review page', 'scout-reviews' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name . '[enabled]' ); ?>" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Turn on the review page', 'scout-reviews' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-slug"><?php esc_html_e( 'Address', 'scout-reviews' ); ?></label></th>
							<td><code><?php echo esc_html( home_url( '/' ) ); ?></code><input id="srk-slug" type="text" style="width:160px" name="<?php echo esc_attr( $name . '[slug]' ); ?>" value="<?php echo esc_attr( $s['slug'] ); ?>" /><code>/</code>
								<p class="description"><?php esc_html_e( 'Change this before you print cards. Printed cards keep the address they were printed with.', 'scout-reviews' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-primary"><?php esc_html_e( 'Main platform', 'scout-reviews' ); ?></label></th>
							<td>
								<select id="srk-primary" name="<?php echo esc_attr( $name . '[primary]' ); ?>">
									<?php foreach ( Scout_Reviews_Sources::all() as $slug => $info ) : ?>
										<?php if ( 'direct' === $slug ) { continue; } ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['primary'], $slug ); ?>><?php echo esc_html( $info['label'] . ( isset( $links[ $slug ] ) ? '' : ' ' . __( '(no link yet)', 'scout-reviews' ) ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'The big button on the review page. Other platforms with a link show as smaller buttons under it.', 'scout-reviews' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Where the link goes', 'scout-reviews' ); ?></th>
							<td>
								<label><input type="radio" name="<?php echo esc_attr( $name . '[mode]' ); ?>" value="page" <?php checked( $s['mode'], 'page' ); ?> /> <?php esc_html_e( 'Your review page (recommended)', 'scout-reviews' ); ?></label><br />
								<label><input type="radio" name="<?php echo esc_attr( $name . '[mode]' ); ?>" value="direct" <?php checked( $s['mode'], 'direct' ); ?> /> <?php esc_html_e( 'Straight to the main platform, skipping the page', 'scout-reviews' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Private notes', 'scout-reviews' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name . '[notes]' ); ?>" value="1" <?php checked( $s['notes'] ); ?> /> <?php esc_html_e( 'Show the "send us a note privately" link and form', 'scout-reviews' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-note-email"><?php esc_html_e( 'Send private notes to', 'scout-reviews' ); ?></label></th>
							<td><input id="srk-note-email" type="email" class="regular-text" name="<?php echo esc_attr( $name . '[note_email]' ); ?>" value="<?php echo esc_attr( $s['note_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave empty to use the site\'s admin email.', 'scout-reviews' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-business"><?php esc_html_e( 'Business name', 'scout-reviews' ); ?></label></th>
							<td><input id="srk-business" type="text" class="regular-text" name="<?php echo esc_attr( $name . '[business]' ); ?>" value="<?php echo esc_attr( $s['business'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-headline"><?php esc_html_e( 'Page headline', 'scout-reviews' ); ?></label></th>
							<td><input id="srk-headline" type="text" class="regular-text" name="<?php echo esc_attr( $name . '[headline]' ); ?>" value="<?php echo esc_attr( $s['headline'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-ask"><?php esc_html_e( 'Page ask line', 'scout-reviews' ); ?></label></th>
							<td><input id="srk-ask" type="text" class="large-text" name="<?php echo esc_attr( $name . '[ask]' ); ?>" value="<?php echo esc_attr( $s['ask'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Keep it the same for everyone. Never ask whether they were happy first.', 'scout-reviews' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-photo"><?php esc_html_e( 'Team photo', 'scout-reviews' ); ?></label></th>
							<td><input id="srk-photo" type="url" class="large-text" placeholder="https://" name="<?php echo esc_attr( $name . '[photo]' ); ?>" value="<?php echo esc_attr( $s['photo'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Optional. Paste the link of a photo from Media. A real photo of the team makes the ask feel personal.', 'scout-reviews' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-card-headline"><?php esc_html_e( 'Card headline', 'scout-reviews' ); ?></label></th>
							<td><input id="srk-card-headline" type="text" class="regular-text" placeholder="<?php echo esc_attr( self::card_headline( array( 'card_headline' => '' ) ) ); ?>" name="<?php echo esc_attr( $name . '[card_headline]' ); ?>" value="<?php echo esc_attr( $s['card_headline'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-accent"><?php esc_html_e( 'Brand color', 'scout-reviews' ); ?></label></th>
							<td><input id="srk-accent" type="color" name="<?php echo esc_attr( $name . '[accent]' ); ?>" value="<?php echo esc_attr( $s['accent'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Used for the review button and the card headline.', 'scout-reviews' ); ?></p></td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>
			</section>
		</div>
		<?php
	}
}
