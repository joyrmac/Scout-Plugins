<?php
/**
 * Reads reviews and platform totals for display.
 *
 * Results are cached in the object cache and invalidated whenever a review or
 * the settings change, so a page full of review blocks costs one query.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Reviews_Query {

	const CACHE_GROUP = 'scout_reviews';

	public static function boot(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'maybe_flush' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
	}

	public static function maybe_flush( $new_status, $old_status, $post ): void {
		if ( $post instanceof WP_Post && Scout_Reviews_Post_Type::TYPE === $post->post_type ) {
			self::flush();
		}
	}

	public static function flush(): void {
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	/**
	 * Published reviews, in display order.
	 *
	 * @param array $args {
	 *     @type int    $count      How many to return. 0 means all.
	 *     @type string $source     Only this source slug. Empty means all.
	 *     @type bool   $featured   Only reviews marked featured.
	 *     @type int    $min_rating Only reviews at or above this many stars
	 *                              (reviews without stars are left out when set).
	 * }
	 * @return array<int, array> Normalized reviews (see self::normalize()).
	 */
	public static function reviews( array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'count'      => 0,
				'source'     => '',
				'featured'   => false,
				'min_rating' => 0,
			)
		);

		$key    = 'reviews:' . md5( wp_json_encode( $args ) ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$meta_query = array();
		if ( $args['source'] ) {
			$meta_query[] = array(
				'key'   => Scout_Reviews_Post_Type::META_SOURCE,
				'value' => sanitize_key( $args['source'] ),
			);
		}
		if ( $args['featured'] ) {
			$meta_query[] = array(
				'key'   => Scout_Reviews_Post_Type::META_FEATURED,
				'value' => '1',
			);
		}
		if ( (int) $args['min_rating'] > 0 ) {
			$meta_query[] = array(
				'key'     => Scout_Reviews_Post_Type::META_RATING,
				'value'   => (int) $args['min_rating'],
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => Scout_Reviews_Post_Type::TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => (int) $args['count'] > 0 ? (int) $args['count'] : 100,
				'orderby'                => array( 'menu_order' => 'ASC', 'date' => 'DESC' ),
				'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery -- small, cached.
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			)
		);

		$reviews = array_map( array( __CLASS__, 'normalize' ), $query->posts );
		wp_cache_set( $key, $reviews, self::CACHE_GROUP );
		return $reviews;
	}

	/**
	 * One review as plain data, ready for a template.
	 */
	public static function normalize( WP_Post $post ): array {
		$source = (string) get_post_meta( $post->ID, Scout_Reviews_Post_Type::META_SOURCE, true );
		$photo  = get_the_post_thumbnail_url( $post, 'thumbnail' );

		return array(
			'id'     => $post->ID,
			'name'   => get_the_title( $post ),
			'text'   => trim( wp_strip_all_tags( $post->post_content ) ),
			'rating' => (int) get_post_meta( $post->ID, Scout_Reviews_Post_Type::META_RATING, true ),
			'source' => Scout_Reviews_Sources::exists( $source ) ? $source : 'direct',
			'url'    => (string) get_post_meta( $post->ID, Scout_Reviews_Post_Type::META_URL, true ),
			'date'   => (string) get_post_meta( $post->ID, Scout_Reviews_Post_Type::META_DATE, true ),
			'detail' => (string) get_post_meta( $post->ID, Scout_Reviews_Post_Type::META_DETAIL, true ),
			'photo'  => $photo ? $photo : '',
		);
	}

	/**
	 * Platform totals for the rating summary, from Reviews > Settings.
	 *
	 * @param string $source Only this source. Empty means every platform with a count.
	 * @return array<int, array{source:string, rating:float, count:int, url:string, write_url:string}>
	 */
	public static function totals( string $source = '' ): array {
		$out = array();
		foreach ( Scout_Reviews_Sources::all() as $slug => $info ) {
			if ( $source && $slug !== $source ) {
				continue;
			}
			$profile = Scout_Reviews_Settings::profile( $slug );
			if ( $profile['count'] < 1 ) {
				continue;
			}
			$out[] = array( 'source' => $slug ) + $profile;
		}

		// Biggest platform first: it is the number visitors trust most.
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		return $out;
	}
}

Scout_Reviews_Query::boot();
