<?php
/**
 * WordPress's own Site Health, run by Scout so nobody has to open
 * Tools > Site Health as well (Joy, 2026-11-05: "i dont want to have to click
 * into wordpress's site health and then also our own every single time").
 *
 * Once a day, with Scout Build's daily check, this runs every WordPress Site
 * Health test the way WordPress's own weekly check does, keeps the items that
 * want a look, and updates WordPress's own count so its badge agrees. Scout's
 * Site health then lists those items by name, and marks the ones only the host
 * can change, so they stop asking for attention.
 *
 * @package Scout_Build
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hub_WP_Health {

	const OPTION = 'scout_hub_wp_health'; // { at, items: [ { test, label, status, host } ] }.
	const HOOK   = 'scout_hub_wp_health_run';

	/** Tests only the host can change. */
	const HOST_TESTS = array( 'available_updates_disk_space', 'opcode_cache', 'persistent_object_cache', 'page_cache', 'php_extensions', 'php_default_timezone', 'sql_server', 'php_version', 'utf8mb4_support', 'background_updates', 'loopback_requests', 'http_requests' );

	/** Labels only the host can change, for tests whose key is not known. */
	const HOST_WORDS = '/disk space|opcode|object cache|page cache|php extension|php module|server/i';

	public static function boot(): void {
		add_action( Scout_Hub_Watch::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	/** The last result, or null before the first run. */
	public static function last(): ?array {
		$saved = get_option( self::OPTION, null );
		return is_array( $saved ) && isset( $saved['items'] ) ? $saved : null;
	}

	/** Ask for a run in the background (used when nothing has run yet). */
	public static function soon(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}
	}

	/** Run every test WordPress's own scheduled check runs, and keep what wants a look. */
	public static function run(): void {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			$file = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
			if ( ! is_readable( $file ) ) {
				return;
			}
			require_once ABSPATH . 'wp-admin/includes/admin.php';
			require_once $file;
		}
		$health  = WP_Site_Health::get_instance();
		$tests   = WP_Site_Health::get_tests();
		$results = array();
		foreach ( (array) ( $tests['direct'] ?? array() ) as $key => $test ) {
			if ( ! empty( $test['skip_cron'] ) ) {
				continue;
			}
			$call = null;
			if ( is_string( $test['test'] ?? null ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
				$call = array( $health, 'get_test_' . $test['test'] );
			} elseif ( is_callable( $test['test'] ?? null ) ) {
				$call = $test['test'];
			}
			if ( $call ) {
				$results[] = self::perform( $call, (string) $key );
			}
		}
		foreach ( (array) ( $tests['async'] ?? array() ) as $key => $test ) {
			if ( ! empty( $test['skip_cron'] ) ) {
				continue;
			}
			// Only tests WordPress can run without calling its own address over the network.
			if ( ! empty( $test['async_direct_test'] ) && is_callable( $test['async_direct_test'] ) ) {
				$results[] = self::perform( $test['async_direct_test'], (string) $key );
			}
		}
		$results = array_values( array_filter( $results ) );
		$count   = array( 'good' => 0, 'recommended' => 0, 'critical' => 0 );
		$items   = array();
		foreach ( $results as $r ) {
			$status = in_array( $r['status'], array( 'critical', 'recommended' ), true ) ? $r['status'] : 'good';
			++$count[ $status ];
			if ( 'good' !== $status ) {
				$items[] = array(
					'test'   => $r['test'],
					'label'  => $r['label'],
					'status' => $status,
					'host'   => self::host_only( $r['test'], $r['label'] ),
				);
			}
		}
		update_option( self::OPTION, array( 'at' => time(), 'items' => $items ), false );
		// WordPress's own badge and Dashboard widget read this; keep them in step.
		set_transient( 'health-check-site-status-result', wp_json_encode( $count ) );
	}

	/** One test, as WordPress's own check runs it. */
	private static function perform( $call, string $key ): ?array {
		try {
			$r = call_user_func( $call );
		} catch ( Throwable $e ) {
			return null;
		}
		if ( ! is_array( $r ) || empty( $r['status'] ) ) {
			return null;
		}
		/** This filter is documented in wp-admin/includes/class-wp-site-health.php */
		$r = (array) apply_filters( 'site_status_test_result', $r );
		return array(
			'test'   => (string) ( $r['test'] ?? $key ),
			'label'  => wp_strip_all_tags( (string) ( $r['label'] ?? $key ) ),
			'status' => (string) $r['status'],
		);
	}

	/** True when only the host can act on this item. */
	public static function host_only( string $test, string $label ): bool {
		return in_array( $test, self::HOST_TESTS, true ) || (bool) preg_match( self::HOST_WORDS, $label );
	}

	/** Plain advice for the items people can act on, keyed by test. */
	public static function advice( string $test ): string {
		$map = array(
			'plugin_version'   => __( 'Delete plugins that are switched off, on the Plugins screen. Features can put a Scout one back.', 'scout-build' ),
			'theme_version'    => __( 'Delete themes you do not use, on Appearance, Themes. Keep the active theme and its parent.', 'scout-build' ),
			'wordpress_version' => __( 'Update WordPress on Dashboard, Updates.', 'scout-build' ),
		);
		return $map[ $test ] ?? '';
	}

	/**
	 * The row for Scout's Site health.
	 *
	 * @return array{label:string, value:string, state:string, note:string, link?:string, link_text?:string}
	 */
	public static function row(): array {
		$last = self::last();
		if ( ! $last ) {
			self::soon();
			return array(
				'label' => __( 'WordPress checks', 'scout-build' ),
				'value' => __( 'Running now', 'scout-build' ),
				'state' => 'good',
				'note'  => __( 'Scout runs WordPress\'s own Site Health checks once a day. The first run is under way; reload in a minute.', 'scout-build' ),
			);
		}
		$act  = array_values( array_filter( $last['items'], static fn( $i ) => ! $i['host'] ) );
		$host = array_values( array_filter( $last['items'], static fn( $i ) => $i['host'] ) );
		$crit = count( array_filter( $act, static fn( $i ) => 'critical' === $i['status'] ) );
		$note = array();
		foreach ( $act as $i ) {
			$tip    = self::advice( $i['test'] );
			$note[] = $i['label'] . '.' . ( '' !== $tip ? ' ' . $tip : '' );
		}
		if ( $host ) {
			/* translators: %s: list of items */
			$note[] = sprintf( __( 'For the host, nothing to do here: %s.', 'scout-build' ), implode( '; ', wp_list_pluck( $host, 'label' ) ) );
		}
		/* translators: %s: time since, e.g. "3 hours" */
		$note[] = sprintf( __( 'Checked %s ago.', 'scout-build' ), human_time_diff( (int) $last['at'] ) );
		return array(
			'label'     => __( 'WordPress checks', 'scout-build' ),
			/* translators: %d: number of items */
			'value'     => $act ? sprintf( _n( '%d to look at', '%d to look at', count( $act ), 'scout-build' ), count( $act ) ) : __( 'All clear', 'scout-build' ),
			'state'     => $crit ? 'bad' : ( $act ? 'warn' : 'good' ),
			'note'      => implode( ' ', $note ),
			'link'      => admin_url( 'site-health.php' ),
			'link_text' => __( 'Details', 'scout-build' ),
		);
	}
}
