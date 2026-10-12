<?php
/**
 * The review sources Scout Reviews knows about.
 *
 * One list, read by the editor dropdown, the badge on each card, and the
 * rating summary, so a platform's name is spelled the same everywhere.
 * Add or rename a source with the `scout_reviews_sources` filter.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Reviews_Sources {

	/**
	 * All sources, keyed by slug.
	 *
	 * - label:  the platform's name ("Google").
	 * - stars:  whether the platform uses a 1 to 5 star rating.
	 * - sync:   whether the platform can be pulled automatically (planned, 0.2.0).
	 *
	 * @return array<string, array{label:string, stars:bool, sync:bool}>
	 */
	public static function all(): array {
		$sources = array(
			'google'     => array( 'label' => 'Google', 'stars' => true, 'sync' => true ),
			'facebook'   => array( 'label' => 'Facebook', 'stars' => false, 'sync' => true ),
			'yelp'       => array( 'label' => 'Yelp', 'stars' => true, 'sync' => false ),
			'clutch'     => array( 'label' => 'Clutch', 'stars' => true, 'sync' => false ),
			'upcity'     => array( 'label' => 'UpCity', 'stars' => true, 'sync' => false ),
			'designrush' => array( 'label' => 'DesignRush', 'stars' => true, 'sync' => false ),
			'bbb'        => array( 'label' => 'BBB', 'stars' => true, 'sync' => false ),
			'avvo'       => array( 'label' => 'Avvo', 'stars' => true, 'sync' => false ),
			'direct'     => array( 'label' => 'Client', 'stars' => false, 'sync' => false ),
		);

		/**
		 * Filter the review sources.
		 *
		 * @param array $sources Slug => { label, stars, sync }.
		 */
		return (array) apply_filters( 'scout_reviews_sources', $sources );
	}

	public static function get( string $slug ): ?array {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	public static function label( string $slug ): string {
		$source = self::get( $slug );
		return $source ? $source['label'] : '';
	}

	/**
	 * The badge text on a card: "Google review", or "Client testimonial" for
	 * reviews sent to the business directly.
	 */
	public static function badge( string $slug ): string {
		if ( 'direct' === $slug ) {
			return __( 'Client testimonial', 'scout-reviews' );
		}
		/* translators: %s: platform name, e.g. Google. */
		return sprintf( __( '%s review', 'scout-reviews' ), self::label( $slug ) );
	}

	/**
	 * The link text back to the original: "View on Google".
	 */
	public static function view_text( string $slug ): string {
		/* translators: %s: platform name, e.g. Google. */
		return sprintf( __( 'View on %s', 'scout-reviews' ), self::label( $slug ) );
	}

	public static function exists( string $slug ): bool {
		return null !== self::get( $slug );
	}
}
