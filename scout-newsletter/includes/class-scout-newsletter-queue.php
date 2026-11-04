<?php
/**
 * The send queue: works through waiting consent records in the background.
 *
 * Uses Action Scheduler when WooCommerce (or anything else) has loaded it, and
 * WP-Cron otherwise. A row that gets no answer, a 429 or a 5xx is tried again
 * after 5 minutes, 15 minutes, an hour, 3 hours and 12 hours, then marked
 * failed. An hourly sweep picks up anything a missed cron left behind.
 *
 * For each sign-up:
 *   1. Ask Beehiiv whether the email is already on the list.
 *   2. Unsubscribed or invalid: stop, remember it, never send them again.
 *   3. Not on the list: create the subscription (reactivate_existing false).
 *   4. Add the tags.
 *
 * @package Scout_Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Newsletter_Queue {

	const HOOK  = 'scout_newsletter_run';
	const SWEEP = 'scout_newsletter_sweep';
	const LOCK  = 'scout_newsletter_lock';
	const GROUP = 'scout-newsletter';

	/** Minutes to wait after each failed try; one more failure after the last is final. */
	const BACKOFF = array( 5, 15, 60, 180, 720 );

	/** Set when Beehiiv refuses the key, so the rest of the batch waits too. */
	private static bool $stop = false;

	/** True while run() works, when Action Scheduler reports this very run as "in progress". */
	private static bool $running = false;

	public static function boot(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::SWEEP, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'sweep_schedule' ) );
	}

	public static function sweep_schedule(): void {
		if ( ! wp_next_scheduled( self::SWEEP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::SWEEP );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::SWEEP );
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/** True when sends go through Action Scheduler. */
	public static function uses_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' ) && function_exists( 'as_next_scheduled_action' ) && did_action( 'action_scheduler_init' );
	}

	/** Run the queue at $when (Unix time, default now), unless it already runs sooner. */
	public static function schedule( int $when = 0 ): void {
		$when = max( time(), $when );
		if ( self::uses_action_scheduler() ) {
			$next = as_next_scheduled_action( self::HOOK, array(), self::GROUP );
			// true means a run is under way; when that run is this one, the retry still needs a slot.
			if ( ( true === $next && ! self::$running ) || ( is_int( $next ) && $next <= $when ) ) {
				return;
			}
			if ( is_int( $next ) ) {
				as_unschedule_action( self::HOOK, array(), self::GROUP );
			}
			as_schedule_single_action( $when, self::HOOK, array(), self::GROUP );
			return;
		}
		$next = wp_next_scheduled( self::HOOK );
		if ( $next && $next <= $when ) {
			return;
		}
		if ( $next ) {
			wp_unschedule_event( $next, self::HOOK );
		}
		wp_schedule_single_event( $when, self::HOOK );
	}

	/** Work through what is due. Safe to call any time. */
	public static function run(): int {
		if ( ! Scout_Newsletter::sending() || get_transient( self::LOCK ) ) {
			return 0;
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );
		self::$running = true;
		// This run covers any run already due, so the next one can be set for the next retry.
		$due = wp_next_scheduled( self::HOOK );
		if ( $due && $due <= time() ) {
			wp_unschedule_event( $due, self::HOOK );
		}
		if ( self::uses_action_scheduler() ) {
			$due = as_next_scheduled_action( self::HOOK, array(), self::GROUP );
			if ( is_int( $due ) && $due <= time() ) {
				as_unschedule_action( self::HOOK, array(), self::GROUP );
			}
		}
		$done       = 0;
		self::$stop = false;
		try {
			foreach ( Scout_Newsletter_Store::due( 20 ) as $row ) {
				if ( self::$stop ) {
					break;
				}
				self::process( $row );
				++$done;
			}
		} finally {
			delete_transient( self::LOCK );
			self::$running = false;
		}
		$next = self::$stop ? 0 : Scout_Newsletter_Store::next_due();
		if ( $next ) {
			self::schedule( $next );
		}
		return $done;
	}

	/** One row. */
	public static function process( array $row ): void {
		$id    = (int) $row['id'];
		$email = (string) $row['email'];
		$kind  = (string) $row['kind'];

		if ( 'optin' === $kind && Scout_Newsletter::suppressed( $email ) ) {
			self::finish( $row, 'skipped', 'unsubscribed' );
			return;
		}

		$sub_id = (string) $row['sub_id'];
		if ( '' === $sub_id ) {
			$found = Scout_Newsletter_Beehiiv::find( $email );
			if ( $found['ok'] ) {
				$status = (string) ( $found['data']['status'] ?? '' );
				if ( in_array( $status, Scout_Newsletter_Beehiiv::STOP, true ) ) {
					if ( 'inactive' === $status ) {
						Scout_Newsletter::suppress( $email );
					}
					self::finish( $row, 'skipped', 'inactive' === $status ? 'unsubscribed' : 'invalid address' );
					return;
				}
				$sub_id = (string) ( $found['data']['id'] ?? '' );
			} elseif ( 404 === $found['code'] ) {
				if ( 'update' === $kind ) {
					// Not on the list: updates never subscribe anyone.
					self::finish( $row, 'skipped', 'not subscribed' );
					return;
				}
				$made = Scout_Newsletter_Beehiiv::create( $row );
				if ( ! $made['ok'] ) {
					self::fail( $row, $made );
					return;
				}
				$status = (string) ( $made['data']['status'] ?? '' );
				if ( in_array( $status, Scout_Newsletter_Beehiiv::STOP, true ) ) {
					if ( 'inactive' === $status ) {
						Scout_Newsletter::suppress( $email );
					}
					self::finish( $row, 'skipped', 'inactive' === $status ? 'unsubscribed' : 'invalid address' );
					return;
				}
				$sub_id = (string) ( $made['data']['id'] ?? '' );
				Scout_Newsletter::log( $email, $kind, 'subscribed (' . ( '' !== $status ? $status : 'ok' ) . ')' );
			} else {
				self::fail( $row, $found );
				return;
			}
			// Remember the subscription, so a retry goes straight to the tags.
			Scout_Newsletter_Store::set( $id, array( 'sub_id' => $sub_id ) );
			$row['sub_id'] = $sub_id;
		}

		if ( 'update' === $kind && $row['fields'] ) {
			$up = Scout_Newsletter_Beehiiv::update_fields( $email, $row['fields'] );
			if ( ! $up['ok'] ) {
				self::fail( $row, $up );
				return;
			}
		}

		if ( $row['tags'] && '' !== $sub_id ) {
			$tagged = Scout_Newsletter_Beehiiv::add_tags( $sub_id, $row['tags'] );
			if ( ! $tagged['ok'] ) {
				self::fail( $row, $tagged );
				return;
			}
		}

		self::finish( $row, 'sent', $row['tags'] ? 'tagged ' . count( $row['tags'] ) : 'ok' );
	}

	private static function finish( array $row, string $status, string $result ): void {
		Scout_Newsletter_Store::set(
			(int) $row['id'],
			array(
				'status'   => $status,
				'result'   => $result,
				'attempts' => (int) $row['attempts'] + 1,
			)
		);
		Scout_Newsletter::log( (string) $row['email'], (string) $row['kind'], $status . ': ' . $result );
	}

	/** A failed call: try again later, hold for a fixed key, or give up. */
	private static function fail( array $row, array $res ): void {
		$tries = (int) $row['attempts'] + 1;
		$why   = (string) $res['error'];
		if ( 401 === $res['code'] || 403 === $res['code'] || 'not_connected' === $why ) {
			// The key or publication is wrong: keep the sign-up until it is fixed.
			self::$stop = true;
			Scout_Newsletter_Store::set(
				(int) $row['id'],
				array(
					'status'   => 'held',
					'result'   => 'key refused (' . $res['code'] . ')',
					'attempts' => $tries,
				)
			);
			Scout_Newsletter::log( (string) $row['email'], (string) $row['kind'], 'held: key refused ' . $res['code'] );
			return;
		}
		if ( $res['retry'] && $tries <= count( self::BACKOFF ) ) {
			$wait = self::BACKOFF[ $tries - 1 ] * MINUTE_IN_SECONDS;
			Scout_Newsletter_Store::set(
				(int) $row['id'],
				array(
					'status'   => 'retry',
					'result'   => $why,
					'attempts' => $tries,
					'next_try' => gmdate( 'Y-m-d H:i:s', time() + $wait ),
				)
			);
			Scout_Newsletter::log( (string) $row['email'], (string) $row['kind'], 'retry in ' . ( $wait / MINUTE_IN_SECONDS ) . ' min: ' . $why );
			self::schedule( time() + $wait );
			return;
		}
		self::finish( $row, 'failed', $why );
	}
}
