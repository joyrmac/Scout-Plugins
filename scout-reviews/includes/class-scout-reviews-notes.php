<?php
/**
 * The public review pages: /review/ (the ask) and /review/note/ (a private
 * note form whose entries land in Reviews > Inbox).
 *
 * Both are small standalone phone pages, so they load fast from a QR scan and
 * look the same whatever the theme does. A note becomes a draft review with
 * source "Client". It can only be published when the client ticked the
 * "OK to show this" box; without it the note stays private.
 *
 * Spam: a hidden field, a signed timestamp (too fast or too old is refused),
 * and at most five notes an hour from one connection. Nothing about the
 * visitor is stored beyond what they typed.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Reviews_Notes {

	const META_CONSENT = '_scout_review_consent';
	const META_ORIGIN  = '_scout_review_origin';

	const HP  = 'srn_website';
	const TS  = 'srn_ts';
	const SIG = 'srn_sig';

	public static function boot(): void {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'keep_private' ), 10, 2 );
	}

	/**
	 * A private note stays a draft even if someone presses Publish on it in
	 * the editor.
	 */
	public static function keep_private( array $data, array $postarr ): array {
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( $id && Scout_Reviews_Post_Type::TYPE === $data['post_type'] && in_array( $data['post_status'], array( 'publish', 'future' ), true ) && ! self::can_publish( $id ) ) {
			$data['post_status'] = 'draft';
		}
		return $data;
	}

	/** True when this review came in through the note form. */
	public static function from_website( int $post_id ): bool {
		return 'website' === get_post_meta( $post_id, self::META_ORIGIN, true );
	}

	/** A note may go on the site only with the client's OK. */
	public static function can_publish( int $post_id ): bool {
		return ! self::from_website( $post_id ) || (bool) get_post_meta( $post_id, self::META_CONSENT, true );
	}

	/* ------------------------------------------------------------------ */
	/* Shared shell                                                        */
	/* ------------------------------------------------------------------ */

	private static function open( array $s, string $title ): void {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		$fonts = (string) apply_filters( 'scout_reviews_page_fonts_url', 'https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600&family=Geist+Mono:wght@500&display=swap' );
		$icon  = get_site_icon_url( 112 );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?></title>
<?php if ( $fonts ) : ?>
<link rel="stylesheet" href="<?php echo esc_url( $fonts ); ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo esc_url( SCOUT_REVIEWS_URL . 'assets/review-page.css?ver=' . SCOUT_REVIEWS_VERSION ); ?>">
<style>:root{--accent:<?php echo esc_html( $s['accent'] ); ?>}</style>
</head>
<body>
<main class="rp">
	<div class="rp-top">
		<?php if ( $icon ) : ?>
			<img class="rp-logo" src="<?php echo esc_url( $icon ); ?>" alt="" width="56" height="56">
		<?php endif; ?>
		<span class="rp-biz"><?php echo esc_html( $s['business'] ); ?></span>
	</div>
		<?php
	}

	private static function close(): void {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		?>
	<p class="rp-domain"><?php echo esc_html( $host ); ?></p>
</main>
</body>
</html>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* /review/                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Every visitor sees the same page: the review button first, any other
	 * platforms under it, and the private note link last and small.
	 *
	 * @param array<string, array{label:string, url:string}> $links
	 */
	public static function review_page( array $s, ?array $target, array $links ): void {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		self::open( $s, sprintf( /* translators: %s: business */ __( 'Review %s', 'scout-reviews' ), $s['business'] ) );
		?>
	<?php if ( $s['photo'] ) : ?>
		<img class="rp-photo" src="<?php echo esc_url( $s['photo'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: business */ __( 'The %s team', 'scout-reviews' ), $s['business'] ) ); ?>">
	<?php endif; ?>
	<p class="rp-eyebrow"><?php echo esc_html( $host . '/' . $s['slug'] ); ?></p>
	<h1><?php echo esc_html( $s['headline'] ); ?></h1>
	<p class="rp-lede"><?php echo esc_html( $s['ask'] ); ?></p>

	<?php if ( $target ) : ?>
		<div class="rp-actions">
			<a class="rp-btn rp-btn-primary" href="<?php echo esc_url( $target['url'] ); ?>" rel="noopener"><?php echo esc_html( sprintf( /* translators: %s: platform */ __( 'Leave a %s review', 'scout-reviews' ), $target['label'] ) ); ?></a>
			<?php foreach ( $links as $slug => $link ) : ?>
				<?php if ( $slug === $target['slug'] ) { continue; } ?>
				<a class="rp-btn" href="<?php echo esc_url( $link['url'] ); ?>" rel="noopener"><?php echo esc_html( sprintf( /* translators: %s: platform */ __( 'Review us on %s', 'scout-reviews' ), $link['label'] ) ); ?></a>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="rp-lede"><?php esc_html_e( 'Our review link is being set up. Please check back soon.', 'scout-reviews' ); ?></p>
	<?php endif; ?>

	<?php if ( $s['notes'] ) : ?>
		<p class="rp-quiet"><a href="<?php echo esc_url( Scout_Reviews_Requests::note_link() ); ?>"><?php esc_html_e( 'Prefer to send us a note privately?', 'scout-reviews' ); ?></a></p>
	<?php endif; ?>
		<?php
		self::close();
	}

	/* ------------------------------------------------------------------ */
	/* /review/note/                                                       */
	/* ------------------------------------------------------------------ */

	public static function route( array $s ): void {
		$errors = array();
		$values = array( 'rating' => 0, 'quote' => '', 'name' => '', 'business' => '', 'consent' => false );

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form; signed timestamp below instead of a nonce (nonces break on cached pages).
			$values = array(
				'rating'   => max( 0, min( 5, (int) ( $_POST['rating'] ?? 0 ) ) ),
				'quote'    => trim( sanitize_textarea_field( wp_unslash( (string) ( $_POST['quote'] ?? '' ) ) ) ),
				'name'     => trim( sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ) ),
				'business' => trim( sanitize_text_field( wp_unslash( (string) ( $_POST['business'] ?? '' ) ) ) ),
				'consent'  => ! empty( $_POST['consent'] ),
			);
			$spam = '' !== trim( (string) ( $_POST[ self::HP ] ?? '' ) ) || ! self::fresh( (string) ( $_POST[ self::TS ] ?? '' ), (string) ( $_POST[ self::SIG ] ?? '' ) );
			// phpcs:enable

			if ( '' === $values['quote'] ) {
				$errors['quote'] = __( 'Add a few words so we know what to pass on.', 'scout-reviews' );
			} elseif ( mb_strlen( $values['quote'] ) > 2000 ) {
				$errors['quote'] = __( 'That note is a little long. Please keep it under 2,000 characters.', 'scout-reviews' );
			}
			if ( '' === $values['name'] ) {
				$errors['name'] = __( 'Add your name so we know who we are talking to.', 'scout-reviews' );
			}

			if ( ! $errors ) {
				if ( $spam || ! self::under_limit() ) {
					// Bots and floods get the same thank-you page and nothing is saved.
					self::thanks( $s );
					return;
				}
				self::save( $values );
				self::thanks( $s );
				return;
			}
		}
		self::form( $s, $values, $errors );
	}

	private static function sign( string $ts ): string {
		return hash_hmac( 'sha256', 'scout-review-note|' . $ts, wp_salt( 'auth' ) );
	}

	/** Submitted at least 3 seconds and at most 2 days after the form loaded. */
	private static function fresh( string $ts, string $sig ): bool {
		if ( ! ctype_digit( $ts ) || ! hash_equals( self::sign( $ts ), $sig ) ) {
			return false;
		}
		$age = time() - (int) $ts;
		return $age >= 3 && $age <= 2 * DAY_IN_SECONDS;
	}

	private static function under_limit(): bool {
		$ip  = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$key = 'srn_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
		$n   = (int) get_transient( $key );
		if ( $n >= 5 ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	private static function save( array $v ): void {
		$id = wp_insert_post(
			array(
				'post_type'    => Scout_Reviews_Post_Type::TYPE,
				'post_status'  => 'draft',
				'post_title'   => $v['name'],
				'post_content' => $v['quote'],
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return;
		}
		update_post_meta( $id, Scout_Reviews_Post_Type::META_SOURCE, 'direct' );
		update_post_meta( $id, Scout_Reviews_Post_Type::META_DATE, wp_date( 'Y-m-d' ) );
		if ( $v['rating'] ) {
			update_post_meta( $id, Scout_Reviews_Post_Type::META_RATING, $v['rating'] );
		}
		if ( '' !== $v['business'] ) {
			update_post_meta( $id, Scout_Reviews_Post_Type::META_DETAIL, $v['business'] );
		}
		update_post_meta( $id, self::META_ORIGIN, 'website' );
		update_post_meta( $id, self::META_CONSENT, $v['consent'] ? 1 : 0 );
		update_post_meta( $id, Scout_Reviews_Google_Sync::META_NEW, 1 );

		$lines = array(
			/* translators: %s: name */
			sprintf( __( 'From: %s', 'scout-reviews' ), $v['name'] . ( '' !== $v['business'] ? ', ' . $v['business'] : '' ) ),
			$v['rating'] ? sprintf( /* translators: %d: stars */ __( 'Stars: %d of 5', 'scout-reviews' ), $v['rating'] ) : __( 'Stars: none given', 'scout-reviews' ),
			$v['consent'] ? __( 'OK to show on the website: yes', 'scout-reviews' ) : __( 'OK to show on the website: no, keep it private', 'scout-reviews' ),
			'',
			$v['quote'],
			'',
			/* translators: %s: admin link */
			sprintf( __( 'Read it in your inbox: %s', 'scout-reviews' ), Scout_Reviews_Inbox::url() ),
		);
		wp_mail(
			(string) apply_filters( 'scout_reviews_note_recipient', '' !== Scout_Reviews_Requests::get()['note_email'] ? Scout_Reviews_Requests::get()['note_email'] : get_option( 'admin_email' ) ),
			__( 'New note from your review page', 'scout-reviews' ),
			implode( "\n", $lines )
		);
	}

	private static function form( array $s, array $v, array $errors ): void {
		$ts = (string) time();
		self::open( $s, sprintf( /* translators: %s: business */ __( 'Send %s a note', 'scout-reviews' ), $s['business'] ) );
		?>
	<p class="rp-eyebrow"><?php esc_html_e( 'Private note', 'scout-reviews' ); ?></p>
	<h1><?php esc_html_e( 'Tell us how it went.', 'scout-reviews' ); ?></h1>
	<p class="rp-lede"><?php echo esc_html( sprintf( /* translators: %s: business */ __( 'Your note goes straight to %s. Tick the box at the end if you are happy for us to share it on our website.', 'scout-reviews' ), $s['business'] ) ); ?></p>

	<form class="rp-form" method="post" action="<?php echo esc_url( Scout_Reviews_Requests::note_link() ); ?>" novalidate>
		<fieldset class="rp-stars">
			<legend class="rp-label"><?php esc_html_e( 'Your rating (optional)', 'scout-reviews' ); ?></legend>
			<div class="rp-starrow">
				<?php for ( $i = 5; $i >= 1; $i-- ) : ?>
					<input type="radio" id="rp-star-<?php echo (int) $i; ?>" name="rating" value="<?php echo (int) $i; ?>" <?php checked( $v['rating'], $i ); ?>>
					<label for="rp-star-<?php echo (int) $i; ?>" title="<?php echo esc_attr( sprintf( /* translators: %d: stars */ _n( '%d star', '%d stars', $i, 'scout-reviews' ), $i ) ); ?>"><span class="rp-sr"><?php echo esc_html( sprintf( /* translators: %d: stars */ _n( '%d star', '%d stars', $i, 'scout-reviews' ), $i ) ); ?></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/></svg></label>
				<?php endfor; ?>
			</div>
		</fieldset>

		<div class="rp-field">
			<label class="rp-label" for="rp-quote"><?php esc_html_e( 'Your words', 'scout-reviews' ); ?></label>
			<textarea id="rp-quote" name="quote" rows="5" maxlength="2000" required <?php echo isset( $errors['quote'] ) ? 'aria-invalid="true" aria-describedby="rp-quote-err"' : ''; ?>><?php echo esc_textarea( $v['quote'] ); ?></textarea>
			<?php if ( isset( $errors['quote'] ) ) : ?><p class="rp-err" id="rp-quote-err"><?php echo esc_html( $errors['quote'] ); ?></p><?php endif; ?>
		</div>
		<div class="rp-field">
			<label class="rp-label" for="rp-name"><?php esc_html_e( 'Your name', 'scout-reviews' ); ?></label>
			<input id="rp-name" name="name" type="text" autocomplete="name" maxlength="80" required value="<?php echo esc_attr( $v['name'] ); ?>" <?php echo isset( $errors['name'] ) ? 'aria-invalid="true" aria-describedby="rp-name-err"' : ''; ?>>
			<?php if ( isset( $errors['name'] ) ) : ?><p class="rp-err" id="rp-name-err"><?php echo esc_html( $errors['name'] ); ?></p><?php endif; ?>
		</div>
		<div class="rp-field">
			<label class="rp-label" for="rp-business"><?php esc_html_e( 'Your business (optional)', 'scout-reviews' ); ?></label>
			<input id="rp-business" name="business" type="text" autocomplete="organization" maxlength="120" value="<?php echo esc_attr( $v['business'] ); ?>">
		</div>
		<label class="rp-check"><input type="checkbox" name="consent" value="1" <?php checked( $v['consent'] ); ?>>
			<span><?php echo esc_html( sprintf( /* translators: %s: site domain */ __( 'OK to show this on %s', 'scout-reviews' ), (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ); ?></span></label>

		<div class="rp-hp" aria-hidden="true"><label><?php esc_html_e( 'Leave this field empty', 'scout-reviews' ); ?><input type="text" name="<?php echo esc_attr( self::HP ); ?>" tabindex="-1" autocomplete="off"></label></div>
		<input type="hidden" name="<?php echo esc_attr( self::TS ); ?>" value="<?php echo esc_attr( $ts ); ?>">
		<input type="hidden" name="<?php echo esc_attr( self::SIG ); ?>" value="<?php echo esc_attr( self::sign( $ts ) ); ?>">

		<button class="rp-btn rp-btn-primary" type="submit"><?php esc_html_e( 'Send it', 'scout-reviews' ); ?></button>
	</form>
		<?php
		self::close();
	}

	/** The same thank-you for everyone, with the review link offered to all. */
	private static function thanks( array $s ): void {
		$target = Scout_Reviews_Requests::target();
		self::open( $s, __( 'Thank you', 'scout-reviews' ) );
		?>
	<div class="rp-done">
		<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
	</div>
	<h1><?php esc_html_e( 'Thank you. Your note is on its way.', 'scout-reviews' ); ?></h1>
	<p class="rp-lede"><?php echo esc_html( sprintf( /* translators: %s: business */ __( '%s reads every note personally.', 'scout-reviews' ), $s['business'] ) ); ?></p>
	<?php if ( $target ) : ?>
		<div class="rp-actions">
			<a class="rp-btn" href="<?php echo esc_url( $target['url'] ); ?>" rel="noopener"><?php echo esc_html( sprintf( /* translators: %s: platform */ __( 'Leave a %s review too', 'scout-reviews' ), $target['label'] ) ); ?></a>
		</div>
	<?php endif; ?>
		<?php
		self::close();
	}
}
