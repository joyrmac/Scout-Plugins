<?php
/**
 * Event and Person schema, so events can show in Google's event results.
 *
 * Event pages get an Event node (or EducationEvent, Festival, SocialEvent)
 * with dates, status, place, offers, organizer and the speakers as
 * performers. Speaker pages get a Person node.
 *
 * With Scout Core active the nodes join its single @graph through the
 * `scout_schema_graph` filter and the organizer points at its business,
 * home_url( '/#business' ). Without Scout Core the plugin prints its own
 * small graph on those pages. Turn schema off under Events, Settings, or
 * with the scout_events_schema filter, when a theme prints its own.
 *
 * No node is printed for an event without a start date, since Google
 * requires one.
 *
 * @package Scout_Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Events_Schema {

	const STATUS = array(
		'scheduled'   => 'https://schema.org/EventScheduled',
		'postponed'   => 'https://schema.org/EventPostponed',
		'rescheduled' => 'https://schema.org/EventRescheduled',
		'cancelled'   => 'https://schema.org/EventCancelled',
	);

	const MODE = array(
		'offline' => 'https://schema.org/OfflineEventAttendanceMode',
		'online'  => 'https://schema.org/OnlineEventAttendanceMode',
		'mixed'   => 'https://schema.org/MixedEventAttendanceMode',
	);

	const AVAILABILITY = array(
		'onsale'  => 'https://schema.org/InStock',
		'free'    => 'https://schema.org/InStock',
		'soldout' => 'https://schema.org/SoldOut',
		'notyet'  => 'https://schema.org/PreOrder',
	);

	public static function boot(): void {
		add_filter( 'scout_schema_graph', array( __CLASS__, 'extend_graph' ) );
		add_action( 'wp_head', array( __CLASS__, 'standalone' ), 21 );
	}

	public static function enabled(): bool {
		return (bool) apply_filters( 'scout_events_schema', (bool) Scout_Events::settings()['schema'] );
	}

	private static function core_graph(): bool {
		return class_exists( 'Scout_Schema' ) && function_exists( 'scout_core_business' );
	}

	private static function text( string $s ): string {
		return html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * The Event node, or an empty array when the event has no start date.
	 *
	 * @param int $post_id Event post ID.
	 */
	public static function event( int $post_id ): array {
		$e     = Scout_Events::event( $post_id );
		$start = Scout_Events::start( $e );
		if ( ! $start ) {
			return array();
		}
		$url  = get_permalink( $post_id );
		$node = array(
			'@type'               => $e['schema_type'],
			'@id'                 => $url . '#event',
			'name'                => self::text( get_the_title( $post_id ) ),
			'url'                 => $url,
			'startDate'           => '' !== $e['start_time'] ? $start->format( 'c' ) : $start->format( 'Y-m-d' ),
			'eventStatus'         => self::STATUS[ $e['status'] ],
			'eventAttendanceMode' => self::MODE[ $e['mode'] ],
		);
		$end = Scout_Events::end( $e );
		if ( $end && ( '' !== $e['end_date'] || '' !== $e['end_time'] ) ) {
			$node['endDate'] = '' !== $e['end_time'] ? $end->format( 'c' ) : $end->format( 'Y-m-d' );
		}

		$desc = has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : wp_trim_words( (string) get_post_field( 'post_content', $post_id ), 50, '' );
		$desc = trim( self::text( (string) $desc ) );
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}
		if ( has_post_thumbnail( $post_id ) ) {
			$image = wp_get_attachment_image_url( (int) get_post_thumbnail_id( $post_id ), 'full' );
			if ( $image ) {
				$node['image'] = array( $image );
			}
		}

		$places = array();
		if ( 'online' !== $e['mode'] && '' !== $e['venue'] ) {
			$place = array(
				'@type' => 'Place',
				'name'  => $e['venue'],
			);
			$addr  = array_filter(
				array(
					'streetAddress'   => $e['street'],
					'addressLocality' => $e['city'],
					'addressRegion'   => $e['region'],
					'postalCode'      => $e['postal'],
					'addressCountry'  => $e['country'],
				)
			);
			if ( count( $addr ) > 1 ) {
				$place['address'] = array( '@type' => 'PostalAddress' ) + $addr;
			}
			$places[] = $place;
		}
		if ( 'offline' !== $e['mode'] && '' !== $e['online_url'] ) {
			$places[] = array(
				'@type' => 'VirtualLocation',
				'url'   => $e['online_url'],
			);
		}
		if ( $places ) {
			$node['location'] = 1 === count( $places ) ? $places[0] : $places;
		}

		$offer = self::offer( $e, $url );
		if ( $offer ) {
			$node['offers'] = $offer;
		}
		if ( 'free' === $e['availability'] ) {
			$node['isAccessibleForFree'] = true;
		}

		$node['organizer'] = self::core_graph()
			? array( '@id' => home_url( '/#business' ) )
			: array(
				'@type' => 'Organization',
				'name'  => self::text( get_bloginfo( 'name' ) ),
				'url'   => home_url( '/' ),
			);

		$performers = array();
		foreach ( Scout_Events::event_speaker_ids( $post_id ) as $pid ) {
			$performers[] = array(
				'@type' => 'Person',
				'@id'   => get_permalink( $pid ) . '#person',
				'name'  => self::text( get_the_title( $pid ) ),
				'url'   => get_permalink( $pid ),
			);
		}
		if ( $performers ) {
			$node['performer'] = $performers;
		}

		if ( $e['min_age'] > 0 ) {
			$node['typicalAgeRange'] = $e['min_age'] . '-';
		}
		if ( $e['capacity'] > 0 ) {
			$node['maximumAttendeeCapacity'] = $e['capacity'];
		}

		return (array) apply_filters( 'scout_events_event_schema', $node, $post_id );
	}

	/** Offer or AggregateOffer, or an empty array when there is nothing to sell. */
	private static function offer( array $e, string $url ): array {
		if ( ! isset( self::AVAILABILITY[ $e['availability'] ] ) ) {
			return array();
		}
		$currency = Scout_Events::settings()['currency'];
		$offer    = array(
			'@type'         => 'Offer',
			'url'           => '' !== $e['ticket_url'] ? $e['ticket_url'] : $url,
			'availability'  => self::AVAILABILITY[ $e['availability'] ],
			'priceCurrency' => $currency,
		);
		if ( 'free' === $e['availability'] ) {
			$offer['price'] = 0;
		} elseif ( '' !== $e['price_to'] && '' !== $e['price_from'] ) {
			$offer['@type']    = 'AggregateOffer';
			$offer['lowPrice'] = (float) $e['price_from'];
			$offer['highPrice'] = (float) $e['price_to'];
		} elseif ( '' !== $e['price_from'] ) {
			$offer['price'] = (float) $e['price_from'];
		} else {
			unset( $offer['priceCurrency'] );
		}
		$from = '' !== $e['sales_start'] ? Scout_Events::moment( $e['sales_start'], '00:00' ) : null;
		if ( $from ) {
			$offer['validFrom'] = $from->format( 'c' );
		}
		$until = '' !== $e['sales_end'] ? Scout_Events::moment( $e['sales_end'], '23:59' ) : null;
		if ( $until ) {
			$offer['validThrough'] = $until->format( 'c' );
		}
		return $offer;
	}

	/** The Person node for a speaker page. */
	public static function person( int $post_id ): array {
		$p    = Scout_Events::speaker( $post_id );
		$node = array(
			'@type' => 'Person',
			'@id'   => get_permalink( $post_id ) . '#person',
			'name'  => self::text( get_the_title( $post_id ) ),
			'url'   => get_permalink( $post_id ),
		);
		if ( '' !== $p['role'] ) {
			$node['jobTitle'] = $p['role'];
		}
		if ( '' !== $p['short'] ) {
			$node['description'] = $p['short'];
		}
		if ( has_post_thumbnail( $post_id ) ) {
			$image = wp_get_attachment_image_url( (int) get_post_thumbnail_id( $post_id ), 'full' );
			if ( $image ) {
				$node['image'] = $image;
			}
		}
		$same = $p['links'];
		if ( '' !== $p['website'] ) {
			array_unshift( $same, $p['website'] );
		}
		if ( $same ) {
			$node['sameAs'] = array_values( array_unique( $same ) );
		}
		return (array) apply_filters( 'scout_events_person_schema', $node, $post_id );
	}

	/** The nodes for the page being viewed, keyed for Scout Core's graph. */
	private static function nodes(): array {
		if ( ! self::enabled() ) {
			return array();
		}
		$id = (int) get_queried_object_id();
		if ( is_singular( Scout_Events::EVENT ) && 'publish' === get_post_status( $id ) ) {
			$node = self::event( $id );
			return $node ? array( 'scout_event_' . $id => $node ) : array();
		}
		if ( is_singular( Scout_Events::SPEAKER ) && 'publish' === get_post_status( $id ) ) {
			return array( 'person_' . $id => self::person( $id ) );
		}
		return array();
	}

	/**
	 * Scout Core: join its graph.
	 *
	 * @param array $graph Scout Core graph, keyed.
	 */
	public static function extend_graph( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}
		return array_merge( $graph, self::nodes() );
	}

	/** No Scout Core: print a small graph of our own. */
	public static function standalone(): void {
		if ( is_admin() || is_feed() || self::core_graph() ) {
			return;
		}
		$nodes = self::nodes();
		if ( ! $nodes ) {
			return;
		}
		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $nodes ),
		);
		echo "\n<script type=\"application/ld+json\" id=\"scout-events-schema\">" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}
}
