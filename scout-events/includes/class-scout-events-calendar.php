<?php
/**
 * Add to calendar: a Google Calendar link and an .ics file (Apple Calendar,
 * Outlook and most others) for every upcoming event, under its details. The
 * same file rides along with each sign-up's ticket email.
 *
 *   /event/name/?se_ics=1   the event as an .ics file
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Calendar {

	public static function boot(): void {
		add_action( 'template_redirect', array( __CLASS__, 'download' ) );
		add_filter( 'scout_events_ticket_html', array( __CLASS__, 'links' ), 50, 3 );
	}

	/**
	 * Start and end as UTC stamps for a calendar, or dates when the event has
	 * no start time (an all-day entry).
	 *
	 * @return array{all_day:bool,start:string,end:string}|null
	 */
	private static function span( array $e ): ?array {
		$start = Scout_Events::start( $e );
		if ( ! $start ) {
			return null;
		}
		if ( '' === $e['start_time'] ) {
			$last = '' !== $e['end_date'] ? $e['end_date'] : $e['start_date'];
			return array(
				'all_day' => true,
				'start'   => str_replace( '-', '', $e['start_date'] ),
				// All-day ends are exclusive: the day after the last day.
				'end'     => gmdate( 'Ymd', strtotime( $last . ' +1 day' ) ),
			);
		}
		$end = Scout_Events::end( $e );
		$end = $end && $end > $start ? $end : $start->modify( '+2 hours' );
		$utc = new DateTimeZone( 'UTC' );
		return array(
			'all_day' => false,
			'start'   => $start->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
			'end'     => $end->setTimezone( $utc )->format( 'Ymd\THis\Z' ),
		);
	}

	private static function where( array $e ): string {
		if ( 'online' === $e['mode'] ) {
			return $e['online_url'];
		}
		return trim( implode( ', ', array_filter( array( $e['venue'], Scout_Events::address_label( $e ) ) ) ) );
	}

	public static function google_url( int $id ): string {
		$e    = Scout_Events::event( $id );
		$span = self::span( $e );
		if ( ! $span ) {
			return '';
		}
		return add_query_arg(
			array_map(
				'rawurlencode',
				array(
					'action'   => 'TEMPLATE',
					'text'     => wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES ),
					'dates'    => $span['start'] . '/' . $span['end'],
					'details'  => (string) get_permalink( $id ),
					'location' => self::where( $e ),
				)
			),
			'https://calendar.google.com/calendar/render'
		);
	}

	public static function ics_url( int $id ): string {
		return add_query_arg( 'se_ics', 1, (string) get_permalink( $id ) );
	}

	/** The event as iCalendar text. */
	public static function ics( int $id ): string {
		$e    = Scout_Events::event( $id );
		$span = self::span( $e );
		if ( ! $span ) {
			return '';
		}
		$esc   = static fn( string $t ): string => str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), $t );
		$about = has_excerpt( $id ) ? get_the_excerpt( $id ) : '';
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Scout Media//Scout Events//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:event-' . $id . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			( $span['all_day'] ? 'DTSTART;VALUE=DATE:' : 'DTSTART:' ) . $span['start'],
			( $span['all_day'] ? 'DTEND;VALUE=DATE:' : 'DTEND:' ) . $span['end'],
			'SUMMARY:' . $esc( wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES ) ),
			'URL:' . get_permalink( $id ),
			'DESCRIPTION:' . $esc( trim( wp_specialchars_decode( wp_strip_all_tags( $about ), ENT_QUOTES ) . "\n\n" . get_permalink( $id ) ) ),
		);
		if ( '' !== self::where( $e ) ) {
			$lines[] = 'LOCATION:' . $esc( self::where( $e ) );
		}
		if ( 'cancelled' === $e['status'] ) {
			$lines[] = 'STATUS:CANCELLED';
		}
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';
		// Lines longer than 75 octets fold onto the next with a leading space (RFC 5545).
		$out = '';
		foreach ( $lines as $line ) {
			while ( strlen( $line ) > 75 ) {
				$cut  = 75;
				while ( $cut > 1 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) {
					--$cut; // Never split a multi-byte character.
				}
				$out .= substr( $line, 0, $cut ) . "\r\n";
				$line = ' ' . substr( $line, $cut );
			}
			$out .= $line . "\r\n";
		}
		return $out;
	}

	/** A temporary .ics file for an email, or an empty string. */
	public static function ics_file( int $id ): string {
		$ics = self::ics( $id );
		if ( '' === $ics ) {
			return '';
		}
		$file = trailingslashit( get_temp_dir() ) . sanitize_file_name( (string) get_post_field( 'post_name', $id ) ) . '.ics';
		return false !== file_put_contents( $file, $ics ) ? $file : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temp file deleted after sending.
	}

	public static function download(): void {
		if ( ! isset( $_GET['se_ics'] ) || ! is_singular( Scout_Events::EVENT ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public file.
			return;
		}
		$id  = (int) get_queried_object_id();
		$ics = self::ics( $id );
		if ( '' === $ics ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) get_post_field( 'post_name', $id ) ) . '.ics"' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- calendar text, escaped for its own format above.
		exit;
	}

	/** "Add to calendar" under the ticket button, for upcoming events with a date. */
	public static function links( $html, $id, $e ): string {
		$id = (int) $id;
		$e  = (array) $e;
		if ( Scout_Events::is_past( $e ) || 'cancelled' === $e['status'] || ! Scout_Events::start( $e ) ) {
			return (string) $html;
		}
		return (string) $html . '<p class="se-addcal"><span>' . esc_html__( 'Add to calendar:', 'scout-events' ) . '</span> '
			. '<a href="' . esc_url( self::google_url( $id ) ) . '" rel="noopener" data-scout-track="calendar_add">' . esc_html__( 'Google', 'scout-events' ) . '</a>'
			. ' · <a href="' . esc_url( self::ics_url( $id ) ) . '" data-scout-track="calendar_add">' . esc_html__( 'Apple or Outlook', 'scout-events' ) . '</a></p>';
	}
}
