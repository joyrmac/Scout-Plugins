<?php
/**
 * Busy times from the owner's own calendar.
 *
 * The owner pastes the private address of up to three calendars (the iCal
 * address Google, Apple and Outlook all offer). A background job reads each
 * one every 15 minutes, keeps only when the owner is busy over the next 60
 * days, and the slot builder leaves those times off the booking page.
 * Visitors never wait for a calendar: the booking page only reads what the
 * last background read saved.
 *
 * Storage:
 *   scout_booking_busy_feeds  the addresses, not autoloaded. They are secrets:
 *                             never logged, never sent to the browser in full,
 *                             never exported.
 *   scout_booking_busy_state  per address (keyed by a hash, never the address):
 *                             last good read, last try, failing since, error
 *                             word, busy count. Not autoloaded.
 *   scout_booking_busy        transient: per address, a list of busy blocks as
 *                             [start, end] Unix seconds. Nothing else is kept:
 *                             no titles, people, places or notes.
 *
 * @package Scout_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Booking_Busy {

	const FEEDS     = 'scout_booking_busy_feeds';
	const STATE     = 'scout_booking_busy_state';
	const CACHE     = 'scout_booking_busy';
	const LOCK      = 'scout_booking_busy_lock';
	const HOOK      = 'scout_booking_busy_refresh';
	const SCHEDULE  = 'scout_booking_15min';
	const MAX       = 3;
	const EVERY     = 900;
	const DAYS      = 60;
	const TIMEOUT   = 8;
	const MAX_BYTES = 8388608;
	const MAX_BLOCK = 2000;
	const LATE      = 86400;

	/** Common Windows zone names Outlook puts in TZID, mapped to real zones. */
	const WINDOWS_ZONES = array(
		'eastern standard time'          => 'America/New_York',
		'us eastern standard time'       => 'America/Indianapolis',
		'central standard time'          => 'America/Chicago',
		'mountain standard time'         => 'America/Denver',
		'us mountain standard time'      => 'America/Phoenix',
		'pacific standard time'          => 'America/Los_Angeles',
		'alaskan standard time'          => 'America/Anchorage',
		'hawaiian standard time'         => 'Pacific/Honolulu',
		'atlantic standard time'         => 'America/Halifax',
		'gmt standard time'              => 'Europe/London',
		'greenwich standard time'        => 'Atlantic/Reykjavik',
		'w. europe standard time'        => 'Europe/Berlin',
		'romance standard time'          => 'Europe/Paris',
		'central europe standard time'   => 'Europe/Budapest',
		'central european standard time' => 'Europe/Warsaw',
		'india standard time'            => 'Asia/Kolkata',
		'tokyo standard time'            => 'Asia/Tokyo',
		'aus eastern standard time'      => 'Australia/Sydney',
		'utc'                            => 'UTC',
	);

	/** Read once per request by the slot builder. */
	private static $memo = null;

	public static function boot(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'refresh' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_tests' ) );
	}

	/* ---------- the addresses ---------- */

	/**
	 * A calendar address made safe to store, or '' when it is not one we read.
	 * webcal:// (what Apple hands out) is the same address over https.
	 */
	public static function clean_url( string $url ): string {
		$url = trim( $url );
		if ( preg_match( '#^webcals?://#i', $url ) ) {
			$url = 'https://' . preg_replace( '#^webcals?://#i', '', $url );
		}
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\s<>"\'\\\\]/', $url ) ) {
			return '';
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- also runs in tests without WordPress.
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === (string) ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		return $url;
	}

	/** What the owner sees of a saved address: the host and its last 6 characters. */
	public static function mask( string $url ): string {
		$host = (string) parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- also runs in tests without WordPress.
		return strtolower( $host ) . ' … ' . substr( $url, -6 );
	}

	/** A short key for an address, so state never holds the address itself. */
	public static function key( string $url ): string {
		return substr( hash( 'sha256', $url ), 0, 16 );
	}

	/** @return string[] the saved addresses, at most three */
	public static function feeds(): array {
		$out = array();
		foreach ( (array) get_option( self::FEEDS, array() ) as $url ) {
			$url = self::clean_url( (string) $url );
			if ( '' !== $url && ! in_array( $url, $out, true ) ) {
				$out[] = $url;
			}
		}
		return array_slice( $out, 0, self::MAX );
	}

	/** Save the addresses (not autoloaded) and read the new ones now, so the status is right away. */
	public static function save_feeds( array $feeds ): void {
		$old   = array_map( array( __CLASS__, 'key' ), self::feeds() );
		$clean = array();
		foreach ( $feeds as $url ) {
			$url = self::clean_url( (string) $url );
			if ( '' !== $url && ! in_array( $url, $clean, true ) ) {
				$clean[] = $url;
			}
		}
		$clean = array_slice( $clean, 0, self::MAX );
		if ( $clean ) {
			if ( false === get_option( self::FEEDS, false ) ) {
				add_option( self::FEEDS, $clean, '', false );
			} else {
				update_option( self::FEEDS, $clean, false );
			}
		} else {
			delete_option( self::FEEDS );
		}
		self::$memo = null;
		self::refresh( array_values( array_diff( array_map( array( __CLASS__, 'key' ), $clean ), $old ) ) );
		self::schedule();
	}

	/* ---------- the background read ---------- */

	public static function schedules( $schedules ): array {
		$schedules                   = (array) $schedules;
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::EVERY,
			'display'  => __( 'Every 15 minutes (Scout Booking calendar)', 'scout-booking' ),
		);
		return $schedules;
	}

	/** Read every 15 minutes while there is an address, and not at all without one. */
	public static function schedule(): void {
		$next = wp_next_scheduled( self::HOOK );
		if ( self::feeds() ) {
			if ( ! $next ) {
				wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
			}
		} elseif ( $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Read each address that is due (every 15 minutes). An address just added
	 * or replaced is read now: pass its key in $force (true reads them all).
	 * A failed read keeps the busy times from the last good one.
	 *
	 * @param bool|string[] $force
	 */
	public static function refresh( $force = false ): void {
		$feeds = self::feeds();
		if ( ! $feeds ) {
			delete_transient( self::CACHE );
			delete_option( self::STATE );
			self::$memo = null;
			return;
		}
		if ( ! $force && get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 60 );
		$cache = get_transient( self::CACHE );
		$cache = is_array( $cache ) ? $cache : array();
		$state = (array) get_option( self::STATE, array() );
		$now   = Scout_Booking::now()->getTimestamp();
		$to    = $now + self::DAYS * 86400;
		$tz    = wp_timezone();
		$skip  = self::own_uid();
		$keep  = array();
		$notes = array();
		foreach ( $feeds as $url ) {
			$k  = self::key( $url );
			$st = self::state_row( $state[ $k ] ?? array() );
			$bl = array_values( array_filter( (array) ( $cache[ $k ] ?? array() ), static fn( $b ) => is_array( $b ) && (int) ( $b[1] ?? 0 ) > $now ) );
			// A few seconds of slack, so the 15-minute job is never skipped for being early.
			if ( true === $force || ( is_array( $force ) && in_array( $k, $force, true ) ) || $st['try_at'] <= $now - self::EVERY + 60 ) {
				$st['try_at'] = $now;
				$got          = self::fetch( $url );
				if ( $got['ok'] ) {
					$bl               = self::parse( $got['body'], $tz, $now, $to, $skip );
					$st['ok_at']      = $now;
					$st['fail_since'] = 0;
					$st['error']      = '';
				} else {
					$st['error']      = $got['error'];
					$st['fail_since'] = $st['fail_since'] ? $st['fail_since'] : $now;
				}
			}
			$st['count'] = count( $bl );
			$keep[ $k ]  = $bl;
			$notes[ $k ] = $st;
		}
		set_transient( self::CACHE, $keep, 2 * 86400 );
		update_option( self::STATE, $notes, false );
		delete_transient( self::LOCK );
		self::$memo = null;
	}

	/** One address's notes with every field present. */
	private static function state_row( $row ): array {
		$row = (array) $row;
		return array(
			'ok_at'      => (int) ( $row['ok_at'] ?? 0 ),
			'try_at'     => (int) ( $row['try_at'] ?? 0 ),
			'fail_since' => (int) ( $row['fail_since'] ?? 0 ),
			'error'      => preg_replace( '/[^a-z]/', '', (string) ( $row['error'] ?? '' ) ),
			'count'      => (int) ( $row['count'] ?? 0 ),
		);
	}

	/** Our own bookings, if the owner copied one into their calendar, are already counted. */
	private static function own_uid(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return '' !== $host ? '/^booking-[a-z0-9]+@' . preg_quote( $host, '/' ) . '$/i' : '';
	}

	/**
	 * Get one calendar over https. Redirects are followed by hand, at most
	 * three, and only to another https address.
	 *
	 * @return array{ok:bool, body?:string, error?:string}
	 */
	private static function fetch( string $url ): array {
		for ( $hop = 0; $hop < 4; $hop++ ) {
			$r = wp_remote_get(
				$url,
				array(
					'timeout'             => self::TIMEOUT,
					'redirection'         => 0,
					'reject_unsafe_urls'  => true,
					'limit_response_size' => self::MAX_BYTES,
					'headers'             => array( 'Accept' => 'text/calendar, text/plain;q=0.5' ),
				)
			);
			if ( is_wp_error( $r ) ) {
				return array(
					'ok'    => false,
					'error' => 'network',
				);
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$url = self::clean_url( (string) wp_remote_retrieve_header( $r, 'location' ) );
				if ( '' === $url ) {
					break;
				}
				continue;
			}
			if ( 200 !== $code ) {
				return array(
					'ok'    => false,
					'error' => in_array( $code, array( 401, 403, 404, 410 ), true ) ? 'gone' : 'http',
				);
			}
			$body = (string) wp_remote_retrieve_body( $r );
			if ( strlen( $body ) >= self::MAX_BYTES ) {
				return array(
					'ok'    => false,
					'error' => 'big',
				);
			}
			if ( ! self::is_calendar( $body ) ) {
				return array(
					'ok'    => false,
					'error' => 'notcal',
				);
			}
			return array(
				'ok'   => true,
				'body' => $body,
			);
		}
		return array(
			'ok'    => false,
			'error' => 'http',
		);
	}

	/** True when the text is a calendar file and not a web page. */
	public static function is_calendar( string $body ): bool {
		$body = ltrim( preg_replace( '/^\xEF\xBB\xBF/', '', $body ) );
		return 0 === stripos( $body, 'BEGIN:VCALENDAR' ) && false !== stripos( $body, 'END:VCALENDAR' );
	}

	/* ---------- reading the calendar file ---------- */

	/**
	 * Busy blocks from a calendar file, between two moments.
	 *
	 * Reads only DTSTART, DTEND, DURATION, TRANSP, STATUS, RRULE, EXDATE,
	 * RECURRENCE-ID and UID. Titles, people, places and notes are never read
	 * into anything that is kept.
	 *
	 * @param DateTimeZone $tz       The site's timezone, for all-day and floating times.
	 * @param int          $from     Unix seconds; blocks ending before this are dropped.
	 * @param int          $to       Unix seconds; blocks starting after this are dropped.
	 * @param string       $skip_uid A pattern for UIDs to leave out (our own bookings).
	 * @return array<int,array{0:int,1:int}> merged, soonest first
	 */
	public static function parse( string $ics, DateTimeZone $tz, int $from, int $to, string $skip_uid = '' ): array {
		$keep   = array( 'DTSTART', 'DTEND', 'DURATION', 'TRANSP', 'STATUS', 'RRULE', 'RECURRENCE-ID', 'UID' );
		$events = array();
		$stack  = array();
		$ev     = null;
		foreach ( self::unfold( $ics ) as $line ) {
			$p = self::prop( $line );
			if ( ! $p ) {
				continue;
			}
			list( $name, $params, $value ) = $p;
			if ( 'BEGIN' === $name ) {
				$stack[] = strtoupper( trim( $value ) );
				if ( 'VEVENT' === end( $stack ) ) {
					$ev = array( 'EXDATE' => array() );
				}
				continue;
			}
			if ( 'END' === $name ) {
				if ( 'VEVENT' === strtoupper( trim( $value ) ) && null !== $ev ) {
					$events[] = $ev;
					$ev       = null;
				}
				array_pop( $stack );
				continue;
			}
			if ( null === $ev || 'VEVENT' !== end( $stack ) ) {
				continue;
			}
			if ( 'EXDATE' === $name ) {
				$ev['EXDATE'][] = array( $params, $value );
			} elseif ( in_array( $name, $keep, true ) && ! isset( $ev[ $name ] ) ) {
				$ev[ $name ] = array( $params, $value );
			}
		}

		// Moved or changed single times of a series replace the series' own time.
		$moved = array();
		foreach ( $events as $e ) {
			if ( isset( $e['RECURRENCE-ID'], $e['UID'] ) ) {
				$rid = self::moment( $e['RECURRENCE-ID'], $tz );
				if ( $rid ) {
					$moved[ trim( $e['UID'][1] ) ][ self::occ_key( $rid['dt'], $rid['date'] ) ] = true;
				}
			}
		}

		$out = array();
		foreach ( $events as $e ) {
			$uid = isset( $e['UID'] ) ? trim( $e['UID'][1] ) : '';
			if ( '' !== $skip_uid && '' !== $uid && preg_match( $skip_uid, $uid ) ) {
				continue;
			}
			if ( 'CANCELLED' === strtoupper( trim( $e['STATUS'][1] ?? '' ) ) || 'TRANSPARENT' === strtoupper( trim( $e['TRANSP'][1] ?? '' ) ) ) {
				continue;
			}
			$start = isset( $e['DTSTART'] ) ? self::moment( $e['DTSTART'], $tz ) : null;
			if ( ! $start ) {
				continue;
			}
			$allday  = $start['date'];
			$days    = 0;
			$seconds = 0;
			$end     = isset( $e['DTEND'] ) ? self::moment( $e['DTEND'], $tz, $start['dt']->getTimezone() ) : null;
			if ( $end ) {
				if ( $allday ) {
					$days = self::day_count( $start['dt'], $end['dt'] );
				} else {
					$seconds = $end['dt']->getTimestamp() - $start['dt']->getTimestamp();
				}
			} elseif ( isset( $e['DURATION'] ) ) {
				$seconds = self::duration( $e['DURATION'][1] );
				$days    = $allday ? intdiv( max( 0, $seconds ), 86400 ) : 0;
			} elseif ( $allday ) {
				$days = 1;
			}
			if ( $allday ? $days <= 0 : $seconds <= 0 ) {
				continue;
			}
			$length = $allday ? $days * 86400 + 7200 : $seconds;
			$single = isset( $e['RECURRENCE-ID'] ) || ! isset( $e['RRULE'] );
			$times  = $single
				? array( $start['dt'] )
				: self::expand( $start['dt'], $e['RRULE'][1], self::exdates( $e['EXDATE'], $tz, $start['dt']->getTimezone() ), $allday, $from - $length, $to );
			foreach ( $times as $t ) {
				if ( ! $single && '' !== $uid && isset( $moved[ $uid ][ self::occ_key( $t, $allday ) ] ) ) {
					continue;
				}
				$s = $t->getTimestamp();
				$f = $allday ? $t->modify( '+' . $days . ' days' )->getTimestamp() : $s + $seconds;
				if ( $f > $from && $s < $to ) {
					$out[] = array( $s, $f );
				}
			}
		}
		return self::merge( $out );
	}

	/** Join overlapping and touching blocks, soonest first, at most MAX_BLOCK. */
	public static function merge( array $blocks ): array {
		usort( $blocks, static fn( $a, $b ) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] );
		$out = array();
		foreach ( $blocks as $b ) {
			$n = count( $out );
			if ( $n && $b[0] <= $out[ $n - 1 ][1] ) {
				$out[ $n - 1 ][1] = max( $out[ $n - 1 ][1], $b[1] );
			} else {
				$out[] = array( (int) $b[0], (int) $b[1] );
			}
		}
		return array_slice( $out, 0, self::MAX_BLOCK );
	}

	/** Lines with folding undone (a line starting with a space or tab continues the one before). */
	private static function unfold( string $ics ): array {
		$ics = str_replace( array( "\r\n", "\r" ), "\n", $ics );
		$ics = preg_replace( "/\n[ \t]/", '', $ics );
		return explode( "\n", (string) $ics );
	}

	/**
	 * One content line as [NAME, params, value]. Params are upper-case keys
	 * with quotes taken off.
	 */
	private static function prop( string $line ): ?array {
		$line = trim( $line );
		if ( '' === $line ) {
			return null;
		}
		$len   = strlen( $line );
		$quote = false;
		$colon = -1;
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '"' === $line[ $i ] ) {
				$quote = ! $quote;
			} elseif ( ':' === $line[ $i ] && ! $quote ) {
				$colon = $i;
				break;
			}
		}
		if ( $colon < 1 ) {
			return null;
		}
		$head   = substr( $line, 0, $colon );
		$value  = substr( $line, $colon + 1 );
		$bits   = preg_split( '/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $head );
		$name   = strtoupper( trim( (string) array_shift( $bits ) ) );
		$params = array();
		foreach ( $bits as $b ) {
			$eq = strpos( $b, '=' );
			if ( false !== $eq ) {
				$params[ strtoupper( trim( substr( $b, 0, $eq ) ) ) ] = trim( substr( $b, $eq + 1 ), " \"" );
			}
		}
		return array( $name, $params, $value );
	}

	/** A timezone from a TZID, falling back to the site's. */
	public static function zone( string $tzid, DateTimeZone $fallback ): DateTimeZone {
		$tzid = trim( $tzid, " \"" );
		if ( '' === $tzid ) {
			return $fallback;
		}
		$tries = array( $tzid );
		$lower = strtolower( $tzid );
		if ( isset( self::WINDOWS_ZONES[ $lower ] ) ) {
			array_unshift( $tries, self::WINDOWS_ZONES[ $lower ] );
		}
		// "/mozilla.org/20050126_1/America/New_York" and friends.
		if ( preg_match( '#([A-Za-z_]+/[A-Za-z_\-]+(?:/[A-Za-z_\-]+)?)$#', $tzid, $m ) ) {
			$tries[] = $m[1];
		}
		foreach ( $tries as $name ) {
			try {
				return new DateTimeZone( $name );
			} catch ( Exception $e ) {
				continue;
			}
		}
		return $fallback;
	}

	/**
	 * A date or date-time value.
	 *
	 * @param array             $prop  [params, value].
	 * @param DateTimeZone      $tz    The site's timezone (all-day and floating times).
	 * @param DateTimeZone|null $local The event's own zone, for a floating DTEND.
	 * @return array{dt:DateTimeImmutable, date:bool}|null
	 */
	private static function moment( array $prop, DateTimeZone $tz, ?DateTimeZone $local = null ): ?array {
		list( $params, $value ) = $prop;
		$value = strtoupper( trim( (string) $value ) );
		$value = explode( ',', $value )[0];
		if ( 'DATE' === strtoupper( (string) ( $params['VALUE'] ?? '' ) ) || preg_match( '/^\d{8}$/', $value ) ) {
			$d = DateTimeImmutable::createFromFormat( '!Ymd', substr( $value, 0, 8 ), $tz );
			return $d ? array(
				'dt'   => $d,
				'date' => true,
			) : null;
		}
		if ( ! preg_match( '/^(\d{8}T\d{4})(\d{2})?(Z)?$/', $value, $m ) ) {
			return null;
		}
		$text = $m[1] . ( '' !== ( $m[2] ?? '' ) ? $m[2] : '00' );
		if ( ! empty( $m[3] ) ) {
			$zone = new DateTimeZone( 'UTC' );
		} elseif ( isset( $params['TZID'] ) ) {
			$zone = self::zone( (string) $params['TZID'], $tz );
		} else {
			$zone = $local ?? $tz;
		}
		$d = DateTimeImmutable::createFromFormat( '!Ymd\THis', $text, $zone );
		return $d ? array(
			'dt'   => $d,
			'date' => false,
		) : null;
	}

	/** Whole days from one date to another, counted on the calendar (daylight saving never shortens a day). */
	private static function day_count( DateTimeImmutable $a, DateTimeImmutable $b ): int {
		$utc = new DateTimeZone( 'UTC' );
		$x   = DateTimeImmutable::createFromFormat( '!Ymd', $a->format( 'Ymd' ), $utc );
		$y   = DateTimeImmutable::createFromFormat( '!Ymd', $b->format( 'Ymd' ), $utc );
		return (int) round( ( $y->getTimestamp() - $x->getTimestamp() ) / 86400 );
	}

	/** Seconds in an iCal DURATION such as PT1H30M or P1D. */
	public static function duration( string $value ): int {
		if ( ! preg_match( '/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', strtoupper( trim( $value ) ), $m ) ) {
			return 0;
		}
		$s = ( (int) ( $m[2] ?? 0 ) ) * 604800 + ( (int) ( $m[3] ?? 0 ) ) * 86400 + ( (int) ( $m[4] ?? 0 ) ) * 3600 + ( (int) ( $m[5] ?? 0 ) ) * 60 + (int) ( $m[6] ?? 0 );
		return '-' === ( $m[1] ?? '' ) ? -$s : $s;
	}

	/** The key one time of a series is matched by: the moment, or the day for all-day events. */
	private static function occ_key( DateTimeImmutable $t, bool $allday ): string {
		return $allday ? 'd' . $t->format( 'Ymd' ) : 't' . $t->getTimestamp();
	}

	/** @return array<string,bool> EXDATE keys, both by moment and by day */
	private static function exdates( array $list, DateTimeZone $tz, DateTimeZone $local ): array {
		$out = array();
		foreach ( $list as $prop ) {
			foreach ( explode( ',', (string) $prop[1] ) as $one ) {
				$m = self::moment( array( $prop[0], $one ), $tz, $local );
				if ( $m ) {
					$day                                     = 'd' . ( $m['date'] ? $m['dt']->format( 'Ymd' ) : $m['dt']->setTimezone( $local )->format( 'Ymd' ) );
					$out[ self::occ_key( $m['dt'], false ) ] = true;
					$out[ $day ]                             = ! empty( $out[ $day ] ) || $m['date'];
				}
			}
		}
		return $out;
	}

	/**
	 * The start of each time a repeating event happens, from $min to $to.
	 * Handles DAILY, WEEKLY (with BYDAY), MONTHLY (by day of the month, or
	 * BYDAY such as 2TU or -1FR) and simple YEARLY, with INTERVAL, COUNT,
	 * UNTIL and EXDATE. Anything else counts as the first time only.
	 *
	 * @param array<string,bool> $ex EXDATE keys.
	 * @return DateTimeImmutable[]
	 */
	public static function expand( DateTimeImmutable $start, string $rrule, array $ex, bool $allday, int $min, int $to ): array {
		$rule = array();
		foreach ( explode( ';', strtoupper( trim( $rrule ) ) ) as $part ) {
			$eq = strpos( $part, '=' );
			if ( false !== $eq ) {
				$rule[ substr( $part, 0, $eq ) ] = substr( $part, $eq + 1 );
			}
		}
		$freq = $rule['FREQ'] ?? '';
		if ( ! in_array( $freq, array( 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ), true ) ) {
			return array( $start );
		}
		$every = max( 1, (int) ( $rule['INTERVAL'] ?? 1 ) );
		$count = isset( $rule['COUNT'] ) ? max( 1, (int) $rule['COUNT'] ) : 0;
		$until = PHP_INT_MAX;
		if ( isset( $rule['UNTIL'] ) ) {
			$u = self::moment( array( array(), $rule['UNTIL'] ), $start->getTimezone(), $start->getTimezone() );
			if ( $u ) {
				$until = $u['date'] ? $u['dt']->setTime( 23, 59, 59 )->getTimestamp() : $u['dt']->getTimestamp();
			}
		}
		$wd     = array( 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7 );
		$byday  = array();
		foreach ( array_filter( explode( ',', $rule['BYDAY'] ?? '' ) ) as $d ) {
			if ( preg_match( '/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', trim( $d ), $m ) ) {
				$byday[] = array( '' !== $m[1] ? (int) $m[1] : 0, $wd[ $m[2] ] );
			}
		}
		$bymday = array_values( array_filter( array_map( 'intval', explode( ',', $rule['BYMONTHDAY'] ?? '' ) ) ) );
		$wkst   = $wd[ $rule['WKST'] ?? 'MO' ] ?? 1;
		$h      = (int) $start->format( 'G' );
		$i      = (int) $start->format( 'i' );
		$s      = (int) $start->format( 's' );
		$first  = $start->getTimestamp();
		$day0   = $start->setTime( 0, 0 );

		// Without COUNT, skip whole periods that end before the window.
		$skip = 0;
		if ( ! $count && $min > $first ) {
			$gap = $min - $first;
			if ( 'DAILY' === $freq ) {
				$skip = intdiv( $gap, $every * 86400 );
			} elseif ( 'WEEKLY' === $freq ) {
				$skip = intdiv( $gap, $every * 604800 );
			} else {
				$minday = ( new DateTimeImmutable( '@' . $min ) )->setTimezone( $start->getTimezone() );
				$months = ( (int) $minday->format( 'Y' ) - (int) $start->format( 'Y' ) ) * 12 + (int) $minday->format( 'n' ) - (int) $start->format( 'n' );
				$skip   = 'MONTHLY' === $freq ? intdiv( max( 0, $months ), $every ) : intdiv( max( 0, intdiv( $months, 12 ) ), $every );
			}
			$skip = max( 0, $skip - 1 );
		}

		$out   = array();
		$made  = 0;
		$guard = 0;
		for ( $p = $skip; $guard < 5000; $p++, $guard++ ) {
			$n    = $p * $every;
			$cand = array();
			if ( 'DAILY' === $freq ) {
				$d = $day0->modify( '+' . $n . ' days' );
				if ( ! $byday || in_array( (int) $d->format( 'N' ), array_column( $byday, 1 ), true ) ) {
					$cand[] = $d;
				}
			} elseif ( 'WEEKLY' === $freq ) {
				$week = $day0->modify( '-' . ( ( (int) $day0->format( 'N' ) - $wkst + 7 ) % 7 ) . ' days' )->modify( '+' . ( $n * 7 ) . ' days' );
				$days = $byday ? array_column( $byday, 1 ) : array( (int) $day0->format( 'N' ) );
				$offs = array_unique( array_map( static fn( $x ) => ( $x - $wkst + 7 ) % 7, $days ) );
				sort( $offs );
				foreach ( $offs as $o ) {
					$cand[] = $week->modify( '+' . $o . ' days' );
				}
			} elseif ( 'MONTHLY' === $freq ) {
				$month = $day0->modify( 'first day of this month' )->modify( '+' . $n . ' months' );
				$last  = (int) $month->format( 't' );
				$found = array();
				if ( $byday ) {
					foreach ( $byday as $b ) {
						$all = array();
						for ( $dd = 1; $dd <= $last; $dd++ ) {
							if ( (int) $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $dd )->format( 'N' ) === $b[1] ) {
								$all[] = $dd;
							}
						}
						if ( 0 === $b[0] ) {
							$found = array_merge( $found, $all );
						} elseif ( $b[0] > 0 && isset( $all[ $b[0] - 1 ] ) ) {
							$found[] = $all[ $b[0] - 1 ];
						} elseif ( $b[0] < 0 && isset( $all[ count( $all ) + $b[0] ] ) ) {
							$found[] = $all[ count( $all ) + $b[0] ];
						}
					}
				} else {
					foreach ( $bymday ? $bymday : array( (int) $day0->format( 'j' ) ) as $md ) {
						$dd = $md > 0 ? $md : $last + 1 + $md;
						if ( $dd >= 1 && $dd <= $last ) {
							$found[] = $dd;
						}
					}
				}
				$found = array_unique( $found );
				sort( $found );
				foreach ( $found as $dd ) {
					$cand[] = $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $dd );
				}
			} else {
				$y = (int) $day0->format( 'Y' ) + $n;
				if ( checkdate( (int) $day0->format( 'n' ), (int) $day0->format( 'j' ), $y ) ) {
					$cand[] = $day0->setDate( $y, (int) $day0->format( 'n' ), (int) $day0->format( 'j' ) );
				}
			}
			foreach ( $cand as $c ) {
				$c  = $allday ? $c : $c->setTime( $h, $i, $s );
				$ts = $c->getTimestamp();
				if ( $ts < $first ) {
					continue;
				}
				if ( $ts > $until || ( $count && $made >= $count ) || $ts >= $to ) {
					return $out;
				}
				++$made;
				if ( isset( $ex[ self::occ_key( $c, false ) ] ) || ( $allday && isset( $ex[ 'd' . $c->format( 'Ymd' ) ] ) ) || ( ! $allday && ! empty( $ex[ 'd' . $c->format( 'Ymd' ) ] ) ) ) {
					continue;
				}
				if ( $ts >= $min ) {
					$out[] = $c;
				}
			}
		}
		return $out;
	}

	/* ---------- for the slot builder ---------- */

	/** @return array<int,array{0:int,1:int}> every saved busy block still ahead, merged */
	public static function blocks(): array {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$cache = self::feeds() ? get_transient( self::CACHE ) : array();
		$all   = array();
		$keys  = array_map( array( __CLASS__, 'key' ), self::feeds() );
		foreach ( is_array( $cache ) ? $cache : array() as $k => $list ) {
			if ( ! in_array( (string) $k, $keys, true ) ) {
				continue;
			}
			foreach ( (array) $list as $b ) {
				if ( is_array( $b ) && isset( $b[0], $b[1] ) && (int) $b[1] > (int) $b[0] ) {
					$all[] = array( (int) $b[0], (int) $b[1] );
				}
			}
		}
		self::$memo = self::merge( $all );
		return self::$memo;
	}

	/**
	 * Busy blocks overlapping a stretch of time, in the site's timezone.
	 *
	 * @return array<int,array{0:DateTimeImmutable,1:DateTimeImmutable}>
	 */
	public static function between( DateTimeImmutable $from, DateTimeImmutable $to ): array {
		$a   = $from->getTimestamp();
		$z   = $to->getTimestamp();
		$tz  = wp_timezone();
		$out = array();
		foreach ( self::blocks() as $b ) {
			if ( $b[1] > $a && $b[0] < $z ) {
				$out[] = array( ( new DateTimeImmutable( '@' . $b[0] ) )->setTimezone( $tz ), ( new DateTimeImmutable( '@' . $b[1] ) )->setTimezone( $tz ) );
			}
		}
		return $out;
	}

	/* ---------- status, for the owner ---------- */

	/** "4 minutes", "2 hours", "3 days". */
	public static function ago( int $seconds ): string {
		$seconds = max( 0, $seconds );
		if ( $seconds < 60 ) {
			return __( 'less than a minute', 'scout-booking' );
		}
		if ( $seconds < 3600 ) {
			$n = intdiv( $seconds, 60 );
			/* translators: %d: minutes. */
			return sprintf( _n( '%d minute', '%d minutes', $n, 'scout-booking' ), $n );
		}
		if ( $seconds < 86400 ) {
			$n = intdiv( $seconds, 3600 );
			/* translators: %d: hours. */
			return sprintf( _n( '%d hour', '%d hours', $n, 'scout-booking' ), $n );
		}
		$n = intdiv( $seconds, 86400 );
		/* translators: %d: days. */
		return sprintf( _n( '%d day', '%d days', $n, 'scout-booking' ), $n );
	}

	/** Why a read failed, in a few plain words. */
	public static function error_words( string $error ): string {
		$map = array(
			'network' => __( 'The calendar did not answer in time.', 'scout-booking' ),
			'gone'    => __( 'The calendar turned the address away. Copy the secret address again.', 'scout-booking' ),
			'http'    => __( 'The calendar sent back an error.', 'scout-booking' ),
			'notcal'  => __( 'That address is not a calendar file.', 'scout-booking' ),
			'big'     => __( 'That calendar is too big to read.', 'scout-booking' ),
		);
		return $map[ $error ] ?? '';
	}

	/** Notes for each saved address, in the order saved. @return array<int,array> */
	public static function rows(): array {
		$state = (array) get_option( self::STATE, array() );
		$out   = array();
		foreach ( self::feeds() as $n => $url ) {
			$out[ $n ] = array_merge( array( 'mask' => self::mask( $url ) ), self::state_row( $state[ self::key( $url ) ] ?? array() ) );
		}
		return $out;
	}

	/** "Read 4 minutes ago" or "Couldn't read since Nov 5, 2:10 pm", for one address. */
	public static function row_words( array $row, int $now ): string {
		if ( $row['fail_since'] ) {
			/* translators: %s: date and time. */
			return sprintf( __( 'Couldn’t read it since %s.', 'scout-booking' ), wp_date( 'M j, g:i a', $row['fail_since'] ) ) . ' ' . self::error_words( $row['error'] );
		}
		if ( $row['ok_at'] ) {
			/* translators: 1: time since, 2: number of busy times. */
			return sprintf( _n( 'Read %1$s ago, %2$d busy time.', 'Read %1$s ago, %2$d busy times.', $row['count'], 'scout-booking' ), self::ago( $now - $row['ok_at'] ), $row['count'] );
		}
		return __( 'Waiting for the first read.', 'scout-booking' );
	}

	/**
	 * The calendar's status line, or null when no address is saved.
	 *
	 * @return array{state:string, headline:string, detail:string, since:int}|null
	 */
	public static function summary(): ?array {
		$rows = self::rows();
		if ( ! $rows ) {
			return null;
		}
		$now     = Scout_Booking::now()->getTimestamp();
		$failing = array_filter( $rows, static fn( $r ) => $r['fail_since'] > 0 );
		if ( $failing ) {
			$since = min( array_column( $failing, 'fail_since' ) );
			$when  = wp_date( 'M j, g:i a', $since );
			return array(
				'state'    => 'warn',
				'headline' => 1 === count( $rows )
					/* translators: %s: date and time. */
					? sprintf( __( 'Couldn’t read your calendar since %s.', 'scout-booking' ), $when )
					/* translators: 1: number of calendars, 2: date and time. */
					: sprintf( _n( 'Couldn’t read %1$d of your calendars since %2$s.', 'Couldn’t read %1$d of your calendars since %2$s.', count( $failing ), 'scout-booking' ), count( $failing ), $when ),
				'detail'   => __( 'Bookings still open, but busy times may be missing.', 'scout-booking' ),
				'since'    => $since,
			);
		}
		$read = array_column( $rows, 'ok_at' );
		if ( in_array( 0, $read, true ) ) {
			return array(
				'state'    => 'off',
				'headline' => __( 'Calendar saved.', 'scout-booking' ),
				'detail'   => __( 'Waiting for the first read, which runs in the background.', 'scout-booking' ),
				'since'    => 0,
			);
		}
		$ahead = count( array_filter( self::blocks(), static fn( $b ) => $b[0] < $now + self::DAYS * 86400 ) );
		return array(
			'state'    => 'good',
			'headline' => 1 === count( $rows ) ? __( 'Calendar connected.', 'scout-booking' ) : __( 'Calendars connected.', 'scout-booking' ),
			/* translators: 1: time since, 2: number of busy times. */
			'detail'   => sprintf( _n( 'Last read %1$s ago, %2$d busy time in the next 60 days.', 'Last read %1$s ago, %2$d busy times in the next 60 days.', $ahead, 'scout-booking' ), self::ago( $now - min( $read ) ), $ahead ),
			'since'    => 0,
		);
	}

	/* ---------- Site status ---------- */

	/**
	 * A WordPress Site Health check while an address is saved. Scout Build runs
	 * WordPress's checks daily and lists any that want a look in Site status.
	 */
	public static function site_tests( $tests ): array {
		$tests = (array) $tests;
		if ( self::feeds() ) {
			$tests['direct']['scout_booking_busy'] = array(
				'label' => __( 'Busy times from your calendar', 'scout-booking' ),
				'test'  => array( __CLASS__, 'site_test' ),
			);
		}
		return $tests;
	}

	/** Recommended when a calendar has not been read for a day. */
	public static function site_test(): array {
		$s     = self::summary();
		$badge = array(
			'label' => __( 'Bookings', 'scout-booking' ),
			'color' => 'blue',
		);
		$link  = '<p><a href="' . esc_url( Scout_Booking_Admin::url( array(), 'sb-busy', 'settings' ) ) . '">' . esc_html__( 'Check the calendar address', 'scout-booking' ) . '</a></p>';
		if ( $s && 'warn' === $s['state'] && $s['since'] <= Scout_Booking::now()->getTimestamp() - self::LATE ) {
			return array(
				'label'       => __( 'Bookings can’t read your calendar', 'scout-booking' ),
				'status'      => 'recommended',
				'badge'       => $badge,
				'description' => '<p>' . esc_html( $s['headline'] . ' ' . $s['detail'] ) . '</p>',
				'actions'     => $link,
				'test'        => 'scout_booking_busy',
			);
		}
		if ( $s && 'warn' === $s['state'] ) {
			return array(
				'label'       => __( 'Bookings will try your calendar again soon', 'scout-booking' ),
				'status'      => 'good',
				'badge'       => $badge,
				'description' => '<p>' . esc_html( $s['headline'] . ' ' . $s['detail'] ) . '</p>',
				'actions'     => $link,
				'test'        => 'scout_booking_busy',
			);
		}
		return array(
			'label'       => __( 'Bookings can read your calendar', 'scout-booking' ),
			'status'      => 'good',
			'badge'       => $badge,
			'description' => '<p>' . esc_html__( 'Busy times from your calendar are left off the booking page.', 'scout-booking' ) . '</p>',
			'actions'     => '',
			'test'        => 'scout_booking_busy',
		);
	}
}
