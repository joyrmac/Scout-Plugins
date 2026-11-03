<?php
/**
 * The consent records, which double as the send queue.
 *
 * One row per agreement: who (email, first name), when (created, UTC), where
 * (source, page, campaign) and the exact words shown next to the box. That is
 * the record CAN-SPAM and a careful owner want. The same row carries how the
 * send went: held (not connected or switched off), queued, retry, sent,
 * skipped (unsubscribed or invalid) or failed.
 *
 * "update" rows are not agreements: they keep an existing subscriber's tags or
 * member plan current and never subscribe anyone.
 *
 * @package Scout_Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Newsletter_Store {

	const DB_VERSION = '1';
	const DB_OPTION  = 'scout_newsletter_db';

	/** Statuses that still have work to do. */
	const OPEN = array( 'queued', 'retry' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'scout_newsletter';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(10) NOT NULL DEFAULT 'optin',
				email varchar(190) NOT NULL DEFAULT '',
				first_name varchar(100) NOT NULL DEFAULT '',
				source varchar(40) NOT NULL DEFAULT '',
				tags text NULL,
				fields text NULL,
				wording text NULL,
				url varchar(255) NOT NULL DEFAULT '',
				campaign varchar(100) NOT NULL DEFAULT '',
				created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				status varchar(20) NOT NULL DEFAULT 'queued',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_try datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				sub_id varchar(64) NOT NULL DEFAULT '',
				result varchar(100) NOT NULL DEFAULT '',
				updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY status_next (status,next_try),
				KEY email (email)
			) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/** Make the table on first use after an update that skipped activation. */
	public static function ensure(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Save a row. A second tick of the same box within ten minutes (a double
	 * click, two plugins reporting the same checkout) joins the first row
	 * instead of making a second send.
	 */
	public static function add( array $row ): int {
		global $wpdb;
		self::ensure();
		$now = self::now();
		$t   = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$same = $wpdb->get_row( $wpdb->prepare( "SELECT id, tags, fields FROM {$t} WHERE kind = %s AND email = %s AND source = %s AND status IN ('held','queued','retry') AND created >= %s ORDER BY id DESC LIMIT 1", $row['kind'], $row['email'], $row['source'] ?? '', gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ), ARRAY_A );
		if ( $same ) {
			$tags   = array_values( array_unique( array_merge( self::json( $same['tags'] ), (array) ( $row['tags'] ?? array() ) ) ) );
			$fields = array_merge( self::json( $same['fields'] ), (array) ( $row['fields'] ?? array() ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $t, array( 'tags' => wp_json_encode( $tags ), 'fields' => wp_json_encode( $fields ), 'updated' => $now ), array( 'id' => (int) $same['id'] ) );
			return (int) $same['id'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			$t,
			array(
				'kind'       => (string) $row['kind'],
				'email'      => (string) $row['email'],
				'first_name' => (string) ( $row['first_name'] ?? '' ),
				'source'     => (string) ( $row['source'] ?? '' ),
				'tags'       => wp_json_encode( array_values( (array) ( $row['tags'] ?? array() ) ) ),
				'fields'     => wp_json_encode( (array) ( $row['fields'] ?? array() ) ),
				'wording'    => (string) ( $row['wording'] ?? '' ),
				'url'        => substr( (string) ( $row['url'] ?? '' ), 0, 255 ),
				'campaign'   => substr( (string) ( $row['campaign'] ?? '' ), 0, 100 ),
				'created'    => $now,
				'status'     => (string) ( $row['status'] ?? 'queued' ),
				'next_try'   => $now,
				'updated'    => $now,
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	/** Rows due now, oldest first. */
	public static function due( int $limit = 20 ): array {
		global $wpdb;
		self::ensure();
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status IN ('queued','retry') AND next_try <= %s ORDER BY id ASC LIMIT %d", self::now(), $limit ), ARRAY_A );
		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	/** When the next waiting row is due (Unix time), or 0. */
	public static function next_due(): int {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next = $wpdb->get_var( "SELECT MIN(next_try) FROM {$t} WHERE status IN ('queued','retry')" );
		return $next ? (int) strtotime( $next . ' UTC' ) : 0;
	}

	public static function set( int $id, array $changes ): void {
		global $wpdb;
		$changes['updated'] = self::now();
		if ( isset( $changes['tags'] ) ) {
			$changes['tags'] = wp_json_encode( array_values( (array) $changes['tags'] ) );
		}
		if ( isset( $changes['result'] ) ) {
			$changes['result'] = substr( (string) $changes['result'], 0, 100 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), $changes, array( 'id' => $id ) );
	}

	/** Held sign-ups go into the queue (the owner pressed Send). */
	public static function release_held(): int {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'queued', next_try = %s, updated = %s WHERE status = 'held'", self::now(), self::now() ) );
	}

	/** Counts by status, for consent records only. */
	public static function counts(): array {
		global $wpdb;
		self::ensure();
		$t   = self::table();
		$out = array_fill_keys( array( 'held', 'queued', 'retry', 'sent', 'skipped', 'failed' ), 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$t} WHERE kind = 'optin' GROUP BY status", ARRAY_A ) as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** The newest consent records. */
	public static function recent( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		self::ensure();
		$t = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE kind = 'optin' ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		return array_map( array( __CLASS__, 'shape' ), (array) $rows );
	}

	private static function shape( array $row ): array {
		$row['id']       = (int) $row['id'];
		$row['attempts'] = (int) $row['attempts'];
		$row['tags']     = self::json( $row['tags'] );
		$row['fields']   = self::json( $row['fields'] );
		return $row;
	}

	private static function json( $raw ): array {
		$v = json_decode( (string) $raw, true );
		return is_array( $v ) ? $v : array();
	}
}
