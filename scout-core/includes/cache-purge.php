<?php
/**
 * Purge the host's page cache after the site's look changes (Site Standard gap 5).
 *
 * When the active theme (or its parent) or any Scout plugin is updated or
 * replaced, cached pages still point at the old style and script versions
 * until someone purges by hand. Core now purges WP Engine's caches (and WP
 * Rocket's, where a site runs it) right after the update. Bliss Boogie's theme
 * did this for itself; every site gets it now.
 *
 * Cloudflare is not purged: that needs the site's Cloudflare key, which a
 * plugin never stores. Sites behind Cloudflare still purge it by hand.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Cache {

	const LAST = 'scout_core_cache_purged'; // { at, why, hosts[] }.

	public static function init() {
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 20, 2 );
	}

	/**
	 * What an update touched that changes how pages look, or '' for nothing.
	 *
	 * @param array  $extra      The upgrader's hook_extra.
	 * @param string $installed  The folder an upload installed into (theme or plugin), when known.
	 * @param string $stylesheet The active theme's folder.
	 * @param string $template   The active parent theme's folder.
	 */
	public static function reason( array $extra, string $installed, string $stylesheet, string $template ) {
		$type   = (string) ( $extra['type'] ?? '' );
		$action = (string) ( $extra['action'] ?? '' );
		if ( 'theme' === $type ) {
			$themes = array_map( 'strval', (array) ( $extra['themes'] ?? array() ) );
			if ( isset( $extra['theme'] ) ) {
				$themes[] = (string) $extra['theme'];
			}
			if ( 'install' === $action && '' !== $installed ) {
				$themes[] = $installed;
			}
			return array_intersect( $themes, array( $stylesheet, $template ) ) ? 'theme' : '';
		}
		if ( 'plugin' === $type ) {
			$plugins = array_map( 'strval', (array) ( $extra['plugins'] ?? array() ) );
			if ( isset( $extra['plugin'] ) ) {
				$plugins[] = (string) $extra['plugin'];
			}
			if ( 'install' === $action && '' !== $installed ) {
				$plugins[] = $installed . '/';
			}
			foreach ( $plugins as $file ) {
				if ( 0 === strpos( $file, 'scout-' ) ) {
					return 'plugin';
				}
			}
		}
		return '';
	}

	public static function after_upgrade( $upgrader, $extra ) {
		$installed = '';
		if ( is_object( $upgrader ) && isset( $upgrader->result ) && is_array( $upgrader->result ) ) {
			$installed = (string) ( $upgrader->result['destination_name'] ?? '' );
		}
		$why = self::reason( is_array( $extra ) ? $extra : array(), $installed, (string) get_stylesheet(), (string) get_template() );
		if ( '' !== $why && (bool) apply_filters( 'scout_core_purge_after_update', true, $why ) ) {
			self::purge( $why );
		}
	}

	/** Purge every page cache this plugin can reach. Returns the names of the caches purged. */
	public static function purge( string $why = 'manual' ) {
		$hosts = array();
		if ( class_exists( 'WpeCommon' ) ) {
			foreach ( array( 'purge_memcached', 'purge_varnish_cache' ) as $method ) {
				if ( method_exists( 'WpeCommon', $method ) ) {
					call_user_func( array( 'WpeCommon', $method ) );
				}
			}
			$hosts[] = 'WP Engine';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$hosts[] = 'WP Rocket';
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		update_option(
			self::LAST,
			array(
				'at'    => time(),
				'why'   => $why,
				'hosts' => $hosts,
			),
			false
		);
		do_action( 'scout_core_caches_purged', $hosts, $why );
		return $hosts;
	}
}
