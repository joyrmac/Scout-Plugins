<?php
/**
 * Review requests: one short link that sends clients to the review box, and
 * the kit to share it (QR code, printable cards, ready-to-send messages).
 *
 *   /review/          sends the visitor to the "leave a review" link
 *   /review/?via=qr   same, counted as a QR scan (also text, email, card, link)
 *
 * Why the QR points at the site and not at Google: the printed cards outlive
 * any one link. If the review link changes, or the business adds Yelp, the
 * cards already on counters keep working because only this setting changes.
 *
 * Compliance, built in on purpose. Every client gets the same ask and the same
 * choices: no "were you happy?" step that sends unhappy people elsewhere
 * (review gating, which Google's policy and the FTC both prohibit) and no
 * incentive wording. Visits are counted as totals per month and source; no
 * visitor data is stored.
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
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_init', array( __CLASS__, 'register' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults(): array {
		return array(
			'enabled'  => true,
			'slug'     => 'review',
			'mode'     => 'direct', // direct: straight to one platform. choose: a page listing every platform.
			'primary'  => 'google',
			'business' => '',
			'headline' => __( 'How did we do?', 'scout-reviews' ),
			'accent'   => '#1D1D1F',
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		$s     = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		if ( '' === trim( $s['business'] ) ) {
			$s['business'] = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}
		return $s;
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

		return array(
			'enabled'  => ! empty( $input['enabled'] ),
			'slug'     => '' !== $slug ? $slug : $d['slug'],
			'mode'     => ( 'choose' === ( $input['mode'] ?? '' ) ) ? 'choose' : 'direct',
			'primary'  => Scout_Reviews_Sources::exists( $prime ) ? $prime : $d['primary'],
			'business' => sanitize_text_field( (string) ( $input['business'] ?? '' ) ),
			'headline' => sanitize_text_field( (string) ( $input['headline'] ?? '' ) ) ?: $d['headline'],
			'accent'   => $color ? $color : $d['accent'],
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
	 * The platform a direct link sends people to: the chosen one when it has
	 * a link, otherwise the first platform that does.
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

	/* ------------------------------------------------------------------ */
	/* The /review/ address                                               */
	/* ------------------------------------------------------------------ */

	public static function rules(): void {
		$s = self::get();
		if ( $s['enabled'] ) {
			add_rewrite_rule( '^' . preg_quote( $s['slug'], '#' ) . '/?$', 'index.php?' . self::VAR . '=1', 'top' );
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
		if ( ! get_query_var( self::VAR ) ) {
			return;
		}
		nocache_headers(); // A cached redirect would skip the count and could outlive a changed link.
		header( 'X-Robots-Tag: noindex, nofollow' );

		$via = sanitize_key( (string) ( $_GET['via'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::count( in_array( $via, self::VIA, true ) ? $via : 'link' );

		$s     = self::get();
		$links = self::write_links();
		if ( ! $links ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		if ( 'choose' === $s['mode'] && count( $links ) > 1 ) {
			self::choice_page( $s, $links );
			exit;
		}
		$target = self::target();
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the review platforms are other sites by design.
		wp_redirect( $target['url'], 302, 'Scout Reviews' );
		exit;
	}

	/**
	 * Monthly totals per source. Link previews (a text app fetching the link
	 * to draw a preview) are skipped so pasting the link does not count.
	 */
	private static function count( string $via ): void {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return; // Your own test clicks.
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

	/**
	 * Every platform gets the same button. Nothing on this page asks how the
	 * visit went first.
	 */
	private static function choice_page( array $s, array $links ): void {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		$accent = $s['accent'];
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( sprintf( /* translators: %s: business name */ __( 'Review %s', 'scout-reviews' ), $s['business'] ) ); ?></title>
<style>
:root{--accent:<?php echo esc_html( $accent ); ?>;--bg:#F5F5F7;--card:#fff;--text:#1D1D1F;--muted:#515154}
@media (prefers-color-scheme:dark){:root{--bg:#0B0B0B;--card:#161616;--text:#F5F5F7;--muted:#B5B5B8}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--text);font:400 17px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px 16px}
main{width:100%;max-width:420px;background:var(--card);border-radius:18px;padding:32px 24px;text-align:center}
.biz{font-size:14px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);margin:0}
h1{font-size:28px;line-height:1.15;letter-spacing:-.02em;margin:10px 0 8px;text-wrap:balance}
p{margin:0 0 24px;color:var(--muted);text-wrap:pretty}
ul{list-style:none;margin:0;padding:0;display:grid;gap:10px}
a{display:block;padding:14px 18px;border-radius:980px;background:var(--accent);color:#fff;text-decoration:none;font-weight:600}
a:focus-visible{outline:3px solid var(--text);outline-offset:3px}
</style>
</head>
<body>
<main>
	<p class="biz"><?php echo esc_html( $s['business'] ); ?></p>
	<h1><?php echo esc_html( $s['headline'] ); ?></h1>
	<p><?php esc_html_e( 'Pick where you would like to leave your review. It takes about a minute and helps other people find us.', 'scout-reviews' ); ?></p>
	<ul>
		<?php foreach ( $links as $link ) : ?>
			<li><a href="<?php echo esc_url( $link['url'] ); ?>" rel="noopener"><?php echo esc_html( sprintf( /* translators: %s: platform */ __( 'Review us on %s', 'scout-reviews' ), $link['label'] ) ); ?></a></li>
		<?php endforeach; ?>
	</ul>
</main>
</body>
</html>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Reviews > Ask for reviews                                           */
	/* ------------------------------------------------------------------ */

	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Scout_Reviews_Post_Type::TYPE,
			__( 'Ask for reviews', 'scout-reviews' ),
			__( 'Ask for reviews', 'scout-reviews' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'scout-reviews-ask', SCOUT_REVIEWS_URL . 'assets/ask.css', array(), SCOUT_REVIEWS_VERSION );
		wp_enqueue_script( 'scout-reviews-qr', SCOUT_REVIEWS_URL . 'assets/qrcode.min.js', array(), SCOUT_REVIEWS_VERSION, true );
		wp_enqueue_script( 'scout-reviews-ask', SCOUT_REVIEWS_URL . 'assets/ask.js', array( 'scout-reviews-qr' ), SCOUT_REVIEWS_VERSION, true );
	}

	/** The ready-to-send messages, with the business and link filled in. */
	public static function messages( array $s, ?array $target ): array {
		$where = ( $target && 'direct' === $s['mode'] ) ? $target['label'] : '';
		$text  = sprintf(
			/* translators: 1: business name, 2: review link */
			__( 'Hi [first name], thank you for choosing %1$s. If you have a minute, would you leave us an honest review? It helps other people find us. %2$s', 'scout-reviews' ),
			$s['business'],
			self::link( 'text' )
		);
		$subject = __( 'Would you share a quick review?', 'scout-reviews' );
		$body    = sprintf(
			/* translators: 1: business name, 2: "on Google " or empty, 3: review link */
			__( "Hi [first name],\n\nThank you for choosing %1\$s. If you have a minute, we would be grateful for an honest review %2\$s. It takes about a minute and helps other people decide whether we are a good fit.\n\n%3\$s\n\nThank you,\n%1\$s", 'scout-reviews' ),
			$s['business'],
			$where ? sprintf( /* translators: %s: platform */ __( 'on %s', 'scout-reviews' ), $where ) : '',
			self::link( 'email' )
		);
		$body = str_replace( ' .', '.', $body );
		return array(
			'text'    => $text,
			'subject' => $subject,
			'email'   => $body,
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::get();
		$links  = self::write_links();
		$target = self::target();
		$msg    = self::messages( $s, $target );
		$name   = self::OPTION;
		$scan   = ( $target && 'direct' === $s['mode'] )
			? sprintf( /* translators: %s: platform */ __( 'Scan to leave a review on %s. It takes about a minute.', 'scout-reviews' ), $target['label'] )
			: __( 'Scan to leave a review. It takes about a minute.', 'scout-reviews' );
		$stats  = get_option( self::STATS, array() );
		$stats  = is_array( $stats ) ? array_slice( $stats, 0, 6, true ) : array();
		$settings_url = admin_url( 'edit.php?post_type=' . Scout_Reviews_Post_Type::TYPE . '&page=' . Scout_Reviews_Settings::PAGE );
		?>
		<div class="wrap srk" style="--srk-accent:<?php echo esc_attr( $s['accent'] ); ?>">
			<h1><?php esc_html_e( 'Ask for reviews', 'scout-reviews' ); ?></h1>
			<p class="srk-lede"><?php esc_html_e( 'One link for every review request. Share it by text, by email, or on a printed card with a QR code. If your review link ever changes, update it in Settings and every card and message keeps working.', 'scout-reviews' ); ?></p>

			<?php if ( ! $s['enabled'] ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The review link is turned off. Turn it on under Link settings below.', 'scout-reviews' ); ?></p></div>
			<?php elseif ( ! $links ) : ?>
				<div class="notice notice-error inline"><p>
					<?php esc_html_e( 'Add your "leave a review" link first. Until then the review link sends people to your home page.', 'scout-reviews' ); ?>
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open Settings', 'scout-reviews' ); ?></a>
				</p></div>
			<?php endif; ?>

			<div class="srk-grid">
				<section class="srk-box">
					<h2><?php esc_html_e( 'Your review link', 'scout-reviews' ); ?></h2>
					<p class="srk-link"><code id="srk-main"><?php echo esc_html( self::link() ); ?></code>
						<button type="button" class="button" data-copy="#srk-main"><?php esc_html_e( 'Copy', 'scout-reviews' ); ?></button></p>
					<p class="description">
						<?php
						if ( 'choose' === $s['mode'] && count( $links ) > 1 ) {
							esc_html_e( 'Opens a page with a button for each platform you are on.', 'scout-reviews' );
						} elseif ( $target ) {
							/* translators: %s: platform */
							echo esc_html( sprintf( __( 'Sends people straight to your %s review box.', 'scout-reviews' ), $target['label'] ) );
						}
						?>
					</p>
					<p><a class="button" href="<?php echo esc_url( self::link() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Test the link', 'scout-reviews' ); ?></a>
						<span class="description"><?php esc_html_e( 'Your own clicks are not counted while you are signed in.', 'scout-reviews' ); ?></span></p>
				</section>

				<section class="srk-box">
					<h2><?php esc_html_e( 'QR code', 'scout-reviews' ); ?></h2>
					<div class="srk-qr srk-qr-lg" data-qr-url="<?php echo esc_attr( self::link( 'qr' ) ); ?>" role="img" aria-label="<?php esc_attr_e( 'QR code for your review link', 'scout-reviews' ); ?>"></div>
					<p>
						<button type="button" class="button" data-qr-download="svg"><?php esc_html_e( 'Download SVG (for printers)', 'scout-reviews' ); ?></button>
						<button type="button" class="button" data-qr-download="png"><?php esc_html_e( 'Download PNG', 'scout-reviews' ); ?></button>
					</p>
				</section>
			</div>

			<section class="srk-box">
				<h2><?php esc_html_e( 'Printable cards', 'scout-reviews' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Print on card stock. Each layout prints on its own letter-size page with light cut lines.', 'scout-reviews' ); ?></p>
				<div class="srk-print-grid">
					<?php
					$layouts = array(
						'counter' => __( 'Counter card, 4 by 6 inches', 'scout-reviews' ),
						'cards'   => __( 'Business cards, 10 per page', 'scout-reviews' ),
						'tent'    => __( 'Table tent, fold in half', 'scout-reviews' ),
					);
					foreach ( $layouts as $key => $label ) :
						?>
						<figure class="srk-layout">
							<div class="srk-sheet srk-sheet-<?php echo esc_attr( $key ); ?>" data-layout="<?php echo esc_attr( $key ); ?>">
								<?php
								$copies = 'cards' === $key ? 10 : ( 'tent' === $key ? 2 : 1 );
								for ( $i = 0; $i < $copies; $i++ ) :
									?>
									<div class="srk-card">
										<p class="srk-biz"><?php echo esc_html( $s['business'] ); ?></p>
										<p class="srk-head"><?php echo esc_html( $s['headline'] ); ?></p>
										<div class="srk-qr" data-qr-url="<?php echo esc_attr( self::link( 'qr' ) ); ?>"></div>
										<p class="srk-scan"><?php echo esc_html( $scan ); ?></p>
									</div>
								<?php endfor; ?>
							</div>
							<figcaption>
								<span><?php echo esc_html( $label ); ?></span>
								<button type="button" class="button button-primary" data-print="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Print', 'scout-reviews' ); ?></button>
							</figcaption>
						</figure>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="srk-box">
				<h2><?php esc_html_e( 'Ready-to-send messages', 'scout-reviews' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Send the same message to every client, right after you finish the work. Replace [first name] before sending.', 'scout-reviews' ); ?></p>
				<div class="srk-grid">
					<div>
						<h3><?php esc_html_e( 'Text message', 'scout-reviews' ); ?></h3>
						<textarea id="srk-text" class="large-text" rows="5" readonly><?php echo esc_textarea( $msg['text'] ); ?></textarea>
						<p><button type="button" class="button" data-copy="#srk-text"><?php esc_html_e( 'Copy text', 'scout-reviews' ); ?></button></p>
					</div>
					<div>
						<h3><?php esc_html_e( 'Email', 'scout-reviews' ); ?></h3>
						<input id="srk-subject" class="large-text" type="text" readonly value="<?php echo esc_attr( $msg['subject'] ); ?>" />
						<textarea id="srk-email" class="large-text" rows="9" readonly><?php echo esc_textarea( $msg['email'] ); ?></textarea>
						<p><button type="button" class="button" data-copy="#srk-subject"><?php esc_html_e( 'Copy subject', 'scout-reviews' ); ?></button>
							<button type="button" class="button" data-copy="#srk-email"><?php esc_html_e( 'Copy email', 'scout-reviews' ); ?></button></p>
					</div>
				</div>
			</section>

			<div class="srk-grid">
				<section class="srk-box">
					<h2><?php esc_html_e( 'Visits to your review link', 'scout-reviews' ); ?></h2>
					<?php if ( ! $stats ) : ?>
						<p class="description"><?php esc_html_e( 'No visits yet. Counts appear here as people use the link.', 'scout-reviews' ); ?></p>
					<?php else : ?>
						<table class="widefat striped srk-stats">
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
						<p class="description"><?php esc_html_e( 'Visits, not reviews. Your platform shows how many reviews actually came in.', 'scout-reviews' ); ?></p>
					<?php endif; ?>
				</section>

				<section class="srk-box srk-rules">
					<h2><?php esc_html_e( 'Asking the right way', 'scout-reviews' ); ?></h2>
					<ul>
						<li><?php esc_html_e( 'Ask every client the same way. Asking only the happy ones is against Google\'s rules and the FTC\'s.', 'scout-reviews' ); ?></li>
						<li><?php esc_html_e( 'Offer nothing in return: no discounts, gifts, or entries.', 'scout-reviews' ); ?></li>
						<li><?php esc_html_e( 'Ask soon after the work is done, while it is fresh.', 'scout-reviews' ); ?></li>
						<li><?php esc_html_e( 'Law firms: check your state bar\'s rules on asking clients for reviews before you send anything.', 'scout-reviews' ); ?></li>
					</ul>
				</section>
			</div>

			<section class="srk-box">
				<h2><?php esc_html_e( 'Link settings', 'scout-reviews' ); ?></h2>
				<form method="post" action="options.php">
					<?php settings_fields( self::PAGE ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Review link', 'scout-reviews' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $name . '[enabled]' ); ?>" value="1" <?php checked( $s['enabled'] ); ?> /> <?php esc_html_e( 'Turn on the review link', 'scout-reviews' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-slug"><?php esc_html_e( 'Address', 'scout-reviews' ); ?></label></th>
							<td><code><?php echo esc_html( home_url( '/' ) ); ?></code><input id="srk-slug" type="text" class="regular-text" style="width:160px" name="<?php echo esc_attr( $name . '[slug]' ); ?>" value="<?php echo esc_attr( $s['slug'] ); ?>" /><code>/</code>
								<p class="description"><?php esc_html_e( 'Change this before you print cards. Printed cards keep the address they were printed with.', 'scout-reviews' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Where it goes', 'scout-reviews' ); ?></th>
							<td>
								<label><input type="radio" name="<?php echo esc_attr( $name . '[mode]' ); ?>" value="direct" <?php checked( $s['mode'], 'direct' ); ?> /> <?php esc_html_e( 'Straight to one platform (fewest taps, most reviews)', 'scout-reviews' ); ?></label>
								<select name="<?php echo esc_attr( $name . '[primary]' ); ?>" aria-label="<?php esc_attr_e( 'Platform', 'scout-reviews' ); ?>">
									<?php foreach ( Scout_Reviews_Sources::all() as $slug => $info ) : ?>
										<?php if ( 'direct' === $slug ) { continue; } ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['primary'], $slug ); ?>><?php echo esc_html( $info['label'] . ( isset( $links[ $slug ] ) ? '' : ' ' . __( '(no link yet)', 'scout-reviews' ) ) ); ?></option>
									<?php endforeach; ?>
								</select><br />
								<label><input type="radio" name="<?php echo esc_attr( $name . '[mode]' ); ?>" value="choose" <?php checked( $s['mode'], 'choose' ); ?> /> <?php esc_html_e( 'A page with a button for each platform', 'scout-reviews' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-business"><?php esc_html_e( 'Name on cards', 'scout-reviews' ); ?></label></th>
							<td><input id="srk-business" type="text" class="regular-text" name="<?php echo esc_attr( $name . '[business]' ); ?>" value="<?php echo esc_attr( $s['business'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-headline"><?php esc_html_e( 'Card headline', 'scout-reviews' ); ?></label></th>
							<td><input id="srk-headline" type="text" class="regular-text" name="<?php echo esc_attr( $name . '[headline]' ); ?>" value="<?php echo esc_attr( $s['headline'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="srk-accent"><?php esc_html_e( 'Card color', 'scout-reviews' ); ?></label></th>
							<td><input id="srk-accent" type="color" name="<?php echo esc_attr( $name . '[accent]' ); ?>" value="<?php echo esc_attr( $s['accent'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Used for the headline on cards and the buttons on the choice page. Pick a dark brand color so the cards print clearly.', 'scout-reviews' ); ?></p></td>
						</tr>
					</table>
					<?php submit_button(); ?>
				</form>
			</section>
		</div>
		<?php
	}
}
