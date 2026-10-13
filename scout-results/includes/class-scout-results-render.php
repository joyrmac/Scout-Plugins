<?php
/**
 * The front end: [scout_results] prints a grid of approved results with
 * practice-area filters, and [scout_result id="12"] prints one. Every card
 * carries the site's disclaimer, and there is no way to switch it off.
 *
 * No structured data is printed. A case result is not a review or a rating,
 * and nothing here should suggest it is.
 *
 * @package Scout_Results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Results_Render {

	public static function boot(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( 'scout_results', array( __CLASS__, 'grid' ) );
		add_shortcode( 'scout_result', array( __CLASS__, 'single' ) );
	}

	public static function register_assets(): void {
		wp_register_style( 'scout-results', SCOUT_RESULTS_URL . 'assets/scout-results.css', array(), SCOUT_RESULTS_VERSION );
		wp_register_script( 'scout-results', SCOUT_RESULTS_URL . 'assets/scout-results.js', array(), SCOUT_RESULTS_VERSION, true );
		// Load in the head on pages that use the shortcode, so nothing shifts.
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && ( has_shortcode( $post->post_content, 'scout_results' ) || has_shortcode( $post->post_content, 'scout_result' ) ) ) {
				wp_enqueue_style( 'scout-results' );
			}
		}
	}

	/** Approved, published results, newest result date first. */
	public static function query( array $args = array() ): array {
		$q = array(
			'post_type'              => Scout_Results::POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => max( 1, min( 200, (int) ( $args['limit'] ?? 100 ) ) ),
			'no_found_rows'          => true,
			'update_post_term_cache' => true,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, per-site list.
				'approved' => array(
					'key'   => Scout_Results::META['approved'],
					'value' => '1',
				),
			),
			'orderby'                => 'date',
			'order'                  => 'DESC',
		);
		if ( ! empty( $args['area'] ) ) {
			$q['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => Scout_Results::TAXONOMY,
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', explode( ',', (string) $args['area'] ) ),
				),
			);
		}
		$out = array();
		foreach ( get_posts( $q ) as $post ) {
			$r = Scout_Results::get( (int) $post->ID );
			if ( Scout_Results::is_approved( $r['approved'], $r['approver'] ) ) {
				$out[] = $r;
			}
		}
		// Newest result date first; results without a date keep their order at the end.
		usort(
			$out,
			static function ( array $x, array $y ): int {
				return strcmp( $y['date'], $x['date'] );
			}
		);
		return $out;
	}

	/** The disclaimer as printed beside a result. */
	public static function disclaimer_html(): string {
		return '<p class="scout-result__disclaimer">' . esc_html( Scout_Results::disclaimer() ) . '</p>';
	}

	/**
	 * One result card. $preview marks the copy shown on the edit screen, which
	 * the editor script keeps in step with the fields.
	 */
	public static function card( array $r, bool $preview = false ): string {
		$date  = Scout_Results::format_date( $r['date'] );
		$attrs = $preview ? ' data-sr-preview' : ' data-area="' . esc_attr( $r['area_slug'] ) . '"';
		$h     = '<article class="scout-result"' . $attrs . '>';
		$h    .= '<p class="scout-result__type" data-sr="type">' . esc_html( $r['type_label'] ) . '</p>';
		$h    .= '<h3 class="scout-result__headline" data-sr="headline">' . esc_html( '' !== $r['headline'] ? $r['headline'] : ( $preview ? __( 'Your headline shows here', 'scout-results' ) : '' ) ) . '</h3>';
		$h    .= '<p class="scout-result__amount" data-sr="amount"' . ( '' === $r['amount'] ? ' hidden' : '' ) . '>' . esc_html( $r['amount'] ) . '</p>';
		$h    .= '<p class="scout-result__description" data-sr="description"' . ( '' === $r['description'] ? ' hidden' : '' ) . '>' . esc_html( $r['description'] ) . '</p>';
		$h    .= '<p class="scout-result__meta"><span class="scout-result__area" data-sr="area"' . ( '' === $r['area'] ? ' hidden' : '' ) . '>' . esc_html( $r['area'] ) . '</span>';
		$h    .= '<span class="scout-result__date" data-sr="date"' . ( '' === $date ? ' hidden' : '' ) . '>' . esc_html( $date ) . '</span></p>';
		$h    .= self::disclaimer_html();
		$h    .= '</article>';
		return $h;
	}

	/**
	 * [scout_results area="car-accidents" limit="12" filters="yes" heading="Case results"]
	 */
	public static function grid( $atts ): string {
		$a = shortcode_atts(
			array(
				'area'    => '',
				'limit'   => 100,
				'filters' => 'yes',
				'heading' => '',
			),
			(array) $atts,
			'scout_results'
		);

		$results = self::query( $a );
		wp_enqueue_style( 'scout-results' );

		$uid = 'scout-results-' . wp_unique_id();
		$h   = '<section class="scout-results" id="' . esc_attr( $uid ) . '"' . ( '' !== $a['heading'] ? ' aria-labelledby="' . esc_attr( $uid ) . '-h"' : ' aria-label="' . esc_attr__( 'Case results', 'scout-results' ) . '"' ) . '>';
		if ( '' !== $a['heading'] ) {
			$h .= '<h2 class="scout-results__heading" id="' . esc_attr( $uid ) . '-h">' . esc_html( $a['heading'] ) . '</h2>';
		}

		if ( ! $results ) {
			$h .= '<p class="scout-results__empty">' . esc_html__( 'Case results will appear here once the firm has approved them.', 'scout-results' ) . '</p>';
			return $h . '</section>';
		}

		// Filter buttons for the practice areas that have results, in name order.
		$areas = array();
		foreach ( $results as $r ) {
			if ( '' !== $r['area_slug'] ) {
				$areas[ $r['area_slug'] ] = $r['area'];
			}
		}
		asort( $areas, SORT_NATURAL | SORT_FLAG_CASE );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public filter link, read only.
		$current = isset( $_GET['result_area'] ) ? sanitize_title( wp_unslash( (string) $_GET['result_area'] ) ) : '';
		$current = isset( $areas[ $current ] ) ? $current : '';

		if ( 'no' !== $a['filters'] && count( $areas ) > 1 ) {
			wp_enqueue_script( 'scout-results' );
			$h .= '<nav class="scout-results__filters" aria-label="' . esc_attr__( 'Filter results by practice area', 'scout-results' ) . '"><ul>';
			$h .= '<li><a class="scout-results__filter" href="' . esc_url( remove_query_arg( 'result_area' ) . '#' . $uid ) . '" data-filter=""' . ( '' === $current ? ' aria-current="true"' : '' ) . '>' . esc_html__( 'All practice areas', 'scout-results' ) . '</a></li>';
			foreach ( $areas as $slug => $name ) {
				$h .= '<li><a class="scout-results__filter" href="' . esc_url( add_query_arg( 'result_area', $slug ) . '#' . $uid ) . '" data-filter="' . esc_attr( $slug ) . '"' . ( $slug === $current ? ' aria-current="true"' : '' ) . '>' . esc_html( $name ) . '</a></li>';
			}
			$h .= '</ul></nav>';
			$h .= '<p class="scout-results__status screen-reader-text" role="status" aria-live="polite" data-one="' . esc_attr__( 'Showing 1 result', 'scout-results' ) . '" data-many="' . esc_attr__( 'Showing %d results', 'scout-results' ) . '"></p>';
		}

		$h .= '<div class="scout-results__grid">';
		foreach ( $results as $r ) {
			$card = self::card( $r );
			if ( '' !== $current && $r['area_slug'] !== $current ) {
				$card = preg_replace( '/^<article /', '<article hidden ', $card, 1 );
			}
			$h .= $card;
		}
		$h .= '</div>';
		return $h . '</section>';
	}

	/** [scout_result id="12"]: one approved result, with the disclaimer. */
	public static function single( $atts ): string {
		$a  = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'scout_result' );
		$id = absint( $a['id'] );
		if ( ! $id || Scout_Results::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		$r = Scout_Results::get( $id );
		if ( ! Scout_Results::is_approved( $r['approved'], $r['approver'] ) ) {
			return '';
		}
		wp_enqueue_style( 'scout-results' );
		return '<div class="scout-results scout-results--single">' . self::card( $r ) . '</div>';
	}
}
