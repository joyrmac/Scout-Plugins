<?php
/**
 * Every booking email, in plain text like the rest of the Scout suite, and
 * the calendar files: one appointment for the client, and the owner's
 * private feed.
 *
 * Client emails carry the session, the time, what was paid and what is left,
 * the cancellation policy, and the links to add it to a calendar, change it
 * or cancel it. Owner emails link straight to the Bookings screen.
 *
 * @package Scout_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Booking_Emails {

	private static function site(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	private static function sign_off(): string {
		return "\n" . self::site() . "\n" . home_url( '/' );
	}

	/** Session, time and length, one per line. */
	private static function facts( array $row ): string {
		$type   = Scout_Booking::type( $row['type_id'] );
		$minute = (int) round( ( Scout_Booking::at( $row['end_at'] )->getTimestamp() - Scout_Booking::at( $row['start_at'] )->getTimestamp() ) / 60 );
		return $row['type_name'] . "\n" . Scout_Booking::when_label( $row ) . "\n" . Scout_Booking::length_label( $minute ) . ( $type && '' !== $type['note'] ? "\n\n" . $type['note'] : '' ) . "\n";
	}

	/** "Paid online: $90. Balance at your appointment: $0." */
	public static function money_line( array $row ): string {
		if ( $row['price'] <= 0 ) {
			return '';
		}
		if ( $row['paid'] > 0 ) {
			$left = max( 0, $row['price'] - $row['due'] );
			if ( $left <= 0 ) {
				/* translators: %s: amount paid. */
				return sprintf( __( 'Paid online: %s. Nothing more to pay.', 'scout-booking' ), Scout_Booking::money( $row['paid'] ) ) . "\n";
			}
			/* translators: 1: amount paid, 2: amount left to pay. */
			return sprintf( __( 'Paid online: %1$s. Balance at your appointment: %2$s.', 'scout-booking' ), Scout_Booking::money( $row['paid'] ), Scout_Booking::money( $left ) ) . "\n";
		}
		/* translators: %s: session price. */
		return sprintf( __( 'Session price: %s, paid at your appointment.', 'scout-booking' ), Scout_Booking::money( $row['price'] ) ) . "\n";
	}

	private static function policy_line(): string {
		$p = Scout_Booking::policy();
		return '' !== $p ? "\n" . $p . "\n" : '';
	}

	private static function send( string $to, string $subject, string $body, array $files = array() ): void {
		if ( ! is_email( $to ) ) {
			return;
		}
		wp_mail( $to, $subject, $body, array(), $files );
	}

	/* ---------- requests ---------- */

	public static function request_received( array $row ): void {
		$body = sprintf( /* translators: %s: first name. */ __( 'Hi %s,', 'scout-booking' ), $row['first_name'] ) . "\n\n"
			. __( 'Thank you for your request. A person reads every one, and you will hear back by email. Nothing is booked or charged yet.', 'scout-booking' ) . "\n\n"
			. self::facts( $row ) . "\n"
			. __( 'Your request, any time:', 'scout-booking' ) . "\n" . Scout_Booking_Front::manage_url( $row ) . "\n"
			. self::sign_off();
		/* translators: %s: session name. */
		self::send( $row['email'], sprintf( __( 'We have your request: %s', 'scout-booking' ), $row['type_name'] ), $body );
	}

	public static function request_owner( array $row ): void {
		$body = __( 'A new session request is waiting for you to approve or decline.', 'scout-booking' ) . "\n\n"
			. self::facts( $row ) . "\n"
			. self::client_block( $row )
			. "\n" . __( 'Approve or decline it here:', 'scout-booking' ) . "\n" . Scout_Booking_Admin::url( array(), 'sb-requests' ) . "\n";
		/* translators: 1: client name, 2: session name. */
		self::send( Scout_Booking::notify_email(), sprintf( __( 'Session request from %1$s: %2$s', 'scout-booking' ), Scout_Booking::client_name( $row ), $row['type_name'] ), $body );
	}

	public static function approved( array $row ): void {
		$pay  = $row['due'] > 0 && Scout_Booking::payments_on();
		$body = sprintf( /* translators: %s: first name. */ __( 'Hi %s,', 'scout-booking' ), $row['first_name'] ) . "\n\n";
		if ( $pay ) {
			$hours = Scout_Booking::settings()['pay_hours'];
			$body .= sprintf(
				/* translators: 1: amount to pay, 2: hours. */
				_n( 'Good news: your request is approved. To book it, pay %1$s within %2$d hour here:', 'Good news: your request is approved. To book it, pay %1$s within %2$d hours here:', $hours, 'scout-booking' ),
				Scout_Booking::money( $row['due'] ),
				$hours
			) . "\n" . Scout_Booking_Front::pay_url( $row ) . "\n\n" . self::facts( $row );
			if ( $row['price'] > $row['due'] ) {
				/* translators: %s: amount left to pay. */
				$body .= sprintf( __( 'The balance of %s is paid at your appointment.', 'scout-booking' ), Scout_Booking::money( $row['price'] - $row['due'] ) ) . "\n";
			}
		}
		$body .= self::policy_line() . self::sign_off();
		/* translators: %s: session name. */
		self::send( $row['email'], sprintf( __( 'Approved: %s', 'scout-booking' ), $row['type_name'] ), $body );
	}

	public static function declined( array $row ): void {
		$body = sprintf( /* translators: %s: first name. */ __( 'Hi %s,', 'scout-booking' ), $row['first_name'] ) . "\n\n"
			. __( 'Thank you for your request. This time is not one we can offer, so the request is closed and nothing was charged. You are welcome to reply to this email to talk about another option.', 'scout-booking' ) . "\n\n"
			. self::facts( $row ) . self::sign_off();
		/* translators: %s: session name. */
		self::send( $row['email'], sprintf( __( 'About your request: %s', 'scout-booking' ), $row['type_name'] ), $body );
	}

	public static function expired( array $row ): void {
		$body = sprintf( /* translators: %s: first name. */ __( 'Hi %s,', 'scout-booking' ), $row['first_name'] ) . "\n\n"
			. __( 'Your approved request was not paid in time, so the time has been released. You are welcome to book again on the site.', 'scout-booking' ) . "\n\n"
			. self::facts( $row ) . "\n" . Scout_Booking_Front::page_url() . "\n" . self::sign_off();
		/* translators: %s: session name. */
		self::send( $row['email'], sprintf( __( 'Time released: %s', 'scout-booking' ), $row['type_name'] ), $body );
	}

	/* ---------- booked ---------- */

	public static function confirmed( array $row ): void {
		$files = array();
		$ics   = self::ics_file( $row );
		if ( '' !== $ics ) {
			$files[] = $ics;
		}
		$body = sprintf( /* translators: %s: first name. */ __( 'Hi %s,', 'scout-booking' ), $row['first_name'] ) . "\n\n"
			. __( 'You are booked. Here are the details.', 'scout-booking' ) . "\n\n"
			. self::facts( $row ) . "\n" . self::money_line( $row )
			. self::links( $row ) . self::policy_line() . self::sign_off();
		/* translators: 1: session name, 2: date. */
		self::send( $row['email'], sprintf( __( 'Booked: %1$s on %2$s', 'scout-booking' ), $row['type_name'], wp_date( 'F j', Scout_Booking::at( $row['start_at'] )->getTimestamp() ) ), $body, $files );
		self::cleanup( $files );
		$owner = __( 'A session is booked.', 'scout-booking' ) . "\n\n" . self::facts( $row ) . "\n" . self::owner_money( $row ) . self::client_block( $row )
			. "\n" . Scout_Booking_Admin::url( array( 'date' => substr( $row['start_at'], 0, 10 ) ) ) . "\n";
		/* translators: 1: client name, 2: session name. */
		self::send( Scout_Booking::notify_email(), sprintf( __( 'Booked: %1$s, %2$s', 'scout-booking' ), Scout_Booking::client_name( $row ), $row['type_name'] ), $owner );
	}

	public static function reminder( array $row ): void {
		$body = sprintf( /* translators: %s: first name. */ __( 'Hi %s,', 'scout-booking' ), $row['first_name'] ) . "\n\n"
			. __( 'A reminder of your session.', 'scout-booking' ) . "\n\n"
			. self::facts( $row ) . "\n" . self::money_line( $row ) . self::links( $row ) . self::policy_line() . self::sign_off();
		/* translators: %s: session name. */
		self::send( $row['email'], sprintf( __( 'See you soon: %s', 'scout-booking' ), $row['type_name'] ), $body );
	}

	public static function rescheduled( array $row, string $was ): void {
		$body = sprintf( /* translators: %s: first name. */ __( 'Hi %s,', 'scout-booking' ), $row['first_name'] ) . "\n\n"
			. __( 'Your session has a new time.', 'scout-booking' ) . "\n\n"
			. self::facts( $row ) . "\n"
			/* translators: %s: the old date and time. */
			. sprintf( __( 'It was %s.', 'scout-booking' ), $was ) . "\n\n"
			. self::links( $row ) . self::policy_line() . self::sign_off();
		$files = array();
		$ics   = self::ics_file( $row );
		if ( '' !== $ics ) {
			$files[] = $ics;
		}
		/* translators: %s: session name. */
		self::send( $row['email'], sprintf( __( 'New time: %s', 'scout-booking' ), $row['type_name'] ), $body, $files );
		self::cleanup( $files );
		/* translators: %s: the old date and time. */
		$owner = __( 'A client moved their session.', 'scout-booking' ) . "\n\n" . self::facts( $row ) . "\n" . sprintf( __( 'It was %s.', 'scout-booking' ), $was ) . "\n\n" . self::client_block( $row );
		/* translators: 1: client name, 2: session name. */
		self::send( Scout_Booking::notify_email(), sprintf( __( 'Moved: %1$s, %2$s', 'scout-booking' ), Scout_Booking::client_name( $row ), $row['type_name'] ), $owner );
	}

	/**
	 * @param string $by   'client' or 'owner'.
	 * @param bool   $late Cancelled inside the cancellation window.
	 */
	public static function cancelled( array $row, string $by, bool $late = false ): void {
		$body = sprintf( /* translators: %s: first name. */ __( 'Hi %s,', 'scout-booking' ), $row['first_name'] ) . "\n\n"
			. ( 'client' === $by ? __( 'Your session is cancelled, as you asked.', 'scout-booking' ) : __( 'Your session has been cancelled. We are sorry for the change, and you are welcome to reply to this email.', 'scout-booking' ) ) . "\n\n"
			. self::facts( $row ) . "\n";
		if ( $row['paid'] > 0 ) {
			$body .= ( $late
				? __( 'This was inside the cancellation window, so the deposit is kept as the policy says.', 'scout-booking' )
				: __( 'If a refund is due under the policy, it goes back to the way you paid.', 'scout-booking' ) ) . "\n";
		}
		$body .= self::sign_off();
		/* translators: %s: session name. */
		self::send( $row['email'], sprintf( __( 'Cancelled: %s', 'scout-booking' ), $row['type_name'] ), $body );
		if ( 'client' === $by ) {
			$owner = __( 'A client cancelled their session.', 'scout-booking' ) . "\n\n" . self::facts( $row ) . "\n" . self::owner_money( $row )
				. ( $late ? __( 'They cancelled inside the cancellation window.', 'scout-booking' ) . "\n" : '' )
				. ( $row['paid'] > 0 && ! $late ? __( 'They paid online. Refund it from the order in WooCommerce if the policy calls for it.', 'scout-booking' ) . "\n" : '' )
				. "\n" . self::client_block( $row );
			/* translators: 1: client name, 2: session name. */
			self::send( Scout_Booking::notify_email(), sprintf( __( 'Cancelled: %1$s, %2$s', 'scout-booking' ), Scout_Booking::client_name( $row ), $row['type_name'] ), $owner );
		}
	}

	/* ---------- pieces ---------- */

	private static function links( array $row ): string {
		return __( 'Add it to your calendar:', 'scout-booking' ) . "\n" . Scout_Booking_Front::ics_url( $row ) . "\n"
			. __( 'Google Calendar:', 'scout-booking' ) . "\n" . self::google_url( $row ) . "\n\n"
			. __( 'Change the time or cancel:', 'scout-booking' ) . "\n" . Scout_Booking_Front::manage_url( $row ) . "\n";
	}

	private static function client_block( array $row ): string {
		$out = __( 'Client', 'scout-booking' ) . ': ' . Scout_Booking::client_name( $row ) . "\n"
			. __( 'Email', 'scout-booking' ) . ': ' . $row['email'] . "\n"
			. ( '' !== $row['phone'] ? __( 'Phone', 'scout-booking' ) . ': ' . $row['phone'] . "\n" : '' );
		foreach ( $row['answers'] as $a ) {
			$out .= "\n" . (string) ( $a['q'] ?? '' ) . "\n" . ( '' !== (string) ( $a['a'] ?? '' ) ? (string) $a['a'] : '-' ) . "\n";
		}
		return $out;
	}

	private static function owner_money( array $row ): string {
		if ( $row['paid'] > 0 ) {
			$codes = Scout_Booking::payments_on() ? Scout_Booking_Woo::coupons( $row['order_id'] ) : '';
			$line  = $row['order_id']
				/* translators: 1: amount paid, 2: session price, 3: order number. */
				? sprintf( __( 'Paid online: %1$s of %2$s (order #%3$d).', 'scout-booking' ), Scout_Booking::money( $row['paid'] ), Scout_Booking::money( $row['price'] ), $row['order_id'] )
				/* translators: 1: amount paid, 2: session price. */
				: sprintf( __( 'Paid online: %1$s of %2$s.', 'scout-booking' ), Scout_Booking::money( $row['paid'] ), Scout_Booking::money( $row['price'] ) );
			return $line
				/* translators: %s: discount codes. */
				. ( '' !== $codes ? ' ' . sprintf( __( 'Discount code: %s.', 'scout-booking' ), $codes ) : '' ) . "\n";
		}
		if ( $row['price'] > 0 ) {
			/* translators: %s: session price. */
			return sprintf( __( 'Nothing paid online. Session price: %s.', 'scout-booking' ), Scout_Booking::money( $row['price'] ) ) . "\n";
		}
		return '';
	}

	/** A Google Calendar "add event" link. */
	public static function google_url( array $row ): string {
		$f = 'Ymd\THis\Z';
		return add_query_arg(
			array(
				'action'  => 'TEMPLATE',
				'text'    => rawurlencode( $row['type_name'] . ' · ' . self::site() ),
				'dates'   => gmdate( $f, Scout_Booking::at( $row['start_at'] )->getTimestamp() ) . '/' . gmdate( $f, Scout_Booking::at( $row['end_at'] )->getTimestamp() ),
				'details' => rawurlencode( __( 'Change the time or cancel:', 'scout-booking' ) . ' ' . Scout_Booking_Front::manage_url( $row ) ),
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	/* ---------- calendar files ---------- */

	private static function esc_ics( string $text ): string {
		return str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), $text );
	}

	/** Fold lines at 75 octets, as the calendar format asks. */
	private static function fold( string $line ): string {
		$out = '';
		while ( strlen( $line ) > 75 ) {
			$cut = 75;
			// Never split a multi-byte character.
			while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
				--$cut;
			}
			$out .= substr( $line, 0, $cut ) . "\r\n ";
			$line = substr( $line, $cut );
		}
		return $out . $line;
	}

	/** One VEVENT for a row. */
	private static function vevent( array $row, bool $owner ): array {
		$f    = 'Ymd\THis\Z';
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( 'blocked' === $row['status'] ) {
			$summary = __( 'Blocked', 'scout-booking' ) . ( '' !== (string) $row['note'] ? ': ' . $row['note'] : '' );
			$desc    = '';
		} elseif ( $owner ) {
			$summary = ( in_array( $row['status'], array( 'requested', 'approved', 'pending' ), true ) ? __( 'Held', 'scout-booking' ) . ': ' : '' ) . $row['type_name'] . ' · ' . Scout_Booking::client_name( $row );
			$desc    = ( Scout_Booking::statuses()[ $row['status'] ] ?? $row['status'] ) . "\n"
				. Scout_Booking::client_name( $row ) . "\n" . $row['email'] . "\n" . $row['phone'] . "\n"
				. trim( self::owner_money( $row ) ) . "\n" . Scout_Booking_Admin::url( array( 'date' => substr( $row['start_at'], 0, 10 ) ) );
		} else {
			$summary = $row['type_name'] . ' · ' . self::site();
			$desc    = __( 'Change the time or cancel:', 'scout-booking' ) . ' ' . Scout_Booking_Front::manage_url( $row );
		}
		$lines = array(
			'BEGIN:VEVENT',
			'UID:booking-' . $row['token'] . '@' . $host,
			'DTSTAMP:' . gmdate( $f ),
			'DTSTART:' . gmdate( $f, Scout_Booking::at( $row['start_at'] )->getTimestamp() ),
			'DTEND:' . gmdate( $f, Scout_Booking::at( $row['end_at'] )->getTimestamp() ),
			'SUMMARY:' . self::esc_ics( $summary ),
		);
		if ( '' !== $desc ) {
			$lines[] = 'DESCRIPTION:' . self::esc_ics( $desc );
		}
		if ( 'cancelled' === $row['status'] ) {
			$lines[] = 'STATUS:CANCELLED';
		} elseif ( in_array( $row['status'], array( 'requested', 'approved', 'pending' ), true ) ) {
			$lines[] = 'STATUS:TENTATIVE';
		} else {
			$lines[] = 'STATUS:CONFIRMED';
		}
		$lines[] = 'END:VEVENT';
		return $lines;
	}

	/**
	 * A calendar file.
	 *
	 * @param array[] $rows
	 */
	public static function ics( array $rows, string $name, bool $owner = false ): string {
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Scout Media//Scout Booking ' . SCOUT_BOOKING_VERSION . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::esc_ics( $name ),
		);
		if ( $owner ) {
			$lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
			$lines[] = 'X-PUBLISHED-TTL:PT1H';
		}
		foreach ( $rows as $row ) {
			$lines = array_merge( $lines, self::vevent( $row, $owner ) );
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( array( __CLASS__, 'fold' ), $lines ) ) . "\r\n";
	}

	/** Remove temporary attachments and their folders. */
	private static function cleanup( array $files ): void {
		foreach ( $files as $f ) {
			wp_delete_file( $f );
			@rmdir( dirname( $f ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- an empty temporary folder.
		}
	}

	/** The client's appointment as a temporary .ics file to attach. Empty on failure. */
	public static function ics_file( array $row ): string {
		$dir = trailingslashit( get_temp_dir() ) . 'scout-booking-' . wp_generate_password( 8, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$file = $dir . '/appointment.ics';
		$ok   = file_put_contents( $file, self::ics( array( $row ), $row['type_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary attachment.
		return false === $ok ? '' : $file;
	}
}
