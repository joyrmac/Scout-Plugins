<?php
/**
 * Global SEO defaults, stored as one option `scout_seo_defaults`. Per-page SEO
 * still lives in the Scout SEO box; these are the site-wide fallbacks:
 *  - default_image: the share image used when a page has no featured image.
 *  - home_description: meta description for the front page.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_SEO_Settings {

	const OPTION = 'scout_seo_defaults';

	/** @return array<string,string> */
	public static function fields() {
		return array(
			'default_image'    => 'Default share image URL (used when a page has no featured image)',
			'home_description' => 'Homepage meta description',
		);
	}

	/** @return array<string,string> */
	public static function get() {
		$defaults = array_fill_keys( array_keys( self::fields() ), '' );
		return wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
	}

	public static function value( $key ) {
		$all = self::get();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	public static function register_settings() {
		register_setting(
			'scout_seo_group',
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	public static function sanitize( $input ) {
		$input = (array) $input;
		return array(
			'default_image'    => isset( $input['default_image'] ) ? esc_url_raw( $input['default_image'] ) : '',
			'home_description' => isset( $input['home_description'] ) ? sanitize_textarea_field( $input['home_description'] ) : '',
		);
	}
}
