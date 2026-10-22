<?php
/**
 * The hours data and the clock: weekly hours, dated closures, and what is
 * true right now in the site's own timezone.
 *
 * Storage:
 *   scout_hours           confirmed flag, notice bar mode, the seven days.
 *   scout_hours_closures  dated closures and special hours (holidays).
 *   scout_hours_dismissed closure ids whose Google reminder was dismissed.
 *
 * Times are kept as 24-hour "HH:MM" strings. A range whose closing time is
 * at or before its opening time runs past midnight into the next day.
 *
 * Nothing about the hours reaches visitors or schema until the owner has
 * confirmed them (the "Hours not confirmed yet" state).
 *
 * @package Scout_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hours {

	const OPTION    = 'scout_hours';
	const CLOSURES  = 'scout_hours_closures';
	const DISMISSED = 'scout_hours_dismissed';

	/** How many days ahead the live status and notice bar look. */
	const WINDOW = 14;

	/** @return array<string,string> day key => schema.org day name */
	public static function days(): array {
		return array(
			'monday'    => 'Monday',
			'tuesday'   => 'Tuesday',
			'wednesday' => 'Wednesday',
			'thursday'  => 'Thursday',
			'friday'    => 'Friday',
			'saturday'  => 'Saturday',
			'sunday'    => 'Sunday',
		);
	}

	/** Day keys in the order the site's week starts (Settings, General). */
	public static function ordered_days(): array {
		$keys  = array_keys( self::days() );
		$start = (int) get_option( 'start_of_week', 1 ); // 0 = Sunday.
		$shift = ( 0 === $start ) ? 6 : $start - 1;
		return array_merge( array_slice( $keys, $shift ), array_slice( $keys, 0, $shift ) );
	}

	/** Translated weekday name for a day key. */
	public static function day_label( string $key ): string {
		$labels = array(
			'monday'    => __( 'Monday', 'scout-hours' ),
			'tuesday'   => __( 'Tuesday', 'scout-hours' ),
			'wednesday' => __( 'Wednesday', 'scout-hours' ),
			'thursday'  => __( 'Thursday', 'scout-hours' ),
			'friday'    => __( 'Friday', 'scout-hours' ),
			'saturday'  => __( 'Saturday', 'scout-hours' ),
			'sunday'    => __( 'Sunday', 'scout-hours' ),
		);
		return $labels[ $key ] ?? $key;
	}

	/**
	 * @return array{confirmed:bool, bar:string, days:array<string,array{open:bool, ranges:array<int,array{0:string,1:string}>}>}
	 */
	public static function settings(): array {
		$s    = (array) get_option( self::OPTION, array() );
		$days = array();
		foreach ( array_keys( self::days() ) as $key ) {
			$d            = (array) ( $s['days'][ $key ] ?? array() );
			$days[ $key ] = array(
				'open'   => ! empty( $d['open'] ),
				'ranges' => self::clean_ranges( (array) ( $d['ranges'] ?? array() ) ),
			);
			if ( ! $days[ $key ]['ranges'] ) {
				$days[ $key ]['open'] = false;
			}
		}
		return array(
			'confirmed' => ! empty( $s['confirmed'] ),
			'bar'       => in_array( $s['bar'] ?? '', array( 'off', 'day', 'week' ), true ) ? $s['bar'] : 'off',
			'days'      => $days,
		);
	}

	/** Visitors and schema only see hours the owner has confirmed. */
	public static function is_public(): bool {
		return self::settings()['confirmed'];
	}

	/** True when at least one day is open. */
	public static function has_hours(): bool {
		foreach ( self::settings()['days'] as $day ) {
			if ( $day['open'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Closures sorted by start date.
	 *
	 * @return array<int,array{id:string,name:string,start:string,end:string,type:string,ranges:array,note:string}>
	 */
	public static function closures(): array {
		$out = array();
		foreach ( (array) get_option( self::CLOSURES, array() ) as $c ) {
			$c = self::clean_closure( (array) $c );
			if ( $c ) {
				$out[] = $c;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['start'], $b['start'] );
			}
		);
		return $out;
	}

	/** Closures that have not ended yet, in the site's timezone. */
	public static function upcoming_closures(): array {
		$today = self::now()->format( 'Y-m-d' );
		return array_values(
			array_filter(
				self::closures(),
				static function ( $c ) use ( $today ) {
					return $c['end'] >= $today;
				}
			)
		);
	}

	/** A closure (or special-hours entry) normalised, or null if unusable. */
	public static function clean_closure( array $c ): ?array {
		$start = self::clean_date( (string) ( $c['start'] ?? '' ) );
		if ( '' === $start ) {
			return null;
		}
		$end = self::clean_date( (string) ( $c['end'] ?? '' ) );
		if ( '' === $end || $end < $start ) {
			$end = $start;
		}
		$type   = 'special' === ( $c['type'] ?? '' ) ? 'special' : 'closed';
		$ranges = 'special' === $type ? self::clean_ranges( (array) ( $c['ranges'] ?? array() ) ) : array();
		if ( 'special' === $type && ! $ranges ) {
			$type = 'closed';
		}
		$id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $c['id'] ?? '' ) ) );
		return array(
			'id'     => '' !== $id ? $id : substr( md5( $start . $end . wp_rand() ), 0, 10 ),
			'name'   => sanitize_text_field( (string) ( $c['name'] ?? '' ) ),
			'start'  => $start,
			'end'    => $end,
			'type'   => $type,
			'ranges' => $ranges,
			'note'   => sanitize_text_field( (string) ( $c['note'] ?? '' ) ),
		);
	}

	/** "YYYY-MM-DD" or ''. */
	public static function clean_date( string $date ): string {
		$date = trim( $date );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $date;
	}

	/** "HH:MM" (24 hour) or ''. */
	public static function clean_time( string $time ): string {
		$time = trim( $time );
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)/', $time, $m ) ) {
			return '';
		}
		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	/** Up to two complete open/close pairs. */
	public static function clean_ranges( array $ranges ): array {
		$out = array();
		foreach ( $ranges as $r ) {
			$r     = array_values( (array) $r );
			$open  = self::clean_time( (string) ( $r[0] ?? '' ) );
			$close = self::clean_time( (string) ( $r[1] ?? '' ) );
			if ( '' !== $open && '' !== $close && $open !== $close ) {
				$out[] = array( $open, $close );
			}
			if ( count( $out ) >= 2 ) {
				break;
			}
		}
		return $out;
	}

	/** Now, in the site's timezone (Settings, General). */
	public static function now(): DateTimeImmutable {
		$now = (int) apply_filters( 'scout_hours_now', time() );
		return ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
	}

	/** The closure covering a date, if any. */
	public static function closure_on( string $ymd ): ?array {
		foreach ( self::closures() as $c ) {
			if ( $c['start'] <= $ymd && $c['end'] >= $ymd ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * One day's plan: its closure (if any) and its open intervals as real
	 * moments, with ranges past midnight ending the next day.
	 *
	 * @param DateTimeImmutable $date Any moment on the day, in the site timezone.
	 * @return array{date:DateTimeImmutable, key:string, closure:?array, intervals:array<int,array{0:DateTimeImmutable,1:DateTimeImmutable}>}
	 */
	public static function day_plan( DateTimeImmutable $date ): array {
		$date    = $date->setTime( 0, 0 );
		$key     = strtolower( $date->format( 'l' ) );
		$closure = self::closure_on( $date->format( 'Y-m-d' ) );
		if ( $closure ) {
			$ranges = $closure['ranges'];
		} else {
			$day    = self::settings()['days'][ $key ];
			$ranges = $day['open'] ? $day['ranges'] : array();
		}
		$intervals = array();
		foreach ( $ranges as $r ) {
			list( $oh, $om ) = array_map( 'intval', explode( ':', $r[0] ) );
			list( $ch, $cm ) = array_map( 'intval', explode( ':', $r[1] ) );
			$start           = $date->setTime( $oh, $om );
			$end             = $date->setTime( $ch, $cm );
			if ( $end <= $start ) {
				$end = $end->modify( '+1 day' );
			}
			$intervals[] = array( $start, $end );
		}
		return array(
			'date'      => $date,
			'key'       => $key,
			'closure'   => $closure,
			'intervals' => $intervals,
		);
	}

	/** Site time format, e.g. "9:00 AM". */
	public static function time_label( DateTimeInterface $t ): string {
		return wp_date( (string) get_option( 'time_format', 'g:i a' ), $t->getTimestamp() );
	}

	/** "9:00 AM to 5:00 PM" for a stored "HH:MM" pair. */
	public static function range_label( array $range ): string {
		$base  = self::now()->setTime( 0, 0 );
		$open  = $base->setTime( ...array_map( 'intval', explode( ':', $range[0] ) ) );
		$close = $base->setTime( ...array_map( 'intval', explode( ':', $range[1] ) ) );
		/* translators: 1: opening time, 2: closing time. */
		return sprintf( __( '%1$s to %2$s', 'scout-hours' ), self::time_label( $open ), self::time_label( $close ) );
	}

	/** "9:00 AM to 12:00 PM and 1:00 PM to 5:00 PM", or "Closed". */
	public static function ranges_label( array $ranges ): string {
		if ( ! $ranges ) {
			return __( 'Closed', 'scout-hours' );
		}
		return implode( __( ' and ', 'scout-hours' ), array_map( array( __CLASS__, 'range_label' ), $ranges ) );
	}

	/** A date as "Thursday, November 26". */
	public static function date_label( string $ymd ): string {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
		return $d ? wp_date( _x( 'l, F j', 'closure date', 'scout-hours' ), $d->setTime( 12, 0 )->getTimestamp() ) : $ymd;
	}

	/** A closure's dates as "Thursday, November 26" or "November 26 to November 27". */
	public static function closure_dates_label( array $c ): string {
		if ( $c['start'] === $c['end'] ) {
			return self::date_label( $c['start'] );
		}
		$a = DateTimeImmutable::createFromFormat( '!Y-m-d', $c['start'], wp_timezone() );
		$b = DateTimeImmutable::createFromFormat( '!Y-m-d', $c['end'], wp_timezone() );
		$f = _x( 'F j', 'closure date range', 'scout-hours' );
		/* translators: 1: first day, 2: last day. */
		return sprintf( __( '%1$s to %2$s', 'scout-hours' ), wp_date( $f, $a->setTime( 12, 0 )->getTimestamp() ), wp_date( $f, $b->setTime( 12, 0 )->getTimestamp() ) );
	}

	/**
	 * Everything the front end needs to work out "Open now" in the visitor's
	 * browser, so a cached page still tells the truth: one entry per day from
	 * yesterday to WINDOW days ahead, with epoch boundaries and labels made
	 * here in the site's timezone. The browser never does timezone maths.
	 */
	public static function payload(): array {
		$today = self::now()->setTime( 0, 0 );
		$days  = array();
		for ( $i = -1; $i <= self::WINDOW; $i++ ) {
			$plan = self::day_plan( $today->modify( ( $i >= 0 ? '+' : '' ) . $i . ' day' ) );
			$iv   = array();
			foreach ( $plan['intervals'] as $pair ) {
				$iv[] = array( $pair[0]->getTimestamp(), $pair[1]->getTimestamp(), self::time_label( $pair[0] ), self::time_label( $pair[1] ) );
			}
			$days[] = array(
				's'  => $plan['date']->getTimestamp(),
				'e'  => $plan['date']->modify( '+1 day' )->getTimestamp(),
				'w'  => $plan['key'],
				'n'  => self::day_label( $plan['key'] ),
				'c'  => $plan['closure'] ? $plan['closure']['name'] : '',
				'k'  => $plan['closure'] ? $plan['closure']['type'] : '',
				'iv' => $iv,
			);
		}
		return $days;
	}

	/** Status words, shared by PHP and the browser script. */
	public static function strings(): array {
		return array(
			/* translators: %s: closing time. */
			'openUntil'        => __( 'Open now until %s', 'scout-hours' ),
			/* translators: 1: closing time, 2: holiday name. */
			'openSpecial'      => __( 'Open now until %1$s, with special hours today for %2$s', 'scout-hours' ),
			/* translators: 1: holiday name, 2: day ("tomorrow", "Friday"), 3: opening time. */
			'closedForOpens'   => __( 'Closed today for %1$s. Opens %2$s at %3$s', 'scout-hours' ),
			/* translators: %s: holiday name. */
			'closedFor'        => __( 'Closed today for %s', 'scout-hours' ),
			/* translators: 1: day ("today", "tomorrow", "Friday"), 2: opening time. */
			'closedOpens'      => __( 'Closed now. Opens %1$s at %2$s', 'scout-hours' ),
			'closed'           => __( 'Closed now', 'scout-hours' ),
			'today'            => __( 'today', 'scout-hours' ),
			'tomorrow'         => __( 'tomorrow', 'scout-hours' ),
			'holiday'          => __( 'the holiday', 'scout-hours' ),
			'barLabel'         => __( 'Holiday hours', 'scout-hours' ),
			'barHide'          => __( 'Hide this notice', 'scout-hours' ),
		);
	}

	/**
	 * Work out the status line from a payload. The browser script runs the
	 * same steps (assets/scout-hours.js); keep the two in step.
	 *
	 * @return array{open:bool, text:string}
	 */
	public static function status( ?array $days = null, ?int $now = null ): array {
		$days = $days ?? self::payload();
		$now  = $now ?? self::now()->getTimestamp();
		$t    = self::strings();
		$i    = -1;
		foreach ( $days as $n => $d ) {
			if ( $d['s'] <= $now && $now < $d['e'] ) {
				$i = $n;
				break;
			}
		}
		if ( $i < 0 ) {
			return array(
				'open' => false,
				'text' => '',
			);
		}
		$today   = $days[ $i ];
		$holiday = '' !== $today['c'] ? $today['c'] : $t['holiday'];
		foreach ( array( $i - 1, $i ) as $j ) {
			if ( ! isset( $days[ $j ] ) ) {
				continue;
			}
			foreach ( $days[ $j ]['iv'] as $iv ) {
				if ( $iv[0] <= $now && $now < $iv[1] ) {
					$special = $j === $i && 'special' === $today['k'];
					return array(
						'open' => true,
						'text' => $special ? sprintf( $t['openSpecial'], $iv[3], $holiday ) : sprintf( $t['openUntil'], $iv[3] ),
					);
				}
			}
		}
		$next = null;
		for ( $j = $i, $c = count( $days ); $j < $c && ! $next; $j++ ) {
			foreach ( $days[ $j ]['iv'] as $iv ) {
				if ( $iv[0] > $now ) {
					$when = $j === $i ? $t['today'] : ( $j === $i + 1 ? $t['tomorrow'] : $days[ $j ]['n'] );
					$next = array( $when, $iv[2] );
					break;
				}
			}
		}
		if ( 'closed' === $today['k'] ) {
			$text = $next ? sprintf( $t['closedForOpens'], $holiday, $next[0], $next[1] ) : sprintf( $t['closedFor'], $holiday );
		} else {
			$text = $next ? sprintf( $t['closedOpens'], $next[0], $next[1] ) : $t['closed'];
		}
		return array(
			'open' => false,
			'text' => $text,
		);
	}
}
