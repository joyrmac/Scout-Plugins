<?php
/**
 * Where the disclaimer lines show on the site: the footer of every page,
 * under every Gravity Form, next to case results (Scout Case Results) and
 * under reviews (Scout Reviews). One wording, set once on the Disclaimers
 * screen, reaches every place.
 *
 * A theme that places the footer lines itself declares
 * add_theme_support( 'scout-law-footer' ) and calls scout_law_disclaimers( 'footer' ).
 *
 * @package Scout_Law
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Law_Front {

	/** @var bool The small stylesheet already printed on this page. */
	private static $styled = false;

	public static function boot(): void {
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 20 );
		add_shortcode( 'scout_disclaimers', array( __CLASS__, 'shortcode_place' ) );
		add_shortcode( 'scout_disclaimer', array( __CLASS__, 'shortcode_line' ) );
		add_filter( 'gform_get_form_filter', array( __CLASS__, 'form' ), 10, 2 );
		add_filter( 'scout_results_disclaimer', array( __CLASS__, 'results' ) );
		add_filter( 'option_scout_reviews_settings', array( __CLASS__, 'reviews' ) );
		add_filter( 'default_option_scout_reviews_settings', array( __CLASS__, 'reviews' ) ); // Review settings never saved yet.
	}

	/** The lines for one place, as HTML. Empty string when none show there. */
	public static function html( string $place ): string {
		$lines = Scout_Law_Disclaimers::in_place( $place );
		if ( ! $lines ) {
			return '';
		}
		$out = '';
		foreach ( $lines as $line ) {
			$out .= '<p class="scout-law-disclaimer scout-law-disclaimer--' . esc_attr( $line ) . '">' . esc_html( Scout_Law_Disclaimers::text( $line ) ) . '</p>';
		}
		return self::style() . '<div class="scout-law-disclaimers scout-law-disclaimers--' . esc_attr( $place ) . '">' . $out . '</div>';
	}

	/** A few quiet lines of CSS, once per page. Themes can restyle .scout-law-disclaimers. */
	private static function style(): string {
		if ( self::$styled ) {
			return '';
		}
		self::$styled = true;
		return '<style id="scout-law-css">.scout-law-disclaimers{font-size:13px;line-height:1.5;opacity:.85}.scout-law-disclaimers p{margin:0 0 6px}.scout-law-disclaimers--footer{max-width:72ch;margin:16px auto;padding:0 16px;text-align:center}.scout-law-disclaimers--forms{margin-top:12px}</style>';
	}

	/** The footer lines, unless the theme places them itself. */
	public static function footer(): void {
		if ( is_admin() || current_theme_supports( 'scout-law-footer' ) ) {
			return;
		}
		echo self::html( 'footer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in html().
	}

	/** [scout_disclaimers place="footer"] prints every line set to show in that place. */
	public static function shortcode_place( $atts ): string {
		$atts  = shortcode_atts( array( 'place' => 'footer' ), (array) $atts, 'scout_disclaimers' );
		$place = sanitize_key( (string) $atts['place'] );
		return isset( Scout_Law_Disclaimers::places()[ $place ] ) ? self::html( $place ) : '';
	}

	/** [scout_disclaimer line="relationship"] prints one line wherever it is placed. */
	public static function shortcode_line( $atts ): string {
		$atts = shortcode_atts( array( 'line' => 'relationship' ), (array) $atts, 'scout_disclaimer' );
		$line = sanitize_key( (string) $atts['line'] );
		if ( ! isset( Scout_Law_Disclaimers::lines()[ $line ] ) ) {
			return '';
		}
		return self::style() . '<p class="scout-law-disclaimer scout-law-disclaimer--' . esc_attr( $line ) . '">' . esc_html( Scout_Law_Disclaimers::text( $line ) ) . '</p>';
	}

	/** Under every Gravity Form. */
	public static function form( $html, $form ) {
		unset( $form );
		return (string) $html . self::html( 'forms' );
	}

	/** Scout Case Results prints this firm-wide wording next to every result. */
	public static function results( $text ) {
		return in_array( 'results', Scout_Law_Disclaimers::places_for( 'results' ), true ) ? Scout_Law_Disclaimers::text( 'results' ) : $text;
	}

	/** Scout Reviews prints this wording under its reviews. */
	public static function reviews( $settings ) {
		if ( ! is_array( $settings ) || is_admin() ) {
			return $settings; // The Review settings screen keeps showing what was saved there.
		}
		if ( in_array( 'reviews', Scout_Law_Disclaimers::places_for( 'reviews' ), true ) ) {
			$settings['disclaimer'] = Scout_Law_Disclaimers::text( 'reviews' );
		}
		return $settings;
	}
}

/**
 * Print the disclaimer lines for a place, for themes that place them
 * themselves (add_theme_support( 'scout-law-footer' )).
 *
 * @param string $place footer, forms, results or reviews.
 */
function scout_law_disclaimers( string $place = 'footer' ): void {
	echo Scout_Law_Front::html( $place ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
}
