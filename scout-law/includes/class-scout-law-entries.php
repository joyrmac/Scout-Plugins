<?php
/**
 * Form entries that delete themselves: each night, Gravity Forms entries
 * older than the firm's limit (90 days by default) are deleted from the
 * website, with the files uploaded with them.
 *
 * What stays safe:
 * - The first delete waits FIRST_WAIT days after this feature first runs on a
 *   site, so an automatic update never removes old entries the same night.
 *   Home shows the start date until then.
 * - Starred entries are kept. Starring an entry in Gravity Forms is how a
 *   person says "keep this one".
 * - A form that emails no one is skipped: its entries are the only copy.
 * - A form with Gravity Forms' own retention setting is left to Gravity Forms.
 * - Forms the firm chose to keep are skipped.
 *
 * Assumes GFAPI::get_forms() returns the active forms, each with 'id',
 * 'title', 'notifications' (keyed arrays with 'isActive', 'event', 'to') and
 * optionally 'personalData' => [ 'retention' => [ 'policy' => retain|trash|delete ] ].
 * Assumes GFAPI::get_entries( $form_id, $search, null, $paging ) returns entry
 * arrays with 'id', 'date_created' (UTC, Y-m-d H:i:s) and 'is_starred', and
 * GFAPI::delete_entry( $id ) deletes the entry and its uploaded files.
 *
 * @package Scout_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Law_Entries {

	const OPTION     = 'scout_law_entries';
	const STATE      = 'scout_law_entries_state'; // first_seen, last_run.
	const LOG        = 'scout_law_entries_log';
	const HOOK       = 'scout_law_entries_nightly';
	const FIRST_WAIT = 14;                         // Days' notice before the first delete.
	const DAYS       = array( 30, 60, 90, 180, 365 );
	const PER_RUN    = 500;                        // Entries per form per night, so a backlog never times out.

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'nightly' ) );
		add_filter( 'scout_hub_today', array( __CLASS__, 'today' ) );
	}

	/* ---- Settings ----------------------------------------------------- */

	/** @return array{on:bool,days:int,keep:int[]} */
	public static function settings(): array {
		$s    = get_option( self::OPTION, array() );
		$s    = is_array( $s ) ? $s : array();
		$days = (int) ( $s['days'] ?? 90 );
		return array(
			'on'   => array_key_exists( 'on', $s ) ? (bool) $s['on'] : true,
			'days' => in_array( $days, self::DAYS, true ) ? $days : 90,
			'keep' => array_values( array_filter( array_map( 'intval', (array) ( $s['keep'] ?? array() ) ) ) ),
		);
	}

	/** Days entries are kept, or 0 when entries are never deleted. The privacy policy reads this. */
	public static function days(): int {
		$s = self::settings();
		return $s['on'] ? $s['days'] : 0;
	}

	/** When this feature was first seen on the site. Set once. */
	public static function first_seen( int $now ): int {
		$state = get_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();
		if ( empty( $state['first_seen'] ) ) {
			$state['first_seen'] = $now;
			update_option( self::STATE, $state, false );
		}
		return (int) $state['first_seen'];
	}

	/** The first night entries can be deleted. */
	public static function starts( int $first_seen ): int {
		return $first_seen + self::FIRST_WAIT * DAY_IN_SECONDS;
	}

	public static function cutoff( int $now, int $days ): int {
		return $now - $days * DAY_IN_SECONDS;
	}

	/* ---- Which forms and which entries -------------------------------- */

	/** True when the form sends at least one email on submission. */
	public static function emails_someone( array $form ): bool {
		foreach ( (array) ( $form['notifications'] ?? array() ) as $n ) {
			if ( ! is_array( $n ) ) {
				continue;
			}
			$active = ! array_key_exists( 'isActive', $n ) || (bool) $n['isActive'];
			$event  = (string) ( $n['event'] ?? 'form_submission' );
			$to     = trim( (string) ( $n['to'] ?? '' ) );
			if ( $active && ( '' === $event || 'form_submission' === $event ) && '' !== $to ) {
				return true;
			}
		}
		return false;
	}

	/** Gravity Forms' own retention setting, when it deletes or trashes entries. */
	public static function own_policy( array $form ): bool {
		$policy = (string) ( $form['personalData']['retention']['policy'] ?? 'retain' );
		return in_array( $policy, array( 'trash', 'delete' ), true );
	}

	/**
	 * What happens to each form's entries.
	 *
	 * @return array<int,array{title:string,does:string}> does: delete | kept | no-email | gravity
	 */
	public static function plan( array $forms, array $settings ): array {
		$out = array();
		foreach ( $forms as $form ) {
			$id = (int) ( $form['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			if ( in_array( $id, $settings['keep'], true ) ) {
				$does = 'kept';
			} elseif ( self::own_policy( $form ) ) {
				$does = 'gravity';
			} elseif ( ! self::emails_someone( $form ) ) {
				$does = 'no-email';
			} else {
				$does = 'delete';
			}
			$out[ $id ] = array(
				'title' => (string) ( $form['title'] ?? '' ),
				'does'  => $does,
			);
		}
		return $out;
	}

	/** True when this entry is past the limit and not starred. */
	public static function expired( array $entry, int $cutoff ): bool {
		if ( ! empty( $entry['is_starred'] ) ) {
			return false;
		}
		$at = strtotime( (string) ( $entry['date_created'] ?? '' ) . ' UTC' );
		return false !== $at && $at > 0 && $at < $cutoff;
	}

	/* ---- The nightly run ---------------------------------------------- */

	public static function schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::HOOK ) ) {
			self::first_seen( time() ); // The notice period starts the day the plugin first runs here.
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function gravity(): bool {
		return class_exists( 'GFAPI' ) && method_exists( 'GFAPI', 'get_forms' );
	}

	/** @return array<int,array> Active forms, keyed by id. */
	public static function forms(): array {
		if ( ! self::gravity() ) {
			return array();
		}
		$out = array();
		foreach ( (array) GFAPI::get_forms() as $form ) {
			if ( is_array( $form ) && ! empty( $form['id'] ) ) {
				$full                     = method_exists( 'GFAPI', 'get_form' ) ? GFAPI::get_form( (int) $form['id'] ) : false;
				$out[ (int) $form['id'] ] = is_array( $full ) ? $full : $form;
			}
		}
		return $out;
	}

	/** The scheduled job. WordPress passes an empty string to a cron action with no arguments, so run() is not hooked directly. */
	public static function nightly(): void {
		self::run();
	}

	/**
	 * Delete the entries past the limit. Returns how many went, by form.
	 *
	 * @return array<int,int>
	 */
	public static function run( int $now = 0 ): array {
		$now      = $now ? $now : time();
		$settings = self::settings();
		$first    = self::first_seen( $now );
		if ( ! $settings['on'] || ! self::gravity() || $now < self::starts( $first ) ) {
			return array();
		}
		$cutoff  = self::cutoff( $now, $settings['days'] );
		$deleted = array();
		foreach ( self::plan( self::forms(), $settings ) as $form_id => $row ) {
			if ( 'delete' === $row['does'] ) {
				$deleted[ $form_id ] = self::clear_form( $form_id, $cutoff );
			}
		}
		$state             = get_option( self::STATE, array() );
		$state             = is_array( $state ) ? $state : array();
		$state['last_run'] = $now;
		update_option( self::STATE, $state, false );
		self::remember( $now, $deleted, $settings['days'] );
		return $deleted;
	}

	/** Delete one form's old entries in every status (active, spam, trash), a page at a time. */
	public static function clear_form( int $form_id, int $cutoff ): int {
		$count = 0;
		$end   = gmdate( 'Y-m-d H:i:s', $cutoff );
		foreach ( array( 'active', 'spam', 'trash' ) as $status ) {
			$skip = 0;
			while ( $count < self::PER_RUN ) {
				$search  = array(
					'status'        => $status,
					'end_date'      => $end,
					'field_filters' => array( array( 'key' => 'is_starred', 'value' => 0 ) ),
				);
				$entries = GFAPI::get_entries( $form_id, $search, array( 'key' => 'date_created', 'direction' => 'ASC' ), array( 'offset' => $skip, 'page_size' => 50 ) );
				if ( ! is_array( $entries ) || ! $entries ) {
					break;
				}
				$gone = 0;
				foreach ( $entries as $entry ) {
					if ( $count >= self::PER_RUN ) {
						break 2;
					}
					// Checked again here: the search's date reading and the star filter are Gravity Forms' own.
					if ( self::expired( (array) $entry, $cutoff ) && true === GFAPI::delete_entry( (int) $entry['id'] ) ) {
						++$gone;
						++$count;
					} else {
						++$skip; // Left in place, so the next page starts after it.
					}
				}
				if ( ! $gone ) {
					break;
				}
			}
		}
		return $count;
	}

	/** Keep a short record of each night that deleted something. Counts only, never names. */
	private static function remember( int $now, array $deleted, int $days ): void {
		$total = array_sum( $deleted );
		if ( ! $total ) {
			return;
		}
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'at'    => $now,
				'total' => $total,
				'forms' => $deleted,
				'days'  => $days,
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 30 ), false );
	}

	public static function log(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/* ---- Home ---------------------------------------------------------- */

	/** Before the first delete, Home says when it starts, so anyone who needs an old entry can save it. */
	public static function today( $items ) {
		$items    = is_array( $items ) ? $items : array();
		$settings = self::settings();
		if ( ! $settings['on'] || ! self::gravity() ) {
			return $items;
		}
		$now    = time();
		$starts = self::starts( self::first_seen( $now ) );
		if ( $now < $starts ) {
			$items[] = array(
				'id'       => 'law-entries-start',
				/* translators: 1: days, 2: date */
				'label'    => sprintf( __( 'Form entries older than %1$d days start deleting on %2$s', 'scout-law' ), $settings['days'], wp_date( get_option( 'date_format' ), $starts ) ),
				'why'      => __( 'Save any old entry you need first, or star it in Gravity Forms to keep it. The emails you already got stay in your inbox.', 'scout-law' ),
				'minutes'  => 5,
				'url'      => Scout_Law_Entries_Admin::url(),
				'button'   => __( 'See what gets deleted', 'scout-law' ),
				'priority' => 40,
				'cap'      => 'manage_options',
			);
		}
		return $items;
	}
}

if ( ! function_exists( 'scout_law_entry_days' ) ) {
	/** Days form entries are kept on the website, or 0 when they are never deleted. */
	function scout_law_entry_days(): int {
		return Scout_Law_Entries::days();
	}
}
