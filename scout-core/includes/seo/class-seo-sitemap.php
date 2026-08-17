<?php
/**
 * Sitemap handling. WordPress core already generates a valid XML sitemap at
 * /wp-sitemap.xml and lists it in robots.txt, so we do not rebuild it. We only
 * tune it: keep noindexed pages out of the sitemap, so what we tell crawlers to
 * index and what we submit always agree.
 *
 * @package Scout_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_SEO_Sitemap {

	public static function init() {
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'exclude_noindex' ), 10, 2 );
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
