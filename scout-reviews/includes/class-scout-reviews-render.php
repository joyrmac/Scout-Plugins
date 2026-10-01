<?php
/**
 * Front-end output: the `[scout_reviews]` and `[scout_review_summary]`
 * shortcodes and the `scout/reviews` block, all drawn from the same templates.
 *
 * Themes restyle with CSS or replace markup outright: a file at
 * `{theme}/scout-reviews/{template}.php` wins over the plugin's copy in
 * `templates/`. Templates: section, summary, card.
 *
 * @package Scout_Reviews
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Reviews_Render {

	const STYLE = 'scout-reviews';

	public static function register(): void {
		wp_register_style( self::STYLE, SCOUT_REVIEWS_URL . 'assets/scout-reviews.css', array(), SCOUT_REVIEWS_VERSION );

		add_shortcode( 'scout_reviews', array( __CLASS__, 'shortcode_reviews' ) );
		add_shortcode( 'scout_review_summary', array( __CLASS__, 'shortcode_summary' ) );

		register_block_type(
			SCOUT_REVIEWS_DIR . 'assets/block',
			array( 'render_callback' => array( __CLASS__, 'block' ) )
		);
	}

	/**
	 * Normalize options from a shortcode or block into one shape.
	 */
	public static function options( array $raw ): array {
		$bool = static function ( $value ): bool {
			return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
		};

		$layout = strtolower( (string) ( $raw['layout'] ?? 'grid' ) );
		$source = sanitize_key( (string) ( $raw['source'] ?? '' ) );

		return array(
			'layout'     => in_array( $layout, array( 'grid', 'row', 'list' ), true ) ? $layout : 'grid',
			'count'      => max( 0, (int) ( $raw['count'] ?? 0 ) ),
			'source'     => Scout_Reviews_Sources::exists( $source ) ? $source : '',
			'featured'   => $bool( $raw['featured'] ?? false ),
			'min_rating' => max( 0, min( 5, (int) ( $raw['min_rating'] ?? 0 ) ) ),
			'summary'    => $bool( $raw['summary'] ?? true ),
		);
	}

	public static function shortcode_reviews( $atts ): string {
		return self::section( self::options( is_array( $atts ) ? $atts : array() ) );
	}

	public static function shortcode_summary( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$opts = self::options( $atts );
		self::enqueue();
		return self::template( 'summary', array( 'totals' => Scout_Reviews_Query::totals( $opts['source'] ) ) );
	}

	public static function block( array $attributes ): string {
		$opts = self::options(
			array(
				'layout'     => $attributes['layout'] ?? 'grid',
				'count'      => $attributes['count'] ?? 0,
				'source'     => $attributes['source'] ?? '',
				'featured'   => ! empty( $attributes['featured'] ) ? '1' : '0',
				'min_rating' => $attributes['minRating'] ?? 0,
				'summary'    => ! isset( $attributes['showSummary'] ) || $attributes['showSummary'] ? '1' : '0',
			)
		);
		$html = self::section( $opts );
		if ( '' === $html ) {
			return '';
		}
		return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * Summary + cards + disclaimer. Empty string when there is nothing to show,
	 * so an empty section never leaves a blank gap on the page.
	 */
	public static function section( array $opts ): string {
		$reviews = Scout_Reviews_Query::reviews(
			array(
				'count'      => $opts['count'],
				'source'     => $opts['source'],
				'featured'   => $opts['featured'],
				'min_rating' => $opts['min_rating'],
			)
		);
		$totals = $opts['summary'] ? Scout_Reviews_Query::totals( $opts['source'] ) : array();

		if ( ! $reviews && ! $totals ) {
			return '';
		}

		self::enqueue();
		return self::template(
			'section',
			array(
				'reviews'    => $reviews,
				'totals'     => $totals,
				'layout'     => $opts['layout'],
				'disclaimer' => Scout_Reviews_Settings::get()['disclaimer'],
			)
		);
	}

	public static function enqueue(): void {
		if ( apply_filters( 'scout_reviews_load_styles', (bool) Scout_Reviews_Settings::get()['load_styles'] ) ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	/**
	 * Render a template with $vars in scope. Theme copy wins.
	 */
	public static function template( string $name, array $vars = array() ): string {
		$name = sanitize_key( $name );
		$file = locate_template( 'scout-reviews/' . $name . '.php' );
		if ( ! $file ) {
			$file = SCOUT_REVIEWS_DIR . 'templates/' . $name . '.php';
		}
		if ( ! is_readable( $file ) ) {
			return '';
		}
		ob_start();
		( static function ( $__file, $__vars ) {
			extract( $__vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract -- template scope.
			include $__file;
		} )( $file, $vars );
		return (string) ob_get_clean();
	}

	/* ---- Helpers the templates use ---- */

	/**
	 * Five stars, filled to the rating (supports halves for platform totals).
	 */
	public static function stars( float $rating ): string {
		$label = sprintf(
			/* translators: %s: rating such as 4.9. */
			__( 'Rated %s out of 5', 'scout-reviews' ),
			rtrim( rtrim( number_format_i18n( $rating, 1 ), '0' ), '.,' )
		);
		$pct = max( 0, min( 100, ( $rating / 5 ) * 100 ) );

		// One element: a star-shaped mask over a two-stop fill. Partial
		// ratings (4.9) fill to the exact fraction.
		return '<span class="scout-stars" role="img" aria-label="' . esc_attr( $label ) . '" style="--sr-pct:' . esc_attr( (string) round( $pct, 1 ) ) . '%"></span>';
	}

	/**
	 * "March 2026": the month is enough for trust and ages better than a full date.
	 */
	public static function date( string $ymd ): string {
		if ( ! $ymd ) {
			return '';
		}
		$ts = strtotime( $ymd . ' 12:00:00' );
		return $ts ? wp_date( 'F Y', $ts ) : '';
	}

	/**
	 * First letter of the reviewer's name, for the avatar when there is no photo.
	 */
	public static function initial( string $name ): string {
		$name = trim( $name );
		return '' === $name ? '?' : mb_strtoupper( mb_substr( $name, 0, 1 ) );
	}
}
