<?php
/**
 * The booking page: [scout_booking].
 *
 * Three steps, each a plain page address so it works without scripts, with
 * the back button, and with a screen reader:
 *   1. pick a session  (?sb_type=)
 *   2. pick a time     (&sb_date=, &sb_time=)
 *   3. your details and the short intake questions, then
 *      - "by request only" sessions (or a site without a checkout): a request
 *        the owner approves; approval emails a pay link;
 *      - a deposit or full price: on to the WooCommerce checkout;
 *      - nothing to pay online: booked straight away.
 *
 * The same page shows a booking to its client from the link in every email
 * (?sb_manage=token): add to calendar, change the time, cancel, inside the
 * cancellation window rules. Calendar files and the owner's private feed are
 * served from the home address with their own query keys.
 *
 * Attributes: type="id" opens one session straight away; types="id,id" lists
 * only some (a tantra page that shows only tantra sessions).
 *
 * @package Scout_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Booking_Front {

	const MIN_SECONDS = 3;

	public static function boot(): void {
		add_shortcode( 'scout_booking', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
		foreach ( array( 'submit', 'manage' ) as $a ) {
			add_action( 'admin_post_nopriv_scout_booking_' . $a, array( __CLASS__, $a ) );
			add_action( 'admin_post_scout_booking_' . $a, array( __CLASS__, $a ) );
		}
		add_action( 'template_redirect', array( __CLASS__, 'endpoints' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	public static function register(): void {
		wp_register_style( 'scout-booking', SCOUT_BOOKING_URL . 'assets/scout-booking.css', array(), SCOUT_BOOKING_VERSION );
	}

	/* ---------- addresses ---------- */

	public static function page_url(): string {
		$id = Scout_Booking::settings()['page_id'];
		$u  = $id ? get_permalink( $id ) : '';
		return $u ? (string) $u : home_url( '/' );
	}

	public static function manage_url( array $row, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'sb_manage' => $row['token'] ), $args ), self::page_url() );
	}

	public static function pay_url( array $row ): string {
		return add_query_arg( 'scout_booking_pay', $row['token'], home_url( '/' ) );
	}

	public static function ics_url( array $row ): string {
		return add_query_arg( 'scout_booking_ics', $row['token'], home_url( '/' ) );
	}

	public static function feed_url(): string {
		return add_query_arg( 'scout_booking_feed', Scout_Booking::settings()['feed_token'], home_url( '/' ) );
	}

	/** The booking page's own address with step arguments, keeping the page's other arguments out. */
	private static function step_url( array $args ): string {
		$base = get_permalink();
		return add_query_arg( array_filter( $args, static fn( $v ) => '' !== $v && null !== $v ), $base ? $base : self::page_url() );
	}

	/* ---------- calendar files, feed, caching ---------- */

	public static function endpoints(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the secret token is the key.
		if ( isset( $_GET['scout_booking_ics'] ) ) {
			$row = Scout_Booking::find( sanitize_key( wp_unslash( $_GET['scout_booking_ics'] ) ) );
			if ( ! $row || 'blocked' === $row['status'] ) {
				status_header( 404 );
				exit;
			}
			self::serve_ics( Scout_Booking_Emails::ics( array( $row ), $row['type_name'] ), 'appointment.ics', true );
		}
		if ( isset( $_GET['scout_booking_feed'] ) ) {
			$given = sanitize_key( wp_unslash( $_GET['scout_booking_feed'] ) );
			$want  = Scout_Booking::settings()['feed_token'];
			if ( '' === $want || ! hash_equals( $want, $given ) ) {
				status_header( 404 );
				exit;
			}
			$now  = Scout_Booking::now();
			$rows = Scout_Booking::between( $now->modify( '-30 days' )->format( 'Y-m-d H:i:s' ), $now->modify( '+400 days' )->format( 'Y-m-d H:i:s' ), Scout_Booking::HOLDING );
			// A time held for a few minutes of checkout is left out; an order waiting for payment stays in.
			$rows = array_values( array_filter( $rows, static fn( $r ) => Scout_Booking::holds( $r, $now ) && ( 'pending' !== $r['status'] || $r['order_id'] ) ) );
			/* translators: %s: site name. */
			$name = sprintf( __( '%s bookings', 'scout-booking' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
			self::serve_ics( Scout_Booking_Emails::ics( $rows, $name, true ), 'bookings.ics', false );
		}
		// phpcs:enable
		if ( self::is_booking_view() ) {
			nocache_headers();
		}
	}

	private static function serve_ics( string $body, string $file, bool $download ): void {
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . $file . '"' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- calendar data, every text field escaped for the format.
		exit;
	}

	/** The booking page, or any step or booking link on it. Never cached: times and holds change by the minute. */
	private static function is_booking_view(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which view is open.
		foreach ( array( 'sb_type', 'sb_manage', 'sb_done', 'sb_date' ) as $k ) {
			if ( isset( $_GET[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}
		$id = Scout_Booking::settings()['page_id'];
		return $id && is_page( $id );
	}

	/** A client's booking page stays out of search. */
	public static function robots( array $robots ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['sb_manage'] ) || isset( $_GET['sb_done'] ) ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/* ---------- the shortcode ---------- */

	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'type'  => '',
				'types' => '',
			),
			(array) $atts,
			'scout_booking'
		);
		// The first page that shows the form becomes the booking page for email links.
		if ( ! Scout_Booking::settings()['page_id'] && is_singular() && get_the_ID() && 'publish' === get_post_status() ) {
			Scout_Booking::save_settings( array( 'page_id' => (int) get_the_ID() ) );
		}
		wp_enqueue_style( 'scout-booking' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- choosing a view; every change posts with a nonce.
		$g = static fn( string $k ): string => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		// phpcs:enable

		$out = '<div class="sb" id="scout-booking">' . self::notice( $g( 'sb_msg' ) );
		if ( '' !== $g( 'sb_manage' ) ) {
			return $out . self::manage_view( $g( 'sb_manage' ), $g ) . '</div>';
		}
		if ( '' !== $g( 'sb_done' ) ) {
			return $out . self::done_view( $g( 'sb_done' ) ) . '</div>';
		}

		$types = Scout_Booking::types( true );
		if ( '' !== $atts['types'] ) {
			$keep  = array_map( 'trim', explode( ',', strtolower( (string) $atts['types'] ) ) );
			$types = array_intersect_key( $types, array_flip( $keep ) );
		}
		$fixed = '';
		if ( '' !== $atts['type'] && isset( $types[ strtolower( (string) $atts['type'] ) ] ) ) {
			$fixed = strtolower( (string) $atts['type'] );
		} elseif ( 1 === count( $types ) ) {
			$fixed = (string) array_key_first( $types );
		}
		if ( ! $types ) {
			$msg = current_user_can( 'list_users' )
				? __( 'Booking goes here once a session type is turned on under Bookings, Session types. Only people who edit the site see this note.', 'scout-booking' )
				: __( 'Online booking is not open right now. Please get in touch to book a session.', 'scout-booking' );
			return $out . '<p class="sb-note">' . esc_html( $msg ) . '</p></div>';
		}

		$tid  = '' !== $fixed ? $fixed : $g( 'sb_type' );
		$type = $types[ $tid ] ?? null;
		if ( ! $type ) {
			return $out . self::steps( 1, '' === $fixed ) . self::type_list( $types ) . '</div>';
		}
		$date = Scout_Booking::clean_date( $g( 'sb_date' ) );
		$time = Scout_Booking::clean_time( $g( 'sb_time' ) );
		if ( '' !== $date && '' !== $time ) {
			$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
			if ( $start && Scout_Booking_Slots::is_open( $type, $start ) ) {
				return $out . self::steps( 3, '' === $fixed ) . self::chosen( $type, '' === $fixed, $start ) . self::details_form( $type, $start, $g( 'sb_f' ) ) . '</div>';
			}
			$out .= self::notice( 'taken' );
		}
		return $out . self::steps( 2, '' === $fixed ) . self::chosen( $type, '' === $fixed ) . self::time_picker( $type, $g( 'sb_from' ), $date ) . '</div>';
	}

	private static function notice( string $code ): string {
		$texts = array(
			'taken'     => array( 'bad', __( 'That time was just taken or is no longer open. Please pick another.', 'scout-booking' ) ),
			'missing'   => array( 'bad', __( 'Please fill in every field marked required.', 'scout-booking' ) ),
			'email'     => array( 'bad', __( 'Please check the email address.', 'scout-booking' ) ),
			'expired'   => array( 'bad', __( 'The form timed out. Please try again.', 'scout-booking' ) ),
			'booked'    => array( 'good', __( 'You are booked. A confirmation is on its way to your email.', 'scout-booking' ) ),
			'cancelled' => array( 'good', __( 'Your session is cancelled. A note is on its way to your email.', 'scout-booking' ) ),
			'moved'     => array( 'good', __( 'Your session has its new time. The details are on their way to your email.', 'scout-booking' ) ),
			'late'      => array( 'bad', __( 'This session is inside the cancellation window, so it can no longer be changed online.', 'scout-booking' ) ),
			'nochange'  => array( 'bad', __( 'This booking can no longer be changed online.', 'scout-booking' ) ),
			'held'      => array( 'bad', __( 'The time held for checkout has run out. Please pick a time again.', 'scout-booking' ) ),
		);
		if ( ! isset( $texts[ $code ] ) ) {
			return '';
		}
		return '<p class="sb-note is-' . esc_attr( $texts[ $code ][0] ) . '" role="' . ( 'bad' === $texts[ $code ][0] ? 'alert' : 'status' ) . '">' . esc_html( $texts[ $code ][1] ) . '</p>';
	}

	/** "1 Session · 2 Time · 3 Your details". */
	private static function steps( int $on, bool $with_first ): string {
		$names = array(
			1 => __( 'Session', 'scout-booking' ),
			2 => __( 'Time', 'scout-booking' ),
			3 => __( 'Your details', 'scout-booking' ),
		);
		if ( ! $with_first ) {
			unset( $names[1] );
		}
		$html = '<ol class="sb-steps" aria-label="' . esc_attr__( 'Booking steps', 'scout-booking' ) . '">';
		$n    = 0;
		foreach ( $names as $i => $label ) {
			++$n;
			$state = $i < $on ? ' is-done' : ( $i === $on ? ' is-on' : '' );
			$html .= '<li class="sb-step' . $state . '"' . ( $i === $on ? ' aria-current="step"' : '' ) . '><span class="sb-step-n" aria-hidden="true">' . $n . '</span> ' . esc_html( $label ) . '</li>';
		}
		return $html . '</ol>';
	}

	/** Price words for a type: "$150 · paid in full when you book". */
	public static function price_line( array $type ): string {
		if ( $type['price'] <= 0 ) {
			return __( 'No charge', 'scout-booking' );
		}
		$price = Scout_Booking::money( $type['price'] );
		if ( Scout_Booking::is_request( $type ) ) {
			return $price . ' · ' . ( Scout_Booking::payments_on() && Scout_Booking::due( $type ) > 0
				/* translators: %s: amount. */
				? sprintf( __( 'by request, then %s to book', 'scout-booking' ), Scout_Booking::money( Scout_Booking::due( $type ) ) )
				: __( 'by request', 'scout-booking' ) );
		}
		$due = Scout_Booking::due( $type );
		if ( $due <= 0 ) {
			return $price . ' · ' . __( 'paid at your appointment', 'scout-booking' );
		}
		if ( $due < $type['price'] ) {
			/* translators: %s: deposit amount. */
			return $price . ' · ' . sprintf( __( '%s deposit to book', 'scout-booking' ), Scout_Booking::money( $due ) );
		}
		return $price . ' · ' . __( 'paid when you book', 'scout-booking' );
	}

	private static function type_list( array $types ): string {
		$html = '<h2 class="sb-h">' . esc_html__( 'Choose a session', 'scout-booking' ) . '</h2>';
		if ( ! Scout_Booking::payments_on() ) {
			$html .= '<p class="sb-help">' . esc_html__( 'Online payment is not on yet, so every booking here is a request. You will hear back by email, and nothing is charged on the site.', 'scout-booking' ) . '</p>';
		}
		$html .= '<ul class="sb-types">';
		foreach ( $types as $t ) {
			$html .= '<li><a class="sb-type" href="' . esc_url( self::step_url( array( 'sb_type' => $t['id'] ) ) ) . '">'
				. '<span class="sb-type-name">' . esc_html( $t['name'] ) . '</span>'
				. '<span class="sb-type-meta">' . esc_html( Scout_Booking::length_label( $t['length'] ) . ' · ' . self::price_line( $t ) ) . '</span>'
				. ( '' !== $t['note'] ? '<span class="sb-type-note">' . esc_html( wp_trim_words( $t['note'], 30 ) ) . '</span>' : '' )
				. ( Scout_Booking::is_request( $t ) ? '<span class="sb-tag">' . esc_html__( 'By request', 'scout-booking' ) . '</span>' : '' )
				. '</a></li>';
		}
		return $html . '</ul>';
	}

	/** The chosen session (and time) with a way back. */
	private static function chosen( array $type, bool $can_change, ?DateTimeImmutable $start = null ): string {
		$html = '<div class="sb-chosen"><div><p class="sb-chosen-name">' . esc_html( $type['name'] ) . '</p>'
			. '<p class="sb-chosen-meta">' . esc_html( Scout_Booking::length_label( $type['length'] ) . ' · ' . self::price_line( $type ) ) . '</p>';
		if ( $start ) {
			/* translators: 1: date, 2: time. */
			$html .= '<p class="sb-chosen-when">' . esc_html( sprintf( __( '%1$s at %2$s', 'scout-booking' ), wp_date( 'l, F j', $start->getTimestamp() ), wp_date( (string) get_option( 'time_format', 'g:i a' ), $start->getTimestamp() ) ) ) . '</p>';
		}
		$html .= '</div><p class="sb-chosen-links">';
		if ( $start ) {
			$html .= '<a href="' . esc_url( self::step_url( array( 'sb_type' => $can_change ? $type['id'] : '', 'sb_date' => $start->format( 'Y-m-d' ) ) ) ) . '">' . esc_html__( 'Change the time', 'scout-booking' ) . '</a>';
		}
		if ( $can_change ) {
			$html .= '<a href="' . esc_url( self::step_url( array() ) ) . '">' . esc_html__( 'Change the session', 'scout-booking' ) . '</a>';
		}
		$html .= '</p></div>';
		if ( '' !== $type['note'] && ! $start ) {
			$html .= '<p class="sb-statement">' . nl2br( esc_html( $type['note'] ) ) . '</p>';
		}
		return $html;
	}

	/**
	 * A week of days with their open-time counts, and the open times on the
	 * chosen day. In move mode the links carry the booking token.
	 */
	private static function time_picker( array $type, string $from, string $date, ?array $moving = null ): string {
		list( $first, $last ) = Scout_Booking_Slots::window();
		$skip  = $moving ? $moving['id'] : 0;
		$start = Scout_Booking::clean_date( $from );
		$start = '' !== $start ? DateTimeImmutable::createFromFormat( '!Y-m-d', $start, wp_timezone() ) : $first->setTime( 0, 0 );
		if ( $start < $first->setTime( 0, 0 ) ) {
			$start = $first->setTime( 0, 0 );
		}
		$days = Scout_Booking_Slots::days( $type, $start, 7, $skip );
		$base = $moving ? array( 'sb_manage' => $moving['token'], 'sb_move' => '1' ) : array( 'sb_type' => self::type_arg( $type ) );

		// Nothing in this week: look ahead for the first day with an opening.
		if ( ! array_filter( $days ) && '' === $from ) {
			$ahead = Scout_Booking_Slots::days( $type, $start, (int) Scout_Booking::settings()['days_ahead'] + 1, $skip );
			$next  = array_key_first( array_filter( $ahead ) );
			if ( null === $next ) {
				return '<p class="sb-note">' . esc_html__( 'There are no open times to book online right now. Please get in touch, and we will find a time together.', 'scout-booking' ) . '</p>';
			}
			$start = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $next, wp_timezone() );
			$days  = Scout_Booking_Slots::days( $type, $start, 7, $skip );
		}
		if ( '' === $date || ! isset( $days[ $date ] ) || ! $days[ $date ] ) {
			$date = (string) array_key_first( array_filter( $days ) );
		}

		$html  = '<h2 class="sb-h" id="sb-pick">' . esc_html__( 'Pick a day and time', 'scout-booking' ) . '</h2>';
		$html .= '<p class="sb-help">' . esc_html(
			sprintf(
				/* translators: %s: timezone name, e.g. America/New_York. */
				__( 'Times are in %s.', 'scout-booking' ),
				str_replace( '_', ' ', wp_timezone_string() )
			)
		) . '</p>';
		$prev  = $start->modify( '-7 days' );
		$next  = $start->modify( '+7 days' );
		$html .= '<div class="sb-week">';
		$html .= $start > $first->setTime( 0, 0 )
			? '<a class="sb-week-nav" href="' . esc_url( self::step_url( array_merge( $base, array( 'sb_from' => max( $prev, $first->setTime( 0, 0 ) )->format( 'Y-m-d' ) ) ) ) . '#sb-pick' ) . '">' . esc_html__( 'Earlier', 'scout-booking' ) . '</a>'
			: '<span class="sb-week-nav" aria-hidden="true"></span>';
		/* translators: 1: first day of the week shown, 2: last day. */
		$html .= '<p class="sb-week-label">' . esc_html( sprintf( __( '%1$s to %2$s', 'scout-booking' ), wp_date( 'F j', $start->setTime( 12, 0 )->getTimestamp() ), wp_date( 'F j', $start->modify( '+6 days' )->setTime( 12, 0 )->getTimestamp() ) ) ) . '</p>';
		$html .= $next <= $last
			? '<a class="sb-week-nav" href="' . esc_url( self::step_url( array_merge( $base, array( 'sb_from' => $next->format( 'Y-m-d' ) ) ) ) . '#sb-pick' ) . '">' . esc_html__( 'Later', 'scout-booking' ) . '</a>'
			: '<span class="sb-week-nav" aria-hidden="true"></span>';
		$html .= '</div><ul class="sb-days">';
		foreach ( $days as $ymd => $n ) {
			$d     = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() )->setTime( 12, 0 );
			$inner = '<span class="sb-dow">' . esc_html( wp_date( 'D', $d->getTimestamp() ) ) . '</span><span class="sb-date">' . esc_html( wp_date( 'M j', $d->getTimestamp() ) ) . '</span>'
				/* translators: %d: number of open times. */
				. '<span class="sb-count">' . esc_html( $n ? sprintf( _n( '%d time', '%d times', $n, 'scout-booking' ), $n ) : ( Scout_Booking_Slots::working( $type, $d ) ? __( 'No times', 'scout-booking' ) : __( 'Closed', 'scout-booking' ) ) ) . '</span>';
			if ( $n ) {
				$html .= '<li><a class="sb-day' . ( $ymd === $date ? ' is-on' : '' ) . '" href="' . esc_url( self::step_url( array_merge( $base, array( 'sb_from' => $start->format( 'Y-m-d' ), 'sb_date' => $ymd ) ) ) . '#sb-times' ) . '"' . ( $ymd === $date ? ' aria-current="date"' : '' ) . '>' . $inner . '</a></li>';
			} else {
				$html .= '<li><span class="sb-day is-full">' . $inner . '</span></li>';
			}
		}
		$html .= '</ul>';
		if ( '' === $date ) {
			return $html . '<p class="sb-note">' . esc_html__( 'No open times this week. Try later dates.', 'scout-booking' ) . '</p>';
		}
		$day   = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, wp_timezone() );
		$times = Scout_Booking_Slots::for_day( $type, $day, $skip );
		$tf    = (string) get_option( 'time_format', 'g:i a' );
		/* translators: %s: date. */
		$html .= '<h3 class="sb-h3" id="sb-times">' . esc_html( sprintf( __( 'Open times on %s', 'scout-booking' ), wp_date( 'l, F j', $day->setTime( 12, 0 )->getTimestamp() ) ) ) . '</h3><ul class="sb-times">';
		foreach ( $times as $t ) {
			$html .= '<li><a class="sb-time" href="' . esc_url( self::step_url( array_merge( $base, array( 'sb_date' => $date, 'sb_time' => $t->format( 'H:i' ) ) ) ) . '#scout-booking' ) . '">' . esc_html( wp_date( $tf, $t->getTimestamp() ) ) . '</a></li>';
		}
		return $html . '</ul>';
	}

	/** The type argument for step links, empty when the page shows one fixed type. */
	private static function type_arg( array $type ): string {
		return $type['id'];
	}

	/** Step 3: details, intake questions, newsletter tick, policy, submit. */
	private static function details_form( array $type, DateTimeImmutable $start, string $stash ): string {
		$old = array();
		if ( '' !== $stash ) {
			$old = (array) get_transient( 'scout_booking_f_' . sanitize_key( $stash ) );
		}
		$v       = static fn( string $k ): string => isset( $old[ $k ] ) ? (string) $old[ $k ] : '';
		$request = Scout_Booking::is_request( $type );
		$due     = Scout_Booking::due( $type );
		if ( $request ) {
			$button = __( 'Send my request', 'scout-booking' );
		} elseif ( $due > 0 ) {
			/* translators: %s: amount. */
			$button = sprintf( __( 'Continue to payment (%s)', 'scout-booking' ), Scout_Booking::money( $due ) );
		} else {
			$button = __( 'Book this time', 'scout-booking' );
		}
		$req = ' <span class="sb-req">' . esc_html__( '(required)', 'scout-booking' ) . '</span>';

		$html  = '<form class="sb-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="scout_booking_submit">';
		$html .= wp_nonce_field( 'scout_booking_submit', '_sbnonce', true, false );
		$html .= '<input type="hidden" name="sb_type" value="' . esc_attr( $type['id'] ) . '">';
		$html .= '<input type="hidden" name="sb_start" value="' . esc_attr( $start->format( 'Y-m-d H:i' ) ) . '">';
		$html .= '<input type="hidden" name="sb_t" value="' . esc_attr( (string) time() ) . '">';
		$html .= '<input type="hidden" name="sb_back" value="' . esc_url( self::step_url( array( 'sb_type' => $type['id'], 'sb_date' => $start->format( 'Y-m-d' ), 'sb_time' => $start->format( 'H:i' ) ) ) ) . '">';
		$html .= '<div class="sb-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this empty', 'scout-booking' ) . ' <input type="text" name="sb_website" tabindex="-1" autocomplete="off"></label></div>';

		$html .= '<fieldset class="sb-group"><legend>' . esc_html__( 'Your details', 'scout-booking' ) . '</legend><div class="sb-row">';
		$fields = array(
			'first_name' => array( __( 'First name', 'scout-booking' ), 'text', 'given-name' ),
			'last_name'  => array( __( 'Last name', 'scout-booking' ), 'text', 'family-name' ),
			'email'      => array( __( 'Email', 'scout-booking' ), 'email', 'email' ),
			'phone'      => array( __( 'Phone', 'scout-booking' ), 'tel', 'tel' ),
		);
		foreach ( $fields as $k => $f ) {
			$html .= '<p class="sb-field"><label for="sb-' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . $req . '</label>'
				. '<input type="' . esc_attr( $f[1] ) . '" id="sb-' . esc_attr( $k ) . '" name="sb[' . esc_attr( $k ) . ']" value="' . esc_attr( $v( $k ) ) . '" autocomplete="' . esc_attr( $f[2] ) . '" required></p>';
		}
		$html .= '</div></fieldset>';

		if ( $type['questions'] ) {
			$html .= '<fieldset class="sb-group"><legend>' . esc_html__( 'A few questions', 'scout-booking' ) . '</legend>';
			foreach ( $type['questions'] as $i => $q ) {
				$id    = 'sb-q' . $i;
				$val   = isset( $old['q'][ $i ] ) ? (string) $old['q'][ $i ] : '';
				$html .= '<p class="sb-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $q['label'] ) . ( $q['required'] ? $req : '' ) . '</label>';
				$html .= $q['long']
					? '<textarea id="' . esc_attr( $id ) . '" name="sb[q][' . (int) $i . ']" rows="3"' . ( $q['required'] ? ' required' : '' ) . '>' . esc_textarea( $val ) . '</textarea>'
					: '<input type="text" id="' . esc_attr( $id ) . '" name="sb[q][' . (int) $i . ']" value="' . esc_attr( $val ) . '"' . ( $q['required'] ? ' required' : '' ) . '>';
				$html .= '</p>';
			}
			$html .= '</fieldset>';
		}

		if ( Scout_Booking::settings()['optin'] ) {
			$html .= '<p class="sb-check"><label><input type="checkbox" name="sb[optin]" value="1"' . checked( '1', $v( 'optin' ), false ) . '> <span>' . esc_html( Scout_Booking::optin_label() ) . '</span></label></p>';
		}

		$policy = Scout_Booking::policy();
		$what   = $request
			? ( Scout_Booking::payments_on() && $due > 0
				/* translators: %s: amount. */
				? sprintf( __( 'This session is by request. Nothing is charged now. If it is approved, you will get an email with a link to pay %s and book it.', 'scout-booking' ), Scout_Booking::money( $due ) )
				: __( 'This sends a request. Nothing is charged on the site, and you will hear back by email.', 'scout-booking' ) )
			: ( $due > 0
				? ( $due < $type['price']
					/* translators: 1: deposit, 2: balance. */
					? sprintf( __( 'Next is the checkout, where you pay the %1$s deposit. The balance of %2$s is paid at your appointment.', 'scout-booking' ), Scout_Booking::money( $due ), Scout_Booking::money( $type['price'] - $due ) )
					: __( 'Next is the checkout, where you pay for the session. A discount code goes in at the checkout.', 'scout-booking' ) )
				: '' );
		if ( '' !== $what || '' !== $policy ) {
			$html .= '<div class="sb-statement">' . ( '' !== $what ? '<p>' . esc_html( $what ) . '</p>' : '' ) . ( '' !== $policy ? '<p>' . esc_html( $policy ) . '</p>' : '' ) . '</div>';
		}
		if ( ! $request && $due > 0 ) {
			$hold = Scout_Booking::settings()['hold_minutes'];
			/* translators: %d: minutes. */
			$html .= '<p class="sb-help">' . esc_html( sprintf( __( 'The time is held for you for %d minutes while you pay.', 'scout-booking' ), $hold ) ) . '</p>';
		}
		$html .= '<p><button type="submit" class="sb-button">' . esc_html( $button ) . '</button></p></form>';
		return $html;
	}

	/* ---------- after a request ---------- */

	private static function done_view( string $token ): string {
		$row = Scout_Booking::find( $token );
		if ( ! $row ) {
			return '<p class="sb-note">' . esc_html__( 'We could not find that booking.', 'scout-booking' ) . '</p>';
		}
		return '<div class="sb-done"><h2 class="sb-h">' . esc_html__( 'Your request is in', 'scout-booking' ) . '</h2>'
			. '<p>' . esc_html__( 'Thank you. A person reads every request, and you will hear back by email. Nothing is booked or charged yet.', 'scout-booking' ) . '</p>'
			. self::facts( $row )
			. '<p><a href="' . esc_url( self::manage_url( $row ) ) . '">' . esc_html__( 'See your request', 'scout-booking' ) . '</a></p></div>';
	}

	private static function facts( array $row ): string {
		$minutes = (int) round( ( Scout_Booking::at( $row['end_at'] )->getTimestamp() - Scout_Booking::at( $row['start_at'] )->getTimestamp() ) / 60 );
		$html    = '<dl class="sb-facts">'
			. '<div><dt>' . esc_html__( 'Session', 'scout-booking' ) . '</dt><dd>' . esc_html( $row['type_name'] ) . '</dd></div>'
			. '<div><dt>' . esc_html__( 'When', 'scout-booking' ) . '</dt><dd>' . esc_html( Scout_Booking::when_label( $row ) ) . '</dd></div>'
			. '<div><dt>' . esc_html__( 'Length', 'scout-booking' ) . '</dt><dd>' . esc_html( Scout_Booking::length_label( $minutes ) ) . '</dd></div>';
		$money   = trim( Scout_Booking_Emails::money_line( $row ) );
		if ( '' !== $money && in_array( $row['status'], array( 'confirmed', 'cancelled' ), true ) ) {
			$html .= '<div><dt>' . esc_html__( 'Payment', 'scout-booking' ) . '</dt><dd>' . esc_html( $money ) . '</dd></div>';
		}
		return $html . '</dl>';
	}

	/* ---------- a client's booking ---------- */

	private static function manage_view( string $token, callable $g ): string {
		$row = Scout_Booking::find( $token );
		if ( ! $row || 'blocked' === $row['status'] ) {
			return '<p class="sb-note">' . esc_html__( 'We could not find that booking. Please use the link in your latest email.', 'scout-booking' ) . '</p>';
		}
		$future = Scout_Booking::at( $row['start_at'] ) > Scout_Booking::now();
		$live   = in_array( $row['status'], array( 'requested', 'approved', 'pending', 'confirmed' ), true ) && $future;
		$type   = Scout_Booking::type( $row['type_id'] );

		// Moving: the same picker, then a confirm button.
		if ( '1' === $g( 'sb_move' ) && $live && $type && Scout_Booking::outside_window( $row ) ) {
			$date = Scout_Booking::clean_date( $g( 'sb_date' ) );
			$time = Scout_Booking::clean_time( $g( 'sb_time' ) );
			$html = '<p class="sb-back"><a href="' . esc_url( self::manage_url( $row ) ) . '">' . esc_html__( 'Back to your booking', 'scout-booking' ) . '</a></p>';
			/* translators: %s: current date and time. */
			$html .= '<p class="sb-help">' . esc_html( sprintf( __( 'Now booked for %s.', 'scout-booking' ), Scout_Booking::when_label( $row ) ) ) . '</p>';
			if ( '' !== $date && '' !== $time ) {
				$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
				if ( $start && Scout_Booking_Slots::is_open( $type, $start, $row['id'] ) ) {
					/* translators: %s: new date and time. */
					$html .= '<h2 class="sb-h">' . esc_html( sprintf( __( 'Move it to %s?', 'scout-booking' ), wp_date( 'l, F j', $start->getTimestamp() ) . ' ' . wp_date( (string) get_option( 'time_format', 'g:i a' ), $start->getTimestamp() ) ) ) . '</h2>'
						. self::manage_form( $row, 'move', __( 'Yes, move my session', 'scout-booking' ), array( 'sb_start' => $start->format( 'Y-m-d H:i' ) ) )
						. '<p><a href="' . esc_url( self::manage_url( $row, array( 'sb_move' => '1', 'sb_date' => $date ) ) ) . '">' . esc_html__( 'Pick another time', 'scout-booking' ) . '</a></p>';
					return $html;
				}
				$html .= self::notice( 'taken' );
			}
			return $html . self::time_picker( $type, $g( 'sb_from' ), $date, $row );
		}

		$heads = array(
			'requested' => __( 'Your request is waiting', 'scout-booking' ),
			'approved'  => __( 'Approved: pay to book it', 'scout-booking' ),
			'pending'   => __( 'Almost booked', 'scout-booking' ),
			'confirmed' => $future ? __( 'You are booked', 'scout-booking' ) : __( 'Your session', 'scout-booking' ),
			'cancelled' => __( 'This session is cancelled', 'scout-booking' ),
			'declined'  => __( 'This request was declined', 'scout-booking' ),
			'expired'   => __( 'This time was released', 'scout-booking' ),
		);
		$html  = '<div class="sb-manage"><h2 class="sb-h">' . esc_html( $heads[ $row['status'] ] ?? '' ) . '</h2>';
		$html .= self::facts( $row );

		if ( 'approved' === $row['status'] && $future && $row['due'] > 0 && Scout_Booking::payments_on() ) {
			/* translators: %s: amount. */
			$html .= '<p><a class="sb-button" href="' . esc_url( self::pay_url( $row ) ) . '">' . esc_html( sprintf( __( 'Pay %s and book', 'scout-booking' ), Scout_Booking::money( $row['due'] ) ) ) . '</a></p>';
		}
		if ( 'pending' === $row['status'] && Scout_Booking::holds( $row ) && Scout_Booking::payments_on() ) {
			$html .= '<p><a class="sb-button" href="' . esc_url( self::pay_url( $row ) ) . '">' . esc_html__( 'Continue to payment', 'scout-booking' ) . '</a></p>';
		}
		if ( 'requested' === $row['status'] ) {
			$html .= '<p>' . esc_html__( 'A person reads every request, and you will hear back by email. Nothing is charged yet.', 'scout-booking' ) . '</p>';
		}
		if ( in_array( $row['status'], array( 'cancelled', 'declined', 'expired' ), true ) ) {
			$html .= '<p><a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Book another time', 'scout-booking' ) . '</a></p>';
		}

		if ( 'confirmed' === $row['status'] && $future ) {
			$html .= '<p class="sb-actions"><a class="sb-button is-quiet" href="' . esc_url( self::ics_url( $row ) ) . '">' . esc_html__( 'Add to my calendar', 'scout-booking' ) . '</a> '
				. '<a class="sb-button is-quiet" href="' . esc_url( Scout_Booking_Emails::google_url( $row ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Add to Google Calendar', 'scout-booking' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'scout-booking' ) . '</span></a></p>';
		}

		if ( $live && 'pending' !== $row['status'] ) {
			$policy = Scout_Booking::policy();
			$html  .= '<div class="sb-change"><h3 class="sb-h3">' . esc_html__( 'Change or cancel', 'scout-booking' ) . '</h3>' . ( '' !== $policy ? '<p>' . esc_html( $policy ) . '</p>' : '' );
			if ( Scout_Booking::outside_window( $row ) ) {
				if ( $type ) {
					$html .= '<p><a class="sb-button is-quiet" href="' . esc_url( self::manage_url( $row, array( 'sb_move' => '1' ) ) ) . '">' . esc_html__( 'Change the time', 'scout-booking' ) . '</a></p>';
				}
				$html .= self::manage_form( $row, 'cancel', 'requested' === $row['status'] ? __( 'Withdraw my request', 'scout-booking' ) : __( 'Cancel my session', 'scout-booking' ) );
			} else {
				$contact = Scout_Booking::notify_email();
				$html   .= '<p>' . esc_html(
					sprintf(
						/* translators: %s: email address. */
						__( 'Your session is less than the notice time away, so it can no longer be changed online. Please email %s.', 'scout-booking' ),
						$contact
					)
				) . '</p>';
				if ( 'allow' === Scout_Booking::settings()['late'] ) {
					$html .= '<p class="sb-help">' . esc_html__( 'You can still cancel here, and the deposit is kept as the policy says.', 'scout-booking' ) . '</p>'
						. self::manage_form( $row, 'cancel', __( 'Cancel my session', 'scout-booking' ) );
				}
			}
			$html .= '</div>';
		}
		return $html . '</div>';
	}

	private static function manage_form( array $row, string $do, string $label, array $extra = array() ): string {
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sb-inline">'
			. '<input type="hidden" name="action" value="scout_booking_manage">'
			. '<input type="hidden" name="token" value="' . esc_attr( $row['token'] ) . '">'
			. '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
			. wp_nonce_field( 'scout_booking_manage_' . $do, '_sbnonce', true, false );
		foreach ( $extra as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		return $html . '<button type="submit" class="sb-button' . ( 'cancel' === $do ? ' is-danger' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/* ---------- handlers ---------- */

	private static function go( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/** Back to the form with a message, keeping what was typed for ten minutes. */
	private static function back( string $url, string $msg, array $typed = array() ): void {
		$args = array( 'sb_msg' => $msg );
		if ( $typed ) {
			$key = strtolower( wp_generate_password( 12, false ) );
			set_transient( 'scout_booking_f_' . $key, $typed, 10 * MINUTE_IN_SECONDS );
			$args['sb_f'] = $key;
		}
		self::go( add_query_arg( $args, $url ) . '#scout-booking' );
	}

	public static function submit(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just below.
		$back = isset( $_POST['sb_back'] ) ? esc_url_raw( wp_unslash( $_POST['sb_back'] ) ) : self::page_url();
		$back = wp_validate_redirect( $back, self::page_url() );
		if ( ! isset( $_POST['_sbnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sbnonce'] ) ), 'scout_booking_submit' ) ) {
			self::back( $back, 'expired' );
		}
		// phpcs:enable
		// Bots fill the hidden field or post faster than a person can type.
		if ( ! empty( $_POST['sb_website'] ) || time() - (int) ( $_POST['sb_t'] ?? 0 ) < self::MIN_SECONDS ) {
			self::go( self::page_url() );
		}
		$in   = isset( $_POST['sb'] ) ? (array) wp_unslash( $_POST['sb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is cleaned below.
		$type = Scout_Booking::types( true )[ sanitize_key( wp_unslash( $_POST['sb_type'] ?? '' ) ) ] ?? null;
		$when = sanitize_text_field( wp_unslash( $_POST['sb_start'] ?? '' ) );
		if ( ! $type ) {
			self::go( self::page_url() );
		}
		$typed = array(
			'first_name' => sanitize_text_field( (string) ( $in['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( (string) ( $in['last_name'] ?? '' ) ),
			'email'      => sanitize_email( (string) ( $in['email'] ?? '' ) ),
			'phone'      => preg_replace( '/[^0-9+().\-\s]/', '', sanitize_text_field( (string) ( $in['phone'] ?? '' ) ) ),
			'optin'      => empty( $in['optin'] ) ? '' : '1',
			'q'          => array(),
		);
		$answers = array();
		$missing = '' === $typed['first_name'] || '' === $typed['last_name'] || '' === $typed['phone'];
		foreach ( $type['questions'] as $i => $q ) {
			$a                 = sanitize_textarea_field( (string) ( $in['q'][ $i ] ?? '' ) );
			$typed['q'][ $i ]  = $a;
			$answers[]         = array(
				'q' => $q['label'],
				'a' => $a,
			);
			$missing = $missing || ( $q['required'] && '' === trim( $a ) );
		}
		if ( ! is_email( $typed['email'] ) ) {
			self::back( $back, 'email', $typed );
		}
		if ( $missing ) {
			self::back( $back, 'missing', $typed );
		}
		$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $when, wp_timezone() );
		if ( ! $start || ! Scout_Booking_Slots::is_open( $type, $start ) ) {
			self::back( remove_query_arg( 'sb_time', $back ), 'taken' );
		}

		$request = Scout_Booking::is_request( $type );
		$due     = Scout_Booking::due( $type );
		$status  = $request ? 'requested' : ( $due > 0 ? 'pending' : 'confirmed' );
		$end     = $start->modify( '+' . $type['length'] . ' minutes' );
		$row     = Scout_Booking::add(
			array(
				'type_id'    => $type['id'],
				'type_name'  => $type['name'],
				'start_at'   => $start->format( 'Y-m-d H:i:s' ),
				'end_at'     => $end->format( 'Y-m-d H:i:s' ),
				'block_end'  => $end->modify( '+' . $type['buffer'] . ' minutes' )->format( 'Y-m-d H:i:s' ),
				'status'     => $status,
				'first_name' => $typed['first_name'],
				'last_name'  => $typed['last_name'],
				'email'      => $typed['email'],
				'phone'      => $typed['phone'],
				'answers'    => $answers,
				'price'      => $type['price'],
				'due'        => $due,
				'optin'      => '1' === $typed['optin'],
				'hold_until' => 'pending' === $status ? Scout_Booking::now()->modify( '+' . Scout_Booking::settings()['hold_minutes'] . ' minutes' )->format( 'Y-m-d H:i:s' ) : null,
			)
		);
		if ( ! $row ) {
			self::back( remove_query_arg( 'sb_time', $back ), 'taken' );
		}

		if ( '1' === $typed['optin'] ) {
			self::newsletter( $row, $type, (string) strtok( $back, '?#' ) );
		}
		/**
		 * A booking or request was made on the site.
		 *
		 * @param array $row  The booking.
		 * @param array $type Its session type.
		 */
		do_action( 'scout_booking_created', $row, $type );

		if ( 'requested' === $status ) {
			Scout_Booking_Emails::request_received( $row );
			Scout_Booking_Emails::request_owner( $row );
			self::go( add_query_arg( 'sb_done', $row['token'], self::page_url() ) . '#scout-booking' );
		}
		if ( 'confirmed' === $status ) {
			Scout_Booking_Emails::confirmed( $row );
			self::go( self::manage_url( $row, array( 'sb_msg' => 'booked' ) ) . '#scout-booking' );
		}
		self::go( self::pay_url( $row ) );
	}

	/**
	 * Hand the opt-in to the newsletter connector (Scout Newsletter listens on
	 * this action). The source "booking" is what its tag map reads, so no tags
	 * are sent. The context carries the exact words beside the box and the page
	 * the form was on, as the record of what the person agreed to, and the
	 * session type's id as the campaign.
	 *
	 * @param string $page The page the booking form was on.
	 */
	private static function newsletter( array $row, array $type, string $page ): void {
		do_action(
			'scout_newsletter_optin',
			$row['email'],
			$row['first_name'],
			'booking',
			array(),
			array(
				'wording'  => Scout_Booking::optin_label(),
				'url'      => $page,
				'page'     => $page,
				'campaign' => $type['id'],
			)
		);
	}

	public static function manage(): void {
		$token = sanitize_key( wp_unslash( $_POST['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below with the action.
		$do    = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$row   = Scout_Booking::find( $token );
		if ( ! $row || ! in_array( $do, array( 'cancel', 'move' ), true ) ) {
			self::go( self::page_url() );
		}
		if ( ! isset( $_POST['_sbnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_sbnonce'] ) ), 'scout_booking_manage_' . $do ) ) {
			self::go( self::manage_url( $row, array( 'sb_msg' => 'expired' ) ) );
		}
		$live = in_array( $row['status'], array( 'requested', 'approved', 'confirmed' ), true ) && Scout_Booking::at( $row['start_at'] ) > Scout_Booking::now();
		if ( ! $live ) {
			self::go( self::manage_url( $row, array( 'sb_msg' => 'nochange' ) ) );
		}
		$outside = Scout_Booking::outside_window( $row );

		if ( 'cancel' === $do ) {
			if ( ! $outside && 'allow' !== Scout_Booking::settings()['late'] ) {
				self::go( self::manage_url( $row, array( 'sb_msg' => 'late' ) ) );
			}
			$row = Scout_Booking::update( $row['id'], array( 'status' => 'cancelled' ) );
			Scout_Booking_Emails::cancelled( $row, 'client', ! $outside );
			self::go( self::manage_url( $row, array( 'sb_msg' => 'cancelled' ) ) . '#scout-booking' );
		}

		// Move.
		$type  = Scout_Booking::type( $row['type_id'] );
		$start = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', sanitize_text_field( wp_unslash( $_POST['sb_start'] ?? '' ) ), wp_timezone() );
		if ( ! $outside ) {
			self::go( self::manage_url( $row, array( 'sb_msg' => 'late' ) ) );
		}
		if ( ! $type || ! $start || ! Scout_Booking_Slots::is_open( $type, $start, $row['id'] ) ) {
			self::go( self::manage_url( $row, array( 'sb_move' => '1', 'sb_msg' => 'taken' ) ) );
		}
		$was    = Scout_Booking::when_label( $row );
		$length = (int) round( ( Scout_Booking::at( $row['end_at'] )->getTimestamp() - Scout_Booking::at( $row['start_at'] )->getTimestamp() ) / 60 );
		$end    = $start->modify( '+' . $length . ' minutes' );
		$row    = Scout_Booking::update(
			$row['id'],
			array(
				'start_at'  => $start->format( 'Y-m-d H:i:s' ),
				'end_at'    => $end->format( 'Y-m-d H:i:s' ),
				'block_end' => $end->modify( '+' . $type['buffer'] . ' minutes' )->format( 'Y-m-d H:i:s' ),
				'reminded'  => null,
			)
		);
		Scout_Booking_Emails::rescheduled( $row, $was );
		self::go( self::manage_url( $row, array( 'sb_msg' => 'moved' ) ) . '#scout-booking' );
	}
}
