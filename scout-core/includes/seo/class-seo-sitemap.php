<?php
/**
 * Sitemap handling. WordPress core already generates a valid XML sitemap at
 * /wp-sitemap.xml and lists it in robots.txt, so we do not rebuild it. We tune
 * it (noindexed pages stay out, so what we tell crawlers to index and what we
 * submit always agree) and guard it: if robots.txt ends up with no Sitemap
 * line (an SEO plugin switched core's off, or a leftover setting), we add the
 * one that works. Finding it happens in a daily background check, so serving
 * robots.txt never waits on a web request.
 *
 * @package Scout_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_SEO_Sitemap {

	const FOUND_OPTION = 'scout_seo_sitemap_found';
	const CRON         = 'scout_seo_sitemap_check';

	/** Where sitemaps usually live: Yoast, Rank Math / All in One SEO, WordPress core. */
	const CANDIDATES = array( '/sitemap_index.xml', '/sitemap.xml', '/wp-sitemap.xml' );

	public static function init() {
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'exclude_noindex' ), 10, 2 );
		// After every SEO plugin has had its say, just before AI Search's Content-Signal line.
		add_filter( 'robots_txt', array( __CLASS__, 'guard_robots' ), PHP_INT_MAX - 1, 2 );
		add_action( self::CRON, array( __CLASS__, 'find' ) );
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	/** Keep the daily check scheduled (activation hooks do not run on updates). */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 60, 'daily', self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** Add the found sitemap when robots.txt names none. Never guesses. */
	public static function guard_robots( $output, $public ) {
		$output = (string) $output;
		if ( ! $public || preg_match( '/^\s*sitemap\s*:/im', $output ) ) {
			return $output;
		}
		$found = get_option( self::FOUND_OPTION, array() );
		if ( empty( $found['url'] ) ) {
			return $output;
		}
		return rtrim( $output ) . "\n\nSitemap: " . esc_url_raw( $found['url'] ) . "\n";
	}

	/**
	 * Ask the public site which sitemap answers, and remember the first.
	 *
	 * @return array{url:string, checked:int}
	 */
	public static function find() {
		$home  = untrailingslashit( home_url() );
		$found = array( 'url' => '', 'checked' => time() );
		foreach ( self::CANDIDATES as $path ) {
			$res = wp_remote_head( $home . $path, array( 'timeout' => 8, 'redirection' => 2 ) );
			if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
				$found['url'] = $home . $path;
				break;
			}
		}
		update_option( self::FOUND_OPTION, $found, false );
		return $found;
	}

	/**
	 * Drop any post flagged noindex from the core sitemap query.
	 */
	public static function exclude_noindex( $args, $post_type ) {
		$meta_query   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();
		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => SCOUT_SEO_META_NOINDEX,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => SCOUT_SEO_META_NOINDEX,
				'value'   => '1',
				'compare' => '!=',
			),
		);
		$args['meta_query'] = $meta_query;
		return $args;
	}
}
