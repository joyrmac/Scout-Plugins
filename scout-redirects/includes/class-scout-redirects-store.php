<?php
/**
 * Storage for redirects and the missing-page log.
 *
 * Two small custom tables rather than one option. A redirect is looked up on
 * every front-end request and its hit count is written each time it is used.
 * In an option, every hit would rewrite (and autoload) the whole list, which
 * gets slow and racy once a rebuild imports a few hundred old addresses. A
 * table gives a single indexed lookup by a hash of the old path and a
 * one-row update per hit. The missing-page log works the same way and is
 * capped, so it never grows past a few hundred rows.
 *
 * @package Scout_Redirects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Redirects_Store {

	const DB_VERSION   = '1';
	const DB_OPTION    = 'scout_redirects_db';
	const COUNT_OPTION = 'scout_redirects_count';
	const CACHE_GROUP  = 'scout_redirects';
	const CACHE_OPTION = 'scout_redirects_cache_v';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'scout_redirects';
	}

	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'scout_404_log';
	}

	/** Create or update both tables. Runs on activation and after an update. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		$log     = self::log_table();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(255) NOT NULL,
			source_hash char(32) NOT NULL,
			target text NOT NULL,
			type smallint(3) unsigned NOT NULL DEFAULT 301,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime DEFAULT NULL,
			origin varchar(20) NOT NULL DEFAULT 'manual',
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_hash (source_hash)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			path varchar(255) NOT NULL,
			path_hash char(32) NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 1,
			referrer varchar(255) NOT NULL DEFAULT '',
			internal tinyint(1) unsigned NOT NULL DEFAULT 0,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY path_hash (path_hash),
			KEY last_seen (last_seen)
			) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		self::refresh_count();
	}

	/** Install after an update that skipped the activation hook. */
	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	private static function hash( string $path ): string {
		return md5( $path );
	}

	/**
	 * Cache key for one lookup. It carries a version number that every change
	 * bumps, so a change takes effect at once on every object cache. Group
	 * flushing alone is not enough: WP Engine's cache does not support it, and
	 * a remembered "no redirect here" then hid a new redirect for an hour.
	 */
	private static function cache_key( string $hash ): string {
		return (int) get_option( self::CACHE_OPTION, 0 ) . ':' . $hash;
	}

	/** Forget every remembered lookup. */
	private static function forget(): void {
		update_option( self::CACHE_OPTION, (int) get_option( self::CACHE_OPTION, 0 ) + 1, true );
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}
	}

	/* ---------------------------------------------------------------- */
	/* Redirects                                                         */
	/* ---------------------------------------------------------------- */

	/** How many redirects exist. Autoloaded so a site with none skips every lookup. */
	public static function count(): int {
		return (int) get_option( self::COUNT_OPTION, 0 );
	}

	public static function refresh_count(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		update_option( self::COUNT_OPTION, $n, true );
		self::forget();
	}

	/** The redirect for a normalized old path, or null. */
	public static function find( string $source ): ?object {
		global $wpdb;
		$hash  = self::hash( $source );
		$found = wp_cache_get( self::cache_key( $hash ), self::CACHE_GROUP, false, $hit );
		if ( $hit ) {
			return $found ? $found : null;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_hash = %s", $hash ) );
		wp_cache_set( self::cache_key( $hash ), $row ? $row : 0, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $row ? $row : null;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		return $row ? $row : null;
	}

	/**
	 * A page of redirects, most used first.
	 *
	 * @param string $search Part of an old or new address.
	 */
	public static function page( int $per_page, int $offset, string $search = '' ): array {
		global $wpdb;
		$table = self::table();
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source LIKE %s OR target LIKE %s ORDER BY hits DESC, id DESC LIMIT %d OFFSET %d", $like, $like, $per_page, $offset ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY hits DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
	}

	public static function search_count( string $search ): int {
		global $wpdb;
		$table = self::table();
		$like  = '%' . $wpdb->esc_like( $search ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source LIKE %s OR target LIKE %s", $like, $like ) );
	}

	/** Every redirect, for the CSV export. */
	public static function all(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY source ASC" );
	}

	/**
	 * Add a redirect, or update the one with the same old path (or this id).
	 *
	 * Inputs are already normalized by Scout_Redirects.
	 *
	 * @return int The redirect's id.
	 */
	public static function save( string $source, string $target, int $type, string $origin = 'manual', int $id = 0 ): int {
		global $wpdb;
		$table = self::table();
		$data  = array(
			'source'      => $source,
			'source_hash' => self::hash( $source ),
			'target'      => $target,
			'type'        => $type,
		);
		$existing = self::find( $source );
		if ( $existing && (int) $existing->id !== $id && $id ) {
			// Editing one row onto another row's old path: the edited row wins.
			self::delete( (int) $existing->id );
			$existing = null;
		}
		if ( ! $id && $existing ) {
			$id = (int) $existing->id;
		}
		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$data['origin']  = $origin;
			$data['created'] = self::now();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}
		self::refresh_count();
		self::log_delete_path( $source );
		return $id;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'id' => $id ) );
		self::refresh_count();
	}

	public static function delete_source( string $source ): void {
		$row = self::find( $source );
		if ( $row ) {
			self::delete( (int) $row->id );
		}
	}

	/** Point every redirect aimed at one internal path at another, so chains stay one hop. */
	public static function retarget( string $from_path, string $to_path ): void {
		global $wpdb;
		$table = self::table();
		foreach ( array( $from_path, trailingslashit( $from_path ) ) as $old ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $table, array( 'target' => $to_path ), array( 'target' => $old ) );
		}
		self::forget();
	}

	/** Count one use of a redirect. */
	public static function hit( object $row ): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", self::now(), (int) $row->id ) );
		wp_cache_delete( self::cache_key( (string) $row->source_hash ), self::CACHE_GROUP );
	}

	/* ---------------------------------------------------------------- */
	/* Missing pages                                                     */
	/* ---------------------------------------------------------------- */

	/** Record one request for a missing page, keeping the log under its cap. */
	public static function log_404( string $path, string $referrer, bool $internal, int $cap ): void {
		global $wpdb;
		$log  = self::log_table();
		$hash = self::hash( $path );
		$now  = self::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$log} WHERE path_hash = %s", $hash ) );
		if ( $id ) {
			if ( '' !== $referrer ) {
				// Once a missing page is linked from the site, it stays flagged and
				// keeps that page as its referrer, so the broken link can be found.
				$set = $internal ? 'referrer = %s, internal = 1' : 'referrer = CASE WHEN internal = 1 THEN referrer ELSE %s END';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$log} SET hits = hits + 1, last_seen = %s, {$set} WHERE id = %d", $now, $referrer, $id ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$log} SET hits = hits + 1, last_seen = %s WHERE id = %d", $now, $id ) );
			}
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$log,
			array(
				'path'       => $path,
				'path_hash'  => $hash,
				'hits'       => 1,
				'referrer'   => $referrer,
				'internal'   => $internal ? 1 : 0,
				'first_seen' => $now,
				'last_seen'  => $now,
			)
		);
		self::log_trim( $cap );
	}

	/** Drop the oldest, least visited rows once the log passes its cap. */
	public static function log_trim( int $cap ): void {
		global $wpdb;
		$log = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log}" );
		if ( $total <= $cap ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$log} ORDER BY last_seen ASC, hits ASC LIMIT %d", $total - $cap ) ) );
		if ( $ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$log} WHERE id IN (" . implode( ',', $ids ) . ')' );
		}
	}

	/**
	 * Missing pages, most visited first.
	 *
	 * @param bool $internal_only Only the ones linked from the site's own pages.
	 */
	public static function log_list( int $limit, bool $internal_only = false ): array {
		global $wpdb;
		$log   = self::log_table();
		$where = $internal_only ? 'WHERE internal = 1' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$log} {$where} ORDER BY hits DESC, last_seen DESC LIMIT %d", $limit ) );
	}

	public static function log_get( int $id ): ?object {
		global $wpdb;
		$log = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$log} WHERE id = %d", $id ) );
		return $row ? $row : null;
	}

	/** Distinct missing pages seen since a time (GMT, Y-m-d H:i:s). */
	public static function log_count_since( string $since, bool $internal_only = false ): int {
		global $wpdb;
		$log = self::log_table();
		$and = $internal_only ? ' AND internal = 1' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE last_seen >= %s{$and}", $since ) );
	}

	public static function log_delete( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::log_table(), array( 'id' => $id ) );
	}

	public static function log_delete_path( string $path ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::log_table(), array( 'path_hash' => self::hash( $path ) ) );
	}

	public static function log_clear(): void {
		global $wpdb;
		$log = self::log_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$log}" );
	}
}
