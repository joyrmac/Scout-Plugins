<?php
/**
 * Renewals without saved cards. Once a day, members whose renewal date is 30
 * days away (yearly plans) and 7 days away (every plan) get a reminder email.
 * With dues through the checkout, it carries a Renew now link; without one,
 * it carries the owner's next-step note and the membership page. Each
 * reminder goes once per renewal date, and a missed day catches up the next.
 *
 * After the renewal date the grace period (Settings, 14 days unless changed)
 * keeps members-only pages open while the member renews; then access ends by
 * itself and the membership waits, still marked active, for a payment or the
 * owner. Nothing is charged automatically.
 *
 * @package Scout_Members
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Members_Renewals {

	const HOOK = 'scout_members_daily';

	public static function boot(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Send whatever reminders are due today. Returns how many went out. */
	public static function run(): int {
		if ( ! Scout_Members::settings()['reminders'] ) {
			return 0;
		}
		$sent  = 0;
		$today = Scout_Members::today();
		foreach ( Scout_Members::ids( 'active' ) as $id ) {
			$m      = Scout_Members::member( $id );
			$period = Scout_Members::plans()[ $m['plan'] ]['period'] ?? 'year';
			if ( '' === $m['renews'] || 'once' === $period || $m['renews'] < $today ) {
				continue;
			}
			$days = (int) round( ( strtotime( $m['renews'] . ' 12:00:00' ) - strtotime( $today . ' 12:00:00' ) ) / DAY_IN_SECONDS );
			$done = $m['reminded'];
			$due  = '';
			if ( $days <= 7 && ( $done['7'] ?? '' ) !== $m['renews'] ) {
				$due = '7';
			} elseif ( $days <= 30 && $days > 7 && 'year' === $period && ( $done['30'] ?? '' ) !== $m['renews'] ) {
				$due = '30';
			}
			if ( '' === $due ) {
				continue;
			}
			if ( self::remind( $id, $days ) ) {
				++$sent;
			}
			// A 7-day reminder also stands in for a 30-day one that never went.
			$done[ $due ] = $m['renews'];
			if ( '7' === $due ) {
				$done['30'] = $m['renews'];
			}
			Scout_Members::save( $id, array( 'reminded' => $done ) );
		}
		return $sent;
	}

	/** The reminder email. */
	public static function remind( int $user_id, int $days ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$m     = Scout_Members::member( $user_id );
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$date  = Scout_Members::date_label( $m['renews'] );
		$grace = Scout_Members::settings()['grace'];
		$link  = class_exists( 'Scout_Members_Woo' ) && Scout_Members_Woo::active() ? Scout_Members_Woo::pay_url( $user_id ) : '';
		/* translators: 1: site name, 2: date. */
		$subject = sprintf( __( 'Your %1$s membership renews on %2$s', 'scout-members' ), $site, $date );
		$hello   = '' !== $user->first_name ? $user->first_name : $user->display_name;
		// A comped member's plan line leaves out the plan price they do not pay.
		$plan = '' !== $m['comp'] ? (string) ( Scout_Members::plans()[ $m['plan'] ]['name'] ?? '' ) : Scout_Members::plan_label( $m['plan'] );
		$plan = '' !== $plan ? $plan : __( 'your plan', 'scout-members' );
		/* translators: %s: first name. */
		$body = sprintf( __( 'Hello %s,', 'scout-members' ), $hello ) . "\n\n";
		if ( 0 === $days ) {
			/* translators: %s: plan label, such as "Club Member, $120 a year". */
			$body .= sprintf( __( 'Your membership (%s) renews today.', 'scout-members' ), $plan ) . "\n\n";
		} else {
			/* translators: 1: plan label, 2: renewal date, 3: number of days. */
			$body .= sprintf( _n( 'Your membership (%1$s) renews on %2$s, in %3$d day.', 'Your membership (%1$s) renews on %2$s, in %3$d days.', $days, 'scout-members' ), $plan, $date, $days ) . "\n\n";
		}
		if ( '' !== $link ) {
			$amount = Scout_Members_Woo::amount( $user_id );
			if ( '' !== $amount && 0.0 === (float) $amount ) {
				$body .= __( 'Your renewal is free this time. Confirm it with one click:', 'scout-members' ) . "\n" . $link . "\n\n";
			} else {
				$body .= ( '' !== $amount
					/* translators: %s: amount. */
					? sprintf( __( 'Renew now (%s):', 'scout-members' ), Scout_Members::money( $amount ) )
					: __( 'Renew now:', 'scout-members' ) ) . "\n" . $link . "\n\n";
			}
		} else {
			$next = trim( (string) get_option( 'scout_members_next_step', '' ) );
			if ( '' !== $next ) {
				$body .= $next . "\n\n";
			}
			$body .= __( 'Your membership page:', 'scout-members' ) . "\n" . Scout_Members::page_url() . "\n\n";
		}
		if ( $grace > 0 ) {
			/* translators: %d: days. */
			$body .= sprintf( _n( 'If it slips past the date, members-only pages stay open for %d more day while you renew.', 'If it slips past the date, members-only pages stay open for %d more days while you renew.', $grace, 'scout-members' ), $grace ) . "\n\n";
		}
		$body .= __( 'Thank you for being a member.', 'scout-members' );
		$ok = wp_mail( $user->user_email, $subject, $body );
		/* translators: %d: days before the renewal date. */
		Scout_Members::log( $user_id, sprintf( _n( 'Renewal reminder sent, %d day before.', 'Renewal reminder sent, %d days before.', $days, 'scout-members' ), $days ) );
		return (bool) $ok;
	}

	/**
	 * The Renewals screen's list: active members renewing in the next 30
	 * days, in the grace period or lapsed in the last 60 days, and everyone
	 * who paid in the last 30 days, soonest renewal first.
	 *
	 * @return int[]
	 */
	public static function list_ids(): array {
		$today = Scout_Members::today();
		$soon  = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );
		$back  = gmdate( 'Y-m-d', strtotime( $today . ' -60 days' ) );
		$paid  = gmdate( 'Y-m-d', strtotime( $today . ' -30 days' ) );
		$ids   = array_filter(
			Scout_Members::ids( array( 'active', 'approved' ) ),
			static function ( $id ) use ( $soon, $back, $paid ) {
				$m = Scout_Members::member( $id );
				if ( 'approved' === $m['status'] ) {
					return true;
				}
				return ( '' !== $m['renews'] && $m['renews'] <= $soon && $m['renews'] >= $back ) || ( '' !== $m['paid'] && $m['paid'] >= $paid );
			}
		);
		usort(
			$ids,
			static function ( $a, $b ) {
				$ra = Scout_Members::member( $a )['renews'];
				$rb = Scout_Members::member( $b )['renews'];
				return strcmp( '' !== $ra ? $ra : '9999', '' !== $rb ? $rb : '9999' );
			}
		);
		return array_values( $ids );
	}
}
