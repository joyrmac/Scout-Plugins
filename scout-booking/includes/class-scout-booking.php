<?php
/**
 * The booking data: settings, session types, and the appointments table.
 *
 * Storage:
 *   scout_booking           settings (cancellation window, notice, booking hours, feed token...).
 *   scout_booking_types     the session types the owner edits.
 *   {prefix}scout_bookings  one row per appointment, request or blocked time.
 *
 * Times are kept as "Y-m-d H:i:s" in the site's own timezone (Settings,
 * General), the same clock the owner reads. block_end is the end plus the
 * session's buffer, so the next appointment cannot start inside it.
 *
 * Statuses:
 *   requested  a "by request only" session waiting for the owner.
 *   approved   approved by the owner, waiting for the client to pay.
 *   pending    in the checkout; holds the time until hold_until.
 *   confirmed  booked (paid, or nothing to pay online).
 *   cancelled, declined, expired  no longer holding a time.
 *   blocked    time the owner blocked off.
 *
 * @package Scout_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Booking {

	const OPTION     = 'scout_booking';
	const TYPES      = 'scout_booking_types';
	const DB_OPTION  = 'scout_booking_db';
	const DB_VERSION = '1';
	const CRON       = 'scout_booking_hourly';

	/** Statuses that hold a time on the calendar. "pending" only while its hold lasts. */
	const HOLDING = array( 'requested', 'approved', 'pending', 'confirmed', 'blocked' );

	public static function boot(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ), 5 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'hourly' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'eraser' ) );
	}

	/* ---------- the table ---------- */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'scout_bookings';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token varchar(40) NOT NULL DEFAULT '',
				type_id varchar(20) NOT NULL DEFAULT '',
				type_name varchar(190) NOT NULL DEFAULT '',
				start_at datetime NOT NULL,
				end_at datetime NOT NULL,
				block_end datetime NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'requested',
				first_name varchar(100) NOT NULL DEFAULT '',
				last_name varchar(100) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(40) NOT NULL DEFAULT '',
				answers longtext NULL,
				note text NULL,
				price decimal(10,2) NOT NULL DEFAULT 0,
				due decimal(10,2) NOT NULL DEFAULT 0,
				paid decimal(10,2) NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				optin tinyint(1) unsigned NOT NULL DEFAULT 0,
				hold_until datetime NULL,
				reminded datetime NULL,
				created datetime NOT NULL,
				updated datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token (token),
				KEY start_status (start_at,status),
				KEY email (email)
			) $charset;"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		if ( '' === self::settings()['feed_token'] ) {
			self::save_settings( array( 'feed_token' => self::new_token() ) );
		}
	}

	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	public static function new_token(): string {
		return strtolower( wp_generate_password( 32, false ) );
	}

	/* ---------- settings ---------- */

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'page_id'      => 0,
			'notify'       => '',
			'cancel_hours' => 24,
			'late'         => 'block',
			'notice_hours' => 12,
			'days_ahead'   => 60,
			'step'         => 30,
			'hold_minutes' => 30,
			'pay_hours'    => 48,
			'policy'       => '',
			'optin'        => 1,
			'optin_label'  => '',
			'reminders'    => 1,
			'hours'        => array(),
			'feed_token'   => '',
		);
	}

	public static function settings(): array {
		$s   = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$out = array(
			'page_id'      => absint( $s['page_id'] ),
			'notify'       => sanitize_email( (string) $s['notify'] ),
			'cancel_hours' => max( 0, min( 720, (int) $s['cancel_hours'] ) ),
			'late'         => 'allow' === $s['late'] ? 'allow' : 'block',
			'notice_hours' => max( 0, min( 720, (int) $s['notice_hours'] ) ),
			'days_ahead'   => max( 1, min( 365, (int) $s['days_ahead'] ) ),
			'step'         => in_array( (int) $s['step'], array( 15, 30, 60 ), true ) ? (int) $s['step'] : 30,
			'hold_minutes' => max( 10, min( 240, (int) $s['hold_minutes'] ) ),
			'pay_hours'    => max( 1, min( 336, (int) $s['pay_hours'] ) ),
			'policy'       => sanitize_textarea_field( (string) $s['policy'] ),
			'optin'        => ! empty( $s['optin'] ),
			'optin_label'  => sanitize_text_field( (string) $s['optin_label'] ),
			'reminders'    => ! empty( $s['reminders'] ),
			'hours'        => self::clean_week( (array) $s['hours'] ),
			'feed_token'   => preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $s['feed_token'] ) ),
		);
		return $out;
	}

	public static function save_settings( array $changes ): void {
		$s = array_merge( (array) get_option( self::OPTION, array() ), $changes );
		update_option( self::OPTION, $s, false );
	}

	/** Where booking emails for the owner go. */
	public static function notify_email(): string {
		$e = self::settings()['notify'];
		return '' !== $e ? $e : self::shared_notice_email();
	}

	/** The site's shared notice address from Business info, or the site email without Scout Core. */
	public static function shared_notice_email(): string {
		return function_exists( 'scout_core_notice_email' ) ? (string) scout_core_notice_email() : (string) get_option( 'admin_email' );
	}

	/** The cancellation policy line shown before booking and in every email. */
	public static function policy(): string {
		$s = self::settings();
		if ( '' !== $s['policy'] ) {
			return $s['policy'];
		}
		if ( $s['cancel_hours'] <= 0 ) {
			return '';
		}
		/* translators: %d: hours of notice. */
		return sprintf( _n( 'Please give %d hour of notice to change or cancel.', 'Please give %d hours of notice to change or cancel.', $s['cancel_hours'], 'scout-booking' ), $s['cancel_hours'] );
	}

	/** The newsletter tick box words. */
	public static function optin_label(): string {
		$l = self::settings()['optin_label'];
		return '' !== $l ? $l : __( 'Send me the newsletter', 'scout-booking' );
	}

	/** WooCommerce is here, so deposits go through its checkout. */
	public static function payments_on(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
	}

	/* ---------- weekly hours ---------- */

	/** @return array<string,string> day key => label */
	public static function days(): array {
		return array(
			'monday'    => __( 'Monday', 'scout-booking' ),
			'tuesday'   => __( 'Tuesday', 'scout-booking' ),
			'wednesday' => __( 'Wednesday', 'scout-booking' ),
			'thursday'  => __( 'Thursday', 'scout-booking' ),
			'friday'    => __( 'Friday', 'scout-booking' ),
			'saturday'  => __( 'Saturday', 'scout-booking' ),
			'sunday'    => __( 'Sunday', 'scout-booking' ),
		);
	}

	/** "HH:MM" (24 hour) or ''. */
	public static function clean_time( string $time ): string {
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)/', trim( $time ), $m ) ) {
			return '';
		}
		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	/** "YYYY-MM-DD" or ''. */
	public static function clean_date( string $date ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( $date ), $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return trim( $date );
	}

	/**
	 * A week of hours: each day open or not, with one range that must close
	 * after it opens (sessions do not run past midnight).
	 *
	 * @return array<string,array{open:bool,from:string,to:string}>
	 */
	public static function clean_week( array $week ): array {
		$out = array();
		foreach ( array_keys( self::days() ) as $key ) {
			$d           = (array) ( $week[ $key ] ?? array() );
			$from        = self::clean_time( (string) ( $d['from'] ?? '' ) );
			$to          = self::clean_time( (string) ( $d['to'] ?? '' ) );
			$ok          = '' !== $from && '' !== $to && $from < $to;
			$out[ $key ] = array(
				'open' => ! empty( $d['open'] ) && $ok,
				'from' => '' !== $from ? $from : '10:00',
				'to'   => '' !== $to ? $to : '18:00',
			);
		}
		return $out;
	}

	/** Scout Hours is active and its hours are confirmed. */
	public static function scout_hours_on(): bool {
		return class_exists( 'Scout_Hours' ) && method_exists( 'Scout_Hours', 'day_plan' ) && Scout_Hours::is_public() && Scout_Hours::has_hours();
	}

	/* ---------- session types ---------- */

	/** @return array<string,mixed> one type with every field present */
	public static function clean_type( array $t ): array {
		$deposit   = in_array( $t['deposit'] ?? '', array( 'full', 'amount', 'none' ), true ) ? $t['deposit'] : 'full';
		$questions = array();
		foreach ( (array) ( $t['questions'] ?? array() ) as $q ) {
			$q     = (array) $q;
			$label = sanitize_text_field( (string) ( $q['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$questions[] = array(
				'label'    => $label,
				'required' => ! empty( $q['required'] ),
				'long'     => ! empty( $q['long'] ),
			);
			if ( count( $questions ) >= 8 ) {
				break;
			}
		}
		$id = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $t['id'] ?? '' ) ) );
		return array(
			'id'             => '' !== $id ? substr( $id, 0, 20 ) : strtolower( wp_generate_password( 8, false ) ),
			'name'           => sanitize_text_field( (string) ( $t['name'] ?? '' ) ),
			'receipt'        => sanitize_text_field( (string) ( $t['receipt'] ?? '' ) ),
			'length'         => max( 15, min( 720, (int) ( $t['length'] ?? 60 ) ) ),
			'price'          => max( 0, round( (float) ( $t['price'] ?? 0 ), 2 ) ),
			'deposit'        => $deposit,
			'deposit_amount' => max( 0, round( (float) ( $t['deposit_amount'] ?? 0 ), 2 ) ),
			'buffer'         => max( 0, min( 240, (int) ( $t['buffer'] ?? 0 ) ) ),
			'schedule'       => 'own' === ( $t['schedule'] ?? '' ) ? 'own' : 'default',
			'hours'          => self::clean_week( (array) ( $t['hours'] ?? array() ) ),
			'request'        => ! empty( $t['request'] ),
			'questions'      => $questions,
			'note'           => sanitize_textarea_field( (string) ( $t['note'] ?? '' ) ),
			'active'         => ! empty( $t['active'] ),
			'product_id'     => absint( $t['product_id'] ?? 0 ),
		);
	}

	/** @return array<string,array> id => type, in the owner's order */
	public static function types( bool $active_only = false ): array {
		$out = array();
		foreach ( (array) get_option( self::TYPES, array() ) as $t ) {
			$t = self::clean_type( (array) $t );
			if ( '' === $t['name'] || ( $active_only && ! $t['active'] ) ) {
				continue;
			}
			$out[ $t['id'] ] = $t;
		}
		return $out;
	}

	public static function type( string $id ): ?array {
		return self::types()[ $id ] ?? null;
	}

	public static function save_types( array $types ): void {
		update_option( self::TYPES, array_values( array_map( array( __CLASS__, 'clean_type' ), $types ) ), false );
	}

	/** The name on checkout and receipts: the receipt name if set, else the session name. */
	public static function receipt_name( array $type ): string {
		return '' !== $type['receipt'] ? $type['receipt'] : $type['name'];
	}

	/** What the client pays on the site for this type: the deposit, the full price, or nothing. */
	public static function due( array $type ): float {
		if ( 'none' === $type['deposit'] ) {
			return 0.0;
		}
		if ( 'amount' === $type['deposit'] ) {
			return min( $type['price'], $type['deposit_amount'] );
		}
		return $type['price'];
	}

	/** Booked as a request the owner approves: the type says so, or the site has no checkout. */
	public static function is_request( array $type ): bool {
		return $type['request'] || ( ! self::payments_on() && self::due( $type ) > 0 );
	}

	/* ---------- clock ---------- */

	public static function now(): DateTimeImmutable {
		$now = (int) apply_filters( 'scout_booking_now', time() );
		return ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
	}

	/** A stored "Y-m-d H:i:s" as a moment in the site's timezone. */
	public static function at( string $local ): DateTimeImmutable {
		$d = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $local, wp_timezone() );
		return $d ? $d : self::now();
	}

	/** "Thursday, November 12 at 2:00 PM". */
	public static function when_label( array $row ): string {
		$start = self::at( $row['start_at'] );
		/* translators: 1: date, 2: time. */
		return sprintf( __( '%1$s at %2$s', 'scout-booking' ), wp_date( 'l, F j, Y', $start->getTimestamp() ), wp_date( (string) get_option( 'time_format', 'g:i a' ), $start->getTimestamp() ) );
	}

	/** "2:00 PM to 3:00 PM". */
	public static function time_range_label( array $row ): string {
		$f = (string) get_option( 'time_format', 'g:i a' );
		/* translators: 1: start time, 2: end time. */
		return sprintf( __( '%1$s to %2$s', 'scout-booking' ), wp_date( $f, self::at( $row['start_at'] )->getTimestamp() ), wp_date( $f, self::at( $row['end_at'] )->getTimestamp() ) );
	}

	/** "1 hour 30 minutes". */
	public static function length_label( int $minutes ): string {
		$h = intdiv( $minutes, 60 );
		$m = $minutes % 60;
		$p = array();
		if ( $h ) {
			/* translators: %d: hours. */
			$p[] = sprintf( _n( '%d hour', '%d hours', $h, 'scout-booking' ), $h );
		}
		if ( $m ) {
			/* translators: %d: minutes. */
			$p[] = sprintf( _n( '%d minute', '%d minutes', $m, 'scout-booking' ), $m );
		}
		return implode( ' ', $p );
	}

	/** Money in the shop's currency when WooCommerce is here, else plain dollars. */
	public static function money( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		}
		return '$' . number_format_i18n( $amount, floor( $amount ) === $amount ? 0 : 2 );
	}

	/* ---------- rows ---------- */

	/** @return array<string,string> status => label for the owner */
	public static function statuses(): array {
		return array(
			'requested' => __( 'Request to approve', 'scout-booking' ),
			'approved'  => __( 'Approved, waiting for payment', 'scout-booking' ),
			'pending'   => __( 'In checkout', 'scout-booking' ),
			'confirmed' => __( 'Booked', 'scout-booking' ),
			'cancelled' => __( 'Cancelled', 'scout-booking' ),
			'declined'  => __( 'Declined', 'scout-booking' ),
			'expired'   => __( 'Expired', 'scout-booking' ),
			'blocked'   => __( 'Blocked time', 'scout-booking' ),
		);
	}

	private static function decode( ?array $row ): ?array {
		if ( ! $row ) {
			return null;
		}
		$answers        = json_decode( (string) $row['answers'], true );
		$row['answers'] = is_array( $answers ) ? $answers : array();
		foreach ( array( 'price', 'due', 'paid' ) as $k ) {
			$row[ $k ] = (float) $row[ $k ];
		}
		$row['id']       = (int) $row['id'];
		$row['order_id'] = (int) $row['order_id'];
		return $row;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		return self::decode( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	public static function find( string $token ): ?array {
		$token = preg_replace( '/[^a-z0-9]/', '', strtolower( $token ) );
		if ( strlen( $token ) < 20 ) {
			return null;
		}
		global $wpdb;
		return self::decode( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s', $token ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Rows starting between two local times, optionally only some statuses.
	 *
	 * @param string[] $statuses
	 * @return array<int,array>
	 */
	public static function between( string $from, string $to, array $statuses = array() ): array {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::table() . ' WHERE start_at < %s AND block_end > %s';
		$args = array( $to, $from );
		if ( $statuses ) {
			$sql .= ' AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$args = array_merge( $args, $statuses );
		}
		$sql .= ' ORDER BY start_at ASC';
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_values( array_filter( array_map( array( __CLASS__, 'decode' ), $rows ) ) );
	}

	/** @return array<int,array> rows with one status, soonest first */
	public static function with_status( string $status, int $limit = 100 ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY start_at ASC LIMIT %d', $status, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		return array_values( array_filter( array_map( array( __CLASS__, 'decode' ), $rows ) ) );
	}

	/** Does this row hold its time right now? */
	public static function holds( array $row, ?DateTimeImmutable $now = null ): bool {
		if ( ! in_array( $row['status'], self::HOLDING, true ) ) {
			return false;
		}
		if ( 'pending' === $row['status'] && ! empty( $row['hold_until'] ) ) {
			return self::at( $row['hold_until'] ) > ( $now ?? self::now() );
		}
		return true;
	}

	/**
	 * Rows that hold time overlapping [start, end), skipping one row (the
	 * appointment being moved).
	 */
	public static function conflicts( DateTimeImmutable $start, DateTimeImmutable $end, int $skip = 0 ): array {
		$rows = self::between( $start->format( 'Y-m-d H:i:s' ), $end->format( 'Y-m-d H:i:s' ), self::HOLDING );
		$now  = self::now();
		return array_values(
			array_filter(
				$rows,
				static function ( $r ) use ( $skip, $now ) {
					return (int) $r['id'] !== $skip && self::holds( $r, $now );
				}
			)
		);
	}

	/**
	 * Add a row. Returns the stored row, or null when the time was taken in
	 * the meantime (checked again right before writing).
	 */
	public static function add( array $data ): ?array {
		global $wpdb;
		$start = self::at( $data['start_at'] );
		$block = self::at( $data['block_end'] );
		if ( 'blocked' !== ( $data['status'] ?? '' ) && self::conflicts( $start, $block ) ) {
			return null;
		}
		$now  = current_time( 'mysql' );
		$row  = array(
			'token'      => self::new_token(),
			'type_id'    => (string) ( $data['type_id'] ?? '' ),
			'type_name'  => (string) ( $data['type_name'] ?? '' ),
			'start_at'   => $data['start_at'],
			'end_at'     => $data['end_at'],
			'block_end'  => $data['block_end'],
			'status'     => (string) $data['status'],
			'first_name' => (string) ( $data['first_name'] ?? '' ),
			'last_name'  => (string) ( $data['last_name'] ?? '' ),
			'email'      => strtolower( (string) ( $data['email'] ?? '' ) ),
			'phone'      => (string) ( $data['phone'] ?? '' ),
			'answers'    => wp_json_encode( array_values( (array) ( $data['answers'] ?? array() ) ) ),
			'note'       => (string) ( $data['note'] ?? '' ),
			'price'      => (float) ( $data['price'] ?? 0 ),
			'due'        => (float) ( $data['due'] ?? 0 ),
			'paid'       => 0,
			'order_id'   => 0,
			'optin'      => empty( $data['optin'] ) ? 0 : 1,
			'hold_until' => $data['hold_until'] ?? null,
			'created'    => $now,
			'updated'    => $now,
		);
		$ok = $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? self::get( (int) $wpdb->insert_id ) : null;
	}

	/** Change fields on a row and return it fresh. */
	public static function update( int $id, array $fields ): ?array {
		global $wpdb;
		$fields['updated'] = current_time( 'mysql' );
		$wpdb->update( self::table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = self::get( $id );
		if ( $row && isset( $fields['status'] ) ) {
			/**
			 * A booking changed status.
			 *
			 * @param array  $row    The booking after the change.
			 * @param string $status The new status.
			 */
			do_action( 'scout_booking_status', $row, (string) $fields['status'] );
		}
		return $row;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Can the client still change or cancel this appointment themselves? */
	public static function outside_window( array $row ): bool {
		$hours = self::settings()['cancel_hours'];
		return self::at( $row['start_at'] )->getTimestamp() - self::now()->getTimestamp() >= $hours * HOUR_IN_SECONDS;
	}

	/** The client's full name. */
	public static function client_name( array $row ): string {
		return trim( $row['first_name'] . ' ' . $row['last_name'] );
	}

	/* ---------- hourly upkeep ---------- */

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Release checkout holds that ran out, release approved requests that were
	 * not paid within the pay window, and send tomorrow's reminders.
	 */
	public static function hourly(): void {
		global $wpdb;
		$now = self::now();
		$t   = self::table();
		foreach ( self::with_status( 'pending', 500 ) as $row ) {
			if ( ! self::holds( $row, $now ) ) {
				self::update( $row['id'], array( 'status' => 'expired' ) );
			}
		}
		$cut = $now->modify( '-' . self::settings()['pay_hours'] . ' hours' )->format( 'Y-m-d H:i:s' );
		foreach ( self::with_status( 'approved', 500 ) as $row ) {
			if ( $row['updated'] < $cut && 0 === $row['order_id'] ) {
				$row = self::update( $row['id'], array( 'status' => 'expired' ) );
				if ( $row ) {
					Scout_Booking_Emails::expired( $row );
				}
			}
		}
		if ( ! self::settings()['reminders'] ) {
			return;
		}
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'confirmed' AND reminded IS NULL AND start_at BETWEEN %s AND %s", $now->format( 'Y-m-d H:i:s' ), $now->modify( '+24 hours' )->format( 'Y-m-d H:i:s' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$cut  = $now->modify( '-24 hours' )->format( 'Y-m-d H:i:s' );
		foreach ( $rows as $row ) {
			$row = self::decode( $row );
			// Mark first, so a slow mail server never sends the same reminder twice.
			$wpdb->update( $t, array( 'reminded' => current_time( 'mysql' ) ), array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			// Booked inside the last day: the confirmation was the reminder.
			if ( $row['created'] < $cut ) {
				Scout_Booking_Emails::reminder( $row );
			}
		}
	}

	/* ---------- privacy ---------- */

	public static function exporter( array $exporters ): array {
		$exporters['scout-booking'] = array(
			'exporter_friendly_name' => __( 'Bookings', 'scout-booking' ),
			'callback'               => array( __CLASS__, 'export_data' ),
		);
		return $exporters;
	}

	public static function export_data( string $email ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email = %s', strtolower( $email ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$data = array();
		foreach ( $rows as $r ) {
			$r      = self::decode( $r );
			$fields = array(
				array(
					'name'  => __( 'Session', 'scout-booking' ),
					'value' => $r['type_name'],
				),
				array(
					'name'  => __( 'When', 'scout-booking' ),
					'value' => self::when_label( $r ),
				),
				array(
					'name'  => __( 'Status', 'scout-booking' ),
					'value' => self::statuses()[ $r['status'] ] ?? $r['status'],
				),
				array(
					'name'  => __( 'Name', 'scout-booking' ),
					'value' => self::client_name( $r ),
				),
				array(
					'name'  => __( 'Phone', 'scout-booking' ),
					'value' => $r['phone'],
				),
				array(
					'name'  => __( 'Paid', 'scout-booking' ),
					'value' => $r['paid'] > 0 ? self::money( $r['paid'] ) : '',
				),
				array(
					'name'  => __( 'Order', 'scout-booking' ),
					'value' => $r['order_id'] ? '#' . $r['order_id'] : '',
				),
				array(
					'name'  => __( 'Newsletter box ticked', 'scout-booking' ),
					'value' => ! empty( $r['optin'] ) ? __( 'Yes', 'scout-booking' ) : '',
				),
			);
			foreach ( $r['answers'] as $a ) {
				$fields[] = array(
					'name'  => (string) ( $a['q'] ?? '' ),
					'value' => (string) ( $a['a'] ?? '' ),
				);
			}
			$data[] = array(
				'group_id'    => 'scout-booking',
				'group_label' => __( 'Bookings', 'scout-booking' ),
				'item_id'     => 'booking-' . $r['id'],
				'data'        => $fields,
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	public static function eraser( array $erasers ): array {
		$erasers['scout-booking'] = array(
			'eraser_friendly_name' => __( 'Bookings', 'scout-booking' ),
			'callback'             => array( __CLASS__, 'erase_data' ),
		);
		return $erasers;
	}

	/**
	 * Unpaid bookings are deleted. A booking that took money stays as the
	 * business's record of the payment (the session, time, amounts and order
	 * number) with the person's name, email, phone and answers taken out.
	 */
	public static function erase_data( string $email ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, paid, order_id FROM ' . self::table() . ' WHERE email = %s', strtolower( $email ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$kept = 0;
		foreach ( $rows as $r ) {
			if ( (float) $r['paid'] > 0 || (int) $r['order_id'] > 0 ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					self::table(),
					array(
						'first_name' => wp_privacy_anonymize_data( 'text' ),
						'last_name'  => '',
						'email'      => '',
						'phone'      => '',
						'answers'    => wp_json_encode( array() ),
					),
					array( 'id' => (int) $r['id'] )
				);
				++$kept;
			} else {
				$wpdb->delete( self::table(), array( 'id' => (int) $r['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		$messages = array();
		if ( $kept ) {
			/* translators: %d: number of paid bookings. */
			$messages[] = sprintf( _n( '%d paid booking was kept as a payment record, without the person’s name, email, phone or answers.', '%d paid bookings were kept as payment records, without the person’s name, email, phone or answers.', $kept, 'scout-booking' ), $kept );
		}
		return array(
			'items_removed'  => (bool) $rows,
			'items_retained' => $kept > 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
