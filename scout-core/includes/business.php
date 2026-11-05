<?php
/**
 * The site's business identity: NAP, geo, profile URLs and the address that
 * gets notices (scout_core_notice_email()). Stored as one option,
 * `scout_business`, because it is site identity, not per-post data. The
 * Business info form is drawn by Scout_Core_Admin::tab_business().
 *
 * scout-schema reads this to build LocalBusiness JSON-LD; templates can pull
 * any value through the `scout/business` block binding. One source of truth for
 * the NAP that has to match the Google Business Profile byte-for-byte.
 *
 * @package Scout_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scout_Core_Business {

	const OPTION = 'scout_business';

	/**
	 * Every saved key, with its label. "hours" stays a key so a site's old
	 * saved text is kept and still readable through the `scout/business`
	 * binding, but Business info no longer shows it: weekly hours live in
	 * Scout Hours (see hidden()).
	 *
	 * @return array<string,string> field key => label
	 */
	public static function fields() {
		return array(
			'name'          => 'Business name',
			'legal_name'    => 'Legal name',
			'business_type' => 'Type of business',
			'phone'         => 'Phone',
			'email'         => 'Email',
			'notice_email'  => 'Send notices to',
			'street'        => 'Street address',
			'city'          => 'City',
			'region'        => 'State / region',
			'postal'        => 'Postal code',
			'country'       => 'Country code (e.g. US)',
			'price_range'   => 'Price range (e.g. $$)',
			'latitude'      => 'Latitude',
			'longitude'     => 'Longitude',
			'hours'         => 'Hours (one line per day)',
			'same_as'       => 'Your other profiles',
		);
	}

	/**
	 * Keys kept in the saved option but not shown on Business info.
	 *
	 * @return string[]
	 */
	public static function hidden() {
		return array( 'hours' );
	}

	/** @return array<string,string> field key => help line under the field */
	public static function help() {
		return array(
			'business_type' => 'The kind of business Google should read this as, for example LocalBusiness, Campground or LegalService.',
			'notice_email'  => 'Who gets form, booking and chat emails. Leave blank to use the email above.',
			'same_as'       => 'One link per line, like Google, Facebook, Yelp.',
		);
	}

	/** @return array<string,string> */
	public static function get() {
		$defaults = array_fill_keys( array_keys( self::fields() ), '' );
		return wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
	}

	public static function register_settings() {
		register_setting(
			'scout_business_group',
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	public static function sanitize( $input ) {
		$input = (array) $input;
		$saved = (array) get_option( self::OPTION, array() );
		$clean = array();
		foreach ( self::fields() as $key => $label ) {
			// A hidden key the form did not send keeps its saved value.
			if ( ! array_key_exists( $key, $input ) && in_array( $key, self::hidden(), true ) ) {
				$value = isset( $saved[ $key ] ) ? $saved[ $key ] : '';
			} else {
				$value = isset( $input[ $key ] ) ? $input[ $key ] : '';
			}
			if ( in_array( $key, array( 'hours', 'same_as' ), true ) ) {
				$clean[ $key ] = sanitize_textarea_field( $value );
			} elseif ( in_array( $key, array( 'email', 'notice_email' ), true ) ) {
				$clean[ $key ] = sanitize_email( $value );
			} else {
				$clean[ $key ] = sanitize_text_field( $value );
			}
		}
		return $clean;
	}
}

/**
 * Public getter for the business identity array. Used by scout-schema, the
 * `scout/business` binding, and anywhere a template needs the canonical NAP.
 *
 * @return array<string,string>
 */
function scout_core_business() {
	return Scout_Core_Business::get();
}

/**
 * The one address every Scout plugin sends notices to (form, booking and chat
 * emails). Business info's "Send notices to" when set, else Business info's
 * email, else the WordPress admin email. Other plugins call it guarded:
 * `function_exists( 'scout_core_notice_email' ) ? scout_core_notice_email() : <old default>`.
 * A plugin's own "send notices to" field stays an optional override on top.
 */
function scout_core_notice_email(): string {
	$business = Scout_Core_Business::get();
	foreach ( array( 'notice_email', 'email' ) as $key ) {
		$email = sanitize_email( (string) $business[ $key ] );
		if ( '' !== $email && is_email( $email ) ) {
			return $email;
		}
	}
	return (string) get_option( 'admin_email' );
}
