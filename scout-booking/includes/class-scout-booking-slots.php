<?php
/**
 * Open times: the working hours for a day, minus appointments, requests,
 * checkout holds and blocked time, minus anything too soon to book.
 *
 * Working hours come from, in order:
 *   1. the session type's own hours, when the owner set them;
 *   2. Scout Hours, when it is active and its hours are confirmed (holiday
 *      closures and special hours included);
 *   3. the booking hours on the Booking settings screen.
 *
 * One person runs the practice, so any booking blocks every session type.
 *
 * @package Scout_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Booking_Slots {

	/**
	 * Working intervals on a day for a type, as real moments.
	 *
	 * @return array<int,array{0:DateTimeImmutable,1:DateTimeImmutable}>
	 */
	public static function working( array $type, DateTimeImmutable $day ): array {
		$day = $day->setTime( 0, 0 );
		$key = strtolower( $day->format( 'l' ) );
		if ( 'default' === $type['schedule'] && Scout_Booking::scout_hours_on() ) {
			$plan = Scout_Hours::day_plan( $day );
			return array_values( (array) $plan['intervals'] );
		}
		$week = 'own' === $type['schedule'] ? $type['hours'] : Scout_Booking::settings()['hours'];
		$d    = $week[ $key ] ?? array( 'open' => false );
		if ( empty( $d['open'] ) ) {
			return array();
		}
		list( $fh, $fm ) = array_map( 'intval', explode( ':', $d['from'] ) );
		list( $th, $tm ) = array_map( 'intval', explode( ':', $d['to'] ) );
		return array( array( $day->setTime( $fh, $fm ), $day->setTime( $th, $tm ) ) );
	}

	/** Where the hours for a type come from, for the owner's screens. */
	public static function source( array $type ): string {
		if ( 'own' === $type['schedule'] ) {
			return __( 'Its own hours', 'scout-booking' );
		}
		return Scout_Booking::scout_hours_on() ? __( 'Your business hours (Scout Hours)', 'scout-booking' ) : __( 'Booking hours (Booking settings)', 'scout-booking' );
	}

	/** First and last day a client may book. */
	public static function window(): array {
		$s     = Scout_Booking::settings();
		$now   = Scout_Booking::now();
		$first = $now->modify( '+' . $s['notice_hours'] . ' hours' );
		$last  = $now->setTime( 23, 59 )->modify( '+' . $s['days_ahead'] . ' days' );
		return array( $first, $last );
	}

	/**
	 * Open start times on a day.
	 *
	 * @param int $skip A booking to ignore (the one being moved).
	 * @return DateTimeImmutable[]
	 */
	public static function for_day( array $type, DateTimeImmutable $day, int $skip = 0 ): array {
		list( $first, $last ) = self::window();
		$day = $day->setTime( 0, 0 );
		if ( $day > $last || $day->setTime( 23, 59 ) < $first ) {
			return array();
		}
		$length = $type['length'];
		$buffer = $type['buffer'];
		$step   = Scout_Booking::settings()['step'];
		// Everything holding time that day, read once.
		$busy = array();
		$rows = Scout_Booking::between( $day->modify( '-1 day' )->format( 'Y-m-d H:i:s' ), $day->modify( '+2 days' )->format( 'Y-m-d H:i:s' ), Scout_Booking::HOLDING );
		$now  = Scout_Booking::now();
		foreach ( $rows as $r ) {
			if ( (int) $r['id'] !== $skip && Scout_Booking::holds( $r, $now ) ) {
				$busy[] = array( Scout_Booking::at( $r['start_at'] ), Scout_Booking::at( $r['block_end'] ) );
			}
		}
		$out = array();
		foreach ( self::working( $type, $day ) as $range ) {
			list( $open, $close ) = $range;
			for ( $t = $open; $t->modify( '+' . $length . ' minutes' ) <= $close; $t = $t->modify( '+' . $step . ' minutes' ) ) {
				if ( $t < $first || $t > $last ) {
					continue;
				}
				$end = $t->modify( '+' . ( $length + $buffer ) . ' minutes' );
				$ok  = true;
				foreach ( $busy as $b ) {
					if ( $t < $b[1] && $b[0] < $end ) {
						$ok = false;
						break;
					}
				}
				if ( $ok ) {
					$out[] = $t;
				}
			}
		}
		return $out;
	}

	/** Is this exact start still open for the type? */
	public static function is_open( array $type, DateTimeImmutable $start, int $skip = 0 ): bool {
		foreach ( self::for_day( $type, $start, $skip ) as $t ) {
			if ( $t->format( 'Y-m-d H:i' ) === $start->format( 'Y-m-d H:i' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Days with at least one opening, from a start day, up to a count.
	 *
	 * @return array<string,int> "Y-m-d" => number of open times
	 */
	public static function days( array $type, DateTimeImmutable $from, int $count, int $skip = 0 ): array {
		list( $first, $last ) = self::window();
		$day = max( $from->setTime( 0, 0 ), $first->setTime( 0, 0 ) );
		$out = array();
		for ( $i = 0; $i < $count && $day <= $last; $i++, $day = $day->modify( '+1 day' ) ) {
			$out[ $day->format( 'Y-m-d' ) ] = count( self::for_day( $type, $day, $skip ) );
		}
		return $out;
	}
}
