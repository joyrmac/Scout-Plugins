<?php
/**
 * Builds and prints a single JSON-LD @graph from Scout Core data.
 *
 * The graph is one connected entity model, not a pile of disconnected blobs:
 *
 *   #business  LocalBusiness (or the subtype set in Scout Business) — the NAP,
 *              geo, profiles, and an aggregateRating computed from real
 *              testimonials. Stable @id: home_url( '/#business' ).
 *   #website   WebSite, publisher -> #business.
 *   FAQPage    on the FAQ hub page / archive, from the FAQ post type.
 *   Review     from testimonials, itemReviewed -> #business.
 *   BlogPosting on single posts, publisher -> #business.
 *
 * Everything references #business by @id, which is what makes Google (and AI
 * engines) read it as one entity. The @ids are derived from home_url() and
 * never change.
 *
 * @package Scout_Schema
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Schema {

	const CACHE_KEY = 'scout_schema_reviews';

	/**
	 * Print the graph in the document head.
	 */
	public static function render() {
		if ( is_admin() || is_feed() || ! function_exists( 'scout_core_business' ) ) {
			return;
		}

		$graph = apply_filters( 'scout_schema_graph', self::build_graph() );
		if ( empty( $graph ) ) {
			return;
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $graph ),
		);

		echo "\n<script type=\"application/ld+json\">"
			. wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. "</script>\n";
	}

	/**
	 * Assemble the graph nodes relevant to the current request.
	 *
	 * @return array<string,array>
	 */
	public static function build_graph() {
		$graph = array(
			'business' => self::business_node(),
			'website'  => self::website_node(),
		);

		list( $aggregate, $reviews ) = self::reviews();

		$aggregate = apply_filters( 'scout_schema_aggregate_rating', $aggregate );
		if ( ! empty( $aggregate ) ) {
			$graph['business']['aggregateRating'] = $aggregate;
		}
		foreach ( $reviews as $i => $review ) {
			$graph[ 'review_' . $i ] = $review;
		}

		if ( self::is_faq_context() ) {
			$faq = self::faq_node();
			if ( $faq ) {
				$graph['faqpage'] = $faq;
			}
		}

		if ( is_singular( 'post' ) ) {
			$article = self::blogposting_node();
			if ( $article ) {
				$graph['article'] = $article;
			}
		}

		return $graph;
	}

	/**
	 * The business entity, built from Settings -> Scout Business.
	 */
	private static function business_node() {
		$b    = scout_core_business();
		$type = ( '' !== $b['business_type'] ) ? $b['business_type'] : 'LocalBusiness';

		$node = array(
			'@type' => $type,
			'@id'   => home_url( '/#business' ),
			'name'  => ( '' !== $b['name'] ) ? $b['name'] : get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		if ( '' !== $b['legal_name'] ) {
			$node['legalName'] = $b['legal_name'];
		}
		if ( '' !== $b['phone'] ) {
			$node['telephone'] = $b['phone'];
		}
		if ( '' !== $b['email'] ) {
			$node['email'] = $b['email'];
		}
		if ( '' !== $b['price_range'] ) {
			$node['priceRange'] = $b['price_range'];
		}

		$address = array_filter(
			array(
				'streetAddress'   => $b['street'],
				'addressLocality' => $b['city'],
				'addressRegion'   => $b['region'],
				'postalCode'      => $b['postal'],
				'addressCountry'  => $b['country'],
			),
			static function ( $value ) {
				return '' !== $value;
			}
		);
		if ( $address ) {
			$node['address'] = array_merge( array( '@type' => 'PostalAddress' ), $address );
		}

		if ( '' !== $b['latitude'] && '' !== $b['longitude'] ) {
			$node['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $b['latitude'],
				'longitude' => $b['longitude'],
			);
		}

		$same_as = self::lines( $b['same_as'] );
		if ( $same_as ) {
			$node['sameAs'] = $same_as;
		}

		$logo = get_site_icon_url();
		if ( $logo ) {
			$node['logo'] = $logo;
		}

		return $node;
	}

	private static function website_node() {
		return array(
			'@type'           => 'WebSite',
			'@id'             => home_url( '/#website' ),
			'url'             => home_url( '/' ),
			'name'            => get_bloginfo( 'name' ),
			'publisher'       => array( '@id' => home_url( '/#business' ) ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		);
	}

	/**
	 * Compute the aggregate rating and review nodes from testimonials.
	 * Cached for an hour; cleared on testimonial save/delete.
	 *
	 * @return array{0:?array,1:array}
	 */
	public static function reviews() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$limit   = (int) apply_filters( 'scout_schema_review_limit', 10 );
		$ratings = array();
		$reviews = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'testimonial',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $post ) {
			$rating = get_post_meta( $post->ID, scout_core_meta_key( 'rating' ), true );
			if ( is_numeric( $rating ) ) {
				$ratings[] = (float) $rating;
			}

			if ( count( $reviews ) >= $limit ) {
				continue;
			}

			$node = array(
				'@type'        => 'Review',
				'@id'          => home_url( '/#review-' . $post->ID ),
				'itemReviewed' => array( '@id' => home_url( '/#business' ) ),
				'author'       => array(
					'@type' => 'Person',
					'name'  => get_the_title( $post ),
				),
			);

			$body = trim( wp_strip_all_tags( $post->post_content ) );
			if ( '' !== $body ) {
				$node['reviewBody'] = $body;
			}
			if ( is_numeric( $rating ) ) {
				$node['reviewRating'] = array(
					'@type'       => 'Rating',
					'ratingValue' => (string) ( 0 + $rating ),
					'bestRating'  => '5',
				);
			}
			$date = get_post_meta( $post->ID, scout_core_meta_key( 'date' ), true );
			if ( '' !== $date ) {
				$node['datePublished'] = $date;
			}

			$reviews[] = $node;
		}
		wp_reset_postdata();

		$aggregate = null;
		if ( $ratings ) {
			$aggregate = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) round( array_sum( $ratings ) / count( $ratings ), 1 ),
				'reviewCount' => (string) count( $ratings ),
				'bestRating'  => '5',
			);
		}

		$result = array( $aggregate, $reviews );
		set_transient( self::CACHE_KEY, $result, HOUR_IN_SECONDS );
		return $result;
	}

	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Is this the FAQ hub? Defaults to the FAQ archive or a page slugged "faq".
	 * Filter `scout_schema_is_faq_page` to mark any page as the hub.
	 */
	private static function is_faq_context() {
		$is_faq = is_post_type_archive( 'faq' )
			|| ( is_page() && 'faq' === get_post_field( 'post_name', get_queried_object_id() ) );

		return (bool) apply_filters( 'scout_schema_is_faq_page', $is_faq );
	}

	private static function faq_node() {
		$query = new WP_Query(
			array(
				'post_type'      => 'faq',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);

		$main = array();
		foreach ( $query->posts as $post ) {
			$answer = trim( wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) ) );
			if ( '' === $answer ) {
				continue;
			}
			$main[] = array(
				'@type'          => 'Question',
				'name'           => get_the_title( $post ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			);
		}
		wp_reset_postdata();

		if ( ! $main ) {
			return null;
		}

		return array(
			'@type'      => 'FAQPage',
			'@id'        => self::current_url() . '#faqpage',
			'mainEntity' => $main,
		);
	}

	private static function blogposting_node() {
		$id = get_the_ID();
		if ( ! $id ) {
			return null;
		}

		$node = array(
			'@type'            => 'BlogPosting',
			'@id'              => get_permalink( $id ) . '#article',
			'mainEntityOfPage' => get_permalink( $id ),
			'headline'         => get_the_title( $id ),
			'datePublished'    => get_the_date( 'c', $id ),
			'dateModified'     => get_the_modified_date( 'c', $id ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $id ) ),
			),
			'publisher'        => array( '@id' => home_url( '/#business' ) ),
		);

		if ( has_post_thumbnail( $id ) ) {
			$image = wp_get_attachment_image_url( get_post_thumbnail_id( $id ), 'full' );
			if ( $image ) {
				$node['image'] = $image;
			}
		}
		if ( has_excerpt( $id ) ) {
			$node['description'] = wp_strip_all_tags( get_the_excerpt( $id ) );
		}

		return $node;
	}

	/**
	 * Best-effort canonical URL for the current request (for FAQPage @id).
	 */
	private static function current_url() {
		if ( is_singular() || is_page() ) {
			$permalink = get_permalink( get_queried_object_id() );
			if ( $permalink ) {
				return $permalink;
			}
		}
		if ( is_post_type_archive( 'faq' ) ) {
			$link = get_post_type_archive_link( 'faq' );
			if ( $link ) {
				return $link;
			}
		}
		return home_url( '/' );
	}

	/**
	 * Split a textarea value into a clean array of non-empty lines.
	 */
	private static function lines( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}
}
