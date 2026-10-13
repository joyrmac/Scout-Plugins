<?php
/**
 * Template helpers for themes and other Scout features.
 *
 * @package Scout_Hours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'scout_hours_html' ) ) {
	/**
	 * The hours block as HTML, the same as the [scout_hours] shortcode.
	 *
	 *   <?php echo scout_hours_html(); ?>
	 *   <?php echo scout_hours_html( array( 'show' => 'status' ) ); ?>
	 *
	 * Already escaped. Empty until the hours are confirmed.
	 *
	 * @param array{show?:string} $args show: 'both' (default), 'table' or 'status'.
	 */
	function scout_hours_html( array $args = array() ): string {
		return Scout_Hours_Front::html( $args );
	}
}

if ( ! function_exists( 'scout_hours_status' ) ) {
	/**
	 * The live status for other features (a chat answer, a header badge).
	 * Null until the hours are confirmed.
	 *
	 * @return array{open:bool, text:string}|null
	 */
	function scout_hours_status(): ?array {
		return Scout_Hours::is_public() && Scout_Hours::has_hours() ? Scout_Hours::status() : null;
	}
}

if ( ! function_exists( 'scout_hours_data' ) ) {
	/**
	 * The confirmed weekly hours and upcoming closures as plain data, for
	 * other features. Null until the hours are confirmed.
	 *
	 * @return array{days:array, closures:array}|null
	 */
	function scout_hours_data(): ?array {
		if ( ! Scout_Hours::is_public() ) {
			return null;
		}
		return array(
			'days'     => Scout_Hours::settings()['days'],
			'closures' => Scout_Hours::upcoming_closures(),
		);
	}
}
