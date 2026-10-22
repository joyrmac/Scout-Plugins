<?php
/**
 * Opening hours in the schema Google reads.
 *
 * With Scout Core active, the hours are added to Scout Core's own business
 * node (@id /#business) through its `scout_schema_graph` filter, so the page
 * keeps exactly one business. Without Scout Core, a minimal node is printed
 * with the same @id, carrying only the site name, its address on the web and the
 * hours, unless the active theme is one of the Scout themes that print their
 * own business schema (filter `scout_hours_standalone_schema`).
 *
 * Nothing is added until the hours are confirmed.
 *
 * @package Scout_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Hours_Schema {

	public static function boot(): void {
		add_filter( 'scout_schema_graph', array( __CLASS__, 'add_to_graph' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'standalone' ), 21 );
	}

	/** Scout Core is printing the business graph. */
	public static function core_active(): bool {
		return class_exists( 'Scout_Schema' ) && function_exists( 'scout_core_business' );
	}

	/**
	 * How the hours reach schema on this site: 'core', 'standalone' or 'none'.
	 */
	public static function route(): string {
		if ( self::core_active() ) {
			return 'core';
		}
		$theme   = get_template();
		$default = ! in_array( $theme, array( 'scout-media', 'scout-recon' ), true );
		return apply_filters( 'scout_hours_standalone_schema', $default ) ? 'standalone' : 'none';
	}

	/**
	 * The schema properties: openingHoursSpecification and
	 * specialOpeningHoursSpecification. Empty until the hours are confirmed.
	 * Themes that print their own business node can merge this array in.
	 */
	public static function properties(): array {
		if ( ! Scout_Hours::is_public() ) {
			return array();
		}
		$s     = Scout_Hours::settings();
		$props = array();

		// Group days that share the same range, one specification per range.
		$groups = array();
		foreach ( Scout_Hours::days() as $key => $name ) {
			if ( ! $s['days'][ $key ]['open'] ) {
				continue;
			}
			foreach ( $s['days'][ $key ]['ranges'] as $r ) {
				$groups[ $r[0] . '-' . $r[1] ]['range']  = $r;
				$groups[ $r[0] . '-' . $r[1] ]['days'][] = $name;
			}
		}
		foreach ( $groups as $g ) {
			$props['openingHoursSpecification'][] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => 1 === count( $g['days'] ) ? $g['days'][0] : $g['days'],
				'opens'     => $g['range'][0],
				'closes'    => $g['range'][1],
			);
		}

		foreach ( Scout_Hours::upcoming_closures() as $c ) {
			$base = array(
				'@type'        => 'OpeningHoursSpecification',
				'validFrom'    => $c['start'],
				'validThrough' => $c['end'],
			);
			if ( '' !== $c['name'] ) {
				$base['name'] = $c['name'];
			}
			if ( 'closed' === $c['type'] ) {
				// Google's documented way to say "closed all day".
				$props['specialOpeningHoursSpecification'][] = $base + array(
					'opens'  => '00:00',
					'closes' => '00:00',
				);
				continue;
			}
			foreach ( $c['ranges'] as $r ) {
				$props['specialOpeningHoursSpecification'][] = $base + array(
					'opens'  => $r[0],
					'closes' => $r[1],
				);
			}
		}

		return (array) apply_filters( 'scout_hours_schema_properties', $props );
	}

	/** Scout Core: add the hours to its business node. */
	public static function add_to_graph( $graph ) {
		if ( ! is_array( $graph ) || empty( $graph['business'] ) || ! is_array( $graph['business'] ) ) {
			return $graph;
		}
		$props = self::properties();
		if ( $props ) {
			$graph['business'] = array_merge( $graph['business'], $props );
		}
		return $graph;
	}

	/** No Scout Core: a minimal business node with the same @id. */
	public static function standalone(): void {
		if ( is_admin() || is_feed() || 'standalone' !== self::route() ) {
			return;
		}
		$props = self::properties();
		if ( ! $props ) {
			return;
		}
		$node = array_merge(
			array(
				'@context' => 'https://schema.org',
				'@type'    => (string) apply_filters( 'scout_hours_standalone_type', 'LocalBusiness' ),
				'@id'      => home_url( '/#business' ),
				'name'     => get_bloginfo( 'name' ),
				'url'      => home_url( '/' ),
			),
			$props
		);
		echo "\n<script type=\"application/ld+json\" id=\"scout-hours-schema\">" . wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}
}
